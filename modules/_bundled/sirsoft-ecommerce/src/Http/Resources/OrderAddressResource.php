<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;
use Modules\Sirsoft\Ecommerce\Http\Resources\Concerns\LocalizesCountryName;

/**
 * 주문 배송지/청구지 리소스
 */
class OrderAddressResource extends BaseApiResource
{
    use LocalizesCountryName;

    /**
     * 리소스를 배열로 변환
     *
     * @param  Request  $request  요청
     * @return array 주소 리소스 배열 (국가명·국내/해외 필드 포함)
     */
    public function toArray(Request $request): array
    {
        $isDomestic = empty($this->recipient_country_code) || strtoupper((string) $this->recipient_country_code) === 'KR';

        return [
            'id' => $this->id,
            'address_type' => $this->address_type,
            'orderer_name' => $this->orderer_name,
            'orderer_phone' => $this->orderer_phone,
            'orderer_email' => $this->orderer_email,
            'recipient_name' => $this->recipient_name,
            'recipient_phone' => $this->recipient_phone,
            // 국가 (항상 표시 — D9)
            'recipient_country_code' => $this->recipient_country_code,
            'recipient_country_name' => $this->getCountryLocalizedName($this->recipient_country_code),
            'is_domestic' => $isDomestic,
            // 국내(KR) 필드
            'zipcode' => $this->zipcode,
            'address' => $this->address,
            'address_detail' => $this->address_detail,
            // 해외 배송 필드
            'address_line_1' => $this->address_line_1,
            'address_line_2' => $this->address_line_2,
            'intl_city' => $this->intl_city,
            'intl_state' => $this->intl_state,
            'intl_postal_code' => $this->intl_postal_code,
            'delivery_memo' => $this->delivery_memo,
            'delivery_memo_label' => $this->delivery_memo_label,
            'full_address' => $isDomestic
                ? $this->address.($this->address_detail ? ' '.$this->address_detail : '')
                : trim(implode(' ', array_filter([
                    $this->address_line_1,
                    $this->address_line_2,
                    $this->intl_city,
                    $this->intl_state,
                    $this->intl_postal_code,
                ]))),
        ];
    }
}
