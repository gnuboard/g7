<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Enums\PostStatus;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 인기 게시글 API 테스트
 *
 * 홈 페이지에서 사용할 인기 게시글 조회 API의 동작을 검증합니다.
 * - 조회수(view_count) 기준 정렬
 * - 기간별 필터링 (today, week, month, all)
 * - author nested 객체 구조
 * - comment_count 서브쿼리 집계
 *
 * @group board
 * @group board-popular
 */
class BoardPopularApiTest extends ModuleTestCase
{
    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 기존 활성 게시판 비활성화
        Board::where('is_active', true)->update(['is_active' => false]);

        // 캐시 클리어
        Cache::flush();
    }

    /**
     * 테스트 종료 후 정리
     */
    protected function tearDown(): void
    {
        parent::tearDown();
    }

    /**
     * 인기 게시글 API가 올바른 구조로 응답하는지 테스트
     */
    public function test_popular_returns_correct_structure(): void
    {
        // Given: 활성화된 게시판과 게시글 생성
        $this->createBoardWithPosts(5);

        // When: 인기 게시글 API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/popular');

        // Then: 올바른 구조 반환
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'id',
                        'board_slug',
                        'board_name',
                        'title',
                        'excerpt',
                        'author' => ['id', 'name', 'email', 'is_guest'],
                        'view_count',
                        'comment_count',
                        'created_at',
                    ],
                ],
            ]);
    }

    /**
     * 기본 limit이 20개인지 테스트
     */
    public function test_popular_default_limit_is_twenty(): void
    {
        // Given: 25개 게시글 생성
        $this->createBoardWithPosts(25);

        // When: limit 없이 API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/popular');

        // Then: 20개 반환
        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertLessThanOrEqual(20, count($data));
    }

    /**
     * limit 파라미터가 동작하는지 테스트
     */
    public function test_popular_respects_limit_parameter(): void
    {
        // Given: 15개 게시글 생성
        $this->createBoardWithPosts(15);

        // When: limit=5로 API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/popular?limit=5');

        // Then: 5개 반환
        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(5, $data);
    }

    /**
     * limit 최대값이 50인지 테스트
     */
    public function test_popular_max_limit_is_fifty(): void
    {
        // Given: 60개 게시글 생성
        $this->createBoardWithPosts(60);

        // When: limit=100으로 API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/popular?limit=100');

        // Then: 최대 50개만 반환
        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertLessThanOrEqual(50, count($data));
    }

    /**
     * view_count 기준 내림차순 정렬되는지 테스트
     */
    public function test_popular_sorted_by_popularity_score_desc(): void
    {
        // Given: view_count가 다른 게시글 생성
        $board = Board::factory()->create(['is_active' => true]);
        $this->ensureBoardPartitions($board->id);

        DB::table('board_posts')->insert([
            ['board_id' => $board->id, 'title' => 'Post 1', 'content' => 'Content 1', 'view_count' => 100, 'status' => PostStatus::Published->value, 'ip_address' => '127.0.0.1', 'created_at' => now(), 'updated_at' => now()],
            ['board_id' => $board->id, 'title' => 'Post 2', 'content' => 'Content 2', 'view_count' => 300, 'status' => PostStatus::Published->value, 'ip_address' => '127.0.0.1', 'created_at' => now(), 'updated_at' => now()],
            ['board_id' => $board->id, 'title' => 'Post 3', 'content' => 'Content 3', 'view_count' => 200, 'status' => PostStatus::Published->value, 'ip_address' => '127.0.0.1', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // When: API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/popular');

        // Then: view_count 기준 내림차순 정렬
        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertEquals(300, $data[0]['view_count']);
        $this->assertEquals(200, $data[1]['view_count']);
        $this->assertEquals(100, $data[2]['view_count']);
    }

    /**
     * period=today 필터가 동작하는지 테스트
     */
    public function test_popular_filters_by_period_today(): void
    {
        // Given: 오늘과 어제 게시글 생성
        $board = Board::factory()->create(['is_active' => true]);
        $this->ensureBoardPartitions($board->id);

        DB::table('board_posts')->insert([
            ['board_id' => $board->id, 'title' => 'Today Post', 'content' => 'Content', 'view_count' => 100, 'status' => PostStatus::Published->value, 'ip_address' => '127.0.0.1', 'created_at' => now(), 'updated_at' => now()],
            ['board_id' => $board->id, 'title' => 'Yesterday Post', 'content' => 'Content', 'view_count' => 200, 'status' => PostStatus::Published->value, 'ip_address' => '127.0.0.1', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()],
        ]);

        // When: period=today로 API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/popular?period=today');

        // Then: 오늘 게시글만 반환
        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals('Today Post', $data[0]['title']);
    }

    /**
     * period=week 필터가 동작하는지 테스트
     */
    public function test_popular_filters_by_period_week(): void
    {
        // Given: 최근 1주일과 2주 전 게시글 생성
        $board = Board::factory()->create(['is_active' => true]);
        $this->ensureBoardPartitions($board->id);

        DB::table('board_posts')->insert([
            ['board_id' => $board->id, 'title' => 'This Week', 'content' => 'Content', 'view_count' => 100, 'status' => PostStatus::Published->value, 'ip_address' => '127.0.0.1', 'created_at' => now()->subDays(3), 'updated_at' => now()],
            ['board_id' => $board->id, 'title' => 'Two Weeks Ago', 'content' => 'Content', 'view_count' => 200, 'status' => PostStatus::Published->value, 'ip_address' => '127.0.0.1', 'created_at' => now()->subWeeks(2), 'updated_at' => now()],
        ]);

        // When: period=week로 API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/popular?period=week');

        // Then: 최근 1주일 게시글만 반환
        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals('This Week', $data[0]['title']);
    }

    /**
     * period=all이 year(최근 1년)로 매핑되는지 테스트
     * all → year 하위 호환 매핑: 최근 1년 이내 게시글만 반환
     */
    public function test_popular_period_all_maps_to_year(): void
    {
        // Given: 1년 이내/이전 게시글 생성
        $board = Board::factory()->create(['is_active' => true]);
        $this->ensureBoardPartitions($board->id);

        DB::table('board_posts')->insert([
            ['board_id' => $board->id, 'title' => 'Recent', 'content' => 'Content', 'view_count' => 100, 'status' => PostStatus::Published->value, 'ip_address' => '127.0.0.1', 'created_at' => now(), 'updated_at' => now()],
            ['board_id' => $board->id, 'title' => 'Six Months Ago', 'content' => 'Content', 'view_count' => 200, 'status' => PostStatus::Published->value, 'ip_address' => '127.0.0.1', 'created_at' => now()->subMonths(6), 'updated_at' => now()],
            ['board_id' => $board->id, 'title' => 'Over One Year', 'content' => 'Content', 'view_count' => 300, 'status' => PostStatus::Published->value, 'ip_address' => '127.0.0.1', 'created_at' => now()->subMonths(13), 'updated_at' => now()],
        ]);

        // When: period=all로 API 호출 (year로 매핑됨)
        $response = $this->getJson('/api/modules/sirsoft-board/boards/popular?period=all');

        // Then: 1년 이내 게시글만 반환 (Over One Year 제외)
        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(2, $data);
        $titles = array_column($data, 'title');
        $this->assertContains('Recent', $titles);
        $this->assertContains('Six Months Ago', $titles);
        $this->assertNotContains('Over One Year', $titles);
    }

    /**
     * 게시글이 없을 때 빈 배열을 반환하는지 테스트
     */
    public function test_popular_returns_empty_when_no_posts(): void
    {
        // Given: 게시판만 있고 게시글 없음
        Board::factory()->create(['is_active' => true]);

        // When: API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/popular');

        // Then: 빈 배열 반환
        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertIsArray($data);
        $this->assertEmpty($data);
    }

    /**
     * 비활성화된 게시판의 게시글은 제외되는지 테스트
     */
    public function test_popular_excludes_inactive_board_posts(): void
    {
        // Given: 활성/비활성 게시판 생성
        $activeBoard = $this->createBoardWithPosts(5);

        $inactiveBoard = Board::factory()->create([
            'is_active' => false,
            'name' => ['ko' => '비활성 게시판', 'en' => 'Inactive Board'],
        ]);
        $this->createBoardWithPosts(10, $inactiveBoard);

        // When: API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/popular');

        // Then: 비활성 게시판 게시글 제외
        $response->assertStatus(200);
        $data = $response->json('data');

        foreach ($data as $post) {
            $this->assertNotEquals($inactiveBoard->slug, $post['board_slug']);
        }
    }

    /**
     * comment_count가 서브쿼리로 정확히 집계되는지 테스트
     */
    public function test_popular_returns_correct_comment_count(): void
    {
        // Given: 게시글과 댓글 생성
        $board = Board::factory()->create(['is_active' => true]);
        $this->ensureBoardPartitions($board->id);

        // comments_count 컬럼에 직접 값 설정 (캐시 컬럼 방식)
        $postId = DB::table('board_posts')->insertGetId([
            'board_id' => $board->id,
            'title' => 'Post with comments',
            'content' => 'Content',
            'view_count' => 100,
            'comments_count' => 3,
            'status' => PostStatus::Published->value,
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // When: API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/popular');

        // Then: comment_count가 3
        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertEquals(3, $data[0]['comment_count']);
    }

    /**
     * author 객체 구조가 올바른지 테스트 (게스트)
     */
    public function test_popular_returns_correct_author_structure_for_guest(): void
    {
        // Given: 게스트가 작성한 게시글
        $board = Board::factory()->create(['is_active' => true]);
        $this->ensureBoardPartitions($board->id);

        DB::table('board_posts')->insert([
            'board_id' => $board->id,
            'title' => 'Guest Post',
            'content' => 'Content',
            'author_name' => '익명',
            'user_id' => null,
            'view_count' => 100,
            'status' => PostStatus::Published->value,
            'ip_address' => '127.0.0.1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // When: API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/popular');

        // Then: author 구조 확인
        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertNull($data[0]['author']['id']);
        $this->assertEquals('익명', $data[0]['author']['name']);
        $this->assertNull($data[0]['author']['email']);
        $this->assertTrue($data[0]['author']['is_guest']);
    }

    /**
     * 결과가 캐시되는지 테스트
     */
    public function test_popular_are_cached(): void
    {
        // Given: 게시글 생성
        $this->createBoardWithPosts(5);

        // When: 첫 번째 API 호출
        $response1 = $this->getJson('/api/modules/sirsoft-board/boards/popular?limit=5&period=week');
        $response1->assertStatus(200);

        // 캐시 키 확인 (형식: g7:module.sirsoft-board:popular_posts_{period}_{limit})
        $this->assertTrue(Cache::has("g7:module.sirsoft-board:popular_posts_week_5"));

        // When: 두 번째 API 호출
        $response2 = $this->getJson('/api/modules/sirsoft-board/boards/popular?limit=5&period=week');
        $response2->assertStatus(200);

        // Then: 같은 결과 반환
        $this->assertEquals($response1->json('data'), $response2->json('data'));
    }

    /**
     * 여러 게시판의 게시글이 통합되는지 테스트
     */
    public function test_popular_aggregates_posts_from_multiple_boards(): void
    {
        // Given: 3개 게시판에 각각 게시글 생성
        $this->createBoardWithPosts(2);
        $this->createBoardWithPosts(2);
        $this->createBoardWithPosts(2);

        // When: API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/popular?limit=10');

        // Then: 여러 게시판의 게시글이 섞여서 반환
        $response->assertStatus(200);
        $data = $response->json('data');

        $boardSlugs = array_unique(array_column($data, 'board_slug'));
        $this->assertGreaterThan(1, count($boardSlugs));
    }

    /**
     * 인기글 응답에 created_at(요일 포함 포맷)과 created_at_formatted(표시용) 필드가 포함되는지 확인
     */
    public function test_popular_includes_created_at_and_created_at_formatted(): void
    {
        // Given: 게시판과 게시글 생성
        $this->createBoardWithPosts(1);

        // When: API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/popular');

        // Then: 응답에 created_at/created_at_formatted 필드 포함
        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertNotEmpty($data);
        $item = $data[0];

        // created_at: 요일 포함 전체 날짜 포맷
        $this->assertArrayHasKey('created_at', $item);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} [가-힣]+요일 \d{2}:\d{2}$/', $item['created_at']);

        // created_at_formatted: 표시용 포맷 (비어있지 않은 문자열)
        $this->assertArrayHasKey('created_at_formatted', $item);
        $this->assertNotEmpty($item['created_at_formatted']);
    }

    /**
     * 게시판을 생성하고 게시글을 삽입하는 헬퍼
     *
     * @param int $postCount 생성할 게시글 수
     * @param Board|null $board 기존 게시판 (null이면 새로 생성)
     * @return Board 생성된 게시판
     */
    private function createBoardWithPosts(int $postCount, ?Board $board = null): Board
    {
        if ($board === null) {
            $board = Board::factory()->create([
                'is_active' => true,
            ]);
        }

        // LIST 파티션 테이블이므로 board_id 파티션이 없으면 INSERT 오류
        $this->ensureBoardPartitions($board->id);

        for ($i = 0; $i < $postCount; $i++) {
            DB::table('board_posts')->insert([
                'board_id' => $board->id,
                'title' => "테스트 게시글 {$i}",
                'content' => "게시글 내용 {$i}",
                'author_name' => '작성자',
                'view_count' => rand(50, 500),
                'status' => PostStatus::Published->value,
                'ip_address' => '127.0.0.1',
                'created_at' => now()->subMinutes($i),
                'updated_at' => now()->subMinutes($i),
            ]);
        }

        return $board;
    }
}
