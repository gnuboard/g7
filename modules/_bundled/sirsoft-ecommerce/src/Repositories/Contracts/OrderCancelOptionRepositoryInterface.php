<?php

namespace Modules\Sirsoft\Ecommerce\Repositories\Contracts;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Ecommerce\Models\OrderCancelOption;

/**
 * 주문 취소 옵션 리포지토리 인터페이스
 *
 * 주문 취소 옵션 상세의 데이터 접근을 위한 인터페이스입니다.
 */
interface OrderCancelOptionRepositoryInterface
{
    /**
     * 주문 취소 옵션 상세를 생성합니다.
     *
     * @param  array  $data  취소 옵션 데이터
     * @return OrderCancelOption 생성된 취소 옵션
     */
    public function create(array $data): OrderCancelOption;

    /**
     * 취소 건의 옵션 상세 상태를 일괄 갱신합니다. (취소 확정 시 completed 전이)
     *
     * @param  int  $cancelId  주문 취소 ID
     * @param  string  $status  옵션 상태 값
     * @param  int|null  $processedBy  처리 관리자 ID
     * @param  Carbon|null  $completedAt  완료 시각 (완료 전이일 때만)
     * @return int 갱신된 행 수
     */
    public function bulkUpdateStatus(int $cancelId, string $status, ?int $processedBy = null, ?Carbon $completedAt = null): int;

    /**
     * 취소신청서에 속한 상세 행을 모두 조회합니다.
     *
     * 상품 단위 전이 활동로그(D16)가 "무엇이 무엇으로 바뀌었는가" 를 적으려면 일괄 갱신
     * **전** 상태를 읽어야 한다 — 갱신 뒤에 읽으면 전·후가 같은 값으로 남는다.
     *
     * @param  int  $cancelId  취소신청서 ID
     * @return Collection<int, OrderCancelOption> 상세 행 목록
     */
    public function listByCancelId(int $cancelId): Collection;

    /**
     * 되살린 행(또는 그 부모 행)을 가리키며 아직 재활성 몫이 남은 완료 취소 상품을 잠가 조회합니다. (Q29)
     *
     * 반품·교환 발 환불이 만든 취소신청서는 제외한다 — 그 상품은 반품·교환 상태로 남아 되살아날 수 없다.
     * 나중 취소부터 되돌리도록 order_cancel_id·id 내림차순이다.
     *
     * @param  int  $orderId  주문 ID
     * @param  array<int>  $orderOptionIds  되살린 행 ID 와 부모 행 ID
     * @return Collection<int, OrderCancelOption> 후보 취소 상품
     */
    public function listReactivatableForRows(int $orderId, array $orderOptionIds): Collection;

    /**
     * 취소 상품의 재활성 수량을 늘립니다. (Q29)
     *
     * @param  OrderCancelOption  $row  취소 상품
     * @param  int  $quantity  더할 수량
     * @return void 반환값 없음
     */
    public function addReactivatedQuantity(OrderCancelOption $row, int $quantity): void;
}
