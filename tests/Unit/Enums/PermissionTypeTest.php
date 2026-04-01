<?php

namespace Tests\Unit\Enums;

use App\Enums\PermissionType;
use Tests\TestCase;

/**
 * PermissionType Enum 테스트
 *
 * 권한 타입(admin/user) Enum의 동작을 검증합니다.
 */
class PermissionTypeTest extends TestCase
{
    // ========================================================================
    // 기본 Enum 동작 테스트
    // ========================================================================

    /**
     * PermissionType Enum에 Admin과 User case가 존재하는지 확인
     */
    public function test_has_admin_and_user_cases(): void
    {
        $cases = PermissionType::cases();

        $this->assertCount(2, $cases);
        $this->assertContains(PermissionType::Admin, $cases);
        $this->assertContains(PermissionType::User, $cases);
    }

    /**
     * Admin case의 value가 'admin' 문자열인지 확인
     */
    public function test_admin_case_has_correct_value(): void
    {
        $this->assertEquals('admin', PermissionType::Admin->value);
    }

    /**
     * User case의 value가 'user' 문자열인지 확인
     */
    public function test_user_case_has_correct_value(): void
    {
        $this->assertEquals('user', PermissionType::User->value);
    }

    // ========================================================================
    // values() 정적 메서드 테스트
    // ========================================================================

    /**
     * values() 메서드가 모든 타입 값을 배열로 반환하는지 확인
     */
    public function test_values_returns_all_type_values(): void
    {
        $values = PermissionType::values();

        $this->assertIsArray($values);
        $this->assertCount(2, $values);
        $this->assertContains('admin', $values);
        $this->assertContains('user', $values);
    }

    // ========================================================================
    // isValid() 정적 메서드 테스트
    // ========================================================================

    /**
     * isValid()가 유효한 값에 대해 true를 반환하는지 확인
     */
    public function test_is_valid_returns_true_for_valid_values(): void
    {
        $this->assertTrue(PermissionType::isValid('admin'));
        $this->assertTrue(PermissionType::isValid('user'));
    }

    /**
     * isValid()가 유효하지 않은 값에 대해 false를 반환하는지 확인
     */
    public function test_is_valid_returns_false_for_invalid_values(): void
    {
        $this->assertFalse(PermissionType::isValid('invalid'));
        $this->assertFalse(PermissionType::isValid(''));
        $this->assertFalse(PermissionType::isValid('Admin')); // 대소문자 구분
        $this->assertFalse(PermissionType::isValid('USER'));
    }

    // ========================================================================
    // label() 인스턴스 메서드 테스트
    // ========================================================================

    /**
     * Admin type의 label()이 번역된 문자열을 반환하는지 확인
     */
    public function test_admin_label_returns_translated_string(): void
    {
        $label = PermissionType::Admin->label();

        $this->assertIsString($label);
        $this->assertNotEmpty($label);
        // 번역 키가 없는 경우 키 자체가 반환될 수 있음
        // 번역이 있는 경우 '관리자 권한' 또는 'Admin Permissions' 등
    }

    /**
     * User type의 label()이 번역된 문자열을 반환하는지 확인
     */
    public function test_user_label_returns_translated_string(): void
    {
        $label = PermissionType::User->label();

        $this->assertIsString($label);
        $this->assertNotEmpty($label);
    }

    // ========================================================================
    // icon() 인스턴스 메서드 테스트
    // ========================================================================

    /**
     * Admin type의 icon()이 'cog'를 반환하는지 확인
     */
    public function test_admin_icon_returns_cog(): void
    {
        $icon = PermissionType::Admin->icon();

        $this->assertEquals('cog', $icon);
    }

    /**
     * User type의 icon()이 'user'를 반환하는지 확인
     */
    public function test_user_icon_returns_user(): void
    {
        $icon = PermissionType::User->icon();

        $this->assertEquals('user', $icon);
    }

    // ========================================================================
    // from() 정적 메서드 테스트
    // ========================================================================

    /**
     * from()으로 문자열에서 Enum 인스턴스 생성 가능 확인
     */
    public function test_can_create_from_string(): void
    {
        $admin = PermissionType::from('admin');
        $user = PermissionType::from('user');

        $this->assertInstanceOf(PermissionType::class, $admin);
        $this->assertInstanceOf(PermissionType::class, $user);
        $this->assertEquals(PermissionType::Admin, $admin);
        $this->assertEquals(PermissionType::User, $user);
    }

    /**
     * from()에 잘못된 값 전달 시 ValueError 발생 확인
     */
    public function test_from_throws_exception_for_invalid_value(): void
    {
        $this->expectException(\ValueError::class);

        PermissionType::from('invalid');
    }

    // ========================================================================
    // tryFrom() 정적 메서드 테스트
    // ========================================================================

    /**
     * tryFrom()으로 문자열에서 Enum 인스턴스 생성 (유효한 값)
     */
    public function test_try_from_returns_instance_for_valid_value(): void
    {
        $admin = PermissionType::tryFrom('admin');
        $user = PermissionType::tryFrom('user');

        $this->assertInstanceOf(PermissionType::class, $admin);
        $this->assertInstanceOf(PermissionType::class, $user);
    }

    /**
     * tryFrom()에 잘못된 값 전달 시 null 반환 확인
     */
    public function test_try_from_returns_null_for_invalid_value(): void
    {
        $result = PermissionType::tryFrom('invalid');

        $this->assertNull($result);
    }
}
