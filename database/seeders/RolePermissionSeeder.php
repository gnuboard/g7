<?php

namespace Database\Seeders;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Enums\ScopeType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RolePermissionSeeder extends Seeder
{
    /**
     * 기본 권한과 역할을 생성합니다.
     */
    public function run(): void
    {
        $this->command->info('기본 권한과 역할 생성을 시작합니다.');

        DB::transaction(function () {
            // 기존 데이터 삭제
            $this->deleteExistingData();

            // 계층형 권한 생성
            $this->createHierarchicalPermissions();

            // 기본 역할 생성
            $this->createRoles();

            // 관리자 계정에 admin 역할 부여
            $this->assignAdminRole();
        });

        $this->command->info('기본 권한과 역할이 성공적으로 생성되었습니다.');
    }

    /**
     * 기존 코어 권한 데이터를 삭제합니다.
     *
     * 주의: 역할(Role)은 삭제하지 않습니다. 역할 삭제 시 role_menus, role_users 등
     * cascade 연결된 피벗 데이터가 함께 삭제되므로, 역할은 updateOrCreate로 보존합니다.
     */
    private function deleteExistingData(): void
    {
        // 기존 코어 권한만 삭제 (role_permissions 피벗은 cascade 삭제됨 → 이후 재연결)
        $deletedPermissions = Permission::where('extension_type', ExtensionOwnerType::Core)->delete();

        if ($deletedPermissions > 0) {
            $this->command->info("기존 코어 권한 {$deletedPermissions}개가 삭제되었습니다.");
        }
    }

    /**
     * 계층형 권한을 생성합니다.
     * 구조: 모듈(1레벨) → 카테고리(2레벨) → 개별 권한(3레벨)
     * 정의는 config/core.php의 permissions에서 읽습니다.
     */
    private function createHierarchicalPermissions(): void
    {
        $permConfig = config('core.permissions');
        $moduleConfig = $permConfig['module'];

        // 1레벨: 코어 모듈
        $coreModule = Permission::create([
            'identifier' => $moduleConfig['identifier'],
            'name' => $moduleConfig['name'],
            'description' => $moduleConfig['description'],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'type' => PermissionType::Admin,
            'order' => $moduleConfig['order'],
            'parent_id' => null,
        ]);

        // 2레벨: 카테고리들
        $categories = $permConfig['categories'];

        foreach ($categories as $categoryData) {
            // 2레벨: 카테고리 생성
            $category = Permission::create([
                'identifier' => $categoryData['identifier'],
                'name' => $categoryData['name'],
                'description' => $categoryData['description'],
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => PermissionType::Admin,
                'order' => $categoryData['order'],
                'parent_id' => $coreModule->id,
            ]);

            // 3레벨: 개별 권한 생성
            foreach ($categoryData['permissions'] as $permData) {
                $permissionData = [
                    'identifier' => $permData['identifier'],
                    'name' => $permData['name'],
                    'description' => $permData['description'],
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => PermissionType::Admin,
                    'order' => $permData['order'],
                    'parent_id' => $category->id,
                ];

                // scope 관련 컬럼 (resource_route_key, owner_key) 추가
                if (isset($permData['resource_route_key'])) {
                    $permissionData['resource_route_key'] = $permData['resource_route_key'];
                }
                if (isset($permData['owner_key'])) {
                    $permissionData['owner_key'] = $permData['owner_key'];
                }

                Permission::create($permissionData);
            }
        }

        $this->command->info('계층형 권한이 생성되었습니다.');
    }

    /**
     * 역할을 생성(또는 업데이트)하고 권한을 할당합니다.
     * 정의는 config/core.php의 roles에서 읽습니다.
     *
     * updateOrCreate를 사용하여 역할 ID를 보존합니다.
     * 역할 삭제 시 role_menus, role_users 등 cascade 피벗이 함께 삭제되므로,
     * 역할은 절대 삭제하지 않고 속성만 업데이트합니다.
     */
    private function createRoles(): void
    {
        $roles = config('core.roles');

        foreach ($roles as $roleDef) {
            $role = Role::updateOrCreate(
                ['identifier' => $roleDef['identifier']],
                [
                    'name' => $roleDef['name'],
                    'description' => $roleDef['description'],
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'is_active' => $roleDef['attributes']['is_active'] ?? true,
                ]
            );

            // 권한별 스코프 맵 (identifier => scope_type value)
            $scopeMap = $roleDef['permission_scopes'] ?? [];

            // 권한 할당
            if ($roleDef['permissions'] === 'all_leaf') {
                // 리프 노드 코어 권한만 할당 (parent_id가 있고, 자식이 없는 권한)
                $leafPermissions = Permission::where('extension_type', ExtensionOwnerType::Core)
                    ->whereNotNull('parent_id')
                    ->whereDoesntHave('children')
                    ->get();

                // 코어 권한만 sync하고, 모듈/플러그인이 부여한 권한은 보존
                $nonCorePermissionIds = $role->permissions()
                    ->where('extension_type', '!=', ExtensionOwnerType::Core)
                    ->pluck('permissions.id')
                    ->toArray();

                $allPermissionIds = array_merge($leafPermissions->pluck('id')->toArray(), $nonCorePermissionIds);
                $role->permissions()->sync($allPermissionIds);
            } elseif (is_array($roleDef['permissions']) && ! empty($roleDef['permissions'])) {
                $permissions = Permission::whereIn('identifier', $roleDef['permissions'])->get();

                // scope_type 매핑 (permission_scopes 맵에서 스코프 타입 해석)
                $coreSyncData = $permissions->mapWithKeys(function ($permission) use ($scopeMap) {
                    $pivotData = [
                        'scope_type' => isset($scopeMap[$permission->identifier])
                            ? ScopeType::from($scopeMap[$permission->identifier])
                            : null,
                    ];

                    return [$permission->id => $pivotData];
                })->toArray();

                // 모듈/플러그인이 부여한 권한은 보존
                $nonCorePermissions = $role->permissions()
                    ->where('extension_type', '!=', ExtensionOwnerType::Core)
                    ->get()
                    ->mapWithKeys(fn ($p) => [$p->id => ['scope_type' => $p->pivot->scope_type ?? null]])
                    ->toArray();

                $role->permissions()->sync(array_replace($coreSyncData, $nonCorePermissions));
            }
        }

        $this->command->info('역할이 생성/업데이트되었습니다.');
    }

    /**
     * 관리자 계정에 admin 역할을 부여하고 슈퍼 관리자로 설정합니다.
     */
    private function assignAdminRole(): void
    {
        $adminUser = User::where('is_super', true)->first();
        $adminRole = Role::where('identifier', 'admin')->first();

        if ($adminUser && $adminRole) {
            $adminUser->roles()->syncWithoutDetaching([$adminRole->id => [
                'assigned_at' => now(),
                'assigned_by' => $adminUser->id,
            ]]);

            // 기본 관리자를 슈퍼 관리자로 설정
            $adminUser->update(['is_super' => true]);

            $this->command->info('관리자 계정에 admin 역할이 부여되고 슈퍼 관리자로 설정되었습니다.');
        }
    }
}
