<?php

namespace App\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 코어 7.0.0-alpha.4 업그레이드 스텝
 *
 * core_upgrade_test 테이블에 샘플 데이터를 삽입합니다.
 * 코어 업데이트 시 업그레이드 스텝이 정상 실행되는지 검증하기 위한 테스트 스텝입니다.
 */
class Upgrade_7_0_0_alpha_4 implements UpgradeStepInterface
{
    /**
     * 업그레이드 스텝을 실행합니다.
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     * @return void
     */
    public function run(UpgradeContext $context): void
    {
        // 완료됨 — core_upgrade_test 테이블은 테스트 전용이므로 더 이상 실행하지 않음
        // $context->logger->info("코어 업그레이드 스텝 7.0.0-alpha.4 실행: core_upgrade_test 샘플 데이터 삽입");
        //
        // if (! Schema::hasTable('core_upgrade_test')) {
        //     $context->logger->warning("core_upgrade_test 테이블이 존재하지 않습니다. 마이그레이션을 먼저 실행하세요.");
        //
        //     return;
        // }
        //
        // DB::table('core_upgrade_test')->insert([
        //     'name' => 'upgrade_step_test',
        //     'description' => "코어 업그레이드 스텝 검증용 샘플 데이터 (from: {$context->fromVersion} → to: {$context->toVersion})",
        //     'created_at' => now(),
        //     'updated_at' => now(),
        // ]);
        //
        // $context->logger->info("코어 업그레이드 스텝 7.0.0-alpha.4 완료: 샘플 데이터 1건 삽입");
    }
}
