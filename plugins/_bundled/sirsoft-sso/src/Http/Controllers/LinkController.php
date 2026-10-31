<?php

namespace Plugins\Sirsoft\Sso\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Http\Requests\LinkVerifyRequest;
use Plugins\Sirsoft\Sso\Http\Requests\PendingTokenRequest;
use Plugins\Sirsoft\Sso\Services\LinkVerificationService;

/**
 * 기존 회원 연결 확인 대기(`/login?sso_link=`) API — 로그인 화면 모달이 부른다.
 *
 * @since 1.0.0
 */
class LinkController extends PublicBaseController
{
    /**
     * @param  LinkVerificationService  $service  연결 확인 서비스
     */
    public function __construct(private readonly LinkVerificationService $service)
    {
        parent::__construct();
    }

    /**
     * 연결 확인 모달에 보일 정보 (기존 회원 이메일은 가린다).
     *
     * @param  PendingTokenRequest  $request  요청
     * @param  string  $token  대기 토큰
     * @return JsonResponse 정보
     */
    public function show(PendingTokenRequest $request, string $token): JsonResponse
    {
        try {
            return ResponseHelper::pluginSuccess('sirsoft-sso', 'messages.link.loaded', $this->service->linkInfo($token, $request));
        } catch (SsoException $e) {
            return PendingController::flowError($e);
        }
    }

    /**
     * 기존 회원 메일로 인증번호를 보냅니다.
     *
     * @param  PendingTokenRequest  $request  요청
     * @param  string  $token  대기 토큰
     * @return JsonResponse `{challenge_id, expires_at}`
     */
    public function email(PendingTokenRequest $request, string $token): JsonResponse
    {
        try {
            return ResponseHelper::pluginSuccess('sirsoft-sso', 'messages.link.code_sent', $this->service->requestEmailCode($token, $request));
        } catch (SsoException $e) {
            return PendingController::flowError($e);
        }
    }

    /**
     * 비밀번호 또는 메일 인증번호로 연결을 확인하고 인계 코드를 돌려줍니다.
     *
     * @param  LinkVerifyRequest  $request  요청
     * @param  string  $token  대기 토큰
     * @return JsonResponse `{handoff_code, guard, redirect}` + 인계 묶음 쿠키
     */
    public function verify(LinkVerifyRequest $request, string $token): JsonResponse
    {
        try {
            $issued = $request->input('method') === 'password'
                ? $this->service->verifyPassword($token, (string) $request->input('password', ''), $request)
                : $this->service->verifyEmailCode($token, (string) $request->input('challenge_id', ''), (string) $request->input('code', ''), $request);
        } catch (SsoException $e) {
            return PendingController::flowError($e);
        }

        return PendingController::handoffResponse($issued);
    }
}
