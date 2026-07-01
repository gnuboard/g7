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
