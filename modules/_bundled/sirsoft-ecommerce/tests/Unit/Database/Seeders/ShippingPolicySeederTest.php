<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Database\Seeders;

use Modules\Sirsoft\Ecommerce\Database\Seeders\Sample\ShippingPolicySeeder;
use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicyCountrySetting;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 배송정책 시더 테스트
 *
 * 배송정책의 국가별 설정(charge_policy, base_fee 등)은
 * ShippingPolicyCountrySetting 테이블에서 관리됩니다.
 */
class ShippingPolicySeederTest extends ModuleTestCase
{
    /**
     * 시더 실행
     */
    protected function runSeeder(): void
    {
        $this->seed(ShippingPolicySeeder::class);
    }

    // ========================================
    // 기본 생성 테스트
    // ========================================

    public function test_seeder_creates_15_policies(): void
    {
        $this->runSeeder();

        $this->assertEquals(15, ShippingPolicy::count());
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->runSeeder();
        $this->runSeeder();

        $this->assertEquals(15, ShippingPolicy::count());
    }

    // ========================================
    // charge_policy 커버리지 테스트 (countrySettings 기반)
    // ========================================

    public function test_seeder_covers_all_charge_policies(): void
    {
        $this->runSeeder();

        foreach (ChargePolicyEnum::values() as $value) {
            $this->assertTrue(
                ShippingPolicyCountrySetting::where('charge_policy', $value)->exists(),
                "charge_policy '{$value}' 정책이 시더에 포함되어야 합니다."
            );
        }
    }

    public function test_seeder_creates_free_policy(): void
    {
        $this->runSeeder();

        $countrySetting = ShippingPolicyCountrySetting::where('charge_policy', 'free')->first();

        $this->assertNotNull($countrySetting);
        $this->assertEquals(0, (float) $countrySetting->base_fee);

        $policy = $countrySetting->shippingPolicy;
        $this->assertTrue($policy->is_active);
    }

    public function test_seeder_creates_fixed_policies(): void
    {
        $this->runSeeder();

        $fixedSettings = ShippingPolicyCountrySetting::where('charge_policy', 'fixed')->get();

        // fixed 정책은 2건 (국내 택배, 퀵서비스)
        $this->assertEquals(2, $fixedSettings->count());
    }

    public function test_seeder_creates_conditional_free_policy(): void
    {
        $this->runSeeder();

        $countrySetting = ShippingPolicyCountrySetting::where('charge_policy', 'conditional_free')->first();

        $this->assertNotNull($countrySetting);
        $this->assertGreaterThan(0, (float) $countrySetting->base_fee);
        $this->assertGreaterThan(0, (float) $countrySetting->free_threshold);
    }

    public function test_seeder_creates_api_policy(): void
    {
        $this->runSeeder();

        $countrySetting = ShippingPolicyCountrySetting::where('charge_policy', 'api')->first();

        $this->assertNotNull($countrySetting);
        $this->assertNotNull($countrySetting->api_endpoint);
    }

    // ========================================
    // 구간별(range_*) 정책 테스트 (countrySettings 기반)
    // ========================================

    public function test_seeder_creates_range_policies_with_tiers(): void
    {
        $this->runSeeder();

        $rangePolicies = [
            'range_amount' => 5,
            'range_quantity' => 2,
            'range_weight' => 4,
            'range_volume' => 3,
            'range_volume_weight' => 4,
        ];

        foreach ($rangePolicies as $chargePolicy => $expectedTiers) {
            $countrySetting = ShippingPolicyCountrySetting::where('charge_policy', $chargePolicy)->first();

            $this->assertNotNull($countrySetting, "{$chargePolicy} 정책이 존재해야 합니다.");
            $this->assertIsArray($countrySetting->ranges, "{$chargePolicy} 정책의 ranges가 배열이어야 합니다.");
            $this->assertArrayHasKey('tiers', $countrySetting->ranges, "{$chargePolicy} 정책에 tiers가 있어야 합니다.");
            $this->assertCount(
                $expectedTiers,
                $countrySetting->ranges['tiers'],
                "{$chargePolicy} 정책의 구간 수가 {$expectedTiers}이어야 합니다."
            );
        }
    }

    public function test_seeder_range_tiers_have_correct_structure(): void
    {
        $this->runSeeder();

        $countrySetting = ShippingPolicyCountrySetting::where('charge_policy', 'range_amount')->first();
        $tier = $countrySetting->ranges['tiers'][0];

        $this->assertArrayHasKey('min', $tier);
        $this->assertArrayHasKey('max', $tier);
        $this->assertArrayHasKey('unit', $tier);
        $this->assertArrayHasKey('fee', $tier);
    }

    // ========================================
    // 단위당(per_*) 정책 테스트 (countrySettings 기반)
    // ========================================

    public function test_seeder_creates_five_per_unit_policies(): void
    {
        $this->runSeeder();

        $perUnitSettings = ShippingPolicyCountrySetting::whereIn('charge_policy', [
            'per_quantity',
            'per_weight',
            'per_volume',
            'per_volume_weight',
            'per_amount',
        ])->get();

        $this->assertCount(5, $perUnitSettings);
    }

    public function test_seeder_per_unit_policies_have_unit_value(): void
    {
        $this->runSeeder();

        $expectedValues = [
            'per_quantity' => 3,
            'per_weight' => 1,
            'per_volume' => 10,
            'per_volume_weight' => 5,
            'per_amount' => 10000,
        ];

        foreach ($expectedValues as $chargePolicy => $expectedUnit) {
            $countrySetting = ShippingPolicyCountrySetting::where('charge_policy', $chargePolicy)->first();

            $this->assertNotNull($countrySetting, "{$chargePolicy} 정책이 존재해야 합니다.");
            $this->assertIsArray($countrySetting->ranges, "{$chargePolicy} 정책의 ranges가 배열이어야 합니다.");
            $this->assertArrayHasKey('unit_value', $countrySetting->ranges, "{$chargePolicy} 정책에 unit_value가 있어야 합니다.");
            $this->assertEquals(
                $expectedUnit,
                $countrySetting->ranges['unit_value'],
                "{$chargePolicy} 정책의 unit_value가 {$expectedUnit}이어야 합니다."
            );
        }
    }

    public function test_seeder_per_unit_policies_have_base_fee(): void
    {
        $this->runSeeder();

        foreach (ChargePolicyEnum::perUnitPolicies() as $policy) {
            $countrySetting = ShippingPolicyCountrySetting::where('charge_policy', $policy->value)->first();

            $this->assertNotNull($countrySetting, "{$policy->value} 정책이 존재해야 합니다.");
            $this->assertGreaterThan(
                0,
                (float) $countrySetting->base_fee,
                "{$policy->value} 정책의 base_fee가 0보다 커야 합니다."
            );
        }
    }

    // ========================================
    // 다국어 name 필드 테스트
    // ========================================

    public function test_seeder_policies_have_multilingual_name(): void
    {
        $this->runSeeder();

        $policies = ShippingPolicy::all();

        foreach ($policies as $policy) {
            $this->assertIsArray($policy->name, "정책 #{$policy->id}의 name이 배열이어야 합니다.");
            $this->assertArrayHasKey('ko', $policy->name, "정책 #{$policy->id}에 ko 이름이 있어야 합니다.");
            $this->assertArrayHasKey('en', $policy->name, "정책 #{$policy->id}에 en 이름이 있어야 합니다.");
            $this->assertNotEmpty($policy->name['ko'], "정책 #{$policy->id}의 ko 이름이 비어있으면 안됩니다.");
            $this->assertNotEmpty($policy->name['en'], "정책 #{$policy->id}의 en 이름이 비어있으면 안됩니다.");
        }
    }

    // ========================================
    // extra_fee 설정 테스트 (countrySettings 기반)
    // ========================================

    public function test_seeder_creates_policies_with_extra_fee(): void
    {
        $this->runSeeder();

        $withExtraFee = ShippingPolicyCountrySetting::where('extra_fee_enabled', true)->get();

        $this->assertEquals(2, $withExtraFee->count());

        foreach ($withExtraFee as $countrySetting) {
            $this->assertNotNull($countrySetting->extra_fee_settings);
            $this->assertIsArray($countrySetting->extra_fee_settings);
            $this->assertNotEmpty($countrySetting->extra_fee_settings);

            // 각 추가배송비 설정에 zipcode와 fee가 있는지 확인
            foreach ($countrySetting->extra_fee_settings as $setting) {
                $this->assertArrayHasKey('zipcode', $setting);
                $this->assertArrayHasKey('fee', $setting);
            }
        }
    }

    // ========================================
    // is_active 테스트
    // ========================================

    public function test_seeder_creates_one_inactive_policy(): void
    {
        $this->runSeeder();

        $inactivePolicies = ShippingPolicy::where('is_active', false)->get();

        $this->assertCount(1, $inactivePolicies);

        // 비활성 정책의 국가별 설정에서 quick 배송방법 확인
        $countrySetting = $inactivePolicies->first()->countrySettings()->first();
        $this->assertNotNull($countrySetting);
        $this->assertEquals('quick', $countrySetting->shipping_method);
    }

    // ========================================
    // 해외배송 테스트 (countrySettings 기반)
    // ========================================

    public function test_seeder_creates_international_shipping_policy(): void
    {
        $this->runSeeder();

        $usSetting = ShippingPolicyCountrySetting::where('currency_code', 'USD')
            ->where('country_code', 'US')
            ->first();

        $this->assertNotNull($usSetting);
        $this->assertEquals('api', $usSetting->charge_policy->value);
    }

    // ========================================
    // sort_order 테스트
    // ========================================

    public function test_seeder_policies_have_sequential_sort_order(): void
    {
        $this->runSeeder();

        $sortOrders = ShippingPolicy::orderBy('sort_order')->pluck('sort_order')->toArray();

        $this->assertEquals(range(1, 15), $sortOrders);
    }

    // ========================================
    // 국가별 설정 (countrySettings) 테스트
    // ========================================

    public function test_seeder_creates_country_settings_for_each_policy(): void
    {
        $this->runSeeder();

        $policies = ShippingPolicy::with('countrySettings')->get();

        foreach ($policies as $policy) {
            $this->assertTrue(
                $policy->countrySettings->isNotEmpty(),
                "정책 #{$policy->id} ({$policy->getLocalizedName()})에 국가별 설정이 있어야 합니다."
            );
        }
    }

    public function test_seeder_international_policy_has_multiple_countries(): void
    {
        $this->runSeeder();

        // 해외배송 (DHL) 정책은 US, CN, JP 3개 국가 설정
        $dhlPolicy = ShippingPolicy::whereJsonContains('name->en', 'International Shipping (DHL)')->first();
        $this->assertNotNull($dhlPolicy);

        $countryCodes = $dhlPolicy->countrySettings->pluck('country_code')->toArray();
        $this->assertContains('US', $countryCodes);
        $this->assertContains('CN', $countryCodes);
        $this->assertContains('JP', $countryCodes);
    }

    public function test_seeder_domestic_intl_combined_policy_has_two_countries(): void
    {
        $this->runSeeder();

        // 국내외 복합 배송 정책은 KR, US 2개 국가 설정
        $combinedPolicy = ShippingPolicy::whereJsonContains('name->en', 'Domestic & Intl (Per Volume Weight)')->first();
        $this->assertNotNull($combinedPolicy);

        $countryCodes = $combinedPolicy->countrySettings->pluck('country_code')->toArray();
        $this->assertContains('KR', $countryCodes);
        $this->assertContains('US', $countryCodes);
    }
}
