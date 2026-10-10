<?php

namespace Modules\Sirsoft\Ecommerce\Services;

use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\OrderShippingRepositoryInterface;

/**
 * 재계산이 정한 주문 행별 배송비 몫을 배송 행에 씁니다. (S-Q26-b)
 *
 * 배송 행 금액은 「그 주문 행이 지금 부담하는 배송비 몫」이다. 주문 생성이 그렇게 만들고(createOrderShippings),
 * 부분취소·재활성 재계산도 같은 뜻으로 다시 쓴다. 몫이 없는 행(취소·교환완료·이번에 빠진 행)은 0 이다 —
 * 취소 시점의 배송비는 취소신청서 shipping_snapshot 과 환불 문서가 기록한다. 분할로 새로 생긴 행의 배송 행까지
 * 맞추려고 쓰기 직전에 배송 행을 다시 읽는다. 한 주문 행에 배송 행이 여럿이면(병합 이전분) id 가 가장 작은 행이
 * 몫을 받고 나머지는 0 이다. 배송비 쿠폰 할인은 행에 안분하지 않는다(생성과 같은 계약 — 헤더에만 있다).
 */
class OrderShippingAmountWriter
{
    /**
     * @param  OrderShippingRepositoryInterface  $orderShippingRepository  주문 배송 저장소
     * @param  CurrencyConversionService  $currencyService  통화 변환 서비스
     */
    public function __construct(
        private OrderShippingRepositoryInterface $orderShippingRepository,
        private CurrencyConversionService $currencyService,
    ) {}

    /**
     * 행별 배송비 몫을 배송 행에 씁니다.
     *
     * @param  Order  $order  주문 (currency_snapshot 사용)
     * @param  array<int, array{base: int, extra: int}>  $shares  order_option_id => 몫 (빈 배열이면 아무것도 쓰지 않는다)
     * @return void 반환값 없음
     */
    public function apply(Order $order, array $shares): void
    {
        if ($shares === []) {
            return;
        }

        $snapshot = $order->currency_snapshot;
        $assigned = [];

        foreach ($this->orderShippingRepository->listByOrderId((int) $order->id) as $row) {
            $rowId = (int) $row->order_option_id;
            [$base, $extra] = (isset($shares[$rowId]) && ! isset($assigned[$rowId]))
                ? [(int) $shares[$rowId]['base'], (int) $shares[$rowId]['extra']]
                : [0, 0];
            $assigned[$rowId] = true;

            $data = [
                'base_shipping_amount' => $base,
                'extra_shipping_amount' => $extra,
                'total_shipping_amount' => $base + $extra,
                'shipping_discount_amount' => 0,
            ];

            if (! empty($snapshot)) {
                $convert = fn (int $amount) => $this->currencyService->convertToMultiCurrencyWithSnapshot($amount, $snapshot);
                $data += [
                    'mc_base_shipping_amount' => $convert($base),
                    'mc_extra_shipping_amount' => $convert($extra),
                    'mc_total_shipping_amount' => $convert($base + $extra),
                    'mc_shipping_discount_amount' => $convert(0),
                ];
            }

            $this->orderShippingRepository->updateRow($row, $data);
        }
    }
}
