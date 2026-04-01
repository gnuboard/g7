<?php

namespace Tests\Unit\Enums;

use App\Enums\ExtensionOwnerType;
use Tests\TestCase;

class ExtensionOwnerTypeTest extends TestCase
{
    /**
     * Enum 값이 올바르게 정의되어 있는지 확인합니다.
     */
    public function test_has_correct_values(): void
    {
        $this->assertEquals('core', ExtensionOwnerType::Core->value);
        $this->assertEquals('module', ExtensionOwnerType::Module->value);
        $this->assertEquals('plugin', ExtensionOwnerType::Plugin->value);
    }

    /**
     * values() 메서드가 모든 값을 반환하는지 확인합니다.
     */
    public function test_values_returns_all_values(): void
    {
        $values = ExtensionOwnerType::values();

        $this->assertCount(3, $values);
        $this->assertContains('core', $values);
        $this->assertContains('module', $values);
        $this->assertContains('plugin', $values);
    }

    /**
     * isValid() 메서드가 올바르게 동작하는지 확인합니다.
     */
    public function test_is_valid_returns_correct_result(): void
    {
        $this->assertTrue(ExtensionOwnerType::isValid('core'));
        $this->assertTrue(ExtensionOwnerType::isValid('module'));
        $this->assertTrue(ExtensionOwnerType::isValid('plugin'));
        $this->assertFalse(ExtensionOwnerType::isValid('invalid'));
        $this->assertFalse(ExtensionOwnerType::isValid(''));
    }

    /**
     * label() 메서드가 다국어 라벨을 반환하는지 확인합니다.
     */
    public function test_label_returns_translated_string(): void
    {
        // label()은 __() 함수를 호출하므로 문자열을 반환해야 함
        $this->assertIsString(ExtensionOwnerType::Core->label());
        $this->assertIsString(ExtensionOwnerType::Module->label());
        $this->assertIsString(ExtensionOwnerType::Plugin->label());
    }

    /**
     * from() 메서드로 올바르게 생성할 수 있는지 확인합니다.
     */
    public function test_can_be_created_from_string(): void
    {
        $this->assertEquals(ExtensionOwnerType::Core, ExtensionOwnerType::from('core'));
        $this->assertEquals(ExtensionOwnerType::Module, ExtensionOwnerType::from('module'));
        $this->assertEquals(ExtensionOwnerType::Plugin, ExtensionOwnerType::from('plugin'));
    }

    /**
     * tryFrom()이 잘못된 값에 대해 null을 반환하는지 확인합니다.
     */
    public function test_try_from_returns_null_for_invalid_value(): void
    {
        $this->assertNull(ExtensionOwnerType::tryFrom('invalid'));
        $this->assertNull(ExtensionOwnerType::tryFrom(''));
    }
}
