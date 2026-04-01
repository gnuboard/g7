<?php

namespace Modules\Sirsoft\Board\Tests\Feature\Admin;

// 테스트 베이스 클래스 수동 require (autoload 전에 로드 필요)
require_once __DIR__.'/../../ModuleTestCase.php';

use App\Models\User;
use Modules\Sirsoft\Board\Tests\BoardTestCase;

/**
 * 관리자 게시글 분류 필터 API 테스트
 *
 * 게시글 목록 API에서 분류 필터(전체/미분류/특정분류)가 올바르게 동작하는지 검증합니다.
 */
class PostCategoryFilterTest extends BoardTestCase
{
    protected User $adminUser;

    protected function getTestBoardSlug(): string
    {
        return 'post-cat-filter';
    }

    protected function getDefaultBoardAttributes(string $slug): array
    {
        return [
            'slug' => $slug,
            'name' => ['ko' => '분류 필터 테스트', 'en' => 'Category Filter Test'],
            'is_active' => true,
            'categories' => ['공지', '질문', '자유'],
            'guest_permissions' => ['posts.list', 'posts.read'],
            'blocked_keywords' => [],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createDefaultRoles();

        $slug = $this->getTestBoardSlug();
        $this->adminUser = $this->createAdminUser([
            'sirsoft-board.boards.read',
            "sirsoft-board.{$slug}.admin.posts.read",
        ]);

        // 테스트 데이터 생성
        $this->createTestPost(['title' => '공지1', 'category' => '공지', 'user_id' => $this->adminUser->id, 'author_name' => $this->adminUser->name]);
        $this->createTestPost(['title' => '공지2', 'category' => '공지', 'user_id' => $this->adminUser->id, 'author_name' => $this->adminUser->name]);
        $this->createTestPost(['title' => '질문1', 'category' => '질문', 'user_id' => $this->adminUser->id, 'author_name' => $this->adminUser->name]);
        $this->createTestPost(['title' => '미분류1', 'category' => null, 'user_id' => $this->adminUser->id, 'author_name' => $this->adminUser->name]);
        $this->createTestPost(['title' => '미분류2', 'category' => null, 'user_id' => $this->adminUser->id, 'author_name' => $this->adminUser->name]);
    }

    /**
     * 분류 필터 없이 전체 게시글 반환
     */
    public function test_no_category_filter_returns_all_posts(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts");

        $response->assertOk();
        $this->assertEquals(5, $response->json('data.pagination.total'));
    }

    /**
     * 빈 문자열 분류 필터는 전체 반환 (프론트엔드에서 'all' → '' 변환)
     */
    public function test_empty_category_filter_returns_all_posts(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts?category=");

        $response->assertOk();
        $this->assertEquals(5, $response->json('data.pagination.total'));
    }

    /**
     * 특정 분류 필터로 해당 분류 게시글만 반환
     */
    public function test_specific_category_filter(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts?category=공지");

        $response->assertOk();
        $this->assertEquals(2, $response->json('data.pagination.total'));
    }

    /**
     * 미분류(unclassified) 필터로 category가 NULL인 게시글만 반환
     */
    public function test_unclassified_filter_returns_null_category_posts(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts?category=unclassified");

        $response->assertOk();
        $this->assertEquals(2, $response->json('data.pagination.total'));
    }

    /**
     * 존재하지 않는 분류로 필터 시 결과 없음
     */
    public function test_nonexistent_category_returns_empty(): void
    {
        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/modules/sirsoft-board/admin/board/{$this->board->slug}/posts?category=없는분류");

        $response->assertOk();
        $this->assertEquals(0, $response->json('data.pagination.total'));
    }
}
