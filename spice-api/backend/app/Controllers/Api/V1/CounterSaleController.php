<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

/**
 * Counter (POS) sales, listed alongside online orders in the admin Orders page:
 * what was sold, whether it's fully paid, whether the customer has been handed
 * their items, and any reviews that customer has since left for those products.
 *
 * customer_name/customer_mobile resolve the same way the Invoice Tracking
 * Center's list does (COALESCE the linked account over the walk-in fields
 * typed at the till) — a sale rung up against a real logged-in customer must
 * show that customer, not "Walk-in", just because no walk-in name was typed.
 *
 * Administrator only — it exposes every cashier's sales.
 */
final class CounterSaleController extends BaseController
{
    public function __construct(private readonly Database $db)
    {
    }

    /** GET /api/v1/admin/counter-sales?delivery=&search=&page= */
    public function index(Request $request): Response
    {
        $params = $this->paginationParams($request, 'created_date', 50);
        $delivery = (string) $request->query('delivery', '');
        $search = trim((string) $request->query('search', ''));

        $where = ["s.`is_deleted` = 0", "s.`status` = 'completed'"];
        $bind = [];

        if (in_array($delivery, ['delivered', 'pending'], true)) {
            $where[] = 's.`delivery_status` = :delivery';
            $bind['delivery'] = $delivery;
        }

        if ($search !== '') {
            $where[] = '(s.`sale_number` LIKE :s1 OR s.`walk_in_mobile` LIKE :s2 OR s.`walk_in_name` LIKE :s3)';
            $bind['s1'] = $bind['s2'] = $bind['s3'] = '%' . $search . '%';
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM `pos_sales` s WHERE {$whereSql}", $bind);

        $sales = $this->db->select(
            "SELECT s.`id`, s.`uuid`, s.`sale_number`, s.`created_date`, s.`payment_method`, s.`grand_total`,
                    s.`delivery_status`, s.`delivered_date`, s.`shop_label`,
                    s.`payment_status`, s.`amount_paid`, (s.`grand_total` - s.`amount_paid`) AS `balance_due`,
                    COALESCE(c.`full_name`, s.`walk_in_name`, 'Walk-in customer') AS `customer_name`,
                    COALESCE(c.`mobile`, s.`walk_in_mobile`) AS `customer_mobile`,
                    u.`full_name` AS `cashier_name`, w.`name` AS `shop`
               FROM `pos_sales` s
               INNER JOIN `users` u ON u.`id` = s.`cashier_id`
               INNER JOIN `warehouses` w ON w.`id` = s.`warehouse_id`
               LEFT JOIN `users` c ON c.`id` = s.`customer_id`
              WHERE {$whereSql}
              ORDER BY s.`created_date` DESC
              LIMIT {$params['per_page']} OFFSET {$params['offset']}",
            $bind
        );

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $sales);
        $itemsBySale = [];
        $reviewsBySale = [];

        if ($ids !== []) {
            $in = implode(',', $ids);

            foreach ($this->db->select(
                "SELECT `pos_sale_id`, `product_name`, `variant_name`, `quantity`
                   FROM `pos_sale_items` WHERE `pos_sale_id` IN ({$in}) AND `is_deleted` = 0"
            ) as $item) {
                $itemsBySale[(int) $item['pos_sale_id']][] = [
                    'name' => $item['product_name'] . ' — ' . $item['variant_name'],
                    'quantity' => (float) $item['quantity'],
                ];
            }

            // Reviews left by the customer this sale belongs to (linked account, or
            // the account whose mobile matches the number typed at the till), on
            // the products that were in the sale.
            foreach ($this->db->select(
                "SELECT DISTINCT s.`id` AS `sale_id`, p.`name` AS `product_name`, r.`rating`, r.`title`, r.`body`,
                        r.`status`, r.`created_date`, cu.`full_name` AS `reviewer`
                   FROM `pos_sales` s
                   INNER JOIN `pos_sale_items` psi ON psi.`pos_sale_id` = s.`id` AND psi.`is_deleted` = 0
                   INNER JOIN `product_variants` v ON v.`id` = psi.`product_variant_id`
                   INNER JOIN `products` p ON p.`id` = v.`product_id`
                   INNER JOIN `users` cu
                           ON cu.`id` = s.`customer_id`
                           OR (s.`walk_in_mobile` IS NOT NULL AND s.`walk_in_mobile` <> ''
                               AND RIGHT(REGEXP_REPLACE(s.`walk_in_mobile`, '[^0-9]', ''), 10) = RIGHT(cu.`mobile`, 10))
                   INNER JOIN `product_reviews` r
                           ON r.`product_id` = p.`id` AND r.`user_id` = cu.`id` AND r.`is_deleted` = 0
                  WHERE s.`id` IN ({$in})"
            ) as $review) {
                $reviewsBySale[(int) $review['sale_id']][] = [
                    'product' => $review['product_name'],
                    'rating' => (int) $review['rating'],
                    'title' => $review['title'],
                    'body' => $review['body'],
                    'status' => $review['status'],
                    'reviewer' => $review['reviewer'],
                    'date' => $review['created_date'],
                ];
            }
        }

        $rows = array_map(static fn (array $s): array => [
            'uuid' => $s['uuid'],
            'sale_number' => $s['sale_number'],
            'created_date' => $s['created_date'],
            'cashier_name' => $s['cashier_name'],
            'shop' => $s['shop'],
            'shop_label' => $s['shop_label'],
            'customer_name' => $s['customer_name'],
            'customer_mobile' => $s['customer_mobile'],
            'payment_method' => $s['payment_method'],
            'payment_status' => $s['payment_status'],
            'amount_paid' => (float) $s['amount_paid'],
            'balance_due' => (float) $s['balance_due'],
            'grand_total' => (float) $s['grand_total'],
            'delivery_status' => $s['delivery_status'],
            'delivered_date' => $s['delivered_date'],
            'items' => $itemsBySale[(int) $s['id']] ?? [],
            'reviews' => $reviewsBySale[(int) $s['id']] ?? [],
        ], $sales);

        return $this->paginated($rows, $total, $params, 'Counter sales loaded');
    }
}
