<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Modules\Sirsoft\Ecommerce\Models\ProductLabel;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ProductLabelRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\ProductLabelService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 상품 라벨 서비스 Unit 테스트
 */
class ProductLabelServiceTest extends ModuleTestCase
{
    protected ProductLabelService $service;

    protected $mockRepository;

    /**
     * 큐를 가짜로 바꾸고 모의 저장소로 ProductLabelService 를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Hook listener job 차단 (mock 모델 deserialize 시 null TypeError 방지)
        Queue::fake();

        $this->mockRepository = Mockery::mock(ProductLabelRepositoryInterface::class);
        $this->service = new ProductLabelService($this->mockRepository);
    }

    /**
     * Mockery 기대를 검증하고 닫은 뒤 부모 정리를 실행합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        try {
            Mockery::close();
        } finally {
            parent::tearDown();
        }
    }

    // ========================================
    // getAllLabels() 테스트
    // ========================================

    /**
     * 필터를 주면 저장소에 그대로 전달하고 라벨 컬렉션을 돌려주는지 확인
     *
     * @scenario case=list_with_filters
     *
     * @effects product_label_list_passes_filters_to_repository
     *
     * @return void 반환값 없음
     */
    public function test_get_all_labels_returns_collection(): void
    {
        // Given: Repository가 컬렉션 반환
        $filters = ['is_active' => true];
        $mockCollection = new Collection([
            new ProductLabel(['id' => 1, 'name' => ['ko' => '신상품']]),
            new ProductLabel(['id' => 2, 'name' => ['ko' => '베스트']]),
        ]);

        $this->mockRepository
            ->shouldReceive('getAll')
            ->with($filters)
            ->once()
            ->andReturn($mockCollection);

        // When: getAllLabels 호출
        $result = $this->service->getAllLabels($filters);

        // Then: 컬렉션 반환
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(2, $result);
    }

    /**
     * 필터 없이 호출하면 빈 필터로 저장소를 조회하는지 확인
     *
     * @scenario case=list_without_filters
     *
     * @effects product_label_list_defaults_to_empty_filters
     *
     * @return void 반환값 없음
     */
    public function test_get_all_labels_without_filters(): void
    {
        // Given: 필터 없이 호출
        $mockCollection = new Collection([
            new ProductLabel(['id' => 1]),
        ]);

        $this->mockRepository
            ->shouldReceive('getAll')
            ->with([])
            ->once()
            ->andReturn($mockCollection);

        // When: getAllLabels 호출
        $result = $this->service->getAllLabels();

        // Then: 결과 반환
        $this->assertInstanceOf(Collection::class, $result);
    }

    // ========================================
    // getLabel() 테스트
    // ========================================

    /**
     * 단건 조회가 assignments 관계를 함께 읽어 라벨을 돌려주는지 확인
     *
     * @scenario case=get_existing
     *
     * @effects product_label_get_loads_assignments_relation
     *
     * @return void 반환값 없음
     */
    public function test_get_label_returns_label(): void
    {
        // Given: Repository가 라벨 반환
        $label = new ProductLabel([
            'id' => 1,
            'name' => ['ko' => '신상품', 'en' => 'New'],
            'color' => '#FF5733',
        ]);

        $this->mockRepository
            ->shouldReceive('findById')
            ->with(1, ['assignments'])
            ->once()
            ->andReturn($label);

        // When: getLabel 호출
        $result = $this->service->getLabel(1);

        // Then: 라벨 반환
        $this->assertEquals($label, $result);
    }

    /**
     * 없는 라벨을 조회하면 null 을 돌려주는지 확인
     *
     * @scenario case=get_missing
     *
     * @effects product_label_get_returns_null_when_missing
     *
     * @return void 반환값 없음
     */
    public function test_get_label_returns_null_for_nonexistent(): void
    {
        // Given: Repository가 null 반환
        $this->mockRepository
            ->shouldReceive('findById')
            ->with(99999, ['assignments'])
            ->once()
            ->andReturn(null);

        // When: getLabel 호출
        $result = $this->service->getLabel(99999);

        // Then: null 반환
        $this->assertNull($result);
    }

    // ========================================
    // getActiveLabels() 테스트
    // ========================================

    /**
     * 활성 라벨 조회가 저장소의 활성 라벨 컬렉션을 돌려주는지 확인
     *
     * @scenario case=list_active
     *
     * @effects product_label_active_list_returned_from_repository
     *
     * @return void 반환값 없음
     */
    public function test_get_active_labels_returns_collection(): void
    {
        // Given: Repository가 활성 라벨 컬렉션 반환
        $collection = new Collection([
            new ProductLabel(['id' => 1, 'is_active' => true]),
            new ProductLabel(['id' => 2, 'is_active' => true]),
        ]);

        $this->mockRepository
            ->shouldReceive('getActiveLabels')
            ->once()
            ->andReturn($collection);

        // When: getActiveLabels 호출
        $result = $this->service->getActiveLabels();

        // Then: 컬렉션 반환
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(2, $result);
    }

    // ========================================
    // createLabel() 테스트
    // ========================================

    /**
     * 라벨 생성이 입력 데이터를 저장소에 전달하고 생성된 라벨을 돌려주는지 확인
     *
     * @scenario case=create
     *
     * @effects product_label_create_returns_created_model
     *
     * @return void 반환값 없음
     */
    public function test_create_label_creates_and_returns_label(): void
    {
        // Given: 사용자 인증 상태
        $user = $this->createAdminUser();
        $this->actingAs($user);

        $data = [
            'name' => ['ko' => '신상품', 'en' => 'New'],
            'color' => '#FF5733',
            'is_active' => true,
            'sort_order' => 1,
        ];

        // makePartial 로 만들어 getKey 등 Eloquent 메서드는 실제 동작 (HookArgumentSerializer
        // 가 hook 발행 시 model->getKey() 호출하므로 stub 만으로는 부족)
        $createdLabel = Mockery::mock(ProductLabel::class)->makePartial();
        $createdLabel->shouldReceive('fresh')->andReturnSelf();
        $createdLabel->id = 1;

        $this->mockRepository
            ->shouldReceive('create')
            ->with($data)
            ->once()
            ->andReturn($createdLabel);

        // When: createLabel 호출
        $result = $this->service->createLabel($data);

        // Then: 라벨 생성됨
        $this->assertSame($createdLabel, $result);
    }

    // ========================================
    // updateLabel() 테스트
    // ========================================

    /**
     * 라벨 수정이 기존 라벨을 찾은 뒤 저장소 update 결과를 돌려주는지 확인
     *
     * @scenario case=update
     *
     * @effects product_label_update_returns_updated_model
     *
     * @return void 반환값 없음
     */
    public function test_update_label_updates_and_returns_label(): void
    {
        // Given: 사용자 인증 및 기존 라벨
        $user = $this->createAdminUser();
        $this->actingAs($user);

        // id 는 $guarded 이므로 mass assignment 불가 → forceFill 로 주입
        $existingLabel = (new ProductLabel)->forceFill([
            'id' => 1,
            'name' => ['ko' => '신상품'],
            'color' => '#FF5733',
        ]);

        $data = ['color' => '#00FF00'];

        // makePartial: getKey 등 Eloquent 메서드 실제 동작 (HookArgumentSerializer 호환)
        $updatedLabel = Mockery::mock(ProductLabel::class)->makePartial();
        $updatedLabel->shouldReceive('fresh')->andReturnSelf();
        $updatedLabel->id = 1;

        $this->mockRepository
            ->shouldReceive('findById')
            ->with(1)
            ->once()
            ->andReturn($existingLabel);

        $this->mockRepository
            ->shouldReceive('update')
            ->with(1, $data)
            ->once()
            ->andReturn($updatedLabel);

        // When: updateLabel 호출
        $result = $this->service->updateLabel(1, $data);

        // Then: 라벨 수정됨
        $this->assertSame($updatedLabel, $result);
    }

    /**
     * 없는 라벨을 수정하면 예외가 발생하는지 확인
     *
     * @scenario case=update_missing
     *
     * @effects product_label_update_throws_when_missing
     *
     * @return void 반환값 없음
     */
    public function test_update_label_throws_exception_for_nonexistent(): void
    {
        // Given: Repository가 null 반환
        $this->mockRepository
            ->shouldReceive('findById')
            ->with(99999)
            ->once()
            ->andReturn(null);

        // Then: 예외 발생 예상
        $this->expectException(\Exception::class);

        // When: updateLabel 호출
        $this->service->updateLabel(99999, ['color' => '#00FF00']);
    }

    // ========================================
    // toggleStatus() 테스트
    // ========================================

    /**
     * 상태 전환이 활성 라벨의 is_active 를 false 로 뒤집어 저장하는지 확인
     *
     * @scenario case=toggle_status
     *
     * @effects product_label_toggle_inverts_is_active
     *
     * @return void 반환값 없음
     */
    public function test_toggle_status_changes_label_status(): void
    {
        // Given: 활성 라벨 존재 (id 는 $guarded → forceFill)
        $existingLabel = (new ProductLabel)->forceFill([
            'id' => 1,
            'is_active' => true,
        ]);

        // 반환용 라벨은 real model partial mock 으로 — fresh() 만 mock 하고 나머지 속성은
        // 실제 모델로 동작 (setAttribute 가 Mockery 를 우회하도록)
        $toggledLabel = Mockery::mock(ProductLabel::class)->makePartial();
        $toggledLabel->shouldReceive('fresh')->andReturnSelf();
        $toggledLabel->is_active = false;

        $this->mockRepository
            ->shouldReceive('findById')
            ->with(1)
            ->once()
            ->andReturn($existingLabel);

        $this->mockRepository
            ->shouldReceive('update')
            ->with(1, ['is_active' => false])
            ->once()
            ->andReturn($toggledLabel);

        // When: toggleStatus 호출
        $result = $this->service->toggleStatus(1);

        // Then: 상태 변경됨
        $this->assertFalse($result->is_active);
    }

    /**
     * 없는 라벨의 상태를 전환하면 예외가 발생하는지 확인
     *
     * @scenario case=toggle_missing
     *
     * @effects product_label_toggle_throws_when_missing
     *
     * @return void 반환값 없음
     */
    public function test_toggle_status_throws_exception_for_nonexistent(): void
    {
        // Given: Repository가 null 반환
        $this->mockRepository
            ->shouldReceive('findById')
            ->with(99999)
            ->once()
            ->andReturn(null);

        // Then: 예외 발생 예상
        $this->expectException(\Exception::class);

        // When: toggleStatus 호출
        $this->service->toggleStatus(99999);
    }

    // ========================================
    // deleteLabel() 테스트
    // ========================================

    /**
     * 연결된 상품이 없는 라벨을 삭제하면 label_id 를 담은 결과를 돌려주는지 확인
     *
     * @scenario case=delete
     *
     * @effects product_label_delete_returns_label_id
     *
     * @return void 반환값 없음
     */
    public function test_delete_label_deletes_and_returns_result(): void
    {
        // Given: 라벨 존재 (연결된 상품 없음) — id 는 $guarded → forceFill
        $existingLabel = (new ProductLabel)->forceFill([
            'id' => 1,
            'name' => ['ko' => '삭제할 라벨'],
        ]);

        $this->mockRepository
            ->shouldReceive('findById')
            ->with(1)
            ->once()
            ->andReturn($existingLabel);

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

        // When: deleteLabel 호출
        $result = $this->service->deleteLabel(1);

        // Then: 삭제 성공
        $this->assertArrayHasKey('label_id', $result);
        $this->assertEquals(1, $result['label_id']);
    }

    /**
     * 연결된 상품이 있는 라벨을 삭제하면 예외가 발생하는지 확인
     *
     * @scenario case=delete_blocked_by_products
     *
     * @effects product_label_delete_blocked_when_products_assigned
     *
     * @return void 반환값 없음
     */
    public function test_delete_label_throws_exception_when_has_products(): void
    {
        // Given: 연결된 상품이 있는 라벨 — id 는 $guarded → forceFill
        $existingLabel = (new ProductLabel)->forceFill([
            'id' => 1,
            'name' => ['ko' => '삭제할 라벨'],
        ]);

        $this->mockRepository
            ->shouldReceive('findById')
            ->with(1)
            ->once()
            ->andReturn($existingLabel);

        $this->mockRepository
            ->shouldReceive('getProductCount')
            ->with(1)
            ->once()
            ->andReturn(5);

        // Then: 예외 발생 예상
        $this->expectException(\Exception::class);

        // When: deleteLabel 호출
        $this->service->deleteLabel(1);
    }

    /**
     * 없는 라벨을 삭제하면 예외가 발생하는지 확인
     *
     * @scenario case=delete_missing
     *
     * @effects product_label_delete_throws_when_missing
     *
     * @return void 반환값 없음
     */
    public function test_delete_label_throws_exception_for_nonexistent(): void
    {
        // Given: Repository가 null 반환
        $this->mockRepository
            ->shouldReceive('findById')
            ->with(99999)
            ->once()
            ->andReturn(null);

        // Then: 예외 발생 예상
        $this->expectException(\Exception::class);

        // When: deleteLabel 호출
        $this->service->deleteLabel(99999);
    }
}
