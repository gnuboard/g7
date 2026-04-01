<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v0.8.0 업그레이드 스텝
 *
 * - OrderOptionStatusEnum 제거에 따른 order_options.option_status 값 마이그레이션
 * - 기존 값(pending, shipped)을 OrderStatusEnum 값(pending_order, shipping)으로 변환
 * - 클레임 상태(return_*, exchange_*, refund_*) 제거 (미지원 → cancelled로 변환)
 */
class Upgrade_0_8_0 implements UpgradeStepInterface
{
    /**
     * 업그레이드를 실행합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        $this->migrateOptionStatusValues($context);
    }

    /**
     * order_options.option_status 값을 OrderStatusEnum 기준으로 변환합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    private function migrateOptionStatusValues(UpgradeContext $context): void
    {
        if (! Schema::hasTable('order_options')) {
            $context->logger->info('[v0.8.0] order_options 테이블이 없습니다. 스킵합니다.');

            return;
        }

        // 변환 매핑: 이전 OrderOptionStatusEnum → 신규 OrderStatusEnum
        $mappings = [
            'pending' => 'pending_order',
            'shipped' => 'shipping',
            'return_requested' => 'cancelled',
            'return_complete' => 'cancelled',
            'exchange_requested' => 'cancelled',
            'exchange_complete' => 'cancelled',
            'refund_complete' => 'cancelled',
        ];

        $totalUpdated = 0;

        foreach ($mappings as $oldValue => $newValue) {
            $count = DB::table('order_options')
                ->where('option_status', $oldValue)
                ->update(['option_status' => $newValue]);

            if ($count > 0) {
                $context->logger->info("[v0.8.0] option_status '{$oldValue}' → '{$newValue}': {$count}건 변환");
                $totalUpdated += $count;
            }
        }

        $context->logger->info("[v0.8.0] option_status 값 마이그레이션 완료: 총 {$totalUpdated}건 변환");
    }
}
