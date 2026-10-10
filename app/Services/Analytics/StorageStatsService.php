<?php

namespace App\Services\Analytics;

use App\Analytics\AnalyticsConfig;
use App\Analytics\CodeLabelTranslator;
use App\Contracts\Repositories\Analytics\ActionRepositoryInterface;
use App\Contracts\Repositories\Analytics\TableStatsRepositoryInterface;
use App\Contracts\Repositories\Analytics\VisitRepositoryInterface;
use App\Extension\HookManager;

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
        $tables = $this->tables();
        $sizes = $this->tableStats->sizes($tables);
        // 표별 행 수는 DB 통계 어림값이다. 이미 정확히 센 원장 두 표는 그 값을 써서, 같은 카드 위쪽의
        // 「방문 N행」과 표의 방문 기록 줄이 다른 숫자를 보이지 않게 한다.
        $exactRows = [
            'analytics_visits' => $exactVisits,
            'analytics_actions' => $exactActions,
        ];

        return [
            'tables' => array_map(fn (string $table): array => [
                'table' => $table,
                // 표 이름만으로는 무엇이 쌓이는지 알 수 없다 — 화면 이름을 덧붙인다. 확장이 더한 표는 이름이 없어 원래 이름 그대로다.
                'label' => CodeLabelTranslator::label('analytics.storage_table', $table),
                'rows' => $exactRows[$table] ?? $sizes[$table]['rows'] ?? null,
                'data_bytes' => $sizes[$table]['data_bytes'] ?? null,
                'index_bytes' => $sizes[$table]['index_bytes'] ?? null,
            ], $tables),
            'exact' => [
                'analytics_visits' => $exactVisits,
                'analytics_actions' => $exactActions,
            ],
            'over_limit' => $exactVisits >= $config->ledgerWarningVisits
                || $exactActions >= $config->ledgerWarningActions,
            'driver_supported' => $this->tableStats->supportsTableStats(),
        ];
    }

    /**
     * 현황을 보여 줄 표 목록을 만듭니다.
     *
     * 필터 `core.analytics.storage_tables` — 인자: 빈 배열. 확장이 통계용으로 따로 둔 표를
     * 더하면 진단 화면의 저장소 현황에 함께 뜹니다. 코어 표는 빼지 못합니다.
     *
     * @return array<int, string> 표 이름 목록
     */
    private function tables(): array
    {
        $extra = HookManager::applyFilters('core.analytics.storage_tables', []);

        $valid = array_filter(
            is_array($extra) ? $extra : [],
            fn ($table): bool => is_string($table) && preg_match('/^[a-z0-9_]{1,64}$/', $table) === 1,
        );

        return array_values(array_unique(array_merge(self::TABLES, $valid)));
    }
}
