<?php

namespace Tests\Unit\Providers;

use App\Providers\SettingsServiceProvider;
use App\Repositories\JsonConfigRepository;
use Illuminate\Support\Facades\Config;
use ReflectionMethod;
use Tests\TestCase;

/**
 * SettingsServiceProvider 앱 설정(site_name) 다국어 안전화 테스트 (공개#49).
 *
 * applyAppConfig()가 general.site_name 을 config('app.name')에 주입할 때,
 * 다국어 JSON array 면 현재/폴백 로케일 string 으로 정규화해 SPA <title>
 * ({{ config('app.name') }} → Blade e() → htmlspecialchars) TypeError 를 차단하는지 검증.
 */
class SettingsServiceProviderAppConfigTest extends TestCase
{
    /**
     * applyAppConfig 를 리플렉션으로 호출합니다.
     *
     * @param  array  $generalSettings  general 카테고리 설정 데이터
     */
    private function callApplyAppConfig(array $generalSettings): void
    {
        $configRepository = $this->createMock(JsonConfigRepository::class);
        $configRepository->method('getCategory')
            ->with('general')
            ->willReturn($generalSettings);

        $provider = new SettingsServiceProvider($this->app);
        $method = new ReflectionMethod($provider, 'applyAppConfig');
        $method->invoke($provider, $configRepository);
    }

    /**
     * 단일 문자열 site_name 은 그대로 config('app.name')에 적용됩니다.
     */
    public function test_string_site_name_applied_as_is(): void
    {
        $this->callApplyAppConfig(['site_name' => '그누보드7']);

        $this->assertSame('그누보드7', config('app.name'));
    }

    /**
     * 다국어 array site_name 은 현재 로케일 string 으로 정규화되어 적용됩니다.
     */
    public function test_multilingual_site_name_normalized_to_current_locale(): void
    {
        Config::set('app.locale', 'ko');
        Config::set('app.fallback_locale', 'en');

        $this->callApplyAppConfig([
            'site_name' => ['ko' => '한글몰', 'en' => 'EngMall'],
        ]);

        $name = config('app.name');
        $this->assertIsString($name, 'config(app.name)은 항상 string 이어야 함 (SPA title TypeError 방지)');
        $this->assertSame('한글몰', $name);
    }

    /**
     * 현재 로케일 키가 없으면 폴백 로케일 string 으로 적용됩니다.
     */
    public function test_multilingual_site_name_falls_back_to_fallback_locale(): void
    {
        Config::set('app.locale', 'ja');
        Config::set('app.fallback_locale', 'en');

        $this->callApplyAppConfig([
            'site_name' => ['en' => 'EngMall'],
        ]);

        $this->assertSame('EngMall', config('app.name'));
    }

    /**
     * 저장된 site_url 의 후행 슬래시·공백은 app.url 로 주입되기 전에 걷어낸다 (17차 G3 라운드 B-8).
     *
     * 관리자 저장 경로(SaveSettingsRequest)는 이미 정규화하지만 인스톨러는 그 경로를 우회해
     * 원값을 저장한다. 저장된 값이 모두 이 지점을 지나 app.url 이 되므로, 여기서 정규화하면
     * 저장 경로와 무관하게 app.url 이 후행 슬래시 없는 형태로 고정된다.
     */
    public function test_site_url_trailing_slash_and_whitespace_are_stripped(): void
    {
        $this->callApplyAppConfig(['site_url' => 'https://example.com/g7/']);
        $this->assertSame('https://example.com/g7', config('app.url'), '후행 슬래시가 남았다 — 인스톨러가 저장한 값이 정규화되지 않는다');

        $this->callApplyAppConfig(['site_url' => '  https://example.com/  ']);
        $this->assertSame('https://example.com', config('app.url'), '앞뒤 공백·후행 슬래시가 남았다');

        $this->callApplyAppConfig(['site_url' => 'https://example.com']);
        $this->assertSame('https://example.com', config('app.url'), '이미 정규화된 값은 그대로 둔다');
    }

    /**
     * 정규화 결과가 Blade e() (htmlspecialchars)에서 TypeError 없이 string 으로 렌더됩니다.
     */
    public function test_normalized_site_name_renders_without_type_error(): void
    {
        Config::set('app.locale', 'ko');

        $this->callApplyAppConfig([
            'site_name' => ['ko' => '한글몰', 'en' => 'EngMall'],
        ]);

        // app.blade.php 의 {{ config('app.name') }} 와 동일 경로
        $rendered = e(config('app.name', '그누보드7'));
        $this->assertSame('한글몰', $rendered);
    }
}
