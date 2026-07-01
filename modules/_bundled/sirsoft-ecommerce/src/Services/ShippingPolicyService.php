<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;
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
     * @param  array  $filters  필터 조건
     * @return LengthAwarePaginator 페이지네이션된 배송정책 목록
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
     * @param  int  $id  배송정책 ID
     * @return ShippingPolicy|null 조회된 배송정책 (없으면 null)
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
     * @param  array  $data  배송정책 데이터
     * @return ShippingPolicy 생성된 배송정책
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

        // 통화는 상점 기본 통화로 강제 (상품 등록과 동일 정책 — 자유 지정 금지)
        $countrySettingsData = $this->forceDefaultCurrency($countrySettingsData);

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
     * @param  ShippingPolicy  $shippingPolicy  배송정책 모델
     * @param  array  $data  수정 데이터
     * @return ShippingPolicy 수정된 배송정책
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

        // 통화는 상점 기본 통화로 강제 (상품 등록과 동일 정책 — 자유 지정 금지)
        $countrySettingsData = $this->forceDefaultCurrency($countrySettingsData);

        // 계산 API 인증 토큰 재전송 유지: 폼이 마스킹된 빈 토큰을 보낸 경우
        // 기존 country_setting(동일 country_code)의 토큰을 복원한다 (재입력 강제 방지).
        $countrySettingsData = $this->preserveApiAuthTokens($shippingPolicy, $countrySettingsData);

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
     * 국가별 설정의 통화를 상점 기본 통화로 강제합니다.
     *
     * 배송정책 통화는 상품 등록(ProductService)과 동일하게 관리자 입력값을 신뢰하지 않고
     * 서버가 상점 기본 통화(language_currency.default_currency)로 고정한다. 배송비는
     * 기본 통화 정수로 합산되므로 정책마다 다른 통화를 허용하면 합계 단위가 섞여 정합성이
     * 깨진다. 폼은 통화를 읽기전용으로만 표시한다.
     *
     * @param  array  $countrySettingsData  국가별 설정 입력
     * @return array 통화가 기본 통화로 고정된 국가별 설정
     */
    private function forceDefaultCurrency(array $countrySettingsData): array
    {
        $defaultCurrency = $this->getDefaultCurrency();

        foreach ($countrySettingsData as $i => $cs) {
            $countrySettingsData[$i]['currency_code'] = $defaultCurrency;
        }

        return $countrySettingsData;
    }

    /**
     * 상점 기본 통화 코드를 반환합니다.
     *
     * @return string 기본 통화 코드 (미설정 시 KRW)
     */
    private function getDefaultCurrency(): string
    {
        return g7_module_settings('sirsoft-ecommerce', 'language_currency')['default_currency'] ?? 'KRW';
    }

    /**
     * 계산 API 인증 토큰 재전송 유지 처리.
     *
     * 응답 직렬화 시 토큰은 마스킹되어 폼으로 내려가므로, 사용자가 토큰을 다시 입력하지
     * 않으면 빈 값이 전송된다. 이 경우 기존 country_setting(동일 country_code)의 토큰을
     * 복원하여 재입력을 강제하지 않는다. 사용자가 새 토큰을 입력한 경우에는 그대로 둔다.
     *
     * @param  ShippingPolicy  $policy  기존 배송정책 (수정 대상)
     * @param  array  $countrySettingsData  신규 country_settings 입력
     * @return array 토큰이 보존된 country_settings
     */
    private function preserveApiAuthTokens(ShippingPolicy $policy, array $countrySettingsData): array
    {
        $existingByCode = $policy->countrySettings()
            ->get()
            ->keyBy('country_code');

        foreach ($countrySettingsData as $i => $cs) {
            $config = $cs['api_config'] ?? null;

            if (! is_array($config) || ! empty($config['auth_token'])) {
                continue;
            }

            $existing = $existingByCode->get($cs['country_code'] ?? null);
            $existingToken = $existing?->api_config['auth_token'] ?? null;

            if (! empty($existingToken)) {
                $countrySettingsData[$i]['api_config']['auth_token'] = $existingToken;
            }
        }

        return $countrySettingsData;
    }

    /**
     * 배송정책 삭제
     *
     * @param  ShippingPolicy  $shippingPolicy  배송정책 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(ShippingPolicy $shippingPolicy): bool
    {
        // 삭제 전 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.before_delete', $shippingPolicy);

        // 삭제 전 ID 캡처 (삭제 후 모델 id가 null이 될 수 있음)
        $shippingPolicyId = $shippingPolicy->id;

        // 국가별 설정 명시적 삭제 (DB CASCADE에 의존하지 않음)
        $shippingPolicy->countrySettings()->delete();

        $result = $this->repository->delete($shippingPolicy);

        // 삭제 후 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.after_delete', $shippingPolicyId);

        return $result;
    }

    /**
     * 배송정책 사용여부 토글
     *
     * @param  ShippingPolicy  $shippingPolicy  배송정책 모델
     * @return ShippingPolicy 토글된 배송정책
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
     * @param  array  $ids  배송정책 ID 배열
     * @return int 삭제된 개수
     */
    public function bulkDelete(array $ids): int
    {
        // 삭제 전 스냅샷 캡처 (after_bulk_delete 훅에 전달)
        $snapshots = $this->repository->findByIdsKeyed($ids)->map->toArray()->all();

        // 일괄 삭제 전 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.before_bulk_delete', $ids);

        // 국가별 설정 명시적 삭제 (DB CASCADE에 의존하지 않음)
        $this->repository->deleteCountrySettingsByPolicyIds($ids);

        $count = $this->repository->bulkDelete($ids);

        // 일괄 삭제 후 훅
        HookManager::doAction('sirsoft-ecommerce.shipping_policy.after_bulk_delete', $ids, $count, $snapshots);

        return $count;
    }

    /**
     * 배송정책 일괄 사용여부 변경
     *
     * @param  array  $ids  배송정책 ID 배열
     * @param  bool  $isActive  사용여부
     * @return int 변경된 개수
     */
    public function bulkToggleActive(array $ids, bool $isActive): int
    {
        // 변경 전 스냅샷 캡처 (after_bulk_toggle_active 훅에 전달)
        $snapshots = $this->repository->findByIdsKeyed($ids)->map->toArray()->all();

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
     * @return Collection 활성 배송정책 목록
     */
    public function getActiveList(): Collection
    {
        return $this->repository->getActiveList();
    }

    /**
     * 기본 배송정책 설정
     *
     * @param  ShippingPolicy  $shippingPolicy  배송정책 모델
     * @return ShippingPolicy 기본값으로 설정된 배송정책
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
