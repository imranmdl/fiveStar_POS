<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Helpers\Uuid;

/**
 * Mobile verification through phone.email ("Sign in with Phone").
 *
 * The phone.email button on the web page verifies the customer's number on
 * phone.email's own service and then gives the page a one-off JSON URL on
 * user.phone.email. The page sends us only that URL. This service fetches it
 * itself, so the verified number always comes from phone.email and never from
 * the browser, and records the URL so each verification is used once only.
 *
 * Used for customer sign-in (AuthService::loginWithPhoneEmail) and for order
 * confirmation (CheckoutService::verifyPhone) — the same two places the SMS
 * OTP is used, which stays available as the fallback.
 */
class PhoneEmailService
{
    public const PURPOSE_LOGIN = 'login';
    public const PURPOSE_ORDER_CONFIRMATION = 'order_confirmation';

    private const MAX_BODY_BYTES = 16384;

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->clientId() !== '';
    }

    public function clientId(): string
    {
        return trim((string) $this->config->get('auth.phone_email.client_id', ''));
    }

    /**
     * Fetches and checks the verified number behind a user_json_url and marks
     * the URL used.
     *
     * @return array{mobile:string, country_code:string, first_name:string, last_name:string}
     */
    public function consume(string $url, string $purpose, ?int $userId = null, ?int $referenceId = null): array
    {
        if (!$this->isEnabled()) {
            throw new HttpException('Phone verification is not switched on for this shop.', 503);
        }

        $url = trim($url);
        $this->assertTrustedUrl($url);
        $hash = hash('sha256', $url);

        if ($this->db->scalar('SELECT 1 FROM phone_email_verifications WHERE url_hash = ? LIMIT 1', [$hash])) {
            throw new HttpException('This phone verification has already been used. Please verify your number again.', 409);
        }

        $payload = $this->fetch($url);
        $verified = $this->parse($payload);

        try {
            $this->db->insert(
                'INSERT INTO phone_email_verifications
                    (uuid, url_hash, mobile, country_code, purpose, user_id, reference_id, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [Uuid::v4(), $hash, $verified['mobile'], $verified['country_code'], $purpose, $userId, $referenceId, $userId]
            );
        } catch (\PDOException $e) {
            // Two requests racing with the same URL: the unique key lets one win.
            if ((string) $e->getCode() === '23000') {
                throw new HttpException('This phone verification has already been used. Please verify your number again.', 409);
            }
            throw $e;
        }

        return $verified;
    }

    /** Links a consumed verification to the account it signed in to. */
    public function attachUser(string $url, int $userId): void
    {
        $this->db->execute(
            'UPDATE phone_email_verifications SET user_id = ?, updated_by = ?, updated_date = NOW(), version = version + 1
             WHERE url_hash = ? AND user_id IS NULL',
            [$userId, $userId, hash('sha256', trim($url))]
        );
    }

    /**
     * Only https URLs on phone.email's user host are fetched, so the endpoint
     * can't be pointed at our own network or at a JSON file someone made up.
     */
    private function assertTrustedUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) $this->config->get('auth.phone_email.json_host', 'user.phone.email'));
        $schemes = $this->config->get('auth.phone_email.allow_http', false) ? ['https', 'http'] : ['https'];

        $ok = is_array($parts)
            && in_array($parts['scheme'] ?? '', $schemes, true)
            && strtolower($parts['host'] ?? '') === $host
            && (!isset($parts['port']) || $schemes !== ['https'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && preg_match('#^/[A-Za-z0-9_\-]+\.json$#', $parts['path'] ?? '') === 1;

        if (!$ok) {
            throw new HttpException(
                'The phone verification link is not valid.',
                422,
                ['user_json_url' => ['Must be the user_json_url returned by the phone.email button.']]
            );
        }
    }

    /**
     * Downloads the verification JSON. Protected so tests can stand in for
     * phone.email.
     */
    protected function fetch(string $url): string
    {
        $handle = curl_init($url);
        $timeout = max(2, (int) $this->config->get('auth.phone_email.timeout_seconds', 8));
        $body = '';

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => $this->config->get('auth.phone_email.allow_http', false)
                ? CURLPROTO_HTTPS | CURLPROTO_HTTP
                : CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                $body .= $chunk;
                // Returning less than the chunk size aborts an oversized reply.
                return strlen($body) > self::MAX_BODY_BYTES ? 0 : strlen($chunk);
            },
        ]);

        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($ok === false || $status !== 200) {
            throw new HttpException(
                $status === 404
                    ? 'This phone verification has expired. Please verify your number again.'
                    : 'Could not reach phone.email to confirm your number. Please try again.',
                $status === 404 ? 410 : 502
            );
        }

        return $body;
    }

    /** @return array{mobile:string, country_code:string, first_name:string, last_name:string} */
    private function parse(string $payload): array
    {
        $data = json_decode($payload, true);

        if (!is_array($data)) {
            throw new HttpException('phone.email returned an unreadable answer. Please verify your number again.', 502);
        }

        $country = preg_replace('/\D+/', '', (string) ($data['user_country_code'] ?? ''));
        $number = preg_replace('/\D+/', '', (string) ($data['user_phone_number'] ?? ''));

        // Some answers carry the number with its country code already on it.
        if ($country !== '' && strlen($number) > 10 && str_starts_with($number, $country)) {
            $number = substr($number, strlen($country));
        }
        $number = ltrim($number, '0');

        if ($number === '') {
            throw new HttpException('phone.email did not return a verified number. Please verify again.', 502);
        }

        if ($country !== '91' || preg_match('/^[6-9]\d{9}$/', $number) !== 1) {
            throw new HttpException(
                'Only Indian mobile numbers (+91) can be used with this shop.',
                422,
                ['mobile' => ['Verify a +91 mobile number.']]
            );
        }

        return [
            'mobile' => $number,
            'country_code' => '+' . $country,
            'first_name' => trim((string) ($data['user_first_name'] ?? '')),
            'last_name' => trim((string) ($data['user_last_name'] ?? '')),
        ];
    }
}
