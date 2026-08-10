<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Settings;

use App\Services\LanguagePack\LanguagePackTranslator;
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
     *
     * 활성 언어팩 등록은 `LanguagePackServiceProvider` 가 부팅 시 DB 의 활성 행을 읽어
     * 수행하는데, 테스트 DB 에는 그 행이 없다. 예전에는 "ja 가 우연히 로드됐는지" 를 보고
     * 아니면 skip 했는데, 그 조건은 표준 테스트 환경에서 **항상** 참이라 이 케이스는 한 번도
     * 실행되지 않는 죽은 커버리지였다. 그래서 provider 와 동일한 방식으로 번들 언어팩 경로를
     * 직접 등록해 결정적으로 실행되게 한다.
     */
    public function test_catalog_labels_resolve_to_japanese_when_lang_pack_active(): void
    {
        $this->registerBundledJapaneseLanguagePack();

        // 전제 확인 — 등록이 실제로 먹었는지 (미등록이면 아래 단언이 무의미해진다)
        $this->assertSame(
            '大韓民国',
            __('sirsoft-ecommerce::settings.countries.KR.name', [], 'ja'),
            '번들 ja 언어팩의 settings.* 키가 등록되지 않았습니다.'
        );

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

    /**
     * 번들 ja 언어팩을 Translator 에 등록합니다.
     *
     * `LanguagePackServiceProvider::registerActivePack()` 과 같은 경로를 씁니다 —
     * `addNamespace` 는 hint 를 덮어써 모듈 자체 ko/en 등록을 깨뜨리므로,
     * namespace fallback 으로 ja 만 보완합니다.
     */
    private function registerBundledJapaneseLanguagePack(): void
    {
        $path = base_path('lang-packs/_bundled/g7-module-sirsoft-ecommerce-ja/backend/ja');

        $this->assertDirectoryExists($path, '번들 ja 언어팩 디렉토리가 없습니다.');

        $translator = $this->app->make('translator');

        if (! $translator instanceof LanguagePackTranslator) {
            $this->fail('Translator 가 LanguagePackTranslator 가 아닙니다 — 언어팩 보강 경로가 배선되지 않았습니다.');
        }

        $translator->addNamespaceFallbackPath(
            namespace: 'sirsoft-ecommerce',
            locale: 'ja',
            path: $path,
        );
    }
}
