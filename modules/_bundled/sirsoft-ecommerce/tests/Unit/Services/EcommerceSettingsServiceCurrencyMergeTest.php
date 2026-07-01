<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Tests\TestCase;

/**
 * 통화 환율 영속성 테스트 (U11-A)
 *
 * language_currency.currencies 는 정수키 리스트라 array_merge 가 통째 교체되어
 * 관리자가 일부 통화를 빼고 저장하면 defaults 통화가 영구 소실되던 영속성 공백을 검증한다.
 * getAllSettings() 의 code 기준 병합(mergeCurrenciesByCode)으로 누락 통화 보충(환율 저장본 우선).
 */
class EcommerceSettingsServiceCurrencyMergeTest extends TestCase
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
     * 저장된 currencies 의 code 목록을 추출합니다.
     */
    private function currencyCodes(array $currencies): array
    {
        return array_map(fn ($c) => $c['code'] ?? null, $currencies);
    }

    // ──────────────────────────────────────────────
    // 누락 통화 보충 (핵심 — array_merge 통째교체 회귀)
    // ──────────────────────────────────────────────

    public function test_missing_jpy_is_supplemented_from_defaults(): void
    {
        // 관리자가 JPY 를 빼고 저장 (KRW/USD 만)
        $this->saveLanguageCurrency([
            'default_currency' => 'KRW',
            'currencies' => [
                ['code' => 'KRW', 'name' => ['ko' => 'KRW (원)', 'en' => 'KRW (Won)'], 'exchange_rate' => null, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0, 'is_default' => true],
                ['code' => 'USD', 'name' => ['ko' => 'USD (달러)', 'en' => 'USD (Dollar)'], 'exchange_rate' => 0.85, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2, 'is_default' => false],
            ],
        ]);

        $this->service->clearCache();
        $lc = $this->service->getSettings('language_currency');
        $codes = $this->currencyCodes($lc['currencies']);

        // JPY 가 defaults 에서 보충되어야 한다 (array_merge 통째교체로 소실되면 실패)
        $this->assertContains('JPY', $codes, 'JPY 가 defaults 에서 보충되지 않았습니다 (array_merge 통째교체 회귀).');
        $this->assertContains('KRW', $codes);
        $this->assertContains('USD', $codes);
    }

    public function test_saved_exchange_rate_is_preserved_over_defaults(): void
    {
        // USD 환율을 defaults(0.85) 와 다른 값(1.23)으로 저장
        $this->saveLanguageCurrency([
            'default_currency' => 'KRW',
            'currencies' => [
                ['code' => 'KRW', 'name' => ['ko' => 'KRW', 'en' => 'KRW'], 'exchange_rate' => null, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0, 'is_default' => true],
                ['code' => 'USD', 'name' => ['ko' => 'USD', 'en' => 'USD'], 'exchange_rate' => 1.23, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2, 'is_default' => false],
            ],
        ]);

        $this->service->clearCache();
        $lc = $this->service->getSettings('language_currency');
        $usd = collect($lc['currencies'])->firstWhere('code', 'USD');

        $this->assertEquals(1.23, $usd['exchange_rate'], '저장본 환율이 defaults 로 덮어써졌습니다.');
    }

    public function test_new_added_currency_is_preserved(): void
    {
        // defaults 에 없는 신규 통화(GBP) 추가 저장
        $this->saveLanguageCurrency([
            'default_currency' => 'KRW',
            'currencies' => [
                ['code' => 'KRW', 'name' => ['ko' => 'KRW', 'en' => 'KRW'], 'exchange_rate' => null, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0, 'is_default' => true],
                ['code' => 'GBP', 'name' => ['ko' => 'GBP (파운드)', 'en' => 'GBP (Pound)'], 'exchange_rate' => 0.66, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2, 'is_default' => false],
            ],
        ]);

        $this->service->clearCache();
        $lc = $this->service->getSettings('language_currency');
        $codes = $this->currencyCodes($lc['currencies']);

        $this->assertContains('GBP', $codes, '저장본에만 있는 신규 통화가 보존되지 않았습니다.');
        // defaults 통화도 함께 보충되어야 한다
        $this->assertContains('JPY', $codes);
    }

    public function test_default_currency_exchange_rate_stays_null(): void
    {
        $this->saveLanguageCurrency([
            'default_currency' => 'KRW',
            'currencies' => [
                ['code' => 'KRW', 'name' => ['ko' => 'KRW', 'en' => 'KRW'], 'exchange_rate' => null, 'rounding_unit' => '1', 'rounding_method' => 'floor', 'decimal_places' => 0, 'is_default' => true],
            ],
        ]);

        $this->service->clearCache();
        $lc = $this->service->getSettings('language_currency');
        $krw = collect($lc['currencies'])->firstWhere('code', 'KRW');

        $this->assertNull($krw['exchange_rate'], '기본통화 환율은 null 이어야 합니다.');
        $this->assertTrue($krw['is_default']);
    }

    public function test_all_five_defaults_present_when_nothing_saved(): void
    {
        // 저장본 없음 → defaults 5종 전부
        $this->service->clearCache();
        $lc = $this->service->getSettings('language_currency');
        $codes = $this->currencyCodes($lc['currencies']);

        foreach (['KRW', 'USD', 'JPY', 'CNY', 'EUR'] as $code) {
            $this->assertContains($code, $codes);
        }
    }

    // ──────────────────────────────────────────────
    // symbol/flag 보강 (A1 — D-CUR-4)
    // ──────────────────────────────────────────────

    public function test_currencies_carry_symbol_and_flag_meta(): void
    {
        $this->service->clearCache();
        $lc = $this->service->getSettings('language_currency');

        $krw = collect($lc['currencies'])->firstWhere('code', 'KRW');
        $usd = collect($lc['currencies'])->firstWhere('code', 'USD');
        $jpy = collect($lc['currencies'])->firstWhere('code', 'JPY');

        // 표준 매핑 헬퍼로 보강된 symbol/flag (셀렉터가 currency.symbol/currency.flag 참조)
        $this->assertSame('₩', $krw['symbol']);
        $this->assertSame('🇰🇷', $krw['flag']);
        $this->assertSame('$', $usd['symbol']);
        $this->assertSame('¥', $jpy['symbol']);
    }

    // ──────────────────────────────────────────────
    // base_unit 보충 (MP08-3 방향 B)
    // ──────────────────────────────────────────────

    public function test_base_unit_is_backfilled_from_defaults_when_missing(): void
    {
        // 기존 저장본(base_unit 미저장) → defaults 값으로 보충
        $this->saveLanguageCurrency([
            'default_currency' => 'KRW',
            'currencies' => [
                ['code' => 'KRW', 'name' => ['ko' => 'KRW', 'en' => 'KRW'], 'exchange_rate' => null, 'is_default' => true],
                ['code' => 'JPY', 'name' => ['ko' => 'JPY', 'en' => 'JPY'], 'exchange_rate' => 115, 'is_default' => false],
                ['code' => 'USD', 'name' => ['ko' => 'USD', 'en' => 'USD'], 'exchange_rate' => 0.85, 'is_default' => false],
            ],
        ]);

        $this->service->clearCache();
        $lc = $this->service->getSettings('language_currency');

        $krw = collect($lc['currencies'])->firstWhere('code', 'KRW');
        $jpy = collect($lc['currencies'])->firstWhere('code', 'JPY');
        $usd = collect($lc['currencies'])->firstWhere('code', 'USD');

        // defaults: KRW=1000, JPY=100, USD=1
        $this->assertSame(1000, $krw['base_unit']);
        $this->assertSame(100, $jpy['base_unit']);
        $this->assertSame(1, $usd['base_unit']);
    }

    public function test_saved_base_unit_is_preserved_over_defaults(): void
    {
        // 관리자가 base_unit 을 직접 저장 → 보존 (defaults 로 덮어쓰지 않음)
        $this->saveLanguageCurrency([
            'default_currency' => 'KRW',
            'currencies' => [
                ['code' => 'KRW', 'name' => ['ko' => 'KRW', 'en' => 'KRW'], 'exchange_rate' => null, 'base_unit' => 1, 'is_default' => true],
            ],
        ]);

        $this->service->clearCache();
        $lc = $this->service->getSettings('language_currency');
        $krw = collect($lc['currencies'])->firstWhere('code', 'KRW');

        $this->assertSame(1, $krw['base_unit'], '저장본 base_unit 이 defaults 로 덮어써졌습니다.');
    }

    public function test_unknown_currency_base_unit_falls_back_to_one(): void
    {
        // defaults 에 없는 통화(GBP) → 폴백 1 (소액통화 아님)
        $this->saveLanguageCurrency([
            'default_currency' => 'KRW',
            'currencies' => [
                ['code' => 'KRW', 'name' => ['ko' => 'KRW', 'en' => 'KRW'], 'exchange_rate' => null, 'is_default' => true],
                ['code' => 'GBP', 'name' => ['ko' => 'GBP', 'en' => 'GBP'], 'exchange_rate' => 0.66, 'is_default' => false],
            ],
        ]);

        $this->service->clearCache();
        $lc = $this->service->getSettings('language_currency');
        $gbp = collect($lc['currencies'])->firstWhere('code', 'GBP');

        $this->assertSame(1, $gbp['base_unit']);
    }

    public function test_unknown_currency_symbol_falls_back_to_code(): void
    {
        // defaults 에 없는 통화(GBP) 저장 → symbol 폴백 = code
        $this->saveLanguageCurrency([
            'default_currency' => 'KRW',
            'currencies' => [
                ['code' => 'KRW', 'name' => ['ko' => 'KRW', 'en' => 'KRW'], 'exchange_rate' => null, 'is_default' => true],
                ['code' => 'XYZ', 'name' => ['ko' => 'XYZ', 'en' => 'XYZ'], 'exchange_rate' => 1.0, 'is_default' => false],
            ],
        ]);

        $this->service->clearCache();
        $lc = $this->service->getSettings('language_currency');
        $xyz = collect($lc['currencies'])->firstWhere('code', 'XYZ');

        $this->assertSame('XYZ', $xyz['symbol']); // 미정의 → code 폴백
        $this->assertSame('', $xyz['flag']);      // 미정의 → 빈 문자열
    }
}
