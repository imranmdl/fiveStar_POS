<?php

declare(strict_types=1);

namespace App\Repositories;

final class BannerRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'banners';
    }

    protected function fillable(): array
    {
        return [
            'title', 'subtitle', 'image_path', 'mobile_image_path', 'alt_text',
            'placement', 'link_type', 'link_value', 'cta_label', 'display_order',
            'start_date', 'end_date',
        ];
    }

    protected function sortable(): array
    {
        return ['id', 'title', 'placement', 'display_order', 'start_date', 'created_date'];
    }

    /**
     * Live banners for a placement. The schedule window is evaluated in SQL so
     * an expired banner can never be served, whatever the caller does.
     *
     * @return array<int, array<string, mixed>>
     */
    public function liveForPlacement(string $placement): array
    {
        return $this->db->select(
            'SELECT `uuid`, `title`, `subtitle`, `image_path`, `mobile_image_path`,
                    `alt_text`, `placement`, `link_type`, `link_value`, `cta_label`,
                    `display_order`, `start_date`, `end_date`
               FROM `banners`
              WHERE `placement` = :placement
                AND `is_active` = 1 AND `is_deleted` = 0
                AND (`start_date` IS NULL OR `start_date` <= NOW())
                AND (`end_date`   IS NULL OR `end_date`   >= NOW())
              ORDER BY `display_order` ASC, `created_date` DESC',
            ['placement' => $placement]
        );
    }

    /**
     * Counts one impression against every banner served for a placement.
     * Not audited: an impression is telemetry, not a business event.
     */
    public function recordImpressions(string $placement): void
    {
        $this->db->execute(
            'UPDATE `banners`
                SET `impression_count` = `impression_count` + 1
              WHERE `placement` = :placement AND `is_active` = 1 AND `is_deleted` = 0
                AND (`start_date` IS NULL OR `start_date` <= NOW())
                AND (`end_date`   IS NULL OR `end_date`   >= NOW())',
            ['placement' => $placement]
        );
    }

    public function recordClick(string $uuid): bool
    {
        return $this->db->execute(
            'UPDATE `banners` SET `click_count` = `click_count` + 1
              WHERE `uuid` = :uuid AND `is_deleted` = 0',
            ['uuid' => $uuid]
        ) > 0;
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string, search:?string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginateForAdmin(array $params, ?string $placement = null): array
    {
        $conditions = [];

        if ($placement !== null) {
            $conditions['placement'] = $placement;
        }

        return $this->paginateWhere($conditions, $params);
    }
}
