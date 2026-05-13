<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Helpers\PermissionHelper;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicyCountrySetting;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingPolicyRepositoryInterface;

/**
 * 배송정책 Repository 구현체
 */
class ShippingPolicyRepository implements ShippingPolicyRepositoryInterface
{
    public function __construct(
        protected ShippingPolicy $model
    ) {}

    /**
     * {@inheritDoc}
     */
    public function find(int $id): ?ShippingPolicy
    {
        return $this->model->with('countrySettings')->find($id);
    }

    /**
     * {@inheritDoc}
     */
    public function getListWithFilters(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->model->newQuery()->with('countrySettings');

        // 권한 스코프 필터링
        PermissionHelper::applyPermissionScope($query, 'sirsoft-ecommerce.shipping-policies.read');

        // 정책명 검색
        if (! empty($filters['search'])) {
            $query->searchByName($filters['search']);
        }

        // 배송방법 필터 (다중 선택) - countrySettings 기반
        if (! empty($filters['shipping_methods'])) {
            $methods = is_array($filters['shipping_methods'])
                ? $filters['shipping_methods']
                : [$filters['shipping_methods']];
            $query->withShippingMethods($methods);
        }

        // 부과정책 필터 (다중 선택) - countrySettings 기반
        if (! empty($filters['charge_policies'])) {
            $policies = is_array($filters['charge_policies'])
                ? $filters['charge_policies']
                : [$filters['charge_policies']];
            $query->withChargePolicies($policies);
        }

        // 배송국가 필터 (다중 선택) - countrySettings 기반
        if (! empty($filters['countries'])) {
            $countries = is_array($filters['countries'])
                ? $filters['countries']
                : [$filters['countries']];

            $query->whereHas('countrySettings', function ($sub) use ($countries) {
                $sub->whereIn('country_code', $countries);
            });
        }

        // 사용여부 필터
        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $isActive = filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN);
            $query->where('is_active', $isActive);
        }

        // 정렬
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortOrder = $filters['sort_order'] ?? 'desc';

        // 다국어 이름 정렬 처리
        if ($sortBy === 'name') {
            $locale = app()->getLocale();
            $query->orderBy("name->{$locale}", $sortOrder);
        } else {
            $query->orderBy($sortBy, $sortOrder);
        }

        return $query->paginate($perPage);
    }

    /**
     * {@inheritDoc}
     */
    public function create(array $data): ShippingPolicy
    {
        return $this->model->create($data);
    }

    /**
     * {@inheritDoc}
     */
    public function update(ShippingPolicy $shippingPolicy, array $data): ShippingPolicy
    {
        $shippingPolicy->update($data);

        return $shippingPolicy->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function delete(ShippingPolicy $shippingPolicy): bool
    {
        return $shippingPolicy->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function toggleActive(ShippingPolicy $shippingPolicy): ShippingPolicy
    {
        $shippingPolicy->update([
            'is_active' => ! $shippingPolicy->is_active,
            'updated_by' => auth()->id(),
        ]);

        return $shippingPolicy->fresh();
    }

    /**
     * {@inheritDoc}
     */
    public function bulkDelete(array $ids): int
    {
        return $this->model->whereIn('id', $ids)->delete();
    }

    /**
     * {@inheritDoc}
     */
    public function bulkToggleActive(array $ids, bool $isActive): int
    {
        return $this->model
            ->whereIn('id', $ids)
            ->update([
                'is_active' => $isActive,
                'updated_by' => auth()->id(),
                'updated_at' => now(),
            ]);
    }

    /**
     * {@inheritDoc}
     */
    public function getStatistics(): array
    {
        $total = $this->model->count();
        $active = $this->model->where('is_active', true)->count();
        $inactive = $this->model->where('is_active', false)->count();

        // 배송방법별 통계 (countrySettings 기반)
        $shippingMethodCounts = ShippingPolicyCountrySetting::query()
            ->select('shipping_method', DB::raw('COUNT(DISTINCT shipping_policy_id) as count'))
            ->groupBy('shipping_method')
            ->pluck('count', 'shipping_method')
            ->toArray();

        // 부과정책별 통계 (countrySettings 기반)
        $chargePolicyCounts = ShippingPolicyCountrySetting::query()
            ->select('charge_policy', DB::raw('COUNT(DISTINCT shipping_policy_id) as count'))
            ->groupBy('charge_policy')
            ->pluck('count', 'charge_policy')
            ->toArray();

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $inactive,
            'shipping_method' => $shippingMethodCounts,
            'charge_policy' => $chargePolicyCounts,
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveList(): Collection
    {
        return $this->model
            ->with('countrySettings')
            ->active()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * {@inheritDoc}
     */
    public function clearDefault(?int $exceptId = null): int
    {
        $query = $this->model->where('is_default', true);

        if ($exceptId !== null) {
            $query->where('id', '!=', $exceptId);
        }

        return $query->update(['is_default' => false]);
    }

    /**
     * ID 목록으로 조회하고 ID 키 맵으로 반환합니다 (bulk activity log lookup).
     *
     * @param  array<int, int>  $ids  ID 목록
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function findByIdsKeyed(array $ids): \Illuminate\Database\Eloquent\Collection
    {
        if (empty($ids)) {
            return new \Illuminate\Database\Eloquent\Collection();
        }

        return ShippingPolicy::whereIn('id', $ids)->get()->keyBy('id');
    }
}
