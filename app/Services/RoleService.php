<?php

namespace App\Services;

use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Exceptions\CannotModifyProtectedRoleException;
use App\Exceptions\ExtensionOwnedRoleDeleteException;
use App\Exceptions\RoleActiveStateLockedException;
use App\Exceptions\SystemRoleDeleteException;
use App\Extension\HookManager;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionEscalationGuard;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RoleService
{
    /**
     * RoleService 생성자
     *
     * @param  RoleRepositoryInterface  $roleRepository  역할 리포지토리
     * @param  PermissionEscalationGuard  $escalationGuard  권한 상승 방지 가드 (행위자 보유 범위 밖 권한 부여 차단)
     * @param  MenuService  $menuService  메뉴 서비스 (역할 폼 「접근 가능 메뉴」 동기화)
     */
    public function __construct(
        private RoleRepositoryInterface $roleRepository,
        private PermissionEscalationGuard $escalationGuard,
        private MenuService $menuService
    ) {}

    /**
     * 활성화된 역할 목록을 반환합니다.
     *
     * @return Collection 활성화된 역할 컬렉션
     */
    public function getActiveRoles(): Collection
    {
        return $this->roleRepository->getActiveRoles();
    }

    /**
     * 특정 사용자에게 부여된 활성 역할 목록을 반환합니다.
     *
     * @param  User  $user  사용자 모델
     * @return Collection 사용자의 활성 역할 컬렉션
     */
    public function getUserActiveRoles(User $user): Collection
    {
        return $user->roles()->where('is_active', true)->get();
    }

    /**
     * 모든 역할 목록을 반환합니다.
     *
     * @return Collection 역할 컬렉션
     */
    public function getAllRoles(): Collection
    {
        return $this->roleRepository->getAll();
    }

    /**
     * 역할 목록을 페이지네이션하여 조회합니다.
     *
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 페이지네이션된 역할 목록
     */
    public function getPaginatedRoles(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->roleRepository->getPaginated($filters, $perPage);
    }

    /**
     * 특정 역할을 ID로 조회합니다.
     *
     * @param  int  $id  역할 ID
     * @return Role|null 역할 모델 또는 null
     */
    public function findById(int $id): ?Role
    {
        return $this->roleRepository->findById($id);
    }

    /**
     * 역할 상세 정보를 조회합니다 (권한 포함).
     *
     * @param  Role  $role  역할 모델
     * @return Role 권한이 로드된 역할 모델
     */
    public function getRoleWithPermissions(Role $role): Role
    {
        $role->load(['permissions' => fn ($q) => $q->withPivot('scope_type'), 'users', 'menus' => fn ($q) => $q->select('menus.id')]);
        $role->loadCount('users');

        return $role;
    }

    /**
     * 새로운 역할을 생성합니다.
     *
     * @param  array  $data  역할 생성 데이터
     * @return Role 생성된 역할 모델
     */
    public function createRole(array $data): Role
    {
        // identifier가 없으면 name에서 자동 생성
        if (empty($data['identifier'])) {
            $data['identifier'] = $this->generateIdentifier($data['name']);
        }

        // 권한 목록 분리
        $permissions = $data['permissions'] ?? [];
        unset($data['permissions']);

        // 접근 가능 메뉴 분리 (보내지 않았으면 메뉴를 건드리지 않는다)
        $menuIds = array_key_exists('menu_ids', $data) ? (array) $data['menu_ids'] : null;
        unset($data['menu_ids']);

        // 비밀번호 만료 설정(#736)을 비워 보내면 저장하지 않는다 — 컬럼은 NOT NULL 기본 'inherit' 이고, null 은 "바꾸지 않음" 이다.
        if (array_key_exists('password_expiry_policy', $data) && $data['password_expiry_policy'] === null) {
            unset($data['password_expiry_policy']);
        }

        // 권한 상승 상한을 **역할 생성 전에** 검사한다. 생성 뒤에 검사하면 거부된 요청이
        // 권한 0개짜리 고아 역할 행을 남긴다(사용자 경로와 동일한 "가드 → 쓰기" 순서).
        if (! empty($permissions)) {
            $this->escalationGuard->assertGrantWithinActorCeiling($permissions);
        }

        // 훅: 생성 전
        HookManager::doAction('core.role.before_create', $data);

        // 훅: 생성 데이터 필터
        $data = HookManager::applyFilters('core.role.filter_create_data', $data);

        // 역할 생성·권한·메뉴를 한 번에 — 메뉴 지정이 거부되면 역할 행도 남기지 않는다.
        $role = DB::transaction(function () use ($data, $permissions, $menuIds) {
            $role = $this->roleRepository->create($data);

            if (! empty($permissions)) {
                $this->syncPermissions($role, $permissions);
            }

            if ($menuIds !== null) {
                $this->menuService->syncRoleMenus($role, $menuIds);
            }

            return $role;
        });

        // 훅: 생성 후
        HookManager::doAction('core.role.after_create', $role);

        return $role;
    }

    /**
     * 역할 정보를 업데이트합니다.
     *
     * @param  Role  $role  역할 모델
     * @param  array  $data  업데이트 데이터
     * @return Role 업데이트된 역할 모델
     *
     * @throws RoleActiveStateLockedException 최고관리자 역할을 끄려 할 때
     */
    public function updateRole(Role $role, array $data): Role
    {
        // 보호된 역할(코어/확장 소유) 수정 상한: 삭제 경로와 대칭.
        // 비-슈퍼관리자 액터는 admin 등 코어/확장 소유 역할을 변경할 수 없다.
        $this->assertActorMayModifyRole($role);

        // 최고관리자 역할은 끌 수 없다 — 끄면 운영자 전원의 권한이 회수된다.
        if ($role->isActiveStateLocked() && array_key_exists('is_active', $data) && ! $data['is_active']) {
            throw new RoleActiveStateLockedException;
        }

        // 권한 목록 분리
        $permissions = $data['permissions'] ?? null;
        unset($data['permissions']);

        // 접근 가능 메뉴 분리 (보내지 않았으면 메뉴를 건드리지 않는다)
        $menuIds = array_key_exists('menu_ids', $data) ? (array) $data['menu_ids'] : null;
        unset($data['menu_ids']);

        // 비밀번호 만료 설정(#736)을 비워 보내면 저장하지 않는다 — 컬럼은 NOT NULL 기본 'inherit' 이고, null 은 "바꾸지 않음" 이다.
        if (array_key_exists('password_expiry_policy', $data) && $data['password_expiry_policy'] === null) {
            unset($data['password_expiry_policy']);
        }

        // 권한 상승 상한을 **속성 업데이트 전에** 검사한다. update 뒤에 검사하면
        // 403 을 받은 요청이 name/is_active 변경만 반영된 상태를 남긴다.
        if ($permissions !== null) {
            $this->escalationGuard->assertGrantWithinActorCeiling($permissions);
        }

        // 훅: 업데이트 전 (원본 data 전달)
        HookManager::doAction('core.role.before_update', $role, $data);

        // 스냅샷 캡처 (ChangeDetector용)
        $snapshot = $role->toArray();

        // 훅: 업데이트 데이터 필터
        $data = HookManager::applyFilters('core.role.filter_update_data', $data, $role);

        // 속성·권한·메뉴를 한 트랜잭션으로 — 메뉴 지정이 거부(422)되면 이름·권한 변경도 남기지 않는다.
        DB::transaction(function () use ($role, $data, $permissions, $menuIds) {
            $this->roleRepository->update($role, $data);

            // 권한 동기화 (null이 아닌 경우에만)
            if ($permissions !== null) {
                $this->syncPermissions($role, $permissions);
            }

            if ($menuIds !== null) {
                $this->menuService->syncRoleMenus($role, $menuIds);
            }
        });

        $role->refresh();

        // 훅: 업데이트 후 (스냅샷 전달)
        HookManager::doAction('core.role.after_update', $role, $snapshot);

        return $role;
    }

    /**
     * 역할을 삭제합니다.
     *
     * @param  Role  $role  역할 모델
     * @return bool 삭제 성공 여부
     *
     * @throws SystemRoleDeleteException 코어 역할 삭제 시도 시
     * @throws ExtensionOwnedRoleDeleteException 확장 소유 역할 삭제 시도 시
     */
    public function deleteRole(Role $role): bool
    {
        // 코어 역할 삭제 불가
        if ($role->isCore()) {
            throw new SystemRoleDeleteException;
        }

        // 확장(모듈/플러그인) 소유 역할 삭제 불가
        if ($role->isExtensionOwned()) {
            throw new ExtensionOwnedRoleDeleteException;
        }

        // 관계 해제 후 삭제가 실패하면 그 역할을 가진 사용자 전원이 권한만 잃고
        // 역할은 그대로 남는다. 전 단계를 하나로 묶는다.
        $result = DB::transaction(function () use ($role) {
            // 훅: 삭제 전
            HookManager::doAction('core.role.before_delete', $role);

            // 관계 해제 (명시적 삭제 - CASCADE 의존 금지)
            $this->roleRepository->detachAllPermissions($role);
            $role->menus()->detach();
            $role->users()->detach();

            // 역할 삭제
            return $this->roleRepository->delete($role);
        });

        // 훅: 삭제 후
        HookManager::doAction('core.role.after_delete', $role->id);

        return $result;
    }

    /**
     * 역할에 권한을 동기화합니다.
     *
     * @param  Role  $role  역할 모델
     * @param  array  $permissions  권한 배열 [{id, scope_type}, ...]
     * @return void 반환값 없음
     */
    public function syncPermissions(Role $role, array $permissions): void
    {
        // 보호된 역할(코어/확장 소유) 수정 상한: updateRole·toggleRoleStatus 와 대칭.
        // 이 메서드는 public 이므로 서비스를 주입한 확장이 직접 호출할 수 있다 —
        // 형제 경로에만 가드를 두면 여기가 코어 역할 보호의 우회로가 된다.
        $this->assertActorMayModifyRole($role);

        // 권한 상승 상한(ceiling): 비-슈퍼관리자 액터는 자신이 보유하지 않았거나
        // 자신의 범위(scope)보다 넓은 범위의 권한을 부여할 수 없다(SSoT 가드에 위임).
        $this->escalationGuard->assertGrantWithinActorCeiling($permissions);

        // 동기화 전 현재 권한 식별자 캡처 (Listener diff 계산용)
        $previousPermIdentifiers = $role->permissions()->pluck('identifier')->toArray();

        $pivotData = [];
        $grantedBy = Auth::id();

        foreach ($permissions as $permission) {
            $pivotData[$permission['id']] = [
                'scope_type' => $permission['scope_type'] ?? null,
                'granted_at' => now(),
                'granted_by' => $grantedBy,
            ];
        }

        $role->permissions()->sync($pivotData);

        // 동기화 후 현재 권한 식별자
        $currentPermIdentifiers = $role->permissions()->pluck('identifier')->toArray();

        // 훅: 권한 동기화 후 (이전/이후 식별자 모두 전달)
        HookManager::doAction('core.role.after_sync_permissions', $role, $previousPermIdentifiers, $currentPermIdentifiers);
    }

    /**
     * 역할의 활성화 상태를 토글합니다.
     *
     * @param  Role  $role  역할 모델
     * @return bool 토글 성공 여부
     *
     * @throws RoleActiveStateLockedException 최고관리자 역할일 때
     */
    public function toggleRoleStatus(Role $role): bool
    {
        // 보호된 역할(코어/확장 소유) 상태변경 상한: 삭제 경로와 대칭.
        $this->assertActorMayModifyRole($role);

        // 최고관리자 역할은 사용 여부를 바꿀 수 없다 — 끄면 운영자 전원의 권한이 회수된다.
        if ($role->isActiveStateLocked()) {
            throw new RoleActiveStateLockedException;
        }

        $newStatus = ! $role->is_active;

        // 훅: 상태 변경 전
        HookManager::doAction('core.role.before_toggle_status', $role, $newStatus);

        $result = $this->roleRepository->update($role, [
            'is_active' => $newStatus,
        ]);

        // 훅: 상태 변경 후
        HookManager::doAction('core.role.after_toggle_status', $role);

        return $result;
    }

    /**
     * 액터가 보호된 역할(코어/확장 소유)을 수정할 수 있는지 확인합니다.
     *
     * 인증 액터가 없으면(Artisan/내부 시더) 신뢰 경로로 간주해 통과시킵니다.
     * 슈퍼 관리자는 모든 역할을 수정할 수 있고, 그 외 액터는 삭제 경로와 동일하게
     * 코어/확장 소유 역할을 수정할 수 없습니다.
     *
     * @param  Role  $role  대상 역할
     * @return void 반환값 없음
     *
     * @throws CannotModifyProtectedRoleException 상한 위반 시
     */
    private function assertActorMayModifyRole(Role $role): void
    {
        $actor = Auth::user();

        // 판정은 모델 한 곳(Role::isModifiableBy)에 둔다 — 응답의 abilities 가 같은 판정을
        // 읽어야 화면의 수정·토글 버튼이 서버 수락 여부와 어긋나지 않는다.
        // 인증 액터 부재(내부/Artisan 신뢰 경로)·슈퍼 관리자는 통과, 그 외는 보호 역할 수정 불가.
        if (! $role->isModifiableBy($actor instanceof User ? $actor : null)) {
            throw new CannotModifyProtectedRoleException;
        }
    }

    /**
     * name에서 identifier를 자동 생성합니다.
     *
     * @param  array|string  $name  역할 이름
     * @return string 생성된 identifier
     */
    private function generateIdentifier(array|string $name): string
    {
        // 배열인 경우 영어 이름 또는 첫 번째 값 사용
        if (is_array($name)) {
            $baseName = $name['en'] ?? $name['ko'] ?? reset($name);
        } else {
            $baseName = $name;
        }

        // 슬러그 형태로 변환
        $identifier = Str::slug($baseName, '_');

        // 중복 체크 및 고유 identifier 생성
        $originalIdentifier = $identifier;
        $counter = 1;

        while ($this->roleRepository->findByIdentifier($identifier)) {
            $identifier = "{$originalIdentifier}_{$counter}";
            $counter++;
        }

        return $identifier;
    }
}
