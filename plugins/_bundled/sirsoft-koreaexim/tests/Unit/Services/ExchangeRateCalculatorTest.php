<?php

namespace Plugins\Sirsoft\Koreaexim\Tests\Unit\Services;

use Plugins\Sirsoft\Koreaexim\Enums\RateType;
use Plugins\Sirsoft\Koreaexim\Enums\RunStatus;
use Plugins\Sirsoft\Koreaexim\Services\ExchangeRateCalculator;
use Plugins\Sirsoft\Koreaexim\Tests\PluginTestCase;

/** @effects cross_rates, preserve_invalid_rates */
class ExchangeRateCalculatorTest extends PluginTestCase
{
    /**
     * 기본통화와 환율 종류 및 값과 정밀도 조합별 환산 결과를 검증합니다.
     *
     * @effects cross_rates, preserve_invalid_rates
     *
     * @return void 반환값 없음
     */
    public function test_calculation_input_matrix(): void
    {
        $cases = require dirname(__DIR__, 2).'/fixtures/CalculationCases.php';
        foreach ($cases as [$base, $target, $type, $value, $precision]) {
            $quotes = $this->quotes();
            $apiCode = ['JPY' => 'JPY(100)', 'CNY' => 'CNH'][$target] ?? $target;
            foreach ($quotes as &$quote) {
                if ($quote['cur_unit'] === $apiCode && $value !== 'normal') {
                    $quote[$type] = $value === 'zero' ? '0' : '';
                }
            }
            unset($quote);
            $currencies = array_map(fn ($code) => ['code' => $code, 'base_unit' => ['KRW' => 1000, 'JPY' => 100][$code] ?? 1, 'exchange_rate' => 0.8], ['KRW', 'USD', 'JPY', 'CNY', 'VND']);
            $result = (new ExchangeRateCalculator)->calculate($quotes, $currencies, $base, RateType::from($type), $precision);
            $item = collect($result['items'])->firstWhere('currency_code', $target);
            $context = implode('/', [$base, $target, $type, $value, $precision]);
            if ($base === $target) {
                $this->assertSame('base_currency', $item['status'], $context);
                $this->assertNull($item['new_rate'], $context);
            } elseif ($target === 'VND') {
                $this->assertSame('unsupported', $item['status'], $context);
                $this->assertSame(0.8, $item['new_rate'], $context);
            } elseif ($value !== 'normal') {
                $this->assertSame('invalid_rate', $item['status'], $context);
                $this->assertSame(0.8, $item['new_rate'], $context);
            } else {
                $perWon = ['deal_bas_r' => ['KRW' => 1, 'USD' => 1380, 'JPY' => 9.5105, 'CNY' => 190], 'ttb' => ['KRW' => 1, 'USD' => 1370, 'JPY' => 9.4, 'CNY' => 189], 'tts' => ['KRW' => 1, 'USD' => 1390, 'JPY' => 9.6, 'CNY' => 191]][$type];
                $expected = round((['KRW' => 1000, 'JPY' => 100][$base] ?? 1) * $perWon[$base] / $perWon[$target], $precision);
                $this->assertEquals($expected > 0 ? $expected : 0.8, $item['new_rate'], $context);
                $this->assertSame($expected > 0 ? 'applied' : 'invalid_rate', $item['status'], $context);
            }
            $this->assertArrayNotHasKey($base, $result['rates'], $context);
        }
    }

    /**
     * 원화 기준에서 백 단위 고시 환율을 환산하는지 검증합니다.
     *
     * @scenario base=KRW
     *
     * @return void 반환값 없음
     */
    public function test_krw_base_and_hundred_unit_quote(): void
    {
        $result = $this->calculate('KRW');
        $this->assertSame(0.724638, $result['rates']['USD']);
        $this->assertSame(round(1000 / 9.5105, 6), $result['rates']['JPY']);
        $this->assertArrayNotHasKey('KRW', $result['rates']);
        $this->assertSame('base_currency', $result['items'][0]['status']);
    }

    /**
     * 달러 기준으로 원화 환율도 갱신하는지 검증합니다.
     *
     * @scenario base=USD
     *
     * @return void 반환값 없음
     */
    public function test_usd_base_updates_krw_too(): void
    {
        $result = $this->calculate('USD');
        $this->assertSame(1380.0, $result['rates']['KRW']);
        $this->assertSame(round(1380 / 9.5105, 6), $result['rates']['JPY']);
    }

    /**
     * 엔화 기준 단위와 위안화 별칭을 처리하는지 검증합니다.
     *
     * @scenario base=JPY
     *
     * @return void 반환값 없음
     */
    public function test_jpy_base_unit_and_aliases(): void
    {
        $result = $this->calculate('JPY');
        $this->assertSame(round(951.05 / 1380, 6), $result['rates']['USD']);
        $this->assertSame(round(951.05 / 190, 6), $result['rates']['CNY']);
    }

    /**
     * 모든 환율 종류에서 잘못된 값은 기존 환율을 보존하는지 검증합니다.
     *
     * @effects every_rate_type_and_invalid_values_preserve_old_rate
     *
     * @return void 반환값 없음
     */
    public function test_every_rate_type_and_invalid_values_preserve_old_rate(): void
    {
        foreach (RateType::cases() as $type) {
            $result = $this->calculate('KRW', $type);
            $this->assertGreaterThan(0, $result['rates']['USD']);
        }
        foreach (['0', '', 'invalid'] as $value) {
            $quotes = $this->quotes();
            $quotes[1]['deal_bas_r'] = $value;
            $result = $this->calculate('KRW', quotes: $quotes);
            $this->assertArrayNotHasKey('USD', $result['rates']);
            $this->assertSame(0.8, $result['items'][1]['new_rate']);
            $this->assertSame('invalid_rate', $result['items'][1]['status']);
        }
    }

    /**
     * 영으로 반올림되는 환율과 미지원 기본통화를 안전하게 처리하는지 검증합니다.
     *
     * @effects rounding_to_zero_and_unsupported_base
     *
     * @return void 반환값 없음
     */
    public function test_rounding_to_zero_and_unsupported_base(): void
    {
        $quotes = $this->quotes();
        $quotes[1]['deal_bas_r'] = '999999999999';
        $this->assertArrayNotHasKey('USD', $this->calculate('KRW', quotes: $quotes)['rates']);
        $result = $this->calculate('VND');
        $this->assertSame(RunStatus::UnsupportedBase, $result['status']);
        $this->assertSame([], $result['managed_codes']);
    }

    /**
     * 교차 환산에 필요한 고정 고시 환율을 반환합니다.
     *
     * @return array 처리 결과
     */
    protected function quotes(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/fixtures/koreaexim/success.json'), true);
    }

    /**
     * 지정 기본통화와 고시 응답으로 환산 결과를 계산합니다.
     *
     * @param  string  $base  base 입력값
     * @param  RateType  $type  type 입력값
     * @param  ?array  $quotes  quotes 입력값
     * @return array 처리 결과
     */
    private function calculate(string $base, RateType $type = RateType::DealBaseRate, ?array $quotes = null): array
    {
        $currencies = array_map(fn ($code) => ['code' => $code, 'base_unit' => match ($code) {
            'KRW' => 1000, 'JPY' => 100, default => 1
        }, 'exchange_rate' => 0.8], ['KRW', 'USD', 'JPY', 'CNY', 'VND']);

        return (new ExchangeRateCalculator)->calculate($quotes ?? $this->quotes(), $currencies, $base, $type);
    }
}
