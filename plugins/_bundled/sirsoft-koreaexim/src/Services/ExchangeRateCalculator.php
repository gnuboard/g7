<?php

namespace Plugins\Sirsoft\Koreaexim\Services;

use Plugins\Sirsoft\Koreaexim\Enums\ItemStatus;
use Plugins\Sirsoft\Koreaexim\Enums\RateType;
use Plugins\Sirsoft\Koreaexim\Enums\RunStatus;

/** HTTP·DB 없이 원화 호가를 기본통화 기준 환율로 교차환산합니다. */
class ExchangeRateCalculator
{
    /**
     * 은행 통화 단위를 정규화하고 기본통화 기준으로 환산합니다.
     *
     * @param  array  $response  response 입력값
     * @param  array  $currencies  currencies 입력값
     * @param  string  $baseCode  baseCode 입력값
     * @param  RateType  $type  type 입력값
     * @param  int  $precision  precision 입력값
     * @return array 처리 결과
     */
    public function calculate(array $response, array $currencies, string $baseCode, RateType $type, int $precision = 6): array
    {
        $quotes = [];
        foreach ($response as $row) {
            if (! is_array($row) || (int) ($row['result'] ?? 0) !== 1 || ! preg_match('/^([A-Z]{3})(?:\((\d+)\))?$/D', $row['cur_unit'] ?? '', $match)) {
                continue;
            }
            $raw = str_replace(',', '', (string) ($row[$type->value] ?? ''));
            $unit = (int) ($match[2] ?? 1);
            $value = is_numeric($raw) ? (float) $raw : 0;
            $quotes[$match[1]] = ['row' => $row, 'unit' => $unit, 'source' => $value, 'krw' => $unit > 0 && is_finite($value) && $value > 0 ? $value / $unit : null];
        }
        foreach (['CNY' => 'CNH', 'CNH' => 'CNY'] as $code => $alias) {
            if (! isset($quotes[$code]) && isset($quotes[$alias])) {
                $quotes[$code] = $quotes[$alias];
            }
        }
        $base = collect($currencies)->firstWhere('code', $baseCode);
        $baseUnit = (int) ($base['base_unit'] ?? 1);
        $baseQuote = $quotes[$baseCode]['krw'] ?? null;
        $supportedBase = $base !== null && $baseUnit > 0 && $baseQuote !== null;
        $items = $rates = $managed = $unsupported = [];
        foreach ($currencies as $currency) {
            $code = $currency['code'];
            $quote = $quotes[$code] ?? null;
            $previous = $currency['exchange_rate'] ?? null;
            $new = $previous;
            if ($code === $baseCode) {
                $status = ItemStatus::BaseCurrency;
                $new = null;
            } elseif ($quote === null || ! $supportedBase) {
                $status = ItemStatus::Unsupported;
                $unsupported[] = $code;
            } elseif ($quote['krw'] === null) {
                $status = ItemStatus::InvalidRate;
                $unsupported[] = $code;
            } else {
                $candidate = round($baseUnit * $baseQuote / $quote['krw'], $precision);
                if (! is_finite($candidate) || $candidate <= 0) {
                    $status = ItemStatus::InvalidRate;
                    $unsupported[] = $code;
                } else {
                    $new = $candidate;
                    $managed[] = $code;
                    $rates[$code] = $new;
                    $status = abs((float) $previous - $new) <= 1e-9 ? ItemStatus::Unchanged : ItemStatus::Applied;
                }
            }
            $items[] = [
                'currency_code' => $code, 'api_currency_unit' => $quote['row']['cur_unit'] ?? null,
                'unit_divisor' => $quote['unit'] ?? 1, 'source_rate' => $quote['source'] ?? null,
                'previous_rate' => $previous, 'new_rate' => $new, 'status' => $status->value,
                'currency_snapshot' => array_intersect_key($currency, array_flip(['name', 'symbol', 'base_unit', 'rounding_unit', 'rounding_method', 'decimal_places', 'is_default'])),
                'api_fields' => $quote['row'] ?? null,
            ];
        }

        return ['status' => ! $supportedBase ? RunStatus::UnsupportedBase : ($unsupported ? RunStatus::Partial : RunStatus::Success),
            'base_unit' => $baseUnit, 'rates' => $rates, 'items' => $items,
            'managed_codes' => $managed, 'unsupported_codes' => $unsupported];
    }
}
