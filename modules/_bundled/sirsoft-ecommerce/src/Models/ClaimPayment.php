<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Sirsoft\Ecommerce\Database\Factories\ClaimPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;

/**
 * 클레임 추가결제 모델
 *
 * 교환/반품의 클레임 배송비·차액을 별도 결제 건으로 수납한다.
 * payment_number 는 PG merchant_order_id 로도 쓰이며, transaction_id 와 함께
 * 재시도 멱등의 축이다(두 컬럼 모두 UNIQUE).
 */
class ClaimPayment extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return ClaimPaymentFactory::new();
    }

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'ecommerce_claim_payments';

    /**
     * 대량 할당 가능 필드
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'payable_type',
        'payable_id',
        'order_id',
        'payment_number',
        'payment_method',
        'pg_provider',
        'payment_status',
        'amount',
        'points_amount',
        'currency_code',
        'mc_amount',
        'transaction_id',
        'pg_error_code',
        'pg_error_message',
        'raw_response',
        'paid_at',
    ];

    /**
     * 타입 캐스팅
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payable_id' => 'integer',
            'payment_status' => ClaimPaymentStatusEnum::class,
            'amount' => 'decimal:2',
            'points_amount' => 'decimal:2',
            'mc_amount' => 'array',
            'raw_response' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * 결제 대상(교환/반품 신청서)과의 다형 관계
     *
     * @return MorphTo 결제 대상 관계
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * 주문과의 관계
     *
     * @return BelongsTo 소속 주문 관계
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * 마일리지 거래 내역과의 관계
     *
     * @return HasMany 마일리지 거래 관계
     */
    public function mileageTransactions(): HasMany
    {
        return $this->hasMany(MileageTransaction::class, 'claim_payment_id');
    }

    /**
     * 결제 대상 클레임 유형을 반환합니다.
     *
     * @return ClaimTypeEnum|null 클레임 유형 (해석 불가하면 null)
     */
    public function claimType(): ?ClaimTypeEnum
    {
        return ClaimTypeEnum::tryFrom((string) $this->payable_type);
    }

    /**
     * 총 결제 금액(PG + 마일리지)을 반환합니다.
     *
     * @return float 총 결제 금액
     */
    public function getTotalAmount(): float
    {
        return (float) $this->amount + (float) $this->points_amount;
    }

    /**
     * 수납 완료 여부를 반환합니다.
     *
     * @return bool 수납 완료이면 true
     */
    public function isPaid(): bool
    {
        return $this->payment_status->isSettled();
    }

    /**
     * 환급 대상 여부를 반환합니다.
     *
     * 철회·반려 시 이미 수납된 건만 환급 대상이 된다.
     *
     * @return bool 환급 대상이면 true
     */
    public function isRefundable(): bool
    {
        return $this->payment_status->isRefundable();
    }

    /**
     * 마일리지로 결제된 건인지 반환합니다.
     *
     * @return bool 마일리지 결제분이 있으면 true
     */
    public function usesPoints(): bool
    {
        return (float) $this->points_amount > 0;
    }

    /**
     * 특정 결제 상태만 조회하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  string|ClaimPaymentStatusEnum  $status  결제 상태
     * @return Builder 필터가 적용된 쿼리 빌더
     */
    public function scopeOfStatus($query, string|ClaimPaymentStatusEnum $status)
    {
        $value = $status instanceof ClaimPaymentStatusEnum ? $status->value : $status;

        return $query->where('payment_status', $value);
    }
}
