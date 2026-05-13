<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__ . '/../../ModuleTestCase.php';

use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 사용자 게시판 접근 테스트
 *
 * 사용자(비로그인 포함) 페이지에서 게시판 목록 및 상세 조회 테스트
 */
class BoardAccessTest extends ModuleTestCase
{

    protected function setUp(): void
    {
        parent::setUp();
        // DDL implicit commit으로 커밋된 게시판 비활성화 (데이터 격리 보장)
        DB::table('boards')->update(['is_active' => false]);
        // 이 테스트 클래스에서 사용하는 슬러그 정리 (커밋된 경우 대비)
        DB::table('boards')->whereIn('slug', ['active-1', 'active-2', 'inactive', 'test-board', 'inactive-board', 'non-existent'])->delete();
    }

    /**
     * 비로그인 사용자도 활성화된 게시판 목록 조회 가능
     */
    public function test_guest_can_access_active_boards_list(): void
    {
        // Given: 활성화된 게시판 2개, 비활성화된 게시판 1개 생성
        Board::factory()->create([
            'slug' => 'active-1',
            'is_active' => true,
        ]);
        Board::factory()->create([
            'slug' => 'active-2',
            'is_active' => true,
        ]);
        Board::factory()->create([
            'slug' => 'inactive',
            'is_active' => false,
        ]);

        // When: 비로그인 상태에서 게시판 목록 API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards');

        // Then: 응답 성공, 활성화된 게시판만 2개 반환
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                '*' => [
                    'id',
                    'name',
                    'slug',
                ],
            ],
        ]);

        // 데이터가 배열 형태로 반환되는지 확인
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertCount(2, $data);

        // 활성화된 게시판만 반환되는지 확인
        $slugs = collect($data)->pluck('slug')->toArray();
        $this->assertContains('active-1', $slugs);
        $this->assertContains('active-2', $slugs);
        $this->assertNotContains('inactive', $slugs);

        // 비활성화된 게시판은 포함되지 않아야 함 (slug 기반 확인)
        $this->assertCount(2, $slugs, '활성화된 게시판만 반환되어야 합니다');
    }

    /**
     * 비활성화된 게시판은 목록에 포함되지 않음
     */
    public function test_inactive_boards_not_included_in_list(): void
    {
        // Given: 비활성화된 게시판 3개, 활성화된 게시판 1개 생성
        Board::factory()->count(3)->create(['is_active' => false]);
        Board::factory()->create(['is_active' => true]);

        // When: 게시판 목록 조회
        $response = $this->getJson('/api/modules/sirsoft-board/boards');

        // Then: 활성화된 게시판 1개만 반환
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    /**
     * 활성화된 게시판 상세 조회 가능
     */
    public function test_guest_can_access_active_board_detail(): void
    {
        // Given: 활성화된 게시판 생성
        $board = Board::factory()->create([
            'slug' => 'test-board',
            'name' => ['ko' => '테스트 게시판', 'en' => 'Test Board'],
            'is_active' => true,
        ]);

        // When: 게시판 상세 조회
        $response = $this->getJson("/api/modules/sirsoft-board/boards/test-board");

        // Then: 성공 응답, 게시판 정보 반환
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'slug' => 'test-board',
                'is_active' => true,
            ],
        ]);
    }

    /**
     * 비활성화된 게시판 상세 조회 불가 (404)
     */
    public function test_guest_cannot_access_inactive_board_detail(): void
    {
        // Given: 비활성화된 게시판 생성
        Board::factory()->create([
            'slug' => 'inactive-board',
            'is_active' => false,
        ]);

        // When: 비활성화된 게시판 상세 조회 시도
        $response = $this->getJson("/api/modules/sirsoft-board/boards/inactive-board");

        // Then: 404 응답
        $response->assertStatus(404);
    }

    /**
     * 존재하지 않는 게시판 조회 시 404
     */
    public function test_accessing_non_existent_board_returns_404(): void
    {
        // When: 존재하지 않는 게시판 조회
        $response = $this->getJson("/api/modules/sirsoft-board/boards/non-existent");

        // Then: 404 응답
        $response->assertStatus(404);
    }

    /**
     * 게시판 목록 응답에 페이지네이션 정보가 포함되지 않음 (단순 배열)
     */
    public function test_board_list_response_does_not_include_pagination(): void
    {
        // Given: 게시판 여러 개 생성
        Board::factory()->count(5)->create(['is_active' => true]);

        // When: 게시판 목록 조회
        $response = $this->getJson('/api/modules/sirsoft-board/boards');

        // Then: 페이지네이션 정보 없이 순수 배열만 반환
        $response->assertStatus(200);
        $this->assertArrayNotHasKey('pagination', $response->json('data'));
        $this->assertIsArray($response->json('data'));
    }
}