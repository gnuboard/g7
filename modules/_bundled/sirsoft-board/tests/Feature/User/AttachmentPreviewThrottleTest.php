<?php

namespace Modules\Sirsoft\Board\Tests\Feature\User;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 첨부파일 이미지 미리보기 Throttle 테스트
 *
 * preview API가 게시판 API(60/분)와 별도 throttle(300/분)을 사용하는지 검증합니다.
 * 갤러리 게시판 등에서 다수의 썸네일을 동시 로드해도 429 오류가 발생하지 않아야 합니다.
 */
class AttachmentPreviewThrottleTest extends ModuleTestCase
{
    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('boards')->update(['is_active' => false]);
        DB::table('boards')->where('slug', 'gallery-test')->delete();
    }

    /**
     * preview 라우트가 boards 그룹과 별도 throttle 버킷을 사용하는지 테스트
     *
     * 게시판 API를 60회 호출해도 preview API가 429를 반환하지 않아야 합니다.
     */
    public function test_preview_route_has_separate_throttle_from_board_routes(): void
    {
        // Given: 게시판 생성
        $board = Board::factory()->create([
            'is_active' => true,
            'slug' => 'gallery-test',
            'name' => ['ko' => '갤러리 테스트', 'en' => 'Gallery Test'],
        ]);

        // When: 게시판 목록 API를 60회 호출하여 throttle 소진
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/modules/sirsoft-board/boards');
        }

        // Then: 게시판 API는 429 반환 (throttle 소진)
        $boardResponse = $this->getJson('/api/modules/sirsoft-board/boards');
        $boardResponse->assertStatus(429);

        // But: preview API는 별도 throttle이므로 429가 아닌 다른 상태 반환
        // (게시판 미존재 해시이므로 404 반환 예상)
        $previewResponse = $this->getJson('/api/modules/sirsoft-board/boards/gallery-test/attachment/abcdef123456/preview');
        $this->assertNotEquals(429, $previewResponse->getStatusCode());
    }

    /**
     * preview API가 존재하지 않는 첨부파일에 대해 404를 반환하는지 테스트
     */
    public function test_preview_returns_404_for_nonexistent_attachment(): void
    {
        // Given: 게시판 생성
        Board::factory()->create([
            'is_active' => true,
            'slug' => 'gallery-test',
            'name' => ['ko' => '갤러리 테스트', 'en' => 'Gallery Test'],
        ]);

        // When: 존재하지 않는 해시로 preview API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/gallery-test/attachment/abcdef123456/preview');

        // Then: 404 반환
        $response->assertStatus(404);
    }

    /**
     * preview API가 존재하지 않는 게시판에 대해 404를 반환하는지 테스트
     */
    public function test_preview_returns_404_for_nonexistent_board(): void
    {
        // When: 존재하지 않는 게시판 슬러그로 preview API 호출
        $response = $this->getJson('/api/modules/sirsoft-board/boards/nonexistent-board/attachment/abcdef123456/preview');

        // Then: 404 반환
        $response->assertStatus(404);
    }
}
