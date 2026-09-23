<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingPolicyClaimAddressRepositoryInterface;
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

    /**
     * 리포지토리 목을 주입한 배송정책 서비스를 준비하고, 삭제 경로의 자식 행(국가별 설정·회수지) 정리 호출은 기본 허용으로 둔다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Hook listener job 차단 (mock 모델 deserialize 시 null TypeError 방지)
        Queue::fake();

        $this->mockRepository = Mockery::mock(ShippingPolicyRepositoryInterface::class);
        // 삭제 경로는 자식 행(국가별 설정·회수지)을 명시적으로 지운다 — 각 테스트가 따로 단언하지
        // 않는 한 그 호출은 허용만 한다.
        $this->mockRepository->shouldReceive('deleteCountrySettingsByPolicyIds')->andReturn(0)->byDefault();
        $claimAddressRepository = Mockery::mock(ShippingPolicyClaimAddressRepositoryInterface::class);
        $claimAddressRepository->shouldReceive('deleteByPolicyIds')->andReturn(0)->byDefault();
        $this->service = new ShippingPolicyService(
            $this->mockRepository,
            $claimAddressRepository,
        );
    }

    /**
     * Mockery 기대값을 검증·정리한 뒤 부모 정리를 수행한다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    // ========================================
    // getList() 테스트
    // ========================================

    /**
     * 목록 조회가 필터와 요청한 per_page 를 리포지토리에 그대로 넘기고, 받은 페이지네이터를 가공 없이 돌려주는지 확인한다.
     *
     * @scenario area=read
     *
     * @effects shipping_policy_list_passes_filters_and_per_page
     *
     * @return void 반환값 없음
     */
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

    /**
     * per_page 를 주지 않으면 기본값 20 으로 리포지토리를 조회하는지 확인한다.
     *
     * @scenario area=read
     *
     * @effects shipping_policy_list_defaults_per_page_to_20
     *
     * @return void 반환값 없음
     */
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

    /**
     * 통계 조회가 리포지토리 집계(총계·활성·비활성·배송방법별·부과정책별)를 키 손실 없이 그대로 돌려주는지 확인한다.
     *
     * @scenario area=read
     *
     * @effects shipping_policy_statistics_passthrough
     *
     * @return void 반환값 없음
     */
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

    /**
     * 상세 조회가 ID 로 리포지토리에서 찾은 배송정책을 그대로 돌려주는지 확인한다.
     *
     * @scenario area=read
     *
     * @effects shipping_policy_detail_returns_found_policy
     *
     * @return void 반환값 없음
     */
    public function test_get_detail_returns_shipping_policy(): void
    {
        // Given: Repository가 배송정책 반환
        $shippingPolicy = new ShippingPolicy([
            'id' => 1,
            'name' => ['ko' => '기본택배', 'en' => 'Basic Parcel'],
            'shipping_method' => 'parcel',
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

    /**
     * 없는 ID 를 조회하면 예외 없이 null 을 돌려주는지 확인한다.
     *
     * @scenario area=read
     *
     * @effects shipping_policy_detail_returns_null_when_missing
     *
     * @return void 반환값 없음
     */
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

    /**
     * 관리자 인증 상태의 생성 요청이 리포지토리 create 를 한 번 호출하고 그 결과를 돌려주는지 확인한다.
     *
     * @scenario area=create
     *
     * @effects shipping_policy_create_returns_created_policy
     *
     * @return void 반환값 없음
     */
    public function test_create_creates_shipping_policy(): void
    {
        // Given: 사용자 인증 상태
        $user = $this->createAdminUser();
        $this->actingAs($user);

        $data = [
            'name' => ['ko' => '새택배정책', 'en' => 'New Parcel Policy'],
            'shipping_method' => 'parcel',
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

    /**
     * 기본 배송정책으로 생성하면 새 정책 ID 로 clearDefault 를 호출해 다른 정책의 기본 지정을 해제하고, 새 정책은 기본으로 저장되는지 확인한다.
     *
     * @scenario area=create
     *
     * @effects create_as_default_clears_other_defaults
     *
     * @return void 반환값 없음
     */
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

    /**
     * 기본이 아닌 정책을 생성하면 clearDefault 를 호출하지 않아 기존 기본 정책이 그대로 유지되는지 확인한다.
     *
     * @scenario area=create
     *
     * @effects create_non_default_keeps_existing_default
     *
     * @return void 반환값 없음
     */
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

    /**
     * 수정 시 정렬순서가 바뀌고 국가별 설정이 요청값으로 교체(행 1개 유지, 기본 배송비 3000 → 5000)되는지 실제 DB 로 확인한다.
     *
     * @scenario area=update
     *
     * @effects shipping_policy_update_replaces_country_settings
     *
     * @return void 반환값 없음
     */
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

    /**
     * 비기본 정책을 기본으로 수정하면 그 정책 ID 로 clearDefault 를 호출하고 is_default 가 true 로 저장되는지 확인한다.
     *
     * @scenario area=update
     *
     * @effects update_to_default_clears_other_defaults
     *
     * @return void 반환값 없음
     */
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

    /**
     * 단건 삭제가 리포지토리 delete 에 위임되고 성공 여부(true)를 돌려주는지 확인한다.
     *
     * @scenario area=delete
     *
     * @effects shipping_policy_delete_delegates_to_repository
     *
     * @return void 반환값 없음
     */
    public function test_delete_removes_shipping_policy(): void
    {
        // Given: 배송정책 존재 (id는 fillable이 아니므로 직접 설정)
        $shippingPolicy = new ShippingPolicy;
        $shippingPolicy->id = 1;

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

    /**
     * 활성 토글이 리포지토리 결과를 돌려줘 활성 정책이 비활성으로 바뀌는지 확인한다.
     *
     * @scenario area=toggle
     *
     * @effects toggle_active_returns_flipped_policy
     *
     * @return void 반환값 없음
     */
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

    /**
     * 일괄 삭제가 훅 전달용 스냅샷을 먼저 잡고, 국가별 설정을 명시적으로 지운 뒤 정책을 삭제해 삭제 건수를 돌려주는지 확인한다.
     *
     * @scenario area=delete
     *
     * @effects bulk_delete_removes_country_settings_then_policies
     *
     * @return void 반환값 없음
     */
    public function test_bulk_delete_removes_multiple_policies(): void
    {
        // Given: ID 배열
        $ids = [1, 2, 3];

        // 스냅샷 캡처 (after_bulk_delete 훅 전달용) + 국가별 설정 명시적 삭제 위임
        $this->mockRepository
            ->shouldReceive('findByIdsKeyed')
            ->with($ids)
            ->once()
            ->andReturn(new Collection);
        $this->mockRepository
            ->shouldReceive('deleteCountrySettingsByPolicyIds')
            ->with($ids)
            ->once()
            ->andReturn(0);
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

    /**
     * 일괄 활성 변경이 훅 전달용 스냅샷을 잡은 뒤 ID 목록과 목표 상태를 리포지토리에 넘기고 변경 건수를 돌려주는지 확인한다.
     *
     * @scenario area=toggle
     *
     * @effects bulk_toggle_active_returns_changed_count
     *
     * @return void 반환값 없음
     */
    public function test_bulk_toggle_active_changes_multiple_statuses(): void
    {
        // Given: ID 배열과 활성화 상태
        $ids = [1, 2, 3];
        $isActive = false;

        // 스냅샷 캡처 (after_bulk_toggle_active 훅 전달용) 위임
        $this->mockRepository
            ->shouldReceive('findByIdsKeyed')
            ->with($ids)
            ->once()
            ->andReturn(new Collection);
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

    /**
     * 활성 정책 목록 조회가 리포지토리 컬렉션을 그대로 돌려주는지 확인한다.
     *
     * @scenario area=read
     *
     * @effects active_list_returns_repository_collection
     *
     * @return void 반환값 없음
     */
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

    // ========================================
    // 통화 강제 (forceDefaultCurrency) 테스트
    // ========================================

    /**
     * 생성 시 관리자가 국가별 설정 통화를 USD 로 보내도 무시되고 상점 기본 통화(미설정 환경 폴백 KRW)로 저장되는지 확인한다.
     *
     * @scenario area=currency
     *
     * @effects currency_forced_to_default_on_create, submitted_currency_ignored
     *
     * @return void 반환값 없음
     */
    public function test_create_forces_country_setting_currency_to_default(): void
    {
        // Given: 관리자 인증 + 비-기본통화(USD)로 country_settings 입력
        $user = $this->createAdminUser();
        $this->actingAs($user);

        $data = [
            'name' => ['ko' => '통화강제정책', 'en' => 'Currency Forced Policy'],
            'is_active' => true,
            'country_settings' => [
                [
                    'country_code' => 'KR',
                    'shipping_method' => 'parcel',
                    'currency_code' => 'USD', // 관리자가 임의로 USD 지정해도 무시되어야 함
                    'charge_policy' => 'fixed',
                    'base_fee' => 3000,
                    'is_active' => true,
                ],
            ],
        ];

        $this->mockRepository
            ->shouldReceive('create')
            ->once()
            ->andReturnUsing(fn ($createData) => ShippingPolicy::create($createData));

        // When: create 호출
        $result = $this->service->create($data);

        // Then: country_setting 통화가 기본 통화(미설정 환경 폴백 KRW)로 강제됨
        $this->assertCount(1, $result->countrySettings);
        $this->assertSame('KRW', $result->countrySettings->first()->currency_code);
    }

    /**
     * 수정 시 EUR 를 보내도 무시되고, USD 로 저장돼 있던 국가별 설정 통화가 상점 기본 통화(폴백 KRW)로 바로잡히는지 확인한다.
     *
     * @scenario area=currency
     *
     * @effects currency_forced_to_default_on_update, submitted_currency_ignored
     *
     * @return void 반환값 없음
     */
    public function test_update_forces_country_setting_currency_to_default(): void
    {
        // Given: 관리자 인증 + 기존 정책(USD 저장 상태)
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
            'currency_code' => 'USD',
            'charge_policy' => 'fixed',
            'base_fee' => 3000,
            'is_active' => true,
        ]);

        $data = [
            'country_settings' => [
                [
                    'country_code' => 'KR',
                    'shipping_method' => 'parcel',
                    'currency_code' => 'EUR', // 수정 시에도 임의 통화 무시
                    'charge_policy' => 'fixed',
                    'base_fee' => 5000,
                    'is_active' => true,
                ],
            ],
        ];

        $this->mockRepository
            ->shouldReceive('update')
            ->once()
            ->andReturnUsing(function ($policy, $updateData) {
                $policy->update($updateData);

                return $policy->fresh();
            });

        // When: update 호출
        $result = $this->service->update($shippingPolicy, $data);

        // Then: country_setting 통화가 기본 통화(폴백 KRW)로 강제됨
        $this->assertCount(1, $result->countrySettings);
        $this->assertSame('KRW', $result->countrySettings->first()->currency_code);
    }
}
