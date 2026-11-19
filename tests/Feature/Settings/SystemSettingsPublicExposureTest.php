<?php

namespace Tests\Feature\Settings;

use App\Services\SettingsService;
use Tests\TestCase;

/**
 * 실행 방식·웹 크론·신뢰 프록시 설정은 공개 설정 응답에 실리지 않는다.
 *
 * 공개 설정(`/api/frontend-config`, 셸의 `window.G7Config.settings`)은 비로그인 방문자에게도 나간다.
 * `system` 카테고리(실행 방식·크론 방식·크론 허용 IP·방문 트리거 폴백)와 `debug.trusted_proxies` 는
 * 공개 화면이 참조하지 않는데 노출 표식이 켜져 있어 그대로 나갔다. 관리자 환경설정 화면은 관리자 설정
 * API 로 이 값들을 읽으므로 공개 응답에서 빠져도 영향이 없다.
 */
class SystemSettingsPublicExposureTest extends TestCase
{
    /**
     * 공개 설정 묶음에 시스템 카테고리와 신뢰 프록시 목록이 없다.
     *
     * @scenario ip_allow_list=match
     *
     * @effects system_and_proxy_settings_stay_out_of_public_frontend_config
     *
     * @return void 반환값 없음
     */
    public function test_frontend_settings_omit_system_and_trusted_proxies(): void
    {
        $settings = app(SettingsService::class)->getFrontendSettings();

        $this->assertArrayNotHasKey('system', $settings, '실행 방식·크론 허용 IP 가 공개 설정에 실렸다');
        $this->assertArrayNotHasKey('trusted_proxies', $settings['advanced'] ?? [], '신뢰 프록시 목록이 공개 설정에 실렸다');
        $this->assertArrayNotHasKey('outbound_proxy_bypass', $settings['advanced'] ?? [], '형제 키(비노출)와 같은 처리여야 한다');
    }

    /**
     * 비로그인 공개 설정 API 응답 본문에 그 키들이 없다.
     *
     * @scenario ip_allow_list=match
     *
     * @effects system_and_proxy_settings_stay_out_of_public_frontend_config
     *
     * @return void 반환값 없음
     */
    public function test_public_frontend_config_endpoint_omits_them(): void
    {
        $body = (string) $this->getJson('/api/frontend-config')->assertOk()->getContent();

        foreach (['cron_allowed_ips', 'cron_visit_fallback', 'cron_mode', 'execution_mode', 'trusted_proxies'] as $key) {
            $this->assertStringNotContainsString('"'.$key.'"', $body, $key.' 가 비로그인 공개 설정 응답에 실렸다');
        }
    }
}
