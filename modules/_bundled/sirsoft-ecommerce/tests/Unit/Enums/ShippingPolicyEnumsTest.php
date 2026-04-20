<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Enums;

use Modules\Sirsoft\Ecommerce\Enums\ChargePolicyEnum;
use Modules\Sirsoft\Ecommerce\Enums\ShippingCountryEnum;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 배송정책 관련 Enum 테스트
 */
class ShippingPolicyEnumsTest extends ModuleTestCase
{
    // ========================================
    // ChargePolicyEnum 테스트
    // ========================================

    public function test_charge_policy_has_correct_values(): void
    {
        $this->assertEquals('free', ChargePolicyEnum::FREE->value);
        $this->assertEquals('fixed', ChargePolicyEnum::FIXED->value);
        $this->assertEquals('conditional_free', ChargePolicyEnum::CONDITIONAL_FREE->value);
        $this->assertEquals('range_amount', ChargePolicyEnum::RANGE_AMOUNT->value);
        $this->assertEquals('range_quantity', ChargePolicyEnum::RANGE_QUANTITY->value);
        $this->assertEquals('range_weight', ChargePolicyEnum::RANGE_WEIGHT->value);
        $this->assertEquals('range_volume', ChargePolicyEnum::RANGE_VOLUME->value);
        $this->assertEquals('range_volume_weight', ChargePolicyEnum::RANGE_VOLUME_WEIGHT->value);
        $this->assertEquals('api', ChargePolicyEnum::API->value);
        $this->assertEquals('per_quantity', ChargePolicyEnum::PER_QUANTITY->value);
        $this->assertEquals('per_weight', ChargePolicyEnum::PER_WEIGHT->value);
        $this->assertEquals('per_volume', ChargePolicyEnum::PER_VOLUME->value);
        $this->assertEquals('per_volume_weight', ChargePolicyEnum::PER_VOLUME_WEIGHT->value);
        $this->assertEquals('per_amount', ChargePolicyEnum::PER_AMOUNT->value);
    }

    public function test_charge_policy_values_returns_all_values(): void
    {
        $values = ChargePolicyEnum::values();

        $this->assertIsArray($values);
        $this->assertCount(14, $values);
        $this->assertContains('free', $values);
        $this->assertContains('fixed', $values);
        $this->assertContains('conditional_free', $values);
        $this->assertContains('range_amount', $values);
        $this->assertContains('api', $values);
        $this->assertContains('per_quantity', $values);
        $this->assertContains('per_weight', $values);
        $this->assertContains('per_volume', $values);
        $this->assertContains('per_volume_weight', $values);
        $this->assertContains('per_amount', $values);
    }

    public function test_charge_policy_to_select_options_returns_array(): void
    {
        $options = ChargePolicyEnum::toSelectOptions();

        $this->assertIsArray($options);
        $this->assertCount(14, $options);

        foreach ($options as $option) {
            $this->assertArrayHasKey('value', $option);
            $this->assertArrayHasKey('label', $option);
        }
    }

    public function test_charge_policy_requires_base_fee(): void
    {
        $this->assertTrue(ChargePolicyEnum::FIXED->requiresBaseFee());
        $this->assertTrue(ChargePolicyEnum::CONDITIONAL_FREE->requiresBaseFee());
        $this->assertTrue(ChargePolicyEnum::PER_QUANTITY->requiresBaseFee());
        $this->assertTrue(ChargePolicyEnum::PER_WEIGHT->requiresBaseFee());
        $this->assertTrue(ChargePolicyEnum::PER_VOLUME->requiresBaseFee());
        $this->assertTrue(ChargePolicyEnum::PER_VOLUME_WEIGHT->requiresBaseFee());
        $this->assertTrue(ChargePolicyEnum::PER_AMOUNT->requiresBaseFee());
        $this->assertFalse(ChargePolicyEnum::FREE->requiresBaseFee());
        $this->assertFalse(ChargePolicyEnum::RANGE_AMOUNT->requiresBaseFee());
        $this->assertFalse(ChargePolicyEnum::API->requiresBaseFee());
    }

    public function test_charge_policy_requires_free_threshold(): void
    {
        $this->assertTrue(ChargePolicyEnum::CONDITIONAL_FREE->requiresFreeThreshold());
        $this->assertFalse(ChargePolicyEnum::FREE->requiresFreeThreshold());
        $this->assertFalse(ChargePolicyEnum::FIXED->requiresFreeThreshold());
        $this->assertFalse(ChargePolicyEnum::RANGE_AMOUNT->requiresFreeThreshold());
    }

    public function test_charge_policy_requires_ranges(): void
    {
        $this->assertTrue(ChargePolicyEnum::RANGE_AMOUNT->requiresRanges());
        $this->assertTrue(ChargePolicyEnum::RANGE_QUANTITY->requiresRanges());
        $this->assertTrue(ChargePolicyEnum::RANGE_WEIGHT->requiresRanges());
        $this->assertTrue(ChargePolicyEnum::RANGE_VOLUME->requiresRanges());
        $this->assertTrue(ChargePolicyEnum::RANGE_VOLUME_WEIGHT->requiresRanges());
        $this->assertFalse(ChargePolicyEnum::FREE->requiresRanges());
        $this->assertFalse(ChargePolicyEnum::FIXED->requiresRanges());
        $this->assertFalse(ChargePolicyEnum::API->requiresRanges());
    }

    public function test_charge_policy_requires_api_endpoint(): void
    {
        $this->assertTrue(ChargePolicyEnum::API->requiresApiEndpoint());
        $this->assertFalse(ChargePolicyEnum::FREE->requiresApiEndpoint());
        $this->assertFalse(ChargePolicyEnum::FIXED->requiresApiEndpoint());
        $this->assertFalse(ChargePolicyEnum::RANGE_AMOUNT->requiresApiEndpoint());
    }

    public function test_charge_policy_range_policies_returns_correct_list(): void
    {
        $rangePolicies = ChargePolicyEnum::rangePolicies();

        $this->assertCount(5, $rangePolicies);
        $this->assertContains(ChargePolicyEnum::RANGE_AMOUNT, $rangePolicies);
        $this->assertContains(ChargePolicyEnum::RANGE_QUANTITY, $rangePolicies);
        $this->assertContains(ChargePolicyEnum::RANGE_WEIGHT, $rangePolicies);
        $this->assertContains(ChargePolicyEnum::RANGE_VOLUME, $rangePolicies);
        $this->assertContains(ChargePolicyEnum::RANGE_VOLUME_WEIGHT, $rangePolicies);
    }

    public function test_charge_policy_requires_unit_value(): void
    {
        // 단위당 정책은 unit_value 필요
        $this->assertTrue(ChargePolicyEnum::PER_QUANTITY->requiresUnitValue());
        $this->assertTrue(ChargePolicyEnum::PER_WEIGHT->requiresUnitValue());
        $this->assertTrue(ChargePolicyEnum::PER_VOLUME->requiresUnitValue());
        $this->assertTrue(ChargePolicyEnum::PER_VOLUME_WEIGHT->requiresUnitValue());
        $this->assertTrue(ChargePolicyEnum::PER_AMOUNT->requiresUnitValue());

        // 비-단위당 정책은 unit_value 불필요
        $this->assertFalse(ChargePolicyEnum::FREE->requiresUnitValue());
        $this->assertFalse(ChargePolicyEnum::FIXED->requiresUnitValue());
        $this->assertFalse(ChargePolicyEnum::CONDITIONAL_FREE->requiresUnitValue());
        $this->assertFalse(ChargePolicyEnum::RANGE_AMOUNT->requiresUnitValue());
        $this->assertFalse(ChargePolicyEnum::API->requiresUnitValue());
    }

    public function test_charge_policy_per_unit_policies_returns_correct_list(): void
    {
        $perUnitPolicies = ChargePolicyEnum::perUnitPolicies();

        $this->assertCount(5, $perUnitPolicies);
        $this->assertContains(ChargePolicyEnum::PER_QUANTITY, $perUnitPolicies);
        $this->assertContains(ChargePolicyEnum::PER_WEIGHT, $perUnitPolicies);
        $this->assertContains(ChargePolicyEnum::PER_VOLUME, $perUnitPolicies);
        $this->assertContains(ChargePolicyEnum::PER_VOLUME_WEIGHT, $perUnitPolicies);
        $this->assertContains(ChargePolicyEnum::PER_AMOUNT, $perUnitPolicies);
    }

    public function test_charge_policy_label_returns_string(): void
    {
        $label = ChargePolicyEnum::FREE->label();

        $this->assertIsString($label);
        $this->assertNotEmpty($label);
    }

    public function test_charge_policy_per_unit_label_returns_string(): void
    {
        foreach (ChargePolicyEnum::perUnitPolicies() as $policy) {
            $label = $policy->label();

            $this->assertIsString($label);
            $this->assertNotEmpty($label, "{$policy->value}의 라벨이 비어있습니다");
        }
    }

    // ========================================
    // ShippingCountryEnum 테스트
    // ========================================

    public function test_shipping_country_has_correct_values(): void
    {
        $this->assertEquals('KR', ShippingCountryEnum::KR->value);
        $this->assertEquals('US', ShippingCountryEnum::US->value);
        $this->assertEquals('CN', ShippingCountryEnum::CN->value);
        $this->assertEquals('JP', ShippingCountryEnum::JP->value);
    }

    public function test_shipping_country_values_returns_all_values(): void
    {
        $values = ShippingCountryEnum::values();

        $this->assertIsArray($values);
        $this->assertCount(4, $values);
        $this->assertContains('KR', $values);
        $this->assertContains('US', $values);
        $this->assertContains('CN', $values);
        $this->assertContains('JP', $values);
    }

    public function test_shipping_country_to_select_options_returns_array(): void
    {
        $options = ShippingCountryEnum::toSelectOptions();

        $this->assertIsArray($options);
        $this->assertCount(4, $options);

        foreach ($options as $option) {
            $this->assertArrayHasKey('value', $option);
            $this->assertArrayHasKey('label', $option);
            $this->assertArrayHasKey('flag', $option);
        }
    }

    public function test_shipping_country_flag_returns_emoji(): void
    {
        $this->assertEquals("\u{1F1F0}\u{1F1F7}", ShippingCountryEnum::KR->flag());
        $this->assertEquals("\u{1F1FA}\u{1F1F8}", ShippingCountryEnum::US->flag());
        $this->assertEquals("\u{1F1E8}\u{1F1F3}", ShippingCountryEnum::CN->flag());
        $this->assertEquals("\u{1F1EF}\u{1F1F5}", ShippingCountryEnum::JP->flag());
    }

    public function test_shipping_country_is_domestic(): void
    {
        $this->assertTrue(ShippingCountryEnum::KR->isDomestic());
        $this->assertFalse(ShippingCountryEnum::US->isDomestic());
        $this->assertFalse(ShippingCountryEnum::CN->isDomestic());
        $this->assertFalse(ShippingCountryEnum::JP->isDomestic());
    }

    public function test_shipping_country_international_countries_returns_correct_list(): void
    {
        $internationalCountries = ShippingCountryEnum::internationalCountries();

        $this->assertCount(3, $internationalCountries);
        $this->assertContains(ShippingCountryEnum::US, $internationalCountries);
        $this->assertContains(ShippingCountryEnum::CN, $internationalCountries);
        $this->assertContains(ShippingCountryEnum::JP, $internationalCountries);
        $this->assertNotContains(ShippingCountryEnum::KR, $internationalCountries);
    }

    public function test_shipping_country_label_returns_string(): void
    {
        $label = ShippingCountryEnum::KR->label();

        $this->assertIsString($label);
        $this->assertNotEmpty($label);
    }
}
