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
     *
     * @return bool 항상 true (권한은 미들웨어 체인에서 처리)
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
        $rules = [
            // 주문자 정보 (이메일은 회원/비회원 분기 — getOrdererEmailRules)
            'orderer.name' => 'required|string|max:50',
            'orderer.phone' => 'required|string|max:20',

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

        // 주문자 이메일은 회원/비회원 분기 (비회원은 알림 수신 통로가 이메일뿐 → 필수)
        $rules = array_merge($rules, $this->getOrdererEmailRules());

        // 비회원 주문일 때만 조회 비밀번호 규칙 추가 (회원은 미요구)
        $rules = array_merge($rules, $this->getGuestLookupRules());

        return HookManager::applyFilters('sirsoft-ecommerce.order.create_validation_rules', $rules, $this);
    }

    /**
     * 주문자 이메일 검증 규칙을 반환합니다.
     *
     * 회원은 가입 시점에 이메일을 보유하고 주문서에서 자동 채워지므로 nullable 로 유지하고,
     * 비회원은 주문 확인/배송/취소 알림을 받을 통로가 주문자 이메일뿐이므로 required 로 강제합니다.
     *
     * @return array<string, mixed>
     */
    protected function getOrdererEmailRules(): array
    {
        // 로그인 사용자(회원)는 이메일 자동 채움 → 형식만 검증
        if ($this->user()) {
            return ['orderer.email' => ['nullable', 'email', 'max:255']];
        }

        // 비회원 주문 → 알림 수신 통로 확보를 위해 이메일 필수
        return ['orderer.email' => ['required', 'email', 'max:255']];
    }

    /**
     * 비회원 주문 조회 비밀번호 검증 규칙을 반환합니다.
     *
     * 로그인 사용자(회원)는 조회 비밀번호가 필요 없으므로 nullable 로 유지하고,
     * 비로그인 사용자(비회원)에게만 8자 이상 + 확인 일치를 강제합니다 (G7 회원가입 정책과 일치).
     * 실제 해시 저장은 후속 단계(주문 생성 시점)에서 처리합니다.
     *
     * @return array<string, mixed>
     */
    protected function getGuestLookupRules(): array
    {
        // 로그인 사용자는 회원 주문 → 조회 비밀번호 미요구
        if ($this->user()) {
            return [
                'guest_lookup_password' => ['nullable'],
                'guest_lookup_password_confirmation' => ['nullable'],
            ];
        }

        // 비회원 주문 → 8자 이상, 확인 일치 필수 (G7 회원가입 정책과 일치 — RegisterRequest 의 min:8|confirmed)
        return [
            'guest_lookup_password' => [
                'required',
                'string',
                'min:8',
                'max:255',
                'confirmed',
            ],
            'guest_lookup_password_confirmation' => ['required', 'string'],
        ];
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 검증 메시지 배열
     */
    public function messages(): array
    {
        return [
            // 주문자 정보
            'orderer.name.required' => __('sirsoft-ecommerce::validation.order.orderer_name_required'),
            'orderer.phone.required' => __('sirsoft-ecommerce::validation.order.orderer_phone_required'),
            'orderer.email.required' => __('sirsoft-ecommerce::validation.order.orderer_email_required'),
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

            // 비회원 조회 비밀번호
            'guest_lookup_password.required' => __('sirsoft-ecommerce::validation.order.guest_lookup_password_required'),
            'guest_lookup_password.min' => __('sirsoft-ecommerce::validation.order.guest_lookup_password_min'),
            'guest_lookup_password.confirmed' => __('sirsoft-ecommerce::validation.order.guest_lookup_password_confirmed'),
            'guest_lookup_password_confirmation.required' => __('sirsoft-ecommerce::validation.order.guest_lookup_password_confirmation_required'),
        ];
    }

    /**
     * 주문자 정보 반환
     *
     * @return array{name: string, phone: string, email: string} 주문자 정보
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
     * @return array<string, mixed> 배송지 입력값 배열
     */
    public function getShippingInfo(): array
    {
        return $this->input('shipping', []);
    }

    /**
     * 무통장 수동입금 정보 반환
     *
     * @return array<string, mixed>|null dbank 결제 시 입금 정보, 그 외 null
     */
    public function getDbankInfo(): ?array
    {
        if ($this->input('payment_method') !== PaymentMethodEnum::DBANK->value) {
            return null;
        }

        return $this->input('dbank');
    }

    /**
     * 비회원 주문 조회 비밀번호 반환 (회원 주문이면 null)
     *
     * 평문 비밀번호이며, 주문 생성 시점에 해시로 변환해 저장합니다.
     * 응답/로그에 그대로 노출하지 않습니다.
     *
     * @return string|null 비회원 조회 비밀번호 (회원이면 null)
     */
    public function getGuestLookupPassword(): ?string
    {
        if ($this->user()) {
            return null;
        }

        $password = $this->input('guest_lookup_password');

        return is_string($password) && $password !== '' ? $password : null;
    }
}
