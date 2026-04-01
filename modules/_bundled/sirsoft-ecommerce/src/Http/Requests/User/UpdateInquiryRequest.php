<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 상품 1:1 문의 수정 요청 (사용자)
 */
class UpdateInquiryRequest extends FormRequest
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
     * 기본 규칙은 최소한으로 정의하며, 게시판 설정 기반 동적 규칙은
     * BoardInquiryListener가 update_validation_rules Filter 훅으로 주입합니다.
     *
     * @return array
     */
    public function rules(): array
    {
        $rules = [
            'title'     => ['nullable', 'string'],
            'category'  => ['nullable', 'string'],
            'content'   => ['required', 'string'],
            'is_secret' => ['boolean'],
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.inquiry.update_validation_rules', $rules, $this);
    }

    /**
     * 검증 에러 메시지 정의
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'content.required' => __('sirsoft-ecommerce::validation.inquiries.content.required'),
            'content.min'      => __('sirsoft-ecommerce::validation.inquiries.content.min'),
            'content.max'      => __('sirsoft-ecommerce::validation.inquiries.content.max'),
        ];
    }
}
