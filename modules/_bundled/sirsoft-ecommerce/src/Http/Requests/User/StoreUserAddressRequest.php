<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 사용자 배송지 등록 요청
 */
class StoreUserAddressRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청 데이터 전처리
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // is_default: 빈 문자열/null → false 정규화 (DB NOT NULL 제약 보호)
        $this->merge([
            'is_default' => filter_var($this->is_default, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
        ]);
    }

    public function rules(): array
    {
        $rules = [
            // 배송지명
            'name' => ['required', 'string', 'max:100'],

            // 수령인 정보
            'recipient_name' => 'required|string|max:50',
            'recipient_phone' => 'required|string|max:20',

            // 국가 코드
            'country_code' => 'nullable|string|size:2',

            // 국내 배송 주소
            'zipcode' => 'required_without:intl_postal_code|nullable|string|max:10',
            'province_code' => 'nullable|string|max:10',
            'city' => 'nullable|string|max:100',
            'address' => 'required_without:address_line_1|nullable|string|max:255',
            'address_detail' => 'nullable|string|max:255',
            'address_type_code' => 'nullable|string|in:R,J',

            // 해외 배송 주소
            'address_line_1' => 'required_without:address|nullable|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'intl_city' => 'required_with:address_line_1|nullable|string|max:100',
            'intl_state' => 'nullable|string|max:100',
            'intl_postal_code' => 'required_with:address_line_1|nullable|string|max:20',

            // 기본 배송지 여부
            'is_default' => 'nullable|boolean',

            // 동일 배송지명 덮어쓰기 여부
            'force_overwrite' => 'nullable|boolean',
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.user_address.store_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'name.required' => __('sirsoft-ecommerce::validation.user_address.name_required'),
            'recipient_name.required' => __('sirsoft-ecommerce::validation.user_address.recipient_name_required'),
            'recipient_phone.required' => __('sirsoft-ecommerce::validation.user_address.recipient_phone_required'),
            'zipcode.required_without' => __('sirsoft-ecommerce::validation.user_address.zipcode_required'),
            'address.required_without' => __('sirsoft-ecommerce::validation.user_address.address_required'),
            'address_line_1.required_without' => __('sirsoft-ecommerce::validation.user_address.address_line_1_required'),
            'intl_city.required_with' => __('sirsoft-ecommerce::validation.user_address.intl_city_required'),
            'intl_postal_code.required_with' => __('sirsoft-ecommerce::validation.user_address.intl_postal_code_required'),
        ];
    }
}
