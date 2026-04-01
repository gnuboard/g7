<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;
use Modules\Sirsoft\Ecommerce\Models\ProductReview;

/**
 * 리뷰 일괄 처리 요청 (관리자)
 */
class BulkReviewRequest extends FormRequest
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
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', Rule::exists(ProductReview::class, 'id')],
            'action' => ['required', 'string', 'in:delete,change_status'],
            'status' => ['required_if:action,change_status', 'nullable', 'string', Rule::in(ReviewStatus::values())],
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.review.bulk_validation_rules', $rules, $this);
    }

    /**
     * 검증 에러 메시지 정의
     *
     * @return array
     */
    public function messages(): array
    {
        $messages = [
            'ids.required' => __('sirsoft-ecommerce::validation.reviews.ids.required'),
            'ids.array' => __('sirsoft-ecommerce::validation.reviews.ids.array'),
            'ids.min' => __('sirsoft-ecommerce::validation.reviews.ids.min'),
            'ids.*.integer' => __('sirsoft-ecommerce::validation.reviews.ids.integer'),
            'ids.*.exists' => __('sirsoft-ecommerce::validation.reviews.ids.exists'),
            'action.required' => __('sirsoft-ecommerce::validation.reviews.action.required'),
            'action.in' => __('sirsoft-ecommerce::validation.reviews.action.in'),
            'status.required_if' => __('sirsoft-ecommerce::validation.reviews.status.required_if'),
            'status.in' => __('sirsoft-ecommerce::validation.reviews.status.in'),
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.review.bulk_validation_messages', $messages, $this);
    }
}
