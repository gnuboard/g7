<?php

namespace Plugins\Sirsoft\Sso\Repositories\Contracts;

use Illuminate\Support\Collection;
use Plugins\Sirsoft\Sso\Models\SsoScimGroup;

/**
 * SCIM 그룹과 소속 저장소 계약.
 *
 * @since 1.0.0
 */
interface ScimGroupRepositoryInterface
{
    /**
     * IdP 안의 그룹을 ID 로 찾습니다.
     *
     * @param  int  $idpId  IdP ID
     * @param  int  $id  그룹 ID
     * @return SsoScimGroup|null 그룹
     */
    public function findInIdp(int $idpId, int $id): ?SsoScimGroup;

    /**
     * IdP 안의 그룹을 이름으로 찾습니다.
     *
     * @param  int  $idpId  IdP ID
     * @param  string  $displayName  그룹 이름
     * @return SsoScimGroup|null 그룹
     */
    public function findByName(int $idpId, string $displayName): ?SsoScimGroup;

    /**
     * IdP 안의 그룹 목록을 한 쪽 반환합니다.
     *
     * @param  int  $idpId  IdP ID
     * @param  array{column: string, value: string}|null  $filter  같음 조건 (`display_name`, `external_id`)
     * @param  int  $offset  건너뛸 행 수
     * @param  int  $limit  최대 행 수
     * @return array{total: int, items: Collection<int, SsoScimGroup>} 전체 수와 행
     */
    public function pageInIdp(int $idpId, ?array $filter, int $offset, int $limit): array;

    /**
     * 그룹을 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  속성
     * @return SsoScimGroup 만든 그룹
     */
    public function create(array $attributes): SsoScimGroup;

    /**
     * 그룹을 고칩니다.
     *
     * @param  SsoScimGroup  $group  그룹
     * @param  array<string, mixed>  $attributes  바꿀 속성
     * @return SsoScimGroup 고친 그룹
     */
    public function update(SsoScimGroup $group, array $attributes): SsoScimGroup;

    /**
     * 그룹과 그 소속을 지웁니다.
     *
     * @param  SsoScimGroup  $group  그룹
     * @return void
     */
    public function delete(SsoScimGroup $group): void;

    /**
     * 그룹 소속 연결 ID 를 반환합니다.
     *
     * @param  SsoScimGroup  $group  그룹
     * @return array<int, int> 연결 ID
     */
    public function memberIds(SsoScimGroup $group): array;

    /**
     * 그룹 소속을 바꿉니다.
     *
     * @param  SsoScimGroup  $group  그룹
     * @param  array<int, int>  $add  더할 연결 ID
     * @param  array<int, int>  $remove  뺄 연결 ID
     * @return void
     */
    public function changeMembers(SsoScimGroup $group, array $add, array $remove): void;

    /**
     * 연결이 속한 그룹 이름을 반환합니다 (역할 매핑 입력).
     *
     * @param  int  $accountId  연결 ID
     * @return array<int, string> 그룹 이름
     */
    public function groupNamesFor(int $accountId): array;

    /**
     * 연결들의 소속을 모두 지웁니다 (연결 정리 전).
     *
     * @param  array<int, int>  $accountIds  연결 ID
     * @return int 지운 행 수
     */
    public function deleteMembershipsFor(array $accountIds): int;
}
