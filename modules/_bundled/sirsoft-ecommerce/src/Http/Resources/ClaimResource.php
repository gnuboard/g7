<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\MasksRefundBankAccount;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;

/**
 * 클레임 신청서 리소스 (교환·반품·환불·취소 공용 — 유저 목록·상세·비회원 공용)
 *
 * 네 유형의 헤더를 같은 모양(`claim_number` / `claim_type` / `status` / `items` / `images`)으로 정규화한다 —
 * 목록의 서브탭과 상세의 유형 분기 partial 이 하나의 데이터 형태를 읽는다(§5-1-1). 유형 고유 축
 * (교환의 재발송지·차액, 반품·교환의 회수 송장, 환불의 환불액·수단)은 있을 때만 싣는다.
 *
 * 결제 블록(`payments` / `outstanding_amount` / `payment_entry`)은 상세 응답에서 `withPaymentBlock()` 으로
 * 주입한다 — 목록 행마다 결제 건을 조회하지 않는다.
 */
class ClaimResource extends BaseApiResource
{
    use HasMultiCurrencyPrices;
    use MasksRefundBankAccount;

    /**
     * 상세 응답에 실을 결제 블록 (목록에서는 null)
     */
    protected ?array $paymentBlock = null;

    /**
     * 부모가 주입한 사유 라벨 맵 (`"{유형}:{코드}" => 라벨`, 단건 경로에서는 null)
     *
     * @var array<string, string>|null
     */
    protected ?array $reasonLabels = null;

    /**
     * 관리자 확장 필드 포함 여부 (유저 응답 형태는 불변)
     */
    protected bool $withAdminFields = false;

    /**
     * 목록 표현 여부 (화면이 목록 행에서 그리지 않는 것을 뺀다)
     */
    protected bool $forList = false;

    /**
     * 관리자 확장 필드(관리자 메모·처리자·신청자명)를 응답에 덧붙입니다.
     *
     * 유저 응답의 기존 키는 바꾸지 않고 **추가만** 한다 — 같은 리소스를 유저·관리자 화면이
     * 공유하므로 형태를 바꾸면 유저 화면이 조용히 깨진다.
     *
     * @return static 자기 자신
     */
    public function withAdminFields(): static
    {
        $this->withAdminFields = true;

        return $this;
    }

    /**
     * 목록 표현으로 좁힙니다. (신청내역 목록 — 유형 서브탭 단위 페이지네이션)
     *
     * 목록은 화면이 그 행에서 **실제로 그리는 것**만 싣는다. 이 리소스는 목록과 상세가
     * 겸용이라 상세에 필요한 것(첨부 전량·주소/배송비 스냅샷 JSON·상세 사유·반려 사유·
     * 환불 계좌)까지 행마다 실리는데, 신청내역 목록의 어느 칸도 그것을 읽지 않는다.
     * `per_page` 상한이 50 이고 신청서 하나가 상품 200줄까지 가질 수 있어, 그대로 두면
     * 한 페이지를 여는 것만으로 수천 줄이 응답에 실린다.
     *
     * 상품 행(`items`)은 남긴다 — 목록 카드가 상품마다 썸네일·옵션·수량·금액과 (교환이면)
     * 교환 대상을 그리므로 그것은 낭비가 아니라 화면이 요구하는 값이다.
     *
     * @return static 자기 자신
     */
    public function forList(): static
    {
        $this->forList = true;

        return $this;
    }

    /**
     * 결제 블록을 주입합니다.
     *
     * @param  array|null  $block  `UserClaimService::paymentBlock()` 결과
     * @return static 자기 자신
     */
    public function withPaymentBlock(?array $block): static
    {
        $this->paymentBlock = $block;

        return $this;
    }

    /**
     * 사유 라벨 맵을 주입합니다. (목록에서 부모가 한 번에 해석해 내려보낸다)
     *
     * 모델의 `getReasonLabel()`/`getRefundReasonLabel()` 은 관계가 아니라 **행마다 독립 쿼리**다
     * (`ClaimReason::where(type, code)->first()`, 캐시 계층 없음). 단건 상세에서는 1회지만 목록에서는
     * 행 수만큼 늘어난다 — `ClaimPaymentResource` 의 통화 스냅샷과 같은 구조라 처방도 같다:
     * 부모가 주입하면 그것을 쓰고, 주입이 없으면(단건 경로) 종전처럼 스스로 해석한다.
     *
     * @param  array<string, string>|null  $labels  `"{유형}:{코드}" => 라벨` 맵
     * @return static 자기 자신
     */
    public function withReasonLabels(?array $labels): static
    {
        $this->reasonLabels = $labels;

        return $this;
    }

    /**
     * 사유 라벨을 해석합니다. (주입 우선 → 없으면 모델 게터)
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @param  ClaimTypeEnum  $type  클레임 유형
     * @return string 사유 라벨
     */
    protected function resolveReasonLabel(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim, ClaimTypeEnum $type): string
    {
        $code = $claim instanceof OrderCancel
            ? ($claim->cancel_reason_type ?? '')
            : ($claim->reason_code ?? '');

        // 취소 이력의 사유는 환불 카탈로그에서 읽는다(`OrderCancel::getRefundReasonLabel()` 과 같은 축).
        $catalogType = $claim instanceof OrderCancel ? ClaimTypeEnum::REFUND->value : $type->value;

        if ($this->reasonLabels !== null) {
            return $this->reasonLabels[$catalogType.':'.$code] ?? $code;
        }

        return $claim instanceof OrderCancel ? $claim->getRefundReasonLabel() : $claim->getReasonLabel();
    }

    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 응답 배열
     */
    public function toArray(Request $request): array
    {
        $claim = $this->resource;
        $type = $claim->claimType();
        $order = $this->whenLoaded('order', fn () => $this->order, null);
        $currency = $this->orderCurrencyCode ?: ($claim->currency_code ?? ($order ? $this->resolveOrderBaseCurrencyCode($order) : null));
        // 주문에 스냅샷이 없어도 `[]` 로 내려보낸다 — 자식 리소스는 `null` 을 "부모가 주입하지 않았다" 로 읽어
        // 스스로 `loadMissing('order')` 를 태우는데, 그렇게 되읽어 봐야 값은 같고 결제 건 수만큼 쿼리만 는다.
        // 소비 지점은 `['exchange_rates'][...] ?? null` 하나뿐이라 `[]` 와 `null` 의 표기 결과는 동일하다.
        $snapshot = $this->currencySnapshot ?? $order?->currency_snapshot ?? [];
        $this->withOrderCurrency($currency)->withCurrencySnapshot($snapshot);

        [$numberField, $statusField] = match ($type) {
            ClaimTypeEnum::EXCHANGE => ['exchange_number', 'exchange_status'],
            ClaimTypeEnum::RETURN => ['return_number', 'return_status'],
            ClaimTypeEnum::REFUND => ['refund_number', 'refund_status'],
            ClaimTypeEnum::CANCEL => ['cancel_number', 'cancel_status'],
        };

        $status = $claim->{$statusField};
        $items = $this->itemsRelation($claim);

        $data = [
            'id' => $claim->id,
            'claim_number' => $claim->{$numberField},
            'claim_type' => $type->value,
            'claim_type_label' => $type->label(),
            'order_id' => $claim->order_id,
            'order_number' => $order?->order_number,
            'status' => $status instanceof \BackedEnum ? $status->value : $status,
            'status_label' => $status instanceof \BackedEnum ? $status->label() : null,
            'status_variant' => $status instanceof \BackedEnum && method_exists($status, 'variant') ? $status->variant() : null,
            'is_final' => method_exists($claim, 'isFinal') ? $claim->isFinal() : true,
            'is_withdrawable' => method_exists($claim, 'isWithdrawable') ? $claim->isWithdrawable() : false,
            // 수정 가능 경계 = 철회 경계 (D13 — 승인 전)
            'is_editable' => $type !== ClaimTypeEnum::CANCEL && method_exists($claim, 'isWithdrawable') && $claim->isWithdrawable(),
            'reason_code' => $claim->reason_code ?? $claim->cancel_reason_type ?? null,
            'reason_label' => $this->resolveReasonLabel($claim, $type),
            'reason_detail' => $this->forList ? null : ($claim->reason_detail ?? $claim->cancel_reason ?? null),
            'fault_type' => $claim->fault_type?->value ?? null,
            'fault_type_label' => $claim->fault_type?->label() ?? null,
            'reject_reason' => $this->forList ? null : ($claim->reject_reason ?? null),
            'requested_by' => $claim->requested_by ?? $claim->cancelled_by ?? null,
            'is_guest_request' => method_exists($claim, 'isGuestRequest') ? $claim->isGuestRequest() : ($claim->cancelled_by === null),
            'currency_code' => $currency,
            'items' => ClaimItemResource::collection($items)->each(
                fn ($r) => $r->withOrderCurrency($currency)->withCurrencySnapshot($snapshot)
            ),
            // 목록은 첨부를 그리지 않는다 — 사진은 상세의 사유 카드에서만 보인다. 저장소도
            // 목록에서 이 관계를 싣지 않으므로 값은 어차피 빈 배열이지만, 표현 쪽에서도
            // 의도를 남긴다(관계를 다시 싣는 변경이 조용히 목록을 부풀리지 않게).
            'images' => ($this->forList || $claim instanceof OrderCancel)
                ? []
                : ClaimImageResource::collection($this->whenLoaded('images', fn () => $this->images, [])),
            'requested_at' => $claim->created_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: paired with *_formatted user-tz field
            'requested_at_formatted' => $this->formatDateTimeStringForUser($claim->created_at),
            'approved_at_formatted' => $this->formatDateTimeStringForUser($claim->approved_at ?? null),
            'rejected_at_formatted' => $this->formatDateTimeStringForUser($claim->rejected_at ?? null),
            'withdrawn_at_formatted' => $this->formatDateTimeStringForUser($claim->withdrawn_at ?? null),
            'completed_at_formatted' => $this->formatDateTimeStringForUser($claim->completed_at ?? $claim->refunded_at ?? $claim->cancelled_at ?? null),
        ];

        if ($claim instanceof OrderExchange || $claim instanceof OrderReturn) {
            $data += [
                'charge_method' => $claim->charge_method?->value,
                'charge_method_label' => $claim->charge_method?->label(),
                'shipping_fee_mode' => $claim->shipping_fee_mode?->value,
                'claim_shipping_fee' => $this->roundToOrderCurrency((float) $claim->claim_shipping_fee, $currency),
                'claim_shipping_fee_formatted' => $this->formatOrderCurrency((float) $claim->claim_shipping_fee, $currency),
                'mc_claim_shipping_fee' => $this->formatStoredMultiCurrency($claim->mc_claim_shipping_fee),
                'shipping_fee_snapshot' => $this->forList ? null : $claim->shipping_fee_snapshot,
                'total_charge_amount' => $this->roundToOrderCurrency((float) $claim->total_charge_amount, $currency),
                'total_charge_amount_formatted' => $this->formatOrderCurrency((float) $claim->total_charge_amount, $currency),
                'mc_total_charge_amount' => $this->formatStoredMultiCurrency($claim->mc_total_charge_amount),
                'requires_additional_payment' => $claim->requiresAdditionalPayment(),
                'return_address_snapshot' => $this->forList ? null : $claim->return_address_snapshot,
                'collection_carrier_id' => $claim->collection_carrier_id,
                'collection_carrier_name' => $this->whenLoaded('collectionCarrier', fn () => $this->collectionCarrier?->getLocalizedName(), null),
                'collection_tracking_number' => $claim->collection_tracking_number,
                // 추적 URL 은 배송사 템플릿에서 만든다 — 화면이 규칙을 재조립하면 주문 배송 조회와
                // 두 벌이 된다(형제 `OrderShippingResource` 는 같은 빌더를 쓴다).
                'collection_tracking_url' => $this->whenLoaded('collectionCarrier', fn () => $claim->collection_tracking_number
                    ? $this->collectionCarrier?->buildTrackingUrl((string) $claim->collection_tracking_number)
                    : null, null),
                'collection_completed_at_formatted' => $this->formatDateTimeStringForUser($claim->collection_completed_at),
                // 구매자가 이 신청서에 적어 낸 환불 계좌 — 환불신청서(아래 OrderRefund 블록)와 같은
                // 키·같은 마스킹 게이트를 쓴다. 유형마다 이름이 다르면 운영자 화면이 유형별로 갈린다.
                // 환불 계좌는 목록의 어느 칸도 읽지 않는다. 은행명 해석은 행마다 설정을
                // 읽는 호출이기도 하다.
                'refund_bank_code' => $this->forList ? null : $claim->refund_bank_code,
                'refund_bank_name' => $this->forList ? null : $this->resolveRefundBankName($claim->refund_bank_code),
                'refund_bank_account' => $this->forList ? null : $this->refundBankAccountForViewer($claim->refund_bank_account),
                'refund_bank_holder' => $this->forList ? null : $this->refundBankHolderForViewer($claim->refund_bank_holder),
            ];
        }

        if ($claim instanceof OrderExchange) {
            $data += [
                'additional_product_amount' => $this->roundToOrderCurrency((float) $claim->additional_product_amount, $currency),
                'additional_product_amount_formatted' => $this->formatOrderCurrency((float) $claim->additional_product_amount, $currency),
                'refund_product_amount' => $this->roundToOrderCurrency((float) $claim->refund_product_amount, $currency),
                'refund_product_amount_formatted' => $this->formatOrderCurrency((float) $claim->refund_product_amount, $currency),
                'redelivery_address_snapshot' => $this->forList ? null : $claim->redelivery_address_snapshot,
                'redelivery_carrier_id' => $claim->redelivery_carrier_id,
                'redelivery_carrier_name' => $this->whenLoaded('redeliveryCarrier', fn () => $this->redeliveryCarrier?->getLocalizedName(), null),
                'redelivery_tracking_number' => $claim->redelivery_tracking_number,
                'redelivery_tracking_url' => $this->whenLoaded('redeliveryCarrier', fn () => $claim->redelivery_tracking_number
                    ? $this->redeliveryCarrier?->buildTrackingUrl((string) $claim->redelivery_tracking_number)
                    : null, null),
                'redelivered_at_formatted' => $this->formatDateTimeStringForUser($claim->redelivered_at),
            ];
        }

        if ($claim instanceof OrderRefund) {
            $data += [
                'refund_source_type' => $claim->refund_source_type?->value,
                'refund_method' => $claim->refund_method?->value,
                'refund_method_label' => $claim->refund_method?->label(),
                'refund_amount' => $this->roundToOrderCurrency((float) $claim->refund_amount, $currency),
                'refund_amount_formatted' => $this->formatOrderCurrency((float) $claim->refund_amount, $currency),
                'refund_points_amount' => $this->roundToOrderCurrency((float) $claim->refund_points_amount, $currency),
                'refund_points_amount_formatted' => $this->formatOrderCurrency((float) $claim->refund_points_amount, $currency),
                'refund_shipping_amount' => $this->roundToOrderCurrency((float) $claim->refund_shipping_amount, $currency),
                'refund_shipping_amount_formatted' => $this->formatOrderCurrency((float) $claim->refund_shipping_amount, $currency),
                // 상세의 배송비 대비 행은 교환·반품에서 `mc_claim_shipping_fee` 를 병기한다. 환불 분기가
                // 이 키를 내보내지 않으면 같은 자리에서 환불 신청서만 외화가 사라진다 — 컬럼은 이미 있고
                // 노출만 없었다.
                'mc_refund_shipping_amount' => $this->formatStoredMultiCurrency($claim->mc_refund_shipping_amount),
                'refund_total' => $this->roundToOrderCurrency((float) $claim->refund_amount + (float) $claim->refund_points_amount, $currency),
                'refund_total_formatted' => $this->formatOrderCurrency((float) $claim->refund_amount + (float) $claim->refund_points_amount, $currency),
                'mc_refund_amount' => $this->formatStoredMultiCurrency($claim->mc_refund_amount),
                // 화면의 보조 통화 줄은 바로 위 기준 통화 줄(`refund_total_formatted` = 현금 + 마일리지)과
                // 같은 값의 다른 표기여야 한다. `mc_refund_amount` 는 **현금분만**이라 마일리지가 섞인
                // 환불에서 두 줄이 서로 다른 금액이 되는데, 둘 다 정상 서식이라 흔적이 남지 않는다.
                'mc_refund_total' => $this->formatStoredMultiCurrency(
                    $this->sumMultiCurrency($claim->mc_refund_amount, $claim->mc_refund_points_amount)
                ),
                'refund_bank_code' => $this->forList ? null : $claim->refund_bank_code,
                'refund_bank_name' => $this->forList ? null : $this->resolveRefundBankName($claim->refund_bank_code),
                'refund_bank_account' => $this->forList ? null : $this->refundBankAccountForViewer($claim->refund_bank_account),
                'refund_bank_holder' => $this->forList ? null : $this->refundBankHolderForViewer($claim->refund_bank_holder),
                'refunded_at_formatted' => $this->formatDateTimeStringForUser($claim->refunded_at),
            ];
        }

        if ($claim instanceof OrderCancel) {
            $refund = $this->whenLoaded('refund', fn () => $this->refund, null);

            $data += [
                'cancel_type' => $claim->cancel_type?->value,
                'cancel_type_label' => $claim->cancel_type?->label(),
                'refund_amount' => $refund ? $this->roundToOrderCurrency((float) $refund->refund_amount, $currency) : null,
                'refund_amount_formatted' => $refund ? $this->formatOrderCurrency((float) $refund->refund_amount, $currency) : null,
                'refund_points_amount' => $refund ? $this->roundToOrderCurrency((float) $refund->refund_points_amount, $currency) : null,
                // 취소도 형제 유형과 같이 보조 통화를 병기한다 — 이 키가 없으면 다통화 상점에서
                // 취소 유형 상세만 외화 줄이 통째로 사라진다. 기준 줄이 현금분(`refund_amount_formatted`)
                // 이므로 여기도 현금분이다(합계를 실으면 위아래 두 줄이 다른 금액이 된다).
                'mc_refund_amount' => $refund ? $this->formatStoredMultiCurrency($refund->mc_refund_amount) : [],
                'refund_status' => $refund?->refund_status?->value,
                'refund_status_label' => $refund?->refund_status?->label(),
                'cancelled_at_formatted' => $this->formatDateTimeStringForUser($claim->cancelled_at),
            ];
        }

        if ($this->withAdminFields) {
            $data += [
                'admin_memo' => $claim->admin_memo ?? null,
                'processed_by' => $claim->processed_by ?? null,
                // 신청자 표기는 주문자명이다 — 클레임 테이블의 신청자 축은 회원 ID 뿐이라
                // 비회원 신청 건은 이름을 주문에서만 가져올 수 있다 (목록 검색 축과 동일 근거).
                'requester_name' => $order?->getOrdererName(),
            ];
        }

        if ($this->paymentBlock !== null) {
            $data['payments'] = ClaimPaymentResource::collection($this->paymentBlock['payments'])->each(
                fn ($r) => $r->withOrderCurrency($currency)->withCurrencySnapshot($snapshot)
            );
            $data['outstanding_amount'] = $this->roundToOrderCurrency((float) $this->paymentBlock['outstanding_amount'], $currency);
            $data['outstanding_amount_formatted'] = $this->formatOrderCurrency((float) $this->paymentBlock['outstanding_amount'], $currency);
            $data['pending_payment'] = $this->paymentBlock['pending_payment']
                ? (new ClaimPaymentResource($this->paymentBlock['pending_payment']))->withOrderCurrency($currency)->withCurrencySnapshot($snapshot)
                : null;
            $data['payment_entry'] = $this->paymentBlock['payment_entry'];
        }

        return $data;
    }

    /**
     * 유형별 상품 행 관계를 반환합니다. (미로드면 빈 컬렉션)
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return iterable 상품 행
     */
    protected function itemsRelation(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): iterable
    {
        $relation = match (true) {
            $claim instanceof OrderExchange => 'exchangeOptions',
            $claim instanceof OrderReturn => 'returnOptions',
            $claim instanceof OrderRefund => 'refundOptions',
            default => 'cancelOptions',
        };

        return $claim->relationLoaded($relation) ? $claim->{$relation} : [];
    }

    /**
     * 환불 은행 코드를 운영자 설정의 은행명으로 해석합니다.
     *
     * 은행 코드표는 운영자가 주문설정에서 관리한다. 화면이 그 표를 읽으려면 설정 데이터소스가
     * 필요한데 그 게이트는 클레임 화면의 라우트 권한보다 좁아, 클레임만 볼 수 있는 운영자에게는
     * 그 데이터소스만 403 이 되어 은행명이 조용히 빈다. 그래서 서버가 해석해 실어 준다 —
     * 해석 규칙은 계좌를 결제행에 반영할 때(`AppliesRefundBankAccount`)와 같은 자리를 쓴다.
     *
     * @param  string|null  $bankCode  은행 코드
     * @return string|null 은행명 (코드가 없으면 null, 목록에 없으면 코드 그대로)
     */
    protected function resolveRefundBankName(?string $bankCode): ?string
    {
        if ($bankCode === null || $bankCode === '') {
            return null;
        }

        return app(EcommerceSettingsService::class)->resolveBankName($bankCode);
    }
}
