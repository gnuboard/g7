<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Services\BoardService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 네비게이션 메뉴 API 테스트
 *
 * 네비게이션 메뉴용 경량 API의 동작을 검증합니다.
 */
class BoardMenuApiTest extends ModuleTestCase
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
        // board-menu 캐시 플러시 (이전 테스트 캐시가 남아있으면 데이터 격리 실패)
        app(BoardService::class)->clearAllBoardCaches();
    }

    /**
     * 네비게이션 메뉴 API가 활성화된 게시판만 반환하는지 테스트
     */
    public function test_board_menu_returns_only_active_boards(): void
    {
        // Given: 활성화/비활성화 게시판 생성
        $activeBoard = Board::factory()->create([
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
        $response = $this->getJson('/api/modules/sirsoft-board/boards/board-menu');

        // Then: 활성화된 게시판만 반환
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => ['id', 'name', 'slug'],
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
     * 네비게이션 메뉴 API가 최소 필드만 반환하는지 테스트
     */
    public function test_board_menu_returns_minimal_fields_only(): void
    {
        // Given: 게시판 생성
        Board::factory()->create([
            'is_active' => true,
            'name' => ['ko' => '자유게시판', 'en' => 'Free Board'],
            'slug' => 'free',
            'description' => ['ko' => '설명', 'en' => 'Description'],
            'type' => 'basic',
        ]);

        // When: API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/board-menu');

        // Then: id, name, slug만 반환
        $response->assertStatus(200);

        $data = $response->json('data.0');

        $this->assertArrayHasKey('id', $data);
        $this->assertArrayHasKey('name', $data);
        $this->assertArrayHasKey('slug', $data);

        // 다른 필드는 포함되지 않아야 함
        $this->assertArrayNotHasKey('description', $data);
        $this->assertArrayNotHasKey('type', $data);
        $this->assertArrayNotHasKey('is_active', $data);
        $this->assertArrayNotHasKey('permissions', $data);
    }

    /**
     * 네비게이션 메뉴 API가 오래된 순으로 정렬되는지 테스트
     */
    public function test_board_menu_returns_boards_in_created_order(): void
    {
        // Given: 게시판을 시간 순서대로 생성
        $oldBoard = Board::factory()->create([
            'is_active' => true,
            'name' => ['ko' => '오래된 게시판', 'en' => 'Old Board'],
            'slug' => 'old',
            'created_at' => now()->subDays(10),
        ]);

        $newBoard = Board::factory()->create([
            'is_active' => true,
            'name' => ['ko' => '새 게시판', 'en' => 'New Board'],
            'slug' => 'new',
            'created_at' => now(),
        ]);

        // When: API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/board-menu');

        // Then: 오래된 순으로 정렬
        $response->assertStatus(200);

        $data = $response->json('data');

        $this->assertEquals('old', $data[0]['slug']);
        $this->assertEquals('new', $data[1]['slug']);
    }

    /**
     * 네비게이션 메뉴 API가 다국어를 올바르게 처리하는지 테스트
     */
    public function test_board_menu_returns_localized_names(): void
    {
        // Given: 다국어 게시판 생성
        Board::factory()->create([
            'is_active' => true,
            'name' => ['ko' => '자유게시판', 'en' => 'Free Board'],
            'slug' => 'free',
        ]);

        // When: 한국어로 API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/board-menu', [
            'Accept-Language' => 'ko',
        ]);

        // Then: 한국어 이름 반환
        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => '자유게시판',
            ]);

        // When: 영어로 API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/board-menu', [
            'Accept-Language' => 'en',
        ]);

        // Then: 영어 이름 반환
        $response->assertStatus(200)
            ->assertJsonFragment([
                'name' => 'Free Board',
            ]);
    }

    /**
     * 게시판이 없을 때 빈 배열을 반환하는지 테스트
     */
    public function test_board_menu_returns_empty_array_when_no_boards(): void
    {
        // Given: 게시판 없음

        // When: API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/board-menu');

        // Then: 빈 배열 반환
        $response->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }
}
