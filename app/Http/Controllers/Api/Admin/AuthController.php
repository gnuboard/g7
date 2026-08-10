<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\Auth\AccountLockedException;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Auth\AuthenticatedRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class AuthController extends AdminBaseController
{
    public function __construct(
        private AuthService $authService
    ) {
        parent::__construct();
    }

    /**
     * 관리자를 로그인시킵니다.
     *
     * @param  LoginRequest  $request  로그인 요청 데이터
     * @return JsonResponse 로그인 결과와 관리자 정보, 토큰을 포함한 JSON 응답
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $data = $this->authService->login(
                $request->validated()['email'],
                $request->validated()['password']
            );

            $user = $data['user'];

            // 관리자 권한 확인
            if (! $user->isAdmin()) {
                return $this->forbidden('auth.admin_required');
            }

            // 사용자 정보는 Resource로, 토큰은 그대로
            $data['user'] = new UserResource($user);

            return $this->success('auth.admin_login_success', $data);
        } catch (AccountLockedException $e) {
            // 영구 잠금(무한대 설정)은 해제 시각·잔여 시간이 없다 — null 그대로 노출.
            return $this->error(
                $e->isPermanent() ? 'auth.account_locked_permanently' : 'auth.account_locked',
                423,
                [
                    'locked_until' => $e->lockedUntil?->toIso8601String(),
                    'retry_after_seconds' => $e->remainingMinutes === null ? null : $e->remainingMinutes * 60,
                    'permanent' => $e->isPermanent(),
                ],
                ['minutes' => $e->remainingMinutes]
            );
        } catch (ValidationException $e) {
            return $this->unauthorized('auth.login_failed');
        }
    }

    /**
     * 관리자를 로그아웃시킵니다.
     *
     * @param  AuthenticatedRequest  $request  인증 세션 요청 (본문 입력 없음)
     * @return JsonResponse 로그아웃 성공 메시지
     */
    public function logout(AuthenticatedRequest $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return $this->success('auth.logout_success');
    }

    /**
     * 현재 로그인된 관리자의 정보를 반환합니다.
     *
     * @param  AuthenticatedRequest  $request  인증 세션 요청 (본문 입력 없음)
     * @return JsonResponse 관리자 정보를 포함한 JSON 응답
     */
    public function user(AuthenticatedRequest $request): JsonResponse
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
     * 관리자의 인증 토큰을 갱신합니다.
     *
     * @param  AuthenticatedRequest  $request  인증 세션 요청 (본문 입력 없음)
     * @return JsonResponse 새로운 토큰과 관리자 정보를 포함한 JSON 응답
     */
    public function refresh(AuthenticatedRequest $request): JsonResponse
    {
        try {
            $data = $this->authService->refreshToken($request->user());

            // 사용자 정보는 Resource로, 토큰은 그대로
            if (isset($data['user'])) {
                $data['user'] = new UserResource($data['user']);
            }

            return $this->success('common.success', $data);
        } catch (ValidationException $e) {
            return $this->unauthorized('auth.unauthenticated');
        }
    }
}
