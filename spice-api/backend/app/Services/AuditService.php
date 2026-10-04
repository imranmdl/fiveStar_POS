<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Request;
use App\Repositories\AuditLogRepository;

/**
 * Records before/after snapshots for business entities (BR-009).
 * Sensitive fields are never written to the audit trail.
 */
final class AuditService
{
    private const REDACTED_KEYS = [
        'password',
        'password_hash',
        'password_confirmation',
        'otp',
        'otp_hash',
        'token',
        'token_hash',
        'access_token',
        'refresh_token',
    ];

    public function __construct(private readonly AuditLogRepository $repository)
    {
    }

    /**
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     */
    public function log(
        string $entityName,
        ?int $entityId,
        string $action,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?Request $request = null,
        ?string $entityUuid = null,
        ?string $notes = null,
    ): void {
        $user = $request?->attribute('auth_user');

        $this->repository->create([
            'entity_name' => $entityName,
            'entity_id' => $entityId,
            'entity_uuid' => $entityUuid,
            'action' => $action,
            'old_values' => $this->encode($oldValues),
            'new_values' => $this->encode($newValues),
            'performed_by_user_id' => $user === null ? null : (int) $user['id'],
            'performed_by_role' => $user === null ? 'system' : (string) $user['role_code'],
            'ip_address' => $request?->ip,
            'user_agent' => $request?->userAgent,
            'request_id' => $request?->requestId,
            'notes' => $notes,
        ], $user === null ? null : (int) $user['id']);
    }

    /** @param array<string, mixed>|null $values */
    private function encode(?array $values): ?string
    {
        if ($values === null) {
            return null;
        }

        foreach (array_keys($values) as $key) {
            if (in_array(strtolower((string) $key), self::REDACTED_KEYS, true)) {
                $values[$key] = '[redacted]';
            }
        }

        return json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
