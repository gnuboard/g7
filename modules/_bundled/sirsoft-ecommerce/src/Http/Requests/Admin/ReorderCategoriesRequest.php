<?php

namespace Modules\Sirsoft\Ecommerce\Http\Requests\Admin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Sirsoft\Ecommerce\Models\Category;
use Modules\Sirsoft\Ecommerce\Rules\NotCircularCategoryParent;

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
     *
     * @return bool 권한 검증 결과 (항상 true)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, mixed> 필드별 검증 규칙
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
     * 추가 검증 — 자식으로 지정된 카테고리가 대상 부모의 조상이면 순환이 된다.
     *
     * 개별 이동 단위로 현재 DB 상태와 대조한다. 한 요청 안의 여러 이동이 누적되어 만들어지는
     * 순환은 CategoryService 의 방문 ID 가드가 최종 방어한다.
     *
     * @param  Validator  $validator  검증기
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            foreach ((array) $this->input('child_menus', []) as $parentId => $children) {
                foreach ((array) $children as $index => $child) {
                    $childId = $child['id'] ?? null;

                    if ($childId === null) {
                        continue;
                    }

                    $attribute = "child_menus.{$parentId}.{$index}.id";

                    (new NotCircularCategoryParent((int) $childId))->validate(
                        $attribute,
                        (int) $parentId,
                        fn (string $message) => $validator->errors()->add($attribute, $message)
                    );
                }
            }
        });
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string> 규칙별 다국어 에러 메시지
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
