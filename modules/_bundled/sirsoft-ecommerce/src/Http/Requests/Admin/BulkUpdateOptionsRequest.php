<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;

/**
 * 옵션 통합 일괄 업데이트 요청
 *
 * 상품 미체크 + 옵션만 체크된 경우에 사용됩니다.
 * 일괄 변경 + 개별 인라인 수정을 동시 처리합니다.
 */
class BulkUpdateOptionsRequest extends FormRequest
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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            // 처리 대상 옵션 ID 배열 (필수) - "productId-optionId" 형식
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['string', 'regex:/^\d+-\d+$/'],

            // 옵션 일괄 변경 조건 (선택)
            'bulk_changes' => ['nullable', 'array'],
            'bulk_changes.price_adjustment' => ['nullable', 'array'],
            'bulk_changes.price_adjustment.method' => ['required_with:bulk_changes.price_adjustment', 'string', 'in:set,add,percent'],
            'bulk_changes.price_adjustment.value' => ['required_with:bulk_changes.price_adjustment', 'numeric'],
            'bulk_changes.stock_quantity' => ['nullable', 'array'],
            'bulk_changes.stock_quantity.method' => ['required_with:bulk_changes.stock_quantity', 'string', 'in:set,add,subtract'],
            'bulk_changes.stock_quantity.value' => ['required_with:bulk_changes.stock_quantity', 'integer', 'min:0'],

            // 옵션 개별 인라인 수정 (선택)
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required', 'integer', Rule::exists(Product::class, 'id')],
            'items.*.option_id' => ['required', 'integer', Rule::exists(ProductOption::class, 'id')],
            'items.*.option_name' => ['nullable', 'array'],
            'items.*.option_name.*' => ['string', 'max:255'],
            'items.*.sku' => ['nullable', 'string', 'max:100'],
            'items.*.list_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.price_adjustment' => ['nullable', 'numeric'],
            'items.*.stock_quantity' => ['nullable', 'integer', 'min:0'],
            'items.*.safe_stock_quantity' => ['nullable', 'integer', 'min:0'],
            'items.*.is_default' => ['nullable', 'boolean'],
            'items.*.is_active' => ['nullable', 'boolean'],
        ];

        // 훅을 통한 validation rules 확장
        return HookManager::applyFilters('sirsoft-ecommerce.option.bulk_update_validation_rules', $rules, $this);
    }

    /**
     * 커스텀 에러 메시지
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => __('sirsoft-ecommerce::validation.option.bulk.ids_required'),
            'ids.min' => __('sirsoft-ecommerce::validation.option.bulk.ids_min'),
            'ids.*.regex' => __('sirsoft-ecommerce::validation.option.bulk.invalid_id_format'),
            'items.*.option_id.exists' => __('sirsoft-ecommerce::validation.product.bulk.option_not_found'),
        ];
    }
}
