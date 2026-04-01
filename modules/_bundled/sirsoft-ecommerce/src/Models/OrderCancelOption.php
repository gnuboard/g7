<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sirsoft\Ecommerce\Enums\CancelOptionStatusEnum;

/**
 * 주문 취소 옵션별 상세 모델
 */
class OrderCancelOption extends Model
{
    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'ecommerce_order_cancel_options';

    /**
     * 대량 할당 가능 필드
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_cancel_id',
        'order_id',
        'order_option_id',
        'option_status',
        'cancel_quantity',
        'original_quantity',
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
            'option_status' => CancelOptionStatusEnum::class,
            'cancel_quantity' => 'integer',
            'original_quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'subtotal_amount' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * 취소 레코드와의 관계
     *
     * @return BelongsTo
     */
    public function orderCancel(): BelongsTo
    {
        return $this->belongsTo(OrderCancel::class, 'order_cancel_id');
    }

    /**
     * 주문과의 관계
     *
     * @return BelongsTo
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * 주문 옵션과의 관계
     *
     * @return BelongsTo
     */
    public function orderOption(): BelongsTo
    {
        return $this->belongsTo(OrderOption::class, 'order_option_id');
    }

    /**
     * 처리 관리자와의 관계
     *
     * @return BelongsTo
     */
    public function processedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
