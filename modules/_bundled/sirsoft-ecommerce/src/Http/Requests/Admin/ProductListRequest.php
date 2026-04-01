<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ProductDateType;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductPriceType;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductTaxStatus;

/**
 * 상품 목록 조회 요청
 */
class ProductListRequest extends FormRequest
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
            'search_field' => ['nullable', 'in:all,name,product_code,sku,barcode'],
            'search_keyword' => ['nullable', 'string', 'max:200'],

            // 카테고리 (다대다 관계)
            'category_id' => ['nullable', 'integer'],
            'no_category' => ['nullable', 'boolean'],

            // 날짜
            'date_type' => ['nullable', Rule::in(ProductDateType::values())],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],

            // 판매상태 (다중선택)
            'sales_status' => ['nullable', 'array'],
            'sales_status.*' => ['string', Rule::in(ProductSalesStatus::values())],

            // 전시상태
            'display_status' => ['nullable', Rule::in(ProductDisplayStatus::values())],

            // 브랜드
            'brand_id' => ['nullable', 'integer'],
            'no_brand' => ['nullable', 'boolean'],

            // 과세여부
            'tax_status' => ['nullable', Rule::in(ProductTaxStatus::values())],

            // 가격 범위
            'price_type' => ['nullable', Rule::in(ProductPriceType::values())],
            'min_price' => ['nullable', 'integer', 'min:0'],
            'max_price' => ['nullable', 'integer', 'min:0'],

            // 재고 범위
            'min_stock' => ['nullable', 'integer'],
            'max_stock' => ['nullable', 'integer'],

            // 배송정책
            'shipping_policy_id' => ['nullable', 'integer'],

            // 정렬 및 페이지네이션
            'sort_by' => ['nullable', 'in:created_at,updated_at,selling_price,stock_quantity,name'],
            'sort_order' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];

        // 훅을 통한 validation rules 확장
        return HookManager::applyFilters('sirsoft-ecommerce.product.list_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'search_field.in' => __('sirsoft-ecommerce::validation.list.search_field.in'),
            'search_keyword.string' => __('sirsoft-ecommerce::validation.list.search_keyword.string'),
            'search_keyword.max' => __('sirsoft-ecommerce::validation.list.search_keyword.max'),
            'category_id.integer' => __('sirsoft-ecommerce::validation.list.category_id.integer'),
            'no_category.boolean' => __('sirsoft-ecommerce::validation.list.no_category.boolean'),
            'date_type.in' => __('sirsoft-ecommerce::validation.list.date_type.in'),
            'start_date.date' => __('sirsoft-ecommerce::validation.list.start_date.date'),
            'end_date.date' => __('sirsoft-ecommerce::validation.list.end_date.date'),
            'end_date.after_or_equal' => __('sirsoft-ecommerce::validation.list.end_date.after_or_equal'),
            'sales_status.array' => __('sirsoft-ecommerce::validation.list.sales_status.array'),
            'sales_status.*.in' => __('sirsoft-ecommerce::validation.list.sales_status.in'),
            'display_status.in' => __('sirsoft-ecommerce::validation.list.display_status.in'),
            'brand_id.integer' => __('sirsoft-ecommerce::validation.list.brand_id.integer'),
            'no_brand.boolean' => __('sirsoft-ecommerce::validation.list.no_brand.boolean'),
            'tax_status.in' => __('sirsoft-ecommerce::validation.list.tax_status.in'),
            'price_type.in' => __('sirsoft-ecommerce::validation.list.price_type.in'),
            'min_price.integer' => __('sirsoft-ecommerce::validation.list.min_price.integer'),
            'min_price.min' => __('sirsoft-ecommerce::validation.list.min_price.min'),
            'max_price.integer' => __('sirsoft-ecommerce::validation.list.max_price.integer'),
            'max_price.min' => __('sirsoft-ecommerce::validation.list.max_price.min'),
            'min_stock.integer' => __('sirsoft-ecommerce::validation.list.min_stock.integer'),
            'max_stock.integer' => __('sirsoft-ecommerce::validation.list.max_stock.integer'),
            'shipping_policy_id.integer' => __('sirsoft-ecommerce::validation.list.shipping_policy_id.integer'),
            'sort_by.in' => __('sirsoft-ecommerce::validation.list.sort_by.in'),
            'sort_order.in' => __('sirsoft-ecommerce::validation.list.sort_order.in'),
            'per_page.integer' => __('sirsoft-ecommerce::validation.list.per_page.integer'),
            'per_page.min' => __('sirsoft-ecommerce::validation.list.per_page.min'),
            'per_page.max' => __('sirsoft-ecommerce::validation.list.per_page.max'),
            'page.integer' => __('sirsoft-ecommerce::validation.list.page.integer'),
            'page.min' => __('sirsoft-ecommerce::validation.list.page.min'),
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.product.list_validation_messages', $messages, $this);
    }
}
