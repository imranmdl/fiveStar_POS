<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Core\Logger;

/**
 * Development / staging driver. Writes the message to storage/logs instead of
 * sending it, so the OTP flow is fully testable before an SMS contract exists.
 *
 * Select it with SMS_DRIVER=log. Production uses HttpSmsGateway.
 */
final class LogSmsGateway implements SmsGatewayInterface
{
    public function __construct(private readonly Logger $logger)
    {
    }

    public function send(string $mobile, string $message, array $variables = []): array
    {
        $reference = 'log-' . bin2hex(random_bytes(6));

        $this->logger->info('Outbound SMS (log driver)', [
            'mobile' => $mobile,
            'message' => $message,
            'variables' => $variables,
            'provider_reference' => $reference,
        ], 'sms');

        return ['accepted' => true, 'provider_reference' => $reference, 'detail' => 'written to log'];
    }
}
