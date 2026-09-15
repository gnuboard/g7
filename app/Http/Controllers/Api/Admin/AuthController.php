<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\Auth\AccountLockedException;
use App\Exceptions\Auth\TwoFactorDeliveryFailedException;
use App\Http\Controllers\Api\Base\AdminBaseController;
use App\Http\Controllers\Concerns\BuildsAuthFailureResponses;
use App\Http\Requests\Auth\AuthenticatedRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Http\Requests\Auth\TwoFactorResendRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class AuthController extends AdminBaseController
{
    use BuildsAuthFailureResponses;

    public function __construct(
        private AuthService $authService
    ) {
        // 부모 생성자 호출하지 않음 - 인증 미들웨어를 수동으로 설정
        // parent::__construct();

        // 2단계 인증 확인·재발송은 아직 토큰이 없는 상태에서 호출된다 — 주체는 challenge 가
        // 식별하며, 관리자 여부는 코드 확인에 성공한 뒤 서비스가 돌려준 사용자로 판정한다.
        $this->middleware(['auth:sanctum', 'admin'])->except([
            'login',
            'verifyTwoFactor',
            'resendTwoFactor',
        ]);
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

            // 2단계 인증이 켜져 있으면 아직 토큰도 사용자도 없다 — 관리자 판정은 코드 확인
            // 뒤로 미룬다. 이 분기가 없으면 $data['user'] 가 없어 500 이 되고, 관리자까지
            // 로그인할 수 없어 설정을 되돌릴 수단이 사라진다.
            if ($data['two_factor_required'] ?? false) {
                return $this->success('auth.two_factor_required', $data);
            }

            $user = $data['user'];

            // 관리자 권한 확인
            if (! $user->isAdmin()) {
                // 이 시점에는 이미 토큰과 web 세션이 발급되어 있다 — 거절하면서 남겨 두면
                // 관리자가 아닌 사용자가 응답만 403 을 받을 뿐 세션은 그대로 유효해진다.
                $this->authService->revokeIssuedSession($user, $data['token']);

                return $this->forbidden('auth.admin_required');
            }

            // 사용자 정보는 Resource로, 토큰은 그대로
            $data['user'] = new UserResource($user);

            return $this->success('auth.admin_login_success', $data);
        } catch (AccountLockedException $e) {
            return $this->lockedResponse($e);
        } catch (TwoFactorDeliveryFailedException $e) {
            return $this->deliveryFailedResponse();
        } catch (ValidationException $e) {
            return $this->unauthorized('auth.login_failed');
        }
    }

    /**
     * 관리자의 2단계 인증 코드를 확인하고 로그인을 완료합니다.
     *
     * 비밀번호 확인 단계(`login`)는 토큰 대신 challenge 를 돌려주며, 이 엔드포인트가
     * 코드 확인에 성공해야 비로소 토큰이 발급됩니다.
     *
     * @param  TwoFactorChallengeRequest  $request  challenge 확인 요청
     * @return JsonResponse 로그인 결과와 관리자 정보, 토큰을 포함한 JSON 응답
     */
    public function verifyTwoFactor(TwoFactorChallengeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $data = $this->authService->completeTwoFactor(
                $validated['challenge_id'],
                ['code' => $validated['code']]
            );

            $user = $data['user'];

            if (! $user->isAdmin()) {
                // completeTwoFactor() 는 코드 확인에 성공한 시점에 토큰을 발급한다.
                // 관리자 판정으로 거절하면서 그 발급분을 회수하지 않으면 관리자가 아닌
                // 사용자가 유효한 세션을 손에 쥔 채 응답만 403 을 받는다.
                $this->authService->revokeIssuedSession($user, $data['token']);

                return $this->forbidden('auth.admin_required');
            }

            $data['user'] = new UserResource($user);

            return $this->success('auth.admin_login_success', $data);
        } catch (AccountLockedException $e) {
            // 세션을 여는 지점이므로 `login` 과 같은 423 계약을 따른다.
            return $this->lockedResponse($e);
        } catch (TwoFactorDeliveryFailedException $e) {
            return $this->deliveryFailedResponse();
        } catch (ValidationException $e) {
            return $this->unauthorized('auth.two_factor_failed');
        }
    }

    /**
     * 관리자의 2단계 인증 코드를 재발송합니다.
     *
     * 기존 challenge 는 취소되고 새 challenge 가 발행되므로, 앞서 받은 인증번호는
     * 더 이상 통하지 않습니다.
     *
     * @param  TwoFactorResendRequest  $request  challenge 재발송 요청
     * @return JsonResponse 새 challenge 정보를 포함한 JSON 응답
     */
    public function resendTwoFactor(TwoFactorResendRequest $request): JsonResponse
    {
        try {
            $resolvedUser = null;

            $data = $this->authService->resendTwoFactorChallenge(
                $request->validated()['challenge_id'],
                $resolvedUser
            );

            // 이 단계는 토큰을 발급하지 않으므로 회수할 것이 없다 — 다만 완료할 수 없는
            // 상대에게 새 인증번호를 계속 보내지는 않는다.
            if ($resolvedUser === null || ! $resolvedUser->isAdmin()) {
                return $this->forbidden('auth.admin_required');
            }

            return $this->success('auth.two_factor_required', $data);
        } catch (AccountLockedException $e) {
            return $this->lockedResponse($e);
        } catch (TwoFactorDeliveryFailedException $e) {
            return $this->deliveryFailedResponse();
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'auth.two_factor_invalid_challenge');
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
        } catch (AccountLockedException $e) {
            // 재발급도 세션을 여는 지점이다 — 사용자 경로와 같은 423 계약을 따른다.
            // 이 catch 가 없으면 잠긴 계정의 재발급 시도가 500 으로 새어 나간다.
            return $this->lockedResponse($e);
        } catch (ValidationException $e) {
            return $this->unauthorized('auth.unauthenticated');
        }
    }
}
