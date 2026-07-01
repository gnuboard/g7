<?php

namespace Modules\Sirsoft\Board\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Sirsoft\Board\Repositories\Contracts\CommentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Services\BoardDashboardService;

/**
 * 게시판 일별 현황 집계 커맨드
 *
 * 최근 7일치(오늘 포함) 게시글/댓글 수를 board_stats 테이블에 upsert 합니다.
 * 스케줄러(hourly)에서 주기적으로 실행하며, 대시보드 조회 API 는 이 테이블만 읽습니다.
 *
 * @example php artisan sirsoft-board:aggregate-stats
 * @example php artisan sirsoft-board:aggregate-stats --dry-run
 */
class AggregateBoardStatsCommand extends Command
{
    /**
     * 집계 대상 일수 (오늘 포함 최근 N일)
     */
    private const AGGREGATE_DAYS = 7;

    /**
     * 커맨드 이름 및 시그니처
     *
     * @var string
     */
    protected $signature = 'sirsoft-board:aggregate-stats
                            {--dry-run : 실제 저장 없이 대상 날짜와 예상 카운트만 출력}';

    /**
     * 커맨드 설명
     *
     * @var string
     */
    protected $description = '최근 7일치 게시글/댓글 현황을 board_stats 테이블에 집계합니다.';

    /**
     * @param  BoardDashboardService  $dashboardService  대시보드 집계 서비스
     * @param  PostRepositoryInterface  $postRepository  게시글 Repository
     * @param  CommentRepositoryInterface  $commentRepository  댓글 Repository
     */
    public function __construct(
        private readonly BoardDashboardService $dashboardService,
        private readonly PostRepositoryInterface $postRepository,
        private readonly CommentRepositoryInterface $commentRepository,
    ) {
        parent::__construct();
    }

    /**
     * 커맨드 실행
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        if ($isDryRun) {
            return $this->runDryRun();
        }

        $rows = $this->dashboardService->aggregateRecentDays(self::AGGREGATE_DAYS);

        $this->info(sprintf('게시판 현황 집계 완료: 최근 %d일치 %d개 행 갱신', self::AGGREGATE_DAYS, count($rows)));
        $this->table(['날짜', '게시글', '댓글'], array_map(
            fn (array $row) => [$row['date'], $row['post_count'], $row['comment_count']],
            $rows,
        ));

        return Command::SUCCESS;
    }

    /**
     * dry-run: 저장 없이 대상 날짜와 예상 카운트만 출력합니다.
     *
     * @return int 종료 코드
     */
    private function runDryRun(): int
    {
        $today = CarbonImmutable::today();
        $preview = [];

        for ($i = 0; $i < self::AGGREGATE_DAYS; $i++) {
            $date = $today->subDays($i)->toDateString();
            $preview[] = [
                $date,
                $this->postRepository->countCreatedOnDate($date),
                $this->commentRepository->countCreatedOnDate($date),
            ];
        }

        $this->info(sprintf('[dry-run] 집계 대상 최근 %d일 (저장하지 않음):', self::AGGREGATE_DAYS));
        $this->table(['날짜', '게시글', '댓글'], $preview);

        return Command::SUCCESS;
    }
}