<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Resources\Concerns\LocalizesCountryName;

/**
 * 사용자 배송지 리소스
 */
class UserAddressResource extends BaseApiResource
{
    use LocalizesCountryName;

    /**
     * 비즈니스 로직 기반 abilities를 반환합니다.
     * 회원 본인 주소록 응답이라 관리자 권한(`user-addresses.read`·`user-addresses.manage` — 관리자 배송지 목록 전용)이 아니라
     * 소유·기본 배송지 여부로 판정하므로 abilityMap()은 빈 배열이다.
     * 인증된 사용자는 자신의 주소를 수정 가능하며, 기본 배송지는 삭제/변경 불가.
     *
     * @param  Request  $request  HTTP 요청 객체
     * @return array<string, bool> 능력 불리언 맵
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
     * @param  Request  $request  요청
     * @return array 회원 주소록 리소스 배열 (국가명·국내/해외 필드 포함)
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
            'country_name' => $this->getCountryLocalizedName($this->country_code),

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
