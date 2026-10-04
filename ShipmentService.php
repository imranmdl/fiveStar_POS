<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Logger;
use App\Core\Request;
use App\Helpers\Money;
use App\Helpers\Uuid;
use App\Repositories\CourierRepository;
use App\Repositories\OrderRepository;
use App\Repositories\SettingRepository;
use App\Repositories\ShipmentRepository;
use App\Services\Delivery\CourierAdapterInterface;
use App\Services\Delivery\TrackingUpdate;
use App\Services\Orders\NumberingService;
use App\Services\Orders\OrderStateMachine;
use App\Services\Orders\OrderStatus;
use App\Services\Orders\PaymentStatus;

/**
 * Booking parcels, printing labels, calling for pickups and tracking.
 *
 * The rule that shapes this class: A SHIPMENT SCAN NEVER WRITES AN ORDER STATUS
 * DIRECTLY. Courier scans arrive out of order, get replayed, and occasionally
 * contradict each other — a parcel can report "out for delivery" after
 * "delivered" when two facilities sync late. Every order status change still
 * goes through OrderStateMachine, so an impossible sequence is refused and
 * logged rather than corrupting the order.
 *
 * BR-005 also still applies here. An unpaid order cannot be booked with a
 * courier, because handing goods to a courier is the point of no return.
 */
final class ShipmentService
{
    public function __construct(
        private readonly ShipmentRepository $shipments,
        private readonly CourierRepository $couriers,
        private readonly OrderRepository $orders,
        private readonly CourierRoutingService $routing,
        private readonly CourierAdapterInterface $adapter,
        private readonly OrderStateMachine $stateMachine,
        private readonly NumberingService $numbering,
        private readonly SettingRepository $settings,
        private readonly AuditService $audit,
        private readonly Database $db,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Books a parcel with the automatically selected courier (BR-007).
     *
     * @return array<string, mixed>
     */
    public function book(Request $request, string $orderUuid, ?string $courierCode = null, ?string $strategy = null): array
    {
        $order = $this->orders->findByUuid($orderUuid);

        if ($order === null) {
            throw new NotFoundException('That order does not exist.');
        }

        // BR-005. Handing goods to a courier is irreversible in a way that
        // printing a packing slip is not, so the payment check is repeated here
        // rather than trusted from an earlier step.
        if (!PaymentStatus::isSettled((string) $order['payment_status'])) {
            throw new HttpException(
                'This order has not been paid for, so it cannot be handed to a courier.',
                409,
                ['payment_status' => [(string) $order['payment_status']]]
            );
        }

        if (!in_array($order['status'], [OrderStatus::PACKED, OrderStatus::READY_TO_SHIP], true)) {
            throw new HttpException(
                sprintf(
                    'An order must be packed before it can be booked. This one is %s.',
                    OrderStatus::label((string) $order['status'])
                ),
                409
            );
        }

        $existing = $this->shipments->forOrder((int) $order['id']);

        foreach ($existing as $shipment) {
            if (!in_array($shipment['status'], ['cancelled'], true)) {
                throw new HttpException(
                    'This order already has a shipment (' . $shipment['shipment_number'] . ').',
                    409
                );
            }
        }

        // A manual override is allowed but recorded as one, so the BR-007 audit
        // trail distinguishes "the algorithm chose this" from "someone did".
        if ($courierCode !== null) {
            $courier = $this->couriers->findByCode($courierCode);

            if ($courier === null) {
                throw new NotFoundException('That courier does not exist.');
            }

            $parcel = $this->routing->buildParcel((int) $order['id']);
            $selection = null;
        } else {
            $outcome = $this->routing->selectForOrder((int) $order['id'], $strategy, $request->authUserId());

            if ($outcome['selected'] === null) {
                throw new HttpException(
                    'No courier can carry this parcel: ' . $outcome['reason'],
                    422,
                    ['courier' => [$outcome['reason']]]
                );
            }

            $courier = (array) $this->couriers->findById($outcome['selected']->courierId);
            $parcel = $outcome['parcel'];
            $selection = $outcome;
        }

        // The courier call happens OUTSIDE the transaction. A third-party HTTP
        // request must never hold a database lock, and a courier taking twenty
        // seconds must not roll back work already done.
        $orderPayload = $order + [
            'items' => $this->orders->itemsFor((int) $order['id']),
        ];

        $booking = $this->adapter->book($courier, $parcel, $orderPayload);

        if (!$booking->success) {
            $this->logger->error('Courier booking failed', [
                'order_number' => $order['order_number'],
                'courier' => $courier['code'],
                'reason' => $booking->failureReason,
            ], 'delivery');

            throw new HttpException(
                'The courier could not accept this parcel: ' . ($booking->failureReason ?? 'no reason given'),
                502,
                ['courier' => [$booking->failureReason ?? 'Booking refused.']]
            );
        }

        $shipmentId = $this->db->transaction(function () use ($order, $courier, $parcel, $booking, $selection, $request): int {
            $shipmentNumber = $this->numbering->nextShipmentNumber();

            $courierVolumetric = \App\Services\Delivery\ParcelSpec::volumetricGrams(
                $parcel->lengthMm,
                $parcel->widthMm,
                $parcel->heightMm,
                (int) $courier['volumetric_divisor']
            );

            $id = $this->shipments->create([
                'shipment_number' => $shipmentNumber,
                'order_id' => (int) $order['id'],
                'courier_id' => (int) $courier['id'],
                'awb_number' => $booking->awbNumber,
                'courier_shipment_id' => $booking->courierShipmentId,
                'label_url' => $booking->labelUrl,
                'label_generated_date' => $booking->labelUrl === null ? null : date('Y-m-d H:i:s'),
                'status' => 'booked',
                'actual_weight_grams' => $parcel->actualWeightGrams,
                'volumetric_weight_grams' => $courierVolumetric,
                'chargeable_weight_grams' => max($parcel->actualWeightGrams, $courierVolumetric),
                'length_mm' => $parcel->lengthMm,
                'width_mm' => $parcel->widthMm,
                'height_mm' => $parcel->heightMm,
                'used_default_dimensions' => $parcel->usedDefaultDimensions ? 1 : 0,
                'declared_value' => $parcel->declaredValue->toDecimal(),
                // $selection is null when staff chose the courier by hand, so the
                // quote must be read defensively rather than assumed present.
                'courier_charge' => $booking->courierCharge?->toDecimal()
                    ?? (($selection['selected'] ?? null)?->cost->toDecimal() ?? 0),
                'customer_paid_delivery' => (float) $order['delivery_charge'],
                'promised_sla_min_days' => ($selection['selected'] ?? null)?->slaMinDays,
                'promised_sla_max_days' => ($selection['selected'] ?? null)?->slaMaxDays,
                'estimated_delivery_date' => $booking->estimatedDeliveryDate,
                'booking_response' => json_encode($booking->raw),
            ], $request->authUserId());

            if ($selection !== null && isset($selection['selection_id'])) {
                $this->db->execute(
                    'UPDATE `courier_selections` SET `shipment_id` = :shipment_id WHERE `id` = :id',
                    ['shipment_id' => $id, 'id' => $selection['selection_id']]
                );
            } elseif ($selection === null) {
                // A manual choice still gets an audit row, flagged as such.
                $this->db->insert(
                    'INSERT INTO `courier_selections`
                         (`uuid`, `order_id`, `shipment_id`, `selected_courier_id`, `strategy`,
                          `destination_pincode`, `chargeable_weight_grams`, `order_value`,
                          `candidates_considered`, `candidates_eligible`, `reason`, `candidates`,
                          `was_manual_override`, `overridden_by`, `created_by`, `created_date`,
                          `is_active`, `is_deleted`, `version`)
                     VALUES
                         (:uuid, :order_id, :shipment_id, :courier_id, \'manual\',
                          :pincode, :weight, :value, 0, 0, :reason, :candidates,
                          1, :actor, :actor2, NOW(), 1, 0, 1)',
                    [
                        'uuid' => Uuid::v4(),
                        'order_id' => (int) $order['id'],
                        'shipment_id' => $id,
                        'courier_id' => (int) $courier['id'],
                        'pincode' => $parcel->destinationPincode,
                        'weight' => $parcel->chargeableWeightGrams(),
                        'value' => $parcel->declaredValue->toDecimal(),
                        'reason' => 'Courier chosen manually by staff, bypassing automatic selection.',
                        'candidates' => json_encode([]),
                        'actor' => $request->authUserId(),
                        'actor2' => $request->authUserId(),
                    ]
                );
            }

            $this->shipments->appendEvent(
                shipmentId: $id,
                status: 'booked',
                title: 'Shipment booked',
                description: sprintf('%s assigned AWB %s.', $courier['name'], $booking->awbNumber),
                location: null,
                occurredAt: date('Y-m-d H:i:s'),
                courierEventId: 'booked:' . $booking->awbNumber,
                eventCode: null,
                source: 'manual',
                raw: [],
            );

            // The order carries a denormalised copy so the customer's order page
            // and tracking email do not need to join through shipments.
            $this->orders->update((int) $order['id'], [
                'courier_code' => $courier['code'],
                'courier_name' => $courier['name'],
                'tracking_number' => $booking->awbNumber,
                'tracking_url' => $this->trackingUrl($courier, (string) $booking->awbNumber),
            ], $request->authUserId());

            $this->advanceOrder(
                (int) $order['id'],
                (string) $order['status'],
                OrderStatus::ASSIGNED,
                'Handed to courier',
                (string) $order['payment_status'],
                sprintf('%s, tracking number %s.', $courier['name'], $booking->awbNumber),
                $request->authUserId()
            );

            return $id;
        });

        $this->audit->log(
            entityName: 'shipments',
            entityId: $shipmentId,
            action: 'book',
            newValues: [
                'order_number' => $order['order_number'],
                'courier' => $courier['code'],
                'awb' => $booking->awbNumber,
                'manual_override' => $courierCode !== null,
            ],
            request: $request,
        );

        return $this->present((array) $this->shipments->findById($shipmentId), staffView: true);
    }

    /** @return array<string, mixed> */
    public function generateLabel(Request $request, string $shipmentUuid): array
    {
        $shipment = $this->requireShipment($shipmentUuid);
        $courier = (array) $this->couriers->findById((int) $shipment['courier_id']);

        if ($shipment['label_url'] !== null) {
            return ['label_url' => $shipment['label_url'], 'already_generated' => true];
        }

        if ($shipment['awb_number'] === null) {
            throw new HttpException('This shipment has no tracking number yet.', 409);
        }

        $label = $this->adapter->label($courier, (string) $shipment['awb_number']);

        if ($label === null) {
            throw new HttpException('The courier could not produce a label right now.', 502);
        }

        $this->shipments->update((int) $shipment['id'], [
            'label_url' => $label,
            'label_generated_date' => date('Y-m-d H:i:s'),
            'status' => $shipment['status'] === 'booked' ? 'label_generated' : $shipment['status'],
        ], $request->authUserId());

        return ['label_url' => $label, 'already_generated' => false];
    }

    /**
     * Books a collection for everything waiting with one courier.
     *
     * @return array<string, mixed>
     */
    public function schedulePickup(Request $request, string $courierCode, string $pickupDate): array
    {
        $courier = $this->couriers->findByCode($courierCode);

        if ($courier === null) {
            throw new NotFoundException('That courier does not exist.');
        }

        if ((int) $courier['supports_pickup'] !== 1) {
            throw new HttpException('This courier does not accept pickup requests through the API.', 422);
        }

        $waiting = $this->shipments->readyForPickup((int) $courier['id']);

        if ($waiting === []) {
            throw new HttpException('There are no parcels waiting for collection with this courier.', 422);
        }

        $awbs = array_values(array_filter(array_column($waiting, 'awb_number')));

        $contact = [
            'name' => (string) ($this->settings->value('pickup_contact_name') ?? ''),
            'phone' => (string) ($this->settings->value('pickup_contact_phone') ?? ''),
            'address' => (string) ($this->settings->value('pickup_address') ?? ''),
        ];

        $result = $this->adapter->schedulePickup($courier, $awbs, $pickupDate, $contact);

        $pickupId = $this->db->transaction(function () use ($courier, $waiting, $awbs, $pickupDate, $contact, $result, $request): int {
            $totalWeight = array_sum(array_map(
                static fn (array $s): int => (int) $s['chargeable_weight_grams'],
                $waiting
            ));

            $id = $this->db->insert(
                'INSERT INTO `pickup_requests`
                     (`uuid`, `courier_id`, `courier_reference`, `pickup_date`, `shipment_count`,
                      `total_weight_grams`, `status`, `contact_name`, `contact_phone`,
                      `pickup_address`, `failure_reason`, `courier_response`,
                      `created_by`, `created_date`, `is_active`, `is_deleted`, `version`)
                 VALUES
                     (:uuid, :courier_id, :reference, :pickup_date, :count, :weight, :status,
                      :contact_name, :contact_phone, :address, :failure, :response,
                      :created_by, NOW(), 1, 0, 1)',
                [
                    'uuid' => Uuid::v4(),
                    'courier_id' => (int) $courier['id'],
                    'reference' => $result['reference'],
                    'pickup_date' => $pickupDate,
                    'count' => count($waiting),
                    'weight' => $totalWeight,
                    'status' => $result['success'] ? 'confirmed' : 'failed',
                    'contact_name' => $contact['name'],
                    'contact_phone' => $contact['phone'],
                    'address' => $contact['address'],
                    'failure' => $result['success'] ? null : substr((string) $result['message'], 0, 250),
                    'response' => json_encode($result['raw'] ?? []),
                    'created_by' => $request->authUserId(),
                ]
            );

            if ($result['success']) {
                foreach ($waiting as $shipment) {
                    $this->shipments->update((int) $shipment['id'], [
                        'pickup_request_id' => $id,
                        'pickup_scheduled_date' => $pickupDate . ' 00:00:00',
                        'status' => 'pickup_scheduled',
                    ], $request->authUserId());
                }
            }

            return $id;
        });

        if (!$result['success']) {
            throw new HttpException(
                'The courier refused the pickup request: ' . ($result['message'] ?? 'no reason given'),
                502
            );
        }

        return [
            'pickup_request_uuid' => (string) ($this->db->selectOne(
                'SELECT `uuid` FROM `pickup_requests` WHERE `id` = :id',
                ['id' => $pickupId]
            )['uuid'] ?? ''),
            'courier' => $courier['code'],
            'pickup_date' => $pickupDate,
            'shipment_count' => count($waiting),
            'courier_reference' => $result['reference'],
            'message' => $result['message'],
        ];
    }

    /**
     * Closes off a set of parcels into a manifest for handover.
     *
     * @return array<string, mixed>
     */
    public function generateManifest(Request $request, string $courierCode): array
    {
        $courier = $this->couriers->findByCode($courierCode);

        if ($courier === null) {
            throw new NotFoundException('That courier does not exist.');
        }

        $shipments = $this->db->select(
            "SELECT * FROM `shipments`
              WHERE `courier_id` = :courier_id
                AND `status` IN ('booked','label_generated','pickup_scheduled')
                AND `manifest_id` IS NULL
                AND `is_deleted` = 0",
            ['courier_id' => (int) $courier['id']]
        );

        if ($shipments === []) {
            throw new HttpException('There are no parcels waiting to be manifested for this courier.', 422);
        }

        $awbs = array_values(array_filter(array_column($shipments, 'awb_number')));
        $documentUrl = $this->adapter->manifest($courier, $awbs);

        $manifestId = $this->db->transaction(function () use ($courier, $shipments, $documentUrl, $request): int {
            $number = $this->numbering->nextManifestNumber();

            $id = $this->db->insert(
                'INSERT INTO `manifests`
                     (`uuid`, `manifest_number`, `courier_id`, `manifest_date`, `shipment_count`,
                      `total_weight_grams`, `document_url`, `status`,
                      `created_by`, `created_date`, `is_active`, `is_deleted`, `version`)
                 VALUES
                     (:uuid, :number, :courier_id, CURDATE(), :count, :weight, :url, \'closed\',
                      :created_by, NOW(), 1, 0, 1)',
                [
                    'uuid' => Uuid::v4(),
                    'number' => $number,
                    'courier_id' => (int) $courier['id'],
                    'count' => count($shipments),
                    'weight' => array_sum(array_map(
                        static fn (array $s): int => (int) $s['chargeable_weight_grams'],
                        $shipments
                    )),
                    'url' => $documentUrl,
                    'created_by' => $request->authUserId(),
                ]
            );

            foreach ($shipments as $shipment) {
                $this->shipments->update((int) $shipment['id'], ['manifest_id' => $id], $request->authUserId());
            }

            return $id;
        });

        $manifest = (array) $this->db->selectOne(
            'SELECT * FROM `manifests` WHERE `id` = :id',
            ['id' => $manifestId]
        );

        return [
            'manifest' => [
                'uuid' => $manifest['uuid'],
                'manifest_number' => $manifest['manifest_number'],
                'courier' => $courier['code'],
                'shipment_count' => (int) $manifest['shipment_count'],
                'total_weight_grams' => (int) $manifest['total_weight_grams'],
                'document_url' => $manifest['document_url'],
                'status' => $manifest['status'],
            ],
        ];
    }

    /**
     * Pulls the latest tracking from the courier.
     *
     * @return array<string, mixed>
     */
    public function refreshTracking(Request $request, string $shipmentUuid): array
    {
        $shipment = $this->requireShipment($shipmentUuid);
        $courier = (array) $this->couriers->findById((int) $shipment['courier_id']);

        if ($shipment['awb_number'] === null) {
            throw new HttpException('This shipment has no tracking number yet.', 409);
        }

        $updates = $this->adapter->track($courier, (string) $shipment['awb_number']);
        $applied = $this->applyUpdates((int) $shipment['id'], $updates, 'poll', $request);

        return [
            'shipment' => $this->present((array) $this->shipments->findById((int) $shipment['id']), staffView: true),
            'new_events' => $applied,
        ];
    }

    /**
     * Handles an inbound tracking webhook.
     *
     * Same rule as payment webhooks: unauthenticated by design, the signature is
     * the authentication, and an unverified body is never acted on.
     *
     * @return array<string, mixed>
     */
    public function handleWebhook(Request $request, string $rawBody, string $signature): array
    {
        $parsed = $this->adapter->parseWebhook($rawBody, $signature);

        if ($parsed === null) {
            $this->logger->warning('Tracking webhook rejected', [
                'adapter' => $this->adapter->name(),
                'ip' => $request->ip,
                'body_length' => strlen($rawBody),
            ], 'delivery');

            return ['status' => 'rejected', 'processed' => false];
        }

        $shipment = $this->shipments->findByAwbAnyCourier($parsed['awb']);

        if ($shipment === null) {
            $this->logger->warning('Tracking webhook did not match a shipment', [
                'awb' => $parsed['awb'],
            ], 'delivery');

            return ['status' => 'unmatched', 'processed' => false];
        }

        $applied = $this->applyUpdates((int) $shipment['id'], $parsed['updates'], 'webhook', $request);

        return [
            'status' => $applied > 0 ? 'processed' : 'duplicate',
            'processed' => $applied > 0,
            'new_events' => $applied,
        ];
    }

    /**
     * Sweeps stale shipments and refreshes them.
     *
     * @return array<string, mixed>
     */
    public function refreshStaleShipments(Request $request, int $staleMinutes = 180, int $limit = 100): array
    {
        $refreshed = 0;
        $newEvents = 0;
        $failures = 0;

        foreach ($this->shipments->needingTrackingRefresh($staleMinutes, $limit) as $shipment) {
            try {
                $courier = (array) $this->couriers->findById((int) $shipment['courier_id']);
                $updates = $this->adapter->track($courier, (string) $shipment['awb_number']);
                $newEvents += $this->applyUpdates((int) $shipment['id'], $updates, 'poll', $request);
                ++$refreshed;
            } catch (\Throwable $exception) {
                ++$failures;

                $this->logger->warning('Could not refresh tracking', [
                    'shipment' => $shipment['shipment_number'],
                    'reason' => $exception->getMessage(),
                ], 'delivery');
            }
        }

        return ['refreshed' => $refreshed, 'new_events' => $newEvents, 'failures' => $failures];
    }

    /**
     * Applies tracking updates and moves the order along with them.
     *
     * @param array<int, TrackingUpdate> $updates
     */
    private function applyUpdates(int $shipmentId, array $updates, string $source, Request $request): int
    {
        if ($updates === []) {
            return 0;
        }

        return $this->db->transaction(function () use ($shipmentId, $updates, $source, $request): int {
            $shipment = $this->shipments->lockForUpdate($shipmentId);

            if ($shipment === null) {
                return 0;
            }

            $applied = 0;
            $latest = null;

            foreach ($updates as $update) {
                $isNew = $this->shipments->appendEvent(
                    shipmentId: $shipmentId,
                    status: $update->status,
                    title: $update->title,
                    description: $update->description,
                    location: $update->location,
                    occurredAt: $update->occurredAt,
                    courierEventId: $update->courierEventId,
                    eventCode: $update->eventCode,
                    source: $source,
                    raw: $update->raw,
                );

                if ($isNew) {
                    ++$applied;
                    $latest = $update;
                }
            }

            if ($latest === null) {
                return 0;
            }

            $changes = [
                'status' => $this->mapToShipmentStatus($latest->status),
                'last_scan_status' => $latest->status,
                'last_scan_location' => $latest->location,
                'last_scan_date' => $latest->occurredAt,
            ];

            if ($latest->status === TrackingUpdate::PICKED_UP) {
                $changes['picked_up_date'] = $latest->occurredAt;
            }

            if ($latest->status === TrackingUpdate::DELIVERED) {
                $changes['delivered_date'] = $latest->occurredAt;
            }

            if ($latest->status === TrackingUpdate::FAILED_DELIVERY) {
                $changes['delivery_attempts'] = (int) $shipment['delivery_attempts'] + 1;
            }

            if ($latest->status === TrackingUpdate::RTO_INITIATED) {
                $changes['rto_reason'] = substr((string) ($latest->description ?? 'Returned to origin'), 0, 250);
            }

            $this->shipments->update($shipmentId, $changes, null);

            $this->syncOrderStatus((int) $shipment['order_id'], $latest, $request);

            return $applied;
        });
    }

    /**
     * Moves the order to match the parcel, through the state machine.
     *
     * A refused transition is logged and dropped rather than forced. Couriers
     * replay scans and occasionally send them out of order; a late "out for
     * delivery" arriving after "delivered" should be recorded on the shipment
     * and change nothing about the order.
     */
    private function syncOrderStatus(int $orderId, TrackingUpdate $update, Request $request): void
    {
        $target = match ($update->status) {
            TrackingUpdate::PICKED_UP => OrderStatus::SHIPPED,
            TrackingUpdate::IN_TRANSIT => OrderStatus::SHIPPED,
            TrackingUpdate::OUT_FOR_DELIVERY => OrderStatus::OUT_FOR_DELIVERY,
            TrackingUpdate::DELIVERED => OrderStatus::DELIVERED,
            TrackingUpdate::RTO_INITIATED, TrackingUpdate::RTO_DELIVERED => OrderStatus::RETURNED,
            default => null,
        };

        if ($target === null) {
            return;
        }

        $order = $this->orders->lockForUpdate($orderId);

        if ($order === null || $order['status'] === $target) {
            return;
        }

        $verdict = $this->stateMachine->evaluate(
            (string) $order['status'],
            $target,
            (string) $order['payment_status'],
            (bool) $order['otp_verified'],
            otpRequired: false,
            isStaffOverride: true
        );

        if (!$verdict['allowed']) {
            if ($verdict['reason'] !== null) {
                $this->logger->info('Courier scan did not fit the order lifecycle; recorded but not applied', [
                    'order_id' => $orderId,
                    'from' => $order['status'],
                    'to' => $target,
                    'scan' => $update->status,
                    'reason' => $verdict['reason'],
                ], 'delivery');
            }

            return;
        }

        $changes = ['status' => $target];

        if ($target === OrderStatus::DELIVERED) {
            $changes['delivered_date'] = $update->occurredAt;
        }

        if ($target === OrderStatus::SHIPPED) {
            $changes['shipped_date'] = $update->occurredAt;
        }

        $this->orders->update($orderId, $changes, null);

        $this->orders->appendTimeline(
            orderId: $orderId,
            fromStatus: (string) $order['status'],
            toStatus: $target,
            title: $update->title,
            paymentStatus: (string) $order['payment_status'],
            note: $update->location === null
                ? $update->description
                : trim(($update->description ?? '') . ' (' . $update->location . ')'),
            changedByRole: 'courier',
        );
    }

    private function mapToShipmentStatus(string $trackingStatus): string
    {
        return match ($trackingStatus) {
            TrackingUpdate::PICKED_UP => 'picked_up',
            TrackingUpdate::IN_TRANSIT => 'in_transit',
            TrackingUpdate::OUT_FOR_DELIVERY => 'out_for_delivery',
            TrackingUpdate::DELIVERED => 'delivered',
            TrackingUpdate::FAILED_DELIVERY => 'failed_delivery',
            TrackingUpdate::RTO_INITIATED => 'rto_initiated',
            TrackingUpdate::RTO_DELIVERED => 'rto_delivered',
            TrackingUpdate::LOST => 'lost',
            TrackingUpdate::CANCELLED => 'cancelled',
            default => 'in_transit',
        };
    }

    /** @return array<string, mixed> */
    public function showForCustomer(Request $request, string $orderUuid): array
    {
        $order = $this->orders->findByUuid($orderUuid);

        if ($order === null || (int) $order['user_id'] !== (int) $request->authUserId()) {
            throw new NotFoundException('That order does not exist.');
        }

        $shipments = $this->shipments->forOrder((int) $order['id']);

        return [
            'shipments' => array_map(
                fn (array $shipment): array => $this->present($shipment, staffView: false),
                $shipments
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function showForStaff(string $shipmentUuid): array
    {
        $shipment = $this->requireShipment($shipmentUuid);
        $detail = $this->present($shipment, staffView: true);

        $detail['selection'] = $this->db->selectOne(
            'SELECT `strategy`, `reason`, `candidates_considered`, `candidates_eligible`,
                    `winning_score`, `candidates`, `was_manual_override`, `created_date`
               FROM `courier_selections`
              WHERE `shipment_id` = :shipment_id
              ORDER BY `id` DESC LIMIT 1',
            ['shipment_id' => (int) $shipment['id']]
        );

        if ($detail['selection'] !== null) {
            $detail['selection']['candidates'] = json_decode(
                (string) $detail['selection']['candidates'],
                true
            );
        }

        return $detail;
    }

    /**
     * @param array<string, mixed> $shipment
     *
     * @return array<string, mixed>
     */
    private function present(array $shipment, bool $staffView): array
    {
        $courier = (array) $this->couriers->findById((int) $shipment['courier_id']);

        $view = [
            'uuid' => $shipment['uuid'],
            'shipment_number' => $shipment['shipment_number'],
            'status' => $shipment['status'],
            'courier_name' => $courier['name'] ?? null,
            'awb_number' => $shipment['awb_number'],
            'tracking_url' => $shipment['awb_number'] === null
                ? null
                : $this->trackingUrl($courier, (string) $shipment['awb_number']),
            'estimated_delivery_date' => $shipment['estimated_delivery_date'],
            'delivered_date' => $shipment['delivered_date'],
            'last_scan_status' => $shipment['last_scan_status'],
            'last_scan_location' => $shipment['last_scan_location'],
            'last_scan_date' => $shipment['last_scan_date'],
            'events' => $this->shipments->eventsFor((int) $shipment['id'], !$staffView),
        ];

        if (!$staffView) {
            return $view;
        }

        // Weights, costs and label URLs are commercial information: what the
        // merchant pays a courier is not the customer's business.
        return $view + [
            'courier_code' => $courier['code'] ?? null,
            'label_url' => $shipment['label_url'],
            'actual_weight_grams' => (int) $shipment['actual_weight_grams'],
            'volumetric_weight_grams' => (int) $shipment['volumetric_weight_grams'],
            'chargeable_weight_grams' => (int) $shipment['chargeable_weight_grams'],
            'used_default_dimensions' => (bool) $shipment['used_default_dimensions'],
            'courier_charge' => (float) $shipment['courier_charge'],
            'customer_paid_delivery' => (float) $shipment['customer_paid_delivery'],
            'delivery_attempts' => (int) $shipment['delivery_attempts'],
            'declared_value' => (float) $shipment['declared_value'],
        ];
    }

    /** @param array<string, mixed> $courier */
    private function trackingUrl(array $courier, string $awb): ?string
    {
        $template = $courier['tracking_url_template'] ?? null;

        if (!is_string($template) || $template === '') {
            return null;
        }

        return str_replace('{awb}', rawurlencode($awb), $template);
    }

    private function advanceOrder(
        int $orderId,
        string $from,
        string $to,
        string $title,
        string $paymentStatus,
        ?string $note,
        ?int $actorId,
    ): void {
        $this->stateMachine->assert($from, $to, $paymentStatus, true, false, true);

        $this->orders->update($orderId, ['status' => $to], $actorId);

        $this->orders->appendTimeline(
            orderId: $orderId,
            fromStatus: $from,
            toStatus: $to,
            title: $title,
            paymentStatus: $paymentStatus,
            note: $note,
            changedBy: $actorId,
            changedByRole: 'staff',
        );
    }

    /** @return array<string, mixed> */
    private function requireShipment(string $uuid): array
    {
        $shipment = $this->shipments->findByUuid($uuid);

        if ($shipment === null) {
            throw new NotFoundException('That shipment does not exist.');
        }

        return $shipment;
    }
}
