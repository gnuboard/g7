<?php

namespace Plugins\Sirsoft\Gdpr\Tests\Unit\Services;

use App\Services\PluginSettingsService;
use Plugins\Sirsoft\Gdpr\Services\CookieCategoryService;
use Plugins\Sirsoft\Gdpr\Tests\PluginTestCase;

/**
 * 쿠키 카테고리 카탈로그 서비스 Unit 테스트.
 *
 * `getLabelForKey()` 는 마이페이지 「내 동의 현황」 에서 raw consent_key 대신
 * 사람 친화 라벨을 표시하기 위해 추가됨. 카탈로그 라벨 변환 로직 회귀 가드.
 */
class CookieCategoryServiceTest extends PluginTestCase
{
    /**
     * @param array<string, mixed> $values
     * @return void
     */
    private function mockSettings(array $values): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturnCallback(
            fn (string $id, ?string $key = null, mixed $default = null) => $values[$key] ?? $default
        );
        $this->app->instance(PluginSettingsService::class, $mock);
    }

    private function makeService(): CookieCategoryService
    {
        return $this->app->make(CookieCategoryService::class);
    }

    public function test_get_label_for_key_returns_locale_label_from_catalog(): void
    {
        $this->mockSettings([
            'cookie_categories' => json_encode([
                ['key' => 'analytics', 'required' => false, 'label' => ['ko' => '분석 쿠키', 'en' => 'Analytics']],
            ]),
        ]);

        app()->setLocale('ko');
        $this->assertSame('분석 쿠키', $this->makeService()->getLabelForKey('cookie_analytics'));

        app()->setLocale('en');
        $this->assertSame('Analytics', $this->makeService()->getLabelForKey('cookie_analytics'));
    }

    public function test_get_label_for_key_handles_key_without_cookie_prefix(): void
    {
        // 회귀 가드: cookie_ 접두사 없이 호출해도 카탈로그 매칭됨.
        $this->mockSettings([
            'cookie_categories' => json_encode([
                ['key' => 'marketing', 'required' => false, 'label' => ['ko' => '마케팅 쿠키']],
            ]),
        ]);

        app()->setLocale('ko');
        $this->assertSame('마케팅 쿠키', $this->makeService()->getLabelForKey('marketing'));
    }

    public function test_get_label_for_key_falls_back_to_fallback_locale(): void
    {
        // 회귀 가드: 현재 locale 의 라벨이 없으면 fallback_locale 라벨 사용.
        $this->mockSettings([
            'cookie_categories' => json_encode([
                ['key' => 'analytics', 'required' => false, 'label' => ['en' => 'Analytics']],
            ]),
        ]);

        app()->setLocale('ko');
        config(['app.fallback_locale' => 'en']);

        $this->assertSame('Analytics', $this->makeService()->getLabelForKey('cookie_analytics'));
    }

    public function test_get_label_for_key_returns_first_label_when_locale_and_fallback_missing(): void
    {
        // 회귀 가드: locale/fallback 모두 없으면 카탈로그에 등록된 첫 라벨 사용 (UX 상 raw key 보다 무엇이든 표기).
        $this->mockSettings([
            'cookie_categories' => json_encode([
                ['key' => 'custom', 'required' => false, 'label' => ['ja' => 'カスタム']],
            ]),
        ]);

        app()->setLocale('ko');
        config(['app.fallback_locale' => 'en']);

        $this->assertSame('カスタム', $this->makeService()->getLabelForKey('cookie_custom'));
    }

    public function test_get_label_for_key_returns_null_when_key_not_in_catalog(): void
    {
        // 회귀 가드: 카탈로그 매칭 실패 시 null 반환 → 호출 측이 raw key fallback.
        $this->mockSettings([
            'cookie_categories' => json_encode([
                ['key' => 'analytics', 'required' => false, 'label' => ['ko' => '분석 쿠키']],
            ]),
        ]);

        app()->setLocale('ko');
        $this->assertNull($this->makeService()->getLabelForKey('cookie_unknown_xyz'));
    }

    public function test_get_label_for_key_uses_default_categories_when_settings_empty(): void
    {
        // 회귀 가드: 카탈로그 미설정 시 getDefaultCategories() 폴백 — 기본 4종 모두 라벨 반환.
        // Phase 1: functional 카테고리 추가 (ICO/CNIL 4분류 체계 부합).
        $this->mockSettings([]);

        app()->setLocale('ko');
        $this->assertSame('필수 쿠키', $this->makeService()->getLabelForKey('cookie_necessary'));
        $this->assertSame('기능 쿠키', $this->makeService()->getLabelForKey('cookie_functional'));
        $this->assertSame('분석 쿠키', $this->makeService()->getLabelForKey('cookie_analytics'));
        $this->assertSame('마케팅 쿠키', $this->makeService()->getLabelForKey('cookie_marketing'));
    }

    public function test_default_categories_include_functional_with_required_false(): void
    {
        // Phase 1 회귀 가드: getDefaultCategories() 폴백이 functional 카테고리 포함 + required=false.
        // ePrivacy Art.5(3) 가이드: functional 은 사용자 거부 가능 카테고리.
        $this->mockSettings([]);

        $service = $this->makeService();
        $categories = $service->getCategories();

        $this->assertCount(4, $categories, '기본 카테고리는 4건 (necessary/functional/analytics/marketing)');

        $functional = collect($categories)->firstWhere('key', 'functional');
        $this->assertNotNull($functional, 'functional 카테고리 존재');
        $this->assertFalse($functional['required'], 'functional 은 거부 가능 (required=false)');

        // isRequired() 도 functional 은 false 반환
        $this->assertFalse($service->isRequired('cookie_functional'));
    }

    public function test_get_optional_categories_includes_functional(): void
    {
        // Phase 1 회귀 가드: getOptionalCategories() 가 functional/analytics/marketing 3건 반환 (necessary 제외).
        $this->mockSettings([]);

        $optional = $this->makeService()->getOptionalCategories();
        $optionalKeys = array_column($optional, 'key');

        $this->assertCount(3, $optional);
        $this->assertContains('functional', $optionalKeys);
        $this->assertContains('analytics', $optionalKeys);
        $this->assertContains('marketing', $optionalKeys);
        $this->assertNotContains('necessary', $optionalKeys);
    }

    public function test_get_label_for_key_returns_null_for_empty_label(): void
    {
        // 회귀 가드: label 배열이 비어 있으면 null (사용자 화면에 빈 문자열 노출 방지).
        $this->mockSettings([
            'cookie_categories' => json_encode([
                ['key' => 'analytics', 'required' => false, 'label' => []],
            ]),
        ]);

        app()->setLocale('ko');
        $this->assertNull($this->makeService()->getLabelForKey('cookie_analytics'));
    }
}
