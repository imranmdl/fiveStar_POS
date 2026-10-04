<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\CourierRepository;
use App\Repositories\OrderRepository;
use App\Repositories\ShipmentRepository;
use App\Services\CourierRoutingService;
use App\Services\ShipmentService;

/**
 * Shipments: booking, labels, pickups, manifests and tracking.
 *
 * Almost all of this is staff work. Customers see only their own order's
 * shipments, through a deliberately thinner view.
 */
final class ShipmentController extends BaseController
{
    public function __construct(
        private readonly ShipmentService $shipments,
        private readonly CourierRoutingService $routing,
        private readonly ShipmentRepository $shipmentRows,
        private readonly CourierRepository $couriers,
        private readonly OrderRepository $orders,
    ) {
    }

    /** GET /api/v1/orders/{uuid}/shipments */
    public function forOrder(Request $request): Response
    {
        return Response::success(
            $this->shipments->showForCustomer($request, (string) $request->routeParam('uuid')),
            'Shipments loaded'
        );
    }

    // -----------------------------------------------------------------------
    // Staff
    // -----------------------------------------------------------------------

    /** GET /api/v1/admin/shipments */
    public function index(Request $request): Response
    {
        $params = $this->paginationParams($request, 'created_date', 100);
        $status = $request->query('status');
        $courierCode = $request->query('courier');

        $courierId = null;

        if (is_string($courierCode) && $courierCode !== '') {
            $courier = $this->couriers->findByCode($courierCode);

            if ($courier === null) {
                throw new HttpException('Unknown courier: ' . $courierCode, 422);
            }

            $courierId = (int) $courier['id'];
        }

        $result = $this->shipmentRows->paginateForStaff(
            $params,
            is_string($status) && $status !== '' ? $status : null,
            $courierId
        );

        return $this->paginated($result['items'], $result['total'], $params, 'Shipments loaded');
    }

    /** GET /api/v1/admin/shipments/{uuid} */
    public function show(Request $request): Response
    {
        return Response::success(
            $this->shipments->showForStaff((string) $request->routeParam('uuid')),
            'Shipment loaded'
        );
    }

    /**
     * GET /api/v1/admin/orders/{uuid}/courier-options
     *
     * What BR-007 would choose and why, without committing to it. Lets a
     * supervisor see the reasoning before booking, and makes a bad rate card
     * visible rather than merely expensive.
     */
    public function courierOptions(Request $request): Response
    {
        $order = $this->routing->previewForOrder(
            $this->resolveOrderId((string) $request->routeParam('uuid')),
            $request->query('strategy')
        );

        return Response::success($order, 'Courier options loaded');
    }

    /**
     * POST /api/v1/admin/orders/{uuid}/ship
     *
     * Book the parcel with a courier. Omit courier_code to let automatic selection choose.
     */
    public function book(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            // Optional: omit to let BR-007 choose, which is the normal path.
            'courier_code' => 'nullable|string|max:40',
            'strategy' => 'nullable|in:cheapest,fastest,balanced,reliable',
        ]);

        return Response::created(
            $this->shipments->book(
                $request,
                (string) $request->routeParam('uuid'),
                $data['courier_code'] ?? null,
                $data['strategy'] ?? null
            ),
            'Shipment booked'
        );
    }

    /** POST /api/v1/admin/shipments/{uuid}/label */
    public function label(Request $request): Response
    {
        return Response::success(
            $this->shipments->generateLabel($request, (string) $request->routeParam('uuid')),
            'Label ready'
        );
    }

    /** POST /api/v1/admin/shipments/{uuid}/track */
    public function refresh(Request $request): Response
    {
        return Response::success(
            $this->shipments->refreshTracking($request, (string) $request->routeParam('uuid')),
            'Tracking refreshed'
        );
    }

    /** POST /api/v1/admin/couriers/{code}/pickup */
    public function schedulePickup(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'pickup_date' => 'required|date',
        ]);

        return Response::created(
            $this->shipments->schedulePickup(
                $request,
                (string) $request->routeParam('code'),
                $data['pickup_date']
            ),
            'Pickup scheduled'
        );
    }

    /** POST /api/v1/admin/couriers/{code}/manifest */
    public function manifest(Request $request): Response
    {
        return Response::created(
            $this->shipments->generateManifest($request, (string) $request->routeParam('code')),
            'Manifest generated'
        );
    }

    /** POST /api/v1/admin/shipments/refresh-stale */
    public function refreshStale(Request $request): Response
    {
        $minutes = (int) $request->input('stale_minutes', 180);

        return Response::success(
            $this->shipments->refreshStaleShipments($request, max(5, $minutes)),
            'Stale shipments refreshed'
        );
    }

    private function resolveOrderId(string $uuid): int
    {
        $order = $this->orders->findByUuid($uuid);

        if ($order === null) {
            throw new NotFoundException('That order does not exist.');
        }

        return (int) $order['id'];
    }
}
