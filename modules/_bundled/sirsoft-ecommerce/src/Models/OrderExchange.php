<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderExchangeFactory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimShippingFeeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;

/**
 * 교환 신청서 모델
 */
class OrderExchange extends Model
{
    use HasFactory;

    /**
     * 모델 팩토리를 돌려줍니다.
     *
     * @return OrderExchangeFactory 교환 신청서 팩토리
     */
    protected static function newFactory()
    {
        return OrderExchangeFactory::new();
    }

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'ecommerce_order_exchanges';

    /**
     * 대량 할당 가능 필드
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_id',
        'exchange_number',
        'exchange_status',
        'reason_code',
        'reason_detail',
        'fault_type',
        'charge_method',
        'shipping_fee_mode',
        'claim_shipping_fee',
        'mc_claim_shipping_fee',
        'shipping_fee_snapshot',
        'additional_product_amount',
        'refund_product_amount',
        'total_charge_amount',
        'mc_total_charge_amount',
        'currency_code',
        'items_snapshot',
        'return_address_snapshot',
        'redelivery_address_snapshot',
        'collection_carrier_id',
        'collection_tracking_number',
        'collection_completed_at',
        'redelivery_carrier_id',
        'redelivery_tracking_number',
        'redelivered_at',
        'is_stock_restored',
        'requested_by',
        'processed_by',
        'approved_at',
        'on_hold_at',
        'rejected_at',
        'withdrawn_at',
        'completed_at',
        'reject_reason',
        'admin_memo',
        'refund_bank_code',
        'refund_bank_account',
        'refund_bank_holder',
        'original_calculation_snapshot',
        'recalculated_snapshot',
    ];

    /**
     * 타입 캐스팅
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exchange_status' => ExchangeStatusEnum::class,
            'fault_type' => ClaimReasonFaultTypeEnum::class,
            'charge_method' => ClaimChargeMethodEnum::class,
            'shipping_fee_mode' => ClaimShippingFeeModeEnum::class,
            'claim_shipping_fee' => 'decimal:2',
            'mc_claim_shipping_fee' => 'array',
            'shipping_fee_snapshot' => 'array',
            'additional_product_amount' => 'decimal:2',
            'refund_product_amount' => 'decimal:2',
            'total_charge_amount' => 'decimal:2',
            'mc_total_charge_amount' => 'array',
            'items_snapshot' => 'array',
            'return_address_snapshot' => 'array',
            'redelivery_address_snapshot' => 'array',
            'original_calculation_snapshot' => 'array',
            'recalculated_snapshot' => 'array',
            'collection_completed_at' => 'datetime',
            'redelivered_at' => 'datetime',
            'is_stock_restored' => 'boolean',
            'approved_at' => 'datetime',
            'on_hold_at' => 'datetime',
            'rejected_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
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
     * 교환 신청 상품 목록과의 관계
     *
     * @return HasMany 신청 상품 목록 관계
     */
    public function exchangeOptions(): HasMany
    {
        return $this->hasMany(OrderExchangeOption::class, 'order_exchange_id');
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
     * 추가결제 목록과의 관계
     *
     * @return MorphMany 추가결제 관계
     */
    public function claimPayments(): MorphMany
    {
        return $this->morphMany(ClaimPayment::class, 'payable');
    }

    /**
     * 회수 택배사와의 관계
     *
     * @return BelongsTo 회수 택배사 관계
     */
    public function collectionCarrier(): BelongsTo
    {
        return $this->belongsTo(ShippingCarrier::class, 'collection_carrier_id');
    }

    /**
     * 재발송 택배사와의 관계
     *
     * @return BelongsTo 재발송 택배사 관계
     */
    public function redeliveryCarrier(): BelongsTo
    {
        return $this->belongsTo(ShippingCarrier::class, 'redelivery_carrier_id');
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
     * 처리 관리자와의 관계
     *
     * @return BelongsTo 처리 관리자 관계
     */
    public function processedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /**
     * 클레임 유형을 반환합니다.
     *
     * @return ClaimTypeEnum 클레임 유형
     */
    public function claimType(): ClaimTypeEnum
    {
        return ClaimTypeEnum::EXCHANGE;
    }

    /**
     * 최종(터미널) 상태 여부를 반환합니다.
     *
     * @return bool 최종 상태이면 true
     */
    public function isFinal(): bool
    {
        return $this->exchange_status->isFinal();
    }

    /**
     * 신청자가 철회할 수 있는 상태인지 반환합니다.
     *
     * @return bool 철회 가능하면 true
     */
    public function isWithdrawable(): bool
    {
        return $this->exchange_status->isWithdrawable();
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
     * 미수납 청구액이 남아 있는지 반환합니다.
     *
     * 환불 차감 방식은 환불 실행 시점에 상계되므로 별도 결제 대상이 아니다.
     *
     * @return bool 결제 대기 청구액이 있으면 true
     */
    public function requiresAdditionalPayment(): bool
    {
        if ((float) $this->total_charge_amount <= 0) {
            return false;
        }

        return $this->charge_method !== null && $this->charge_method->requiresPayment();
    }

    /**
     * 교환 사유 라벨을 반환합니다.
     *
     * @return string 현지화된 사유 라벨
     */
    public function getReasonLabel(): string
    {
        $reason = ClaimReason::where('type', ClaimTypeEnum::EXCHANGE->value)
            ->where('code', $this->reason_code)->first();

        return $reason ? $reason->getLocalizedName() : ($this->reason_code ?? '');
    }
}
