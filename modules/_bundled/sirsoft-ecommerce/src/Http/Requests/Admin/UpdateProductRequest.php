<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\Product;

/**
 * 상품 수정 요청
 */
class UpdateProductRequest extends StoreProductRequest
{
    /**
     * 유효성 검사 규칙
     *
     * @return array
     */
    public function rules(): array
    {
        $rules = parent::rules();

        // product_code unique 검증에서 현재 상품 제외
        $productId = $this->route('product')?->id ?? $this->route('product');
        $rules['product_code'] = [
            'required',
            'string',
            'max:50',
            Rule::unique(Product::class, 'product_code')->ignore($productId),
        ];

        // 수정 시 필드 일부 optional 처리
        $optionalFields = [
            'name',
            'list_price',
            'selling_price',
            'stock_quantity',
            'sales_status',
            'display_status',
            'tax_status',
            'category_ids',
            'options',
        ];

        foreach ($optionalFields as $field) {
            if (isset($rules[$field]) && is_array($rules[$field])) {
                // required를 sometimes로 변경
                $rules[$field] = array_map(
                    fn ($v) => $v === 'required' ? 'sometimes' : $v,
                    $rules[$field]
                );

                // sometimes가 없으면 추가
                if (! in_array('sometimes', $rules[$field])) {
                    array_unshift($rules[$field], 'sometimes');
                }
            }
        }

        // 훅을 통한 validation rules 확장
        return HookManager::applyFilters('sirsoft-ecommerce.product.update_validation_rules', $rules, $this);
    }
}
