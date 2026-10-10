<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 배송정책 클레임 회수지 리소스
 *
 * 배송정책 × 클레임 유형(교환/반품)당 1건인 회수지의 표시 표현이다. 신청 시점 스냅샷
 * (`return_address_snapshot`)의 원본이 되는 현재 설정값을 그대로 싣는다.
 */
class ShippingPolicyClaimAddressResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array<string, mixed> 회수지 리소스 배열
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'claim_type' => $this->claim_type?->value,
            'recipient_name' => $this->recipient_name,
            'phone' => $this->phone,
            'zipcode' => $this->zipcode,
            'address1' => $this->address1,
            'address2' => $this->address2,
            'country_code' => $this->country_code,
        ];
    }
}
