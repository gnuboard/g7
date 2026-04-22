<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Models\Comment;
use Modules\Sirsoft\Board\Models\Post;
use Modules\Sirsoft\Board\Tests\BoardTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * 사용자 게시글 활동 API 테스트
 *
 * BoardTestCase를 통해 테스트 보드와 데이터 정리를 자동으로 처리합니다.
 */
#[Group('board')]
#[Group('user-activities')]
class UserActivityApiTest extends BoardTestCase
{
    /**
     * 테스트용 사용자
     */
    private User $user;

    /**
     * 테스트 게시판 slug
     */
    protected function getTestBoardSlug(): string
    {
        return 'user-activity';
    }

    /**
     * 기본 게시판 속성
     */
    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '사용자 활동 테스트 게시판', 'en' => 'User Activity Test Board'],
            'is_active' => true,
            'blocked_keywords' => [],
        ];
    }

    /**
     * 각 테스트 전 실행
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 다른 테스트 클래스에서 user_id=1로 커밋된 posts가 user_id 기반 API에 영향을 미치므로 전체 정리
        // (truncateUserTables() 후 새 user는 항상 id=1을 받으므로 타 테스트 posts와 충돌)
        DB::table('board_posts')->delete();
        DB::table('board_comments')->delete();

        // 테스트 사용자 생성 (parent::setUp()에서 users 테이블 초기화됨)
        $this->user = User::factory()->create();
    }

    /**
     * 각 테스트 후 실행
     */
    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * 인증된 사용자가 자신의 게시글 활동을 조회할 수 있다.
     */
    public function test_authenticated_user_can_fetch_own_board_activities(): void
    {
        // Given: 사용자가 게시글을 작성했을 때
        $post = $this->createPost($this->board->slug, [
            'user_id' => $this->user->id,
            'title' => '테스트 게시글',
            'content' => '내용',
        ]);

        // When: 사용자가 자신의 활동을 조회하면
        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-board/me/board-activities');

        // Then: 성공 응답과 함께 작성한 게시글이 포함되어야 함
        $response->assertOk();

        // ResponseHelper는 Paginator를 data 키에 그대로 넣음
        // 실제 응답 구조: { success, message, data: { current_page, data: [...], ... } }
        $responseData = $response->json();

        $this->assertArrayHasKey('data', $responseData);

        // Paginator 구조에서 실제 데이터 추출
        $paginatorData = $responseData['data'];
        $this->assertArrayHasKey('data', $paginatorData);
        $this->assertIsArray($paginatorData['data']);
        $this->assertGreaterThan(0, count($paginatorData['data']));

        $firstActivity = $paginatorData['data'][0];
        $this->assertEquals('authored', $firstActivity['activity_type']);
        $this->assertEquals('테스트 게시글', $firstActivity['title']);
    }

    /**
     * 비인증 사용자는 게시글 활동을 조회할 수 없다.
     */
    public function test_guest_cannot_fetch_board_activities(): void
    {
        // When: 비인증 사용자가 활동을 조회하면
        $response = $this->getJson('/api/modules/sirsoft-board/me/board-activities');

        // Then: 401 응답이 반환되어야 함
        $response->assertUnauthorized();
    }

    /**
     * 게시판별로 활동을 필터링할 수 있다.
     */
    public function test_can_filter_activities_by_board_slug(): void
    {
        // Given: 두 개의 게시판과 각각에 게시글 작성
        $board1 = $this->board;
        $board2 = Board::factory()->create([
            'name' => ['ko' => '다른 게시판', 'en' => 'Another Board'],
            'is_active' => true,
        ]);

        $post1 = $this->createPost($board1->slug, [
            'user_id' => $this->user->id,
            'title' => '첫 번째 게시글',
        ]);

        $post2 = $this->createPost($board2->slug, [
            'board_id' => $board2->id,
            'user_id' => $this->user->id,
            'title' => '두 번째 게시글',
        ]);

        // When: 특정 게시판으로 필터링하면
        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-board/me/board-activities?board_slug='.$board1->slug);

        // Then: 해당 게시판의 게시글만 반환되어야 함
        $response->assertOk();

        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals($board1->slug, $data[0]['board_slug']);
        $this->assertEquals('첫 번째 게시글', $data[0]['title']);

        // 단일 테이블이므로 별도 정리 불필요 (RefreshDatabase가 처리)
    }

    /**
     * 제목으로 활동을 검색할 수 있다.
     */
    public function test_can_search_activities_by_title(): void
    {
        // Given: 여러 게시글 작성
        $this->createPost($this->board->slug, [
            'user_id' => $this->user->id,
            'title' => 'Laravel 튜토리얼',
        ]);

        $this->createPost($this->board->slug, [
            'user_id' => $this->user->id,
            'title' => 'React 가이드',
        ]);

        // When: "Laravel"로 검색하면
        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-board/me/board-activities?search=Laravel');

        // Then: Laravel이 포함된 게시글만 반환되어야 함
        $response->assertOk();

        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertStringContainsString('Laravel', $data[0]['title']);
    }

    /**
     * 작성한 게시글이 활동에 포함된다.
     */
    public function test_activity_includes_authored_posts(): void
    {
        // Given: 사용자가 게시글을 작성
        $post = $this->createPost($this->board->slug, [
            'user_id' => $this->user->id,
            'title' => '내가 작성한 게시글',
        ]);

        // When: 활동을 조회하면
        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-board/me/board-activities');

        // Then: activity_type이 'authored'여야 함
        $response->assertOk()
            ->assertJsonFragment([
                'activity_type' => 'authored',
                'title' => '내가 작성한 게시글',
            ]);
    }

    /**
     * 작성한 게시글 활동에 created_at_formatted 필드가 포함된다.
     */
    public function test_authored_post_activity_includes_created_at_formatted(): void
    {
        // Given: 사용자가 게시글을 작성
        $this->createPost($this->board->slug, [
            'user_id' => $this->user->id,
            'title' => '날짜 포맷 테스트 게시글',
        ]);

        // When: 활동을 조회하면
        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-board/me/board-activities');

        // Then: created_at_formatted 필드가 포함되어야 함
        $response->assertOk();
        $data = $response->json('data.data');
        $this->assertNotEmpty($data);

        $item = $data[0];
        $this->assertArrayHasKey('created_at_formatted', $item);
        $this->assertNotEmpty($item['created_at_formatted']);
    }

    /**
     * 댓글을 단 게시글이 활동에 포함된다.
     */
    public function test_activity_includes_commented_posts(): void
    {
        // Given: 다른 사용자의 게시글에 댓글 작성
        $otherUser = User::factory()->create();
        $post = $this->createPost($this->board->slug, [
            'user_id' => $otherUser->id,
            'title' => '다른 사람의 게시글',
        ]);

        $this->createComment($this->board->slug, $post->id, [
            'user_id' => $this->user->id,
            'content' => '내 댓글',
        ]);

        // When: activity_type=commented로 활동을 조회하면
        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-board/me/board-activities?activity_type=commented');

        // Then: activity_type이 'commented'여야 함
        $response->assertOk()
            ->assertJsonFragment([
                'activity_type' => 'commented',
                'title' => '다른 사람의 게시글',
            ]);

        // And: activity_count는 1이어야 함 (댓글 1개)
        $data = $response->json('data.data');
        $this->assertEquals(1, $data[0]['activity_count']);
    }

    /**
     * 활동 통계를 조회할 수 있다.
     */
    public function test_can_fetch_activity_stats(): void
    {
        // Given: 게시글과 댓글 작성
        $post = $this->createPost($this->board->slug, [
            'user_id' => $this->user->id,
            'title' => '테스트 게시글',
            'view_count' => 100,
        ]);

        $this->createComment($this->board->slug, $post->id, [
            'user_id' => $this->user->id,
            'content' => '내 댓글',
        ]);

        // When: 통계를 조회하면
        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-board/me/activity-stats');

        // Then: 통계 데이터가 반환되어야 함
        $response->assertOk();

        $data = $response->json('data');
        $this->assertEquals(1, $data['total_posts']);
        $this->assertEquals(1, $data['total_comments']);
        $this->assertEquals(100, $data['total_views']);
    }

    /**
     * 비인증 사용자는 통계를 조회할 수 없다.
     */
    public function test_guest_cannot_fetch_activity_stats(): void
    {
        // When: 비인증 사용자가 통계를 조회하면
        $response = $this->getJson('/api/modules/sirsoft-board/me/activity-stats');

        // Then: 401 응답이 반환되어야 함
        $response->assertUnauthorized();
    }

    /**
     * 삭제된 게시글의 댓글은 통계 카운트에서 제외된다.
     */
    public function test_stats_comments_on_deleted_posts_are_excluded(): void
    {
        // Given: 활성 게시글에 댓글 1개
        $activePost = $this->createPost($this->board->slug, [
            'user_id' => $this->user->id,
        ]);
        $this->createComment($this->board->slug, $activePost->id, [
            'user_id' => $this->user->id,
            'content' => '활성 게시글 댓글',
        ]);

        // And: 삭제된 게시글에 댓글 1개
        $deletedPost = $this->createPost($this->board->slug, [
            'user_id' => $this->user->id,
        ]);
        $this->createComment($this->board->slug, $deletedPost->id, [
            'user_id' => $this->user->id,
            'content' => '삭제된 게시글 댓글',
        ]);
        $deletedPost->delete();

        // When: 통계를 조회하면
        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-board/me/activity-stats');

        // Then: total_comments는 활성 게시글 댓글만 카운트 (1개)
        $response->assertOk();
        $data = $response->json('data');
        $this->assertEquals(1, $data['total_comments'], '삭제된 게시글의 댓글은 통계에서 제외되어야 함');
    }

    /**
     * 정렬 옵션으로 활동을 조회할 수 있다.
     */
    public function test_can_sort_activities(): void
    {
        // Given: 조회수가 다른 게시글들 작성 (created_at을 명시적으로 업데이트)
        $post1 = $this->createPost($this->board->slug, [
            'user_id' => $this->user->id,
            'title' => '조회수 낮은 게시글',
            'view_count' => 10,
        ]);
        $post1->created_at = now()->subDays(2);
        $post1->save();

        $post2 = $this->createPost($this->board->slug, [
            'user_id' => $this->user->id,
            'title' => '조회수 높은 게시글',
            'view_count' => 100,
        ]);
        $post2->created_at = now()->subDay();
        $post2->save();

        $post3 = $this->createPost($this->board->slug, [
            'user_id' => $this->user->id,
            'title' => '가장 최신 게시글',
            'view_count' => 50,
        ]);
        $post3->created_at = now();
        $post3->save();

        // When: 최신순으로 조회하면
        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-board/me/board-activities?sort=latest');

        $response->assertOk();
        $data = $response->json('data.data');
        $this->assertEquals('가장 최신 게시글', $data[0]['title']);

        // When: 조회순으로 조회하면
        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-board/me/board-activities?sort=views');

        $response->assertOk();
        $data = $response->json('data.data');
        $this->assertEquals('조회수 높은 게시글', $data[0]['title']);

        // When: 오래된순으로 조회하면
        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-board/me/board-activities?sort=oldest');

        $response->assertOk();
        $data = $response->json('data.data');
        $this->assertEquals('조회수 낮은 게시글', $data[0]['title']);
    }

    /**
     * 삭제된 게시글은 활동에서 제외된다.
     */
    public function test_deleted_posts_are_excluded_from_activities(): void
    {
        // Given: 게시글 작성 후 삭제
        $post = $this->createPost($this->board->slug, [
            'user_id' => $this->user->id,
            'title' => '삭제될 게시글',
        ]);

        $post->delete(); // Soft delete

        // When: 활동을 조회하면
        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-board/me/board-activities');

        // Then: 삭제된 게시글은 포함되지 않아야 함
        $response->assertOk();

        $data = $response->json('data.data');
        $this->assertCount(0, $data);
    }

    /**
     * 페이지네이션이 정상 동작한다.
     */
    public function test_pagination_works(): void
    {
        // Given: 25개의 게시글 작성 (기본 페이지당 20개)
        for ($i = 1; $i <= 25; $i++) {
            $this->createPost($this->board->slug, [
                'user_id' => $this->user->id,
                'title' => "게시글 {$i}",
            ]);
        }

        // When: 첫 페이지 조회
        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-board/me/board-activities?page=1');

        // Then: 20개의 항목과 페이지네이션 메타데이터가 있어야 함
        $response->assertOk();

        $this->assertCount(20, $response->json('data.data'));
        $this->assertEquals(1, $response->json('data.current_page'));
        $this->assertEquals(2, $response->json('data.last_page'));
        $this->assertEquals(25, $response->json('data.total'));

        // When: 두 번째 페이지 조회
        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-board/me/board-activities?page=2');

        // Then: 5개의 항목이 있어야 함
        $response->assertOk();
        $this->assertCount(5, $response->json('data.data'));
    }

    /**
     * 동일 게시글에 여러 활동이 있을 경우 authored 우선순위가 적용된다.
     */
    public function test_authored_activity_takes_priority_over_commented(): void
    {
        // Given: 자신이 작성한 게시글에 자신이 댓글도 단 경우
        $post = $this->createPost($this->board->slug, [
            'user_id' => $this->user->id,
            'title' => '내가 작성하고 댓글도 단 게시글',
        ]);

        $this->createComment($this->board->slug, $post->id, [
            'user_id' => $this->user->id,
            'content' => '내 댓글',
        ]);

        // When: 활동을 조회하면
        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-board/me/board-activities');

        // Then: activity_type은 'authored'여야 함 (우선순위)
        $response->assertOk();

        $data = $response->json('data.data');
        $this->assertCount(1, $data); // 중복 제거됨
        $this->assertEquals('authored', $data[0]['activity_type']);
    }

    /**
     * activity_type으로 활동을 필터링할 수 있다.
     */
    public function test_can_filter_by_activity_type(): void
    {
        // Given: 다양한 활동이 있을 때
        $otherUser = User::factory()->create();

        // 1. 내가 작성한 게시글
        $this->createPost($this->board->slug, [
            'user_id' => $this->user->id,
            'title' => '내가 작성한 게시글',
        ]);

        // 2. 댓글 단 게시글
        $post2 = $this->createPost($this->board->slug, [
            'user_id' => $otherUser->id,
            'title' => '댓글 단 게시글',
        ]);
        $this->createComment($this->board->slug, $post2->id, [
            'user_id' => $this->user->id,
            'content' => '내 댓글',
        ]);

        // When: activity_type=authored로 필터링하면
        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-board/me/board-activities?activity_type=authored');

        // Then: authored만 반환되어야 함
        $response->assertOk();
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('authored', $data[0]['activity_type']);
        $this->assertEquals('내가 작성한 게시글', $data[0]['title']);

        // When: activity_type=commented로 필터링하면
        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-board/me/board-activities?activity_type=commented');

        // Then: commented만 반환되어야 함
        $response->assertOk();
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('commented', $data[0]['activity_type']);
        $this->assertEquals('댓글 단 게시글', $data[0]['title']);
    }

    /**
     * 내가 작성한 댓글 목록 API를 조회할 수 있다.
     */
    public function test_authenticated_user_can_fetch_own_comments(): void
    {
        // Given: 다른 사용자의 게시글에 내가 댓글을 달았을 때
        $otherUser = User::factory()->create();
        $post = $this->createPost($this->board->slug, [
            'user_id' => $otherUser->id,
            'title' => '다른 사람 게시글',
        ]);
        $this->createComment($this->board->slug, $post->id, [
            'user_id' => $this->user->id,
            'content' => '내가 쓴 댓글',
        ]);

        // When: /me/my-comments 엔드포인트 호출
        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-board/me/my-comments');

        // Then: 댓글 목록이 반환되어야 함
        $response->assertOk();

        $paginatorData = $response->json('data');
        $this->assertArrayHasKey('data', $paginatorData);
        $this->assertGreaterThan(0, count($paginatorData['data']));

        $firstComment = $paginatorData['data'][0];
        $this->assertEquals('내가 쓴 댓글', $firstComment['content']);
        $this->assertEquals('다른 사람 게시글', $firstComment['post_title']);
        $this->assertNotEmpty($firstComment['board_slug']);
        $this->assertNotEmpty($firstComment['board_name']);
        $this->assertNotNull($firstComment['post_id_val']);
        $this->assertNotEmpty($firstComment['created_at_formatted']);
    }

    /**
     * 비인증 사용자는 내 댓글 목록을 조회할 수 없다.
     */
    public function test_guest_cannot_fetch_own_comments(): void
    {
        $response = $this->getJson('/api/modules/sirsoft-board/me/my-comments');
        $response->assertUnauthorized();
    }

    /**
     * 내 댓글 목록은 게시판 필터와 정렬을 지원한다.
     */
    public function test_my_comments_supports_board_filter_and_sort(): void
    {
        // Given: 두 게시판에 각각 댓글 작성
        $board2 = Board::factory()->create([
            'name' => ['ko' => '두 번째 게시판', 'en' => 'Second Board'],
            'is_active' => true,
        ]);

        $post1 = $this->createPost($this->board->slug, ['user_id' => $this->user->id, 'title' => '게시판1 글']);
        $post2 = Post::create([
            'board_id' => $board2->id,
            'title' => '게시판2 글',
            'content' => '내용',
            'user_id' => $this->user->id,
            'ip_address' => '127.0.0.1',
            'is_notice' => false,
            'is_secret' => false,
            'status' => 'published',
        ]);

        $this->createComment($this->board->slug, $post1->id, ['user_id' => $this->user->id, 'content' => '게시판1 댓글']);
        Comment::create([
            'board_id' => $board2->id,
            'post_id' => $post2->id,
            'content' => '게시판2 댓글',
            'user_id' => $this->user->id,
            'status' => 'published',
        ]);

        // When: board_slug 필터로 게시판1만 조회
        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-board/me/my-comments?board_slug='.$this->board->slug);

        // Then: 게시판1 댓글만 반환
        $response->assertOk();
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('게시판1 댓글', $data[0]['content']);

        // When: 정렬 oldest로 조회
        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-board/me/my-comments?sort=oldest');

        // Then: 성공 응답
        $response->assertOk();
        $this->assertCount(2, $response->json('data.data'));
    }

    /**
     * 삭제된 게시글에 달린 내 댓글은 목록에서 제외된다.
     */
    public function test_comments_on_deleted_posts_are_excluded(): void
    {
        // Given: 게시글에 댓글을 달고, 이후 게시글이 삭제됨
        $post = $this->createPost($this->board->slug, [
            'user_id' => $this->user->id,
            'title' => '삭제될 게시글',
        ]);
        $this->createComment($this->board->slug, $post->id, [
            'user_id' => $this->user->id,
            'content' => '삭제된 게시글의 댓글',
        ]);

        // 게시글 소프트 삭제
        $post->delete();

        // When: 내 댓글 목록 조회
        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-board/me/my-comments');

        // Then: 삭제된 게시글의 댓글은 포함되지 않아야 함
        $response->assertOk();
        $data = $response->json('data.data');
        $contents = array_column($data, 'content');
        $this->assertNotContains('삭제된 게시글의 댓글', $contents);
    }

    /**
     * 비활성 게시판의 게시글은 내 게시글 목록에서 제외된다.
     */
    public function test_posts_on_inactive_board_are_excluded(): void
    {
        // Given: 비활성 게시판에 게시글 작성
        $inactiveBoard = Board::factory()->create([
            'name' => ['ko' => '비활성 게시판', 'en' => 'Inactive Board'],
            'is_active' => false,
        ]);

        Post::create([
            'board_id' => $inactiveBoard->id,
            'title' => '비활성 게시판 글',
            'content' => '내용',
            'user_id' => $this->user->id,
            'ip_address' => '127.0.0.1',
            'is_notice' => false,
            'is_secret' => false,
            'status' => 'published',
        ]);

        // When: 내 게시글 목록 조회
        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-board/me/board-activities');

        // Then: 비활성 게시판 게시글은 포함되지 않아야 함
        $response->assertOk();
        $data = $response->json('data.data');
        $titles = array_column($data, 'title');
        $this->assertNotContains('비활성 게시판 글', $titles);
    }

    /**
     * 비활성 게시판의 댓글은 목록에서 제외된다.
     */
    public function test_comments_on_inactive_board_are_excluded(): void
    {
        // Given: 비활성 게시판에 게시글·댓글 작성
        $inactiveBoard = Board::factory()->create([
            'name' => ['ko' => '비활성 게시판', 'en' => 'Inactive Board'],
            'is_active' => false,
        ]);

        $post = Post::create([
            'board_id' => $inactiveBoard->id,
            'title' => '비활성 게시판 글',
            'content' => '내용',
            'user_id' => $this->user->id,
            'ip_address' => '127.0.0.1',
            'is_notice' => false,
            'is_secret' => false,
            'status' => 'published',
        ]);
        Comment::create([
            'board_id' => $inactiveBoard->id,
            'post_id' => $post->id,
            'content' => '비활성 게시판 댓글',
            'user_id' => $this->user->id,
            'status' => 'published',
        ]);

        // When: 내 댓글 목록 조회
        $response = $this->actingAs($this->user)
            ->getJson('/api/modules/sirsoft-board/me/my-comments');

        // Then: 비활성 게시판 댓글은 포함되지 않아야 함
        $response->assertOk();
        $data = $response->json('data.data');
        $contents = array_column($data, 'content');
        $this->assertNotContains('비활성 게시판 댓글', $contents);
    }

    /**
     * 헬퍼 메서드: 게시글 생성
     *
     * @param  string  $slug  사용하지 않음 (단일 테이블 전환 후 board_id로 대체)
     */
    private function createPost(string $slug, array $attributes = []): Post
    {
        return Post::create(array_merge([
            'board_id' => $this->board->id,
            'title' => 'Test Post',
            'content' => 'Test Content',
            'user_id' => $this->user->id,
            'ip_address' => '127.0.0.1',
            'is_notice' => false,
            'is_secret' => false,
            'status' => 'published',
        ], $attributes));
    }

    /**
     * 헬퍼 메서드: 댓글 생성
     *
     * @param  string  $slug  사용하지 않음 (단일 테이블 전환 후 board_id로 대체)
     */
    private function createComment(string $slug, int $postId, array $attributes = []): Comment
    {
        return Comment::create(array_merge([
            'board_id' => $this->board->id,
            'post_id' => $postId,
            'content' => 'Test Comment',
            'user_id' => $this->user->id,
            'status' => 'published',
        ], $attributes));
    }
}