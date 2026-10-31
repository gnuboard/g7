<?php

namespace Modules\Sirsoft\Ecommerce\DTO;

use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;

/**
 * 수동 상태 전이의 분류 (Q13·Q14 — 확인 창 세 항목의 대상 판정)
 *
 * - P: 결제 전 → 판매 상태 (`entersPaidCourse`)
 * - R-주문: 주문이 취소 → 판매 상태 (`revivesWholeOrder`)
 * - R-상품: 취소된 상품 행 → 판매 상태 (`revivedItems`, 부모 전이와 무관)
 *
 * 한 요청이 P 와 R-상품을 함께 가질 수 있다(미결제 주문의 일부 상품을 취소했다가 그 상품까지
 * 결제완료로 올리는 경우). `revivedItems` 는 **변경 전** 행 값으로 만든다 — 미리보기와 실행이
 * 같은 값을 써야 확인 창이 예고한 금액과 실행이 갈리지 않는다.
 */
final class ManualPaidTransition
{
    /**
     * @param  bool  $entersPaidCourse  결제 전 → 판매 상태
     * @param  bool  $revivesWholeOrder  주문 취소 → 판매 상태
     * @param  array<int, array{order_option_id: int, quantity: int, row_quantity: int, subtotal_paid_amount: float, subtotal_points_used_amount: float, is_stock_deducted: bool}>  $revivedItems  되살리는 취소 행 (변경 전 값)
     * @param  bool  $leavesNoCancelledRows  되살린 뒤 취소 행이 하나도 남지 않는지
     * @param  string  $trigger  single_update | bulk_update | bulk_update_status | option_bulk_status
     * @param  string|null  $previousStatus  변경 전 주문 상태
     * @param  string|null  $newStatus  목표 상태
     */
    public function __construct(
        public readonly bool $entersPaidCourse,
        public readonly bool $revivesWholeOrder,
        public readonly array $revivedItems,
        public readonly bool $leavesNoCancelledRows,
        public readonly string $trigger,
        public readonly ?string $previousStatus,
        public readonly ?string $newStatus,
    ) {}

    /**
     * 주문 상태 변경(단건·목록 일괄)에서 전이를 분류합니다.
     *
     * @param  string|null  $from  변경 전 주문 상태
     * @param  string|null  $to  목표 상태
     * @param  string  $trigger  진입 경로
     * @return self 전이
     */
    public static function forOrderStatusChange(?string $from, ?string $to, string $trigger): self
    {
        return new self(
            OrderStatusEnum::movesIntoPaidCourse($from, $to),
            OrderStatusEnum::revivesFromCancelled($from, $to),
            [],
            true,
            $trigger,
            $from,
            $to,
        );
    }

    /**
     * 대상 전이가 아닌지 여부 (판매 → 판매 등)
     *
     * @return bool 빈 전이면 true
     */
    public function isEmpty(): bool
    {
        return ! $this->entersPaidCourse && ! $this->revivesWholeOrder && $this->revivedItems === [];
    }

    /**
     * 취소된 것을 되살리는 전이인지 여부 (R-주문 또는 R-상품)
     *
     * @return bool 재활성이면 true
     */
    public function revives(): bool
    {
        return $this->revivesWholeOrder || $this->revivedItems !== [];
    }

    /**
     * 취소로 돌려준 몫을 전액 회수하는 전이인지 여부
     *
     * R-주문이거나, R-상품인데 되살린 뒤 취소 행이 남지 않으면 전액이다 — 행 몫으로 나누면
     * 우선순위·배송비 환급 몫이 남는다.
     *
     * @return bool 전액 회수면 true
     */
    public function sweepsAll(): bool
    {
        return $this->revivesWholeOrder || ($this->revivedItems !== [] && $this->leavesNoCancelledRows);
    }

    /**
     * 되살리는 수량 합계 (R-상품)
     *
     * @return int 수량
     */
    public function revivedQuantity(): int
    {
        return (int) array_sum(array_column($this->revivedItems, 'quantity'));
    }

    /**
     * 활동 로그·미리보기용 요약
     *
     * @return array<string, mixed> 요약
     */
    public function toArray(): array
    {
        return [
            'enters_paid_course' => $this->entersPaidCourse,
            'revives_whole_order' => $this->revivesWholeOrder,
            'revived_item_count' => count($this->revivedItems),
            'previous_status' => $this->previousStatus,
            'new_status' => $this->newStatus,
        ];
    }
}
