<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Http\Requests\Concerns\ResolvesBaseCurrencyPrecision;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;

/**
 * 상품 통합 일괄 업데이트 요청
 *
 * 일괄 변경 + 개별 인라인 수정을 동시 처리합니다.
 */
class BulkUpdateProductsRequest extends FormRequest
{
    use ResolvesBaseCurrencyPrecision;

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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $priceRule = $this->basePriceRule();

        $rules = [
            // 처리 대상 상품 ID 배열 (필수)
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', Rule::exists(Product::class, 'id')],

            // 상품 일괄 변경 조건 (선택)
            'bulk_changes' => ['nullable', 'array'],
            'bulk_changes.sales_status' => ['nullable', 'string', function ($attribute, $value, $fail) {
                if ($value !== null && ! in_array($value, ProductSalesStatus::values())) {
                    $fail(__('sirsoft-ecommerce::validation.product.invalid_sales_status'));
                }
            }],
            'bulk_changes.display_status' => ['nullable', 'string', function ($attribute, $value, $fail) {
                if ($value !== null && ! in_array($value, ProductDisplayStatus::values())) {
                    $fail(__('sirsoft-ecommerce::validation.product.invalid_display_status'));
                }
            }],

            // 상품 개별 인라인 수정 (선택)
            'items' => ['nullable', 'array'],
            'items.*.id' => ['required', 'integer', Rule::exists(Product::class, 'id')],
            'items.*.name' => ['nullable', 'array'],
            'items.*.name.*' => ['nullable', 'string', 'max:255'],
            // 단건 등록/수정과 동일한 통화 정밀도·하한 규칙 (일괄 수정만 느슨하면 우회로가 된다)
            'items.*.list_price' => ['nullable', 'numeric', $priceRule, 'min:0.01'],
            'items.*.selling_price' => ['nullable', 'numeric', $priceRule, 'min:0.01'],
            'items.*.sales_status' => ['nullable', 'string', function ($attribute, $value, $fail) {
                if ($value !== null && ! in_array($value, ProductSalesStatus::values())) {
                    $fail(__('sirsoft-ecommerce::validation.product.invalid_sales_status'));
                }
            }],
            'items.*.display_status' => ['nullable', 'string', function ($attribute, $value, $fail) {
                if ($value !== null && ! in_array($value, ProductDisplayStatus::values())) {
                    $fail(__('sirsoft-ecommerce::validation.product.invalid_display_status'));
                }
            }],

            // 옵션 일괄 변경 조건 (선택)
            'option_bulk_changes' => ['nullable', 'array'],
            'option_bulk_changes.price_adjustment' => ['nullable', 'array'],
            'option_bulk_changes.price_adjustment.method' => ['required_with:option_bulk_changes.price_adjustment', 'string', 'in:set,add,percent'],
            'option_bulk_changes.price_adjustment.value' => ['required_with:option_bulk_changes.price_adjustment', 'numeric'],
            'option_bulk_changes.stock_quantity' => ['nullable', 'array'],
            'option_bulk_changes.stock_quantity.method' => ['required_with:option_bulk_changes.stock_quantity', 'string', 'in:set,add,subtract'],
            'option_bulk_changes.stock_quantity.value' => ['required_with:option_bulk_changes.stock_quantity', 'integer', 'min:0'],

            // 옵션 개별 인라인 수정 (선택)
            'option_items' => ['nullable', 'array'],
            'option_items.*.product_id' => ['required', 'integer', Rule::exists(Product::class, 'id')],
            'option_items.*.option_id' => ['required', 'integer', Rule::exists(ProductOption::class, 'id')],
            'option_items.*.option_name' => ['nullable', 'array'],
            'option_items.*.option_name.*' => ['nullable', 'string', 'max:255'],
            'option_items.*.sku' => ['nullable', 'string', 'max:100'],
            'option_items.*.list_price' => ['nullable', 'numeric', $priceRule, 'min:0'],
            'option_items.*.price_adjustment' => ['nullable', 'numeric', $priceRule],
            'option_items.*.stock_quantity' => ['nullable', 'integer', 'min:0'],
            'option_items.*.safe_stock_quantity' => ['nullable', 'integer', 'min:0'],
            'option_items.*.is_default' => ['nullable', 'boolean'],
            'option_items.*.is_active' => ['nullable', 'boolean'],
        ];

        // 훅을 통한 validation rules 확장
        return HookManager::applyFilters('sirsoft-ecommerce.product.bulk_update_validation_rules', $rules, $this);
    }

    /**
     * 커스텀 에러 메시지
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => __('sirsoft-ecommerce::validation.product.bulk.ids_required'),
            'ids.min' => __('sirsoft-ecommerce::validation.product.bulk.ids_min'),
            'ids.*.exists' => __('sirsoft-ecommerce::validation.product.bulk.product_not_found'),
            'option_items.*.option_id.exists' => __('sirsoft-ecommerce::validation.product.bulk.option_not_found'),
        ];
    }

    /**
     * 단일 필드 규칙으로 표현할 수 없는 가격 관계를 검증합니다.
     *
     * 일괄 수정은 정가/판매가 중 한쪽만 보내는 부분 전송이 흔하므로, **둘 다 전송된 경우에만**
     * 비교합니다. 한쪽만 온 경우의 정합성은 Service 가 DB 기존값과 대조해 판정합니다.
     *
     * @param  Validator  $validator  검증기
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('items', []) as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $list = $item['list_price'] ?? null;
                $selling = $item['selling_price'] ?? null;

                if ($list === null || $selling === null || $list === '' || $selling === '') {
                    continue;
                }

                if ((float) $selling > (float) $list) {
                    $validator->errors()->add(
                        "items.{$index}.selling_price",
                        __('sirsoft-ecommerce::validation.product.selling_price_lte_list')
                    );
                }
            }
        });
    }
}
