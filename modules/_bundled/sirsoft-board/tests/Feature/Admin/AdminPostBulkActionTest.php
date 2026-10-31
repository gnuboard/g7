<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Extension\Helpers\IdentityPolicySyncHelper;
use App\Extension\HookManager;
use App\Listeners\Identity\EnforceIdentityPolicyListener;
use App\Models\IdentityPolicy;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Enums\ReplyDeletePolicy;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Module;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 관리자 게시글 일괄 처리 API 테스트 (삭제 · 블라인드 · 복구)
 *
 * 검증 목적 (이슈 #524 §4.4 — 관리자 게시글 목록에 선택 일괄 처리가 없던 결함):
 * - 권한: 일괄 3종은 모두 admin.manage 필요 (posts.write 만으로는 403)
 * - 건너뛰기: 이미 그 상태인 글, 답글 정책이 block 인데 답글이 있는 글은 건너뛰고 사유를 싣는다
 * - 훅: 일괄 훅은 **루프 앞에서 한 번만** 발화한다 (항목별 before_* 를 타면 IDV 가 루프 중간에 터진다)
 * - 단건 경로 회귀: 리팩터 후에도 deletePost 가 before_delete 를 그대로 발화한다
 *
 * URL:
 * - DELETE /api/modules/sirsoft-board/admin/board/{slug}/posts/bulk
 * - PATCH  /api/modules/sirsoft-board/admin/board/{slug}/posts/bulk-blind
 * - PATCH  /api/modules/sirsoft-board/admin/board/{slug}/posts/bulk-restore
 *
 * @scenario surface=admin_post_list,action=bulk_delete,actor=admin
 *
 * @effects admin_post_bulk_requires_manage_permission, admin_post_bulk_fires_hook_once
 */
#[Group('board')]
#[Group('admin')]
#[Group('post')]
class AdminPostBulkActionTest extends BoardTestCase
{
    private User $adminWithManage;

    private User $writerOnly;

    /**
     * 이 테스트가 쓰는 게시판 슬러그를 돌려줍니다.
     *
     * @return string 게시판 슬러그
     */
    protected function getTestBoardSlug(): string
    {
        return 'admin-post-bulk';
    }

    /**
     * 답글을 켠 테스트 게시판 속성을 돌려줍니다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return array<string, mixed> 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '관리자 일괄 처리 테스트 게시판', 'en' => 'Admin Bulk Action Test Board'],
            'is_active' => true,
            'use_reply' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
        ];
    }

    /**
     * admin.manage 를 가진 관리자와 posts.write 만 가진 운영자를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $slug = $this->board->slug;

        $this->adminWithManage = $this->createAdminUser([
            "sirsoft-board.{$slug}.admin.manage",
            "sirsoft-board.{$slug}.admin.posts.read",
        ]);

        // posts.write 만 가진 운영자 — 단건 삭제는 본인 글에 한해 가능하지만
        // 일괄 3종은 admin.manage 를 요구하므로 막혀야 한다.
        $this->writerOnly = $this->createWriterOnlyAdmin($slug);
    }

    // ==========================================
    // 권한
    // ==========================================

    /**
     * admin.manage 없는 운영자의 일괄 삭제 → 403.
     *
     * @effects admin_post_bulk_requires_manage_permission
     *
     * @return void 반환값 없음
     */
    public function test_bulk_destroy_requires_manage_permission(): void
    {
        $postId = $this->createTestPost();

        $this->actingAs($this->writerOnly)
            ->deleteJson($this->url('/bulk'), ['ids' => [$postId]])
            ->assertStatus(403);

        $this->assertDatabaseHas('board_posts', ['id' => $postId, 'deleted_at' => null]);
    }

    // ==========================================
    // 일괄 삭제
    // ==========================================

    /**
     * 답글 삭제 정책이 block 이면 답글 있는 글은 건너뛰고 사유를 싣는다.
     *
     * @effects admin_post_bulk_delete_skips_posts_with_replies
     *
     * @return void 반환값 없음
     */
    public function test_bulk_destroy_deletes_selected_and_skips_posts_with_replies_when_policy_block(): void
    {
        DB::table('boards')->where('id', $this->board->id)
            ->update(['reply_delete_policy' => ReplyDeletePolicy::Block->value]);

        $plain = $this->createTestPost(['title' => '답글 없는 글']);
        $parent = $this->createTestPost(['title' => '답글 있는 글']);
        $this->createTestPost(['title' => '답글', 'parent_id' => $parent]);

        $response = $this->actingAs($this->adminWithManage)
            ->deleteJson($this->url('/bulk'), ['ids' => [$plain, $parent]]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.affected_count', 1);
        $response->assertJsonPath('data.skipped.0.id', $parent);
        $response->assertJsonPath('data.skipped.0.reason', 'has_replies');

        $this->assertNotNull(DB::table('board_posts')->where('id', $plain)->value('deleted_at'));
        $this->assertNull(DB::table('board_posts')->where('id', $parent)->value('deleted_at'));
    }

    /**
     * 이미 삭제된 글은 건너뛴다 (already_deleted).
     *
     * @effects admin_post_bulk_delete_skips_already_deleted
     *
     * @return void 반환값 없음
     */
    public function test_bulk_destroy_skips_already_deleted(): void
    {
        $alive = $this->createTestPost(['title' => '살아있는 글']);
        $deleted = $this->createTestPost(['title' => '이미 삭제된 글']);

        DB::table('board_posts')->where('id', $deleted)
            ->update(['deleted_at' => now(), 'status' => 'deleted']);

        $response = $this->actingAs($this->adminWithManage)
            ->deleteJson($this->url('/bulk'), ['ids' => [$alive, $deleted]]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.affected_count', 1);
        $response->assertJsonPath('data.skipped.0.id', $deleted);
        $response->assertJsonPath('data.skipped.0.reason', 'already_deleted');
    }

    // ==========================================
    // 일괄 블라인드 · 복구
    // ==========================================

    /**
     * 일괄 블라인드 → status=blinded, 사유가 이력에 남는다.
     *
     * @effects admin_post_bulk_blind_changes_status
     *
     * @return void 반환값 없음
     */
    public function test_bulk_blind_changes_status_and_records_reason(): void
    {
        $first = $this->createTestPost(['title' => '블라인드 1']);
        $second = $this->createTestPost(['title' => '블라인드 2']);

        $response = $this->actingAs($this->adminWithManage)
            ->patchJson($this->url('/bulk-blind'), ['ids' => [$first, $second], 'reason' => '운영 정책 위반']);

        $response->assertStatus(200);
        $response->assertJsonPath('data.affected_count', 2);

        foreach ([$first, $second] as $id) {
            $this->assertSame('blinded', DB::table('board_posts')->where('id', $id)->value('status'));
        }

        // 사유는 작업 이력(action_logs JSON)에 남는다 — 단건 블라인드와 같은 자리다.
        // 컬럼은 유니코드 이스케이프된 JSON 이라 문자열 비교 전에 디코딩한다.
        $logs = json_decode((string) DB::table('board_posts')->where('id', $first)->value('action_logs'), true);
        $this->assertIsArray($logs);
        $this->assertSame('blind', $logs[0]['action']);
        $this->assertSame('운영 정책 위반', $logs[0]['reason']);
    }

    /**
     * 일괄 블라인드는 삭제된 글을 건너뛰고 「이미 삭제된 글」 사유를 싣는다 — 삭제가 풀리지 않는다.
     *
     * 종전에는 삭제된 글도 블라인드되며 deleted_at 이 지워져 되살아났다(브라우저 실측 O-K3T-2).
     *
     * @effects admin_post_bulk_blind_skips_deleted
     *
     * @return void 반환값 없음
     */
    public function test_bulk_blind_skips_deleted_post_and_keeps_it_deleted(): void
    {
        $alive = $this->createTestPost(['title' => '게시 중인 글']);
        $deleted = $this->createTestPost([
            'title' => '지워진 글',
            'status' => 'deleted',
            'trigger_type' => 'user',
            'deleted_at' => now(),
        ]);

        $response = $this->actingAs($this->adminWithManage)
            ->withHeader('Accept-Language', 'ko')
            ->patchJson($this->url('/bulk-blind'), ['ids' => [$alive, $deleted]]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.affected_count', 1);
        $response->assertJsonPath('data.skipped.0.id', $deleted);
        $response->assertJsonPath('data.skipped.0.reason', 'already_deleted');
        $response->assertJsonPath('data.skipped.0.reason_label', '이미 삭제된 글');

        $row = DB::table('board_posts')->where('id', $deleted)->first();
        $this->assertSame('deleted', $row->status);
        $this->assertNotNull($row->deleted_at);
    }

    /**
     * 다른 게시판의 글·없는 번호는 「처리 중 오류」가 아니라 「찾을 수 없는 글」 사유로 건너뛴다.
     *
     * @effects admin_post_bulk_skips_missing_with_not_found_reason
     *
     * @return void 반환값 없음
     */
    public function test_bulk_action_reports_not_found_for_other_board_or_missing_id(): void
    {
        $alive = $this->createTestPost(['title' => '이 게시판 글']);
        $otherBoard = Board::updateOrCreate(
            ['slug' => 'admin-post-bulk-other'],
            $this->getDefaultBoardAttributes('admin-post-bulk-other'),
        );
        DB::table('board_posts')->where('board_id', $otherBoard->id)->delete();
        $otherBoardPost = DB::table('board_posts')->insertGetId([
            'board_id' => $otherBoard->id,
            'title' => '다른 게시판 글',
            'content' => '내용',
            'author_name' => '테스트',
            'ip_address' => '127.0.0.1',
            'status' => 'published',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->adminWithManage)
            ->withHeader('Accept-Language', 'en')
            ->patchJson($this->url('/bulk-blind'), ['ids' => [$alive, $otherBoardPost, 999999]]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.affected_count', 1);
        $response->assertJsonPath('data.skipped.0.reason', 'not_found');
        $response->assertJsonPath('data.skipped.0.reason_label', 'Post not found in this board');
        $response->assertJsonPath('data.skipped.1.reason', 'not_found');

        // 다른 게시판 글은 건드리지 않는다
        $this->assertSame('published', DB::table('board_posts')->where('id', $otherBoardPost)->value('status'));
    }

    /**
     * 일괄 복구 → 블라인드된 글과 삭제된 글 모두 published 로 돌아온다.
     *
     * @effects admin_post_bulk_restore_restores_blinded_and_deleted
     *
     * @return void 반환값 없음
     */
    public function test_bulk_restore_restores_blinded_and_deleted(): void
    {
        $blinded = $this->createTestPost(['title' => '블라인드된 글', 'status' => 'blinded']);
        $deleted = $this->createTestPost(['title' => '삭제된 글']);

        $this->actingAs($this->adminWithManage)
            ->deleteJson($this->url('/bulk'), ['ids' => [$deleted]])
            ->assertStatus(200);

        $response = $this->actingAs($this->adminWithManage)
            ->patchJson($this->url('/bulk-restore'), ['ids' => [$blinded, $deleted], 'reason' => '오탐 정정']);

        $response->assertStatus(200);
        $response->assertJsonPath('data.affected_count', 2);

        foreach ([$blinded, $deleted] as $id) {
            $row = DB::table('board_posts')->where('id', $id)->first();
            $this->assertSame('published', $row->status);
            $this->assertNull($row->deleted_at);
        }
    }

    /**
     * 이미 게시 상태인 글의 복구는 건너뛴다.
     *
     * @effects admin_post_bulk_restore_skips_already_published
     *
     * @return void 반환값 없음
     */
    public function test_bulk_restore_skips_already_published(): void
    {
        $published = $this->createTestPost(['title' => '이미 게시중']);

        $response = $this->actingAs($this->adminWithManage)
            ->patchJson($this->url('/bulk-restore'), ['ids' => [$published]]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.affected_count', 0);
        $response->assertJsonPath('data.skipped.0.reason', 'already_published');
    }

    /**
     * 건너뛴 항목은 화면이 그대로 보일 수 있게 글 제목과 읽을 수 있는 사유를 함께 싣는다.
     *
     * 종전에는 사유 코드(`has_replies` 등)만 실려, 화면은 「건너뜀 N개」 개수만 알렸다 —
     * 어느 글이 왜 빠졌는지 운영자가 알 길이 없었다(브라우저 실측 2026-10-03).
     *
     * @effects admin_post_bulk_skipped_items_carry_title_and_reason_label
     *
     * @return void 반환값 없음
     */
    public function test_bulk_skipped_items_carry_title_and_reason_label(): void
    {
        $blinded = $this->createTestPost(['title' => '이미 블라인드된 글', 'status' => 'blinded']);

        $response = $this->actingAs($this->adminWithManage)
            ->patchJson($this->url('/bulk-blind'), ['ids' => [$blinded]]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.skipped.0.id', $blinded);
        $response->assertJsonPath('data.skipped.0.reason', 'already_blinded');
        $response->assertJsonPath('data.skipped.0.title', '이미 블라인드된 글');
        $response->assertJsonPath(
            'data.skipped.0.reason_label',
            __('sirsoft-board::messages.posts_bulk.skip_reasons.already_blinded'),
        );
        $this->assertNotSame(
            'sirsoft-board::messages.posts_bulk.skip_reasons.already_blinded',
            $response->json('data.skipped.0.reason_label'),
        );
    }

    // ==========================================
    // 훅 발화 횟수
    // ==========================================

    /**
     * 일괄 훅은 항목 수와 무관하게 **한 번만** 발화한다.
     *
     * 항목별 before_delete 를 타면 IDV 정책이 루프 중간에 428 을 던져 앞부분만 처리된
     * 상태로 끝난다 — 그 사고를 막는 설계라서 횟수 자체가 계약이다.
     *
     * @effects admin_post_bulk_fires_hook_once
     *
     * @return void 반환값 없음
     */
    public function test_bulk_action_fires_bulk_hook_once_not_per_item(): void
    {
        $ids = [
            $this->createTestPost(['title' => '1']),
            $this->createTestPost(['title' => '2']),
            $this->createTestPost(['title' => '3']),
        ];

        $bulkHookCount = 0;
        $perItemHookCount = 0;

        HookManager::addAction('sirsoft-board.post.before_bulk_action', function () use (&$bulkHookCount) {
            $bulkHookCount++;
        }, 99);
        HookManager::addAction('sirsoft-board.post.before_delete', function () use (&$perItemHookCount) {
            $perItemHookCount++;
        }, 99);

        $this->actingAs($this->adminWithManage)
            ->deleteJson($this->url('/bulk'), ['ids' => $ids])
            ->assertStatus(200)
            ->assertJsonPath('data.affected_count', 3);

        $this->assertSame(1, $bulkHookCount, '일괄 훅은 루프 앞에서 한 번만 발화해야 한다');
        $this->assertSame(0, $perItemHookCount, '일괄 경로는 항목별 before_delete 를 타지 않아야 한다');
    }

    /**
     * IDV 정책을 켠 뒤 일괄 삭제 → 428 이고 **한 건도 지워지지 않는다**.
     *
     * 일괄 훅을 루프 «앞» 에서 한 번만 발화하도록 설계한 이유가 바로 이것이다. 항목별
     * before_delete 를 탔다면 정책이 두 번째 항목에서 428 을 던져 첫 항목만 지워진 채
     * 끝난다 — 운영자는 428 을 보고 「아무것도 안 됐다」 고 믿는데 실제로는 일부가 지워진
     * 상태다. 428 과 「DB 불변」을 함께 재야 그 사고를 잡는다.
     *
     * 428 은 `IdentityVerificationRequiredException`(\Error 상속)이라 컨트롤러의
     * `catch (\Exception)` 에 걸리지 않고 통과해야 한다 — 그 경로도 이 테스트가 함께 잰다.
     *
     * @effects admin_post_bulk_returns_428_when_identity_policy_enabled
     *
     * @return void 반환값 없음
     */
    public function test_bulk_action_returns_428_when_identity_policy_enabled_and_deletes_nothing(): void
    {
        $ids = [
            $this->createTestPost(['title' => 'IDV 대상 1']),
            $this->createTestPost(['title' => 'IDV 대상 2']),
        ];

        $this->enableBulkActionIdentityPolicy();

        $response = $this->actingAs($this->adminWithManage)
            ->deleteJson($this->url('/bulk'), ['ids' => $ids]);

        $response->assertStatus(428);
        $response->assertJsonPath('verification.policy_key', 'sirsoft-board.post.bulk_action');

        foreach ($ids as $id) {
            $this->assertNull(
                DB::table('board_posts')->where('id', $id)->value('deleted_at'),
                '428 로 막힌 일괄 삭제는 한 건도 지우면 안 된다'
            );
        }
    }

    /**
     * 리팩터 회귀: 단건 삭제는 여전히 before_delete 를 발화한다.
     *
     * @effects admin_post_single_delete_behaviour_unchanged
     *
     * @return void 반환값 없음
     */
    public function test_single_delete_behaviour_unchanged(): void
    {
        $postId = $this->createTestPost(['title' => '단건 삭제 대상']);

        $fired = 0;
        HookManager::addAction('sirsoft-board.post.before_delete', function () use (&$fired) {
            $fired++;
        }, 99);

        $this->actingAs($this->adminWithManage)
            ->deleteJson($this->url("/{$postId}"))
            ->assertStatus(200);

        $this->assertSame(1, $fired, '단건 삭제 경로의 before_delete 발화가 리팩터로 사라지면 안 된다');
        $this->assertNotNull(DB::table('board_posts')->where('id', $postId)->value('deleted_at'));
    }

    // ==========================================
    // 검증
    // ==========================================

    /**
     * ids 검증 — 누락·빈 배열·상한 초과·정수 아님은 모두 422.
     *
     * @effects admin_post_bulk_validates_ids
     *
     * @return void 반환값 없음
     */
    public function test_bulk_action_validates_ids(): void
    {
        $this->actingAs($this->adminWithManage)
            ->deleteJson($this->url('/bulk'), [])
            ->assertStatus(422);

        $this->actingAs($this->adminWithManage)
            ->deleteJson($this->url('/bulk'), ['ids' => []])
            ->assertStatus(422);

        $this->actingAs($this->adminWithManage)
            ->deleteJson($this->url('/bulk'), ['ids' => range(1, 101)])
            ->assertStatus(422);

        $this->actingAs($this->adminWithManage)
            ->deleteJson($this->url('/bulk'), ['ids' => ['abc']])
            ->assertStatus(422);
    }

    // ==========================================
    // 헬퍼
    // ==========================================

    /**
     * 관리자 게시글 엔드포인트 URL 을 만듭니다.
     *
     * @param  string  $suffix  경로 접미
     * @return string 엔드포인트 URL
     */
    private function url(string $suffix = ''): string
    {
        $slug = $this->board->slug;

        return "/api/modules/sirsoft-board/admin/board/{$slug}/posts{$suffix}";
    }

    /**
     * posts.write 만 가진 운영자를 만듭니다 (admin.manage 없음).
     *
     * createAdminUser 는 공용 admin 역할에 권한을 붙이므로 "manage 없음" 을 만들 수 없다.
     * 전용 역할을 쓰면 admin 미들웨어(관리자 타입 권한 보유)는 통과하면서 manage 만 빠진
     * 상태를 정확히 재현한다.
     *
     * @param  string  $slug  게시판 슬러그
     * @return User 쓰기 전용 운영자
     */
    private function createWriterOnlyAdmin(string $slug): User
    {
        $role = Role::firstOrCreate(
            ['identifier' => 'sirsoft-board.bulk-writer-test'],
            [
                'name' => ['ko' => '게시글 쓰기 전용', 'en' => 'Post Writer'],
                'description' => ['ko' => '테스트용', 'en' => 'For tests'],
            ]
        );

        foreach (["sirsoft-board.{$slug}.admin.posts.read", "sirsoft-board.{$slug}.admin.posts.write"] as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => 'admin']
            );
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $user = User::factory()->create();
        $user->roles()->attach($role->id);

        return $user;
    }

    /**
     * 일괄 처리 IDV 정책을 켜고 동적 훅 구독을 재동기화한다.
     *
     * 모듈이 선언한 정책은 `enabled=false` 로 적재되고, 켠 뒤에는
     * `syncDynamicHookSubscriptions()` 를 불러야 리스너가 그 target 에 실제로 붙는다 —
     * 이 호출을 빼면 정책을 켜도 훅이 조용히 통과해 테스트가 «428 이 안 난다» 로 헛돈다.
     *
     * @return void 반환값 없음
     */
    private function enableBulkActionIdentityPolicy(): void
    {
        $helper = app(IdentityPolicySyncHelper::class);
        $module = new Module('sirsoft-board', $this->getModuleBasePath());

        foreach ($module->getIdentityPolicies() as $policy) {
            $helper->syncPolicy(array_merge($policy, [
                'source_type' => 'module',
                'source_identifier' => 'sirsoft-board',
            ]));
        }

        IdentityPolicy::where('key', 'sirsoft-board.post.bulk_action')->update(['enabled' => true]);

        EnforceIdentityPolicyListener::syncDynamicHookSubscriptions();
    }

    /**
     * 동적 훅 구독은 정적 상태라 테스트 사이에 샌다 — 매 테스트 뒤 해제한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        EnforceIdentityPolicyListener::resetDynamicSubscriptions();

        parent::tearDown();
    }
}
