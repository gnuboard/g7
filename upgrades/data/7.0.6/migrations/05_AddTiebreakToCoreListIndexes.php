<?php

namespace App\Upgrades\Data\V7_0_6\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 코어 목록(활동 로그·알림 발송 이력·회원)의 정렬 색인 끝에 기본키를 덧붙입니다.
 *
 * 세 목록 모두 작성일 내림차순으로 정렬하는데, 색인이 작성일에서 끝나면 같은 시각에 쌓인
 * 구간의 순서를 색인으로 만들 수 없어 추가 정렬 작업이 붙습니다. 활동 로그처럼 같은 초에
 * 여러 건이 쌓이는 목록에서는 이 구간이 일상적입니다.
 *
 * 신규 설치는 마이그레이션이 처리하지만 기존 사이트에는 반영되지 않으므로 업그레이드
 * 시점에 같은 교체를 수행합니다.
 *
 * 기록이 많은 사이트에서는 색인 교체가 수 분 이상 걸릴 수 있고 그동안 해당 테이블 쓰기가
 * 대기합니다. 새 색인을 먼저 만들고 기존 색인을 나중에 지우므로, 중간에 중단되어도 조회가
 * 색인 없이 남는 구간은 없습니다.
 *
 * idempotent: 이미 교체된 대상은 건너뜁니다. V-1 안전: Facades\Schema / Facades\DB 만 사용합니다.
 */
class AddTiebreakToCoreListIndexes implements DataMigration
{
    /**
     * 대상을 반환합니다: [테이블 => [신규 색인명, 컬럼, 교체 대상 기존 색인명(없으면 null)]]
     *
     * activity_logs 의 기존 색인은 Laravel 자동 색인명(접두사 포함 테이블명에서 파생)이라
     * 리터럴 `g7_` 을 박으면 다른 접두사 설치에서 조용한 no-op(중복 색인 잔존)이 된다 —
     * `DB::getTablePrefix()` 로 동적 조립한다.
     *
     * @return array<string, array{0: string, 1: array<int, string>, 2: string|null}>
     */
    private function targets(): array
    {
        $prefix = DB::getTablePrefix();

        return [
            'activity_logs' => ['idx_activity_logs_created_id', ['created_at', 'id'], $prefix.'activity_logs_created_at_index'],
            'notification_logs' => ['idx_notification_logs_created_id', ['created_at', 'id'], null],
            'users' => ['idx_users_created_id', ['created_at', 'id'], 'idx_users_created_at'],
        ];
    }

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'AddTiebreakToCoreListIndexes';
    }

    /**
     * 목록 정렬 색인을 기본키 포함 형태로 교체합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        foreach ($this->targets() as $table => [$newIndex, $columns, $oldIndex]) {
            if (! Schema::hasTable($table)) {
                $context->logger->info("[core:7.0.6] 테이블 부재 — 색인 교체 스킵: {$table}");

                continue;
            }

            $existing = array_column(Schema::getIndexes($table), 'name');

            if (in_array($newIndex, $existing, true)) {
                $context->logger->info("[core:7.0.6] 이미 교체된 색인 — 스킵: {$newIndex}");
            } else {
                $context->logger->info("[core:7.0.6] 목록 정렬 색인 교체 시작: {$table} (기록이 많으면 수 분 이상 걸릴 수 있고 그동안 해당 테이블 쓰기가 대기합니다)");

                Schema::table($table, function ($blueprint) use ($columns, $newIndex) {
                    $blueprint->index($columns, $newIndex);
                });

                $context->logger->info("[core:7.0.6] 새 색인 생성 완료: {$newIndex}");
            }

            // 기존 단일 색인은 새 색인의 좌측 프리픽스라 남겨 두면 쓰기 비용만 늘어난다.
            // 반드시 새 색인 생성 이후에 지운다.
            if ($oldIndex !== null && in_array($oldIndex, $existing, true)) {
                Schema::table($table, function ($blueprint) use ($oldIndex) {
                    $blueprint->dropIndex($oldIndex);
                });

                $context->logger->info("[core:7.0.6] 상위집합에 포함된 기존 색인 제거: {$oldIndex}");
            }
        }
    }
}
