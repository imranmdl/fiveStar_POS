<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Repositories\BannerRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\CollectionRepository;
use App\Repositories\ProductRepository;

final class BannerService
{
    public const PLACEMENTS = ['home_hero', 'home_strip', 'category_top', 'app_home', 'checkout'];

    public function __construct(
        private readonly BannerRepository $banners,
        private readonly CategoryRepository $categories,
        private readonly ProductRepository $products,
        private readonly CollectionRepository $collections,
        private readonly FileUploadService $uploads,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function liveForPlacement(string $placement): array
    {
        $this->assertPlacement($placement);

        $banners = $this->banners->liveForPlacement($placement);

        if ($banners !== []) {
            $this->banners->recordImpressions($placement);
        }

        return array_map(fn (array $row): array => [
            'uuid' => $row['uuid'],
            'title' => $row['title'],
            'subtitle' => $row['subtitle'],
            'image_url' => $this->uploads->publicUrl($row['image_path']),
            'mobile_image_url' => $this->uploads->publicUrl($row['mobile_image_path'])
                ?? $this->uploads->publicUrl($row['image_path']),
            'alt_text' => $row['alt_text'] ?? $row['title'],
            'link' => [
                'type' => $row['link_type'],
                'value' => $row['link_value'],
            ],
            'cta_label' => $row['cta_label'],
            'eyebrow' => $row['eyebrow'],
            'promo_code' => $row['promo_code'],
            'bg_color' => $row['bg_color'],
            'text_color' => $row['text_color'],
            'layout' => $row['layout'] ?? 'split',
        ], $banners);
    }

    public function recordClick(string $uuid): void
    {
        if (!$this->banners->recordClick($uuid)) {
            throw new NotFoundException('That banner does not exist.');
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $files $_FILES for this request
     *
     * @return array<string, mixed>
     */
    public function create(array $data, array $files, Request $request): array
    {
        $this->assertPlacement((string) $data['placement']);
        $this->assertLinkTargetExists((string) $data['link_type'], $data['link_value'] ?? null);

        // Artwork is optional: a text banner (eyebrow, headline, body, button
        // and promo code on a coloured panel) needs no image at all.
        $this->assertColors($data);
        $hasImage = isset($files['image']) && (int) ($files['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $wide = $hasImage ? $this->uploads->storeImage($files['image'], 'banners') : null;
        $mobile = isset($files['mobile_image'])
            ? $this->uploads->storeImage($files['mobile_image'], 'banners')
            : null;

        try {
            $bannerId = $this->banners->create([
                'title' => $data['title'],
                'subtitle' => $data['subtitle'] ?? null,
                'image_path' => $wide['file_path'] ?? null,
                'eyebrow' => $data['eyebrow'] ?? null,
                'promo_code' => $data['promo_code'] ?? null,
                'bg_color' => strtolower((string) ($data['bg_color'] ?? '#2a2829')),
                'text_color' => strtolower((string) ($data['text_color'] ?? '#ffffff')),
                'layout' => ($data['layout'] ?? 'split') === 'image' ? 'image' : 'split',
                'mobile_image_path' => $mobile['file_path'] ?? null,
                'alt_text' => $data['alt_text'] ?? $data['title'],
                'placement' => $data['placement'],
                'link_type' => $data['link_type'] ?? 'none',
                'link_value' => $data['link_value'] ?? null,
                'cta_label' => $data['cta_label'] ?? null,
                'display_order' => (int) ($data['display_order'] ?? 100),
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
            ], $request->authUserId());
        } catch (\Throwable $exception) {
            $this->uploads->delete($wide['file_path'] ?? null);
            $this->uploads->delete($mobile['file_path'] ?? null);

            throw $exception;
        }

        $this->audit->log(
            entityName: 'banners',
            entityId: $bannerId,
            action: 'create',
            newValues: ['title' => $data['title'], 'placement' => $data['placement']],
            request: $request
        );

        return $this->present((array) $this->banners->findById($bannerId));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function update(string $uuid, array $data, Request $request): array
    {
        $banner = $this->requireBanner($uuid);

        if (!empty($data['placement'])) {
            $this->assertPlacement((string) $data['placement']);
        }

        if (array_key_exists('link_type', $data)) {
            $this->assertLinkTargetExists(
                (string) $data['link_type'],
                $data['link_value'] ?? $banner['link_value']
            );
        }

        $this->assertColors($data);

        $changes = array_intersect_key($data, array_flip([
            'title', 'subtitle', 'alt_text', 'placement', 'link_type', 'link_value',
            'cta_label', 'display_order', 'start_date', 'end_date', 'is_active',
            'eyebrow', 'promo_code', 'bg_color', 'text_color', 'layout',
        ]));

        foreach (['bg_color', 'text_color'] as $colorField) {
            if (isset($changes[$colorField])) {
                $changes[$colorField] = strtolower((string) $changes[$colorField]);
            }
        }

        if ($changes === []) {
            throw new HttpException('No changes were supplied.', 422);
        }

        $this->banners->update((int) $banner['id'], $changes, $request->authUserId());

        $this->audit->log(
            entityName: 'banners',
            entityId: (int) $banner['id'],
            action: 'update',
            oldValues: array_intersect_key($banner, $changes),
            newValues: $changes,
            request: $request,
            entityUuid: $uuid
        );

        return $this->present((array) $this->banners->findById((int) $banner['id']));
    }

    public function delete(string $uuid, Request $request): void
    {
        $banner = $this->requireBanner($uuid);

        $this->banners->softDelete((int) $banner['id'], $request->authUserId());
        $this->uploads->delete($banner['image_path']);
        $this->uploads->delete($banner['mobile_image_path']);

        $this->audit->log(
            entityName: 'banners',
            entityId: (int) $banner['id'],
            action: 'delete',
            oldValues: ['title' => $banner['title'], 'placement' => $banner['placement']],
            request: $request,
            entityUuid: $uuid
        );
    }

    /**
     * Uploads (or replaces) a banner's wide artwork.
     *
     * @param array<string, mixed> $file
     *
     * @return array<string, mixed>
     */
    public function replaceImage(string $uuid, array $file, Request $request, bool $mobile = false): array
    {
        $banner = $this->requireBanner($uuid);
        $stored = $this->uploads->storeImage($file, 'banners');
        $column = $mobile ? 'mobile_image_path' : 'image_path';

        $this->banners->update((int) $banner['id'], [$column => $stored['file_path']], $request->authUserId());
        $this->uploads->delete($banner[$column]);

        $this->audit->log(
            entityName: 'banners',
            entityId: (int) $banner['id'],
            action: 'replace_image',
            request: $request,
            entityUuid: $uuid
        );

        return $this->present((array) $this->banners->findById((int) $banner['id']));
    }

    /**
     * Turns a photo banner back into a text banner.
     *
     * @return array<string, mixed>
     */
    public function removeImage(string $uuid, Request $request): array
    {
        $banner = $this->requireBanner($uuid);

        $this->banners->update((int) $banner['id'], ['image_path' => null, 'mobile_image_path' => null], $request->authUserId());
        $this->uploads->delete($banner['image_path']);
        $this->uploads->delete($banner['mobile_image_path']);

        $this->audit->log(
            entityName: 'banners',
            entityId: (int) $banner['id'],
            action: 'remove_image',
            request: $request,
            entityUuid: $uuid
        );

        return $this->present((array) $this->banners->findById((int) $banner['id']));
    }

    /** @param array<string, mixed> $data */
    private function assertColors(array $data): void
    {
        $errors = [];

        foreach (['bg_color', 'text_color'] as $field) {
            if (isset($data[$field]) && preg_match('/^#[0-9a-fA-F]{6}$/', (string) $data[$field]) !== 1) {
                $errors[$field] = ['Use a colour like #c62d1f.'];
            }
        }

        if ($errors !== []) {
            throw new HttpException('Banner colours must be hex colours.', 422, $errors);
        }
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string, search:?string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginateForAdmin(array $params, ?string $placement = null): array
    {
        if ($placement !== null) {
            $this->assertPlacement($placement);
        }

        $result = $this->banners->paginateForAdmin($params, $placement);
        $result['items'] = array_map([$this, 'present'], $result['items']);

        return $result;
    }

    /**
     * A banner pointing at a deleted category or product is a dead end for the
     * customer, so the target is verified at save time rather than at click time.
     */
    private function assertLinkTargetExists(string $linkType, ?string $linkValue): void
    {
        if ($linkType === 'none') {
            return;
        }

        if ($linkValue === null || trim($linkValue) === '') {
            throw new HttpException('This link type needs a target.', 422, [
                'link_value' => ['Provide a slug, URL or offer code.'],
            ]);
        }

        if ($linkType === 'category' && $this->categories->findBySlug($linkValue) === null) {
            throw new HttpException('The linked category does not exist.', 422, [
                'link_value' => ['Unknown category: ' . $linkValue],
            ]);
        }

        if ($linkType === 'product'
            && $this->products->findDetailBySlugOrUuid($linkValue, includeUnpublished: true) === null) {
            throw new HttpException('The linked product does not exist.', 422, [
                'link_value' => ['Unknown product: ' . $linkValue],
            ]);
        }

        // A campaign page, checked the same way a product is: an advert pointing
        // at a page that does not exist is a dead end the customer discovers,
        // not the merchant.
        if ($linkType === 'collection') {
            if ($this->collections->findBySlug($linkValue) === null) {
                throw new HttpException('The linked campaign page does not exist.', 422, [
                    'link_value' => ['No campaign page with that address: ' . $linkValue],
                ]);
            }
        }

        if ($linkType === 'url' && filter_var($linkValue, FILTER_VALIDATE_URL) === false) {
            throw new HttpException('The link URL is not valid.', 422, [
                'link_value' => ['Enter a full URL including https://'],
            ]);
        }
    }

    private function assertPlacement(string $placement): void
    {
        if (!in_array($placement, self::PLACEMENTS, true)) {
            throw new HttpException(
                'Unknown banner placement: ' . $placement,
                422,
                ['placement' => ['Allowed values: ' . implode(', ', self::PLACEMENTS)]]
            );
        }
    }

    /** @return array<string, mixed> */
    private function requireBanner(string $uuid): array
    {
        $banner = $this->banners->findByUuid($uuid);

        if ($banner === null) {
            throw new NotFoundException('That banner does not exist.');
        }

        return $banner;
    }

    /** @param array<string, mixed> $row */
    private function present(array $row): array
    {
        return [
            'uuid' => $row['uuid'],
            'title' => $row['title'],
            'subtitle' => $row['subtitle'],
            'image_url' => $this->uploads->publicUrl($row['image_path']),
            'mobile_image_url' => $this->uploads->publicUrl($row['mobile_image_path']),
            'alt_text' => $row['alt_text'],
            'placement' => $row['placement'],
            'link' => ['type' => $row['link_type'], 'value' => $row['link_value']],
            'cta_label' => $row['cta_label'],
            'eyebrow' => $row['eyebrow'] ?? null,
            'promo_code' => $row['promo_code'] ?? null,
            'bg_color' => $row['bg_color'] ?? '#2a2829',
            'text_color' => $row['text_color'] ?? '#ffffff',
            'layout' => $row['layout'] ?? 'split',
            'display_order' => (int) $row['display_order'],
            'schedule' => ['start_date' => $row['start_date'], 'end_date' => $row['end_date']],
            'stats' => [
                'impressions' => (int) $row['impression_count'],
                'clicks' => (int) $row['click_count'],
                'click_through_rate' => (int) $row['impression_count'] > 0
                    ? round((int) $row['click_count'] / (int) $row['impression_count'] * 100, 2)
                    : 0.0,
            ],
            'is_active' => (bool) $row['is_active'],
            'created_date' => $row['created_date'],
        ];
    }
}
