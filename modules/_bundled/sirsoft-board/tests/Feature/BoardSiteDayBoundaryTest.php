<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\BoardStat;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Models\ReportLog;
use Modules\Sirsoft\Board\Repositories\ReportRepository;
use Modules\Sirsoft\Board\Services\BoardDashboardService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 게시판의 「하루」 경계를 검증합니다 — 대시보드 일 집계는 사이트 표준시, 일일 신고 한도는 신고자의 계정 시간대
 * (없으면 사이트 기본 시간대). 이 시험의 신고자는 계정 시간대가 없어 사이트 표준시로 잰다.
 *
 * 저장값은 UTC 이고, 앱 시간대(`app.timezone`)도 UTC 다. 「오늘」을 앱 시간대로 자르면
 * 한국 사이트에서는 오전 9시에 하루가 바뀐다 — 일일 신고 한도가 오전 9시에 풀리고(「오늘 신고
 * 가능 횟수」 문구와 어긋난다), 게시판 대시보드의 일 집계가 새벽 글을 전날로 센다(K2 실측
 * 2026-10-06, 주 세션 관측 O-CF3). 사이트 표준시(`general.timezone` → `app.default_user_timezone`,
 * 스케줄러도 같은 값 `app.schedule_timezone`)로 자른다.
 */
#[Group('board')]
class BoardSiteDayBoundaryTest extends ModuleTestCase
{
    private Board $board;

    private User $reporter;

    /**
     * 사이트 표준시를 서울로 두고 집계 테이블을 비운다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.timezone', 'UTC');
        Config::set('app.default_user_timezone', 'Asia/Seoul');

        // board_stats 는 전역 집계 테이블이라 다른 테스트 잔여 행이 섞이지 않게 비운다(대시보드 테스트와 같은 이유).
        DB::table('boards_report_logs')->delete();
        DB::table('boards_reports')->delete();
        Comment::query()->forceDelete();
        Post::query()->forceDelete();
        BoardStat::query()->delete();

        $this->board = Board::create([
            'name' => ['ko' => '하루 경계 테스트', 'en' => 'Day Boundary Test'],
            'slug' => 'site-day-'.uniqid(),
            'type' => 'basic',
        ]);
        $this->reporter = User::factory()->create();
    }

    /**
     * 일일 신고 건수는 사이트 표준시의 하루로 센다 — 서울 새벽 1시에 한 신고도 그날 건이다.
     *
     * @scenario boundary=report_daily_limit, clock=evening_same_utc_day
     *
     * @effects daily_report_count_uses_site_day
     *
     * @return void 반환값 없음
     */
    public function test_today_report_count_includes_site_dawn_and_excludes_site_yesterday(): void
    {
        // 지금: UTC 2026-10-06 13:00 = 서울 2026-10-06 22:00
        $this->travelTo(CarbonImmutable::parse('2026-10-06 13:00:00', 'UTC'));

        $this->seedReportLog('2026-10-05 16:00:00'); // 서울 10-06 01:00 — 오늘
        $this->seedReportLog('2026-10-06 12:00:00'); // 서울 10-06 21:00 — 오늘
        $this->seedReportLog('2026-10-05 14:00:00'); // 서울 10-05 23:00 — 어제

        $this->assertSame(2, (new ReportRepository)->countTodayReportsByUser($this->reporter->id));
    }

    /**
     * 사이트 자정이 지나면 UTC 로는 아직 같은 날이어도 새 하루로 센다.
     *
     * @scenario boundary=report_daily_limit, clock=after_site_midnight
     *
     * @effects daily_report_count_resets_at_site_midnight
     *
     * @return void 반환값 없음
     */
    public function test_today_report_count_resets_at_site_midnight(): void
    {
        // 지금: UTC 2026-10-06 16:30 = 서울 2026-10-07 01:30
        $this->travelTo(CarbonImmutable::parse('2026-10-06 16:30:00', 'UTC'));

        $this->seedReportLog('2026-10-06 13:00:00'); // 서울 10-06 22:00 — 어제
        $this->seedReportLog('2026-10-06 15:10:00'); // 서울 10-07 00:10 — 오늘

        $this->assertSame(1, (new ReportRepository)->countTodayReportsByUser($this->reporter->id));
    }

    /**
     * 대시보드 일 집계는 글을 사이트 표준시의 날짜에 센다.
     *
     * @scenario boundary=board_stats, clock=evening_same_utc_day
     *
     * @effects board_stats_counts_posts_on_site_day
     *
     * @return void 반환값 없음
     */
    public function test_aggregate_counts_site_dawn_post_on_site_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 13:00:00', 'UTC'));

        $post = $this->makePost('서울 새벽 글', '2026-10-05 16:00:00'); // 서울 10-06 01:00
        $this->makeComment($post->id, '2026-10-05 15:30:00'); // 서울 10-06 00:30

        app(BoardDashboardService::class)->aggregateRecentDays(7);

        $this->assertSame(1, BoardStat::where('date', '2026-10-06')->value('post_count'));
        $this->assertSame(1, BoardStat::where('date', '2026-10-06')->value('comment_count'));
        $this->assertSame(0, BoardStat::where('date', '2026-10-05')->value('post_count'));
    }

    /**
     * 사이트 자정이 지나면 대시보드의 「오늘」과 그래프 마지막 날이 새 날짜다.
     *
     * @scenario boundary=board_stats, clock=after_site_midnight
     *
     * @effects dashboard_today_follows_site_day
     *
     * @return void 반환값 없음
     */
    public function test_dashboard_today_follows_site_midnight(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-06 16:30:00', 'UTC')); // 서울 10-07 01:30

        BoardStat::create(['date' => '2026-10-07', 'post_count' => 5, 'comment_count' => 2]);
        BoardStat::create(['date' => '2026-10-06', 'post_count' => 9, 'comment_count' => 9]);

        $service = app(BoardDashboardService::class);
        $overview = $service->getOverview();

        $this->assertSame(5, $overview['today_posts']);
        $this->assertSame(2, $overview['today_comments']);

        $graph = $service->getPostGraph(7);
        $this->assertSame('2026-10-07', $graph['days'][6]['date']);
        $this->assertSame('2026-10-01', $graph['days'][0]['date']);
    }

    /**
     * 신고 로그 한 건을 지정한 UTC 시각으로 만듭니다.
     *
     * @param  string  $createdAtUtc  생성 시각(UTC, Y-m-d H:i:s)
     * @return void 반환값 없음
     */
    private function seedReportLog(string $createdAtUtc): void
    {
        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'author_id' => User::factory()->create()->id,
        ]);

        $log = ReportLog::factory()->create([
            'report_id' => $report->id,
            'reporter_id' => $this->reporter->id,
        ]);
        DB::table('boards_report_logs')->where('id', $log->id)->update(['created_at' => $createdAtUtc]);
    }

    /**
     * 게시글을 지정한 UTC 시각으로 만듭니다.
     *
     * @param  string  $title  제목
     * @param  string  $createdAtUtc  생성 시각(UTC)
     * @return Post 게시글
     */
    private function makePost(string $title, string $createdAtUtc): Post
    {
        $post = Post::create([
            'board_id' => $this->board->id,
            'title' => $title,
            'content' => '내용',
            'ip_address' => '127.0.0.1',
        ]);
        $post->forceFill(['created_at' => $createdAtUtc])->saveQuietly();

        return $post;
    }

    /**
     * 댓글을 지정한 UTC 시각으로 만듭니다.
     *
     * @param  int  $postId  게시글 ID
     * @param  string  $createdAtUtc  생성 시각(UTC)
     * @return Comment 댓글
     */
    private function makeComment(int $postId, string $createdAtUtc): Comment
    {
        $comment = Comment::create([
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'content' => '댓글',
            'ip_address' => '127.0.0.1',
        ]);
        $comment->forceFill(['created_at' => $createdAtUtc])->saveQuietly();

        return $comment;
    }
}
