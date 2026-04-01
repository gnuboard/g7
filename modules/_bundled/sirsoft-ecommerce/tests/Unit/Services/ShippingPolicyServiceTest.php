<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\ShippingMethodEnum;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingPolicyRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\ShippingPolicyService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 배송정책 서비스 Unit 테스트
 */
class ShippingPolicyServiceTest extends ModuleTestCase
{
    protected ShippingPolicyService $service;

    protected $mockRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockRepository = Mockery::mock(ShippingPolicyRepositoryInterface::class);
        $this->service = new ShippingPolicyService($this->mockRepository);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ========================================
    // getList() 테스트
    // ========================================

    public function test_get_list_returns_paginated_shipping_policies(): void
    {
        // Given: Repository가 페이지네이션 결과 반환
        $filters = ['shipping_methods' => ['parcel'], 'per_page' => 10];
        $mockPaginator = Mockery::mock(LengthAwarePaginator::class);

        $this->mockRepository
            ->shouldReceive('getListWithFilters')
            ->with($filters, 10)
            ->once()
            ->andReturn($mockPaginator);

        // When: getList 호출
        $result = $this->service->getList($filters);

        // Then: 결과 반환
        $this->assertSame($mockPaginator, $result);
    }

    public function test_get_list_uses_default_per_page(): void
    {
        // Given: per_page 미지정
        $filters = ['shipping_methods' => ['parcel']];
        $mockPaginator = Mockery::mock(LengthAwarePaginator::class);

        $this->mockRepository
            ->shouldReceive('getListWithFilters')
            ->with($filters, 20) // 기본값 20
            ->once()
            ->andReturn($mockPaginator);

        // When: getList 호출
        $result = $this->service->getList($filters);

        // Then: 기본 per_page 적용
        $this->assertSame($mockPaginator, $result);
    }

    // ========================================
    // getStatistics() 테스트
    // ========================================

    public function test_get_statistics_returns_statistics_array(): void
    {
        // Given: Repository가 통계 반환
        $statistics = [
            'total' => 10,
            'active' => 8,
            'inactive' => 2,
            'shipping_method' => ['parcel' => 5, 'quick' => 3, 'direct' => 2],
            'charge_policy' => ['fixed' => 4, 'conditional_free' => 3, 'free' => 3],
        ];

        $this->mockRepository
            ->shouldReceive('getStatistics')
            ->once()
            ->andReturn($statistics);

        // When: getStatistics 호출
        $result = $this->service->getStatistics();

        // Then: 통계 반환
        $this->assertEquals($statistics, $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('active', $result);
        $this->assertArrayHasKey('inactive', $result);
        $this->assertArrayHasKey('shipping_method', $result);
        $this->assertArrayHasKey('charge_policy', $result);
    }

    // ========================================
    // getDetail() 테스트
    // ========================================

    public function test_get_detail_returns_shipping_policy(): void
    {
        // Given: Repository가 배송정책 반환
        $shippingPolicy = new ShippingPolicy([
            'id' => 1,
            'name' => ['ko' => '기본택배', 'en' => 'Basic Parcel'],
            'shipping_method' => ShippingMethodEnum::PARCEL->value,
            'charge_policy' => ChargePolicyEnum::FIXED->value,
        ]);

        $this->mockRepository
            ->shouldReceive('find')
            ->with(1)
            ->once()
            ->andReturn($shippingPolicy);

        // When: getDetail 호출
        $result = $this->service->getDetail(1);

        // Then: 배송정책 반환
        $this->assertEquals($shippingPolicy, $result);
    }

    public function test_get_detail_returns_null_for_nonexistent_policy(): void
    {
        // Given: Repository가 null 반환
        $this->mockRepository
            ->shouldReceive('find')
            ->with(99999)
            ->once()
            ->andReturn(null);

        // When: getDetail 호출
        $result = $this->service->getDetail(99999);

        // Then: null 반환
        $this->assertNull($result);
    }

    // ========================================
    // create() 테스트
    // ========================================

    public function test_create_creates_shipping_policy(): void
    {
        // Given: 사용자 인증 상태
        $user = $this->createAdminUser();
        $this->actingAs($user);

        $data = [
            'name' => ['ko' => '새택배정책', 'en' => 'New Parcel Policy'],
            'shipping_method' => ShippingMethodEnum::PARCEL->value,
            'charge_policy' => ChargePolicyEnum::FIXED->value,
            'base_fee' => 3000,
            'countries' => ['KR'],
            'currency_code' => 'KRW',
            'is_active' => true,
        ];

        $createdPolicy = new ShippingPolicy(array_merge($data, ['id' => 1]));

        $this->mockRepository
            ->shouldReceive('create')
            ->once()
            ->andReturn($createdPolicy);

        // When: create 호출
        $result = $this->service->create($data);

        // Then: 배송정책 생성됨
        $this->assertEquals($createdPolicy, $result);
    }

    public function test_create_with_is_default_true_clears_existing_default(): void
    {
        // Given: 사용자 인증 및 실제 DB에 정책 생성
        $user = $this->createAdminUser();
        $this->actingAs($user);

        $data = [
            'name' => ['ko' => '기본정책', 'en' => 'Default Policy'],
            'is_active' => true,
            'is_default' => true,
            'country_settings' => [],
        ];

        // 실제 DB에 생성하여 id 보장
        $this->mockRepository
            ->shouldReceive('create')
            ->once()
            ->andReturnUsing(function ($createData) {
                return ShippingPolicy::create($createData);
            });

        // is_default=true 이므로 clearDefault 호출되어야 함
        $this->mockRepository
            ->shouldReceive('clearDefault')
            ->once()
            ->with(Mockery::type('int'));

        // When: create 호출
        $result = $this->service->create($data);

        // Then: 배송정책 생성됨 + is_default=true
        $this->assertNotNull($result);
        $this->assertTrue($result->is_default);
    }

    public function test_create_with_is_default_false_does_not_clear_default(): void
    {
        // Given: 사용자 인증 및 실제 DB에 정책 생성
        $user = $this->createAdminUser();
        $this->actingAs($user);

        $data = [
            'name' => ['ko' => '일반정책', 'en' => 'Normal Policy'],
            'is_active' => true,
            'is_default' => false,
            'country_settings' => [],
        ];

        $this->mockRepository
            ->shouldReceive('create')
            ->once()
            ->andReturnUsing(function ($createData) {
                return ShippingPolicy::create($createData);
            });

        // is_default=false 이므로 clearDefault 호출되지 않아야 함
        $this->mockRepository
            ->shouldNotReceive('clearDefault');

        // When: create 호출
        $result = $this->service->create($data);

        // Then: 배송정책 생성됨
        $this->assertNotNull($result);
        $this->assertFalse($result->is_default);
    }

    // ========================================
    // update() 테스트
    // ========================================

    public function test_update_modifies_shipping_policy(): void
    {
        // Given: 사용자 인증 및 실제 DB에 정책 생성 (countrySettings 아키텍처)
        $user = $this->createAdminUser();
        $this->actingAs($user);

        $shippingPolicy = ShippingPolicy::create([
            'name' => ['ko' => '기본택배', 'en' => 'Basic Parcel'],
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $shippingPolicy->countrySettings()->create([
            'country_code' => 'KR',
            'shipping_method' => 'parcel',
            'currency_code' => 'KRW',
            'charge_policy' => 'fixed',
            'base_fee' => 3000,
            'is_active' => true,
        ]);

        $data = [
            'sort_order' => 5,
            'country_settings' => [
                [
                    'country_code' => 'KR',
                    'shipping_method' => 'parcel',
                    'currency_code' => 'KRW',
                    'charge_policy' => 'fixed',
                    'base_fee' => 5000,
                    'is_active' => true,
                ],
            ],
        ];

        // Repository::update()만 모킹 (나머지 Eloquent 호출은 실제 DB 사용)
        $this->mockRepository
            ->shouldReceive('update')
            ->with($shippingPolicy, Mockery::type('array'))
            ->once()
            ->andReturnUsing(function ($policy, $updateData) {
                $policy->update($updateData);

                return $policy->fresh();
            });

        // When: update 호출
        $result = $this->service->update($shippingPolicy, $data);

        // Then: 배송정책 수정됨
        $this->assertNotNull($result);
        $this->assertEquals(5, $result->sort_order);
        $this->assertCount(1, $result->countrySettings);
        $this->assertEquals('5000.00', $result->countrySettings->first()->base_fee);
    }

    public function test_update_with_is_default_true_clears_existing_default(): void
    {
        // Given: 사용자 인증 및 실제 DB에 정책 생성
        $user = $this->createAdminUser();
        $this->actingAs($user);

        $shippingPolicy = ShippingPolicy::create([
            'name' => ['ko' => '일반정책', 'en' => 'Normal Policy'],
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 0,
        ]);

        $data = [
            'is_default' => true,
            'country_settings' => [],
        ];

        $this->mockRepository
            ->shouldReceive('update')
            ->once()
            ->andReturnUsing(function ($policy, $updateData) {
                $policy->update($updateData);

                return $policy->fresh();
            });

        // is_default=true로 변경되므로 clearDefault 호출되어야 함
        $this->mockRepository
            ->shouldReceive('clearDefault')
            ->with($shippingPolicy->id)
            ->once();

        // When: update 호출
        $result = $this->service->update($shippingPolicy, $data);

        // Then: is_default가 true로 변경됨
        $this->assertTrue($result->is_default);
    }

    // ========================================
    // delete() 테스트
    // ========================================

    public function test_delete_removes_shipping_policy(): void
    {
        // Given: 배송정책 존재
        $shippingPolicy = new ShippingPolicy(['id' => 1]);

        $this->mockRepository
            ->shouldReceive('delete')
            ->with($shippingPolicy)
            ->once()
            ->andReturn(true);

        // When: delete 호출
        $result = $this->service->delete($shippingPolicy);

        // Then: true 반환
        $this->assertTrue($result);
    }

    // ========================================
    // toggleActive() 테스트
    // ========================================

    public function test_toggle_active_changes_status(): void
    {
        // Given: 배송정책 존재
        $shippingPolicy = new ShippingPolicy([
            'id' => 1,
            'is_active' => true,
        ]);

        $toggledPolicy = new ShippingPolicy([
            'id' => 1,
            'is_active' => false,
        ]);

        $this->mockRepository
            ->shouldReceive('toggleActive')
            ->with($shippingPolicy)
            ->once()
            ->andReturn($toggledPolicy);

        // When: toggleActive 호출
        $result = $this->service->toggleActive($shippingPolicy);

        // Then: 상태 변경됨
        $this->assertFalse($result->is_active);
    }

    // ========================================
    // bulkDelete() 테스트
    // ========================================

    public function test_bulk_delete_removes_multiple_policies(): void
    {
        // Given: ID 배열
        $ids = [1, 2, 3];

        $this->mockRepository
            ->shouldReceive('bulkDelete')
            ->with($ids)
            ->once()
            ->andReturn(3);

        // When: bulkDelete 호출
        $result = $this->service->bulkDelete($ids);

        // Then: 삭제 개수 반환
        $this->assertEquals(3, $result);
    }

    // ========================================
    // bulkToggleActive() 테스트
    // ========================================

    public function test_bulk_toggle_active_changes_multiple_statuses(): void
    {
        // Given: ID 배열과 활성화 상태
        $ids = [1, 2, 3];
        $isActive = false;

        $this->mockRepository
            ->shouldReceive('bulkToggleActive')
            ->with($ids, $isActive)
            ->once()
            ->andReturn(3);

        // When: bulkToggleActive 호출
        $result = $this->service->bulkToggleActive($ids, $isActive);

        // Then: 변경 개수 반환
        $this->assertEquals(3, $result);
    }

    // ========================================
    // getActiveList() 테스트
    // ========================================

    public function test_get_active_list_returns_collection(): void
    {
        // Given: Repository가 컬렉션 반환
        $collection = new Collection([
            new ShippingPolicy(['id' => 1, 'is_active' => true]),
            new ShippingPolicy(['id' => 2, 'is_active' => true]),
        ]);

        $this->mockRepository
            ->shouldReceive('getActiveList')
            ->once()
            ->andReturn($collection);

        // When: getActiveList 호출
        $result = $this->service->getActiveList();

        // Then: 컬렉션 반환
        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(2, $result);
    }
}
