<?php

namespace Modules\Sirsoft\Board\Services;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;

/**
 * 게시판 권한 관리 서비스
 *
 * 게시판별 동적 권한 생성/삭제를 담당합니다.
 * 계층 구조: sirsoft-board (루트) → sirsoft-board.{slug} (카테고리) → sirsoft-board.{slug}.{action} (액션)
 */
class BoardPermissionService
{
    use ChecksBoardPermission;

    /**
     * 모듈 identifier
     *
     * @var string
     */
    private const MODULE_IDENTIFIER = 'sirsoft-board';

    /**
     * 생성자
     */
    public function __construct()
    {
        //
    }

    /**
     * 게시판 권한을 생성하거나 업데이트합니다.
     *
     * 계층 구조:
     * - 1단계: sirsoft-board (모듈 루트, parent_id=null)
     * - 2단계: sirsoft-board.{slug} (게시판 카테고리, parent_id=모듈 루트 ID)
     * - 3단계: sirsoft-board.{slug}.{action} (개별 권한, parent_id=카테고리 ID)
     *
     * @param  Board  $board  대상 게시판
     * @param  array  $permissions  사용자가 설정한 권한 (프론트엔드에서 전달)
     * @param  array<string>|null  $onlyKeys  null이면 전체 적용, 지정 시 해당 키만 역할 업데이트
     * @return void
     */
    public function ensureBoardPermissions(Board $board, array $permissions = [], ?array $onlyKeys = null): void
    {
        // 1. 모듈 루트 권한 생성 (최초 1회, 이미 존재하면 skip)
        $moduleRootPermission = Permission::firstOrCreate(
            ['identifier' => self::MODULE_IDENTIFIER],
            [
                'name' => ['ko' => '게시판', 'en' => 'Board'],
                'description' => ['ko' => '게시판 모듈', 'en' => 'Board module'],
                'extension_type' => ExtensionOwnerType::Module,
                'extension_identifier' => self::MODULE_IDENTIFIER,
                'type' => \App\Enums\PermissionType::Admin,
                'parent_id' => null,
            ]
        );

        // 2. 게시판 카테고리 권한 생성 (부모)
        $categoryPermission = Permission::updateOrCreate(
            ['identifier' => self::MODULE_IDENTIFIER.".{$board->slug}"],
            [
                'name' => [
                    'ko' => ($board->name['ko'] ?? $board->name['en'] ?? '').' 게시판',
                    'en' => ($board->name['en'] ?? $board->name['ko'] ?? '').' board',
                ],
                'description' => [
                    'ko' => ($board->name['ko'] ?? $board->name['en'] ?? '').' 게시판 권한',
                    'en' => ($board->name['en'] ?? $board->name['ko'] ?? '').' board permissions',
                ],
                'extension_type' => ExtensionOwnerType::Module,
                'extension_identifier' => self::MODULE_IDENTIFIER,
                'type' => \App\Enums\PermissionType::Admin,
                'parent_id' => $moduleRootPermission->id,
            ]
        );

        // 3. 액션 권한 생성 (자식)
        $permissionDefinitions = config('sirsoft-board.board_permission_definitions');
        $basicDefaults = g7_module_settings('sirsoft-board', 'basic_defaults', []);
        $defaultPermissions = $basicDefaults['default_board_permissions'] ?? [];

        // 게시판 생성/수정 시($onlyKeys=null)에만 manager/step 자동주입
        // 일괄적용 시($onlyKeys 지정)에는 해당 게시판의 기존 manager/step을 건드리지 않음
        if ($onlyKeys === null) {
            $defaultPermissions = $this->injectBoardRoles($defaultPermissions, $board->slug);
        }

        foreach ($permissionDefinitions as $key => $definition) {
            // 권한 identifier 생성 (3단계: sirsoft-board.{slug}.{action})
            $identifier = self::MODULE_IDENTIFIER.".{$board->slug}.{$key}";

            // 권한 타입 결정: admin.*로 시작하면 Admin, 아니면 User
            $permissionType = str_starts_with($key, 'admin.')
                ? \App\Enums\PermissionType::Admin
                : \App\Enums\PermissionType::User;

            // 권한 생성 또는 업데이트
            $attributes = [
                'name' => $definition['name'],
                'description' => $definition['description'],
                'extension_type' => ExtensionOwnerType::Module,
                'extension_identifier' => self::MODULE_IDENTIFIER,
                'type' => $permissionType,
                'parent_id' => $categoryPermission->id,
            ];

            // scope 메타데이터가 정의된 경우 resource_route_key/owner_key 설정
            if (isset($definition['scope'])) {
                $attributes['resource_route_key'] = $definition['scope']['resource_route_key'];
                $attributes['owner_key'] = $definition['scope']['owner_key'];
            }

            $permission = Permission::updateOrCreate(
                ['identifier' => $identifier],
                $attributes
            );

            // 4. 역할 할당
            // $onlyKeys가 지정된 경우 해당 키만 역할 업데이트 (부분 적용), 나머지는 건너뜀
            if ($onlyKeys !== null && ! in_array($key, $onlyKeys, true)) {
                continue;
            }
            $this->assignRolesToPermission($permission, $key, $board, $permissions, $defaultPermissions, $onlyKeys !== null);
        }
    }

    /**
     * 권한에 역할을 할당합니다.
     *
     * @param  Permission  $permission  대상 권한
     * @param  string  $key  권한 키 (posts.list 등)
     * @param  Board  $board  게시판
     * @param  array  $permissions  사용자 설정 권한
     * @param  array  $defaultPermissions  기본 권한 설정
     */
    private function assignRolesToPermission(
        Permission $permission,
        string $key,
        Board $board,
        array $permissions,
        array $defaultPermissions,
        bool $isBulkApply = false
    ): void {
        // 프론트엔드에서는 posts_list 형식으로 전송되므로 변환하여 찾기
        $frontendKey = str_replace('.', '_', $key);
        $permissionData = $permissions[$frontendKey] ?? $permissions[$key] ?? null;

        // 최종 할당할 역할 배열 계산
        if ($permissionData !== null) {
            $targetRoles = $permissionData['roles'] ?? [];
        } else {
            // 사용자 설정이 없으면 config 기본값 사용 (null이면 전체 허용 → 빈 배열)
            $defaultRoles = $defaultPermissions[$key] ?? null;
            $targetRoles = is_array($defaultRoles) ? $defaultRoles : [];
        }

        // diff 계산: 추가/제거분만 처리하여 granted_at 보존
        $currentIdentifiers = $permission->roles()->pluck('identifier')->toArray();
        $toAdd = array_diff($targetRoles, $currentIdentifiers);
        $toRemove = array_diff($currentIdentifiers, $targetRoles);

        // 일괄적용 시에만 manager/step 보호 — 개별 게시판 수정 시에는 그대로 반영
        if ($isBulkApply) {
            $protectedRoles = [
                "sirsoft-board.{$board->slug}.manager",
                "sirsoft-board.{$board->slug}.step",
            ];
            $toRemove = array_values(array_diff($toRemove, $protectedRoles));
        }

        if (empty($toAdd) && empty($toRemove)) {
            return;
        }

        if (! empty($toRemove)) {
            $removeIds = Role::whereIn('identifier', $toRemove)->pluck('id')->toArray();
            $permission->roles()->detach($removeIds);
        }

        if (! empty($toAdd)) {
            $this->attachRoles($permission, $toAdd, $board, $key);
        }
    }

    /**
     * 권한에 역할들을 attach합니다.
     *
     * @param  Permission  $permission  대상 권한
     * @param  array  $roleIdentifiers  역할 identifier 배열
     * @param  Board  $board  게시판
     * @param  string  $key  권한 키
     */
    private function attachRoles(Permission $permission, array $roleIdentifiers, Board $board, string $key): void
    {
        $existingRoles = Role::whereIn('identifier', $roleIdentifiers)->get();

        if ($existingRoles->isEmpty()) {
            Log::warning("게시판 '{$board->slug}' 권한 '{$key}': 설정된 역할이 존재하지 않습니다.", [
                'board_slug' => $board->slug,
                'permission_key' => $key,
                'requested_roles' => $roleIdentifiers,
            ]);

            return;
        }

        foreach ($existingRoles as $role) {
            $permission->roles()->attach($role->id, [
                'granted_at' => now(),
                'granted_by' => Auth::id(),
            ]);
        }
    }

    /**
     * 게시판의 모든 권한을 삭제합니다.
     *
     * 그누보드7 규정: detach 후 삭제 순서를 따릅니다.
     * 카테고리 권한 삭제 시 CASCADE로 자식 권한도 함께 삭제됩니다.
     *
     * @param  Board  $board  대상 게시판
     */
    public function removeBoardPermissions(Board $board): void
    {
        // 카테고리 권한 찾기
        $categoryPermission = Permission::where('identifier', self::MODULE_IDENTIFIER.".{$board->slug}")->first();

        if ($categoryPermission) {
            // 그누보드7 규정: detach 후 삭제
            // CASCADE로 자식 권한도 함께 삭제됨
            $categoryPermission->roles()->detach();
            $categoryPermission->delete();
        }
    }

    /**
     * 게시판의 권한을 업데이트합니다.
     *
     * permissions 테이블은 유지하고, role_permissions만 동기화합니다.
     *
     * @param  Board  $board  대상 게시판
     * @param  array  $permissions  사용자가 설정한 권한 (프론트엔드에서 전달)
     * @param  array<string>|null  $onlyKeys  null이면 전체 적용, 지정 시 해당 키만 역할 업데이트
     * @return void
     */
    public function updateBoardPermissions(Board $board, array $permissions, ?array $onlyKeys = null): void
    {
        // ensureBoardPermissions가 updateOrCreate를 사용하므로
        // 권한 업데이트도 동일한 메서드 사용
        $this->ensureBoardPermissions($board, $permissions, $onlyKeys);
    }

    /**
     * 게시판별 관리자/스텝 역할을 기본 권한 설정에 주입합니다.
     *
     * 관리자 역할: 모든 권한에 추가
     * 스텝 역할: admin.manage, manager 제외한 모든 권한에 추가
     *
     * @param  array  $defaultPermissions  기본 권한 설정
     * @param  string  $slug  게시판 슬러그
     * @return array 게시판별 역할이 추가된 권한 설정
     */
    private function injectBoardRoles(array $defaultPermissions, string $slug): array
    {
        $managerIdentifier = "sirsoft-board.{$slug}.manager";
        $stepIdentifier = "sirsoft-board.{$slug}.step";

        // 스텝 역할이 제외되는 권한 키
        $stepExcludedKeys = ['admin.manage', 'manager'];

        foreach ($defaultPermissions as $key => $roles) {
            if (! is_array($roles)) {
                continue;
            }

            // 관리자 역할: 모든 권한에 추가
            $roles[] = $managerIdentifier;

            // 스텝 역할: admin.manage, manager 제외
            if (! in_array($key, $stepExcludedKeys)) {
                $roles[] = $stepIdentifier;
            }

            $defaultPermissions[$key] = $roles;
        }

        return $defaultPermissions;
    }

    /**
     * 권한이 "전체" 허용인지 확인합니다.
     *
     * role_permissions 테이블에 해당 권한 레코드가 없으면 전체 허용으로 판단합니다.
     *
     * @param  Permission  $permission  확인할 권한
     */
    public function isPublicPermission(Permission $permission): bool
    {
        return $permission->roles()->count() === 0;
    }

    /**
     * 모듈 레벨 권한에 역할을 재할당합니다.
     *
     * role_permissions 테이블에 granted_at, granted_by 컬럼이 존재하므로
     * sync() 대신 detach() + attach() 패턴을 사용합니다.
     *
     * @param  array  $permissionRoleMap  권한 identifier => 역할 identifier 배열 매핑
     * @return void
     */
    public function syncModulePermissionRoles(array $permissionRoleMap): void
    {
        foreach ($permissionRoleMap as $identifier => $roleIdentifiers) {
            $permission = Permission::where('identifier', $identifier)->first();
            if (! $permission) {
                continue;
            }

            $currentIdentifiers = $permission->roles()->pluck('identifier')->toArray();

            $toAdd = array_diff($roleIdentifiers, $currentIdentifiers);
            $toRemove = array_diff($currentIdentifiers, $roleIdentifiers);

            if (empty($toAdd) && empty($toRemove)) {
                continue;
            }

            if (! empty($toRemove)) {
                $removeIds = Role::whereIn('identifier', $toRemove)->pluck('id')->toArray();
                $permission->roles()->detach($removeIds);
            }

            if (! empty($toAdd)) {
                $roles = Role::whereIn('identifier', $toAdd)->get();
                foreach ($roles as $role) {
                    $permission->roles()->attach($role->id, [
                        'granted_at' => now(),
                        'granted_by' => Auth::id(),
                    ]);
                }
            }
        }
    }

    /**
     * 모듈 레벨 권한에 현재 할당된 역할 identifier 배열을 반환합니다.
     *
     * 'sirsoft-board.reports.view' → 'view_roles' 키로 변환합니다.
     *
     * @param  array  $identifiers  조회할 권한 identifier 목록
     * @return array { view_roles: [...], manage_roles: [...] } 형태
     */
    public function getModulePermissionRoles(array $identifiers): array
    {
        $result = [];
        foreach ($identifiers as $identifier) {
            $permission = Permission::where('identifier', $identifier)->first();
            // 'sirsoft-board.reports.view' → 'view_roles'
            $key = str_replace('sirsoft-board.reports.', '', $identifier).'_roles';
            $result[$key] = $permission
                ? $permission->roles()->pluck('identifier')->toArray()
                : [];
        }

        return $result;
    }

    /**
     * 사용자가 특정 게시판의 특정 권한을 가지고 있는지 확인합니다.
     *
     * @param  string  $slug  게시판 slug
     * @param  string  $action  권한 액션 (예: 'posts.create')
     */
    public function hasPermission(string $slug, string $action): bool
    {
        if (! Auth::check()) {
            return false;
        }

        $identifier = self::MODULE_IDENTIFIER.".{$slug}.{$action}";

        return $this->checkPermissionByIdentifier($identifier);
    }
}
