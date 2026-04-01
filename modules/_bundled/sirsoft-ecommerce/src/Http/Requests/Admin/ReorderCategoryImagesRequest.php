<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 카테고리 이미지 순서 변경 요청
 */
class ReorderCategoryImagesRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인합니다.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return $this->user()->can('sirsoft-ecommerce.categories.update');
    }

    /**
     * 요청에 적용할 검증 규칙을 반환합니다.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array|string>
     */
    public function rules(): array
    {
        $rules = [
            'order' => ['required', 'array', 'min:1'],
            'order.*.id' => ['required', 'integer'],
            'order.*.order' => ['required', 'integer', 'min:0'],
        ];

        return HookManager::applyFilters(
            'sirsoft-ecommerce.category-image.filter_reorder_validation_rules',
            $rules
        );
    }

    /**
     * 검증 에러 메시지를 반환합니다.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'order.required' => __('sirsoft-ecommerce::validation.category_images.orders.required'),
            'order.array' => __('sirsoft-ecommerce::validation.category_images.orders.array'),
            'order.min' => __('sirsoft-ecommerce::validation.category_images.orders.min'),
            'order.*.id.required' => __('sirsoft-ecommerce::validation.category_images.orders.item.required'),
            'order.*.id.integer' => __('sirsoft-ecommerce::validation.category_images.orders.item.integer'),
            'order.*.order.required' => __('sirsoft-ecommerce::validation.category_images.orders.item.required'),
            'order.*.order.integer' => __('sirsoft-ecommerce::validation.category_images.orders.item.integer'),
        ];
    }
}
