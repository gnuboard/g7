<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ReviewStatus;

/**
 * 리뷰 목록 조회 요청 (관리자)
 */
class AdminReviewListRequest extends FormRequest
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
            // 문자열 검색
            'search_field' => ['nullable', 'string', 'in:all,product_name,reviewer,content,order_number,option_name'],
            'search_keyword' => ['nullable', 'string', 'max:200'],

            // 평점 필터
            'rating' => ['nullable', 'string', 'in:1,2,3,4,5,'],

            // 답글 상태 필터
            'reply_status' => ['nullable', 'string', 'in:all,replied,unreplied'],

            // 포토 리뷰 필터
            'photo' => ['nullable', 'string', 'in:photo,normal,'],
            'has_photo' => ['nullable', 'boolean'],

            // 리뷰 상태 필터
            'status' => ['nullable', 'string', Rule::in([...ReviewStatus::values(), ''])],

            // 날짜 범위
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],

            // 정렬 및 페이지네이션
            'sort' => ['nullable', 'string', 'in:created_at_desc,created_at_asc,rating_desc,rating_asc'],
            'sort_by' => ['nullable', 'string', 'in:created_at,rating,reply_status'],
            'sort_order' => ['nullable', 'string', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];

        // 훅을 통한 validation rules 확장
        return HookManager::applyFilters('sirsoft-ecommerce.review.list_validation_rules', $rules, $this);
    }

    /**
     * 검증 에러 메시지 정의
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            // 검색 필드
            'search_field.in' => __('sirsoft-ecommerce::validation.reviews.search_field.in'),
            'search_keyword.string' => __('sirsoft-ecommerce::validation.reviews.search_keyword.string'),
            'search_keyword.max' => __('sirsoft-ecommerce::validation.reviews.search_keyword.max'),
            // 평점
            'rating.in' => __('sirsoft-ecommerce::validation.reviews.rating.in'),
            // 답글 상태
            'reply_status.in' => __('sirsoft-ecommerce::validation.reviews.reply_status.in'),
            // 포토 리뷰
            'has_photo.boolean' => __('sirsoft-ecommerce::validation.reviews.has_photo.boolean'),
            // 리뷰 상태
            'status.in' => __('sirsoft-ecommerce::validation.reviews.status.in'),
            // 날짜
            'start_date.date' => __('sirsoft-ecommerce::validation.reviews.start_date.date'),
            'end_date.date' => __('sirsoft-ecommerce::validation.reviews.end_date.date'),
            'end_date.after_or_equal' => __('sirsoft-ecommerce::validation.reviews.end_date.after_or_equal'),
            // 정렬 및 페이지네이션
            'sort_by.in' => __('sirsoft-ecommerce::validation.reviews.sort_by.in'),
            'sort_order.in' => __('sirsoft-ecommerce::validation.reviews.sort_order.in'),
            'per_page.integer' => __('sirsoft-ecommerce::validation.reviews.per_page.integer'),
            'per_page.min' => __('sirsoft-ecommerce::validation.reviews.per_page.min'),
            'per_page.max' => __('sirsoft-ecommerce::validation.reviews.per_page.max'),
            'page.integer' => __('sirsoft-ecommerce::validation.reviews.page.integer'),
            'page.min' => __('sirsoft-ecommerce::validation.reviews.page.min'),
        ];

        // 훅을 통한 validation messages 확장
        return HookManager::applyFilters('sirsoft-ecommerce.review.list_validation_messages', $messages, $this);
    }
}
