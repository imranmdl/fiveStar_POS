<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;

final class HealthController extends BaseController
{
    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
    ) {
    }

    /** GET /api/v1/health — used by the load balancer and uptime monitor. */
    public function index(Request $request): Response
    {
        $databaseOk = true;
        $databaseError = null;

        try {
            $this->db->scalar('SELECT 1');
        } catch (\Throwable $exception) {
            $databaseOk = false;
            $databaseError = $exception->getMessage();
        }

        $payload = [
            'application' => $this->config->get('app.name'),
            'environment' => $this->config->get('app.env'),
            'api_version' => 'v1',
            'server_time' => date('c'),
            'checks' => [
                'database' => $databaseOk ? 'ok' : 'failed',
            ],
        ];

        if (!$databaseOk) {
            return Response::error('Service degraded', 503, ['database' => [(string) $databaseError]]);
        }

        return Response::success($payload, 'Service healthy');
    }

    /** GET /api/v1/admin/ping — verifies the auth + role middleware chain. */
    public function adminPing(Request $request): Response
    {
        $user = (array) $request->attribute('auth_user');

        return Response::success([
            'authenticated_as' => $user['full_name'],
            'role' => $user['role_code'],
        ], 'Administrator access confirmed');
    }
}
