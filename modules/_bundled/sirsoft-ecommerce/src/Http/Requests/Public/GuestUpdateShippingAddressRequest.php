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
        return [
            'recipient_name' => ['required', 'string', 'max:50'],
            'recipient_phone' => ['required', 'string', 'max:20'],
            'recipient_tel' => ['nullable', 'string', 'max:20'],
            'country_code' => ['nullable', 'string', 'size:2'],

            // 국내 배송 주소
            'zipcode' => ['required_without:address_line_1', 'nullable', 'string', 'max:10'],
            'address' => ['required_without:address_line_1', 'nullable', 'string', 'max:255'],
            'address_detail' => ['nullable', 'string', 'max:255'],

            // 해외 배송 주소
            'address_line_1' => ['required_without:address', 'nullable', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'intl_city' => ['required_with:address_line_1', 'nullable', 'string', 'max:100'],
            'intl_state' => ['nullable', 'string', 'max:100'],
            'intl_postal_code' => ['required_with:address_line_1', 'nullable', 'string', 'max:20'],

            'delivery_memo' => ['nullable', 'string', 'max:255'],
        ];
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
            'zipcode.required_without' => __('sirsoft-ecommerce::validation.user_address.zipcode_required'),
            'address.required_without' => __('sirsoft-ecommerce::validation.user_address.address_required'),
            'address_line_1.required_without' => __('sirsoft-ecommerce::validation.user_address.address_line_1_required'),
            'intl_city.required_with' => __('sirsoft-ecommerce::validation.user_address.intl_city_required'),
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
