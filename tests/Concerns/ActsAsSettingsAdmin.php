<?php

namespace Tests\Concerns;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * 환경설정 읽기·저장 권한을 가진 관리자로 API 를 부르는 시험 보조 
 *
 * `SettingsControllerDriverOptionsTest` 의 관리자 준비와 같은 꼴이다 — 설정 권한 두 개를 가진 역할과 기본 관리자 역할을 붙인다.
 */
trait ActsAsSettingsAdmin
{
    /** 발급한 API 토큰 */
    private string $settingsAdminToken = '';

    /**
     * 설정 권한을 가진 관리자를 만들고 토큰을 발급합니다.
     *
     * @return User 관리자
     */
    protected function makeSettingsAdmin(): User
    {
        $user = User::factory()->create(['password' => Hash::make('password123')]);

        $permissionIds = [];
        foreach (['core.settings.read', 'core.settings.update'] as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            )->id;
        }

        $role = Role::create([
            'identifier' => 'settings_admin_'.uniqid(),
            'name' => json_encode(['ko' => '설정 관리자', 'en' => 'Settings admin']),
            'description' => json_encode(['ko' => '시험', 'en' => 'Test']),
            'is_active' => true,
        ]);
        $role->permissions()->sync($permissionIds);

        $adminBase = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $user->roles()->attach($adminBase->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $this->settingsAdminToken = $user->createToken('test-token')->plainTextToken;

        return $user->fresh();
    }

    /**
     * 관리자 토큰을 실은 요청을 돌려줍니다.
     *
     * @return static 요청
     */
    protected function asSettingsAdmin(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->settingsAdminToken,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 드라이버 탭 저장 본문(필수 드라이버 값 + 덮을 값)을 만듭니다.
     *
     * @param  array<string, mixed>  $overrides  덮을 값
     * @return array<string, mixed> drivers 값
     */
    protected function driversPayload(array $overrides = []): array
    {
        return array_merge([
            'storage_driver' => 'local',
            'cache_driver' => 'file',
            'session_driver' => 'file',
            'queue_driver' => 'sync',
            'log_driver' => 'daily',
            'log_level' => 'error',
        ], $overrides);
    }
}
