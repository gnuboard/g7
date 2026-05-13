<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
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
        'refund_number',
        'refund_status',
        'refund_method',
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
     * @return BelongsTo
     */
    public function orderCancel(): BelongsTo
    {
        return $this->belongsTo(OrderCancel::class, 'order_cancel_id');
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
