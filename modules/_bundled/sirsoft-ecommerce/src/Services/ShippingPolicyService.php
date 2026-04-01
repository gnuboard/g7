<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicyCountrySetting;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingPolicyRepositoryInterface;

/**
 * 배송정책 서비스
 */
class ShippingPolicyService
{
    public function __construct(
        protected ShippingPolicyRepositoryInterface $repository
    ) {}

    /**
     * 배송정책 목록 조회
     *
     * @param array $filters 필터 조건
     * @return LengthAwarePaginator
     */
    public function getList(array $filters): LengthAwarePaginator
    {
        // 필터 데이터 가공 훅
        $filters = HookManager::applyFilters('sirsoft-ecommerce.shipping_policy.filter_list_params', $filters);

        $perPage = (int) ($filters['per_page'] ?? 20);

        return $this->repository->getListWithFilters($filters, $perPage);
    }

    /**
     * 배송정책 통계 조회
     *
     * @return array 배송정책 통계 데이터
     */
    public function getStatistics(): array
    {
        return $this->repository->getStatistics();
    }

    /**
     * 배송정책 상세 조회
     *
     * @param int $id 배송정책 ID
     * @return ShippingPolicy|null
     */
    public function getDetail(int $id): ?ShippingPolicy
    {
        $shippingPolicy = $this->repository->find($id);

        if ($shippingPolicy) {
            HookManager::doAction('sirsoft-ecommerce.shipping_policy.after_read', $shippingPolicy);
        }

        return $shippingPolicy;
    }

    /**
     * 배송정책 생성
     *
     * @param array $data 배송정책 데이터
     * @return ShippingPolicy
     */
    public function create(array $data): ShippingPolicy
    {
        // 생성 전 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.before_create', $data);

        // 데이터 가공 훅
        $data = HookManager::applyFilters('sirsoft-ecommerce.shipping_policy.filter_create_data', $data);

        // 국가별 설정 분리
        $countrySettingsData = $data['country_settings'] ?? [];
        unset($data['country_settings']);

        // 생성자 정보 추가
        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        $shippingPolicy = DB::transaction(function () use ($data, $countrySettingsData) {
            $policy = $this->repository->create($data);

            // is_default=true로 생성 시 기존 기본 정책 해제
            if ($policy->is_default) {
                $this->repository->clearDefault($policy->id);
            }

            // 국가별 설정 일괄 생성
            foreach ($countrySettingsData as $cs) {
                $policy->countrySettings()->create($cs);
            }

            return $policy->load('countrySettings');
        });

        // 생성 후 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.after_create', $shippingPolicy);

        return $shippingPolicy;
    }

    /**
     * 배송정책 수정
     *
     * @param ShippingPolicy $shippingPolicy 배송정책 모델
     * @param array $data 수정 데이터
     * @return ShippingPolicy
     */
    public function update(ShippingPolicy $shippingPolicy, array $data): ShippingPolicy
    {
        // 수정 전 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.before_update', $shippingPolicy, $data);

        // 수정 전 스냅샷 캡처 (after_update 훅에 전달)
        $snapshot = $shippingPolicy->toArray();

        // 데이터 가공 훅
        $data = HookManager::applyFilters('sirsoft-ecommerce.shipping_policy.filter_update_data', $data, $shippingPolicy);

        // 국가별 설정 분리
        $countrySettingsData = $data['country_settings'] ?? [];
        unset($data['country_settings']);

        // 수정자 정보 추가
        $data['updated_by'] = Auth::id();

        $shippingPolicy = DB::transaction(function () use ($shippingPolicy, $data, $countrySettingsData) {
            $policy = $this->repository->update($shippingPolicy, $data);

            // is_default=true로 변경된 경우 기존 기본 정책 해제
            if ($policy->is_default) {
                $this->repository->clearDefault($policy->id);
            }

            // 국가별 설정: 삭제 후 재생성 (sync 패턴)
            $policy->countrySettings()->delete();
            foreach ($countrySettingsData as $cs) {
                $policy->countrySettings()->create($cs);
            }

            return $policy->fresh()->load('countrySettings');
        });

        // 수정 후 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.after_update', $shippingPolicy, $snapshot);

        return $shippingPolicy;
    }

    /**
     * 배송정책 삭제
     *
     * @param ShippingPolicy $shippingPolicy 배송정책 모델
     * @return bool
     */
    public function delete(ShippingPolicy $shippingPolicy): bool
    {
        // 삭제 전 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.before_delete', $shippingPolicy);

        // 국가별 설정 명시적 삭제 (DB CASCADE에 의존하지 않음)
        $shippingPolicy->countrySettings()->delete();

        $result = $this->repository->delete($shippingPolicy);

        // 삭제 후 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.after_delete', $shippingPolicy->id);

        return $result;
    }

    /**
     * 배송정책 사용여부 토글
     *
     * @param ShippingPolicy $shippingPolicy 배송정책 모델
     * @return ShippingPolicy
     */
    public function toggleActive(ShippingPolicy $shippingPolicy): ShippingPolicy
    {
        // 토글 전 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.before_toggle_active', $shippingPolicy);

        $shippingPolicy = $this->repository->toggleActive($shippingPolicy);

        // 토글 후 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.after_toggle_active', $shippingPolicy);

        return $shippingPolicy;
    }

    /**
     * 배송정책 일괄 삭제
     *
     * @param array $ids 배송정책 ID 배열
     * @return int 삭제된 개수
     */
    public function bulkDelete(array $ids): int
    {
        // 삭제 전 스냅샷 캡처 (after_bulk_delete 훅에 전달)
        $snapshots = ShippingPolicy::whereIn('id', $ids)->get()->keyBy('id')->map->toArray()->all();

        // 일괄 삭제 전 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.before_bulk_delete', $ids);

        // 국가별 설정 명시적 삭제 (DB CASCADE에 의존하지 않음)
        ShippingPolicyCountrySetting::whereIn('shipping_policy_id', $ids)->delete();

        $count = $this->repository->bulkDelete($ids);

        // 일괄 삭제 후 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.after_bulk_delete', $ids, $count, $snapshots);

        return $count;
    }

    /**
     * 배송정책 일괄 사용여부 변경
     *
     * @param array $ids 배송정책 ID 배열
     * @param bool $isActive 사용여부
     * @return int 변경된 개수
     */
    public function bulkToggleActive(array $ids, bool $isActive): int
    {
        // 변경 전 스냅샷 캡처 (after_bulk_toggle_active 훅에 전달)
        $snapshots = ShippingPolicy::whereIn('id', $ids)->get()->keyBy('id')->map->toArray()->all();

        // 일괄 변경 전 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.before_bulk_toggle_active', $ids, $isActive);

        $count = $this->repository->bulkToggleActive($ids, $isActive);

        // 일괄 변경 후 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.after_bulk_toggle_active', $ids, $isActive, $count, $snapshots);

        return $count;
    }

    /**
     * 활성화된 배송정책 목록 조회 (Select 옵션용)
     *
     * @return Collection
     */
    public function getActiveList(): Collection
    {
        return $this->repository->getActiveList();
    }

    /**
     * 기본 배송정책 설정
     *
     * @param ShippingPolicy $shippingPolicy 배송정책 모델
     * @return ShippingPolicy
     */
    public function setDefault(ShippingPolicy $shippingPolicy): ShippingPolicy
    {
        // 기본값 설정 전 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.before_set_default', $shippingPolicy);

        $shippingPolicy = DB::transaction(function () use ($shippingPolicy) {
            // 기존 기본값 해제
            $this->repository->clearDefault($shippingPolicy->id);

            // 새 기본값 설정
            return $this->repository->update($shippingPolicy, [
                'is_default' => true,
                'updated_by' => Auth::id(),
            ]);
        });

        // 기본값 설정 후 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.after_set_default', $shippingPolicy);

        return $shippingPolicy;
    }
}
