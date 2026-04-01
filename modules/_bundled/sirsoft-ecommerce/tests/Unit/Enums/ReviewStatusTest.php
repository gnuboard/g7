<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Enums;

use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * ReviewStatus Enum 단위 테스트
 *
 * ReviewStatus의 값, 라벨, variant, badgeColor, values(), toSelectOptions() 메서드를 검증합니다.
 */
class ReviewStatusTest extends ModuleTestCase
{
    #[Test]
    public function test_has_correct_values(): void
    {
        $this->assertEquals('visible', ReviewStatus::VISIBLE->value);
        $this->assertEquals('hidden', ReviewStatus::HIDDEN->value);
    }

    #[Test]
    public function test_cases_count(): void
    {
        $this->assertCount(2, ReviewStatus::cases());
    }

    #[Test]
    public function test_label_returns_string(): void
    {
        $label = ReviewStatus::VISIBLE->label();

        $this->assertIsString($label);
        $this->assertNotEmpty($label);
    }

    #[Test]
    public function test_variant_returns_correct_values(): void
    {
        $this->assertEquals('success', ReviewStatus::VISIBLE->variant());
        $this->assertEquals('secondary', ReviewStatus::HIDDEN->variant());
    }

    #[Test]
    public function test_badge_color_returns_correct_values(): void
    {
        $this->assertEquals('blue', ReviewStatus::VISIBLE->badgeColor());
        $this->assertEquals('gray', ReviewStatus::HIDDEN->badgeColor());
    }

    #[Test]
    public function test_values_returns_all_string_values(): void
    {
        $values = ReviewStatus::values();

        $this->assertIsArray($values);
        $this->assertCount(2, $values);
        $this->assertContains('visible', $values);
        $this->assertContains('hidden', $values);
    }

    #[Test]
    public function test_to_select_options_returns_correct_structure(): void
    {
        $options = ReviewStatus::toSelectOptions();

        $this->assertIsArray($options);
        $this->assertCount(2, $options);

        foreach ($options as $option) {
            $this->assertArrayHasKey('value', $option);
            $this->assertArrayHasKey('label', $option);
            $this->assertIsString($option['value']);
            $this->assertIsString($option['label']);
        }
    }

    #[Test]
    public function test_to_select_options_contains_all_cases(): void
    {
        $options = ReviewStatus::toSelectOptions();
        $values = array_column($options, 'value');

        $this->assertContains('visible', $values);
        $this->assertContains('hidden', $values);
    }
}
