<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderExchangeOptionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderRefundRepositoryInterface;

/**
 * 교환으로 받은 상품을 반품·환불할 때 돌려줄 「상위 옵션 교환 차액」을 산정합니다. (S2 · Q9)
 *
 * 24,000원 상품을 29,000원 옵션으로 교환하며 차액 5,000원을 추가결제한 뒤 그 교환품을 반품하면, 주문 재계산은
 * 원 행 단가(24,000)만 돌려주고 차액은 별도 장부(클레임 추가결제)에 남아 돌아오지 않았다. 환불신청서를 만들 때
 * 대상 행이 교환 후속 행이면 그 교환의 양수 상품 차액을 반품 수량 비율로 나눠, 낸 수단 그대로(카드는 카드,
 * 마일리지는 마일리지) 돌려줄 몫을 클레임 결제 건별로 기록한다. 배송비 몫은 제외한다.
 *
 * 같은 교환 차액을 두 번 돌려주지 않도록, 반려·철회되지 않은 다른 환불신청서가 이미 잡아 둔 몫을 빼고 남은
 * 만큼만 잡는다(교환 차액 합계와 각 결제 건의 실수납 잔액이 상한).
 */
class ExchangeDifferenceRefundPlanner
{
    /**
     * @param  OrderExchangeOptionRepositoryInterface  $exchangeOptionRepository  교환 상품 저장소
     * @param  OrderRefundRepositoryInterface  $refundRepository  환불신청서 저장소
     * @param  ClaimPaymentService  $claimPaymentService  클레임 추가결제 서비스
     */
    public function __construct(
        private OrderExchangeOptionRepositoryInterface $exchangeOptionRepository,
        private OrderRefundRepositoryInterface $refundRepository,
        private ClaimPaymentService $claimPaymentService,
    ) {}

    /**
     * 환불 대상 행에 걸린 교환 차액 환급 몫을 산정합니다.
     *
     * @param  Order  $order  주문 (options 로드)
     * @param  array<int, array{order_option_id: int, cancel_quantity?: int, quantity?: int}>  $items  환불 대상 행과 수량
     * @param  int|null  $excludeRefundId  다시 산정하는 환불신청서 ID (이미 잡아 둔 몫에서 뺀다)
     * @return array<int, array{claim_payment_id: int, amount: float, points_amount: float}> 결제 건별 환급 몫 (없으면 빈 배열)
     */
    public function plan(Order $order, array $items, ?int $excludeRefundId = null): array
    {
        $quantities = [];
        foreach ($items as $item) {
            $id = (int) $item['order_option_id'];
            $quantities[$id] = ($quantities[$id] ?? 0) + (int) ($item['cancel_quantity'] ?? $item['quantity'] ?? 0);
        }

        // 대상 행을 교환 후속 행으로 거슬러 올린다 — 일부만 반품하면 후속 행이 분할되어 대상이 분할 조각
        // (source_type=split, 부모=후속 행)이 된다. 수량은 후속 행 단위로 모은다.
        $byId = $order->options->keyBy(fn ($option) => (int) $option->id);
        $successorQuantities = [];
        foreach ($quantities as $id => $quantity) {
            $row = $byId->get($id);
            if ($row !== null && ! $row->isExchanged() && $row->parent_option_id && (int) $row->parent_option_id !== $id) {
                $row = $byId->get((int) $row->parent_option_id);
            }
            if ($row === null || ! $row->isExchanged()) {
                continue;
            }
            $successorQuantities[(int) $row->id] = ($successorQuantities[(int) $row->id] ?? 0) + $quantity;
        }
        $quantities = $successorQuantities;
        $successorIds = array_keys($quantities);

        if ($successorIds === []) {
            return [];
        }

        // 교환별 이번 몫 (수량 비례, 상품 차액만)
        $shareByExchange = [];
        $exchanges = [];
        foreach ($this->exchangeOptionRepository->listByNewOrderOptionIds($successorIds, ['orderExchange']) as $exchangeOption) {
            $difference = (float) $exchangeOption->price_difference;
            $exchangeQuantity = (int) $exchangeOption->exchange_quantity;
            if ($difference <= 0 || $exchangeQuantity <= 0 || $exchangeOption->orderExchange === null) {
                continue;
            }

            $quantity = min($quantities[(int) $exchangeOption->new_order_option_id] ?? 0, $exchangeQuantity);
            $exchangeId = (int) $exchangeOption->order_exchange_id;
            $shareByExchange[$exchangeId] = ($shareByExchange[$exchangeId] ?? 0) + round($difference * $quantity / $exchangeQuantity, 2);
            $exchanges[$exchangeId] = $exchangeOption->orderExchange;
        }

        if ($shareByExchange === []) {
            return [];
        }

        $alreadyPlanned = $this->plannedByPayment((int) $order->id, $excludeRefundId);

        $entries = [];
        foreach ($shareByExchange as $exchangeId => $share) {
            $exchange = $exchanges[$exchangeId];
            $payments = $this->claimPaymentService->listForClaim($exchange)
                ->filter(fn (ClaimPayment $payment) => $payment->isPaid())
                ->sortBy('id')
                ->values();

            $plannedForExchange = $payments->sum(fn (ClaimPayment $payment) => $alreadyPlanned[(int) $payment->id] ?? 0);
            $left = min($share, max(0.0, (float) $exchange->additional_product_amount - $plannedForExchange));

            foreach ($payments as $payment) {
                if ($left <= 0) {
                    break;
                }

                $available = max(0.0, $payment->netPaidAmount() - ($alreadyPlanned[(int) $payment->id] ?? 0));
                $take = round(min($left, $available), 2);
                if ($take <= 0) {
                    continue;
                }

                $entries[] = [
                    'claim_payment_id' => (int) $payment->id,
                    'amount' => $payment->isPointPayment() ? 0.0 : $take,
                    'points_amount' => $payment->isPointPayment() ? $take : 0.0,
                ];
                $left = round($left - $take, 2);
            }
        }

        return $entries;
    }

    /**
     * 반려·철회되지 않은 다른 환불신청서가 결제 건별로 이미 잡아 둔 교환 차액 몫을 모읍니다.
     *
     * @param  int  $orderId  주문 ID
     * @param  int|null  $excludeRefundId  제외할 환불신청서 ID
     * @return array<int, float> claim_payment_id => 이미 잡은 몫
     */
    private function plannedByPayment(int $orderId, ?int $excludeRefundId): array
    {
        $planned = [];
        foreach ($this->refundRepository->listByOrderId($orderId) as $refund) {
            if ($excludeRefundId !== null && (int) $refund->id === $excludeRefundId) {
                continue;
            }
            if (in_array($refund->refund_status, [RefundStatusEnum::REJECTED, RefundStatusEnum::WITHDRAWN], true)) {
                continue;
            }
            foreach ($refund->exchange_difference_refunds ?? [] as $entry) {
                $id = (int) ($entry['claim_payment_id'] ?? 0);
                $planned[$id] = ($planned[$id] ?? 0) + (float) ($entry['amount'] ?? 0) + (float) ($entry['points_amount'] ?? 0);
            }
        }

        return $planned;
    }
}
