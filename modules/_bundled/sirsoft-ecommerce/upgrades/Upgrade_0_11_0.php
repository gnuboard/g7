<?php

namespace Modules\Sirsoft\Ecommerce\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Sirsoft\Ecommerce\Enums\SequenceType;

/**
 * v0.11.0 업그레이드 스텝
 *
 * - 주문 취소/환불 시스템 도입에 따른 CANCEL/REFUND 시퀀스 레코드 생성
 * - 마이그레이션으로 생성된 새 테이블 존재 여부 검증
 */
class Upgrade_0_11_0 implements UpgradeStepInterface
{
    /**
     * 업그레이드를 실행합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        $this->createCancelAndRefundSequences($context);
        $this->validateNewTables($context);
    }

    /**
     * CANCEL/REFUND 시퀀스 레코드를 생성합니다.
     *
     * SequenceSeeder에서 ORDER/PRODUCT만 생성하던 기존 설치 환경을 위해
     * CANCEL/REFUND 시퀀스를 firstOrCreate로 추가합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    private function createCancelAndRefundSequences(UpgradeContext $context): void
    {
        if (! Schema::hasTable('ecommerce_sequences')) {
            $context->logger->info('[v0.11.0] ecommerce_sequences 테이블이 없습니다. 시퀀스 생성을 스킵합니다.');

            return;
        }

        $sequenceTypes = [
            SequenceType::CANCEL,
            SequenceType::REFUND,
        ];

        foreach ($sequenceTypes as $type) {
            $exists = DB::table('ecommerce_sequences')
                ->where('type', $type->value)
                ->exists();

            if ($exists) {
                $context->logger->info("[v0.11.0] {$type->value} 시퀀스가 이미 존재합니다. 스킵합니다.");

                continue;
            }

            $config = $type->getDefaultConfig();

            DB::table('ecommerce_sequences')->insert([
                'type' => $type->value,
                'algorithm' => $config['algorithm']->value,
                'prefix' => $config['prefix'],
                'current_value' => 0,
                'increment' => 1,
                'min_value' => 1,
                'max_value' => $config['max_value'],
                'cycle' => false,
                'pad_length' => $config['pad_length'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $context->logger->info("[v0.11.0] {$type->value} 시퀀스 생성 완료 (알고리즘: {$config['algorithm']->value}, 접두사: {$config['prefix']})");
        }
    }

    /**
     * 마이그레이션으로 생성되어야 하는 새 테이블의 존재 여부를 검증합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    private function validateNewTables(UpgradeContext $context): void
    {
        $requiredTables = [
            'ecommerce_order_cancels',
            'ecommerce_order_cancel_options',
            'ecommerce_order_refunds',
            'ecommerce_order_refund_options',
        ];

        $missingTables = [];

        foreach ($requiredTables as $table) {
            if (! Schema::hasTable($table)) {
                $missingTables[] = $table;
            }
        }

        if (! empty($missingTables)) {
            $context->logger->warning('[v0.11.0] 누락된 테이블이 있습니다: '.implode(', ', $missingTables));
            $context->logger->warning('[v0.11.0] 마이그레이션이 정상 실행되었는지 확인해주세요.');
        } else {
            $context->logger->info('[v0.11.0] 주문 취소/환불 테이블 검증 완료 (4개 테이블 정상)');
        }
    }
}
