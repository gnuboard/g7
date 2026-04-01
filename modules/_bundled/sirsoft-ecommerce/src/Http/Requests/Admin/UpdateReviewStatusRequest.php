<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;

/**
 * 리뷰 상태 변경 요청 (관리자)
 */
class UpdateReviewStatusRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(ReviewStatus::values())],
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.review.update_status_validation_rules', $rules, $this);
    }

    /**
     * 검증 에러 메시지 정의
     *
     * @return array
     */
    public function messages(): array
    {
        $messages = [
            'status.required' => __('sirsoft-ecommerce::validation.reviews.status.required'),
            'status.in' => __('sirsoft-ecommerce::validation.reviews.status.in'),
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.review.update_status_validation_messages', $messages, $this);
    }
}
