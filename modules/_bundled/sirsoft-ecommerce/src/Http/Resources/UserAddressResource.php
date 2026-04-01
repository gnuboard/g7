<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 사용자 배송지 리소스
 */
class UserAddressResource extends BaseApiResource
{
    /**
     * 리소스를 배열로 변환
     *
     * @param Request $request 요청
     * @return array
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user?->uuid,
            'name' => $this->name,
            'recipient_name' => $this->recipient_name,
            'recipient_phone' => $this->recipient_phone,
            'country_code' => $this->country_code,

            // 국내 배송 주소
            'zipcode' => $this->zipcode,
            'address' => $this->address,
            'address_detail' => $this->address_detail,

            // 해외 배송 주소
            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'city' => $this->city,
            'state' => $this->state,
            'postal_code' => $this->postal_code,

            // 메타 정보
            'is_default' => (bool) $this->is_default,
            'is_domestic' => $this->isDomestic(),
            'is_international' => $this->isInternational(),
            'full_address' => $this->getFullAddress(),

            // 타임스탬프
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // 권한 메타
            'abilities' => [
                'can_update' => true,
                'can_delete' => ! $this->is_default,
                'can_set_default' => ! $this->is_default,
            ],
        ];
    }
}
