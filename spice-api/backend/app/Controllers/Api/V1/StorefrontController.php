<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Services\StorefrontThemeService;

final class StorefrontController extends BaseController
{
    public function __construct(private readonly StorefrontThemeService $theme)
    {
    }

    /** GET /api/v1/storefront/theme — public; the web and Android apps read it on load. */
    public function theme(Request $request): Response
    {
        return Response::success(['theme' => $this->theme->theme()], 'Storefront theme loaded')
            ->withHeader('Cache-Control', 'public, max-age=60');
    }

    /** GET /api/v1/admin/storefront/theme */
    public function adminTheme(Request $request): Response
    {
        return Response::success(['theme' => $this->theme->theme()], 'Storefront theme loaded');
    }

    /** PATCH /api/v1/admin/storefront/theme */
    public function update(Request $request): Response
    {
        return Response::success(
            ['theme' => $this->theme->update($request->all(), $request)],
            'Storefront appearance saved'
        );
    }
}
