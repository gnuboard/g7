<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Services\ShippingCountryResolver;

/**
 * 관리자 회원 배송국가 변경 요청 검증 (MP08 후속)
 *
 * 권한은 라우트의 permission:admin,sirsoft-ecommerce.user-shipping-country.manage 미들웨어가
 * 담당한다. 활성 배송가능 국가(available_countries[].is_active) 만 허용한다.
 */
class UpdateAdminUserShippingCountryRequest extends FormRequest
{
    /**
     * 권한 체크는 permission 미들웨어에서 수행됩니다.
     *
     * @return bool 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 검증 규칙
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'shipping_country' => [
                'required',
                'string',
                'size:2',
                Rule::in(app(ShippingCountryResolver::class)->allowedShippingCountryCodes()),
            ],
        ];
    }

    /**
     * 검증 전 국가 코드를 대문자로 정규화합니다.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('shipping_country')) {
            $this->merge(['shipping_country' => strtoupper((string) $this->input('shipping_country'))]);
        }
    }

    /**
     * 검증 메시지
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shipping_country.required' => __('sirsoft-ecommerce::validation.custom.user_shipping_country.required'),
            'shipping_country.in' => __('sirsoft-ecommerce::validation.custom.user_shipping_country.invalid'),
            'shipping_country.size' => __('sirsoft-ecommerce::validation.custom.user_shipping_country.invalid'),
        ];
    }
}
