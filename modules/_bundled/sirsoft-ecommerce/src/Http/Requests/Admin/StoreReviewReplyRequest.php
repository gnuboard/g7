<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 리뷰 답글 작성 요청 (관리자)
 */
class StoreReviewReplyRequest extends FormRequest
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
        $rules = [
            'reply_content' => ['required', 'string', 'min:1', 'max:2000'],
            'reply_content_mode' => ['nullable', 'string', 'in:text,html'],
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.review.store_reply_validation_rules', $rules, $this);
    }

    /**
     * 검증 에러 메시지 정의
     *
     * @return array
     */
    public function messages(): array
    {
        $messages = [
            'reply_content.required' => __('sirsoft-ecommerce::validation.reviews.reply_content.required'),
            'reply_content.min' => __('sirsoft-ecommerce::validation.reviews.reply_content.min'),
            'reply_content.max' => __('sirsoft-ecommerce::validation.reviews.reply_content.max'),
            'reply_content_mode.in' => __('sirsoft-ecommerce::validation.reviews.reply_content_mode.in'),
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.review.store_reply_validation_messages', $messages, $this);
    }
}
