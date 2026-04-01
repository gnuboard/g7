<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\Base\AuthBaseController;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\ValidateResetTokenRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AuthController extends AuthBaseController
{
    public function __construct(
        private AuthService $authService
    ) {
        // 부모 생성자 호출하지 않음 - 인증 미들웨어를 수동으로 설정
        // parent::__construct();

        // 공개 인증 엔드포인트를 제외한 나머지에만 인증 미들웨어 적용
        $this->middleware('auth:sanctum')->except([
            'login',
            'register',
            'forgotPassword',
            'resetPassword',
            'validateResetToken',
        ]);
    }

    /**
     * 사용자를 로그인시킵니다.
     *
     * @param LoginRequest $request 로그인 요청 데이터
     * @return JsonResponse 로그인 결과와 사용자 정보, 토큰을 포함한 JSON 응답
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $data = $this->authService->login(
                $request->validated()['email'],
                $request->validated()['password']
            );

            // 사용자 정보는 Resource로, 토큰은 그대로
            $data['user'] = new UserResource($data['user']);

            return $this->success('auth.login_success', $data);
        } catch (ValidationException $e) {
            return $this->unauthorized('auth.login_failed');
        }
    }

    /**
     * 새로운 사용자를 등록시킵니다.
     *
     * @param RegisterRequest $request 등록 요청 데이터
     * @return JsonResponse 등록 결과와 사용자 정보, 토큰을 포함한 JSON 응답
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        try {
            $data = $this->authService->register($request->validated());

            // 사용자 정보는 Resource로, 토큰은 그대로
            $data['user'] = new UserResource($data['user']);

            return $this->success('auth.register_success', $data, 201);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'auth.register_failed');
        }
    }

    /**
     * 사용자를 로그아웃시킵니다. (현재 디바이스만)
     *
     * @param Request $request HTTP 요청
     * @return JsonResponse 로그아웃 성공 메시지
     */
    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return $this->success('auth.logout_success');
    }

    /**
     * 모든 디바이스에서 사용자를 로그아웃시킵니다.
     *
     * @param Request $request HTTP 요청
     * @return JsonResponse 로그아웃 성공 메시지
     */
    public function logoutFromAllDevices(Request $request): JsonResponse
    {
        $this->authService->logoutFromAllDevices($request->user());

        return $this->success('auth.logout_all_devices_success');
    }

    /**
     * 현재 로그인된 사용자의 정보를 반환합니다.
     *
     * @param Request $request HTTP 요청
     * @return JsonResponse 사용자 정보를 포함한 JSON 응답
     */
    public function user(Request $request): JsonResponse
    {
        $user = $request->user();

        // 역할 관계 로드 (권한은 역할을 통해 간접 연결)
        $user->load(['roles.permissions']);

        return $this->successWithResource(
            'common.success',
            new UserResource($user)
        );
    }

    /**
     * 사용자의 인증 토큰을 갱신합니다.
     *
     * @param Request $request HTTP 요청
     * @return JsonResponse 새로운 토큰과 사용자 정보를 포함한 JSON 응답
     */
    public function refresh(Request $request): JsonResponse
    {
        $data = $this->authService->refreshToken($request->user());

        // 사용자 정보는 Resource로, 토큰은 그대로
        if (isset($data['user'])) {
            $data['user'] = new UserResource($data['user']);
        }

        return $this->success('common.success', $data);
    }

    /**
     * 비밀번호 재설정 토큰을 검증합니다.
     *
     * @param ValidateResetTokenRequest $request 토큰 검증 요청 데이터
     * @return JsonResponse 토큰 유효성 검증 결과 JSON 응답
     */
    public function validateResetToken(ValidateResetTokenRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $result = $this->authService->validateResetToken(
            $validated['token'],
            $validated['email']
        );

        if (! $result['valid']) {
            return $this->validationError(
                ['token' => [$result['error']]],
                'auth.reset_token_invalid'
            );
        }

        return $this->success('common.success', $result);
    }

    /**
     * 비밀번호 찾기 요청을 처리하고 인증 이메일을 발송합니다.
     *
     * @param ForgotPasswordRequest $request 비밀번호 찾기 요청 데이터
     * @return JsonResponse 이메일 발송 결과 JSON 응답
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $this->authService->forgotPassword(
                $validated['email'],
                $validated['redirect_prefix'] ?? null
            );

            return $this->success('auth.password_reset_email_sent');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'auth.password_reset_failed');
        }
    }

    /**
     * 비밀번호를 재설정합니다.
     *
     * @param ResetPasswordRequest $request 비밀번호 재설정 요청 데이터
     * @return JsonResponse 비밀번호 재설정 결과 JSON 응답
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $this->authService->resetPassword(
                $validated['token'],
                $validated['email'],
                $validated['password']
            );

            return $this->success('auth.password_reset_success');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'auth.password_reset_failed');
        }
    }
}
