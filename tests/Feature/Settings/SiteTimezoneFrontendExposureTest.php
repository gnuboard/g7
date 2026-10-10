<?php

namespace Tests\Feature\Settings;

use App\Services\SettingsService;
use Tests\TestCase;

/**
 * 사이트 기본 시간대의 프론트엔드 노출 (7.1.0 validate q2 R1 · 원칙 「유저가 보는 화면은 유저의 시간대」)
 *
 * 관리자 목록의 빠른 기간 버튼은 「오늘」을 계정 시간대(회원 시간대 → 사이트 기본 시간대)로 만들고, 방문자 통계의
 * 기간 날짜는 사이트 표준시로 만든다. 서버도 같은 순서로 날짜를 해석하므로 화면이 사이트 기본 시간대를 알아야 한다.
 * 예전에는 `general.timezone` 이 「프론트엔드 미참조」라 내보내지 않아, 회원 시간대가 없거나 통계 화면에서는
 * 브라우저 시간대로 떨어져 서버와 하루가 어긋났다. 시간대는 민감 정보가 아니다.
 */
class SiteTimezoneFrontendExposureTest extends TestCase
{
    /**
     * 프론트엔드 일반 설정에 사이트 기본 시간대가 실린다.
     *
     * @scenario surface=frontend_site_timezone
     *
     * @effects site_timezone_exposed_to_frontend
     *
     * @return void 반환값 없음
     */
    public function test_site_timezone_is_exposed_to_frontend(): void
    {
        $service = app(SettingsService::class);
        $expected = $service->getSettings('general')['timezone'] ?? null;

        $general = $service->getFrontendSettings()['general'] ?? [];

        $this->assertArrayHasKey('timezone', $general, '사이트 기본 시간대가 프론트엔드 설정에 없습니다.');
        $this->assertSame($expected, $general['timezone']);
    }

    /**
     * 같은 일반 카테고리의 관리자 메일·사이트 주소·언어는 그대로 내보내지 않는다(비회귀).
     *
     * @scenario surface=frontend_site_timezone
     *
     * @effects site_timezone_exposed_to_frontend
     *
     * @return void 반환값 없음
     */
    public function test_other_hidden_general_fields_stay_hidden(): void
    {
        $general = app(SettingsService::class)->getFrontendSettings()['general'] ?? [];

        foreach (['site_url', 'admin_email', 'language'] as $field) {
            $this->assertArrayNotHasKey($field, $general);
        }
    }
}
