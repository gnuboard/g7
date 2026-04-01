<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\User;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\Product;

/**
 * 리뷰 작성 요청
 */
class StoreReviewRequest extends FormRequest
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
            'product_id' => ['required', 'integer', Rule::exists(Product::class, 'id')],
            'order_option_id' => ['required', 'integer', Rule::exists(OrderOption::class, 'id')],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'content' => ['required', 'string', 'min:10', 'max:2000'],
            'content_mode' => ['nullable', 'string', 'in:text,html'],
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.review.store_validation_rules', $rules, $this);
    }

    /**
     * 검증 에러 메시지 정의
     *
     * @return array
     */
    public function messages(): array
    {
        $messages = [
            'product_id.required' => __('sirsoft-ecommerce::validation.reviews.product_id.required'),
            'product_id.exists' => __('sirsoft-ecommerce::validation.reviews.product_id.exists'),
            'order_option_id.required' => __('sirsoft-ecommerce::validation.reviews.order_option_id.required'),
            'order_option_id.exists' => __('sirsoft-ecommerce::validation.reviews.order_option_id.exists'),
            'rating.required' => __('sirsoft-ecommerce::validation.reviews.rating.required'),
            'rating.integer' => __('sirsoft-ecommerce::validation.reviews.rating.integer'),
            'rating.min' => __('sirsoft-ecommerce::validation.reviews.rating.min'),
            'rating.max' => __('sirsoft-ecommerce::validation.reviews.rating.max'),
            'content.required' => __('sirsoft-ecommerce::validation.reviews.content.required'),
            'content.min' => __('sirsoft-ecommerce::validation.reviews.content.min'),
            'content.max' => __('sirsoft-ecommerce::validation.reviews.content.max'),
            'content_mode.in' => __('sirsoft-ecommerce::validation.reviews.content_mode.in'),
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.review.store_validation_messages', $messages, $this);
    }
}
