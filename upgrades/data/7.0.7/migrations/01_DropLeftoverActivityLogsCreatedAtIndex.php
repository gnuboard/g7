<?php

namespace App\Upgrades\Data\V7_0_7\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 7.0.6 색인 교체가 남긴 활동 로그의 구 색인을 정리합니다.
 *
 * 7.0.6 스텝(AddTiebreakToCoreListIndexes)의 초기 배포본은 교체 대상 기존 색인명을
 * `g7_` 접두사 리터럴로 지목했다. 기본 접두사가 아닌 설치본에서는 그 이름이 실재하지
 * 않아 삭제가 조용히 건너뛰어졌고, 새 색인(`idx_activity_logs_created_id`)과 구 색인
 * (`{접두사}activity_logs_created_at_index`)이 함께 남았다 — 구 색인은 새 색인의 좌측
 * 프리픽스라 조회 이득 없이 쓰기 비용만 늘린다. 스텝 소스는 동적 접두사로 교정됐지만
 * 이미 7.0.6 을 거친 설치본은 그 스텝을 다시 실행하지 않으므로 여기서 정리한다.
 *
 * idempotent: 새 색인이 있고 구 색인이 남은 경우에만 삭제한다. 기본 접두사(g7_)
 * 설치본과 7.0.7 교정판으로 업그레이드한 설치본에서는 구 색인이 이미 없어 no-op 이다.
 * V-1 안전: Facades\Schema / Facades\DB 만 사용합니다.
 */
class DropLeftoverActivityLogsCreatedAtIndex implements DataMigration
{
    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'DropLeftoverActivityLogsCreatedAtIndex';
    }

    /**
     * 잔존 구 색인을 삭제합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        $table = 'activity_logs';

        if (! Schema::hasTable($table)) {
            $context->logger->info('[core:7.0.7] 테이블 부재 — 잔존 색인 정리 스킵: '.$table);

            return;
        }

        $existing = array_column(Schema::getIndexes($table), 'name');
        $newIndex = 'idx_activity_logs_created_id';
        $oldIndex = DB::getTablePrefix().'activity_logs_created_at_index';

        // 새 색인이 없으면 교체 자체가 미완이다 — 구 색인이 유일한 정렬 색인일 수
        // 있으므로 지우지 않는다(7.0.6 스텝이 담당할 상태).
        if (! in_array($newIndex, $existing, true)) {
            $context->logger->info("[core:7.0.7] 새 색인 부재 — 잔존 색인 정리 스킵: {$newIndex}");

            return;
        }

        if (! in_array($oldIndex, $existing, true)) {
            $context->logger->info('[core:7.0.7] 잔존 구 색인 없음 — 정리 불필요: '.$table);

            return;
        }

        Schema::table($table, function ($blueprint) use ($oldIndex) {
            $blueprint->dropIndex($oldIndex);
        });

        $context->logger->info("[core:7.0.7] 7.0.6 교체가 남긴 구 색인 제거: {$oldIndex}");
    }
}
