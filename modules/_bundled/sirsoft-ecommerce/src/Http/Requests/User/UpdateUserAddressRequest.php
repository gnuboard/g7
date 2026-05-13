<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 사용자 배송지 수정 요청
 */
class UpdateUserAddressRequest extends FormRequest
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
        if ($this->has('is_default')) {
            $this->merge([
                'is_default' => filter_var($this->is_default, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            ]);
        }
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array
     */
    public function rules(): array
    {
        $rules = [
            // 배송지명
            'name' => ['sometimes', 'string', 'max:100'],

            // 수령인 정보
            'recipient_name' => 'sometimes|string|max:50',
            'recipient_phone' => 'sometimes|string|max:20',

            // 국가 코드
            'country_code' => 'nullable|string|size:2',

            // 국내 배송 주소
            'zipcode' => 'nullable|string|max:10',
            'province_code' => 'nullable|string|max:10',
            'city' => 'nullable|string|max:100',
            'address' => 'nullable|string|max:255',
            'address_detail' => 'nullable|string|max:255',
            'address_type_code' => 'nullable|string|in:R,J',

            // 해외 배송 주소
            'address_line_1' => 'nullable|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'intl_city' => 'nullable|string|max:100',
            'intl_state' => 'nullable|string|max:100',
            'intl_postal_code' => 'nullable|string|max:20',

            // 기본 배송지 여부
            'is_default' => 'nullable|boolean',
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.user_address.update_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'name.string' => __('sirsoft-ecommerce::validation.user_address.name_string'),
            'recipient_name.string' => __('sirsoft-ecommerce::validation.user_address.recipient_name_string'),
            'recipient_phone.string' => __('sirsoft-ecommerce::validation.user_address.recipient_phone_string'),
        ];
    }
}
