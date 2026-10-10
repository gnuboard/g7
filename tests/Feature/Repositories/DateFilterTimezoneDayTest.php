<?php

namespace Tests\Feature\Repositories;

use App\Http\Middleware\SetTimezone;
use App\Models\Analytics\AnalyticsBotHit;
use App\Models\Analytics\AnalyticsCollectFailure;
use App\Models\Schedule;
use App\Models\ScheduleHistory;
use App\Models\User;
use App\Repositories\Analytics\BotHitRepository;
use App\Repositories\Analytics\CollectFailureRepository;
use App\Repositories\MemberNotificationRepository;
use App\Repositories\ScheduleHistoryRepository;
use App\Repositories\ScheduleRepository;
use App\Repositories\UserRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * 날짜 필터의 「하루」 기준 회귀 테스트
 *
 * 결함: 회원 목록(C-D1)과 같은 결함군 — 화면에서 고른 날짜(Y-m-d)를 UTC 하루로 잘라, 한국 시간
 * 새벽에 생긴 행이 목록에는 그날로 보이는데 그날로 거르면 빠졌다. 목록은 날짜를 보는 사람의
 * 시간대로 그리므로 필터도 보는 사람의 시간대 하루로 자른다. 방문자 통계 원장(봇 기록·수집 실패)은
 * 집계가 사이트 표준시 자정에 잠겨 있어(방문 원장 `visit_date`) 그 기준으로 자른다.
 *
 * 공통 데이터: 2026-03-09 15:30 UTC = 2026-03-10 00:30 KST(그날 안),
 * 2026-03-10 15:30 UTC = 2026-03-11 00:30 KST(다음날). 2026-03-10 로 거르면 앞의 것만 남아야 한다.
 *
 * 로컬 MySQL 은 migrate:fresh 의 파티션·인덱스 DDL 에서 OOM 이 나므로 트랜잭션 격리를 쓴다.
 *
 * 시나리오 SSoT: tests/scenarios/date-filter-viewer-timezone-day.yaml
 */
#[Group('core')]
class DateFilterTimezoneDayTest extends TestCase
{
    use DatabaseTransactions;

    private const DAY = '2026-03-10';

    private const INSIDE_UTC = '2026-03-09 15:30:00';

    private const OUTSIDE_UTC = '2026-03-10 15:30:00';

    /**
     * 보는 사람의 시간대를 한국 시간으로 둡니다(SetTimezone 미들웨어가 요청마다 심는 값).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->instance(SetTimezone::TIMEZONE_KEY, 'Asia/Seoul');
    }

    /**
     * 회원 알림함의 기간 필터는 보는 사람의 시간대 하루로 자른다.
     *
     * @scenario surface=admin_member_notifications
     *
     * @effects date_filters_follow_viewer_timezone_day
     *
     * @return void 반환값 없음
     */
    public function test_member_notification_created_range_follows_viewer_day(): void
    {
        $member = User::factory()->create();
        $inside = $this->seedNotification($member, self::INSIDE_UTC);
        $this->seedNotification($member, self::OUTSIDE_UTC);

        $page = app(MemberNotificationRepository::class)->paginateForAdmin([
            'user_id' => $member->id,
            'created_from' => self::DAY,
            'created_to' => self::DAY,
        ]);

        $this->assertSame([$inside], collect($page->items())->pluck('id')->all());
    }

    /**
     * 스케줄 목록의 등록일 필터는 보는 사람의 시간대 하루로 자른다.
     *
     * @scenario surface=admin_schedules
     *
     * @effects date_filters_follow_viewer_timezone_day
     *
     * @return void 반환값 없음
     */
    public function test_schedule_created_range_follows_viewer_day(): void
    {
        $marker = 'tzday-'.Str::lower(Str::random(8));
        $inside = $this->schedule($marker.'-in', self::INSIDE_UTC);
        $outside = $this->schedule($marker.'-out', self::OUTSIDE_UTC);

        $page = app(ScheduleRepository::class)->getPaginatedSchedules([
            'created_from' => self::DAY,
            'created_to' => self::DAY,
            'per_page' => 100,
        ]);
        $ids = collect($page->items())->pluck('id')->all();

        $this->assertContains($inside->id, $ids);
        $this->assertNotContains($outside->id, $ids);
    }

    /**
     * 스케줄 실행 이력의 시작일 필터는 보는 사람의 시간대 하루로 자른다.
     *
     * @scenario surface=admin_schedule_history
     *
     * @effects date_filters_follow_viewer_timezone_day
     *
     * @return void 반환값 없음
     */
    public function test_schedule_history_started_range_follows_viewer_day(): void
    {
        $schedule = $this->schedule('tzday-history-'.Str::lower(Str::random(6)), now()->toDateTimeString());
        $inside = $this->history($schedule, self::INSIDE_UTC);
        $this->history($schedule, self::OUTSIDE_UTC);

        $page = app(ScheduleHistoryRepository::class)->getPaginatedByScheduleId($schedule->id, [
            'started_from' => self::DAY,
            'started_to' => self::DAY,
        ]);

        $this->assertSame([$inside->id], collect($page->items())->pluck('id')->all());
    }

    /**
     * 회원 통계의 「오늘 가입」은 보는 사람의 시간대 오늘이다.
     *
     * @scenario surface=admin_user_statistics
     *
     * @effects date_filters_follow_viewer_timezone_day
     *
     * @return void 반환값 없음
     */
    public function test_users_today_counts_viewer_today(): void
    {
        // 지금 = 2026-03-10 01:00 KST (2026-03-09 16:00 UTC). UTC 로는 아직 3월 9일이다.
        $this->travelTo(Carbon::parse('2026-03-09 16:00:00', 'UTC'));

        $repository = app(UserRepository::class);
        $before = $repository->getStatistics(applyPermissionScope: false)['users_today'];

        // 한국 시간 오늘 00:30 가입(UTC 로는 어제) — 오늘 가입으로 세야 한다.
        User::factory()->create(['created_at' => self::INSIDE_UTC]);
        // 한국 시간 어제 23:30 가입(UTC 로는 같은 3월 9일) — 오늘 가입이 아니다.
        User::factory()->create(['created_at' => '2026-03-09 14:30:00']);

        $after = $repository->getStatistics(applyPermissionScope: false)['users_today'];

        $this->assertSame(1, $after - $before);
    }

    /**
     * 방문자 통계 원장(봇 기록·수집 실패)의 기간 순회는 사이트 표준시 하루로 자른다
     * — 같은 익명화 실행이 방문 원장을 `visit_date`(사이트 표준시 일자)로 자르기 때문이다.
     *
     * @scenario surface=analytics_side_ledgers
     *
     * @effects analytics_side_ledgers_follow_site_timezone_day
     *
     * @return void 반환값 없음
     */
    public function test_analytics_side_ledgers_follow_site_timezone_day(): void
    {
        config(['g7_settings.core.general.timezone' => 'Asia/Seoul']);
        // 보는 사람의 시간대와 섞이지 않는지 보려고 일부러 다르게 둔다.
        app()->instance(SetTimezone::TIMEZONE_KEY, 'America/New_York');

        $botInside = AnalyticsBotHit::factory()->create(['occurred_at' => self::INSIDE_UTC]);
        $botOutside = AnalyticsBotHit::factory()->create(['occurred_at' => self::OUTSIDE_UTC]);
        $failInside = AnalyticsCollectFailure::factory()->create(['occurred_at' => self::INSIDE_UTC]);
        $failOutside = AnalyticsCollectFailure::factory()->create(['occurred_at' => self::OUTSIDE_UTC]);

        $seen = [];
        app(BotHitRepository::class)->chunkInDateRange(self::DAY, self::DAY, 100, function ($rows) use (&$seen): void {
            foreach ($rows as $row) {
                $seen['bot'][] = $row->id;
            }
        });
        app(CollectFailureRepository::class)->chunkInDateRange(self::DAY, self::DAY, 100, function ($rows) use (&$seen): void {
            foreach ($rows as $row) {
                $seen['fail'][] = $row->id;
            }
        });

        $this->assertContains($botInside->id, $seen['bot'] ?? []);
        $this->assertNotContains($botOutside->id, $seen['bot'] ?? []);
        $this->assertContains($failInside->id, $seen['fail'] ?? []);
        $this->assertNotContains($failOutside->id, $seen['fail'] ?? []);
    }

    /**
     * 회원 수신함 알림 한 건을 넣습니다.
     *
     * @param  User  $user  수신 회원
     * @param  string  $createdAt  생성 시각(UTC)
     * @return string 알림 id
     */
    private function seedNotification(User $user, string $createdAt): string
    {
        $id = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $id,
            'type' => 'App\\Notifications\\GenericNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode(['subject' => 'tz', 'body' => 'tz'], JSON_UNESCAPED_UNICODE),
            'read_at' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $id;
    }

    /**
     * 스케줄 하나를 만듭니다.
     *
     * @param  string  $name  이름(검색 표식)
     * @param  string  $createdAt  등록 시각(UTC)
     * @return Schedule 스케줄
     */
    private function schedule(string $name, string $createdAt): Schedule
    {
        $schedule = Schedule::create([
            'name' => $name,
            'description' => $name,
            'type' => 'artisan',
            'command' => 'inspire',
            'expression' => '* * * * *',
            'frequency' => 'everyMinute',
            'is_active' => true,
        ]);
        $schedule->forceFill(['created_at' => $createdAt])->saveQuietly();

        return $schedule;
    }

    /**
     * 실행 이력 한 건을 만듭니다.
     *
     * @param  Schedule  $schedule  대상 스케줄
     * @param  string  $startedAt  시작 시각(UTC)
     * @return ScheduleHistory 이력
     */
    private function history(Schedule $schedule, string $startedAt): ScheduleHistory
    {
        return ScheduleHistory::create([
            'schedule_id' => $schedule->id,
            'started_at' => $startedAt,
            'ended_at' => $startedAt,
            'duration' => 1,
            'status' => 'success',
            'exit_code' => 0,
            'trigger_type' => 'manual',
        ]);
    }
}
