<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 게시판 관리자 게시글·댓글 화면의 서버 판정 회귀 테스트 (7.1.0 abilities 게이트 최종 점검 board-admin-b)
 *
 * - 댓글 본인 판정 권한명이 config 에 없는 'admin.write' 라 댓글 쓰기 권한만 가진 작성자도 자기 댓글을 못 고쳤다(#1)
 * - 관리자 글 저장·수정 API 가 공지 지정 권한을 보지 않아 본문에 is_notice 를 넣으면 공지가 등록됐다(S1·G30)
 * - 그 판정이 관리 권한(admin.manage)만 인정해 공지 등록 권한(posts.write-notice)자는 사용자 경로에선 되는 공지를
 *   관리자 경로에선 지정하지 못했다 — 사용자 경로와 같은 판정(canSetNotice)으로 맞추고 form-meta 가 can_write_notice 를 싣는다(B7-2)
 * - 남의 글 수정 폼이 열린 채 편집되고 저장할 때만 403 이었다 — form-meta 가 저장과 같은 판정(can_modify)을 싣는다(#4)
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/board-admin-post-comment-gate.yaml 참조.
 */
class AdminPostCommentAbilityGateTest extends BoardTestCase
{
    /**
     * 이 테스트가 쓰는 게시판 slug 를 돌려줍니다.
     *
     * @return string 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'admin-post-comment-gate';
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
            'name' => ['ko' => '게시글 댓글 게이트 게시판', 'en' => 'Post Comment Gate Board'],
            'is_active' => true,
            'use_comment' => true,
            'max_comment_depth' => 10,
        ];
    }

    /**
     * 주어진 게시판 권한만 가진 관리자를 만듭니다(공유 admin 역할을 쓰지 않는다).
     *
     * @param  array<int, string>  $actions  게시판 권한 액션(예: admin.posts.write)
     * @return User 관리자
     */
    private function boardAdminWith(array $actions): User
    {
        $role = Role::create([
            'identifier' => 'board_gate_'.uniqid(),
            'name' => ['ko' => '게이트 역할', 'en' => 'Gate Role'],
            'is_active' => true,
        ]);

        foreach ($actions as $action) {
            $identifier = "sirsoft-board.{$this->board->slug}.{$action}";
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                ['name' => ['ko' => $identifier, 'en' => $identifier], 'type' => 'admin']
            );
            $role->permissions()->attach($permission->id, ['scope_type' => null, 'granted_at' => now()]);
        }

        $user = User::factory()->create(['status' => 'active']);
        $user->roles()->attach($role->id);
        $this->resetPermissionMiddlewareCache();

        return $user->fresh();
    }

    /**
     * 관리자 댓글 API 경로를 돌려줍니다.
     *
     * @param  int  $postId  게시글 ID
     * @param  int  $commentId  댓글 ID
     * @return string 댓글 API URL
     */
    private function commentUrl(int $postId, int $commentId): string
    {
        return "/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$postId}/comments/{$commentId}";
    }

    /**
     * 댓글 쓰기 권한만 가진 작성자는 관리 권한 없이도 자기 댓글을 고칠 수 있다.
     *
     * @scenario case=comment_owner_write_only
     *
     * @effects own_comment_editable_with_comments_write
     *
     * @return void 반환값 없음
     */
    public function test_comment_author_with_comments_write_can_update_own_comment(): void
    {
        $author = $this->boardAdminWith(['admin.posts.read', 'admin.comments.read', 'admin.comments.write']);
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, ['user_id' => $author->id, 'author_name' => $author->name]);

        $this->actingAs($author)
            ->putJson($this->commentUrl($postId, $commentId), ['content' => '본인이 고친 댓글'])
            ->assertOk();

        $this->assertDatabaseHas('board_comments', ['id' => $commentId, 'content' => '본인이 고친 댓글']);
    }

    /**
     * 남의 댓글은 관리 권한 없이 고칠 수 없다(종전 그대로).
     *
     * @scenario case=comment_other_write_only
     *
     * @effects other_comment_not_editable_without_manage
     *
     * @return void 반환값 없음
     */
    public function test_comment_writer_cannot_update_others_comment_without_manage(): void
    {
        $writer = $this->boardAdminWith(['admin.posts.read', 'admin.comments.read', 'admin.comments.write']);
        $other = User::factory()->create();
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, ['user_id' => $other->id, 'content' => '남의 댓글']);

        $this->actingAs($writer)
            ->putJson($this->commentUrl($postId, $commentId), ['content' => '바꿔 보기'])
            ->assertForbidden();
    }

    /**
     * 글쓰기 권한만으로 관리자 글 등록 본문에 is_notice=true 를 넣으면 403 이다.
     *
     * @scenario case=notice_store_without_manage
     *
     * @effects notice_requires_manage_on_server
     *
     * @return void 반환값 없음
     */
    public function test_store_with_notice_is_forbidden_without_manage(): void
    {
        $writer = $this->boardAdminWith(['admin.posts.read', 'admin.posts.write']);

        $this->actingAs($writer)
            ->postJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts", [
                'title' => '공지 시도 제목',
                'content' => '공지로 올려 보는 본문입니다.',
                'is_notice' => true,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('board_posts', ['title' => '공지 시도 제목']);
    }

    /**
     * 관리 권한이 있으면 공지로 등록된다.
     *
     * @scenario case=notice_store_with_manage
     *
     * @effects notice_requires_manage_on_server
     *
     * @return void 반환값 없음
     */
    public function test_store_with_notice_succeeds_with_manage(): void
    {
        $manager = $this->boardAdminWith(['admin.posts.read', 'admin.posts.write', 'admin.manage']);

        $this->actingAs($manager)
            ->postJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts", [
                'title' => '관리자 공지 제목',
                'content' => '관리 권한으로 올리는 공지 본문입니다.',
                'is_notice' => true,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('board_posts', ['title' => '관리자 공지 제목', 'is_notice' => true]);
    }

    /**
     * 본인 글이라도 관리 권한 없이 공지 여부를 바꾸면 403 이고, 같은 값의 평소 저장은 된다.
     *
     * @scenario case=notice_update_without_manage
     *
     * @effects notice_requires_manage_on_server
     *
     * @return void 반환값 없음
     */
    public function test_update_changing_notice_is_forbidden_without_manage(): void
    {
        $author = $this->boardAdminWith(['admin.posts.read', 'admin.posts.write']);
        $postId = $this->createTestPost(['user_id' => $author->id, 'author_name' => $author->name]);
        $url = "/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/{$postId}";

        $this->actingAs($author)
            ->putJson($url, ['title' => '본인 글 제목', 'content' => '본인 글 본문입니다.', 'is_notice' => true])
            ->assertForbidden();
        $this->assertFalse((bool) DB::table('board_posts')->where('id', $postId)->value('is_notice'));

        $this->actingAs($author)
            ->putJson($url, ['title' => '본인 글 제목', 'content' => '본인 글 본문입니다.', 'is_notice' => false])
            ->assertOk();
    }

    /**
     * form-meta 는 저장과 같은 판정(can_modify)을 싣는다 — 남의 글은 false, 본인 글은 true.
     *
     * @scenario case=form_meta_can_modify
     *
     * @effects form_meta_reports_can_modify
     *
     * @return void 반환값 없음
     */
    public function test_form_meta_reports_can_modify_by_ownership(): void
    {
        $writer = $this->boardAdminWith(['admin.posts.read', 'admin.posts.write']);
        $other = User::factory()->create();
        $othersPost = $this->createTestPost(['user_id' => $other->id]);
        $ownPost = $this->createTestPost(['user_id' => $writer->id]);
        $base = "/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/form-meta?post_id=";

        $this->actingAs($writer)->getJson($base.$othersPost)
            ->assertOk()
            ->assertJsonPath('data.can_modify', false);

        $this->actingAs($writer)->getJson($base.$ownPost)
            ->assertOk()
            ->assertJsonPath('data.can_modify', true);
    }

    /**
     * 게시판 관리자 역할 대신 공지 등록 권한(posts.write-notice, 사용자 권한)을 준 관리자를 만듭니다.
     *
     * @param  array<int, string>  $adminActions  함께 줄 관리자 게시판 권한 액션
     * @return User 관리자
     */
    private function boardAdminWithWriteNotice(array $adminActions): User
    {
        $user = $this->boardAdminWith($adminActions);
        $role = $user->roles()->first();
        $identifier = "sirsoft-board.{$this->board->slug}.posts.write-notice";
        $permission = Permission::firstOrCreate(
            ['identifier' => $identifier],
            ['name' => ['ko' => '공지 등록', 'en' => 'Create Notice Posts'], 'type' => 'user']
        );
        $role->permissions()->attach($permission->id, ['scope_type' => null, 'granted_at' => now()]);
        $this->resetPermissionMiddlewareCache();

        return $user->fresh();
    }

    /**
     * 공지 등록 권한만 있고 관리 권한이 없어도 관리자 글 등록·수정으로 공지를 지정·해제할 수 있다 — 사용자 경로
     * (canSetNotice)와 같은 판정이다(B7-2 회귀 잠금).
     *
     * @scenario case=notice_with_write_notice_only
     *
     * @effects notice_requires_manage_or_write_notice_on_server
     *
     * @return void 반환값 없음
     */
    public function test_write_notice_holder_can_set_notice_on_admin_route(): void
    {
        $holder = $this->boardAdminWithWriteNotice(['admin.posts.read', 'admin.posts.write']);
        $base = "/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts";

        $created = $this->actingAs($holder)
            ->postJson($base, [
                'title' => '공지 담당 관리자 공지',
                'content' => '공지 등록 권한으로 올리는 공지 본문입니다.',
                'is_notice' => true,
            ])
            ->assertCreated();
        $this->assertTrue((bool) DB::table('board_posts')->where('id', $created->json('data.id'))->value('is_notice'));

        $postId = $this->createTestPost(['user_id' => $holder->id, 'author_name' => $holder->name]);
        $this->actingAs($holder)
            ->putJson("{$base}/{$postId}", ['title' => '본인 글 제목', 'content' => '본인 글 본문입니다.', 'is_notice' => true])
            ->assertOk();
        $this->assertTrue((bool) DB::table('board_posts')->where('id', $postId)->value('is_notice'));

        $this->actingAs($holder)
            ->putJson("{$base}/{$postId}", ['title' => '본인 글 제목', 'content' => '본인 글 본문입니다.', 'is_notice' => false])
            ->assertOk();
        $this->assertFalse((bool) DB::table('board_posts')->where('id', $postId)->value('is_notice'));
    }

    /**
     * 관리자 글쓰기 form-meta 의 can_write_notice 는 서버 공지 판정과 같다 — 공지 등록 권한·관리 권한이면 true,
     * 글쓰기 권한뿐이면 false. 화면의 공지 체크박스가 이 값으로 잠긴다.
     *
     * @scenario case=form_meta_can_write_notice
     *
     * @effects form_meta_reports_can_write_notice
     *
     * @return void 반환값 없음
     */
    public function test_form_meta_reports_can_write_notice_matching_server(): void
    {
        $url = "/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts/form-meta";

        $holder = $this->boardAdminWithWriteNotice(['admin.posts.read', 'admin.posts.write']);
        $holderMeta = $this->actingAs($holder)->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.board.user_abilities.can_write_notice', true);
        // 관리 권한은 없다(권한 행이 아직 없으면 키가 빠진다) — 공지 체크박스가 can_manage 만 보면 잠기던 경우다
        $this->assertNotTrue($holderMeta->json('data.board.user_abilities.can_manage'));

        $manager = $this->boardAdminWith(['admin.posts.read', 'admin.posts.write', 'admin.manage']);
        $this->actingAs($manager)->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.board.user_abilities.can_write_notice', true);

        $writer = $this->boardAdminWith(['admin.posts.read', 'admin.posts.write']);
        $this->actingAs($writer)->getJson($url)
            ->assertOk()
            ->assertJsonPath('data.board.user_abilities.can_write_notice', false);
    }
}
