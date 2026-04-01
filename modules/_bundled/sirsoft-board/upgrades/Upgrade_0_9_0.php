<?php

namespace Modules\Sirsoft\Board\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Schema;

/**
 * v0.9.0 업그레이드 스텝
 *
 * FULLTEXT 인덱스(ngram) 추가 검증.
 * - boards: name
 * - boards_report_logs: snapshot
 */
class Upgrade_0_9_0 implements UpgradeStepInterface
{
    /**
     * 검증 대상 FULLTEXT 인덱스 목록.
     *
     * @var array<string, string[]>
     */
    private const EXPECTED_INDEXES = [
        'boards' => [
            'ft_boards_name',
        ],
        'boards_report_logs' => [
            'ft_boards_report_logs_snapshot',
        ],
    ];

    /**
     * 업그레이드를 실행합니다.
     *
     * @param UpgradeContext $context 업그레이드 컨텍스트
     * @return void
     */
    public function run(UpgradeContext $context): void
    {
        $totalExpected = 0;
        $totalFound = 0;

        foreach (self::EXPECTED_INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                $context->logger->warning("[v0.9.0] {$table} 테이블이 존재하지 않습니다.");

                continue;
            }

            $existingIndexes = collect(Schema::getIndexes($table))->pluck('name')->toArray();

            foreach ($indexes as $indexName) {
                $totalExpected++;

                if (in_array($indexName, $existingIndexes)) {
                    $totalFound++;
                } else {
                    $context->logger->warning("[v0.9.0] {$table} 테이블에 {$indexName} FULLTEXT 인덱스가 없습니다. 마이그레이션을 실행하세요.");
                }
            }
        }

        $context->logger->info("[v0.9.0] 게시판 FULLTEXT 인덱스 검증 완료: {$totalFound}/{$totalExpected}개 확인됨");
    }
}
