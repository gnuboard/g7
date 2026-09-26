<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
use Modules\Sirsoft\Ecommerce\Enums\ExchangeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;

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
        'exchange_mode',
        'exchange_mode_set_by',
        'exchange_mode_set_at',
        'collection_due_at',
        'collection_overdue_notified_at',
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
        'collection_completed_at',
        'redelivered_at',
        'is_stock_restored',
        'requested_by',
        'requested_by_admin',
        'processed_by',
        'approved_at',
        'on_hold_at',
        'rejected_at',
        'withdrawn_at',
        'withdrawn_by_admin',
        'withdraw_reason',
        'completed_at',
        'reject_reason',
        'admin_memo',
        'refund_bank_code',
        'refund_bank_account',
        'refund_bank_holder',
        'original_calculation_snapshot',
        'recalculated_snapshot',
        'refund_priority',
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
            'exchange_mode' => ExchangeModeEnum::class,
            'exchange_mode_set_at' => 'datetime',
            'collection_due_at' => 'datetime',
            'collection_overdue_notified_at' => 'datetime',
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
            'refund_priority' => RefundPriorityEnum::class,
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
     * 이 교환에서 파생된 환불신청서 목록과의 관계 (저가 차액 환불 — `refund_source_type = exchange`)
     *
     * @return HasMany 파생 환불신청서 관계
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(OrderRefund::class, 'refund_source_id')
            ->where('refund_source_type', RefundSourceTypeEnum::EXCHANGE->value);
    }

    /**
     * 이 교환에서 파생된 살아 있는(반려·철회 제외) 차액 환불신청서가 있는지 반환합니다.
     *
     * 목록은 `scopeWithLiveRefundCount` 로 센 값(`live_refund_count`)을 읽고, 그 값이 없으면 직접 조회한다.
     *
     * @return bool 살아 있는 환불신청서가 있으면 true
     */
    public function hasLiveRefund(): bool
    {
        if (array_key_exists('live_refund_count', $this->getAttributes())) {
            return (int) $this->getAttribute('live_refund_count') > 0;
        }

        return $this->refunds()->whereNotIn('refund_status', RefundStatusEnum::linkReleasingValues())->exists();
    }

    /**
     * 완료됐는데 살아 있는 차액 환불신청서가 없는 저가 차액 상품 행을 반환합니다. (「환불 미접수」 — D9)
     *
     * 상세 배너 금액(`AdminClaimService::pendingRefundFor`)·목록 뱃지·주문 상세 이력 뱃지가 모두 이 판정을 쓰고,
     * 목록 필터는 쿼리판 `scopePendingRefund` 를 쓴다.
     *
     * @return Collection<int, OrderExchangeOption> 환불 미접수 상품 행
     */
    public function pendingRefundItems(): Collection
    {
        $lowerPriced = $this->loadMissing('exchangeOptions')->exchangeOptions
            ->filter(fn (OrderExchangeOption $item) => $item->option_status?->value === ExchangeStatusEnum::COMPLETED->value
                && (float) $item->price_difference < 0)
            ->values();

        return $lowerPriced->isEmpty() || $this->hasLiveRefund() ? new Collection : $lowerPriced;
    }

    /**
     * 환불 미접수 저가 차액 상품이 있는 교환만 남깁니다. (`pendingRefundItems()` 의 쿼리판)
     *
     * @param  Builder  $query  쿼리
     * @return Builder 쿼리
     */
    public function scopePendingRefund(Builder $query): Builder
    {
        return $query
            ->whereHas('exchangeOptions', fn (Builder $items) => $items
                ->where('option_status', ExchangeStatusEnum::COMPLETED->value)
                ->where('price_difference', '<', 0))
            ->whereDoesntHave('refunds', fn (Builder $refunds) => $refunds
                ->whereNotIn('refund_status', RefundStatusEnum::linkReleasingValues()));
    }

    /**
     * 살아 있는 차액 환불신청서 수를 `live_refund_count` 로 함께 셉니다. (목록 — `hasLiveRefund()` 가 읽는다)
     *
     * @param  Builder  $query  쿼리
     * @return Builder 쿼리
     */
    public function scopeWithLiveRefundCount(Builder $query): Builder
    {
        return $query->withCount(['refunds as live_refund_count' => fn (Builder $refunds) => $refunds
            ->whereNotIn('refund_status', RefundStatusEnum::linkReleasingValues())]);
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
     * 대리 접수한 관리자와의 관계 (판매자 접수)
     *
     * @return BelongsTo 대리 접수 관리자 관계
     */
    public function requestedByAdminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_admin');
    }

    /**
     * 고객 요청으로 대신 철회한 관리자와의 관계
     *
     * @return BelongsTo 대리 철회 관리자 관계
     */
    public function withdrawnByAdminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'withdrawn_by_admin');
    }

    /**
     * 진행 방식을 바꾼 관리자와의 관계
     *
     * @return BelongsTo 진행 방식 변경 관리자 관계
     */
    public function exchangeModeSetByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exchange_mode_set_by');
    }

    /**
     * 진행 방식을 반환합니다. (저장값이 없거나 알 수 없으면 일반 교환)
     *
     * @return ExchangeModeEnum 진행 방식
     */
    public function mode(): ExchangeModeEnum
    {
        return ExchangeModeEnum::resolve($this->exchange_mode);
    }

    /**
     * 선교환인지 반환합니다.
     *
     * @return bool 선교환이면 true
     */
    public function isAdvanceExchange(): bool
    {
        return $this->mode()->isAdvance();
    }

    /**
     * 운영자가 고객을 대신해 접수한 신청서인지 반환합니다. (판매자 접수 — 고객 철회·수정 불가)
     *
     * @return bool 판매자 접수면 true
     */
    public function isAdminRequested(): bool
    {
        return $this->requested_by_admin !== null;
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
