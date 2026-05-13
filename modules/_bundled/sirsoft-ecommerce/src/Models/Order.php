<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Enums\DeviceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;

/**
 * 주문 모델
 */
class Order extends Model
{
    use HasFactory;
    use SoftDeletes;

    /** @var array<string, array> 활동 로그 추적 필드 */
    public static array $activityLogFields = [
        'order_status' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.order_status', 'type' => 'enum', 'enum' => \Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum::class],
        'total_amount' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.total_amount', 'type' => 'currency'],
        'total_paid_amount' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.total_paid_amount', 'type' => 'currency'],
        'total_discount_amount' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.total_discount_amount', 'type' => 'currency'],
        'total_shipping_amount' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.total_shipping_amount', 'type' => 'currency'],
        'total_cancelled_amount' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.total_cancelled_amount', 'type' => 'currency'],
        'total_refunded_amount' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.total_refunded_amount', 'type' => 'currency'],
        'admin_memo' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.admin_memo', 'type' => 'text'],
        'paid_at' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.paid_at', 'type' => 'datetime'],
        'confirmed_at' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.confirmed_at', 'type' => 'datetime'],
    ];

    /**
     * 팩토리 클래스 반환
     *
     * @return string
     */
    protected static function newFactory()
    {
        return OrderFactory::new();
    }

    /**
     * 라우트 모델 바인딩 시 ID 또는 order_number로 주문을 조회합니다.
     *
     * @param  mixed  $value  라우트 파라미터 값 (ID 또는 order_number)
     * @param  string|null  $field  검색할 필드명
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if ($field) {
            return $this->where($field, $value)->firstOrFail();
        }

        if (is_numeric($value)) {
            return $this->where('id', $value)->firstOrFail();
        }

        return $this->where('order_number', $value)->firstOrFail();
    }

    protected $table = 'ecommerce_orders';

    protected $fillable = [
        'user_id',
        'order_number',
        'order_status',
        'order_device',
        'is_first_order',
        'ip_address',
        'currency',
        'currency_snapshot',
        'subtotal_amount',
        'total_discount_amount',
        'total_product_coupon_discount_amount',
        'total_order_coupon_discount_amount',
        'total_coupon_discount_amount',
        'total_code_discount_amount',
        'base_shipping_amount',
        'extra_shipping_amount',
        'shipping_discount_amount',
        'total_shipping_amount',
        'total_amount',
        'total_tax_amount',
        'total_vat_amount',
        'total_tax_free_amount',
        'total_points_used_amount',
        'total_deposit_used_amount',
        'total_paid_amount',
        'total_due_amount',
        'total_cancelled_amount',
        'total_refunded_amount',
        'total_refunded_points_amount',
        'total_earned_points_amount',
        'cancellation_count',
        'item_count',
        'total_weight',
        'total_volume',
        'ordered_at',
        'paid_at',
        'payment_due_at',
        'confirmed_at',
        'admin_memo',
        'promotions_applied_snapshot',
        'shipping_policy_applied_snapshot',
        'promotions_available_snapshot',
        'order_meta',
        // 다중 통화 컬럼 (JSON)
        'mc_subtotal_amount',
        'mc_total_discount_amount',
        'mc_total_product_coupon_discount_amount',
        'mc_total_order_coupon_discount_amount',
        'mc_total_coupon_discount_amount',
        'mc_total_code_discount_amount',
        'mc_base_shipping_amount',
        'mc_extra_shipping_amount',
        'mc_shipping_discount_amount',
        'mc_total_shipping_amount',
        'mc_total_points_used_amount',
        'mc_total_deposit_used_amount',
        'mc_total_tax_amount',
        'mc_total_tax_free_amount',
        'mc_total_amount',
        'mc_total_paid_amount',
    ];

    protected $casts = [
        'is_first_order' => 'boolean',
        'currency_snapshot' => 'array',
        'subtotal_amount' => 'decimal:2',
        'total_discount_amount' => 'decimal:2',
        'total_product_coupon_discount_amount' => 'decimal:2',
        'total_order_coupon_discount_amount' => 'decimal:2',
        'total_coupon_discount_amount' => 'decimal:2',
        'total_code_discount_amount' => 'decimal:2',
        'base_shipping_amount' => 'decimal:2',
        'extra_shipping_amount' => 'decimal:2',
        'shipping_discount_amount' => 'decimal:2',
        'total_shipping_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'total_tax_amount' => 'decimal:2',
        'total_vat_amount' => 'decimal:2',
        'total_tax_free_amount' => 'decimal:2',
        'total_points_used_amount' => 'decimal:2',
        'total_deposit_used_amount' => 'decimal:2',
        'total_paid_amount' => 'decimal:2',
        'total_due_amount' => 'decimal:2',
        'total_cancelled_amount' => 'decimal:2',
        'total_refunded_amount' => 'decimal:2',
        'total_refunded_points_amount' => 'decimal:2',
        'total_earned_points_amount' => 'decimal:2',
        'cancellation_count' => 'integer',
        'item_count' => 'integer',
        'total_weight' => 'decimal:3',
        'total_volume' => 'decimal:3',
        'ordered_at' => 'datetime',
        'paid_at' => 'datetime',
        'payment_due_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'promotions_applied_snapshot' => 'array',
        'shipping_policy_applied_snapshot' => 'array',
        'promotions_available_snapshot' => 'array',
        'order_meta' => 'array',
        'order_status' => OrderStatusEnum::class,
        'order_device' => DeviceTypeEnum::class,
        // 다중 통화 컬럼 (JSON)
        'mc_subtotal_amount' => 'array',
        'mc_total_discount_amount' => 'array',
        'mc_total_product_coupon_discount_amount' => 'array',
        'mc_total_order_coupon_discount_amount' => 'array',
        'mc_total_coupon_discount_amount' => 'array',
        'mc_total_code_discount_amount' => 'array',
        'mc_base_shipping_amount' => 'array',
        'mc_extra_shipping_amount' => 'array',
        'mc_shipping_discount_amount' => 'array',
        'mc_total_shipping_amount' => 'array',
        'mc_total_points_used_amount' => 'array',
        'mc_total_deposit_used_amount' => 'array',
        'mc_total_tax_amount' => 'array',
        'mc_total_tax_free_amount' => 'array',
        'mc_total_amount' => 'array',
        'mc_total_paid_amount' => 'array',
    ];

    /**
     * 회원 관계
     *
     * @return BelongsTo 회원 모델과의 관계
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 주문 옵션 관계
     *
     * @return HasMany 주문 옵션 모델과의 관계
     */
    public function options(): HasMany
    {
        return $this->hasMany(OrderOption::class, 'order_id');
    }

    /**
     * 배송지 관계 (모든 주소 유형)
     *
     * @return HasMany 배송지 모델과의 관계
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(OrderAddress::class, 'order_id');
    }

    /**
     * 배송지 관계 (shipping 타입만)
     *
     * @return HasOne 배송지 모델과의 관계
     */
    public function shippingAddress(): HasOne
    {
        return $this->hasOne(OrderAddress::class, 'order_id')
            ->where('address_type', 'shipping');
    }

    /**
     * 청구지 관계 (billing 타입만)
     *
     * @return HasOne 청구지 모델과의 관계
     */
    public function billingAddress(): HasOne
    {
        return $this->hasOne(OrderAddress::class, 'order_id')
            ->where('address_type', 'billing');
    }

    /**
     * 결제 관계
     *
     * @return HasOne 결제 모델과의 관계
     */
    public function payment(): HasOne
    {
        return $this->hasOne(OrderPayment::class, 'order_id');
    }

    /**
     * 결제 관계 (복수)
     *
     * @return HasMany 결제 모델과의 관계
     */
    public function payments(): HasMany
    {
        return $this->hasMany(OrderPayment::class, 'order_id');
    }

    /**
     * 배송 관계
     *
     * @return HasMany 배송 모델과의 관계
     */
    public function shippings(): HasMany
    {
        return $this->hasMany(OrderShipping::class, 'order_id');
    }

    /**
     * 세금계산서 관계
     *
     * @return HasMany 세금계산서 모델과의 관계
     */
    public function taxInvoices(): HasMany
    {
        return $this->hasMany(OrderTaxInvoice::class, 'order_id');
    }

    /**
     * 취소 이력 관계
     *
     * @return HasMany 취소 모델과의 관계
     */
    public function cancels(): HasMany
    {
        return $this->hasMany(OrderCancel::class, 'order_id');
    }

    /**
     * 환불 이력 관계
     *
     * @return HasMany 환불 모델과의 관계
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(OrderRefund::class, 'order_id');
    }

    /**
     * 주문자명 반환 (배송지 기준)
     *
     * @return string|null 주문자명
     */
    public function getOrdererName(): ?string
    {
        return $this->shippingAddress?->orderer_name;
    }

    /**
     * 수령인명 반환 (배송지 기준)
     *
     * @return string|null 수령인명
     */
    public function getRecipientName(): ?string
    {
        return $this->shippingAddress?->recipient_name;
    }

    /**
     * 첫 번째 상품명 반환
     *
     * @return string|null 첫 번째 상품명
     */
    public function getFirstProductName(): ?string
    {
        return $this->options->first()?->product_name;
    }

    /**
     * 상품 요약 반환 (상품명 외 N건)
     *
     * @return string 상품 요약
     */
    public function getProductSummary(): string
    {
        $firstProduct = $this->getFirstProductName();
        if (! $firstProduct) {
            return '';
        }

        $count = $this->options->count();
        if ($count <= 1) {
            return $firstProduct;
        }

        return sprintf('%s 외 %d건', $firstProduct, $count - 1);
    }

    /**
     * 결제 수단명 반환
     *
     * @return string|null 결제 수단명
     */
    public function getPaymentMethodLabel(): ?string
    {
        return $this->payment?->payment_method?->getLabel();
    }

    /**
     * 취소 가능 여부 확인
     *
     * 환경설정의 cancellable_statuses 기반으로 판단합니다.
     * 설정이 없으면 기본값(결제완료까지)을 사용합니다.
     *
     * @param  array|null  $cancellableStatuses  취소 가능 상태 목록 (null이면 기본값)
     * @return bool 취소 가능 여부
     */
    public function isCancellable(?array $cancellableStatuses = null): bool
    {
        if ($this->order_status === OrderStatusEnum::CANCELLED) {
            return false;
        }

        $defaultStatuses = [
            OrderStatusEnum::PENDING_ORDER,
            OrderStatusEnum::PENDING_PAYMENT,
            OrderStatusEnum::PAYMENT_COMPLETE,
        ];

        if ($cancellableStatuses === null) {
            return in_array($this->order_status, $defaultStatuses);
        }

        $statusEnums = array_filter(
            array_map(fn ($s) => OrderStatusEnum::tryFrom($s), $cancellableStatuses)
        );

        // 기본 상태(미결제)는 항상 취소 가능
        $allStatuses = array_unique(array_merge($defaultStatuses, $statusEnums), SORT_REGULAR);

        return in_array($this->order_status, $allStatuses);
    }

    /**
     * 취소 불가 시 상세 사유 메시지를 반환합니다.
     *
     * @param  array|null  $cancellableStatuses  취소 가능 상태 목록 (null이면 기본값)
     * @return string 취소 불가 사유 메시지
     */
    public function getCancelDeniedReason(?array $cancellableStatuses = null): string
    {
        if ($this->order_status === OrderStatusEnum::CANCELLED) {
            return __('sirsoft-ecommerce::exceptions.order_already_cancelled');
        }

        $allowedStatuses = $this->getCancellableStatuses($cancellableStatuses);
        $allowedLabels = array_map(fn (OrderStatusEnum $s) => $s->label(), $allowedStatuses);

        return __('sirsoft-ecommerce::exceptions.order_not_cancellable_detail', [
            'current_status' => $this->order_status->label(),
            'allowed_statuses' => implode(', ', $allowedLabels),
        ]);
    }

    /**
     * 취소 가능한 상태 목록을 반환합니다.
     *
     * @param  array|null  $cancellableStatuses  설정값 (null이면 기본값)
     * @return array<OrderStatusEnum> 취소 가능 상태 Enum 배열
     */
    public function getCancellableStatuses(?array $cancellableStatuses = null): array
    {
        $defaultStatuses = [
            OrderStatusEnum::PENDING_ORDER,
            OrderStatusEnum::PENDING_PAYMENT,
            OrderStatusEnum::PAYMENT_COMPLETE,
        ];

        if ($cancellableStatuses === null) {
            return $defaultStatuses;
        }

        $statusEnums = array_filter(
            array_map(fn ($s) => OrderStatusEnum::tryFrom($s), $cancellableStatuses)
        );

        return array_values(array_unique(array_merge($defaultStatuses, $statusEnums), SORT_REGULAR));
    }

    /**
     * 부분취소 가능 여부 확인
     *
     * 활성 옵션이 2개 이상이어야 부분취소가 가능합니다.
     *
     * @param  array|null  $cancellableStatuses  취소 가능 상태 목록
     * @return bool 부분취소 가능 여부
     */
    public function isPartialCancellable(?array $cancellableStatuses = null): bool
    {
        if (! $this->isCancellable($cancellableStatuses)) {
            return false;
        }

        $activeOptionCount = $this->options()
            ->where('option_status', '!=', OrderStatusEnum::CANCELLED->value)
            ->count();

        return $activeOptionCount >= 2;
    }

    /**
     * 구매 확정 가능 여부 확인
     *
     * @return bool 구매 확정 가능 여부
     */
    public function isConfirmable(): bool
    {
        return $this->order_status === OrderStatusEnum::DELIVERED;
    }
}
