<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidLayoutStructure implements ValidationRule
{
    /**
     * 최대 중첩 깊이
     */
    private const MAX_DEPTH = 10;

    /**
     * 레이아웃 JSON 스키마 검증
     *
     * 필수 필드, 재귀적 children 구조, 데이터 타입, actions 배열 구조를 검증합니다.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // JSON 문자열인 경우 디코딩
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $fail(__('validation.layout.invalid_json'));

                return;
            }
            $value = $decoded;
        }

        // 배열이 아닌 경우
        if (! is_array($value)) {
            $fail(__('validation.layout.must_be_array'));

            return;
        }

        // 최상위 필수 필드 검증
        if (! $this->validateRequiredFields($value, $fail)) {
            return;
        }

        // components 필드 검증 (존재하는 경우만)
        if (isset($value['components']) && ! $this->validateComponents($value, $fail)) {
            return;
        }
    }

    /**
     * 최상위 필수 필드 검증
     */
    private function validateRequiredFields(array $data, Closure $fail): bool
    {
        // 기본 필수 필드
        $requiredFields = ['version', 'layout_name'];

        foreach ($requiredFields as $field) {
            if (! isset($data[$field])) {
                $fail(__('validation.layout.required_field_missing', ['field' => $field]));

                return false;
            }
        }

        // version 타입 검증
        if (! is_string($data['version'])) {
            $fail(__('validation.layout.version_must_be_string'));

            return false;
        }

        // layout_name 타입 검증
        if (! is_string($data['layout_name'])) {
            $fail(__('validation.layout.layout_name_must_be_string'));

            return false;
        }

        // extends가 있는 경우 (상속 레이아웃) - components나 slots 중 하나는 있어야 함
        if (isset($data['extends'])) {
            if (! isset($data['components']) && ! isset($data['slots'])) {
                $fail(__('validation.layout.components_or_slots_required'));

                return false;
            }
        } else {
            // extends가 없는 경우 (base 레이아웃) - components 필수
            if (! isset($data['components'])) {
                $fail(__('validation.layout.required_field_missing', ['field' => 'components']));

                return false;
            }
        }

        // components 타입 검증 (존재하는 경우)
        if (isset($data['components']) && ! is_array($data['components'])) {
            $fail(__('validation.layout.components_must_be_array'));

            return false;
        }

        return true;
    }

    /**
     * components 배열 검증
     */
    private function validateComponents(array $data, Closure $fail): bool
    {
        $components = $data['components'];

        foreach ($components as $index => $component) {
            if (! $this->validateComponent($component, $index, $fail)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 개별 컴포넌트 검증
     *
     * children 내에 올 수 있는 요소 타입:
     * - 일반 컴포넌트: type, name 필수
     * - 슬롯 참조: { "slot": "slotName" }
     * - Partial 참조: { "partial": "path/to/file.json" }
     */
    private function validateComponent(mixed $component, int|string $index, Closure $fail, int $depth = 0): bool
    {
        // 중첩 깊이 제한 검증
        if ($depth > self::MAX_DEPTH) {
            $fail(__('validation.layout.max_depth_exceeded', ['max' => self::MAX_DEPTH]));

            return false;
        }

        // 컴포넌트는 배열이어야 함
        if (! is_array($component)) {
            $fail(__('validation.layout.component_must_be_array', ['index' => $index]));

            return false;
        }

        // 슬롯 참조인 경우 (slot 필드만 있음) - type/name 검증 건너뜀
        if (isset($component['slot']) && is_string($component['slot'])) {
            return true;
        }

        // Partial 참조인 경우 (partial 필드만 있음) - type/name 검증 건너뜀
        if (isset($component['partial']) && is_string($component['partial'])) {
            return true;
        }

        // 필수 필드 검증 (id, props는 선택적 필드)
        $requiredComponentFields = ['type', 'name'];

        foreach ($requiredComponentFields as $field) {
            if (! isset($component[$field])) {
                $fail(__('validation.layout.component_required_field_missing', ['index' => $index, 'field' => $field]));

                return false;
            }
        }

        // name 필드 타입 검증
        if (! is_string($component['name'])) {
            $fail(__('validation.layout.component_name_must_be_string', ['index' => $index]));

            return false;
        }

        // type 필드 검증
        if (! in_array($component['type'], ['basic', 'composite', 'layout'])) {
            $fail(__('validation.layout.component_type_invalid', ['index' => $index]));

            return false;
        }

        // props 필드 타입 검증 (선택적 필드)
        if (isset($component['props']) && ! is_array($component['props']) && ! is_object($component['props'])) {
            $fail(__('validation.layout.props_must_be_object', ['index' => $index]));

            return false;
        }

        // children 필드 검증 (선택적)
        if (isset($component['children'])) {
            if (! is_array($component['children'])) {
                $fail(__('validation.layout.children_must_be_array', ['index' => $index]));

                return false;
            }

            // 재귀적으로 children 검증
            foreach ($component['children'] as $childIndex => $child) {
                if (! $this->validateComponent($child, "{$index}.children[{$childIndex}]", $fail, $depth + 1)) {
                    return false;
                }
            }
        }

        // permissions 필드 검증 (선택적 - 컴포넌트별 권한 제어, OR/AND 구조 지원)
        if (isset($component['permissions'])) {
            $permissionRule = new ValidPermissionStructure((string) $index);
            $permissionFailed = false;
            $permissionRule->validate(
                "components[{$index}].permissions",
                $component['permissions'],
                function ($message) use ($fail, &$permissionFailed) {
                    $fail($message);
                    $permissionFailed = true;
                }
            );

            if ($permissionFailed) {
                return false;
            }
        }

        // actions 필드 검증 (선택적)
        if (isset($component['actions'])) {
            if (! $this->validateActions($component['actions'], $index, $fail)) {
                return false;
            }
        }

        return true;
    }

    /**
     * actions 배열 검증
     *
     * type 또는 event 중 하나가 필수입니다:
     * - type: 표준 DOM 이벤트 (click, change, submit 등)
     * - event: 커스텀 콜백 이벤트 (onChange, onRowAction 등)
     */
    private function validateActions(mixed $actions, int|string $componentIndex, Closure $fail): bool
    {
        if (! is_array($actions)) {
            $fail(__('validation.layout.actions_must_be_array', ['index' => $componentIndex]));

            return false;
        }

        foreach ($actions as $actionIndex => $action) {
            if (! is_array($action)) {
                $fail(__('validation.layout.action_must_be_array', ['index' => $componentIndex, 'actionIndex' => $actionIndex]));

                return false;
            }

            // type 또는 event 중 하나는 필수
            $hasType = isset($action['type']);
            $hasEvent = isset($action['event']);

            if (! $hasType && ! $hasEvent) {
                $fail(__('validation.layout.action_type_or_event_missing', ['index' => $componentIndex, 'actionIndex' => $actionIndex]));

                return false;
            }

            // type이 있으면 문자열이어야 함
            if ($hasType && ! is_string($action['type'])) {
                $fail(__('validation.layout.action_type_must_be_string', ['index' => $componentIndex, 'actionIndex' => $actionIndex]));

                return false;
            }

            // event가 있으면 문자열이어야 함
            if ($hasEvent && ! is_string($action['event'])) {
                $fail(__('validation.layout.action_event_must_be_string', ['index' => $componentIndex, 'actionIndex' => $actionIndex]));

                return false;
            }
        }

        return true;
    }
}
