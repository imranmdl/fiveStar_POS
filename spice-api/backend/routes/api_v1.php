<?php

declare(strict_types=1);

use App\Controllers\Api\V1\AddressController;
use App\Controllers\Api\V1\AdminAuditLogController;
use App\Controllers\Api\V1\AdminPrivilegeAuthController;
use App\Controllers\Api\V1\AdminUserManagementController;
use App\Controllers\Api\V1\ApprovalController;
use App\Controllers\Api\V1\AuthController;
use App\Controllers\Api\V1\BackupController;
use App\Controllers\Api\V1\BannerController;
use App\Controllers\Api\V1\CollectionController;
use App\Controllers\Api\V1\CartController;
use App\Controllers\Api\V1\BulkOrderController;
use App\Controllers\Api\V1\CategoryController;
use App\Controllers\Api\V1\ContentController;
use App\Controllers\Api\V1\CounterSaleController;
use App\Controllers\Api\V1\CommissionController;
use App\Controllers\Api\V1\CourierController;
use App\Controllers\Api\V1\CheckoutController;
use App\Controllers\Api\V1\HealthController;
use App\Controllers\Api\V1\ImportController;
use App\Controllers\Api\V1\InwardSetupController;
use App\Controllers\Api\V1\InventoryController;
use App\Controllers\Api\V1\InvoiceController;
use App\Controllers\Api\V1\LeadController;
use App\Controllers\Api\V1\LoyaltyController;
use App\Controllers\Api\V1\ManualPaymentController;
use App\Controllers\Api\V1\PromotionController;
use App\Controllers\Api\V1\NotificationController;
use App\Controllers\Api\V1\OrderController;
use App\Controllers\Api\V1\PosCashierController;
use App\Controllers\Api\V1\PosSaleController;
use App\Controllers\Api\V1\PricingDecisionController;
use App\Controllers\Api\V1\PricingRuleController;
use App\Controllers\Api\V1\ExportController;
use App\Controllers\Api\V1\ProductController;
use App\Controllers\Api\V1\ProductSourcingController;
use App\Controllers\Api\V1\PurchaseCsvController;
use App\Controllers\Api\V1\PurchaseOrderController;
use App\Controllers\Api\V1\PurchaseReturnController;
use App\Controllers\Api\V1\RoleManagementController;
use App\Controllers\Api\V1\VendorPaymentController;
use App\Controllers\Api\V1\ReportController;
use App\Controllers\Api\V1\ReviewController;
use App\Controllers\Api\V1\SettingsController;
use App\Controllers\Api\V1\ShipmentController;
use App\Controllers\Api\V1\StaffController;
use App\Controllers\Api\V1\StockAuditController;
use App\Controllers\Api\V1\StoreReviewController;
use App\Controllers\Api\V1\SupportController;
use App\Controllers\Api\V1\VariantOptionController;
use App\Controllers\Api\V1\VendorController;
use App\Controllers\Api\V1\WalletController;
use App\Controllers\Api\V1\WarehouseController;
use App\Controllers\Api\V1\WebhookController;
use App\Controllers\Api\V1\WelcomeBonusController;
use App\Controllers\Api\V1\WishlistController;
use App\Core\Middleware\ActivityLogMiddleware;
use App\Core\Middleware\AdminPrivilegeMiddleware;
use App\Core\Middleware\AuthenticateMiddleware;
use App\Core\Middleware\OptionalAuthenticateMiddleware;
use App\Core\Middleware\AuthorizeRoleMiddleware;
use App\Core\Middleware\ThrottleMiddleware;
use App\Core\Router;

/**
 * API v1 route table — the single contract shared by the web app, the Android
 * app and the iOS app.
 *
 * Throttle values are deliberate rather than uniform: OTP issuing and password
 * login are what an attacker hammers, so they get the tightest windows, while
 * catalog reads are generous because they are the hot path for real customers.
 *
 * Route order matters: literal paths are declared before `{placeholder}` ones,
 * so /products/filters is never swallowed by /products/{identifier}.
 */
return static function (Router $router): void {
    $router->registerMiddleware([
        'auth' => AuthenticateMiddleware::class,
        // Recognises a signed-in caller without requiring one. Essential on the
        // cart routes: they must serve guests, but a logged-in customer has to
        // be identified or they silently get a guest cart.
        'auth.optional' => OptionalAuthenticateMiddleware::class,
        'role' => AuthorizeRoleMiddleware::class,
        'throttle' => ThrottleMiddleware::class,
        'activity' => ActivityLogMiddleware::class,
        // Admin Privilege Management panel: a separate authorization gate on
        // top of 'auth', resolved from the live permission matrix rather than
        // a hard-coded role list. See AdminPrivilegeMiddleware's own doc
        // comment. Registered as one more alias, alongside the others above —
        // nothing about them changes.
        'adminPrivilege' => AdminPrivilegeMiddleware::class,
    ]);

    $router->group('/api/v1', ['activity'], static function (Router $router): void {
        // ===================================================================
        // Public — no authentication
        // ===================================================================
        $router->get('/health', [HealthController::class, 'index']);

        // --- Authentication ------------------------------------------------
        $router->post('/auth/register', [AuthController::class, 'register'], ['throttle:5,600']);
        $router->post('/auth/register/verify', [AuthController::class, 'verifyRegistration'], ['throttle:10,600']);
        $router->post('/auth/otp/request', [AuthController::class, 'requestOtp'], ['throttle:5,600']);
        $router->post('/auth/login', [AuthController::class, 'login'], ['throttle:10,600']);
        $router->post('/auth/login/otp', [AuthController::class, 'loginWithOtp'], ['throttle:10,600']);
        $router->post('/auth/token/refresh', [AuthController::class, 'refresh'], ['throttle:30,600']);
        $router->post('/auth/password/forgot', [AuthController::class, 'forgotPassword'], ['throttle:5,900']);
        $router->post('/auth/password/reset', [AuthController::class, 'resetPassword'], ['throttle:10,900']);

        // --- Storefront catalog --------------------------------------------
        $router->get('/categories', [CategoryController::class, 'index'], ['throttle:300,60']);
        $router->get('/categories/{slug}', [CategoryController::class, 'show'], ['throttle:300,60']);

        $router->get('/products', [ProductController::class, 'index'], ['throttle:300,60']);
        $router->get('/products/filters', [ProductController::class, 'filters'], ['throttle:120,60']);
        $router->get('/products/{identifier}', [ProductController::class, 'show'], ['throttle:300,60']);

        // --- Marketing leads --------------------------------------------------
        // A website visitor leaving contact details, not a `users` account —
        // deliberately public, no auth, gated only by throttle.
        $router->post('/leads', [LeadController::class, 'store'], ['throttle:5,3600']);
        $router->post('/leads/{uuid}/unsubscribe', [LeadController::class, 'unsubscribe'], ['throttle:10,3600']);

        // Reviews of the shop itself, from the home page. Open to anyone to send,
        // but only staff-approved ones are ever shown.
        $router->get('/store-reviews', [StoreReviewController::class, 'index'], ['throttle:300,60']);
        $router->post('/store-reviews', [StoreReviewController::class, 'store'], ['throttle:5,3600']);

        $router->get('/banners', [BannerController::class, 'index'], ['throttle:300,60']);
        $router->post('/banners/{uuid}/click', [BannerController::class, 'click'], ['throttle:60,60']);

        // A campaign page. Public and unauthenticated: an advert points at it,
        // and a customer must be able to land there before signing in.
        // Registered before /collections/{slug} so 'index' isn't read as a slug.
        $router->get('/collections', [CollectionController::class, 'index'], ['throttle:300,60']);
        $router->get('/collections/{slug}', [CollectionController::class, 'show'], ['throttle:300,60']);

        // --- Payment webhooks ------------------------------------------------
        // Unauthenticated by design: a gateway carries no bearer token. The
        // signature is the authentication, checked before anything is acted on.
        // Throttled generously because gateways retry hard during an outage.
        $router->post('/webhooks/payment', [WebhookController::class, 'payment'], ['throttle:600,60']);
        $router->post('/webhooks/tracking', [WebhookController::class, 'tracking'], ['throttle:600,60']);

        // --- Content (public) --------------------------------------------------
        $router->get('/content/pages', [ContentController::class, 'pages']);
        $router->get('/content/pages/{slug}', [ContentController::class, 'page']);
        $router->get('/content/posts', [ContentController::class, 'posts']);
        $router->get('/content/posts/{slug}', [ContentController::class, 'post']);
        $router->get('/content/faq', [ContentController::class, 'faq']);
        $router->post('/content/faq/{uuid}/helpful', [ContentController::class, 'faqHelpful'], ['throttle:60,600']);

        // Reviews are public to read. Writing one needs an account and a
        // delivered order.
        $router->get('/products/{identifier}/reviews', [ReviewController::class, 'index']);

        // Anyone can raise a support ticket, including a guest whose payment
        // failed before they finished registering.
        $router->post('/support/tickets', [SupportController::class, 'open'], ['auth.optional', 'throttle:10,3600']);

        // --- Wholesale enquiries ----------------------------------------------
        // Open to guests: a business should not need an account to ask whether
        // we can supply them. Throttled hard, since it is an unauthenticated
        // write.
        // 'auth.optional' matters here: the enquiry records who submitted it, and
        // without it a signed-in customer's enquiry is stored as a guest's — they
        // would then be unable to view their own quotation.
        $router->post('/bulk-orders/enquiries', [BulkOrderController::class, 'submit'], ['auth.optional', 'throttle:5,3600']);

        // --- Guest order tracking --------------------------------------------
        // Needs the order number AND the mobile: an order number alone is
        // printed on the parcel label.
        $router->post('/orders/track', [OrderController::class, 'track'], ['throttle:30,600']);

        // --- Offers (merchandising campaigns) -------------------------------
        // /offers/product-lookup is registered before /offers/{code} so the
        // static path is never swallowed by the param route (see the
        // /products/filters vs /products/{identifier} note above).
        $router->get('/offers', [PromotionController::class, 'offers'], ['throttle:300,60']);
        $router->get(
            '/offers/product-lookup',
            [PromotionController::class, 'offersForProducts'],
            ['throttle:300,60']
        );
        $router->get('/offers/{code}', [PromotionController::class, 'offer'], ['throttle:300,60']);
        $router->get('/offers/{code}/products', [PromotionController::class, 'offerProducts'], ['throttle:300,60']);

        // --- Delivery pricing (public: the cart page needs it before login) --
        $router->get('/delivery/serviceability', [CartController::class, 'serviceability'], ['throttle:120,60']);
        $router->get('/delivery/rate-card', [CartController::class, 'rateCard'], ['throttle:60,60']);

        // --- Cart -----------------------------------------------------------
        // Guests are first-class here: the cart is keyed by the X-Cart-Token
        // header when there is no bearer token, so a shopper can fill a cart
        // before signing in and merge it afterwards.
        $router->get('/cart', [CartController::class, 'show'], ['auth.optional', 'throttle:300,60']);
        $router->post('/cart/items', [CartController::class, 'store'], ['auth.optional', 'throttle:120,60']);
        $router->patch('/cart/items/{uuid}', [CartController::class, 'update'], ['auth.optional', 'throttle:120,60']);
        $router->delete('/cart/items/{uuid}', [CartController::class, 'destroy'], ['auth.optional', 'throttle:120,60']);
        $router->post('/cart/items/{uuid}/save-for-later', [CartController::class, 'saveForLater'], ['auth.optional', 'throttle:120,60']);
        $router->post('/cart/items/{uuid}/move-to-cart', [CartController::class, 'moveToCart'], ['auth.optional', 'throttle:120,60']);
        $router->post('/cart/clear', [CartController::class, 'clear'], ['auth.optional', 'throttle:60,60']);
        $router->post('/cart/pincode', [CartController::class, 'setPincode'], ['auth.optional', 'throttle:120,60']);
        $router->post('/cart/price-changes/acknowledge', [CartController::class, 'acknowledgePriceChanges'], ['auth.optional', 'throttle:60,60']);

        // ===================================================================
        // Authenticated — any signed-in user
        // ===================================================================
        $router->get('/auth/me', [AuthController::class, 'me'], ['auth']);
        $router->get('/auth/sessions', [AuthController::class, 'sessions'], ['auth']);
        $router->post('/auth/logout', [AuthController::class, 'logout'], ['auth']);
        $router->post('/auth/password/change', [AuthController::class, 'changePassword'], ['auth', 'throttle:10,900']);

        // Merging a guest cart requires knowing who to merge it into.
        $router->post('/cart/merge', [CartController::class, 'merge'], ['auth', 'throttle:20,600']);

        // --- Coupons and wallet on the cart ---------------------------------
        // Coupons are account-scoped (per-customer limits, new-customer
        // audiences), so unlike the rest of the cart they require sign-in.
        $router->get('/cart/coupons', [PromotionController::class, 'availableCoupons'], ['auth', 'throttle:120,60']);
        $router->post('/cart/coupon', [CartController::class, 'applyCoupon'], ['auth', 'throttle:30,600']);
        $router->delete('/cart/coupon', [CartController::class, 'removeCoupon'], ['auth', 'throttle:60,60']);
        $router->post('/cart/wallet', [CartController::class, 'setWalletRedemption'], ['auth', 'throttle:60,60']);

        // --- Delivery addresses ----------------------------------------------
        $router->get('/addresses', [AddressController::class, 'index'], ['auth']);
        $router->post('/addresses', [AddressController::class, 'store'], ['auth', 'throttle:30,600']);
        $router->patch('/addresses/{uuid}', [AddressController::class, 'update'], ['auth']);
        $router->post('/addresses/{uuid}/default', [AddressController::class, 'makeDefault'], ['auth']);
        $router->delete('/addresses/{uuid}', [AddressController::class, 'destroy'], ['auth']);

        // --- Checkout (BR-003 OTP, BR-004 prepaid UPI) ------------------------
        $router->get('/checkout/review', [CheckoutController::class, 'review'], ['auth']);
        $router->post('/checkout/place', [CheckoutController::class, 'place'], ['auth', 'throttle:20,600']);
        $router->post('/checkout/orders/{uuid}/verify-otp', [CheckoutController::class, 'verifyOtp'], ['auth', 'throttle:10,600']);
        $router->post('/checkout/orders/{uuid}/resend-otp', [CheckoutController::class, 'resendOtp'], ['auth', 'throttle:5,600']);
        $router->post('/checkout/orders/{uuid}/payment', [CheckoutController::class, 'startPayment'], ['auth', 'throttle:20,600']);
        $router->post('/checkout/orders/{uuid}/cod', [CheckoutController::class, 'chooseCod'], ['auth', 'throttle:20,600']);
        $router->post('/checkout/orders/{uuid}/payment/callback', [CheckoutController::class, 'paymentCallback'], ['auth', 'throttle:30,600']);

        // --- Orders ----------------------------------------------------------
        $router->get('/orders', [OrderController::class, 'index'], ['auth']);
        $router->get('/orders/{uuid}', [OrderController::class, 'show'], ['auth']);
        $router->get('/orders/{uuid}/invoice', [OrderController::class, 'invoice'], ['auth']);
        $router->post('/orders/{uuid}/cancel', [OrderController::class, 'cancel'], ['auth', 'throttle:10,600']);
        $router->get('/orders/{uuid}/shipments', [ShipmentController::class, 'forOrder'], ['auth']);

        // --- Wholesale (customer side) ----------------------------------------
        $router->get('/bulk-orders/enquiries/{uuid}', [BulkOrderController::class, 'show'], ['auth']);
        $router->post('/bulk-orders/quotes/{uuid}/accept', [BulkOrderController::class, 'accept'], ['auth', 'throttle:10,600']);
        $router->post('/bulk-orders/quotes/{uuid}/reject', [BulkOrderController::class, 'reject'], ['auth', 'throttle:10,600']);

        // --- Reviews (customer) ------------------------------------------------
        $router->post('/products/{identifier}/reviews', [ReviewController::class, 'store'], ['auth', 'throttle:20,3600']);
        $router->get('/reviews/mine', [ReviewController::class, 'mine'], ['auth']);
        $router->get('/reviews/awaiting', [ReviewController::class, 'awaiting'], ['auth']);
        $router->post('/reviews/{uuid}/report', [ReviewController::class, 'report'], ['auth', 'throttle:20,3600']);
        $router->post('/reviews/{uuid}/vote', [ReviewController::class, 'vote'], ['auth', 'throttle:100,3600']);

        // --- Support (customer) -------------------------------------------------
        $router->get('/support/tickets', [SupportController::class, 'mine'], ['auth']);
        $router->get('/support/tickets/{uuid}', [SupportController::class, 'show'], ['auth']);
        $router->post('/support/tickets/{uuid}/reply', [SupportController::class, 'reply'], ['auth', 'throttle:30,3600']);
        $router->post('/support/tickets/{uuid}/rate', [SupportController::class, 'rate'], ['auth']);

        // --- Notification preferences ------------------------------------------
        $router->get('/notifications/preferences', [NotificationController::class, 'preferences'], ['auth']);
        $router->patch('/notifications/preferences', [NotificationController::class, 'updatePreferences'], ['auth']);
        $router->get('/notifications/history', [NotificationController::class, 'history'], ['auth']);

        // --- Wallet ----------------------------------------------------------
        $router->get('/wallet', [WalletController::class, 'show'], ['auth']);
        $router->get('/wallet/statement', [WalletController::class, 'statement'], ['auth']);

        // --- Referrals -------------------------------------------------------
        $router->get('/referrals', [WalletController::class, 'referralOverview'], ['auth']);
        $router->get('/referrals/history', [WalletController::class, 'referralHistory'], ['auth']);

        // --- Loyalty points ----------------------------------------------------
        $router->get('/loyalty', [LoyaltyController::class, 'show'], ['auth']);
        $router->get('/loyalty/statement', [LoyaltyController::class, 'statement'], ['auth']);
        $router->post('/loyalty/redeem', [LoyaltyController::class, 'redeem'], ['auth', 'throttle:10,600']);

        // --- Wishlist (no guest equivalent: it only helps if it persists) ----
        $router->get('/wishlist', [WishlistController::class, 'index'], ['auth']);
        $router->post('/wishlist', [WishlistController::class, 'store'], ['auth', 'throttle:120,60']);
        $router->get('/wishlist/contains', [WishlistController::class, 'contains'], ['auth', 'throttle:300,60']);
        $router->patch('/wishlist/{uuid}', [WishlistController::class, 'update'], ['auth']);
        $router->delete('/wishlist/{uuid}', [WishlistController::class, 'destroy'], ['auth']);
        $router->post('/wishlist/{uuid}/move-to-cart', [WishlistController::class, 'moveToCart'], ['auth', 'throttle:120,60']);

        // ===================================================================
        // Administration — administrator role only
        // ===================================================================
        $administrator = ['auth', 'role:administrator'];
        // Order fulfilment is day-to-day work for executives and supervisors,
        // not just administrators — restricting it to admins would put a
        // bottleneck on packing every parcel.
        $staff = ['auth', 'role:administrator,supervisor,executive'];
        // Distributing work and approving commission are supervisory acts. An
        // executive must not be able to assign orders to themselves or sign off
        // their own pay.
        $supervisory = ['auth', 'role:administrator,supervisor'];
        // Inventory roles (5Star inventory-first brief §13). `manager` approves
        // sensitive stock corrections; `inventoryStaff` is day-to-day inward and
        // stock lookup, opened to the same roles that already do that work
        // (administrator/supervisor) plus the two new ones.
        $manager = ['auth', 'role:administrator,supervisor,manager'];
        // executive gets vendor/purchase-order/inventory READ access plus
        // purchase-order creation (both $inventoryStaff-gated) — "limited
        // view/entry" per the vendor-management brief. It is deliberately NOT
        // in $manager, so recording a payment, correcting a posted line,
        // adjusting stock, or processing a purchase return all stay off
        // limits to it.
        $inventoryStaff = ['auth', 'role:administrator,supervisor,manager,inventory_staff,executive'];
        // Counter sales (brief §12/§13): a cashier rings up sales; void and
        // refund stay $manager, same reasoning as every other sensitive
        // stock/price-correcting action in this system.
        $cashier = ['auth', 'role:administrator,supervisor,manager,cashier'];
        // A cashier-only account (till.html's whole point — see console.js's
        // NAV comment) still needs to pick a warehouse and look an item up by
        // scan or name to ring anything up at all. Scoped to just those three
        // read-only lookups rather than added to $inventoryStaff itself, which
        // also gates vendors/purchase-orders/pricing-history a cashier has no
        // business touching.
        $posLookup = ['auth', 'role:administrator,supervisor,manager,inventory_staff,cashier'];

        $router->get('/admin/ping', [HealthController::class, 'adminPing'], $administrator);

        // --- Categories ----------------------------------------------------
        // Read access matches quick-create's own gate ($manager): a manager
        // can already create a new item via POST /admin/inventory/quick-create,
        // which needs a category_uuid — the category list itself must be
        // readable by whoever that action already trusts, not only administrators.
        $router->get('/admin/categories', [CategoryController::class, 'adminIndex'], $manager);
        $router->post('/admin/categories', [CategoryController::class, 'store'], $administrator);
        $router->patch('/admin/categories/{uuid}', [CategoryController::class, 'update'], $administrator);
        $router->post('/admin/categories/{uuid}/image', [CategoryController::class, 'storeImage'], $administrator);
        $router->delete('/admin/categories/{uuid}', [CategoryController::class, 'destroy'], $administrator);

        // --- Products ------------------------------------------------------
        // Registered before any /admin/products/{uuid} route so 'sourcing' is not read as a uuid.
        $router->get('/admin/products/sourcing', [ProductSourcingController::class, 'lookup'], $administrator);
        $router->get('/admin/products', [ProductController::class, 'adminIndex'], $administrator);
        $router->post('/admin/products', [ProductController::class, 'store'], $administrator);
        $router->get('/admin/products/{identifier}', [ProductController::class, 'adminShow'], $administrator);
        $router->patch('/admin/products/{uuid}', [ProductController::class, 'update'], $administrator);
        $router->post('/admin/products/{uuid}/publish', [ProductController::class, 'publish'], $administrator);
        $router->post('/admin/products/{uuid}/archive', [ProductController::class, 'archive'], $administrator);
        $router->delete('/admin/products/{uuid}', [ProductController::class, 'destroy'], $administrator);

        // --- Variants (pack sizes) -----------------------------------------
        $router->post('/admin/products/{uuid}/variants', [ProductController::class, 'storeVariant'], $administrator);
        $router->patch('/admin/variants/{uuid}', [ProductController::class, 'updateVariant'], $administrator);
        $router->delete('/admin/variants/{uuid}', [ProductController::class, 'destroyVariant'], $administrator);

        // --- Warehouses (5Star inventory-first brief) -----------------------
        $router->get('/admin/warehouses', [WarehouseController::class, 'index'], $posLookup);
        $router->post('/admin/warehouses', [WarehouseController::class, 'store'], $administrator);
        $router->get('/admin/warehouses/{uuid}', [WarehouseController::class, 'show'], $inventoryStaff);
        $router->patch('/admin/warehouses/{uuid}', [WarehouseController::class, 'update'], $administrator);
        $router->delete('/admin/warehouses/{uuid}', [WarehouseController::class, 'destroy'], $administrator);

        // --- Inventory: stock, ledger, manual adjustments -------------------
        // Reads are open to inventory staff for day-to-day lookup; adjust is
        // restricted to manager+ because it is the one endpoint in this phase
        // that can silently correct a stock figure (§13: sensitive actions
        // require appropriate permission and audit logging — see AuditService
        // calls inside InventoryService).
        $router->get('/admin/inventory/stock', [InventoryController::class, 'stock'], $inventoryStaff);
        $router->get('/admin/inventory/stock/{variantUuid}', [InventoryController::class, 'stockForVariant'], $inventoryStaff);
        $router->get('/admin/inventory/lookup', [InventoryController::class, 'lookup'], $posLookup);
        $router->get('/admin/inventory/search', [InventoryController::class, 'search'], $posLookup);
        // Generating/fetching a barcode to print is a labelling convenience,
        // not a sensitive write — open to the same tier as lookup/adjust.
        $router->post('/admin/inventory/variants/{variantUuid}/barcode', [InventoryController::class, 'assignBarcode'], $inventoryStaff);
        // Creating a catalog entry on the fly (unrecognised scanned barcode)
        // is as sensitive as any other new-product action, hence $manager —
        // not opened to inventoryStaff the way the lookup/adjust reads are.
        $router->post('/admin/inventory/quick-create', [ImportController::class, 'quickCreate'], $manager);
        $router->post('/admin/inventory/quick-category', [InwardSetupController::class, 'storeCategory'], $manager);
        $router->get('/admin/inventory/setup', [InwardSetupController::class, 'show'], $inventoryStaff);
        $router->get('/admin/inventory/low-stock', [InventoryController::class, 'lowStock'], $inventoryStaff);
        $router->get('/admin/inventory/movements', [InventoryController::class, 'movements'], $inventoryStaff);
        $router->get('/admin/inventory/reports/damage-loss', [InventoryController::class, 'damageLoss'], $inventoryStaff);
        $router->get('/admin/inventory/reports/expiry', [InventoryController::class, 'expiry'], $inventoryStaff);

        // --- Inventory: Recycle Bin (deleted products/pack sizes) ----------
        // Viewing the bin is a day-to-day inventory-staff lookup, same tier
        // as the Stock tab it mirrors. Restoring is exactly as consequential
        // as correcting a stock figure with adjust() above, hence $manager.
        // Permanent delete is $administrator-only — it mirrors the product
        // delete route (also $administrator) and, unlike everything else in
        // this module, cannot be undone.
        $router->get('/admin/inventory/deleted', [InventoryController::class, 'deleted'], $inventoryStaff);
        $router->post('/admin/inventory/deleted/{variantUuid}/restore', [InventoryController::class, 'restoreDeleted'], $manager);
        $router->delete('/admin/inventory/deleted/{variantUuid}', [InventoryController::class, 'permanentlyDelete'], $administrator);

        $router->post('/admin/inventory/adjust', [InventoryController::class, 'adjust'], $manager);
        $router->patch('/admin/inventory/reorder-threshold', [InventoryController::class, 'setReorderThreshold'], $manager);
        // Admin Privilege Management item 4 (sensitive-action approval),
        // reference wiring — see InventoryController::requestAdjustmentApproval's
        // own doc comment. Open to inventoryStaff: this only RAISES a request,
        // it does not adjust anything itself, so it can sit below $manager.
        $router->post('/admin/inventory/adjustments/request-approval', [InventoryController::class, 'requestAdjustmentApproval'], $inventoryStaff);

        // --- Bulk product/stock import (CSV/XLSX) — preview writes nothing,
        // same split as the vendor-bill parser above; confirm() creates
        // products/variants and posts stock movements, so it sits at
        // $manager like quick-create and the manual adjust endpoint.
        $router->get('/admin/imports', [ImportController::class, 'index'], $inventoryStaff);
        $router->get('/admin/imports/{uuid}', [ImportController::class, 'show'], $inventoryStaff);
        $router->post('/admin/imports/preview', [ImportController::class, 'preview'], $inventoryStaff);
        $router->post('/admin/imports/confirm', [ImportController::class, 'confirm'], $manager);

        // --- Vendors (5Star inventory-first brief, Phase 2) ------------------
        // Same split as warehouses: reads open to inventory staff (they pick a
        // vendor when recording inward), writes to administrator only.
        $router->get('/admin/vendors', [VendorController::class, 'index'], $inventoryStaff);
        $router->post('/admin/vendors', [VendorController::class, 'store'], $administrator);
        // /admin/vendors/dashboard before /admin/vendors/{uuid}, same static-
        // before-param ordering every other route table in this file follows.
        $router->get('/admin/vendors/dashboard', [VendorController::class, 'dashboard'], $inventoryStaff);
        $router->get('/admin/vendors/{uuid}', [VendorController::class, 'show'], $inventoryStaff);
        $router->get('/admin/vendors/{uuid}/history', [VendorController::class, 'history'], $inventoryStaff);
        $router->patch('/admin/vendors/{uuid}', [VendorController::class, 'update'], $administrator);
        $router->delete('/admin/vendors/{uuid}', [VendorController::class, 'destroy'], $administrator);
        $router->post(
            '/admin/import/vendors',
            [VendorController::class, 'importVendors'],
            array_merge($administrator, ['throttle:10,3600'])
        );

        // --- CSV export + sample templates: vendors, products, purchase
        // orders, inventory, inventory batches, vendor-payment transactions.
        $router->get('/admin/export/{entity}', [ExportController::class, 'export'], $inventoryStaff);
        $router->get('/admin/export/{entity}/template', [ExportController::class, 'template'], $inventoryStaff);

        // --- Purchase inward (immediate effect — see PurchaseOrderService) --
        // Creating one IS "inventory staff records inward" (§13), so this is
        // opened to inventoryStaff, not restricted to manager like the
        // generic manual-adjustment endpoint above.
        $router->get('/admin/purchase-orders', [PurchaseOrderController::class, 'index'], $inventoryStaff);
        $router->post('/admin/purchase-orders', [PurchaseOrderController::class, 'store'], $inventoryStaff);
        $router->get('/admin/purchase-orders/{uuid}', [PurchaseOrderController::class, 'show'], $inventoryStaff);
        $router->post('/admin/purchase-orders/items/parse-bill', [PurchaseCsvController::class, 'parse'], $inventoryStaff);
        $router->get('/admin/purchase-orders/items/bill-template', [PurchaseCsvController::class, 'template'], $inventoryStaff);
        $router->get('/admin/purchase-orders/items/bill-sample', [PurchaseCsvController::class, 'sample'], $inventoryStaff);
        $router->post('/admin/inventory/quick-create-batch', [PurchaseCsvController::class, 'createItems'], $manager);
        $router->post('/admin/purchase-orders/items/parse-csv', [PurchaseOrderController::class, 'parseItemsCsv'], $inventoryStaff);
        // Settling a vendor bill is money-sensitive, same tier as POS void/
        // refund and inventory-adjust — not opened to inventoryStaff.
        $router->patch('/admin/purchase-orders/{uuid}/payment', [PurchaseOrderController::class, 'updatePayment'], $manager);
        // The ledger (vendor_payments): one row per payment, alongside the
        // single-figure PATCH .../payment above, which stays exactly as it
        // was for whatever already calls it. $manager — recording money paid
        // to a vendor is the same sensitivity class as every other
        // stock/price-correcting action already gated there.
        $router->post('/admin/purchase-orders/{uuid}/payments', [VendorPaymentController::class, 'store'], $manager);
        $router->get('/admin/purchase-orders/{uuid}/payments', [VendorPaymentController::class, 'forOrder'], $inventoryStaff);
        // Corrects a line's quantity/cost after the fact — both a money and
        // an inventory correction, same tier as the payment update above.
        $router->patch('/admin/purchase-orders/{uuid}/items/{itemUuid}', [PurchaseOrderController::class, 'updateItem'], $manager);
        $router->delete('/admin/purchase-orders/{uuid}/items/{itemUuid}', [PurchaseOrderController::class, 'destroyItem'], $manager);

        // --- Purchase returns (goods sent back to a vendor) -----------------
        // Reads at $inventoryStaff (executive can see them); creating one
        // reverses inventory and adjusts what's owed the vendor, same
        // sensitivity as the payment/item-correction actions above.
        $router->get('/admin/purchase-returns', [PurchaseReturnController::class, 'index'], $inventoryStaff);
        $router->post('/admin/purchase-returns', [PurchaseReturnController::class, 'store'], $manager);
        $router->get('/admin/purchase-returns/{uuid}', [PurchaseReturnController::class, 'show'], $inventoryStaff);

        // --- Point of Sale (brief §12, Priority 2) --------------------------
        $router->get('/admin/pos/offers', [PosSaleController::class, 'offersForVariant'], $cashier);
        // Till logins and per-cashier history: administrator only.
        $router->get('/admin/pos/cashiers', [PosCashierController::class, 'index'], $administrator);
        $router->post('/admin/pos/cashiers', [PosCashierController::class, 'store'], $administrator);
        $router->patch('/admin/pos/cashiers/{uuid}', [PosCashierController::class, 'update'], $administrator);
        $router->get('/admin/pos/cashiers/{uuid}/history', [PosCashierController::class, 'history'], $administrator);
        $router->get('/admin/pos/customers', [PosSaleController::class, 'findCustomer'], $cashier);
        $router->post('/admin/pos/customers', [PosSaleController::class, 'createCustomer'], $cashier);
        $router->get('/admin/pos/sales', [PosSaleController::class, 'index'], $cashier);
        $router->get('/admin/pos/sales/{uuid}', [PosSaleController::class, 'show'], $cashier);
        $router->post('/admin/pos/sales', [PosSaleController::class, 'store'], $cashier);
        $router->post('/admin/pos/sales/{uuid}/deliver', [PosSaleController::class, 'deliver'], $cashier);
        $router->post('/admin/pos/sales/{uuid}/void', [PosSaleController::class, 'void'], $manager);
        $router->post('/admin/pos/sales/{uuid}/refund', [PosSaleController::class, 'refund'], $manager);
        // --- POS customer dues / partial payment — same split as vendor
        // payments (line 425/426): recording money is $manager, reading who
        // owes what is open to $cashier so the till itself can show it.
        $router->get('/admin/pos/dues', [PosSaleController::class, 'duesIndex'], $cashier);
        $router->get('/admin/pos/sales/{uuid}/payments', [PosSaleController::class, 'paymentHistory'], $cashier);
        $router->post('/admin/pos/sales/{uuid}/payments', [PosSaleController::class, 'recordPayment'], $manager);
        $router->get('/admin/customers/{uuid}/dues', [PosSaleController::class, 'customerDues'], $cashier);

        // --- Invoice Tracking & Communication Center ------------------------
        // "Invoice" here is a POS till sale (pos_sales) — see InvoiceService's
        // doc comment. Recording a payment/refund/void is NOT duplicated here;
        // this is a read + WhatsApp-communication layer over the existing
        // pos_sales/pos_sale_payments/pos_refunds, at the same $cashier
        // visibility as the Customer Dues screen right above. Static paths
        // (summary, export) registered before /admin/invoices/{uuid}, same
        // ordering rule as every other route table in this file.
        $router->get('/admin/invoices/summary', [InvoiceController::class, 'summary'], $cashier);
        $router->get('/admin/invoices/export', [InvoiceController::class, 'export'], $manager);
        $router->get('/admin/invoices', [InvoiceController::class, 'index'], $cashier);
        $router->get('/admin/invoices/{uuid}', [InvoiceController::class, 'show'], $cashier);
        $router->get('/admin/invoices/{uuid}/whatsapp-preview', [InvoiceController::class, 'whatsappPreview'], $cashier);
        $router->post('/admin/invoices/{uuid}/whatsapp-log', [InvoiceController::class, 'whatsappLog'], $cashier);
        $router->post('/admin/invoices/{uuid}/sms-send', [InvoiceController::class, 'smsSend'], $cashier);
        $router->get('/admin/invoices/{uuid}/communications', [InvoiceController::class, 'communications'], $cashier);
        $router->get('/admin/customers/{uuid}/invoice-summary', [InvoiceController::class, 'customerSummary'], $cashier);

        // The standalone bulk CSV/Excel import screen (brief §9) was removed
        // from the admin console — ImportController::index/show/preview/
        // confirm are no longer routed. ImportController::quickCreate()
        // (POST /admin/inventory/quick-create, registered above) and
        // ImportService::parsePurchaseOrderItems() (POST
        // /admin/purchase-orders/items/parse-csv, registered with the
        // purchase-order routes) stay — the mobile scan workflow and
        // Purchase Inward's own CSV upload both still depend on them.

        // --- Pricing strategy (5Star inventory-first brief §7/§8) -----------
        // Rule configuration is admin-only, matching category/product config.
        // Applying a price DECISION is $manager — a selling-price change is
        // treated as sensitive, same reasoning as the inventory adjust route.
        $router->get('/admin/pricing/rules', [PricingRuleController::class, 'index'], $administrator);
        $router->post('/admin/pricing/rules', [PricingRuleController::class, 'store'], $administrator);
        $router->patch('/admin/pricing/rules/{uuid}', [PricingRuleController::class, 'update'], $administrator);
        $router->delete('/admin/pricing/rules/{uuid}', [PricingRuleController::class, 'destroy'], $administrator);
        $router->get('/admin/pricing/live', [PricingDecisionController::class, 'live'], $inventoryStaff);
        $router->post('/admin/pricing/decisions', [PricingDecisionController::class, 'store'], $manager);
        $router->get('/admin/pricing/history/{variantUuid}', [PricingDecisionController::class, 'history'], $inventoryStaff);

        // --- Inventory: variant dimensions (Size, Colour, Pack size, ...) ---
        // Category/product configuration, not day-to-day operations, so this
        // stays at administrator like categories and products themselves.
        $router->get('/admin/inventory/option-types', [VariantOptionController::class, 'index'], $administrator);
        $router->post('/admin/inventory/option-types', [VariantOptionController::class, 'store'], $administrator);
        $router->post('/admin/inventory/option-types/{uuid}/values', [VariantOptionController::class, 'storeValue'], $administrator);
        $router->put('/admin/categories/{uuid}/inventory-dimensions', [VariantOptionController::class, 'setCategoryDimensions'], $administrator);
        $router->put('/admin/variants/{uuid}/options', [VariantOptionController::class, 'setVariantOptions'], $administrator);

        // --- Media ---------------------------------------------------------
        // Upload throttling is tighter: these requests are expensive and are the
        // most abusable surface in the whole API.
        $router->post(
            '/admin/products/{uuid}/images',
            [ProductController::class, 'storeImage'],
            array_merge($administrator, ['throttle:60,600'])
        );
        $router->post('/admin/products/{uuid}/videos', [ProductController::class, 'storeVideo'], $administrator);
        $router->delete('/admin/media/{uuid}', [ProductController::class, 'destroyMedia'], $administrator);

        // --- Nutrition and specifications ----------------------------------
        $router->put('/admin/products/{uuid}/nutrition', [ProductController::class, 'saveNutrition'], $administrator);
        $router->put('/admin/products/{uuid}/attributes', [ProductController::class, 'saveAttributes'], $administrator);

        // --- Orders (staff) -------------------------------------------------
        // There is deliberately no "force status" route. BR-005 applies to
        // staff exactly as it applies to customers.
        // Counter (POS) sales shown next to online orders — administrator only.
        $router->get('/admin/counter-sales', [CounterSaleController::class, 'index'], $administrator);
        $router->get('/admin/orders', [OrderController::class, 'adminIndex'], $staff);
        $router->post('/admin/orders/expire-unpaid', [OrderController::class, 'adminExpireUnpaid'], $administrator);
        $router->get('/admin/orders/{uuid}', [OrderController::class, 'adminShow'], $staff);
        $router->get('/admin/orders/{uuid}/invoice', [OrderController::class, 'adminInvoice'], $staff);
        $router->post('/admin/orders/{uuid}/status', [OrderController::class, 'adminAdvance'], $staff);
        $router->post('/admin/orders/{uuid}/cancel', [OrderController::class, 'adminCancel'], $staff);

        // --- Cash on Delivery approval (administrator only) -----------------
        // Same reasoning as the manual payment queue: approving a COD order
        // confirms it with no payment collected yet, so this is restricted
        // to administrator, not opened to supervisor/executive the way
        // ordinary order-status routes are.
        $router->get('/admin/orders/cod/pending', [OrderController::class, 'adminCodPending'], $administrator);
        $router->post('/admin/orders/{uuid}/cod/approve', [OrderController::class, 'adminCodApprove'], $administrator);
        $router->post('/admin/orders/{uuid}/cod/decline', [OrderController::class, 'adminCodDecline'], $administrator);

        // --- Manual payment verification (administrator only) ---------------
        // The whole security model for the manual QR gateway is that only an
        // administrator can turn a pending attempt into a confirmed payment —
        // see ManualGateway and ManualPaymentService. Not opened to
        // supervisor/executive the way order status routes are.
        $router->get('/admin/payments/pending', [ManualPaymentController::class, 'pending'], $administrator);
        $router->post('/admin/payments/{uuid}/verify', [ManualPaymentController::class, 'verify'], $administrator);
        $router->post('/admin/payments/{uuid}/reject', [ManualPaymentController::class, 'reject'], $administrator);

        // --- Runtime settings (administrator only) ---------------------------
        // Lets payment_driver / delivery_driver be flipped between
        // manual/sandbox/razorpay/shiprocket from the admin console, without a
        // redeploy. See SettingsService and bootstrap/container.php.
        $router->get('/admin/settings', [SettingsController::class, 'index'], $administrator);
        $router->patch('/admin/settings/payment-driver', [SettingsController::class, 'setPaymentDriver'], $administrator);
        $router->patch('/admin/settings/delivery-driver', [SettingsController::class, 'setDeliveryDriver'], $administrator);
        $router->patch('/admin/settings/cod', [SettingsController::class, 'setCodEnabled'], $administrator);
        $router->patch('/admin/settings/price-change-mode', [SettingsController::class, 'setPriceChangeMode'], $administrator);
        $router->patch('/admin/settings/pos-due-reminder', [SettingsController::class, 'setPosDueReminderConfig'], $administrator);
        $router->patch('/admin/settings/manual', [SettingsController::class, 'updateManual'], $administrator);
        $router->post(
            '/admin/settings/manual/qr-image',
            [SettingsController::class, 'setManualQrImage'],
            array_merge($administrator, ['throttle:20,600'])
        );
        $router->post(
            '/admin/settings/logo',
            [SettingsController::class, 'setStoreLogo'],
            array_merge($administrator, ['throttle:20,600'])
        );

        // --- Backups and data cleanup (administrator-only: see
        // BackupService/DataCleanupService's own doc comments for why) ------
        $router->get('/admin/backups', [BackupController::class, 'index'], $administrator);
        $router->post('/admin/backups', [BackupController::class, 'store'], array_merge($administrator, ['throttle:10,3600']));
        $router->get('/admin/backups/{filename}/download', [BackupController::class, 'download'], $administrator);
        $router->delete('/admin/backups/{filename}', [BackupController::class, 'destroy'], $administrator);
        $router->post(
            '/admin/backups/restore',
            [BackupController::class, 'restore'],
            array_merge($administrator, ['throttle:5,3600'])
        );
        $router->get('/admin/data-cleanup/preview', [BackupController::class, 'cleanupPreview'], $administrator);
        $router->post(
            '/admin/data-cleanup/run',
            [BackupController::class, 'cleanupRun'],
            array_merge($administrator, ['throttle:10,3600'])
        );

        // --- Review moderation --------------------------------------------------
        $router->get('/admin/reviews', [ReviewController::class, 'queue'], $staff);
        $router->get('/admin/store-reviews', [StoreReviewController::class, 'queue'], $staff);
        $router->post('/admin/store-reviews/{uuid}/moderate', [StoreReviewController::class, 'moderate'], $supervisory);
        $router->post('/admin/reviews/{uuid}/moderate', [ReviewController::class, 'moderate'], $supervisory);
        $router->post('/admin/reviews/{uuid}/reply', [ReviewController::class, 'reply'], $supervisory);

        // --- Support (staff) ----------------------------------------------------
        $router->get('/admin/support/tickets', [SupportController::class, 'index'], $staff);
        $router->get('/admin/support/tickets/{uuid}', [SupportController::class, 'adminShow'], $staff);
        $router->post('/admin/support/tickets/{uuid}/reply', [SupportController::class, 'adminReply'], $staff);
        $router->post('/admin/support/tickets/{uuid}/assign', [SupportController::class, 'assign'], $supervisory);
        $router->get('/admin/support/staff', [SupportController::class, 'assignableStaff'], $supervisory);
        $router->post('/admin/support/tickets/{uuid}/resolve', [SupportController::class, 'resolve'], $staff);

        // --- Content (staff) ----------------------------------------------------
        $router->post('/admin/content/pages', [ContentController::class, 'savePage'], $administrator);
        $router->get('/admin/content/pages/{slug}', [ContentController::class, 'adminPage'], $staff);
        $router->patch('/admin/content/pages/{slug}', [ContentController::class, 'updatePage'], $administrator);
        $router->delete('/admin/content/pages/{slug}', [ContentController::class, 'deletePage'], $administrator);
        $router->post('/admin/content/posts', [ContentController::class, 'savePost'], $supervisory);
        $router->patch('/admin/content/posts/{slug}', [ContentController::class, 'updatePost'], $supervisory);
        $router->post('/admin/content/faq', [ContentController::class, 'saveFaq'], $supervisory);
        $router->patch('/admin/content/faq/{uuid}', [ContentController::class, 'updateFaq'], $supervisory);

        // --- Notifications and scheduling (staff) ------------------------------
        $router->get('/admin/notifications/health', [NotificationController::class, 'health'], $staff);
        $router->post('/admin/notifications/dispatch', [NotificationController::class, 'dispatch'], $administrator);
        $router->get('/admin/scheduler/tasks', [NotificationController::class, 'tasks'], $staff);
        $router->post('/admin/scheduler/run', [NotificationController::class, 'runScheduler'], $administrator);
        $router->patch('/admin/scheduler/tasks/{code}', [NotificationController::class, 'setTaskEnabled'], $administrator);

        $router->get('/admin/leads', [LeadController::class, 'index'], $staff);

        // --- Dashboards and reports -------------------------------------------
        $router->get('/admin/dashboard', [ReportController::class, 'dashboard'], $staff);
        $router->get('/admin/dashboard/collections/{method}', [ReportController::class, 'collectionHistory'], $staff);
        $router->get('/admin/dashboard/today/{kind}', [ReportController::class, 'today'], $staff);
        $router->get('/admin/reports/profit-loss', [ReportController::class, 'profitLoss'], $supervisory);
        $router->get('/admin/reports/sales', [ReportController::class, 'sales'], $supervisory);
        $router->get('/admin/reports/products', [ReportController::class, 'products'], $supervisory);
        $router->get('/admin/reports/customers', [ReportController::class, 'customers'], $supervisory);
        $router->get('/admin/reports/promotions', [ReportController::class, 'promotions'], $supervisory);
        $router->get('/admin/reports/operations', [ReportController::class, 'operations'], $supervisory);
        $router->get('/admin/reports/cancellations', [ReportController::class, 'cancellations'], $supervisory);
        $router->get('/admin/reports/pos', [ReportController::class, 'pos'], $supervisory);
        $router->get('/admin/reports/stock-audit', [StockAuditController::class, 'index'], $supervisory);
        $router->get('/admin/reports/stock-audit/movements', [StockAuditController::class, 'movements'], $supervisory);

        // --- Wholesale (staff side) -------------------------------------------
        $router->get('/admin/bulk-orders', [BulkOrderController::class, 'index'], $staff);
        $router->get('/admin/bulk-orders/{uuid}', [BulkOrderController::class, 'adminShow'], $staff);
        $router->post('/admin/bulk-orders/{uuid}/quote', [BulkOrderController::class, 'quote'], $supervisory);
        $router->post('/admin/bulk-orders/{uuid}/decline', [BulkOrderController::class, 'decline'], $supervisory);
        $router->post('/admin/bulk-orders/quotes/{uuid}/send', [BulkOrderController::class, 'send'], $supervisory);

        // --- Staff operations -------------------------------------------------
        // An executive's own queue and their own commission statement: available
        // to any staff member, scoped to themselves by the service.
        $router->get('/staff/queue', [StaffController::class, 'myQueue'], $staff);
        $router->get('/staff/commission', [CommissionController::class, 'myStatement'], $staff);
        $router->post('/staff/assignments/{uuid}/accept', [StaffController::class, 'accept'], $staff);
        $router->post('/staff/assignments/{uuid}/release', [StaffController::class, 'release'], $staff);
        $router->post('/staff/orders/{uuid}/packing-slip', [StaffController::class, 'packingSlip'], $staff);

        // Supervisory work: distributing orders and watching the board.
        $router->get('/staff/board', [StaffController::class, 'board'], $supervisory);
        $router->get('/staff/orders/{uuid}/assignments', [StaffController::class, 'history'], $supervisory);
        $router->post('/staff/orders/{uuid}/assign', [StaffController::class, 'assign'], $supervisory);
        $router->post('/staff/orders/{uuid}/reassign', [StaffController::class, 'reassign'], $supervisory);
        $router->post('/staff/assign-pending', [StaffController::class, 'assignPending'], $supervisory);

        // --- Commission -------------------------------------------------------
        $router->get('/admin/commission/pending', [CommissionController::class, 'pending'], $supervisory);
        $router->post('/admin/commission/approve', [CommissionController::class, 'approve'], $supervisory);
        $router->get('/admin/commission/{uuid}/statement', [CommissionController::class, 'statement'], $supervisory);
        $router->post('/admin/commission/settle', [CommissionController::class, 'settle'], $administrator);
        $router->post('/admin/commission/settlements/{uuid}/pay', [CommissionController::class, 'markPaid'], $administrator);

        // --- Staff profiles ---------------------------------------------------
        $router->get('/admin/staff', [StaffController::class, 'index'], $supervisory);
        $router->post('/admin/staff', [StaffController::class, 'store'], $administrator);
        $router->patch('/admin/staff/{uuid}', [StaffController::class, 'update'], $supervisory);

        // --- Delivery and couriers (staff) -----------------------------------
        $router->get('/admin/couriers', [CourierController::class, 'index'], $staff);
        $router->get('/admin/couriers/performance', [CourierController::class, 'performance'], $staff);
        $router->post('/admin/couriers/recalculate-reliability', [CourierController::class, 'recalculateReliability'], $administrator);
        $router->get('/admin/couriers/{code}', [CourierController::class, 'show'], $staff);
        $router->patch('/admin/couriers/{code}', [CourierController::class, 'update'], $administrator);
        $router->post('/admin/couriers/{code}/pickup', [ShipmentController::class, 'schedulePickup'], $staff);
        $router->post('/admin/couriers/{code}/manifest', [ShipmentController::class, 'manifest'], $staff);

        $router->get('/admin/shipments', [ShipmentController::class, 'index'], $staff);
        $router->post('/admin/shipments/refresh-stale', [ShipmentController::class, 'refreshStale'], $administrator);
        $router->get('/admin/shipments/{uuid}', [ShipmentController::class, 'show'], $staff);
        $router->post('/admin/shipments/{uuid}/label', [ShipmentController::class, 'label'], $staff);
        $router->post('/admin/shipments/{uuid}/track', [ShipmentController::class, 'refresh'], $staff);

        // BR-007: booking with no courier_code lets the selector choose.
        $router->get('/admin/orders/{uuid}/courier-options', [ShipmentController::class, 'courierOptions'], $staff);
        $router->post('/admin/orders/{uuid}/ship', [ShipmentController::class, 'book'], $staff);

        // --- Coupons -------------------------------------------------------
        $router->get('/admin/coupons', [PromotionController::class, 'adminCoupons'], $administrator);
        $router->post('/admin/coupons', [PromotionController::class, 'storeCoupon'], $administrator);
        $router->patch('/admin/coupons/{uuid}', [PromotionController::class, 'updateCoupon'], $administrator);
        $router->post('/admin/coupons/{uuid}/status', [PromotionController::class, 'setCouponStatus'], $administrator);
        $router->get('/admin/coupons/{uuid}/redemptions', [PromotionController::class, 'couponRedemptions'], $administrator);
        $router->delete('/admin/coupons/{uuid}', [PromotionController::class, 'destroyCoupon'], $administrator);

        // --- Offers --------------------------------------------------------
        $router->get('/admin/offers', [PromotionController::class, 'adminOffers'], $administrator);
        $router->get('/admin/offers/product-lookup', [PromotionController::class, 'offersForProducts'], $administrator);
        $router->get('/admin/offers/recommendations', [PromotionController::class, 'recommendations'], $administrator);
        $router->post('/admin/offers', [PromotionController::class, 'storeOffer'], $administrator);
        $router->patch('/admin/offers/{uuid}', [PromotionController::class, 'updateOffer'], $administrator);
        $router->post('/admin/offers/{uuid}/status', [PromotionController::class, 'setOfferStatus'], $administrator);
        $router->get('/admin/offers/{uuid}/targets', [PromotionController::class, 'offerTargets'], $administrator);
        $router->put('/admin/offers/{uuid}/targets', [PromotionController::class, 'setOfferTargets'], $administrator);
        $router->post(
            '/admin/offers/{uuid}/banner',
            [PromotionController::class, 'setOfferBanner'],
            array_merge($administrator, ['throttle:60,600'])
        );
        $router->delete('/admin/offers/{uuid}', [PromotionController::class, 'destroyOffer'], $administrator);

        // --- Wallet (administrator) ----------------------------------------
        // Every one of these writes to the append-only ledger and the audit log.
        // Dashboard drill-down (read-only) registered before /{userUuid} so
        // the router doesn't treat "transactions"/"accounts"/"pending-refunds"
        // as a uuid.
        $router->get('/admin/wallet/transactions', [WalletController::class, 'adminTransactions'], $administrator);
        $router->get('/admin/wallet/accounts', [WalletController::class, 'adminAccounts'], $administrator);
        $router->get('/admin/wallet/pending-refunds', [WalletController::class, 'adminPendingRefunds'], $administrator);
        $router->post('/admin/wallet/expire-credits', [WalletController::class, 'adminExpireCredits'], $administrator);
        $router->get('/admin/wallet/{userUuid}', [WalletController::class, 'adminShow'], $administrator);
        $router->get('/admin/wallet/{userUuid}/statement', [WalletController::class, 'adminStatement'], $administrator);
        $router->post('/admin/wallet/{userUuid}/credit', [WalletController::class, 'adminCredit'], $administrator);
        $router->post('/admin/wallet/{userUuid}/debit', [WalletController::class, 'adminDebit'], $administrator);
        $router->post('/admin/wallet/{userUuid}/freeze', [WalletController::class, 'adminFreeze'], $administrator);
        $router->post('/admin/wallet/{userUuid}/unfreeze', [WalletController::class, 'adminUnfreeze'], $administrator);

        // --- Referrals (administrator) -------------------------------------
        $router->get('/admin/referrals', [WalletController::class, 'adminReferrals'], $administrator);
        $router->post('/admin/referrals/{uuid}/qualify', [WalletController::class, 'adminQualifyReferral'], $administrator);
        $router->post('/admin/referrals/{uuid}/cancel', [WalletController::class, 'adminCancelReferral'], $administrator);

        // --- Loyalty program (administrator) --------------------------------
        // Gated by the same live role_permissions matrix the Admin Privilege
        // panel uses (migration 049's loyalty.view/edit/adjust), not the old
        // hard-coded $administrator array — so a custom role can be granted
        // just loyalty access without also getting every other admin route.
        $router->get('/admin/loyalty/summary', [LoyaltyController::class, 'adminSummary'], ['auth', 'adminPrivilege:loyalty.view']);
        $router->get('/admin/loyalty/settings', [LoyaltyController::class, 'adminSettings'], ['auth', 'adminPrivilege:loyalty.view']);
        $router->patch('/admin/loyalty/settings', [LoyaltyController::class, 'updateSettings'], ['auth', 'adminPrivilege:loyalty.edit']);
        $router->get('/admin/loyalty/accounts', [LoyaltyController::class, 'adminList'], ['auth', 'adminPrivilege:loyalty.view']);
        $router->get('/admin/loyalty/accounts/{userUuid}/ledger', [LoyaltyController::class, 'adminLedger'], ['auth', 'adminPrivilege:loyalty.view']);
        $router->post('/admin/loyalty/accounts/{userUuid}/adjust', [LoyaltyController::class, 'adjust'], ['auth', 'adminPrivilege:loyalty.adjust']);

        // --- Banners -------------------------------------------------------
        // Campaign pages. Curating what the shop pushes is merchandising, so
        // supervisors get it too rather than only administrators.
        $router->get('/admin/collections', [CollectionController::class, 'adminIndex'], $staff);
        $router->post('/admin/collections', [CollectionController::class, 'store'], $administrator);
        $router->get('/admin/collections/{slug}', [CollectionController::class, 'adminShow'], $staff);
        $router->patch('/admin/collections/{slug}', [CollectionController::class, 'update'], $administrator);
        $router->post('/admin/collections/{slug}/image', [CollectionController::class, 'storeImage'], $administrator);
        $router->post('/admin/collections/{slug}/status', [CollectionController::class, 'setStatus'], $administrator);
        $router->post('/admin/collections/{slug}/items', [CollectionController::class, 'addItem'], $administrator);
        $router->delete('/admin/collections/{slug}/items/{item}', [CollectionController::class, 'removeItem'], $administrator);

        $router->get('/admin/banners', [BannerController::class, 'adminIndex'], $administrator);
        $router->post(
            '/admin/banners',
            [BannerController::class, 'store'],
            array_merge($administrator, ['throttle:60,600'])
        );
        $router->patch('/admin/banners/{uuid}', [BannerController::class, 'update'], $administrator);
        $router->delete('/admin/banners/{uuid}', [BannerController::class, 'destroy'], $administrator);

        // ===================================================================
        // Admin Privilege Management — access control screens gated by their
        // own authorization layer (AdminPrivilegeMiddleware, registered as the
        // 'adminPrivilege' alias above), reached from a sidebar page in the
        // ordinary console (admin/access-control.html) rather than a separate
        // site with its own login. Every route group above this one, and
        // everything those controllers do, is unchanged.
        //
        // The panel's own login/logout (a second, isolated token pair issued
        // only to admin_privilege.access holders) has been retired along with
        // that separate site — this now shares the ordinary console session,
        // the same way every other admin screen does. me() stays: it is how
        // the sidebar page fetches this role's permission codes to decide
        // which tabs to show, and it still goes through the same
        // 'adminPrivilege:admin_privilege.access' gate below, so an account
        // without that permission is refused exactly as before.
        // ===================================================================

        // Every Admin Privilege endpoint: authenticate, then require the
        // dashboard-level admin_privilege.access permission at minimum. A
        // route that needs a specific module permission passes its own
        // 'adminPrivilege:module.action' instead of this generic one.
        $adminPrivilege = ['auth', 'adminPrivilege:admin_privilege.access'];

        $router->get('/admin-privilege/auth/me', [AdminPrivilegeAuthController::class, 'me'], $adminPrivilege);
        // Throttled tighter than the base $adminPrivilege gate: this is a
        // password check reachable by anyone already holding a valid bearer
        // token for the account, so it must not become an unthrottled way to
        // brute-force that one password.
        $router->post(
            '/admin-privilege/auth/confirm',
            [AdminPrivilegeAuthController::class, 'confirm'],
            ['auth', 'adminPrivilege:admin_privilege.access', 'throttle:8,600']
        );

        // --- User Management -------------------------------------------------
        $router->get('/admin-privilege/users', [AdminUserManagementController::class, 'index'], ['auth', 'adminPrivilege:user_management.view']);
        $router->get('/admin-privilege/users/{uuid}', [AdminUserManagementController::class, 'show'], ['auth', 'adminPrivilege:user_management.view']);
        $router->post('/admin-privilege/users', [AdminUserManagementController::class, 'store'], ['auth', 'adminPrivilege:user_management.add']);
        $router->patch('/admin-privilege/users/{uuid}/role', [AdminUserManagementController::class, 'changeRole'], ['auth', 'adminPrivilege:user_management.edit']);
        $router->post('/admin-privilege/users/{uuid}/activate', [AdminUserManagementController::class, 'activate'], ['auth', 'adminPrivilege:user_management.edit']);
        $router->post('/admin-privilege/users/{uuid}/deactivate', [AdminUserManagementController::class, 'deactivate'], ['auth', 'adminPrivilege:user_management.delete']);
        $router->post('/admin-privilege/users/{uuid}/unlock', [AdminUserManagementController::class, 'unlock'], ['auth', 'adminPrivilege:user_management.edit']);
        $router->post('/admin-privilege/users/{uuid}/force-logout', [AdminUserManagementController::class, 'forceLogout'], ['auth', 'adminPrivilege:user_management.edit']);
        $router->post('/admin-privilege/users/{uuid}/reset-password', [AdminUserManagementController::class, 'resetPassword'], ['auth', 'adminPrivilege:user_management.edit']);

        // --- Role Management (Custom Permissions) -----------------------------
        $router->get('/admin-privilege/roles', [RoleManagementController::class, 'index'], ['auth', 'adminPrivilege:role_management.view']);
        $router->get('/admin-privilege/permissions', [RoleManagementController::class, 'permissions'], ['auth', 'adminPrivilege:role_management.view']);
        $router->get('/admin-privilege/roles/{uuid}', [RoleManagementController::class, 'show'], ['auth', 'adminPrivilege:role_management.view']);
        $router->post('/admin-privilege/roles', [RoleManagementController::class, 'store'], ['auth', 'adminPrivilege:role_management.add']);
        $router->patch('/admin-privilege/roles/{uuid}', [RoleManagementController::class, 'update'], ['auth', 'adminPrivilege:role_management.edit']);
        $router->delete('/admin-privilege/roles/{uuid}', [RoleManagementController::class, 'destroy'], ['auth', 'adminPrivilege:role_management.delete']);

        // --- Sensitive Action Approval queue -----------------------------------
        $router->get('/admin-privilege/approvals', [ApprovalController::class, 'index'], $adminPrivilege);
        $router->get('/admin-privilege/approvals/pending-count', [ApprovalController::class, 'pendingCount'], $adminPrivilege);
        $router->post('/admin-privilege/approvals', [ApprovalController::class, 'store'], $adminPrivilege);
        // A single request can belong to any of seven+ modules (discount,
        // refund, wallet, stock, price, credit, payment...), so the specific
        // {module}.approve permission is checked inside ApprovalService::decide()
        // against that row's own module, rather than hard-coded per-route here.
        $router->post('/admin-privilege/approvals/{uuid}/approve', [ApprovalController::class, 'approve'], $adminPrivilege);
        $router->post('/admin-privilege/approvals/{uuid}/reject', [ApprovalController::class, 'reject'], $adminPrivilege);

        // --- Audit Logs ----------------------------------------------------
        $router->get('/admin-privilege/audit-logs', [AdminAuditLogController::class, 'auditLogs'], ['auth', 'adminPrivilege:audit_logs.view']);
        $router->get('/admin-privilege/activity-logs', [AdminAuditLogController::class, 'activityLogs'], ['auth', 'adminPrivilege:audit_logs.view']);

        // --- Wallet: welcome bonus (new-customer wallet credit) ------------
        // The actual credit happens in AuthService on first verification —
        // this is only the admin "edit option" for whether it's on and how
        // much, as asked for explicitly: kept in the Admin Privilege panel
        // rather than the ordinary administrator-only /admin/settings.
        $router->get('/admin-privilege/wallet/welcome-bonus', [WelcomeBonusController::class, 'show'], ['auth', 'adminPrivilege:system_settings.view']);
        $router->patch('/admin-privilege/wallet/welcome-bonus', [WelcomeBonusController::class, 'update'], ['auth', 'adminPrivilege:system_settings.edit']);
    });
};
