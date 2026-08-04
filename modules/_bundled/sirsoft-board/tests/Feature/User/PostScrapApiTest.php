<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 사용자 게시글 스크랩 API 테스트
 *
 * 검증 목적 (기획서 §12):
 * - 스크랩 toggle: 등록·해제 양방향, scrap_count 증감 정합성
 * - 마이페이지 개별 삭제: 본인 소유 레코드만 허용
 * - 스코프 검증: 다른 게시판 postId 로 toggle 요청 시 차단
 * - use_scrap=false → 403
 * - 비로그인 → 401
 * - 활동 로그 기록 (scrap.scrap / scrap.unscrap)
 * - 목록 조회: 최근 스크랩순, 삭제된 원글 포함
 *
 * URL:
 * - POST   /api/modules/sirsoft-board/boards/{slug}/posts/{id}/scrap/toggle
 * - GET    /api/modules/sirsoft-board/me/scraps
 * - DELETE /api/modules/sirsoft-board/me/scraps/{id}
 *
 * @group board
 * @group user
 * @group scrap
 */
class PostScrapApiTest extends BoardTestCase
{
    protected function getTestBoardSlug(): string
    {
        return 'user-scrap-api';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '스크랩 API 테스트 게시판', 'en' => 'Scrap API Test Board'],
            'is_active' => true,
            'use_scrap' => true,
            'use_comment' => true,
            'secret_mode' => 'disabled',
            'blocked_keywords' => [],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setGuestPermissions(['posts.read', 'posts.write', 'comments.write']);
        $this->grantUserRolePermissions(['posts.read', 'posts.write', 'comments.write']);
    }

    // ==========================================
    // 스크랩 토글 (등록 / 해제)
    // ==========================================

    /**
     * 회원이 게시글을 스크랩 → added=true, 레코드 생성, scrap_count +1
     */
    public function test_authenticated_user_can_scrap_post(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost();

        $response = $this->actingAs($user)->postJson($this->toggleUrl($postId));

        $response->assertStatus(200);
        $response->assertJsonPath('data.added', true);

        $this->assertDatabaseHas('board_post_scraps', [
            'user_id' => $user->id,
            'post_id' => $postId,
            'board_id' => $this->board->id,
        ]);
        $this->assertSame(1, (int) DB::table('board_posts')->where('id', $postId)->value('scrap_count'));
    }

    /**
     * 이미 스크랩한 게시글에 재요청 → added=false, 레코드 삭제, scrap_count -1
     */
    public function test_toggle_removes_existing_scrap(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost();

        // 1차 등록
        $this->actingAs($user)->postJson($this->toggleUrl($postId))->assertStatus(200);
        // 2차 해제
        $response = $this->actingAs($user)->postJson($this->toggleUrl($postId));

        $response->assertStatus(200);
        $response->assertJsonPath('data.added', false);

        $this->assertDatabaseMissing('board_post_scraps', [
            'user_id' => $user->id,
            'post_id' => $postId,
        ]);
        $this->assertSame(0, (int) DB::table('board_posts')->where('id', $postId)->value('scrap_count'));
    }

    /**
     * 본인 글도 스크랩 가능 (확정 07 — 셀프 어뷰징 우려 없음)
     */
    public function test_user_can_scrap_own_post(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost(['user_id' => $user->id]);

        $response = $this->actingAs($user)->postJson($this->toggleUrl($postId));

        $response->assertStatus(200);
        $response->assertJsonPath('data.added', true);
    }

    /**
     * 미인증 요청 → 401
     */
    public function test_unauthenticated_cannot_toggle_scrap(): void
    {
        $postId = $this->createTestPost();

        $this->postJson($this->toggleUrl($postId))->assertStatus(401);

        $this->assertDatabaseMissing('board_post_scraps', ['post_id' => $postId]);
    }

    /**
     * use_scrap=false 게시판 → 403
     */
    public function test_toggle_blocked_when_scrap_disabled(): void
    {
        $this->updateBoardSettings(['use_scrap' => false]);
        $user = $this->createUser();
        $postId = $this->createTestPost();

        $this->actingAs($user)->postJson($this->toggleUrl($postId))->assertStatus(403);

        $this->assertDatabaseMissing('board_post_scraps', ['post_id' => $postId]);
    }

    /**
     * 다른 게시판의 postId 로 toggle 요청 → 404 (스코프 검증, 교차 접근 차단)
     */
    public function test_toggle_rejects_post_from_another_board(): void
    {
        $user = $this->createUser();

        // 다른 게시판과 그 소속 게시글 생성
        $otherBoardId = DB::table('boards')->insertGetId([
            'slug' => 'other-scrap-board',
            'name' => json_encode(['ko' => '다른 게시판', 'en' => 'Other Board']),
            'is_active' => true,
            'use_scrap' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherPostId = $this->createTestPost(['board_id' => $otherBoardId]);

        // 우리 게시판 slug 로 다른 게시판 게시글을 스크랩 시도
        $response = $this->actingAs($user)->postJson($this->toggleUrl($otherPostId));

        $response->assertStatus(404);
        $this->assertDatabaseMissing('board_post_scraps', ['post_id' => $otherPostId]);
    }

    /**
     * 스크랩 등록 시 활동 로그(scrap.scrap) 기록
     */
    public function test_scrap_add_writes_activity_log(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost();

        $this->actingAs($user)->postJson($this->toggleUrl($postId))->assertStatus(200);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'scrap.scrap',
            'loggable_type' => Post::class,
            'loggable_id' => $postId,
        ]);
    }

    /**
     * 스크랩 해제 시 활동 로그(scrap.unscrap) 기록
     */
    public function test_scrap_remove_writes_activity_log(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost();

        $this->actingAs($user)->postJson($this->toggleUrl($postId))->assertStatus(200);
        $this->actingAs($user)->postJson($this->toggleUrl($postId))->assertStatus(200);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'scrap.unscrap',
            'loggable_type' => Post::class,
            'loggable_id' => $postId,
        ]);
    }

    // ==========================================
    // 마이페이지 목록 조회
    // ==========================================

    /**
     * 스크랩 목록 조회 → 최근 스크랩순
     */
    public function test_index_returns_scraps_recent_first(): void
    {
        $user = $this->createUser();
        $firstPost = $this->createTestPost(['title' => '먼저 스크랩']);
        $secondPost = $this->createTestPost(['title' => '나중 스크랩']);

        $this->actingAs($user)->postJson($this->toggleUrl($firstPost))->assertStatus(200);
        $this->actingAs($user)->postJson($this->toggleUrl($secondPost))->assertStatus(200);

        $response = $this->actingAs($user)->getJson('/api/modules/sirsoft-board/me/scraps');

        $response->assertStatus(200);
        // 최근 스크랩(secondPost)이 먼저
        $response->assertJsonPath('data.data.0.post_id', $secondPost);
        $response->assertJsonPath('data.data.1.post_id', $firstPost);
    }

    /**
     * 삭제된 원글의 스크랩도 목록에 포함되며 is_deleted=true
     */
    public function test_index_includes_deleted_post_scrap(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost();

        $this->actingAs($user)->postJson($this->toggleUrl($postId))->assertStatus(200);

        // 원글 소프트 삭제
        DB::table('board_posts')->where('id', $postId)->update(['deleted_at' => now()]);

        $response = $this->actingAs($user)->getJson('/api/modules/sirsoft-board/me/scraps');

        $response->assertStatus(200);
        $response->assertJsonPath('data.data.0.is_deleted', true);
    }

    // ==========================================
    // 마이페이지 개별 삭제
    // ==========================================

    /**
     * 본인 스크랩 개별 삭제 → 성공, 레코드 제거, scrap_count -1
     */
    public function test_user_can_delete_own_scrap(): void
    {
        $user = $this->createUser();
        $postId = $this->createTestPost();

        $this->actingAs($user)->postJson($this->toggleUrl($postId))->assertStatus(200);
        $scrapId = DB::table('board_post_scraps')->where('user_id', $user->id)->value('id');

        $response = $this->actingAs($user)->deleteJson("/api/modules/sirsoft-board/me/scraps/{$scrapId}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('board_post_scraps', ['id' => $scrapId]);
        $this->assertSame(0, (int) DB::table('board_posts')->where('id', $postId)->value('scrap_count'));
    }

    /**
     * 다른 회원의 스크랩 삭제 시도 → 404 (본인 소유 검증)
     */
    public function test_user_cannot_delete_others_scrap(): void
    {
        $owner = $this->createUser();
        $attacker = $this->createUser();
        $postId = $this->createTestPost();

        $this->actingAs($owner)->postJson($this->toggleUrl($postId))->assertStatus(200);
        $scrapId = DB::table('board_post_scraps')->where('user_id', $owner->id)->value('id');

        $response = $this->actingAs($attacker)->deleteJson("/api/modules/sirsoft-board/me/scraps/{$scrapId}");

        $response->assertStatus(404);
        // 소유자 레코드는 그대로 유지
        $this->assertDatabaseHas('board_post_scraps', ['id' => $scrapId]);
    }

    /**
     * 스크랩 토글 URL 을 생성합니다.
     *
     * @param  int  $postId  게시글 ID
     * @return string 토글 엔드포인트 URL
     */
    private function toggleUrl(int $postId): string
    {
        $slug = $this->board->slug;

        return "/api/modules/sirsoft-board/boards/{$slug}/posts/{$postId}/scrap/toggle";
    }
}
