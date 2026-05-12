<?php

namespace App\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 코어 7.0.0-alpha.17 업그레이드 스텝
 *
 * ActivityLog 시스템 Monolog 리팩토링에 따른 데이터 무결성 확인.
 * - description 컬럼 삭제 → description_key/description_params 기반 다국어 전환
 * - changes 컬럼 추가 (구조화된 변경 이력)
 * - 복합 인덱스 추가
 * - 기존 로그 데이터는 description_key NULL 상태로 유지 (백필 생략 — 정책 결정)
 */
class Upgrade_7_0_0_alpha_17 implements UpgradeStepInterface
{
    /**
     * 업그레이드 스텝을 실행합니다.
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     * @return void
     */
    public function run(UpgradeContext $context): void
    {
        $table = $context->table('activity_logs');

        // 1. 마이그레이션 적용 여부 확인
        if (Schema::hasColumn('activity_logs', 'description')) {
            $context->logger->warning(
                'activity_logs 테이블에 description 컬럼이 아직 존재합니다. 마이그레이션을 먼저 실행하세요.'
            );

            return;
        }

        if (! Schema::hasColumn('activity_logs', 'description_key')) {
            $context->logger->warning(
                'activity_logs 테이블에 description_key 컬럼이 없습니다. 마이그레이션을 먼저 실행하세요.'
            );

            return;
        }

        // 2. 기존 데이터 현황 보고
        $totalCount = DB::table('activity_logs')->count();
        $nullKeyCount = DB::table('activity_logs')->whereNull('description_key')->count();
        $filledKeyCount = $totalCount - $nullKeyCount;

        $context->logger->info("활동 로그 현황: 총 {$totalCount}건 (description_key 설정됨: {$filledKeyCount}건, NULL: {$nullKeyCount}건)");

        if ($nullKeyCount > 0) {
            $context->logger->info(
                "기존 {$nullKeyCount}건의 로그는 description_key가 NULL 상태로 유지됩니다. "
                . '새 로그부터 description_key 기반 다국어가 적용됩니다.'
            );
        }

        // 3. loggable NULL 현황 보고
        $nullLoggableCount = DB::table('activity_logs')
            ->whereNull('loggable_type')
            ->whereNull('loggable_id')
            ->count();

        $context->logger->info("loggable NULL 현황: {$nullLoggableCount}건 (새 로그부터 모델 연결됩니다)");
    }
}
