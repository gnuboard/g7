<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Illuminate\Support\Facades\File;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

class EcommerceSettingsServiceApplyExchangeRatesTest extends ModuleTestCase
{
    /**
     * 개발 DB 재생성 없이 트랜잭션으로 테스트를 격리합니다.
     *
     * @return void 반환값 없음
     */
    public function refreshDatabase(): void
    {
        $this->beginDatabaseTransaction();
    }

    /**
     * 환율 갱신이 기존 주문 스냅샷과 결제 금액 및 마일리지 규칙을 바꾸지 않는지 검증합니다.
     *
     * @effects historical_order_charge_immutable, mileage_rules_preserved
     *
     * @return void 반환값 없음
     */
    public function test_rate_updates_preserve_order_snapshot_charge_and_mileage_rules(): void
    {
        $service = app(EcommerceSettingsService::class);
        $currency = $service->getSettings('language_currency');
        $currency['rate_provider'] = 'fixture';
        $service->saveSettings(['language_currency' => $currency]);
        $conversion = app(CurrencyConversionService::class);
        $snapshot = ['base_currency' => 'KRW', 'base_unit' => 1000, 'order_currency' => 'USD',
            'exchange_rates' => ['USD' => ['rate' => 0.7, 'rounding_unit' => '0.01', 'rounding_method' => 'round', 'decimal_places' => 2]]];
        $beforeCharge = $conversion->resolveSnapshotPaymentCharge(10000, $snapshot);
        $beforeMileage = $service->getSettings('mileage');
        $this->assertContains('USD', $service->applyExchangeRates(['USD' => 0.812345], 'fixture')['applied']);
        $this->assertSame($beforeCharge, $conversion->resolveSnapshotPaymentCharge(10000, $snapshot));
        $this->assertSame($beforeMileage, $service->getSettings('mileage'));
    }

    /**
     * 선택한 제공자가 전체 통화 목록의 설정을 잃지 않고 환율을 적용하는지 검증합니다.
     *
     * @effects selected_provider_applies_full_list_without_losing_configuration
     *
     * @return void 반환값 없음
     */
    public function test_selected_provider_applies_full_list_without_losing_configuration(): void
    {
        $service = app(EcommerceSettingsService::class);
        $current = $service->getSettings('language_currency');
        $current['rate_provider'] = 'fixture';
        $current['default_currency'] = 'USD';
        $service->saveSettings(['language_currency' => $current]);
        $before = $service->getSettings('language_currency');
        $calls = [];
        HookManager::addAction('sirsoft-ecommerce.currency.after_apply_rates', function ($result, $source) use (&$calls) {
            $calls[] = $source;
        });
        $result = $service->applyExchangeRates(['KRW' => 1380, 'USD' => 1, 'JPY' => 145.108, 'EUR' => 0], 'fixture');
        $after = $service->getSettings('language_currency');
        $this->assertSame('USD', $after['default_currency']);
        $this->assertEqualsCanonicalizing(array_column($before['currencies'], 'code'), array_column($after['currencies'], 'code'));
        $this->assertContains('KRW', $result['applied']);
        $this->assertContains('USD', $result['skipped']);
        $this->assertSame(['fixture'], $calls);
        $this->assertNull(collect($after['currencies'])->firstWhere('code', 'USD')['exchange_rate']);
        // 0 이하·비수치 환율은 적용하지 않고 기존 값을 유지한다 — 환율 0 은 그 통화를 사용자 셀렉터에서
        // 사라지게 하고 회원 선호 통화 저장을 422 로 만든다(계획 §7.2 「0 이하 invalid」·부수의무 #1).
        $this->assertContains('EUR', $result['skipped']);
        $this->assertNotContains('EUR', $result['applied']);
        $this->assertSame(
            collect($before['currencies'])->firstWhere('code', 'EUR')['exchange_rate'],
            collect($after['currencies'])->firstWhere('code', 'EUR')['exchange_rate']
        );
        $negative = $service->applyExchangeRates(['EUR' => -1, 'JPY' => 'abc'], 'fixture');
        $this->assertEqualsCanonicalizing(['EUR', 'JPY'], $negative['skipped']);
        $this->assertSame([], $negative['applied']);
        $this->assertSame(['KRW'], $service->applyExchangeRates(['KRW' => '1380'], 'fixture')['unchanged']);
    }

    /**
     * 다른 제공자의 쓰기를 거부하고 삭제한 기본 제공 통화가 부활하지 않는지 검증합니다.
     *
     * @effects foreign_source_cannot_write_and_deleted_default_stays_deleted
     *
     * @return void 반환값 없음
     */
    public function test_foreign_source_cannot_write_and_deleted_default_stays_deleted(): void
    {
        $service = app(EcommerceSettingsService::class);
        $current = $service->getSettings('language_currency');
        $current['rate_provider'] = 'fixture';
        $current['currencies'] = array_values(array_filter($current['currencies'], fn ($row) => $row['code'] !== 'JPY'));
        $service->saveSettings(['language_currency' => $current]);
        $before = $this->savedRaw();
        $this->assertSame([], $service->applyExchangeRates(['USD' => 12], 'other')['applied']);
        $this->assertSame($before, $this->savedRaw());
        $service->applyExchangeRates(['USD' => 0.72], 'fixture');
        $this->assertNotContains('JPY', array_column($service->getSettings('language_currency')['currencies'], 'code'));
    }

    /**
     * 저장된 language_currency.json 원문을 그대로 읽습니다.
     *
     * @return string 저장 파일 원문 (파일이 없으면 빈 문자열)
     */
    private function savedRaw(): string
    {
        $path = storage_path('framework/testing/modules/sirsoft-ecommerce/settings/language_currency.json');

        return File::exists($path) ? File::get($path) : '';
    }

    /**
     * 저장된 language_currency.json 을 디코드해 돌려줍니다.
     *
     * @return array 저장 파일의 디코드 결과
     */
    private function savedFile(): array
    {
        return json_decode($this->savedRaw(), true) ?? [];
    }

    /**
     * 저장본에 통화 목록이 없어도 defaults 통화 목록에 환율이 적용되는지 검증합니다.
     *
     * @effects selected_provider_applies_full_list_without_losing_configuration
     *
     * @return void 반환값 없음
     */
    public function test_rates_apply_to_default_currencies_without_saved_currency_list(): void
    {
        $service = app(EcommerceSettingsService::class);
        $service->saveSettings(['language_currency' => ['rate_provider' => 'fixture']]);
        $this->assertArrayNotHasKey('currencies', $this->savedFile());
        $this->assertSame(['USD', 'JPY'], $service->applyExchangeRates(['USD' => 0.72, 'JPY' => 151.5], 'fixture')['applied']);
        $saved = $this->savedFile();
        $this->assertSame(['KRW', 'USD', 'JPY', 'CNY', 'EUR'], array_column($saved['currencies'], 'code'));
        $this->assertSame(0.72, collect($saved['currencies'])->firstWhere('code', 'USD')['exchange_rate']);
        $this->assertSame(151.5, collect($saved['currencies'])->firstWhere('code', 'JPY')['exchange_rate']);
    }

    /**
     * 조회 보강 flag 와 언어팩으로 치환된 name 이 저장본에 영속되지 않는지 검증합니다.
     *
     * @effects selected_provider_applies_full_list_without_losing_configuration
     *
     * @return void 반환값 없음
     */
    public function test_display_only_flag_and_localized_name_are_not_persisted(): void
    {
        $service = app(EcommerceSettingsService::class);
        $service->saveSettings(['language_currency' => ['rate_provider' => 'fixture', 'default_currency' => 'KRW', 'currencies' => [
            ['code' => 'KRW', 'name' => ['ko' => '저장본 원'], 'exchange_rate' => null, 'is_default' => true],
            ['code' => 'USD', 'name' => ['ko' => '저장본 달러'], 'exchange_rate' => 0.85, 'is_default' => false],
        ]]]);
        $read = collect($service->getSettings('language_currency')['currencies'])->firstWhere('code', 'USD');
        $this->assertArrayHasKey('flag', $read);
        $this->assertArrayHasKey('en', $read['name']);
        $this->assertSame(['USD'], $service->applyExchangeRates(['USD' => 0.72], 'fixture')['applied']);
        $saved = $this->savedFile();
        foreach ($saved['currencies'] as $row) {
            $this->assertArrayNotHasKey('flag', $row, $row['code']);
        }
        $this->assertSame(['ko' => '저장본 달러'], collect($saved['currencies'])->firstWhere('code', 'USD')['name']);
        $this->assertSame(0.72, collect($saved['currencies'])->firstWhere('code', 'USD')['exchange_rate']);
    }

    /**
     * 환율 적용이 적용 훅만 발행하고 설정 저장 훅은 재발행하지 않는지 검증합니다.
     *
     * @effects selected_provider_applies_full_list_without_losing_configuration
     *
     * @return void 반환값 없음
     */
    public function test_apply_rates_publishes_only_the_apply_rates_hook(): void
    {
        $service = app(EcommerceSettingsService::class);
        $service->saveSettings(['language_currency' => ['rate_provider' => 'fixture']]);
        $calls = [];
        foreach (['sirsoft-ecommerce.currency.after_apply_rates', 'sirsoft-ecommerce.settings.after_save', 'core.module_settings.after_save'] as $hook) {
            HookManager::addAction($hook, function () use (&$calls, $hook) {
                $calls[] = $hook;
            });
        }
        $this->assertSame(['USD'], $service->applyExchangeRates(['USD' => 0.72], 'fixture')['applied']);
        $this->assertSame(['sirsoft-ecommerce.currency.after_apply_rates'], $calls);
    }
}
