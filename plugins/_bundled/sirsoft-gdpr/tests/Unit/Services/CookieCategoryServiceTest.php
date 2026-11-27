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
     * 플러그인 설정 서비스를 목으로 바꿔, 주어진 키에는 주어진 값을 돌려주게 한다.
     *
     * @param  array<string, mixed>  $values  설정 키별로 돌려줄 값
     *
     * @return void 반환값 없음
     */
    private function mockSettings(array $values): void
    {
        $mock = $this->createMock(PluginSettingsService::class);
        $mock->method('get')->willReturnCallback(
            fn (string $id, ?string $key = null, mixed $default = null) => $values[$key] ?? $default
        );
        $this->app->instance(PluginSettingsService::class, $mock);
    }

    /**
     * 컨테이너에서 쿠키 카테고리 서비스를 새로 꺼낸다.
     *
     * @return CookieCategoryService 시험 대상 서비스
     */
    private function makeService(): CookieCategoryService
    {
        return $this->app->make(CookieCategoryService::class);
    }

    /**
     * 카탈로그에서 접속 언어의 라벨을 고른다(ko 는 한국어, en 은 영어).
     *
     * @scenario lookup=label, settings=operator_catalog
     *
     * @effects label_uses_current_locale_from_catalog
     *
     * @return void 반환값 없음
     */
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

    /**
     * cookie_ 접두사 없이 불러도 같은 카테고리 라벨을 찾는다.
     *
     * @scenario lookup=label, settings=operator_catalog
     *
     * @effects label_lookup_accepts_key_without_cookie_prefix
     *
     * @return void 반환값 없음
     */
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

    /**
     * 접속 언어 라벨이 없으면 대체 언어 라벨을 쓴다.
     *
     * @scenario lookup=label, settings=operator_catalog
     *
     * @effects label_falls_back_to_fallback_locale
     *
     * @return void 반환값 없음
     */
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

    /**
     * 접속 언어와 대체 언어 라벨이 모두 없으면 카탈로그의 첫 라벨을 쓴다.
     *
     * @scenario lookup=label, settings=operator_catalog
     *
     * @effects label_falls_back_to_first_label
     *
     * @return void 반환값 없음
     */
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

    /**
     * 카탈로그에 없는 키는 null 을 돌려준다.
     *
     * @scenario lookup=label, settings=operator_catalog
     *
     * @effects label_null_when_key_not_in_catalog
     *
     * @return void 반환값 없음
     */
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

    /**
     * 설정이 비어 있으면 기본 카테고리 4종의 라벨을 돌려준다.
     *
     * @scenario lookup=label, settings=empty
     *
     * @effects label_uses_default_categories_when_settings_empty
     *
     * @return void 반환값 없음
     */
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

    /**
     * 기본 카테고리는 4종이고 functional 은 거부할 수 있는 선택형이다.
     *
     * @scenario lookup=categories, settings=empty
     *
     * @effects default_categories_include_optional_functional
     *
     * @return void 반환값 없음
     */
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

    /**
     * 선택형 카테고리 목록은 functional·analytics·marketing 3종이고 necessary 는 빠진다.
     *
     * @scenario lookup=categories, settings=empty
     *
     * @effects optional_categories_exclude_necessary
     *
     * @return void 반환값 없음
     */
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

    /**
     * 라벨 배열이 비어 있으면 빈 글자 대신 null 을 돌려준다.
     *
     * @scenario lookup=label, settings=operator_catalog
     *
     * @effects label_null_when_label_empty
     *
     * @return void 반환값 없음
     */
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

    /**
     * 운영자 설정에 설명이 없어도 코드 기본 설명을 돌려주고, 라벨은 운영자 값을 유지한다.
     *
     * @scenario lookup=description, settings=operator_catalog
     *
     * @effects description_uses_code_default_when_setting_omits_it
     *
     * @return void 반환값 없음
     */
    public function test_get_description_uses_default_even_when_admin_setting_omits_description(): void
    {
        // 회귀 가드: 관리자 설정 화면에 설명 편집 UI 가 없어, 운영자가 라벨만 저장하면
        // cookie_categories 에 description 이 유실된다. 설명은 관리자 설정이 아니라 항상 코드
        // 기본값에서 가져와야 한다 — 아래처럼 description 이 없는 설정이 저장돼도 기본 설명이 나온다.
        $this->mockSettings([
            'cookie_categories' => json_encode([
                ['key' => 'functional', 'required' => false, 'label' => ['ko' => '기능', 'en' => 'Functional']],
            ]),
        ]);

        app()->setLocale('ko');
        $desc = $this->makeService()->getDescriptionForKey('cookie_functional');
        $this->assertNotNull($desc);
        $this->assertNotSame('', $desc);
        // 라벨은 운영자 커스텀('기능')을 유지하되, 설명은 코드 기본값에서 온다.
        $this->assertSame('기능', $this->makeService()->getLabelForKey('cookie_functional'));
    }

    /**
     * 운영자 설정에 다른 설명을 넣어도 코드 기본 설명이 이긴다.
     *
     * @scenario lookup=description, settings=operator_catalog
     *
     * @effects description_ignores_operator_custom_text
     *
     * @return void 반환값 없음
     */
    public function test_get_description_ignores_admin_custom_description(): void
    {
        // 설명은 관리자 설정이 아니라 코드 기본값 SSoT — 운영자가 설정에 다른 설명을 넣어도
        // 코드 기본 설명이 우선한다 (설명 편집 UI 부재 정합).
        $this->mockSettings([
            'cookie_categories' => json_encode([
                ['key' => 'functional', 'required' => false, 'label' => ['ko' => '기능'], 'description' => ['ko' => '운영자가 임의로 넣은 설명']],
            ]),
        ]);

        app()->setLocale('ko');
        $this->assertNotSame('운영자가 임의로 넣은 설명', $this->makeService()->getDescriptionForKey('cookie_functional'));
    }
}
