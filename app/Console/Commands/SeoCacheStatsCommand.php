<?php

namespace App\Console\Commands;

use App\Seo\SeoCacheStatsService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * SEO 캐시 통계 출력 Artisan 커맨드
 *
 * 지정된 기간 동안의 캐시 히트/미스 통계를 테이블 형식으로 출력합니다.
 */
class SeoCacheStatsCommand extends Command
{
    /**
     * @var string 커맨드 시그니처
     */
    protected $signature = 'seo:stats {--days=7 : 통계 기간 (일)}';

    /**
     * @var string 커맨드 설명
     */
    protected $description = 'SEO 캐시 통계 출력';

    /**
     * 커맨드를 실행합니다.
     *
     * @param SeoCacheStatsService $statsService SEO 캐시 통계 서비스
     * @return int 종료 코드
     */
    public function handle(SeoCacheStatsService $statsService): int
    {
        $days = (int) $this->option('days');
        $since = Carbon::now()->subDays($days);

        $this->info(__('seo.stats_period', ['days' => $days]));
        $this->newLine();

        // 전체 통계
        $overall = $statsService->getStats($since);
        $this->info('=== ' . __('seo.stats_overall') . ' ===');
        $this->table(
            [__('seo.stats_metric'), __('seo.stats_value')],
            [
                [__('seo.stats_total_entries'), $overall['total_entries']],
                [__('seo.stats_hits'), $overall['hits']],
                [__('seo.stats_misses'), $overall['misses']],
                [__('seo.stats_hit_rate'), $overall['hit_rate'] . '%'],
                [__('seo.stats_avg_response_time'), $overall['avg_response_time_ms'] !== null ? $overall['avg_response_time_ms'] . 'ms' : 'N/A'],
            ]
        );

        $this->newLine();

        // 레이아웃별 통계
        $byLayout = $statsService->getStatsByLayout($since);
        if (! empty($byLayout)) {
            $this->info('=== ' . __('seo.stats_by_layout') . ' ===');
            $this->table(
                [__('seo.stats_layout_name'), __('seo.stats_total'), __('seo.stats_hits'), __('seo.stats_misses'), __('seo.stats_hit_rate'), __('seo.stats_avg_response_time')],
                array_map(fn ($row) => [
                    $row['layout_name'] ?? 'N/A',
                    $row['total'],
                    $row['hits'],
                    $row['misses'],
                    $row['hit_rate'] . '%',
                    $row['avg_response_time_ms'] !== null ? $row['avg_response_time_ms'] . 'ms' : 'N/A',
                ], $byLayout)
            );
        }

        $this->newLine();

        // 모듈별 통계
        $byModule = $statsService->getStatsByModule($since);
        if (! empty($byModule)) {
            $this->info('=== ' . __('seo.stats_by_module') . ' ===');
            $this->table(
                [__('seo.stats_module_identifier'), __('seo.stats_total'), __('seo.stats_hits'), __('seo.stats_misses'), __('seo.stats_hit_rate'), __('seo.stats_avg_response_time')],
                array_map(fn ($row) => [
                    $row['module_identifier'] ?? 'N/A',
                    $row['total'],
                    $row['hits'],
                    $row['misses'],
                    $row['hit_rate'] . '%',
                    $row['avg_response_time_ms'] !== null ? $row['avg_response_time_ms'] . 'ms' : 'N/A',
                ], $byModule)
            );
        }

        return Command::SUCCESS;
    }
}
