<?php

namespace Tests\Unit\Services;

use App\Services\SettingsService;
use Tests\TestCase;

/**
 * 사이트 기본 언어(app.site_locale)의 보관·노출 테스트
 *
 * `app()->setLocale()` 은 `app.locale` 을 요청 언어로 덮으므로, 다국어 필수 판정·관리자 입력 부품이
 * 「누가 보든 같은 언어」를 쓰려면 부팅 때 정해진 사이트 기본 언어를 따로 읽어야 한다.
 */
class SettingsServiceSiteLocaleTest extends TestCase
{
    /**
     * 요청 언어를 바꿔도 사이트 기본 언어는 그대로다
     *
     * @scenario case=appconfig
     *
     * @effects site_locale_kept_and_exposed_to_frontend
     *
     * @return void 반환값 없음
     */
    public function test_site_locale_is_not_overwritten_by_request_locale(): void
    {
        config(['app.site_locale' => 'ko']);

        app()->setLocale('en');

        $this->assertSame('en', config('app.locale'), 'setLocale 은 app.locale 을 요청 언어로 덮는다(전제).');
        $this->assertSame('ko', config('app.site_locale'));
    }

    /**
     * 프론트 appConfig 에 사이트 기본 언어가 실린다
     *
     * @scenario case=appconfig
     *
     * @effects site_locale_kept_and_exposed_to_frontend
     *
     * @return void 반환값 없음
     */
    public function test_app_config_exposes_site_locale(): void
    {
        config(['app.site_locale' => 'ja']);
        app()->setLocale('en');

        $result = app(SettingsService::class)->getAppConfigForFrontend();

        $this->assertArrayHasKey('siteLocale', $result);
        $this->assertSame('ja', $result['siteLocale']);
    }
}
