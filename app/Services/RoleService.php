<?php

namespace App\Services;

use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Exceptions\ExtensionOwnedRoleDeleteException;
use App\Exceptions\SystemRoleDeleteException;
use App\Extension\HookManager;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class RoleService
{
    public function __construct(
        private RoleRepositoryInterface $roleRepository
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
        $role->load(['permissions' => fn ($q) => $q->withPivot('scope_type'), 'users']);
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

        // 훅: 생성 전
        HookManager::doAction('core.role.before_create', $data);

        // 훅: 생성 데이터 필터
        $data = HookManager::applyFilters('core.role.filter_create_data', $data);

        // 역할 생성
        $role = $this->roleRepository->create($data);

        // 권한 할당
        if (! empty($permissions)) {
            $this->syncPermissions($role, $permissions);
        }

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
     */
    public function updateRole(Role $role, array $data): Role
    {
        // 권한 목록 분리
        $permissions = $data['permissions'] ?? null;
        unset($data['permissions']);

        // 훅: 업데이트 전 (원본 data 전달)
        HookManager::doAction('core.role.before_update', $role, $data);

        // 스냅샷 캡처 (ChangeDetector용)
        $snapshot = $role->toArray();

        // 훅: 업데이트 데이터 필터
        $data = HookManager::applyFilters('core.role.filter_update_data', $data, $role);

        // 역할 정보 업데이트
        $this->roleRepository->update($role, $data);

        // 권한 동기화 (null이 아닌 경우에만)
        if ($permissions !== null) {
            $this->syncPermissions($role, $permissions);
        }

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

        // 훅: 삭제 전
        HookManager::doAction('core.role.before_delete', $role);

        // 관계 해제 (명시적 삭제 - CASCADE 의존 금지)
        $this->roleRepository->detachAllPermissions($role);
        $role->menus()->detach();
        $role->users()->detach();

        // 역할 삭제
        $result = $this->roleRepository->delete($role);

        // 훅: 삭제 후
        HookManager::doAction('core.role.after_delete', $role->id);

        return $result;
    }

    /**
     * 역할에 권한을 동기화합니다.
     *
     * @param  Role  $role  역할 모델
     * @param  array  $permissions  권한 배열 [{id, scope_type}, ...]
     */
    public function syncPermissions(Role $role, array $permissions): void
    {
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
     */
    public function toggleRoleStatus(Role $role): bool
    {
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
