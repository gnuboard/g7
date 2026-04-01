<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 리뷰 목록 조회 요청 (공개)
 *
 * 상품 리뷰 목록 조회 시 정렬, 필터링, 페이지네이션 파라미터를 검증합니다.
 */
class PublicReviewListRequest extends FormRequest
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
            'sort' => ['nullable', 'string', 'in:created_at_desc,created_at_asc,rating_desc,rating_asc'],
            'photo_only' => ['nullable', 'in:0,1,true,false'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'rating' => ['nullable', 'integer', 'in:1,2,3,4,5'],
            'option_filters' => ['nullable'],
        ];

        // 훅을 통한 validation rules 확장
        return HookManager::applyFilters('sirsoft-ecommerce.review.public_list_validation_rules', $rules, $this);
    }

    /**
     * 검증 에러 메시지 정의
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'sort.in' => __('sirsoft-ecommerce::validation.public_review.sort.in'),
            'photo_only.in' => __('sirsoft-ecommerce::validation.public_review.photo_only.boolean'),
            'page.integer' => __('sirsoft-ecommerce::validation.public_review.page.integer'),
            'page.min' => __('sirsoft-ecommerce::validation.public_review.page.min'),
            'per_page.integer' => __('sirsoft-ecommerce::validation.public_review.per_page.integer'),
            'per_page.min' => __('sirsoft-ecommerce::validation.public_review.per_page.min'),
            'per_page.max' => __('sirsoft-ecommerce::validation.public_review.per_page.max'),
            'rating.in' => __('sirsoft-ecommerce::validation.public_review.rating.in'),
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.review.public_list_validation_messages', $messages, $this);
    }
}
