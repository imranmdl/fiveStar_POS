<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Exceptions\HttpException;
use App\Core\Logger;
use App\Core\Request;
use App\Helpers\Money;
use App\Repositories\SettingRepository;

/**
 * A one-time wallet credit for a new customer, the first time their mobile
 * number is verified (see AuthService::verifyRegistration() and the
 * auto-verify branch in loginWithOtp()) — "welcome bonus" / "welcome offer".
 *
 * Whether it fires at all, and for how much, is an admin decision made from
 * the Admin Privilege panel (item 3's "edit option" — see
 * WelcomeBonusController, gated by system_settings.edit), not a hard-coded
 * amount. Off by default.
 *
 * Reuses WalletService::credit() exactly the way a referral reward already
 * credits a wallet — same ledger, same audit trail, same idempotency
 * guarantee. No new wallet table, and per WalletService::credit()'s own
 * idempotency check, calling this twice for the same customer (registration
 * followed by an unrelated OTP-login auto-verify, say) credits them once.
 */
final class WelcomeBonusService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly SettingRepository $settings,
        private readonly Logger $logger,
    ) {
    }

    /** @return array{enabled:bool, amount:float, expiry_days:int} */
    public function config(): array
    {
        return [
            'enabled' => $this->settings->boolValue('welcome_bonus_enabled', false),
            'amount' => (float) ($this->settings->value('welcome_bonus_amount') ?? '50.00'),
            'expiry_days' => $this->settings->intValue('welcome_bonus_expiry_days', 0),
        ];
    }

    /** @return array{enabled:bool, amount:float, expiry_days:int} */
    public function updateConfig(bool $enabled, float $amount, int $expiryDays, Request $request): array
    {
        if ($amount < 0 || $amount > 100000) {
            throw new HttpException('Welcome bonus amount must be between 0 and 100000.', 422, [
                'amount' => ['Must be between 0 and 100000.'],
            ]);
        }

        if ($expiryDays < 0 || $expiryDays > 3650) {
            throw new HttpException('Expiry days must be between 0 (never) and 3650.', 422, [
                'expiry_days' => ['Must be between 0 and 3650.'],
            ]);
        }

        $before = $this->config();

        $this->settings->put('welcome_bonus_enabled', $enabled ? '1' : '0', $request->authUserId());
        $this->settings->put('welcome_bonus_amount', number_format($amount, 2, '.', ''), $request->authUserId());
        $this->settings->put('welcome_bonus_expiry_days', (string) $expiryDays, $request->authUserId());

        return $this->config() + ['previous' => $before];
    }

    /**
     * Credits the bonus if the feature is on — called right after a
     * customer's mobile is verified for the first time. Deliberately
     * cannot throw: a wallet problem must never turn into a failed sign-in
     * or a failed registration, the same reasoning AuthService::register()
     * already applies to referral bookkeeping being best-effort.
     */
    public function creditIfEligible(int $userId, Request $request): void
    {
        $config = $this->config();

        if (!$config['enabled'] || $config['amount'] <= 0) {
            return;
        }

        try {
            $this->wallet->credit(
                userId: $userId,
                amount: Money::fromDecimal($config['amount']),
                source: 'promotional',
                narration: 'Welcome bonus for joining Spice & Dry Fruits',
                // One per customer, ever — however many times this method is
                // called for the same account, WalletService::credit()'s own
                // idempotency check makes every call after the first a no-op.
                idempotencyKey: 'welcome_bonus:' . $userId,
                referenceType: 'users',
                referenceId: (string) $userId,
                expiryDays: $config['expiry_days'] > 0 ? $config['expiry_days'] : null,
                request: $request,
            );
        } catch (\Throwable $exception) {
            $this->logger->error('Welcome bonus credit failed', [
                'user_id' => $userId,
                'error' => $exception->getMessage(),
            ], 'wallet');
        }
    }
}
