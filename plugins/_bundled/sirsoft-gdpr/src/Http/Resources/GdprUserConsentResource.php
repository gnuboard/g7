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
// audit:allow api-doc-coverage reason: 이번 변경은 toArray() 주석 문구 정정 1건뿐이다. 응답 필드 구성·값 산출 로직이 그대로라 docs/api/ 의 응답 필드 표에 갱신할 내용이 없다.
class GdprUserConsentResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환합니다.
     *
     * @param  Request  $request  HTTP 요청
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

        // 이슈 #430 — 마이페이지 「내 동의 현황」 각 행의 상태를 서버가 판정한다.
        // 상태 판정: 필수 → required(동의), 선택 동의중 → consented(동의),
        // 선택 명시 거부 → rejected(거부), 선택 철회 이력 → revoked(철회), 그 외(신규 미선택) → none.
        if ($isRequired) {
            $status = 'required';
        } elseif ($isConsented) {
            $status = 'consented';
        } elseif ((bool) $this->is_rejected) {
            $status = 'rejected';
        } elseif ($this->revoked_at !== null) {
            $status = 'revoked';
        } else {
            $status = 'none';
        }
        // 표시 날짜/라벨은 "동의한 항목" + "철회 이력이 있는 항목"만 노출한다 (프로젝트 결정, 2026-07-20 / 이슈 #509 16번 갱신).
        // 동의 상태(required·consented)는 동의일 + '동의' 라벨, 철회(revoked)는 철회일 + '철회' 라벨을 내려준다.
        // 거부·신규 미선택은 status_label·status_at_formatted 를 null 로 두어 프론트가 날짜 줄을 렌더하지 않는다.
        // (거부일은 사용자 화면 확인 가치가 낮고, 감사 로그·관리자 화면에서 확인 가능. 철회일은 유저 본인의
        // 철회권 행사 시점 확인 필요성이 있어 노출 — 이슈 #509 16번)
        $isConsentState = $status === 'required' || $status === 'consented';
        $statusRawDate = match (true) {
            $isConsentState => $this->consented_at,
            $status === 'revoked' => $this->revoked_at,
            default => null,
        };
        $statusLabel = match (true) {
            $isConsentState => __('sirsoft-gdpr::messages.mypage.privacy.status.granted'),
            $status === 'revoked' => __('sirsoft-gdpr::messages.mypage.privacy.status.revoked'),
            default => null,
        };
        // 이슈 #430 (버튼 재설계) — 상태 배지 문구. 토글 대신 배지+단일버튼 UI 에서
        // 오른쪽 배지에 노출한다. 철회 이력(revoked)과 신규 미선택(none)은 '미설정'으로 통합.
        $statusBadgeKey = match ($status) {
            'required' => 'required',
            'consented' => 'consented',
            'rejected' => 'rejected',
            default => 'unset', // revoked, none
        };
        $statusBadgeLabel = __('sirsoft-gdpr::messages.mypage.privacy.badge.'.$statusBadgeKey);

        return [
            'id' => $this->id,
            'consent_key' => $consentKey,
            'consent_label' => $categoryService->getLabelForKey($consentKey),
            'consent_description' => $categoryService->getDescriptionForKey($consentKey),
            'consent_category' => $this->consent_category,
            'is_required' => $isRequired,
            'is_consented' => $isConsented,
            // 이슈 #430 — 명시적 거부 상태. 마이페이지 배지 3분화(동의/철회/거부) 에 사용.
            'is_rejected' => (bool) $this->is_rejected,
            'rejected_at' => $this->formatDateTimeStringForUser($this->rejected_at),
            // Art.7(3) 대칭성 + ePrivacy Art.5(3) 동의 면제 패턴 — 필수 카테고리는 철회/재동의 불가, 선택형만 양방향.
            'can_revoke' => ! $isRequired && $isConsented,
            // 활성 동의 + 옛 버전 → 갱신 의도. 활성 동의 + 최신 버전 → 갱신 불요 (이미 최신).
            // 비활성 (철회 또는 신규) → 다시 동의 (기존 동작).
            'can_grant' => ! $isRequired && (! $isConsented || $needsRenewalThisItem),
            'needs_renewal_this_item' => $needsRenewalThisItem,
            // 이슈 #430 — 상태별 단일 표시 필드. 프론트는 status 로 색만 분기하고 나머지는 그대로 렌더.
            'status' => $status,
            'status_label' => $statusLabel,
            'status_badge_label' => $statusBadgeLabel,
            'status_at_formatted' => $statusRawDate !== null
                ? $this->formatDateTimeStringForUser($statusRawDate)
                : null,
            'consented_at' => $this->formatDateTimeStringForUser($this->consented_at),
            'revoked_at' => $this->formatDateTimeStringForUser($this->revoked_at),
            'consent_count' => (int) $this->consent_count,
            'policy_version' => $this->policy_version,
            'last_source' => $this->last_source,
            ...$this->formatTimestamps(),
        ];
    }
}
