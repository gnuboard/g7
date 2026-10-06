<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCancelOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderCancelRepositoryInterface;

/**
 * 되살린 취소 행의 수량이 어느 취소신청서의 취소를 되돌렸는지 기록합니다. (Q29)
 *
 * 재활성 이력(order_meta.reactivation_reclaim)은 주문 단위라 취소신청서마다 판정할 수 없다 — 취소신청서 상품은
 * 분할 전 원 행을 가리키고, 병합이 참조를 옮긴다. 그래서 행이 되살아나는 순간(병합 전)에 그 행 또는 부모 행을
 * 가리키는 완료 취소 상품에, 나중 취소부터 수량을 귀속한다(재활성은 취소의 되돌리기라 스택 순서다). 반품·교환 발
 * 환불이 만든 취소신청서는 상품이 반품·교환 상태로 남아 되살아날 수 없으므로 대상이 아니다. 귀속할 곳이 없으면
 * (기록 이전의 취소) 기록만 건너뛴다 — 재활성을 막지 않는다.
 *
 * 한계: 같은 원 행을 서로 다른 취소가 두 번 부분취소했고 그 조각들이 병합되어 한 취소 행이 된 상태에서 일부
 * 수량만 되살리면 나중 취소에 귀속한다. 주문 전체 재활성은 항상 전부 귀속되어 결과가 같다.
 */
class CancelReactivationRecorder
{
    /**
     * @param  OrderCancelOptionRepositoryInterface  $cancelOptionRepository  취소 상품 저장소
     * @param  OrderCancelRepositoryInterface  $cancelRepository  취소신청서 저장소
     */
    public function __construct(
        private OrderCancelOptionRepositoryInterface $cancelOptionRepository,
        private OrderCancelRepositoryInterface $cancelRepository,
    ) {}

    /**
     * 되살린 행의 수량을 취소신청서에 귀속합니다.
     *
     * @param  OrderOption  $revivedRow  되살리는 행 (분할·병합 전)
     * @param  int  $quantity  되살리는 수량
     * @param  CarbonInterface  $at  재활성 시각
     * @return array<int, int> order_cancel_id => 귀속 수량
     */
    public function record(OrderOption $revivedRow, int $quantity, CarbonInterface $at): array
    {
        $rowIds = [(int) $revivedRow->id];
        if ($revivedRow->parent_option_id && (int) $revivedRow->parent_option_id !== (int) $revivedRow->id) {
            $rowIds[] = (int) $revivedRow->parent_option_id;
        }

        $left = $quantity;
        $byCancel = [];

        foreach ($this->cancelOptionRepository->listReactivatableForRows((int) $revivedRow->order_id, $rowIds) as $candidate) {
            if ($left <= 0) {
                break;
            }

            $take = min((int) $candidate->cancel_quantity - (int) $candidate->reactivated_quantity, $left);
            if ($take <= 0) {
                continue;
            }

            $this->cancelOptionRepository->addReactivatedQuantity($candidate, $take);
            $cancelId = (int) $candidate->order_cancel_id;
            $byCancel[$cancelId] = ($byCancel[$cancelId] ?? 0) + $take;
            $left -= $take;
        }

        foreach ($byCancel as $cancelId => $taken) {
            $this->cancelRepository->markReactivated($cancelId, $taken, $at);
        }

        if ($left > 0) {
            Log::info('재활성 수량 일부를 취소신청서에 귀속하지 못했습니다 (기록 이전 취소)', [
                'order_option_id' => $revivedRow->id,
                'unattributed' => $left,
            ]);
        }

        return $byCancel;
    }
}
