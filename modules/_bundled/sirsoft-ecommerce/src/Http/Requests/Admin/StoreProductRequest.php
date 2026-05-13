<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use App\Rules\LocaleRequiredTranslatable;
use App\Rules\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductTaxStatus;
use Modules\Sirsoft\Ecommerce\Models\Brand;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductCommonInfo;
use Modules\Sirsoft\Ecommerce\Models\ProductLabel;
use App\Models\Role;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;

/**
 * 상품 생성 요청
 */
class StoreProductRequest extends FormRequest
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
            // 기본 정보
            'name' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 200)],
            'product_code' => ['required', 'string', 'max:50', Rule::unique(Product::class, 'product_code')],
            'sales_product_code' => ['nullable', 'string', 'max:50'],
            'sku' => ['nullable', 'string', 'max:100'],

            // 카테고리 (다대다 관계)
            'category_ids' => ['required', 'array', 'min:1', 'max:5'],
            'category_ids.*' => ['integer', Rule::exists(Category::class, 'id')],
            'primary_category_id' => ['nullable', 'integer', 'in_array:category_ids.*'],

            // 브랜드
            'brand_id' => ['nullable', 'integer', Rule::exists(Brand::class, 'id')],

            // 가격
            'list_price' => ['required', 'integer', 'min:1'],
            'selling_price' => ['required', 'integer', 'min:1', 'lte:list_price'],

            // 재고
            'stock_quantity' => ['required', 'integer', 'min:0'],
            'safe_stock_quantity' => ['nullable', 'integer', 'min:0'],

            // 상태
            'sales_status' => ['required', Rule::in(ProductSalesStatus::values())],
            'display_status' => ['required', Rule::in(ProductDisplayStatus::values())],
            'tax_status' => ['required', Rule::in(ProductTaxStatus::values())],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],

            // 배송
            'shipping_policy_id' => ['nullable', 'integer', Rule::exists(ShippingPolicy::class, 'id')],

            // 공통정보
            'common_info_id' => ['nullable', 'integer', Rule::exists(ProductCommonInfo::class, 'id')],

            // 설명 (다국어)
            'description' => ['nullable', 'array', new TranslatableField(maxLength: 65535)],
            'description_mode' => ['nullable', 'string', 'in:text,html'],

            // 이미지 (별도 테이블)
            'thumbnail_hash' => ['nullable', 'string', 'max:64'],
            'image_temp_key' => ['nullable', 'string', 'max:64'],
            'images' => ['nullable', 'array', 'max:10'],
            'images.*.id' => ['nullable', 'integer'],
            'images.*.hash' => ['nullable', 'string'],
            'images.*.url' => ['nullable', 'url'],
            'images.*.alt_text' => ['nullable', 'array', new TranslatableField()],
            'images.*.is_thumbnail' => ['nullable', 'boolean'],
            'images.*.sort_order' => ['nullable', 'integer', 'min:0'],

            // SEO
            'meta_title' => ['nullable', 'string', 'max:200'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'meta_keywords' => ['nullable', 'array'],
            'meta_keywords.*' => ['string', 'max:50'],
            'use_main_image_for_og' => ['nullable', 'boolean'],

            // 옵션
            'has_options' => ['nullable', 'boolean'],
            'option_groups' => ['nullable', 'array'],
            'option_groups.*.name' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 100)],
            'option_groups.*.values' => ['required', 'array', 'min:1'],
            'option_groups.*.values.*' => ['required', 'array'],
            'options' => ['required', 'array', 'min:1'],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.option_code' => ['required_with:options', 'string'],
            'options.*.option_name' => ['required_with:options', 'array', new LocaleRequiredTranslatable(maxLength: 200)],
            'options.*.option_values' => ['required_with:options', 'array'],
            'options.*.option_values.*.key' => ['required', 'array'],
            'options.*.option_values.*.value' => ['required', 'array'],
            'options.*.list_price' => ['required_with:options', 'integer', 'min:0'],
            'options.*.selling_price' => ['required_with:options', 'integer', 'min:0'],
            'options.*.price_adjustment' => ['nullable', 'integer'],
            'options.*.stock_quantity' => ['required_with:options', 'integer', 'min:0'],
            'options.*.safe_stock_quantity' => ['nullable', 'integer', 'min:0'],
            'options.*.sku' => ['nullable', 'string', 'max:100'],
            'options.*.weight' => ['nullable', 'numeric', 'min:0'],
            'options.*.volume' => ['nullable', 'numeric', 'min:0'],
            'options.*.mileage_value' => ['nullable', 'numeric', 'min:0'],
            'options.*.mileage_type' => ['nullable', 'string', 'in:fixed,percent'],
            'options.*.is_default' => ['nullable', 'boolean'],
            'options.*.is_active' => ['nullable', 'boolean'],

            // 추가옵션
            'additional_options' => ['nullable', 'array', 'max:5'],
            'additional_options.*.name' => ['required_with:additional_options', 'array', new LocaleRequiredTranslatable(maxLength: 100)],
            'additional_options.*.is_required' => ['nullable', 'boolean'],

            // 상품정보제공고시 (템플릿은 UI용, 저장하지 않음)
            'notice_items' => ['nullable', 'array', 'max:50'],
            'notice_items.*.name' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 100)],
            'notice_items.*.content' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 500)],
            'notice_items.*.sort_order' => ['nullable', 'integer', 'min:0'],

            // 라벨 할당
            'label_assignments' => ['nullable', 'array'],
            'label_assignments.*.label_id' => ['required', 'integer', Rule::exists(ProductLabel::class, 'id')],
            'label_assignments.*.start_date' => ['nullable', 'date'],
            'label_assignments.*.end_date' => ['nullable', 'date', 'after_or_equal:label_assignments.*.start_date'],

            // 구매 제한
            'min_purchase_qty' => ['nullable', 'integer', 'min:1'],
            'max_purchase_qty' => ['nullable', 'integer', 'min:0'],
            'purchase_restriction' => ['nullable', 'string', 'in:none,restricted'],
            'allowed_roles' => ['nullable', 'array'],
            'allowed_roles.*' => ['integer', Rule::exists(Role::class, 'id')],

            // 식별코드
            'barcode' => ['nullable', 'string', 'max:50'],
            'hs_code' => ['nullable', 'string', 'max:20'],
        ];

        // 훅을 통한 validation rules 확장
        return HookManager::applyFilters('sirsoft-ecommerce.product.store_validation_rules', $rules, $this);
    }

    /**
     * 유효성 검사 메시지
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            // 기본 정보
            'name.required' => __('sirsoft-ecommerce::validation.product.name.required'),
            'product_code.required' => __('sirsoft-ecommerce::validation.product.product_code.required'),
            'product_code.unique' => __('sirsoft-ecommerce::validation.product.product_code.unique'),

            // 가격
            'list_price.required' => __('sirsoft-ecommerce::validation.product.list_price.required'),
            'list_price.min' => __('sirsoft-ecommerce::validation.product.list_price.min'),
            'selling_price.required' => __('sirsoft-ecommerce::validation.product.selling_price.required'),
            'selling_price.min' => __('sirsoft-ecommerce::validation.product.selling_price.min'),
            'selling_price.lte' => __('sirsoft-ecommerce::validation.product.selling_price.lte'),

            // 재고
            'stock_quantity.required' => __('sirsoft-ecommerce::validation.product.stock_quantity.required'),

            // 상태
            'sales_status.required' => __('sirsoft-ecommerce::validation.product.sales_status.required'),
            'sales_status.in' => __('sirsoft-ecommerce::validation.product.sales_status.in'),
            'display_status.required' => __('sirsoft-ecommerce::validation.product.display_status.required'),
            'display_status.in' => __('sirsoft-ecommerce::validation.product.display_status.in'),
            'tax_status.required' => __('sirsoft-ecommerce::validation.product.tax_status.required'),
            'tax_status.in' => __('sirsoft-ecommerce::validation.product.tax_status.in'),

            // 카테고리
            'category_ids.required' => __('sirsoft-ecommerce::validation.product.category_ids.required'),
            'category_ids.min' => __('sirsoft-ecommerce::validation.product.category_ids.min'),
            'category_ids.max' => __('sirsoft-ecommerce::validation.product.category_ids.max'),

            // 옵션
            'options.required' => __('sirsoft-ecommerce::validation.product.options.required'),
            'options.min' => __('sirsoft-ecommerce::validation.product.options.min'),
            'options.*.option_code.required_with' => __('sirsoft-ecommerce::validation.product.options.option_code.required_with'),
            'options.*.option_name.required_with' => __('sirsoft-ecommerce::validation.product.options.option_name.required_with'),
            'options.*.option_values.required_with' => __('sirsoft-ecommerce::validation.product.options.option_values.required_with'),
            'options.*.list_price.required_with' => __('sirsoft-ecommerce::validation.product.options.list_price.required_with'),
            'options.*.selling_price.required_with' => __('sirsoft-ecommerce::validation.product.options.selling_price.required_with'),
            'options.*.stock_quantity.required_with' => __('sirsoft-ecommerce::validation.product.options.stock_quantity.required_with'),

            // 라벨 할당
            'label_assignments.*.label_id.required' => __('sirsoft-ecommerce::validation.product.label_assignments.label_id.required'),
            'label_assignments.*.label_id.exists' => __('sirsoft-ecommerce::validation.product.label_assignments.label_id.exists'),
            'label_assignments.*.end_date.after_or_equal' => __('sirsoft-ecommerce::validation.product.label_assignments.end_date.after_or_equal'),

            // 배송정책
            'shipping_policy_id.exists' => __('sirsoft-ecommerce::validation.product.shipping_policy_id.exists'),

            // 공통정보
            'common_info_id.exists' => __('sirsoft-ecommerce::validation.product.common_info_id.exists'),

            // SEO
            'use_main_image_for_og.boolean' => __('sirsoft-ecommerce::validation.product.use_main_image_for_og.boolean'),
        ];
    }

    /**
     * 데이터 전처리
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // 옵션명이 비어있는 옵션 제거
        if ($this->has('options')) {
            $options = collect($this->options)
                ->filter(fn ($opt) => ! empty($opt['option_name']) || ! empty($opt['option_code']))
                ->values()
                ->toArray();
            $this->merge(['options' => $options]);
        }

        // 기본 옵션이 없으면 첫 번째를 기본으로 설정
        if ($this->has('options') && count($this->options) > 0) {
            $hasDefault = collect($this->options)->contains('is_default', true);
            if (! $hasDefault) {
                $options = $this->options;
                $options[0]['is_default'] = true;
                $this->merge(['options' => $options]);
            }
        }
    }
}
