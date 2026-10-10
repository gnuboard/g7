<?php

namespace App\Extension\Helpers;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Contracts\Repositories\PermissionRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Model;
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

        // 기존 역할 업데이트 — 판정은 모델 공통 규칙(HasUserOverrides::planUpgrade)이 한다({@see planRoleUpgrade()}). 운영자 수정 기록은
        // 다국어 dot-path(`name.ko`)라 종전의 `in_array('name', …)` 정확 일치 판정은 늘 "미수정" 이었다 —
        // 역할 이름·설명 수정이 업데이트마다 되돌아간 원인(#727 D5-①). is_active 도 여기서 함께 보존된다(W10).
        $updateData = array_merge([
            'extension_type' => $extensionType,
            'extension_identifier' => $extensionIdentifier,
        ], self::planRoleUpgrade($existing, array_merge([
            'name' => $newName,
            'description' => $newDescription,
        ], $otherAttributes)));

        // user_overrides 자동 마킹 비활성화 — 시스템 sync 컨텍스트는 사용자 변경이 아님.
        // ExtensionMenuSyncHelper 와 동일 패턴 (HasUserOverrides::bootHasUserOverrides 의
        // updating 이벤트 hook 이 'seeding' 플래그를 보고 자동 마킹을 건너뛴다).
        // 미설정 시 동일한 정의값을 적용해도 name/description 등 trackable 필드가 dirty 로
        // 잡혀 user_overrides 에 자동 추가되어 이후 sync 가 차단되는 결함이 발생한다.
        app()->instance('user_overrides.seeding', true);
        try {
            $this->roleRepository->update($existing, $updateData);
        } finally {
            app()->forgetInstance('user_overrides.seeding');
        }

        return $existing->fresh();
    }

    /**
     * 기존 역할에 반영할 갱신 값을 계산합니다 — 운영자가 고친 칸은 보존합니다.
     *
     * 판정은 모델 공통 규칙(`HasUserOverrides::planUpgrade`)을 쓴다. 다만 7.0.x 에서 올리는 코어 업데이트의 부모 프로세스는
     * 옛 `Role`(planUpgrade 가 없는 트레이트)을 이미 메모리에 올린 채 이 새 헬퍼를 불러, 메서드가 없어 코어 역할·권한
     * 재동기화 전체가 멈췄다(새 권한이 어느 역할에도 연결되지 않음 — 7.1.0 출시 점검 CU-3). 그때는 보수적으로 계산한다:
     * 운영자 수정 표식(`user_overrides`)에 칸 이름이 있으면 그 칸을 통째로 두고, `칸.언어` 표식이면 그 언어 값만 두고
     * 나머지 언어는 새 값으로 바꾼다. 표식 해제(합의 해제)는 하지 않는다 — 다음 정상 업데이트가 처리한다.
     *
     * @param  Model  $existing  기존 역할 모델
     * @param  array<string, mixed>  $newAttributes  선언 값
     * @return array<string, mixed> 갱신할 속성
     */
    public static function planRoleUpgrade(Model $existing, array $newAttributes): array
    {
        if (method_exists($existing, 'planUpgrade')) {
            return $existing->planUpgrade($newAttributes);
        }

        $overrides = $existing->getAttribute('user_overrides');
        if (is_string($overrides)) {
            $overrides = json_decode($overrides, true);
        }
        $overrides = is_array($overrides) ? $overrides : [];

        $updateData = [];
        foreach ($newAttributes as $field => $value) {
            if (in_array($field, $overrides, true)) {
                continue;
            }

            $kept = [];
            foreach ($overrides as $entry) {
                if (is_string($entry) && str_starts_with($entry, $field.'.')) {
                    $kept[] = substr($entry, strlen($field) + 1);
                }
            }

            if ($kept === [] || ! is_array($value)) {
                $updateData[$field] = $value;

                continue;
            }

            $current = $existing->getAttribute($field);
            if (is_string($current)) {
                $current = json_decode($current, true);
            }
            $current = is_array($current) ? $current : [];

            foreach ($kept as $locale) {
                if (array_key_exists($locale, $current)) {
                    $value[$locale] = $current[$locale];
                }
            }
            $updateData[$field] = $value;
        }

        return $updateData;
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
     * Permission 은 설계상 **관리자가 직접 수정할 수 없는 테이블** 이므로 `user_overrides`
     * 컬럼 자체가 없습니다 (Menu/Role 과 달리). 따라서 단순 diff 로 삭제합니다.
     * 자식 권한도 동일하게 순수 orphan 삭제.
     *
     * 자동 호출 경로:
     *  - `CoreUpdateService::syncCoreRolesAndPermissions()` 말미 (완전 동기화 원칙)
     *  - `ModuleManager::updateModule()` 말미
     *  - UpgradeStep 에서 명시 호출
     *
     * @param  ExtensionOwnerType  $extensionType  확장 타입
     * @param  string  $extensionIdentifier  확장 식별자
     * @param  array  $currentIdentifiers  현재 유효한 권한 식별자 목록
     * @return int 삭제된 권한 수
     *
     * @see UpgradeStepInterface
     */
    public function cleanupStalePermissions(
        ExtensionOwnerType $extensionType,
        string $extensionIdentifier,
        array $currentIdentifiers,
    ): int {
        $existingPermissions = $this->permissionRepository->getByExtension($extensionType, $extensionIdentifier);

        $deleted = 0;
        foreach ($existingPermissions as $permission) {
            if (in_array($permission->identifier, $currentIdentifiers, true)) {
                continue;
            }

            // 역할 연결 해제
            $permission->roles()->detach();

            // 자식 권한도 정리 (orphan 회피)
            foreach ($permission->children as $child) {
                $child->roles()->detach();
                $this->permissionRepository->delete($child);
                $deleted++;
            }

            $this->permissionRepository->delete($permission);
            $deleted++;
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
     * 현재 확장에 속하지 않는 stale 역할을 정리합니다.
     *
     * 정책:
     *  - config/확장 정의에 없는 역할은 **user_overrides 유무 무관 삭제**
     *  - 필드 단위 보존은 upsert 시점(`syncRole()`) 에서만 작동
     *  - 예외: `user_roles` 피벗에 참조 사용자가 있으면 **삭제 차단** + 경고 로그
     *    (사용자를 다른 역할로 수동 재배정 후 재실행)
     *
     * @param  ExtensionOwnerType  $extensionType  확장 타입
     * @param  string  $extensionIdentifier  확장 식별자
     * @param  array  $currentIdentifiers  현재 유효한 역할 식별자 목록
     * @return int 삭제된 역할 수
     */
    public function cleanupStaleRoles(
        ExtensionOwnerType $extensionType,
        string $extensionIdentifier,
        array $currentIdentifiers,
    ): int {
        $existingRoles = $this->roleRepository->getByExtension($extensionType, $extensionIdentifier);

        $deleted = 0;
        $blocked = 0;

        foreach ($existingRoles as $role) {
            if (in_array($role->identifier, $currentIdentifiers, true)) {
                continue;
            }

            // user_roles 피벗 참조 검사 — 하나라도 있으면 삭제 차단 (안전 가드)
            $userCount = $role->users()->count();
            if ($userCount > 0) {
                Log::warning('stale 역할 삭제 차단 — 참조 사용자 존재', [
                    'identifier' => $role->identifier,
                    'id' => $role->id,
                    'user_count' => $userCount,
                    'remediation' => '사용자를 다른 역할로 재배정 후 재실행 필요',
                ]);
                $blocked++;

                continue;
            }

            // 조건 통과 → 삭제 (role_permissions, role_menus 는 cascade 또는 모델 이벤트로 정리)
            $this->roleRepository->delete($role);
            Log::info('stale 역할 삭제', [
                'identifier' => $role->identifier,
                'id' => $role->id,
            ]);
            $deleted++;
        }

        if ($deleted > 0 || $blocked > 0) {
            Log::info('stale 역할 정리 결과', [
                'extension_type' => $extensionType->value,
                'extension_identifier' => $extensionIdentifier,
                'deleted' => $deleted,
                'blocked' => $blocked,
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
