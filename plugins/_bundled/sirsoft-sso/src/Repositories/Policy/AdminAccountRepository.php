<?php

namespace Plugins\Sirsoft\Sso\Repositories\Policy;

use App\DisplayName\DisplayNameQuery;
use App\Models\Role;
use App\Models\User;
use App\Search\KeywordSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Repositories\Policy\Contracts\AdminAccountRepositoryInterface;

/**
 * 관리자 「회원 연결」 화면과 회원 화면 주입의 연결 조회 저장소.
 *
 * 추가 속성(`sso_user_attributes`)과 역할 기록(`sso_role_grants`)은 프로비저닝 영역의 표다 — 이 저장소는 읽기만 하고 표가 없으면
 * 빈 값을 돌려준다.
 *
 * @since 1.0.0
 */
class AdminAccountRepository implements AdminAccountRepositoryInterface
{
    /** @var array<int, string> 화면에 보이지 않는 내부 속성 키 (SCIM 대조용) */
    private const INTERNAL_ATTRIBUTE_KEYS = ['scim_user_name'];

    /** 기본 쪽 크기 */
    private const PER_PAGE = 20;

    /** @var array<int, string> 연결 목록 컬럼 */
    private const COLUMNS = [
        'id', 'idp_id', 'user_id', 'subject', 'email', 'display_name', 'linked_via', 'active', 'deprovisioned_at',
        'deprovision_reason', 'linked_at', 'last_login_at', 'created_at',
    ];

    /**
     * {@inheritDoc}
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $q = trim((string) ($filters['q'] ?? ''));

        // 회원 이름은 관리자 영역 표시 이름으로 계산한다 — 닉네임, 상태, 회원이 고른 종류까지 읽고,
        // 행의 「비밀번호 있음」「관리자 계정」 판정에 쓰는 두 컬럼을 더한다.
        return SsoAccount::query()
            ->with(['idp:id,key,protocol,label', 'user:'.User::displayColumns('is_super', 'password')])
            ->when(! empty($filters['idp_id']), fn (Builder $query) => $query->where('idp_id', (int) $filters['idp_id']))
            ->when(($filters['status'] ?? null) === 'active', fn (Builder $query) => $query->where('active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn (Builder $query) => $query->where('active', false))
            ->when(! empty($filters['via']), fn (Builder $query) => $query->where('linked_via', $filters['via']))
            // 관리자 화면의 회원 검색 규칙 — 이름 또는 닉네임(코어 DisplayNameQuery), 종전처럼 이메일도 찾는다
            ->when($q !== '', fn (Builder $query) => $query->whereHas('user', fn (Builder $user) => $user->where(function (Builder $where) use ($q) {
                app(DisplayNameQuery::class)->constrainUsersForAdmin($where, $q);
                $where->orWhereRaw('LOWER(email) LIKE ?', ['%'.KeywordSearch::escapeLikeWildcards(mb_strtolower($q)).'%']);
            })))
            ->orderByDesc('linked_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? self::PER_PAGE), self::COLUMNS);
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id): ?SsoAccount
    {
        return SsoAccount::query()->with(['idp', 'user'])->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function delete(SsoAccount $account): void
    {
        $account->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function forUserWithIdp(int $userId): Collection
    {
        // audit:allow query-unbounded-get reason: 한 회원의 연결은 IdP 수(상한 20)를 넘지 않는다(IdP 당 1행 UNIQUE)
        return SsoAccount::query()
            ->with('idp')
            ->where('user_id', $userId)
            ->get()
            ->sortBy(fn (SsoAccount $account) => [$account->idp?->sort_order ?? 0, $account->idp_id])
            ->values();
    }

    /**
     * {@inheritDoc}
     */
    public function findUserByEmail(string $email): ?User
    {
        return User::query()->whereRaw('LOWER(email) = ?', [mb_strtolower(trim($email))])->first();
    }

    /**
     * {@inheritDoc}
     */
    public function findUser(int $id): ?User
    {
        return User::query()->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function attributesForUsers(array $userIds, array $keys): array
    {
        if ($userIds === [] || $keys === [] || ! Schema::hasTable('sso_user_attributes')) {
            return [];
        }

        $map = [];

        DB::table('sso_user_attributes')
            ->select(['user_id', 'key', 'value'])
            ->whereIn('user_id', $userIds)
            ->whereIn('key', $keys)
            ->orderBy('id')
            ->get()
            ->each(function ($row) use (&$map) {
                $map[(int) $row->user_id][(string) $row->key] ??= (string) ($row->value ?? '');
            });

        return $map;
    }

    /**
     * {@inheritDoc}
     */
    public function attributesForUser(int $userId): array
    {
        if (! Schema::hasTable('sso_user_attributes')) {
            return [];
        }

        return DB::table('sso_user_attributes')
            ->select(['idp_id', 'key', 'value', 'synced_at'])
            ->where('user_id', $userId)
            ->whereNotIn('key', self::INTERNAL_ATTRIBUTE_KEYS)
            ->orderBy('key')
            ->get()
            ->map(fn ($row) => [
                'idp_id' => (int) $row->idp_id,
                'key' => (string) $row->key,
                'value' => $row->value !== null ? (string) $row->value : null,
                'synced_at' => $row->synced_at !== null ? (string) $row->synced_at : null,
            ])->values()->all();
    }

    /**
     * {@inheritDoc}
     */
    public function mappedRoleIdentifiers(int $userId): array
    {
        if (! Schema::hasTable('sso_role_grants')) {
            return [];
        }

        $roles = (new Role)->getTable();

        // audit:allow repository-raw-hardcoded-table reason: 역할 기록 표는 프로비저닝 영역의 표라 이 영역에 모델이 없다(읽기 전용 조회)
        return DB::table('sso_role_grants')
            ->join($roles, $roles.'.id', '=', 'sso_role_grants.role_id')
            ->where('sso_role_grants.user_id', $userId)
            ->distinct()
            ->pluck($roles.'.identifier')
            ->map(fn ($identifier) => (string) $identifier)
            ->values()->all();
    }

    /**
     * {@inheritDoc}
     */
    public function userIdsLinkedToIdpQuery(string $idpKey): Builder
    {
        return SsoAccount::query()->select('user_id')
            ->whereIn('idp_id', IdentityProvider::query()->select('id')->where('key', $idpKey));
    }

    /**
     * {@inheritDoc}
     */
    public function userIdsWithAnyLinkQuery(): Builder
    {
        return SsoAccount::query()->select('user_id');
    }
}
