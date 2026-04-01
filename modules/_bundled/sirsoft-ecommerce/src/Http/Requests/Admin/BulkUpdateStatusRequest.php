<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Enums\ProductDisplayStatus;
use Modules\Sirsoft\Ecommerce\Enums\ProductSalesStatus;
use Modules\Sirsoft\Ecommerce\Models\Product;

/**
 * 상품 일괄 상태 변경 요청
 */
class BulkUpdateStatusRequest extends FormRequest
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
            'field' => ['required', 'in:sales_status,display_status'],
            'value' => ['required', 'string', function ($attribute, $value, $fail) {
                $field = $this->input('field');

                if ($field === 'sales_status' && ! in_array($value, ProductSalesStatus::values())) {
                    $fail(__('sirsoft-ecommerce::validation.product.invalid_sales_status'));
                }

                if ($field === 'display_status' && ! in_array($value, ProductDisplayStatus::values())) {
                    $fail(__('sirsoft-ecommerce::validation.product.invalid_display_status'));
                }
            }],
        ];

        // 훅을 통한 validation rules 확장
        return HookManager::applyFilters('sirsoft-ecommerce.product.bulk_status_validation_rules', $rules, $this);
    }
}
