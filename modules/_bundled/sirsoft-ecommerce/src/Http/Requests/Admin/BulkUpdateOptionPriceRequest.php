<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\Product;

/**
 * 옵션 일괄 가격 변경 요청
 */
class BulkUpdateOptionPriceRequest extends FormRequest
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
     * product_ids: 상품 ID 배열 (해당 상품의 모든 옵션 대상)
     * option_ids: 옵션 ID 배열 ("productId-optionId" 형식, 개별 선택된 옵션)
     * 둘 중 하나 이상 필수
     *
     * @return array
     */
    public function rules(): array
    {
        $rules = [
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', Rule::exists(Product::class, 'id')],
            'option_ids' => ['nullable', 'array'],
            'option_ids.*' => ['string', 'regex:/^\d+-\d+$/'],
            'method' => ['required', 'in:increase,decrease,fixed'],
            'value' => ['required', 'integer', 'min:0'],
            'unit' => ['required', 'in:won,percent'],
        ];

        // 훅을 통한 validation rules 확장
        return HookManager::applyFilters('sirsoft-ecommerce.product_option.bulk_price_validation_rules', $rules, $this);
    }

    /**
     * 추가 유효성 검사
     *
     * @param \Illuminate\Validation\Validator $validator
     * @return void
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $productIds = $this->input('product_ids', []);
            $optionIds = $this->input('option_ids', []);

            if (empty($productIds) && empty($optionIds)) {
                $validator->errors()->add('product_ids', __('sirsoft-ecommerce::validation.bulk_option_price.ids_required'));
            }
        });
    }

    /**
     * 유효성 검사 메시지
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'product_ids.required' => __('sirsoft-ecommerce::validation.bulk_option_price.product_ids.required'),
            'product_ids.min' => __('sirsoft-ecommerce::validation.bulk_option_price.product_ids.min'),
            'method.required' => __('sirsoft-ecommerce::validation.bulk_option_price.method.required'),
            'method.in' => __('sirsoft-ecommerce::validation.bulk_option_price.method.in'),
            'value.required' => __('sirsoft-ecommerce::validation.bulk_option_price.value.required'),
            'value.integer' => __('sirsoft-ecommerce::validation.bulk_option_price.value.integer'),
            'value.min' => __('sirsoft-ecommerce::validation.bulk_option_price.value.min'),
            'unit.required' => __('sirsoft-ecommerce::validation.bulk_option_price.unit.required'),
            'unit.in' => __('sirsoft-ecommerce::validation.bulk_option_price.unit.in'),
        ];
    }
}
