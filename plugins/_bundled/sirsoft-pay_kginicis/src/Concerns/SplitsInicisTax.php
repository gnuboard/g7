<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\PayKginicis\Concerns;

use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderPayment;
use Modules\Sirsoft\Ecommerce\Services\CurrencyConversionService;

/**
 * KG이니시스 과세 구분(부가세 `tax`·비과세 `taxfree`) 승인 표식과 부분취소 분할.
 *
 * 결제창(stdpay_pc `tax`·`taxfree` / stdpay_m `P_TAX`·`P_TAXFREE`)은 면세분이 있는 결제에만 과세 구분을 싣는다
 * (`requestPayment.ts` `buildInicisTaxAmounts`). 부분취소(`/v2/pg/partialRefund` 의 `tax`·`taxFree`)는 **승인 때 과세 구분을
 * 실은 결제에만** 같은 구분을 실어야 한다 — 승인에 없던 구분을 취소에만 실으면 PG 가 「취소할 수 없는 금액」으로 거절하고,
 * 승인에 실은 구분을 취소에서 빼면 면세 상품 환불이 과세분 잔액을 넘을 때 거절된다(토스 「세금 처리」와 같은 동작).
 * 그래서 승인 때 `payment_meta.tax_split` 에 승인 면세액을 남기고, 부분취소는 그 표식이 있을 때만 구분을 싣는다(#820).
 */
trait SplitsInicisTax
{
    /** 과세 합계를 알 수 없을 때의 부가세율(%) — 이커머스 `VatCalculator::DEFAULT_RATE` 와 같다. */
    private static int $inicisDefaultVatRate = 10;

    /**
     * 승인 결제에 남길 과세 구분 표식을 만듭니다.
     *
     * 결제창이 실은 값과 같은 규칙이다 — 주문 면세 합계(면세 + 영세, `Order::pgTaxFreeAmount()`)를 청구액과 같은
     * 스냅샷 환산 규칙으로 바꾸고 승인액으로 자른다(이커머스 `buildPgPaymentData` 의 `tax_free_amount`, 1.3.3 #612).
     *
     * @param  Order  $order  승인한 주문
     * @param  int  $approvedAmount  승인 금액(결제 통화 최소 단위)
     * @return array<string, array{tax_free_amount: int, tax_free_cancelled: int}> payment_meta 에 합칠 배열, 면세분이 없으면 빈 배열
     */
    private function buildTaxSplitApprovalMeta(Order $order, int $approvedAmount): array
    {
        $taxFreeBase = $this->pgTaxFreeOf($order);
        if ($taxFreeBase <= 0 || $approvedAmount <= 0) {
            return [];
        }

        $taxFree = (int) app(CurrencyConversionService::class)
            ->resolveSnapshotPaymentCharge($taxFreeBase, $order->currency_snapshot ?? [])['minor_unit_amount'];
        $taxFree = min($taxFree, $approvedAmount);

        return $taxFree > 0
            ? ['tax_split' => ['tax_free_amount' => $taxFree, 'tax_free_cancelled' => 0]]
            : [];
    }

    /**
     * 부분취소 금액을 부가세·비과세로 나눕니다.
     *
     * 승인 표식이 없으면(면세분 없는 결제·이 표식 이전의 결제) null — 과세 구분을 싣지 않는다. 비과세는 취소 금액을 주문의
     * 면세:과세 비율로 나눈 몫이고, 승인 면세액에서 이미 취소한 면세분을 뺀 잔여 예산을 넘지 않는다(주문 합계는 환불마다
     * 잔여로 줄어 회차별 몫의 합이 승인 면세액을 넘을 수 있다 — 토스 S1-D14 와 같은 상한). 부가세는 나머지 과세분을
     * 주문 부가세 비율로 뗀다.
     *
     * @param  Order  $order  주문
     * @param  OrderPayment  $payment  결제 레코드 (승인 표식의 출처)
     * @param  int  $cancelAmount  이번 부분취소 금액
     * @return array{tax: int, taxFree: int}|null 부가세·비과세, 표식이 없으면 null
     */
    private function resolvePartialTaxSplit(Order $order, OrderPayment $payment, int $cancelAmount): ?array
    {
        $split = is_array($payment->payment_meta) ? ($payment->payment_meta['tax_split'] ?? null) : null;
        if (! is_array($split) || $cancelAmount <= 0) {
            return null;
        }

        $budget = max(0, (int) ($split['tax_free_amount'] ?? 0) - (int) ($split['tax_free_cancelled'] ?? 0));
        // 승인 때와 같은 분류 — 면세 = 면세 + 영세, 과세 = 영세를 뺀 과세 합계(#612)
        $taxFreeTotal = (int) round($this->pgTaxFreeOf($order));
        $taxableTotal = (int) round($this->pgTaxableOf($order));
        $classified = $taxFreeTotal + $taxableTotal;

        $share = match (true) {
            $classified <= 0 => 0,
            $cancelAmount >= $classified => $taxFreeTotal,
            default => (int) round($cancelAmount * $taxFreeTotal / $classified),
        };
        $taxFree = max(0, min($share, $cancelAmount, $budget));

        $taxablePart = $cancelAmount - $taxFree;
        $vatTotal = (int) round((float) ($order->total_vat_amount ?? 0));
        $vatRatio = $taxableTotal > 0
            ? $vatTotal / $taxableTotal
            : self::$inicisDefaultVatRate / (100 + self::$inicisDefaultVatRate);
        $tax = $taxablePart > 0 ? min($taxablePart, (int) round($taxablePart * $vatRatio)) : 0;

        return ['tax' => $tax, 'taxFree' => $taxFree];
    }

    /**
     * 성립한 부분취소의 비과세분을 승인 표식의 소진액에 더한 payment_meta 를 돌려줍니다.
     *
     * @param  OrderPayment  $payment  결제 레코드
     * @param  int  $taxFree  이번 회차에 취소한 비과세 금액
     * @return array 갱신할 payment_meta (표식이 없으면 그대로)
     */
    private function withTaxFreeCancelled(OrderPayment $payment, int $taxFree): array
    {
        $meta = is_array($payment->payment_meta) ? $payment->payment_meta : [];
        if (! is_array($meta['tax_split'] ?? null)) {
            return $meta;
        }

        $meta['tax_split']['tax_free_cancelled'] = (int) ($meta['tax_split']['tax_free_cancelled'] ?? 0) + max(0, $taxFree);

        return $meta;
    }

    /**
     * 결제사에 면세로 넘길 주문 금액(면세 + 영세)을 읽습니다.
     *
     * 이커머스 1.3.3 의 `Order::pgTaxFreeAmount()` 를 쓰고, 그 메서드가 없는 구버전(영세 없음)은 면세 합계로 떨어진다 —
     * 플러그인 갱신은 이커머스 의존 버전을 다시 검사하지 않아 구버전과 함께 돌 수 있다.
     *
     * @param  Order  $order  주문
     * @return float 면세 + 영세
     */
    private function pgTaxFreeOf(Order $order): float
    {
        return method_exists($order, 'pgTaxFreeAmount')
            ? $order->pgTaxFreeAmount()
            : (float) ($order->total_tax_free_amount ?? 0);
    }

    /**
     * 결제사에 과세로 넘길 주문 금액(영세를 뺀 과세 합계, 부가세 포함)을 읽습니다.
     *
     * @param  Order  $order  주문
     * @return float 과세 합계(영세 제외)
     */
    private function pgTaxableOf(Order $order): float
    {
        return method_exists($order, 'pgTaxableAmount')
            ? $order->pgTaxableAmount()
            : (float) ($order->total_tax_amount ?? 0);
    }
}
