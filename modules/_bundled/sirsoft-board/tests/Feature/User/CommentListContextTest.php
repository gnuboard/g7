<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Enums\PermissionType;
use App\Enums\ScopeType;
use App\Helpers\PermissionHelper;
use App\Http\Middleware\PermissionMiddleware;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 사용자 댓글 목록 API 의 권한 맥락 테스트 (#142 W9)
 *
 * `GET boards/{slug}/posts/{postId}/comments` 가 댓글 범위 권한을 사용자 맥락
 * (`sirsoft-board.{slug}.comments.read`)으로 판정하고, 삭제 댓글은 게시판 매니저가
 * `del_cmt=1` 을 줄 때만 내보내는지 확인한다 — 게시글 상세 화면의 댓글과 같은 규칙이다.
 */
class CommentListContextTest extends ModuleTestCase
{
    private Board $board;

    private Post $post;

    private User $author;

    private User $selfScopeMember;

    private User $manager;

    private Comment $ownComment;

    private Comment $otherComment;

    private Comment $deletedComment;

    /**
     * 게시판·게시글·댓글 3개(본인 1·타인 1·삭제 1)와 세 시청자를 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->board = Board::factory()->create([
            'slug' => 'i142-cmt-'.uniqid(),
            'is_active' => true,
            'use_comment' => true,
        ]);
        $slug = $this->board->slug;

        // 댓글 열람 권한 — 범위(scope)가 적용되도록 resource_route_key·owner_key 를 단다
        $commentsRead = Permission::create([
            'identifier' => "sirsoft-board.{$slug}.comments.read",
            'name' => ['ko' => 'comments.read', 'en' => 'comments.read'],
            'type' => PermissionType::User,
            'resource_route_key' => 'comment',
            'owner_key' => 'user_id',
        ]);
        $postsRead = Permission::firstOrCreate(
            ['identifier' => "sirsoft-board.{$slug}.posts.read"],
            ['name' => ['ko' => 'posts.read', 'en' => 'posts.read'], 'type' => PermissionType::User]
        );
        $managerPerm = Permission::firstOrCreate(
            ['identifier' => "sirsoft-board.{$slug}.manager"],
            ['name' => ['ko' => 'manager', 'en' => 'manager'], 'type' => PermissionType::User]
        );

        foreach (['guest', 'user'] as $identifier) {
            Role::where('identifier', $identifier)->first()
                ->permissions()->syncWithoutDetaching([$commentsRead->id, $postsRead->id]);
        }

        // 「본인만」 댓글 범위 역할 — user 역할 없이 이 역할만 가진 회원
        $selfRole = Role::create([
            'identifier' => 'i142_self_scope_'.uniqid(),
            'name' => ['ko' => '본인만', 'en' => 'Self only'],
            'is_active' => true,
        ]);
        $selfRole->permissions()->attach($commentsRead->id, ['scope_type' => ScopeType::Self]);
        $selfRole->permissions()->attach($postsRead->id);
        $this->selfScopeMember = User::factory()->create();
        $this->selfScopeMember->roles()->attach($selfRole->id);

        // 게시판 매니저
        $managerRole = Role::create([
            'identifier' => 'i142_manager_'.uniqid(),
            'name' => ['ko' => '매니저', 'en' => 'Manager'],
            'is_active' => true,
        ]);
        $managerRole->permissions()->attach([$commentsRead->id, $postsRead->id, $managerPerm->id]);
        $this->manager = User::factory()->create();
        $this->manager->roles()->attach($managerRole->id);

        $this->author = User::factory()->create();

        $this->post = Post::create([
            'board_id' => $this->board->id,
            'title' => '댓글 맥락 시험 글',
            'content' => '본문',
            'ip_address' => '127.0.0.1',
            'user_id' => $this->author->id,
        ]);

        $this->ownComment = $this->comment($this->selfScopeMember->id, '본인 댓글');
        $this->otherComment = $this->comment($this->author->id, '타인 댓글');
        $this->deletedComment = $this->comment($this->author->id, '삭제된 댓글');
        $this->deletedComment->delete();

        PermissionHelper::clearPermissionScopeCache();
        PermissionMiddleware::clearGuestRoleCache();
    }

    /**
     * 댓글 하나를 만든다.
     *
     * @param  int  $userId  작성자 ID
     * @param  string  $content  내용
     * @return Comment 만든 댓글
     */
    private function comment(int $userId, string $content): Comment
    {
        return Comment::create([
            'board_id' => $this->board->id,
            'post_id' => $this->post->id,
            'user_id' => $userId,
            'content' => $content,
            'depth' => 0,
            'ip_address' => '127.0.0.1',
        ]);
    }

    /**
     * 댓글 목록을 요청해 응답의 댓글 id 목록을 돌려준다.
     *
     * @param  User|null  $viewer  요청자 (null 이면 비회원)
     * @param  int  $delCmt  del_cmt 쿼리 값
     * @return array<int, int> 응답 댓글 id 목록
     */
    private function commentIds(?User $viewer, int $delCmt): array
    {
        $url = "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$this->post->id}/comments";
        if ($delCmt === 1) {
            $url .= '?del_cmt=1';
        }

        $response = $viewer ? $this->actingAs($viewer)->getJson($url) : $this->getJson($url);
        $response->assertOk();

        $rows = $response->json('data.data') ?? $response->json('data') ?? [];

        return collect($rows)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    }

    /**
     * 비회원은 삭제되지 않은 댓글을 모두 받는다.
     *
     * @scenario viewer=guest, del_cmt=0
     *
     * @effects guest_sees_all_visible_comments, user_scope_permission_applied
     *
     * @return void 반환값 없음
     */
    public function test_guest_receives_all_visible_comments(): void
    {
        $this->assertSame(
            collect([$this->ownComment->id, $this->otherComment->id])->sort()->values()->all(),
            $this->commentIds(null, 0)
        );
    }

    /**
     * 비회원이 del_cmt=1 을 줘도 삭제 댓글은 나오지 않는다.
     *
     * @scenario viewer=guest, del_cmt=1
     *
     * @effects deleted_comments_only_for_manager_with_del_cmt
     *
     * @return void 반환값 없음
     */
    public function test_guest_del_cmt_does_not_reveal_deleted_comments(): void
    {
        $this->assertNotContains($this->deletedComment->id, $this->commentIds(null, 1));
    }

    /**
     * 「본인만」 댓글 범위 회원은 자기 댓글만 받는다 — 관리자 권한(admin.comments.read)으로
     * 판정하면 이 회원에게 그 권한이 없어 범위가 풀리고 남의 댓글까지 나간다(수정 전 결함).
     *
     * @scenario viewer=self_scope_member, del_cmt=0
     *
     * @effects self_scope_member_sees_only_own_comments, user_scope_permission_applied
     *
     * @return void 반환값 없음
     */
    public function test_self_scope_member_receives_only_own_comments(): void
    {
        $this->assertSame([$this->ownComment->id], $this->commentIds($this->selfScopeMember, 0));
    }

    /**
     * 「본인만」 회원이 del_cmt=1 을 줘도 범위·삭제 규칙이 그대로다.
     *
     * @scenario viewer=self_scope_member, del_cmt=1
     *
     * @effects self_scope_member_sees_only_own_comments, deleted_comments_only_for_manager_with_del_cmt
     *
     * @return void 반환값 없음
     */
    public function test_self_scope_member_del_cmt_keeps_scope(): void
    {
        $this->assertSame([$this->ownComment->id], $this->commentIds($this->selfScopeMember, 1));
    }

    /**
     * 매니저라도 del_cmt 없이는 삭제 댓글을 받지 않는다(상세 화면 댓글과 같은 규칙).
     *
     * @scenario viewer=manager, del_cmt=0
     *
     * @effects deleted_comments_only_for_manager_with_del_cmt
     *
     * @return void 반환값 없음
     */
    public function test_manager_without_del_cmt_does_not_receive_deleted_comments(): void
    {
        $ids = $this->commentIds($this->manager, 0);

        $this->assertNotContains($this->deletedComment->id, $ids);
        $this->assertContains($this->otherComment->id, $ids);
    }

    /**
     * 매니저가 del_cmt=1 을 주면 삭제 댓글도 받는다.
     *
     * @scenario viewer=manager, del_cmt=1
     *
     * @effects deleted_comments_only_for_manager_with_del_cmt
     *
     * @return void 반환값 없음
     */
    public function test_manager_with_del_cmt_receives_deleted_comments(): void
    {
        $this->assertContains($this->deletedComment->id, $this->commentIds($this->manager, 1));
    }
}
