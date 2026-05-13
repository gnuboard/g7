<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderOptionSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;

/**
 * 주문 옵션 모델
 */
class OrderOption extends Model
{
    use HasFactory;

    /**
     * 활동 로그 추적 대상 필드 정의
     *
     * @var array<string, array>
     */
    public static array $activityLogFields = [
        'option_status' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.option_status', 'type' => 'enum', 'enum' => \Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum::class],
        'quantity' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.quantity', 'type' => 'number'],
        'cancelled_quantity' => ['label_key' => 'sirsoft-ecommerce::activity_log.fields.cancelled_quantity', 'type' => 'number'],
    ];

    protected static function newFactory()
    {
        return OrderOptionFactory::new();
    }

    protected $table = 'ecommerce_order_options';

    protected $fillable = [
        'order_id',
        'parent_option_id',
        'product_id',
        'product_option_id',
        'option_status',
        'is_stock_deducted',
        'source_type',
        'source_option_id',
        'sku',
        'product_name',
        'product_option_name',
        'option_name',
        'option_value',
        'quantity',
        'cancelled_quantity',
        'cancel_reason',
        'unit_weight',
        'unit_volume',
        'subtotal_weight',
        'subtotal_volume',
        'unit_price',
        'subtotal_price',
        'subtotal_discount_amount',
        'product_coupon_discount_amount',
        'order_coupon_discount_amount',
        'coupon_discount_amount',
        'code_discount_amount',
        'subtotal_points_used_amount',
        'subtotal_deposit_used_amount',
        'subtotal_paid_amount',
        'subtotal_tax_amount',
        'subtotal_tax_free_amount',
        'subtotal_earned_points_amount',
        'product_snapshot',
        'option_snapshot',
        'promotions_applied_snapshot',
        'confirmed_at',
        'external_option_id',
        'external_meta',
        // 다중 통화 컬럼 (JSON)
        'mc_unit_price',
        'mc_subtotal_price',
        'mc_product_coupon_discount_amount',
        'mc_order_coupon_discount_amount',
        'mc_coupon_discount_amount',
        'mc_code_discount_amount',
        'mc_subtotal_points_used_amount',
        'mc_subtotal_deposit_used_amount',
        'mc_subtotal_tax_amount',
        'mc_subtotal_tax_free_amount',
        'mc_final_amount',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'cancelled_quantity' => 'integer',
        'unit_weight' => 'decimal:3',
        'unit_volume' => 'decimal:3',
        'subtotal_weight' => 'decimal:3',
        'subtotal_volume' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'subtotal_price' => 'decimal:2',
        'subtotal_discount_amount' => 'decimal:2',
        'product_coupon_discount_amount' => 'decimal:2',
        'order_coupon_discount_amount' => 'decimal:2',
        'coupon_discount_amount' => 'decimal:2',
        'code_discount_amount' => 'decimal:2',
        'subtotal_points_used_amount' => 'decimal:2',
        'subtotal_deposit_used_amount' => 'decimal:2',
        'subtotal_paid_amount' => 'decimal:2',
        'subtotal_tax_amount' => 'decimal:2',
        'subtotal_tax_free_amount' => 'decimal:2',
        'subtotal_earned_points_amount' => 'decimal:2',
        'product_name' => 'array',
        'product_option_name' => 'array',
        'option_name' => 'array',
        'option_value' => 'array',
        'product_snapshot' => 'array',
        'option_snapshot' => 'array',
        'promotions_applied_snapshot' => 'array',
        'external_meta' => 'array',
        'confirmed_at' => 'datetime',
        'option_status' => OrderStatusEnum::class,
        'is_stock_deducted' => 'boolean',
        'source_type' => OrderOptionSourceTypeEnum::class,
        // 다중 통화 컬럼 (JSON)
        'mc_unit_price' => 'array',
        'mc_subtotal_price' => 'array',
        'mc_product_coupon_discount_amount' => 'array',
        'mc_order_coupon_discount_amount' => 'array',
        'mc_coupon_discount_amount' => 'array',
        'mc_code_discount_amount' => 'array',
        'mc_subtotal_points_used_amount' => 'array',
        'mc_subtotal_deposit_used_amount' => 'array',
        'mc_subtotal_tax_amount' => 'array',
        'mc_subtotal_tax_free_amount' => 'array',
        'mc_final_amount' => 'array',
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
     * 부모 옵션 관계 (self-referencing)
     *
     * @return BelongsTo 부모 옵션 모델과의 관계
     */
    public function parentOption(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_option_id');
    }

    /**
     * 자식 옵션 관계 (self-referencing)
     *
     * @return HasMany 자식 옵션 모델과의 관계
     */
    public function childOptions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_option_id');
    }

    /**
     * 수량 분할된 옵션 관계
     *
     * @return HasMany 분할 생성된 자식 옵션과의 관계
     */
    public function splitOptions(): HasMany
    {
        return $this->hasMany(self::class, 'parent_option_id')
            ->where('source_type', OrderOptionSourceTypeEnum::SPLIT);
    }

    /**
     * 발송 수량 접근자
     *
     * 자신의 상태가 발송 이후이면 자신의 수량을, 그렇지 않으면
     * 분할된 자식 옵션 중 발송 상태인 수량을 합산합니다.
     *
     * @return int 발송 처리된 수량
     */
    public function getShippedQuantityAttribute(): int
    {
        // 자신의 상태가 발송 이후이면 자신의 수량 전체가 발송 수량
        if ($this->option_status->isShipped()) {
            return $this->quantity;
        }

        // 분할된 자식 옵션 중 발송 상태인 수량 합산
        $shippedValues = array_map(fn ($s) => $s->value, OrderStatusEnum::shippedStatuses());

        return (int) $this->splitOptions()
            ->whereIn('option_status', $shippedValues)
            ->sum('quantity');
    }

    /**
     * 상품 관계
     *
     * @return BelongsTo 상품 모델과의 관계
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * 상품 옵션 관계
     *
     * @return BelongsTo 상품 옵션 모델과의 관계
     */
    public function productOption(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'product_option_id');
    }

    /**
     * 교환 원본 옵션 관계
     *
     * @return BelongsTo 원본 옵션 모델과의 관계
     */
    public function sourceOption(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_option_id');
    }

    /**
     * 배송 관계
     *
     * @return HasMany 배송 모델과의 관계
     */
    public function shippings(): HasMany
    {
        return $this->hasMany(OrderShipping::class, 'order_option_id');
    }

    /**
     * 교환 상품 여부 확인
     *
     * @return bool 교환 상품 여부
     */
    public function isExchanged(): bool
    {
        return $this->source_type === OrderOptionSourceTypeEnum::EXCHANGE && $this->source_option_id !== null;
    }

    /**
     * 할인 후 실결제 금액 계산
     *
     * @return float 실결제 금액
     */
    public function getActualPaymentAmount(): float
    {
        return $this->subtotal_price
            - $this->subtotal_discount_amount
            - $this->subtotal_points_used_amount
            - $this->subtotal_deposit_used_amount;
    }

    /**
     * 상품 리뷰 관계
     *
     * @return HasOne 상품 리뷰 모델과의 관계
     */
    public function review(): HasOne
    {
        return $this->hasOne(ProductReview::class, 'order_option_id');
    }
}
