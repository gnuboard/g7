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

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed> 검증 규칙 배열
     */
    public function rules(): array
    {
        // country_code 가 국내/해외를 가르는 기준축이다. 종전에는 zipcode/address 를
        // address_line_1 유무로만 강제해, 해외 필드만 채우고 country_code 를 생략하면
        // 검증을 통과한 뒤 서비스가 국내 분기로 떨어져 NOT NULL 컬럼에 null 을 쓰려다
        // SQL 무결성 위반이 났다. 서비스의 폴백(?? 'KR')과 동일한 기준으로 판정한다.
        // 저장된 배송지(address_id) 선택 시에는 직접 입력 필드를 요구하지 않는다.
        $hasAddressId = $this->filled('address_id');
        $isDomestic = $this->isDomesticShipping();

        $domesticRule = (! $hasAddressId && $isDomestic) ? 'required' : 'nullable';
        $intlRule = (! $hasAddressId && ! $isDomestic) ? 'required' : 'nullable';

        $rules = [
            // 저장된 배송지 선택 (address_id가 있으면 다른 필드 불필요)
            'address_id' => ['nullable', 'integer', Rule::exists(UserAddress::class, 'id')->where('user_id', Auth::id())],

            // 수령인 정보
            'recipient_name' => 'required_without:address_id|string|max:50',
            'recipient_phone' => 'required_without:address_id|string|max:20',

            // 국가 코드
            'country_code' => 'nullable|string|size:2',

            // 국내 배송 주소
            'zipcode' => $domesticRule.'|string|max:10',
            'address' => $domesticRule.'|string|max:255',
            'address_detail' => 'nullable|string|max:255',

            // 해외 배송 주소
            'address_line_1' => $intlRule.'|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'intl_city' => $intlRule.'|string|max:100',
            'intl_state' => 'nullable|string|max:100',
            'intl_postal_code' => $intlRule.'|string|max:20',

            // 배송 메모
            'delivery_memo' => 'nullable|string|max:255',
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.order.shipping_address_validation_rules', $rules, $this);
    }

    /**
     * 국내 배송 여부를 판정합니다.
     *
     * country_code 미전송/빈값은 국내로 간주한다 (OrderService 의 'KR' 폴백과 동일 기준).
     *
     * @return bool 국내 배송이면 true
     */
    private function isDomesticShipping(): bool
    {
        $country = strtoupper(trim((string) $this->input('country_code', '')));

        return $country === '' || $country === 'KR';
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
            'zipcode.required' => __('sirsoft-ecommerce::validation.user_address.zipcode_required'),
            'address.required' => __('sirsoft-ecommerce::validation.user_address.address_required'),
            'address_line_1.required' => __('sirsoft-ecommerce::validation.user_address.address_line_1_required'),
            'intl_city.required' => __('sirsoft-ecommerce::validation.user_address.intl_city_required'),
            'intl_postal_code.required' => __('sirsoft-ecommerce::validation.user_address.intl_postal_code_required'),
        ];
    }
}
