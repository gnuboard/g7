<?php

namespace App\Http\Requests\Menu;

use App\Extension\HookManager;
use App\Models\Menu;
use App\Rules\NotCircularParent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMenuOrderRequest extends FormRequest
{
    /**
     * 사용자가 이 요청을 수행할 권한이 있는지 확인
     *
     * 권한 체크는 라우트의 permission 미들웨어에서 수행됩니다.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 요청에 적용할 검증 규칙
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'parent_menus' => 'required|array|min:1',
            'parent_menus.*.id' => ['required', 'integer', Rule::exists(Menu::class, 'id')],
            'parent_menus.*.order' => 'required|integer|min:1',
            'child_menus' => 'sometimes|array',
            'child_menus.*.*.id' => ['required', 'integer', Rule::exists(Menu::class, 'id')],
            'child_menus.*.*.order' => 'required|integer|min:1',
            'moved_items' => 'sometimes|array',
            'moved_items.*.id' => ['required', 'integer', Rule::exists(Menu::class, 'id')],
            'moved_items.*.new_parent_id' => ['nullable', 'integer', Rule::exists(Menu::class, 'id')],
        ];

        // moved_items 각 항목에 순환 참조 검증 추가
        $movedItems = $this->input('moved_items', []);
        foreach ($movedItems as $index => $movedItem) {
            if (isset($movedItem['id'])) {
                $rules["moved_items.{$index}.new_parent_id"][] = new NotCircularParent((int) $movedItem['id']);
            }
        }

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.menu.update_order_validation_rules', $rules, $this);
    }

    /**
     * 검증 오류 메시지 커스터마이징
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parent_menus.required' => __('validation.menu.parent_menus.required'),
            'parent_menus.array' => __('validation.menu.parent_menus.array'),
            'parent_menus.min' => __('validation.menu.parent_menus.min'),
            'parent_menus.*.id.required' => __('validation.menu.parent_menus.id.required'),
            'parent_menus.*.id.integer' => __('validation.menu.parent_menus.id.integer'),
            'parent_menus.*.id.exists' => __('validation.menu.parent_menus.id.exists'),
            'parent_menus.*.order.required' => __('validation.menu.parent_menus.order.required'),
            'parent_menus.*.order.integer' => __('validation.menu.parent_menus.order.integer'),
            'parent_menus.*.order.min' => __('validation.menu.parent_menus.order.min'),
            'child_menus.array' => __('validation.menu.child_menus.array'),
            'child_menus.*.*.id.required' => __('validation.menu.child_menus.id.required'),
            'child_menus.*.*.id.integer' => __('validation.menu.child_menus.id.integer'),
            'child_menus.*.*.id.exists' => __('validation.menu.child_menus.id.exists'),
            'child_menus.*.*.order.required' => __('validation.menu.child_menus.order.required'),
            'child_menus.*.*.order.integer' => __('validation.menu.child_menus.order.integer'),
            'child_menus.*.*.order.min' => __('validation.menu.child_menus.order.min'),
            'moved_items.array' => __('validation.menu.moved_items.array'),
            'moved_items.*.id.required' => __('validation.menu.moved_items.id.required'),
            'moved_items.*.id.integer' => __('validation.menu.moved_items.id.integer'),
            'moved_items.*.id.exists' => __('validation.menu.moved_items.id.exists'),
            'moved_items.*.new_parent_id.integer' => __('validation.menu.moved_items.new_parent_id.integer'),
            'moved_items.*.new_parent_id.exists' => __('validation.menu.moved_items.new_parent_id.exists'),
        ];
    }
}
