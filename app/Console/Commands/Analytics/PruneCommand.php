<?php

namespace App\Console\Commands\Analytics;

use App\Analytics\AnalyticsConfig;
use App\Console\Commands\Traits\ParsesDaysOption;
use App\Services\Analytics\PruneService;
use Illuminate\Console\Command;

/**
 * 방문자 통계 보존 정리 커맨드
 *
 * 보존 기간이 지난 원장과 부속 기록을 지웁니다. 집계 표는 남으므로 과거 숫자는
 * 그대로 보입니다.
 *
 * 파기 자체와 보존 기간 하한은 서비스가 소유합니다 — 커맨드는 옵션만 넘깁니다.
 *
 * @example php artisan analytics:prune --dry-run
 * @example php artisan analytics:prune --days=30
 */
class PruneCommand extends Command
{
    use ParsesDaysOption;

    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'analytics:prune
                            {--days= : 보존기간(일) 재정의 — 미지정 시 환경설정값}
                            {--dry-run : 삭제 없이 대상 건수만 확인}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '보존 기간이 지난 방문자 통계 원장을 정리합니다.';

    /**
     * 커맨드 실행
     *
     * @param  PruneService  $service  정리 서비스
     * @return int 종료 코드
     */
    public function handle(PruneService $service): int
    {
        $days = $this->parseDaysOption();

        if ($days === false) {
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $counts = $service->prune(AnalyticsConfig::fromSettings(), $days, $dryRun);

        $this->table(
            [__('analytics.console.prune.headers.target'), $dryRun ? __('analytics.console.prune.headers.to_delete') : __('analytics.console.prune.headers.deleted')],
            array_map(fn (string $key, int $count): array => [$key, $count], array_keys($counts), $counts),
        );

        $this->info($dryRun
            ? __('analytics.console.prune.dry_run')
            : __('analytics.console.prune.done', ['count' => array_sum($counts)]));

        return self::SUCCESS;
    }
}
