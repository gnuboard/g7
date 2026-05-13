<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 상품 1:1 문의 작성 요청 (회원 전용)
 */
class StoreInquiryRequest extends FormRequest
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
     * BoardInquiryListener가 store_validation_rules Filter 훅으로 주입합니다.
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
            'temp_key'  => ['nullable', 'string'],
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.inquiry.store_validation_rules', $rules, $this);
    }

    /**
     * 검증 에러 메시지 정의
     *
     * @return array
     */
    public function messages(): array
    {
        $messages = [
            'content.required' => __('sirsoft-ecommerce::validation.inquiries.content.required'),
            'content.min'      => __('sirsoft-ecommerce::validation.inquiries.content.min'),
            'content.max'      => __('sirsoft-ecommerce::validation.inquiries.content.max'),
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.inquiry.store_validation_messages', $messages, $this);
    }
}
