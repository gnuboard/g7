<?php

namespace Modules\Sirsoft\Board\Tests\Unit;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use Carbon\CarbonImmutable;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\BoardStat;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Services\BoardDashboardService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 게시판 대시보드 서비스 테스트
 */
class BoardDashboardServiceTest extends ModuleTestCase
{
    private BoardDashboardService $service;

    private Board $board;

    protected function setUp(): void
    {
        parent::setUp();

        // DatabaseTransactions 는 테스트 시작 전부터 있던 데이터를 정리하지 못한다.
        // board_stats 는 전역 단일 집계 테이블이고, 같은 PHPUnit 스위트의 다른 테스트가
        // 트랜잭션 외부로 board_posts/comments/reports 잔여 행을 남겼을 수 있다.
        // 본 테스트는 "전체 게시판 across boards" 카운트를 단언하므로 모두 명시 초기화한다.
        Report::query()->forceDelete();
        Comment::query()->forceDelete();
        Post::query()->forceDelete();
        BoardStat::query()->delete();

        $this->service = $this->app->make(BoardDashboardService::class);
        $this->board = Board::create([
            'name' => ['ko' => '테스트 게시판', 'en' => 'Test Board'],
            'slug' => 'dashboard-test-'.uniqid(),
            'type' => 'basic',
        ]);
    }

    /**
     * 게시글을 생성합니다 (생성일 지정 가능).
     */
    private function makePost(string $title, ?string $createdAt = null, bool $trashed = false): Post
    {
        $post = Post::create([
            'board_id' => $this->board->id,
            'title' => $title,
            'content' => '내용',
            'ip_address' => '127.0.0.1',
        ]);

        if ($createdAt !== null) {
            $post->forceFill(['created_at' => $createdAt])->saveQuietly();
        }
        if ($trashed) {
            $post->delete();
        }

        return $post;
    }

    /**
     * 댓글을 생성합니다.
     */
    private function makeComment(int $postId, ?string $createdAt = null, bool $trashed = false): Comment
    {
        $comment = Comment::create([
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'content' => '댓글',
            'ip_address' => '127.0.0.1',
        ]);

        if ($createdAt !== null) {
            $comment->forceFill(['created_at' => $createdAt])->saveQuietly();
        }
        if ($trashed) {
            $comment->delete();
        }

        return $comment;
    }

    #[Test]
    public function test_get_overview_reads_today_row_from_board_stats(): void
    {
        $today = CarbonImmutable::today()->toDateString();
        BoardStat::create(['date' => $today, 'post_count' => 3, 'comment_count' => 8]);

        $overview = $this->service->getOverview();

        $this->assertSame(3, $overview['today_posts']);
        $this->assertSame(8, $overview['today_comments']);
    }

    #[Test]
    public function test_get_overview_returns_zero_when_today_row_missing(): void
    {
        $overview = $this->service->getOverview();

        $this->assertSame(0, $overview['today_posts']);
        $this->assertSame(0, $overview['today_comments']);
    }

    #[Test]
    public function test_get_post_graph_returns_current_7_day_bars_and_totals(): void
    {
        $today = CarbonImmutable::today();
        for ($i = 0; $i < 7; $i++) {
            BoardStat::create([
                'date' => $today->subDays($i)->toDateString(),
                'post_count' => 2,
                'comment_count' => 3,
            ]);
        }

        $graph = $this->service->getPostGraph(7);

        $this->assertCount(7, $graph['days']);
        $this->assertSame(14, $graph['total_posts']);
        $this->assertSame(21, $graph['total_comments']);
        // 막대는 날짜 오름차순
        $this->assertSame($today->subDays(6)->toDateString(), $graph['days'][0]['date']);
        $this->assertSame($today->toDateString(), $graph['days'][6]['date']);
    }

    #[Test]
    public function test_post_graph_change_rate_is_calculated_vs_previous_7_days(): void
    {
        $today = CarbonImmutable::today();
        // 이번 7일: 게시글 합 14
        for ($i = 0; $i < 7; $i++) {
            BoardStat::create(['date' => $today->subDays($i)->toDateString(), 'post_count' => 2, 'comment_count' => 0]);
        }
        // 직전 7일(8~14일 전): 게시글 합 7
        for ($i = 7; $i < 14; $i++) {
            BoardStat::create(['date' => $today->subDays($i)->toDateString(), 'post_count' => 1, 'comment_count' => 0]);
        }

        $graph = $this->service->getPostGraph(7);

        // (14 - 7) / 7 * 100 = 100.0
        $this->assertSame(100.0, $graph['posts_change']);
    }

    #[Test]
    public function test_post_graph_change_rate_is_null_when_no_previous_data(): void
    {
        $today = CarbonImmutable::today();
        for ($i = 0; $i < 7; $i++) {
            BoardStat::create(['date' => $today->subDays($i)->toDateString(), 'post_count' => 2, 'comment_count' => 3]);
        }

        $graph = $this->service->getPostGraph(7);

        $this->assertNull($graph['posts_change']);
        $this->assertNull($graph['comments_change']);
    }

    #[Test]
    public function test_post_graph_updated_at_display_is_empty_when_no_rows(): void
    {
        // board_stats 가 비어 있으면 updated_at 은 null 이고 표시 문자열은 빈 문자열이어야 한다.
        // 캡션은 빈 문자열을 falsy 로 평가해 노출되지 않는다 (레이아웃 if 조건 회귀 가드).
        $graph = $this->service->getPostGraph(7);

        $this->assertNull($graph['updated_at']);
        $this->assertSame('', $graph['updated_at_display']);
    }

    #[Test]
    public function test_post_graph_updated_at_display_uses_board_date_format(): void
    {
        // FormatsBoardDate 표준형 규칙:
        //   - 24시간 이상 차이: 올해는 'MM-DD', 작년 이전은 'YY-MM-DD'
        // 캡션이 게시판 날짜 표시 규칙과 일관되어야 한다는 회귀 가드.
        // (게시글 created_at 표시와 동일 컨벤션 — 시각 검증 피드백)
        $today = CarbonImmutable::today();
        // 어제 자정에 마지막 스케줄러가 돈 상황을 시뮬레이션
        $yesterdayBoundary = $today->subDay()->setTime(0, 0, 0);
        $row = BoardStat::create([
            'date' => $today->subDay()->toDateString(),
            'post_count' => 1,
            'comment_count' => 0,
        ]);
        $row->forceFill(['updated_at' => $yesterdayBoundary->toDateTimeString()])->saveQuietly();

        $graph = $this->service->getPostGraph(7);

        // 캡션은 비어 있지 않아야 하고, 시각만 'HH:MM' 으로 잘라 쓰던 기존 동작과 달라야 한다.
        $this->assertNotSame('', $graph['updated_at_display']);
        // 표준형 표시 규칙: 24시간 이상 차이 → 'MM-DD' (또는 작년 이전이면 'YY-MM-DD')
        // 어제 자정은 정확히 24시간 경계라 항상 day-format 으로 떨어진다.
        $this->assertMatchesRegularExpression(
            '/^\d{2}-\d{2}(\s\d{2}:\d{2})?$|^\d{2}-\d{2}-\d{2}$/',
            $graph['updated_at_display'],
        );
    }

    #[Test]
    public function test_get_recent_posts_returns_non_deleted_posts_ordered_by_latest(): void
    {
        $this->makePost('오래된 글', CarbonImmutable::today()->subDays(2)->toDateTimeString());
        $this->makePost('삭제된 글', null, trashed: true);
        $latest = $this->makePost('최신 글');

        $posts = $this->service->getRecentPosts(5);

        $this->assertCount(2, $posts);
        $this->assertSame($latest->id, $posts->first()->id);
    }

    #[Test]
    public function test_get_recent_posts_excludes_replies(): void
    {
        // 대시보드 "최신 게시글" 카드는 본문 글만 보여줘야 한다 (답글 Re: 제외).
        // 시각 검증 피드백: "최신 게시글이 최신순으로 안 나오는 것 같다" — 원인은 답글이 섞여 표시된 것.
        // PostRepository::getRecentAcrossBoards 가 whereNull('parent_id') 누락했던 회귀 가드.
        $parent = $this->makePost('원글');
        $reply = $this->makePost('Re: 원글');
        $reply->forceFill(['parent_id' => $parent->id])->saveQuietly();

        $posts = $this->service->getRecentPosts(5);

        $this->assertCount(1, $posts);
        $this->assertSame($parent->id, $posts->first()->id);
    }

    #[Test]
    public function test_get_pending_reports_returns_items_and_total(): void
    {
        Report::create(['board_id' => $this->board->id, 'target_type' => 'post', 'target_id' => 1, 'status' => ReportStatus::Pending, 'last_reported_at' => now()]);
        Report::create(['board_id' => $this->board->id, 'target_type' => 'comment', 'target_id' => 2, 'status' => ReportStatus::Review, 'last_reported_at' => now()]);
        Report::create(['board_id' => $this->board->id, 'target_type' => 'post', 'target_id' => 3, 'status' => ReportStatus::Rejected, 'last_reported_at' => now()]);

        $result = $this->service->getPendingReports(5);

        // Pending + Review 만 (Rejected 제외)
        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['items']);
    }

    #[Test]
    public function test_aggregate_recent_days_upserts_only_recent_7_days_preserving_older(): void
    {
        $today = CarbonImmutable::today();

        // 8일 전 기존 행 (보존되어야 함)
        $eightDaysAgo = $today->subDays(8)->toDateString();
        BoardStat::create(['date' => $eightDaysAgo, 'post_count' => 99, 'comment_count' => 99]);

        // 오늘 게시글 2건(1건 삭제), 댓글 1건
        $p = $this->makePost('오늘1');
        $this->makePost('오늘2', null, trashed: true);
        $this->makeComment($p->id);

        $this->service->aggregateRecentDays(7);

        // 오늘 행: 삭제 제외 게시글 1, 댓글 1
        $todayRow = BoardStat::where('date', $today->toDateString())->first();
        $this->assertSame(1, $todayRow->post_count);
        $this->assertSame(1, $todayRow->comment_count);

        // 8일 전 행은 변경되지 않음
        $oldRow = BoardStat::where('date', $eightDaysAgo)->first();
        $this->assertSame(99, $oldRow->post_count);

        // 7일치 + 8일전 1행 = 8행
        $this->assertSame(8, BoardStat::count());
    }

    #[Test]
    public function test_aggregate_recent_days_is_idempotent_on_rerun(): void
    {
        $this->service->aggregateRecentDays(7);
        $this->service->aggregateRecentDays(7);

        $this->assertSame(7, BoardStat::count());
    }
}
