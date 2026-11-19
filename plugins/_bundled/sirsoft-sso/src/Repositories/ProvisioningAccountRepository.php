<?php

namespace Plugins\Sirsoft\Sso\Repositories;

use App\Enums\PermissionType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Repositories\Contracts\ProvisioningAccountRepositoryInterface;

/**
 * 프로비저닝(SCIM, 주기 점검, 퇴사)이 쓰는 회원 연결 조회.
 *
 * @since 1.0.0
 */
class ProvisioningAccountRepository implements ProvisioningAccountRepositoryInterface
{
    /** @var array<int, string> 같음 조건으로 받는 컬럼 */
    private const FILTER_COLUMNS = ['subject', 'scim_external_id', 'email'];

    /**
     * IdP 안의 연결을 ID 로 찾습니다 (SCIM 자원 id).
     *
     * @param  int  $idpId  IdP ID
     * @param  int  $id  연결 ID
     * @return SsoAccount|null 연결
     */
    public function findInIdp(int $idpId, int $id): ?SsoAccount
    {
        return SsoAccount::query()->with('user')->where('idp_id', $idpId)->whereKey($id)->first();
    }

    /**
     * IdP 안의 연결을 대조 키로 찾습니다.
     *
     * @param  int  $idpId  IdP ID
     * @param  string  $column  `subject` 또는 `scim_external_id`
     * @param  string  $value  값 (대소문자 구분)
     * @return SsoAccount|null 연결
     */
    public function findInIdpBy(int $idpId, string $column, string $value): ?SsoAccount
    {
        if (! in_array($column, ['subject', 'scim_external_id'], true)) {
            return null;
        }

        return SsoAccount::query()->with('user')->where('idp_id', $idpId)->where($column, $value)->first();
    }

    /**
     * IdP 안의 연결 목록을 한 쪽 반환합니다 (SCIM 목록 — 회원을 함께 읽는다).
     *
     * 이메일 조건은 대소문자를 무시한다(운영 DB 가 `_bin` collation 이어도 같게 — 소문자 비교).
     *
     * @param  int  $idpId  IdP ID
     * @param  array{column: string, value: string}|null  $filter  같음 조건 (`subject`, `scim_external_id`, `email`)
     * @param  int  $offset  건너뛸 행 수
     * @param  int  $limit  최대 행 수
     * @return array{total: int, items: Collection<int, SsoAccount>} 전체 수와 행
     */
    public function pageInIdp(int $idpId, ?array $filter, int $offset, int $limit): array
    {
        $query = SsoAccount::query()->where('idp_id', $idpId);

        if ($filter !== null && str_starts_with($filter['column'], 'attr:')) {
            $key = substr($filter['column'], 5);
            $query->whereExists(fn ($sub) => $sub->selectRaw('1')
                ->from('sso_user_attributes as a')
                ->whereColumn('a.user_id', 'sso_accounts.user_id')
                ->whereColumn('a.idp_id', 'sso_accounts.idp_id')
                ->where('a.key', $key)
                ->where('a.value', $filter['value']));
        } elseif ($filter !== null && in_array($filter['column'], self::FILTER_COLUMNS, true)) {
            if ($filter['column'] === 'email') {
                $query->whereRaw('LOWER(email) = ?', [mb_strtolower($filter['value'])]);
            } else {
                $query->where($filter['column'], $filter['value']);
            }
        }

        $total = (clone $query)->count();
        // audit:allow query-count-then-get-duplicate reason: SCIM 목록의 startIndex 는 쪽 경계가 아닌 임의 오프셋이고 규격이 totalResults 를 요구한다 — 쪽 번호 기반 페이지네이터로는 표현할 수 없다
        $items = $limit > 0
            ? $query->with('user')->orderBy('id')->offset(max(0, $offset))->limit($limit)->get()
            : new Collection;

        return ['total' => $total, 'items' => $items];
    }

    /**
     * 회원에게 이 연결 말고 다른 활성 연결이 있는지 확인합니다 (겸직 판정).
     *
     * @param  int  $userId  회원 ID
     * @param  int  $exceptAccountId  뺄 연결 ID
     * @return bool 있으면 true
     */
    public function hasOtherActive(int $userId, int $exceptAccountId): bool
    {
        return SsoAccount::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->whereKeyNot($exceptAccountId)
            ->exists();
    }

    /**
     * IdP 에 연결된 회원 중 관리자(관리자 권한 역할 보유) 또는 최고관리자가 있는지 확인합니다.
     *
     * @param  int  $idpId  IdP ID
     * @return bool 있으면 true
     */
    public function hasPrivilegedLinkedUser(int $idpId): bool
    {
        return SsoAccount::query()
            ->where('idp_id', $idpId)
            ->whereHas('user', function ($user): void {
                $user->where('is_super', true)
                    ->orWhereHas('roles.permissions', fn ($permission) => $permission->where('type', PermissionType::Admin->value));
            })
            ->exists();
    }

    /**
     * 회원의 활성 연결을 IdP 와 함께 반환합니다.
     *
     * @param  int  $userId  회원 ID
     * @return Collection<int, SsoAccount> 연결
     */
    public function activeForUser(int $userId): Collection
    {
        // audit:allow query-unbounded-get reason: 한 회원의 연결은 IdP 마다 하나(UNIQUE idp_id+user_id)라 IdP 수(등록 상한 20)를 넘지 않는다
        return SsoAccount::query()->with('idp')->where('user_id', $userId)->where('active', true)->orderBy('id')->get();
    }

    /**
     * 주기 점검할 연결을 고릅니다 — 활성이거나 주기 점검이 끈(다시 켤 수 있는) 연결 중 마지막 점검이 기준 시각보다 앞선(또는 없는)
     * 연결을 오래된 순으로.
     *
     * @param  int  $idpId  IdP ID
     * @param  Carbon  $checkedBefore  이 시각 전에 점검한 연결만
     * @param  int  $limit  최대 행 수
     * @return Collection<int, SsoAccount> 연결
     */
    public function dueForPeriodicCheck(int $idpId, Carbon $checkedBefore, int $limit): Collection
    {
        return SsoAccount::query()
            ->with('user')
            ->where('idp_id', $idpId)
            ->where(fn ($q) => $q->where('active', true)->orWhere('deprovision_reason', 'periodic'))
            ->where(fn ($q) => $q->whereNull('periodic_checked_at')->orWhere('periodic_checked_at', '<', $checkedBefore))
            ->orderByRaw('periodic_checked_at IS NOT NULL')
            ->orderBy('periodic_checked_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * 퇴사 처리 시각이 기준보다 앞서고 여전히 비활성인 연결을 고릅니다 (N일 뒤 탈퇴).
     *
     * @param  int  $idpId  IdP ID
     * @param  Carbon  $deprovisionedBefore  기준 시각
     * @param  int  $limit  최대 행 수
     * @return Collection<int, SsoAccount> 연결
     */
    public function deprovisionedBefore(int $idpId, Carbon $deprovisionedBefore, int $limit): Collection
    {
        return SsoAccount::query()
            ->with('user')
            ->where('idp_id', $idpId)
            ->where('active', false)
            ->whereNotNull('deprovisioned_at')
            ->where('deprovisioned_at', '<=', $deprovisionedBefore)
            ->orderBy('deprovisioned_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * 연결을 고칩니다 (fillable 밖의 칸도 — `periodic_checked_at`).
     *
     * @param  SsoAccount  $account  연결
     * @param  array<string, mixed>  $attributes  바꿀 속성
     * @return SsoAccount 고친 연결
     */
    public function update(SsoAccount $account, array $attributes): SsoAccount
    {
        $account->forceFill($attributes)->save();

        return $account;
    }

    /**
     * 연결을 지웁니다 (SCIM 그룹 소속은 호출자가 먼저 지운다).
     *
     * @param  SsoAccount  $account  연결
     * @return void
     */
    public function delete(SsoAccount $account): void
    {
        $account->delete();
    }

    /**
     * IdP 의 마지막 주기 점검 시각을 남깁니다 (진단 화면 「마지막 점검 시각」).
     *
     * @param  int  $idpId  IdP ID
     * @param  Carbon  $at  시각
     * @return void
     */
    public function markIdpChecked(int $idpId, Carbon $at): void
    {
        IdentityProvider::query()->whereKey($idpId)->update(['periodic_checked_at' => $at]);
    }
}
