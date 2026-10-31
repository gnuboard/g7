<?php

namespace App\Console\Commands\Analytics;

use App\Console\Commands\Traits\HasUnifiedConfirm;
use App\Services\Analytics\PurgeService;
use Illuminate\Console\Command;

/**
 * 방문자 통계 전체 삭제 커맨드
 *
 * 쌓인 방문 기록과 집계를 모두 지웁니다. 되돌릴 수 없으므로 --confirm 을 반드시
 * 붙여야 하고, 대화형 실행에서는 한 번 더 묻습니다.
 *
 * @example php artisan analytics:purge --confirm
 */
class PurgeCommand extends Command
{
    use HasUnifiedConfirm;

    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'analytics:purge {--confirm : 되돌릴 수 없음을 확인}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '방문자 통계 기록과 집계를 모두 삭제합니다 (되돌릴 수 없음).';

    /**
     * 커맨드 실행
     *
     * @param  PurgeService  $service  삭제 서비스
     * @return int 종료 코드
     */
    public function handle(PurgeService $service): int
    {
        if (! $this->option('confirm')) {
            $this->error(__('analytics.console.purge.confirm_option_required'));

            return self::FAILURE;
        }

        if (! $this->unifiedConfirm(__('analytics.console.purge.confirm'))) {
            $this->info(__('analytics.console.purge.cancelled'));

            return self::SUCCESS;
        }

        $counts = $service->purgeAll();

        $this->table(
            [__('analytics.console.purge.headers.target'), __('analytics.console.purge.headers.deleted')],
            array_map(fn (string $key, int $count): array => [$key, $count], array_keys($counts), $counts),
        );

        $this->info(__('analytics.console.purge.done', ['count' => array_sum($counts)]));

        return self::SUCCESS;
    }
}
