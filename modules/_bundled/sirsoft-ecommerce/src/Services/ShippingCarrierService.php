<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use App\Extension\HookManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\Exceptions\ShippingCarrierOperationException;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderReturnRepositoryInterface;
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
     * @param  OrderExchangeRepositoryInterface  $orderExchangeRepository  교환 신청 Repository
     * @param  OrderReturnRepositoryInterface  $orderReturnRepository  반품 신청 Repository
     */
    public function __construct(
        protected ShippingCarrierRepositoryInterface $repository,
        protected OrderShippingRepositoryInterface $orderShippingRepository,
        protected OrderExchangeRepositoryInterface $orderExchangeRepository,
        protected OrderReturnRepositoryInterface $orderReturnRepository,
    ) {}

    /**
     * 배송사가 쓰이고 있는 건수를 셉니다.
     *
     * **배송사를 붙잡는 표는 하나가 아니다.** 일반 배송은 `order_shippings.carrier_id` 로,
     * 교환은 상품 행의 `collection_carrier_id`·`redelivery_carrier_id` 로, 반품은 상품 행의
     * `collection_carrier_id` 로 기록한다(송장은 상품 행마다 — R12). 일반 배송만 세면 **회수 전용으로만 쓰인 배송사가 "안 쓰임"
     * 으로 판정되고**, 그 세 외래키는 전부 `nullOnDelete` 라 삭제가 200 으로 통과한 뒤
     * 접수된 교환·반품 신청서의 택배사가 오류도 로그도 없이 비워진다.
     *
     * 삭제·동기화·개별 삭제 세 경로가 모두 이 판정 하나를 쓴다. 시더의 stale 정리도 같다
     * (그래서 public 이다) — 기준이 갈리면 한쪽이 400 으로 막는 것을 다른 쪽이 조용히 지운다.
     * `ClaimReasonService::countUsage()` 와 같은 형태다.
     *
     * @param  int  $carrierId  배송사 ID
     * @return int 사용 건수 (일반 배송 + 교환 회수·재발송 + 반품 회수)
     */
    public function countUsage(int $carrierId): int
    {
        return $this->orderShippingRepository->countByCarrierId($carrierId)
            + $this->orderExchangeRepository->countByCarrierId($carrierId)
            + $this->orderReturnRepository->countByCarrierId($carrierId);
    }

    /**
     * 배송사 목록 조회
     *
     * @param  array  $filters  필터 조건
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
     * @param  int  $id  배송사 ID
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
     * @param  string|null  $type  배송사 유형 필터
     * @return Collection
     */
    public function getActiveCarriers(?string $type = null): Collection
    {
        return $this->repository->getActiveCarriers($type);
    }

    /**
     * 활성 배송사 Select 옵션 배열을 반환합니다.
     *
     * 배송사 목록 엔드포인트(`admin/shipping-carriers/active`)와 클레임 상세 응답이 같은
     * 형태를 그린다 — 클레임 상세 화면은 `claims.read` 라우트인데 그 엔드포인트는
     * `settings.read` 게이트라, 화면이 직접 부르면 클레임 권한만 가진 운영자(기본 역할
     * `manager`)에게만 403 이 되어 택배사 드롭다운이 조용히 빈다. 그 화면은 이미 열려
     * 있는 상세 응답으로 값을 나르고, 형태는 이 메서드 하나가 정한다.
     *
     * @param  string|null  $type  배송사 유형 필터
     * @return array<int, array{value: int, label: string, code: string|null, type: string|null, tracking_url: string|null}> Select 옵션 배열
     */
    public function getActiveCarrierOptions(?string $type = null): array
    {
        return $this->getActiveCarriers($type)
            ->map(fn ($carrier) => [
                'value' => $carrier->id,
                'label' => $carrier->getLocalizedName(),
                'code' => $carrier->code,
                'type' => $carrier->type,
                'tracking_url' => $carrier->tracking_url,
            ])
            ->values()
            ->toArray();
    }

    /**
     * 배송사 생성
     *
     * @param  array  $data  배송사 데이터
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
     * @param  int  $id  배송사 ID
     * @param  array  $data  수정할 데이터
     * @return ShippingCarrier
     *
     * @throws \Exception
     */
    public function updateCarrier(int $id, array $data): ShippingCarrier
    {
        $carrier = $this->repository->findById($id);

        if (! $carrier) {
            throw new ShippingCarrierOperationException('sirsoft-ecommerce::exceptions.shipping_carrier_not_found');
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
     * @param  int  $id  배송사 ID
     * @return ShippingCarrier
     *
     * @throws \Exception
     */
    public function toggleStatus(int $id): ShippingCarrier
    {
        $carrier = $this->repository->findById($id);

        if (! $carrier) {
            throw new ShippingCarrierOperationException('sirsoft-ecommerce::exceptions.shipping_carrier_not_found');
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
     * 저장 payload 가 사용 중인 배송사를 지우려 하는지 **쓰기 없이** 판정합니다
     *
     * 설정 저장은 JSON 파일 쓰기라 트랜잭션으로 되돌릴 수 없다. `syncCarriers()` 안의 가드는
     * 파일이 이미 기록된 뒤에 돌아, 거절되면 배송 스칼라만 반영된 반쪽 상태가 남는다.
     * 그래서 컨트롤러가 파일 쓰기 **앞에서** 이 선행 판정을 부른다
     * (`ClaimReasonService::assertSyncDeletable()` 과 같은 형태).
     *
     * `syncCarriers()` 안의 가드는 그대로 둔다 — 이 서비스는 확장에 열려 있어 다른 호출 경로가
     * 이 선행 판정을 건너뛸 수 있다.
     *
     * @param  array  $carriersData  저장하려는 carriers 배열
     * @return void 반환값 없음 (판정 결과는 예외로만 통지)
     *
     * @throws ShippingCarrierOperationException 사용 중인 배송사를 지우려 할 때
     */
    public function assertSyncDeletable(array $carriersData): void
    {
        $existingIds = $this->repository->pluckIds();
        $incomingIds = array_filter(array_column($carriersData, 'id'));

        foreach (array_diff($existingIds, $incomingIds) as $id) {
            $usageCount = $this->countUsage($id);

            if ($usageCount > 0) {
                throw new ShippingCarrierOperationException('sirsoft-ecommerce::exceptions.shipping_carrier_in_use', [
                    'count' => $usageCount,
                ]);
            }
        }
    }

    /**
     * 배송사 일괄 동기화 (설정 저장 시 사용)
     *
     * payload에 있는 carriers를 DB와 동기화합니다.
     * - id 있음 → 기존 carrier 업데이트
     * - id 없음 → 새 carrier 생성
     * - DB에 있지만 payload에 없음 → 삭제 (주문에서 사용 중이면 예외)
     *
     * @param  array  $carriersData  carriers 배열
     * @return void
     *
     * @throws \Exception 사용 중인 배송사 삭제 시도 시
     */
    public function syncCarriers(array $carriersData): void
    {
        DB::transaction(function () use ($carriersData) {
            $existingIds = $this->repository->pluckIds();
            $incomingIds = array_filter(array_column($carriersData, 'id'));

            // 삭제: DB에 있지만 payload에 없는 항목
            $toDeleteIds = array_diff($existingIds, $incomingIds);
            foreach ($toDeleteIds as $id) {
                $usageCount = $this->countUsage($id);
                if ($usageCount > 0) {
                    throw new ShippingCarrierOperationException('sirsoft-ecommerce::exceptions.shipping_carrier_in_use', [
                        'count' => $usageCount,
                    ]);
                }
                $this->repository->delete($id);
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
                    $this->repository->update((int) $data['id'], $carrierData);
                } else {
                    $carrierData['created_by'] = Auth::id();
                    $this->repository->create($carrierData);
                }
            }
        });
    }

    /**
     * 배송사 삭제
     *
     * @param  int  $id  배송사 ID
     * @return array 삭제 결과 정보
     *
     * @throws \Exception
     */
    public function deleteCarrier(int $id): array
    {
        $carrier = $this->repository->findById($id);

        if (! $carrier) {
            throw new ShippingCarrierOperationException('sirsoft-ecommerce::exceptions.shipping_carrier_not_found');
        }

        // 주문에서 사용 중인지 확인
        $usageCount = $this->countUsage($id);

        if ($usageCount > 0) {
            throw new ShippingCarrierOperationException('sirsoft-ecommerce::exceptions.shipping_carrier_in_use', [
                'count' => $usageCount,
            ]);
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
