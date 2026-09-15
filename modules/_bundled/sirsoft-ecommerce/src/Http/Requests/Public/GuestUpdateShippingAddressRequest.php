<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use App\Helpers\ResponseHelper;
use Illuminate\Foundation\Http\FormRequest;
use Modules\Sirsoft\Ecommerce\Models\Order;

/**
 * 비회원 주문 배송지 수정 요청
 *
 * 주문 소유권은 VerifyGuestOrderToken 미들웨어가 검증한다. 비회원은 저장된
 * 회원 주소(address_id)를 사용할 수 없으므로 배송지 필드를 직접 입력받는다.
 */
class GuestUpdateShippingAddressRequest extends FormRequest
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // country_code 가 국내/해외를 가르는 기준축이다. 종전에는 zipcode/address 를
        // address_line_1 유무로만 강제해, 해외 필드만 채우고 country_code 를 생략하면
        // 검증을 통과한 뒤 서비스가 국내 분기로 떨어져 NOT NULL 컬럼에 null 을 쓰려다
        // SQL 무결성 위반이 났다. 서비스의 폴백(?? 'KR')과 동일한 기준으로 판정한다.
        $isDomestic = $this->isDomesticShipping();

        return [
            'recipient_name' => ['required', 'string', 'max:50'],
            'recipient_phone' => ['required', 'string', 'max:20'],
            'recipient_tel' => ['nullable', 'string', 'max:20'],
            'country_code' => ['nullable', 'string', 'size:2'],

            // 국내 배송 주소
            'zipcode' => [$isDomestic ? 'required' : 'nullable', 'string', 'max:10'],
            'address' => [$isDomestic ? 'required' : 'nullable', 'string', 'max:255'],
            'address_detail' => ['nullable', 'string', 'max:255'],

            // 해외 배송 주소
            'address_line_1' => [$isDomestic ? 'nullable' : 'required', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'intl_city' => [$isDomestic ? 'nullable' : 'required', 'string', 'max:100'],
            'intl_state' => ['nullable', 'string', 'max:100'],
            'intl_postal_code' => [$isDomestic ? 'nullable' : 'required', 'string', 'max:20'],

            'delivery_memo' => ['nullable', 'string', 'max:255'],
        ];
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
     * @return array<string, string>
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

    /**
     * 미들웨어가 검증한 대상 주문을 반환합니다.
     *
     * @return Order 토큰 검증을 통과한 비회원 주문
     */
    public function getOrder(): Order
    {
        $order = $this->attributes->get('guest_order');

        if (! $order instanceof Order) {
            abort(ResponseHelper::moduleError('sirsoft-ecommerce', 'exceptions.order_not_found', 404));
        }

        return $order;
    }
}
