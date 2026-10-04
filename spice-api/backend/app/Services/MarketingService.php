<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Repositories\LeadRepository;

/**
 * Website visitor capture and the recurring promotional broadcast.
 *
 * A lead is not a `users` account — no password, no signup, just contact
 * details left for offers (see migration 025_marketing_leads.sql). Sending
 * to one reuses NotificationService::queue() exactly as it already works
 * for a guest recipient (no user_id) — nothing there needed to change.
 *
 * CONSENT GATES EVERYTHING. `activeConsentedForBroadcast()` is the only
 * source the recurring send reads from; a lead that never ticked the
 * opt-in box is stored (so a second visit doesn't ask again) but never
 * messaged.
 */
final class MarketingService
{
    public function __construct(
        private readonly LeadRepository $leads,
        private readonly NotificationService $notifications,
        private readonly OfferService $offers,
        private readonly ReportingService $reporting,
        private readonly Database $db,
    ) {
    }

    /**
     * @param array<string, mixed> $data full_name?, email, mobile, consent
     *
     * @return array<string, mixed>
     */
    public function captureLead(array $data, Request $request): array
    {
        $mobile = (string) $data['mobile'];
        $consent = (bool) ($data['consent'] ?? false);
        $existing = $this->leads->findByMobile($mobile);

        $attributes = [
            'full_name' => $data['full_name'] ?? null,
            'email' => $data['email'],
            'mobile' => $mobile,
            'source' => $data['source'] ?? 'website_popup',
            'consent_marketing' => $consent ? 1 : 0,
        ];

        if ($consent) {
            $attributes['consent_date'] = date('Y-m-d H:i:s');
        }

        if ($existing !== null) {
            $this->leads->update((int) $existing['id'], $attributes, $request->authUserId());

            return (array) $this->leads->findByUuid((string) $existing['uuid']);
        }

        $id = $this->leads->create($attributes, $request->authUserId());

        return (array) $this->leads->findById($id);
    }

    public function unsubscribe(string $uuid): void
    {
        $lead = $this->leads->findByUuid($uuid);

        if ($lead === null) {
            throw new HttpException('That subscription was not found.', 404);
        }

        $this->leads->update((int) $lead['id'], ['unsubscribed_date' => date('Y-m-d H:i:s')], null);
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginateForAdmin(array $params): array
    {
        return $this->leads->paginateForAdmin($params);
    }

    /** @return array{total:int, consented:int, unsubscribed:int} */
    public function summary(): array
    {
        return $this->leads->summary();
    }

    /**
     * What to promote this run: the best live offer, else the newest
     * product, else a best-seller — first one that actually exists wins.
     * Returns null only when none of the three exist, so the broadcast
     * genuinely has nothing to say (an empty catalogue), rather than
     * sending a blank message.
     *
     * @return array{headline: string, detail: string}|null
     */
    public function nextBroadcastContent(): ?array
    {
        foreach ($this->offers->liveOffers() as $offer) {
            if (($offer['discount']['type'] ?? 'none') !== 'none') {
                return [
                    'headline' => 'New offer: ' . $offer['title'],
                    'detail' => (string) ($offer['discount']['summary'] ?? ''),
                ];
            }
        }

        $newProduct = $this->db->selectOne(
            "SELECT `name` FROM `products`
              WHERE `status` = 'published' AND `is_deleted` = 0
                AND `created_date` >= DATE_SUB(NOW(), INTERVAL 14 DAY)
              ORDER BY `created_date` DESC
              LIMIT 1"
        );

        if ($newProduct !== null) {
            return [
                'headline' => 'New in stock: ' . $newProduct['name'],
                'detail' => 'Fresh arrival — check it out today.',
            ];
        }

        $bestSellers = $this->reporting->topProducts(date('Y-m-d', strtotime('-30 days')), date('Y-m-d'), 1);

        if ($bestSellers !== []) {
            return [
                'headline' => 'Bestseller: ' . $bestSellers[0]['product_name'],
                'detail' => 'A customer favourite — order yours today.',
            ];
        }

        return null;
    }

    /**
     * The scheduled-task entry point (SchedulerService::execute()) — one
     * promotional SMS to every consenting, non-unsubscribed lead. A
     * missing/invalid recipient or a policy suppression (quiet hours,
     * DND) is per-lead and doesn't stop the rest — NotificationService::queue()
     * already reports each outcome without throwing.
     */
    public function sendRecurringBroadcast(Request $request): string
    {
        $content = $this->nextBroadcastContent();

        if ($content === null) {
            return 'Nothing to promote — skipped.';
        }

        $recipients = $this->leads->activeConsentedForBroadcast();

        if ($recipients === []) {
            return 'No consenting leads — skipped. Would have sent: ' . $content['headline'];
        }

        $sent = 0;
        $suppressed = 0;

        foreach ($recipients as $lead) {
            $result = $this->notifications->queue(
                'marketing.broadcast',
                'sms',
                $content,
                ['recipient' => $lead['mobile'], 'dedupe_key' => sprintf(
                    'marketing.broadcast:sms:%s:%s',
                    $lead['mobile'],
                    date('Y-m-d')
                )]
            );

            if ($result['queued']) {
                ++$sent;
                $this->leads->update(
                    (int) $lead['id'],
                    ['last_messaged_date' => date('Y-m-d H:i:s'), 'message_count' => (int) $lead['message_count'] + 1],
                    null
                );
            } else {
                ++$suppressed;
            }
        }

        return sprintf(
            'Sent to %d lead(s), %d suppressed: %s',
            $sent,
            $suppressed,
            $content['headline']
        );
    }
}
