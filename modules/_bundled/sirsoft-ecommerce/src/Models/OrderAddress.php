<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderAddressFactory;

/**
 * 주문 배송지 모델
 */
class OrderAddress extends Model
{
    use HasFactory;

    /**
     * 활동 로그 변경 감지 대상 필드
     *
     * ChangeDetector가 이 필드 목록을 기반으로 변경 전/후를 비교합니다.
     *
     * @var array
     */
    public static array $activityLogFields = [
        'recipient_name' => [
            'label_key' => 'sirsoft-ecommerce::activity_log.fields.recipient_name',
            'type' => 'text',
        ],
        'recipient_phone' => [
            'label_key' => 'sirsoft-ecommerce::activity_log.fields.recipient_phone',
            'type' => 'text',
        ],
        'zipcode' => [
            'label_key' => 'sirsoft-ecommerce::activity_log.fields.zipcode',
            'type' => 'text',
        ],
        'address' => [
            'label_key' => 'sirsoft-ecommerce::activity_log.fields.address',
            'type' => 'text',
        ],
        'address_detail' => [
            'label_key' => 'sirsoft-ecommerce::activity_log.fields.address_detail',
            'type' => 'text',
        ],
        'delivery_memo' => [
            'label_key' => 'sirsoft-ecommerce::activity_log.fields.delivery_memo',
            'type' => 'text',
        ],
    ];

    protected static function newFactory()
    {
        return OrderAddressFactory::new();
    }
    protected $table = 'ecommerce_order_addresses';

    protected $fillable = [
        'order_id',
        'address_type',
        'orderer_name',
        'orderer_phone',
        'orderer_email',
        'recipient_name',
        'recipient_phone',
        'recipient_email',
        'recipient_country_code',
        'recipient_province_code',
        'recipient_city',
        'zipcode',
        'address',
        'address_detail',
        // 해외 배송용 필드
        'address_line_1',
        'address_line_2',
        'intl_city',
        'intl_state',
        'intl_postal_code',
        'address_type_code',
        'delivery_memo',
    ];

    protected $casts = [
        //
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
     * 배송지 여부 확인
     *
     * @return bool 배송지 여부
     */
    public function isShippingAddress(): bool
    {
        return $this->address_type === 'shipping';
    }

    /**
     * 청구지 여부 확인
     *
     * @return bool 청구지 여부
     */
    public function isBillingAddress(): bool
    {
        return $this->address_type === 'billing';
    }

    /**
     * 전체 주소 반환 (국내/해외 구분)
     *
     * @return string 전체 주소
     */
    public function getFullAddress(): string
    {
        if ($this->isDomestic()) {
            $parts = array_filter([
                $this->address,
                $this->address_detail,
            ]);

            return implode(' ', $parts);
        }

        // 해외 주소
        $parts = array_filter([
            $this->address_line_1,
            $this->address_line_2,
            $this->intl_city,
            $this->intl_state,
            $this->intl_postal_code,
        ]);

        return implode(', ', $parts);
    }

    /**
     * 우편번호 포함 전체 주소 반환
     *
     * @return string 우편번호 포함 전체 주소
     */
    public function getFullAddressWithZipcode(): string
    {
        if ($this->isDomestic()) {
            $address = $this->getFullAddress();

            if ($this->zipcode) {
                return sprintf('(%s) %s', $this->zipcode, $address);
            }

            return $address;
        }

        // 해외 주소는 postal_code가 이미 getFullAddress()에 포함됨
        return $this->getFullAddress();
    }

    /**
     * 도로명 주소 여부 확인
     *
     * @return bool 도로명 주소 여부
     */
    public function isRoadAddress(): bool
    {
        return $this->address_type_code === 'R';
    }

    /**
     * 지번 주소 여부 확인
     *
     * @return bool 지번 주소 여부
     */
    public function isJibunAddress(): bool
    {
        return $this->address_type_code === 'J';
    }

    /**
     * 국내 주소 여부 확인
     *
     * @return bool 국내 주소 여부
     */
    public function isDomestic(): bool
    {
        return empty($this->recipient_country_code) || $this->recipient_country_code === 'KR';
    }

    /**
     * 해외 주소 여부 확인
     *
     * @return bool 해외 주소 여부
     */
    public function isInternational(): bool
    {
        return ! $this->isDomestic();
    }
}
