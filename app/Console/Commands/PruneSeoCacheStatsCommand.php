<?php

namespace App\Console\Commands;

use App\Seo\SeoCacheStatsService;
use Illuminate\Console\Command;

/**
 * 보존 기간이 지난 SEO 캐시 통계를 정리하는 커맨드
 *
 * 통계 행은 봇 요청마다 누적되므로 정리 주체가 없으면 무한히 성장합니다.
 */
class PruneSeoCacheStatsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'seo:prune-stats {--days=30 : 보존 기간(일)}';

    /**
     * The console command description.
     */
    protected $description = '보존 기간이 지난 SEO 캐시 통계 기록을 삭제합니다';

    /**
     * Execute the console command.
     *
     * 파기 자체는 도메인 서비스가 수행합니다 — 커맨드는 옵션만 전달하고,
     * 보존 기간 하한은 그 계층이 소유합니다.
     *
     * @param  SeoCacheStatsService  $service  SEO 캐시 통계 서비스
     * @return int 명령 실행 결과 코드
     */
    public function handle(SeoCacheStatsService $service): int
    {
        $days = (int) $this->option('days');
        $deleted = $service->cleanup($days);

        $this->info("SEO 캐시 통계 {$deleted}건이 삭제되었습니다. (보존 {$days}일)");

        return self::SUCCESS;
    }
}
