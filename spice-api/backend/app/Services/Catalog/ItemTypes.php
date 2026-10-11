<?php

declare(strict_types=1);

namespace App\Services\Catalog;

/**
 * Business types a top-level category can have (categories.item_type,
 * inherited by its sub-categories). Decides which fields an item form shows
 * and the default GST rate. Keep in step with migration 060's ENUM.
 */
final class ItemTypes
{
    public const ALL = [
        'grocery', 'oils', 'clothing', 'footwear', 'toys', 'stationery',
        'perfumes', 'personal_care', 'pooja', 'home_kitchen', 'general',
    ];

    /** Validator rule fragment: "in:grocery,oils,…". */
    public static function rule(): string
    {
        return 'in:' . implode(',', self::ALL);
    }
}
