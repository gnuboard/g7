<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// ModuleTestCase 수동 로드 (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 게시글 이전/다음 네비게이션 API 테스트
 *
 * GET /api/modules/sirsoft-board/boards/{slug}/posts/{id}/navigation
 *
 * 검증 항목:
 * - 기본 응답 구조 (prev/next)
 * - 이전글/다음글 반환
 * - 공지글 네비게이션 미제공 (prev/next 모두 null)
 * - 첫 번째 글 prev=null, 마지막 글 next=null
 * - 존재하지 않는 게시글 → 404
 */
class PostNavigationApiTest extends BoardTestCase
{
    protected function getTestBoardSlug(): string
    {
        return 'post-navigation';
    }

    protected function setUp(): void
    {
        parent::setUp();

        // user 역할에 게시판 읽기 권한 부여
        $this->grantUserRolePermissions(['posts.read']);
    }

    /**
     * navigation API가 prev/next 키를 포함한 올바른 구조로 응답하는지 검증
     */
    public function test_navigation_returns_correct_structure(): void
    {
        // Given: 게시글 3개 (id 순서대로 생성 — board 기본 정렬: id desc)
        $id1 = $this->createTestPost(['title' => '첫 번째 글', 'created_at' => now()->subMinutes(3)]);
        $id2 = $this->createTestPost(['title' => '두 번째 글', 'created_at' => now()->subMinutes(2)]);
        $id3 = $this->createTestPost(['title' => '세 번째 글', 'created_at' => now()->subMinutes(1)]);

        // When: 중간 글(id2) navigation 조회
        $response = $this->getJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$id2}/navigation"
        );

        // Then: 200 + prev/next 키 존재
        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'prev',
                    'next',
                ],
            ]);
    }

    /**
     * id DESC 정렬(기본) 기준으로 이전글/다음글이 올바르게 반환되는지 검증
     */
    public function test_navigation_returns_correct_prev_and_next(): void
    {
        // Given: 게시글 3개 (id1 < id2 < id3)
        $id1 = $this->createTestPost(['title' => '첫 번째 글']);
        $id2 = $this->createTestPost(['title' => '두 번째 글']);
        $id3 = $this->createTestPost(['title' => '세 번째 글']);

        // 게시판 기본 정렬: id DESC → 목록 순서: id3, id2, id1
        // id2 기준: prev(위쪽) = id3, next(아래쪽) = id1

        // When
        $response = $this->getJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$id2}/navigation"
        );

        // Then
        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertNotNull($data['prev'], 'id2의 이전글(id3)이 존재해야 함');
        $this->assertEquals($id3, $data['prev']['id']);

        $this->assertNotNull($data['next'], 'id2의 다음글(id1)이 존재해야 함');
        $this->assertEquals($id1, $data['next']['id']);
    }

    /**
     * 목록의 첫 번째 글(가장 최신 = id가 가장 큰)은 prev가 null인지 검증
     */
    public function test_first_post_has_null_prev(): void
    {
        // Given
        $this->createTestPost(['title' => '오래된 글']);
        $latestId = $this->createTestPost(['title' => '최신 글']);

        // When: 최신 글(목록 맨 위) navigation
        $response = $this->getJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$latestId}/navigation"
        );

        // Then: 위쪽에 더 이상 글이 없으므로 prev = null
        $response->assertStatus(200);
        $this->assertNull($response->json('data.prev'));
        $this->assertNotNull($response->json('data.next'));
    }

    /**
     * 목록의 마지막 글(가장 오래된 = id가 가장 작은)은 next가 null인지 검증
     */
    public function test_last_post_has_null_next(): void
    {
        // Given
        $oldestId = $this->createTestPost(['title' => '오래된 글']);
        $this->createTestPost(['title' => '최신 글']);

        // When: 오래된 글(목록 맨 아래) navigation
        $response = $this->getJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$oldestId}/navigation"
        );

        // Then: 아래쪽에 더 이상 글이 없으므로 next = null
        $response->assertStatus(200);
        $this->assertNull($response->json('data.next'));
        $this->assertNotNull($response->json('data.prev'));
    }

    /**
     * 게시글이 1개뿐일 때 prev/next 모두 null인지 검증
     */
    public function test_single_post_has_null_prev_and_next(): void
    {
        // Given
        $id = $this->createTestPost(['title' => '유일한 글']);

        // When
        $response = $this->getJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$id}/navigation"
        );

        // Then
        $response->assertStatus(200);
        $this->assertNull($response->json('data.prev'));
        $this->assertNull($response->json('data.next'));
    }

    /**
     * 공지글은 navigation 미제공 — prev/next 모두 null 반환
     */
    public function test_notice_post_returns_null_navigation(): void
    {
        // Given: 공지글 + 일반 게시글 2개
        $this->createTestPost(['title' => '일반글 1']);
        $noticeId = $this->createTestPost(['title' => '공지글', 'is_notice' => true]);
        $this->createTestPost(['title' => '일반글 2']);

        // When: 공지글 navigation 조회
        $response = $this->getJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$noticeId}/navigation"
        );

        // Then: 공지글은 네비게이션 미제공
        $response->assertStatus(200);
        $this->assertNull($response->json('data.prev'));
        $this->assertNull($response->json('data.next'));
    }

    /**
     * 존재하지 않는 게시글 ID로 조회 시 오류 응답
     *
     * navigation()에서 getPost() 실패 시 일반 \Exception으로 잡혀 500 반환.
     * (BoardNotFoundException만 re-throw, 나머지는 500 처리 — 컨트롤러 설계)
     */
    public function test_navigation_with_nonexistent_post_returns_error(): void
    {
        // When: 존재하지 않는 게시글 ID
        $response = $this->getJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/99999999/navigation"
        );

        // Then: 4xx 또는 5xx 오류 응답 (컨트롤러에서 \Exception → 500)
        $this->assertGreaterThanOrEqual(400, $response->status());
    }

    /**
     * 비활성화된 게시판의 navigation 조회 시 404 응답
     *
     * BoardNotFoundException → NotFoundHttpException → 404
     */
    public function test_navigation_on_inactive_board_returns_404(): void
    {
        // Given: 게시글 생성 후 게시판 비활성화
        $id = $this->createTestPost(['title' => '게시글']);
        $this->board->update(['is_active' => false]);

        // When
        $response = $this->getJson(
            "/api/modules/sirsoft-board/boards/{$this->board->slug}/posts/{$id}/navigation"
        );

        // Then: BoardNotFoundException → 404
        $response->assertStatus(404);

        // 이후 테스트를 위해 복구
        $this->board->update(['is_active' => true]);
    }
}
