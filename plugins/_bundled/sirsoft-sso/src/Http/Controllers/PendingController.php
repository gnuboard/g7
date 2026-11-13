<?php

namespace Plugins\Sirsoft\Sso\Http\Controllers;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Http\Requests\ConsentCompleteRequest;
use Plugins\Sirsoft\Sso\Http\Requests\PendingTokenRequest;
use Plugins\Sirsoft\Sso\Services\LinkVerificationService;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * 처음 로그인 약관 동의 대기(`/login?sso_pending=`) API — 로그인 화면 모달이 부른다.
 *
 * 완료 응답은 인계 주소가 아니라 인계 코드(`handoff_code`)와 코어 인계 묶음 쿠키다 — 모달이 엔진 `loginHandoff` 액션을 그 코드로
 * 직접 실행해 사용자(`applyLoginResult`) 또는 관리자(`applyAdminLoginResult`) 로그인 결과로 분기한다.
 *
 * @since 1.0.0
 */
class PendingController extends PublicBaseController
{
    /**
     * @param  LinkVerificationService  $service  대기 완료 서비스
     */
    public function __construct(private readonly LinkVerificationService $service)
    {
        parent::__construct();
    }

    /**
     * 동의 모달에 보일 정보.
     *
     * @param  PendingTokenRequest  $request  요청
     * @param  string  $token  대기 토큰
     * @return JsonResponse 정보
     */
    public function show(PendingTokenRequest $request, string $token): JsonResponse
    {
        try {
            return ResponseHelper::pluginSuccess('sirsoft-sso', 'messages.pending.loaded', $this->service->consentInfo($token, $request));
        } catch (SsoException $e) {
            return self::flowError($e);
        }
    }

    /**
     * 동의를 받고 회원을 만든 뒤 인계 코드를 돌려줍니다.
     *
     * @param  ConsentCompleteRequest  $request  요청
     * @param  string  $token  대기 토큰
     * @return JsonResponse `{handoff_code, guard, redirect}` + 인계 묶음 쿠키
     */
    public function complete(ConsentCompleteRequest $request, string $token): JsonResponse
    {
        try {
            $issued = $this->service->completeConsent($token, $request, $request->memberFieldInput());
        } catch (SsoException $e) {
            return self::flowError($e);
        }

        return self::handoffResponse($issued);
    }

    /**
     * 인계 코드 응답을 만듭니다 — 코어 인계 묶음 쿠키를 붙인다(api 그룹은 쿠키 대기열이 없어 직접 붙인다).
     *
     * @param  object{code: string, cookie: Cookie|null, guard: string, redirect: string}  $issued  발급 결과
     * @return JsonResponse 응답
     */
    public static function handoffResponse(object $issued): JsonResponse
    {
        $response = ResponseHelper::pluginSuccess('sirsoft-sso', 'messages.handoff.issued', [
            'handoff_code' => $issued->code,
            'guard' => $issued->guard,
            'redirect' => $issued->redirect,
        ]);

        if ($issued->cookie !== null) {
            $response->headers->setCookie($issued->cookie);
        }

        return $response;
    }

    /**
     * 흐름 오류 응답 — `errors.code` 에 오류 코드를 싣는다(모달이 코드별 문구를 고른다).
     *
     * @param  SsoException  $e  오류
     * @return JsonResponse 응답
     */
    public static function flowError(SsoException $e): JsonResponse
    {
        return ResponseHelper::pluginError('sirsoft-sso', 'messages.error.'.$e->errorCode, $e->status, ['code' => $e->errorCode]);
    }
}
