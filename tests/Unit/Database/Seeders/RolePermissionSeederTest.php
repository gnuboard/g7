<?php

namespace Tests\Unit\Database\Seeders;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RolePermissionSeeder 권한 type 정합성 테스트
 *
 * 회귀 방지: config/core.php에 정의된 `type` 필드가 시더를 통해 DB에 반영되어야 한다.
 * 과거 시더는 type을 PermissionType::Admin으로 하드코딩하여 사용자용 권한
 * (core.user-notifications.*)이 admin으로 저장되는 문제가 있었다.
 */
class RolePermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('db:seed', ['--class' => 'RolePermissionSeeder']);
    }

    /**
     * core.user-notifications 카테고리의 type이 user여야 합니다.
     */
    public function test_user_notifications_category_type_is_user(): void
    {
        $category = Permission::where('identifier', 'core.user-notifications')
            ->where('extension_type', ExtensionOwnerType::Core)
            ->first();

        $this->assertNotNull($category, 'core.user-notifications 카테고리가 존재해야 합니다.');
        $this->assertEquals(
            PermissionType::User,
            $category->type,
            'config에서 type=user로 정의된 카테고리는 DB에도 user로 저장되어야 합니다.'
        );
    }

    /**
     * core.user-notifications 하위 권한 3건 모두 type이 user여야 합니다.
     */
    public function test_user_notifications_child_permissions_type_is_user(): void
    {
        $identifiers = [
            'core.user-notifications.read',
            'core.user-notifications.update',
            'core.user-notifications.delete',
        ];

        foreach ($identifiers as $identifier) {
            $permission = Permission::where('identifier', $identifier)
                ->where('extension_type', ExtensionOwnerType::Core)
                ->first();

            $this->assertNotNull($permission, "{$identifier} 권한이 존재해야 합니다.");
            $this->assertEquals(
                PermissionType::User,
                $permission->type,
                "{$identifier} 권한 type은 user여야 합니다."
            );
        }
    }

    /**
     * admin 전용 카테고리는 type이 admin으로 유지되어야 합니다 (회귀 방지).
     */
    public function test_admin_categories_remain_admin_type(): void
    {
        $adminCategories = [
            'core.users',
            'core.menus',
            'core.modules',
            'core.plugins',
            'core.templates',
            'core.permissions',
            'core.notification-logs',
            'core.notifications',
            'core.settings',
        ];

        foreach ($adminCategories as $identifier) {
            $category = Permission::where('identifier', $identifier)
                ->where('extension_type', ExtensionOwnerType::Core)
                ->first();

            if ($category === null) {
                continue;
            }

            $this->assertEquals(
                PermissionType::Admin,
                $category->type,
                "{$identifier} 카테고리 type은 admin이어야 합니다."
            );
        }
    }

    /**
     * 개별 권한의 type은 config 값과 일치해야 합니다.
     */
    public function test_individual_permission_type_matches_config(): void
    {
        $categories = config('core.permissions.categories');

        foreach ($categories as $categoryData) {
            foreach ($categoryData['permissions'] as $permData) {
                $expectedType = isset($permData['type'])
                    ? PermissionType::from($permData['type'])
                    : PermissionType::Admin;

                $permission = Permission::where('identifier', $permData['identifier'])
                    ->where('extension_type', ExtensionOwnerType::Core)
                    ->first();

                $this->assertNotNull($permission, "{$permData['identifier']} 권한이 존재해야 합니다.");
                $this->assertEquals(
                    $expectedType,
                    $permission->type,
                    "{$permData['identifier']} 권한의 type은 config 값과 일치해야 합니다."
                );
            }
        }
    }
}
