<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderShippingFactory;
use Modules\Sirsoft\Ecommerce\Enums\ShippingStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ShippingTypeEnum;

/**
 * 주문 배송 모델
 */
class OrderShipping extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return OrderShippingFactory::new();
    }
    protected $table = 'ecommerce_order_shippings';

    protected $fillable = [
        'order_id',
        'order_option_id',
        'shipping_policy_id',
        'shipping_status',
        'shipping_type',
        'base_shipping_amount',
        'extra_shipping_amount',
        'total_shipping_amount',
        'shipping_discount_amount',
        'is_remote_area',
        'carrier_id',
        'tracking_number',
        'return_shipping_amount',
        'return_carrier_id',
        'return_tracking_number',
        'exchange_carrier_id',
        'exchange_tracking_number',
        'package_number',
        'visit_date',
        'visit_time_slot',
        'actual_weight',
        'delivery_policy_snapshot',
        'currency_snapshot',
        'shipped_at',
        'estimated_arrival_at',
        'delivered_at',
        'confirmed_at',
        // 다중 통화 컬럼 (JSON)
        'mc_base_shipping_amount',
        'mc_extra_shipping_amount',
        'mc_total_shipping_amount',
        'mc_shipping_discount_amount',
        'mc_return_shipping_amount',
    ];

    protected $casts = [
        'base_shipping_amount' => 'decimal:2',
        'extra_shipping_amount' => 'decimal:2',
        'total_shipping_amount' => 'decimal:2',
        'shipping_discount_amount' => 'decimal:2',
        'is_remote_area' => 'boolean',
        'return_shipping_amount' => 'decimal:2',
        'actual_weight' => 'decimal:3',
        'visit_date' => 'date',
        'delivery_policy_snapshot' => 'array',
        'currency_snapshot' => 'array',
        'shipped_at' => 'datetime',
        'estimated_arrival_at' => 'datetime',
        'delivered_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'shipping_status' => ShippingStatusEnum::class,
        'shipping_type' => ShippingTypeEnum::class,
        // 다중 통화 컬럼 (JSON)
        'mc_base_shipping_amount' => 'array',
        'mc_extra_shipping_amount' => 'array',
        'mc_total_shipping_amount' => 'array',
        'mc_shipping_discount_amount' => 'array',
        'mc_return_shipping_amount' => 'array',
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
     * 주문 옵션 관계
     *
     * @return BelongsTo 주문 옵션 모델과의 관계
     */
    public function orderOption(): BelongsTo
    {
        return $this->belongsTo(OrderOption::class, 'order_option_id');
    }

    /**
     * 배송 정책 관계
     *
     * @return BelongsTo 배송 정책 모델과의 관계
     */
    public function shippingPolicy(): BelongsTo
    {
        return $this->belongsTo(ShippingPolicy::class, 'shipping_policy_id');
    }

    /**
     * 배송사 관계
     *
     * @return BelongsTo 배송사 모델과의 관계
     */
    public function carrier(): BelongsTo
    {
        return $this->belongsTo(ShippingCarrier::class, 'carrier_id');
    }

    /**
     * 발송 완료 여부 확인
     *
     * @return bool 발송 완료 여부
     */
    public function isShipped(): bool
    {
        return $this->shipped_at !== null;
    }

    /**
     * 배송 완료 여부 확인
     *
     * @return bool 배송 완료 여부
     */
    public function isDelivered(): bool
    {
        return $this->delivered_at !== null;
    }

    /**
     * 구매 확정 여부 확인
     *
     * @return bool 구매 확정 여부
     */
    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /**
     * 배송 추적 URL 생성
     *
     * @return string|null 배송 추적 URL (배송사 또는 운송장 번호 미설정 시 null)
     */
    public function getTrackingUrl(): ?string
    {
        if (! $this->tracking_number || ! $this->carrier_id) {
            return null;
        }

        $carrier = $this->carrier;

        if (! $carrier) {
            return null;
        }

        return $carrier->buildTrackingUrl($this->tracking_number);
    }

    /**
     * 국내 배송 여부 확인
     *
     * @return bool 국내 배송 여부
     */
    public function isDomestic(): bool
    {
        return in_array($this->shipping_type, [
            ShippingTypeEnum::DOMESTIC_PARCEL,
            ShippingTypeEnum::DOMESTIC_EXPRESS,
            ShippingTypeEnum::DOMESTIC_QUICK,
        ]);
    }

    /**
     * 해외 배송 여부 확인
     *
     * @return bool 해외 배송 여부
     */
    public function isInternational(): bool
    {
        return in_array($this->shipping_type, [
            ShippingTypeEnum::INTERNATIONAL_EMS,
            ShippingTypeEnum::INTERNATIONAL_STANDARD,
        ]);
    }

    /**
     * 방문 수령 여부 확인
     *
     * @return bool 방문 수령 여부
     */
    public function isPickup(): bool
    {
        return $this->shipping_type === ShippingTypeEnum::PICKUP;
    }

    /**
     * 합포장 여부 확인
     *
     * @return bool 합포장 여부
     */
    public function isPackaged(): bool
    {
        return ! empty($this->package_number);
    }

    /**
     * 반품 배송 정보 존재 여부 확인
     *
     * @return bool 반품 배송 정보 존재 여부
     */
    public function hasReturnShipping(): bool
    {
        return ! empty($this->return_tracking_number);
    }

    /**
     * 교환 배송 정보 존재 여부 확인
     *
     * @return bool 교환 배송 정보 존재 여부
     */
    public function hasExchangeShipping(): bool
    {
        return ! empty($this->exchange_tracking_number);
    }
}
