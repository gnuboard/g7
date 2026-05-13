<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\Product;

/**
 * 상품 일괄 가격 변경 요청
 */
class BulkUpdatePriceRequest extends FormRequest
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
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', Rule::exists(Product::class, 'id')],
            'method' => ['required', 'in:increase,decrease,set'],
            'value' => ['required', 'integer', 'min:0'],
            'unit' => ['required', 'in:won,percent'],
        ];

        // 훅을 통한 validation rules 확장
        return HookManager::applyFilters('sirsoft-ecommerce.product.bulk_price_validation_rules', $rules, $this);
    }

    /**
     * 유효성 검사 메시지
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'ids.required' => __('sirsoft-ecommerce::validation.bulk.ids_required'),
            'method.required' => __('sirsoft-ecommerce::validation.bulk.method_required'),
            'value.required' => __('sirsoft-ecommerce::validation.bulk.value_required'),
        ];
    }
}
