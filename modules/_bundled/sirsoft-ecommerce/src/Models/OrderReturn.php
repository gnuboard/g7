<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnFactory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimReasonFaultTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimShippingFeeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundPriorityEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;

/**
 * 반품 신청서 모델
 */
class OrderReturn extends Model
{
    use HasFactory;

    /**
     * 모델 팩토리를 돌려줍니다.
     *
     * @return OrderReturnFactory 반품 신청서 팩토리
     */
    protected static function newFactory()
    {
        return OrderReturnFactory::new();
    }

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'ecommerce_order_returns';

    /**
     * 대량 할당 가능 필드
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_id',
        'return_number',
        'return_status',
        'reason_code',
        'reason_detail',
        'fault_type',
        'charge_method',
        'shipping_fee_mode',
        'claim_shipping_fee',
        'mc_claim_shipping_fee',
        'shipping_fee_snapshot',
        'total_charge_amount',
        'mc_total_charge_amount',
        'currency_code',
        'items_snapshot',
        'return_address_snapshot',
        'collection_completed_at',
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
            'return_status' => ReturnStatusEnum::class,
            'fault_type' => ClaimReasonFaultTypeEnum::class,
            'charge_method' => ClaimChargeMethodEnum::class,
            'shipping_fee_mode' => ClaimShippingFeeModeEnum::class,
            'claim_shipping_fee' => 'decimal:2',
            'mc_claim_shipping_fee' => 'array',
            'shipping_fee_snapshot' => 'array',
            'total_charge_amount' => 'decimal:2',
            'mc_total_charge_amount' => 'array',
            'items_snapshot' => 'array',
            'return_address_snapshot' => 'array',
            'original_calculation_snapshot' => 'array',
            'recalculated_snapshot' => 'array',
            'refund_priority' => RefundPriorityEnum::class,
            'collection_completed_at' => 'datetime',
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
     * 반품 신청 상품 목록과의 관계
     *
     * @return HasMany 신청 상품 목록 관계
     */
    public function returnOptions(): HasMany
    {
        return $this->hasMany(OrderReturnOption::class, 'order_return_id');
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
     * 완료 상품 행을 담아 접수된 환불신청서 목록과의 관계 (상품 행 `refund_id` 경유 — 완료 회차마다 1건)
     *
     * 환불신청서는 반품 신청서당 한 건이 아니다 — 부분 완료 회차마다 그때 환불이 연결되지 않은
     * 완료 상품으로 접수한다. 한 환불신청서가 여러 상품 행에 연결되므로 중복을 없앤다.
     *
     * @return HasManyThrough 환불신청서 관계
     */
    public function refunds(): HasManyThrough
    {
        return $this->hasManyThrough(
            OrderRefund::class,
            OrderReturnOption::class,
            'order_return_id',
            'id',
            'id',
            'refund_id',
        )->distinct();
    }

    /**
     * 이 반품에서 생긴 환불신청서 전부 (출처 축 — 반려·철회 후 재접수 이력 포함, 보완 5)
     *
     * 표시·목록 일괄 로드용이다. `refunds()`(상품 행 `refund_id` 경유)는 재접수 때 덮어써져 과거 신청서를 잃는다 —
     * 화면에는 이 관계를 쓰고, 금액 선점 계산(D19)은 종전대로 `refunds()` 를 쓴다.
     *
     * @return HasMany 환불신청서 관계
     */
    public function sourcedRefunds(): HasMany
    {
        return $this->hasMany(OrderRefund::class, 'refund_source_id')
            ->where('refund_source_type', RefundSourceTypeEnum::RETURN->value);
    }

    /**
     * 완료됐는데 살아 있는 환불신청서에 담기지 않은 상품 행을 반환합니다. (「환불 미접수」 — D9 · S7)
     *
     * 상세 배너 금액(`AdminClaimService::pendingRefundFor`)·목록 뱃지·주문 상세 이력 뱃지가 모두 이 판정을 쓰고,
     * 목록 필터는 쿼리판 `scopePendingRefund` 를 쓴다 — 한 곳만 바뀌어 배너와 뱃지가 갈리지 않게 한 출처로 둔다.
     *
     * @return Collection<int, OrderReturnOption> 환불 미접수 상품 행
     */
    public function pendingRefundItems(): Collection
    {
        return $this->loadMissing('returnOptions.refund')->returnOptions
            ->filter(fn (OrderReturnOption $item) => $item->option_status?->value === ReturnStatusEnum::COMPLETED->value
                && ! $item->hasLiveRefund())
            ->values();
    }

    /**
     * 환불 미접수 상품 행이 있는 반품만 남깁니다. (`pendingRefundItems()` 의 쿼리판)
     *
     * @param  Builder  $query  쿼리
     * @return Builder 쿼리
     */
    public function scopePendingRefund(Builder $query): Builder
    {
        return $query->whereHas('returnOptions', fn (Builder $items) => $items
            ->where('option_status', ReturnStatusEnum::COMPLETED->value)
            ->withoutLiveRefund());
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
        return ClaimTypeEnum::RETURN;
    }

    /**
     * 최종(터미널) 상태 여부를 반환합니다.
     *
     * @return bool 최종 상태이면 true
     */
    public function isFinal(): bool
    {
        return $this->return_status->isFinal();
    }

    /**
     * 신청자가 철회할 수 있는 상태인지 반환합니다.
     *
     * @return bool 철회 가능하면 true
     */
    public function isWithdrawable(): bool
    {
        return $this->return_status->isWithdrawable();
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
     * 반품 사유 라벨을 반환합니다.
     *
     * @return string 현지화된 사유 라벨
     */
    public function getReasonLabel(): string
    {
        $reason = ClaimReason::where('type', ClaimTypeEnum::RETURN->value)
            ->where('code', $this->reason_code)->first();

        return $reason ? $reason->getLocalizedName() : ($this->reason_code ?? '');
    }
}
