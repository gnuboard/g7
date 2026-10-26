<?php

namespace Plugins\Sirsoft\Sso\Repositories\Policy;

use App\Enums\PermissionType;
use App\Models\User;
use Illuminate\Support\Collection;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Models\SsoAccount;
use Plugins\Sirsoft\Sso\Repositories\Policy\Contracts\AdminIdpRepositoryInterface;

/**
 * 관리자 IdP 화면 저장소.
 *
 * @since 1.0.0
 */
class AdminIdpRepository implements AdminIdpRepositoryInterface
{
    /**
     * {@inheritDoc}
     */
    public function all(): Collection
    {
        // audit:allow query-unbounded-get reason: IdP 등록 상한이 20이다(IdpAdminService::MAX_IDPS)
        return IdentityProvider::query()->orderBy('sort_order')->orderBy('id')->get();
    }

    /**
     * {@inheritDoc}
     */
    public function count(): int
    {
        return IdentityProvider::query()->count();
    }

    /**
     * {@inheritDoc}
     */
    public function findById(int $id): ?IdentityProvider
    {
        return IdentityProvider::query()->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function keyExists(string $key, ?int $exceptId = null): bool
    {
        return IdentityProvider::query()->where('key', $key)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $attributes): IdentityProvider
    {
        return IdentityProvider::query()->create($attributes);
    }

    /**
     * {@inheritDoc}
     */
    public function update(IdentityProvider $idp, array $attributes): IdentityProvider
    {
        $idp->fill($attributes);
        $idp->save();

        return $idp->refresh();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(IdentityProvider $idp): void
    {
        $idp->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function accountCounts(array $idpIds): array
    {
        if ($idpIds === []) {
            return [];
        }

        $rows = SsoAccount::query()
            ->selectRaw('idp_id, COUNT(*) as total, SUM(CASE WHEN active = 1 THEN 1 ELSE 0 END) as active_count')
            ->whereIn('idp_id', $idpIds)
            ->groupBy('idp_id')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row->idp_id] = ['active' => (int) $row->active_count, 'total' => (int) $row->total];
        }

        return $counts;
    }

    /**
     * {@inheritDoc}
     */
    public function hasPrivilegedLinkedUser(int $idpId): bool
    {
        return User::query()
            ->whereIn('id', SsoAccount::query()->select('user_id')->where('idp_id', $idpId))
            ->where(fn ($query) => $query->where('is_super', true)
                ->orWhereHas('roles.permissions', fn ($permissions) => $permissions->where('type', PermissionType::Admin->value)))
            ->exists();
    }

    /**
     * {@inheritDoc}
     */
    public function enforcing(): Collection
    {
        // audit:allow query-unbounded-get reason: IdP 등록 상한이 20이다(IdpAdminService::MAX_IDPS)
        return IdentityProvider::query()->where('enforce_owned_domains', true)->orderBy('id')->get();
    }
}
