<?php

namespace Tests\Unit\Enums;

use App\Enums\ScopeType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScopeTypeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Self, Role 케이스가 존재해야 합니다.
     */
    public function test_cases_exist(): void
    {
        $cases = ScopeType::cases();
        $this->assertCount(2, $cases);
        $this->assertSame('self', ScopeType::Self->value);
        $this->assertSame('role', ScopeType::Role->value);
    }

    /**
     * values()가 올바른 값 배열을 반환해야 합니다.
     */
    public function test_values_returns_correct_array(): void
    {
        $this->assertSame(['self', 'role'], ScopeType::values());
    }

    /**
     * label()이 다국어 라벨을 반환해야 합니다.
     */
    public function test_label_returns_translated_text(): void
    {
        $selfLabel = ScopeType::Self->label();
        $roleLabel = ScopeType::Role->label();

        $this->assertIsString($selfLabel);
        $this->assertIsString($roleLabel);
        $this->assertNotEmpty($selfLabel);
        $this->assertNotEmpty($roleLabel);
    }

    /**
     * isValid()가 유효한 값에 대해 true를 반환해야 합니다.
     */
    public function test_is_valid_returns_true_for_valid_values(): void
    {
        $this->assertTrue(ScopeType::isValid('self'));
        $this->assertTrue(ScopeType::isValid('role'));
    }

    /**
     * isValid()가 무효한 값에 대해 false를 반환해야 합니다.
     */
    public function test_is_valid_returns_false_for_invalid_values(): void
    {
        $this->assertFalse(ScopeType::isValid('invalid'));
        $this->assertFalse(ScopeType::isValid('all'));
        $this->assertFalse(ScopeType::isValid(''));
    }
}
