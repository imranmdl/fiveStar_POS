<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Core\Logger;
use App\Helpers\Str;

/**
 * MSG91 OTP driver (SMS_DRIVER=msg91).
 *
 * Sends the shop's own one-time codes through MSG91's OTP API
 * (POST https://control.msg91.com/api/v5/otp) using the OTP template set up in
 * the MSG91 panel (its DLT-approved text must contain ##OTP##). The code is
 * generated and checked by OtpService as before; MSG91 only delivers it.
 *
 * Only OTP messages go out this way. Other texts (order updates, invoice
 * reminders) need their own MSG91 templates and are logged, not sent.
 */
final class Msg91OtpGateway implements SmsGatewayInterface
{
    /** @param array<string, mixed> $config notifications.sms */
    public function __construct(
        private readonly array $config,
        private readonly Logger $logger,
    ) {
    }

    public function send(string $mobile, string $message, array $variables = []): array
    {
        $msg91 = (array) ($this->config['msg91'] ?? []);
        $authKey = trim((string) ($msg91['authkey'] ?? ''));
        $templateId = trim((string) ($msg91['otp_template_id'] ?? ''));
        $otp = (string) ($variables['otp'] ?? '');

        if ($otp === '') {
            $this->logger->info('SMS not sent: MSG91 driver sends OTP codes only', [
                'mobile' => Str::maskMobile($mobile),
                'message' => mb_substr($message, 0, 160),
            ], 'sms');

            return ['accepted' => false, 'provider_reference' => null, 'detail' => 'Only OTP messages are sent through MSG91.'];
        }

        if ($authKey === '' || $templateId === '') {
            $this->logger->warning('SMS not sent: MSG91_AUTHKEY or MSG91_OTP_TEMPLATE_ID is missing', [
                'mobile' => Str::maskMobile($mobile),
            ], 'sms');

            return ['accepted' => false, 'provider_reference' => null, 'detail' => 'MSG91 is not configured.'];
        }

        $query = http_build_query([
            'template_id' => $templateId,
            'mobile' => ((string) ($this->config['country_code'] ?? '91')) . $mobile,
            'otp' => $otp,
            'otp_length' => strlen($otp),
            'otp_expiry' => max(1, (int) ($variables['minutes'] ?? 5)),
            'realTimeResponse' => 1,
        ]);
        $base = rtrim((string) ($msg91['base_url'] ?? 'https://control.msg91.com'), '/');

        $handle = curl_init($base . '/api/v5/otp?' . $query);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['OTP' => $otp]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'authkey: ' . $authKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ($this->config['timeout_seconds'] ?? 10),
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        $decoded = is_string($body) ? json_decode($body, true) : null;
        // MSG91 answers HTTP 200 even for errors; the body's "type" decides.
        $accepted = $body !== false && $status >= 200 && $status < 300
            && is_array($decoded) && ($decoded['type'] ?? '') === 'success';
        $detail = $accepted ? null : (is_array($decoded) && isset($decoded['message'])
            ? (string) $decoded['message']
            : ($error !== '' ? $error : 'HTTP ' . $status));

        $this->logger->info('Outbound OTP via MSG91', [
            'mobile' => Str::maskMobile($mobile),
            'http_status' => $status,
            'accepted' => $accepted,
            'detail' => $detail,
            'request_id' => is_array($decoded) ? ($decoded['request_id'] ?? null) : null,
        ], 'sms');

        return [
            'accepted' => $accepted,
            'provider_reference' => is_array($decoded) ? ($decoded['request_id'] ?? null) : null,
            'detail' => $detail,
        ];
    }
}
