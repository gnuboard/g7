<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Http\Middleware\PermissionMiddleware;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 전체 게시판 목록 API 테스트
 *
 * 전체 게시판 목록 페이지용 경량 API의 동작을 검증합니다.
 * 디렉토리는 호출자 열람 권한(sirsoft-board.{slug}.posts.read) 필터를 적용하므로,
 * 게스트 시점 검증용 게시판에는 setUp 에서 guest 읽기 권한을 부여한다
 * (최근글·인기글 가시성 테스트와 동일 관례 — PublicRecentPostsVisibilityTest).
 */
class BoardListApiTest extends ModuleTestCase
{
    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();
        // DDL implicit commit으로 커밋된 게시판 비활성화 (데이터 격리 보장)
        DB::table('boards')->update(['is_active' => false]);
        // 이 테스트 클래스에서 사용하는 슬러그 정리 (커밋된 경우 대비)
        DB::table('boards')->whereIn('slug', ['free', 'inactive', 'old', 'new'])->delete();

        // 디렉토리 열람 권한 필터 대응 — 이 클래스가 쓰는 슬러그에 guest 읽기 권한 부여
        foreach (['free', 'inactive', 'old', 'new'] as $slug) {
            $this->grantGuestRead($slug);
        }
        PermissionMiddleware::clearGuestRoleCache();
    }

    /**
     * guest 역할에 게시판 읽기 권한을 부여합니다.
     *
     * @param  string  $slug  게시판 슬러그
     */
    private function grantGuestRead(string $slug): void
    {
        $role = Role::where('identifier', 'guest')->first();
        if (! $role) {
            return;
        }

        $perm = Permission::firstOrCreate(
            ['identifier' => "sirsoft-board.{$slug}.posts.read"],
            ['name' => ['ko' => 'read', 'en' => 'read'], 'type' => 'user']
        );
        $role->permissions()->syncWithoutDetaching([$perm->id]);
    }

    /**
     * 전체 게시판 목록 API가 활성화된 게시판만 반환하는지 테스트
     */
    public function test_board_list_returns_only_active_boards(): void
    {
        // Given: 활성화/비활성화 게시판 생성
        Board::factory()->create([
            'is_active' => true,
            'name' => ['ko' => '자유게시판', 'en' => 'Free Board'],
            'slug' => 'free',
        ]);

        Board::factory()->create([
            'is_active' => false,
            'name' => ['ko' => '비활성 게시판', 'en' => 'Inactive Board'],
            'slug' => 'inactive',
        ]);

        // When: API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards');

        // Then: 활성화된 게시판만 반환
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'name', 'slug', 'description', 'posts_count'],
                ],
            ])
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment([
                'slug' => 'free',
            ])
            ->assertJsonMissing([
                'slug' => 'inactive',
            ]);
    }

    /**
     * 전체 게시판 목록 API가 경량 필드만 반환하는지 테스트
     */
    public function test_board_list_returns_lightweight_fields_only(): void
    {
        // Given: 게시판 생성
        Board::factory()->create([
            'is_active' => true,
            'name' => ['ko' => '자유게시판', 'en' => 'Free Board'],
            'slug' => 'free',
            'description' => ['ko' => '설명', 'en' => 'Description'],
            'type' => 'basic',
            'use_comment' => true,
        ]);

        // When: API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards');

        // Then: id, name, slug, description, posts_count만 반환
        $response->assertStatus(200);

        $data = $response->json('data.0');

        // 필수 필드 확인
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('slug', $data);
        $this->assertArrayHasKey('description', $data);
        $this->assertArrayHasKey('posts_count', $data);

        // 불필요한 필드는 포함되지 않아야 함
        $this->assertArrayNotHasKey('type', $data);
        $this->assertArrayNotHasKey('is_active', $data);
        $this->assertArrayNotHasKey('use_comment', $data);
        $this->assertArrayNotHasKey('permissions', $data);
        $this->assertArrayNotHasKey('categories', $data);
    }

    /**
     * 전체 게시판 목록 API가 최신순으로 정렬되는지 테스트
     */
    public function test_board_list_returns_boards_in_newest_order(): void
    {
        // Given: 게시판을 시간 순서대로 생성
        Board::factory()->create([
            'is_active' => true,
            'name' => ['ko' => '오래된 게시판', 'en' => 'Old Board'],
            'slug' => 'old',
            'created_at' => now()->subDays(10),
        ]);

        Board::factory()->create([
            'is_active' => true,
            'name' => ['ko' => '새 게시판', 'en' => 'New Board'],
            'slug' => 'new',
            'created_at' => now(),
        ]);

        // When: API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards');

        // Then: 최신순으로 정렬 (created_at DESC)
        $response->assertStatus(200);

        $data = $response->json('data');

        $this->assertEquals('new', $data[0]['slug']);
        $this->assertEquals('old', $data[1]['slug']);
    }

    /**
     * 전체 게시판 목록 API가 다국어를 올바르게 처리하는지 테스트
     */
    public function test_board_list_returns_localized_content(): void
    {
        // Given: 다국어 게시판 생성
        Board::factory()->create([
            'is_active' => true,
            'name' => ['ko' => '자유게시판', 'en' => 'Free Board'],
            'slug' => 'free',
            'description' => ['ko' => '자유롭게 글을 작성하세요', 'en' => 'Post freely'],
        ]);

        // When: 한국어로 API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards', [
            'Accept-Language' => 'ko',
        ]);

        // Then: 한국어 내용 반환
        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => '자유게시판',
                'description' => '자유롭게 글을 작성하세요',
            ]);

        // When: 영어로 API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards', [
            'Accept-Language' => 'en',
        ]);

        // Then: 영어 내용 반환
        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'Free Board',
                'description' => 'Post freely',
            ]);
    }

    /**
     * 전체 게시판 목록 API가 posts_count를 반환하는지 테스트
     */
    public function test_board_list_returns_posts_count(): void
    {
        // Given: 게시판 생성
        $board = Board::factory()->create([
            'is_active' => true,
            'name' => ['ko' => '자유게시판', 'en' => 'Free Board'],
            'slug' => 'free',
        ]);

        // When: API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards');

        // Then: posts_count 필드 포함 (0 이상)
        $response->assertStatus(200);

        $data = $response->json('data.0');

        $this->assertArrayHasKey('posts_count', $data);
        $this->assertIsInt($data['posts_count']);
        $this->assertGreaterThanOrEqual(0, $data['posts_count']);
    }

    /**
     * 게시판이 없을 때 빈 배열을 반환하는지 테스트
     */
    public function test_board_list_returns_empty_array_when_no_boards(): void
    {
        // Given: 게시판 없음

        // When: API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards');

        // Then: 빈 배열 반환
        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }
}
