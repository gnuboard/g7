<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderReturnOptionFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;

/**
 * 반품 신청 상품별 상세 모델
 */
class OrderReturnOption extends Model
{
    use HasFactory;

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
