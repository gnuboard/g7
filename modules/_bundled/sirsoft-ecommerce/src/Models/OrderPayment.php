<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Services\PaymentMethodResolver;

/**
 * 주문 결제 모델
 */
class OrderPayment extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return OrderPaymentFactory::new();
    }

    protected $table = 'ecommerce_order_payments';

    protected $fillable = [
        'order_id',
        'payment_status',
        'pg_provider',
        'embedded_pg_provider',
        'transaction_id',
        'merchant_order_id',
        'payment_method',
        'payment_device',
        'paid_amount_local',
        'paid_amount_base',
        'vat_amount',
        'currency',
        'currency_snapshot',
        'card_name',
        'card_number_masked',
        'card_approval_number',
        'card_installment_months',
        'is_interest_free',
        'vbank_code',
        'vbank_name',
        'vbank_number',
        'vbank_holder',
        'vbank_due_at',
        'vbank_issued_at',
        'dbank_code',
        'dbank_name',
        'dbank_account',
        'dbank_holder',
        'depositor_name',
        'deposit_due_at',
        'is_escrow',
        'buyer_name',
        'buyer_email',
        'buyer_phone',
        'is_cash_receipt_requested',
        'is_cash_receipt_issued',
        'cash_receipt_type',
        'cash_receipt_identifier',
        'cash_receipt_issued_at',
        'cancelled_amount',
        'cancelled_vat_amount',
        'cancel_reason',
        'cancel_history',
        'refund_bank_code',
        'refund_bank_name',
        'refund_bank_account',
        'refund_bank_holder',
        'receipt_url',
        'payment_name',
        'user_agent',
        'payment_meta',
        'payment_started_at',
        'paid_at',
        'cancelled_at',
        // 다중 통화 컬럼 (JSON)
        'mc_paid_amount',
        'mc_cancelled_amount',
    ];

    protected $casts = [
        'paid_amount_local' => 'decimal:2',
        'paid_amount_base' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'currency_snapshot' => 'array',
        'card_installment_months' => 'integer',
        'is_interest_free' => 'boolean',
        'vbank_due_at' => 'datetime',
        'vbank_issued_at' => 'datetime',
        'deposit_due_at' => 'datetime',
        'is_escrow' => 'boolean',
        'is_cash_receipt_requested' => 'boolean',
        'is_cash_receipt_issued' => 'boolean',
        'cash_receipt_issued_at' => 'datetime',
        'cancelled_amount' => 'decimal:2',
        'cancelled_vat_amount' => 'decimal:2',
        'cancel_history' => 'array',
        'payment_meta' => 'array',
        'payment_started_at' => 'datetime',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'payment_status' => PaymentStatusEnum::class,
        // payment_method 는 의도적으로 캐스트하지 않는다 (순수 string) — 아래 주석 참조.
        // 다중 통화 컬럼 (JSON)
        'mc_paid_amount' => 'array',
        'mc_cancelled_amount' => 'array',
    ];

    /*
     * payment_method 에 enum 캐스트를 두지 않는 이유
     *
     * PG 플러그인이 등록하는 확장 결제수단(예: 'nhnkcp_naverpay')은 PaymentMethodEnum 에
     * case 가 없다. Laravel 의 enum 캐스트는 내부적으로 tryFrom() 이 아니라 from() 을 쓰므로
     * (HasAttributes::getEnumCaseFromValue), 확장 ID 를 저장하는 순간 ValueError → 500 이 된다.
     *
     * 능력 질의(PG 필요 여부 / 라벨 / 환불수단)는 아래 능력 메서드가 PaymentMethodResolver 에
     * 위임해 답한다 — 소비처는 payment_method 를 직접 비교하지 않는다.
     *
     * 하위호환: BackedEnum 은 JsonSerializable 이라 API 응답에는 이미 값 문자열('card')이
     * 나가고 있었다. 순수 string 으로 바꿔도 wire format 이 완전히 동일하다.
     *
     * @see https://github.com/gnuboard/dev-g7/issues/475
     */

    /**
     * 주문 관계
     *
     * @return BelongsTo 주문 모델과의 관계
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * 세금계산서 관계
     *
     * @return HasMany 세금계산서 모델과의 관계
     */
    public function taxInvoices(): HasMany
    {
        return $this->hasMany(OrderTaxInvoice::class, 'payment_id');
    }

    /**
     * 카드 결제 여부 확인
     *
     * @return bool 카드 결제 여부
     */
    public function isCardPayment(): bool
    {
        return $this->paymentMethodId() === PaymentMethodEnum::CARD->value;
    }

    /**
     * 가상계좌 결제 여부 확인
     *
     * @return bool 가상계좌 결제 여부
     */
    public function isVirtualAccount(): bool
    {
        return $this->paymentMethodId() === PaymentMethodEnum::VBANK->value;
    }

    /**
     * 무통장입금(수동 입금확인) 결제 여부 확인
     *
     * @return bool 무통장입금 여부
     */
    public function isBankTransfer(): bool
    {
        return $this->paymentMethodId() === PaymentMethodEnum::DBANK->value;
    }

    /**
     * PG 결제창이 필요한 결제수단인지 확인합니다.
     *
     * 확장 결제수단(간편결제 등)도 카탈로그 선언을 통해 올바르게 판정된다.
     *
     * @return bool PG 결제창 필요 여부
     */
    public function needsPgProvider(): bool
    {
        return $this->resolver()->needsPgProvider($this->paymentMethodId());
    }

    /**
     * 결제수단의 다국어 표시명을 반환합니다.
     *
     * @return string 표시명
     */
    public function paymentMethodLabel(): string
    {
        return $this->resolver()->label($this->paymentMethodId());
    }

    /**
     * 결제수단에 대응하는 환불 수단을 반환합니다.
     *
     * @return RefundMethodEnum 환불 수단
     */
    public function refundMethod(): RefundMethodEnum
    {
        return $this->resolver()->refundMethod($this->paymentMethodId());
    }

    /**
     * 결제수단 ID 를 문자열로 반환합니다.
     *
     * `payment_method` 는 순수 string 이지만, 과거 enum 캐스트 시절의 값이나 외부에서
     * enum 을 대입한 경우를 방어적으로 흡수한다.
     *
     * @return string 결제수단 ID (미설정 시 빈 문자열)
     */
    public function paymentMethodId(): string
    {
        $method = $this->payment_method;

        if ($method instanceof \BackedEnum) {
            return (string) $method->value;
        }

        return (string) ($method ?? '');
    }

    /**
     * 결제수단 능력 해석기를 반환합니다.
     *
     * @return PaymentMethodResolver 결제수단 능력 해석기
     */
    private function resolver(): PaymentMethodResolver
    {
        return app(PaymentMethodResolver::class);
    }

    /**
     * 결제 완료 여부 확인
     *
     * @return bool 결제 완료 여부
     */
    public function isPaid(): bool
    {
        return $this->payment_status === PaymentStatusEnum::PAID;
    }

    /**
     * 입금 대기 여부 확인
     *
     * @return bool 입금 대기 여부
     */
    public function isWaitingDeposit(): bool
    {
        return $this->payment_status === PaymentStatusEnum::WAITING_DEPOSIT;
    }

    /**
     * 취소 가능 금액 계산 (결제 통화 order_currency 기준).
     *
     * paid_amount_local 은 결제 통화 실청구액이므로 누적 취소액도 결제 통화로 맞춰야 한다.
     * 코어가 결제 통화로 누적한 mc_cancelled_amount[order_currency] 를 우선 사용하고,
     * 없으면(레거시 결제) base 누적 cancelled_amount 로 폴백한다.
     *
     * @return float 취소 가능 금액 (결제 통화)
     */
    public function getCancellableAmount(): float
    {
        return $this->paid_amount_local - $this->cancelledLocalAmount();
    }

    /**
     * 결제 통화(order_currency) 기준 누적 취소액을 반환합니다.
     *
     * @return float 결제 통화 기준 누적 취소액
     */
    public function cancelledLocalAmount(): float
    {
        $currency = $this->currency;
        $mc = $this->mc_cancelled_amount ?? [];

        if ($currency !== null && isset($mc[$currency])) {
            return (float) $mc[$currency];
        }

        return (float) $this->cancelled_amount;
    }

    /**
     * 전액 취소 여부 확인
     *
     * @return bool 전액 취소 여부
     */
    public function isFullyCancelled(): bool
    {
        return $this->getCancellableAmount() <= 0;
    }

    /**
     * 부분 취소 여부 확인
     *
     * @return bool 부분 취소 여부
     */
    public function isPartiallyCancelled(): bool
    {
        return $this->cancelled_amount > 0 && ! $this->isFullyCancelled();
    }

    /**
     * 할부 결제 여부 확인
     *
     * @return bool 할부 결제 여부
     */
    public function isInstallment(): bool
    {
        return $this->card_installment_months > 0;
    }

    /**
     * 할부 정보 문자열 반환
     *
     * @return string 할부 정보 (예: "3개월 무이자", "일시불")
     */
    public function getInstallmentLabel(): string
    {
        if (! $this->isInstallment()) {
            return '일시불';
        }

        $label = sprintf('%d개월', $this->card_installment_months);

        if ($this->is_interest_free) {
            $label .= ' 무이자';
        }

        return $label;
    }

    /**
     * 가상계좌 입금 기한 만료 여부 확인
     *
     * @return bool 입금 기한 만료 여부
     */
    public function isVbankExpired(): bool
    {
        if (! $this->isVirtualAccount() || ! $this->vbank_due_at) {
            return false;
        }

        return $this->vbank_due_at->isPast();
    }
}
