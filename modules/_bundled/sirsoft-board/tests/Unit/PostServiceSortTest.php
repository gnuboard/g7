<?php

namespace Modules\Sirsoft\Board\Tests\Unit;

// ModuleTestCase를 수동으로 require (autoload 전에 로드 필요)
require_once __DIR__.'/../ModuleTestCase.php';

use Modules\Sirsoft\Board\Enums\BoardOrderBy;
use Modules\Sirsoft\Board\Enums\OrderDirection;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Services\PostService;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * PostService 정렬/페이지 설정 테스트
 *
 * 게시판 설정에 따른 정렬 및 페이지당 항목 수 계산 로직을 테스트합니다.
 */
class PostServiceSortTest extends ModuleTestCase
{

    private PostService $postService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->postService = app(PostService::class);
    }

    // =========================================================================
    // extractSortParams 테스트
    // =========================================================================

    /**
     * 쿼리 파라미터 없이 게시판 설정이 적용되는지 테스트
     */
    #[Test]
    public function extract_sort_params_uses_board_settings_as_fallback(): void
    {
        $board = Board::factory()->create([
            'order_by' => BoardOrderBy::ViewCount,
            'order_direction' => OrderDirection::Asc,
        ]);

        $result = $this->postService->extractSortParams([], $board);

        $this->assertEquals('view_count', $result['order_by']);
        $this->assertEquals('ASC', $result['order_direction']);
    }

    /**
     * 쿼리 파라미터가 게시판 설정을 오버라이드하는지 테스트
     */
    #[Test]
    public function extract_sort_params_query_params_override_board_settings(): void
    {
        $board = Board::factory()->create([
            'order_by' => BoardOrderBy::ViewCount,
            'order_direction' => OrderDirection::Asc,
        ]);

        $result = $this->postService->extractSortParams(
            ['sort_by' => 'id', 'sort_order' => 'desc'],
            $board
        );

        $this->assertEquals('id', $result['order_by']);
        $this->assertEquals('desc', $result['order_direction']);
    }

    /**
     * 게시판 없이 기본값이 적용되는지 테스트
     */
    #[Test]
    public function extract_sort_params_returns_defaults_without_board(): void
    {
        $result = $this->postService->extractSortParams([]);

        $this->assertEquals('created_at', $result['order_by']);
        $this->assertEquals('desc', $result['order_direction']);
    }

    /**
     * order_by/order_direction 파라미터도 동작하는지 테스트
     */
    #[Test]
    public function extract_sort_params_supports_order_by_params(): void
    {
        $result = $this->postService->extractSortParams([
            'order_by' => 'view_count',
            'order_direction' => 'asc',
        ]);

        $this->assertEquals('view_count', $result['order_by']);
        $this->assertEquals('asc', $result['order_direction']);
    }

    /**
     * sort_by가 order_by보다 우선순위가 높은지 테스트
     */
    #[Test]
    public function extract_sort_params_sort_by_has_priority_over_order_by(): void
    {
        $result = $this->postService->extractSortParams([
            'sort_by' => 'id',
            'sort_order' => 'desc',
            'order_by' => 'view_count',
            'order_direction' => 'asc',
        ]);

        $this->assertEquals('id', $result['order_by']);
        $this->assertEquals('desc', $result['order_direction']);
    }

    /**
     * 게시판 설정에서 title 정렬이 올바르게 추출되는지 테스트
     */
    #[Test]
    public function extract_sort_params_uses_title_from_board_settings(): void
    {
        $board = new Board();
        $board->order_by = BoardOrderBy::Title;
        $board->order_direction = OrderDirection::Asc;

        $result = $this->postService->extractSortParams([], $board);

        $this->assertEquals('title', $result['order_by']);
        $this->assertEquals('ASC', $result['order_direction']);
    }

    /**
     * 게시판 설정에서 author 정렬이 올바르게 추출되는지 테스트
     */
    #[Test]
    public function extract_sort_params_uses_author_from_board_settings(): void
    {
        $board = new Board();
        $board->order_by = BoardOrderBy::Author;
        $board->order_direction = OrderDirection::Desc;

        $result = $this->postService->extractSortParams([], $board);

        $this->assertEquals('author', $result['order_by']);
        $this->assertEquals('DESC', $result['order_direction']);
    }

    // =========================================================================
    // buildListParams 테스트
    // =========================================================================

    /**
     * 사용자 컨텍스트에서 게시판 설정이 적용되는지 테스트
     */
    #[Test]
    public function build_list_params_applies_board_settings_for_user_context(): void
    {
        $board = Board::factory()->create([
            'order_by' => BoardOrderBy::ViewCount,
            'order_direction' => OrderDirection::Asc,
            'per_page' => 25,
            'per_page_mobile' => 10,
        ]);

        $result = $this->postService->buildListParams([], [
            'context' => 'user',
            'board' => $board,
        ]);

        $this->assertEquals('view_count', $result['filters']['order_by']);
        $this->assertEquals('ASC', $result['filters']['order_direction']);
        $this->assertEquals(25, $result['perPage']); // PC 기본값
    }

    /**
     * 관리자 컨텍스트에서 게시판 설정이 적용되는지 테스트
     */
    #[Test]
    public function build_list_params_applies_board_settings_for_admin_context(): void
    {
        $board = Board::factory()->create([
            'order_by' => BoardOrderBy::ViewCount,
            'order_direction' => OrderDirection::Asc,
            'per_page' => 30,
        ]);

        $result = $this->postService->buildListParams([], [
            'context' => 'admin',
            'board' => $board,
        ]);

        $this->assertEquals('view_count', $result['filters']['order_by']);
        $this->assertEquals('ASC', $result['filters']['order_direction']);
        $this->assertEquals(30, $result['perPage']);
    }

    /**
     * 모바일 User-Agent로 per_page_mobile이 적용되는지 테스트
     */
    #[Test]
    public function build_list_params_applies_mobile_per_page(): void
    {
        $board = Board::factory()->create([
            'per_page' => 25,
            'per_page_mobile' => 10,
        ]);

        $result = $this->postService->buildListParams([], [
            'context' => 'user',
            'board' => $board,
            'userAgent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X)',
        ]);

        $this->assertEquals(10, $result['perPage']);
    }

    /**
     * 쿼리 파라미터 per_page가 게시판 설정을 오버라이드하는지 테스트
     */
    #[Test]
    public function build_list_params_query_per_page_overrides_board_setting(): void
    {
        $board = Board::factory()->create([
            'per_page' => 25,
        ]);

        $result = $this->postService->buildListParams(
            ['per_page' => 50],
            [
                'context' => 'user',
                'board' => $board,
            ]
        );

        $this->assertEquals(50, $result['perPage']);
    }

    /**
     * board 없이 관리자 기본값이 적용되는지 테스트
     */
    #[Test]
    public function build_list_params_uses_defaults_without_board(): void
    {
        $result = $this->postService->buildListParams([], [
            'context' => 'admin',
        ]);

        $this->assertEquals('created_at', $result['filters']['order_by']);
        $this->assertEquals('desc', $result['filters']['order_direction']);
        $this->assertEquals(15, $result['perPage']); // admin 기본값
    }

    // =========================================================================
    // isMobileRequest 테스트
    // =========================================================================

    /**
     * iPhone User-Agent 감지 테스트
     */
    #[Test]
    public function is_mobile_request_detects_iphone(): void
    {
        $result = $this->postService->isMobileRequest(
            'Mozilla/5.0 (iPhone; CPU iPhone OS 14_0 like Mac OS X)'
        );

        $this->assertTrue($result);
    }

    /**
     * Android User-Agent 감지 테스트
     */
    #[Test]
    public function is_mobile_request_detects_android(): void
    {
        $result = $this->postService->isMobileRequest(
            'Mozilla/5.0 (Linux; Android 10; SM-G973F)'
        );

        $this->assertTrue($result);
    }

    /**
     * 데스크톱 User-Agent는 false 반환 테스트
     */
    #[Test]
    public function is_mobile_request_returns_false_for_desktop(): void
    {
        $result = $this->postService->isMobileRequest(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        );

        $this->assertFalse($result);
    }

    /**
     * null User-Agent는 false 반환 테스트
     */
    #[Test]
    public function is_mobile_request_returns_false_for_null(): void
    {
        $result = $this->postService->isMobileRequest(null);

        $this->assertFalse($result);
    }
}
