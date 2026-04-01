<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
     * @param LoginRequest $request 로그인 요청 데이터
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
            if (!$user->isAdmin()) {
                return ResponseHelper::forbidden('auth.admin_required');
            }

            // 사용자 정보는 Resource로, 토큰은 그대로
            $data['user'] = new UserResource($user);

            return $this->success('auth.admin_login_success', $data);
        } catch (ValidationException $e) {
            return ResponseHelper::unauthorized('auth.login_failed', [], 'core');
        }
    }

    /**
     * 관리자를 로그아웃시킵니다.
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
     * 현재 로그인된 관리자의 정보를 반환합니다.
     *
     * @param Request $request HTTP 요청
     * @return JsonResponse 관리자 정보를 포함한 JSON 응답
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
     * 관리자의 인증 토큰을 갱신합니다.
     *
     * @param Request $request HTTP 요청
     * @return JsonResponse 새로운 토큰과 관리자 정보를 포함한 JSON 응답
     */
    public function refresh(Request $request): JsonResponse
    {
        try {
            $data = $this->authService->refreshToken($request->user());

            // 사용자 정보는 Resource로, 토큰은 그대로
            if (isset($data['user'])) {
                $data['user'] = new UserResource($data['user']);
            }
            return $this->success('common.success', $data);
        } catch (ValidationException $e) {
            return ResponseHelper::unauthorized('auth.unauthenticated', [], 'core');
        }
    }
}
