<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Support\CurrencySettingsCache;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 기본 통화 플래그 단일성 테스트 (#543 — PG 청구 1/100 회귀)
 *
 * 저장본에 없는 통화를 defaults 에서 보충할 때(mergeCurrenciesByCode) 보충 항목의
 * is_default 를 그대로 들여오면 기본 통화가 2개가 된다. 그 상태에서
 * - buildCurrencySnapshot 이 `is_default ? 1.0 : exchange_rate` 로 환율을 1.0 으로 박제하고
 * - 표시 계층(HasMultiCurrencyPrices)이 앞선 is_default 통화를 기본으로 오인해
 * 화면 총액과 PG 청구액이 어긋난다 (base JPY 23,000 → 토스 230 KRW).
 *
 * is_default 의 SSoT 는 저장본의 default_currency 값이며, 보충된 통화의 is_default 는 기각한다.
 */
class EcommerceSettingsCurrencyDefaultFlagTest extends ModuleTestCase
{
    private EcommerceSettingsService $service;

    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = storage_path('framework/testing/modules/sirsoft-ecommerce/settings');

        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }

        $this->service = new EcommerceSettingsService;
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->storagePath)) {
            File::cleanDirectory($this->storagePath);
        }

        parent::tearDown();
    }

    /**
     * language_currency.json 을 직접 생성하여 저장된 통화 설정을 시뮬레이션합니다.
     */
    private function saveLanguageCurrency(array $settings): void
    {
        File::ensureDirectoryExists($this->storagePath);
        File::put(
            $this->storagePath.'/language_currency.json',
            json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * 병합 결과에서 is_default 가 true 인 통화 코드 목록을 반환합니다.
     */
    private function defaultCodes(): array
    {
        $this->service->clearCache();
        $lc = $this->service->getSettings('language_currency');

        return collect($lc['currencies'])
            ->filter(fn ($c) => ($c['is_default'] ?? false) === true)
            ->pluck('code')
            ->values()
            ->all();
    }

    /**
     * 병합 결과를 런타임과 동일하게 config 로 발행합니다.
     *
     * 실제 요청에서는 CoreServiceProvider 가 부팅 시 getAllSettings() 결과를
     * `g7_settings.modules.{id}` 에 넣고, 표시/환산 계층은 그 config 만 읽습니다.
     * 테스트는 파일을 직접 쓰므로 같은 발행 단계를 재현해야 합니다.
     */
    private function publishSettingsToConfig(): void
    {
        $this->service->clearCache();
        CurrencySettingsCache::clear();

        Config::set(
            'g7_settings.modules.sirsoft-ecommerce.language_currency',
            $this->service->getSettings('language_currency')
        );
    }

    /**
     * 운영 재현 저장본: 기본 통화를 JPY 로 바꾸고 JPY/USD 두 건만 저장한 상태.
     */
    private function saveJpyBaseSubset(): void
    {
        $this->saveLanguageCurrency([
            'default_currency' => 'JPY',
            'currencies' => [
                ['code' => 'JPY', 'name' => ['ja' => '円'], 'is_default' => true, 'decimal_places' => 0, 'base_unit' => 100, 'exchange_rate' => null],
                ['code' => 'USD', 'name' => ['en' => 'Dollar'], 'is_default' => false, 'decimal_places' => 2, 'base_unit' => 1, 'exchange_rate' => 0.85],
            ],
        ]);
    }

    // ──────────────────────────────────────────────
    // 보충 통화의 is_default 기각
    // ──────────────────────────────────────────────

    public function test_backfilled_currency_default_flag_is_rejected(): void
    {
        // defaults 의 KRW 는 is_default: true 이며 저장본에 없어 보충 대상이 된다.
        $this->saveJpyBaseSubset();

        $this->assertSame(['JPY'], $this->defaultCodes(), '보충된 통화의 is_default 가 기각되지 않아 기본 통화가 복수입니다.');
    }

    public function test_default_currency_is_unique_across_all_saved_shapes(): void
    {
        $this->saveJpyBaseSubset();

        $this->service->clearCache();
        $lc = $this->service->getSettings('language_currency');

        $flags = collect($lc['currencies'])->map(fn ($c) => (bool) ($c['is_default'] ?? false));

        $this->assertSame(1, $flags->filter()->count(), '기본 통화 플래그는 정확히 1개여야 합니다.');
        $this->assertSame('JPY', $lc['default_currency']);
    }

    public function test_default_currency_absent_from_saved_list_is_promoted_when_backfilled(): void
    {
        // 기본 통화(CNY)가 저장본 currencies 에는 없어 보충 대상이 되는 경우 —
        // 보충 항목의 플래그를 기각만 하면 기본 통화가 0개가 된다.
        $this->saveLanguageCurrency([
            'default_currency' => 'CNY',
            'currencies' => [
                ['code' => 'USD', 'name' => ['en' => 'Dollar'], 'is_default' => false, 'decimal_places' => 2, 'base_unit' => 1, 'exchange_rate' => 0.85],
            ],
        ]);

        $this->assertSame(['CNY'], $this->defaultCodes(), '보충된 기본 통화가 승격되지 않아 기본 통화가 사라졌습니다.');
    }

    public function test_nothing_saved_keeps_defaults_base_currency(): void
    {
        // 저장본 없음(신규 설치) → defaults 의 default_currency(KRW) 유지
        $this->assertSame(['KRW'], $this->defaultCodes());
    }

    // ──────────────────────────────────────────────
    // 기본 통화 해석 SSoT (표시 계층 ↔ 환산 계층 일치)
    // ──────────────────────────────────────────────

    public function test_display_layer_resolves_same_base_currency_as_conversion_layer(): void
    {
        $this->saveJpyBaseSubset();
        $this->publishSettingsToConfig();

        $resource = new class
        {
            use HasMultiCurrencyPrices {
                getDefaultCurrencyCode as public exposedDefaultCurrencyCode;
                getDefaultBaseUnit as public exposedDefaultBaseUnit;
            }
        };

        $resource::clearCurrencySettingsCache();
        $conversion = new CurrencyConversionService;

        $this->assertSame('JPY', $resource->exposedDefaultCurrencyCode(), '표시 계층이 기본 통화를 오인했습니다.');
        $this->assertSame('JPY', $conversion->getDefaultCurrency());
        $this->assertSame(100, $resource->exposedDefaultBaseUnit(), '표시 계층의 환산 분모가 기본 통화 base_unit 과 다릅니다.');
        $this->assertSame(100, $conversion->getDefaultBaseUnit());
    }

    public function test_unconfigured_exchange_rate_currency_is_not_treated_as_base(): void
    {
        // KRW 는 환율 미입력(null) 상태로 보충된다. 기본 통화로 오인되면 환율이 1.0 으로
        // 박제되어 PG 청구가 base 금액의 1/base_unit 으로 나간다(#543).
        $this->saveJpyBaseSubset();
        $this->service->clearCache();

        $lc = $this->service->getSettings('language_currency');
        $krw = collect($lc['currencies'])->firstWhere('code', 'KRW');

        $this->assertFalse((bool) ($krw['is_default'] ?? false));
        $this->assertNull($krw['exchange_rate'], '환율 미입력 통화는 null 로 남아 미지원 통화 차단에 걸려야 합니다.');
    }
}
