<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicyCountrySetting;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * ShippingPolicyController Feature 테스트
 *
 * 배송정책 관리 API 엔드포인트 테스트 (country_settings 아키텍처)
 */
class ShippingPolicyControllerTest extends ModuleTestCase
{
    protected User $adminUser;

    /** @var string API 베이스 URL */
    protected string $apiBase = '/api/modules/sirsoft-ecommerce/admin/shipping-policies';

    /**
     * 테스트 환경 설정
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 관리자 사용자 생성 (배송정책 권한 포함)
        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.shipping-policies.read',
            'sirsoft-ecommerce.shipping-policies.create',
            'sirsoft-ecommerce.shipping-policies.update',
            'sirsoft-ecommerce.shipping-policies.delete',
        ]);
    }

    // ──────────────────────────────────────────────
    // 헬퍼 메서드
    // ──────────────────────────────────────────────

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
            'sort_order' => 1,
        ], $policyOverrides);

        $policy = ShippingPolicy::create($policyData);

        if (empty($countrySettings)) {
            // 기본 KR 설정
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

    /**
     * 기본 US 국가 설정 데이터
     *
     * @param array $overrides 오버라이드
     * @return array
     */
    protected function makeUsCountrySetting(array $overrides = []): array
    {
        return array_merge([
            'country_code' => 'US',
            'shipping_method' => 'parcel',
            'currency_code' => 'USD',
            'charge_policy' => 'fixed',
            'base_fee' => 15,
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

    /**
     * Store API 페이로드를 생성하는 헬퍼
     *
     * @param array $overrides 정책 레벨 오버라이드
     * @param array|null $countrySettings 국가별 설정 (null이면 기본 KR)
     * @return array
     */
    protected function makeStorePayload(array $overrides = [], ?array $countrySettings = null): array
    {
        $payload = array_merge([
            'name' => ['ko' => '테스트 배송정책', 'en' => 'Test Shipping Policy'],
            'is_active' => true,
            'is_default' => false,
        ], $overrides);

        $payload['country_settings'] = $countrySettings ?? [$this->makeKrCountrySetting()];

        return $payload;
    }

    // ──────────────────────────────────────────────
    // 1. Index (목록 조회) 테스트
    // ──────────────────────────────────────────────

    /**
     * 배송정책 목록 조회 - 페이지네이션 포함
     */
    #[Test]
    public function test_index_returns_paginated_list_with_country_settings(): void
    {
        // Given: 배송정책 2개 생성 (각각 국가별 설정 포함)
        $this->createPolicyWithSettings(
            ['name' => ['ko' => '기본택배', 'en' => 'Standard Delivery']],
            [$this->makeKrCountrySetting()]
        );

        $this->createPolicyWithSettings(
            ['name' => ['ko' => '국제배송', 'en' => 'International Shipping'], 'sort_order' => 2],
            [$this->makeUsCountrySetting()]
        );

        // When: 목록 조회
        $response = $this->actingAs($this->adminUser)->getJson($this->apiBase);

        // Then: 성공 응답 및 구조 확인
        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'name_localized',
                            'country_settings',
                            'fee_summary',
                            'countries_display',
                            'is_active',
                            'is_default',
                            'sort_order',
                            'created_at',
                            'updated_at',
                        ],
                    ],
                    'statistics',
                    'pagination',
                ],
            ]);

        // 데이터 개수 확인
        $this->assertCount(2, $response->json('data.data'));
    }

    /**
     * 배송정책 목록 - country_settings 구조가 응답에 포함됨
     */
    #[Test]
    public function test_index_includes_country_settings_structure(): void
    {
        // Given: 다중 국가 설정 배송정책
        $this->createPolicyWithSettings(
            ['name' => ['ko' => '글로벌배송', 'en' => 'Global Shipping']],
            [$this->makeKrCountrySetting(), $this->makeUsCountrySetting()]
        );

        // When
        $response = $this->actingAs($this->adminUser)->getJson($this->apiBase);

        // Then: country_settings 배열 구조 확인
        $response->assertOk();
        $firstPolicy = $response->json('data.data.0');

        $this->assertCount(2, $firstPolicy['country_settings']);
        $this->assertArrayHasKey('country_code', $firstPolicy['country_settings'][0]);
        $this->assertArrayHasKey('shipping_method', $firstPolicy['country_settings'][0]);
        $this->assertArrayHasKey('shipping_method_label', $firstPolicy['country_settings'][0]);
        $this->assertArrayHasKey('charge_policy', $firstPolicy['country_settings'][0]);
        $this->assertArrayHasKey('charge_policy_label', $firstPolicy['country_settings'][0]);
        $this->assertArrayHasKey('currency_code', $firstPolicy['country_settings'][0]);
        $this->assertArrayHasKey('base_fee', $firstPolicy['country_settings'][0]);
        $this->assertArrayHasKey('extra_fee_enabled', $firstPolicy['country_settings'][0]);
        $this->assertArrayHasKey('is_active', $firstPolicy['country_settings'][0]);
    }

    /**
     * 배송정책 목록 - 통계 정보 포함 (countrySettings 기반)
     */
    #[Test]
    public function test_index_includes_statistics(): void
    {
        // Given
        $this->createPolicyWithSettings(
            ['name' => ['ko' => '활성정책', 'en' => 'Active Policy'], 'is_active' => true],
            [$this->makeKrCountrySetting(['charge_policy' => 'fixed'])]
        );

        $this->createPolicyWithSettings(
            ['name' => ['ko' => '비활성정책', 'en' => 'Inactive Policy'], 'is_active' => false, 'sort_order' => 2],
            [$this->makeKrCountrySetting(['charge_policy' => 'free', 'base_fee' => 0])]
        );

        // When
        $response = $this->actingAs($this->adminUser)->getJson($this->apiBase);

        // Then: 통계 데이터 확인
        $response->assertOk();
        $statistics = $response->json('data.statistics');

        $this->assertEquals(2, $statistics['total']);
        $this->assertEquals(1, $statistics['active']);
        $this->assertEquals(1, $statistics['inactive']);
        $this->assertArrayHasKey('shipping_method', $statistics);
        $this->assertArrayHasKey('charge_policy', $statistics);
    }

    // ──────────────────────────────────────────────
    // 1-1. Index 필터 테스트 (whereHas 기반)
    // ──────────────────────────────────────────────

    /**
     * 배송방법 필터 - countrySettings.shipping_method 기반 whereHas
     */
    #[Test]
    public function test_index_filter_by_shipping_methods(): void
    {
        // Given: 택배 정책
        $this->createPolicyWithSettings(
            ['name' => ['ko' => '택배정책', 'en' => 'Parcel Policy']],
            [$this->makeKrCountrySetting(['shipping_method' => 'parcel'])]
        );

        // Given: 퀵서비스 정책
        $this->createPolicyWithSettings(
            ['name' => ['ko' => '퀵서비스정책', 'en' => 'Quick Policy'], 'sort_order' => 2],
            [$this->makeKrCountrySetting(['shipping_method' => 'quick'])]
        );

        // When: parcel만 필터
        $response = $this->actingAs($this->adminUser)->getJson(
            $this->apiBase . '?shipping_methods[]=parcel'
        );

        // Then: 택배 정책만 반환
        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('parcel', $response->json('data.data.0.country_settings.0.shipping_method'));
    }

    /**
     * 부과정책 필터 - countrySettings.charge_policy 기반 whereHas
     */
    #[Test]
    public function test_index_filter_by_charge_policies(): void
    {
        // Given: 고정 배송비 정책
        $this->createPolicyWithSettings(
            ['name' => ['ko' => '고정정책', 'en' => 'Fixed Policy']],
            [$this->makeKrCountrySetting(['charge_policy' => 'fixed'])]
        );

        // Given: 무료 배송 정책
        $this->createPolicyWithSettings(
            ['name' => ['ko' => '무료정책', 'en' => 'Free Policy'], 'sort_order' => 2],
            [$this->makeKrCountrySetting(['charge_policy' => 'free', 'base_fee' => 0])]
        );

        // When: fixed만 필터
        $response = $this->actingAs($this->adminUser)->getJson(
            $this->apiBase . '?charge_policies[]=fixed'
        );

        // Then: 고정 배송비 정책만 반환
        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('fixed', $response->json('data.data.0.country_settings.0.charge_policy'));
    }

    /**
     * 배송국가 필터 - countrySettings.country_code 기반 whereHas
     */
    #[Test]
    public function test_index_filter_by_countries(): void
    {
        // Given: KR 정책
        $this->createPolicyWithSettings(
            ['name' => ['ko' => '국내정책', 'en' => 'Domestic Policy']],
            [$this->makeKrCountrySetting()]
        );

        // Given: US 정책
        $this->createPolicyWithSettings(
            ['name' => ['ko' => '미국정책', 'en' => 'US Policy'], 'sort_order' => 2],
            [$this->makeUsCountrySetting()]
        );

        // When: KR만 필터
        $response = $this->actingAs($this->adminUser)->getJson(
            $this->apiBase . '?countries[]=KR'
        );

        // Then: 국내 정책만 반환
        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('KR', $response->json('data.data.0.country_settings.0.country_code'));
    }

    /**
     * 정책명 검색 필터
     */
    #[Test]
    public function test_index_filter_by_search(): void
    {
        // Given
        $this->createPolicyWithSettings(
            ['name' => ['ko' => '기본택배', 'en' => 'Standard Delivery']],
            [$this->makeKrCountrySetting()]
        );

        $this->createPolicyWithSettings(
            ['name' => ['ko' => '프리미엄', 'en' => 'Premium'], 'sort_order' => 2],
            [$this->makeKrCountrySetting()]
        );

        // When: '기본' 검색
        $response = $this->actingAs($this->adminUser)->getJson(
            $this->apiBase . '?search=' . urlencode('기본')
        );

        // Then
        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
    }

    /**
     * 사용여부 필터
     */
    #[Test]
    public function test_index_filter_by_is_active(): void
    {
        // Given
        $this->createPolicyWithSettings(
            ['name' => ['ko' => '활성정책', 'en' => 'Active'], 'is_active' => true],
            [$this->makeKrCountrySetting()]
        );

        $this->createPolicyWithSettings(
            ['name' => ['ko' => '비활성정책', 'en' => 'Inactive'], 'is_active' => false, 'sort_order' => 2],
            [$this->makeKrCountrySetting()]
        );

        // When: 활성만 필터
        $response = $this->actingAs($this->adminUser)->getJson(
            $this->apiBase . '?is_active=true'
        );

        // Then
        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertTrue($response->json('data.data.0.is_active'));
    }

    // ──────────────────────────────────────────────
    // 2. Store (생성) 테스트
    // ──────────────────────────────────────────────

    /**
     * 배송정책 생성 - 단일 국가 (KR, fixed)
     */
    #[Test]
    public function test_store_single_country_fixed(): void
    {
        // Given
        $payload = $this->makeStorePayload([], [
            $this->makeKrCountrySetting([
                'charge_policy' => 'fixed',
                'base_fee' => 3000,
            ]),
        ]);

        // When
        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        // Then
        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name.ko', '테스트 배송정책')
            ->assertJsonPath('data.is_active', true);

        // country_settings 생성 확인
        $this->assertCount(1, $response->json('data.country_settings'));
        $this->assertEquals('KR', $response->json('data.country_settings.0.country_code'));
        $this->assertEquals('fixed', $response->json('data.country_settings.0.charge_policy'));
        $this->assertEquals(3000, $response->json('data.country_settings.0.base_fee'));

        // DB 확인
        $this->assertDatabaseHas('ecommerce_shipping_policies', [
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('ecommerce_shipping_policy_country_settings', [
            'country_code' => 'KR',
            'shipping_method' => 'parcel',
            'charge_policy' => 'fixed',
        ]);
    }

    /**
     * 배송정책 생성 - 다중 국가 (KR + US)
     */
    #[Test]
    public function test_store_multiple_countries(): void
    {
        // Given
        $payload = $this->makeStorePayload([], [
            $this->makeKrCountrySetting([
                'charge_policy' => 'fixed',
                'base_fee' => 3000,
            ]),
            $this->makeUsCountrySetting([
                'charge_policy' => 'conditional_free',
                'base_fee' => 15,
                'free_threshold' => 100,
            ]),
        ]);

        // When
        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        // Then
        $response->assertStatus(201);

        $countrySettings = $response->json('data.country_settings');
        $this->assertCount(2, $countrySettings);

        // KR 설정 확인
        $kr = collect($countrySettings)->firstWhere('country_code', 'KR');
        $this->assertEquals('fixed', $kr['charge_policy']);
        $this->assertEquals(3000, $kr['base_fee']);

        // US 설정 확인
        $us = collect($countrySettings)->firstWhere('country_code', 'US');
        $this->assertEquals('conditional_free', $us['charge_policy']);
        $this->assertEquals(15, $us['base_fee']);
        $this->assertEquals(100, $us['free_threshold']);

        // DB 확인: 국가별 설정 2건
        $policyId = $response->json('data.id');
        $this->assertEquals(2, ShippingPolicyCountrySetting::where('shipping_policy_id', $policyId)->count());
    }

    /**
     * 배송정책 생성 - free 부과정책 (base_fee 클리닝)
     */
    #[Test]
    public function test_store_free_charge_policy_cleans_base_fee(): void
    {
        // Given: free 정책에 base_fee를 보냄 (prepareForValidation이 0으로 정리)
        $payload = $this->makeStorePayload([], [
            $this->makeKrCountrySetting([
                'charge_policy' => 'free',
                'base_fee' => 5000,
            ]),
        ]);

        // When
        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        // Then: base_fee가 0으로 정리됨
        $response->assertStatus(201);
        $this->assertEquals(0, $response->json('data.country_settings.0.base_fee'));
    }

    /**
     * 배송정책 생성 - conditional_free 부과정책
     */
    #[Test]
    public function test_store_conditional_free_charge_policy(): void
    {
        // Given
        $payload = $this->makeStorePayload([], [
            $this->makeKrCountrySetting([
                'charge_policy' => 'conditional_free',
                'base_fee' => 3000,
                'free_threshold' => 50000,
            ]),
        ]);

        // When
        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        // Then
        $response->assertStatus(201);
        $cs = $response->json('data.country_settings.0');
        $this->assertEquals('conditional_free', $cs['charge_policy']);
        $this->assertEquals(3000, $cs['base_fee']);
        $this->assertEquals(50000, $cs['free_threshold']);
    }

    /**
     * 배송정책 생성 - range_amount 부과정책
     */
    #[Test]
    public function test_store_range_amount_charge_policy(): void
    {
        // Given
        $ranges = [
            'type' => 'amount',
            'tiers' => [
                ['min' => 0, 'max' => 29999, 'fee' => 3000, 'unit' => '원'],
                ['min' => 30000, 'max' => 49999, 'fee' => 2000, 'unit' => '원'],
                ['min' => 50000, 'max' => null, 'fee' => 0, 'unit' => '원'],
            ],
        ];

        $payload = $this->makeStorePayload([], [
            $this->makeKrCountrySetting([
                'charge_policy' => 'range_amount',
                'base_fee' => 0,
                'ranges' => $ranges,
            ]),
        ]);

        // When
        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        // Then
        $response->assertStatus(201);
        $cs = $response->json('data.country_settings.0');
        $this->assertEquals('range_amount', $cs['charge_policy']);
        $this->assertNotNull($cs['ranges']);
        $this->assertCount(3, $cs['ranges']['tiers']);
    }

    /**
     * 배송정책 생성 - range_quantity 부과정책
     */
    #[Test]
    public function test_store_range_quantity_charge_policy(): void
    {
        $ranges = [
            'type' => 'quantity',
            'tiers' => [
                ['min' => 0, 'max' => 4, 'fee' => 3000, 'unit' => '개'],
                ['min' => 5, 'max' => null, 'fee' => 5000, 'unit' => '개'],
            ],
        ];

        $payload = $this->makeStorePayload([], [
            $this->makeKrCountrySetting([
                'charge_policy' => 'range_quantity',
                'base_fee' => 0,
                'ranges' => $ranges,
            ]),
        ]);

        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        $response->assertStatus(201);
        $this->assertEquals('range_quantity', $response->json('data.country_settings.0.charge_policy'));
    }

    /**
     * 배송정책 생성 - range_weight 부과정책
     */
    #[Test]
    public function test_store_range_weight_charge_policy(): void
    {
        $ranges = [
            'type' => 'weight',
            'tiers' => [
                ['min' => 0, 'max' => 2, 'fee' => 3000, 'unit' => 'kg'],
                ['min' => 3, 'max' => null, 'fee' => 5000, 'unit' => 'kg'],
            ],
        ];

        $payload = $this->makeStorePayload([], [
            $this->makeKrCountrySetting([
                'charge_policy' => 'range_weight',
                'base_fee' => 0,
                'ranges' => $ranges,
            ]),
        ]);

        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        $response->assertStatus(201);
        $this->assertEquals('range_weight', $response->json('data.country_settings.0.charge_policy'));
    }

    /**
     * 배송정책 생성 - range_volume 부과정책
     */
    #[Test]
    public function test_store_range_volume_charge_policy(): void
    {
        $ranges = [
            'type' => 'volume',
            'tiers' => [
                ['min' => 0, 'max' => 99, 'fee' => 5000, 'unit' => 'cm³'],
                ['min' => 100, 'max' => null, 'fee' => 10000, 'unit' => 'cm³'],
            ],
        ];

        $payload = $this->makeStorePayload([], [
            $this->makeKrCountrySetting([
                'charge_policy' => 'range_volume',
                'base_fee' => 0,
                'ranges' => $ranges,
            ]),
        ]);

        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        $response->assertStatus(201);
        $this->assertEquals('range_volume', $response->json('data.country_settings.0.charge_policy'));
    }

    /**
     * 배송정책 생성 - range_volume_weight 부과정책
     */
    #[Test]
    public function test_store_range_volume_weight_charge_policy(): void
    {
        $ranges = [
            'type' => 'volume_weight',
            'tiers' => [
                ['min' => 0, 'max' => 4, 'fee' => 5000, 'unit' => 'kg'],
                ['min' => 5, 'max' => null, 'fee' => 10000, 'unit' => 'kg'],
            ],
        ];

        $payload = $this->makeStorePayload([], [
            $this->makeKrCountrySetting([
                'charge_policy' => 'range_volume_weight',
                'base_fee' => 0,
                'ranges' => $ranges,
            ]),
        ]);

        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        $response->assertStatus(201);
        $this->assertEquals('range_volume_weight', $response->json('data.country_settings.0.charge_policy'));
    }

    /**
     * 배송정책 생성 - api 부과정책
     */
    #[Test]
    public function test_store_api_charge_policy(): void
    {
        $payload = $this->makeStorePayload([], [
            $this->makeKrCountrySetting([
                'charge_policy' => 'api',
                'base_fee' => 0,
                'api_endpoint' => 'https://api.example.com/shipping/calculate',
                'api_request_fields' => ['weight', 'volume', 'zipcode'],
                'api_response_fee_field' => 'calculated_fee',
            ]),
        ]);

        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        $response->assertStatus(201);
        $cs = $response->json('data.country_settings.0');
        $this->assertEquals('api', $cs['charge_policy']);
        $this->assertEquals('https://api.example.com/shipping/calculate', $cs['api_endpoint']);
        $this->assertEquals(['weight', 'volume', 'zipcode'], $cs['api_request_fields']);
        $this->assertEquals('calculated_fee', $cs['api_response_fee_field']);
    }

    /**
     * 배송정책 생성 - per_quantity 부과정책
     */
    #[Test]
    public function test_store_per_quantity_charge_policy(): void
    {
        $payload = $this->makeStorePayload([], [
            $this->makeKrCountrySetting([
                'charge_policy' => 'per_quantity',
                'base_fee' => 500,
                'ranges' => ['type' => 'per_quantity', 'unit_value' => 1],
            ]),
        ]);

        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        $response->assertStatus(201);
        $this->assertEquals('per_quantity', $response->json('data.country_settings.0.charge_policy'));
        $this->assertEquals(500, $response->json('data.country_settings.0.base_fee'));
    }

    /**
     * 배송정책 생성 - per_weight 부과정책
     */
    #[Test]
    public function test_store_per_weight_charge_policy(): void
    {
        $payload = $this->makeStorePayload([], [
            $this->makeKrCountrySetting([
                'charge_policy' => 'per_weight',
                'base_fee' => 1000,
                'ranges' => ['type' => 'per_weight', 'unit_value' => 1],
            ]),
        ]);

        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        $response->assertStatus(201);
        $this->assertEquals('per_weight', $response->json('data.country_settings.0.charge_policy'));
    }

    /**
     * 배송정책 생성 - per_volume 부과정책
     */
    #[Test]
    public function test_store_per_volume_charge_policy(): void
    {
        $payload = $this->makeStorePayload([], [
            $this->makeKrCountrySetting([
                'charge_policy' => 'per_volume',
                'base_fee' => 2000,
                'ranges' => ['type' => 'per_volume', 'unit_value' => 10],
            ]),
        ]);

        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        $response->assertStatus(201);
        $this->assertEquals('per_volume', $response->json('data.country_settings.0.charge_policy'));
    }

    /**
     * 배송정책 생성 - per_volume_weight 부과정책
     */
    #[Test]
    public function test_store_per_volume_weight_charge_policy(): void
    {
        $payload = $this->makeStorePayload([], [
            $this->makeKrCountrySetting([
                'charge_policy' => 'per_volume_weight',
                'base_fee' => 1500,
                'ranges' => ['type' => 'per_volume_weight', 'unit_value' => 1],
            ]),
        ]);

        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        $response->assertStatus(201);
        $this->assertEquals('per_volume_weight', $response->json('data.country_settings.0.charge_policy'));
    }

    /**
     * 배송정책 생성 - per_amount 부과정책
     */
    #[Test]
    public function test_store_per_amount_charge_policy(): void
    {
        $payload = $this->makeStorePayload([], [
            $this->makeKrCountrySetting([
                'charge_policy' => 'per_amount',
                'base_fee' => 100,
                'ranges' => ['type' => 'per_amount', 'unit_value' => 10000],
            ]),
        ]);

        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        $response->assertStatus(201);
        $this->assertEquals('per_amount', $response->json('data.country_settings.0.charge_policy'));
    }

    /**
     * 배송정책 생성 - created_by가 현재 사용자 ID로 설정됨
     */
    #[Test]
    public function test_store_sets_created_by_to_current_user(): void
    {
        $payload = $this->makeStorePayload();

        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        $response->assertStatus(201);

        // DB에서 created_by 확인
        $policyId = $response->json('data.id');
        $policy = ShippingPolicy::find($policyId);
        $this->assertEquals($this->adminUser->id, $policy->created_by);
        $this->assertEquals($this->adminUser->id, $policy->updated_by);
    }

    // ──────────────────────────────────────────────
    // 2-1. Store 검증 (Validation) 테스트
    // ──────────────────────────────────────────────

    /**
     * 검증 실패 - country_settings 필수
     */
    #[Test]
    public function test_store_validation_country_settings_required(): void
    {
        // Given: country_settings 누락
        $payload = [
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'is_active' => true,
        ];

        // When
        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        // Then: 422 검증 실패
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['country_settings']);
    }

    /**
     * 검증 실패 - country_settings 빈 배열
     */
    #[Test]
    public function test_store_validation_country_settings_min_one(): void
    {
        // Given: country_settings 빈 배열
        $payload = $this->makeStorePayload([], []);
        $payload['country_settings'] = [];

        // When
        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        // Then: 422 검증 실패
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['country_settings']);
    }

    /**
     * 검증 실패 - 중복 country_code (distinct 규칙)
     */
    #[Test]
    public function test_store_validation_duplicate_country_code(): void
    {
        // Given: KR이 2번 중복
        $payload = $this->makeStorePayload([], [
            $this->makeKrCountrySetting(['charge_policy' => 'fixed', 'base_fee' => 3000]),
            $this->makeKrCountrySetting(['charge_policy' => 'free', 'base_fee' => 0]),
        ]);

        // When
        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        // Then: 422 검증 실패
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['country_settings.0.country_code']);
    }

    /**
     * 검증 실패 - 잘못된 shipping_method Enum 값
     */
    #[Test]
    public function test_store_validation_invalid_shipping_method(): void
    {
        // Given: 존재하지 않는 shipping_method
        $payload = $this->makeStorePayload([], [
            $this->makeKrCountrySetting(['shipping_method' => 'invalid_method']),
        ]);

        // When
        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        // Then: 422 검증 실패
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['country_settings.0.shipping_method']);
    }

    /**
     * 검증 실패 - 잘못된 charge_policy Enum 값
     */
    #[Test]
    public function test_store_validation_invalid_charge_policy(): void
    {
        // Given: 존재하지 않는 charge_policy
        $payload = $this->makeStorePayload([], [
            $this->makeKrCountrySetting(['charge_policy' => 'invalid_policy']),
        ]);

        // When
        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        // Then: 422 검증 실패
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['country_settings.0.charge_policy']);
    }

    /**
     * 검증 실패 - name 필수
     */
    #[Test]
    public function test_store_validation_name_required(): void
    {
        // Given: name 누락
        $payload = $this->makeStorePayload();
        unset($payload['name']);

        // When
        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        // Then: 422 검증 실패
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * 검증 실패 - 고정 배송비 정책에서 base_fee 0원
     */
    #[Test]
    public function test_store_validation_fixed_policy_base_fee_zero_rejected(): void
    {
        // Given: fixed 정책에 base_fee를 0으로 설정
        $payload = $this->makeStorePayload([], [
            $this->makeKrCountrySetting(['charge_policy' => 'fixed', 'base_fee' => 0]),
        ]);

        // When
        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        // Then: 422 검증 실패
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['country_settings.0.base_fee']);
    }

    /**
     * 검증 실패 - 조건부 무료 정책에서 base_fee 0원
     */
    #[Test]
    public function test_store_validation_conditional_free_policy_base_fee_zero_rejected(): void
    {
        // Given: conditional_free 정책에 base_fee를 0으로 설정
        $payload = $this->makeStorePayload([], [
            $this->makeKrCountrySetting([
                'charge_policy' => 'conditional_free',
                'base_fee' => 0,
                'free_threshold' => 50000,
            ]),
        ]);

        // When
        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        // Then: 422 검증 실패
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['country_settings.0.base_fee']);
    }

    /**
     * 검증 실패 - 단위당(per_quantity) 정책에서 base_fee 0원
     */
    #[Test]
    public function test_store_validation_per_quantity_policy_base_fee_zero_rejected(): void
    {
        // Given: per_quantity 정책에 base_fee를 0으로 설정
        $payload = $this->makeStorePayload([], [
            $this->makeKrCountrySetting([
                'charge_policy' => 'per_quantity',
                'base_fee' => 0,
                'ranges' => ['type' => 'quantity', 'unit_value' => 500, 'tiers' => null],
            ]),
        ]);

        // When
        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        // Then: 422 검증 실패
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['country_settings.0.base_fee']);
    }

    /**
     * 검증 성공 - 구간별 배송비(range_amount) 정책은 base_fee 0원 허용
     */
    #[Test]
    public function test_store_validation_range_policy_base_fee_zero_allowed(): void
    {
        // Given: range_amount 정책에 base_fee 0 (구간별은 예외)
        $payload = $this->makeStorePayload([], [
            $this->makeKrCountrySetting([
                'charge_policy' => 'range_amount',
                'base_fee' => 0,
                'ranges' => [
                    'type' => 'amount',
                    'tiers' => [
                        ['min' => 0, 'max' => 50000, 'fee' => 3000],
                        ['min' => 50001, 'max' => null, 'fee' => 0],
                    ],
                ],
            ]),
        ]);

        // When
        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        // Then: 생성 성공 (구간별은 0원 허용)
        $response->assertStatus(201);
    }

    /**
     * 검증 성공 - 무료배송(free) 정책은 base_fee 0원 허용
     */
    #[Test]
    public function test_store_validation_free_policy_base_fee_zero_allowed(): void
    {
        // Given: free 정책
        $payload = $this->makeStorePayload([], [
            $this->makeKrCountrySetting(['charge_policy' => 'free', 'base_fee' => 0]),
        ]);

        // When
        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        // Then: 생성 성공
        $response->assertStatus(201);
    }

    /**
     * 검증 실패 - 수정 시에도 비무료 정책 base_fee 0원 금지
     */
    #[Test]
    public function test_update_validation_fixed_policy_base_fee_zero_rejected(): void
    {
        // Given: 기존 정상 배송정책
        $policy = $this->createPolicyWithSettings(
            [],
            [$this->makeKrCountrySetting(['charge_policy' => 'fixed', 'base_fee' => 3000])]
        );

        // When: base_fee를 0으로 수정 시도
        $response = $this->actingAs($this->adminUser)->putJson(
            "{$this->apiBase}/{$policy->id}",
            $this->makeStorePayload([], [
                $this->makeKrCountrySetting(['charge_policy' => 'fixed', 'base_fee' => 0]),
            ])
        );

        // Then: 422 검증 실패
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['country_settings.0.base_fee']);
    }

    /**
     * 도서산간 추가배송비 - KR만 허용 (비KR 국가는 강제 비활성)
     */
    #[Test]
    public function test_store_extra_fee_forced_disabled_for_non_kr(): void
    {
        // Given: US에 extra_fee_enabled=true를 보냄
        $payload = $this->makeStorePayload([], [
            $this->makeUsCountrySetting([
                'extra_fee_enabled' => true,
                'extra_fee_settings' => [
                    ['zipcode' => '10001', 'fee' => 5000],
                ],
                'extra_fee_multiply' => true,
            ]),
        ]);

        // When
        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        // Then: 생성 성공하지만 extra_fee 비활성화
        $response->assertStatus(201);
        $cs = $response->json('data.country_settings.0');
        $this->assertFalse($cs['extra_fee_enabled']);
        $this->assertNull($cs['extra_fee_settings']);
        $this->assertFalse($cs['extra_fee_multiply']);
    }

    /**
     * 도서산간 추가배송비 - KR에서는 정상 활성
     */
    #[Test]
    public function test_store_extra_fee_enabled_for_kr(): void
    {
        // Given: KR에 extra_fee_enabled=true
        $payload = $this->makeStorePayload([], [
            $this->makeKrCountrySetting([
                'extra_fee_enabled' => true,
                'extra_fee_settings' => [
                    ['zipcode' => '63000-63999', 'fee' => 3000],
                ],
                'extra_fee_multiply' => false,
            ]),
        ]);

        // When
        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        // Then: extra_fee 설정 유지
        $response->assertStatus(201);
        $cs = $response->json('data.country_settings.0');
        $this->assertTrue($cs['extra_fee_enabled']);
        $this->assertNotNull($cs['extra_fee_settings']);
        $this->assertCount(1, $cs['extra_fee_settings']);
    }

    /**
     * 도서산간 추가배송비 - region 필드 저장 및 조회
     */
    #[Test]
    public function test_store_extra_fee_settings_with_region(): void
    {
        // Given: KR에 region 포함 extra_fee_settings
        $payload = $this->makeStorePayload([], [
            $this->makeKrCountrySetting([
                'extra_fee_enabled' => true,
                'extra_fee_settings' => [
                    ['zipcode' => '63000-63999', 'fee' => 3000, 'region' => '제주도'],
                    ['zipcode' => '23008-23010', 'fee' => 3000, 'region' => '인천 옹진 백령면'],
                ],
                'extra_fee_multiply' => false,
            ]),
        ]);

        // When
        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        // Then: region 필드가 저장되고 응답에 포함
        $response->assertStatus(201);
        $cs = $response->json('data.country_settings.0');
        $this->assertCount(2, $cs['extra_fee_settings']);
        $this->assertEquals('제주도', $cs['extra_fee_settings'][0]['region']);
        $this->assertEquals('인천 옹진 백령면', $cs['extra_fee_settings'][1]['region']);
    }

    /**
     * 도서산간 추가배송비 - region 없이도 저장 가능 (하위 호환)
     */
    #[Test]
    public function test_store_extra_fee_settings_without_region(): void
    {
        // Given: KR에 region 없는 기존 형식
        $payload = $this->makeStorePayload([], [
            $this->makeKrCountrySetting([
                'extra_fee_enabled' => true,
                'extra_fee_settings' => [
                    ['zipcode' => '63000-63999', 'fee' => 3000],
                ],
                'extra_fee_multiply' => false,
            ]),
        ]);

        // When
        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        // Then: region 없이도 저장 성공
        $response->assertStatus(201);
        $cs = $response->json('data.country_settings.0');
        $this->assertCount(1, $cs['extra_fee_settings']);
        $this->assertEquals('63000-63999', $cs['extra_fee_settings'][0]['zipcode']);
    }

    /**
     * fixed 정책일 때 API/Range 필드가 클리닝됨
     */
    #[Test]
    public function test_store_cleans_unnecessary_fields_for_fixed(): void
    {
        // Given: fixed 정책에 불필요한 ranges와 api_endpoint를 보냄
        $payload = $this->makeStorePayload([], [
            $this->makeKrCountrySetting([
                'charge_policy' => 'fixed',
                'base_fee' => 3000,
                'ranges' => ['type' => 'amount', 'tiers' => [['min' => 0, 'max' => 100, 'fee' => 1000]]],
                'api_endpoint' => 'https://api.example.com/calc',
                'api_request_fields' => ['weight'],
                'api_response_fee_field' => 'fee',
            ]),
        ]);

        // When
        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        // Then: 불필요한 필드가 null로 정리됨
        $response->assertStatus(201);
        $cs = $response->json('data.country_settings.0');
        $this->assertNull($cs['ranges']);
        $this->assertNull($cs['api_endpoint']);
        $this->assertNull($cs['api_request_fields']);
        $this->assertNull($cs['api_response_fee_field']);
    }

    // ──────────────────────────────────────────────
    // 3. Update (수정) 테스트
    // ──────────────────────────────────────────────

    /**
     * 배송정책 수정 - 국가별 설정 변경 (sync 패턴: 삭제+재생성)
     */
    #[Test]
    public function test_update_modifies_country_settings(): void
    {
        // Given: 기존 정책 생성
        $policy = $this->createPolicyWithSettings(
            ['name' => ['ko' => '기존정책', 'en' => 'Existing Policy']],
            [$this->makeKrCountrySetting(['charge_policy' => 'fixed', 'base_fee' => 3000])]
        );

        $oldSettingId = $policy->countrySettings->first()->id;

        // When: base_fee 변경
        $updatePayload = [
            'name' => ['ko' => '수정된정책', 'en' => 'Updated Policy'],
            'is_active' => true,
            'country_settings' => [
                $this->makeKrCountrySetting([
                    'charge_policy' => 'fixed',
                    'base_fee' => 5000,
                ]),
            ],
        ];

        $response = $this->actingAs($this->adminUser)->putJson(
            $this->apiBase . '/' . $policy->id,
            $updatePayload
        );

        // Then
        $response->assertOk()
            ->assertJsonPath('data.name.ko', '수정된정책')
            ->assertJsonPath('data.country_settings.0.base_fee', 5000);

        // 기존 설정 삭제 후 재생성되었으므로 ID가 다름
        $newSettingId = $response->json('data.country_settings.0.id');
        $this->assertNotEquals($oldSettingId, $newSettingId);

        // DB에서 기존 설정이 삭제됨 확인
        $this->assertDatabaseMissing('ecommerce_shipping_policy_country_settings', [
            'id' => $oldSettingId,
        ]);
    }

    /**
     * 배송정책 수정 - 국가 추가 (KR만 → KR+US)
     */
    #[Test]
    public function test_update_adds_country(): void
    {
        // Given: KR만 있는 정책
        $policy = $this->createPolicyWithSettings(
            ['name' => ['ko' => '국내전용', 'en' => 'Domestic Only']],
            [$this->makeKrCountrySetting()]
        );

        // When: KR+US로 변경
        $updatePayload = [
            'name' => ['ko' => '글로벌', 'en' => 'Global'],
            'is_active' => true,
            'country_settings' => [
                $this->makeKrCountrySetting(),
                $this->makeUsCountrySetting(),
            ],
        ];

        $response = $this->actingAs($this->adminUser)->putJson(
            $this->apiBase . '/' . $policy->id,
            $updatePayload
        );

        // Then
        $response->assertOk();
        $this->assertCount(2, $response->json('data.country_settings'));

        // DB 확인
        $this->assertEquals(2, ShippingPolicyCountrySetting::where('shipping_policy_id', $policy->id)->count());
    }

    /**
     * 배송정책 수정 - 국가 제거 (KR+US → KR만)
     */
    #[Test]
    public function test_update_removes_country(): void
    {
        // Given: KR+US 정책
        $policy = $this->createPolicyWithSettings(
            ['name' => ['ko' => '글로벌', 'en' => 'Global']],
            [$this->makeKrCountrySetting(), $this->makeUsCountrySetting()]
        );

        $this->assertEquals(2, $policy->countrySettings->count());

        // When: KR만으로 변경
        $updatePayload = [
            'name' => ['ko' => '국내전용', 'en' => 'Domestic Only'],
            'is_active' => true,
            'country_settings' => [
                $this->makeKrCountrySetting(),
            ],
        ];

        $response = $this->actingAs($this->adminUser)->putJson(
            $this->apiBase . '/' . $policy->id,
            $updatePayload
        );

        // Then
        $response->assertOk();
        $this->assertCount(1, $response->json('data.country_settings'));
        $this->assertEquals('KR', $response->json('data.country_settings.0.country_code'));

        // DB 확인: US 설정 삭제됨
        $this->assertEquals(1, ShippingPolicyCountrySetting::where('shipping_policy_id', $policy->id)->count());
    }

    /**
     * 배송정책 수정 - 존재하지 않는 ID
     */
    #[Test]
    public function test_update_nonexistent_policy_returns_404(): void
    {
        // When
        $response = $this->actingAs($this->adminUser)->putJson(
            $this->apiBase . '/99999',
            $this->makeStorePayload()
        );

        // Then
        $response->assertNotFound();
    }

    // ──────────────────────────────────────────────
    // 4. Show (상세 조회) 테스트
    // ──────────────────────────────────────────────

    /**
     * 배송정책 상세 조회 - country_settings 포함
     */
    #[Test]
    public function test_show_includes_country_settings_with_labels(): void
    {
        // Given: 다중 국가 정책
        $policy = $this->createPolicyWithSettings(
            ['name' => ['ko' => '상세조회테스트', 'en' => 'Show Test']],
            [
                $this->makeKrCountrySetting(['charge_policy' => 'fixed', 'base_fee' => 3000]),
                $this->makeUsCountrySetting(['charge_policy' => 'conditional_free', 'base_fee' => 15, 'free_threshold' => 100]),
            ]
        );

        // When
        $response = $this->actingAs($this->adminUser)->getJson(
            $this->apiBase . '/' . $policy->id
        );

        // Then
        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'name',
                    'name_localized',
                    'country_settings' => [
                        '*' => [
                            'id',
                            'country_code',
                            'shipping_method',
                            'shipping_method_label',
                            'currency_code',
                            'charge_policy',
                            'charge_policy_label',
                            'base_fee',
                            'free_threshold',
                            'ranges',
                            'api_endpoint',
                            'api_request_fields',
                            'api_response_fee_field',
                            'extra_fee_enabled',
                            'extra_fee_settings',
                            'extra_fee_multiply',
                            'is_active',
                        ],
                    ],
                    'fee_summary',
                    'countries_display',
                    'is_active',
                    'is_default',
                    'sort_order',
                    'created_at',
                    'updated_at',
                ],
            ]);

        // 라벨이 포함되어 있는지 확인
        $kr = collect($response->json('data.country_settings'))
            ->firstWhere('country_code', 'KR');

        $this->assertNotNull($kr['shipping_method_label']);
        $this->assertNotNull($kr['charge_policy_label']);
    }

    /**
     * 배송정책 상세 조회 - 존재하지 않는 ID
     */
    #[Test]
    public function test_show_nonexistent_policy_returns_404(): void
    {
        $response = $this->actingAs($this->adminUser)->getJson(
            $this->apiBase . '/99999'
        );

        $response->assertNotFound();
    }

    // ──────────────────────────────────────────────
    // 5. Delete (삭제) 테스트
    // ──────────────────────────────────────────────

    /**
     * 배송정책 삭제 - country_settings도 함께 삭제
     */
    #[Test]
    public function test_destroy_deletes_policy_and_country_settings(): void
    {
        // Given
        $policy = $this->createPolicyWithSettings(
            ['name' => ['ko' => '삭제테스트', 'en' => 'Delete Test']],
            [$this->makeKrCountrySetting(), $this->makeUsCountrySetting()]
        );

        $policyId = $policy->id;
        $this->assertEquals(2, ShippingPolicyCountrySetting::where('shipping_policy_id', $policyId)->count());

        // When
        $response = $this->actingAs($this->adminUser)->deleteJson(
            $this->apiBase . '/' . $policyId
        );

        // Then
        $response->assertOk()
            ->assertJsonPath('success', true);

        // DB 확인: 정책과 국가별 설정 모두 삭제
        $this->assertDatabaseMissing('ecommerce_shipping_policies', ['id' => $policyId]);
        $this->assertEquals(0, ShippingPolicyCountrySetting::where('shipping_policy_id', $policyId)->count());
    }

    /**
     * 배송정책 삭제 - 존재하지 않는 ID
     */
    #[Test]
    public function test_destroy_nonexistent_policy_returns_404(): void
    {
        $response = $this->actingAs($this->adminUser)->deleteJson(
            $this->apiBase . '/99999'
        );

        $response->assertNotFound();
    }

    // ──────────────────────────────────────────────
    // 6. Toggle Active (사용여부 토글) 테스트
    // ──────────────────────────────────────────────

    /**
     * 배송정책 사용여부 토글
     */
    #[Test]
    public function test_toggle_active(): void
    {
        // Given: 활성 정책
        $policy = $this->createPolicyWithSettings(
            ['name' => ['ko' => '토글테스트', 'en' => 'Toggle Test'], 'is_active' => true],
            [$this->makeKrCountrySetting()]
        );

        // When: 비활성으로 토글
        $response = $this->actingAs($this->adminUser)->patchJson(
            $this->apiBase . '/' . $policy->id . '/toggle-active'
        );

        // Then
        $response->assertOk()
            ->assertJsonPath('data.is_active', false);

        // When: 다시 활성으로 토글
        $response = $this->actingAs($this->adminUser)->patchJson(
            $this->apiBase . '/' . $policy->id . '/toggle-active'
        );

        $response->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    // ──────────────────────────────────────────────
    // 7. Bulk (일괄) 테스트
    // ──────────────────────────────────────────────

    /**
     * 배송정책 일괄 삭제 - country_settings도 함께 삭제
     */
    #[Test]
    public function test_bulk_destroy_deletes_policies_and_country_settings(): void
    {
        // Given: 3개 정책
        $policy1 = $this->createPolicyWithSettings(
            ['name' => ['ko' => '정책1', 'en' => 'Policy 1']],
            [$this->makeKrCountrySetting()]
        );
        $policy2 = $this->createPolicyWithSettings(
            ['name' => ['ko' => '정책2', 'en' => 'Policy 2'], 'sort_order' => 2],
            [$this->makeKrCountrySetting(), $this->makeUsCountrySetting()]
        );
        $policy3 = $this->createPolicyWithSettings(
            ['name' => ['ko' => '정책3', 'en' => 'Policy 3'], 'sort_order' => 3],
            [$this->makeKrCountrySetting()]
        );

        // When: 1, 2번 일괄 삭제
        $response = $this->actingAs($this->adminUser)->deleteJson(
            $this->apiBase . '/bulk',
            ['ids' => [$policy1->id, $policy2->id]]
        );

        // Then
        $response->assertOk()
            ->assertJsonPath('data.deleted_count', 2);

        // DB 확인: 1, 2번 삭제, 3번 유지
        $this->assertDatabaseMissing('ecommerce_shipping_policies', ['id' => $policy1->id]);
        $this->assertDatabaseMissing('ecommerce_shipping_policies', ['id' => $policy2->id]);
        $this->assertDatabaseHas('ecommerce_shipping_policies', ['id' => $policy3->id]);

        // country_settings도 삭제됨 확인
        $this->assertEquals(0, ShippingPolicyCountrySetting::where('shipping_policy_id', $policy1->id)->count());
        $this->assertEquals(0, ShippingPolicyCountrySetting::where('shipping_policy_id', $policy2->id)->count());
        $this->assertEquals(1, ShippingPolicyCountrySetting::where('shipping_policy_id', $policy3->id)->count());
    }

    /**
     * 배송정책 일괄 사용여부 변경
     */
    #[Test]
    public function test_bulk_toggle_active(): void
    {
        // Given
        $policy1 = $this->createPolicyWithSettings(
            ['name' => ['ko' => '정책1', 'en' => 'Policy 1'], 'is_active' => true],
            [$this->makeKrCountrySetting()]
        );
        $policy2 = $this->createPolicyWithSettings(
            ['name' => ['ko' => '정책2', 'en' => 'Policy 2'], 'is_active' => true, 'sort_order' => 2],
            [$this->makeKrCountrySetting()]
        );

        // When: 일괄 비활성화
        $response = $this->actingAs($this->adminUser)->patchJson(
            $this->apiBase . '/bulk-toggle-active',
            ['ids' => [$policy1->id, $policy2->id], 'is_active' => false]
        );

        // Then
        $response->assertOk()
            ->assertJsonPath('data.updated_count', 2);

        // DB 확인
        $this->assertDatabaseHas('ecommerce_shipping_policies', ['id' => $policy1->id, 'is_active' => false]);
        $this->assertDatabaseHas('ecommerce_shipping_policies', ['id' => $policy2->id, 'is_active' => false]);
    }

    // ──────────────────────────────────────────────
    // 8. Set Default (기본 정책 설정) 테스트
    // ──────────────────────────────────────────────

    /**
     * 기본 배송정책 설정 - 기존 기본값 해제
     */
    #[Test]
    public function test_set_default_clears_previous_default(): void
    {
        // Given: 기본 정책
        $oldDefault = $this->createPolicyWithSettings(
            ['name' => ['ko' => '기존기본', 'en' => 'Old Default'], 'is_default' => true],
            [$this->makeKrCountrySetting()]
        );

        $newDefault = $this->createPolicyWithSettings(
            ['name' => ['ko' => '새기본', 'en' => 'New Default'], 'is_default' => false, 'sort_order' => 2],
            [$this->makeKrCountrySetting()]
        );

        // When: 새 정책을 기본으로 설정
        $response = $this->actingAs($this->adminUser)->patchJson(
            $this->apiBase . '/' . $newDefault->id . '/set-default'
        );

        // Then
        $response->assertOk()
            ->assertJsonPath('data.is_default', true);

        // 기존 기본값 해제 확인
        $this->assertDatabaseHas('ecommerce_shipping_policies', [
            'id' => $oldDefault->id,
            'is_default' => false,
        ]);

        // 새 기본값 설정 확인
        $this->assertDatabaseHas('ecommerce_shipping_policies', [
            'id' => $newDefault->id,
            'is_default' => true,
        ]);
    }

    // ──────────────────────────────────────────────
    // 9. Active List (활성 목록) 테스트
    // ──────────────────────────────────────────────

    /**
     * 활성 배송정책 목록 - Select 옵션용
     */
    #[Test]
    public function test_active_list_returns_only_active_policies(): void
    {
        // Given
        $this->createPolicyWithSettings(
            ['name' => ['ko' => '활성1', 'en' => 'Active 1'], 'is_active' => true],
            [$this->makeKrCountrySetting()]
        );
        $this->createPolicyWithSettings(
            ['name' => ['ko' => '비활성', 'en' => 'Inactive'], 'is_active' => false, 'sort_order' => 2],
            [$this->makeKrCountrySetting()]
        );
        $this->createPolicyWithSettings(
            ['name' => ['ko' => '활성2', 'en' => 'Active 2'], 'is_active' => true, 'sort_order' => 3],
            [$this->makeKrCountrySetting()]
        );

        // When
        $response = $this->actingAs($this->adminUser)->getJson(
            $this->apiBase . '/active'
        );

        // Then: 활성 정책만 반환
        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data);

        // 각 항목에 필요한 필드 포함 확인
        $this->assertArrayHasKey('value', $data[0]);
        $this->assertArrayHasKey('label', $data[0]);
        $this->assertArrayHasKey('countries_display', $data[0]);
        $this->assertArrayHasKey('fee_summary', $data[0]);
        $this->assertArrayHasKey('is_default', $data[0]);
    }

    // ──────────────────────────────────────────────
    // 10. 권한 (Permission) 테스트
    // ──────────────────────────────────────────────

    /**
     * 권한 없는 사용자 - 목록 조회 거부
     */
    #[Test]
    public function test_unauthorized_user_cannot_access_index(): void
    {
        // Given: 권한 없는 관리자
        $noPermUser = $this->createAdminUser([]);

        // When
        $response = $this->actingAs($noPermUser)->getJson($this->apiBase);

        // Then: 403 Forbidden
        $response->assertForbidden();
    }

    /**
     * 권한 없는 사용자 - 생성 거부
     */
    #[Test]
    public function test_unauthorized_user_cannot_store(): void
    {
        // Given: read 권한만 있는 관리자
        $readOnlyUser = $this->createAdminUser([
            'sirsoft-ecommerce.shipping-policies.read',
        ]);

        // When
        $response = $this->actingAs($readOnlyUser)->postJson(
            $this->apiBase,
            $this->makeStorePayload()
        );

        // Then: 403 Forbidden
        $response->assertForbidden();
    }

    /**
     * 인증되지 않은 사용자 - 접근 거부
     */
    #[Test]
    public function test_unauthenticated_user_cannot_access(): void
    {
        $response = $this->getJson($this->apiBase);

        $response->assertUnauthorized();
    }

    // ──────────────────────────────────────────────
    // 11. 다중 국가 + 다른 부과정책 조합 테스트
    // ──────────────────────────────────────────────

    /**
     * KR(fixed) + US(conditional_free) 조합 생성 후 개별 확인
     */
    #[Test]
    public function test_multi_country_different_charge_policies(): void
    {
        // Given
        $payload = $this->makeStorePayload(
            ['name' => ['ko' => '복합정책', 'en' => 'Mixed Policy']],
            [
                $this->makeKrCountrySetting([
                    'charge_policy' => 'fixed',
                    'base_fee' => 3000,
                    'extra_fee_enabled' => true,
                    'extra_fee_settings' => [
                        ['zipcode' => '63000-63999', 'fee' => 3000],
                    ],
                ]),
                $this->makeUsCountrySetting([
                    'charge_policy' => 'conditional_free',
                    'base_fee' => 20,
                    'free_threshold' => 150,
                ]),
            ]
        );

        // When
        $response = $this->actingAs($this->adminUser)->postJson($this->apiBase, $payload);

        // Then
        $response->assertStatus(201);

        $countrySettings = $response->json('data.country_settings');
        $this->assertCount(2, $countrySettings);

        // KR 확인
        $kr = collect($countrySettings)->firstWhere('country_code', 'KR');
        $this->assertEquals('fixed', $kr['charge_policy']);
        $this->assertEquals(3000, $kr['base_fee']);
        $this->assertTrue($kr['extra_fee_enabled']);
        $this->assertNotNull($kr['extra_fee_settings']);

        // US 확인
        $us = collect($countrySettings)->firstWhere('country_code', 'US');
        $this->assertEquals('conditional_free', $us['charge_policy']);
        $this->assertEquals(20, $us['base_fee']);
        $this->assertEquals(150, $us['free_threshold']);
        $this->assertFalse($us['extra_fee_enabled']); // 비KR → 강제 비활성
    }

    /**
     * 다중 필터 조합 - shipping_method + charge_policy
     */
    #[Test]
    public function test_index_combined_filters(): void
    {
        // Given: parcel + fixed
        $this->createPolicyWithSettings(
            ['name' => ['ko' => '택배고정', 'en' => 'Parcel Fixed']],
            [$this->makeKrCountrySetting(['shipping_method' => 'parcel', 'charge_policy' => 'fixed'])]
        );

        // Given: quick + fixed
        $this->createPolicyWithSettings(
            ['name' => ['ko' => '퀵고정', 'en' => 'Quick Fixed'], 'sort_order' => 2],
            [$this->makeKrCountrySetting(['shipping_method' => 'quick', 'charge_policy' => 'fixed'])]
        );

        // Given: parcel + free
        $this->createPolicyWithSettings(
            ['name' => ['ko' => '택배무료', 'en' => 'Parcel Free'], 'sort_order' => 3],
            [$this->makeKrCountrySetting(['shipping_method' => 'parcel', 'charge_policy' => 'free', 'base_fee' => 0])]
        );

        // When: parcel + fixed 필터
        $response = $this->actingAs($this->adminUser)->getJson(
            $this->apiBase . '?shipping_methods[]=parcel&charge_policies[]=fixed'
        );

        // Then: parcel + fixed인 정책만 반환 (1건)
        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
    }
}
