<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 주문 이메일 발송 요청
 */
class SendOrderEmailRequest extends FormRequest
{
    /**
     * 권한 확인
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 유효성 검사 규칙
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ];
    }

    /**
     * 검증 에러 메시지 정의
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'email.required' => __('sirsoft-ecommerce::validation.orders.email.required'),
            'email.email' => __('sirsoft-ecommerce::validation.orders.email.email'),
            'email.max' => __('sirsoft-ecommerce::validation.orders.email.max'),
            'message.required' => __('sirsoft-ecommerce::validation.orders.email_message.required'),
            'message.max' => __('sirsoft-ecommerce::validation.orders.email_message.max'),
        ];
    }
}
