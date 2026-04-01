<?php

namespace App\Extension\Helpers;

use App\Contracts\Repositories\PermissionRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * 확장 역할/권한 동기화 헬퍼
 *
 * 확장(모듈/플러그인) 설치/업데이트 시 사용자 커스터마이징을 보존하면서
 * 역할/권한을 안전하게 동기화합니다.
 *
 * user_overrides 컬럼에서 유저가 수정한 필드명 목록을 읽어,
 * 해당 필드는 건너뛰고 나머지만 갱신합니다.
 */
class ExtensionRoleSyncHelper
{
    /**
     * @param  RoleRepositoryInterface  $roleRepository  역할 저장소
     * @param  PermissionRepositoryInterface  $permissionRepository  권한 저장소
     */
    public function __construct(
        private readonly RoleRepositoryInterface $roleRepository,
        private readonly PermissionRepositoryInterface $permissionRepository,
    ) {}

    /**
     * 역할을 동기화합니다.
     *
     * 신규: 생성 (user_overrides 없음)
     * 기존: user_overrides에 없는 필드만 업데이트
     *
     * @param  string  $identifier  역할 식별자
     * @param  array  $newDescription  새 역할 설명 (다국어 배열)
     * @param  array  $newName  새 역할 이름 (다국어 배열)
     * @param  ExtensionOwnerType  $extensionType  확장 타입
     * @param  string  $extensionIdentifier  확장 식별자
     * @param  array  $otherAttributes  기타 속성 (is_active 등)
     * @return Role 동기화된 역할 모델
     */
    public function syncRole(
        string $identifier,
        array $newDescription,
        array $newName,
        ExtensionOwnerType $extensionType,
        string $extensionIdentifier,
        array $otherAttributes = [],
    ): Role {
        $existing = $this->roleRepository->findByIdentifier($identifier);

        if (! $existing) {
            // 신규 생성
            return $this->roleRepository->updateOrCreate(
                ['identifier' => $identifier],
                array_merge([
                    'name' => $newName,
                    'description' => $newDescription,
                    'extension_type' => $extensionType,
                    'extension_identifier' => $extensionIdentifier,
                    'is_active' => true,
                ], $otherAttributes)
            );
        }

        // 기존 역할 업데이트: user_overrides에 없는 필드만 갱신
        $userOverrides = $existing->user_overrides ?? [];

        $updateData = [
            'extension_type' => $extensionType,
            'extension_identifier' => $extensionIdentifier,
        ];

        if (! in_array('name', $userOverrides, true)) {
            $updateData['name'] = $newName;
        }

        if (! in_array('description', $userOverrides, true)) {
            $updateData['description'] = $newDescription;
        }

        // 기타 속성 병합
        $updateData = array_merge($updateData, $otherAttributes);

        $this->roleRepository->update($existing, $updateData);

        return $existing->fresh();
    }

    /**
     * 권한을 동기화합니다.
     *
     * Permission은 유저가 수정할 수 없는 테이블이므로 항상 확장 정의값으로 덮어씁니다.
     *
     * @param  string  $identifier  권한 식별자
     * @param  array  $newName  새 권한 이름 (다국어 배열)
     * @param  array  $newDescription  새 권한 설명 (다국어 배열)
     * @param  ExtensionOwnerType  $extensionType  확장 타입
     * @param  string  $extensionIdentifier  확장 식별자
     * @param  array  $otherAttributes  기타 속성 (type, order, parent_id 등)
     * @return Permission 동기화된 권한 모델
     */
    public function syncPermission(
        string $identifier,
        array $newName,
        array $newDescription,
        ExtensionOwnerType $extensionType,
        string $extensionIdentifier,
        array $otherAttributes = [],
    ): Permission {
        return $this->permissionRepository->updateOrCreate(
            ['identifier' => $identifier],
            array_merge([
                'name' => $newName,
                'description' => $newDescription,
                'extension_type' => $extensionType,
                'extension_identifier' => $extensionIdentifier,
            ], $otherAttributes)
        );
    }

    /**
     * 현재 확장에 속하지 않는 stale 권한을 정리합니다.
     *
     * ⚠️ 주의: 이 메서드는 정적 정의(getPermissions()) 기반으로만 판단하므로,
     * 확장이 런타임에 동적으로 생성한 권한도 삭제됩니다.
     * 자동 호출은 폐기되었으며, 필요 시 UpgradeStep에서 명시적으로 호출하세요.
     *
     * @param  ExtensionOwnerType  $extensionType  확장 타입
     * @param  string  $extensionIdentifier  확장 식별자
     * @param  array  $currentIdentifiers  현재 유효한 권한 식별자 목록
     * @return int 삭제된 권한 수
     *
     * @see \App\Contracts\Extension\UpgradeStepInterface
     */
    public function cleanupStalePermissions(
        ExtensionOwnerType $extensionType,
        string $extensionIdentifier,
        array $currentIdentifiers,
    ): int {
        $existingPermissions = $this->permissionRepository->getByExtension($extensionType, $extensionIdentifier);

        $deleted = 0;
        foreach ($existingPermissions as $permission) {
            if (! in_array($permission->identifier, $currentIdentifiers, true)) {
                // 역할 연결 해제
                $permission->roles()->detach();
                // 자식 권한도 정리
                foreach ($permission->children as $child) {
                    $child->roles()->detach();
                    $this->permissionRepository->delete($child);
                    $deleted++;
                }
                $this->permissionRepository->delete($permission);
                $deleted++;
            }
        }

        if ($deleted > 0) {
            Log::info('stale 권한 정리 완료', [
                'extension_type' => $extensionType->value,
                'extension_identifier' => $extensionIdentifier,
                'deleted' => $deleted,
            ]);
        }

        return $deleted;
    }

    /**
     * 확장의 전체 역할-권한 할당을 일괄 동기화합니다.
     *
     * DB 기반 diff: 현재 DB 상태와 확장 정의를 비교하여
     * 새 권한은 attach, 제거된 권한은 detach합니다.
     * user_overrides에 기록된 개별 권한 식별자는 보호됩니다.
     *
     * @param  array  $permissionRoleMap  권한→역할 맵 (예: ['board.read' => [['role' => 'admin', 'scope_type' => null], ...]])
     * @param  array  $allExtensionPermIdentifiers  이 확장의 모든 권한 식별자 목록
     */
    public function syncAllRoleAssignments(
        array $permissionRoleMap,
        array $allExtensionPermIdentifiers,
    ): void {
        // 역방향 매핑 구축: 역할→권한 맵 (scope_type 포함)
        $rolePermissionMap = [];
        foreach ($permissionRoleMap as $permIdentifier => $roleEntries) {
            foreach ($roleEntries as $roleEntry) {
                // 하위호환: 문자열이면 scope_type=null
                if (is_string($roleEntry)) {
                    $roleIdentifier = $roleEntry;
                    $scopeType = null;
                } else {
                    $roleIdentifier = $roleEntry['role'];
                    $scopeType = $roleEntry['scope_type'] ?? null;
                }
                $rolePermissionMap[$roleIdentifier][] = [
                    'identifier' => $permIdentifier,
                    'scope_type' => $scopeType,
                ];
            }
        }

        // '*' 와일드카드 처리: 모든 활성 역할에 해당 권한 할당
        $wildcardPermEntries = $rolePermissionMap['*'] ?? [];
        unset($rolePermissionMap['*']);

        if (! empty($wildcardPermEntries)) {
            $allRoles = $this->roleRepository->getActiveRoles();
            foreach ($allRoles as $role) {
                // 와일드카드 권한과 해당 역할에 명시된 권한을 병합
                $roleSpecificEntries = $rolePermissionMap[$role->identifier] ?? [];
                $mergedEntries = array_merge($wildcardPermEntries, $roleSpecificEntries);

                // 중복 제거 (identifier 기준)
                $seen = [];
                $uniqueEntries = [];
                foreach ($mergedEntries as $entry) {
                    if (! isset($seen[$entry['identifier']])) {
                        $seen[$entry['identifier']] = true;
                        $uniqueEntries[] = $entry;
                    }
                }

                $this->syncRolePermissions($role, $uniqueEntries, $allExtensionPermIdentifiers);
                // 이미 처리된 역할은 아래 루프에서 건너뛰도록 제거
                unset($rolePermissionMap[$role->identifier]);
            }
        }

        foreach ($rolePermissionMap as $roleIdentifier => $definedPermEntries) {
            $role = $this->roleRepository->findByIdentifier($roleIdentifier);
            if (! $role) {
                continue;
            }
            $this->syncRolePermissions($role, $definedPermEntries, $allExtensionPermIdentifiers);
        }
    }

    /**
     * 단일 역할의 확장 권한 할당을 동기화합니다.
     *
     * user_overrides에 기록된 개별 권한 식별자는 보호하고,
     * 나머지 권한만 DB 기반 diff로 attach/detach합니다.
     *
     * @param  Role  $role  대상 역할
     * @param  array  $definedPermEntries  이 확장이 이 역할에 정의한 권한 목록 [['identifier' => ..., 'scope_type' => ...], ...]
     * @param  array  $allExtensionPermIdentifiers  이 확장의 모든 권한 식별자 목록
     */
    private function syncRolePermissions(
        Role $role,
        array $definedPermEntries,
        array $allExtensionPermIdentifiers,
    ): void {
        $userOverrides = $role->user_overrides ?? [];

        // 정의된 권한 식별자 목록 및 scope_type 맵 구축
        $definedPermIdentifiers = array_map(fn ($e) => $e['identifier'], $definedPermEntries);
        $scopeTypeMap = [];
        foreach ($definedPermEntries as $entry) {
            $scopeTypeMap[$entry['identifier']] = $entry['scope_type'];
        }

        // DB에서 이 역할이 현재 갖고 있는 이 확장의 권한 목록
        $currentExtPermIds = $role->permissions()
            ->whereIn('identifier', $allExtensionPermIdentifiers)
            ->pluck('identifier')
            ->toArray();

        // user_overrides에 기록된 권한 식별자 필터링 (필드명 "name", "description" 등 제외)
        $protectedPermissions = array_intersect($userOverrides, $allExtensionPermIdentifiers);

        // 보호된 권한을 제외하고 diff 계산
        $unprotectedDefined = array_diff($definedPermIdentifiers, $protectedPermissions);
        $unprotectedCurrent = array_diff($currentExtPermIds, $protectedPermissions);

        // 새 권한: 보호되지 않은 정의 중 현재 DB에 없는 것 → attach (scope_type 포함)
        $toAttach = array_diff($unprotectedDefined, $currentExtPermIds);
        foreach ($toAttach as $permIdentifier) {
            $permission = $this->permissionRepository->findByIdentifier($permIdentifier);
            if ($permission) {
                $this->roleRepository->attachPermission($role, $permission->id, [
                    'scope_type' => $scopeTypeMap[$permIdentifier] ?? null,
                    'granted_at' => now(),
                    'granted_by' => Auth::id(),
                ]);
            }
        }

        // 제거된 권한: 보호되지 않은 현재 DB 중 정의에 없는 것 → detach
        $toDetach = array_diff($unprotectedCurrent, $unprotectedDefined);
        foreach ($toDetach as $permIdentifier) {
            $permission = $this->permissionRepository->findByIdentifier($permIdentifier);
            if ($permission) {
                $this->roleRepository->detachPermission($role, $permission->id);
            }
        }
    }
}
