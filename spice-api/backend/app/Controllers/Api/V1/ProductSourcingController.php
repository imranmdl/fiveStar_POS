<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * When each product was added, by whom, and which vendor(s) it was bought from.
 *
 * The vendor comes from purchase inward: a product has a vendor once stock of
 * it has been received on a purchase order. A product that has never been
 * purchased (added to the catalogue only) simply has none — that is reported
 * as an empty list, not guessed at.
 */
final class ProductSourcingController extends BaseController
{
    public function __construct(private readonly Database $db)
    {
    }

    /** GET /api/v1/admin/products/sourcing?product_uuids=a,b,c */
    public function lookup(Request $request): Response
    {
        $uuids = array_values(array_unique(array_filter(
            array_map('trim', explode(',', (string) $request->query('product_uuids', ''))),
            static fn (string $u): bool => preg_match('/^[0-9a-f-]{36}$/i', $u) === 1
        )));
        $uuids = array_slice($uuids, 0, 100);

        if ($uuids === []) {
            return Response::success([], 'Nothing to look up');
        }

        $bindings = [];
        $placeholders = [];

        foreach ($uuids as $i => $uuid) {
            $bindings['u' . $i] = $uuid;
            $placeholders[] = ':u' . $i;
        }

        $in = implode(',', $placeholders);

        $products = $this->db->select(
            "SELECT p.`id`, p.`uuid`, p.`created_date`, u.`full_name` AS `added_by`
               FROM `products` p
               LEFT JOIN `users` u ON u.`id` = p.`created_by`
              WHERE p.`uuid` IN ({$in})",
            $bindings
        );

        $vendors = $this->db->select(
            "SELECT p.`uuid` AS `product_uuid`, ve.`name` AS `vendor_name`,
                    MAX(po.`purchase_date`) AS `last_purchase_date`, COUNT(DISTINCT po.`id`) AS `purchases`
               FROM `products` p
               INNER JOIN `product_variants` v ON v.`product_id` = p.`id`
               INNER JOIN `purchase_order_items` poi ON poi.`product_variant_id` = v.`id` AND poi.`is_deleted` = 0
               INNER JOIN `purchase_orders` po ON po.`id` = poi.`purchase_order_id` AND po.`is_deleted` = 0
               INNER JOIN `vendors` ve ON ve.`id` = po.`vendor_id`
              WHERE p.`uuid` IN ({$in})
              GROUP BY p.`uuid`, ve.`id`, ve.`name`
              ORDER BY `last_purchase_date` DESC",
            $bindings
        );

        $result = [];

        foreach ($products as $product) {
            $result[$product['uuid']] = [
                'added_date' => $product['created_date'],
                'added_by' => $product['added_by'],
                'vendors' => [],
            ];
        }

        foreach ($vendors as $row) {
            $result[$row['product_uuid']]['vendors'][] = [
                'name' => $row['vendor_name'],
                'last_purchase_date' => $row['last_purchase_date'],
                'purchases' => (int) $row['purchases'],
            ];
        }

        return Response::success($result, 'Product sourcing loaded');
    }
}
