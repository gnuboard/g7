<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Helpers\PermissionHelper;
use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Models\OrderCashReceipt;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Support\OrderProgressSteps;
use Modules\Sirsoft\Ecommerce\Support\ShippingPolicySnapshot;

/**
 * 비회원 주문 상세 리소스
 *
 * 조회 인증 토큰으로 보호되는 비회원 주문 상세 응답 전용 리소스.
 * 회원용 OrderResource 와 달리 다음을 노출하지 않는다.
 * - admin_memo / customer_memo (관리자·내부 메모)
 * - user / user_id / user_login_id (회원 정보)
 * - promotions_applied_snapshot (내부 계산 스냅샷)
 * - 회원 권한 메타(resourceMeta/abilityMap)
 *
 * `shipping_policy_applied_snapshot` 은 **표시용 필드만** 추려 내보낸다. 비회원 주문 상세
 * 화면이 회원 마이페이지와 같은 partial 로 상품별 정책명·개별 배송비를 그리므로, 통째로
 * 빼면 오류 없이 그 줄만 사라진다. 정책 id·계산 근거는 `ShippingPolicySnapshot::forDisplay()`
 * 가 걸러낸다.
 *
 * 허용 액션(abilities)은 회원 권한이 아니라 주문 상태로만 판정한다.
 */
class GuestOrderResource extends BaseApiResource
{
    use HasMultiCurrencyPrices;

    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array 비회원 주문 상세 응답 배열
     */
    public function toArray(Request $request): array
    {
        // 주문 시점 기준 통화 — 과거 주문의 *_formatted 는 설정 변경과 무관하게 이 통화로 고정 표기한다.
        $orderCurrency = $this->resolveOrderBaseCurrencyCode($this->resource);
        // 주문 시점 통화 스냅샷 — 소수 자릿수를 현재 설정이 아닌 주문 시점 값으로 고정 (공개 #91 후속).
        $currencySnapshot = $this->resource->currency_snapshot ?? null;
        $this->withCurrencySnapshot($currencySnapshot);

        // 클레임 추가결제 실수납 합계 — 결제 완료 건의 청구액에서 부분 환급을 뺀 값. 주문 1건당 집계 1회.
        // 산정은 ClaimPaymentService 의 게터를 경유한다 — 같은 값을 리소스가 저장소에서 따로 집계하면
        // 뒤에 이 합계의 정의가 바뀔 때(예: 환급 반영 범위) 두 곳이 갈린다. 단건 상세 전용이라
        // 목록에서 행마다 해석되지 않는다(목록은 OrderListResource).
        $claimPaid = app(ClaimPaymentService::class)->paidTotalForOrder($this->resource);

        // 결제 통화(order_currency) — 현금영수증 금액 표기 기준(구매자가 실제 낸 통화).
        $paymentCurrency = $this->currency
            ?: (data_get($this->currency_snapshot, 'order_currency') ?: $orderCurrency);

        return [
            'order_number' => $this->order_number,
            // 주문 금액 표기 기준 통화 — 회원 응답(`OrderResource`)과 **같은 키로 같은 값**이다.
            // 비회원 화면은 회원과 같은 partial·핸들러를 쓰고, 그 핸들러가 보조 통화 목록에서
            // 기준 통화를 걸러 낼 때 이 키를 읽는다(`claimApplyHandlers.ts::orderCurrencyCode`).
            // 이 키가 없으면 필터 기준이 null 이 되어 비회원 신청서에서만 같은 금액이 기준 통화
            // 줄과 보조 통화 줄에 **두 번** 나온다 — 둘 다 정상 서식이라 흔적이 남지 않는다.
            // 통화 코드는 민감 정보가 아니며 이미 모든 `*_formatted` 가 그 통화로 표기된다.
            'base_currency' => $orderCurrency,
            'order_status' => $this->order_status,
            'order_status_label' => $this->order_status ? $this->order_status->label() : null,
            'order_status_variant' => $this->order_status ? $this->order_status->variant() : null,
            // 아이콘 의미 토큰(status-*) — 글리프 이름이 아니다. 템플릿 Icon 이 해석한다.
            'order_status_icon' => $this->order_status ? $this->order_status->icon() : null,
            // 진행단계 배열 — 회원 상세와 같은 partial 이 같은 배열로 그린다(OrderProgressSteps 단일 출처).
            'progress_steps' => OrderProgressSteps::for($this->resource),
            // 부분취소 파생 플래그 — 일부 옵션만 취소된 주문(별도 order_status 아님). 보조 뱃지 표시용.
            'is_partially_cancelled' => $this->whenLoaded('options', fn () => $this->resource->isPartiallyCancelled(), false),

            // 금액
            'subtotal_amount' => $this->roundToOrderCurrency($this->subtotal_amount, $orderCurrency),
            'subtotal_amount_formatted' => $this->formatOrderCurrency($this->subtotal_amount, $orderCurrency),
            'total_discount_amount' => $this->roundToOrderCurrency($this->total_discount_amount, $orderCurrency),
            'total_discount_amount_formatted' => $this->formatOrderCurrency($this->total_discount_amount, $orderCurrency),
            'total_shipping_amount' => $this->roundToOrderCurrency($this->total_shipping_amount, $orderCurrency),
            'total_shipping_amount_formatted' => $this->formatOrderCurrency($this->total_shipping_amount, $orderCurrency),
            'total_amount' => $this->roundToOrderCurrency($this->total_amount, $orderCurrency),
            'total_amount_formatted' => $this->formatOrderCurrency($this->total_amount, $orderCurrency),
            'total_paid_amount' => $this->roundToOrderCurrency($this->total_paid_amount, $orderCurrency),
            'total_paid_amount_formatted' => $this->formatOrderCurrency($this->total_paid_amount, $orderCurrency),
            'total_cancelled_amount' => $this->roundToOrderCurrency($this->total_cancelled_amount, $orderCurrency),
            'total_cancelled_amount_formatted' => $this->formatOrderCurrency($this->total_cancelled_amount, $orderCurrency),
            'total_refunded_amount' => $this->roundToOrderCurrency($this->total_refunded_amount, $orderCurrency),
            'total_refunded_amount_formatted' => $this->formatOrderCurrency($this->total_refunded_amount, $orderCurrency),
            'total_refunded_points_amount' => $this->roundToOrderCurrency($this->total_refunded_points_amount, $orderCurrency),
            'total_refunded_points_amount_formatted' => $this->formatOrderCurrency($this->total_refunded_points_amount, $orderCurrency),

            // 클레임 추가결제 합계 (교환·반품 청구액의 실수납 합계 — 부분 환급 차감, 0원이면 화면 미표시)
            // 주문 총 배송비와 분리해 표기한다(§2 해석 확정). ClaimPaymentService::paidTotalForOrder 가 SSoT.
            'claim_additional_paid_total' => $this->roundToOrderCurrency($claimPaid['amount'], $orderCurrency),
            'claim_additional_paid_total_formatted' => $this->formatOrderCurrency($claimPaid['amount'], $orderCurrency),
            'mc_claim_additional_paid_total' => $this->formatStoredMultiCurrency($claimPaid['mc'] ?: null),

            // 마일리지/예치금 — 회원 OrderResource 와 동일 노출. 비회원 주문상세도 회원과 같은 결제정보 partial 을
            // 재사용하므로 사용/적립 마일리지를 동등하게 표시할 수 있도록 보강 (마일리지는 base_currency 단일 정산).
            'total_points_used_amount' => $this->roundToOrderCurrency($this->total_points_used_amount, $orderCurrency),
            'total_points_used_amount_formatted' => $this->formatOrderCurrency($this->total_points_used_amount, $orderCurrency),
            'total_deposit_used_amount' => $this->roundToOrderCurrency($this->total_deposit_used_amount, $orderCurrency),
            'total_deposit_used_amount_formatted' => $this->formatOrderCurrency($this->total_deposit_used_amount, $orderCurrency),
            'total_earned_points_amount' => $this->roundToOrderCurrency($this->total_earned_points_amount, $orderCurrency),
            'total_earned_points_amount_formatted' => $this->formatEarnedPoints($this->total_earned_points_amount),

            // 다중 통화 금액 (주문 시점 스냅샷) — 회원 OrderResource 와 동일 노출.
            // 통화 선택 UI 가 추후 노출되어도 비회원 화면이 회원과 동등하게 다중 통화를 표시할 수 있도록 보강.
            'mc_subtotal_amount' => $this->formatStoredMultiCurrency($this->mc_subtotal_amount),
            'mc_total_discount_amount' => $this->formatStoredMultiCurrency($this->mc_total_discount_amount),
            'mc_total_shipping_amount' => $this->formatStoredMultiCurrency($this->mc_total_shipping_amount),
            'mc_total_amount' => $this->formatStoredMultiCurrency($this->mc_total_amount),
            'mc_total_points_used_amount' => $this->formatStoredMultiCurrency($this->mc_total_points_used_amount),
            'mc_total_deposit_used_amount' => $this->formatStoredMultiCurrency($this->mc_total_deposit_used_amount),

            // 수량
            'item_count' => $this->item_count,
            'total_quantity' => $this->whenLoaded('options', fn () => $this->options->sum('quantity'), 0),

            // 일시 — raw ISO 와 사용자 타임존 변환된 *_formatted 를 함께 제공 (회원 OrderResource 와 동일 패턴)
            'ordered_at' => $this->ordered_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: paired with *_formatted user-tz field
            'ordered_at_formatted' => $this->formatDateTimeStringForUser($this->ordered_at),
            'paid_at' => $this->paid_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: paired with *_formatted user-tz field
            'paid_at_formatted' => $this->formatDateTimeStringForUser($this->paid_at),
            'confirmed_at' => $this->confirmed_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: paired with *_formatted user-tz field
            'confirmed_at_formatted' => $this->formatDateTimeStringForUser($this->confirmed_at),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: paired with *_formatted user-tz field
            'cancelled_at_formatted' => $this->formatDateTimeStringForUser($this->cancelled_at),

            // 주문자 정보 (본인이 입력한 정보 — 배송지에서 플래튼)
            'orderer_name' => $this->shippingAddress?->orderer_name,
            'orderer_phone' => $this->shippingAddress?->orderer_phone,
            'orderer_email' => $this->shippingAddress?->orderer_email,

            // 수취인/배송지 정보
            'recipient_name' => $this->shippingAddress?->recipient_name,
            'recipient_phone' => $this->shippingAddress?->recipient_phone,
            'recipient_zipcode' => $this->shippingAddress?->zipcode,
            'recipient_address' => $this->shippingAddress?->address,
            'recipient_detail_address' => $this->shippingAddress?->address_detail,
            'delivery_memo' => $this->shippingAddress?->delivery_memo,
            'delivery_memo_label' => $this->shippingAddress?->delivery_memo_label,

            // 주문 옵션 (품목) — 주문 시점 통화를 자식에 전파. 클레임 점유 수량은 옵션마다 구하면
            // 옵션 수 × 3 쿼리라 여기서 한 번에 구해 주입한다.
            // 비회원 주문 품목 표시 — 리뷰 작성 가능(can_write_review)을 끈다 (비회원 상세에는 리뷰 창이 없다)
            'options' => $this->whenLoaded('options', function () use ($orderCurrency, $currencySnapshot) {
                $locked = OrderOptionResource::lockedQuantitiesFor($this->options);

                return OrderOptionResource::collection($this->options)->each(
                    fn ($r) => $r->withOrderCurrency($orderCurrency)
                        ->withCurrencySnapshot($currencySnapshot)
                        ->withClaimLockedQuantity($locked[(int) $r->resource->id] ?? null)
                        ->forGuestOrder()
                );
            }),

            // 배송지/결제/배송 정보
            'shipping_address' => new OrderAddressResource($this->whenLoaded('shippingAddress')),
            'payment' => $this->whenLoaded('payment', fn () => (new OrderPaymentResource($this->payment))->withOrderCurrency($orderCurrency)->withCurrencySnapshot($currencySnapshot)),
            'shippings' => $this->whenLoaded('shippings', fn () => OrderShippingResource::collection($this->shippings)->each(
                fn ($r) => $r->withOrderCurrency($orderCurrency)->withCurrencySnapshot($currencySnapshot)
            )),

            // 취소 이력 (취소 사유·상세 사유·취소일시 표시용) — 최근 취소가 먼저 오도록 정렬
            'cancels' => OrderCancelResource::collection($this->whenLoaded('cancels')),

            // 현금영수증 — 회원 OrderResource 와 동일 노출. 비회원 주문상세도 회원과 같은
            // 확장 레이아웃(mypage_order_cash_receipt)을 재사용하며, 그 카드는 `cash_receipt`
            // 하나로 발급완료/미발급을 가른다. 이 두 키가 없으면 발급에 성공해도 카드가 영구히
            // "미발급" 으로 남고 영수증 URL 에 도달할 방법이 사라진다(오류·경고 없음).
            // CashReceiptResource 는 식별번호를 마스킹 값으로만 내보내고 프로바이더 원응답을
            // 노출하지 않으므로 비회원에게 내려도 안전하다.
            // 통화 스냅샷은 명시 캡처가 필요하다 — 형제 항목들이 쓰는 화살표 함수와 달리
            // 이 클로저는 자동 캡처가 없어, use 목록에서 빠지면 undefined 로 떨어져 주문 시점
            // 통화(자릿수·절사 규칙)가 전파되지 않는다.
            'cash_receipt' => $this->whenLoaded('cashReceipts', function () use ($orderCurrency, $paymentCurrency, $currencySnapshot) {
                $active = OrderCashReceipt::filterActive($this->cashReceipts)[0] ?? null;

                return $active
                    ? (new CashReceiptResource($active))->withOrderCurrency($orderCurrency)->withCurrencySnapshot($currencySnapshot)->withReceiptCurrency($paymentCurrency)
                    : null;
            }),
            // 발급/취소 전체 이력 — 카드의 "직전 발급이 실패했는가" 분기가 cash_receipts[0] 를 본다.
            'cash_receipts' => $this->whenLoaded('cashReceipts', fn () => CashReceiptResource::collection(
                $this->cashReceipts->sortByDesc('id')->values()
            )->each(fn ($r) => $r->withOrderCurrency($orderCurrency)->withCurrencySnapshot($currencySnapshot)->withReceiptCurrency($paymentCurrency))),

            // 배송정책 — **표시용 필드만**. 비회원 주문 상세 화면은 회원 마이페이지와 같은
            // partial 을 써서 상품별 정책명·개별 배송비를 그리는데, 이 필드를 통째로 빼면
            // 오류 없이 그 줄만 사라진다(옵셔널 체이닝이 빈 배열로 떨어져 조건이 false).
            // 정책 id·계산 근거 같은 내부 값은 forDisplay() 가 걸러낸다.
            'shipping_policy_applied_snapshot' => ShippingPolicySnapshot::forDisplay(
                $this->shipping_policy_applied_snapshot
            ),

            // 상태 기반 허용 액션 (회원 권한이 아닌 주문 상태로만 판정)
            'abilities' => $this->guestAbilities(),
        ];
    }

    /**
     * 비회원에게 허용되는 액션을 계산합니다.
     *
     * 본인 확인은 조회 토큰으로 끝났지만, 취소는 회원과 같은 「주문 취소」 권한(guest 역할)을 함께
     * 본다 — 취소 창이 여는 사유 목록 API 와 비회원 취소 API 가 이 권한으로 판정되므로, 여기서 빼면
     * 운영자가 비회원 취소 권한을 거뒀을 때 버튼만 남고 창이 사유를 불러오지 못한다.
     *
     * @return array<string, bool>
     */
    private function guestAbilities(): array
    {
        $cancellableStatuses = module_setting(
            'sirsoft-ecommerce',
            'order_settings.cancellable_statuses',
            ['payment_complete']
        );

        return [
            'can_cancel' => $this->resource->isCancellable($cancellableStatuses)
                && PermissionHelper::check('sirsoft-ecommerce.user-orders.cancel'),
        ];
    }
}
