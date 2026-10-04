<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Helpers\Str;
use App\Repositories\CollectionRepository;
use App\Repositories\ProductRepository;
use App\Services\AuditService;
use App\Services\FileUploadService;

/**
 * Campaign pages: a curated set of products under a slug and a template.
 *
 * The shape a shop owner wants is "here are the twelve things I am pushing this
 * Diwali, give it a page, point an advert at it".
 */
final class CollectionService
{
    private const TEMPLATES = ['grid', 'spotlight', 'story', 'gift'];

    public function __construct(
        private readonly CollectionRepository $collections,
        private readonly ProductRepository $products,
        private readonly ProductService $productService,
        private readonly FileUploadService $uploads,
        private readonly AuditService $audit,
    ) {
    }

    // -----------------------------------------------------------------------
    // Public
    // -----------------------------------------------------------------------

    /**
     * A campaign page as a shopper sees it.
     *
     * Only published products appear. A merchant who unpublishes something
     * mid-campaign should not have it silently vanish from the shop but linger
     * on the landing page — the page is a view of the catalogue, not a copy.
     *
     * @return array<string, mixed>
     */
    public function publicView(string $slug): array
    {
        $collection = $this->collections->findLiveBySlug($slug);

        if ($collection === null) {
            throw new HttpException('That page is not available.', 404);
        }

        $this->collections->recordView((int) $collection['id']);
        $items = $this->collections->items((int) $collection['id'], publishedOnly: true);

        return [
            'collection' => $this->present($collection),
            'items' => array_map(fn (array $item): array => $this->presentItem($item), $items),
        ];
    }

    /**
     * Live campaign pages for the home page's "festive gifts" strip — title,
     * hero image and link, nothing a shopper doesn't need for a tile.
     *
     * @return array<int, array<string, mixed>>
     */
    public function liveList(): array
    {
        return array_map(fn (array $row): array => $this->present($row), $this->collections->listLive());
    }

    // -----------------------------------------------------------------------
    // Administration
    // -----------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForAdmin(?string $status): array
    {
        return array_map(static function (array $row): array {
            $starts = $row['starts_date'];
            $ends = $row['ends_date'];
            $now = date('Y-m-d H:i:s');

            return [
                'uuid' => $row['uuid'],
                'slug' => $row['slug'],
                'title' => $row['title'],
                'subtitle' => $row['subtitle'],
                'template' => $row['template'],
                'status' => $row['status'],
                'item_count' => (int) $row['item_count'],
                'purchasable_count' => (int) $row['purchasable_count'],
                'view_count' => (int) $row['view_count'],
                'starts_date' => $starts,
                'ends_date' => $ends,
                // Stated plainly, because "published but out of season" looks
                // identical to "live" in a status column and is the mistake this
                // feature will actually produce.
                'is_live' => $row['status'] === 'published'
                    && ($starts === null || $starts <= $now)
                    && ($ends === null || $ends >= $now),
                'has_expired' => $ends !== null && $ends < $now,
            ];
        }, $this->collections->listAll($status));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function create(array $data, Request $request): array
    {
        $template = (string) ($data['template'] ?? 'grid');

        if (!in_array($template, self::TEMPLATES, true)) {
            throw new HttpException('Unknown template.', 422, [
                'template' => ['Choose one of: ' . implode(', ', self::TEMPLATES)],
            ]);
        }

        $slug = Str::slug((string) ($data['slug'] ?? $data['title']));

        if ($this->collections->findBySlug($slug) !== null) {
            throw new HttpException('A page already uses that address.', 422, [
                'slug' => ['Choose a different address.'],
            ]);
        }

        $this->assertDates($data);

        $this->collections->create([
            'slug' => $slug,
            'title' => (string) $data['title'],
            'subtitle' => $data['subtitle'] ?? null,
            'intro' => $data['intro'] ?? null,
            'template' => $template,
            'cta_label' => $data['cta_label'] ?? null,
            'status' => 'draft',
            'starts_date' => $data['starts_date'] ?? null,
            'ends_date' => $data['ends_date'] ?? null,
            'display_order' => (int) ($data['display_order'] ?? 100),
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'created_by' => $request->authUserId(),
        ]);

        return $this->adminView($slug);
    }

    /**
     * @return array<string, mixed>
     */
    public function adminView(string $slugOrUuid): array
    {
        $collection = $this->resolve($slugOrUuid);

        // Staff see EVERYTHING on the page, including products that are not on
        // sale, each marked. Hiding them would leave a merchant wondering why
        // their page shows nine products when they added twelve.
        $items = $this->collections->items((int) $collection['id'], publishedOnly: false);

        return [
            'collection' => $this->present($collection) + [
                'status' => $collection['status'],
                'starts_date' => $collection['starts_date'],
                'ends_date' => $collection['ends_date'],
                'view_count' => (int) $collection['view_count'],
            ],
            'items' => array_map(function (array $item): array {
                return $this->presentItem($item) + [
                    'item_uuid' => $item['item_uuid'],
                    'is_purchasable' => $item['status'] === 'published',
                    'product_status' => $item['status'],
                ];
            }, $items),
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function update(string $slugOrUuid, array $data, Request $request): array
    {
        $collection = $this->resolve($slugOrUuid);

        if (isset($data['template']) && !in_array((string) $data['template'], self::TEMPLATES, true)) {
            throw new HttpException('Unknown template.', 422, [
                'template' => ['Choose one of: ' . implode(', ', self::TEMPLATES)],
            ]);
        }

        $this->assertDates($data);

        $updatable = ['title', 'subtitle', 'intro', 'template', 'cta_label',
            'starts_date', 'ends_date', 'display_order', 'meta_title', 'meta_description'];

        // Intersected with the RAW request, not just $data: Validator::make()
        // marks every 'nullable' rule present-with-null even when the caller
        // sent nothing for it (that is what lets a request updating only, say,
        // `intro` pass validation at all) — so building $changes from $data
        // alone would carry every other field in as null and wipe it, and for
        // title/template/display_order (NOT NULL columns) fail outright. Only
        // a field the caller actually sent should be touched.
        $changes = array_intersect_key($data, array_flip($updatable), $request->all());

        $this->collections->update((int) $collection['id'], $changes, $request->authUserId());

        return $this->adminView((string) $collection['slug']);
    }

    /**
     * Publishing refuses an empty page.
     *
     * A live campaign page with nothing on it is worse than no page: an advert
     * points at it, a customer clicks, and the shop looks broken.
     *
     * @return array<string, mixed>
     */
    public function setStatus(string $slugOrUuid, string $status, Request $request): array
    {
        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            throw new HttpException('Unknown status.', 422);
        }

        $collection = $this->resolve($slugOrUuid);

        if ($status === 'published') {
            $live = $this->collections->items((int) $collection['id'], publishedOnly: true);

            if ($live === []) {
                throw new HttpException('This page has nothing on sale to show.', 422, [
                    'items' => ['Add at least one product that is on sale before publishing.'],
                ]);
            }
        }

        $this->collections->update(
            (int) $collection['id'],
            ['status' => $status],
            $request->authUserId(),
        );

        return $this->adminView((string) $collection['slug']);
    }

    /**
     * @return array<string, mixed>
     */
    public function addItem(string $slugOrUuid, array $data, Request $request): array
    {
        $collection = $this->resolve($slugOrUuid);
        $product = $this->products->findDetailBySlugOrUuid((string) ($data['product'] ?? ''), includeUnpublished: true);

        if ($product === null) {
            throw new HttpException('Unknown product.', 422, [
                'product' => ['No product with that name.'],
            ]);
        }

        $this->collections->addItem(
            (int) $collection['id'],
            (int) $product['id'],
            isset($data['headline']) && $data['headline'] !== '' ? (string) $data['headline'] : null,
            (int) ($data['display_order'] ?? 100),
            $request->authUserId(),
        );

        return $this->adminView((string) $collection['slug']);
    }

    public function removeItem(string $slugOrUuid, string $itemUuid, Request $request): array
    {
        $collection = $this->resolve($slugOrUuid);

        if (!$this->collections->removeItem($itemUuid, $request->authUserId())) {
            throw new HttpException('That product is not on this page.', 404);
        }

        return $this->adminView((string) $collection['slug']);
    }

    /**
     * The picture that leads the page and represents it in the home page's
     * festive gifts strip — a Ganesh Chaturthi hamper photo, an Eid crescent
     * banner, whatever the season calls for.
     *
     * @param array<string, mixed> $file A single entry from $_FILES
     *
     * @return array<string, mixed>
     */
    public function setHeroImage(string $slugOrUuid, array $file, Request $request): array
    {
        $collection = $this->resolve($slugOrUuid);
        $previousPath = $collection['hero_image_path'];

        $stored = $this->uploads->storeImage($file, 'collections');

        $this->collections->update(
            (int) $collection['id'],
            ['hero_image_path' => $stored['file_path']],
            $request->authUserId()
        );

        if ($previousPath !== null) {
            $this->uploads->delete($previousPath);
        }

        $this->audit->log(
            entityName: 'collections',
            entityId: (int) $collection['id'],
            action: 'set_hero_image',
            oldValues: ['hero_image_path' => $previousPath],
            newValues: ['hero_image_path' => $stored['file_path']],
            request: $request,
            entityUuid: (string) $collection['uuid'],
        );

        return $this->adminView((string) $collection['slug']);
    }

    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function resolve(string $slugOrUuid): array
    {
        $collection = $this->collections->findBySlug($slugOrUuid)
            ?? $this->collections->findByUuid($slugOrUuid);

        if ($collection === null) {
            throw new HttpException('No such page.', 404);
        }

        return $collection;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertDates(array $data): void
    {
        $starts = $data['starts_date'] ?? null;
        $ends = $data['ends_date'] ?? null;

        if ($starts !== null && $ends !== null && $ends <= $starts) {
            throw new HttpException('The end date must be after the start date.', 422, [
                'ends_date' => ['Choose a date after the start.'],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'uuid' => $row['uuid'],
            'slug' => $row['slug'],
            'title' => $row['title'],
            'subtitle' => $row['subtitle'],
            'intro' => $row['intro'],
            'template' => $row['template'],
            'cta_label' => $row['cta_label'],
            // Was built from getenv('APP_URL') directly, which this app's config
            // never populates (settings load from .env into Config, not into the
            // process environment) and which skipped the uploads/ prefix every
            // other image URL in this codebase gets — so this always pointed at
            // a URL with neither a host nor the right path. Same helper
            // BannerService/CategoryService already use for their own images.
            'hero_image_url' => $this->uploads->publicUrl($row['hero_image_path']),
            'hero_alt_text' => $row['hero_alt_text'],
            'meta_title' => $row['meta_title'] ?? $row['title'],
            'meta_description' => $row['meta_description'] ?? $row['subtitle'],
        ];
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function presentItem(array $item): array
    {
        // Built from the catalogue's OWN detail presenter, so a product on a
        // campaign page shows exactly the price, offers and pack sizes it shows
        // everywhere else. A campaign page quietly displaying a stale price is a
        // consumer-protection problem, not a cosmetic one — which is why this
        // reads live rather than copying values in at curation time.
        //
        // One query per product. For a curated page of a dozen items that is the
        // right trade: correctness now, and an obvious place to batch later if a
        // page ever grows large enough to matter.
        try {
            // detail() returns the product itself; the controller is what wraps
            // it in a 'product' key for the API response.
            $product = $this->productService->detail(
                (string) $item['slug'],
                includeUnpublished: true,
                countView: false,
            );

            return [
                'slug' => $product['slug'],
                'name' => $product['name'],
                'short_description' => $product['short_description'] ?? null,
                'pricing' => $product['pricing'] ?? null,
                'rating' => $product['rating'] ?? null,
                'flags' => $product['flags'] ?? null,
                'primary_image' => $product['primary_image'] ?? null,
                'weight_grams' => $product['weight_grams'] ?? null,
                'has_size_options' => $product['has_size_options'] ?? false,
                'category' => $product['category'] ?? null,
                'headline' => $item['headline'],
            ];
        } catch (\Throwable $exception) {
            // A product that cannot be presented must not take the page down
            // with it — but swallowing the reason silently hid a bug for a whole
            // round of testing, so it goes to the log.
            error_log(sprintf(
                'Collection card failed for "%s": %s',
                (string) $item['slug'],
                $exception->getMessage()
            ));

            return [
                'slug' => $item['slug'],
                'name' => $item['name'],
                'headline' => $item['headline'],
            ];
        }
    }
}
