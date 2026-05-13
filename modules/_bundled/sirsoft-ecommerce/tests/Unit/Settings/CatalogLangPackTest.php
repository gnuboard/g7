<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Settings;

use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 이커머스 환경설정 카탈로그 라벨 lang pack 통합 검증.
 *
 * 검증 시나리오:
 *   1. lang/{ko,en}/settings.php 가 sirsoft-ecommerce 네임스페이스로 등록됨
 *   2. 표시 시점에 `localized_label(value: $catalog['name'], fallbackKey: 'sirsoft-ecommerce::settings.countries.KR.name')` 가
 *      활성 locale 라벨로 해석됨
 *   3. ja 활성 시 lang pack ja 가 보강한 키가 우선 적용 (lang pack 미설치 시 ko fallback)
 */
class CatalogLangPackTest extends ModuleTestCase
{
    public function test_korean_country_labels_load_from_module_lang(): void
    {
        app()->setLocale('ko');
        $this->assertSame('대한민국', __('sirsoft-ecommerce::settings.countries.KR.name'));
        $this->assertSame('일본', __('sirsoft-ecommerce::settings.countries.JP.name'));
    }

    public function test_english_country_labels_load_from_module_lang(): void
    {
        app()->setLocale('en');
        $this->assertSame('South Korea', __('sirsoft-ecommerce::settings.countries.KR.name'));
        $this->assertSame('Japan', __('sirsoft-ecommerce::settings.countries.JP.name'));
    }

    public function test_currency_labels_load_from_module_lang(): void
    {
        app()->setLocale('ko');
        $this->assertSame('KRW (원)', __('sirsoft-ecommerce::settings.currencies.KRW.name'));
        app()->setLocale('en');
        $this->assertSame('KRW (Won)', __('sirsoft-ecommerce::settings.currencies.KRW.name'));
    }

    public function test_payment_method_labels_load_from_module_lang(): void
    {
        app()->setLocale('ko');
        $this->assertSame('신용카드', __('sirsoft-ecommerce::settings.payment_methods.card.name'));
        $this->assertSame('신용카드로 안전하게 결제', __('sirsoft-ecommerce::settings.payment_methods.card.description'));
    }

    public function test_localized_label_with_settings_fallback_key(): void
    {
        app()->setLocale('ja');

        // settings JSON entry: ko/en 만 보유, ja 없음
        $entry = ['code' => 'KR', 'name' => ['ko' => '대한민국', 'en' => 'South Korea']];

        // ja 활성 + lang pack ja 미보유 → fallbackKey __() 가 ko/en 키 가진 lang 파일에서 해석
        // (ja lang pack 활성 시에는 ja 라벨 우선 — 통합 환경 시나리오)
        $resolved = localized_label(
            value: $entry['name'],
            fallbackKey: 'sirsoft-ecommerce::settings.countries.KR.name',
        );

        // ja 키 부재 → fallbackKey __() 결과 ko 라인 (Laravel fallback_locale=ko)
        $this->assertSame('대한민국', $resolved);
    }

    public function test_localized_label_prefers_user_edited_active_locale(): void
    {
        app()->setLocale('ja');

        // 운영자가 ja 직접 편집한 경우
        $entry = ['code' => 'KR', 'name' => ['ko' => '대한민국', 'en' => 'South Korea', 'ja' => '운영자 편집']];

        $resolved = localized_label(
            value: $entry['name'],
            fallbackKey: 'sirsoft-ecommerce::settings.countries.KR.name',
        );

        // value[ja] 가 fallbackKey 보다 우선
        $this->assertSame('운영자 편집', $resolved);
    }

    /**
     * ja lang pack 활성 환경 통합 — 카탈로그 라벨이 일본어로 보강되는지 end-to-end 검증.
     * lang pack 미설치 환경에서는 skip.
     */
    public function test_catalog_labels_resolve_to_japanese_when_lang_pack_active(): void
    {
        $jaCountry = __('sirsoft-ecommerce::settings.countries.KR.name', [], 'ja');
        $koCountry = __('sirsoft-ecommerce::settings.countries.KR.name', [], 'ko');

        if ($jaCountry === 'sirsoft-ecommerce::settings.countries.KR.name' || $jaCountry === $koCountry) {
            $this->markTestSkipped('g7-module-sirsoft-ecommerce-ja lang pack 의 settings.* 키 미로드');
        }

        app()->setLocale('ja');

        // settings JSON entry: ko/en 만 보유 (실제 settings/defaults.json 기본값)
        $countryEntry = ['code' => 'KR', 'name' => ['ko' => '대한민국', 'en' => 'South Korea']];
        $currencyEntry = ['code' => 'KRW', 'name' => ['ko' => 'KRW (원)', 'en' => 'KRW (Won)']];
        $methodEntry = ['id' => 'card', '_cached_name' => ['ko' => '신용카드', 'en' => 'Credit Card']];

        $this->assertNotSame('대한민국', localized_label(
            value: $countryEntry['name'],
            fallbackKey: "sirsoft-ecommerce::settings.countries.{$countryEntry['code']}.name",
        ), 'ja 활성 + lang pack 보강 시 ko 그대로 노출되면 회귀');

        $this->assertNotSame('KRW (원)', localized_label(
            value: $currencyEntry['name'],
            fallbackKey: "sirsoft-ecommerce::settings.currencies.{$currencyEntry['code']}.name",
        ));

        $this->assertNotSame('신용카드', localized_label(
            value: $methodEntry['_cached_name'],
            fallbackKey: "sirsoft-ecommerce::settings.payment_methods.{$methodEntry['id']}.name",
        ));
    }
}
