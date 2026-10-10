<?php

namespace Modules\Sirsoft\Board\Tests\Unit\Listeners;

use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Mockery;
use Mockery\MockInterface;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Listeners\BoardNotificationDataListener;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Models\ReportLog;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\CommentRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Repositories\Contracts\ReportRepositoryInterface;
use Modules\Sirsoft\Board\Services\UserNotificationSettingService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 알림 데이터 리스너가 조회를 Repository 로만 한다 (#599 validate board minor)
 *
 * 리스너는 Repository 경유가 규정이다(board AGENTS.md — Model·DB 직접 접근 금지). #599 가
 * 게시판 관리자 해석을 헬퍼로 뽑으면서 `$role->users()->get()` · `User::superAdmins()` 를
 * 그대로 옮겼고, 신고 알림은 `$report->logs()` 를 직접 셌다.
 */
class BoardNotificationRepositoryRoutingTest extends ModuleTestCase
{
    /** @var MockInterface&BoardRepositoryInterface */
    private $boardRepository;

    /** @var MockInterface&RoleRepositoryInterface */
    private $roleRepository;

    /** @var MockInterface&UserRepositoryInterface */
    private $userRepository;

    /** @var MockInterface&ReportRepositoryInterface */
    private $reportRepository;

    private BoardNotificationDataListener $listener;

    /**
     * 모든 조회 협력자를 Mock 으로 둔 리스너를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->boardRepository = Mockery::mock(BoardRepositoryInterface::class);
        $this->roleRepository = Mockery::mock(RoleRepositoryInterface::class);
        $this->userRepository = Mockery::mock(UserRepositoryInterface::class);
        $this->reportRepository = Mockery::mock(ReportRepositoryInterface::class);

        $this->listener = new BoardNotificationDataListener(
            $this->boardRepository,
            Mockery::mock(PostRepositoryInterface::class),
            Mockery::mock(CommentRepositoryInterface::class),
            Mockery::mock(UserNotificationSettingService::class),
            $this->roleRepository,
            $this->userRepository,
            $this->reportRepository,
        );
    }

    /**
     * 새 글 관리자 알림용 게시판·게시글 Mock 을 준비합니다.
     *
     * @return Post 게시글 Mock
     */
    private function preparePostForAdminNotice(): Post
    {
        $board = Mockery::mock(Board::class)->makePartial();
        $board->slug = 'validate-board';
        $board->name = '검증게시판';
        $board->localizedName = '검증게시판';
        $board->notify_admin_on_post = true;

        $this->boardRepository->shouldReceive('findBySlug')->with('validate-board')->andReturn($board);

        $post = Mockery::mock(Post::class)->makePartial();
        $post->id = 700;
        $post->title = '새 글';
        $post->user_id = null;
        $post->user = null;
        $post->parent_id = null;
        $post->trigger_type = null;
        $post->status = PostStatus::Published;

        return $post;
    }

    /**
     * 리스너 소스에 모델·관계 직접 조회가 남아 있지 않다.
     *
     * 수정 전 실패 이유: `User::superAdmins()`, `$managerRole->users()->get()`, `$report->logs()` 가 있었다.
     *
     * @scenario case=source_scan
     *
     * @effects board_notification_listener_has_no_direct_model_queries
     *
     * @return void 반환값 없음
     */
    public function test_listener_has_no_direct_model_queries(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/src/Listeners/BoardNotificationDataListener.php');

        foreach (['User::', '->users()', '->logs()', "loadMissing(['logs'", '::query(', 'DB::'] as $needle) {
            $this->assertStringNotContainsString($needle, $source, "리스너가 직접 조회합니다: {$needle}");
        }
    }

    /**
     * 게시판 관리자는 역할 Repository 로 해석한다.
     *
     * 수정 전 실패 이유: 리스너가 findByIdentifier 로 역할 모델을 받아 `users()` 관계를 직접 조회했다
     * (getUsersByIdentifier 를 부르지 않아 Mock 기대가 어긋난다).
     *
     * @scenario case=board_managers
     *
     * @effects board_managers_are_resolved_through_role_repository
     *
     * @return void 반환값 없음
     */
    public function test_board_managers_are_resolved_through_role_repository(): void
    {
        $post = $this->preparePostForAdminNotice();
        $manager = User::factory()->make(['id' => 9001, 'name' => '게시판관리자']);

        $this->roleRepository->shouldReceive('getUsersByIdentifier')
            ->once()
            ->with('sirsoft-board.validate-board.manager')
            ->andReturn(new EloquentCollection([$manager]));
        $this->userRepository->shouldReceive('findSuperAdmin')->never();

        $result = $this->listener->extractData([], 'new_post_admin', [$post, 'validate-board']);

        $managers = $result['context']['related_users']['board_managers'];
        $this->assertCount(1, $managers);
        $this->assertSame(9001, $managers->first()->id);
    }

    /**
     * 게시판 관리자가 없으면 superAdmin 폴백도 사용자 Repository 로 한다.
     *
     * 수정 전 실패 이유: `User::superAdmins()->first()` 를 직접 불렀다.
     *
     * @scenario case=super_admin_fallback
     *
     * @effects super_admin_fallback_goes_through_user_repository
     *
     * @return void 반환값 없음
     */
    public function test_super_admin_fallback_goes_through_user_repository(): void
    {
        $post = $this->preparePostForAdminNotice();
        $superAdmin = User::factory()->make(['id' => 9002, 'name' => '최고관리자']);

        $this->roleRepository->shouldReceive('getUsersByIdentifier')
            ->once()
            ->andReturn(new EloquentCollection);
        $this->userRepository->shouldReceive('findSuperAdmin')->once()->andReturn($superAdmin);

        $result = $this->listener->extractData([], 'new_post_admin', [$post, 'validate-board']);

        $managers = $result['context']['related_users']['board_managers'];
        $this->assertCount(1, $managers);
        $this->assertSame(9002, $managers->first()->id);
    }

    /**
     * 신고 접수 알림(케이스당 1회)은 로그 수와 최근 로그를 신고 Repository 로 읽는다.
     *
     * 수정 전 실패 이유: `$report->logs()` 로 직접 세고 `loadMissing(['logs'…])` 로 전 로그를 읽었다
     * (신고 Repository 의 기대 호출이 일어나지 않는다).
     *
     * @scenario case=report_logs
     *
     * @effects report_logs_are_read_through_report_repository
     *
     * @return void 반환값 없음
     */
    public function test_report_logs_are_read_through_report_repository(): void
    {
        Config::set('g7_settings.modules.sirsoft-board.report_policy', [
            'notify_admin_on_report' => true,
            'notify_admin_on_report_scope' => 'per_case',
        ]);

        $activatedAt = Carbon::parse('2026-09-01 10:00:00');
        $report = $this->prepareReport($activatedAt);

        $log = new ReportLog;
        $log->snapshot = ['title' => '신고된 글', 'board_name' => '검증게시판'];
        $log->reason_type = 'spam';

        $this->reportRepository->shouldReceive('countLogsByReportSince')
            ->once()
            ->with(88, Mockery::on(fn ($since) => $since instanceof \DateTimeInterface && $since->eq($activatedAt)))
            ->andReturn(1);
        $this->reportRepository->shouldReceive('findLatestLogByReport')->once()->with(88)->andReturn($log);
        $this->roleRepository->shouldReceive('getUsersByIdentifier')->andReturn(new EloquentCollection);
        $this->userRepository->shouldReceive('findSuperAdmin')->andReturn(null);

        $result = $this->listener->extractData([], 'report_received_admin', [$report]);

        $this->assertSame('신고된 글', $result['data']['post_title']);
    }

    /**
     * 현재 주기에 이미 로그가 있으면(케이스당 1회) 두 번째부터는 보내지 않는다.
     *
     * Repository 로 옮긴 뒤에도 발송 범위 판정이 그대로인지 지킨다.
     *
     * @scenario case=report_logs
     *
     * @effects per_case_report_notice_is_sent_once_per_cycle
     *
     * @return void 반환값 없음
     */
    public function test_per_case_report_notice_is_sent_once_per_cycle(): void
    {
        Config::set('g7_settings.modules.sirsoft-board.report_policy', [
            'notify_admin_on_report' => true,
            'notify_admin_on_report_scope' => 'per_case',
        ]);

        $report = $this->prepareReport(null);

        $this->reportRepository->shouldReceive('countLogsByReportSince')->once()->with(88, null)->andReturn(2);
        $this->reportRepository->shouldReceive('findLatestLogByReport')->never();

        $result = $this->listener->extractData([], 'report_received_admin', [$report]);

        $this->assertTrue($result['context']['skip'] ?? false, '같은 주기의 두 번째 신고에는 알림을 보내지 않아야 합니다.');
    }

    /**
     * 신고 케이스 Mock 을 준비합니다.
     *
     * @param  Carbon|null  $activatedAt  현재 주기 시작 시각
     * @return Report 신고 케이스 Mock
     */
    private function prepareReport(?Carbon $activatedAt): Report
    {
        $board = Mockery::mock(Board::class)->makePartial();
        $board->slug = 'validate-board';
        $board->name = '검증게시판';
        $board->localizedName = '검증게시판';

        $report = Mockery::mock(Report::class)->makePartial();
        $report->id = 88;
        $report->target_type = 'post';
        $report->reporter_id = null;
        $report->reporter = null;
        $report->board = $board;
        $report->last_activated_at = $activatedAt;

        return $report;
    }
}
