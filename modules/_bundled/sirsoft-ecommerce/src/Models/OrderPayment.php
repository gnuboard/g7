<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;

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
        'payment_method' => PaymentMethodEnum::class,
        // 다중 통화 컬럼 (JSON)
        'mc_paid_amount' => 'array',
        'mc_cancelled_amount' => 'array',
    ];

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
        return $this->payment_method === PaymentMethodEnum::CARD;
    }

    /**
     * 가상계좌 결제 여부 확인
     *
     * @return bool 가상계좌 결제 여부
     */
    public function isVirtualAccount(): bool
    {
        return $this->payment_method === PaymentMethodEnum::VBANK;
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
     * 취소 가능 금액 계산
     *
     * @return float 취소 가능 금액
     */
    public function getCancellableAmount(): float
    {
        return $this->paid_amount_local - $this->cancelled_amount;
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
