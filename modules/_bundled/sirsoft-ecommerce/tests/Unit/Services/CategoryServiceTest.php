<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Mockery;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CategoryImageRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\CategoryRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\CategoryService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * CategoryService 삭제 테스트
 *
 * 카테고리 삭제 시 관계 레코드 명시적 삭제, 예외 처리, 훅 실행을 검증합니다.
 */
class CategoryServiceTest extends ModuleTestCase
{
    protected CategoryService $service;

    protected $mockRepository;

    protected $mockImageRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockRepository = Mockery::mock(CategoryRepositoryInterface::class);
        $this->mockImageRepository = Mockery::mock(CategoryImageRepositoryInterface::class);
        $this->service = new CategoryService($this->mockRepository, $this->mockImageRepository);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ========================================
    // deleteCategory() - 관계 삭제 검증
    // ========================================

    /**
     * 카테고리 삭제 시 이미지가 명시적으로 삭제되는지 확인
     */
    public function test_delete_category_deletes_images(): void
    {
        // Given: 카테고리 존재 (하위 카테고리/상품 없음)
        $category = Mockery::mock(Category::class)->makePartial();
        $category->shouldReceive('getAttribute')->with('id')->andReturn(1);

        $mockImages = Mockery::mock(\Illuminate\Database\Eloquent\Relations\HasMany::class);
        $mockImages->shouldReceive('delete')->once();
        $category->shouldReceive('images')->once()->andReturn($mockImages);

        $this->mockRepository
            ->shouldReceive('findById')
            ->with(1)
            ->once()
            ->andReturn($category);

        $this->mockRepository
            ->shouldReceive('hasChildren')
            ->with(1)
            ->once()
            ->andReturn(false);

        $this->mockRepository
            ->shouldReceive('getProductCount')
            ->with(1)
            ->once()
            ->andReturn(0);

        $this->mockRepository
            ->shouldReceive('delete')
            ->with(1)
            ->once()
            ->andReturn(true);

        // When: deleteCategory 호출
        $result = $this->service->deleteCategory(1);

        // Then: 결과 반환 + 이미지 삭제 호출됨
        $this->assertEquals(['category_id' => 1], $result);
    }

    // ========================================
    // deleteCategory() - 예외 처리 검증
    // ========================================

    /**
     * 하위 카테고리가 있는 경우 삭제 시 예외 발생
     */
    public function test_delete_category_with_children_throws_exception(): void
    {
        $category = Mockery::mock(Category::class)->makePartial();
        $category->shouldReceive('getAttribute')->with('id')->andReturn(1);

        $mockChildren = Mockery::mock(\Illuminate\Database\Eloquent\Relations\HasMany::class);
        $mockChildren->shouldReceive('count')->andReturn(2);
        $category->shouldReceive('children')->andReturn($mockChildren);

        $this->mockRepository
            ->shouldReceive('findById')
            ->with(1)
            ->once()
            ->andReturn($category);

        $this->mockRepository
            ->shouldReceive('hasChildren')
            ->with(1)
            ->once()
            ->andReturn(true);

        $this->expectException(\Exception::class);

        $this->service->deleteCategory(1);
    }

    /**
     * 연결된 상품이 있는 경우 삭제 시 예외 발생
     */
    public function test_delete_category_with_products_throws_exception(): void
    {
        $category = Mockery::mock(Category::class)->makePartial();
        $category->shouldReceive('getAttribute')->with('id')->andReturn(1);

        $this->mockRepository
            ->shouldReceive('findById')
            ->with(1)
            ->once()
            ->andReturn($category);

        $this->mockRepository
            ->shouldReceive('hasChildren')
            ->with(1)
            ->once()
            ->andReturn(false);

        $this->mockRepository
            ->shouldReceive('getProductCount')
            ->with(1)
            ->once()
            ->andReturn(5);

        $this->expectException(\Exception::class);

        $this->service->deleteCategory(1);
    }
}
