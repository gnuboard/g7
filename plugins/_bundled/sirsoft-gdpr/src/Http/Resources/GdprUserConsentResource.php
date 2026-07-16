<?php

namespace Plugins\Sirsoft\Gdpr\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Plugins\Sirsoft\Gdpr\Services\CookieCategoryService;
use Plugins\Sirsoft\Gdpr\Services\GdprConsentService;

/**
 * GDPR 사용자 현재 동의 상태 API 리소스
 *
 * `gdpr_user_consents` (status, mutable) 단일 레코드를 응답 형식으로 변환.
 * 회원의 활성/철회 상태를 마이페이지·로그인 직후 동기화에 노출합니다.
 *
 * `consent_label` 은 카탈로그(쿠키 카테고리) 다국어 라벨을 현재 locale 기준으로 변환한 값으로,
 * 마이페이지 「내 동의 현황」 에서 raw key (cookie_analytics) 대신 사람 친화 표기를 노출하기 위함.
 *
 * `needs_renewal_this_item` 은 *선택형 항목 중 정책 버전이 옛 버전인* 행에서 true — 마이페이지
 * 액션 컬럼이 "다시 동의" 대신 "최신 정책으로 갱신" 분기를 표시할 때 사용 (#21).
 */
class GdprUserConsentResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param Request $request HTTP 요청
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $categoryService = app(CookieCategoryService::class);
        $consentService = app(GdprConsentService::class);
        $consentKey = (string) $this->consent_key;
        $isRequired = $categoryService->isRequired($consentKey);
        $isConsented = (bool) $this->is_consented;
        $currentPolicyVersion = $consentService->getCurrentPolicyVersion();
        $policyVersion = (string) ($this->policy_version ?? '');
        // 선택형 항목 중 정책 버전이 옛 버전인지 — 필수는 ePrivacy Art.5(3) 면제로 갱신 대상 아님.
        $needsRenewalThisItem = ! $isRequired && $isConsented && $policyVersion !== '' && $policyVersion !== $currentPolicyVersion;

        return [
            'id' => $this->id,
            'consent_key' => $consentKey,
            'consent_label' => $categoryService->getLabelForKey($consentKey),
            'consent_description' => $categoryService->getDescriptionForKey($consentKey),
            'consent_category' => $this->consent_category,
            'is_required' => $isRequired,
            'is_consented' => $isConsented,
            // Art.7(3) 대칭성 + ePrivacy Art.5(3) 동의 면제 패턴 — 필수 카테고리는 철회/재동의 불가, 선택형만 양방향.
            'can_revoke' => ! $isRequired && $isConsented,
            // 활성 동의 + 옛 버전 → 갱신 의도. 활성 동의 + 최신 버전 → 갱신 불요 (이미 최신).
            // 비활성 (철회 또는 신규) → 다시 동의 (기존 동작).
            'can_grant' => ! $isRequired && (! $isConsented || $needsRenewalThisItem),
            'needs_renewal_this_item' => $needsRenewalThisItem,
            'consented_at' => $this->formatDateTimeStringForUser($this->consented_at),
            'revoked_at' => $this->formatDateTimeStringForUser($this->revoked_at),
            'consent_count' => (int) $this->consent_count,
            'policy_version' => $this->policy_version,
            'last_source' => $this->last_source,
            ...$this->formatTimestamps(),
        ];
    }
}
