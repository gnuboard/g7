<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Public;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 상품 목록 조회 요청 (공개)
 *
 * 카테고리, 브랜드, 검색어, 정렬, 가격 범위 등 필터링 파라미터를 검증합니다.
 */
class PublicProductListRequest extends FormRequest
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
            'category_id' => ['nullable', 'integer'],
            'category_slug' => ['nullable', 'string', 'max:100'],
            'brand_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:200'],
            'sort' => ['nullable', 'string', 'in:latest,sales,price_asc,price_desc'],
            'min_price' => ['nullable', 'integer', 'min:0'],
            'max_price' => ['nullable', 'integer', 'min:0'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];

        // 훅을 통한 validation rules 확장
        return HookManager::applyFilters('sirsoft-ecommerce.product.public_list_validation_rules', $rules, $this);
    }

    /**
     * 검증 에러 메시지 정의
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'category_id.integer' => __('sirsoft-ecommerce::validation.public_product.category_id.integer'),
            'category_slug.string' => __('sirsoft-ecommerce::validation.public_product.category_slug.string'),
            'category_slug.max' => __('sirsoft-ecommerce::validation.public_product.category_slug.max'),
            'brand_id.integer' => __('sirsoft-ecommerce::validation.public_product.brand_id.integer'),
            'search.string' => __('sirsoft-ecommerce::validation.public_product.search.string'),
            'search.max' => __('sirsoft-ecommerce::validation.public_product.search.max'),
            'sort.in' => __('sirsoft-ecommerce::validation.public_product.sort.in'),
            'min_price.integer' => __('sirsoft-ecommerce::validation.public_product.min_price.integer'),
            'min_price.min' => __('sirsoft-ecommerce::validation.public_product.min_price.min'),
            'max_price.integer' => __('sirsoft-ecommerce::validation.public_product.max_price.integer'),
            'max_price.min' => __('sirsoft-ecommerce::validation.public_product.max_price.min'),
            'per_page.integer' => __('sirsoft-ecommerce::validation.public_product.per_page.integer'),
            'per_page.min' => __('sirsoft-ecommerce::validation.public_product.per_page.min'),
            'per_page.max' => __('sirsoft-ecommerce::validation.public_product.per_page.max'),
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.product.public_list_validation_messages', $messages, $this);
    }
}
