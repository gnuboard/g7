<?php

namespace Modules\Sirsoft\Board\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\Schema;

/**
 * v0.8.0 업그레이드 스텝
 *
 * 검색/필터/정렬 성능 향상을 위한 인덱스 추가 검증.
 * - board_posts: 2개 복합 인덱스
 */
class Upgrade_0_8_0 implements UpgradeStepInterface
{
    /**
     * 검증 대상 인덱스 목록.
     *
     * @var array<string, string[]>
     */
    private const EXPECTED_INDEXES = [
        'board_posts' => [
            'idx_board_posts_board_author',
            'idx_board_posts_board_status_created',
        ],
    ];

    /**
     * 업그레이드를 실행합니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     */
    public function run(UpgradeContext $context): void
    {
        $totalExpected = 0;
        $totalFound = 0;

        foreach (self::EXPECTED_INDEXES as $table => $indexes) {
            if (! Schema::hasTable($table)) {
                $context->logger->warning("[v0.8.0] {$table} 테이블이 존재하지 않습니다.");

                continue;
            }

            $existingIndexes = collect(Schema::getIndexes($table))->pluck('name')->toArray();

            foreach ($indexes as $indexName) {
                $totalExpected++;

                if (in_array($indexName, $existingIndexes)) {
                    $totalFound++;
                } else {
                    $context->logger->warning("[v0.8.0] {$table} 테이블에 {$indexName} 인덱스가 없습니다. 마이그레이션을 실행하세요.");
                }
            }
        }

        $context->logger->info("[v0.8.0] 게시판 인덱스 검증 완료: {$totalFound}/{$totalExpected}개 확인됨");
    }
}
