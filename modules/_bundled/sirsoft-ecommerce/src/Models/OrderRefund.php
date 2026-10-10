<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;

/**
 * 주문 환불 모델
 */
class OrderRefund extends Model
{
    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'ecommerce_order_refunds';

    /**
     * 대량 할당 가능 필드
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_id',
        'order_cancel_id',
        'refund_source_type',
        'refund_source_id',
        'refund_number',
        'refund_status',
        'refund_method',
        'refund_method_overridden',
        'reason_code',
        'reason_detail',
        'fault_type',
        'requested_by',
        'refund_amount',
        'refund_points_amount',
        'refund_shipping_amount',
        'exchange_difference_refunds',
        'mc_refund_amount',
        'mc_refund_points_amount',
        'mc_refund_shipping_amount',
        'original_calculation_snapshot',
        'recalculated_snapshot',
        'refund_priority',
        'additional_payment_amount',
        'additional_payment_method',
        'is_additional_payment_completed',
        'additional_paid_at',
        'refund_bank_holder',
        'refund_bank_code',
        'refund_bank_account',
        'pg_transaction_id',
        'pg_error_code',
        'pg_error_message',
        'refunded_at',
        'approved_at',
        'on_hold_at',
        'withdrawn_at',
        'withdrawn_by_admin',
        'withdraw_reason',
        'rejected_at',
        'reject_reason',
        'processed_by',
    ];

    /**
     * 타입 캐스팅
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'refund_status' => RefundStatusEnum::class,
            'refund_method' => RefundMethodEnum::class,
            'refund_method_overridden' => 'boolean',
            'exchange_difference_refunds' => 'array',
            'refund_source_type' => RefundSourceTypeEnum::class,
            'refund_source_id' => 'integer',
            'fault_type' => ClaimReasonFaultTypeEnum::class,
            'refund_amount' => 'decimal:2',
            'refund_points_amount' => 'decimal:2',
            'refund_shipping_amount' => 'decimal:2',
            'mc_refund_amount' => 'array',
            'mc_refund_points_amount' => 'array',
            'mc_refund_shipping_amount' => 'array',
            'original_calculation_snapshot' => 'array',
            'recalculated_snapshot' => 'array',
            'refund_priority' => RefundPriorityEnum::class,
            'additional_payment_amount' => 'decimal:2',
            'is_additional_payment_completed' => 'boolean',
            'additional_paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'approved_at' => 'datetime',
            'on_hold_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /**
     * 주문과의 관계
     *
     * @return BelongsTo
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * 취소 레코드와의 관계
     *
     * @deprecated refund_source_type/refund_source_id 로 대체됨. 취소 발 환불에는
     *             하위호환을 위해 두 축이 함께 기록되므로 기존 호출부는 그대로 동작한다.
     *
     * @return BelongsTo
     */
    public function orderCancel(): BelongsTo
    {
        return $this->belongsTo(OrderCancel::class, 'order_cancel_id');
    }

    /**
     * 환불 발생 출처 레코드(취소/반품/교환)를 해석합니다.
     *
     * Eloquent 다형 관계(morphTo)를 쓰지 않는다 — `direct`(관리자 직접 환불)에는
     * 대응 모델이 없어 morphTo 가 그 값을 클래스명으로 해석하다 fatal 이 난다.
     *
     * @return Model|null 출처 레코드 (없으면 null)
     */
    public function resolveRefundSource(): ?Model
    {
        $type = $this->refundSourceType();
        $modelClass = $type?->sourceModelClass();

        if ($modelClass === null || $this->refund_source_id === null) {
            return null;
        }

        return $modelClass::find($this->refund_source_id);
    }

    /**
     * 환불 발생 출처 유형을 반환합니다.
     *
     * @return RefundSourceTypeEnum|null 출처 유형 (미기록이면 null)
     */
    public function refundSourceType(): ?RefundSourceTypeEnum
    {
        return $this->refund_source_type;
    }

    /**
     * 첨부 이미지 목록과의 관계
     *
     * @return MorphMany 첨부 이미지 관계
     */
    public function images(): MorphMany
    {
        return $this->morphMany(ClaimImage::class, 'claimable')->orderBy('sort_order');
    }

    /**
     * 클레임 유형을 반환합니다.
     *
     * @return ClaimTypeEnum 클레임 유형
     */
    public function claimType(): ClaimTypeEnum
    {
        return ClaimTypeEnum::REFUND;
    }

    /**
     * 신청자와의 관계
     *
     * @return BelongsTo 신청 사용자 관계
     */
    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * 신청자가 철회할 수 있는 상태인지 반환합니다.
     *
     * @return bool 철회 가능하면 true
     */
    public function isWithdrawable(): bool
    {
        return $this->refund_status->isWithdrawable();
    }

    /**
     * 비회원 신청 건인지 반환합니다.
     *
     * @return bool 비회원 신청이면 true
     */
    public function isGuestRequest(): bool
    {
        // 비회원 판정은 **주문의 주인**이 정한다. `requested_by` 는 비회원 신청과 운영자 대리
        // 접수가 똑같이 null 이라(§4-3-1), 그 값으로 가르면 **회원 주문의 운영자 접수 건이
        // 「비회원」으로 표시된다** — 운영자는 연락 수단을 잘못 고르게 된다.
        //
        // 주문을 읽을 수 없는 예외적인 경우에만 종전 판정으로 떨어진다.
        $order = $this->relationLoaded('order') ? $this->order : $this->order()->first();

        if ($order === null) {
            return $this->requested_by === null;
        }

        return $order->user_id === null;
    }

    /**
     * 이 신청서의 사유 코드가 등록된 카탈로그 유형을 반환합니다. (사유 라벨 해석의 단일 출처)
     *
     * 교환·반품에서 파생된 환불신청서는 **부모의 사유 코드를 그대로 물려받는다**. 사유 카탈로그는
     * 유형별로 나뉘어 있어서, 교환에만 있는 코드(`defective_product` 등)를 환불 카탈로그에서 찾으면
     * 매칭에 실패하고 폴백이 원시 코드를 그대로 화면에 내보낸다(S17 트랙 A F-2). 코드가 실제로
     * 등록된 곳은 **파생 출처의 유형**이므로 그쪽을 먼저 본다.
     *
     * 취소 발(`cancel`)과 직접 환불(`direct`)은 환불 카탈로그가 정본이다 — 취소 사유는
     * `OrderCancel::getRefundReasonLabel()` 과 같은 축을 쓴다.
     *
     * @return string 카탈로그 유형 값
     */
    public function reasonCatalogType(): string
    {
        return match ($this->refund_source_type) {
            RefundSourceTypeEnum::EXCHANGE => ClaimTypeEnum::EXCHANGE->value,
            RefundSourceTypeEnum::RETURN => ClaimTypeEnum::RETURN->value,
            default => ClaimTypeEnum::REFUND->value,
        };
    }

    /**
     * 환불 사유 라벨을 반환합니다.
     *
     * @return string 현지화된 사유 라벨
     */
    public function getReasonLabel(): string
    {
        $reason = ClaimReason::where('type', $this->reasonCatalogType())
            ->where('code', $this->reason_code)->first();

        return $reason ? $reason->getLocalizedName() : ($this->reason_code ?? '');
    }

    /**
     * 환불 옵션 목록과의 관계
     *
     * @return HasMany
     */
    public function refundOptions(): HasMany
    {
        return $this->hasMany(OrderRefundOption::class, 'order_refund_id');
    }

    /**
     * 운영자가 **받아 주기로 한** 상품 행만 반환합니다.
     *
     * 돈이 덮는 범위(`refundOptionsForAmount()`)보다 좁다 — 미판단분을 빼기 때문이다. 그래서
     * 이 술어는 "얼마가 나갈 수 있는가" 가 아니라 **"운영자가 무엇을 받아 줬는가"** 를 묻는
     * 자리에서만 쓴다(부분 처리 안내·집계).
     *
     * `PROCESSING`·`COMPLETED` 를 포함하는 이유: 운영자가 여러 라운드로 나눠 처리하면 앞
     * 라운드가 이미 그 상태로 가 있다. 빼면 다음 라운드의 계산에서 앞 라운드 몫이 사라진다.
     *
     * @return Collection<int, OrderRefundOption> 승인 계열 상품 행
     */
    public function approvedRefundOptions(): Collection
    {
        $included = [
            RefundStatusEnum::APPROVED->value,
            RefundStatusEnum::PROCESSING->value,
            RefundStatusEnum::COMPLETED->value,
        ];

        return $this->refundOptions
            ->filter(fn (OrderRefundOption $option): bool => in_array(
                $this->optionStatusValue($option),
                $included,
                true
            ))
            ->values();
    }

    /**
     * 금액 산정·환불 실행이 대상으로 삼을 상품 행을 반환합니다. (= 헤더 금액이 덮는 행)
     *
     * **빠지는 것은 반려·철회분뿐이다.** 그 둘은 "이 상품에는 돈이 나가지 않는다" 는 확정이고,
     * 계속 세면 취소 가능 금액(D19)이 그만큼 넓어져 **과다 환불이 가드를 통과해** 집행된다.
     *
     * 아직 판단이 없는 행(`requested`·`on_hold`)은 **그대로 센다.** 승인분만 세면 세 가지가
     * 동시에 어긋난다:
     *
     *  1. 상한이 승인분으로 좁혀진 순간, 헤더에 남아 있는 미판단분이 상한을 넘겨 **저장·실행이
     *     422 로 막힌다** — 운영자는 아무 금액도 건드리지 않았는데 막힌다.
     *  2. 미판단 행의 금액을 0 으로 내리면, 나중에 그 행을 승인할 때 **되살릴 근거가 없다**.
     *     비율 안분의 가중치가 이미 0 이기 때문이다.
     *  3. 헤더가 판단 순서에 따라 줄었다 늘었다 해서, 활동로그의 전/후가 실제 결정과 무관한
     *     숫자를 말한다.
     *
     * 그래서 이 술어는 **단조**다 — 반려가 쌓이는 만큼만 좁아진다.
     *
     * @return Collection<int, OrderRefundOption> 금액 대상 상품 행
     */
    public function refundOptionsForAmount(): Collection
    {
        $excluded = [
            RefundStatusEnum::REJECTED->value,
            RefundStatusEnum::WITHDRAWN->value,
        ];

        return $this->refundOptions
            ->reject(fn (OrderRefundOption $option): bool => in_array(
                $this->optionStatusValue($option),
                $excluded,
                true
            ))
            ->values();
    }

    /**
     * 반려·철회로 환불 대상에서 빠진 상품 행을 반환합니다.
     *
     * `refundOptionsForAmount()` 의 여집합이다. 재계산이 이 행들의 환불액을 0 으로 내린다 —
     * 남겨 두면 헤더 합계는 맞는데 **반려 행이 돈을 들고 있고**, D19 의 선점액 집계가 그것을
     * 계속 센다(오류도 로그도 없다).
     *
     * @return Collection<int, OrderRefundOption> 환불 대상에서 빠진 상품 행
     */
    public function rejectedRefundOptions(): Collection
    {
        $excluded = [
            RefundStatusEnum::REJECTED->value,
            RefundStatusEnum::WITHDRAWN->value,
        ];

        return $this->refundOptions
            ->filter(fn (OrderRefundOption $option): bool => in_array(
                $this->optionStatusValue($option),
                $excluded,
                true
            ))
            ->values();
    }

    /**
     * 상품 행 상태의 문자열 값을 반환합니다 (캐스트 여부와 무관하게).
     *
     * @param  OrderRefundOption  $option  상품 행
     * @return string 상태 값
     */
    private function optionStatusValue(OrderRefundOption $option): string
    {
        return $option->option_status instanceof RefundStatusEnum
            ? $option->option_status->value
            : (string) $option->option_status;
    }

    /**
     * 처리 관리자와의 관계
     *
     * @return BelongsTo
     */
    public function processedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * 환불 완료 여부를 반환합니다.
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->refund_status === RefundStatusEnum::COMPLETED;
    }

    /**
     * 환불 반려 여부를 반환합니다.
     *
     * @return bool
     */
    public function isRejected(): bool
    {
        return $this->refund_status === RefundStatusEnum::REJECTED;
    }

    /**
     * 최종 상태 여부를 반환합니다.
     *
     * @return bool
     */
    public function isFinal(): bool
    {
        return $this->refund_status->isFinal();
    }

    /**
     * 추가결제가 필요한지 여부를 반환합니다.
     *
     * @return bool
     */
    public function requiresAdditionalPayment(): bool
    {
        return $this->additional_payment_amount > 0 && ! $this->is_additional_payment_completed;
    }
}
