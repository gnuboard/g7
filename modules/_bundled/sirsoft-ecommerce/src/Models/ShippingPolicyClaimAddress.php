<?php

namespace Modules\Sirsoft\Ecommerce\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Sirsoft\Ecommerce\Database\Factories\ShippingPolicyClaimAddressFactory;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;

/**
 * 배송정책별 클레임 회수지 모델
 *
 * 배송정책 × 클레임 유형(교환/반품)마다 회수지를 하나씩 둔다. 신청 시점에
 * 클레임 헤더의 return_address_snapshot 으로 복사되므로 이후 정책이 바뀌어도
 * 이미 접수된 건의 회수지는 흔들리지 않는다.
 */
class ShippingPolicyClaimAddress extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return ShippingPolicyClaimAddressFactory::new();
    }

    /**
     * 테이블명
     *
     * @var string
     */
    protected $table = 'ecommerce_shipping_policy_claim_addresses';

    /**
     * 대량 할당 가능 필드
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'shipping_policy_id',
        'claim_type',
        'recipient_name',
        'phone',
        'zipcode',
        'address1',
        'address2',
        'country_code',
    ];

    /**
     * 타입 캐스팅
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shipping_policy_id' => 'integer',
            'claim_type' => ClaimTypeEnum::class,
        ];
    }

    /**
     * 배송정책과의 관계
     *
     * @return BelongsTo 소속 배송정책 관계
     */
    public function shippingPolicy(): BelongsTo
    {
        return $this->belongsTo(ShippingPolicy::class, 'shipping_policy_id');
    }

    /**
     * 클레임 헤더에 동결할 주소 스냅샷 배열을 반환합니다.
     *
     * @return array<string, string|null> 회수지 스냅샷
     */
    public function toSnapshot(): array
    {
        return [
            'recipient_name' => $this->recipient_name,
            'phone' => $this->phone,
            'zipcode' => $this->zipcode,
            'address1' => $this->address1,
            'address2' => $this->address2,
            'country_code' => $this->country_code,
        ];
    }

    /**
     * 특정 클레임 유형의 회수지만 조회하는 스코프
     *
     * @param  Builder  $query  쿼리 빌더
     * @param  string|ClaimTypeEnum  $type  클레임 유형
     * @return Builder 필터가 적용된 쿼리 빌더
     */
    public function scopeOfClaimType($query, string|ClaimTypeEnum $type)
    {
        $value = $type instanceof ClaimTypeEnum ? $type->value : $type;

        return $query->where('claim_type', $value);
    }
}
