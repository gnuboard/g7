<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use Carbon\CarbonImmutable;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\BoardStat;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Services\BoardDashboardService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * AggregateBoardStatsCommand Feature 테스트
 *
 * php artisan sirsoft-board:aggregate-stats [--dry-run]
 */
class AggregateBoardStatsCommandTest extends ModuleTestCase
{
    private Board $board;

    /**
     * 집계 표를 비우고 시험용 게시판을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 집계의 「오늘」은 사이트 표준시의 하루다(siteToday). 사이트 날짜와 UTC 날짜가 같은 시각으로
        // 고정해 실행 시각과 무관하게 같은 결과를 낸다 — 날짜 경계 자체는 BoardSiteDayBoundaryTest 가 잰다.
        $this->travelTo(CarbonImmutable::parse('2030-01-15 03:00:00', 'UTC'));

        BoardStat::query()->delete();

        $this->board = Board::create([
            'name' => ['ko' => '커맨드 테스트', 'en' => 'Command Test'],
            'slug' => 'command-test-'.uniqid(),
            'type' => 'basic',
        ]);
    }

    /**
     * 게시글을 생성합니다.
     *
     * @param  bool|null  $trashed  만든 뒤 삭제 상태로 둘지 여부
     * @return Post 생성된 게시글
     */
    private function makePost(?bool $trashed = false): Post
    {
        $post = Post::create([
            'board_id' => $this->board->id,
            'title' => '글',
            'content' => '내용',
            'ip_address' => '127.0.0.1',
        ]);

        if ($trashed) {
            $post->delete();
        }

        return $post;
    }

    /**
     * 댓글을 생성합니다.
     *
     * @param  int  $postId  게시글 ID
     * @return Comment 생성된 댓글
     */
    private function makeComment(int $postId): Comment
    {
        return Comment::create([
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'content' => '댓글',
            'ip_address' => '127.0.0.1',
        ]);
    }

    /**
     * --dry-run 은 집계 행을 저장하지 않는지 확인합니다.
     *
     * @scenario module_state=active, data_state=no_posts, user_role=admin_with_dashboard_permission
     *
     * @effects post_graph_updated_at_caption_hidden_when_no_aggregated_rows, aggregate_command_dry_run_persists_nothing
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_dry_run_does_not_persist_any_row(): void
    {
        $this->makePost();
        $this->makeComment($this->makePost()->id);

        $this->artisan('sirsoft-board:aggregate-stats', ['--dry-run' => true])
            ->expectsOutputToContain('[dry-run]')
            ->assertSuccessful();

        $this->assertSame(0, BoardStat::count());
    }

    /**
     * 실행하면 7일치 행을 만들고 사이트 하루 「오늘」 행에 새 글·댓글 수가 맞게 들어가는지 확인합니다.
     *
     * @scenario module_state=active, data_state=has_posts, user_role=admin_with_dashboard_permission
     *
     * @effects today_posts_badge_reflects_overview_api, today_comments_badge_reflects_overview_api, board_stats_aggregate_upserts_recent_days_only
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_real_run_upserts_7_days_with_correct_today_counts(): void
    {
        // baseline: 트랜잭션 외부에 이전 테스트/수동 실행 잔존 게시글이 있을 수 있어
        // 절대값이 아닌 증가량으로 검증한다 (board_posts/board_comments 는 단일 테이블).
        // 집계 행의 「오늘」은 사이트 표준시의 하루다(7.1.0 O-CF3) — 같은 하루를 UTC 범위로 풀어 센다.
        $siteToday = app(BoardDashboardService::class)->siteToday();
        $range = [$siteToday->utc(), $siteToday->addDay()->utc()];
        $baselinePosts = Post::query()->whereNull('deleted_at')
            ->where('created_at', '>=', $range[0])->where('created_at', '<', $range[1])->count();
        $baselineComments = Comment::query()->whereNull('deleted_at')
            ->where('created_at', '>=', $range[0])->where('created_at', '<', $range[1])->count();

        // 오늘 게시글 2건(1건 삭제 — 제외돼야), 댓글 1건
        $p = $this->makePost();
        $this->makePost(trashed: true);
        $this->makeComment($p->id);

        $this->artisan('sirsoft-board:aggregate-stats')
            ->expectsOutputToContain('집계 완료')
            ->assertSuccessful();

        // 7일치 행 생성
        $this->assertSame(7, BoardStat::count());

        // 오늘 행: baseline + 추가(삭제 제외 게시글 1, 댓글 1)
        $todayRow = BoardStat::where('date', $siteToday->toDateString())->first();
        $this->assertSame($baselinePosts + 1, $todayRow->post_count);
        $this->assertSame($baselineComments + 1, $todayRow->comment_count);
    }

    /**
     * 8일 전 행은 건드리지 않는지 확인합니다.
     *
     * @scenario module_state=active, data_state=has_previous_week, user_role=admin_with_dashboard_permission
     *
     * @effects post_graph_chart_renders_7_days_from_post_graph_api, board_stats_aggregate_upserts_recent_days_only
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_real_run_preserves_8_days_old_row(): void
    {
        $eightDaysAgo = app(BoardDashboardService::class)->siteToday()->subDays(8)->toDateString();
        BoardStat::create(['date' => $eightDaysAgo, 'post_count' => 99, 'comment_count' => 99]);

        $this->artisan('sirsoft-board:aggregate-stats')->assertSuccessful();

        $oldRow = BoardStat::where('date', $eightDaysAgo)->first();
        $this->assertSame(99, $oldRow->post_count);
        $this->assertSame(99, $oldRow->comment_count);

        // 7일치 신규 + 8일전 1행 = 8행
        $this->assertSame(8, BoardStat::count());
    }

    /**
     * 반복 실행해도 행이 늘지 않는지 확인합니다.
     *
     * @scenario module_state=active, data_state=no_posts, user_role=admin_with_dashboard_permission
     *
     * @effects post_graph_chart_renders_7_days_from_post_graph_api, board_stats_aggregate_idempotent
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_repeated_runs_are_idempotent(): void
    {
        $this->artisan('sirsoft-board:aggregate-stats')->assertSuccessful();
        $this->artisan('sirsoft-board:aggregate-stats')->assertSuccessful();
        $this->artisan('sirsoft-board:aggregate-stats')->assertSuccessful();

        $this->assertSame(7, BoardStat::count());
    }
}
