<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;

/**
 * 주문 생성 (결제하기) 요청
 *
 * 임시 주문을 실제 주문으로 변환합니다.
 */
class CreateOrderRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array
     */
    public function rules(): array
    {
        $rules = [
            // 주문자 정보
            'orderer.name' => 'required|string|max:50',
            'orderer.phone' => 'required|string|max:20',
            'orderer.email' => 'nullable|email|max:255',

            // 배송지 정보
            'shipping.recipient_name' => 'required|string|max:50',
            'shipping.recipient_phone' => 'required_without:shipping.recipient_tel|nullable|string|max:20',
            'shipping.recipient_tel' => 'required_without:shipping.recipient_phone|nullable|string|max:20',
            'shipping.country_code' => 'nullable|string|size:2',

            // 국내 배송 주소 (국내인 경우 필수)
            'shipping.zipcode' => 'required_without:shipping.intl_postal_code|nullable|string|max:10',
            'shipping.address' => 'required_without:shipping.address_line_1|nullable|string|max:255',
            'shipping.address_detail' => 'required|string|max:255',
            'shipping.address_type_code' => 'nullable|string|in:R,J',

            // 해외 배송 주소 (해외인 경우 필수)
            'shipping.address_line_1' => 'required_without:shipping.address|nullable|string|max:255',
            'shipping.address_line_2' => 'nullable|string|max:255',
            'shipping.intl_city' => 'required_with:shipping.address_line_1|nullable|string|max:100',
            'shipping.intl_state' => 'nullable|string|max:100',
            'shipping.intl_postal_code' => 'required_with:shipping.address_line_1|nullable|string|max:20',

            // 결제 정보
            'payment_method' => ['required', 'string', Rule::in(array_column(PaymentMethodEnum::cases(), 'value'))],
            'expected_total_amount' => 'required|numeric|min:0',

            // 배송 메모
            'shipping_memo' => 'nullable|string|max:500',

            // 무통장입금 (vbank/dbank) 공통
            'depositor_name' => 'required_if:payment_method,vbank|required_if:payment_method,dbank|nullable|string|max:50',

            // 수동 무통장입금 (dbank) 전용
            'dbank.bank_code' => 'required_if:payment_method,dbank|nullable|string|max:10',
            'dbank.bank_name' => 'nullable|string|max:50',
            'dbank.account_number' => 'required_if:payment_method,dbank|nullable|string|max:50',
            'dbank.account_holder' => 'required_if:payment_method,dbank|nullable|string|max:50',
            'dbank.due_days' => 'nullable|integer|min:1|max:30',

            // 배송지 저장
            'save_shipping_address' => 'nullable|boolean',
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.order.create_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            // 주문자 정보
            'orderer.name.required' => __('sirsoft-ecommerce::validation.order.orderer_name_required'),
            'orderer.phone.required' => __('sirsoft-ecommerce::validation.order.orderer_phone_required'),
            'orderer.email.email' => __('sirsoft-ecommerce::validation.order.orderer_email_invalid'),

            // 배송지 정보
            'shipping.recipient_name.required' => __('sirsoft-ecommerce::validation.order.recipient_name_required'),
            'shipping.recipient_phone.required_without' => __('sirsoft-ecommerce::validation.order.recipient_phone_required_without'),
            'shipping.recipient_tel.required_without' => __('sirsoft-ecommerce::validation.order.recipient_tel_required_without'),
            'shipping.zipcode.required_without' => __('sirsoft-ecommerce::validation.order.zipcode_required'),
            'shipping.address.required_without' => __('sirsoft-ecommerce::validation.order.address_required'),
            'shipping.address_detail.required' => __('sirsoft-ecommerce::validation.order.address_detail_required'),
            'shipping.address_line_1.required_without' => __('sirsoft-ecommerce::validation.order.address_line_1_required'),
            'shipping.intl_city.required_with' => __('sirsoft-ecommerce::validation.order.intl_city_required'),
            'shipping.intl_postal_code.required_with' => __('sirsoft-ecommerce::validation.order.intl_postal_code_required'),

            // 결제 정보
            'payment_method.required' => __('sirsoft-ecommerce::validation.order.payment_method_required'),
            'payment_method.in' => __('sirsoft-ecommerce::validation.order.payment_method_invalid'),
            'expected_total_amount.required' => __('sirsoft-ecommerce::validation.order.expected_total_amount_required'),
            'expected_total_amount.numeric' => __('sirsoft-ecommerce::validation.order.expected_total_amount_numeric'),

            // 무통장입금
            'depositor_name.required_if' => __('sirsoft-ecommerce::validation.order.depositor_name_required'),
            'dbank.bank_code.required_if' => __('sirsoft-ecommerce::validation.order.dbank_bank_code_required'),
            'dbank.account_number.required_if' => __('sirsoft-ecommerce::validation.order.dbank_account_number_required'),
            'dbank.account_holder.required_if' => __('sirsoft-ecommerce::validation.order.dbank_account_holder_required'),
        ];
    }

    /**
     * 주문자 정보 반환
     *
     * @return array
     */
    public function getOrdererInfo(): array
    {
        $orderer = $this->input('orderer', []);

        return [
            'name' => $orderer['name'] ?? '',
            'phone' => $orderer['phone'] ?? '',
            'email' => $orderer['email'] ?? '',
        ];
    }

    /**
     * 배송지 정보 반환
     *
     * @return array
     */
    public function getShippingInfo(): array
    {
        return $this->input('shipping', []);
    }

    /**
     * 무통장 수동입금 정보 반환
     *
     * @return array|null
     */
    public function getDbankInfo(): ?array
    {
        if ($this->input('payment_method') !== 'dbank') {
            return null;
        }

        return $this->input('dbank');
    }
}
