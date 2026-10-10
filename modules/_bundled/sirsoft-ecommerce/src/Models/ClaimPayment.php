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
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;

/**
 * 클레임 추가결제 모델
 *
 * 교환/반품 신청서의 청구액(클레임 배송비·옵션 차액)을 원 주문과 분리해 수납한 기록이다.
 * `payment_number`(CP 채번)가 PG merchant_order_id 이며, `transaction_id` UNIQUE 가 PG 재시도
 * 멱등의 축이다. 부분 환급(재발송지 수정으로 청구액이 줄어든 차액)은 상태가 아니라
 * `refunded_amount` 누적액으로 기록하고, 전액 환급(철회·반려)만 `payment_status=refunded` 로 닫는다.
 */
class ClaimPayment extends Model
{
    use HasFactory;

    /**
     * 결제번호(CP 채번) 접두사
     *
     * `SequenceType::CLAIM_PAYMENT` 의 prefix 와 같은 값이다. 결제 플러그인 콜백이
     * 주문번호 자리에 실려 온 값이 클레임 결제 건인지 판별하는 계약 접두사이므로,
     * 채번 쪽을 바꾸면 이 상수와 판별 헬퍼(`isClaimPaymentNumber`)도 함께 바꿔야 한다.
     *
     * @var string
     */
    public const NUMBER_PREFIX = 'CP';

    protected static function newFactory()
    {
        return ClaimPaymentFactory::new();
    }

    /**
     * 주문번호 자리에 실린 값이 클레임 결제번호(CP 채번)인지 판별합니다.
     *
     * 결제 플러그인의 PG 콜백은 merchant_order_id 하나로 주문과 클레임 결제를 모두 받으므로,
     * 이 판별이 참이면 `Order` 가 아니라 `ClaimPayment` 로 되찾아야 한다. 주문번호는 숫자로
     * 시작하는 채번(TIMESTAMP 계열)이라 접두사가 겹치지 않는다.
     *
     * @param  string  $number  PG 콜백의 merchant_order_id 값
     * @return bool 클레임 결제번호이면 true
     */
    public static function isClaimPaymentNumber(string $number): bool
    {
        return str_starts_with($number, self::NUMBER_PREFIX);
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
        'refunded_amount',
        'refunded_at',
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
            'refunded_amount' => 'decimal:2',
            'mc_amount' => 'array',
            'raw_response' => 'array',
            'paid_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    /**
     * 결제 대상 신청서 (교환/반품)
     *
     * @return MorphTo 다형 관계
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * 원 주문
     *
     * @return BelongsTo 주문 관계
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * 이 결제에 묶인 마일리지 거래 (사용·환급)
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
     * @return ClaimTypeEnum|null 클레임 유형
     */
    public function claimType(): ?ClaimTypeEnum
    {
        return ClaimTypeEnum::tryFrom((string) $this->payable_type);
    }

    /**
     * 청구 총액(PG + 마일리지)을 반환합니다.
     *
     * @return float 청구 총액
     */
    public function getTotalAmount(): float
    {
        return (float) $this->amount + (float) $this->points_amount;
    }

    /**
     * 실수납 잔액(청구 총액 − 부분 환급 누적액)을 반환합니다.
     *
     * 결제 전·환급 완료 건은 0 이다 — 주문 상세의 "클레임 추가결제 합계" 와 재발송지 수정 차액
     * 정산이 같은 값을 읽는다.
     *
     * @return float 실수납 잔액
     */
    public function netPaidAmount(): float
    {
        if (! $this->isPaid()) {
            return 0.0;
        }

        return max(0.0, $this->getTotalAmount() - (float) $this->refunded_amount);
    }

    /**
     * 결제 완료 여부를 반환합니다.
     *
     * @return bool 결제 완료이면 true
     */
    public function isPaid(): bool
    {
        return $this->payment_status->isSettled();
    }

    /**
     * 환급 가능 여부를 반환합니다.
     *
     * @return bool 환급할 수 있으면 true
     */
    public function isRefundable(): bool
    {
        return $this->payment_status->isRefundable() && $this->netPaidAmount() > 0;
    }

    /**
     * 결제 대기 여부를 반환합니다.
     *
     * @return bool 결제 대기이면 true
     */
    public function isPending(): bool
    {
        return $this->payment_status === ClaimPaymentStatusEnum::PENDING;
    }

    /**
     * 마일리지 결제 여부를 반환합니다.
     *
     * @return bool 마일리지 결제액이 있으면 true
     */
    public function usesPoints(): bool
    {
        return (float) $this->points_amount > 0;
    }

    /**
     * 마일리지 단독 결제 건인지 반환합니다.
     *
     * @return bool 결제 수단이 마일리지이면 true
     */
    public function isPointPayment(): bool
    {
        return $this->payment_method === PaymentMethodEnum::POINT->value;
    }

    /**
     * 결제 상태로 조회 범위를 좁힙니다.
     *
     * @param  Builder  $query  쿼리
     * @param  string|ClaimPaymentStatusEnum  $status  결제 상태
     * @return Builder 쿼리
     */
    public function scopeOfStatus($query, string|ClaimPaymentStatusEnum $status)
    {
        $value = $status instanceof ClaimPaymentStatusEnum ? $status->value : $status;

        return $query->where('payment_status', $value);
    }
}
