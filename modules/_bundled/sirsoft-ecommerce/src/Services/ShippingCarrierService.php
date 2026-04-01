<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderShippingRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\ShippingCarrierRepositoryInterface;

/**
 * 배송사 서비스
 */
class ShippingCarrierService
{
    /**
     * @param  ShippingCarrierRepositoryInterface  $repository  배송사 Repository
     * @param  OrderShippingRepositoryInterface  $orderShippingRepository  주문 배송 Repository
     */
    public function __construct(
        protected ShippingCarrierRepositoryInterface $repository,
        protected OrderShippingRepositoryInterface $orderShippingRepository,
    ) {}

    /**
     * 배송사 목록 조회
     *
     * @param array $filters 필터 조건
     * @return Collection
     */
    public function getAllCarriers(array $filters = []): Collection
    {
        HookManager::doAction('sirsoft-ecommerce.shipping_carrier.before_list', $filters);

        $filters = HookManager::applyFilters('sirsoft-ecommerce.shipping_carrier.filter_list_query', $filters);

        $carriers = $this->repository->getAll($filters);

        $carriers = HookManager::applyFilters('sirsoft-ecommerce.shipping_carrier.filter_list_result', $carriers, $filters);

        HookManager::doAction('sirsoft-ecommerce.shipping_carrier.after_list', $carriers, $filters);

        return $carriers;
    }

    /**
     * 배송사 상세 조회
     *
     * @param int $id 배송사 ID
     * @return ShippingCarrier|null
     */
    public function getCarrier(int $id): ?ShippingCarrier
    {
        HookManager::doAction('sirsoft-ecommerce.shipping_carrier.before_show', $id);

        $carrier = $this->repository->findById($id);

        if ($carrier) {
            $carrier = HookManager::applyFilters('sirsoft-ecommerce.shipping_carrier.filter_show_result', $carrier);
            HookManager::doAction('sirsoft-ecommerce.shipping_carrier.after_show', $carrier);
        }

        return $carrier;
    }

    /**
     * 활성 배송사 목록 조회 (Select 옵션용)
     *
     * @param string|null $type 배송사 유형 필터
     * @return Collection
     */
    public function getActiveCarriers(?string $type = null): Collection
    {
        return $this->repository->getActiveCarriers($type);
    }

    /**
     * 배송사 생성
     *
     * @param array $data 배송사 데이터
     * @return ShippingCarrier
     */
    public function createCarrier(array $data): ShippingCarrier
    {
        HookManager::doAction('sirsoft-ecommerce.shipping_carrier.before_create', $data);

        $data = HookManager::applyFilters('sirsoft-ecommerce.shipping_carrier.filter_create_data', $data);

        $data['created_by'] = Auth::id();
        $data['updated_by'] = Auth::id();

        $carrier = DB::transaction(function () use ($data) {
            $carrier = $this->repository->create($data);

            return $carrier->fresh();
        });

        HookManager::doAction('sirsoft-ecommerce.shipping_carrier.after_create', $carrier, $data);

        return $carrier;
    }

    /**
     * 배송사 수정
     *
     * @param int $id 배송사 ID
     * @param array $data 수정할 데이터
     * @return ShippingCarrier
     * @throws \Exception
     */
    public function updateCarrier(int $id, array $data): ShippingCarrier
    {
        $carrier = $this->repository->findById($id);

        if (! $carrier) {
            throw new \Exception(__('sirsoft-ecommerce::exceptions.shipping_carrier_not_found'));
        }

        HookManager::doAction('sirsoft-ecommerce.shipping_carrier.before_update', $id, $data);

        // 수정 전 스냅샷 캡처 (after_update 훅에 전달)
        $snapshot = $carrier->toArray();

        $data = HookManager::applyFilters('sirsoft-ecommerce.shipping_carrier.filter_update_data', $data, $id);

        $data['updated_by'] = Auth::id();

        $carrier = DB::transaction(function () use ($carrier, $data) {
            $carrier = $this->repository->update($carrier->id, $data);

            return $carrier->fresh();
        });

        HookManager::doAction('sirsoft-ecommerce.shipping_carrier.after_update', $carrier, $data, $snapshot);

        return $carrier;
    }

    /**
     * 배송사 상태 토글
     *
     * @param int $id 배송사 ID
     * @return ShippingCarrier
     * @throws \Exception
     */
    public function toggleStatus(int $id): ShippingCarrier
    {
        $carrier = $this->repository->findById($id);

        if (! $carrier) {
            throw new \Exception(__('sirsoft-ecommerce::exceptions.shipping_carrier_not_found'));
        }

        HookManager::doAction('sirsoft-ecommerce.shipping_carrier.before_toggle_status', $carrier);

        $carrier = DB::transaction(function () use ($carrier) {
            return $this->repository->update($carrier->id, [
                'is_active' => ! $carrier->is_active,
            ]);
        });

        HookManager::doAction('sirsoft-ecommerce.shipping_carrier.after_toggle_status', $carrier);

        return $carrier->fresh();
    }

    /**
     * 배송사 일괄 동기화 (설정 저장 시 사용)
     *
     * payload에 있는 carriers를 DB와 동기화합니다.
     * - id 있음 → 기존 carrier 업데이트
     * - id 없음 → 새 carrier 생성
     * - DB에 있지만 payload에 없음 → 삭제 (주문에서 사용 중이면 예외)
     *
     * @param array $carriersData carriers 배열
     * @return void
     * @throws \Exception 사용 중인 배송사 삭제 시도 시
     */
    public function syncCarriers(array $carriersData): void
    {
        DB::transaction(function () use ($carriersData) {
            $existingIds = ShippingCarrier::pluck('id')->toArray();
            $incomingIds = array_filter(array_column($carriersData, 'id'));

            // 삭제: DB에 있지만 payload에 없는 항목
            $toDeleteIds = array_diff($existingIds, $incomingIds);
            foreach ($toDeleteIds as $id) {
                $usageCount = $this->orderShippingRepository->countByCarrierId($id);
                if ($usageCount > 0) {
                    throw new \Exception(__('sirsoft-ecommerce::exceptions.shipping_carrier_in_use', [
                        'count' => $usageCount,
                    ]));
                }
                ShippingCarrier::destroy($id);
            }

            // 생성/수정
            foreach ($carriersData as $index => $data) {
                $carrierData = [
                    'code' => $data['code'],
                    'name' => $data['name'],
                    'type' => $data['type'],
                    'tracking_url' => $data['tracking_url'] ?? null,
                    'is_active' => $data['is_active'] ?? true,
                    'sort_order' => $index,
                    'updated_by' => Auth::id(),
                ];

                if (! empty($data['id']) && in_array($data['id'], $existingIds)) {
                    ShippingCarrier::where('id', $data['id'])->update($carrierData);
                } else {
                    $carrierData['created_by'] = Auth::id();
                    ShippingCarrier::create($carrierData);
                }
            }
        });
    }

    /**
     * 배송사 삭제
     *
     * @param int $id 배송사 ID
     * @return array 삭제 결과 정보
     * @throws \Exception
     */
    public function deleteCarrier(int $id): array
    {
        $carrier = $this->repository->findById($id);

        if (! $carrier) {
            throw new \Exception(__('sirsoft-ecommerce::exceptions.shipping_carrier_not_found'));
        }

        // 주문에서 사용 중인지 확인
        $usageCount = $this->orderShippingRepository->countByCarrierId($id);

        if ($usageCount > 0) {
            throw new \Exception(__('sirsoft-ecommerce::exceptions.shipping_carrier_in_use', [
                'count' => $usageCount,
            ]));
        }

        HookManager::doAction('sirsoft-ecommerce.shipping_carrier.before_delete', $carrier);

        DB::transaction(function () use ($carrier) {
            $this->repository->delete($carrier->id);
        });

        HookManager::doAction('sirsoft-ecommerce.shipping_carrier.after_delete', $carrier->id);

        return [
            'carrier_id' => $carrier->id,
        ];
    }
}
