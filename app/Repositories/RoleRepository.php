<?php

namespace App\Repositories;

use App\Contracts\Repositories\RoleRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Models\Role;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class RoleRepository implements RoleRepositoryInterface
{
    /**
     * 모든 역할을 조회합니다.
     *
     * @return Collection 역할 컬렉션
     */
    public function getAll(): Collection
    {
        return Role::with(['permissions'])
            ->orderBy('id')
            ->get();
    }

    /**
     * 활성화된 역할들만 조회합니다.
     *
     * @return Collection 활성화된 역할 컬렉션
     */
    public function getActiveRoles(): Collection
    {
        return Role::where('is_active', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * ID로 역할을 찾습니다.
     *
     * @param  int  $id  역할 ID
     * @return Role|null 찾은 역할 모델 또는 null
     */
    public function findById(int $id): ?Role
    {
        return Role::with(['permissions'])->find($id);
    }

    /**
     * 식별자로 역할을 찾습니다.
     *
     * @param  string  $identifier  역할 식별자
     * @return Role|null 찾은 역할 모델 또는 null
     */
    public function findByIdentifier(string $identifier): ?Role
    {
        return Role::where('identifier', $identifier)->first();
    }

    /**
     * 새로운 역할을 생성합니다.
     *
     * @param  array  $data  역할 생성 데이터
     * @return Role 생성된 역할 모델
     */
    public function create(array $data): Role
    {
        return Role::create($data);
    }

    /**
     * 역할을 생성하거나 업데이트합니다.
     *
     * @param  array  $attributes  조회 조건
     * @param  array  $values  생성/업데이트할 데이터
     * @return Role 생성 또는 업데이트된 역할 모델
     */
    public function updateOrCreate(array $attributes, array $values): Role
    {
        return Role::updateOrCreate($attributes, $values);
    }

    /**
     * 기존 역할을 업데이트합니다.
     *
     * @param  Role  $role  업데이트할 역할 모델
     * @param  array  $data  업데이트할 데이터
     * @return bool 업데이트 성공 여부
     */
    public function update(Role $role, array $data): bool
    {
        return $role->update($data);
    }

    /**
     * 역할을 삭제합니다.
     *
     * @param  Role  $role  삭제할 역할 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(Role $role): bool
    {
        return $role->delete();
    }

    /**
     * 확장이 소유한 역할을 식별자로 찾습니다.
     *
     * @param  string  $identifier  역할 식별자
     * @param  ExtensionOwnerType  $extensionType  확장 타입
     * @param  string  $extensionIdentifier  확장 식별자
     * @return Role|null 찾은 역할 모델 또는 null
     */
    public function findExtensionRoleByIdentifier(string $identifier, ExtensionOwnerType $extensionType, string $extensionIdentifier): ?Role
    {
        return Role::where('identifier', $identifier)
            ->where('extension_type', $extensionType)
            ->where('extension_identifier', $extensionIdentifier)
            ->first();
    }

    /**
     * 역할에 권한을 할당합니다.
     *
     * 기존 권한을 유지하면서 새 권한만 추가합니다.
     *
     * @param  Role  $role  역할 모델
     * @param  int  $permissionId  권한 ID
     * @param  array  $pivotData  피벗 테이블에 저장할 추가 데이터
     */
    public function attachPermission(Role $role, int $permissionId, array $pivotData = []): void
    {
        $role->permissions()->syncWithoutDetaching([
            $permissionId => $pivotData,
        ]);
    }

    /**
     * 역할에서 권한을 해제합니다.
     *
     * @param  Role  $role  역할 모델
     * @param  int  $permissionId  권한 ID
     * @return int 해제된 권한 수
     */
    public function detachPermission(Role $role, int $permissionId): int
    {
        return $role->permissions()->detach($permissionId);
    }

    /**
     * 역할의 모든 권한을 해제합니다.
     *
     * @param  Role  $role  역할 모델
     * @return int 해제된 권한 수
     */
    public function detachAllPermissions(Role $role): int
    {
        return $role->permissions()->detach();
    }

    /**
     * 역할 목록을 페이지네이션하여 조회합니다.
     *
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 페이지네이션된 역할 목록
     */
    public function getPaginated(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Role::query()
            ->withCount('users')
            ->with('permissions');

        // 검색 필터
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('identifier', 'like', "%{$search}%")
                    ->orWhere('name->ko', 'like', "%{$search}%")
                    ->orWhere('name->en', 'like', "%{$search}%");
            });
        }

        // 활성화 상태 필터
        if (isset($filters['is_active'])) {
            $query->where('is_active', $filters['is_active']);
        }

        // 정렬 (코어/확장 소유 역할 먼저, 그 다음 사용자 생성 역할)
        $query->orderByRaw('CASE WHEN extension_type IS NOT NULL THEN 0 ELSE 1 END')
            ->orderBy('created_at', 'desc');

        return $query->paginate($perPage);
    }

    /**
     * 역할에 할당된 권한 개수를 반환합니다.
     *
     * @param  Role  $role  역할 모델
     * @return int 권한 개수
     */
    public function getPermissionCount(Role $role): int
    {
        return $role->permissions()->count();
    }
}
