<?php

// audit:allow api-doc-coverage reason: 비교표 「교환 전」 열의 보조 통화가 접수시점 동결값이 아니라 현재 주문을 읽던 것을 고쳤다(S3-D15). 응답 키·상태코드·payload 형태 불변 — 값만 기준줄과 같은 시점으로 맞춘다. `api:docgen --scope=module:sirsoft-ecommerce --check` drift 없음 확인.

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Helpers\PermissionHelper;
use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\HasMultiCurrencyPrices;
use Modules\Sirsoft\Ecommerce\Http\Resources\Traits\MasksRefundBankAccount;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Services\ClaimRefundAccountPolicy;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;
use Modules\Sirsoft\Ecommerce\Services\PaymentMethodResolver;
use Modules\Sirsoft\Ecommerce\Services\RefundExecutionService;
use Modules\Sirsoft\Ecommerce\Support\CalculationSnapshotPresenter;
use Modules\Sirsoft\Ecommerce\Support\ClaimTrackingGroups;
use Modules\Sirsoft\Ecommerce\Support\RefundBreakdown;

/**
 * 클레임 신청서 리소스 (교환·반품·환불·취소 공용 — 유저 목록·상세·비회원 공용)
 *
 * 네 유형의 헤더를 같은 모양(`claim_number` / `claim_type` / `status` / `items` / `images`)으로 정규화한다 —
 * 목록의 서브탭과 상세의 유형 분기 partial 이 하나의 데이터 형태를 읽는다(§5-1-1). 유형 고유 축
 * (교환의 재발송지·차액·진행 방식, 반품·교환의 운송장 묶음, 환불의 환불액·수단)은 있을 때만 싣는다.
 *
 * 송장은 **상품 행**마다 있다 — 헤더에는 송장 칸이 없다. 상세는 운송장별 묶음(`tracking_groups`)을,
 * 목록은 방향별 첫 운송장과 개수(`tracking_summary`)를 싣는다. 상품 줄의 송장은 `items[]` 가 나른다.
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
     * 구매자가 이 신청서를 수정·철회할 수 있는 **출처**인지 판정합니다. (F3)
     *
     * 서버 게이트(`OrderRefundRequestService::withdraw()`·`updateRequest()`, 교환·반품의
     * `claim_admin_requested_not_editable`)와 같은 축을 쓴다 — 화면만 열려 있으면 버튼이 뜨고 누르면
     * 언제나 422 이고, 그것은 오류로 보고되지도 않는다. 교환·반품은 판매자 접수(`requested_by_admin`)가,
     * 환불은 발생 출처가 그 축이다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return bool 구매자 조작 가능하면 true
     */
    protected function buyerMayEdit(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): bool
    {
        // 판매자가 접수한 교환·반품은 고객이 철회·수정할 수 없다(D8 — 서버 게이트
        // `claim_admin_requested_not_editable` 와 같은 축).
        if ($claim instanceof OrderExchange || $claim instanceof OrderReturn) {
            return ! $claim->isAdminRequested();
        }

        if (! $claim instanceof OrderRefund) {
            return true;
        }

        return (bool) $claim->refund_source_type?->isBuyerEditable();
    }

    /**
     * 신청 출처를 판정합니다. (계획 676 — 출처 뱃지)
     *
     * 세 갈래다 — `customer`(고객이 직접 냈다) · `guest`(비회원이 냈다) · `admin`(운영자가
     * 대리 접수했다). 운영자는 이 셋을 다르게 대한다: 고객 건은 연락처가 계정에 있고,
     * 비회원 건은 주문서에 적힌 연락처뿐이며, 대리 접수 건은 **고객이 철회·수정할 수 없다**.
     *
     * **`requested_by` 하나로는 가를 수 없다** — 비회원 신청과 운영자 대리 접수가 똑같이
     * null 이다(`RefundSourceTypeEnum` 독블록 §4-3-1). 그래서 두 축을 쓴다:
     *
     * ① `refund_source_type = admin` 이면 운영자 대리 접수다. 이 값은 관리자 [주문취소]가
     *    명시적으로 심는 축이고(`Admin\OrderController::cancelOrder()`), 철회·수정 차단이
     *    이미 같은 값을 읽는다 — 뱃지가 그 판정과 어긋나지 않는다.
     * ② 나머지는 **주문의 주인**이 가른다(`isGuestRequest()` 와 같은 근거). `requested_by`
     *    로 가르면 회원 주문의 운영자 접수 건이 「비회원」으로 표시돼 운영자가 연락 수단을
     *    잘못 고른다 — 그 함정을 `OrderReturn::isGuestRequest()` 가 이미 기록해 두었다.
     *
     * ③ 반품·교환은 판매자가 주문 상세에서 직권 접수할 수 있다(D5~D8) — `requested_by_admin` 이
     *    있으면 `admin` 이다. 고객은 그 신청서를 철회·수정할 수 없다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return string 출처 코드 (customer|guest|admin)
     */
    protected function resolveRequestSource(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): string
    {
        if ($claim instanceof OrderRefund && $claim->refund_source_type === RefundSourceTypeEnum::ADMIN) {
            return 'admin';
        }

        if (($claim instanceof OrderExchange || $claim instanceof OrderReturn) && $claim->isAdminRequested()) {
            return 'admin';
        }

        $isGuest = method_exists($claim, 'isGuestRequest')
            ? $claim->isGuestRequest()
            : ($claim->cancelled_by === null);

        return $isGuest ? 'guest' : 'customer';
    }

    /**
     * 접수 때 고른 환불 우선순위를 반환합니다. (저장값이 없으면 null)
     *
     * 값은 신청서에 저장된 것만 낸다 — 환불액 구성으로 추측하지 않는다. 추측은 화면이 이미 하던
     * 것이고, 서버가 같은 추측을 내면 그 값이 저장값처럼 보여 틀린 추측을 굳힌다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @return string|null `pg_first` / `points_first` (저장값이 없으면 null)
     */
    protected function resolveRefundPriority(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim): ?string
    {
        if ($claim instanceof OrderCancel) {
            return null;
        }

        $value = $claim->getAttribute('refund_priority');

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return RefundPriorityEnum::tryFrom((string) $value)?->value;
    }

    /**
     * 신청자(주문의 주인) 회원의 uuid 를 반환합니다. (비회원 주문이면 null)
     *
     * 상세 응답 전용이다(관리자 확장 필드) — 관계가 실려 있으면 그것을, 없으면 한 번 읽는다.
     *
     * @param  Order|null  $order  주문
     * @return string|null 회원 uuid
     */
    protected function requesterUserUuid(?Order $order): ?string
    {
        if ($order === null || $order->user_id === null) {
            return null;
        }

        $order->loadMissing('user');

        return $order->user?->uuid;
    }

    /**
     * 판매자 접수·대리 철회를 한 운영자의 이름을 반환합니다. (관계가 실린 응답에서만 — 없으면 null)
     *
     * 관리자 상세 조회(`AdminClaimService::findForDetail()`)가 두 관계를 함께 싣는다. 여기서 지연 로드하지
     * 않는다 — 같은 관리자 확장 필드를 쓰는 주문상세 클레임 내역은 행마다 쿼리가 하나씩 붙는다.
     *
     * @param  OrderExchange|OrderReturn  $claim  교환·반품 신청서
     * @param  string  $relation  운영자 관계명 (`requestedByAdminUser` / `withdrawnByAdminUser`)
     * @return string|null 운영자 이름 (운영자 접수·철회가 아니거나 관계 미로드면 null)
     */
    protected function operatorNameOf(OrderExchange|OrderReturn $claim, string $relation): ?string
    {
        if (! $claim->relationLoaded($relation)) {
            return null;
        }

        return $claim->getRelation($relation)?->name;
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

        // 환불신청서와 취소 이력은 스스로 카탈로그 유형을 안다 — 교환·반품에서 파생된 건은 부모의
        // 사유 코드를 물려받으므로 그 코드가 실제로 등록된 부모 카탈로그를 봐야 한다(S17 트랙 A F-2).
        // 판정은 `OrderRefund::reasonCatalogType()`·`OrderCancel::reasonCatalogType()` 단일 출처를
        // 쓴다(모델 게터·목록 라벨 맵과 공유).
        $catalogType = match (true) {
            $claim instanceof OrderCancel, $claim instanceof OrderRefund => $claim->reasonCatalogType(),
            default => $type->value,
        };

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
            // 상태를 상징하는 아이콘 — 뱃지와 진행단계 스텝퍼가 같은 값을 읽는다 (Enum `icon()` 단일 출처).
            'status_icon' => $status instanceof \BackedEnum && method_exists($status, 'icon') ? $status->icon() : null,
            'is_final' => method_exists($claim, 'isFinal') ? $claim->isFinal() : true,
            // 출처 축을 함께 본다(F3). 상태만 보면 반품·교환 발 환불신청서와 운영자 대리 접수
            // 건에도 [수정]/[철회] 버튼이 뜨고, 서버는 `source_type` 으로 거절하므로 **누르면
            // 언제나 422** 다. 버튼은 서 있는데 언제나 실패하는 경로라 오류로 보이지도 않는다.
            // 권한 축도 함께 본다 — [철회]는 `user-claims.withdraw`, [수정]은 `user-claims.request` 라우트 권한이다.
            // 운영자가 권한을 거두면 버튼이 사라지고, 서버는 같은 권한으로 403 을 낸다(비회원은 guest 역할로 판정).
            'is_withdrawable' => $this->buyerMayEdit($claim) && method_exists($claim, 'isWithdrawable') && $claim->isWithdrawable()
                && PermissionHelper::check('sirsoft-ecommerce.user-claims.withdraw'),
            // 수정 가능 경계 = 철회 경계 (D13 — 승인 전)
            'is_editable' => $type !== ClaimTypeEnum::CANCEL && $this->buyerMayEdit($claim) && method_exists($claim, 'isWithdrawable') && $claim->isWithdrawable()
                && PermissionHelper::check('sirsoft-ecommerce.user-claims.request'),
            // 추가결제 [결제하기]의 권한(`user-claims.pay`) — 결제 대기 여부는 `pending_payment` 가 따로 말한다.
            'can_pay' => PermissionHelper::check('sirsoft-ecommerce.user-claims.pay'),
            'reason_code' => $claim->reason_code ?? $claim->cancel_reason_type ?? null,
            'reason_label' => $this->resolveReasonLabel($claim, $type),
            'reason_detail' => $this->forList ? null : ($claim->reason_detail ?? $claim->cancel_reason ?? null),
            'fault_type' => $claim->fault_type?->value ?? null,
            'fault_type_label' => $claim->fault_type?->label() ?? null,
            'reject_reason' => $this->forList ? null : ($claim->reject_reason ?? null),
            'requested_by' => $claim->requested_by ?? $claim->cancelled_by ?? null,
            'is_guest_request' => method_exists($claim, 'isGuestRequest') ? $claim->isGuestRequest() : ($claim->cancelled_by === null),
            // 출처 뱃지(계획 676) — 고객 신청 / 비회원 신청 / 운영자 접수 셋을 가른다.
            // `is_guest_request` 는 회원·비회원만 갈라 **운영자 대리 접수를 드러내지 못한다**.
            'request_source' => $this->resolveRequestSource($claim),
            'request_source_label' => __('sirsoft-ecommerce::enums.request_source.'.$this->resolveRequestSource($claim)),
            // 접수 때 고른 환불 우선순위(`pg_first` / `points_first`). 수정 화면이 이 값을 그대로 세운다 —
            // 화면이 환불액 구성(마일리지 > 0 이면 마일리지 우선)으로 추측하면 PG 잔여가 모자라 마일리지가
            // 섞인 PG 우선 신청서가 수정 화면에서 마일리지 우선으로 뒤집힌다. 저장된 값이 없는 신청서는
            // null 이고 화면은 종전 추측으로 떨어진다.
            'refund_priority' => $this->resolveRefundPriority($claim),
            'currency_code' => $currency,
            // 행별 전이 선택지는 **관리자 상세**에서만 실린다 — 목록은 50행 × 200상품마다
            // 전이표를 계산하게 되고, 유저 응답은 그 어휘를 쓰지 않는다.
            'items' => ClaimItemResource::collection($items)->each(
                function ($r) use ($currency, $snapshot, $claim) {
                    $r->withOrderCurrency($currency)->withCurrencySnapshot($snapshot);

                    // 선교환은 전이표·회수 진행 판정이 다르다 — 행이 그 방식을 알아야 선택지가 서버와 맞는다.
                    if ($claim instanceof OrderExchange) {
                        $r->withExchangeMode($claim->mode());
                    }

                    if ($this->withAdminFields && ! $this->forList) {
                        $r->forAdminTransitions();
                    }
                }
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
                // 헤더 회수지는 대표값(첫 상품)이다 — 상품마다 회수지가 다를 수 있어(S4) 화면은
                // `return_addresses` 로 주소별 묶음을 그린다.
                'return_address_snapshot' => $this->forList ? null : $claim->return_address_snapshot,
                'return_addresses' => $this->forList ? null : ClaimTrackingGroups::returnAddresses($claim),
                // 운송장별 묶음(상세) · 방향별 첫 운송장과 개수(목록). 송장은 상품 행에 있다 — 추적 URL 은
                // 배송사 템플릿에서 만든다(형제 `OrderShippingResource` 와 같은 빌더). [도착 처리] 가능
                // 여부는 관리자 상세에만 싣는다.
                'tracking_groups' => $this->forList ? null : ClaimTrackingGroups::for($claim, $this->withAdminFields),
                'tracking_summary' => $this->forList ? ClaimTrackingGroups::summary($claim) : null,
                // 「전체」 회수 완료 시각 — 신청서의 모든 살아 있는 상품이 회수도착에 이른 시각이다(S2).
                // 상품별 도착은 `items[].collected_at_formatted`.
                'collection_completed_at_formatted' => $this->formatDateTimeStringForUser($claim->collection_completed_at),
                'is_admin_requested' => $claim->isAdminRequested(),
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
                // 차액 2줄의 보조통화 — 형제 줄(`mc_total_charge_amount`·`mc_claim_shipping_fee`)과 같은
                // 규약이다. 재료는 신청 상품 행에 박제된 `mc_price_difference` 이고, 부호별로 갈라
                // 더한다(추가는 양수분, 환불은 음수분의 절대값) — 헤더의 `additional_product_amount` ·
                // `refund_product_amount` 가 만들어진 방식과 같다. 화면이 환율을 다시 보지 않게
                // 서버가 합계까지 만들어 내려보낸다(S17 트랙 A F-1).
                //
                // 합산 결과는 **반드시 `formatStoredMultiCurrency()` 를 통과시킨다**(S3-D14). 그 맵은
                // `{KRW: 3000, USD: 3}` 같은 맨숫자라 `.formatted` 가 없고, 화면은 `[1]?.formatted ?? [1]`
                // 로 폴백해 **기호도 자릿수도 없는 `3` `345` 를 그대로 그린다** — 형제 줄이
                // `$6.00 ¥690` 인데 이 줄만 `3 345` 가 되지만, 오류가 아니라 흔적이 남지 않는다.
                'mc_additional_product_amount' => $this->formatStoredMultiCurrency($this->sumSignedItemPriceDifferences($claim, true)),
                'mc_refund_product_amount' => $this->formatStoredMultiCurrency($this->sumSignedItemPriceDifferences($claim, false)),
                'redelivery_address_snapshot' => $this->forList ? null : $claim->redelivery_address_snapshot,
                // 「전체」 발송 완료 시각(S2). 상품별 도착은 `items[].redelivered_at_formatted`.
                'redelivered_at_formatted' => $this->formatDateTimeStringForUser($claim->redelivered_at),
            ] + $this->exchangeModeFields($claim);
        }

        // 결제는 확정됐는데 신청서가 결제대기에 멈춘 상태(B5) — 확정 뒤 전이가 거절된 건이다. 운영자가 상태를 직접 옮겨야 한다.
        if (($claim instanceof OrderExchange || $claim instanceof OrderReturn) && ! $this->forList) {
            $status = $claim instanceof OrderExchange ? $claim->exchange_status?->value : $claim->return_status?->value;
            $data['paid_but_transition_failed'] = $status === 'payment_pending'
                && $claim->requiresAdditionalPayment()
                && app(ClaimPaymentService::class)->paidAmount($claim) > 0
                && app(ClaimPaymentService::class)->outstandingAmount($claim) <= 0;
        }

        if ($claim instanceof OrderRefund) {
            // 차감된 적 없는 사용 마일리지는 돌려주지 않는다(Q15) — 이 수정 전에 마일리지 몫으로 접수된 신청서도 화면은
            // 0 과 그 이유를 보인다. 실행이 어차피 0 으로 바꾸므로, 저장값을 그대로 그리면 확인 창이 나가지 않을 돈을 예고한다.
            $pointsNotDeducted = $this->pointsNotDeducted($claim);
            $refundPoints = $pointsNotDeducted ? 0.0 : (float) $claim->refund_points_amount;
            $mcRefundPoints = $pointsNotDeducted ? null : $claim->mc_refund_points_amount;

            // 상세는 **실행 때 쓰일 수단**을 보인다(S3-D26) — 플러그인이 꺼진 동안 계좌로 폴백된 카드 신청서는
            // 플러그인이 돌아오면 PG 로 실행된다. 저장값(계좌)을 그대로 보이면 실행 창이 계좌를 요구하고 그 값이
            // 운영자 선택으로 박혀 PG 를 부르지 않는다. 목록 행은 리스너 조회가 행마다 돌지 않도록 저장값 그대로다.
            $shownMethod = $this->forList
                ? $claim->refund_method
                : app(OrderRefundRequestService::class)->effectiveRefundMethod($claim);

            $data += [
                'refund_source_type' => $claim->refund_source_type?->value,
                'refund_method' => $shownMethod?->value,
                'refund_method_label' => $shownMethod?->label(),
                // 실제로 돈이 간 수단별 금액 줄(S7) — 신청서 수단은 결제수단에서 정해져, 카드 주문을 마일리지로만 돌려준
                // 환불도 「PG사 환불」 이다. 상세의 수단 칸은 이 줄을 보인다(알림의 `refund_breakdown` 과 같은 규칙).
                'refund_breakdown_formatted' => $this->forList
                    ? null
                    : RefundBreakdown::lines($claim, fn (float $amount): string => $this->formatOrderCurrency($amount, $currency)),
                // PG 플러그인이 사라져 계좌 환불로 떨어진 건인지 (P14).
                //
                // 폴백 자체는 운영을 살린다 — 리스너가 없는데 PG 경로로 들어가면 아무도 응답하지
                // 않아 그 주문은 **영영 취소할 수 없다**. 그런데 화면에 보이는 것이 "계좌 환불" 뿐이면
                // 운영자는 카드 결제 건에 왜 계좌 송금을 하라는지 알 수 없고, 근거는 애플리케이션
                // 로그에만 남는다. 그 사실을 그 자리에서 말해 준다.
                //
                // 목록 행은 이 값을 그리지 않는다 — 행마다 주문·결제행을 읽고 PG 리스너를 조회해
                // 페이지 크기만큼 쿼리가 늘었다(N+1). 상세에서만 판정한다.
                'refund_fell_back_to_manual' => $this->forList ? null : $this->refundFellBackToManual($claim),
                // 이 환불을 실행하려면 **보낼 계좌가 있어야 하는가** (T2-D6).
                //
                // 화면이 결제수단 코드로 재현하면 안 된다 — 확장 결제수단이 자기 카탈로그에
                // 선언한 환불 수단과 갈리고, 어긋난 쪽은 오류 없이 「계좌 칸 없는 계좌 환불」
                // (운영자가 계좌를 못 적어 영영 못 끝낸다) 또는 「PG 환불인데 계좌를 요구」
                // (카드 환불이 잠긴다)가 된다. 서버가 실행 게이트와 같은 술어로 답한다.
                // 목록 행은 그리지 않는다(위와 같은 N+1 이유) — 상세에서만 판정한다.
                'refund_account_required' => $this->forList ? null : $this->refundAccountRequired($claim),
                // 같은 판정을 환불 수단마다 — 운영자가 상세에서 수단을 바꾼 뒤 [환불하기]를 누르므로, 확인 창은 지금
                // 고른 수단의 값으로 계좌 줄(used)·계좌 입력 칸과 잠금(required)을 세운다. 저장 수단 하나로 그리면
                // 「마일리지로 환불」로 바꿔도 계좌 줄이 남고, 계좌 없는 신청서는 실행 버튼이 잠긴다.
                'refund_account_by_method' => $this->forList ? null : app(ClaimRefundAccountPolicy::class)->judgementsByMethod($claim),
                'refund_amount' => $this->roundToOrderCurrency((float) $claim->refund_amount, $currency),
                'refund_amount_formatted' => $this->formatOrderCurrency((float) $claim->refund_amount, $currency),
                'refund_points_amount' => $this->roundToOrderCurrency($refundPoints, $currency),
                'refund_points_amount_formatted' => $this->formatOrderCurrency($refundPoints, $currency),
                // 사용 마일리지를 차감하지 않은 주문이라 마일리지 몫이 0 인가 — 화면이 「0 (차감되지 않은 마일리지)」로 이유를 보인다.
                'points_not_deducted' => $pointsNotDeducted,
                // 「마일리지로 환불」로 완료된 현금 환불액(D1) — 그 돈이 카드·계좌가 아니라 회원 마일리지로
                // 갔다는 사실을 상세가 따로 말한다(회원 클레임 상세의 「마일리지로 지급」 줄).
                // 교환으로 받은 상품의 환불이면 그 교환 때 낸 상위 옵션 차액도 낸 수단 그대로 돌려준다(S2·Q9) — 원 주문 환불액과
                // 따로 보인다(돈이 나가는 결제 건이 다르다).
                'exchange_difference_refund_total' => $this->roundToOrderCurrency($this->exchangeDifferenceTotal($claim), $currency),
                'exchange_difference_refund_total_formatted' => $this->formatOrderCurrency($this->exchangeDifferenceTotal($claim), $currency),
                // 합계와 함께 낸 수단을 말한다(05 S2-3 「X원(카드/마일리지)」) — 상세에서만 결제 건을 읽는다.
                'exchange_difference_refunds_formatted' => $this->forList
                    ? null
                    : $this->exchangeDifferenceFormatted($claim, fn (float $amount): string => $this->formatOrderCurrency($amount, $currency)),
                'refund_mileage_credit_amount' => $this->roundToOrderCurrency($this->mileageCreditAmount($claim), $currency),
                'refund_mileage_credit_amount_formatted' => $this->formatOrderCurrency($this->mileageCreditAmount($claim), $currency),
                // PG 승인 직후 장부 반영이 롤백돼 표식만 남은 건(§14-C) — 「승인 상태 + PG 거래 ID」 조합은 그
                // 표식에서만 생긴다. 다음 [환불하기]는 PG 를 다시 부르지 않고 장부만 확정한다.
                'pg_settled_pending_ledger' => $claim->refund_status === RefundStatusEnum::APPROVED && filled($claim->pg_transaction_id),
                'refund_shipping_amount' => $this->roundToOrderCurrency((float) $claim->refund_shipping_amount, $currency),
                'refund_shipping_amount_formatted' => $this->formatOrderCurrency((float) $claim->refund_shipping_amount, $currency),
                // 상세의 배송비 대비 행은 교환·반품에서 `mc_claim_shipping_fee` 를 병기한다. 환불 분기가
                // 이 키를 내보내지 않으면 같은 자리에서 환불 신청서만 외화가 사라진다 — 컬럼은 이미 있고
                // 노출만 없었다.
                'mc_refund_shipping_amount' => $this->formatStoredMultiCurrency($claim->mc_refund_shipping_amount),
                'refund_total' => $this->roundToOrderCurrency((float) $claim->refund_amount + $refundPoints, $currency),
                'refund_total_formatted' => $this->formatOrderCurrency((float) $claim->refund_amount + $refundPoints, $currency),
                'mc_refund_amount' => $this->formatStoredMultiCurrency($claim->mc_refund_amount),
                // 화면의 보조 통화 줄은 바로 위 기준 통화 줄(`refund_total_formatted` = 현금 + 마일리지)과
                // 같은 값의 다른 표기여야 한다. `mc_refund_amount` 는 **현금분만**이라 마일리지가 섞인
                // 환불에서 두 줄이 서로 다른 금액이 되는데, 둘 다 정상 서식이라 흔적이 남지 않는다.
                'mc_refund_total' => $this->formatStoredMultiCurrency(
                    $this->sumMultiCurrency($claim->mc_refund_amount, $mcRefundPoints)
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

            // 재활성 표시(Q29)는 운영 사건이라 관리자 응답에만 싣는다 — 구매자에게는 취소·환불 기록만 보인다.
            if ($this->withAdminFields) {
                $data += [
                    'reactivated_at' => $claim->reactivated_at?->toIso8601String(), // audit:allow datetime-display-user-timezone reason: paired with *_formatted user-tz field
                    'reactivated_at_formatted' => $claim->reactivated_at ? $this->formatDateTimeStringForUser($claim->reactivated_at) : null,
                    'reactivated_quantity' => (int) $claim->reactivated_quantity,
                    'total_cancel_quantity' => $claim->totalCancelQuantity(),
                    'is_partially_reactivated' => $claim->isPartiallyReactivated(),
                ];
            }
        }

        // 접수 시점 주문금액 비교(전/후) — 상세에서만 싣는다. 목록의 어느 칸도 이 표를 그리지 않는데
        // 스냅샷 두 벌은 행마다 수십 줄이라 목록을 통째로 부풀린다. 스냅샷이 없는 구형 신청서는 null.
        if (! $this->forList) {
            $data['calculation_comparison'] = $this->calculationComparison($claim, $order);
        }

        if ($this->withAdminFields) {
            $data += [
                'admin_memo' => $claim->admin_memo ?? null,
                'processed_by' => $claim->processed_by ?? null,
                // 신청자 표기는 주문자명이다 — 클레임 테이블의 신청자 축은 회원 ID 뿐이라
                // 비회원 신청 건은 이름을 주문에서만 가져올 수 있다 (목록 검색 축과 동일 근거).
                'requester_name' => $order?->getOrdererName(),
                // 그 이름에서 회원 화면으로 가는 링크의 대상. **주문의 주인**이지 `requested_by`
                // 가 아니다 — 위 이름이 주문자명이므로 둘이 어긋나면 운영자가 화면에서 본 사람과
                // 다른 회원 상세로 간다(운영자 대리 접수 건은 `requested_by` 가 그 운영자다).
                // 비회원 주문은 null 이고, 화면은 그때 링크 대신 평문 이름을 그린다.
                'requester_user_id' => $order?->user_id,
                // 회원 상세 링크의 대상 식별자. 관리자 회원 화면은 **uuid** 로 연다 — 숫자 id 로
                // 링크를 만들면 404 다(주문 상세 `OrderResource.user.uuid` 와 같은 축). 비회원은 null.
                'requester_user_uuid' => $this->requesterUserUuid($order),
            ];

            // 판매자 접수(D8)·대리 철회(S14)를 한 운영자 이름 — 관리자 상세에만 싣는다. 고객 응답은
            // 출처(`is_admin_requested`)만 알면 되고, 운영자 이름은 내부 인력 정보라 내보내지 않는다.
            if ($claim instanceof OrderExchange || $claim instanceof OrderReturn) {
                $data += [
                    'requested_by_admin_name' => $this->operatorNameOf($claim, 'requestedByAdminUser'),
                    'withdrawn_by_admin_name' => $this->operatorNameOf($claim, 'withdrawnByAdminUser'),
                ];
            }
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
            // 대기 건을 낼 수 있는 수단 — 화면이 수단을 다시 고르게 하는 근거다. 표시명은
            // 카탈로그 이름을 함께 싣는다 — 값만 주면 화면이 `point` 밖의 수단을 모두 카드로
            // 불렀다(S1-D14). 신청 화면의 `charge_payment_method_labels` 와 같은 모양이다.
            $data['available_payment_methods'] = $this->paymentBlock['available_payment_methods'] ?? [];
            $data['available_payment_method_labels'] = (object) ($this->paymentBlock['available_payment_method_labels'] ?? []);
        }

        return $data;
    }

    /**
     * 교환 진행 방식(일반·선교환)과 회수 기한·지연 필드를 만듭니다. (D1·D2)
     *
     * @param  OrderExchange  $claim  교환 신청서
     * @return array<string, mixed> 진행 방식 필드
     */
    protected function exchangeModeFields(OrderExchange $claim): array
    {
        $mode = $claim->mode();
        $overdue = ClaimTrackingGroups::overdueOf($claim);

        return [
            'exchange_mode' => $mode->value,
            'exchange_mode_label' => $mode->label(),
            'exchange_mode_variant' => $mode->variant(),
            'is_advance_exchange' => $mode === ExchangeModeEnum::ADVANCE,
            'collection_due_at_formatted' => $this->formatDateTimeStringForUser($claim->collection_due_at),
            'collection_due_date_formatted' => $claim->collection_due_at !== null
                ? $this->formatDateForUser($claim->collection_due_at)
                : null,
            'is_collection_overdue' => $overdue['is_overdue'],
            'collection_overdue_days' => $overdue['days'],
        ];
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
     * 접수 시점에 동결한 주문금액 비교 스냅샷(전/후)을 상세 표현으로 만듭니다.
     *
     * 출처는 유형별로 자기 컬럼이다 — 환불신청서·반품·교환은 자기 행, 취소는 취소 발 환불 행
     * (`refund` 관계)이다. 다통화 줄은 저장하지 않으므로 읽을 때 미리보기와 **같은 출처**
     * (`CalculationSnapshotPresenter`)로 다시 만든다 — 원 열의 정가 합계는 동결값을 넘겨 접수 당시와
     * 같은 금액이 환산되게 한다. 교환의 치환 근거 행은 재계산 스냅샷 안의 `_substituted_items` 에서
     * 꺼내고 그 내부 키는 노출하지 않는다.
     *
     * @param  OrderExchange|OrderReturn|OrderRefund|OrderCancel  $claim  신청서
     * @param  Order|null  $order  주문 (관계가 로드되지 않았으면 null — 다통화 줄만 비운다)
     * @return array{original: array, recalculated: array, mc_original: array|null, mc_recalculated: array|null, substituted_items: array}|null 비교 블록 (스냅샷이 없으면 null)
     */
    protected function calculationComparison(OrderExchange|OrderReturn|OrderRefund|OrderCancel $claim, ?Order $order): ?array
    {
        $source = $claim instanceof OrderCancel
            ? ($claim->relationLoaded('refund') ? $claim->refund : null)
            : $claim;

        $original = $source?->original_calculation_snapshot;
        $recalculated = $source?->recalculated_snapshot;

        if (! is_array($original) || $original === [] || ! is_array($recalculated) || $recalculated === []) {
            return null;
        }

        $substituted = $recalculated['_substituted_items'] ?? [];
        unset($recalculated['_substituted_items']);

        $presenter = app(CalculationSnapshotPresenter::class);
        $frozenListPrice = isset($original['total_list_price_amount']) ? (float) $original['total_list_price_amount'] : null;
        // 기준줄이 동결 스냅샷을 그리므로 보조 통화도 같은 시점을 가리켜야 한다(S3-D15).
        // 주문을 그대로 읽으면 접수 뒤 그 주문에 생긴 취소·변동이 보조 줄에만 반영돼
        // 한 칸 안에서 두 시점이 섞인다 — 둘 다 정상 서식이라 흔적이 남지 않는다.
        $frozenSubtotal = isset($original['subtotal_amount']) ? (float) $original['subtotal_amount'] : null;
        $frozenPaid = isset($original['total_paid_amount']) ? (float) $original['total_paid_amount'] : null;
        $currency = $this->orderCurrencyCode ?: ($original['base_currency'] ?? null);

        // 치환 근거 행의 단가는 raw 로 저장된다 — 상품 행(`ClaimItemResource`)과 같은 통화 포맷을 함께 싣는다.
        $substitutedRows = [];
        foreach (is_array($substituted) ? $substituted : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $substitutedRows[] = $row + [
                'unit_price_formatted' => $this->formatOrderCurrency((float) ($row['unit_price'] ?? 0), $currency),
                'new_unit_price_formatted' => $this->formatOrderCurrency((float) ($row['new_unit_price'] ?? 0), $currency),
            ];
        }

        // 적립 마일리지 줄의 표기 — 포인트 단위다(`formatted` 맵은 기준 통화 표기라 그대로 쓰면
        // 「710원」이 된다). 새 스냅샷은 서비스가 이미 싣지만(`OrderAdjustmentService`), 그 이전에
        // 접수된 신청서의 스냅샷에는 없으므로 읽을 때 같은 식(`formatEarnedPoints()`)으로 채운다.
        $original = $this->withEarnedPointsFormatted($original);
        $recalculated = $this->withEarnedPointsFormatted($recalculated);

        return [
            'original' => $original,
            'recalculated' => $recalculated,
            'mc_original' => $order ? $presenter->mcOriginal($order, $frozenListPrice, $frozenSubtotal, $frozenPaid) : null,
            'mc_recalculated' => $presenter->mcRecalculated($recalculated, $order?->currency_snapshot),
            'substituted_items' => $substitutedRows,
        ];
    }

    /**
     * 비교 스냅샷 한쪽에 적립 마일리지 포인트 표기(`total_earned_points_amount_formatted`)를 채웁니다.
     *
     * 이미 실려 있으면(새 스냅샷) 그대로 두고, 적립 금액 키가 없으면 아무것도 하지 않는다.
     *
     * @param  array  $snapshot  비교 스냅샷 한쪽 (원 / 재계산)
     * @return array 포인트 표기가 채워진 스냅샷
     */
    protected function withEarnedPointsFormatted(array $snapshot): array
    {
        if (array_key_exists('total_earned_points_amount', $snapshot)
            && ! array_key_exists('total_earned_points_amount_formatted', $snapshot)) {
            $snapshot['total_earned_points_amount_formatted'] = $this->formatEarnedPoints(
                (float) $snapshot['total_earned_points_amount']
            );
        }

        return $snapshot;
    }

    /**
     * 신청 상품 행의 옵션 차액을 부호별로 합산한 보조통화 맵을 만듭니다. (§5-4 다통화 규약)
     *
     * 헤더의 `additional_product_amount`(더 낼 돈) · `refund_product_amount`(돌려받을 돈)는 상품별
     * 차액을 부호로 갈라 더한 값이다. 보조통화도 **같은 방식으로 서버가 합산**한다 — 화면이 통화별
     * 금액을 직접 더하면 그 규칙이 화면마다 갈리고, 환율을 다시 보게 되면 예고와 청구의 근거가 갈린다.
     *
     * 재료는 접수 시 박제된 `order_exchange_options.mc_price_difference` 다. 관계가 로드되지 않은
     * 응답(목록의 일부 경로)에서는 근거가 없으므로 null 을 돌려주고, 화면은 그 줄을 세우지 않는다.
     *
     * @param  OrderExchange  $claim  교환 신청서
     * @param  bool  $positive  true 면 추가 청구분(양수), false 면 환불분(음수의 절대값)
     * @return array<string, float>|null 통화 코드 => 금액 (근거가 없으면 null)
     *                                   — 호출층이 `formatStoredMultiCurrency()` 로 감싸 `.formatted` 를 입힌다(S3-D14).
     */
    protected function sumSignedItemPriceDifferences(OrderExchange $claim, bool $positive): ?array
    {
        if (! $claim->relationLoaded('exchangeOptions')) {
            return null;
        }

        $maps = [];

        foreach ($claim->exchangeOptions as $option) {
            $difference = (float) ($option->price_difference ?? 0);

            if ($difference === 0.0 || ($difference > 0) !== $positive) {
                continue;
            }

            $map = $option->mc_price_difference;

            if (! is_array($map) || $map === []) {
                continue;
            }

            // 환불분은 화면이 `−` 를 앞에 붙여 그리므로 기준 통화 줄과 같이 절대값으로 낸다.
            $maps[] = $positive ? $map : array_map(static fn ($v) => abs((float) $v), $map);
        }

        return $maps === [] ? null : $this->sumMultiCurrency(...$maps);
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

    /**
     * 사용 마일리지를 차감하지 않은 주문이라 마일리지 환불 몫이 0 인지 판정합니다. (Q15)
     *
     * 실행(`OrderRefundRequestService` 의 Q15 보정)과 계산 원천(`OrderAdjustmentService`)과 같은 축 — `is_mileage_deducted` —
     * 이다. 사용 마일리지가 없는 주문은 해당 없음이다.
     *
     * @param  OrderRefund  $claim  환불신청서
     * @return bool 미차감 주문이라 마일리지 몫이 0 이면 true
     */
    protected function pointsNotDeducted(OrderRefund $claim): bool
    {
        // 목록은 주문을 싣지 않으면 판정하지 않는다(행마다 주문을 읽는 N+1 금지) — 상세는 주문을 읽는다.
        $order = ($claim->relationLoaded('order') || ! $this->forList) ? $claim->order : null;

        return self::orderPointsNotDeducted($order);
    }

    /**
     * 주문이 사용 마일리지를 차감하지 않아 그 주문의 환불 마일리지 몫이 0 인지 판정합니다. (Q15)
     *
     * 상세(이 리소스)·관리자 목록(`ClaimAdminListResource`)·연결 카드(`ClaimLinkResource`)가 같은 술어를 써야 한
     * 신청서의 환불 합계가 화면마다 달라지지 않는다.
     *
     * @param  Order|null  $order  주문 (없으면 판정하지 않음)
     * @return bool 미차감 주문이라 마일리지 몫이 0 이면 true
     */
    public static function orderPointsNotDeducted(?Order $order): bool
    {
        return $order !== null
            && (float) $order->total_points_used_amount > 0
            && ! $order->is_mileage_deducted;
    }

    /**
     * 이 환불을 실행하려면 보낼 계좌가 있어야 하는지 답합니다. (T2-D6)
     *
     * 실행 게이트(`ExecuteRefundRequest::validateRefundBank()`)와 **같은 판정**이다 — 계좌로 나갈
     * 현금이 있는가(마일리지로만 돌려주는 환불은 회원 원장으로 들어가 보낼 계좌가 없다)와, 그 현금이
     * 계좌로 나가는가(환불 수단 `BANK` — 카드 주문의 계좌 수동 환불 포함 — 또는 계좌로 환불되는
     * 주문)를 본다. 이미 적혀 있으면 요구하지 않는다 — 운영자가 다시 적을 일이 없다.
     *
     * @param  OrderRefund  $claim  환불신청서
     * @return bool 계좌가 있어야 실행되는지 여부
     */
    protected function refundAccountRequired(OrderRefund $claim): bool
    {
        // 판정은 실행 게이트와 같은 한 곳(`ClaimRefundAccountPolicy::requiredForRefund()`)이다 —
        // 카드 주문의 계좌 수동 환불(`BANK`)도 계좌를 요구한다.
        return app(ClaimRefundAccountPolicy::class)->requiredForRefund($claim);
    }

    /**
     * 환불신청서에 잡힌 교환 차액 환급 합계를 구합니다. (S2 — 카드·마일리지 합)
     *
     * @param  OrderRefund  $claim  환불신청서
     * @return float 교환 차액 환급 합계 (없으면 0)
     */
    protected function exchangeDifferenceTotal(OrderRefund $claim): float
    {
        $total = 0.0;
        foreach ($claim->exchange_difference_refunds ?? [] as $entry) {
            $total += (float) ($entry['amount'] ?? 0) + (float) ($entry['points_amount'] ?? 0);
        }

        return $total;
    }

    /**
     * 교환 차액 환급을 합계와 수단으로 표기합니다. (S2 — 「5,000원 (신용카드)」·「5,000원 (신용카드 3,000원 · 마일리지 2,000원)」)
     *
     * 카드 등 PG 로 낸 몫은 그 클레임 결제 건의 결제수단 라벨로, 마일리지로 낸 몫은 「마일리지」로 적는다.
     * 수단이 하나면 라벨만, 둘 이상이면 수단별 금액을 함께 적는다.
     *
     * @param  OrderRefund  $claim  환불신청서
     * @param  callable(float): string  $format  금액 포맷터 (주문 통화)
     * @return string|null 표기 (환급할 차액이 없으면 null)
     */
    protected function exchangeDifferenceFormatted(OrderRefund $claim, callable $format): ?string
    {
        $total = $this->exchangeDifferenceTotal($claim);
        if ($total <= 0.0) {
            return null;
        }

        $byLabel = [];
        $resolver = app(PaymentMethodResolver::class);
        $claimPayments = app(ClaimPaymentService::class);
        foreach ($claim->exchange_difference_refunds ?? [] as $entry) {
            $cash = (float) ($entry['amount'] ?? 0);
            if ($cash > 0.0) {
                $method = (string) ($claimPayments->findById((int) ($entry['claim_payment_id'] ?? 0))?->payment_method ?? '');
                $label = $method !== '' ? $resolver->label($method) : RefundMethodEnum::PG->label();
                $byLabel[$label] = ($byLabel[$label] ?? 0.0) + $cash;
            }

            $points = (float) ($entry['points_amount'] ?? 0);
            if ($points > 0.0) {
                $label = __('sirsoft-ecommerce::messages.claims.exchange_difference.points');
                $byLabel[$label] = ($byLabel[$label] ?? 0.0) + $points;
            }
        }

        $methods = count($byLabel) === 1
            ? (string) array_key_first($byLabel)
            : implode(' · ', array_map(
                fn (string $label, float $amount): string => __('sirsoft-ecommerce::messages.claims.exchange_difference.part', ['method' => $label, 'amount' => $format($amount)]),
                array_keys($byLabel),
                array_values($byLabel),
            ));

        return __('sirsoft-ecommerce::messages.claims.exchange_difference.format', ['total' => $format($total), 'methods' => $methods]);
    }

    /**
     * 「마일리지로 환불」로 완료돼 회원 마일리지로 지급된 현금 환불액을 돌려줍니다. (D1)
     *
     * 완료 전에는 0 이다 — 「지급되었습니다」 안내가 실행 전 신청서에 붙으면 고객은 돈이 들어온 줄 안다.
     *
     * @param  OrderRefund  $claim  환불신청서
     * @return float 마일리지로 지급한 현금 환불액 (기준 통화)
     */
    protected function mileageCreditAmount(OrderRefund $claim): float
    {
        if ($claim->refund_method !== RefundMethodEnum::POINTS || $claim->refund_status !== RefundStatusEnum::COMPLETED) {
            return 0.0;
        }

        return (float) $claim->refund_amount;
    }

    /**
     * 이 환불이 **PG 플러그인 부재로** 계좌 환불로 떨어진 건인지 판정합니다. (P14)
     *
     * 결제가 PG 결제창을 쓰는 수단인데 환불수단이 계좌라면, 그것은 운영자가 고른 것이 아니라
     * 코어가 폴백시킨 것이다 — `payment.refund` 훅에 응답할 리스너가 하나도 없다는 뜻이다.
     *
     * 처음부터 계좌 환불인 수단(무통장)은 여기 걸리지 않는다. 그 경우 `needsPgProvider()` 가
     * false 다.
     *
     * @param  OrderRefund  $claim  환불신청서
     * @return bool 폴백으로 계좌 환불이 된 건이면 true
     */
    protected function refundFellBackToManual(OrderRefund $claim): bool
    {
        if ($claim->refund_method !== RefundMethodEnum::BANK) {
            return false;
        }

        $payment = $claim->order?->payment;

        if (! $payment || ! $payment->needsPgProvider()) {
            return false;
        }

        return ! app(RefundExecutionService::class)->hasPgRefundListener($claim->order);
    }
}
