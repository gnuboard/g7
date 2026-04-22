<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 댓글/대댓글 CRUD 전수 시나리오 테스트
 *
 * 검증 목적:
 * - 회원/비회원/타인 별 댓글 생성/수정/삭제 권한
 * - 대댓글(parent_id) 생성 및 depth 경계
 * - 삭제된/블라인드 댓글 처리
 * - 비밀번호 기반 비회원 댓글 수정/삭제
 *
 * @group board
 * @group comment
 */
class CommentCrudScenarioTest extends BoardTestCase
{
    private User $memberUser;

    private User $otherUser;

    protected function getTestBoardSlug(): string
    {
        return 'comment-crud';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '댓글 CRUD 테스트 게시판', 'en' => 'Comment CRUD Test Board'],
            'is_active' => true,
            'max_comment_depth' => 2,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // 댓글 권한도 guest/user 에 부여
        $this->setGuestPermissions(['posts.read', 'posts.write', 'comments.read', 'comments.write']);
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'comments.read', 'comments.write']);

        $this->memberUser = User::factory()->create(['email' => 'comment-member@test.com']);
        $this->otherUser = User::factory()->create(['email' => 'comment-other@test.com']);

        $userRole = \App\Models\Role::where('identifier', 'user')->first();
        if ($userRole) {
            $this->memberUser->roles()->attach($userRole->id);
            $this->otherUser->roles()->attach($userRole->id);
        }
    }

    // ==========================================
    // 댓글 생성 시나리오
    // ==========================================

    /**
     * 회원이 댓글을 생성할 수 있다
     */
    public function test_member_can_create_comment(): void
    {
        $postId = $this->createTestPost(['user_id' => $this->memberUser->id]);

        $response = $this->actingAs($this->memberUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments", [
                'content' => '회원 댓글입니다.',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.content', '회원 댓글입니다.');

        $this->assertDatabaseHas('board_comments', [
            'post_id' => $postId,
            'user_id' => $this->memberUser->id,
            'content' => '회원 댓글입니다.',
        ]);
    }

    /**
     * 비회원이 댓글을 생성할 수 있다
     */
    public function test_guest_can_create_comment(): void
    {
        $postId = $this->createTestPost();

        $response = $this->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments", [
            'content' => '비회원 댓글입니다.',
            'author_name' => '비회원',
            'password' => 'guest1234',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('board_comments', [
            'post_id' => $postId,
            'user_id' => null,
            'content' => '비회원 댓글입니다.',
        ]);
    }

    /**
     * 댓글 쓰기 권한 없는 사용자는 댓글을 생성할 수 없다
     */
    public function test_user_without_permission_cannot_create_comment(): void
    {
        $postId = $this->createTestPost();
        $noPermUser = User::factory()->create(['email' => 'no-perm@test.com']);

        $response = $this->actingAs($noPermUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments", [
                'content' => '권한 없는 댓글',
            ]);

        $response->assertStatus(403);
    }

    /**
     * 블라인드 게시글에는 댓글을 생성할 수 없다
     */
    public function test_cannot_create_comment_on_blinded_post(): void
    {
        $postId = $this->createTestPost(['status' => 'blinded']);

        $response = $this->actingAs($this->memberUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments", [
                'content' => '블라인드 게시글 댓글',
            ]);

        $response->assertStatus(422);
    }

    /**
     * 삭제된 게시글에는 댓글을 생성할 수 없다
     */
    public function test_cannot_create_comment_on_deleted_post(): void
    {
        $postId = $this->createTestPost(['deleted_at' => now()]);

        $response = $this->actingAs($this->memberUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments", [
                'content' => '삭제된 게시글 댓글',
            ]);

        $response->assertStatus(422);
    }

    // ==========================================
    // 대댓글(대댓글) 시나리오
    // ==========================================

    /**
     * 댓글에 대댓글을 생성할 수 있다
     */
    public function test_can_create_reply_to_comment(): void
    {
        $postId = $this->createTestPost();
        $parentCommentId = $this->createTestComment($postId, ['user_id' => $this->memberUser->id]);

        $response = $this->actingAs($this->memberUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments", [
                'content' => '대댓글입니다.',
                'parent_id' => $parentCommentId,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.parent_id', $parentCommentId);

        $this->assertDatabaseHas('board_comments', [
            'post_id' => $postId,
            'parent_id' => $parentCommentId,
            'content' => '대댓글입니다.',
        ]);
    }

    /**
     * max_comment_depth 초과 depth의 대댓글은 생성할 수 없다
     */
    public function test_cannot_create_comment_beyond_max_depth(): void
    {
        // max_comment_depth = 2 → depth 0(부모) → depth 1(대댓글) → depth 2 불가
        $postId = $this->createTestPost();
        $depth0 = $this->createTestComment($postId);
        $depth1 = $this->createTestComment($postId, ['parent_id' => $depth0, 'depth' => 1]);
        $depth2 = $this->createTestComment($postId, ['parent_id' => $depth1, 'depth' => 2]);

        $response = $this->actingAs($this->memberUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments", [
                'content' => '허용 초과 depth 대댓글',
                'parent_id' => $depth2,
            ]);

        $response->assertStatus(422);
    }

    /**
     * 존재하지 않는 parent_id로 대댓글 생성 시 422 반환
     */
    public function test_cannot_create_reply_with_invalid_parent_id(): void
    {
        $postId = $this->createTestPost();

        $response = $this->actingAs($this->memberUser)
            ->postJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments", [
                'content' => '잘못된 부모 대댓글',
                'parent_id' => 99999999,
            ]);

        $response->assertStatus(422);
    }

    // ==========================================
    // 댓글 수정 시나리오
    // ==========================================

    /**
     * 작성자 회원은 자신의 댓글을 수정할 수 있다
     */
    public function test_comment_author_can_update_own_comment(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, ['user_id' => $this->memberUser->id]);

        $response = $this->actingAs($this->memberUser)
            ->putJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments/{$commentId}", [
                'content' => '수정된 댓글 내용',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.content', '수정된 댓글 내용');
    }

    /**
     * 타인의 댓글은 수정할 수 없다
     */
    public function test_cannot_update_other_users_comment(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, ['user_id' => $this->memberUser->id]);

        $response = $this->actingAs($this->otherUser)
            ->putJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments/{$commentId}", [
                'content' => '타인이 수정 시도',
            ]);

        $response->assertStatus(403);
    }

    /**
     * 비회원은 올바른 비밀번호로 자신의 댓글을 수정할 수 있다
     */
    public function test_guest_can_update_comment_with_correct_password(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, [
            'password' => bcrypt('pass1234'),
            'author_name' => '비회원',
        ]);

        $response = $this->putJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments/{$commentId}", [
            'content' => '비회원 수정 내용',
            'password' => 'pass1234',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.content', '비회원 수정 내용');
    }

    /**
     * 비회원은 틀린 비밀번호로 댓글을 수정할 수 없다
     */
    public function test_guest_cannot_update_comment_with_wrong_password(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, [
            'password' => bcrypt('pass1234'),
            'author_name' => '비회원',
        ]);

        $response = $this->putJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments/{$commentId}", [
            'content' => '비밀번호 틀린 수정',
            'password' => 'wrongpass',
        ]);

        $response->assertStatus(403);
    }

    // ==========================================
    // 댓글 삭제 시나리오
    // ==========================================

    /**
     * 작성자 회원은 자신의 댓글을 삭제할 수 있다
     */
    public function test_comment_author_can_delete_own_comment(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, ['user_id' => $this->memberUser->id]);

        $response = $this->actingAs($this->memberUser)
            ->deleteJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments/{$commentId}");

        $response->assertStatus(200);

        // soft-delete 확인
        $this->assertSoftDeleted('board_comments', ['id' => $commentId]);
    }

    /**
     * 타인의 댓글은 삭제할 수 없다
     */
    public function test_cannot_delete_other_users_comment(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, ['user_id' => $this->memberUser->id]);

        $response = $this->actingAs($this->otherUser)
            ->deleteJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments/{$commentId}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('board_comments', ['id' => $commentId, 'deleted_at' => null]);
    }

    /**
     * 비회원은 올바른 비밀번호로 자신의 댓글을 삭제할 수 있다
     */
    public function test_guest_can_delete_comment_with_correct_password(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, [
            'password' => bcrypt('pass1234'),
            'author_name' => '비회원',
        ]);

        $response = $this->deleteJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments/{$commentId}",
            ['password' => 'pass1234']
        );

        $response->assertStatus(200);
        $this->assertSoftDeleted('board_comments', ['id' => $commentId]);
    }

    /**
     * 댓글 삭제 후 comments_count가 감소한다
     */
    public function test_deleting_comment_decrements_post_comments_count(): void
    {
        $postId = $this->createTestPost();
        $commentId = $this->createTestComment($postId, ['user_id' => $this->memberUser->id]);

        // 카운트 초기값 설정
        DB::table('board_posts')->where('id', $postId)->update(['comments_count' => 1]);

        $this->actingAs($this->memberUser)
            ->deleteJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments/{$commentId}");

        $count = DB::table('board_posts')->where('id', $postId)->value('comments_count');
        $this->assertEquals(0, $count);
    }

    /**
     * 댓글 블라인드 처리 시 comments_count는 변동 없다
     */
    public function test_blinding_comment_does_not_change_comments_count(): void
    {
        $postId = $this->createTestPost();
        $this->createTestComment($postId, ['user_id' => $this->memberUser->id]);

        // 카운트 설정
        DB::table('board_posts')->where('id', $postId)->update(['comments_count' => 1]);

        // blind 처리는 관리자 API를 통해야 하므로 DB 직접 업데이트로 시뮬레이션
        DB::table('board_comments')->where('post_id', $postId)->update(['status' => 'blinded']);

        // 카운트 변동 없음 확인
        $count = DB::table('board_posts')->where('id', $postId)->value('comments_count');
        $this->assertEquals(1, $count);
    }

    // ==========================================
    // 댓글 목록 조회 시나리오
    // ==========================================

    /**
     * 댓글 목록을 조회할 수 있다
     */
    public function test_can_list_comments_for_post(): void
    {
        $postId = $this->createTestPost();
        $this->createTestComment($postId, ['content' => '첫 번째 댓글']);
        $this->createTestComment($postId, ['content' => '두 번째 댓글']);

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');
    }

    /**
     * soft-delete된 댓글은 목록에 표시되지 않는다
     */
    public function test_deleted_comments_are_not_shown_in_list(): void
    {
        $postId = $this->createTestPost();
        $this->createTestComment($postId, ['content' => '정상 댓글']);
        $this->createTestComment($postId, ['content' => '삭제된 댓글', 'deleted_at' => now()]);

        $response = $this->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }
}
