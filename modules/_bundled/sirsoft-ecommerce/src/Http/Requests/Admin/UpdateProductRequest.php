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
        $product = $this->route('product');
        $productId = $product?->id ?? $product;
        $rules['product_code'] = ['required', 'string', 'max:50'];

        // 허용 문자 규칙은 코드를 바꿀 때만 적용한다 — 수정 화면은 코드 입력칸이 비활성인 채 폼 전체를 보내므로,
        // 규칙 이전에 저장된 코드(한글·공백 등)의 상품도 다른 항목은 계속 수정할 수 있어야 한다.
        // 현재 코드: {product} 경로는 바인딩된 모델, by-code 경로는 {code} 인자 자체
        $currentCode = $product instanceof Product ? $product->product_code : $this->route('code');
        if ($this->input('product_code') !== $currentCode) {
            $rules['product_code'][] = 'regex:'.self::PRODUCT_CODE_PATTERN;
        }

        $rules['product_code'][] = Rule::unique(Product::class, 'product_code')->ignore($productId);

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
