<?php

namespace App\Extension\Helpers;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Contracts\Repositories\PermissionRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Extension\Helpers\Concerns\RecordsSyncDeletion;
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
    use RecordsSyncDeletion;

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
     * 재동기화 전체가 멈췄다(새 권한이 어느 역할에도 연결되지 않음 — 7.1.0 출시 점검). 그때는 보수적으로 계산한다:
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
        // 검수 서버(stage)는 공유 DB 의 선언 항목을 지우지 않고 대장에 미룬다 — 운영 서버가 extension:apply-deferred-cleanup 으로 같은 규칙대로 지운다
        if (DeferredDeclarativeCleanup::shouldDefer()) {
            return DeferredDeclarativeCleanup::defer($this, __FUNCTION__, func_get_args());
        }

        $existingPermissions = $this->permissionRepository->getByExtension($extensionType, $extensionIdentifier);

        $deleted = 0;
        foreach ($existingPermissions as $permission) {
            if (in_array($permission->identifier, $currentIdentifiers, true)) {
                continue;
            }

            if ($this->permissionRepository->findById((int) $permission->id) === null) {
                continue;
            }

            $deleted += $this->deletePermissionSubtree($permission, $extensionType, $extensionIdentifier, $currentIdentifiers);
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
        // 검수 서버(stage)는 공유 DB 의 선언 항목을 지우지 않고 대장에 미룬다 — 운영 서버가 extension:apply-deferred-cleanup 으로 같은 규칙대로 지운다
        if (DeferredDeclarativeCleanup::shouldDefer()) {
            return DeferredDeclarativeCleanup::defer($this, __FUNCTION__, func_get_args());
        }

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

            // 조건 통과 → 운영자가 고친 역할이면 기록 → 연결(role_permissions·role_menus·menu_permissions)을 명시적으로 뗀 뒤 삭제
            if ($this->hasOperatorOverrides($role)) {
                $this->recordSyncDeletion('role', (string) $role->identifier, $extensionType->value, $extensionIdentifier, [
                    'role' => $role->toArray(),
                    'permission_ids' => $role->permissions()->pluck('permissions.id')->all(),
                    'menu_ids' => $role->menus()->pluck('menus.id')->all(),
                ]);
            }

            $role->permissions()->detach();
            $role->menus()->detach();
            $this->roleRepository->deleteMenuPermissions($role);
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
            $scopeType = $entry['scope_type'];

            // 비회원 역할은 권한 범위를 가질 수 없다(7.1.1) — 확장 정의(와일드카드 '*' 포함)가 범위를 주면
            // 범위 없이 부여하고 경고만 남긴다. 설치·업데이트를 멈추면 확장 하나 때문에 사이트가 막힌다.
            if ($scopeType !== null && ! $role->supportsPermissionScope()) {
                Log::warning('비회원 역할에는 권한 범위를 지정할 수 없어 범위 없이 부여합니다', [
                    'role' => $role->identifier,
                    'permission' => $entry['identifier'],
                    'scope_type' => $scopeType instanceof \BackedEnum ? $scopeType->value : $scopeType,
                ]);
                $scopeType = null;
            }

            $scopeTypeMap[$entry['identifier']] = $scopeType;
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
            if ($permission && DeferredDeclarativeCleanup::shouldDefer()) {
                // 검수 서버(stage)는 역할 권한을 회수하지 않고 미룬다 — 운영 서버 옛 화면의 그 역할 사용자가 기능을 잃지 않게
                DeferredDeclarativeCleanup::deferOwner($permission->extension_type, $permission->extension_identifier);

                continue;
            }
            if ($permission) {
                $this->roleRepository->detachPermission($role, $permission->id);
            }
        }
    }

    /**
     * 선언에서 빠진 권한 하나를 하위 트리째 명시적으로 지웁니다.
     *
     * 순서: 역할 부여(`role_permissions`)·훅 매핑(`permission_hooks`) → 깊은 자식부터 → 본 권한. 새 판이 여전히 선언한 자식이나
     * 다른 출처 자식은 지우지 않고 부모를 비운다. 역할에 부여된 권한이면 지우기 직전 `permission.sync_deleted` 로 부여 목록을 남긴다.
     *
     * @param  Permission  $permission  선언에서 빠진 권한
     * @param  ExtensionOwnerType  $extensionType  소유 확장 종류
     * @param  string  $extensionIdentifier  소유 확장 식별자
     * @param  array<int, string>  $currentIdentifiers  새 판이 선언한 권한 식별자
     * @return int 지운 권한 수
     */
    private function deletePermissionSubtree(Permission $permission, ExtensionOwnerType $extensionType, string $extensionIdentifier, array $currentIdentifiers): int
    {
        [$owned, $lifted] = $this->partitionPermissionSubtree($permission, $extensionType, $extensionIdentifier, $currentIdentifiers);
        $grants = [];

        foreach ($owned as $node) {
            $roleIds = $node->roles()->pluck('roles.id')->all();
            if ($roleIds !== []) {
                $grants[(string) $node->identifier] = $roleIds;
            }
        }

        if ($grants !== []) {
            $this->recordSyncDeletion('permission', (string) $permission->identifier, $extensionType->value, $extensionIdentifier, [
                'permissions' => array_map(fn (Permission $p) => $p->toArray(), $owned),
                'role_grants' => $grants,
            ]);
        }

        foreach ($lifted as $child) {
            $this->permissionRepository->update($child, ['parent_id' => null]);
        }

        foreach (array_reverse($owned) as $node) {
            $node->roles()->detach();
            $node->hooks()->delete();
            $this->permissionRepository->delete($node);
        }

        return count($owned);
    }

    /**
     * 권한 하위 트리를 「같이 지울 것」과 「부모를 비울 것」으로 나눕니다 (부모 → 자식 순).
     *
     * @param  Permission  $permission  뿌리 권한
     * @param  ExtensionOwnerType  $extensionType  소유 확장 종류
     * @param  string  $extensionIdentifier  소유 확장 식별자
     * @param  array<int, string>  $currentIdentifiers  새 판이 선언한 권한 식별자
     * @return array{0: list<Permission>, 1: list<Permission>} [지울 권한(뿌리 포함), 부모를 비울 자식]
     */
    private function partitionPermissionSubtree(Permission $permission, ExtensionOwnerType $extensionType, string $extensionIdentifier, array $currentIdentifiers): array
    {
        $owned = [$permission];
        $lifted = [];
        $queue = [$permission];

        while ($queue !== []) {
            $node = array_shift($queue);

            foreach ($this->permissionRepository->getChildrenByParent((int) $node->id) as $child) {
                $childType = $child->extension_type instanceof ExtensionOwnerType ? $child->extension_type : ExtensionOwnerType::tryFrom((string) $child->extension_type);
                $sameOwner = $childType === $extensionType && (string) $child->extension_identifier === $extensionIdentifier;

                if (! $sameOwner || in_array($child->identifier, $currentIdentifiers, true)) {
                    $lifted[] = $child;

                    continue;
                }

                $owned[] = $child;
                $queue[] = $child;
            }
        }

        return [$owned, $lifted];
    }

    /**
     * 지워질 stale 권한·역할을 미리 계산합니다 — 쓰기 없음, 실제 정리와 같은 판정식.
     *
     * @param  ExtensionOwnerType  $extensionType  소유 확장 종류
     * @param  string  $extensionIdentifier  소유 확장 식별자
     * @param  array<int, string>|null  $currentPermissions  새 판이 선언한 권한 식별자 (null 이면 권한은 보지 않음)
     * @param  array<int, string>|null  $currentRoles  새 판이 선언한 역할 식별자 (null 이면 역할은 보지 않음)
     * @return list<array{type: string, key: string, has_operator_overrides: bool}> 삭제 예정
     */
    public function planCleanup(ExtensionOwnerType $extensionType, string $extensionIdentifier, ?array $currentPermissions, ?array $currentRoles): array
    {
        $plan = [];
        $seen = [];

        if ($currentPermissions !== null) {
            foreach ($this->permissionRepository->getByExtension($extensionType, $extensionIdentifier) as $permission) {
                if (in_array($permission->identifier, $currentPermissions, true) || isset($seen[$permission->id])) {
                    continue;
                }

                [$owned] = $this->partitionPermissionSubtree($permission, $extensionType, $extensionIdentifier, $currentPermissions);
                $granted = false;
                foreach ($owned as $node) {
                    $seen[$node->id] = true;
                    $granted = $granted || $node->roles()->exists();
                }

                $plan[] = ['type' => 'permission', 'key' => (string) $permission->identifier, 'has_operator_overrides' => $granted];
            }
        }

        if ($currentRoles !== null) {
            foreach ($this->roleRepository->getByExtension($extensionType, $extensionIdentifier) as $role) {
                if (in_array($role->identifier, $currentRoles, true) || $role->users()->exists()) {
                    continue;
                }

                $plan[] = ['type' => 'role', 'key' => (string) $role->identifier, 'has_operator_overrides' => $this->hasOperatorOverrides($role)];
            }
        }

        return $plan;
    }
}
