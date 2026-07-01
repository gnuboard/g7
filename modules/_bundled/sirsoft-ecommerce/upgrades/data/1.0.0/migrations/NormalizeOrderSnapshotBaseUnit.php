<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_0_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 기존 주문의 currency_snapshot 을 통화별 base_unit 공식으로 정규화. (MP08-3 방향 B)
 *
 * 환불 재계산은 주문 시점 스냅샷 환율을 사용한다(환차손 0, D-BASE-3). 환산 공식이
 * ÷1000(KRW base_unit 하드코딩)에서 ÷base_unit 으로 바뀌므로, 기존 스냅샷에 base_unit 을
 * 박제하고 환율을 정규화해 변환 전/후 결제·환불 금액을 동일하게 유지한다:
 *
 *   기본 통화 base_unit = B 일 때
 *   (base / 1000) × old_rate = (base / B) × new_rate  →  new_rate = old_rate × (B / 1000)
 *
 * 박제 위치(getSnapshotBaseUnit SSoT 와 일치):
 *   - snapshot.base_unit = 기본 통화 base_unit
 *   - snapshot.exchange_rates[code].base_unit = 통화별 base_unit
 *
 * idempotent: snapshot.base_unit 이 이미 있으면 건너뛴다. V-1 안전: Facades\DB/Schema 만 사용.
 */
class NormalizeOrderSnapshotBaseUnit implements DataMigration
{
    private const TABLE = 'ecommerce_orders';

    private const COLUMN = 'currency_snapshot';

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
        return 'NormalizeOrderSnapshotBaseUnit';
    }

    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasColumn(self::TABLE, self::COLUMN)) {
            $context->logger->info('[ecommerce:1.0.0] ecommerce_orders.currency_snapshot 없음 — 스냅샷 정규화 스킵');

            return;
        }

        $converted = 0;

        DB::table(self::TABLE)
            ->whereNotNull(self::COLUMN)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$converted) {
                foreach ($rows as $row) {
                    $snapshot = json_decode((string) $row->{self::COLUMN}, true);
                    if (! is_array($snapshot)) {
                        continue;
                    }

                    // idempotent: 이미 base_unit 박제됨
                    if (isset($snapshot['base_unit'])) {
                        continue;
                    }

                    $updated = $this->normalizeSnapshot($snapshot);

                    DB::table(self::TABLE)
                        ->where('id', $row->id)
                        ->update([self::COLUMN => json_encode($updated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                    $converted++;
                }
            });

        $context->logger->info("[ecommerce:1.0.0] 주문 스냅샷 base_unit 정규화 완료 — {$converted}건");
    }

    /**
     * 단일 스냅샷에 base_unit 을 박제하고 환율을 새 공식 기준으로 정규화합니다.
     *
     * @param  array  $snapshot  주문 시점 통화 스냅샷
     * @return array 정규화된 스냅샷
     */
    private function normalizeSnapshot(array $snapshot): array
    {
        $baseCurrency = $snapshot['base_currency'] ?? 'KRW';
        $baseUnit = $this->baseUnitFor($baseCurrency);
        $scale = $baseUnit / self::LEGACY_DIVISOR;

        // 최상위 base_unit 박제 (getSnapshotBaseUnit 1순위)
        $snapshot['base_unit'] = $baseUnit;

        // order 단위 환율(exchange_rate)도 정규화 (표시/메타용)
        if (isset($snapshot['exchange_rate']) && is_numeric($snapshot['exchange_rate'])) {
            $orderCurrency = $snapshot['order_currency'] ?? $baseCurrency;
            // base 통화 자기환율(1.0)은 정규화 대상 아님
            if ($orderCurrency !== $baseCurrency) {
                $snapshot['exchange_rate'] = $this->normalizeRate((float) $snapshot['exchange_rate'], $scale);
            }
        }

        // 통화별 환율 + base_unit 정규화
        if (isset($snapshot['exchange_rates']) && is_array($snapshot['exchange_rates'])) {
            foreach ($snapshot['exchange_rates'] as $code => $rateData) {
                $isBase = ($code === $baseCurrency);

                if (is_array($rateData)) {
                    $snapshot['exchange_rates'][$code]['base_unit'] = $this->baseUnitFor((string) $code);

                    if (! $isBase && isset($rateData['rate']) && is_numeric($rateData['rate'])) {
                        $snapshot['exchange_rates'][$code]['rate'] = $this->normalizeRate((float) $rateData['rate'], $scale);
                    }
                } elseif (is_numeric($rateData) && ! $isBase) {
                    // 하위 호환: 단순 float 형태 스냅샷
                    $snapshot['exchange_rates'][$code] = $this->normalizeRate((float) $rateData, $scale);
                }
            }
        }

        return $snapshot;
    }

    /**
     * 통화의 base_unit 표준값을 반환합니다.
     *
     * @param  string  $code  통화 코드
     * @return int base_unit
     */
    private function baseUnitFor(string $code): int
    {
        return self::BASE_UNIT[$code] ?? 1;
    }

    /**
     * 환율을 새 공식 기준으로 정규화합니다(정수면 int).
     *
     * @param  float  $rate  기존 환율(÷1000 전제)
     * @param  float  $scale  정규화 계수(base_unit / 1000)
     * @return float|int 정규화된 환율
     */
    private function normalizeRate(float $rate, float $scale): float|int
    {
        $normalized = round($rate * $scale, 8);

        if ($normalized == (int) $normalized) {
            return (int) $normalized;
        }

        return $normalized;
    }
}
