<?php

namespace Modules\Sirsoft\Ecommerce\Http\Resources;

use App\Http\Resources\BaseApiResource;
use Illuminate\Http\Request;

/**
 * 주문 배송지/청구지 리소스
 */
class OrderAddressResource extends BaseApiResource
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
            'address_type' => $this->address_type,
            'orderer_name' => $this->orderer_name,
            'orderer_phone' => $this->orderer_phone,
            'orderer_email' => $this->orderer_email,
            'recipient_name' => $this->recipient_name,
            'recipient_phone' => $this->recipient_phone,
            'recipient_country_code' => $this->recipient_country_code,
            'zipcode' => $this->zipcode,
            'address' => $this->address,
            'address_detail' => $this->address_detail,
            'delivery_memo' => $this->delivery_memo,
            'full_address' => $this->address.($this->address_detail ? ' '.$this->address_detail : ''),
        ];
    }
}
