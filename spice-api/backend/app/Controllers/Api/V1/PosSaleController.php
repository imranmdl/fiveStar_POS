<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Helpers\Money;
use App\Repositories\ProductVariantRepository;
use App\Repositories\UserRepository;
use App\Services\AuditService;
use App\Services\OfferService;
use App\Services\PosDuePaymentService;
use App\Services\LoyaltyService;
use App\Services\PosSaleService;
use App\Services\WalletService;

final class PosSaleController extends BaseController
{
    public function __construct(
        private readonly PosSaleService $sales,
        private readonly OfferService $offers,
        private readonly ProductVariantRepository $variants,
        private readonly UserRepository $users,
        private readonly WalletService $wallet,
        private readonly PosDuePaymentService $duePayments,
        private readonly AuditService $audit,
        private readonly LoyaltyService $loyalty,
    ) {
    }

    /**
     * GET /api/v1/admin/pos/customers?mobile=
     * Looks a registered customer up by mobile number so the till can attach
     * a sale to their account and offer their wallet balance — a walk-in
     * stays a walk-in until this finds someone.
     */
    public function findCustomer(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'mobile' => 'required|mobile_in',
        ]);

        $user = $this->users->findByMobile($data['mobile']);

        if ($user === null) {
            throw new NotFoundException('No customer account has that mobile number.');
        }

        return Response::success([
            'uuid' => $user['uuid'],
            'full_name' => $user['full_name'],
            'mobile' => $user['mobile'],
            'wallet' => $this->wallet->summary((int) $user['id']),
            'loyalty' => $this->loyalty->summary((int) $user['id']),
        ], 'Customer found');
    }

    /**
     * POST /api/v1/admin/pos/customers
     * Quick-registers a walk-in as a trackable customer — just enough (name
     * + mobile) to attach a Customer Due sale to somebody real. Most people
     * a small shop extends credit to have never signed up on the website or
     * app, so requiring findCustomer() to already know them would make
     * "Accept partial payment" useless for exactly the shoppers it exists
     * for. Idempotent on mobile: an existing account is handed back
     * unchanged rather than erroring — the cashier's goal is "make sure
     * there is someone to attach this sale to", not "create a new record".
     */
    public function createCustomer(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'full_name' => 'required|string|min:2|max:120',
            'mobile' => 'required|mobile_in',
        ]);

        $result = $this->users->findOrCreateWalkIn(trim($data['full_name']), $data['mobile'], $request->authUserId());
        $user = $result['row'];

        if ($result['created']) {
            $this->audit->log(
                entityName: 'users',
                entityId: (int) $user['id'],
                action: 'pos_quick_customer_created',
                newValues: ['full_name' => trim($data['full_name'])],
                request: $request,
            );
        }

        return Response::success([
            'uuid' => $user['uuid'],
            'full_name' => $user['full_name'],
            'mobile' => $user['mobile'],
            'wallet' => $this->wallet->summary((int) $user['id']),
        ], $result['created'] ? 'Customer created' : 'Customer already on file');
    }

    /**
     * GET /api/v1/admin/pos/offers?variant_uuid=&subtotal_so_far=&existing_quantity=
     * Up to 3 live offers applicable to a just-scanned line — the cashier
     * picks one (or none) before it's added to the bill. `existing_quantity`
     * is how many of this same variant are already on the bill (0 for a
     * brand-new line), needed for same-variant BOGO to know which
     * buy-X-get-Y block this scan completes. See
     * OfferService::applicableOffersForPosLine() for why free_delivery and
     * cheapest_eligible-scope BOGO never appear here.
     */
    public function offersForVariant(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'variant_uuid' => 'required|uuid',
            'subtotal_so_far' => 'nullable|numeric|min:0',
            'existing_quantity' => 'nullable|int|min:0',
        ]);

        $variant = $this->variants->findDetailByUuid($data['variant_uuid']);

        if ($variant === null) {
            throw new NotFoundException('That pack size does not exist.');
        }

        // The rest of the bill (JSON: [{variant_uuid, quantity}, …]) so offers that
        // depend on the whole basket — minimum spend, cheapest-one-free across
        // items — can be judged. Optional: without it only this item is considered.
        $cartLines = [];
        $rawCart = json_decode((string) $request->query('cart', ''), true);

        if (is_array($rawCart)) {
            foreach (array_slice($rawCart, 0, 100) as $entry) {
                $quantity = (int) ($entry['quantity'] ?? 0);
                $other = is_array($entry) && is_string($entry['variant_uuid'] ?? null)
                    ? $this->variants->findDetailByUuid($entry['variant_uuid'])
                    : null;

                if ($other === null || $quantity < 1) {
                    continue;
                }

                $cartLines[] = [
                    'variant_uuid' => $other['uuid'],
                    'product_id' => (int) $other['product_id'],
                    'category_id' => $other['category_id'] ?? null,
                    'category_parent_id' => $other['category_parent_id'] ?? null,
                    'unit_price_snapshot' => (string) ($entry['unit_price'] ?? $other['selling_price']),
                    'quantity' => $quantity,
                ];
            }
        }

        $candidates = $this->offers->applicableOffersForPosLine(
            [
                'variant_uuid' => $variant['uuid'],
                'product_id' => (int) $variant['product_id'],
                'category_id' => $variant['category_id'] ?? null,
                'category_parent_id' => $variant['category_parent_id'] ?? null,
                'unit_price' => $variant['selling_price'],
                'quantity' => 1,
            ],
            Money::fromDecimal((string) ($data['subtotal_so_far'] ?? '0')),
            (int) ($data['existing_quantity'] ?? 0),
            null,
            $cartLines
        );

        return Response::success(['offers' => $candidates], 'Applicable offers loaded');
    }

    /** GET /api/v1/admin/pos/sales */
    public function index(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'cashier_uuid' => 'nullable|uuid',
            'payment_method' => 'nullable|in:cash,upi,card,other',
            'status' => 'nullable|in:completed,voided',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        // Only an administrator sees every cashier's sales. Anyone else gets
        // their own, whatever filter they asked for.
        if ($request->authRole() !== 'administrator') {
            $filters['cashier_uuid'] = (string) $request->attribute('auth_user')['uuid'];
        }

        $params = $this->paginationParams($request, 'created_date', 50);
        $result = $this->sales->list($filters, $params);

        return $this->paginated($result['items'], $result['total'], $params, 'Sales loaded');
    }

    /** GET /api/v1/admin/pos/sales/{uuid} */
    public function show(Request $request): Response
    {
        $sale = $this->sales->detail((string) $request->routeParam('uuid'));

        // Same rule as the list: someone else's sale reads as "not found".
        if ($request->authRole() !== 'administrator' && (int) $sale['cashier_id'] !== (int) $request->authUserId()) {
            throw new NotFoundException('That sale does not exist.');
        }

        return Response::success($sale, 'Sale loaded');
    }

    /** POST /api/v1/admin/pos/sales */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'warehouse_uuid' => 'required|uuid',
            'customer_uuid' => 'nullable|uuid',
            'walk_in_name' => 'nullable|string|max:120',
            'walk_in_mobile' => 'nullable|mobile_in',
            'payment_method' => 'required|in:cash,upi,card,other',
            'amount_tendered' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
            'delivered' => 'nullable|boolean',
            'shop_label' => 'nullable|string|max:120',
            'wallet_applied' => 'nullable|numeric|min:0.01',
            // Knowingly leaving part (or all) of the bill due against a
            // registered customer, instead of the usual "paid in full at the
            // register" — see PosSaleService::create()'s $acceptPartial.
            'accept_partial' => 'nullable|boolean',
        ]);

        $lines = $this->readLines($request);

        $result = $this->sales->create(
            warehouseUuid: $data['warehouse_uuid'],
            customerUuid: $data['customer_uuid'] ?? null,
            walkInName: $data['walk_in_name'] ?? null,
            walkInMobile: $data['walk_in_mobile'] ?? null,
            lines: $lines,
            paymentMethod: $data['payment_method'],
            amountTendered: isset($data['amount_tendered']) ? (float) $data['amount_tendered'] : null,
            notes: $data['notes'] ?? null,
            request: $request,
            walletApplied: isset($data['wallet_applied']) ? (float) $data['wallet_applied'] : null,
            delivered: !isset($data['delivered']) || (bool) $data['delivered'],
            shopLabel: empty($data['shop_label']) ? null : trim((string) $data['shop_label']),
            acceptPartial: (bool) ($data['accept_partial'] ?? false),
        );

        return Response::created($result, 'Sale recorded');
    }

    /**
     * POST /api/v1/admin/pos/sales/{uuid}/payments
     * Records a payment against a credit sale's remaining balance — the
     * "Record due payment" action on a Customer Dues invoice.
     */
    public function recordPayment(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,upi,card,other',
            'payment_date' => 'nullable|date',
            'reference_number' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:255',
        ]);

        $result = $this->duePayments->record(
            (string) $request->routeParam('uuid'),
            [
                'amount' => (float) $data['amount'],
                'payment_method' => $data['payment_method'],
                'payment_date' => $data['payment_date'] ?? date('Y-m-d'),
                'reference_number' => $data['reference_number'] ?? null,
                'notes' => $data['notes'] ?? null,
            ],
            $request,
        );

        return Response::created($result, 'Payment recorded');
    }

    /** GET /api/v1/admin/pos/sales/{uuid}/payments — this sale's payment history. */
    public function paymentHistory(Request $request): Response
    {
        return Response::success(
            $this->duePayments->forSale((string) $request->routeParam('uuid')),
            'Payment history loaded'
        );
    }

    /**
     * GET /api/v1/admin/customers/{uuid}/dues
     * A customer's own outstanding balance across every credit sale they
     * have — "if a customer has multiple unpaid invoices, show the total
     * outstanding balance". Looked up from the Till when a registered
     * customer is attached, and from the Customer Dues admin page.
     */
    public function customerDues(Request $request): Response
    {
        return Response::success(
            $this->duePayments->customerDues((string) $request->routeParam('uuid')),
            'Customer dues loaded'
        );
    }

    /** GET /api/v1/admin/pos/dues — every credit sale, for the Customer Dues admin page and reports. */
    public function duesIndex(Request $request): Response
    {
        $filters = Validator::make($request->all(), [
            'customer_uuid' => 'nullable|uuid',
            'payment_status' => 'nullable|in:unpaid,partial,paid',
            'open_only' => 'nullable|boolean',
            'overdue_only' => 'nullable|boolean',
        ]);

        $params = $this->paginationParams($request, 'created_date', 50);
        $result = $this->duePayments->list($filters, $params);

        return $this->paginated($result['items'], $result['total'], $params, 'Customer dues loaded');
    }

    /** POST /api/v1/admin/pos/sales/{uuid}/deliver — the customer has now been handed their items. */
    public function deliver(Request $request): Response
    {
        $uuid = (string) $request->routeParam('uuid');
        $sale = $this->sales->detail($uuid);

        // A cashier can only hand over their own sales; an administrator, any.
        if ($request->authRole() !== 'administrator' && (int) $sale['cashier_id'] !== (int) $request->authUserId()) {
            throw new NotFoundException('That sale does not exist.');
        }

        return Response::success($this->sales->markDelivered($uuid, $request), 'Sale marked as delivered');
    }

    /** POST /api/v1/admin/pos/sales/{uuid}/void */
    public function void(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'reason' => 'required|string|min:3|max:255',
        ]);

        return Response::success(
            $this->sales->void((string) $request->routeParam('uuid'), $data['reason'], $request),
            'Sale voided'
        );
    }

    /** POST /api/v1/admin/pos/sales/{uuid}/refund */
    public function refund(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'reason' => 'required|string|min:3|max:255',
            // 'original' (handed back at the counter, the existing default) or
            // 'wallet' (credited to the sale's linked customer instead).
            'refund_method' => 'nullable|in:original,wallet',
        ]);

        $rows = $request->input('items');

        if (!is_array($rows) || $rows === []) {
            throw new HttpException('Select at least one line to refund.', 422, [
                'items' => ['Send an `items` array of {pos_sale_item_uuid, quantity}.'],
            ]);
        }

        $lines = array_map(fn (array $row): array => Validator::make($row, [
            'pos_sale_item_uuid' => 'required|uuid',
            'quantity' => 'required|numeric|min:0.001',
        ]), $rows);

        return Response::success(
            $this->sales->refund(
                (string) $request->routeParam('uuid'),
                $lines,
                $data['reason'],
                $request,
                $data['refund_method'] ?? 'original',
            ),
            'Refund recorded'
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function readLines(Request $request): array
    {
        $rows = $request->input('lines');

        if (!is_array($rows) || $rows === []) {
            throw new HttpException('A sale needs at least one line.', 422, [
                'lines' => ['Send a `lines` array with at least one entry.'],
            ]);
        }

        if (count($rows) > 200) {
            throw new HttpException('A sale can have at most 200 lines.', 422);
        }

        return array_map(fn (array $row): array => Validator::make($row, [
            'variant_uuid' => 'required|uuid',
            'quantity' => 'required|numeric|min:0.001',
            'unit_price' => 'required|numeric|min:0',
            'discount_amount' => 'nullable|numeric|min:0',
            'applied_offer_code' => 'nullable|string|max:40',
        ]), $rows);
    }
}
