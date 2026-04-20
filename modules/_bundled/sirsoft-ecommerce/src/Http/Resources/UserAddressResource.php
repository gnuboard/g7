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
     * 비즈니스 로직 기반 abilities를 반환합니다.
     * user-addresses 권한 식별자가 모듈 매니페스트에 없으므로, abilityMap()은 빈 배열.
     * 인증된 사용자는 자신의 주소를 수정 가능하며, 기본 배송지는 삭제/변경 불가.
     *
     * @param Request $request
     * @return array
     */
    protected function resolveAbilities(Request $request): array
    {
        $abilities = parent::resolveAbilities($request);
        $abilities['can_update'] = true;
        $abilities['can_delete'] = ! $this->is_default;
        $abilities['can_set_default'] = ! $this->is_default;

        return $abilities;
    }

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

            ...$this->formatTimestamps(),
            ...$this->resourceMeta($request),
        ];
    }
}
