<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\Product;

/**
 * 상품 일괄 재고 변경 요청
 */
class BulkUpdateStockRequest extends FormRequest
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
        ];

        // 훅을 통한 validation rules 확장
        return HookManager::applyFilters('sirsoft-ecommerce.product.bulk_stock_validation_rules', $rules, $this);
    }
}
