<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Helpers\Money;
use App\Repositories\PosRefundItemRepository;
use App\Repositories\PosRefundRepository;
use App\Repositories\PosSaleItemRepository;
use App\Repositories\PosSalePaymentRepository;
use App\Repositories\PosSaleRepository;
use App\Repositories\ProductVariantRepository;
use App\Repositories\UserRepository;
use App\Repositories\WarehouseRepository;
use App\Services\Orders\NumberingService;

/**
 * Counter sales (brief §12). A sale draws from, and a void/refund restores,
 * the exact same inventory every other channel uses — one call each to the
 * unmodified InventoryService::recordMovement(), 'sale' on the way out,
 * 'return' on the way back, referenceType 'pos_sale' throughout (already
 * reserved in the enum since migration 014). There is no separate POS stock
 * balance anywhere in this class.
 *
 * Payment is cashier-attested (see the plan's Context note) — this class
 * never talks to PaymentGatewayInterface. A payment method is recorded, not
 * verified against a gateway, the same trust boundary
 * OrderService::approveCod() already uses for COD.
 */
final class PosSaleService
{
    public function __construct(
        private readonly PosSaleRepository $sales,
        private readonly PosSaleItemRepository $items,
        private readonly PosRefundRepository $refunds,
        private readonly PosRefundItemRepository $refundItems,
        private readonly ProductVariantRepository $variants,
        private readonly WarehouseRepository $warehouses,
        private readonly UserRepository $users,
        private readonly InventoryService $inventory,
        private readonly NumberingService $numbering,
        private readonly AuditService $audit,
        private readonly Database $db,
        private readonly WalletService $wallet,
        private readonly PosSalePaymentRepository $duePayments,
        private readonly LoyaltyService $loyalty,
        private readonly SettingsService $shopSettings,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $lines Each: variant_uuid, quantity, unit_price, discount_amount?
     *
     * @return array<string, mixed>
     */
    public function create(
        string $warehouseUuid,
        ?string $customerUuid,
        ?string $walkInName,
        ?string $walkInMobile,
        array $lines,
        string $paymentMethod,
        ?float $amountTendered,
        ?string $notes,
        Request $request,
        bool $delivered = true,
        ?string $shopLabel = null,
        ?float $walletApplied = null,
        bool $acceptPartial = false,
    ): array {
        $warehouse = $this->warehouses->findByUuid($warehouseUuid);

        if ($warehouse === null) {
            throw new NotFoundException('That warehouse does not exist.');
        }

        $cashierId = $request->authUserId();

        if ($cashierId === null) {
            throw new HttpException('Could not identify the signed-in cashier.', 401);
        }

        $customerId = null;

        if ($customerUuid !== null) {
            $customer = $this->users->findByUuid($customerUuid);

            if ($customer === null) {
                throw new HttpException('That customer does not exist.', 422, ['customer_uuid' => ['Unknown customer.']]);
            }

            $customerId = (int) $customer['id'];
        }

        // Plain float arithmetic for aggregation — the same convention
        // PurchaseOrderService already uses for qty * money-value, since
        // quantity can be fractional (weight) and Money has no divide/
        // fractional-multiply operation. Money is still used for the one
        // operation that genuinely needs paise precision: extracting GST
        // from an inclusive price.
        $resolvedLines = [];
        $subtotal = 0.0;
        $discountTotal = 0.0;
        $taxTotal = 0.0;
        $grandTotal = 0.0;

        foreach ($lines as $index => $line) {
            $variant = $this->variants->findDetailByUuid((string) $line['variant_uuid']);

            if ($variant === null) {
                throw new HttpException(
                    sprintf('Line %d: that pack size does not exist.', $index + 1),
                    422,
                    ['lines' => ['Every line needs a valid pack size.']]
                );
            }

            $quantity = (float) $line['quantity'];
            $unitPrice = (float) $line['unit_price'];
            $lineDiscount = (float) ($line['discount_amount'] ?? 0);
            $gstRate = (float) $variant['gst_rate'];

            $grossLine = round($quantity * $unitPrice, 2);
            $netLine = max(0.0, round($grossLine - $lineDiscount, 2));
            $taxAmount = Money::fromDecimal((string) $netLine)->extractInclusiveTax($gstRate)['tax']->toDecimal();

            $resolvedLines[] = [
                'variant_id' => (int) $variant['id'],
                'sku' => $variant['sku'],
                'product_name' => $variant['product_name'],
                'variant_name' => $variant['variant_name'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                // Display only (printed receipt): the pack's MRP today.
                'mrp' => isset($variant['mrp']) && $variant['mrp'] !== null ? (float) $variant['mrp'] : null,
                'discount_amount' => $lineDiscount,
                'applied_offer_code' => $line['applied_offer_code'] ?? null,
                'gst_rate' => $gstRate,
                'tax_amount' => $taxAmount,
                'line_total' => $netLine,
            ];

            $subtotal += $grossLine;
            $discountTotal += $lineDiscount;
            $taxTotal += $taxAmount;
            $grandTotal += $netLine;
        }

        if ($resolvedLines === []) {
            throw new HttpException('A sale needs at least one line.', 422, ['lines' => ['Add at least one item.']]);
        }

        $subtotal = round($subtotal, 2);
        $discountTotal = round($discountTotal, 2);
        $taxTotal = round($taxTotal, 2);
        $grandTotal = round($grandTotal, 2);

        // Wallet credit belongs to an account, not a walk-in — only a sale
        // linked to a registered customer can draw on one, the same rule
        // checkout's own wallet redemption already applies.
        $walletApplied = round(max(0.0, (float) ($walletApplied ?? 0)), 2);

        if ($walletApplied > 0 && $customerId === null) {
            throw new HttpException(
                'Wallet credit can only be used for a sale linked to a registered customer.',
                422,
                ['wallet_applied' => ['Look up the customer first.']]
            );
        }

        if ($walletApplied > $grandTotal + 0.001) {
            throw new HttpException('The wallet amount cannot be more than the sale total.', 422, [
                'wallet_applied' => ['Must not exceed the sale total.'],
            ]);
        }

        // What's left to collect by the chosen payment method, after wallet
        // credit covers its share — cash tendered (and the change owed) is
        // judged against this remainder, not the full bill.
        $remainder = round($grandTotal - $walletApplied, 2);
        $changeDue = null;
        $amountPaidAtCreation = $grandTotal;
        $paymentStatus = 'paid';

        if ($acceptPartial) {
            // Leaving a balance due needs somebody to collect it from later —
            // not a name typed on a receipt, an actual account the till (and
            // the Customer Dues screen) can look back up. A walk-in mobile
            // number is enough: findOrCreateWalkIn() attaches the existing
            // account for that number, or creates one on the spot, so no
            // separate "register this customer first" step blocks the sale.
            if ($customerId === null) {
                $mobileForDue = trim((string) ($walkInMobile ?? ''));

                if ($mobileForDue === '') {
                    throw new HttpException(
                        'A partial payment / customer due needs at least a mobile number, so there is someone to collect the rest from later.',
                        422,
                        ['walk_in_mobile' => ['Required to leave a balance due.']]
                    );
                }

                $resolved = $this->users->findOrCreateWalkIn(
                    trim((string) ($walkInName ?? '')) ?: 'Walk-in customer',
                    $mobileForDue,
                    $cashierId,
                );
                $customerId = (int) $resolved['row']['id'];
            }

            $collectedNow = round(max(0.0, (float) ($amountTendered ?? 0)), 2);

            if ($collectedNow > $remainder + 0.001) {
                throw new HttpException(
                    'The amount collected cannot be more than the bill — there is no change to give back on a due sale.',
                    422,
                    ['amount_tendered' => ['Must not exceed the amount left after wallet credit.']]
                );
            }

            $amountTendered = $collectedNow > 0 ? $collectedNow : null;
            $amountPaidAtCreation = round($walletApplied + $collectedNow, 2);
            $paymentStatus = PurchaseOrderService::derivePaymentStatus($amountPaidAtCreation, $grandTotal);
        } elseif ($paymentMethod === 'cash' && $remainder > 0.001) {
            if ($amountTendered === null) {
                throw new HttpException('Enter the amount tendered for a cash sale.', 422, [
                    'amount_tendered' => ['Required for cash payments.'],
                ]);
            }

            if ($amountTendered < $remainder - 0.001) {
                throw new HttpException('The amount tendered is less than the amount still due.', 422, [
                    'amount_tendered' => ['Must be at least the amount left after wallet credit.'],
                ]);
            }

            $changeDue = round($amountTendered - $remainder, 2);
        }

        $saleId = $this->db->transaction(function () use (
            $warehouse,
            $cashierId,
            $customerId,
            $walkInName,
            $walkInMobile,
            $paymentMethod,
            $amountTendered,
            $changeDue,
            $notes,
            $resolvedLines,
            $subtotal,
            $discountTotal,
            $taxTotal,
            $grandTotal,
            $request,
            $delivered,
            $shopLabel,
            $walletApplied,
            $acceptPartial,
            $amountPaidAtCreation,
            $paymentStatus,
        ): int {
            $saleNumber = $this->numbering->nextPosSaleNumber();

            // Debited before the sale row exists, same as checkout's own wallet
            // redemption (CheckoutService::place()) — the idempotency key is
            // keyed off the sale number reserved just above, so a retried
            // request (the sale insert failing after this, say) cannot debit
            // twice; WalletService::debit() itself also refuses to overdraw.
            if ($walletApplied > 0) {
                $this->wallet->debit(
                    userId: (int) $customerId,
                    amount: Money::fromDecimal(number_format($walletApplied, 2, '.', '')),
                    source: WalletService::SOURCE_REDEMPTION,
                    narration: 'Used for POS sale ' . $saleNumber,
                    idempotencyKey: 'pos:' . $saleNumber . ':wallet',
                    referenceType: 'pos_sales',
                    referenceId: $saleNumber,
                    request: $request,
                );
            }

            $saleId = $this->sales->create([
                'sale_number' => $saleNumber,
                'cashier_id' => $cashierId,
                'warehouse_id' => (int) $warehouse['id'],
                'shop_label' => $shopLabel,
                'customer_id' => $customerId,
                'walk_in_name' => $customerId === null ? $walkInName : null,
                'walk_in_mobile' => $customerId === null ? $walkInMobile : null,
                'payment_method' => $paymentMethod,
                'subtotal' => number_format($subtotal, 2, '.', ''),
                'discount_amount' => number_format($discountTotal, 2, '.', ''),
                'tax_amount' => number_format($taxTotal, 2, '.', ''),
                'wallet_applied' => number_format($walletApplied, 2, '.', ''),
                'grand_total' => number_format($grandTotal, 2, '.', ''),
                'payment_status' => $paymentStatus,
                'amount_paid' => number_format($amountPaidAtCreation, 2, '.', ''),
                'is_credit_sale' => $acceptPartial ? 1 : 0,
                'amount_tendered' => $amountTendered === null ? null : number_format($amountTendered, 2, '.', ''),
                'change_due' => $changeDue === null ? null : number_format($changeDue, 2, '.', ''),
                'status' => 'completed',
                // Handed over at the counter (the normal case), or still to be delivered.
                'delivery_status' => $delivered ? 'delivered' : 'pending',
                'delivered_date' => $delivered ? date('Y-m-d H:i:s') : null,
                'notes' => $notes,
            ], $cashierId);

            // A credit sale's opening payment (whatever was actually collected
            // at the register, wallet included) is itself the first entry in
            // its payment history — pos_sales.amount_paid must stay exactly
            // equal to SUM(pos_sale_payments.amount) for this sale, the same
            // invariant vendor_payments holds for purchase_orders.amount_paid,
            // so a later due payment's recompute never under-counts what the
            // wallet already covered here.
            if ($acceptPartial) {
                $today = date('Y-m-d');

                if ($walletApplied > 0) {
                    $this->duePayments->create([
                        'pos_sale_id' => $saleId,
                        'customer_id' => $customerId,
                        'amount' => number_format($walletApplied, 2, '.', ''),
                        'payment_method' => 'wallet',
                        'payment_date' => $today,
                        'status' => 'completed',
                        'notes' => 'Wallet credit applied at sale',
                    ], $cashierId);
                }

                if ($amountTendered !== null && (float) $amountTendered > 0) {
                    $this->duePayments->create([
                        'pos_sale_id' => $saleId,
                        'customer_id' => $customerId,
                        'amount' => number_format((float) $amountTendered, 2, '.', ''),
                        'payment_method' => $paymentMethod,
                        'payment_date' => $today,
                        'status' => 'completed',
                        'notes' => 'Collected at sale',
                    ], $cashierId);
                }
            }

            foreach ($resolvedLines as $line) {
                $this->items->create([
                    'pos_sale_id' => $saleId,
                    'product_variant_id' => $line['variant_id'],
                    'sku' => $line['sku'],
                    'product_name' => $line['product_name'],
                    'variant_name' => $line['variant_name'],
                    'quantity' => number_format($line['quantity'], 3, '.', ''),
                    'unit_price' => number_format($line['unit_price'], 2, '.', ''),
                    'mrp' => $line['mrp'] === null ? null : number_format($line['mrp'], 2, '.', ''),
                    'discount_amount' => number_format($line['discount_amount'], 2, '.', ''),
                    'applied_offer_code' => $line['applied_offer_code'],
                    'gst_rate' => number_format($line['gst_rate'], 2, '.', ''),
                    'tax_amount' => number_format($line['tax_amount'], 2, '.', ''),
                    'line_total' => number_format($line['line_total'], 2, '.', ''),
                ], $cashierId);

                $this->inventory->recordMovement(
                    variantId: $line['variant_id'],
                    warehouseId: (int) $warehouse['id'],
                    movementType: 'sale',
                    quantityDelta: -1 * $line['quantity'],
                    referenceType: 'pos_sale',
                    referenceId: $saleId,
                    reason: 'POS sale ' . $saleNumber,
                    performedBy: $cashierId,
                    request: $request,
                );
            }

            return $saleId;
        });

        $this->audit->log(
            entityName: 'pos_sales',
            entityId: $saleId,
            action: 'create',
            newValues: ['grand_total' => $grandTotal, 'payment_method' => $paymentMethod, 'line_count' => count($resolvedLines)],
            request: $request,
        );

        $freshSale = (array) $this->sales->findById($saleId);

        // Loyalty: a walk-in sale with no linked account has nowhere to earn
        // points, so this only fires when the till attached a real customer.
        if ($customerId !== null) {
            $this->loyalty->earnForPurchase(
                userId: $customerId,
                orderValue: Money::fromDecimal(number_format($grandTotal, 2, '.', '')),
                channel: 'pos',
                referenceType: 'pos_sales',
                referenceId: (string) $freshSale['sale_number'],
                request: $request,
            );
        }

        return $this->detail((string) $freshSale['uuid']);
    }

    public function void(string $uuid, string $reason, Request $request): array
    {
        $sale = $this->requireByUuid($uuid);

        if ($sale['status'] === 'voided') {
            throw new HttpException('This sale has already been voided.', 409);
        }

        $lines = $this->items->forSale((int) $sale['id']);
        $actorId = $request->authUserId();

        $this->db->transaction(function () use ($sale, $lines, $reason, $actorId, $request): void {
            // Wallet credit spent on this sale comes back, the same as
            // cancelling an online order returns its wallet_applied — a void
            // undoes the sale entirely, and the customer's money should not
            // stay spent against a sale that no longer exists.
            $walletApplied = (float) ($sale['wallet_applied'] ?? 0);

            if ($walletApplied > 0 && $sale['customer_id'] !== null) {
                $this->wallet->credit(
                    userId: (int) $sale['customer_id'],
                    amount: Money::fromDecimal(number_format($walletApplied, 2, '.', '')),
                    source: WalletService::SOURCE_ORDER_REFUND,
                    narration: 'POS sale ' . $sale['sale_number'] . ' voided: ' . $reason,
                    idempotencyKey: 'pos:' . $sale['sale_number'] . ':wallet:void',
                    referenceType: 'pos_sales',
                    referenceId: (string) $sale['sale_number'],
                    request: $request,
                );
            }

            foreach ($lines as $line) {
                // Restore only what hasn't already come back via a prior
                // partial refund — voiding after a refund must not
                // double-credit the stock the refund already restored.
                $remaining = (float) $line['quantity'] - (float) $line['refunded_quantity'];

                if ($remaining > 0) {
                    $this->inventory->recordMovement(
                        variantId: (int) $line['product_variant_id'],
                        warehouseId: (int) $sale['warehouse_id'],
                        movementType: 'return',
                        quantityDelta: $remaining,
                        referenceType: 'pos_sale',
                        referenceId: (int) $sale['id'],
                        reason: 'POS sale voided: ' . $reason,
                        performedBy: $actorId,
                        request: $request,
                    );
                }
            }

            $this->sales->update((int) $sale['id'], [
                'status' => 'voided',
                'voided_by' => $actorId,
                'voided_date' => date('Y-m-d H:i:s'),
                'void_reason' => $reason,
            ], $actorId);
        });

        $this->audit->log(
            entityName: 'pos_sales',
            entityId: (int) $sale['id'],
            action: 'void',
            newValues: ['reason' => $reason],
            request: $request,
            entityUuid: $uuid,
        );

        return $this->detail($uuid);
    }

    /**
     * @param array<int, array{pos_sale_item_uuid:string, quantity:float}> $refundLines
     * @param string $refundMethod 'original' (default — handed back at the counter,
     *                             unchanged) or 'wallet' (credited to the sale's
     *                             linked customer instead; refused for a walk-in,
     *                             same as wallet_applied at sale time).
     */
    public function refund(string $uuid, array $refundLines, string $reason, Request $request, string $refundMethod = 'original'): array
    {
        $sale = $this->requireByUuid($uuid);

        if ($sale['status'] === 'voided') {
            throw new HttpException('A voided sale cannot also be refunded.', 409);
        }

        if ($refundLines === []) {
            throw new HttpException('Select at least one line to refund.', 422);
        }

        if ($refundMethod === 'wallet' && $sale['customer_id'] === null) {
            throw new HttpException(
                'This sale has no registered customer attached, so it cannot be refunded to a wallet.',
                422,
                ['refund_method' => ['Only available for a sale linked to a registered customer.']]
            );
        }

        $actorId = $request->authUserId();
        $allItems = $this->items->forSale((int) $sale['id']);
        $byUuid = [];

        foreach ($allItems as $item) {
            $byUuid[$item['uuid']] = $item;
        }

        $resolved = [];
        $refundTotal = Money::zero();

        foreach ($refundLines as $index => $requested) {
            $item = $byUuid[$requested['pos_sale_item_uuid']] ?? null;

            if ($item === null) {
                throw new HttpException(sprintf('Refund line %d does not belong to this sale.', $index + 1), 422);
            }

            $requestedQty = (float) $requested['quantity'];
            $available = (float) $item['quantity'] - (float) $item['refunded_quantity'];

            if ($requestedQty <= 0 || $requestedQty > $available + 0.0005) {
                throw new HttpException(
                    sprintf('%s: only %s unit(s) remain refundable on this line.', $item['sku'], $available),
                    422
                );
            }

            // Same per-unit rate the line was actually sold at (post its own
            // discount), so a partial refund is proportionally fair.
            $perUnit = (float) $item['line_total'] / (float) $item['quantity'];
            $lineRefund = Money::fromDecimal((string) round($perUnit * $requestedQty, 2));

            $resolved[] = ['item' => $item, 'quantity' => $requestedQty, 'amount' => $lineRefund];
            $refundTotal = $refundTotal->add($lineRefund);
        }

        $refundId = $this->db->transaction(function () use ($sale, $resolved, $reason, $refundTotal, $actorId, $request, $refundMethod): int {
            $refundNumber = $this->numbering->nextPosRefundNumber();

            $refundId = $this->refunds->create([
                'refund_number' => $refundNumber,
                'pos_sale_id' => (int) $sale['id'],
                'refunded_by' => $actorId,
                'reason' => $reason,
                'refund_amount' => (string) $refundTotal,
                'refund_method' => $refundMethod,
            ], $actorId);

            if ($refundMethod === 'wallet') {
                $this->wallet->credit(
                    userId: (int) $sale['customer_id'],
                    amount: $refundTotal,
                    source: WalletService::SOURCE_ORDER_REFUND,
                    narration: 'Refund ' . $refundNumber . ' for POS sale ' . $sale['sale_number'] . ': ' . $reason,
                    idempotencyKey: 'pos-refund:' . $refundNumber . ':wallet',
                    referenceType: 'pos_refunds',
                    referenceId: $refundNumber,
                    request: $request,
                );
            }

            foreach ($resolved as $entry) {
                $this->refundItems->create([
                    'pos_refund_id' => $refundId,
                    'pos_sale_item_id' => (int) $entry['item']['id'],
                    'quantity' => number_format($entry['quantity'], 3, '.', ''),
                    'amount' => (string) $entry['amount'],
                ], $actorId);

                $this->items->addRefundedQuantity((int) $entry['item']['id'], $entry['quantity'], $actorId);

                $this->inventory->recordMovement(
                    variantId: (int) $entry['item']['product_variant_id'],
                    warehouseId: (int) $sale['warehouse_id'],
                    movementType: 'return',
                    quantityDelta: $entry['quantity'],
                    referenceType: 'pos_sale',
                    referenceId: (int) $sale['id'],
                    reason: 'POS refund ' . $refundNumber . ': ' . $reason,
                    performedBy: $actorId,
                    request: $request,
                );
            }

            return $refundId;
        });

        $this->audit->log(
            entityName: 'pos_refunds',
            entityId: $refundId,
            action: 'create',
            newValues: ['refund_amount' => $refundTotal->toDecimal(), 'reason' => $reason],
            request: $request,
        );

        return $this->detail($uuid);
    }

    /**
     * @param array<string, mixed> $filters
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function list(array $filters, array $params): array
    {
        return $this->sales->search($filters, $params);
    }

    /** @return array<string, mixed> */
    public function detail(string $uuid): array
    {
        $sale = $this->requireByUuid($uuid);
        $sale['items'] = $this->items->forSale((int) $sale['id']);
        // Shop name, address, contact and UPI ID for the printed receipt.
        $sale['shop'] = $this->shopSettings->shopDetails($sale);
        $sale['due_payments'] = $sale['is_credit_sale'] ? $this->duePayments->forSale((int) $sale['id']) : [];
        $sale['refunds'] = array_map(function (array $refund): array {
            $refund['items'] = $this->refundItems->forRefund((int) $refund['id']);

            return $refund;
        }, $this->refunds->forSale((int) $sale['id']));

        return $sale;
    }

    /**
     * Marks a counter sale as handed over to the customer. Idempotent: a sale
     * that is already delivered is returned unchanged.
     *
     * @return array<string, mixed>
     */
    public function markDelivered(string $uuid, Request $request): array
    {
        $sale = $this->requireByUuid($uuid);

        if ($sale['status'] === 'voided') {
            throw new HttpException('A voided sale cannot be delivered.', 409);
        }

        if ($sale['delivery_status'] !== 'delivered') {
            $this->sales->update((int) $sale['id'], [
                'delivery_status' => 'delivered',
                'delivered_date' => date('Y-m-d H:i:s'),
            ], $request->authUserId());
        }

        return $this->detail($uuid);
    }

    /** @return array<string, mixed> */
    private function requireByUuid(string $uuid): array
    {
        $sale = $this->sales->detailByUuid($uuid);

        if ($sale === null) {
            throw new NotFoundException('That sale does not exist.');
        }

        return $sale;
    }
}
