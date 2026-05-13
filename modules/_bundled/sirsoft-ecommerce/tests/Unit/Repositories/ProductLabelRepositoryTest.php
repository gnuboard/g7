<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Repositories;

use Modules\Sirsoft\Ecommerce\Models\ProductLabel;
use Modules\Sirsoft\Ecommerce\Repositories\ProductLabelRepository;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품 라벨 Repository Unit 테스트
 */
class ProductLabelRepositoryTest extends ModuleTestCase
{
    protected ProductLabelRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new ProductLabelRepository(new ProductLabel);
    }

    // ========================================
    // findById() 테스트
    // ========================================

    public function test_find_by_id_returns_label(): void
    {
        // Given: 라벨 존재
        $label = ProductLabel::create([
            'name' => ['ko' => '신상품', 'en' => 'New'],
            'color' => '#FF5733',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // When: findById 호출
        $result = $this->repository->findById($label->id);

        // Then: 라벨 반환
        $this->assertNotNull($result);
        $this->assertEquals($label->id, $result->id);
        $this->assertEquals('신상품', $result->name['ko']);
    }

    public function test_find_by_id_returns_null_for_nonexistent(): void
    {
        // When: 존재하지 않는 ID로 findById 호출
        $result = $this->repository->findById(99999);

        // Then: null 반환
        $this->assertNull($result);
    }

    public function test_find_by_id_includes_assignments_count(): void
    {
        // Given: 라벨 존재
        $label = ProductLabel::create([
            'name' => ['ko' => '신상품', 'en' => 'New'],
            'color' => '#FF5733',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // When: findById 호출
        $result = $this->repository->findById($label->id);

        // Then: assignments_count 포함
        $this->assertArrayHasKey('assignments_count', $result->getAttributes());
    }

    // ========================================
    // getAll() 테스트
    // ========================================

    public function test_get_all_returns_collection(): void
    {
        // Given: 라벨 여러 개 생성
        ProductLabel::create([
            'name' => ['ko' => '신상품', 'en' => 'New'],
            'color' => '#FF5733',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        ProductLabel::create([
            'name' => ['ko' => '베스트', 'en' => 'Best'],
            'color' => '#33FF57',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        // When: getAll 호출
        $result = $this->repository->getAll();

        // Then: 컬렉션 반환
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $result);
        $this->assertCount(2, $result);
    }

    public function test_get_all_filters_by_is_active(): void
    {
        // Given: 활성/비활성 라벨 생성
        ProductLabel::create([
            'name' => ['ko' => '활성라벨', 'en' => 'Active'],
            'color' => '#FF5733',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        ProductLabel::create([
            'name' => ['ko' => '비활성라벨', 'en' => 'Inactive'],
            'color' => '#33FF57',
            'is_active' => false,
            'sort_order' => 2,
        ]);

        // When: 활성 필터 적용
        $result = $this->repository->getAll(['is_active' => true]);

        // Then: 활성 라벨만 반환
        $this->assertCount(1, $result);
        $this->assertTrue($result->first()->is_active);
    }

    public function test_get_all_searches_by_name(): void
    {
        // Given: 라벨 생성
        ProductLabel::create([
            'name' => ['ko' => '신상품', 'en' => 'New Product'],
            'color' => '#FF5733',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        ProductLabel::create([
            'name' => ['ko' => '베스트셀러', 'en' => 'Best Seller'],
            'color' => '#33FF57',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        // When: 검색어 적용
        $result = $this->repository->getAll(['search' => '신상품']);

        // Then: 검색된 라벨만 반환
        $this->assertCount(1, $result);
        $this->assertEquals('신상품', $result->first()->name['ko']);
    }

    public function test_get_all_sorts_by_name_asc(): void
    {
        // Given: 라벨 생성
        ProductLabel::create([
            'name' => ['ko' => '베스트', 'en' => 'Best'],
            'color' => '#FF5733',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        ProductLabel::create([
            'name' => ['ko' => '가성비', 'en' => 'Value'],
            'color' => '#33FF57',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        // When: 이름 오름차순 정렬
        $result = $this->repository->getAll(['sort' => 'name_asc']);

        // Then: 정렬된 결과 반환 (한국어 기준)
        $this->assertEquals('가성비', $result->first()->name['ko']);
    }

    public function test_get_all_sorts_by_name_desc(): void
    {
        // Given: 라벨 생성
        ProductLabel::create([
            'name' => ['ko' => '가성비', 'en' => 'Value'],
            'color' => '#FF5733',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        ProductLabel::create([
            'name' => ['ko' => '베스트', 'en' => 'Best'],
            'color' => '#33FF57',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        // When: 이름 내림차순 정렬
        $result = $this->repository->getAll(['sort' => 'name_desc']);

        // Then: 정렬된 결과 반환
        $this->assertEquals('베스트', $result->first()->name['ko']);
    }

    public function test_get_all_sorts_by_created_desc(): void
    {
        // Given: 라벨 생성 (시간 간격 필요)
        $label1 = ProductLabel::create([
            'name' => ['ko' => '첫번째', 'en' => 'First'],
            'color' => '#FF5733',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $label2 = ProductLabel::create([
            'name' => ['ko' => '두번째', 'en' => 'Second'],
            'color' => '#33FF57',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        // When: 생성일 내림차순 정렬
        $result = $this->repository->getAll(['sort' => 'created_desc']);

        // Then: 최신순 정렬 (두번째가 먼저)
        $this->assertEquals($label2->id, $result->first()->id);
    }

    public function test_get_all_default_sort_by_sort_order(): void
    {
        // Given: 라벨 생성 (sort_order 역순으로 생성)
        ProductLabel::create([
            'name' => ['ko' => '세번째', 'en' => 'Third'],
            'color' => '#FF5733',
            'is_active' => true,
            'sort_order' => 3,
        ]);

        ProductLabel::create([
            'name' => ['ko' => '첫번째', 'en' => 'First'],
            'color' => '#33FF57',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        ProductLabel::create([
            'name' => ['ko' => '두번째', 'en' => 'Second'],
            'color' => '#5733FF',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        // When: 정렬 옵션 없이 호출
        $result = $this->repository->getAll();

        // Then: sort_order 기준 정렬
        $this->assertEquals(1, $result->first()->sort_order);
        $this->assertEquals(2, $result->get(1)->sort_order);
        $this->assertEquals(3, $result->last()->sort_order);
    }

    // ========================================
    // create() 테스트
    // ========================================

    public function test_create_creates_label(): void
    {
        // Given: 라벨 데이터
        $data = [
            'name' => ['ko' => '신상품', 'en' => 'New'],
            'color' => '#FF5733',
            'is_active' => true,
            'sort_order' => 1,
        ];

        // When: create 호출
        $result = $this->repository->create($data);

        // Then: 라벨 생성됨
        $this->assertNotNull($result->id);
        $this->assertEquals('신상품', $result->name['ko']);
        $this->assertDatabaseHas('ecommerce_product_labels', [
            'id' => $result->id,
            'color' => '#FF5733',
        ]);
    }

    // ========================================
    // update() 테스트
    // ========================================

    public function test_update_updates_label(): void
    {
        // Given: 라벨 존재
        $label = ProductLabel::create([
            'name' => ['ko' => '신상품', 'en' => 'New'],
            'color' => '#FF5733',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // When: update 호출
        $result = $this->repository->update($label->id, ['color' => '#00FF00']);

        // Then: 라벨 수정됨
        $this->assertEquals('#00FF00', $result->color);
        $this->assertDatabaseHas('ecommerce_product_labels', [
            'id' => $label->id,
            'color' => '#00FF00',
        ]);
    }

    // ========================================
    // delete() 테스트
    // ========================================

    public function test_delete_removes_label(): void
    {
        // Given: 라벨 존재
        $label = ProductLabel::create([
            'name' => ['ko' => '삭제대상', 'en' => 'To Delete'],
            'color' => '#FF5733',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $id = $label->id;

        // When: delete 호출
        $result = $this->repository->delete($id);

        // Then: 라벨 삭제됨
        $this->assertTrue($result);
        $this->assertDatabaseMissing('ecommerce_product_labels', [
            'id' => $id,
        ]);
    }

    // ========================================
    // getProductCount() 테스트
    // ========================================

    public function test_get_product_count_returns_zero_for_no_assignments(): void
    {
        // Given: 라벨 존재 (할당 없음)
        $label = ProductLabel::create([
            'name' => ['ko' => '신상품', 'en' => 'New'],
            'color' => '#FF5733',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // When: getProductCount 호출
        $result = $this->repository->getProductCount($label->id);

        // Then: 0 반환
        $this->assertEquals(0, $result);
    }

    // ========================================
    // getActiveLabels() 테스트
    // ========================================

    public function test_get_active_labels_returns_only_active(): void
    {
        // Given: 활성/비활성 라벨 생성
        ProductLabel::create([
            'name' => ['ko' => '활성라벨1', 'en' => 'Active 1'],
            'color' => '#FF5733',
            'is_active' => true,
            'sort_order' => 2,
        ]);

        ProductLabel::create([
            'name' => ['ko' => '비활성라벨', 'en' => 'Inactive'],
            'color' => '#33FF57',
            'is_active' => false,
            'sort_order' => 1,
        ]);

        ProductLabel::create([
            'name' => ['ko' => '활성라벨2', 'en' => 'Active 2'],
            'color' => '#5733FF',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        // When: getActiveLabels 호출
        $result = $this->repository->getActiveLabels();

        // Then: 활성 라벨만 sort_order 순으로 반환
        $this->assertCount(2, $result);
        $this->assertTrue($result->first()->is_active);
        $this->assertEquals(0, $result->first()->sort_order);
        $this->assertEquals(2, $result->last()->sort_order);
    }
}
