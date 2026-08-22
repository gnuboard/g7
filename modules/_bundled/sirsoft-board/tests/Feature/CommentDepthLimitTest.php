<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

require_once __DIR__.'/../ModuleTestCase.php';

use App\Models\Role;
use App\Models\User;
use Modules\Sirsoft\Board\Exceptions\CommentDepthExceededException;
use Modules\Sirsoft\Board\Services\CommentService;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 대댓글 깊이 제한 테스트
 *
 * 검증 목적:
 * - 저장되는 depth 가 항상 부모 depth + 1 (하드코딩 상한 없음)
 * - 게시판 설정 max_comment_depth 를 넘는 답글은 422
 * - max 를 낮게(3) / 높게(10) 설정한 두 경우 모두 설정값을 따른다
 *
 * @group board
 * @group comment
 * @group depth
 */
class CommentDepthLimitTest extends BoardTestCase
{
    private User $memberUser;

    protected function getTestBoardSlug(): string
    {
        return 'comment-depth-limit';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '댓글 깊이 제한 게시판', 'en' => 'Comment Depth Limit Board'],
            'is_active' => true,
            'use_comment' => true,
            'max_comment_depth' => 10,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setGuestPermissions(['posts.read', 'posts.write', 'comments.read', 'comments.write']);
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'comments.read', 'comments.write']);

        $this->memberUser = User::factory()->create(['email' => 'comment-depth@test.com']);

        $userRole = Role::where('identifier', 'user')->first();
        if ($userRole) {
            $this->memberUser->roles()->attach($userRole->id);
        }
    }

    /**
     * max_comment_depth=10 게시판에서 depth 는 1..10 까지 정확히 증가한다
     *
     * @scenario entity=board_comment, parent_target=unrelated
     *
     * @effects depth_equals_parent_depth_plus_one, depth_follows_board_setting_not_literal
     */
    public function test_depth_increments_up_to_configured_max(): void
    {
        $postId = $this->createTestPost();
        $parentId = $this->createTestComment($postId, ['depth' => 0]);

        for ($expectedDepth = 1; $expectedDepth <= 10; $expectedDepth++) {
            $response = $this->actingAs($this->memberUser)
                ->postJson($this->storeUrl($postId), [
                    'content' => "{$expectedDepth}단계 답글",
                    'parent_id' => $parentId,
                ]);

            $response->assertStatus(201);

            $parentId = (int) $response->json('data.id');

            $this->assertDatabaseHas('board_comments', [
                'id' => $parentId,
                'depth' => $expectedDepth,
            ]);
        }
    }

    /**
     * max_comment_depth=10 게시판에서 11단계 답글은 422
     *
     * @scenario entity=board_comment, parent_target=unrelated
     *
     * @effects depth_beyond_board_max_rejected_422
     */
    public function test_reply_beyond_configured_max_is_rejected(): void
    {
        $postId = $this->createTestPost();

        // depth 10 인 부모 댓글을 직접 생성 → 11단계 시도
        $parentId = $this->createTestComment($postId, ['depth' => 10]);

        $this->actingAs($this->memberUser)
            ->postJson($this->storeUrl($postId), [
                'content' => '11단계 답글 시도',
                'parent_id' => $parentId,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);
    }

    /**
     * max_comment_depth=3 게시판에서 4단계 답글은 422
     *
     * @scenario entity=board_comment, parent_target=unrelated
     *
     * @effects depth_beyond_board_max_rejected_422, depth_follows_board_setting_not_literal
     */
    public function test_reply_beyond_lowered_max_is_rejected(): void
    {
        $this->updateBoardSettings(['max_comment_depth' => 3]);

        $postId = $this->createTestPost();
        $parentId = $this->createTestComment($postId, ['depth' => 3]);

        $this->actingAs($this->memberUser)
            ->postJson($this->storeUrl($postId), [
                'content' => '4단계 답글 시도',
                'parent_id' => $parentId,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);
    }

    /**
     * max_comment_depth=3 게시판에서 3단계까지는 정상 생성된다
     *
     * @scenario entity=board_comment, parent_target=unrelated
     *
     * @effects depth_equals_parent_depth_plus_one, depth_follows_board_setting_not_literal
     */
    public function test_reply_within_lowered_max_is_allowed(): void
    {
        $this->updateBoardSettings(['max_comment_depth' => 3]);

        $postId = $this->createTestPost();
        $parentId = $this->createTestComment($postId, ['depth' => 2]);

        $response = $this->actingAs($this->memberUser)
            ->postJson($this->storeUrl($postId), [
                'content' => '3단계 답글',
                'parent_id' => $parentId,
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('board_comments', [
            'id' => (int) $response->json('data.id'),
            'depth' => 3,
        ]);
    }

    /**
     * max_comment_depth=0 게시판에서는 대댓글 자체가 불가능하다
     *
     * 0 은 "답글 없음" 설정이다. 상한을 리터럴로 클램프하거나 0 을 무제한으로 오해하면
     * 이 게시판에서 대댓글이 그대로 달린다.
     *
     * @scenario entity=board_comment, parent_target=unrelated
     *
     * @effects depth_beyond_board_max_rejected_422, depth_follows_board_setting_not_literal
     */
    public function test_reply_is_rejected_when_max_depth_is_zero(): void
    {
        $this->updateBoardSettings(['max_comment_depth' => 0]);

        $postId = $this->createTestPost();
        $parentId = $this->createTestComment($postId, ['depth' => 0]);

        $this->actingAs($this->memberUser)
            ->postJson($this->storeUrl($postId), [
                'content' => '대댓글 불가 게시판에서의 답글 시도',
                'parent_id' => $parentId,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['parent_id']);
    }

    /**
     * max_comment_depth=0 이어도 최상위 댓글은 정상 작성된다 (과잉 차단 방지)
     *
     * @scenario entity=board_comment, parent_target=unrelated
     *
     * @effects depth_follows_board_setting_not_literal
     */
    public function test_top_level_comment_is_allowed_when_max_depth_is_zero(): void
    {
        $this->updateBoardSettings(['max_comment_depth' => 0]);

        $postId = $this->createTestPost();

        $this->actingAs($this->memberUser)
            ->postJson($this->storeUrl($postId), ['content' => '최상위 댓글'])
            ->assertStatus(201);
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

    /**
     * FormRequest 를 거치지 않는 경로(훅 · Service 직접 호출)에서도 깊이 상한이 지켜진다
     *
     * `CommentValidationRule` 은 요청 단계 선차단이라 Service 를 직접 부르는 확장 코드에는
     * 걸리지 않는다. 첨부 개수(B3)가 Service 를 최종 불변조건으로 삼은 것과 같은 이유로,
     * 깊이 상한도 Service 가 마지막 관문이어야 한다.
     *
     * @scenario entity=board_comment, parent_target=unrelated
     *
     * @effects depth_beyond_board_max_rejected_422
     */
    public function test_service_direct_call_rejects_reply_beyond_max_depth(): void
    {
        $this->updateBoardSettings(['max_comment_depth' => 2]);

        $postId = $this->createTestPost();
        $depth0 = $this->createTestComment($postId, ['depth' => 0]);
        $depth1 = $this->createTestComment($postId, ['parent_id' => $depth0, 'depth' => 1]);
        $depth2 = $this->createTestComment($postId, ['parent_id' => $depth1, 'depth' => 2]);

        $service = app(CommentService::class);

        $this->expectException(CommentDepthExceededException::class);

        // depth 3 = 상한 2 초과 → Service 가 막아야 한다
        $service->createComment($this->board->slug, [
            'post_id' => $postId,
            'parent_id' => $depth2,
            'content' => 'FormRequest 를 우회한 깊이 초과 답글',
            'user_id' => $this->memberUser->id,
        ]);
    }

    /**
     * 상한 이내의 Service 직접 호출은 그대로 통과한다 (과잉 차단 방지)
     *
     * @scenario entity=board_comment, parent_target=unrelated
     *
     * @effects depth_follows_board_setting_not_literal
     */
    public function test_service_direct_call_allows_reply_within_max_depth(): void
    {
        $this->updateBoardSettings(['max_comment_depth' => 2]);

        $postId = $this->createTestPost();
        $depth0 = $this->createTestComment($postId, ['depth' => 0]);

        $comment = app(CommentService::class)->createComment($this->board->slug, [
            'post_id' => $postId,
            'parent_id' => $depth0,
            'content' => '상한 이내 답글',
            'user_id' => $this->memberUser->id,
        ]);

        $this->assertSame(1, (int) $comment->depth);
    }
}
