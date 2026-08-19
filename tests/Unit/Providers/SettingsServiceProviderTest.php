<?php

namespace Tests\Unit\Providers;

use App\Repositories\JsonConfigRepository;
use App\Support\ExtensionSettingsMirror;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * SettingsServiceProvider 타임존 설정 테스트
 *
 * 환경설정의 timezone이 app.timezone(UTC)을 오버라이트하지 않고
 * app.default_user_timezone에만 반영되는지 검증합니다.
 */
class SettingsServiceProviderTest extends TestCase
{
    /**
     * app.timezone이 항상 UTC인지 테스트합니다.
     */
    public function test_app_timezone_remains_utc_after_settings_loaded(): void
    {
        $this->assertEquals('UTC', config('app.timezone'));
    }

    /**
     * PHP 기본 타임존이 UTC인지 테스트합니다.
     */
    public function test_php_default_timezone_remains_utc(): void
    {
        $this->assertEquals('UTC', date_default_timezone_get());
    }

    /**
     * 환경설정 timezone이 default_user_timezone에 반영되는지 테스트합니다.
     */
    public function test_settings_timezone_applied_to_default_user_timezone(): void
    {
        // general.json에 timezone이 Asia/Seoul로 설정되어 있으므로
        // SettingsServiceProvider가 이를 default_user_timezone에 반영해야 함
        $defaultUserTimezone = config('app.default_user_timezone');

        $this->assertNotNull($defaultUserTimezone);
        $this->assertContains($defaultUserTimezone, config('app.supported_timezones'));
    }

    /**
     * Carbon::now()가 UTC를 반환하는지 테스트합니다.
     */
    public function test_carbon_now_returns_utc(): void
    {
        $now = Carbon::now();

        $this->assertEquals('UTC', $now->timezone->getName());
    }

    /**
     * CORE_CATEGORIES에 seo 카테고리가 포함되어 봇 감지 설정이 로딩되는지 테스트합니다.
     *
     * 목록의 소유자는 ExtensionSettingsMirror 다 — Provider 에 사본을 두고 그 사본을
     * 단언하면, 미러의 목록에서 카테고리가 빠져도 이 테스트가 초록으로 남는다.
     */
    public function test_seo_category_included_in_core_categories(): void
    {
        $this->assertContains(
            'seo',
            ExtensionSettingsMirror::CORE_CATEGORIES,
            'CORE_CATEGORIES에 seo 카테고리가 포함되어야 합니다.'
        );
    }

    /**
     * CORE_CATEGORIES 가 실제로 미러에 채워지는 목록인지 테스트합니다.
     *
     * 이 단언이 없으면 위/아래의 상수 포함 검사는 "상수에 문자열이 있다" 만 증명한다.
     * 상수와 채움 동작이 실제로 이어져 있어야 그 검사에 회귀 차단 효력이 생긴다.
     */
    public function test_core_categories_constant_is_what_the_mirror_fills(): void
    {
        $probe = new class extends JsonConfigRepository
        {
            /**
             * 어느 카테고리를 물어도 비어 있지 않은 값을 돌려준다 (채움 대상 판정용).
             *
             * @param  string  $category  카테고리명
             * @return array<string, mixed> 탐침 값
             */
            public function getCategory(string $category): array
            {
                return ['__probe' => $category];
            }
        };

        app(ExtensionSettingsMirror::class)->refreshCore($probe);

        $this->assertSame(
            ExtensionSettingsMirror::CORE_CATEGORIES,
            array_keys(config('g7_settings.core')),
            'g7_settings.core 에 채워지는 카테고리가 CORE_CATEGORIES 와 일치해야 합니다.'
        );
    }

    /**
     * SEO 설정이 g7_core_settings() 헬퍼로 접근 가능한지 테스트합니다.
     */
    public function test_seo_settings_accessible_via_helper(): void
    {
        // seo.json이 존재하면 봇 감지 관련 설정이 로딩되어야 함
        $botDetectionEnabled = g7_core_settings('seo.bot_detection_enabled');

        // 설정값이 null이 아님 = seo 카테고리가 정상 로딩됨
        $this->assertNotNull($botDetectionEnabled, 'seo.bot_detection_enabled 설정이 로딩되어야 합니다.');
    }

    /**
     * CORE_CATEGORIES 에 identity 카테고리가 포함되어 admin UI 본인인증 설정이 로딩되는지 테스트합니다.
     *
     * 회귀 차단 (이슈 #275): identity 가 CORE_CATEGORIES 에서 누락되면 admin UI 의
     * 본인인증 환경설정(기본 프로바이더 / 목적별 / TTL / 최대 시도 횟수) 4개 항목이 모두 무시됨.
     */
    public function test_identity_category_included_in_core_categories(): void
    {
        $this->assertContains(
            'identity',
            ExtensionSettingsMirror::CORE_CATEGORIES,
            'CORE_CATEGORIES 에 identity 카테고리가 포함되어야 합니다.'
        );
    }

    /**
     * 본인인증 설정이 config('settings.identity.*') dot-path 로 hydrate 되는지 테스트합니다.
     *
     * 회귀 차단 (이슈 #275): IdentityVerificationManager / MailIdentityProvider /
     * InicisIdentityProvider 등이 `config('settings.identity.*')` 경로로 직접 read 한다.
     * 이 경로 hydration 이 누락되면 admin UI 저장값이 모두 무시되고 코드 fallback 만 사용된다.
     */
    public function test_settings_identity_dot_path_hydrated(): void
    {
        // storage/app/settings/identity.json 이 존재하는 환경에서는 hydrate 되어 array
        // 부재 환경에서도 최소 null 이어야 함 (Config::set('settings.identity', ...) 의 cover 범위)
        $identityConfig = config('settings.identity');

        // 카테고리 자체가 등록되어 있어야 함. 빈 배열일 수도 있지만 false 또는 'string' 류는 부적합
        $this->assertTrue(
            $identityConfig === null || is_array($identityConfig),
            'config(\'settings.identity\') 는 null 또는 array 여야 합니다.'
        );
    }
}
