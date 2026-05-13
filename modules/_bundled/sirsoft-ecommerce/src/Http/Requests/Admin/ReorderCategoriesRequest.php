<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sirsoft\Ecommerce\Models\Category;

/**
 * 카테고리 순서 변경 요청
 *
 * SortableMenuList 컴포넌트에서 전송하는 데이터 형식:
 * {
 *   "parent_menus": [{ "id": 1, "order": 1 }, ...],
 *   "child_menus": { "1": [{ "id": 2, "order": 1 }, ...] }
 * }
 */
class ReorderCategoriesRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array
     */
    public function rules(): array
    {
        $rules = [
            'parent_menus' => 'required_without:child_menus|array',
            'parent_menus.*.id' => ['required', 'integer', Rule::exists(Category::class, 'id')],
            'parent_menus.*.order' => 'required|integer|min:0',
            'child_menus' => 'required_without:parent_menus|array',
            'child_menus.*' => 'array',
            'child_menus.*.*.id' => ['required', 'integer', Rule::exists(Category::class, 'id')],
            'child_menus.*.*.order' => 'required|integer|min:0',
        ];

        return HookManager::applyFilters('sirsoft-ecommerce.category.reorder_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'parent_menus.required_without' => __('sirsoft-ecommerce::validation.category_reorder.parent_menus_required'),
            'parent_menus.array' => __('sirsoft-ecommerce::validation.category_reorder.parent_menus_array'),
            'parent_menus.*.id.required' => __('sirsoft-ecommerce::validation.category_reorder.id_required'),
            'parent_menus.*.id.integer' => __('sirsoft-ecommerce::validation.category_reorder.id_integer'),
            'parent_menus.*.id.exists' => __('sirsoft-ecommerce::validation.category_reorder.id_exists'),
            'parent_menus.*.order.required' => __('sirsoft-ecommerce::validation.category_reorder.order_required'),
            'parent_menus.*.order.integer' => __('sirsoft-ecommerce::validation.category_reorder.order_integer'),
            'parent_menus.*.order.min' => __('sirsoft-ecommerce::validation.category_reorder.order_min'),
            'child_menus.required_without' => __('sirsoft-ecommerce::validation.category_reorder.parent_menus_required'),
            'child_menus.*.*.id.required' => __('sirsoft-ecommerce::validation.category_reorder.id_required'),
            'child_menus.*.*.id.integer' => __('sirsoft-ecommerce::validation.category_reorder.id_integer'),
            'child_menus.*.*.id.exists' => __('sirsoft-ecommerce::validation.category_reorder.id_exists'),
            'child_menus.*.*.order.required' => __('sirsoft-ecommerce::validation.category_reorder.order_required'),
            'child_menus.*.*.order.integer' => __('sirsoft-ecommerce::validation.category_reorder.order_integer'),
            'child_menus.*.*.order.min' => __('sirsoft-ecommerce::validation.category_reorder.order_min'),
        ];
    }
}
