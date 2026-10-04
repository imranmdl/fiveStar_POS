<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\WishlistService;

/**
 * Wishlists require a signed-in customer. Unlike carts there is no guest
 * equivalent: a wishlist is only useful if it survives across devices, which
 * needs an account.
 */
final class WishlistController extends BaseController
{
    public function __construct(private readonly WishlistService $wishlist)
    {
    }

    /** GET /api/v1/wishlist */
    public function index(Request $request): Response
    {
        $params = $this->paginationParams($request, 'created_date', 100);
        $result = $this->wishlist->list($request, $params);

        return $this->paginated($result['items'], $result['total'], $params, 'Wishlist loaded');
    }

    /** POST /api/v1/wishlist */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'product' => 'required|string|max:180',
            'variant_uuid' => 'nullable|uuid',
        ]);

        return Response::created(
            $this->wishlist->add($request, $data['product'], $data['variant_uuid'] ?? null),
            'Added to your wishlist'
        );
    }

    /** GET /api/v1/wishlist/contains?product=slug-or-uuid */
    public function contains(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'product' => 'required|string|max:180',
        ]);

        return Response::success(
            ['in_wishlist' => $this->wishlist->contains($request, $data['product'])],
            'Checked'
        );
    }

    /** PATCH /api/v1/wishlist/{uuid} */
    public function update(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'variant_uuid' => 'nullable|uuid',
            'notify_on_offer' => 'nullable|boolean',
            'notify_on_price_drop' => 'nullable|boolean',
            'notes' => 'nullable|string|max:255',
        ]);

        $supplied = array_intersect_key($data, $request->all());

        return Response::success(
            $this->wishlist->updatePreferences($request, (string) $request->routeParam('uuid'), $supplied),
            'Wishlist item updated'
        );
    }

    /** DELETE /api/v1/wishlist/{uuid} */
    public function destroy(Request $request): Response
    {
        $count = $this->wishlist->remove($request, (string) $request->routeParam('uuid'));

        return Response::success(['wishlist_count' => $count], 'Removed from your wishlist');
    }

    /** POST /api/v1/wishlist/{uuid}/move-to-cart */
    public function moveToCart(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'variant_uuid' => 'nullable|uuid',
            'quantity' => 'nullable|int|min:1|max:500',
        ]);

        return Response::success(
            $this->wishlist->moveToCart(
                $request,
                (string) $request->routeParam('uuid'),
                $data['variant_uuid'] ?? null,
                (int) ($data['quantity'] ?? 1)
            ),
            'Moved to your cart'
        );
    }
}
