<?php

namespace App\Repositories;

use App\Contracts\Repositories\PermissionRepositoryInterface;
use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PermissionRepository implements PermissionRepositoryInterface
{
    /**
     * 모든 권한을 조회합니다.
     *
     * @return Collection 권한 컬렉션
     */
    public function getAll(): Collection
    {
        return Permission::orderBy('id')->get();
    }

    /**
     * ID로 권한을 찾습니다.
     *
     * @param  int  $id  권한 ID
     * @return Permission|null 찾은 권한 모델 또는 null
     */
    public function findById(int $id): ?Permission
    {
        return Permission::find($id);
    }

    /**
     * 여러 ID로 권한을 일괄 조회합니다.
     *
     * @param  array<int>  $ids  권한 ID 배열
     * @return Collection 권한 컬렉션 (ID 기준)
     */
    public function getByIds(array $ids): Collection
    {
        if (empty($ids)) {
            return new Collection;
        }

        return Permission::whereIn('id', $ids)->get();
    }

    /**
     * 식별자로 권한을 찾습니다.
     *
     * @param  string  $identifier  권한 식별자
     * @return Permission|null 찾은 권한 모델 또는 null
     */
    public function findByIdentifier(string $identifier): ?Permission
    {
        return Permission::where('identifier', $identifier)->first();
    }

    /**
     * 새로운 권한을 생성합니다.
     *
     * @param  array  $data  권한 생성 데이터
     * @return Permission 생성된 권한 모델
     */
    public function create(array $data): Permission
    {
        return Permission::create($data);
    }

    /**
     * 권한을 생성하거나 업데이트합니다.
     *
     * @param  array  $attributes  조회 조건
     * @param  array  $values  생성/업데이트할 데이터
     * @return Permission 생성 또는 업데이트된 권한 모델
     */
    public function updateOrCreate(array $attributes, array $values): Permission
    {
        return Permission::updateOrCreate($attributes, $values);
    }

    /**
     * 기존 권한을 업데이트합니다.
     *
     * @param  Permission  $permission  업데이트할 권한 모델
     * @param  array  $data  업데이트할 데이터
     * @return bool 업데이트 성공 여부
     */
    public function update(Permission $permission, array $data): bool
    {
        return $permission->update($data);
    }

    /**
     * 권한을 삭제합니다.
     *
     * @param  Permission  $permission  삭제할 권한 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(Permission $permission): bool
    {
        return $permission->delete();
    }

    /**
     * 특정 확장의 모든 권한을 조회합니다.
     *
     * @param  ExtensionOwnerType  $type  확장 타입
     * @param  string|null  $identifier  확장 식별자
     * @return Collection 확장에 속한 권한 컬렉션
     */
    public function getByExtension(ExtensionOwnerType $type, ?string $identifier = null): Collection
    {
        $query = Permission::where('extension_type', $type);

        if ($identifier !== null) {
            $query->where('extension_identifier', $identifier);
        }

        return $query->get();
    }

    /**
     * 특정 확장의 모든 권한을 삭제합니다.
     *
     * @param  ExtensionOwnerType  $type  확장 타입
     * @param  string|null  $identifier  확장 식별자
     * @return int 삭제된 레코드 수
     */
    public function deleteByExtension(ExtensionOwnerType $type, ?string $identifier = null): int
    {
        $query = Permission::where('extension_type', $type);

        if ($identifier !== null) {
            $query->where('extension_identifier', $identifier);
        }

        return $query->delete();
    }

    /**
     * 코어 권한들을 조회합니다.
     *
     * @return Collection 코어 권한 컬렉션
     */
    public function getCorePermissions(): Collection
    {
        return Permission::where('extension_type', ExtensionOwnerType::Core)->get();
    }

    /**
     * 최상위 권한(루트)을 모든 자식과 함께 조회합니다.
     *
     * @return Collection 루트 권한 컬렉션 (allChildren 관계 포함)
     */
    public function getRootsWithChildren(): Collection
    {
        return Permission::roots()
            ->with('allChildren')
            ->orderBy('order')
            ->get();
    }

    /**
     * 할당 가능한 권한 ID 목록을 반환합니다. (리프 노드만)
     *
     * @return array 할당 가능한 권한 ID 배열
     */
    public function getAssignableIds(): array
    {
        return Permission::whereDoesntHave('children')
            ->whereNotNull('parent_id')
            ->pluck('id')
            ->toArray();
    }

    /**
     * 스코프(self·role)가 붙었지만 라우트 스코프 메타(resource_route_key·owner_key)가 없는
     * 역할-권한 부여를 조회합니다.
     *
     * @param  array<int, string>  $exemptIdentifiers  쿼리 스코프가 정본이라 제외할 권한 식별자
     * @return array<int, array{role: string, permission: string, scope_type: string}> 부여 목록
     */
    public function getScopedGrantsWithoutOwnerMetadata(array $exemptIdentifiers): array
    {
        $roles = (new Role)->getTable();
        $permissions = (new Permission)->getTable();

        // audit:allow repository-raw-hardcoded-table reason: role_permissions 는 모델이 없는 피벗 테이블
        return DB::table('role_permissions')
            ->join($roles, "{$roles}.id", '=', 'role_permissions.role_id')
            ->join($permissions, "{$permissions}.id", '=', 'role_permissions.permission_id')
            ->whereNotNull('role_permissions.scope_type')
            ->where(function ($q) use ($permissions) {
                $q->whereNull("{$permissions}.resource_route_key")
                    ->orWhereNull("{$permissions}.owner_key");
            })
            ->when($exemptIdentifiers !== [], fn ($q) => $q->whereNotIn("{$permissions}.identifier", $exemptIdentifiers))
            ->orderBy("{$roles}.identifier")
            ->orderBy("{$permissions}.identifier")
            ->get(["{$roles}.identifier as role", "{$permissions}.identifier as permission", 'role_permissions.scope_type'])
            ->map(static fn ($row) => [
                'role' => $row->role,
                'permission' => $row->permission,
                'scope_type' => (string) $row->scope_type,
            ])
            ->all();
    }
}
