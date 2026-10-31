<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\CancelTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;

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
        'shipping_snapshot',
        'cancelled_by',
        'cancelled_at',
        'reactivated_quantity',
        'reactivated_at',
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
            'shipping_snapshot' => 'array',
            'cancelled_at' => 'datetime',
            'reactivated_quantity' => 'integer',
            'reactivated_at' => 'datetime',
        ];
    }

    /**
     * 이 취소로 빠진 상품 수량 합을 반환합니다. (취소 시점 스냅샷 기준)
     *
     * @return int 취소 수량 합
     */
    public function totalCancelQuantity(): int
    {
        return (int) collect($this->items_snapshot ?? [])->sum(fn ($item) => (int) ($item['quantity'] ?? 0));
    }

    /**
     * 이 취소의 상품이 재활성된 적이 있는지 반환합니다. (Q29)
     *
     * @return bool 재활성 기록이 있으면 true
     */
    public function isReactivated(): bool
    {
        return $this->reactivated_at !== null;
    }

    /**
     * 이 취소의 상품 중 일부만 재활성됐는지 반환합니다. (Q29)
     *
     * @return bool 재활성 수량이 취소 수량보다 적으면 true
     */
    public function isPartiallyReactivated(): bool
    {
        return $this->isReactivated() && (int) $this->reactivated_quantity < $this->totalCancelQuantity();
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
     * 취소 옵션 목록과의 관계
     *
     * @return HasMany 취소 옵션 목록 관계
     */
    public function cancelOptions(): HasMany
    {
        return $this->hasMany(OrderCancelOption::class, 'order_cancel_id');
    }

    /**
     * 환불 레코드와의 관계
     *
     * @return HasOne 연결된 환불 레코드 관계
     */
    public function refund(): HasOne
    {
        return $this->hasOne(OrderRefund::class, 'order_cancel_id');
    }

    /**
     * 취소 요청자와의 관계
     *
     * @return BelongsTo 취소 요청 사용자 관계
     */
    public function cancelledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * 이 취소 문서가 **비회원 주문**의 것인지 반환합니다.
     *
     * 판정은 **주문의 주인**이 한다. `cancelled_by` 는 비회원 신청·운영자 대리 접수·자동 취소가
     * 모두 null 이라(§4-3-1 과 같은 모호성), 그 값으로 가르면 회원 주문의 취소가 「비회원」으로
     * 표시되고 운영자는 연락 수단을 잘못 고른다. 네 유형이 같은 축을 쓰게 한다.
     *
     * @return bool 비회원 주문이면 true
     */
    public function isGuestRequest(): bool
    {
        $order = $this->relationLoaded('order') ? $this->order : $this->order()->first();

        if ($order === null) {
            return $this->cancelled_by === null;
        }

        return $order->user_id === null;
    }

    /**
     * 전체취소 여부를 반환합니다.
     *
     * @return bool 전체취소이면 true
     */
    public function isFullCancel(): bool
    {
        return $this->cancel_type === CancelTypeEnum::FULL;
    }

    /**
     * 취소 완료 여부를 반환합니다.
     *
     * @return bool 취소 완료 상태이면 true
     */
    public function isCompleted(): bool
    {
        return $this->cancel_status === CancelStatusEnum::COMPLETED;
    }

    /**
     * 이 취소 이력의 사유 코드가 등록된 카탈로그 유형을 반환합니다. (사유 라벨 해석의 단일 출처)
     *
     * 환불 실행이 남긴 취소 이력은 환불신청서의 사유 코드를 물려받는다. 반품·교환에서 파생된
     * 환불이면 그 코드는 반품·교환 카탈로그에만 있으므로(`defective_product` 등), 환불 카탈로그만
     * 보면 원시 코드가 화면에 나온다. 판정은 연결된 환불신청서의 `reasonCatalogType()` 에 맡기고,
     * 연결이 없으면(직접 취소) 환불 카탈로그가 정본이다.
     *
     * @return string 카탈로그 유형 값
     */
    public function reasonCatalogType(): string
    {
        return $this->refund?->reasonCatalogType() ?? ClaimTypeEnum::REFUND->value;
    }

    /**
     * 환불 사유 라벨을 반환합니다.
     *
     * DB에서 클레임 사유를 조회하여 다국어 이름을 반환합니다.
     *
     * @return string 현지화된 환불 사유 라벨
     */
    public function getRefundReasonLabel(): string
    {
        $reason = ClaimReason::where('type', $this->reasonCatalogType())
            ->where('code', $this->cancel_reason_type)->first();

        return $reason ? $reason->getLocalizedName() : ($this->cancel_reason_type ?? '');
    }

    /**
     * 통합 클레임 유형 (취소 이력은 클레임 목록·상세에서 `cancel` 로 노출된다)
     *
     * @return ClaimTypeEnum 클레임 유형
     */
    public function claimType(): ClaimTypeEnum
    {
        return ClaimTypeEnum::CANCEL;
    }

    /**
     * 최종(터미널) 상태 여부를 반환합니다.
     *
     * @return bool 최종 상태이면 true
     */
    public function isFinal(): bool
    {
        return $this->cancel_status->isFinal();
    }
}
