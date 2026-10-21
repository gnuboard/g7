<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Board\Repositories\Contracts\ReportRepositoryInterface;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 신고 케이스의 마지막 신고 시각(`last_reported_at`) 갱신 테스트
 *
 * `last_reported_at` 은 그 케이스에 들어온 가장 늦은 신고 시각이다 — 관리자 신고 목록의 정렬·기간 거르개·
 * 대시보드 미처리 목록이 이 열을 쓰고, 목록 신고일 칸은 이 값을 크게 보인다. 처리 중(접수·검토) 케이스에
 * 다른 회원의 신고가 더해질 때 갱신하지 않아, 두 번째 신고가 들어와도 처음 신고 시각에 머물렀다(7.1.0 부터).
 * 신고 취소 경로는 남은 기록의 가장 늦은 시각으로 다시 계산해 두 경로가 서로 다른 뜻으로 열을 썼다.
 */
#[Group('board')]
#[Group('user')]
#[Group('report')]
class ReportLastReportedAtTest extends BoardTestCase
{
    /**
     * 이 테스트가 쓰는 게시판 slug 를 돌려줍니다.
     *
     * @return string 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'report-last-reported-at';
    }

    /**
     * 이 테스트에 맞춘 게시판 속성을 반환합니다.
     *
     * @param  string  $slug  게시판 slug
     * @return array 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '마지막 신고 시각 테스트 게시판', 'en' => 'Last Reported At Test Board'],
            'is_active' => true,
            'use_report' => true,
            'use_comment' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
        ];
    }

    /**
     * 테스트 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'comments.read', 'comments.write']);
    }

    /**
     * 테스트 시각 고정을 풉니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * 접수·검토 중인 케이스에 다른 회원의 신고가 더해지면 마지막 신고 시각이 그 신고 시각으로 바뀐다.
     * 처음 신고 시각(케이스 생성 시각)은 그대로다.
     *
     * @scenario operation=submit_post_report, condition=reportable_target
     *
     * @effects additional_report_on_open_case_updates_last_reported_at
     *
     * @return void 반환값 없음
     */
    public function test_additional_report_on_open_case_updates_last_reported_at(): void
    {
        $postId = $this->createTestPost(['user_id' => $this->createUser()->id]);

        Carbon::setTestNow(Carbon::parse('2026-10-17 12:06:51'));
        $this->report($postId)->assertStatus(201);
        $reportId = $this->caseId($postId);

        // 관리자가 검토로 바꾼 뒤(처리 중 — 케이스는 열린 채)
        DB::table('boards_reports')->where('id', $reportId)->update(['status' => 'review']);

        Carbon::setTestNow(Carbon::parse('2026-10-17 17:37:02'));
        $this->report($postId)->assertStatus(201);

        $row = DB::table('boards_reports')->where('id', $reportId)->first();
        $this->assertSame('2026-10-17 17:37:02', (string) $row->last_reported_at, '두 번째 신고 시각이 마지막 신고 시각이어야 한다');
        $this->assertSame('2026-10-17 12:06:51', (string) $row->created_at, '처음 신고 시각(케이스 생성)은 그대로');
        $this->assertSame('review', $row->status, '추가 신고는 처리 중 상태를 바꾸지 않는다');
        $this->assertSame(
            (string) DB::table('boards_report_logs')->where('report_id', $reportId)->max('created_at'),
            (string) $row->last_reported_at,
            '마지막 신고 시각 = 신고 기록의 가장 늦은 시각'
        );
    }

    /**
     * 추가 신고로 마지막 신고 시각이 바뀐 케이스가 관리자 신고 목록(마지막 신고 시각 내림차순) 맨 위로 온다.
     *
     * @scenario operation=submit_post_report, condition=reportable_target
     *
     * @effects additional_report_moves_case_to_top_of_admin_list
     *
     * @return void 반환값 없음
     */
    public function test_additional_report_moves_case_to_top_of_admin_list(): void
    {
        $older = $this->createTestPost(['user_id' => $this->createUser()->id]);
        $newer = $this->createTestPost(['user_id' => $this->createUser()->id]);

        Carbon::setTestNow(Carbon::parse('2026-10-17 09:00:00'));
        $this->report($older)->assertStatus(201);
        Carbon::setTestNow(Carbon::parse('2026-10-17 10:00:00'));
        $this->report($newer)->assertStatus(201);
        Carbon::setTestNow(Carbon::parse('2026-10-17 11:00:00'));
        $this->report($older)->assertStatus(201);

        $ids = app(ReportRepositoryInterface::class)
            ->paginateGrouped(['board_id' => $this->board->id], 15)
            ->getCollection()
            ->pluck('id')
            ->all();

        $this->assertSame([$this->caseId($older), $this->caseId($newer)], $ids, '다시 신고된 케이스가 위');
    }

    /**
     * 닫힌(반려) 케이스를 다시 신고하면 재접수되며 마지막 신고 시각도 그 시각이다(7.1.0 동작 유지).
     *
     * @scenario operation=submit_post_report, condition=reportable_target
     *
     * @effects reactivated_case_updates_last_reported_at
     *
     * @return void 반환값 없음
     */
    public function test_reactivated_case_updates_last_reported_at(): void
    {
        $postId = $this->createTestPost(['user_id' => $this->createUser()->id]);

        Carbon::setTestNow(Carbon::parse('2026-10-17 12:00:00'));
        $this->report($postId)->assertStatus(201);
        $reportId = $this->caseId($postId);
        DB::table('boards_reports')->where('id', $reportId)->update(['status' => 'rejected']);

        Carbon::setTestNow(Carbon::parse('2026-10-18 08:30:00'));
        $this->report($postId)->assertStatus(201);

        $row = DB::table('boards_reports')->where('id', $reportId)->first();
        $this->assertSame('pending', $row->status);
        $this->assertSame('2026-10-18 08:30:00', (string) $row->last_reported_at);
        $this->assertSame('2026-10-18 08:30:00', (string) $row->last_activated_at);
    }

    /**
     * 같은 회원이 같은 대상을 다시 신고하면 409 로 거절되고 마지막 신고 시각은 바뀌지 않는다.
     *
     * @scenario operation=submit_post_report, condition=reportable_target
     *
     * @effects duplicate_report_keeps_last_reported_at
     *
     * @return void 반환값 없음
     */
    public function test_duplicate_report_keeps_last_reported_at(): void
    {
        $postId = $this->createTestPost(['user_id' => $this->createUser()->id]);
        $reporter = $this->createUser();

        Carbon::setTestNow(Carbon::parse('2026-10-17 12:00:00'));
        $this->report($postId, $reporter)->assertStatus(201);
        $reportId = $this->caseId($postId);

        // 같은 회원의 재신고 — 쿨다운을 지나도록 하루 뒤
        Carbon::setTestNow(Carbon::parse('2026-10-18 12:00:00'));
        $this->report($postId, $reporter)->assertStatus(409);

        $this->assertSame(
            '2026-10-17 12:00:00',
            (string) DB::table('boards_reports')->where('id', $reportId)->value('last_reported_at')
        );
    }

    /**
     * 늦게 끝난 요청이 이미 기록된 더 늦은 시각을 앞 시각으로 되돌리지 않는다(동시 신고).
     *
     * @scenario operation=submit_post_report, condition=reportable_target
     *
     * @effects last_reported_at_never_moves_backward
     *
     * @return void 반환값 없음
     */
    public function test_last_reported_at_never_moves_backward(): void
    {
        $postId = $this->createTestPost(['user_id' => $this->createUser()->id]);

        Carbon::setTestNow(Carbon::parse('2026-10-17 12:00:00'));
        $this->report($postId)->assertStatus(201);
        $reportId = $this->caseId($postId);

        $repository = app(ReportRepositoryInterface::class);

        // 다른 요청이 먼저 13:00 을 기록한 상태에서 12:30 신고의 갱신이 뒤늦게 도착
        $this->assertTrue($repository->advanceLastReportedAt($reportId, Carbon::parse('2026-10-17 13:00:00')));
        $this->assertFalse($repository->advanceLastReportedAt($reportId, Carbon::parse('2026-10-17 12:30:00')));

        $this->assertSame(
            '2026-10-17 13:00:00',
            (string) DB::table('boards_reports')->where('id', $reportId)->value('last_reported_at')
        );
    }

    /**
     * 다른 회원으로 게시글을 신고합니다.
     *
     * @param  int  $postId  게시글 ID
     * @param  User|null  $reporter  신고자(없으면 새 회원)
     * @return TestResponse 응답
     */
    private function report(int $postId, $reporter = null)
    {
        $reporter ??= $this->createUser();

        return $this->actingAs($reporter)->postJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/reports",
            ['reason_type' => 'spam', 'reason_detail' => '스팸 게시글입니다.']
        );
    }

    /**
     * 게시글의 신고 케이스 ID 를 반환합니다.
     *
     * @param  int  $postId  게시글 ID
     * @return int 케이스 ID
     */
    private function caseId(int $postId): int
    {
        return (int) DB::table('boards_reports')
            ->where('board_id', $this->board->id)
            ->where('target_type', 'post')
            ->where('target_id', $postId)
            ->value('id');
    }
}
