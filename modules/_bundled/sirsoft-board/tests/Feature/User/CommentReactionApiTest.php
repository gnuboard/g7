<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Database\Seeders\BoardReactionTypeSeeder;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 댓글 반응 API Feature 테스트.
 *
 * 게시글 반응과 같은 규약(대상당 1개, 재클릭=해제, 다른 유형=전환)을 댓글에도 적용하되,
 * 게시판이 use_reaction 과 use_comment_reaction 을 **모두** 켰을 때만 허용한다 (결정 R9).
 * 비회원 댓글(user_id NULL)은 본인 판정이 불가능하므로 셀프 차단을 걸지 않는다.
 */
class CommentReactionApiTest extends BoardTestCase
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

        // 댓글 반응 엔드포인트는 글·댓글 읽기 권한을 요구한다(7.1.0 abilities 게이트 S7) — 회원 역할에 부여해 둔다
        $this->grantUserRolePermissions(['posts.read', 'comments.read']);
    }

    /**
     * 댓글 반응 API 엔드포인트 URL 을 조립합니다.
     *
     * @param  $commentId  commentId 값
     * @return string 문자열
     */
    private function reactUrl(int $commentId): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/comments/{$commentId}/react";
    }

    /**
     * 등록 → 전환 → 해제가 댓글의 reaction_counts 를 정확히 반영한다.
     *
     * @scenario case=member_add_change_remove_on_comment
     *
     * @effects comment_react_inserts_row_and_increments_count, comment_switch_adjusts_both_counts, comment_remove_deletes_row
     *
     * @return void 반환값 없음
     */
    public function test_react_to_comment_add_change_remove_updates_comment_counts(): void
    {
        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);
        $commentId = $this->createTestComment($postId, ['user_id' => $author->id]);

        // 등록
        $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($commentId), ['reaction_type_id' => $this->likeId])
            ->assertOk()
            ->assertJsonPath('data.action', 'add')
            ->assertJsonPath('data.my_reaction_type_id', $this->likeId)
            ->assertJsonPath("data.reaction_counts.{$this->likeId}", 1);

        $this->assertDatabaseHas('board_reactions', [
            'user_id' => $reactor->id,
            'target_type' => 'comment',
            'target_id' => $commentId,
            'reaction_type_id' => $this->likeId,
        ]);

        // 전환
        $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($commentId), ['reaction_type_id' => $this->dislikeId])
            ->assertOk()
            ->assertJsonPath('data.action', 'change')
            ->assertJsonPath("data.reaction_counts.{$this->likeId}", 0)
            ->assertJsonPath("data.reaction_counts.{$this->dislikeId}", 1);

        // 해제 (같은 유형 재요청)
        $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($commentId), ['reaction_type_id' => $this->dislikeId])
            ->assertOk()
            ->assertJsonPath('data.action', 'remove')
            ->assertJsonPath('data.my_reaction_type_id', null)
            ->assertJsonPath("data.reaction_counts.{$this->dislikeId}", 0);

        $this->assertDatabaseMissing('board_reactions', [
            'user_id' => $reactor->id,
            'target_type' => 'comment',
            'target_id' => $commentId,
        ]);
    }

    /**
     * 댓글 반응 토글이 꺼져 있으면 422 (게시글 반응은 켜져 있어도).
     *
     * @scenario case=comment_reaction_toggle_off_blocks
     *
     * @effects comment_reaction_off_returns_422
     *
     * @return void 반환값 없음
     */
    public function test_comment_reaction_blocked_when_use_comment_reaction_off(): void
    {
        $this->updateBoardSettings(['use_reaction' => true, 'use_comment_reaction' => false]);

        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);
        $commentId = $this->createTestComment($postId, ['user_id' => $author->id]);

        $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($commentId), ['reaction_type_id' => $this->likeId])
            ->assertStatus(422);

        $this->assertDatabaseMissing('board_reactions', [
            'target_type' => 'comment',
            'target_id' => $commentId,
        ]);
    }

    /**
     * 게시글 반응이 꺼져 있으면 댓글 반응도 막힌다 (하위 토글).
     *
     * @scenario case=parent_reaction_toggle_off_blocks
     *
     * @effects comment_reaction_requires_parent_toggle
     *
     * @return void 반환값 없음
     */
    public function test_comment_reaction_blocked_when_use_reaction_off(): void
    {
        $this->updateBoardSettings(['use_reaction' => false, 'use_comment_reaction' => true]);

        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);
        $commentId = $this->createTestComment($postId, ['user_id' => $author->id]);

        $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($commentId), ['reaction_type_id' => $this->likeId])
            ->assertStatus(422);
    }

    /**
     * 본인 댓글은 막고, 비회원 댓글은 허용한다.
     *
     * @scenario case=self_comment_blocked_guest_comment_allowed
     *
     * @effects self_comment_react_returns_422, guest_comment_react_allowed
     *
     * @return void 반환값 없음
     */
    public function test_self_comment_blocked_guest_comment_allowed(): void
    {
        $member = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $this->createUser()->id]);

        $ownComment = $this->createTestComment($postId, ['user_id' => $member->id]);
        $guestComment = $this->createTestComment($postId, ['user_id' => null, 'author_name' => '비회원']);

        // 본인 댓글 차단
        $this->actingAs($member, 'sanctum')
            ->postJson($this->reactUrl($ownComment), ['reaction_type_id' => $this->likeId])
            ->assertStatus(422);

        // 비회원 댓글은 본인 판정이 불가능하므로 허용
        $this->actingAs($member, 'sanctum')
            ->postJson($this->reactUrl($guestComment), ['reaction_type_id' => $this->likeId])
            ->assertOk()
            ->assertJsonPath('data.action', 'add');
    }

    /**
     * 로그인하지 않은 방문자는 비회원 댓글의 작성자로 판정되지 않는다.
     *
     * 비로그인(Auth::id() = null)과 비회원 댓글(user_id = null)을 같은 값으로 비교하면
     * `is_author` 가 참이 되어, 화면이 반응 버튼을 흐리게 그리고 누르면 로그인 안내 대신
     * 「본인 댓글에는 반응할 수 없습니다」를 띄운다. 게시글 리소스와 같은 기준(회원 댓글만 본인 판정)을 쓴다.
     *
     * @scenario case=self_comment_blocked_guest_comment_allowed
     *
     * @effects guest_viewer_not_author_of_guest_comment
     *
     * @return void 반환값 없음
     */
    public function test_anonymous_viewer_is_not_author_of_guest_comment(): void
    {
        $member = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $this->createUser()->id]);
        $guestComment = $this->createTestComment($postId, ['user_id' => null, 'author_name' => '비회원']);
        $memberComment = $this->createTestComment($postId, ['user_id' => $member->id]);

        $this->setGuestPermissions(['posts.read', 'comments.read']);

        $comments = collect(
            $this->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments")
                ->assertOk()
                ->json('data')
        )->keyBy('id');

        $this->assertFalse($comments[$guestComment]['is_author'], '비로그인 방문자는 비회원 댓글의 작성자가 아니다.');
        $this->assertFalse($comments[$memberComment]['is_author']);

        // 회원 본인 판정은 그대로다.
        $this->grantUserRolePermissions(['posts.read', 'comments.read']);
        $own = collect(
            $this->actingAs($member, 'sanctum')
                ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments")
                ->assertOk()
                ->json('data')
        )->keyBy('id');

        $this->assertTrue($own[$memberComment]['is_author']);
        $this->assertFalse($own[$guestComment]['is_author']);
    }

    /**
     * 삭제·블라인드된 댓글에는 새 반응을 남길 수 없다.
     *
     * @scenario case=deleted_or_blinded_comment_blocked
     *
     * @effects unavailable_comment_returns_422
     *
     * @return void 반환값 없음
     */
    public function test_blinded_or_deleted_comment_returns_422(): void
    {
        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $blinded = $this->createTestComment($postId, ['user_id' => $author->id, 'status' => 'blinded']);
        $deleted = $this->createTestComment($postId, ['user_id' => $author->id, 'deleted_at' => now()]);

        $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($blinded), ['reaction_type_id' => $this->likeId])
            ->assertStatus(422);

        $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($deleted), ['reaction_type_id' => $this->likeId])
            ->assertStatus(422);
    }

    /**
     * 다른 게시판의 댓글 ID 로는 404 (교차 접근 차단).
     *
     * @scenario case=cross_board_comment_not_found
     *
     * @effects cross_board_comment_returns_404
     *
     * @return void 반환값 없음
     */
    public function test_cross_board_comment_returns_404(): void
    {
        $reactor = $this->createUser();

        // 이 게시판에 존재하지 않는 댓글 ID
        $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl(99999999), ['reaction_type_id' => $this->likeId])
            ->assertNotFound();
    }

    /**
     * 게시판이 켜지 않은 유형은 422.
     *
     * @scenario case=inactive_type_blocked
     *
     * @effects inactive_type_comment_react_returns_422
     *
     * @return void 반환값 없음
     */
    public function test_inactive_type_returns_422(): void
    {
        $this->updateBoardSettings(['active_reaction_types' => ['like']]);

        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);
        $commentId = $this->createTestComment($postId, ['user_id' => $author->id]);

        $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($commentId), ['reaction_type_id' => $this->dislikeId])
            ->assertStatus(422);
    }

    /**
     * 비로그인은 401.
     *
     * @scenario case=anonymous_user_unauthorized
     *
     * @effects guest_comment_react_returns_401
     *
     * @return void 반환값 없음
     */
    public function test_guest_returns_401(): void
    {
        $postId = $this->createTestPost(['user_id' => $this->createUser()->id]);
        $commentId = $this->createTestComment($postId, ['user_id' => $this->createUser()->id]);

        $this->postJson($this->reactUrl($commentId), ['reaction_type_id' => $this->likeId])
            ->assertUnauthorized();
    }

    /**
     * 게시글 상세가 댓글별 반응 카운트와 내 반응을 함께 싣는다.
     *
     * @scenario case=detail_embeds_counts_and_my_reaction
     *
     * @effects post_detail_embeds_comment_reaction_counts_and_my_reaction
     *
     * @return void 반환값 없음
     */
    public function test_post_detail_embeds_comment_reaction_counts_and_my_reaction_type_id(): void
    {
        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);
        $commentId = $this->createTestComment($postId, ['user_id' => $author->id]);

        $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($commentId), ['reaction_type_id' => $this->likeId])
            ->assertOk();

        // 인증 사용자가 게시글 상세를 읽으려면 user 역할에 게시판 권한이 있어야 한다.
        $this->grantUserRolePermissions(['posts.read']);

        $response = $this->actingAs($reactor, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        $response->assertOk();

        $comment = collect($response->json('data.comments'))->firstWhere('id', $commentId);

        $this->assertNotNull($comment, '댓글이 상세 응답에 있어야 합니다.');
        $this->assertSame($this->likeId, $comment['my_reaction_type_id']);
        $this->assertSame(1, $comment['reaction_counts'][(string) $this->likeId] ?? null);
        // 활성 유형은 0 으로라도 채워져야 화면이 "아직 0" 상태를 그릴 수 있다.
        $this->assertSame(0, $comment['reaction_counts'][(string) $this->dislikeId] ?? null);
    }

    /**
     * 같은 프로세스에서 반응 유형이 바뀌면 다음 응답의 댓글 반응 카운트 키도 바뀐다.
     *
     * 활성 유형 ID 를 함수 정적 변수에 담으면 프로세스가 끝날 때까지 남아, 큐 워커·장기 실행
     * 프로세스(그리고 같은 PHPUnit 프로세스의 다음 테스트)가 관리자가 끈 유형이나 다시 만든 유형의
     * 옛 ID 를 계속 쓴다. 캐시는 요청 단위여야 한다.
     *
     * @scenario case=active_type_ids_follow_type_changes_in_same_process
     *
     * @effects comment_reaction_counts_follow_type_changes_in_same_process
     *
     * @return void 반환값 없음
     */
    public function test_comment_reaction_count_keys_follow_type_changes_in_same_process(): void
    {
        $postId = $this->createTestPost(['user_id' => $this->createUser()->id]);
        $commentId = $this->createTestComment($postId, ['user_id' => $this->createUser()->id]);

        $this->setGuestPermissions(['posts.read', 'comments.read']);

        $countKeys = function () use ($postId, $commentId): array {
            $comment = collect(
                $this->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/comments")
                    ->assertOk()
                    ->json('data')
            )->firstWhere('id', $commentId);

            $keys = array_map('strval', array_keys($comment['reaction_counts'] ?? []));
            sort($keys);

            return $keys;
        };

        $expected = [(string) $this->likeId, (string) $this->dislikeId];
        sort($expected);
        $this->assertSame($expected, $countKeys());

        // 관리자가 유형을 끈다 — 다음 응답에서 그 유형 키가 빠져야 한다.
        ReactionType::query()->whereKey($this->dislikeId)->update(['is_active' => false]);
        $this->assertSame([(string) $this->likeId], $countKeys(), '끈 유형의 키가 남으면 이전 프로세스 값을 쓰는 것이다.');

        // 유형을 지우고 다시 만든다(새 ID) — 새 ID 로 응답해야 한다.
        ReactionType::query()->delete();
        $this->seed(BoardReactionTypeSeeder::class);
        $newIds = ReactionType::query()->whereIn('code', ['like', 'dislike'])->pluck('id')
            ->map(fn ($id) => (string) $id)->sort()->values()->all();

        $this->assertNotSame($expected, $newIds, '다시 만든 유형은 새 ID 를 받아야 시험이 성립한다.');
        $this->assertSame($newIds, $countKeys());
    }
}
