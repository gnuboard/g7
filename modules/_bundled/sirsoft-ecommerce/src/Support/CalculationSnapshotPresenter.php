<?php

namespace Modules\Sirsoft\Ecommerce\Support;

use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
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

        // 회계 주체 판정은 재계산 입력(`OrderAdjustmentService::buildRecalcInput()`)과 같다 —
        // 교환완료(`EXCHANGED`)로 닫힌 원 행은 후속 행이 승계하므로 빼고, 원 행이 아직 교환
        // 진행 중인 후속 행은 원 행이 주체이므로 뺀다. 취소만 빼면 교환이 끝난 주문에서 같은
        // 상품의 정가가 두 번 더해진다(#599 validate P2 #5).
        $exchangedSourceIds = $order->options
            ->filter(fn ($option) => $option->option_status === OrderStatusEnum::EXCHANGED)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($order->options as $option) {
            if (in_array($option->option_status, [OrderStatusEnum::CANCELLED, OrderStatusEnum::EXCHANGED], true)) {
                continue;
            }

            if ($option->isExchanged() && ! in_array((int) $option->source_option_id, $exchangedSourceIds, true)) {
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
     * 옵션 한 줄의 정가를 구합니다. 규칙은 주문 상세와 같은 단일 원천(OrderOption)을 쓴다 —
     * 옵션 스냅샷 정가 → 상품 정가 + 옵션 조정액 → 없으면 판매 단가.
     *
     * @param  array  $optionSnapshot  옵션 스냅샷
     * @param  array  $productSnapshot  상품 스냅샷
     * @return float 정가
     */
    private function listPriceOf(array $optionSnapshot, array $productSnapshot): float
    {
        return OrderOption::resolveListOrUnitPriceFromSnapshots($optionSnapshot, $productSnapshot);
    }

    /**
     * 동결된 기준 통화 금액을 주문 환율 스냅샷으로 환산합니다. (S3-D15)
     *
     * 「교환 후」 열(`mcRecalculated`)이 이미 같은 방식으로 재계산 스냅샷을 환산한다 — 「교환 전」
     * 열도 동결값을 받으면 같은 경로를 타야 두 열이 같은 근거 위에 선다.
     *
     * @param  float  $amount  기준 통화 금액 (접수 시점 동결값)
     * @param  string  $key  변환 키 (`mc_subtotal_amount` 등)
     * @param  array|null  $currencySnapshot  주문 시점 환율 스냅샷
     * @return array<string, float> 통화 코드 => 금액
     */
    private function convertFrozenAmount(float $amount, string $key, ?array $currencySnapshot): array
    {
        $converted = $this->currencyService->convertMultipleAmountsWithSnapshot(
            [$key => $amount],
            $currencySnapshot
        );

        $result = [];
        foreach ($converted ?? [] as $currencyCode => $amounts) {
            if (is_array($amounts) && isset($amounts[$key])) {
                $result[$currencyCode] = $amounts[$key];
            }
        }

        return $result;
    }

    /**
     * 원 주문의 다통화 스냅샷을 생성합니다.
     *
     * 모든 항목이 **같은 시점**을 가리켜야 한다(S3-D15). 기준 통화 줄은 접수 시점에 동결된
     * 스냅샷(`original_calculation_snapshot`)을 그리는데, 여기서 주문을 그대로 읽으면
     * **한 칸 안에서 두 시점이 섞인다** — 접수 뒤 그 주문에 취소·변동이 생기면 기준줄은
     * 252,000원인데 보조 줄은 224,000원이 된다. 둘 다 정상 서식이라 화면에도 응답에도
     * 어긋난 흔적이 남지 않는다. 그래서 동결값을 받으면 그것을 SSoT 로 쓴다.
     *
     * @param  Order  $order  주문 (환율 스냅샷·통화별 비율의 근거)
     * @param  float|null  $listPriceTotal  정가 합계 (null 이면 주문에서 계산 — 접수 뒤 상세는 동결값을 넘긴다)
     * @param  float|null  $subtotalTotal  상품금액 합계 동결값 (null 이면 주문의 현재 값)
     * @param  float|null  $paidTotal  실결제금액 동결값 (null 이면 주문의 현재 값)
     * @return array|null {mc_subtotal_amount, mc_total_paid_amount, mc_total_list_price_amount, *_formatted}
     */
    public function mcOriginal(
        Order $order,
        ?float $listPriceTotal = null,
        ?float $subtotalTotal = null,
        ?float $paidTotal = null
    ): ?array {
        if (! $order->currency_snapshot) {
            return null;
        }

        // 동결값이 오면 그것을 환율로 환산한다 — 주문의 현재 맵을 읽으면 기준줄과 시점이 갈린다(S3-D15).
        $mcSubtotal = $subtotalTotal !== null
            ? $this->convertFrozenAmount($subtotalTotal, 'mc_subtotal_amount', $order->currency_snapshot)
            : $order->mc_subtotal_amount;

        $mcTotalPaid = $paidTotal !== null
            ? $this->convertFrozenAmount($paidTotal, 'mc_total_paid_amount', $order->currency_snapshot)
            : $order->mc_total_paid_amount;

        $formattedSubtotal = [];
        foreach ($mcSubtotal ?? [] as $code => $amount) {
            $formattedSubtotal[$code] = $this->currencyService->formatPrice($amount, $code, $order->currency_snapshot);
        }

        $formattedTotalPaid = [];
        foreach ($mcTotalPaid ?? [] as $code => $amount) {
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
            'mc_subtotal_amount' => $mcSubtotal,
            'mc_total_paid_amount' => $mcTotalPaid,
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
