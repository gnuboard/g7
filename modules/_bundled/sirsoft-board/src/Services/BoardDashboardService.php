<?php

namespace Modules\Sirsoft\Board\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardStatRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\CommentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\ReportRepositoryInterface;
use Modules\Sirsoft\Board\Traits\FormatsBoardDate;

/**
 * 게시판 대시보드 서비스
 *
 * 관리자 대시보드의 게시판 영역을 위한 조회/집계 로직을 담당합니다.
 *
 * - 조회(overview/post-graph)는 집계 테이블 board_stats 만 읽어 원본 풀스캔을 피합니다.
 * - 집계(aggregateRecentDays)는 스케쥴러가 1시간마다 호출해 최근 N일치를 upsert 합니다.
 */
class BoardDashboardService
{
    use FormatsBoardDate;

    /**
     * @param  BoardStatRepositoryInterface  $boardStatRepository  일별 집계 Repository
     * @param  PostRepositoryInterface  $postRepository  게시글 Repository
     * @param  CommentRepositoryInterface  $commentRepository  댓글 Repository
     * @param  ReportRepositoryInterface  $reportRepository  신고 Repository
     * @param  ReportService  $reportService  신고 표시용 reportable 데이터 빌더
     */
    public function __construct(
        private readonly BoardStatRepositoryInterface $boardStatRepository,
        private readonly PostRepositoryInterface $postRepository,
        private readonly CommentRepositoryInterface $commentRepository,
        private readonly ReportRepositoryInterface $reportRepository,
        private readonly ReportService $reportService,
    ) {}

    /**
     * 오늘 새 글/새 댓글 수를 집계 테이블에서 조회합니다.
     *
     * board_stats 의 오늘 행을 읽으며, 행이 없으면 0 으로 반환합니다 (최대 1시간 지연 허용).
     *
     * @return array{today_posts: int, today_comments: int} 오늘 집계
     */
    public function getOverview(): array
    {
        $today = CarbonImmutable::today()->toDateString();
        $row = $this->boardStatRepository->findByDate($today);

        return [
            'today_posts' => $row?->post_count ?? 0,
            'today_comments' => $row?->comment_count ?? 0,
        ];
    }

    /**
     * 7일 막대그래프 + 7일 합계 + 직전 7일 대비 변화율을 조회합니다.
     *
     * board_stats 최근 (graphDays * 2) 행을 읽어 이번 기간(차트/합계)과
     * 직전 동일 기간(변화율 비교)을 한 번에 계산합니다. 직전 기간 합이 0 이면
     * 변화율을 null 로 반환합니다 (화면에서 '—' 폴백).
     *
     * @param  int  $graphDays  차트 표시 일수 (기본 7)
     * @return array{
     *     days: array<int, array{date: string, post_count: int, comment_count: int}>,
     *     total_posts: int,
     *     total_comments: int,
     *     posts_change: float|null,
     *     comments_change: float|null,
     *     updated_at: string|null,
     *     updated_at_display: string
     * } 그래프 데이터
     */
    public function getPostGraph(int $graphDays = 7): array
    {
        $today = CarbonImmutable::today();
        $currentStart = $today->subDays($graphDays - 1);
        $previousStart = $today->subDays($graphDays * 2 - 1);
        $previousEnd = $currentStart->subDay();

        $currentRows = $this->boardStatRepository->getByDateRange(
            $currentStart->toDateString(),
            $today->toDateString(),
        );
        $previousRows = $this->boardStatRepository->getByDateRange(
            $previousStart->toDateString(),
            $previousEnd->toDateString(),
        );

        $indexed = $currentRows->keyBy(fn ($row) => $row->date->toDateString());

        $days = [];
        for ($i = 0; $i < $graphDays; $i++) {
            $date = $currentStart->addDays($i)->toDateString();
            $row = $indexed->get($date);
            $days[] = [
                'date' => $date,
                'post_count' => $row?->post_count ?? 0,
                'comment_count' => $row?->comment_count ?? 0,
            ];
        }

        $totalPosts = (int) $currentRows->sum('post_count');
        $totalComments = (int) $currentRows->sum('comment_count');
        $prevPosts = (int) $previousRows->sum('post_count');
        $prevComments = (int) $previousRows->sum('comment_count');

        $updatedAt = $this->resolveUpdatedAt($currentRows);

        return [
            'days' => $days,
            'total_posts' => $totalPosts,
            'total_comments' => $totalComments,
            'posts_change' => $this->calculateChangeRate($totalPosts, $prevPosts),
            'comments_change' => $this->calculateChangeRate($totalComments, $prevComments),
            'updated_at' => $updatedAt,
            'updated_at_display' => $this->formatCreatedAtFormat(
                $updatedAt,
                g7_module_settings('sirsoft-board', 'display.date_display_format', 'standard'),
            ),
        ];
    }

    /**
     * 전체 게시판의 최신 게시글을 조회합니다.
     *
     * @param  int  $limit  조회 건수
     * @return Collection 최신 게시글 컬렉션
     */
    public function getRecentPosts(int $limit): Collection
    {
        return $this->postRepository->getRecentAcrossBoards($limit);
    }

    /**
     * 전체 게시판의 미처리 신고 목록과 총 건수를 조회합니다.
     *
     * 각 Report 모델에 ReportService::buildReportableData() 결과를 `reportableData`
     * public property 로 주입하여 Resource 가 신고 대상의 제목/본문을 노출할 수 있게 합니다.
     * 기존 Admin/ReportController 와 동일한 패턴이라 표시 일관성을 보장합니다.
     *
     * @param  int  $limit  조회 건수
     * @return array{items: Collection, total: int} 미처리 신고
     */
    public function getPendingReports(int $limit): array
    {
        $items = $this->reportRepository->getPendingAcrossBoards($limit);
        foreach ($items as $report) {
            $report->reportableData = $this->reportService->buildReportableData($report);
        }

        return [
            'items' => $items,
            'total' => $this->reportRepository->countPendingAcrossBoards(),
        ];
    }

    /**
     * 최근 N일치(오늘 포함) 집계 행을 board_stats 에 upsert 합니다.
     *
     * 그 이전 날짜 행은 건드리지 않아 과거 추세가 보존됩니다. date unique 로 멱등합니다.
     *
     * @param  int  $days  집계 대상 일수 (오늘 포함, 기본 7)
     * @return array<int, array{date: string, post_count: int, comment_count: int}> 갱신된 집계 목록
     */
    public function aggregateRecentDays(int $days = 7): array
    {
        $today = CarbonImmutable::today();
        $result = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $today->subDays($i)->toDateString();
            $postCount = $this->postRepository->countCreatedOnDate($date);
            $commentCount = $this->commentRepository->countCreatedOnDate($date);

            $this->boardStatRepository->upsertForDate($date, $postCount, $commentCount);

            $result[] = [
                'date' => $date,
                'post_count' => $postCount,
                'comment_count' => $commentCount,
            ];
        }

        return $result;
    }

    /**
     * 직전 기간 대비 증감율(%)을 계산합니다.
     *
     * 직전 기간 합이 0 이면 비교 기준이 없으므로 null 을 반환합니다.
     *
     * @param  int  $current  이번 기간 합계
     * @param  int  $previous  직전 기간 합계
     * @return float|null 소수점 첫째 자리 증감율 또는 null
     */
    private function calculateChangeRate(int $current, int $previous): ?float
    {
        if ($previous === 0) {
            return null;
        }

        return round((($current - $previous) / $previous) * 100, 1);
    }

    /**
     * 집계 행들의 마지막 갱신 시각(MAX updated_at)을 ISO 문자열로 반환합니다.
     *
     * @param  Collection  $rows  집계 행 컬렉션
     * @return string|null 마지막 갱신 시각 또는 null
     */
    private function resolveUpdatedAt(Collection $rows): ?string
    {
        $max = $rows->max('updated_at');

        return $max?->toIso8601String();
    }
}
