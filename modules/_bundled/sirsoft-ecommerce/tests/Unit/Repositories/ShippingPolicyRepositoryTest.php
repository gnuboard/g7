<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Repositories;

use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicyCountrySetting;
use Modules\Sirsoft\Ecommerce\Repositories\ShippingPolicyRepository;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 배송정책 Repository Unit 테스트
 *
 * country_settings 아키텍처 반영:
 * - ShippingPolicy: name, is_active, sort_order 등 정책 메타데이터만 보유
 * - ShippingPolicyCountrySetting: shipping_method, charge_policy, base_fee 등 국가별 설정 보유
 */
class ShippingPolicyRepositoryTest extends ModuleTestCase
{
    protected ShippingPolicyRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new ShippingPolicyRepository(new ShippingPolicy);
    }

    // ========================================
    // 헬퍼 메서드
    // ========================================

    /**
     * 배송정책 + 국가별 설정을 생성하는 헬퍼
     *
     * @param array $policyOverrides 정책 오버라이드
     * @param array $countrySettings 국가별 설정 배열
     * @return ShippingPolicy
     */
    protected function createPolicyWithSettings(
        array $policyOverrides = [],
        array $countrySettings = []
    ): ShippingPolicy {
        $policyData = array_merge([
            'name' => ['ko' => '기본택배', 'en' => 'Standard Delivery'],
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 0,
        ], $policyOverrides);

        $policy = ShippingPolicy::create($policyData);

        if (empty($countrySettings)) {
            $countrySettings = [$this->makeKrCountrySetting()];
        }

        foreach ($countrySettings as $cs) {
            $policy->countrySettings()->create($cs);
        }

        return $policy->load('countrySettings');
    }

    /**
     * 기본 KR 국가 설정 데이터
     *
     * @param array $overrides 오버라이드
     * @return array
     */
    protected function makeKrCountrySetting(array $overrides = []): array
    {
        return array_merge([
            'country_code' => 'KR',
            'shipping_method' => 'parcel',
            'currency_code' => 'KRW',
            'charge_policy' => 'fixed',
            'base_fee' => 3000,
            'free_threshold' => null,
            'ranges' => null,
            'api_endpoint' => null,
            'api_request_fields' => null,
            'api_response_fee_field' => null,
            'extra_fee_enabled' => false,
            'extra_fee_settings' => null,
            'extra_fee_multiply' => false,
            'is_active' => true,
        ], $overrides);
    }

    // ========================================
    // find() 테스트
    // ========================================

    public function test_find_returns_shipping_policy_by_id(): void
    {
        // Given: 배송정책 존재
        $shippingPolicy = $this->createPolicyWithSettings([
            'name' => ['ko' => '기본택배', 'en' => 'Basic Parcel'],
        ]);

        // When: find 호출
        $result = $this->repository->find($shippingPolicy->id);

        // Then: 배송정책 반환
        $this->assertNotNull($result);
        $this->assertEquals($shippingPolicy->id, $result->id);
        $this->assertEquals('기본택배', $result->getLocalizedName('ko'));
    }

    public function test_find_returns_null_for_nonexistent_id(): void
    {
        // When: 존재하지 않는 ID로 find 호출
        $result = $this->repository->find(99999);

        // Then: null 반환
        $this->assertNull($result);
    }

    // ========================================
    // getListWithFilters() 테스트
    // ========================================

    public function test_get_list_with_filters_returns_paginated_result(): void
    {
        // Given: 배송정책 여러 개 생성
        for ($i = 1; $i <= 15; $i++) {
            $this->createPolicyWithSettings([
                'name' => ['ko' => "배송정책 {$i}", 'en' => "Policy {$i}"],
                'sort_order' => $i,
            ]);
        }

        // When: getListWithFilters 호출
        $result = $this->repository->getListWithFilters([], 10);

        // Then: 페이지네이션 결과 반환
        $this->assertInstanceOf(\Illuminate\Contracts\Pagination\LengthAwarePaginator::class, $result);
        $this->assertEquals(10, $result->perPage());
        $this->assertEquals(15, $result->total());
    }

    public function test_get_list_with_filters_filters_by_shipping_method(): void
    {
        // Given: 다양한 배송방법의 정책 생성 (countrySettings 기반)
        $this->createPolicyWithSettings(
            ['name' => ['ko' => '택배배송', 'en' => 'Parcel'], 'sort_order' => 0],
            [$this->makeKrCountrySetting(['shipping_method' => 'parcel'])]
        );

        $this->createPolicyWithSettings(
            ['name' => ['ko' => '퀵서비스', 'en' => 'Quick'], 'sort_order' => 1],
            [$this->makeKrCountrySetting(['shipping_method' => 'quick', 'base_fee' => 10000])]
        );

        // When: 배송방법 필터 적용
        $result = $this->repository->getListWithFilters(['shipping_methods' => ['parcel']], 20);

        // Then: 택배배송만 반환
        $this->assertEquals(1, $result->total());
        $this->assertEquals('택배배송', $result->first()->getLocalizedName('ko'));
    }

    public function test_get_list_with_filters_filters_by_charge_policy(): void
    {
        // Given: 다양한 부과정책의 정책 생성 (countrySettings 기반)
        $this->createPolicyWithSettings(
            ['name' => ['ko' => '무료배송', 'en' => 'Free'], 'sort_order' => 0],
            [$this->makeKrCountrySetting(['charge_policy' => ChargePolicyEnum::FREE->value, 'base_fee' => 0])]
        );

        $this->createPolicyWithSettings(
            ['name' => ['ko' => '고정배송비', 'en' => 'Fixed'], 'sort_order' => 1],
            [$this->makeKrCountrySetting(['charge_policy' => ChargePolicyEnum::FIXED->value, 'base_fee' => 3000])]
        );

        // When: 부과정책 필터 적용
        $result = $this->repository->getListWithFilters(['charge_policies' => ['free']], 20);

        // Then: 무료배송만 반환
        $this->assertEquals(1, $result->total());
        $this->assertEquals('무료배송', $result->first()->getLocalizedName('ko'));
    }

    public function test_get_list_with_filters_filters_by_is_active(): void
    {
        // Given: 활성/비활성 정책 생성
        $this->createPolicyWithSettings([
            'name' => ['ko' => '활성정책', 'en' => 'Active'],
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->createPolicyWithSettings([
            'name' => ['ko' => '비활성정책', 'en' => 'Inactive'],
            'is_active' => false,
            'sort_order' => 1,
        ]);

        // When: 활성 필터 적용
        $result = $this->repository->getListWithFilters(['is_active' => 'true'], 20);

        // Then: 활성 정책만 반환
        $this->assertEquals(1, $result->total());
        $this->assertTrue($result->first()->is_active);
    }

    public function test_get_list_with_filters_searches_by_name(): void
    {
        // Given: 배송정책 생성
        $this->createPolicyWithSettings([
            'name' => ['ko' => '기본택배배송', 'en' => 'Basic Parcel'],
            'sort_order' => 0,
        ]);

        $this->createPolicyWithSettings([
            'name' => ['ko' => '해외배송', 'en' => 'International'],
            'sort_order' => 1,
        ], [$this->makeKrCountrySetting([
            'country_code' => 'US',
            'charge_policy' => ChargePolicyEnum::API->value,
            'currency_code' => 'USD',
            'base_fee' => 0,
        ])]);

        // When: 검색어 적용
        $result = $this->repository->getListWithFilters(['search' => '택배'], 20);

        // Then: 택배 포함 정책만 반환
        $this->assertEquals(1, $result->total());
        $this->assertStringContainsString('택배', $result->first()->getLocalizedName('ko'));
    }

    // ========================================
    // create() 테스트
    // ========================================

    public function test_create_creates_shipping_policy(): void
    {
        // Given: 배송정책 데이터 (ShippingPolicy 모델 필드만)
        $data = [
            'name' => ['ko' => '새배송정책', 'en' => 'New Policy'],
            'is_active' => true,
            'sort_order' => 0,
        ];

        // When: create 호출
        $result = $this->repository->create($data);

        // Then: 배송정책 생성됨
        $this->assertNotNull($result->id);
        $this->assertEquals('새배송정책', $result->getLocalizedName('ko'));
        $this->assertDatabaseHas('ecommerce_shipping_policies', [
            'id' => $result->id,
        ]);
    }

    // ========================================
    // update() 테스트
    // ========================================

    public function test_update_updates_shipping_policy(): void
    {
        // Given: 배송정책 존재
        $shippingPolicy = $this->createPolicyWithSettings([
            'name' => ['ko' => '기본정책', 'en' => 'Basic'],
            'sort_order' => 0,
        ]);

        // When: update 호출 (ShippingPolicy 필드인 sort_order 수정)
        $result = $this->repository->update($shippingPolicy, ['sort_order' => 5]);

        // Then: 배송정책 수정됨
        $this->assertEquals(5, $result->sort_order);
        $this->assertDatabaseHas('ecommerce_shipping_policies', [
            'id' => $shippingPolicy->id,
            'sort_order' => 5,
        ]);
    }

    // ========================================
    // delete() 테스트
    // ========================================

    public function test_delete_removes_shipping_policy(): void
    {
        // Given: 배송정책 존재
        $shippingPolicy = $this->createPolicyWithSettings([
            'name' => ['ko' => '삭제대상', 'en' => 'To Delete'],
        ]);

        $id = $shippingPolicy->id;

        // When: delete 호출
        $result = $this->repository->delete($shippingPolicy);

        // Then: 배송정책 삭제됨
        $this->assertTrue($result);
        $this->assertDatabaseMissing('ecommerce_shipping_policies', [
            'id' => $id,
        ]);
    }

    // ========================================
    // toggleActive() 테스트
    // ========================================

    public function test_toggle_active_toggles_status(): void
    {
        // Given: 사용자 인증 및 배송정책 존재
        $user = $this->createAdminUser();
        $this->actingAs($user);

        $shippingPolicy = $this->createPolicyWithSettings([
            'name' => ['ko' => '토글대상', 'en' => 'To Toggle'],
            'is_active' => true,
        ]);

        // When: toggleActive 호출
        $result = $this->repository->toggleActive($shippingPolicy);

        // Then: 상태 변경됨
        $this->assertFalse($result->is_active);
        $this->assertDatabaseHas('ecommerce_shipping_policies', [
            'id' => $shippingPolicy->id,
            'is_active' => false,
        ]);
    }

    // ========================================
    // bulkDelete() 테스트
    // ========================================

    public function test_bulk_delete_removes_multiple_policies(): void
    {
        // Given: 배송정책 여러 개 생성
        $policy1 = $this->createPolicyWithSettings([
            'name' => ['ko' => '정책1', 'en' => 'Policy1'],
            'sort_order' => 0,
        ]);

        $policy2 = $this->createPolicyWithSettings([
            'name' => ['ko' => '정책2', 'en' => 'Policy2'],
            'sort_order' => 1,
        ]);

        $policy3 = $this->createPolicyWithSettings([
            'name' => ['ko' => '정책3', 'en' => 'Policy3'],
            'sort_order' => 2,
        ]);

        // When: bulkDelete 호출
        $result = $this->repository->bulkDelete([$policy1->id, $policy2->id]);

        // Then: 2개 삭제됨, policy3은 유지
        $this->assertEquals(2, $result);
        $this->assertDatabaseMissing('ecommerce_shipping_policies', ['id' => $policy1->id]);
        $this->assertDatabaseMissing('ecommerce_shipping_policies', ['id' => $policy2->id]);
        $this->assertDatabaseHas('ecommerce_shipping_policies', ['id' => $policy3->id]);
    }

    // ========================================
    // bulkToggleActive() 테스트
    // ========================================

    public function test_bulk_toggle_active_changes_multiple_statuses(): void
    {
        // Given: 사용자 인증 및 배송정책 여러 개 생성
        $user = $this->createAdminUser();
        $this->actingAs($user);

        $policy1 = $this->createPolicyWithSettings([
            'name' => ['ko' => '정책1', 'en' => 'Policy1'],
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $policy2 = $this->createPolicyWithSettings([
            'name' => ['ko' => '정책2', 'en' => 'Policy2'],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        // When: bulkToggleActive 호출
        $result = $this->repository->bulkToggleActive([$policy1->id, $policy2->id], false);

        // Then: 2개 비활성화됨
        $this->assertEquals(2, $result);
        $this->assertDatabaseHas('ecommerce_shipping_policies', [
            'id' => $policy1->id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('ecommerce_shipping_policies', [
            'id' => $policy2->id,
            'is_active' => false,
        ]);
    }

    // ========================================
    // getStatistics() 테스트
    // ========================================

    public function test_get_statistics_returns_correct_counts(): void
    {
        // Given: 배송정책 여러 개 생성 (countrySettings 기반 통계)
        $this->createPolicyWithSettings(
            ['name' => ['ko' => '활성택배', 'en' => 'Active Parcel'], 'is_active' => true, 'sort_order' => 0],
            [$this->makeKrCountrySetting(['shipping_method' => 'parcel', 'charge_policy' => 'fixed', 'base_fee' => 3000])]
        );

        $this->createPolicyWithSettings(
            ['name' => ['ko' => '비활성퀵', 'en' => 'Inactive Quick'], 'is_active' => false, 'sort_order' => 1],
            [$this->makeKrCountrySetting(['shipping_method' => 'quick', 'charge_policy' => 'free', 'base_fee' => 0])]
        );

        $this->createPolicyWithSettings(
            ['name' => ['ko' => '활성택배2', 'en' => 'Active Parcel 2'], 'is_active' => true, 'sort_order' => 2],
            [$this->makeKrCountrySetting(['shipping_method' => 'parcel', 'charge_policy' => 'conditional_free', 'base_fee' => 3000, 'free_threshold' => 50000])]
        );

        // When: getStatistics 호출
        $result = $this->repository->getStatistics();

        // Then: 올바른 통계 반환
        $this->assertEquals(3, $result['total']);
        $this->assertEquals(2, $result['active']);
        $this->assertEquals(1, $result['inactive']);
        $this->assertEquals(2, $result['shipping_method']['parcel']);
        $this->assertEquals(1, $result['shipping_method']['quick']);
        $this->assertEquals(1, $result['charge_policy']['fixed']);
        $this->assertEquals(1, $result['charge_policy']['free']);
        $this->assertEquals(1, $result['charge_policy']['conditional_free']);
    }

    // ========================================
    // getActiveList() 테스트
    // ========================================

    public function test_get_active_list_returns_only_active_policies(): void
    {
        // Given: 활성/비활성 정책 생성
        $this->createPolicyWithSettings([
            'name' => ['ko' => '활성정책1', 'en' => 'Active 1'],
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $this->createPolicyWithSettings([
            'name' => ['ko' => '비활성정책', 'en' => 'Inactive'],
            'is_active' => false,
            'sort_order' => 1,
        ]);

        $this->createPolicyWithSettings([
            'name' => ['ko' => '활성정책2', 'en' => 'Active 2'],
            'is_active' => true,
            'sort_order' => 0,
        ]);

        // When: getActiveList 호출
        $result = $this->repository->getActiveList();

        // Then: 활성 정책만 sort_order 순으로 반환
        $this->assertCount(2, $result);
        $this->assertTrue($result->first()->is_active);
        $this->assertEquals(0, $result->first()->sort_order);
        $this->assertEquals(2, $result->last()->sort_order);
    }
}
