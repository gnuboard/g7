<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 레이아웃 확장 정의 구조 검증
 *
 * 모듈/플러그인이 레이아웃에 주입하는 확장 정의(extension_point / overlay)의
 * JSON 구조를 검증합니다.
 *
 * - extension_point 타입: `extension_point` 키 + `components` 배열
 * - overlay 타입: `target_layout` 키 + `injections` 배열
 * - 두 키는 상호 배타적 (xor)
 */
class ValidLayoutExtensionStructure implements ValidationRule
{
    /**
     * 최대 중첩 깊이
     */
    private const MAX_DEPTH = 10;

    /**
     * 레이아웃 확장 JSON 스키마 검증
     *
     * @param  string  $attribute  속성명
     * @param  mixed  $value  검증 대상 값
     * @param  Closure  $fail  실패 콜백
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // JSON 문자열인 경우 디코딩
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $fail(__('validation.layout_extension.invalid_json'));

                return;
            }
            $value = $decoded;
        }

        if (! is_array($value)) {
            $fail(__('validation.layout_extension.must_be_array'));

            return;
        }

        // extension_point xor target_layout
        $hasExtensionPoint = isset($value['extension_point']);
        $hasTargetLayout = isset($value['target_layout']);

        if (! $hasExtensionPoint && ! $hasTargetLayout) {
            $fail(__('validation.layout_extension.target_required'));

            return;
        }

        if ($hasExtensionPoint && $hasTargetLayout) {
            $fail(__('validation.layout_extension.target_exclusive'));

            return;
        }

        if ($hasExtensionPoint) {
            $this->validateExtensionPoint($value, $fail);
        } else {
            $this->validateOverlay($value, $fail);
        }
    }

    /**
     * extension_point 타입 검증
     *
     * @param  array  $data  확장 정의
     * @param  Closure  $fail  실패 콜백
     */
    private function validateExtensionPoint(array $data, Closure $fail): void
    {
        if (! is_string($data['extension_point']) || $data['extension_point'] === '') {
            $fail(__('validation.layout_extension.extension_point_invalid'));

            return;
        }

        // components 검증 (선택 — 빈 확장점도 허용)
        if (isset($data['components'])) {
            if (! is_array($data['components'])) {
                $fail(__('validation.layout_extension.components_must_be_array'));

                return;
            }

            foreach ($data['components'] as $index => $component) {
                if (! $this->validateComponent($component, $index, $fail)) {
                    return;
                }
            }
        }

        $this->validateOptionalSections($data, $fail);
    }

    /**
     * overlay 타입 검증
     *
     * @param  array  $data  확장 정의
     * @param  Closure  $fail  실패 콜백
     */
    private function validateOverlay(array $data, Closure $fail): void
    {
        if (! is_string($data['target_layout']) || $data['target_layout'] === '') {
            $fail(__('validation.layout_extension.target_layout_invalid'));

            return;
        }

        // injections 검증
        if (! isset($data['injections'])) {
            $fail(__('validation.layout_extension.injections_required'));

            return;
        }

        if (! is_array($data['injections'])) {
            $fail(__('validation.layout_extension.injections_must_be_array'));

            return;
        }

        foreach ($data['injections'] as $index => $injection) {
            if (! $this->validateInjection($injection, $index, $fail)) {
                return;
            }
        }

        $this->validateOptionalSections($data, $fail);
    }

    /**
     * 단일 injection 항목 검증
     *
     * @param  mixed  $injection  injection 정의
     * @param  int|string  $index  인덱스
     * @param  Closure  $fail  실패 콜백
     * @return bool 유효 여부
     */
    private function validateInjection(mixed $injection, int|string $index, Closure $fail): bool
    {
        if (! is_array($injection)) {
            $fail(__('validation.layout_extension.injection_must_be_array', ['index' => $index]));

            return false;
        }

        // target_id 필수
        if (! isset($injection['target_id']) || ! is_string($injection['target_id'])) {
            $fail(__('validation.layout_extension.injection_target_id_required', ['index' => $index]));

            return false;
        }

        // position 검증 (선택 — 기본 append_child)
        $validPositions = ['prepend', 'append', 'prepend_child', 'append_child', 'replace', 'inject_props'];
        $position = $injection['position'] ?? 'append_child';
        if (! in_array($position, $validPositions, true)) {
            $fail(__('validation.layout_extension.injection_position_invalid', ['index' => $index]));

            return false;
        }

        // inject_props 가 아니면 components 검증
        if ($position !== 'inject_props' && isset($injection['components'])) {
            if (! is_array($injection['components'])) {
                $fail(__('validation.layout_extension.injection_components_must_be_array', ['index' => $index]));

                return false;
            }

            foreach ($injection['components'] as $compIndex => $component) {
                if (! $this->validateComponent($component, "{$index}.{$compIndex}", $fail)) {
                    return false;
                }
            }
        }

        // inject_props 는 props 배열 검증
        if ($position === 'inject_props' && isset($injection['props']) && ! is_array($injection['props'])) {
            $fail(__('validation.layout_extension.injection_props_must_be_array', ['index' => $index]));

            return false;
        }

        return true;
    }

    /**
     * 선택적 섹션(data_sources, modals, scripts, computed, state, init_actions) 타입 검증
     *
     * @param  array  $data  확장 정의
     * @param  Closure  $fail  실패 콜백
     */
    private function validateOptionalSections(array $data, Closure $fail): void
    {
        foreach (['data_sources', 'modals', 'scripts', 'computed', 'state', 'init_actions'] as $section) {
            if (isset($data[$section]) && ! is_array($data[$section])) {
                $fail(__('validation.layout_extension.section_must_be_array', ['section' => $section]));

                return;
            }
        }
    }

    /**
     * 개별 컴포넌트 노드 검증 (ValidLayoutStructure 재귀 로직 참고)
     *
     * @param  mixed  $component  컴포넌트 노드
     * @param  int|string  $index  인덱스
     * @param  Closure  $fail  실패 콜백
     * @param  int  $depth  현재 깊이
     * @return bool 유효 여부
     */
    private function validateComponent(mixed $component, int|string $index, Closure $fail, int $depth = 0): bool
    {
        if ($depth > self::MAX_DEPTH) {
            $fail(__('validation.layout_extension.max_depth_exceeded', ['max' => self::MAX_DEPTH]));

            return false;
        }

        if (! is_array($component)) {
            $fail(__('validation.layout_extension.component_must_be_array', ['index' => $index]));

            return false;
        }

        // 슬롯/Partial 참조는 type/name 검증 건너뜀
        if ((isset($component['slot']) && is_string($component['slot']))
            || (isset($component['partial']) && is_string($component['partial']))) {
            return true;
        }

        // extension_point 노드는 type 만 있으면 됨 (중첩 확장점 허용)
        if (($component['type'] ?? '') === 'extension_point') {
            return true;
        }

        foreach (['type', 'name'] as $field) {
            if (! isset($component[$field])) {
                $fail(__('validation.layout_extension.component_required_field_missing', ['index' => $index, 'field' => $field]));

                return false;
            }
        }

        if (! is_string($component['name'])) {
            $fail(__('validation.layout_extension.component_name_must_be_string', ['index' => $index]));

            return false;
        }

        if (! in_array($component['type'], ['basic', 'composite', 'layout'], true)) {
            $fail(__('validation.layout_extension.component_type_invalid', ['index' => $index]));

            return false;
        }

        if (isset($component['props']) && ! is_array($component['props']) && ! is_object($component['props'])) {
            $fail(__('validation.layout_extension.props_must_be_object', ['index' => $index]));

            return false;
        }

        // children 재귀 검증
        if (isset($component['children'])) {
            if (! is_array($component['children'])) {
                $fail(__('validation.layout_extension.children_must_be_array', ['index' => $index]));

                return false;
            }

            foreach ($component['children'] as $childIndex => $child) {
                if (! $this->validateComponent($child, "{$index}.children[{$childIndex}]", $fail, $depth + 1)) {
                    return false;
                }
            }
        }

        return true;
    }
}
