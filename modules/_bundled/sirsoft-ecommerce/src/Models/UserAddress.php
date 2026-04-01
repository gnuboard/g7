<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sirsoft\Ecommerce\Database\Factories\UserAddressFactory;

/**
 * 사용자 저장 배송지 모델
 */
class UserAddress extends Model
{
    use HasFactory;

    /**
     * 팩토리 클래스 반환
     *
     * @return UserAddressFactory
     */
    protected static function newFactory(): UserAddressFactory
    {
        return UserAddressFactory::new();
    }

    protected $table = 'ecommerce_user_addresses';

    protected $fillable = [
        'user_id',
        'name',
        'recipient_name',
        'recipient_phone',
        'country_code',
        // 국내 배송용 (country_code = 'KR')
        'zipcode',
        'address',
        'address_detail',
        // 해외 배송용 (country_code != 'KR')
        'address_line_1',
        'address_line_2',
        'city',
        'state',
        'postal_code',
        // 기본 배송지 여부
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    /**
     * 사용자 관계
     *
     * @return BelongsTo 사용자 모델과의 관계
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 기본 배송지 스코프
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * 특정 국가 배송지 스코프
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $countryCode 국가 코드 (예: 'KR', 'US')
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByCountry($query, string $countryCode)
    {
        return $query->where('country_code', $countryCode);
    }

    /**
     * 국내 배송지 스코프
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeDomestic($query)
    {
        return $query->where('country_code', 'KR');
    }

    /**
     * 해외 배송지 스코프
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeInternational($query)
    {
        return $query->where('country_code', '!=', 'KR');
    }

    /**
     * 국내 배송지 여부 확인
     *
     * @return bool 국내 배송지 여부
     */
    public function isDomestic(): bool
    {
        return $this->country_code === 'KR';
    }

    /**
     * 해외 배송지 여부 확인
     *
     * @return bool 해외 배송지 여부
     */
    public function isInternational(): bool
    {
        return ! $this->isDomestic();
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
            $this->city,
            $this->state,
            $this->postal_code,
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

        // 해외 주소는 postal_code가 이미 포함됨
        return $this->getFullAddress();
    }

    /**
     * 주문 배송지로 변환
     *
     * @return array OrderAddress 생성용 데이터
     */
    public function toOrderAddressData(): array
    {
        $data = [
            'address_type' => 'shipping',
            'recipient_name' => $this->recipient_name,
            'recipient_phone' => $this->recipient_phone,
            'recipient_country_code' => $this->country_code,
        ];

        if ($this->isDomestic()) {
            $data['zipcode'] = $this->zipcode;
            $data['address'] = $this->address;
            $data['address_detail'] = $this->address_detail;
        } else {
            $data['address_line_1'] = $this->address_line_1;
            $data['address_line_2'] = $this->address_line_2;
            $data['intl_city'] = $this->city;
            $data['intl_state'] = $this->state;
            $data['intl_postal_code'] = $this->postal_code;
        }

        return $data;
    }
}
