<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelTypeEnum;

/**
 * 주문 취소 모델
 */
class OrderCancel extends Model
{
    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'ecommerce_order_cancels';

    /**
     * 대량 할당 가능 필드
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'order_id',
        'cancel_number',
        'cancel_type',
        'cancel_status',
        'cancel_reason_type',
        'cancel_reason',
        'items_snapshot',
        'cancelled_by',
        'cancelled_at',
    ];

    /**
     * 타입 캐스팅
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cancel_type' => CancelTypeEnum::class,
            'cancel_status' => CancelStatusEnum::class,
            'cancel_reason_type' => 'string',
            'items_snapshot' => 'array',
            'cancelled_at' => 'datetime',
        ];
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
     * 취소 옵션 목록과의 관계
     *
     * @return HasMany
     */
    public function cancelOptions(): HasMany
    {
        return $this->hasMany(OrderCancelOption::class, 'order_cancel_id');
    }

    /**
     * 환불 레코드와의 관계
     *
     * @return HasOne
     */
    public function refund(): HasOne
    {
        return $this->hasOne(OrderRefund::class, 'order_cancel_id');
    }

    /**
     * 취소 요청자와의 관계
     *
     * @return BelongsTo
     */
    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * 전체취소 여부를 반환합니다.
     *
     * @return bool
     */
    public function isFullCancel(): bool
    {
        return $this->cancel_type === CancelTypeEnum::FULL;
    }

    /**
     * 취소 완료 여부를 반환합니다.
     *
     * @return bool
     */
    public function isCompleted(): bool
    {
        return $this->cancel_status === CancelStatusEnum::COMPLETED;
    }

    /**
     * 환불 사유 라벨을 반환합니다.
     *
     * DB에서 클래임 사유를 조회하여 다국어 이름을 반환합니다.
     *
     * @return string
     */
    public function getRefundReasonLabel(): string
    {
        $reason = ClaimReason::where('type', 'refund')
            ->where('code', $this->cancel_reason_type)->first();

        return $reason ? $reason->getLocalizedName() : ($this->cancel_reason_type ?? '');
    }
}
