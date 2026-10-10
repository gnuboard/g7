<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Models\ReportLog;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 마이페이지 「내 신고」 API Feature 테스트.
 *
 * 본인이 남긴 신고 이력만 조회하고(다른 신고자·처리자·관리자 메모 비노출),
 * **접수(pending) 상태에서만** 취소할 수 있다 (결정 R10).
 * 내가 마지막 신고자였으면 케이스를 영구 삭제한다 — 소프트 삭제로 남기면
 * unique(board,target) 제약이 살아 있어 같은 대상을 다시 신고할 수 없다.
 */
class MyReportsApiTest extends BoardTestCase
{
    private const ENDPOINT = '/api/modules/sirsoft-board/me/reports';

    private User $reporter;

    /**
     * 각 테스트 전에 공통 픽스처를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->reporter = $this->createUser();
    }

    /**
     * 케이스 + 내 신고 로그를 만듭니다.
     *
     * @param  $targetId  대상 ID
     * @param  $targetType  대상 유형
     * @param  $status  status 값
     * @param  $reporter  reporter 값
     * @param  $snapshot  snapshot 값
     * @return array{0: Report, 1: ReportLog} array{0: 인스턴스
     */
    private function seedReport(
        int $targetId,
        string $targetType = 'post',
        ReportStatus $status = ReportStatus::Pending,
        ?User $reporter = null,
        array $snapshot = [],
    ): array {
        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'status' => $status,
        ]);

        $log = ReportLog::factory()->create([
            'report_id' => $report->id,
            'reporter_id' => ($reporter ?? $this->reporter)->id,
            'snapshot' => array_merge([
                'board_name' => '테스트 게시판',
                'title' => '신고 대상 제목',
                'content' => '신고 대상 본문',
                'content_mode' => 'text',
                'author_name' => '작성자',
            ], $snapshot),
        ]);

        return [$report, $log];
    }

    /**
     * 본인 신고만 상태 라벨·취소 가능 여부와 함께 조회된다.
     *
     * @scenario case=list_own_logs_only
     *
     * @effects lists_own_logs_with_status_and_can_cancel
     *
     * @return void 반환값 없음
     */
    public function test_index_lists_own_logs_only_with_status_labels_and_can_cancel(): void
    {
        $postId = $this->createTestPost();
        [$report, $log] = $this->seedReport($postId);

        // 남의 신고 (다른 케이스)
        $otherPost = $this->createTestPost();
        $this->seedReport($otherPost, reporter: $this->createUser());

        $response = $this->actingAs($this->reporter, 'sanctum')->getJson(self::ENDPOINT);

        $response->assertOk()->assertJsonCount(1, 'data.data');

        $row = $response->json('data.data.0');
        $this->assertSame($log->id, $row['id'], 'id 는 취소 엔드포인트가 받는 로그 ID 여야 합니다.');
        $this->assertSame($report->id, $row['report_id']);
        $this->assertSame('pending', $row['status']);
        $this->assertNotEmpty($row['status_label']);
        $this->assertTrue($row['can_cancel']);
        $this->assertSame('신고 대상 제목', $row['title'], '제목은 신고 시점 스냅샷에서 읽는다.');
        $this->assertSame($postId, $row['post_id']);
    }

    /**
     * 처리자 정보·다른 신고자·신고자 메타는 응답에 없다.
     *
     * @scenario case=list_excludes_processor_and_other_reporters
     *
     * @effects excludes_processor_metadata_and_other_reporters
     *
     * @return void 반환값 없음
     */
    public function test_index_excludes_processor_metadata_and_other_reporters(): void
    {
        $postId = $this->createTestPost();
        $this->seedReport($postId);

        $row = $this->actingAs($this->reporter, 'sanctum')->getJson(self::ENDPOINT)->json('data.data.0');

        foreach (['processed_by', 'processed_at', 'process_histories', 'metadata', 'author_id', 'report_count', 'logs'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $row, "{$forbidden} 는 신고자 화면에 노출하지 않습니다.");
        }
    }

    /**
     * 상태·대상 구분·게시판 필터와 스냅샷 JSON 검색이 동작한다.
     *
     * @scenario case=filters_status_target_board_and_snapshot_search
     *
     * @effects filters_status_target_type_board_and_json_extract_search
     *
     * @return void 반환값 없음
     */
    public function test_index_filters_status_target_type_board_and_json_extract_search(): void
    {
        $postId = $this->createTestPost();
        $commentPostId = $this->createTestPost();
        $commentId = $this->createTestComment($commentPostId);

        $this->seedReport($postId, snapshot: ['title' => '스팸 광고글']);
        $this->seedReport($commentId, targetType: 'comment', status: ReportStatus::Rejected, snapshot: ['title' => '욕설 댓글']);

        $act = fn (string $qs) => $this->actingAs($this->reporter, 'sanctum')->getJson(self::ENDPOINT.$qs);

        $act('?status=pending')->assertOk()->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.status', 'pending');

        $act('?target_type=comment')->assertOk()->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.target_type', 'comment');

        $act('?board_slug='.$this->board->slug)->assertOk()->assertJsonCount(2, 'data.data');

        // 스냅샷 JSON 검색
        $act('?search=스팸&search_field=title')->assertOk()->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.title', '스팸 광고글');

        // 잘못된 상태는 422
        $act('?status=unknown')->assertStatus(422);

        // 잘못된 검색 대상은 기본(제목+내용)으로 처리한다 (7.1.0 실측 O-K3-3)
        $act('?search=스팸&search_field=author')->assertOk()->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.title', '스팸 광고글')
            ->assertJsonPath('data.query.search_field', 'title_content');
    }

    /**
     * 마지막 신고자가 취소하면 로그와 케이스가 함께 사라진다.
     *
     * @scenario case=cancel_pending_last_reporter_force_deletes_case
     *
     * @effects cancel_deletes_log_and_force_deletes_case_when_last
     *
     * @return void 반환값 없음
     */
    public function test_cancel_pending_deletes_log_and_force_deletes_case_when_last(): void
    {
        $postId = $this->createTestPost();
        [$report, $log] = $this->seedReport($postId);

        $this->actingAs($this->reporter, 'sanctum')
            ->deleteJson(self::ENDPOINT.'/'.$log->id)
            ->assertOk()
            ->assertJsonPath('data.case_deleted', true);

        $this->assertDatabaseMissing('boards_report_logs', ['id' => $log->id]);
        $this->assertSame(0, Report::withTrashed()->where('id', $report->id)->count(), '케이스는 영구 삭제되어야 합니다.');
    }

    /**
     * 다른 신고자가 남아 있으면 케이스는 유지되고 이력만 추가된다.
     *
     * @scenario case=cancel_pending_keeps_case_when_others_remain
     *
     * @effects cancel_keeps_case_and_appends_history_when_others_remain
     *
     * @return void 반환값 없음
     */
    public function test_cancel_pending_keeps_case_and_appends_history_when_others_remain(): void
    {
        $postId = $this->createTestPost();
        [$report, $log] = $this->seedReport($postId);

        ReportLog::factory()->create([
            'report_id' => $report->id,
            'reporter_id' => $this->createUser()->id,
        ]);

        $this->actingAs($this->reporter, 'sanctum')
            ->deleteJson(self::ENDPOINT.'/'.$log->id)
            ->assertOk()
            ->assertJsonPath('data.case_deleted', false)
            ->assertJsonPath('data.remaining', 1);

        $fresh = Report::find($report->id);
        $this->assertNotNull($fresh, '다른 신고자가 남아 있으면 케이스는 유지됩니다.');

        $types = array_column($fresh->process_histories ?? [], 'type');
        $this->assertContains('report_cancelled', $types, '취소 이력이 남아야 합니다.');
    }

    /**
     * 접수 상태가 아니면 403.
     *
     * @scenario case=cancel_non_pending_forbidden
     *
     * @effects cancel_non_pending_returns_403
     *
     * @return void 반환값 없음
     */
    public function test_cancel_non_pending_returns_403(): void
    {
        $postId = $this->createTestPost();
        [, $log] = $this->seedReport($postId, status: ReportStatus::Rejected);

        $this->actingAs($this->reporter, 'sanctum')
            ->deleteJson(self::ENDPOINT.'/'.$log->id)
            ->assertForbidden()
            ->assertJsonPath('errors.code', 'report_cancel_not_allowed');

        $this->assertDatabaseHas('boards_report_logs', ['id' => $log->id]);
    }

    /**
     * 남의 신고 로그는 404 (존재 여부를 흘리지 않는다).
     *
     * @scenario case=cancel_foreign_log_not_found
     *
     * @effects cancel_foreign_log_returns_404
     *
     * @return void 반환값 없음
     */
    public function test_cancel_foreign_log_returns_404(): void
    {
        $postId = $this->createTestPost();
        [, $log] = $this->seedReport($postId, reporter: $this->createUser());

        $this->actingAs($this->reporter, 'sanctum')
            ->deleteJson(self::ENDPOINT.'/'.$log->id)
            ->assertNotFound();

        $this->assertDatabaseHas('boards_report_logs', ['id' => $log->id]);
    }

    /**
     * 취소 후 같은 대상을 다시 신고할 수 있다 (케이스를 영구 삭제하는 이유).
     *
     * @scenario case=re_report_after_cancel_succeeds
     *
     * @effects re_report_after_cancel_succeeds
     *
     * @return void 반환값 없음
     */
    public function test_re_report_after_cancel_succeeds(): void
    {
        $postId = $this->createTestPost();
        [$report, $log] = $this->seedReport($postId);

        $this->actingAs($this->reporter, 'sanctum')
            ->deleteJson(self::ENDPOINT.'/'.$log->id)
            ->assertOk();

        // 같은 (board, target) 로 케이스를 다시 만들 수 있어야 한다 —
        // 소프트 삭제로 남아 있었다면 unique 제약에 걸린다.
        $recreated = Report::factory()->create([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'status' => ReportStatus::Pending,
        ]);

        $this->assertNotNull($recreated->id);
        $this->assertNotSame($report->id, $recreated->id);
    }

    /**
     * 비로그인은 401.
     *
     * @scenario case=anonymous_user_unauthorized
     *
     * @effects guest_returns_401
     *
     * @return void 반환값 없음
     */
    public function test_unauthenticated_401(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->deleteJson(self::ENDPOINT.'/1')->assertUnauthorized();
    }

    /**
     * 신고 취소는 활동 로그를 남긴다.
     *
     * `report.after_cancel` 훅은 발행만 되고 구독자가 없던 구간이 있었다 — 그때는 취소가
     * 아무 흔적도 남기지 않았다. 케이스가 forceDelete 된 뒤에도 로그가 남아야 하므로
     * loggable 모델이 아니라 loggable_type/loggable_id 를 직접 싣는지까지 잠근다.
     *
     * @scenario case=cancel_records_activity_log
     *
     * @effects cancel_writes_activity_log_with_explicit_loggable
     *
     * @return void 반환값 없음
     */
    public function test_cancel_writes_activity_log(): void
    {
        $postId = $this->createTestPost();
        [$report, $log] = $this->seedReport($postId);

        $this->actingAs($this->reporter, 'sanctum')
            ->deleteJson(self::ENDPOINT.'/'.$log->id)
            ->assertOk();

        $row = DB::table('activity_logs')
            ->where('action', 'report.cancel')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($row, '신고를 취소했는데 활동 로그가 남지 않았다 — after_cancel 구독 누락');
        $this->assertSame(Report::class, $row->loggable_type);
        $this->assertSame($report->id, (int) $row->loggable_id);
        $this->assertSame(
            'sirsoft-board::activity_log.description.report_cancel',
            $row->description_key,
        );
    }
}
