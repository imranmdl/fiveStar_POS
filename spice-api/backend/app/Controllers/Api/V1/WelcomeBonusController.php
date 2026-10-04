<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\WelcomeBonusService;

/**
 * Admin Privilege Management: the "edit option" for the new-customer
 * welcome bonus (Wallet module) — see WelcomeBonusService's own doc
 * comment for where the actual credit happens.
 */
final class WelcomeBonusController extends BaseController
{
    public function __construct(private readonly WelcomeBonusService $welcomeBonus)
    {
    }

    /** GET /api/v1/admin-privilege/wallet/welcome-bonus */
    public function show(Request $request): Response
    {
        return Response::success($this->welcomeBonus->config(), 'Welcome bonus settings loaded');
    }

    /** PATCH /api/v1/admin-privilege/wallet/welcome-bonus */
    public function update(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'enabled' => 'required|boolean',
            'amount' => 'required|numeric|min:0|max:100000',
            'expiry_days' => 'nullable|int|min:0|max:3650',
        ]);

        $result = $this->welcomeBonus->updateConfig(
            (bool) $data['enabled'],
            (float) $data['amount'],
            (int) ($data['expiry_days'] ?? 0),
            $request
        );

        return Response::success($result, 'Welcome bonus settings updated');
    }
}
