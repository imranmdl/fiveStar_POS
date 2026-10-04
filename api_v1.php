<?php

declare(strict_types=1);

use App\Controllers\Api\V1\AddressController;
use App\Controllers\Api\V1\AuthController;
use App\Controllers\Api\V1\BannerController;
use App\Controllers\Api\V1\CartController;
use App\Controllers\Api\V1\BulkOrderController;
use App\Controllers\Api\V1\CategoryController;
use App\Controllers\Api\V1\ContentController;
use App\Controllers\Api\V1\CommissionController;
use App\Controllers\Api\V1\CourierController;
use App\Controllers\Api\V1\CheckoutController;
use App\Controllers\Api\V1\HealthController;
use App\Controllers\Api\V1\PromotionController;
use App\Controllers\Api\V1\NotificationController;
use App\Controllers\Api\V1\OrderController;
use App\Controllers\Api\V1\ProductController;
use App\Controllers\Api\V1\ReportController;
use App\Controllers\Api\V1\ReviewController;
use App\Controllers\Api\V1\ShipmentController;
use App\Controllers\Api\V1\StaffController;
use App\Controllers\Api\V1\SupportController;
use App\Controllers\Api\V1\WalletController;
use App\Controllers\Api\V1\WebhookController;
use App\Controllers\Api\V1\WishlistController;
use App\Core\Middleware\ActivityLogMiddleware;
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

        $router->get('/banners', [BannerController::class, 'index'], ['throttle:300,60']);
        $router->post('/banners/{uuid}/click', [BannerController::class, 'click'], ['throttle:60,60']);

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
        $router->get('/offers', [PromotionController::class, 'offers'], ['throttle:300,60']);
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

        $router->get('/admin/ping', [HealthController::class, 'adminPing'], $administrator);

        // --- Categories ----------------------------------------------------
        $router->get('/admin/categories', [CategoryController::class, 'adminIndex'], $administrator);
        $router->post('/admin/categories', [CategoryController::class, 'store'], $administrator);
        $router->patch('/admin/categories/{uuid}', [CategoryController::class, 'update'], $administrator);
        $router->post('/admin/categories/{uuid}/image', [CategoryController::class, 'storeImage'], $administrator);
        $router->delete('/admin/categories/{uuid}', [CategoryController::class, 'destroy'], $administrator);

        // --- Products ------------------------------------------------------
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
        $router->get('/admin/orders', [OrderController::class, 'adminIndex'], $staff);
        $router->post('/admin/orders/expire-unpaid', [OrderController::class, 'adminExpireUnpaid'], $administrator);
        $router->get('/admin/orders/{uuid}', [OrderController::class, 'adminShow'], $staff);
        $router->get('/admin/orders/{uuid}/invoice', [OrderController::class, 'adminInvoice'], $staff);
        $router->post('/admin/orders/{uuid}/status', [OrderController::class, 'adminAdvance'], $staff);
        $router->post('/admin/orders/{uuid}/cancel', [OrderController::class, 'adminCancel'], $staff);

        // --- Review moderation --------------------------------------------------
        $router->get('/admin/reviews', [ReviewController::class, 'queue'], $staff);
        $router->post('/admin/reviews/{uuid}/moderate', [ReviewController::class, 'moderate'], $supervisory);
        $router->post('/admin/reviews/{uuid}/reply', [ReviewController::class, 'reply'], $supervisory);

        // --- Support (staff) ----------------------------------------------------
        $router->get('/admin/support/tickets', [SupportController::class, 'index'], $staff);
        $router->get('/admin/support/tickets/{uuid}', [SupportController::class, 'adminShow'], $staff);
        $router->post('/admin/support/tickets/{uuid}/reply', [SupportController::class, 'adminReply'], $staff);
        $router->post('/admin/support/tickets/{uuid}/assign', [SupportController::class, 'assign'], $supervisory);
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

        // --- Dashboards and reports -------------------------------------------
        $router->get('/admin/dashboard', [ReportController::class, 'dashboard'], $staff);
        $router->get('/admin/reports/sales', [ReportController::class, 'sales'], $supervisory);
        $router->get('/admin/reports/products', [ReportController::class, 'products'], $supervisory);
        $router->get('/admin/reports/customers', [ReportController::class, 'customers'], $supervisory);
        $router->get('/admin/reports/promotions', [ReportController::class, 'promotions'], $supervisory);
        $router->get('/admin/reports/operations', [ReportController::class, 'operations'], $supervisory);
        $router->get('/admin/reports/cancellations', [ReportController::class, 'cancellations'], $supervisory);

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
        $router->post('/admin/offers', [PromotionController::class, 'storeOffer'], $administrator);
        $router->patch('/admin/offers/{uuid}', [PromotionController::class, 'updateOffer'], $administrator);
        $router->post('/admin/offers/{uuid}/status', [PromotionController::class, 'setOfferStatus'], $administrator);
        $router->put('/admin/offers/{uuid}/targets', [PromotionController::class, 'setOfferTargets'], $administrator);
        $router->post(
            '/admin/offers/{uuid}/banner',
            [PromotionController::class, 'setOfferBanner'],
            array_merge($administrator, ['throttle:60,600'])
        );
        $router->delete('/admin/offers/{uuid}', [PromotionController::class, 'destroyOffer'], $administrator);

        // --- Wallet (administrator) ----------------------------------------
        // Every one of these writes to the append-only ledger and the audit log.
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

        // --- Banners -------------------------------------------------------
        $router->get('/admin/banners', [BannerController::class, 'adminIndex'], $administrator);
        $router->post(
            '/admin/banners',
            [BannerController::class, 'store'],
            array_merge($administrator, ['throttle:60,600'])
        );
        $router->patch('/admin/banners/{uuid}', [BannerController::class, 'update'], $administrator);
        $router->delete('/admin/banners/{uuid}', [BannerController::class, 'destroy'], $administrator);
    });
};
