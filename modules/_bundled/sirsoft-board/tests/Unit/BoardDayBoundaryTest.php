<?php

namespace Modules\Sirsoft\Board\Tests\Unit;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;
use Modules\Sirsoft\Board\Models\BoardStat;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Models\ReportLog;
use Modules\Sirsoft\Board\Repositories\BoardRepository;
use Modules\Sirsoft\Board\Repositories\ReportRepository;
use Modules\Sirsoft\Board\Services\BoardDashboardService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * 게시판 「하루」 경계 테스트 (7.1.0 전수 실측 O-CF3 · 결정 P4)
 *
 * 원칙: 사람이 보는 화면의 하루는 그 사람의 시간대, 서버에 적는 시각은 UTC.
 * 게시판의 하루 경계 세 곳이 UTC 자정으로 잘려, 한국 시간 새벽 0~9시에 생긴 글·신고가 전날로 묶였다.
 *
 * - 하루 신고 한도: 신고하는 사람 자신의 하루(보는 사람 시간대) — 한국 시간 새벽 0시 30분 신고는 「오늘」.
 * - 대시보드 일별 집계(board_stats)·인기글 「오늘」: 모든 운영자·방문자가 함께 보는 하루 1행 집계·공용 캐시라
 *   보는 사람마다 경계를 달리할 수 없다 → 방문자 통계처럼 사이트 표준시의 하루(UTC 자정이 아니라).
 *
 * 기준 시각: UTC 2026-10-07 16:30 = 한국 2026-10-08 01:30.
 */
class BoardDayBoundaryTest extends BoardTestCase
{
    /**
     * 시각과 시간대를 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-07 16:30:00', 'UTC'));
        config(['app.default_user_timezone' => 'Asia/Seoul']);
        App::instance('user_timezone', 'Asia/Seoul');
    }

    /**
     * 하루 신고 한도는 신고하는 사람의 하루로 센다 — 한국 시간 전날 밤 신고는 빼고, 자정 뒤 신고는 넣는다.
     *
     * @scenario boundary=report_daily_limit, timezone=viewer
     *
     * @effects board_report_daily_limit_counts_reporter_day
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function daily_report_count_uses_reporter_timezone_day(): void
    {
        $reporter = User::factory()->create();

        // 한국 2026-10-07 23:30 (UTC 14:30) — 어제
        $this->makeReportLog($reporter->id, '2026-10-07 14:30:00');
        // 한국 2026-10-08 00:30 (UTC 15:30) — 오늘
        $this->makeReportLog($reporter->id, '2026-10-07 15:30:00');

        $this->assertSame(1, (new ReportRepository)->countTodayReportsByUser($reporter->id));
    }

    /**
     * 대시보드 일별 집계는 사이트 표준시의 하루로 행을 만든다.
     *
     * @scenario boundary=board_stats_daily, timezone=site
     *
     * @effects board_stats_rows_follow_site_day
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function daily_stats_aggregate_by_site_day(): void
    {
        Report::query()->forceDelete();
        Comment::query()->forceDelete();
        Post::query()->forceDelete();
        BoardStat::query()->delete();

        // 한국 2026-10-07 23:30 → 10-07 행, 한국 2026-10-08 00:30·01:00 → 10-08 행
        $yesterdayPost = $this->createTestPost(['created_at' => '2026-10-07 14:30:00']);
        $this->createTestPost(['created_at' => '2026-10-07 15:30:00']);
        $this->createTestPost(['created_at' => '2026-10-07 16:00:00']);
        $this->createTestComment($yesterdayPost, ['created_at' => '2026-10-07 15:10:00']);

        $service = $this->app->make(BoardDashboardService::class);
        $service->aggregateRecentDays(2);

        $today = BoardStat::where('date', '2026-10-08')->first();
        $yesterday = BoardStat::where('date', '2026-10-07')->first();

        $this->assertNotNull($today, '사이트 하루(2026-10-08) 행이 있어야 합니다');
        $this->assertSame(2, $today->post_count);
        $this->assertSame(1, $today->comment_count);
        $this->assertSame(1, $yesterday->post_count);
        $this->assertSame(0, $yesterday->comment_count);

        // 「오늘」 카드도 같은 하루 행을 읽는다
        $this->assertSame(['today_posts' => 2, 'today_comments' => 1], $service->getOverview());
        $this->assertSame('2026-10-08', last($service->getPostGraph(7)['days'])['date']);
    }

    /**
     * 인기글 「오늘」 은 사이트 표준시 자정부터 센다.
     *
     * @scenario boundary=popular_today, timezone=site
     *
     * @effects board_popular_today_starts_at_site_midnight
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function popular_today_starts_at_site_midnight(): void
    {
        $before = $this->createTestPost(['created_at' => '2026-10-07 14:30:00', 'view_count' => 9001]);
        $after = $this->createTestPost(['created_at' => '2026-10-07 15:30:00', 'view_count' => 9000]);

        $ids = collect(app(BoardRepository::class)->getPopularPosts('today', 500))->pluck('id')->all();

        $this->assertContains($after, $ids);
        $this->assertNotContains($before, $ids);
    }

    /**
     * 신고 기록 한 줄을 지정한 UTC 시각으로 만듭니다.
     *
     * @param  int  $reporterId  신고자 ID
     * @param  string  $createdAtUtc  기록 시각(UTC)
     * @return void 반환값 없음
     */
    private function makeReportLog(int $reporterId, string $createdAtUtc): void
    {
        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'author_id' => User::factory()->create()->id,
        ]);

        $log = ReportLog::factory()->create([
            'report_id' => $report->id,
            'reporter_id' => $reporterId,
        ]);

        $log->forceFill(['created_at' => $createdAtUtc])->saveQuietly();
    }
}
