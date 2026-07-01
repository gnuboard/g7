<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Repositories\Contracts\PostRepositoryInterface;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 부모 댓글 삭제 시 자식 댓글 트리 보존 테스트 (#413-56-2)
 *
 * 부모 댓글을 삭제해도 살아있는 자식 댓글이 트리에서 사라지지 않고,
 * 부모는 tombstone("삭제된 댓글입니다")으로 유지되어야 한다.
 * 일반 조회(withTrashed=false, del_cmt 토글 OFF)에서만 고아 복구가 적용된다.
 *
 * cross product 의 viewer 축은 각 테스트 메서드 docblock 의 @scenario 로 분담한다.
 */
class CommentDeletedParentChildTreeTest extends BoardTestCase
{
    private User $regularUser;

    private User $managerUser;

    protected function getTestBoardSlug(): string
    {
        return 'comment-tree';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '댓글 트리 테스트 게시판', 'en' => 'Comment Tree Test Board'],
            'is_active' => true,
            'use_comment' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $slug = $this->board->slug;

        // 일반 사용자 (posts.read + comments.read 권한)
        $this->regularUser = User::factory()->create();
        $userRole = Role::where('identifier', 'user')->first();
        if ($userRole) {
            foreach (['posts.read', 'comments.read'] as $key) {
                $perm = Permission::firstOrCreate(
                    ['identifier' => "sirsoft-board.{$slug}.{$key}"],
                    ['name' => ['ko' => $key, 'en' => $key], 'type' => 'user']
                );
                $userRole->permissions()->syncWithoutDetaching([$perm->id]);
            }
            $this->regularUser->roles()->attach($userRole->id);
        }

        // manager 권한 사용자
        $this->managerUser = User::factory()->create();
        $managerRole = Role::firstOrCreate(
            ['identifier' => "{$slug}-manager"],
            ['name' => ['ko' => '게시판 매니저', 'en' => 'Board Manager']]
        );
        foreach (['posts.read', 'comments.read', 'manager'] as $key) {
            $perm = Permission::firstOrCreate(
                ['identifier' => "sirsoft-board.{$slug}.{$key}"],
                ['name' => ['ko' => $key, 'en' => $key], 'type' => 'user']
            );
            $managerRole->permissions()->syncWithoutDetaching([$perm->id]);
        }
        $this->managerUser->roles()->attach($managerRole->id);
    }

    /**
     * 게시글 상세를 조회해 댓글 배열을 반환합니다.
     *
     * @param  User|null  $actor  요청 사용자 (null이면 비로그인)
     * @param  int  $postId  게시글 ID
     * @param  string  $query  추가 쿼리스트링 (예: '?del_cmt=1')
     * @return array<int, array<string, mixed>> 댓글 배열
     */
    private function fetchComments(?User $actor, int $postId, string $query = ''): array
    {
        $request = $actor
            ? $this->actingAs($actor, 'sanctum')
            : $this;

        $response = $request->getJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}{$query}"
        );

        $response->assertStatus(200);

        return $response->json('data.comments') ?? [];
    }

    /**
     * 부모 삭제 + 살아있는 자식 → 기본 조회에 tombstone 부모 + 자식 노출
     *
     * @scenario viewer=regular
     *
     * @effects deleted_parent_with_live_child_shown_as_tombstone
     */
    public function test_deleted_parent_with_live_child_shown_as_tombstone(): void
    {
        $postId = $this->createTestPost(['title' => '트리 보존 게시글']);
        $parentId = $this->createTestComment($postId, ['content' => '부모 댓글', 'depth' => 0]);
        $childId = $this->createTestComment($postId, [
            'content' => '자식 댓글',
            'parent_id' => $parentId,
            'depth' => 1,
        ]);

        // 부모만 삭제 (자식은 생존)
        DB::table('board_comments')->where('id', $parentId)->update([
            'status' => 'deleted',
            'deleted_at' => now(),
        ]);

        $comments = $this->fetchComments($this->regularUser, $postId);
        $ids = collect($comments)->pluck('id')->all();

        $this->assertContains($parentId, $ids, '삭제된 부모가 tombstone 으로 트리에 포함되어야 합니다');
        $this->assertContains($childId, $ids, '살아있는 자식 댓글이 노출되어야 합니다');

        // tombstone 부모는 deleted_at 이 세팅되어 있어 프론트가 묘비로 렌더
        $parent = collect($comments)->firstWhere('id', $parentId);
        $this->assertNotNull($parent['deleted_at'] ?? null, 'tombstone 부모는 deleted_at 을 가져야 합니다');
    }

    /**
     * 부모 삭제 + 자식 없음 → 완전 제외 (tombstone 미생성)
     *
     * @scenario viewer=regular
     *
     * @effects deleted_parent_without_child_excluded
     */
    public function test_deleted_parent_without_child_is_excluded(): void
    {
        $postId = $this->createTestPost(['title' => '고립 삭제 게시글']);
        $aliveId = $this->createTestComment($postId, ['content' => '살아있는 댓글', 'depth' => 0]);
        $loneDeletedId = $this->createTestComment($postId, ['content' => '자식 없는 삭제 댓글', 'depth' => 0]);

        DB::table('board_comments')->where('id', $loneDeletedId)->update([
            'status' => 'deleted',
            'deleted_at' => now(),
        ]);

        $ids = collect($this->fetchComments($this->regularUser, $postId))->pluck('id')->all();

        $this->assertContains($aliveId, $ids, '일반 댓글은 노출되어야 합니다');
        $this->assertNotContains($loneDeletedId, $ids, '자식 없는 삭제 댓글은 완전히 제외되어야 합니다');
    }

    /**
     * 다단계(손자) 트리 보존: 부모 삭제 시 자식·손자 모두 노출
     *
     * @scenario viewer=regular
     *
     * @effects nested_grandchild_tree_preserved
     */
    public function test_nested_grandchild_tree_preserved_when_parent_deleted(): void
    {
        $postId = $this->createTestPost(['title' => '다단계 트리 게시글']);
        $pId = $this->createTestComment($postId, ['content' => '부모', 'depth' => 0]);
        $cId = $this->createTestComment($postId, ['content' => '자식', 'parent_id' => $pId, 'depth' => 1]);
        $gcId = $this->createTestComment($postId, ['content' => '손자', 'parent_id' => $cId, 'depth' => 2]);

        DB::table('board_comments')->where('id', $pId)->update([
            'status' => 'deleted',
            'deleted_at' => now(),
        ]);

        $ids = collect($this->fetchComments($this->regularUser, $postId))->pluck('id')->all();

        $this->assertContains($pId, $ids, '삭제 부모는 tombstone 으로 유지');
        $this->assertContains($cId, $ids, '자식 노출');
        $this->assertContains($gcId, $ids, '손자 노출');
    }

    /**
     * 연쇄 삭제: 부모·자식 모두 삭제 + 손자 생존 → 부모·자식 tombstone, 손자 노출
     *
     * @scenario viewer=regular
     *
     * @effects nested_grandchild_tree_preserved
     */
    public function test_chained_deleted_parent_and_child_kept_as_tombstone_when_grandchild_alive(): void
    {
        $postId = $this->createTestPost(['title' => '연쇄 삭제 게시글']);
        $pId = $this->createTestComment($postId, ['content' => '부모', 'depth' => 0]);
        $cId = $this->createTestComment($postId, ['content' => '자식', 'parent_id' => $pId, 'depth' => 1]);
        $gcId = $this->createTestComment($postId, ['content' => '손자', 'parent_id' => $cId, 'depth' => 2]);

        DB::table('board_comments')->whereIn('id', [$pId, $cId])->update([
            'status' => 'deleted',
            'deleted_at' => now(),
        ]);

        $ids = collect($this->fetchComments($this->regularUser, $postId))->pluck('id')->all();

        $this->assertContains($pId, $ids, '삭제 부모 tombstone 유지');
        $this->assertContains($cId, $ids, '삭제 자식 tombstone 유지 (손자가 살아있으므로)');
        $this->assertContains($gcId, $ids, '손자 노출');
    }

    /**
     * 부모·유일 자식 모두 삭제 (손자 없음) → 둘 다 완전 제외
     *
     * @scenario viewer=regular
     *
     * @effects deleted_parent_without_child_excluded
     */
    public function test_parent_and_only_child_both_deleted_are_excluded(): void
    {
        $postId = $this->createTestPost(['title' => '전체 삭제 게시글']);
        $aliveId = $this->createTestComment($postId, ['content' => '살아있는 별개 댓글', 'depth' => 0]);
        $pId = $this->createTestComment($postId, ['content' => '부모', 'depth' => 0]);
        $cId = $this->createTestComment($postId, ['content' => '자식', 'parent_id' => $pId, 'depth' => 1]);

        DB::table('board_comments')->whereIn('id', [$pId, $cId])->update([
            'status' => 'deleted',
            'deleted_at' => now(),
        ]);

        $ids = collect($this->fetchComments($this->regularUser, $postId))->pluck('id')->all();

        $this->assertContains($aliveId, $ids);
        $this->assertNotContains($pId, $ids, '살아있는 자손이 없으므로 부모 제외');
        $this->assertNotContains($cId, $ids, '삭제 자식도 제외');
    }

    /**
     * 비권한자에게 tombstone 부모의 content 는 "삭제된 댓글입니다" 로 필터링
     *
     * @scenario viewer=regular
     *
     * @effects tombstone_content_filtered_for_non_privileged
     */
    public function test_tombstone_content_filtered_for_non_privileged_viewer(): void
    {
        $postId = $this->createTestPost(['title' => 'content 필터 게시글']);
        $pId = $this->createTestComment($postId, ['content' => '부모 원문 SECRET', 'depth' => 0]);
        $this->createTestComment($postId, ['content' => '자식', 'parent_id' => $pId, 'depth' => 1]);

        DB::table('board_comments')->where('id', $pId)->update([
            'status' => 'deleted',
            'deleted_at' => now(),
        ]);

        $parent = collect($this->fetchComments($this->regularUser, $postId))->firstWhere('id', $pId);
        $this->assertNotNull($parent, 'tombstone 부모가 응답에 포함되어야 합니다');
        $this->assertStringNotContainsString('SECRET', (string) $parent['content'], '비권한자에게 원문이 노출되면 안 됩니다');
    }

    /**
     * 비로그인 사용자도 tombstone 부모 + 자식을 본다 (원문은 가림)
     *
     * @scenario viewer=guest
     *
     * @effects deleted_parent_with_live_child_shown_as_tombstone, tombstone_content_filtered_for_non_privileged
     */
    public function test_guest_sees_tombstone_and_child_with_filtered_content(): void
    {
        $postId = $this->createTestPost(['title' => '비로그인 트리 게시글']);
        $pId = $this->createTestComment($postId, ['content' => '부모 원문 SECRET', 'depth' => 0]);
        $cId = $this->createTestComment($postId, ['content' => '자식', 'parent_id' => $pId, 'depth' => 1]);

        DB::table('board_comments')->where('id', $pId)->update([
            'status' => 'deleted',
            'deleted_at' => now(),
        ]);

        $comments = $this->fetchComments(null, $postId);
        $ids = collect($comments)->pluck('id')->all();
        $this->assertContains($pId, $ids);
        $this->assertContains($cId, $ids);

        $parent = collect($comments)->firstWhere('id', $pId);
        $this->assertStringNotContainsString('SECRET', (string) $parent['content']);
    }

    /**
     * manager 는 tombstone 부모의 원문을 본다
     *
     * @scenario viewer=manager
     *
     * @effects manager_sees_tombstone_original_content
     */
    public function test_manager_sees_tombstone_original_content(): void
    {
        $postId = $this->createTestPost(['title' => 'manager 원문 게시글']);
        $pId = $this->createTestComment($postId, ['content' => '부모 원문 ORIG', 'depth' => 0]);
        $this->createTestComment($postId, ['content' => '자식', 'parent_id' => $pId, 'depth' => 1]);

        DB::table('board_comments')->where('id', $pId)->update([
            'status' => 'deleted',
            'deleted_at' => now(),
        ]);

        // 기본 조회(토글 OFF)에서도 tombstone 부모가 트리에 포함되고, manager 는 원문을 본다
        $parent = collect($this->fetchComments($this->managerUser, $postId))->firstWhere('id', $pId);
        $this->assertNotNull($parent);
        $this->assertSame('부모 원문 ORIG', $parent['content']);
    }

    /**
     * 게시글 comment_count 는 tombstone(삭제) 부모를 제외한 활성 댓글 기준
     *
     * @scenario viewer=regular
     *
     * @effects comments_count_excludes_tombstone
     */
    public function test_comment_count_excludes_tombstone_parent(): void
    {
        $postId = $this->createTestPost(['title' => 'count 게시글']);
        $pId = $this->createTestComment($postId, ['content' => '부모', 'depth' => 0]);
        $this->createTestComment($postId, ['content' => '자식1', 'parent_id' => $pId, 'depth' => 1]);
        $this->createTestComment($postId, ['content' => '자식2', 'parent_id' => $pId, 'depth' => 1]);

        DB::table('board_comments')->where('id', $pId)->update([
            'status' => 'deleted',
            'deleted_at' => now(),
        ]);

        // 게시글 comments_count 재동기화 (실제 삭제 흐름과 동일하게 활성 기준)
        app(PostRepositoryInterface::class)
            ->recalculateCommentsCount($postId);

        $response = $this->actingAs($this->regularUser, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        $response->assertStatus(200);
        // 자식 2개만 활성 → comment_count = 2 (tombstone 부모 미포함)
        $this->assertSame(2, (int) $response->json('data.comment_count'));
    }

    /**
     * del_cmt 토글 ON 시 모든 삭제 댓글이 노출되며, 고아 복구가 중복 적용되어
     * 트리를 흐트러뜨리지 않는다 (부모 1회만 등장)
     *
     * @scenario viewer=manager
     *
     * @effects withtrashed_toggle_not_double_applied
     */
    public function test_withtrashed_toggle_does_not_double_apply(): void
    {
        $postId = $this->createTestPost(['title' => '토글 게시글']);
        $pId = $this->createTestComment($postId, ['content' => '부모', 'depth' => 0]);
        $cId = $this->createTestComment($postId, ['content' => '자식', 'parent_id' => $pId, 'depth' => 1]);

        DB::table('board_comments')->where('id', $pId)->update([
            'status' => 'deleted',
            'deleted_at' => now(),
        ]);

        $comments = $this->fetchComments($this->managerUser, $postId, '?del_cmt=1');
        $ids = collect($comments)->pluck('id')->all();

        $this->assertContains($pId, $ids);
        $this->assertContains($cId, $ids);
        // 부모가 중복 삽입되지 않아야 함
        $this->assertSame(1, collect($ids)->filter(fn ($id) => $id === $pId)->count(), '부모가 1회만 등장해야 합니다');
    }
}
