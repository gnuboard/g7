<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Database\Seeders\BoardReactionTypeSeeder;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 댓글 반응 — 비밀글 게이트와 카운트 캐시 재집계 회귀 (#525 WP-D).
 *
 * `CommentReactionApiTest` 가 토글·본인·비회원 규약을 잠근다. 이 파일은 그쪽이 다루지 않는
 * 두 축을 잠근다:
 *
 *  - **비밀글 게이트**: 비밀글의 댓글·비밀 댓글은 본문 열람 권한자만 반응할 수 있다
 *    ([ReactionService::reactToComment()](modules/_bundled/sirsoft-board/src/Services/ReactionService.php)).
 *    글 작성자 본인은 예외로 허용된다 — 이 예외가 빠지면 자기 글의 댓글에 반응하지 못한다.
 *  - **카운트 캐시 재집계**: `board_comments.reaction_counts` 는 반응 행을 지우는 모든 경로에서
 *    실제 행 수와 같아야 한다(§2-13). 캐시만 줄고 행이 남거나 그 반대면 화면 숫자가 거짓말을 한다.
 *    직렬화 모양(정수 키 배열 → JSON 리스트 붕괴)도 함께 본다.
 */
class CommentReactionSecretAndRecalcTest extends BoardTestCase
{
    private int $likeId;

    private int $dislikeId;

    /**
     * 각 테스트 전에 공통 픽스처를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('board_reactions')->delete();
        ReactionType::query()->delete();
        $this->seed(BoardReactionTypeSeeder::class);

        $this->likeId = ReactionType::query()->where('code', 'like')->value('id');
        $this->dislikeId = ReactionType::query()->where('code', 'dislike')->value('id');

        $this->updateBoardSettings([
            'use_reaction' => true,
            'use_comment_reaction' => true,
            'use_comment' => true,
            'active_reaction_types' => ['like', 'dislike'],
        ]);
    }

    /**
     * 테스트를 돕는 헬퍼입니다.
     *
     * @param  $commentId  commentId 값
     * @return string 문자열
     */
    private function reactUrl(int $commentId): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/comments/{$commentId}/react";
    }

    /**
     * 비밀글의 댓글은 열람 권한 없는 제3자에게 막힌다.
     *
     * @scenario case=secret_post_comment_reaction_denied
     *
     * @effects secret_post_comment_react_returns_422
     *
     * @return void 반환값 없음
     */
    public function test_secret_post_comment_reaction_denied_for_outsider(): void
    {
        $author = $this->createUser();
        $outsider = $this->createUser();

        $postId = $this->createTestPost(['user_id' => $author->id, 'is_secret' => true]);
        $commentId = $this->createTestComment($postId, ['user_id' => $author->id]);

        $this->actingAs($outsider, 'sanctum')
            ->postJson($this->reactUrl($commentId), ['reaction_type_id' => $this->likeId])
            ->assertStatus(422);

        $this->assertDatabaseMissing('board_reactions', [
            'target_type' => 'comment',
            'target_id' => $commentId,
        ]);
    }

    /**
     * 비밀 댓글도 같은 게이트를 탄다 — 글이 공개여도 댓글이 비밀이면 막힌다.
     *
     * @scenario case=secret_post_comment_reaction_denied
     *
     * @effects secret_comment_react_returns_422
     *
     * @return void 반환값 없음
     */
    public function test_secret_comment_reaction_denied_for_outsider(): void
    {
        $author = $this->createUser();
        $outsider = $this->createUser();

        $postId = $this->createTestPost(['user_id' => $author->id, 'is_secret' => false]);
        $commentId = $this->createTestComment($postId, [
            'user_id' => $author->id,
            'is_secret' => true,
        ]);

        $this->actingAs($outsider, 'sanctum')
            ->postJson($this->reactUrl($commentId), ['reaction_type_id' => $this->likeId])
            ->assertStatus(422);
    }

    /**
     * 글 작성자 본인은 자기 비밀글의 (남이 단) 댓글에 반응할 수 있다.
     *
     * 이 예외가 빠지면 작성자가 자기 글의 댓글에 반응하지 못한다 — 게이트 과잉이다.
     *
     * @scenario case=secret_post_comment_reaction_denied
     *
     * @effects secret_post_author_can_react_to_comment
     *
     * @return void 반환값 없음
     */
    public function test_secret_post_author_can_react_to_others_comment(): void
    {
        $author = $this->createUser();
        $commenter = $this->createUser();

        $postId = $this->createTestPost(['user_id' => $author->id, 'is_secret' => true]);
        $commentId = $this->createTestComment($postId, ['user_id' => $commenter->id]);

        $this->actingAs($author, 'sanctum')
            ->postJson($this->reactUrl($commentId), ['reaction_type_id' => $this->likeId])
            ->assertOk()
            ->assertJsonPath('data.action', 'add');
    }

    /**
     * 해제 후 `board_comments.reaction_counts` 가 실제 행 수와 일치한다.
     *
     * 캐시와 행이 어긋나면 화면 숫자가 거짓말을 한다(§2-13).
     *
     * @scenario case=comment_reaction_counts_recalculated
     *
     * @effects comment_reaction_counts_match_row_count
     *
     * @return void 반환값 없음
     */
    public function test_comment_reaction_counts_match_actual_rows_after_remove(): void
    {
        $author = $this->createUser();
        $a = $this->createUser();
        $b = $this->createUser();

        $postId = $this->createTestPost(['user_id' => $author->id]);
        $commentId = $this->createTestComment($postId, ['user_id' => $author->id]);

        foreach ([$a, $b] as $user) {
            $this->actingAs($user, 'sanctum')
                ->postJson($this->reactUrl($commentId), ['reaction_type_id' => $this->likeId])
                ->assertOk();
        }

        $this->assertSame(2, $this->cachedCount($commentId, $this->likeId));

        // 한 명이 해제 — 캐시가 1 로 줄어야 한다.
        $this->actingAs($a, 'sanctum')
            ->postJson($this->reactUrl($commentId), ['reaction_type_id' => $this->likeId])
            ->assertOk()
            ->assertJsonPath('data.action', 'remove');

        $actualRows = DB::table('board_reactions')
            ->where('target_type', 'comment')
            ->where('target_id', $commentId)
            ->where('reaction_type_id', $this->likeId)
            ->count();

        $this->assertSame(1, $actualRows);
        $this->assertSame($actualRows, $this->cachedCount($commentId, $this->likeId));
    }

    /**
     * 카운트 캐시는 **객체**로 직렬화된다 — 정수 키 배열이면 JSON 리스트로 붕괴한다.
     *
     * 유형 ID 가 1 부터 연속이면 PHP 배열이 리스트로 보여 `{"1":2}` 대신 `[2]` 가 나가고,
     * 화면은 `counts[typeId]` 로 못 찾는다(§2-13 의 주의).
     *
     * @scenario case=comment_reaction_counts_recalculated
     *
     * @effects comment_reaction_counts_serialize_as_object
     *
     * @return void 반환값 없음
     */
    public function test_comment_reaction_counts_serialize_as_object(): void
    {
        $author = $this->createUser();
        $reactor = $this->createUser();

        $postId = $this->createTestPost(['user_id' => $author->id]);
        $commentId = $this->createTestComment($postId, ['user_id' => $author->id]);

        $response = $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($commentId), ['reaction_type_id' => $this->likeId])
            ->assertOk();

        $counts = $response->json('data.reaction_counts');

        $this->assertIsArray($counts);
        // 유형 ID 를 키로 찾을 수 있어야 한다 — 리스트로 붕괴하면 이 키가 없다.
        $this->assertArrayHasKey((string) $this->likeId, $counts);
        $this->assertSame(1, $counts[(string) $this->likeId]);
    }

    /**
     * `board_comments.reaction_counts` 에 캐시된 특정 유형의 값을 읽습니다.
     *
     * @param  $commentId  commentId 값
     * @param  $reactionTypeId  reactionTypeId 값
     * @return int 정수 식별자
     */
    private function cachedCount(int $commentId, int $reactionTypeId): int
    {
        $raw = DB::table('board_comments')->where('id', $commentId)->value('reaction_counts');
        $decoded = json_decode((string) $raw, true) ?: [];

        return (int) ($decoded[(string) $reactionTypeId] ?? 0);
    }
}
