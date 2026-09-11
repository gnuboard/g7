<?php

namespace Modules\Sirsoft\Ecommerce\Support;

use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;

/**
 * 주문금액 비교 스냅샷의 표시 재료를 만드는 단일 출처
 *
 * 미리보기(`OrderAdjustmentService`)와 접수 뒤 상세(`ClaimResource::calculation_comparison`)가
 * 같은 다통화 줄(정가·판매가·실결제)과 같은 정가 합계 규칙을 써야 한다. 접수 시에는
 * 다통화 스냅샷을 저장하지 않으므로(base 스냅샷만 동결) 상세는 읽을 때 여기서 다시 만든다 —
 * 두 곳이 각자 계산하면 같은 신청서의 미리보기와 상세가 다른 외화 줄을 보인다.
 *
 * 세 메서드의 본문은 `OrderAdjustmentService` 의 private 메서드에서 **이동**한 것이며
 * 그 서비스는 여기에 위임한다. 미리보기 출력은 이동 전과 바이트 단위로 같다
 * (`OrderAdjustmentServiceTest::test_empty_substitutions_preview_is_byte_identical`).
 */
final class CalculationSnapshotPresenter
{
    /**
     * @param  CurrencyConversionService  $currencyService  통화 변환 서비스
     */
    public function __construct(
        protected CurrencyConversionService $currencyService,
    ) {}

    /**
     * 주문 옵션의 정가(list_price) 합계를 계산합니다.
     *
     * 옵션 스냅샷의 list_price를 우선 사용하고,
     * 없으면 상품 스냅샷의 list_price + 옵션 price_adjustment로 계산합니다.
     *
     * 옵션 치환(교환 후 열)이 있으면 치환 수량만큼 원 정가를 빼고 신규 옵션 스냅샷의 정가를
     * 같은 규칙으로 더한다 — 치환이 없으면 종전 식과 완전히 같다.
     *
     * @param  Order  $order  주문
     * @param  array|null  $excludedMap  제외 맵 [order_option_id => 수량] (null이면 전체 옵션 대상)
     * @param  array  $substitutions  옵션 치환 입력 [order_option_id => {quantity, option_snapshot, …}]
     * @return float 정가 합계
     */
    public function listPriceTotal(Order $order, ?array $excludedMap = null, array $substitutions = []): float
    {
        $total = 0;

        foreach ($order->options as $option) {
            if ($option->option_status === OrderStatusEnum::CANCELLED) {
                continue;
            }

            $quantity = $option->quantity;
            if ($excludedMap !== null) {
                $cancelQty = $excludedMap[$option->id] ?? 0;
                $quantity -= $cancelQty;
            }

            $productSnapshot = $option->product_snapshot ?? [];
            $substitution = $substitutions[$option->id] ?? null;
            $substitutedQty = $substitution ? min((int) ($substitution['quantity'] ?? 0), max(0, (int) $quantity)) : 0;
            $quantity -= $substitutedQty;

            if ($quantity > 0) {
                $total += $this->listPriceOf($option->option_snapshot ?? [], $productSnapshot) * $quantity;
            }

            if ($substitutedQty > 0) {
                $total += $this->listPriceOf((array) ($substitution['option_snapshot'] ?? []), $productSnapshot) * $substitutedQty;
            }
        }

        return $total;
    }

    /**
     * 옵션 한 줄의 정가를 구합니다. (옵션 스냅샷 list_price → 상품 정가 + 옵션 조정액)
     *
     * @param  array  $optionSnapshot  옵션 스냅샷
     * @param  array  $productSnapshot  상품 스냅샷
     * @return float 정가
     */
    private function listPriceOf(array $optionSnapshot, array $productSnapshot): float
    {
        $listPrice = $optionSnapshot['list_price']
            ?? (($productSnapshot['list_price'] ?? 0) + ($optionSnapshot['price_adjustment'] ?? 0));

        return (float) $listPrice;
    }

    /**
     * 원 주문의 다통화 스냅샷을 생성합니다.
     *
     * @param  Order  $order  주문
     * @param  float|null  $listPriceTotal  정가 합계 (null 이면 주문에서 계산 — 접수 뒤 상세는 동결값을 넘긴다)
     * @return array|null {mc_subtotal_amount, mc_total_paid_amount, mc_total_list_price_amount, *_formatted}
     */
    public function mcOriginal(Order $order, ?float $listPriceTotal = null): ?array
    {
        if (! $order->currency_snapshot) {
            return null;
        }

        $formattedSubtotal = [];
        foreach ($order->mc_subtotal_amount ?? [] as $code => $amount) {
            $formattedSubtotal[$code] = $this->currencyService->formatPrice($amount, $code, $order->currency_snapshot);
        }

        $formattedTotalPaid = [];
        foreach ($order->mc_total_paid_amount ?? [] as $code => $amount) {
            $formattedTotalPaid[$code] = $this->currencyService->formatPrice($amount, $code, $order->currency_snapshot);
        }

        // 정가 합계 다통화 변환
        $listPriceTotal ??= $this->listPriceTotal($order);
        $convertedListPrice = $this->currencyService->convertMultipleAmountsWithSnapshot(
            ['mc_total_list_price_amount' => $listPriceTotal],
            $order->currency_snapshot
        );

        $mcListPrice = [];
        $mcListPriceFormatted = [];
        foreach ($convertedListPrice ?? [] as $currencyCode => $amounts) {
            if (is_array($amounts) && isset($amounts['mc_total_list_price_amount'])) {
                $mcListPrice[$currencyCode] = $amounts['mc_total_list_price_amount'];
                $mcListPriceFormatted[$currencyCode] = $amounts['mc_total_list_price_amount_formatted'] ?? '';
            }
        }

        return [
            'mc_subtotal_amount' => $order->mc_subtotal_amount,
            'mc_total_paid_amount' => $order->mc_total_paid_amount,
            'mc_total_list_price_amount' => $mcListPrice,
            'mc_subtotal_amount_formatted' => $formattedSubtotal,
            'mc_total_paid_amount_formatted' => $formattedTotalPaid,
            'mc_total_list_price_amount_formatted' => $mcListPriceFormatted,
        ];
    }

    /**
     * 재계산 결과(또는 동결된 재계산 스냅샷)의 다통화 스냅샷을 생성합니다.
     *
     * @param  array  $recalcSnapshot  재계산 스냅샷 (subtotal_amount · total_paid_amount · total_list_price_amount)
     * @param  array|null  $currencySnapshot  주문 시점 환율 스냅샷
     * @return array|null {mc_subtotal_amount, mc_total_paid_amount, mc_total_list_price_amount, *_formatted}
     */
    public function mcRecalculated(array $recalcSnapshot, ?array $currencySnapshot): ?array
    {
        if (! $currencySnapshot) {
            return null;
        }

        $converted = $this->currencyService->convertMultipleAmountsWithSnapshot(
            [
                'mc_subtotal_amount' => $recalcSnapshot['subtotal_amount'] ?? 0,
                'mc_total_paid_amount' => $recalcSnapshot['total_paid_amount'] ?? 0,
                'mc_total_list_price_amount' => $recalcSnapshot['total_list_price_amount'] ?? 0,
            ],
            $currencySnapshot
        );

        if (empty($converted)) {
            return null;
        }

        // {통화코드 => {mc_subtotal_amount, mc_total_paid_amount, mc_subtotal_amount_formatted, ...}} →
        // {mc_subtotal_amount => {통화코드 => 금액}, mc_subtotal_amount_formatted => {통화코드 => 포맷}, ...}
        $result = [
            'mc_subtotal_amount' => [],
            'mc_total_paid_amount' => [],
            'mc_total_list_price_amount' => [],
            'mc_subtotal_amount_formatted' => [],
            'mc_total_paid_amount_formatted' => [],
            'mc_total_list_price_amount_formatted' => [],
        ];

        foreach ($converted as $currencyCode => $amounts) {
            if (is_array($amounts)) {
                foreach (['mc_subtotal_amount', 'mc_total_paid_amount', 'mc_total_list_price_amount'] as $key) {
                    if (isset($amounts[$key])) {
                        $result[$key][$currencyCode] = $amounts[$key];
                        $result[$key.'_formatted'][$currencyCode] = $amounts[$key.'_formatted'] ?? '';
                    }
                }
            }
        }

        return $result;
    }
}
