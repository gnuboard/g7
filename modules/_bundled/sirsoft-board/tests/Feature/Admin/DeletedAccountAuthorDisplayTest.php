<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Enums\ReportStatus;
use Modules\Sirsoft\Board\Models\Report;
use Modules\Sirsoft\Board\Models\ReportLog;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 회원 계정이 남지 않은(하드 삭제) 작성자의 신고·댓글 표시 검증 (7.1.1 출시 전 점검)
 *
 * board 1.2.1 은 「이름(없으면 「알 수 없음」)만 보이고 회원 상세 링크·메뉴는 나오지 않는다(…댓글·신고 상세)」를
 * 약속했다. 그런데 신고 목록·상세는 그 작성자를 `{uuid:null, name:'비회원', is_guest:true}` 로 내보내
 * 「비회원」 이름과 비회원 배지가 보였다 — 회원 계정이 지워지면 케이스의 author_id 가 FK 로 비워지고, 신고가
 * 계정 삭제 뒤에 접수되면 스냅샷 이름도 비어 비회원과 구분할 근거가 없었다. 댓글 응답도 같은 작성자를
 * is_guest:true 로 내보냈다(게시글은 이미 false).
 *
 * 대조군: 진짜 비회원 글의 신고는 지금처럼 비회원 이름·배지다.
 */
#[Group('board')]
#[Group('admin')]
class DeletedAccountAuthorDisplayTest extends BoardTestCase
{
    private User $admin;

    /**
     * 이 테스트가 쓰는 게시판 slug 를 돌려줍니다.
     *
     * @return string 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'f23-deleted-author';
    }

    /**
     * 신고 조회 권한을 가진 관리자를 준비하고 신고 기록을 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('boards_report_logs')->delete();
        DB::table('boards_reports')->delete();
        $this->updateBoardSettings(['use_report' => true, 'use_comment' => true, 'secret_mode' => 'disabled']);
        $this->setGuestPermissions(['posts.read', 'comments.read']);

        $view = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-board.reports.view'],
            ['name' => ['ko' => '신고 조회', 'en' => 'View Reports'], 'type' => 'admin']
        );
        $adminRole = Role::where('identifier', 'admin')->first();
        $adminRole->permissions()->syncWithoutDetaching([$view->id]);
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($adminRole->id);
        $this->resetPermissionMiddlewareCache();
    }

    /**
     * 회원이 쓴 글을 만들고 그 회원 계정을 하드 삭제한 뒤 신고를 접수한 상태를 만듭니다.
     *
     * 계정 삭제 뒤 접수된 신고는 작성자 조회가 실패해 author_id 가 null, 스냅샷 이름이 빈 값이다(서비스가
     * 만드는 모양 그대로). 글에는 회원 번호가 남는다(board_posts.user_id 는 FK 가 없다).
     *
     * @param  string  $targetType  신고 대상 (post|comment)
     * @return array{report: Report, post_id: int, comment_id: int|null} 만든 신고와 대상 ID
     */
    private function reportOfDeletedMember(string $targetType): array
    {
        $member = User::factory()->create();
        $postId = $this->createTestPost(['user_id' => $member->id, 'author_name' => null, 'title' => 'F23 글']);
        $commentId = $targetType === 'comment'
            ? $this->createTestComment($postId, ['user_id' => $member->id, 'author_name' => null, 'content' => 'F23 댓글'])
            : null;

        DB::table('users')->where('id', $member->id)->delete();

        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'target_type' => $targetType,
            'target_id' => $commentId ?? $postId,
            'author_id' => null,
            'status' => ReportStatus::Pending,
        ]);
        ReportLog::factory()->create([
            'report_id' => $report->id,
            'reporter_id' => $this->admin->id,
            'snapshot' => [
                'board_name' => '게시판',
                'title' => $targetType === 'post' ? 'F23 글' : null,
                'content' => $targetType === 'post' ? 'F23 본문' : 'F23 댓글',
                'content_mode' => 'text',
                'author_name' => '',
            ],
        ]);

        return ['report' => $report, 'post_id' => $postId, 'comment_id' => $commentId];
    }

    /**
     * 신고 목록에서 계정이 남지 않은 회원 작성자는 비회원이 아니다 — 비회원 배지 없음, 이름은 null(화면이 「알 수 없음」).
     *
     * @scenario case=deleted_member_report_list_not_guest
     *
     * @effects deleted_member_report_author_not_guest
     *
     * @return void 반환값 없음
     */
    public function test_report_list_shows_deleted_member_author_as_unknown_not_guest(): void
    {
        $post = $this->reportOfDeletedMember('post');
        $comment = $this->reportOfDeletedMember('comment');

        $response = $this->actingAs($this->admin)->getJson('/api/modules/sirsoft-board/admin/reports');
        $response->assertOk();
        $rows = collect($response->json('data.data'));

        foreach ([$post['report']->id, $comment['report']->id] as $id) {
            $author = $rows->firstWhere('id', $id)['author'] ?? null;
            $this->assertNotNull($author, "신고 {$id} 가 목록에 있어야 한다");
            $this->assertFalse($author['is_guest'], '계정이 남지 않은 회원 작성자에 비회원 배지가 붙으면 안 된다');
            $this->assertNull($author['uuid']);
            $this->assertNull($author['name'], '이름이 없으면 null 이어야 화면이 「알 수 없음」으로 대신 보인다(「비회원」 금지)');
        }
    }

    /**
     * 신고 상세에서도 계정이 남지 않은 회원 작성자는 비회원이 아니다.
     *
     * @scenario case=deleted_member_report_detail_not_guest
     *
     * @effects deleted_member_report_author_not_guest
     *
     * @return void 반환값 없음
     */
    public function test_report_detail_shows_deleted_member_author_as_unknown_not_guest(): void
    {
        $post = $this->reportOfDeletedMember('post');
        $comment = $this->reportOfDeletedMember('comment');

        $postDetail = $this->actingAs($this->admin)->getJson('/api/modules/sirsoft-board/admin/reports/'.$post['report']->id);
        $postDetail->assertOk();
        $this->assertFalse($postDetail->json('data.post.author.is_guest'));
        $this->assertNull($postDetail->json('data.post.author.name'));

        $commentDetail = $this->actingAs($this->admin)->getJson('/api/modules/sirsoft-board/admin/reports/'.$comment['report']->id);
        $commentDetail->assertOk();
        $this->assertFalse($commentDetail->json('data.comment.author.is_guest'));
        $this->assertNull($commentDetail->json('data.comment.author.name'));
    }

    /**
     * 신고 접수 때 대상이 회원 글이면 스냅샷에 회원 표시를 남긴다 — 대상이 지워진 뒤에도 비회원으로 보이지 않게.
     *
     * @scenario case=report_snapshot_marks_member_author
     *
     * @effects deleted_member_report_author_not_guest
     *
     * @return void 반환값 없음
     */
    public function test_report_snapshot_marks_member_author_even_without_account(): void
    {
        $member = User::factory()->create();
        $postId = $this->createTestPost(['user_id' => $member->id, 'author_name' => null]);
        DB::table('users')->where('id', $member->id)->delete();

        $reporter = $this->createUser();
        $this->grantUserRolePermissions(['posts.read']);
        $this->resetPermissionMiddlewareCache();

        $this->actingAs($reporter, 'sanctum')
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/reports", ['reason_type' => 'spam', 'reason_detail' => 'F23 스냅샷'])
            ->assertSuccessful();

        $snapshot = ReportLog::query()->latest('id')->value('snapshot');
        $snapshot = is_array($snapshot) ? $snapshot : json_decode((string) $snapshot, true);
        $this->assertTrue($snapshot['author_is_member'] ?? false, '회원 글 신고의 스냅샷에 회원 표시가 있어야 한다');
    }

    /**
     * 진짜 비회원 글의 신고는 지금처럼 비회원 이름·배지다(대조군).
     *
     * @scenario case=guest_report_still_guest
     *
     * @effects guest_report_author_kept
     *
     * @return void 반환값 없음
     */
    public function test_guest_author_report_is_still_guest(): void
    {
        $postId = $this->createTestPost(['user_id' => null, 'author_name' => '손님']);
        $report = Report::factory()->create([
            'board_id' => $this->board->id,
            'target_type' => 'post',
            'target_id' => $postId,
            'author_id' => null,
            'status' => ReportStatus::Pending,
        ]);
        ReportLog::factory()->create([
            'report_id' => $report->id,
            'reporter_id' => $this->admin->id,
            'snapshot' => ['board_name' => '게시판', 'title' => '손님 글', 'content' => '본문', 'content_mode' => 'text', 'author_name' => '손님'],
        ]);

        $row = collect($this->actingAs($this->admin)->getJson('/api/modules/sirsoft-board/admin/reports')->json('data.data'))
            ->firstWhere('id', $report->id);
        $this->assertTrue($row['author']['is_guest']);
        $this->assertSame('손님', $row['author']['name']);

        $detail = $this->actingAs($this->admin)->getJson('/api/modules/sirsoft-board/admin/reports/'.$report->id);
        $this->assertTrue($detail->json('data.post.author.is_guest'));
        $this->assertSame('손님', $detail->json('data.post.author.name'));
    }

    /**
     * 댓글 응답에서도 계정이 남지 않은 회원 작성자는 비회원이 아니다(게시글 작성자와 같은 판정).
     *
     * @scenario case=deleted_member_comment_not_guest
     *
     * @effects deleted_member_comment_author_not_guest
     *
     * @return void 반환값 없음
     */
    public function test_comment_of_deleted_member_is_not_guest(): void
    {
        $member = User::factory()->create();
        $postId = $this->createTestPost(['user_id' => null, 'author_name' => '손님']);
        $commentId = $this->createTestComment($postId, ['user_id' => $member->id, 'author_name' => null]);
        $guestCommentId = $this->createTestComment($postId, ['user_id' => null, 'author_name' => '손님']);
        DB::table('users')->where('id', $member->id)->delete();

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments");
        $response->assertOk();
        $comments = collect($response->json('data'));

        $this->assertFalse($comments->firstWhere('id', $commentId)['author']['is_guest']);
        $this->assertTrue($comments->firstWhere('id', $guestCommentId)['author']['is_guest'], '비회원 댓글은 그대로 비회원이다');
    }
}
