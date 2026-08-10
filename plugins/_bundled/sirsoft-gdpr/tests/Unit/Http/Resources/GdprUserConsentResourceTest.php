<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Http\Resources;

use Illuminate\Http\Request;
use Plugins\Sirsoft\Gdpr\Http\Resources\GdprUserConsentResource;
use Plugins\Sirsoft\Gdpr\Models\GdprUserConsent;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 이슈 #430 — 마이페이지 「내 동의 현황」 각 행의 상태별 표시 필드
 * (status / status_label / status_at_formatted) 계산을 검증한다.
 *
 * 프론트 레이아웃은 status 로 색만 분기하고 표시 텍스트·날짜는 서버가 계산한 값을 그대로 렌더하므로,
 * 이 매핑이 상태별로 정확해야 화면 정보가 정확해진다.
 */
class GdprUserConsentResourceTest extends PluginTestCase
{
    /**
     * 주어진 동의 레코드를 Resource 배열로 변환합니다.
     *
     * @param GdprUserConsent $consent 대상 동의 레코드
     * @return array<string, mixed>
     */
    private function toArray(GdprUserConsent $consent): array
    {
        return (new GdprUserConsentResource($consent))->toArray(Request::create('/'));
    }

    public function test_required_category_maps_to_required_status_with_consented_date(): void
    {
        $consent = new GdprUserConsent([
            'consent_key' => 'cookie_necessary',
            'is_consented' => true,
            'is_rejected' => false,
            'consented_at' => '2026-07-14 09:00:00',
            'policy_version' => '1',
        ]);

        $result = $this->toArray($consent);

        $this->assertSame('required', $result['status']);
        // 필수는 '동의' 라벨을 granted 키로 공유.
        $this->assertSame(__('sirsoft-gdpr::messages.mypage.privacy.status.granted'), $result['status_label']);
        $this->assertNotNull($result['status_at_formatted']);
        $this->assertSame(__('sirsoft-gdpr::messages.mypage.privacy.badge.required'), $result['status_badge_label']);
    }

    public function test_consented_optional_maps_to_consented_status_with_consented_date(): void
    {
        $consent = new GdprUserConsent([
            'consent_key' => 'cookie_functional',
            'is_consented' => true,
            'is_rejected' => false,
            'consented_at' => '2026-07-14 09:00:00',
            'policy_version' => '1',
        ]);

        $result = $this->toArray($consent);

        $this->assertSame('consented', $result['status']);
        $this->assertSame(__('sirsoft-gdpr::messages.mypage.privacy.status.consented'), $result['status_label']);
        $this->assertNotNull($result['status_at_formatted']);
        $this->assertSame(__('sirsoft-gdpr::messages.mypage.privacy.badge.consented'), $result['status_badge_label']);
    }

    public function test_rejected_optional_maps_to_rejected_status_with_no_date_label(): void
    {
        // 프로젝트 결정(2026-07-20) — 날짜/라벨은 "동의한 항목만" 노출. 거부는 상태만 rejected 로 내려주고
        // status_label·status_at_formatted 는 null (프론트가 날짜 줄을 렌더하지 않음).
        $consent = new GdprUserConsent([
            'consent_key' => 'cookie_analytics',
            'is_consented' => false,
            'is_rejected' => true,
            'rejected_at' => '2026-07-10 08:00:00',
            'policy_version' => '1',
        ]);

        $result = $this->toArray($consent);

        $this->assertSame('rejected', $result['status']);
        $this->assertNull($result['status_label']);
        $this->assertNull($result['status_at_formatted']);
        $this->assertSame(__('sirsoft-gdpr::messages.mypage.privacy.badge.rejected'), $result['status_badge_label']);
    }

    public function test_revoked_optional_maps_to_revoked_status_with_revoked_date_label(): void
    {
        // 이슈 #509 16번 — 철회 이력이 있는 항목은 철회일 + '철회' 라벨을 노출한다
        // (배지는 신규 미선택과 함께 '미설정'(unset) 유지 — 배지 통합과 날짜 줄 노출은 별개).
        $consent = new GdprUserConsent([
            'consent_key' => 'cookie_marketing',
            'is_consented' => false,
            'is_rejected' => false,
            'revoked_at' => '2026-07-12 07:00:00',
            'policy_version' => '1',
        ]);

        $result = $this->toArray($consent);

        $this->assertSame('revoked', $result['status']);
        $this->assertSame(__('sirsoft-gdpr::messages.mypage.privacy.status.revoked'), $result['status_label']);
        $this->assertNotNull($result['status_at_formatted']);
        $this->assertSame(__('sirsoft-gdpr::messages.mypage.privacy.badge.unset'), $result['status_badge_label']);
    }

    public function test_new_untouched_optional_maps_to_none_with_null_label_and_date(): void
    {
        $consent = new GdprUserConsent([
            'consent_key' => 'cookie_analytics',
            'is_consented' => false,
            'is_rejected' => false,
            'policy_version' => '1',
        ]);

        $result = $this->toArray($consent);

        $this->assertSame('none', $result['status']);
        // 신규 미선택은 라벨/날짜 모두 미노출 → 프론트가 날짜 줄 자체를 렌더하지 않음.
        $this->assertNull($result['status_label']);
        $this->assertNull($result['status_at_formatted']);
        $this->assertSame(__('sirsoft-gdpr::messages.mypage.privacy.badge.unset'), $result['status_badge_label']);
    }
}
