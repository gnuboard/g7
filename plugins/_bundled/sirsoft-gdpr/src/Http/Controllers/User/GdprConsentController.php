<?php

namespace Plugins\Sirsoft\Gdpr\Http\Controllers\User;

use App\Helpers\ResponseHelper;
use App\Http\Controllers\Api\Base\AuthBaseController;
use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Gdpr\Http\Requests\GrantConsentRequest;
use Plugins\Sirsoft\Gdpr\Http\Requests\RevokeConsentRequest;
use Plugins\Sirsoft\Gdpr\Http\Resources\GdprUserConsentHistoryResource;
use Plugins\Sirsoft\Gdpr\Http\Resources\GdprUserConsentResource;
use Plugins\Sirsoft\Gdpr\Services\GdprConsentService;

/**
 * GDPR 회원 동의 컨트롤러
 *
 * - GET  /api/plugins/sirsoft-gdpr/consent/me      : 본인 활성 동의 동기화
 * - GET  /api/plugins/sirsoft-gdpr/consent/history : 본인 동의 이력
 * - POST /api/plugins/sirsoft-gdpr/consent/revoke  : 본인 동의 철회
 */
class GdprConsentController extends AuthBaseController
{
    /**
     * GdprConsentController 생성자
     *
     * @param GdprConsentService $consentService GDPR 동의 서비스
     */
    public function __construct(
        private readonly GdprConsentService $consentService,
    ) {
        parent::__construct();
    }

    /**
     * 본인 활성 동의 목록 (디바이스 간 동기화용).
     *
     * @return JsonResponse
     */
    public function me(): JsonResponse
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        // 카탈로그의 모든 카테고리 ∪ 회원 status 매트릭스 (Art.7(3) 대칭성: 철회/재동의/신규 동의 한 화면 처리).
        // 마이페이지 「내 동의 현황」 표는 활성 동의만이 아니라 모든 카테고리를 노출하고
        // 상태별로 액션(철회/다시 동의/동의/필수 안내) 을 분기.
        $consents = $this->consentService->getMyConsentMatrix($user->id);

        return ResponseHelper::success('messages.success', [
            'user_id' => $user->id,
            'needs_renewal' => $this->consentService->needsRenewal($user->id),
            'current_policy_version' => $this->consentService->getCurrentPolicyVersion(),
            'consents' => GdprUserConsentResource::collection($consents),
        ]);
    }

    /**
     * 본인 동의 이력 (마이페이지 표시용).
     *
     * @return JsonResponse
     */
    public function history(): JsonResponse
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $histories = $this->consentService->getHistories($user->id);

        return ResponseHelper::success('messages.success', [
            'histories' => GdprUserConsentHistoryResource::collection($histories),
        ]);
    }

    /**
     * 본인 동의 철회.
     *
     * @param RevokeConsentRequest $request 검증된 요청
     * @return JsonResponse
     */
    public function revoke(RevokeConsentRequest $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $consentKey = (string) $request->validated('consent_key');

        $this->consentService->updateConsent(
            userId: $user->id,
            sessionId: null,
            consentKey: $consentKey,
            value: false,
            source: 'mypage',
        );

        return ResponseHelper::success('sirsoft-gdpr::messages.consent.revoked', [
            'consent_key' => $consentKey,
        ]);
    }

    /**
     * 본인 동의 부여 (재동의 / 신규 동의).
     *
     * Art.7(3) 자유 변경권의 부여 방향 — 마이페이지 「내 동의 현황」 에서
     * 「다시 동의」 / 「동의」 버튼 클릭 시 호출. 화이트리스트 검사는 FormRequest 가 수행.
     *
     * @param GrantConsentRequest $request 검증된 요청
     * @return JsonResponse
     */
    public function grant(GrantConsentRequest $request): JsonResponse
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $consentKey = (string) $request->validated('consent_key');

        $this->consentService->updateConsent(
            userId: $user->id,
            sessionId: null,
            consentKey: $consentKey,
            value: true,
            source: 'mypage',
        );

        return ResponseHelper::success('sirsoft-gdpr::messages.consent.granted_again', [
            'consent_key' => $consentKey,
        ]);
    }

    /**
     * 정책 버전 bump 후 활성 선택형 동의를 일괄 새 버전으로 갱신합니다 (전체 항목 다시 동의 — #19).
     *
     * 필수 쿠키와 철회 상태는 대상 외. 의사 변경은 없으나 *재동의 의사 표명* 으로 처리되어
     * history 에 action=granted 행이 N개 누적됨 (각 갱신 항목당 1행, Art.7(1) 입증 트레일).
     *
     * @return JsonResponse
     */
    public function renewAll(): JsonResponse
    {
        $user = $this->getCurrentUser();
        if ($user === null) {
            return $this->unauthorized();
        }

        $renewed = $this->consentService->renewAllForCurrentPolicy($user->id);

        // 시그니처: success($messageKey, $data, $statusCode, $messageParams, $domain).
        // renewed 카운트는 메시지 보간 (4번째 messageParams) 과 응답 페이로드 (2번째 data) 두 곳에
        // 모두 필요 — 프론트 toast 는 lang 의 {renewed} placeholder 를 토큰으로 직접 보간하지만,
        // 다른 클라이언트 (모바일/API 직접 호출) 가 응답 message 만 사용할 가능성 대비.
        return ResponseHelper::success(
            'sirsoft-gdpr::messages.consent.renew_all_success',
            ['renewed' => $renewed],
            200,
            ['renewed' => $renewed],
        );
    }

}
