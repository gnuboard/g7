<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 전체 후기 목록 조회 요청 (공개 — 리뷰 모아보기)
 *
 * 상품별 후기 목록과 같은 정렬·사진·별점·페이지 규칙을 쓴다. 상품 옵션 필터는 상품이 정해져야
 * 의미가 있어 받지 않는다.
 */
class PublicReviewFeedRequest extends FormRequest
{
    /**
     * 권한 확인
     *
     * @return bool 항상 허용 (라우트 미들웨어가 공개 상품 조회 권한을 본다)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 유효성 검사 규칙
     *
     * @return array<string, mixed> 검증 규칙
     */
    public function rules(): array
    {
        $rules = [
            'sort' => ['nullable', 'string', 'in:created_at_desc,created_at_asc,rating_desc,rating_asc'],
            'photo_only' => ['nullable', 'in:0,1,true,false'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'rating' => ['nullable', 'integer', 'in:1,2,3,4,5'],
        ];

        // 훅을 통한 validation rules 확장
        return HookManager::applyFilters('sirsoft-ecommerce.review.public_feed_validation_rules', $rules, $this);
    }

    /**
     * 검증 필드 라벨
     *
     * @return array<string, string> 필드 라벨
     */
    public function attributes(): array
    {
        return [
            'sort' => __('sirsoft-ecommerce::validation.public_list_attributes.sort'),
            'photo_only' => __('sirsoft-ecommerce::validation.public_list_attributes.photo_only'),
            'rating' => __('sirsoft-ecommerce::validation.public_list_attributes.rating'),
        ];
    }

    /**
     * 검증 에러 메시지 정의
     *
     * @return array<string, string> 검증 메시지
     */
    public function messages(): array
    {
        return [
            'sort.in' => __('sirsoft-ecommerce::validation.public_review.sort.in'),
            'photo_only.in' => __('sirsoft-ecommerce::validation.public_review.photo_only.boolean'),
            'page.integer' => __('sirsoft-ecommerce::validation.public_review.page.integer'),
            'page.min' => __('sirsoft-ecommerce::validation.public_review.page.min'),
            'per_page.integer' => __('sirsoft-ecommerce::validation.public_review.per_page.integer'),
            'per_page.min' => __('sirsoft-ecommerce::validation.public_review.per_page.min'),
            'per_page.max' => __('sirsoft-ecommerce::validation.public_review.per_page.max'),
            'rating.in' => __('sirsoft-ecommerce::validation.public_review.rating.in'),
        ];
    }
}
