<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Board\Exceptions\PostNotCommentableException;
use Modules\Sirsoft\Board\Services\CommentService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 댓글 작성 불가 게시글 상태 응답 계약 테스트
 *
 * 검증 목적:
 * - 요청 경로: `CommentValidationRule` 이 블라인드/삭제 게시글을 422 로 선차단한다 (기존 계약 고정)
 * - Service 직접 경로: FormRequest 를 거치지 않는 훅·확장 호출에서도 도메인 예외로 막힌다
 *
 * 깊이 상한(B1)·첨부 개수(B3)와 같은 구조다 — 요청 단계는 UX 선차단, Service 가 최종 불변조건.
 * Service 가 베이스 `\Exception` 을 던지면 컨트롤러의 제네릭 catch 에 걸려 500 + 일반 문구가 되어
 * 사유가 사라지므로, 전용 예외를 두어 422 로 매핑한다.
 *
 * @group board
 * @group comment
 */
class CommentPostStateTest extends BoardTestCase
{
    private User $memberUser;

    protected function getTestBoardSlug(): string
    {
        return 'comment-post-state';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '댓글 상태 게시판', 'en' => 'Comment State Board'],
            'is_active' => true,
            'use_comment' => true,
            'max_comment_depth' => 5,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setGuestPermissions(['posts.read', 'posts.write', 'comments.read', 'comments.write']);
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'comments.read', 'comments.write']);

        $this->memberUser = User::factory()->create(['email' => 'comment-post-state@test.com']);

        $userRole = Role::where('identifier', 'user')->first();
        if ($userRole) {
            $this->memberUser->roles()->attach($userRole->id);
        }
    }

    /**
     * 요청 경로: 블라인드 게시글 댓글은 422 로 선차단된다
     *
     * @scenario entity=board_comment, post_state=blinded
     *
     * @effects blinded_post_comment_rejected_422
     */
    public function test_request_path_rejects_comment_on_blinded_post(): void
    {
        $postId = $this->createTestPost(['status' => 'blinded']);

        $this->actingAs($this->memberUser)
            ->postJson($this->storeUrl($postId), ['content' => '블라인드 글에 댓글'])
            ->assertStatus(422);

        $this->assertDatabaseMissing('board_comments', ['post_id' => $postId]);
    }

    /**
     * 요청 경로: 삭제된 게시글 댓글은 422 로 선차단된다
     *
     * @scenario entity=board_comment, post_state=deleted
     *
     * @effects deleted_post_comment_rejected_422
     */
    public function test_request_path_rejects_comment_on_deleted_post(): void
    {
        $postId = $this->createTestPost(['status' => 'deleted']);

        $this->actingAs($this->memberUser)
            ->postJson($this->storeUrl($postId), ['content' => '삭제된 글에 댓글'])
            ->assertStatus(422);

        $this->assertDatabaseMissing('board_comments', ['post_id' => $postId]);
    }

    /**
     * Service 직접 경로: 블라인드 게시글은 도메인 예외로 막힌다
     *
     * 베이스 `\Exception` 이면 컨트롤러 제네릭 catch 에 흡수되어 사유가 사라진다.
     *
     * @scenario entity=board_comment, post_state=blinded
     *
     * @effects blinded_post_comment_rejected_422
     */
    public function test_service_direct_call_rejects_comment_on_blinded_post(): void
    {
        $postId = $this->createTestPost(['status' => 'blinded']);

        $this->expectException(PostNotCommentableException::class);
        $this->expectExceptionMessage(__('sirsoft-board::messages.comment.post_blinded'));

        app(CommentService::class)->createComment($this->board->slug, [
            'post_id' => $postId,
            'content' => 'FormRequest 를 우회한 블라인드 글 댓글',
            'user_id' => $this->memberUser->id,
        ]);
    }

    /**
     * Service 직접 경로: 삭제된 게시글은 도메인 예외로 막힌다
     *
     * @scenario entity=board_comment, post_state=deleted
     *
     * @effects deleted_post_comment_rejected_422
     */
    public function test_service_direct_call_rejects_comment_on_deleted_post(): void
    {
        $postId = $this->createTestPost(['status' => 'deleted']);

        $this->expectException(PostNotCommentableException::class);
        $this->expectExceptionMessage(__('sirsoft-board::messages.comment.post_deleted'));

        app(CommentService::class)->createComment($this->board->slug, [
            'post_id' => $postId,
            'content' => 'FormRequest 를 우회한 삭제 글 댓글',
            'user_id' => $this->memberUser->id,
        ]);
    }

    /**
     * Service 직접 경로: soft-delete 된 게시글도 도메인 예외로 막힌다
     *
     * @scenario entity=board_comment, post_state=soft_deleted
     *
     * @effects deleted_post_comment_rejected_422
     */
    public function test_service_direct_call_rejects_comment_on_soft_deleted_post(): void
    {
        $postId = $this->createTestPost(['deleted_at' => now()]);

        $this->expectException(PostNotCommentableException::class);
        $this->expectExceptionMessage(__('sirsoft-board::messages.comment.post_deleted'));

        app(CommentService::class)->createComment($this->board->slug, [
            'post_id' => $postId,
            'content' => 'FormRequest 를 우회한 soft-delete 글 댓글',
            'user_id' => $this->memberUser->id,
        ]);
    }

    /**
     * 정상 게시글의 댓글 작성은 요청·Service 양 경로에서 그대로 통과한다 (과잉 차단 방지)
     *
     * @scenario entity=board_comment, post_state=published
     *
     * @effects published_post_comment_allowed
     */
    public function test_comment_on_published_post_still_passes(): void
    {
        $postId = $this->createTestPost();

        $this->actingAs($this->memberUser)
            ->postJson($this->storeUrl($postId), ['content' => '정상 댓글'])
            ->assertStatus(201);

        $comment = app(CommentService::class)->createComment($this->board->slug, [
            'post_id' => $postId,
            'content' => 'Service 직접 호출 댓글',
            'user_id' => $this->memberUser->id,
        ]);

        $this->assertNotNull($comment->id);
    }

    /**
     * 댓글 생성 엔드포인트 URL 을 생성합니다.
     *
     * @param  int  $postId  게시글 ID
     * @return string 댓글 생성 URL
     */
    private function storeUrl(int $postId): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments";
    }
}
