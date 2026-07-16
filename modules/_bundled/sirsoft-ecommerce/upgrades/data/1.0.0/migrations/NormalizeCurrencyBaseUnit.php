<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_0_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\File;

/**
 * 통화 환율 공식을 KRW-base 종속 ÷1000 에서 통화별 base_unit 기반으로 전환. (MP08-3 방향 B)
 *
 * 기존 공식: 변환금액 = (base금액 / 1000) × exchange_rate  — 1000 이 KRW base_unit 하드코딩.
 * 신규 공식: 변환금액 = (base금액 / 기본통화.base_unit) × exchange_rate.
 *
 * 저장 설정의 환율은 "1000 base단위당 N" 전제(÷1000 짝)였으므로, base_unit 부여 + 환율 정규화로
 * 동일 환산 결과를 유지한다:
 *   - 기본 통화 base_unit = 1000 → 환율 그대로(÷1000 등가, 환산 불변)
 *   - 기본 통화 base_unit ≠ 1000(예: USD=1) → 환율 ÷ (1000 / base_unit), 통화별 base_unit 부여
 *
 * 비-KRW 기본 통화(예: USD)에서 ÷1000 잔재로 외화가 0 이 되던 결함을 정상화한다.
 * idempotent: 이미 base_unit 이 부여되었으면 no-op.
 *
 * V-1 안전: Illuminate\Support\Facades\File + 로컬 헬퍼만 사용.
 */
class NormalizeCurrencyBaseUnit implements DataMigration
{
    private const MODULE_IDENTIFIER = 'sirsoft-ecommerce';

    /**
     * 통화별 base_unit 표준값 (소액 통화만 묶음 단위).
     */
    private const BASE_UNIT = [
        'KRW' => 1000,
        'JPY' => 100,
    ];

    /**
     * 기존 환율 공식의 하드코딩 분모(레거시 KRW base_unit).
     */
    private const LEGACY_DIVISOR = 1000;

    public function name(): string
    {
        return 'NormalizeCurrencyBaseUnit';
    }

    public function run(UpgradeContext $context): void
    {
        $path = $this->settingsFilePath();

        if (! File::exists($path)) {
            $context->logger->info('[ecommerce:1.0.0] language_currency.json 미존재 — base_unit 정규화 스킵');

            return;
        }

        $settings = json_decode((string) File::get($path), true);
        if (! is_array($settings) || ! isset($settings['currencies']) || ! is_array($settings['currencies'])) {
            $context->logger->warning('[ecommerce:1.0.0] language_currency.json 통화 목록 없음 — base_unit 정규화 스킵');

            return;
        }

        $currencies = $settings['currencies'];

        // idempotent: 이미 어느 통화든 base_unit 이 있으면 정규화 완료된 것으로 간주
        foreach ($currencies as $currency) {
            if (isset($currency['base_unit'])) {
                $context->logger->info('[ecommerce:1.0.0] base_unit 이미 부여됨 — 정규화 스킵 (idempotent)');

                return;
            }
        }

        $defaultCode = $settings['default_currency'] ?? 'KRW';
        $defaultBaseUnit = $this->baseUnitFor($defaultCode);

        // 환율 정규화 계수: 기존 ÷1000 공식을 새 ÷base_unit 공식과 등가로 맞춘다.
        // (base / 1000) × old_rate = (base / base_unit) × new_rate
        //   → new_rate = old_rate × (base_unit / 1000)
        $scale = $defaultBaseUnit / self::LEGACY_DIVISOR;

        foreach ($currencies as $idx => $currency) {
            $code = $currency['code'] ?? null;
            if ($code === null) {
                continue;
            }

            // 통화별 base_unit 부여
            $currencies[$idx]['base_unit'] = $this->baseUnitFor($code);

            // 기본 통화가 아니고 환율이 수치면 정규화 (기본 통화 환율은 null 이므로 건너뜀)
            $rate = $currency['exchange_rate'] ?? null;
            if (! ($currency['is_default'] ?? false) && is_numeric($rate)) {
                $currencies[$idx]['exchange_rate'] = $this->normalizeRate((float) $rate, $scale);
            }
        }

        $settings['currencies'] = $currencies;

        File::put($path, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $context->logger->info(sprintf(
            '[ecommerce:1.0.0] 통화 base_unit 정규화 완료 (기본=%s, base_unit=%d, 환율 스케일=%s)',
            $defaultCode,
            $defaultBaseUnit,
            rtrim(rtrim(sprintf('%.6f', $scale), '0'), '.')
        ));
    }

    /**
     * 통화의 base_unit 표준값을 반환합니다 (소액통화 외 1).
     *
     * @param  string  $code  통화 코드
     * @return int base_unit
     */
    private function baseUnitFor(string $code): int
    {
        return self::BASE_UNIT[$code] ?? 1;
    }

    /**
     * 환율을 새 공식 기준으로 정규화합니다.
     *
     * 정수 결과(소수 0)는 정수로, 그 외는 의미 자릿수를 보존해 반올림합니다.
     *
     * @param  float  $rate  기존 환율(÷1000 전제)
     * @param  float  $scale  정규화 계수(base_unit / 1000)
     * @return float|int 정규화된 환율
     */
    private function normalizeRate(float $rate, float $scale): float|int
    {
        $normalized = $rate * $scale;

        // 부동소수 오차 정리: 소수 8자리에서 반올림
        $normalized = round($normalized, 8);

        // 정수면 int 로 (157.0 → 157)
        if ($normalized == (int) $normalized) {
            return (int) $normalized;
        }

        return $normalized;
    }

    /**
     * language_currency.json 의 저장 경로를 반환합니다.
     *
     * 테스트 환경에서는 운영 storage 오염을 막기 위해 framework/testing 경로를 사용합니다
     * (EcommerceSettingsService 의 저장 경로 분기와 동일).
     *
     * @return string 설정 파일 절대 경로
     */
    private function settingsFilePath(): string
    {
        $base = app()->runningUnitTests()
            ? 'framework/testing/modules/'
            : 'app/modules/';

        return storage_path($base.self::MODULE_IDENTIFIER.'/settings/language_currency.json');
    }
}
