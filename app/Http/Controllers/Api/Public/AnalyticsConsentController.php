<?php

namespace App\Http\Controllers\Api\Public;

use App\Analytics\AnalyticsConfig;
use App\Analytics\TrackingRequestFactory;
use App\Enums\Analytics\PrivacyMode;
use App\Http\Controllers\Api\Base\PublicBaseController;
use App\Http\Requests\Public\Analytics\ConsentStatusRequest;
use App\Services\Analytics\PrivacyService;
use Illuminate\Http\JsonResponse;

/**
 * 방문자 동의 상태 조회 API
 *
 * 동의 연동 모드에서 브라우저 트래커가 「이 방문자가 분석 수집에 동의했는가」를 미리 알아
 * 보내지 않아도 될 비콘을 거르는 데 씁니다. 서버도 수집할 때마다 같은 판정을 다시 하므로
 * 보안 경계가 아닙니다.
 *
 * 코어는 동의를 관리하는 확장이 무엇인지 모릅니다. 수집 경로와 같은 코어 필터
 * (`core.analytics.consent_session_id` 로 식별자를 정하고 `core.analytics.visitor_has_consent` 로 묻는다)를
 * 거쳐 답하므로, 브라우저가 특정 확장의 경로를 알 필요가 없습니다.
 */
class AnalyticsConsentController extends PublicBaseController
{
    /**
     * 동의 상태 컨트롤러를 생성합니다.
     *
     * @param  PrivacyService  $privacy  개인정보 처리 서비스
     */
    public function __construct(private readonly PrivacyService $privacy)
    {
        parent::__construct();
    }

    /**
     * 이 방문자의 동의 상태를 돌려줍니다.
     *
     * `has_consent` 는 동의 연동 모드이고 답하는 확장이 있을 때만 참·거짓이며, 그 밖에는 null 입니다
     * (판정 대상이 아니므로 트래커가 막지 않는다).
     *
     * @param  ConsentStatusRequest  $request  동의 상태 조회 요청
     * @return JsonResponse 동의 상태 응답
     */
    public function show(ConsentStatusRequest $request): JsonResponse
    {
        $config = AnalyticsConfig::fromSettings();
        $source = $this->privacy->consentSource();
        $hasConsent = null;

        if ($config->privacyMode === PrivacyMode::Consent && $source !== PrivacyService::SOURCE_NONE) {
            $user = $request->user();
            $userId = $user?->getAuthIdentifier() !== null ? (int) $user->getAuthIdentifier() : null;

            $hasConsent = $this->privacy->visitorHasConsent($userId, TrackingRequestFactory::consentSessionId($request));
        }

        return $this->success('analytics.consent.fetch_success', [
            'consent_source' => $source,
            'has_consent' => $hasConsent,
        ]);
    }
}
