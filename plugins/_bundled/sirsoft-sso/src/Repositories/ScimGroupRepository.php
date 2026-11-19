<?php

namespace Plugins\Sirsoft\Sso\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Plugins\Sirsoft\Sso\Models\SsoScimGroup;
use Plugins\Sirsoft\Sso\Repositories\Contracts\ScimGroupRepositoryInterface;

/**
 * SCIM 그룹과 소속 저장소.
 *
 * @since 1.0.0
 */
class ScimGroupRepository implements ScimGroupRepositoryInterface
{
    /**
     * IdP 안의 그룹을 ID 로 찾습니다.
     *
     * @param  int  $idpId  IdP ID
     * @param  int  $id  그룹 ID
     * @return SsoScimGroup|null 그룹
     */
    public function findInIdp(int $idpId, int $id): ?SsoScimGroup
    {
        return SsoScimGroup::query()->where('idp_id', $idpId)->whereKey($id)->first();
    }

    /**
     * IdP 안의 그룹을 이름으로 찾습니다.
     *
     * @param  int  $idpId  IdP ID
     * @param  string  $displayName  그룹 이름
     * @return SsoScimGroup|null 그룹
     */
    public function findByName(int $idpId, string $displayName): ?SsoScimGroup
    {
        return SsoScimGroup::query()->where('idp_id', $idpId)->where('display_name', $displayName)->first();
    }

    /**
     * IdP 안의 그룹 목록을 한 쪽 반환합니다.
     *
     * @param  int  $idpId  IdP ID
     * @param  array{column: string, value: string}|null  $filter  같음 조건 (`display_name`, `external_id`)
     * @param  int  $offset  건너뛸 행 수
     * @param  int  $limit  최대 행 수
     * @return array{total: int, items: Collection<int, SsoScimGroup>} 전체 수와 행
     */
    public function pageInIdp(int $idpId, ?array $filter, int $offset, int $limit): array
    {
        $query = SsoScimGroup::query()->where('idp_id', $idpId);
        if ($filter !== null && in_array($filter['column'], ['display_name', 'external_id'], true)) {
            $query->where($filter['column'], $filter['value']);
        }

        $total = (clone $query)->count();
        // audit:allow query-count-then-get-duplicate reason: SCIM 목록의 startIndex 는 쪽 경계가 아닌 임의 오프셋이고 규격이 totalResults 를 요구한다 — 쪽 번호 기반 페이지네이터로는 표현할 수 없다
        $items = $limit > 0 ? $query->orderBy('id')->offset(max(0, $offset))->limit($limit)->get() : new Collection;

        return ['total' => $total, 'items' => $items];
    }

    /**
     * 그룹을 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  속성
     * @return SsoScimGroup 만든 그룹
     */
    public function create(array $attributes): SsoScimGroup
    {
        return SsoScimGroup::query()->create($attributes);
    }

    /**
     * 그룹을 고칩니다.
     *
     * @param  SsoScimGroup  $group  그룹
     * @param  array<string, mixed>  $attributes  바꿀 속성
     * @return SsoScimGroup 고친 그룹
     */
    public function update(SsoScimGroup $group, array $attributes): SsoScimGroup
    {
        $group->fill($attributes)->save();

        return $group;
    }

    /**
     * 그룹과 그 소속을 지웁니다.
     *
     * @param  SsoScimGroup  $group  그룹
     * @return void
     */
    public function delete(SsoScimGroup $group): void
    {
        DB::transaction(function () use ($group): void {
            DB::table(SsoScimGroup::MEMBERS_TABLE)->where('group_id', $group->getKey())->delete();
            $group->delete();
        });
    }

    /**
     * 그룹 소속 연결 ID 를 반환합니다.
     *
     * @param  SsoScimGroup  $group  그룹
     * @return array<int, int> 연결 ID
     */
    public function memberIds(SsoScimGroup $group): array
    {
        return DB::table(SsoScimGroup::MEMBERS_TABLE)
            ->where('group_id', $group->getKey())
            ->orderBy('sso_account_id')
            ->pluck('sso_account_id')
            ->map(static fn ($id) => (int) $id)
            ->all();
    }

    /**
     * 그룹 소속을 바꿉니다.
     *
     * @param  SsoScimGroup  $group  그룹
     * @param  array<int, int>  $add  더할 연결 ID
     * @param  array<int, int>  $remove  뺄 연결 ID
     * @return void
     */
    public function changeMembers(SsoScimGroup $group, array $add, array $remove): void
    {
        DB::transaction(function () use ($group, $add, $remove): void {
            if ($remove !== []) {
                DB::table(SsoScimGroup::MEMBERS_TABLE)->where('group_id', $group->getKey())->whereIn('sso_account_id', $remove)->delete();
            }

            foreach (array_unique($add) as $accountId) {
                DB::table(SsoScimGroup::MEMBERS_TABLE)->insertOrIgnore(['group_id' => $group->getKey(), 'sso_account_id' => (int) $accountId]);
            }

            $group->touch();
        });
    }

    /**
     * 연결이 속한 그룹 이름을 반환합니다 (역할 매핑 입력).
     *
     * @param  int  $accountId  연결 ID
     * @return array<int, string> 그룹 이름
     */
    public function groupNamesFor(int $accountId): array
    {
        return SsoScimGroup::query()
            ->join(SsoScimGroup::MEMBERS_TABLE.' as m', 'm.group_id', '=', 'sso_scim_groups.id')
            ->where('m.sso_account_id', $accountId)
            ->orderBy('sso_scim_groups.display_name')
            ->pluck('sso_scim_groups.display_name')
            ->map(static fn ($name) => (string) $name)
            ->all();
    }

    /**
     * 연결들의 소속을 모두 지웁니다 (연결 정리 전).
     *
     * @param  array<int, int>  $accountIds  연결 ID
     * @return int 지운 행 수
     */
    public function deleteMembershipsFor(array $accountIds): int
    {
        if ($accountIds === []) {
            return 0;
        }

        return DB::table(SsoScimGroup::MEMBERS_TABLE)->whereIn('sso_account_id', $accountIds)->delete();
    }
}
