<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\ShippingCountryResolver;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 배송 국가 이름의 대체 언어 테스트 (#148 D7).
 *
 * 국가 이름에 지금 언어 값이 없으면 요청 언어 → 사이트 기본 언어 → 영어 순으로 대체하고, 그래도 없으면
 * 종전처럼 국가 코드를 보인다(체인 밖 아무 언어 값으로 채우지 않는다 — 알림의 국가 이름과 같은 규칙).
 */
class ShippingCountryNameSiteLocaleTest extends ModuleTestCase
{
    /**
     * 요청 언어 값이 없으면 사이트 기본 언어(en) 이름을 쓴다.
     *
     * @scenario shape=three_letter
     *
     * @effects multilingual_value_falls_back_to_site_locale_then_en
     *
     * @return void 반환값 없음
     */
    public function test_country_name_falls_back_to_site_locale(): void
    {
        config(['app.site_locale' => 'en', 'app.fallback_locale' => 'ko']);
        app()->setLocale('fil');
        $this->setCountries([['code' => 'PH', 'name' => ['ko' => '필리핀', 'en' => 'Philippines'], 'is_active' => true]]);

        $this->assertSame('Philippines', app(ShippingCountryResolver::class)->countryName('PH'));
    }

    /**
     * 체인에 없는 언어 값만 있으면 국가 코드를 보인다(종전 꼬리 보존).
     *
     * @scenario shape=region_tagged
     *
     * @effects multilingual_value_falls_back_to_site_locale_then_en
     *
     * @return void 반환값 없음
     */
    public function test_country_name_keeps_code_tail(): void
    {
        config(['app.site_locale' => 'ko', 'app.fallback_locale' => 'ko']);
        app()->setLocale('fil-PH');
        $this->setCountries([['code' => 'PH', 'name' => ['ja' => 'フィリピン'], 'is_active' => true]]);

        $this->assertSame('PH', app(ShippingCountryResolver::class)->countryName('PH'));
    }

    /**
     * 배송 가능 국가 설정을 둡니다.
     *
     * @param  array<int, array<string, mixed>>  $countries  국가 목록
     * @return void 반환값 없음
     */
    private function setCountries(array $countries): void
    {
        app(EcommerceSettingsService::class)->setSetting('shipping.available_countries', $countries);
    }
}
