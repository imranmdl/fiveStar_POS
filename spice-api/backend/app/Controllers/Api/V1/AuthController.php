<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AuthService;
use App\Services\OtpService;
use App\Services\TokenService;

/**
 * Controllers contain no business logic — they validate input, delegate to a
 * service and shape the response. This is what keeps the web app, Android app
 * and iOS app behaviourally identical.
 */
final class AuthController extends BaseController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly OtpService $otp,
        private readonly TokenService $tokens,
    ) {
    }

    /**
     * POST /api/v1/auth/register
     *
     * Create an account. Returns an OTP challenge; the account is not usable until the mobile is verified.
     */
    public function register(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'full_name' => 'required|string|min:3|max:120',
            'mobile' => 'required|mobile_in',
            'email' => 'nullable|email|max:150',
            'password' => 'required|password|max:72',
            'referral_code' => 'nullable|string|min:6|max:20',
        ]);

        $result = $this->auth->register($data, $request);

        return Response::created(
            $result,
            'Registration successful. Please verify the code sent to your mobile number.'
        );
    }

    /** POST /api/v1/auth/register/verify */
    public function verifyRegistration(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'mobile' => 'required|mobile_in',
            'otp' => 'required|digits:6',
            'reference_token' => 'nullable|string|max:100',
        ]);

        $result = $this->auth->verifyRegistration(
            $data['mobile'],
            $data['otp'],
            $data['reference_token'] ?? null,
            $request
        );

        return Response::success($result, 'Mobile number verified. Welcome aboard!');
    }

    /** POST /api/v1/auth/otp/request */
    public function requestOtp(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'mobile' => 'required|mobile_in',
            'purpose' => 'required|in:registration,login,password_reset',
        ]);

        $result = match ($data['purpose']) {
            OtpService::PURPOSE_LOGIN => $this->auth->requestLoginOtp($data['mobile'], $request),
            OtpService::PURPOSE_PASSWORD_RESET => $this->auth->forgotPassword($data['mobile'], $request),
            default => $this->otp->issue($data['mobile'], OtpService::PURPOSE_REGISTRATION, null, $request),
        };

        // The message is intentionally identical whether or not the number is
        // registered, so this endpoint reveals nothing about who has an account.
        return Response::success($result, 'If this number is registered, a verification code has been sent.');
    }

    /**
     * POST /api/v1/auth/login
     *
     * Sign in with mobile or email and a password.
     */
    public function login(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'identifier' => 'required|string|max:150',
            'password' => 'required|string|max:72',
        ]);

        $result = $this->auth->loginWithPassword($data['identifier'], $data['password'], $request);

        return Response::success($result, 'Signed in successfully');
    }

    /** POST /api/v1/auth/login/otp */
    public function loginWithOtp(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'mobile' => 'required|mobile_in',
            'otp' => 'required|digits:6',
            'reference_token' => 'nullable|string|max:100',
        ]);

        $result = $this->auth->loginWithOtp(
            $data['mobile'],
            $data['otp'],
            $data['reference_token'] ?? null,
            $request
        );

        return Response::success($result, 'Signed in successfully');
    }

    /**
     * POST /api/v1/auth/token/refresh
     *
     * Exchange a refresh token for a new access token. The refresh token rotates, and presenting an old one revokes the whole session as suspected theft.
     */
    public function refresh(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'refresh_token' => 'required|string|min:40|max:200',
        ]);

        $result = $this->auth->refresh($data['refresh_token'], $request);

        return Response::success($result, 'Token refreshed');
    }

    /** POST /api/v1/auth/password/forgot */
    public function forgotPassword(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'mobile' => 'required|mobile_in',
        ]);

        $result = $this->auth->forgotPassword($data['mobile'], $request);

        return Response::success($result, 'If this number is registered, a reset code has been sent.');
    }

    /** POST /api/v1/auth/password/reset */
    public function resetPassword(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'mobile' => 'required|mobile_in',
            'otp' => 'required|digits:6',
            'password' => 'required|password|max:72',
            'password_confirmation' => 'required|string|max:72',
            'reference_token' => 'nullable|string|max:100',
        ]);

        if ($data['password'] !== $data['password_confirmation']) {
            throw new HttpException('Password confirmation does not match.', 422, [
                'password_confirmation' => ['The confirmation must match the new password.'],
            ]);
        }

        $this->auth->resetPassword(
            $data['mobile'],
            $data['otp'],
            $data['password'],
            $data['reference_token'] ?? null,
            $request
        );

        return Response::success([], 'Password reset successfully. Please sign in with your new password.');
    }

    /** POST /api/v1/auth/password/change (authenticated) */
    public function changePassword(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'current_password' => 'required|string|max:72',
            'password' => 'required|password|max:72',
            'password_confirmation' => 'required|string|max:72',
        ]);

        if ($data['password'] !== $data['password_confirmation']) {
            throw new HttpException('Password confirmation does not match.', 422, [
                'password_confirmation' => ['The confirmation must match the new password.'],
            ]);
        }

        $this->auth->changePassword(
            (int) $request->authUserId(),
            $data['current_password'],
            $data['password'],
            $request
        );

        return Response::success([], 'Password changed. Please sign in again on your other devices.');
    }

    /** GET /api/v1/auth/me (authenticated) */
    public function me(Request $request): Response
    {
        $user = (array) $request->attribute('auth_user');

        return Response::success(
            ['user' => $this->auth->publicUser($user)],
            'Profile loaded'
        );
    }

    /** GET /api/v1/auth/sessions (authenticated) */
    public function sessions(Request $request): Response
    {
        return Response::success(
            ['sessions' => $this->tokens->activeSessions((int) $request->authUserId())],
            'Active sessions loaded'
        );
    }

    /** POST /api/v1/auth/logout (authenticated) */
    public function logout(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'refresh_token' => 'nullable|string|max:200',
            'all_devices' => 'nullable|boolean',
        ]);

        $revoked = $this->auth->logout(
            (int) $request->authUserId(),
            $data['refresh_token'] ?? null,
            (bool) ($data['all_devices'] ?? false),
            $request
        );

        return Response::success(['sessions_revoked' => $revoked], 'Signed out successfully');
    }
}
