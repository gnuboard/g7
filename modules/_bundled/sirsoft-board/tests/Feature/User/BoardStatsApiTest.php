<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Support\Facades\Cache;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 게시판 통계 API 테스트
 *
 * 홈 페이지에서 사용할 게시판 관련 통계 API의 동작을 검증합니다.
 */
class BoardStatsApiTest extends ModuleTestCase
{

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 이전 테스트에서 잔류한 게시판 비활성화 (정확한 카운트 테스트를 위해)
        Board::where('is_active', true)->update(['is_active' => false]);

        // 캐시 클리어
        Cache::flush();
    }

    /**
     * 통계 API가 올바른 구조로 응답하는지 테스트
     */
    public function test_stats_returns_correct_structure(): void
    {
        // Given: 활성화된 게시판 생성
        Board::factory()->create([
            'is_active' => true,
            'slug' => 'test-board',
        ]);

        // When: 통계 API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/stats');

        // Then: 올바른 구조 반환
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'users',
                    'boards',
                    'posts',
                    'comments',
                ],
            ]);
    }

    /**
     * 활성화된 게시판 수만 카운트하는지 테스트
     */
    public function test_stats_counts_only_active_boards(): void
    {
        // Given: 활성화/비활성화 게시판 생성
        Board::factory()->count(3)->create(['is_active' => true]);
        Board::factory()->count(2)->create(['is_active' => false]);

        // When: 통계 API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/stats');

        // Then: 활성화된 게시판만 카운트
        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertEquals(3, $data['boards']);
    }

    /**
     * 게시판이 없을 때 0을 반환하는지 테스트
     */
    public function test_stats_returns_zero_when_no_boards(): void
    {
        // Given: 게시판 없음

        // When: 통계 API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/stats');

        // Then: 모든 카운트가 0
        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertEquals(0, $data['boards']);
        $this->assertEquals(0, $data['posts']);
        $this->assertEquals(0, $data['comments']);
    }

    /**
     * 통계가 캐시되는지 테스트
     */
    public function test_stats_are_cached(): void
    {
        // Given: 활성화된 게시판 생성
        Board::factory()->create([
            'is_active' => true,
            'slug' => 'test-cache',
        ]);

        // When: 첫 번째 API 호출
        $response1 = $this->getJson('/api/modules/sirsoft-board/boards/stats');
        $response1->assertStatus(200);

        // 캐시 키 확인 (CacheService PREFIX 'g7' + group 'sirsoft-board' + key 'stats')
        $this->assertTrue(Cache::has('g7:sirsoft-board:stats'));

        // When: 두 번째 API 호출
        $response2 = $this->getJson('/api/modules/sirsoft-board/boards/stats');
        $response2->assertStatus(200);

        // Then: 같은 결과 반환
        $this->assertEquals($response1->json('data'), $response2->json('data'));
    }
}