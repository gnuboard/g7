<?php

namespace App\Support;

use App\Contracts\Repositories\PermissionRepositoryInterface;
use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Exceptions\PermissionEscalationException;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * 권한 상승(escalation) 상한 가드 (SSoT)
 *
 * 비-슈퍼관리자 액터가 "자신이 보유하지 않았거나 자신의 범위(scope)보다 넓은" 권한을
 * 부여하는 것을 차단합니다(KVE-2026-1919). 권한을 직접 역할에 부여하는 경로(RoleService)와
 * 역할 자체를 사용자에게 붙여 그 역할의 권한을 통째로 넘기는 경로(UserService)가 **동일한
 * 상한 규칙**을 공유하도록, 판정을 한 지점에 모읍니다. 한 경로만 막으면 다른 경로가 우회로가
 * 됩니다(역할 부여는 곧 그 역할의 전 권한 부여이므로 권한 부여와 같은 상한을 받아야 합니다).
 */
class PermissionEscalationGuard
{
    /**
     * @param  PermissionRepositoryInterface  $permissionRepository  권한 조회
     * @param  RoleRepositoryInterface  $roleRepository  역할 조회
     */
    public function __construct(
        private PermissionRepositoryInterface $permissionRepository,
        private RoleRepositoryInterface $roleRepository
    ) {}

    /**
     * 부여하려는 권한이 액터의 상한(보유 + 범위)을 넘지 않는지 확인합니다.
     *
     * 슈퍼 관리자와 내부/Artisan 경로는 상한 검사 없이 통과합니다. 비-슈퍼관리자
     * 액터는 자신이 보유한 권한만, 그리고 자신의 effective scope 보다 넓지 않은
     * 범위로만 부여할 수 있습니다(권한 상승 차단).
     *
     * @param  array<int, array{id?: int, scope_type?: string|null}>  $permissions  부여 권한 배열
     *
     * @throws PermissionEscalationException 상한 위반 시
     */
    public function assertGrantWithinActorCeiling(array $permissions): void
    {
        $actor = Auth::user();

        // 인증 액터 부재 = 내부/Artisan 신뢰 경로 → 상한 미적용
        if (! $actor instanceof User) {
            return;
        }

        // 슈퍼 관리자는 상한 없음
        if ($actor->isSuperAdmin()) {
            return;
        }

        if (empty($permissions)) {
            return;
        }

        // 부여 대상 권한 ID → 식별자 매핑
        $ids = array_values(array_filter(array_map(
            static fn ($p) => $p['id'] ?? null,
            $permissions
        )));
        $identifierById = $this->permissionRepository->getByIds($ids)
            ->keyBy('id')
            ->map(static fn ($permission) => $permission->identifier);

        foreach ($permissions as $permission) {
            $id = $permission['id'] ?? null;
            $identifier = $id !== null ? ($identifierById[$id] ?? null) : null;

            // 식별자를 해석할 수 없는 권한은 안전하게 거부
            if ($identifier === null) {
                throw new PermissionEscalationException;
            }

            // 액터가 보유하지 않은 권한은 부여 불가
            if (! $actor->hasPermission($identifier)) {
                throw new PermissionEscalationException;
            }

            // 부여 범위가 액터의 effective scope 보다 넓으면 불가
            $requestedScope = $permission['scope_type'] ?? null;
            $actorScope = $actor->getEffectiveScopeForPermission($identifier);

            if ($this->scopeRank($requestedScope) > $this->scopeRank($actorScope)) {
                throw new PermissionEscalationException;
            }
        }
    }

    /**
     * 사용자에게 부여하려는 역할들이 액터의 상한을 넘지 않는지 확인합니다.
     *
     * 역할을 붙이는 것은 그 역할이 담은 권한 전부를 부여하는 것과 같으므로, 각 역할의
     * 권한을 그 pivot scope 와 함께 펼쳐 권한 부여 상한(assertGrantWithinActorCeiling)을
     * 그대로 적용합니다. 호출측은 **이번 조작으로 변경되는(추가·제거) 역할만** 전달해야 합니다 —
     * 기존 유지 역할은 변경이 아니므로 제외합니다. 제거 방향도 같은 상한을 받는 이유는, 상위
     * 역할을 박탈하는 하향 조작 역시 액터가 권한을 갖지 못한 역할 구성에 대한 조작이기 때문입니다.
     *
     * @param  array<int, int>  $roleIds  이번 조작으로 변경되는(추가·제거) 역할 ID 목록
     *
     * @throws PermissionEscalationException 상한 위반 시
     */
    public function assertRoleAssignmentWithinActorCeiling(array $roleIds): void
    {
        $actor = Auth::user();

        if (! $actor instanceof User) {
            return;
        }

        if ($actor->isSuperAdmin()) {
            return;
        }

        $roleIds = array_values(array_filter($roleIds, static fn ($id) => $id !== null));

        if (empty($roleIds)) {
            return;
        }

        // 시스템 baseline 역할('user')은 상한에서 면제한다.
        //
        // 'user' 는 모든 회원이 갖는 기본 역할로, core.permissions.update 가 없는 액터의
        // 생성 경로가 상한 검사 없이 자동 배정하는 바로 그 역할이다(UserService::createUser).
        // 그 역할의 권한은 알림 self-service·본인인증 등 회원 baseline 뿐이라 액터가
        // 그것을 "부여" 해도 권한 상승 벡터가 되지 않는다. baseline 을 검사에 넣으면
        // core.permissions.update 를 가진(=더 권한 있는) 액터가 baseline 을 명시 지정했을 때만
        // 403 이 되어, 권한 낮은 액터의 auto-assign 경로와 비대칭으로 정상 회원 생성/수정이
        // 깨진다. 비-baseline 역할은 여전히 전량 상한 검사를 받는다.
        $baselineRoleId = $this->roleRepository->findByIdentifier('user')?->id;

        // 부여 역할들의 권한을 [{id, scope_type}] 로 펼친다
        $permissions = [];
        foreach ($roleIds as $roleId) {
            if ($baselineRoleId !== null && (int) $roleId === (int) $baselineRoleId) {
                continue;
            }

            $role = $this->roleRepository->findById((int) $roleId);

            // 존재하지 않는 역할은 안전하게 거부
            if ($role === null) {
                throw new PermissionEscalationException;
            }

            foreach ($role->permissions as $permission) {
                $permissions[] = [
                    'id' => $permission->id,
                    'scope_type' => $permission->pivot->scope_type ?? null,
                ];
            }
        }

        $this->assertGrantWithinActorCeiling($permissions);
    }

    /**
     * scope_type 의 넓이 순위를 반환합니다 (클수록 넓음).
     *
     * null(전체) > 'role'(소유역할) > 'self'(본인) 순으로, User::getEffectiveScopeForPermission
     * 의 union 우선순위와 동일한 서열을 사용합니다.
     *
     * @param  string|null  $scope  범위 문자열
     * @return int 넓이 순위 (2=전체, 1=role, 0=self)
     */
    private function scopeRank(?string $scope): int
    {
        return match ($scope) {
            null => 2,
            'role' => 1,
            default => 0,
        };
    }
}
