<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 상품 1:1 문의 답변 작성 요청 (관리자)
 */
class StoreInquiryReplyRequest extends FormRequest
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
            'content' => ['required', 'string', 'min:1', 'max:5000'],
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
            'content.required' => __('sirsoft-ecommerce::validation.inquiries.reply_content.required'),
            'content.min' => __('sirsoft-ecommerce::validation.inquiries.reply_content.min'),
            'content.max' => __('sirsoft-ecommerce::validation.inquiries.reply_content.max'),
        ];
    }
}
