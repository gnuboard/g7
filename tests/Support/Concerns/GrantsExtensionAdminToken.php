<?php

namespace Tests\Support\Concerns;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

/**
 * 확장 관리 API 테스트용 관리자 토큰 — 지정한 코어 권한만 가진 관리자를 만듭니다.
 */
trait GrantsExtensionAdminToken
{
    /**
     * 지정 권한을 가진 관리자 토큰을 만듭니다.
     *
     * @param  list<string>  $permissions  코어 권한 식별자 (예: `core.templates.activate`)
     * @return string Bearer 토큰
     */
    protected function extensionAdminToken(array $permissions): string
    {
        $user = User::factory()->create();
        $role = Role::create([
            'identifier' => 'ext_admin_'.uniqid(),
            'name' => ['ko' => '테스트', 'en' => 'Test'],
            'description' => ['ko' => '테스트', 'en' => 'Test'],
            'is_active' => true,
        ]);

        foreach ($permissions as $identifier) {
            $permission = Permission::firstOrCreate(['identifier' => $identifier], [
                'name' => ['ko' => $identifier, 'en' => $identifier],
                'description' => ['ko' => $identifier, 'en' => $identifier],
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
            ]);
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $admin = Role::firstOrCreate(['identifier' => 'admin'], [
            'name' => ['ko' => '관리자', 'en' => 'Administrator'],
            'description' => ['ko' => '관리자', 'en' => 'Administrator'],
            'is_active' => true,
        ]);
        $user->roles()->syncWithoutDetaching([$role->id, $admin->id]);

        return $user->createToken('ext-admin')->plainTextToken;
    }
}
