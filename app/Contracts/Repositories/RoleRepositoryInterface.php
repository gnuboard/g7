<?php

namespace App\Contracts\Repositories;

use App\Enums\ExtensionOwnerType;
use App\Models\Role;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface RoleRepositoryInterface
{
    /**
     * 모든 역할을 조회합니다.
     *
     * @return Collection 역할 컬렉션
     */
    public function getAll(): Collection;

    /**
     * 활성화된 역할들만 조회합니다.
     *
     * @return Collection 활성화된 역할 컬렉션
     */
    public function getActiveRoles(): Collection;

    /**
     * ID로 역할을 찾습니다.
     *
     * @param  int  $id  역할 ID
     * @return Role|null 찾은 역할 모델 또는 null
     */
    public function findById(int $id): ?Role;

    /**
     * 식별자로 역할을 찾습니다.
     *
     * @param  string  $identifier  역할 식별자
     * @return Role|null 찾은 역할 모델 또는 null
     */
    public function findByIdentifier(string $identifier): ?Role;

    /**
     * 새로운 역할을 생성합니다.
     *
     * @param  array  $data  역할 생성 데이터
     * @return Role 생성된 역할 모델
     */
    public function create(array $data): Role;

    /**
     * 역할을 생성하거나 업데이트합니다.
     *
     * @param  array  $attributes  조회 조건
     * @param  array  $values  생성/업데이트할 데이터
     * @return Role 생성 또는 업데이트된 역할 모델
     */
    public function updateOrCreate(array $attributes, array $values): Role;

    /**
     * 기존 역할을 업데이트합니다.
     *
     * @param  Role  $role  업데이트할 역할 모델
     * @param  array  $data  업데이트할 데이터
     * @return bool 업데이트 성공 여부
     */
    public function update(Role $role, array $data): bool;

    /**
     * 역할을 삭제합니다.
     *
     * @param  Role  $role  삭제할 역할 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(Role $role): bool;

    /**
     * 확장이 소유한 역할을 식별자로 찾습니다.
     *
     * @param  string  $identifier  역할 식별자
     * @param  ExtensionOwnerType  $extensionType  확장 타입
     * @param  string  $extensionIdentifier  확장 식별자
     * @return Role|null 찾은 역할 모델 또는 null
     */
    public function findExtensionRoleByIdentifier(string $identifier, ExtensionOwnerType $extensionType, string $extensionIdentifier): ?Role;

    /**
     * 역할에 권한을 할당합니다.
     *
     * @param  Role  $role  역할 모델
     * @param  int  $permissionId  권한 ID
     * @param  array  $pivotData  피벗 테이블에 저장할 추가 데이터
     */
    public function attachPermission(Role $role, int $permissionId, array $pivotData = []): void;

    /**
     * 역할에서 권한을 해제합니다.
     *
     * @param  Role  $role  역할 모델
     * @param  int  $permissionId  권한 ID
     * @return int 해제된 권한 수
     */
    public function detachPermission(Role $role, int $permissionId): int;

    /**
     * 역할의 모든 권한을 해제합니다.
     *
     * @param  Role  $role  역할 모델
     * @return int 해제된 권한 수
     */
    public function detachAllPermissions(Role $role): int;

    /**
     * 역할 목록을 페이지네이션하여 조회합니다.
     *
     * @param  array  $filters  필터 조건
     * @param  int  $perPage  페이지당 항목 수
     * @return LengthAwarePaginator 페이지네이션된 역할 목록
     */
    public function getPaginated(array $filters = [], int $perPage = 20): LengthAwarePaginator;

    /**
     * 역할에 할당된 권한 개수를 반환합니다.
     *
     * @param  Role  $role  역할 모델
     * @return int 권한 개수
     */
    public function getPermissionCount(Role $role): int;
}
