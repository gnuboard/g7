<?php

namespace App\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Schema;

/**
 * 코어 7.0.0-alpha.19 업그레이드 스텝
 *
 * 검색/필터/정렬 성능 향상을 위한 인덱스 추가 검증.
 * - activity_logs: description_key 인덱스
 * - users: created_at 인덱스
 * - mail_send_logs: status 인덱스
 * - template_layouts: template_id 인덱스
 * - schedules: created_at 인덱스
 */
class Upgrade_7_0_0_alpha_19 implements UpgradeStepInterface
{
    /**
     * 검증 대상 인덱스 목록.
     *
     * @var array<string, string[]>
     */
    private const EXPECTED_INDEXES = [
        'activity_logs' => ['idx_activity_logs_description_key'],
        'users' => ['idx_users_created_at'],
        'mail_send_logs' => ['idx_mail_send_logs_status'],
        'template_layouts' => ['idx_template_layouts_template_id'],
        'schedules' => ['idx_schedules_created_at'],
    ];

    /**
     * 업그레이드 스텝을 실행합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        $totalExpected = 0;
        $totalFound = 0;

        foreach (self::EXPECTED_INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                $context->logger->warning("[alpha.19] {$table} 테이블이 존재하지 않습니다.");

                continue;
            }

            $existingIndexes = collect(Schema::getIndexes($table))->pluck('name')->toArray();

            foreach ($indexes as $indexName) {
                $totalExpected++;

                if (in_array($indexName, $existingIndexes)) {
                    $totalFound++;
                } else {
                    $context->logger->warning("[alpha.19] {$table} 테이블에 {$indexName} 인덱스가 없습니다. 마이그레이션을 실행하세요.");
                }
            }
        }

        $context->logger->info("[alpha.19] 코어 인덱스 검증 완료: {$totalFound}/{$totalExpected}개 확인됨");
    }
}
