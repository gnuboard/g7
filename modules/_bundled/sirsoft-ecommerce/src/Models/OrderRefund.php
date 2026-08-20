<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
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
        'reason_code',
        'reason_detail',
        'fault_type',
        'requested_by',
        'refund_amount',
        'refund_points_amount',
        'refund_shipping_amount',
        'mc_refund_amount',
        'mc_refund_points_amount',
        'mc_refund_shipping_amount',
        'original_calculation_snapshot',
        'recalculated_snapshot',
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
            'additional_payment_amount' => 'decimal:2',
            'is_additional_payment_completed' => 'boolean',
            'additional_paid_at' => 'datetime',
            'refunded_at' => 'datetime',
            'approved_at' => 'datetime',
            'on_hold_at' => 'datetime',
            'withdrawn_at' => 'datetime',
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
        return $this->requested_by === null;
    }

    /**
     * 환불 사유 라벨을 반환합니다.
     *
     * @return string 현지화된 사유 라벨
     */
    public function getReasonLabel(): string
    {
        $reason = ClaimReason::where('type', ClaimTypeEnum::REFUND->value)
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
