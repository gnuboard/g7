<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;

/**
 * 반품 신청 상품별 상세 모델
 */
class OrderReturnOption extends Model
{
    use HasFactory;

    /**
     * 모델 팩토리를 돌려줍니다.
     *
     * @return OrderReturnOptionFactory 모델 팩토리
     */
    protected static function newFactory()
    {
        return OrderReturnOptionFactory::new();
    }

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'ecommerce_order_return_options';

    /**
     * 대량 할당 가능 필드
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_return_id',
        'order_id',
        'order_option_id',
        'option_status',
        'return_quantity',
        'original_quantity',
        'original_option_status',
        'unit_price',
        'subtotal_amount',
        'completed_at',
        'collection_carrier_id',
        'collection_tracking_number',
        'collection_shipped_at',
        'collected_at',
        'reject_reason',
        'is_stock_restored',
        'return_address_snapshot',
        'refund_id',
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
            'option_status' => ReturnStatusEnum::class,
            'return_quantity' => 'integer',
            'original_quantity' => 'integer',
            'original_option_status' => OrderStatusEnum::class,
            'unit_price' => 'decimal:2',
            'subtotal_amount' => 'decimal:2',
            'completed_at' => 'datetime',
            'collection_shipped_at' => 'datetime',
            'collected_at' => 'datetime',
            'is_stock_restored' => 'boolean',
            'return_address_snapshot' => 'array',
        ];
    }

    /**
     * 반품 신청서와의 관계
     *
     * @return BelongsTo 소속 반품 신청서 관계
     */
    public function orderReturn(): BelongsTo
    {
        return $this->belongsTo(OrderReturn::class, 'order_return_id');
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
     * 회수 택배사와의 관계 (이 행의 헌 물건을 실은 택배사)
     *
     * @return BelongsTo 회수 택배사 관계
     */
    public function collectionCarrier(): BelongsTo
    {
        return $this->belongsTo(ShippingCarrier::class, 'collection_carrier_id');
    }

    /**
     * 이 행을 담아 접수된 환불신청서와의 관계 (완료 회차마다 접수)
     *
     * @return BelongsTo 환불신청서 관계
     */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(OrderRefund::class, 'refund_id');
    }

    /**
     * 이 행이 살아 있는 환불신청서(반려·철회 제외 — `RefundStatusEnum::linkReleasingStatuses()`)에 연결돼 있는지 반환합니다.
     *
     * 반품 서비스(재접수 허용)·환불 미접수 판정·주문 환불 가능액의 선점이 모두 이 판정을 쓴다 — 한쪽만 `refund_id`
     * 유무로 보면 반려된 몫이 어느 쪽에서도 잡히지 않는다.
     *
     * @return bool 연결돼 있으면 true
     */
    public function hasLiveRefund(): bool
    {
        if ($this->refund_id === null) {
            return false;
        }

        $refund = $this->relationLoaded('refund') ? $this->refund : $this->refund()->first();
        $status = $refund?->refund_status;
        $value = $status instanceof RefundStatusEnum ? $status->value : $status;

        return $refund !== null && ! in_array($value, RefundStatusEnum::linkReleasingValues(), true);
    }

    /**
     * 살아 있는 환불신청서에 연결되지 않은 행만 남깁니다. (`hasLiveRefund()` 의 쿼리판)
     *
     * @param  Builder  $query  쿼리
     * @return Builder 쿼리
     */
    public function scopeWithoutLiveRefund(Builder $query): Builder
    {
        return $query->where(fn (Builder $link) => $link
            ->whereNull('refund_id')
            ->orWhereHas('refund', fn (Builder $refund) => $refund->whereIn('refund_status', RefundStatusEnum::linkReleasingValues())));
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
     * 부분 수량 반품인지 반환합니다.
     *
     * @return bool 부분 수량이면 true
     */
    public function isPartialQuantity(): bool
    {
        return $this->return_quantity < $this->original_quantity;
    }
}
