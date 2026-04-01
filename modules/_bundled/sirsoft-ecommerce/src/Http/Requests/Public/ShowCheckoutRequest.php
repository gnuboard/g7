<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

/**
 * 체크아웃 조회 요청
 *
 * 배송 주소 파라미터 검증과 cart_key 헤더 검증을 포함합니다.
 */
class ShowCheckoutRequest extends CartKeyRequest
{
    /**
     * cart_key 검증 규칙
     *
     * @return array
     */
    protected function cartKeyRules(): array
    {
        return [
            'country_code' => 'nullable|string|size:2',
            'zipcode' => 'nullable|string|max:20',
            'region' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'address' => 'nullable|string|max:255',
        ];
    }

    /**
     * 훅 필터 이름
     *
     * @return string
     */
    protected function hookFilterName(): string
    {
        return 'sirsoft-ecommerce.checkout.show_validation_rules';
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'country_code.size' => __('sirsoft-ecommerce::validation.checkout.country_code_size'),
            'zipcode.max' => __('sirsoft-ecommerce::validation.checkout.zipcode_max'),
            'region.max' => __('sirsoft-ecommerce::validation.checkout.region_max'),
            'city.max' => __('sirsoft-ecommerce::validation.checkout.city_max'),
            'address.max' => __('sirsoft-ecommerce::validation.checkout.address_max'),
        ]);
    }
}
