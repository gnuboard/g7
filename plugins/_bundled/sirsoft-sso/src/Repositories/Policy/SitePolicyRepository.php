<?php

namespace Plugins\Sirsoft\Sso\Repositories\Policy;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Models\SsoSitePolicy;
use Plugins\Sirsoft\Sso\Repositories\Policy\Contracts\SitePolicyRepositoryInterface;

/**
 * SSO 사이트 정책과 강제 판정 조회 저장소.
 *
 * @since 1.0.0
 */
class SitePolicyRepository implements SitePolicyRepositoryInterface
{
    /** 비밀번호 로그인 토큰의 `login_method` 값 (코어 `issueLoginSession()` 기록) */
    private const PASSWORD_LOGIN_METHOD = 'password';

    /**
     * {@inheritDoc}
     */
    public function tableExists(): bool
    {
        return Schema::hasTable('sso_site_policies');
    }

    /**
     * {@inheritDoc}
     */
    public function current(): ?SsoSitePolicy
    {
        return SsoSitePolicy::query()->orderBy('id')->first();
    }

    /**
     * {@inheritDoc}
     */
    public function save(array $policy, ?int $updatedBy): SsoSitePolicy
    {
        $row = $this->current() ?? new SsoSitePolicy;
        $row->fill(['policy' => $policy, 'updated_by' => $updatedBy]);
        $row->save();

        return $row;
    }

    /**
     * {@inheritDoc}
     */
    public function firstSuperAdminId(): ?int
    {
        $id = User::query()->where('is_super', true)->orderBy('id')->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * {@inheritDoc}
     */
    public function superAdmins(): Collection
    {
        // audit:allow query-unbounded-get reason: 최고관리자는 사이트에 몇 명뿐이다(알림 수신자 전원이 필요)
        return User::query()->where('is_super', true)->orderBy('id')->get();
    }

    /**
     * {@inheritDoc}
     */
    public function existingUserIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return User::query()->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * {@inheritDoc}
     */
    public function usersByIds(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        // audit:allow query-unbounded-get reason: 비상 계정은 요청 검증이 20명으로 묶는다(PolicyUpdateRequest emergency_user_ids max:20)
        // 표시 이름 계산에 필요한 열(닉네임, 종류 등)을 함께 싣는다 — 정책 화면이 관리자 영역 표시 이름을 보인다
        return User::query()->whereIn('id', $ids)->get(explode(',', User::displayColumns('email', 'is_super', 'password')));
    }

    /**
     * {@inheritDoc}
     */
    public function emailsForUsers(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return User::query()->whereIn('id', $ids)->pluck('email')
            ->filter(fn ($email) => is_string($email) && $email !== '')
            ->map(fn (string $email) => mb_strtolower($email))
            ->values()->all();
    }

    /**
     * {@inheritDoc}
     */
    public function userHasAnyRole(User $user, array $roleIdentifiers): bool
    {
        if ($roleIdentifiers === []) {
            return false;
        }

        return $user->roles()->whereIn('identifier', $roleIdentifiers)->exists();
    }

    /**
     * {@inheritDoc}
     */
    public function activeLinkedIdps(int $userId): Collection
    {
        // audit:allow query-unbounded-get reason: 한 회원의 연결은 IdP 수(상한 20)를 넘지 않는다
        $idpIds = SsoAccount::query()->where('user_id', $userId)->where('active', true)->pluck('idp_id');

        if ($idpIds->isEmpty()) {
            return collect();
        }

        return IdentityProvider::query()->whereIn('id', $idpIds)->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * {@inheritDoc}
     */
    public function enforcedDomains(): array
    {
        $domains = [];

        // audit:allow query-unbounded-get reason: IdP 등록 상한이 20이다
        IdentityProvider::query()->where('enabled', true)->where('enforce_owned_domains', true)
            ->get(['id', 'owned_domains'])
            ->each(function (IdentityProvider $idp) use (&$domains) {
                foreach ((array) $idp->owned_domains as $domain) {
                    if (is_string($domain) && trim($domain) !== '') {
                        $domains[] = mb_strtolower(trim($domain));
                    }
                }
            });

        return array_values(array_unique($domains));
    }

    /**
     * {@inheritDoc}
     */
    public function countNewlyEnforcedWithPassword(array $before, array $after, array $excludeIds): int
    {
        $query = $this->newlyEnforcedUsers($before, $after, $excludeIds);

        return $query === null ? 0 : $query->whereNotNull('password')->where('password', '!=', '')->count();
    }

    /**
     * {@inheritDoc}
     */
    public function countNewlyEnforcedPasswordSessions(array $before, array $after, array $excludeIds): int
    {
        $users = $this->newlyEnforcedUsers($before, $after, $excludeIds);

        if ($users === null) {
            return 0;
        }

        return $this->passwordTokens($users)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->distinct()
            ->count('tokenable_id');
    }

    /**
     * {@inheritDoc}
     */
    public function revokeNewlyEnforcedPasswordTokens(array $before, array $after, array $excludeIds): int
    {
        $users = $this->newlyEnforcedUsers($before, $after, $excludeIds);

        return $users === null ? 0 : $this->passwordTokens($users)->delete();
    }

    /**
     * 새 범위에는 들고 이전 범위에는 들지 않는 회원 조건을 만듭니다.
     *
     * @param  array<string, mixed>  $before  이전 범위
     * @param  array<string, mixed>  $after  새 범위
     * @param  array<int, int>  $excludeIds  제외 회원
     * @return Builder<User>|null 회원 조건 (새 범위가 비면 null)
     */
    private function newlyEnforcedUsers(array $before, array $after, array $excludeIds): ?Builder
    {
        if (! $this->scopeHasAny($after)) {
            return null;
        }

        $query = User::query()->where(fn (Builder $q) => $this->applyScope($q, $after));

        if ($this->scopeHasAny($before)) {
            $query->whereNot(fn (Builder $q) => $this->applyScope($q, $before));
        }

        if ($excludeIds !== []) {
            $query->whereNotIn('id', $excludeIds);
        }

        return $query;
    }

    /**
     * 범위에 드는 회원 조건을 OR 로 붙입니다.
     *
     * @param  Builder<User>  $query  회원 쿼리
     * @param  array<string, mixed>  $scope  범위
     * @return void 반환값 없음
     */
    private function applyScope(Builder $query, array $scope): void
    {
        if (! empty($scope['site'])) {
            $query->whereRaw('1 = 1');

            return;
        }

        $query->whereRaw('1 = 0');

        foreach ((array) ($scope['domains'] ?? []) as $domain) {
            if (is_string($domain) && $domain !== '') {
                $query->orWhereRaw('LOWER(email) LIKE ?', ['%@'.mb_strtolower($domain)]);
            }
        }

        $roles = array_values(array_filter((array) ($scope['roles'] ?? []), 'is_string'));
        if ($roles !== []) {
            $query->orWhereHas('roles', fn (Builder $q) => $q->whereIn('identifier', $roles));
        }

        if (! empty($scope['linked'])) {
            $query->orWhereIn('id', SsoAccount::query()->select('user_id')->where('active', true));
        }
    }

    /**
     * 범위가 비었는지 확인합니다.
     *
     * @param  array<string, mixed>  $scope  범위
     * @return bool 하나라도 있으면 true
     */
    private function scopeHasAny(array $scope): bool
    {
        return ! empty($scope['site']) || ! empty($scope['linked'])
            || ! empty(array_filter((array) ($scope['roles'] ?? [])))
            || ! empty(array_filter((array) ($scope['domains'] ?? [])));
    }

    /**
     * 회원 조건에 드는 비밀번호 로그인 토큰 쿼리.
     *
     * @param  Builder<User>  $users  회원 조건
     * @return Builder<PersonalAccessToken> 토큰 쿼리
     */
    private function passwordTokens(Builder $users): Builder
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', (new User)->getMorphClass())
            ->where('login_method', self::PASSWORD_LOGIN_METHOD)
            ->whereIn('tokenable_id', $users->select('id'));
    }
}
