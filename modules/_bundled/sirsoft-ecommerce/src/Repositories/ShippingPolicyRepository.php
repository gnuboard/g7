<?php

namespace Modules\Sirsoft\Ecommerce\Repositories;

use App\Helpers\PermissionHelper;
use App\Repositories\Concerns\ResolvesSortSpec;
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
    use ResolvesSortSpec;

    /** 허용 정렬 컬럼 (ShippingPolicyListRequest 와 동일 집합) */
    private const SORTABLE_COLUMNS = ['id', 'name', 'is_active', 'sort_order', 'created_at', 'updated_at'];

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
        // 국가별 설정은 목록이 실제로 그리는 컬럼만 싣는다.
        //
        // 이 목록의 소비자 둘(배송정책 관리 목록, 상품 등록/수정의 배송정책 선택기)은 국가 칩·
        // 배송방법·부과정책·배송비·활성여부만 그린다. 종전에는 전체 컬럼을 실어 화면이 쓰지도
        // 않는 `extra_fee_settings`(도서산간 지역 배열)·`api_config`·`api_request_fields` 가
        // 정책당 국가 수만큼 응답에 실렸다.
        //
        // 관계를 좁히지 않고 **별도 관계**(`listCountrySettings`)를 쓰는 이유는 모델 docblock
        // 참조 — `countrySettings` 를 좁히면 부분 로드가 배송비 계산 경로로 흘러든다.
        //
        // 활성 필터는 걸지 않는다. 목록이 비활성 국가 설정에 "비활성" 배지를 그리므로, 활성만
        // 실으면 그 배지가 영영 뜨지 않는다(기능 축소). 요약 계산은 모델이 로드된 컬렉션에서
        // 다시 활성만 걸러 쓰므로 요약값은 경로와 무관하게 같다.
        $query = $this->model->newQuery()->with('listCountrySettings');

        // 전체 컬럼이 필요한 외부 호출자를 위한 하위호환 경로 — 켜면 종전 응답 그대로다.
        if (! empty($filters['with_country_settings'])) {
            $query->with('countrySettings');
        }

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

        // 정렬 (허용 컬럼 화이트리스트로 해석)
        $sort = $this->resolveSortSpec($filters, self::SORTABLE_COLUMNS, 'created_at')[0];

        // 다국어 이름 정렬 처리
        if ($sort['column'] === 'name') {
            $locale = app()->getLocale();
            $query->orderBy("name->{$locale}", $sort['direction']);
        } else {
            $query->orderBy($sort['column'], $sort['direction']);
        }

        // audit:allow repository-paginate-column-pruning reason: 배송정책 "정의" 목록 —
        // 행 수가 운영자가 만든 정책 수에 묶여 OFFSET 이 깊어질 수 없다
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
        // 목록용 컬럼만 실은 국가별 설정을 함께 싣는다.
        //
        // 이 목록의 소비자(`ShippingPolicyController::activeList`)는 정책마다
        // `getCountriesWithFlags()` 와 `getFeeSummary()` 를 호출한다. 두 메서드는 관계가
        // 로드돼 있지 않으면 **행마다** 국가별 설정을 다시 조회하므로, 싣지 않으면 정책 수
        // × 2 쿼리가 된다 — 페이로드를 줄이려다 쿼리를 늘리는 맞바꿈이 된다.
        return $this->model
            ->with('listCountrySettings')
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
     * {@inheritDoc}
     */
    public function findDefault(): ?ShippingPolicy
    {
        return $this->model
            ->with('countrySettings')
            ->where('is_default', true)
            ->first();
    }

    /**
     * {@inheritDoc}
     */
    public function findByIdsKeyed(array $ids): Collection
    {
        if (empty($ids)) {
            return new Collection;
        }

        return ShippingPolicy::whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * {@inheritDoc}
     */
    public function deleteCountrySettingsByPolicyIds(array $ids): int
    {
        if (empty($ids)) {
            return 0;
        }

        return ShippingPolicyCountrySetting::whereIn('shipping_policy_id', $ids)->delete();
    }
}
