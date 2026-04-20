<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Models;

use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicyCountrySetting;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * ShippingPolicy 모델 테스트
 *
 * country_settings 아키텍처 반영:
 * - ShippingPolicy: name, is_active, sort_order 등 정책 메타데이터만 보유
 * - ShippingPolicyCountrySetting: shipping_method, charge_policy, base_fee 등 국가별 설정 보유
 */
class ShippingPolicyTest extends ModuleTestCase
{
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
            'sort_order' => 1,
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
    // 모델 생성 테스트
    // ========================================

    public function test_shipping_policy_can_be_created(): void
    {
        $policy = $this->createPolicyWithSettings([
            'name' => ['ko' => '테스트 무료배송', 'en' => 'Test Free Shipping'],
        ], [$this->makeKrCountrySetting(['charge_policy' => 'free', 'base_fee' => 0])]);

        $this->assertDatabaseHas('ecommerce_shipping_policies', [
            'id' => $policy->id,
        ]);
    }

    public function test_country_setting_casts_enums_correctly(): void
    {
        $policy = $this->createPolicyWithSettings([
            'name' => ['ko' => '테스트', 'en' => 'Test'],
        ]);

        $countrySetting = $policy->countrySettings->first();
        $freshSetting = $countrySetting->fresh();

        $this->assertIsString($freshSetting->shipping_method);
        $this->assertEquals('parcel', $freshSetting->shipping_method);
        $this->assertInstanceOf(ChargePolicyEnum::class, $freshSetting->charge_policy);
    }

    // ========================================
    // getLocalizedName() 테스트
    // ========================================

    public function test_get_localized_name_returns_current_locale(): void
    {
        $policy = $this->createPolicyWithSettings([
            'name' => ['ko' => '국내배송', 'en' => 'Domestic'],
        ]);

        app()->setLocale('ko');
        $this->assertEquals('국내배송', $policy->getLocalizedName());

        app()->setLocale('en');
        $this->assertEquals('Domestic', $policy->getLocalizedName());
    }

    public function test_get_localized_name_with_specific_locale(): void
    {
        $policy = $this->createPolicyWithSettings([
            'name' => ['ko' => '국내배송', 'en' => 'Domestic'],
        ]);

        $this->assertEquals('국내배송', $policy->getLocalizedName('ko'));
        $this->assertEquals('Domestic', $policy->getLocalizedName('en'));
    }

    // ========================================
    // getFeeSummary() 테스트 (countrySettings 기반)
    // ========================================

    public function test_get_fee_summary_free(): void
    {
        $policy = $this->createPolicyWithSettings(
            ['name' => ['ko' => '무료', 'en' => 'Free']],
            [$this->makeKrCountrySetting(['charge_policy' => 'free', 'base_fee' => 0])]
        );

        $summary = $policy->getFeeSummary();

        $this->assertIsString($summary);
        $this->assertNotEmpty($summary);
    }

    public function test_get_fee_summary_fixed(): void
    {
        $policy = $this->createPolicyWithSettings(
            ['name' => ['ko' => '고정', 'en' => 'Fixed']],
            [$this->makeKrCountrySetting(['charge_policy' => 'fixed', 'base_fee' => 3000])]
        );

        $summary = $policy->getFeeSummary();

        $this->assertIsString($summary);
        $this->assertNotEmpty($summary);
    }

    public function test_get_fee_summary_per_quantity(): void
    {
        $policy = $this->createPolicyWithSettings(
            ['name' => ['ko' => '수량당', 'en' => 'Per Qty']],
            [$this->makeKrCountrySetting([
                'charge_policy' => 'per_quantity',
                'base_fee' => 3000,
                'ranges' => ['unit_value' => 3],
            ])]
        );

        $summary = $policy->getFeeSummary();

        $this->assertIsString($summary);
        $this->assertNotEmpty($summary);
    }

    public function test_get_fee_summary_per_weight(): void
    {
        $policy = $this->createPolicyWithSettings(
            ['name' => ['ko' => '무게당', 'en' => 'Per Weight']],
            [$this->makeKrCountrySetting([
                'charge_policy' => 'per_weight',
                'base_fee' => 1000,
                'ranges' => ['unit_value' => 1],
            ])]
        );

        $summary = $policy->getFeeSummary();

        $this->assertIsString($summary);
        $this->assertNotEmpty($summary);
    }

    public function test_get_fee_summary_per_volume(): void
    {
        $policy = $this->createPolicyWithSettings(
            ['name' => ['ko' => '부피당', 'en' => 'Per Volume']],
            [$this->makeKrCountrySetting([
                'shipping_method' => 'direct',
                'charge_policy' => 'per_volume',
                'base_fee' => 2000,
                'ranges' => ['unit_value' => 10],
            ])]
        );

        $summary = $policy->getFeeSummary();

        $this->assertIsString($summary);
        $this->assertNotEmpty($summary);
    }

    public function test_get_fee_summary_per_volume_weight(): void
    {
        $policy = $this->createPolicyWithSettings(
            ['name' => ['ko' => '부피무게당', 'en' => 'Per Vol Weight']],
            [$this->makeKrCountrySetting([
                'charge_policy' => 'per_volume_weight',
                'base_fee' => 3000,
                'ranges' => ['unit_value' => 5],
            ])]
        );

        $summary = $policy->getFeeSummary();

        $this->assertIsString($summary);
        $this->assertNotEmpty($summary);
    }

    public function test_get_fee_summary_per_amount(): void
    {
        $policy = $this->createPolicyWithSettings(
            ['name' => ['ko' => '금액당', 'en' => 'Per Amount']],
            [$this->makeKrCountrySetting([
                'charge_policy' => 'per_amount',
                'base_fee' => 500,
                'ranges' => ['unit_value' => 10000],
            ])]
        );

        $summary = $policy->getFeeSummary();

        $this->assertIsString($summary);
        $this->assertNotEmpty($summary);
    }

    public function test_get_fee_summary_per_unit_without_ranges_uses_default(): void
    {
        $policy = $this->createPolicyWithSettings(
            ['name' => ['ko' => '수량당', 'en' => 'Per Qty']],
            [$this->makeKrCountrySetting([
                'charge_policy' => 'per_quantity',
                'base_fee' => 3000,
                'ranges' => null,
            ])]
        );

        // ranges가 null이어도 unit_value 기본값 1로 처리
        $summary = $policy->getFeeSummary();

        $this->assertIsString($summary);
        $this->assertNotEmpty($summary);
    }

    // ========================================
    // 스코프 테스트
    // ========================================

    public function test_active_scope_returns_only_active_policies(): void
    {
        $this->createPolicyWithSettings([
            'name' => ['ko' => '활성', 'en' => 'Active'],
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->createPolicyWithSettings([
            'name' => ['ko' => '비활성', 'en' => 'Inactive'],
            'is_active' => false,
            'sort_order' => 2,
        ]);

        $activePolicies = ShippingPolicy::active()->get();

        $this->assertCount(1, $activePolicies);
        $this->assertTrue($activePolicies->first()->is_active);
    }
}
