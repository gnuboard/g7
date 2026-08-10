<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftEcommerce\V1_0_5\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 주문의 부가세(total_vat_amount)를 백필합니다.
 *
 * 부가세 쓰기 지점이 부분취소 재계산 1곳뿐이라, 부분취소를 겪은 주문만 값이 정상화되고
 * 나머지 주문은 영구 0 으로 남았습니다. 같은 화면에서 주문마다 세금 표기가 달라집니다.
 *
 * 대상: `total_vat_amount = 0` 이면서 과세표준(`total_tax_amount`) > 0 인 주문.
 * 부분취소로 이미 정상화된 주문(값이 0 이 아님)은 건드리지 않습니다.
 *
 * 세율: 주문옵션 스냅샷(`product_snapshot.tax_rate`)이 있으면 그 값을, 없으면 10% 를 사용합니다.
 * 기존 주문은 스냅샷에 세율이 없을 수 있어 폴백이 필요합니다.
 *
 * idempotent: 이미 값이 있는 주문은 스킵. V-1 안전: Facades\DB/Schema 만 사용.
 */
class BackfillOrderVatAmount implements DataMigration
{
    private const ORDERS_TABLE = 'ecommerce_orders';

    private const OPTIONS_TABLE = 'ecommerce_order_options';

    /** @var int 청크 크기 */
    private const CHUNK = 500;

    /** @var float 세율 폴백 (%) */
    private const DEFAULT_RATE = 10.0;

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'BackfillOrderVatAmount';
    }

    /**
     * 부가세가 비어 있는 주문을 백필합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable(self::ORDERS_TABLE)
            || ! Schema::hasColumn(self::ORDERS_TABLE, 'total_vat_amount')
            || ! Schema::hasColumn(self::ORDERS_TABLE, 'total_tax_amount')) {
            $context->logger->info('[ecommerce:1.0.5] 주문 부가세 백필 — 대상 스키마 부재로 스킵');

            return;
        }

        $hasOptionSnapshot = Schema::hasTable(self::OPTIONS_TABLE)
            && Schema::hasColumn(self::OPTIONS_TABLE, 'product_snapshot');

        $updated = 0;
        $lastId = 0;

        while (true) {
            $orders = DB::table(self::ORDERS_TABLE)
                ->where('id', '>', $lastId)
                ->where('total_tax_amount', '>', 0)
                ->where(function ($query) {
                    $query->where('total_vat_amount', 0)->orWhereNull('total_vat_amount');
                })
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->get(['id', 'total_tax_amount']);

            if ($orders->isEmpty()) {
                break;
            }

            foreach ($orders as $order) {
                $lastId = (int) $order->id;

                $vat = $this->resolveVatForOrder(
                    (int) $order->id,
                    (float) $order->total_tax_amount,
                    $hasOptionSnapshot
                );

                if ($vat <= 0) {
                    continue;
                }

                DB::table(self::ORDERS_TABLE)
                    ->where('id', $order->id)
                    ->update(['total_vat_amount' => $vat]);

                $updated++;
            }

            $context->logger->info("[ecommerce:1.0.5] 주문 부가세 백필 진행 — 누적 {$updated}건 (마지막 주문 ID {$lastId})");
        }

        $context->logger->info("[ecommerce:1.0.5] 주문 부가세 백필 완료 — 총 {$updated}건");
    }

    /**
     * 주문 하나의 부가세를 산출합니다.
     *
     * 주문옵션 스냅샷의 세율별로 과세표준을 나눠 합산하고, 스냅샷이 없으면 주문 전체 과세표준에
     * 기본 세율을 적용합니다.
     *
     * @param  int  $orderId  주문 ID
     * @param  float  $totalTaxable  주문 과세표준
     * @param  bool  $hasOptionSnapshot  주문옵션 스냅샷 사용 가능 여부
     * @return int 부가세
     */
    private function resolveVatForOrder(int $orderId, float $totalTaxable, bool $hasOptionSnapshot): int
    {
        if (! $hasOptionSnapshot) {
            return $this->vatFromTaxable($totalTaxable, self::DEFAULT_RATE);
        }

        $columns = ['product_snapshot'];
        $hasSubtotal = Schema::hasColumn(self::OPTIONS_TABLE, 'subtotal_tax_amount');
        if ($hasSubtotal) {
            $columns[] = 'subtotal_tax_amount';
        }

        $options = DB::table(self::OPTIONS_TABLE)
            ->where('order_id', $orderId)
            ->get($columns);

        if ($options->isEmpty() || ! $hasSubtotal) {
            return $this->vatFromTaxable($totalTaxable, self::DEFAULT_RATE);
        }

        // 세율별 과세표준 합산 → 세율별 부가세의 합 (세율이 섞인 주문에서 이것만 정확)
        $byRate = [];
        $sumSubtotal = 0.0;

        foreach ($options as $option) {
            $snapshot = json_decode((string) $option->product_snapshot, true);
            $rate = is_array($snapshot) && isset($snapshot['tax_rate']) && $snapshot['tax_rate'] !== null
                ? (float) $snapshot['tax_rate']
                : self::DEFAULT_RATE;

            $amount = (float) ($option->subtotal_tax_amount ?? 0);
            if ($amount <= 0) {
                continue;
            }

            $byRate[(string) $rate] = ($byRate[(string) $rate] ?? 0) + $amount;
            $sumSubtotal += $amount;
        }

        // 옵션 과세 소계 합이 주문 과세표준과 크게 어긋나면(집계 누락 등) 주문 과세표준 + 기본 세율로 폴백
        if ($sumSubtotal <= 0 || abs($sumSubtotal - $totalTaxable) > max(1.0, $totalTaxable * 0.01)) {
            return $this->vatFromTaxable($totalTaxable, self::DEFAULT_RATE);
        }

        $vat = 0;
        foreach ($byRate as $rate => $amount) {
            $vat += $this->vatFromTaxable($amount, (float) $rate);
        }

        return $vat;
    }

    /**
     * 과세표준에 내재된 부가세를 계산합니다.
     *
     * 경로 A 규율: 기존 클래스(Support\VatCalculator)를 호출하지 않고 로컬 복제한다 —
     * 업그레이드 스텝은 과거 버전의 코드베이스에서도 실행될 수 있다.
     *
     * @param  float  $taxableAmount  과세표준 (부가세 포함)
     * @param  float  $rate  세율 (%)
     * @return int 부가세
     */
    private function vatFromTaxable(float $taxableAmount, float $rate): int
    {
        if ($taxableAmount <= 0 || $rate <= 0) {
            return 0;
        }

        return (int) round($taxableAmount * $rate / (100 + $rate));
    }
}
