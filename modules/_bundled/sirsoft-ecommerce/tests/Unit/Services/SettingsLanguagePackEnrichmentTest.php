<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Support\Facades\Lang;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 읽을 때 언어팩으로 보강한 통화·배송 국가 이름이 저장으로 새지 않는다 (코어 7.1.3 계산값 정리와 같은 유형, 1.3.2).
 *
 * 설정을 읽을 때 활성 언어팩의 번역(예: ja)으로 통화·국가 이름을 채워 준다. 관리자 화면은 그 값을 그대로 되돌려
 * 저장하므로, 보강값이 운영자 입력처럼 설정 파일에 굳었다 — 언어팩을 끄거나 번역을 고쳐도 옛 이름이 남는다.
 * 저장 때 「모듈 자체 언어(ko·en) 밖이면서 지금 번역과 같은」 값은 보강분으로 보고 빼고, 운영자가 다르게 적은 값은 남긴다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/settings-language-pack-enrichment.yaml
 */
class SettingsLanguagePackEnrichmentTest extends ModuleTestCase
{
    /**
     * ja 를 번역 가능 언어로 두고 USD·JP 의 ja 번역을 심습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.translatable_locales' => ['ko', 'en', 'ja'], 'app.supported_locales' => ['ko', 'en', 'ja']]);
        Lang::addLines(['settings.currencies.USD.name' => '米ドル', 'settings.countries.JP.name' => '日本'], 'ja', 'sirsoft-ecommerce');
        app(EcommerceSettingsService::class)->clearCache();
    }

    /**
     * 화면이 되돌려 보낸 보강값(ja 번역과 같은 이름)은 저장 파일에 남지 않고, 읽으면 다시 실시간으로 채워진다.
     *
     * @scenario field=currency_name, value=enriched
     *
     * @effects enrichment_not_persisted_on_save
     *
     * @return void 반환값 없음
     */
    public function test_enriched_currency_name_is_not_persisted(): void
    {
        $service = app(EcommerceSettingsService::class);
        $languageCurrency = $service->getSettings('language_currency');
        $usd = collect($languageCurrency['currencies'])->firstWhere('code', 'USD');
        $this->assertSame('米ドル', $usd['name']['ja'] ?? null, '읽기 보강 전제');

        $this->assertTrue($service->saveSettings(['language_currency' => $languageCurrency]));

        $saved = collect($this->savedCategory('language_currency')['currencies'])->firstWhere('code', 'USD');
        $this->assertArrayNotHasKey('ja', $saved['name'], '보강값이 저장 파일에 굳으면 안 된다');

        $service->clearCache();
        $this->assertSame('米ドル', collect($service->getSettings('language_currency')['currencies'])->firstWhere('code', 'USD')['name']['ja'] ?? null, '읽기는 여전히 실시간 보강');
    }

    /**
     * 운영자가 번역과 다르게 적은 이름은 그대로 저장한다.
     *
     * @scenario field=currency_name, value=operator_edited
     *
     * @effects operator_edited_name_kept
     *
     * @return void 반환값 없음
     */
    public function test_operator_edited_name_is_kept(): void
    {
        $service = app(EcommerceSettingsService::class);
        $languageCurrency = $service->getSettings('language_currency');
        foreach ($languageCurrency['currencies'] as $idx => $currency) {
            if ($currency['code'] === 'USD') {
                $languageCurrency['currencies'][$idx]['name']['ja'] = 'ドル(運営者)';
            }
        }

        $this->assertTrue($service->saveSettings(['language_currency' => $languageCurrency]));

        $saved = collect($this->savedCategory('language_currency')['currencies'])->firstWhere('code', 'USD');
        $this->assertSame('ドル(運営者)', $saved['name']['ja'] ?? null);
    }

    /**
     * 배송 가능 국가 이름도 같은 규칙이다.
     *
     * @scenario field=country_name, value=enriched
     *
     * @effects enrichment_not_persisted_on_save
     *
     * @return void 반환값 없음
     */
    public function test_enriched_country_name_is_not_persisted(): void
    {
        $service = app(EcommerceSettingsService::class);
        $shipping = $service->getSettings('shipping');
        $shipping['available_countries'] = array_values(array_merge(
            array_filter($shipping['available_countries'] ?? [], fn ($c) => ($c['code'] ?? '') !== 'JP'),
            [['code' => 'JP', 'name' => ['ko' => '일본', 'en' => 'Japan', 'ja' => '日本'], 'is_active' => true]],
        ));

        $this->assertTrue($service->saveSettings(['shipping' => $shipping]));

        $saved = collect($this->savedCategory('shipping')['available_countries'] ?? [])->firstWhere('code', 'JP');
        $this->assertNotNull($saved);
        $this->assertArrayNotHasKey('ja', $saved['name']);
        $this->assertSame('일본', $saved['name']['ko'], '모듈 자체 언어는 건드리지 않는다');
    }

    /**
     * 저장 파일 원문(보강 전)을 읽습니다.
     *
     * @param  string  $category  설정 카테고리
     * @return array<string, mixed> 저장값
     */
    private function savedCategory(string $category): array
    {
        $service = app(EcommerceSettingsService::class);

        return (fn () => $this->loadCategorySettings($category))->call($service);
    }
}
