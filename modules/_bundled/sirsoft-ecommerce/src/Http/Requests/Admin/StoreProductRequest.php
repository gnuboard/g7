<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use App\Models\Role;
use App\Rules\LocaleRequiredTranslatable;
use App\Rules\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductImageCollection;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductTaxStatus;
use Modules\Sirsoft\Ecommerce\Models\Brand;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductCommonInfo;
use Modules\Sirsoft\Ecommerce\Models\ProductLabel;
use Modules\Sirsoft\Ecommerce\Models\ShippingPolicy;

/**
 * 상품 생성 요청
 */
class StoreProductRequest extends FormRequest
{
    /**
     * 권한 확인
     *
     * @return bool 인가 여부 (권한 체크는 미들웨어에 위임)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 유효성 검사 규칙
     *
     * @return array 필드별 검증 규칙 배열
     */
    public function rules(): array
    {
        // 기본통화의 소수 자릿수 — 가격 입력 허용 자릿수 상한.
        // KRW(0) 이면 정수만, USD/EUR(2) 이면 소수 2자리까지 허용.
        $decimalPlaces = $this->baseCurrencyDecimalPlaces();
        $priceRule = $decimalPlaces > 0 ? 'decimal:0,'.$decimalPlaces : 'integer';

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

            // 가격 (기본통화 기준 — 소수 통화는 소수 입력 허용)
            'list_price' => ['required', 'numeric', $priceRule, 'min:0.01'],
            'selling_price' => ['required', 'numeric', $priceRule, 'min:0.01', 'lte:list_price'],

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
            'images' => ['nullable', 'array', 'max:'.ProductImageCollection::MAX_IMAGES_PER_COLLECTION],
            'images.*.id' => ['nullable', 'integer'],
            'images.*.hash' => ['nullable', 'string'],
            'images.*.url' => ['nullable', 'url'],
            'images.*.alt_text' => ['nullable', 'array', new TranslatableField],
            'images.*.is_thumbnail' => ['nullable', 'boolean'],
            'images.*.sort_order' => ['nullable', 'integer', 'min:0'],

            // SEO (다국어 — name/description 과 동일 패턴, 언어별 SEO 분기 지원)
            'meta_title' => ['nullable', 'array', new TranslatableField(maxLength: 200)],
            'meta_description' => ['nullable', 'array', new TranslatableField(maxLength: 500)],
            'meta_keywords' => ['nullable', 'array'],
            'meta_keywords.*' => ['string', 'max:50'],
            'seo_sync_title' => ['nullable', 'boolean'],
            'seo_sync_description' => ['nullable', 'boolean'],
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
            'options.*.list_price' => ['required_with:options', 'numeric', $priceRule, 'min:0'],
            'options.*.selling_price' => ['required_with:options', 'numeric', $priceRule, 'min:0'],
            'options.*.price_adjustment' => ['nullable', 'numeric', $priceRule],
            'options.*.stock_quantity' => ['required_with:options', 'integer', 'min:0'],
            'options.*.safe_stock_quantity' => ['nullable', 'integer', 'min:0'],
            'options.*.sku' => ['nullable', 'string', 'max:100'],
            'options.*.weight' => ['nullable', 'numeric', 'min:0'],
            'options.*.volume' => ['nullable', 'numeric', 'min:0'],
            'options.*.mileage_value' => ['nullable', 'numeric', 'min:0'],
            'options.*.mileage_type' => ['nullable', 'string', 'in:fixed,percent'],
            'options.*.is_default' => ['nullable', 'boolean'],
            'options.*.is_active' => ['nullable', 'boolean'],

            // 추가옵션 (그룹 + 선택지)
            'additional_options' => ['nullable', 'array', 'max:5'],
            'additional_options.*.name' => ['required_with:additional_options', 'array', new LocaleRequiredTranslatable(maxLength: 100)],
            'additional_options.*.is_required' => ['nullable', 'boolean'],
            'additional_options.*.sort_order' => ['nullable', 'integer', 'min:0'],
            // 선택지: 그룹당 1개 이상, 최대 20개. 빈 그룹 저장 차단 (D11)
            'additional_options.*.values' => ['required_with:additional_options', 'array', 'min:1', 'max:20'],
            'additional_options.*.values.*.name' => ['required', 'array', new LocaleRequiredTranslatable(maxLength: 100)],
            // 추가금: 0 이상만 허용 (음수 금지, D16). 기본통화 자릿수 따름 (소수 통화는 소수 허용)
            'additional_options.*.values.*.price_adjustment' => ['nullable', 'numeric', $priceRule, 'min:0'],
            'additional_options.*.values.*.is_default' => ['nullable', 'boolean'],
            'additional_options.*.values.*.is_active' => ['nullable', 'boolean'],
            // 직접입력 허용 — 유저가 이 선택지 선택 시 자유 텍스트 입력 필수 (E1/E4)
            'additional_options.*.values.*.allow_custom_text' => ['nullable', 'boolean'],
            'additional_options.*.values.*.sort_order' => ['nullable', 'integer', 'min:0'],

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
     * @return array 검증 실패 메시지 배열
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

            // 추가옵션 (선택지 누락/그룹명/추가금 — 영문 키 노출 방지)
            'additional_options.max' => __('sirsoft-ecommerce::validation.product.additional_options.max'),
            'additional_options.*.name.required_with' => __('sirsoft-ecommerce::validation.product.additional_options.name.required_with'),
            'additional_options.*.values.required_with' => __('sirsoft-ecommerce::validation.product.additional_options.values.required_with'),
            'additional_options.*.values.min' => __('sirsoft-ecommerce::validation.product.additional_options.values.min'),
            'additional_options.*.values.max' => __('sirsoft-ecommerce::validation.product.additional_options.values.max'),
            'additional_options.*.values.*.name.required' => __('sirsoft-ecommerce::validation.product.additional_options.values.name.required'),
            'additional_options.*.values.*.price_adjustment.min' => __('sirsoft-ecommerce::validation.product.additional_options.values.price_adjustment.min'),

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
     * 추가 cross-field 검증을 등록합니다.
     *
     * 단일 필드 rules 로 표현 불가한 검증을 after() 콜백으로 등록합니다.
     *
     * @param  Validator  $validator  Laravel validator 인스턴스
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validatePurchaseRestrictionRoles($validator);
        });
    }

    /**
     * 구매 대상 제한(restricted) 선택 시 허용 역할이 최소 1개 있는지 검증합니다.
     *
     * restricted 인데 allowed_roles 가 비어 있으면 모순 데이터이므로 차단합니다.
     *
     * @param  Validator  $validator  Laravel validator 인스턴스
     */
    protected function validatePurchaseRestrictionRoles(Validator $validator): void
    {
        // 1차 규칙 위반(형식/존재) 시 중복 에러 회피
        if ($validator->errors()->hasAny(['purchase_restriction', 'allowed_roles'])) {
            return;
        }

        if ($this->input('purchase_restriction') !== 'restricted') {
            return;
        }

        $roles = $this->input('allowed_roles', []);
        $validRoleCount = is_array($roles)
            ? count(array_filter($roles, fn ($r) => $r !== null && $r !== ''))
            : 0;

        if ($validRoleCount === 0) {
            $validator->errors()->add(
                'allowed_roles',
                __('sirsoft-ecommerce::validation.product.allowed_roles.required_when_restricted')
            );
        }
    }

    /**
     * 검증 속성명(에러 메시지용 한글 필드명)을 반환합니다.
     *
     * 미지정 시 Laravel 이 `options.0.selling_price` 같은 raw 경로를 노출하므로,
     * 사용자에게 의미 있는 한글 명칭으로 치환합니다. 인덱스 와일드카드(`*`)는
     * 각 인덱스에 동일 라벨이 적용됩니다.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => __('sirsoft-ecommerce::validation.product.attributes.name'),
            'product_code' => __('sirsoft-ecommerce::validation.product.attributes.product_code'),
            'list_price' => __('sirsoft-ecommerce::validation.product.attributes.list_price'),
            'selling_price' => __('sirsoft-ecommerce::validation.product.attributes.selling_price'),
            'stock_quantity' => __('sirsoft-ecommerce::validation.product.attributes.stock_quantity'),
            'safe_stock_quantity' => __('sirsoft-ecommerce::validation.product.attributes.safe_stock_quantity'),
            'options.*.list_price' => __('sirsoft-ecommerce::validation.product.attributes.option_list_price'),
            'options.*.selling_price' => __('sirsoft-ecommerce::validation.product.attributes.option_selling_price'),
            'options.*.price_adjustment' => __('sirsoft-ecommerce::validation.product.attributes.option_price_adjustment'),
            'options.*.stock_quantity' => __('sirsoft-ecommerce::validation.product.attributes.option_stock_quantity'),
            'options.*.option_name' => __('sirsoft-ecommerce::validation.product.attributes.option_name'),
            'options.*.option_code' => __('sirsoft-ecommerce::validation.product.attributes.option_code'),
        ];
    }

    /**
     * 기본통화의 소수 자릿수를 반환합니다.
     *
     * 환경설정 language_currency 의 default_currency 에 매칭되는 통화의
     * decimal_places 값(예: KRW=0, USD=2)을 반환합니다.
     *
     * @return int 소수 자릿수 (미설정 시 0)
     */
    protected function baseCurrencyDecimalPlaces(): int
    {
        $settings = g7_module_settings('sirsoft-ecommerce', 'language_currency');
        $default = $settings['default_currency'] ?? 'KRW';

        foreach ($settings['currencies'] ?? [] as $currency) {
            if (($currency['code'] ?? null) === $default) {
                return (int) ($currency['decimal_places'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * 데이터 전처리
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
