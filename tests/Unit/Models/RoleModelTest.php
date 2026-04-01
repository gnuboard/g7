<?php

namespace Tests\Unit\Models;

use App\Enums\ExtensionOwnerType;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Role 모델 테스트
 *
 * Role 모델의 확장 소유권 관련 메서드를 검증합니다.
 */
class RoleModelTest extends TestCase
{
    use RefreshDatabase;

    // ========================================================================
    // isCore() 메서드 테스트
    // ========================================================================

    /**
     * extension_type이 Core인 역할에서 isCore()가 true 반환
     */
    public function test_is_core_returns_true_for_core_role(): void
    {
        $role = Role::create([
            'identifier' => 'admin',
            'name' => ['ko' => '관리자', 'en' => 'Administrator'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
        ]);

        $this->assertTrue($role->isCore());
    }

    /**
     * extension_type이 null인 역할에서 isCore()가 false 반환
     */
    public function test_is_core_returns_false_for_user_created_role(): void
    {
        $role = Role::create([
            'identifier' => 'custom_role',
            'name' => ['ko' => '사용자 역할', 'en' => 'Custom Role'],
            'is_active' => true,
        ]);

        $this->assertFalse($role->isCore());
    }

    /**
     * extension_type이 Module인 역할에서 isCore()가 false 반환
     */
    public function test_is_core_returns_false_for_module_role(): void
    {
        $role = Role::create([
            'identifier' => 'sirsoft-board.manager',
            'name' => ['ko' => '게시판 관리자', 'en' => 'Board Manager'],
            'extension_type' => ExtensionOwnerType::Module,
            'extension_identifier' => 'sirsoft-board',
            'is_active' => true,
        ]);

        $this->assertFalse($role->isCore());
    }

    // ========================================================================
    // isDeletable() 메서드 테스트
    // ========================================================================

    /**
     * extension_type이 null인 역할은 삭제 가능
     */
    public function test_is_deletable_returns_true_for_user_created_role(): void
    {
        $role = Role::create([
            'identifier' => 'custom_role',
            'name' => ['ko' => '사용자 역할', 'en' => 'Custom Role'],
            'is_active' => true,
        ]);

        $this->assertTrue($role->isDeletable());
    }

    /**
     * extension_type이 Core인 역할은 삭제 불가
     */
    public function test_is_deletable_returns_false_for_core_role(): void
    {
        $role = Role::create([
            'identifier' => 'admin',
            'name' => ['ko' => '관리자', 'en' => 'Administrator'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
        ]);

        $this->assertFalse($role->isDeletable());
    }

    /**
     * extension_type이 Module인 역할은 삭제 불가
     */
    public function test_is_deletable_returns_false_for_module_role(): void
    {
        $role = Role::create([
            'identifier' => 'sirsoft-board.manager',
            'name' => ['ko' => '게시판 관리자', 'en' => 'Board Manager'],
            'extension_type' => ExtensionOwnerType::Module,
            'extension_identifier' => 'sirsoft-board',
            'is_active' => true,
        ]);

        $this->assertFalse($role->isDeletable());
    }

    /**
     * extension_type이 Plugin인 역할은 삭제 불가
     */
    public function test_is_deletable_returns_false_for_plugin_role(): void
    {
        $role = Role::create([
            'identifier' => 'sirsoft-payment.manager',
            'name' => ['ko' => '결제 관리자', 'en' => 'Payment Manager'],
            'extension_type' => ExtensionOwnerType::Plugin,
            'extension_identifier' => 'sirsoft-payment',
            'is_active' => true,
        ]);

        $this->assertFalse($role->isDeletable());
    }

    // ========================================================================
    // isExtensionOwned() 메서드 테스트
    // ========================================================================

    /**
     * extension_type이 Module인 역할에서 isExtensionOwned()가 true 반환
     */
    public function test_is_extension_owned_returns_true_for_module_role(): void
    {
        $role = Role::create([
            'identifier' => 'sirsoft-board.manager',
            'name' => ['ko' => '게시판 관리자', 'en' => 'Board Manager'],
            'extension_type' => ExtensionOwnerType::Module,
            'extension_identifier' => 'sirsoft-board',
            'is_active' => true,
        ]);

        $this->assertTrue($role->isExtensionOwned());
    }

    /**
     * extension_type이 Plugin인 역할에서 isExtensionOwned()가 true 반환
     */
    public function test_is_extension_owned_returns_true_for_plugin_role(): void
    {
        $role = Role::create([
            'identifier' => 'sirsoft-payment.manager',
            'name' => ['ko' => '결제 관리자', 'en' => 'Payment Manager'],
            'extension_type' => ExtensionOwnerType::Plugin,
            'extension_identifier' => 'sirsoft-payment',
            'is_active' => true,
        ]);

        $this->assertTrue($role->isExtensionOwned());
    }

    /**
     * extension_type이 Core인 역할에서 isExtensionOwned()가 false 반환
     */
    public function test_is_extension_owned_returns_false_for_core_role(): void
    {
        $role = Role::create([
            'identifier' => 'admin',
            'name' => ['ko' => '관리자', 'en' => 'Administrator'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
        ]);

        $this->assertFalse($role->isExtensionOwned());
    }

    /**
     * extension_type이 null인 역할에서 isExtensionOwned()가 false 반환
     */
    public function test_is_extension_owned_returns_false_for_user_created_role(): void
    {
        $role = Role::create([
            'identifier' => 'custom_role',
            'name' => ['ko' => '사용자 역할', 'en' => 'Custom Role'],
            'is_active' => true,
        ]);

        $this->assertFalse($role->isExtensionOwned());
    }

    // ========================================================================
    // extension_type 캐스팅 테스트
    // ========================================================================

    /**
     * extension_type 필드가 ExtensionOwnerType Enum으로 캐스팅되는지 확인
     */
    public function test_extension_type_is_cast_to_enum(): void
    {
        $role = Role::create([
            'identifier' => 'test_cast',
            'name' => ['ko' => '캐스트 테스트', 'en' => 'Cast Test'],
            'extension_type' => ExtensionOwnerType::Module,
            'extension_identifier' => 'test-module',
            'is_active' => true,
        ]);

        $freshRole = Role::find($role->id);

        $this->assertInstanceOf(ExtensionOwnerType::class, $freshRole->extension_type);
        $this->assertEquals(ExtensionOwnerType::Module, $freshRole->extension_type);
    }

    /**
     * extension_type이 null일 때 올바르게 처리되는지 확인
     */
    public function test_extension_type_null_is_handled_correctly(): void
    {
        $role = Role::create([
            'identifier' => 'test_null',
            'name' => ['ko' => 'Null 테스트', 'en' => 'Null Test'],
            'is_active' => true,
        ]);

        $freshRole = Role::find($role->id);

        $this->assertNull($freshRole->extension_type);
        $this->assertNull($freshRole->extension_identifier);
    }

    // ========================================================================
    // fillable 테스트
    // ========================================================================

    /**
     * extension_type과 extension_identifier가 fillable에 포함되어 있는지 확인
     */
    public function test_extension_fields_are_in_fillable(): void
    {
        $role = new Role();

        $this->assertContains('extension_type', $role->getFillable());
        $this->assertContains('extension_identifier', $role->getFillable());
    }
}
