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

        if (!isset($files['image'])) {
            throw new HttpException('A banner needs artwork.', 422, [
                'image' => ['Upload the desktop or wide banner image.'],
            ]);
        }

        $wide = $this->uploads->storeImage($files['image'], 'banners');
        $mobile = isset($files['mobile_image'])
            ? $this->uploads->storeImage($files['mobile_image'], 'banners')
            : null;

        try {
            $bannerId = $this->banners->create([
                'title' => $data['title'],
                'subtitle' => $data['subtitle'] ?? null,
                'image_path' => $wide['file_path'],
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
            $this->uploads->delete($wide['file_path']);
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

        $changes = array_intersect_key($data, array_flip([
            'title', 'subtitle', 'alt_text', 'placement', 'link_type', 'link_value',
            'cta_label', 'display_order', 'start_date', 'end_date', 'is_active',
        ]));

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
