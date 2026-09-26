<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderExchangeOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;

/**
 * 교환 신청 상품별 상세 모델
 */
class OrderExchangeOption extends Model
{
    use HasFactory;

    /**
     * 모델 팩토리를 돌려줍니다.
     *
     * @return OrderExchangeOptionFactory 모델 팩토리
     */
    protected static function newFactory()
    {
        return OrderExchangeOptionFactory::new();
    }

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'ecommerce_order_exchange_options';

    /**
     * 대량 할당 가능 필드
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_exchange_id',
        'order_id',
        'order_option_id',
        'option_status',
        'exchange_quantity',
        'original_quantity',
        'original_option_status',
        'unit_price',
        'new_product_option_id',
        'new_option_snapshot',
        'new_unit_price',
        'price_difference',
        'mc_price_difference',
        'new_order_option_id',
        'is_new_stock_deducted',
        'completed_at',
        'collection_carrier_id',
        'collection_tracking_number',
        'collection_shipped_at',
        'collected_at',
        'redelivery_carrier_id',
        'redelivery_tracking_number',
        'redelivery_shipped_at',
        'redelivered_at',
        'reject_reason',
        'is_stock_restored',
        'return_address_snapshot',
        'unreturned_at',
        'unreturned_reason',
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
            'option_status' => ExchangeStatusEnum::class,
            'exchange_quantity' => 'integer',
            'original_quantity' => 'integer',
            'original_option_status' => OrderStatusEnum::class,
            'unit_price' => 'decimal:2',
            'new_option_snapshot' => 'array',
            'new_unit_price' => 'decimal:2',
            'price_difference' => 'decimal:2',
            'mc_price_difference' => 'array',
            'is_new_stock_deducted' => 'boolean',
            'completed_at' => 'datetime',
            'collection_shipped_at' => 'datetime',
            'collected_at' => 'datetime',
            'redelivery_shipped_at' => 'datetime',
            'redelivered_at' => 'datetime',
            'is_stock_restored' => 'boolean',
            'return_address_snapshot' => 'array',
            'unreturned_at' => 'datetime',
        ];
    }

    /**
     * 교환 신청서와의 관계
     *
     * @return BelongsTo 소속 교환 신청서 관계
     */
    public function orderExchange(): BelongsTo
    {
        return $this->belongsTo(OrderExchange::class, 'order_exchange_id');
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
     * 원 주문옵션과의 관계
     *
     * @return BelongsTo 원 주문옵션 관계
     */
    public function orderOption(): BelongsTo
    {
        return $this->belongsTo(OrderOption::class, 'order_option_id');
    }

    /**
     * 교환 대상 신규 상품옵션과의 관계
     *
     * @return BelongsTo 신규 상품옵션 관계
     */
    public function newProductOption(): BelongsTo
    {
        return $this->belongsTo(ProductOption::class, 'new_product_option_id');
    }

    /**
     * 재발송으로 생성된 주문옵션과의 관계
     *
     * @return BelongsTo 재발송 주문옵션 관계
     */
    public function newOrderOption(): BelongsTo
    {
        return $this->belongsTo(OrderOption::class, 'new_order_option_id');
    }

    /**
     * 회수 택배사와의 관계 (이 행의 헌 물건을 실은 택배사)
     *
     * @return BelongsTo 회수 택배사 관계
     */
    public function collectionCarrier(): BelongsTo
    {
        return $this->belongsTo(ShippingCarrier::class, 'collection_carrier_id');
    }

    /**
     * 재발송 택배사와의 관계 (이 행의 교환품을 실은 택배사)
     *
     * @return BelongsTo 재발송 택배사 관계
     */
    public function redeliveryCarrier(): BelongsTo
    {
        return $this->belongsTo(ShippingCarrier::class, 'redelivery_carrier_id');
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
     * 추가 청구가 발생하는 차액인지 반환합니다. (신규 옵션이 더 비싼 경우)
     *
     * @return bool 추가 청구 대상이면 true
     */
    public function hasAdditionalCharge(): bool
    {
        return (float) $this->price_difference > 0;
    }

    /**
     * 환불이 발생하는 차액인지 반환합니다. (신규 옵션이 더 싼 경우)
     *
     * @return bool 환불 대상이면 true
     */
    public function hasRefundableDifference(): bool
    {
        return (float) $this->price_difference < 0;
    }

    /**
     * 부분 수량 교환인지 반환합니다.
     *
     * @return bool 부분 수량이면 true
     */
    public function isPartialQuantity(): bool
    {
        return $this->exchange_quantity < $this->original_quantity;
    }
}
