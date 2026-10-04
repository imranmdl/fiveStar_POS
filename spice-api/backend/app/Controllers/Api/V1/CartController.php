<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\CartService;
use App\Services\DeliveryChargeService;

/**
 * Cart endpoints work for guests and signed-in customers alike.
 *
 * A guest cart is identified by the `X-Cart-Token` header (or a `cart_token`
 * body field). The token is minted on the first cart write and returned in
 * `data.cart.guest_token`; the client must persist it. For a signed-in caller
 * the token is ignored and the account cart is always used.
 */
final class CartController extends BaseController
{
    public function __construct(
        private readonly CartService $cart,
        private readonly DeliveryChargeService $delivery,
    ) {
    }

    /**
     * GET /api/v1/cart
     *
     * The current cart, fully priced, with delivery and any blockers preventing checkout.
     */
    public function show(Request $request): Response
    {
        $pincode = $request->query('pincode');
        $pincode = is_string($pincode) && $pincode !== '' ? $pincode : null;

        return Response::success($this->cart->view($request, $pincode), 'Cart loaded');
    }

    /** POST /api/v1/cart/items */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'variant_uuid' => 'required|uuid',
            'quantity' => 'nullable|int|min:1|max:500',
            'is_gift' => 'nullable|boolean',
            'gift_message' => 'nullable|string|max:320',
        ]);

        $result = $this->cart->addItem(
            $request,
            $data['variant_uuid'],
            (int) ($data['quantity'] ?? 1),
            (bool) ($data['is_gift'] ?? false),
            $data['gift_message'] ?? null
        );

        return Response::created($result, 'Added to cart');
    }

    /** PATCH /api/v1/cart/items/{uuid} */
    public function update(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'quantity' => 'required|int|min:1|max:500',
        ]);

        return Response::success(
            $this->cart->updateQuantity($request, (string) $request->routeParam('uuid'), $data['quantity']),
            'Quantity updated'
        );
    }

    /** DELETE /api/v1/cart/items/{uuid} */
    public function destroy(Request $request): Response
    {
        return Response::success(
            $this->cart->removeItem($request, (string) $request->routeParam('uuid')),
            'Item removed'
        );
    }

    /** POST /api/v1/cart/items/{uuid}/save-for-later */
    public function saveForLater(Request $request): Response
    {
        return Response::success(
            $this->cart->setSavedForLater($request, (string) $request->routeParam('uuid'), true),
            'Saved for later'
        );
    }

    /** POST /api/v1/cart/items/{uuid}/move-to-cart */
    public function moveToCart(Request $request): Response
    {
        return Response::success(
            $this->cart->setSavedForLater($request, (string) $request->routeParam('uuid'), false),
            'Moved back into your cart'
        );
    }

    /** POST /api/v1/cart/clear */
    public function clear(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'include_saved_for_later' => 'nullable|boolean',
        ]);

        return Response::success(
            $this->cart->clear($request, (bool) ($data['include_saved_for_later'] ?? false)),
            'Cart cleared'
        );
    }

    /** POST /api/v1/cart/pincode */
    public function setPincode(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'pincode' => 'required|digits:6',
        ]);

        return Response::success(
            $this->cart->setPincode($request, $data['pincode']),
            'Delivery pincode updated'
        );
    }

    /** POST /api/v1/cart/price-changes/acknowledge */
    public function acknowledgePriceChanges(Request $request): Response
    {
        return Response::success(
            $this->cart->acknowledgePriceChanges($request),
            'Price changes acknowledged'
        );
    }

    /** POST /api/v1/cart/coupon (authenticated) */
    public function applyCoupon(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'coupon_code' => 'required|string|min:3|max:30',
        ]);

        return Response::success(
            $this->cart->applyCoupon($request, $data['coupon_code']),
            'Coupon applied'
        );
    }

    /** DELETE /api/v1/cart/coupon (authenticated) */
    public function removeCoupon(Request $request): Response
    {
        return Response::success($this->cart->removeCoupon($request), 'Coupon removed');
    }

    /**
     * POST /api/v1/cart/wallet (authenticated)
     *
     * Wallet credit is a payment tender, not a discount: it lowers the amount
     * payable without changing the order value or the GST on it. Send 0 to clear.
     */
    public function setWalletRedemption(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0|max:100000',
        ]);

        return Response::success(
            $this->cart->setWalletRedemption($request, (float) $data['amount']),
            'Wallet redemption updated'
        );
    }

    /** POST /api/v1/cart/merge (authenticated) */
    public function merge(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'cart_token' => 'required|string|min:20|max:200',
        ]);

        return Response::success(
            $this->cart->mergeGuestCart($request, $data['cart_token']),
            'Your guest cart has been merged'
        );
    }

    /** GET /api/v1/delivery/serviceability?pincode=560001 */
    public function serviceability(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'pincode' => 'required|digits:6',
        ]);

        return Response::success(
            $this->delivery->checkServiceability($data['pincode']),
            'Serviceability checked'
        );
    }

    /** GET /api/v1/delivery/rate-card */
    public function rateCard(Request $request): Response
    {
        return Response::success(
            ['zones' => $this->delivery->rateCard()],
            'Delivery rate card loaded'
        );
    }
}
