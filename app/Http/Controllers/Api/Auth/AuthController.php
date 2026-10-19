<?php

namespace App\Http\Controllers\Api\Auth;

use App\Exceptions\Auth\AccountLockedException;
use App\Exceptions\Auth\PasswordChangeUnavailableException;
use App\Exceptions\Auth\TwoFactorDeliveryFailedException;
use App\Exceptions\Auth\TwoFactorEnrollmentRequiredException;
use App\Http\Controllers\Api\Base\AuthBaseController;
use App\Http\Controllers\Concerns\BuildsAuthFailureResponses;
use App\Http\Requests\Auth\AuthenticatedRequest;
use App\Http\Requests\Auth\CompletePasswordChangeRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginHandoffRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\PostponePasswordChangeRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\TwoFactorChallengeRequest;
use App\Http\Requests\Auth\TwoFactorResendRequest;
use App\Http\Requests\Auth\ValidateResetTokenRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\LoginHandoffService;
use App\Services\AuthService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class AuthController extends AuthBaseController
{
    use BuildsAuthFailureResponses;

    /**
     * 사용자 인증 컨트롤러를 생성합니다.
     *
     * @param  AuthService  $authService  인증 서비스
     * @param  LoginHandoffService  $loginHandoffService  로그인 인계 코드 서비스
     */
    public function __construct(
        private AuthService $authService,
        private LoginHandoffService $loginHandoffService,
    ) {
        // 부모 생성자 호출하지 않음 - 인증 미들웨어를 수동으로 설정
        // parent::__construct();

        // 공개 인증 엔드포인트를 제외한 나머지에만 인증 미들웨어 적용
        $this->middleware('auth:sanctum')->except([
            'login',
            // 로그인 인계 교환도 아직 토큰이 없는 상태에서 호출된다 — 주체는 인계 코드가 식별한다
            'loginHandoff',
            // 2단계 인증 확인·재발송은 아직 토큰이 없는 상태에서 호출된다 — 주체는 challenge 가 식별한다
            'verifyTwoFactor',
            'resendTwoFactor',
            // 비밀번호 변경 단계도 토큰이 없는 상태에서 호출된다 — 주체는 변경 단계 식별자가 식별한다
            'completePasswordChange',
            'postponePasswordChange',
            'register',
            'forgotPassword',
            'resetPassword',
            'validateResetToken',
        ]);
    }

    /**
     * 사용자를 로그인시킵니다.
     *
     * @param  LoginRequest  $request  로그인 요청 데이터
     * @return JsonResponse 로그인 결과와 사용자 정보, 토큰을 포함한 JSON 응답
     */
    public function login(LoginRequest $request): JsonResponse
    {
        try {
            $data = $this->authService->login(
                $request->validated()['email'],
                $request->validated()['password']
            );

            return $this->loginStepResponse($data);
        } catch (AccountLockedException $e) {
            return $this->lockedResponse($e);
        } catch (TwoFactorEnrollmentRequiredException $e) {
            return $this->enrollmentRequiredResponse();
        } catch (TwoFactorDeliveryFailedException $e) {
            return $this->deliveryFailedResponse();
        } catch (PasswordChangeUnavailableException $e) {
            return $this->passwordChangeUnavailableResponse();
        } catch (ValidationException $e) {
            return $this->unauthorized('auth.login_failed');
        }
    }

    /**
     * 로그인 인계 코드를 교환해 로그인시킵니다.
     *
     * 외부 인증(소셜 로그인 등)이 본인 확인을 마친 회원에 대해 발급한 일회용 코드다. 코드를 꺼내는 순간 지워지고,
     * 코어가 잠금·회원 상태·2단계 인증·비밀번호 변경·휴면 해제를 비밀번호 로그인과 똑같이 처리한다 — 응답도
     * `login` 과 같은 모양이라 로그인 화면이 같은 분기를 그대로 쓴다. 관리자 경로 인계는 아직 열지 않았다(422).
     *
     * @param  LoginHandoffRequest  $request  인계 코드 교환 요청
     * @return JsonResponse `login` 과 같은 모양의 응답, 코드 무효는 422, 상태 거절은 401, 잠금은 423
     */
    public function loginHandoff(LoginHandoffRequest $request): JsonResponse
    {
        $handoff = $this->loginHandoffService->redeem((string) $request->validated()['code'], 'user');

        // 없음·만료·재사용·관리자 경로·회원 삭제 — 전부 같은 응답으로 답한다(상태를 캐내지 못하게).
        if ($handoff === null) {
            return $this->validationError(['code' => [__('auth.login_handoff_invalid')]], 'auth.login_handoff_invalid');
        }

        try {
            $data = $this->authService->loginVerifiedUser($handoff['user'], $handoff['context'], 'user');

            return $this->loginStepResponse($data);
        } catch (AccountLockedException $e) {
            return $this->lockedResponse($e);
        } catch (TwoFactorEnrollmentRequiredException $e) {
            return $this->enrollmentRequiredResponse();
        } catch (TwoFactorDeliveryFailedException $e) {
            return $this->deliveryFailedResponse();
        } catch (PasswordChangeUnavailableException $e) {
            return $this->passwordChangeUnavailableResponse();
        } catch (ValidationException $e) {
            // 회원 상태 거절(차단·탈퇴·비활성) — 비밀번호 로그인과 같은 401 이고, 상태별 코어 문구를 `errors.email` 에 싣는다.
            return $this->error('auth.login_failed', 401, $e->errors());
        }
    }

    /**
     * 로그인 결과를 단계별 응답으로 바꿉니다 — 비밀번호 로그인과 로그인 인계가 같은 모양을 쓴다.
     *
     * @param  array<string, mixed>  $data  `AuthService::login()`·`loginVerifiedUser()` 결과
     * @return JsonResponse 2단계 인증 안내 / 비밀번호 변경 안내 / 로그인 성공(사용자 리소스·토큰)
     */
    private function loginStepResponse(array $data): JsonResponse
    {
        // 2단계 인증이 켜져 있으면 아직 토큰이 없다 — 인증 코드 확인 단계로 안내한다
        if ($data['two_factor_required'] ?? false) {
            return $this->success($this->twoFactorRequiredMessageKey($data), $data);
        }

        // 비밀번호를 바꿔야 하면 아직 토큰도 사용자도 없다 — 변경 단계로 안내한다
        if ($data['password_change_required'] ?? false) {
            return $this->success($this->passwordChangeRequiredMessageKey($data), $data);
        }

        // 사용자 정보는 Resource로, 토큰은 그대로
        $data['user'] = new UserResource($data['user']);

        return $this->success('auth.login_success', $data);
    }

    /**
     * 2단계 인증 코드를 확인하고 로그인을 완료합니다.
     *
     * 비밀번호 확인 단계(`login`)는 토큰 대신 challenge 를 돌려주며, 이 엔드포인트가
     * 코드 확인에 성공해야 비로소 토큰이 발급됩니다.
     *
     * @param  TwoFactorChallengeRequest  $request  challenge 확인 요청
     * @return JsonResponse 로그인 결과와 사용자 정보, 토큰을 포함한 JSON 응답
     */
    public function verifyTwoFactor(TwoFactorChallengeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $data = $this->authService->completeTwoFactor(
                $validated['challenge_id'],
                ['code' => $validated['code']]
            );

            if ($data['password_change_required'] ?? false) {
                return $this->success($this->passwordChangeRequiredMessageKey($data), $data);
            }

            $data['user'] = new UserResource($data['user']);

            return $this->success('auth.login_success', $data);
        } catch (AccountLockedException $e) {
            // 세션을 여는 지점이므로 `login` 과 같은 423 계약을 따른다 — 화면은 두 경로를
            // 구분하지 않으므로 한쪽만 다른 모양이면 잠금 안내가 깨진다.
            return $this->lockedResponse($e);
        } catch (TwoFactorEnrollmentRequiredException $e) {
            return $this->enrollmentRequiredResponse();
        } catch (TwoFactorDeliveryFailedException $e) {
            return $this->deliveryFailedResponse();
        } catch (PasswordChangeUnavailableException $e) {
            return $this->passwordChangeUnavailableResponse();
        } catch (ValidationException $e) {
            return $this->unauthorized('auth.two_factor_failed');
        }
    }

    /**
     * 로그인 비밀번호 변경 단계에서 새 비밀번호를 받아 로그인을 마칩니다.
     *
     * 비밀번호·2단계 인증을 통과했지만 비밀번호를 바꿔야 하는 회원이 로그인이 돌려준 변경 단계 식별자와
     * 새 비밀번호를 보낸다. 성공 응답은 로그인 성공과 같은 모양이다.
     *
     * @param  CompletePasswordChangeRequest  $request  변경 단계 완료 요청
     * @return JsonResponse 로그인 결과(사용자·토큰) 또는 403/422/423
     */
    public function completePasswordChange(CompletePasswordChangeRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $data = $this->authService->completePasswordChange(
                $validated['challenge_id'],
                $validated['password'],
                'user'
            );

            $data['user'] = new UserResource($data['user']);

            return $this->success('auth.password_change_success', $data);
        } catch (AccountLockedException $e) {
            return $this->lockedResponse($e);
        } catch (AuthorizationException $e) {
            return $this->forbidden('auth.admin_required');
        } catch (ValidationException $e) {
            // 로그인 핸들러처럼 401 로 바꾸지 않는다 — 식별자 무효·처리 중·규칙 위반은 화면이 칸 단위로 안내한다.
            return $this->validationError($e->errors(), 'auth.password_change_invalid_challenge');
        }
    }

    /**
     * 로그인 비밀번호 변경 단계에서 '다음에 변경' 을 골라 로그인을 마칩니다 ('다음에 변경 허용' 모드의 만료만).
     *
     * @param  PostponePasswordChangeRequest  $request  변경 연기 요청
     * @return JsonResponse 로그인 결과(사용자·토큰·연기 종료일) 또는 403/422/423
     */
    public function postponePasswordChange(PostponePasswordChangeRequest $request): JsonResponse
    {
        try {
            $data = $this->authService->postponePasswordChange(
                $request->validated()['challenge_id'],
                'user'
            );

            $data['user'] = new UserResource($data['user']);

            return $this->success('auth.password_change_postponed', $data);
        } catch (AccountLockedException $e) {
            return $this->lockedResponse($e);
        } catch (AuthorizationException $e) {
            return $this->forbidden('auth.admin_required');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), $this->authService->postponeFailureMessageKey($e));
        }
    }

    /**
     * 2단계 인증 코드를 재발송합니다.
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
            $data = $this->authService->resendTwoFactorChallenge(
                $request->validated()['challenge_id']
            );

            return $this->success($this->twoFactorRequiredMessageKey($data), $data);
        } catch (AccountLockedException $e) {
            return $this->lockedResponse($e);
        } catch (TwoFactorEnrollmentRequiredException $e) {
            return $this->enrollmentRequiredResponse();
        } catch (TwoFactorDeliveryFailedException $e) {
            return $this->deliveryFailedResponse();
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'auth.two_factor_invalid_challenge');
        }
    }

    /**
     * 새로운 사용자를 등록시킵니다.
     *
     * @param  RegisterRequest  $request  등록 요청 데이터
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
     * @param  AuthenticatedRequest  $request  인증 세션 요청 (본문 입력 없음)
     * @return JsonResponse 로그아웃 성공 메시지
     */
    public function logout(AuthenticatedRequest $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return $this->success('auth.logout_success');
    }

    /**
     * 모든 디바이스에서 사용자를 로그아웃시킵니다.
     *
     * @param  AuthenticatedRequest  $request  인증 세션 요청 (본문 입력 없음)
     * @return JsonResponse 로그아웃 성공 메시지
     */
    public function logoutFromAllDevices(AuthenticatedRequest $request): JsonResponse
    {
        $this->authService->logoutFromAllDevices($request->user());

        return $this->success('auth.logout_all_devices_success');
    }

    /**
     * 현재 로그인된 사용자의 정보를 반환합니다.
     *
     * @param  AuthenticatedRequest  $request  인증 세션 요청 (본문 입력 없음)
     * @return JsonResponse 사용자 정보를 포함한 JSON 응답
     */
    public function user(AuthenticatedRequest $request): JsonResponse
    {
        $user = $request->user();

        // 역할 관계 로드 (권한은 역할을 통해 간접 연결)
        $user->load(['roles.permissions']);

        // toAuthArray(): core.user.filter_resource_data 필터를 적용해 모듈 필드(결제 통화 등)를 병합.
        // 프론트 currentUser 출처라, 로그인 시 계정 영속 통화 덮어씀(D-LOGIN-CUR)을 충족한다.
        return $this->success(
            'common.success',
            (new UserResource($user))->toAuthArray($request)
        );
    }

    /**
     * 사용자의 인증 토큰을 갱신합니다.
     *
     * @param  AuthenticatedRequest  $request  인증 세션 요청 (본문 입력 없음)
     * @return JsonResponse 새로운 토큰과 사용자 정보를 포함한 JSON 응답
     */
    public function refresh(AuthenticatedRequest $request): JsonResponse
    {
        try {
            $data = $this->authService->refreshToken($request->user());
        } catch (AccountLockedException $e) {
            return $this->lockedResponse($e);
        }

        // 사용자 정보는 Resource로, 토큰은 그대로
        if (isset($data['user'])) {
            $data['user'] = new UserResource($data['user']);
        }

        return $this->success('common.success', $data);
    }

    /**
     * 비밀번호 재설정 토큰을 검증합니다.
     *
     * @param  ValidateResetTokenRequest  $request  토큰 검증 요청 데이터
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
     * @param  ForgotPasswordRequest  $request  비밀번호 찾기 요청 데이터
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
     * @param  ResetPasswordRequest  $request  비밀번호 재설정 요청 데이터
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
