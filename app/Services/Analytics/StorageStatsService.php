<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsConfig;
use App\Contracts\Repositories\Analytics\ActionRepositoryInterface;
use App\Contracts\Repositories\Analytics\TableStatsRepositoryInterface;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;

/**
 * 방문자 통계 저장 용량 현황
 *
 * 열한 개 표가 지금 몇 행이고 디스크를 얼마나 쓰는지 봅니다. 운영자가 보존일을 정할
 * 때 「90일이면 얼마나 쌓이는가」를 숫자로 보고 정하게 하는 값입니다.
 *
 * 행 수와 크기는 데이터베이스가 들고 있는 어림값입니다. 그래서 운영자가 지울지
 * 말지를 정하는 두 표(방문·액션)만 정확히 셉니다.
 */
class StorageStatsService
{
    /**
     * 현황을 보여 줄 표 목록
     */
    private const TABLES = [
        'analytics_visits',
        'analytics_actions',
        'analytics_action_names',
        'analytics_conversions',
        'analytics_bot_hits',
        'analytics_stats_hourly',
        'analytics_stats_daily',
        'analytics_dimension_stats',
        'analytics_salts',
        'analytics_archive_runs',
        'analytics_collect_failures',
    ];

    /**
     * 현황 조회기를 생성합니다.
     *
     * @param  VisitRepositoryInterface  $visits  방문 원장 저장소
     * @param  ActionRepositoryInterface  $actions  액션 원장 저장소
     * @param  TableStatsRepositoryInterface  $tableStats  표 용량 저장소
     */
    public function __construct(
        private readonly VisitRepositoryInterface $visits,
        private readonly ActionRepositoryInterface $actions,
        private readonly TableStatsRepositoryInterface $tableStats,
    ) {}

    /**
     * 표별 행 수와 크기를 돌려줍니다.
     *
     * @param  AnalyticsConfig  $config  설정
     * @return array{tables: array<int, array<string, mixed>>, exact: array<string, int>, over_limit: bool, driver_supported: bool}
     *                                                                                                                              저장 현황
     */
    public function stats(AnalyticsConfig $config): array
    {
        $exactVisits = $this->visits->countAll();
        $exactActions = $this->actions->countAll();
        $sizes = $this->tableStats->sizes(self::TABLES);

        return [
            'tables' => array_map(fn (string $table): array => [
                'table' => $table,
                'rows' => $sizes[$table]['rows'] ?? null,
                'data_bytes' => $sizes[$table]['data_bytes'] ?? null,
                'index_bytes' => $sizes[$table]['index_bytes'] ?? null,
            ], self::TABLES),
            'exact' => [
                'analytics_visits' => $exactVisits,
                'analytics_actions' => $exactActions,
            ],
            'over_limit' => $exactVisits >= $config->ledgerWarningVisits
                || $exactActions >= $config->ledgerWarningActions,
            'driver_supported' => $this->tableStats->supportsTableStats(),
        ];
    }
}
