<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\UserAddress;

/**
 * 주문 배송지 변경 요청
 */
class UpdateOrderShippingAddressRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            // 저장된 배송지 선택 (address_id가 있으면 다른 필드 불필요)
            'address_id' => ['nullable', 'integer', Rule::exists(UserAddress::class, 'id')->where('user_id', Auth::id())],

            // 수령인 정보
            'recipient_name' => 'required_without:address_id|string|max:50',
            'recipient_phone' => 'required_without:address_id|string|max:20',

            // 국가 코드
            'country_code' => 'nullable|string|size:2',

            // 국내 배송 주소
            'zipcode' => 'required_without_all:address_id,address_line_1|nullable|string|max:10',
            'address' => 'required_without_all:address_id,address_line_1|nullable|string|max:255',
            'address_detail' => 'nullable|string|max:255',

            // 해외 배송 주소
            'address_line_1' => 'required_without_all:address_id,address|nullable|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'intl_city' => 'required_with:address_line_1|nullable|string|max:100',
            'intl_state' => 'nullable|string|max:100',
            'intl_postal_code' => 'required_with:address_line_1|nullable|string|max:20',

            // 배송 메모
            'delivery_memo' => 'nullable|string|max:255',
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.order.shipping_address_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array
     */
    public function messages(): array
    {
        return [
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
