<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Database\Seeders\BoardReactionTypeSeeder;
use Modules\Sirsoft\Board\Models\ReactionType;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 반응 API Feature 테스트.
 *
 * 등록/전환/해제, reaction_counts 증감 정합성(전환 포함), use_reaction off·비활성 유형
 * 차단, 본인 글 차단, 비로그인 차단을 종단 검증한다 (이슈 #525 §10 테스트 범위).
 *
 * @scenario case=guest_react_blocked
 *
 * @effects guest_react_returns_401
 */
class ReactionApiTest extends BoardTestCase
{
    private int $likeId;

    private int $dislikeId;

    protected function setUp(): void
    {
        parent::setUp();

        // FK(restrictOnDelete) 순서: 이력 먼저 정리 후 유형 삭제 (타 테스트 잔여 데이터 대비)
        DB::table('board_reactions')->delete();
        ReactionType::query()->delete();
        $this->seed(BoardReactionTypeSeeder::class);

        $this->likeId = ReactionType::query()->where('code', 'like')->value('id');
        $this->dislikeId = ReactionType::query()->where('code', 'dislike')->value('id');

        $this->updateBoardSettings([
            'use_reaction' => true,
            'active_reaction_types' => ['like', 'dislike'],
        ]);
    }

    /**
     * 반응 API 엔드포인트 URL 을 조립합니다.
     */
    private function reactUrl(int $postId): string
    {
        return "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}/react";
    }

    /**
     * 반응 등록 → 카운트 +1, my_reaction_type_id 반영.
     */
    public function test_register_reaction(): void
    {
        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $response = $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($postId), ['reaction_type_id' => $this->likeId]);

        $response->assertOk()
            ->assertJsonPath('data.action', 'add')
            ->assertJsonPath('data.my_reaction_type_id', $this->likeId)
            ->assertJsonPath("data.reaction_counts.{$this->likeId}", 1);

        $this->assertDatabaseHas('board_reactions', [
            'user_id' => $reactor->id,
            'target_id' => $postId,
            'reaction_type_id' => $this->likeId,
        ]);
    }

    /**
     * 다른 유형으로 전환 → 이전 -1·신규 +1 (증감 정합성).
     */
    public function test_switch_reaction_adjusts_counts(): void
    {
        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($postId), ['reaction_type_id' => $this->likeId])
            ->assertOk();

        $response = $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($postId), ['reaction_type_id' => $this->dislikeId]);

        $response->assertOk()
            ->assertJsonPath('data.action', 'change')
            ->assertJsonPath('data.my_reaction_type_id', $this->dislikeId)
            ->assertJsonPath("data.reaction_counts.{$this->likeId}", 0)
            ->assertJsonPath("data.reaction_counts.{$this->dislikeId}", 1);
    }

    /**
     * 같은 유형 재클릭 → 해제, 카운트 -1, my_reaction_type_id null.
     */
    public function test_remove_reaction_on_same_type(): void
    {
        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($postId), ['reaction_type_id' => $this->likeId])
            ->assertOk();

        $response = $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($postId), ['reaction_type_id' => $this->likeId]);

        $response->assertOk()
            ->assertJsonPath('data.action', 'remove')
            ->assertJsonPath('data.my_reaction_type_id', null)
            ->assertJsonPath("data.reaction_counts.{$this->likeId}", 0);

        $this->assertDatabaseMissing('board_reactions', [
            'user_id' => $reactor->id,
            'target_id' => $postId,
        ]);
    }

    /**
     * 비로그인 요청은 401 로 차단된다 (확정 07).
     */
    public function test_guest_cannot_react(): void
    {
        $author = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->postJson($this->reactUrl($postId), ['reaction_type_id' => $this->likeId])
            ->assertUnauthorized();
    }

    /**
     * use_reaction 이 꺼진 게시판은 422 로 차단된다.
     */
    public function test_react_blocked_when_use_reaction_off(): void
    {
        $this->updateBoardSettings(['use_reaction' => false]);

        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($postId), ['reaction_type_id' => $this->likeId])
            ->assertStatus(422);
    }

    /**
     * 게시판이 켜지 않은(비활성) 유형은 검증 단계(422)에서 차단된다.
     */
    public function test_react_blocked_for_inactive_type(): void
    {
        $this->updateBoardSettings(['active_reaction_types' => ['like']]);

        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($postId), ['reaction_type_id' => $this->dislikeId])
            ->assertStatus(422);
    }

    /**
     * 본인 글에는 반응할 수 없다 (422, 확정 08).
     */
    public function test_cannot_react_to_own_post(): void
    {
        $author = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        $this->actingAs($author, 'sanctum')
            ->postJson($this->reactUrl($postId), ['reaction_type_id' => $this->likeId])
            ->assertStatus(422);
    }

    /**
     * 다른 게시판 소속이 아닌(존재하지 않는) 게시글은 404 로 차단된다.
     */
    public function test_react_to_missing_post_returns_404(): void
    {
        $reactor = $this->createUser();

        $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl(999999), ['reaction_type_id' => $this->likeId])
            ->assertNotFound();
    }

    /**
     * 비밀글에 열람 권한이 없는 사용자는 반응할 수 없다 (422, secret_denied).
     *
     * 회귀: 반응 서비스가 비밀글 열람 권한을 검사하지 않으면, 본문(content=null)을
     * 못 보는 사용자가 추천/비추천만 남기는 우회가 가능하다. 신고(canViewSecretContent)와
     * 동일하게 열람 권한 없는 비밀글 반응을 서버가 최종 차단해야 한다.
     *
     * @scenario case=secret_post_react_blocked
     *
     * @effects secret_post_without_permission_returns_422
     */
    public function test_react_blocked_on_secret_post_without_permission(): void
    {
        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost([
            'user_id' => $author->id,
            'is_secret' => true,
        ]);

        $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($postId), ['reaction_type_id' => $this->likeId])
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'reaction_not_allowed');

        // 반응 이력이 남지 않아야 한다
        $this->assertDatabaseMissing('board_reactions', [
            'user_id' => $reactor->id,
            'target_id' => $postId,
        ]);
    }

    /**
     * 비밀글 열람 권한(posts.read-secret)이 있는 사용자는 비밀글에도 반응할 수 있다.
     *
     * 비밀글 가드가 열람 권한자까지 막지 않는지 확인한다 (신고 판정과 동일 기준).
     *
     * @scenario case=secret_post_react_allowed_with_permission
     *
     * @effects secret_post_with_read_secret_permission_allows_react
     */
    public function test_react_allowed_on_secret_post_with_read_secret_permission(): void
    {
        $this->grantUserRolePermissions(['posts.read-secret']);

        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost([
            'user_id' => $author->id,
            'is_secret' => true,
        ]);

        $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($postId), ['reaction_type_id' => $this->likeId])
            ->assertOk()
            ->assertJsonPath('data.action', 'add');

        $this->assertDatabaseHas('board_reactions', [
            'user_id' => $reactor->id,
            'target_id' => $postId,
            'reaction_type_id' => $this->likeId,
        ]);
    }

    /**
     * 게시글 상세 응답의 reaction_counts 가 유형 ID 키를 보존한다 (JSON 객체, 배열 재인덱싱 금지).
     *
     * 회귀: PostResource 의 reaction_counts 키가 정수 [1,2] 라 JsonResource::resolve() 가
     * list 로 오인해 [count1, count2] 로 재인덱싱하면, 프론트가 reaction_counts[유형ID] 로
     * 읽을 때 엉뚱한 인덱스를 읽어 개수가 항상 0/어긋나게 표시된다. 키는 항상 유형 ID 여야 한다.
     */
    public function test_post_detail_reaction_counts_preserves_type_id_keys(): void
    {
        // 상세 조회는 posts.read 권한이 필요하다
        $this->grantUserRolePermissions(['posts.read', 'posts.write']);

        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $this->createUser()->id]);

        // 추천 1회 등록
        $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($postId), ['reaction_type_id' => $this->likeId])
            ->assertOk();

        // 게시글 상세 조회 — reaction_counts 는 유형 ID 키로 개수를 담아야 한다
        $detail = $this->actingAs($reactor, 'sanctum')
            ->getJson("/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$postId}");

        $detail->assertOk()
            ->assertJsonPath("data.reaction_counts.{$this->likeId}", 1)
            ->assertJsonPath("data.reaction_counts.{$this->dislikeId}", 0)
            ->assertJsonPath('data.my_reaction_type_id', $this->likeId);

        // 키가 유형 ID 로 보존되는지 직접 확인 (0-기반 재인덱싱 아님)
        $counts = $detail->json('data.reaction_counts');
        $this->assertArrayHasKey((string) $this->likeId, $counts);
        $this->assertArrayNotHasKey('0', $counts);
    }

    /**
     * 반응 등록/전환/해제 시 활동 로그가 기록된다 (after_react 훅 → 리스너).
     *
     * @scenario case=react_logs_activity
     *
     * @effects react_logs_add_change_remove_activity
     */
    public function test_react_writes_activity_log(): void
    {
        $author = $this->createUser();
        $reactor = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $author->id]);

        // 등록 → reaction.add 로그
        $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($postId), ['reaction_type_id' => $this->likeId])
            ->assertOk();
        $this->assertDatabaseHas('activity_logs', ['action' => 'reaction.add']);

        // 전환 → reaction.change 로그
        $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($postId), ['reaction_type_id' => $this->dislikeId])
            ->assertOk();
        $this->assertDatabaseHas('activity_logs', ['action' => 'reaction.change']);

        // 해제 → reaction.remove 로그
        $this->actingAs($reactor, 'sanctum')
            ->postJson($this->reactUrl($postId), ['reaction_type_id' => $this->dislikeId])
            ->assertOk();
        $this->assertDatabaseHas('activity_logs', ['action' => 'reaction.remove']);
    }
}
