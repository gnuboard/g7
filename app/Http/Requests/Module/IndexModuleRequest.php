<?php

namespace App\Http\Requests\Module;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 모듈 목록 조회 요청
 *
 * 다중 검색 조건을 지원합니다.
 * - search: 단일 검색어 (이름, 식별자, 설명, 벤더 OR 검색) - 하위 호환성
 * - filters: 다중 검색 조건 (AND 조건)
 * - with[]: 추가 데이터 포함 (예: custom_menus)
 */
class IndexModuleRequest extends FormRequest
{
    /**
     * 검색 가능한 필드 목록
     */
    public const SEARCHABLE_FIELDS = ['name', 'identifier', 'description', 'vendor'];

    /**
     * with 파라미터로 요청 가능한 추가 데이터 목록
     */
    public const ALLOWED_WITH_OPTIONS = ['custom_menus'];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $searchableFields = implode(',', self::SEARCHABLE_FIELDS);
        $allowedWithOptions = implode(',', self::ALLOWED_WITH_OPTIONS);

        $rules = [
            // 단일 검색어 (하위 호환성 - 이름, 식별자, 설명, 벤더 OR 검색)
            'search' => 'nullable|string|max:255',

            // 다중 검색 조건
            'filters' => 'nullable|array|max:10',
            'filters.*.field' => "required_with:filters|string|in:{$searchableFields}",
            'filters.*.value' => 'required_with:filters|string|max:255',
            'filters.*.operator' => 'nullable|string|in:like,eq,starts_with,ends_with',

            // 상태 필터
            'status' => 'nullable|string|in:installed,not_installed,active,inactive',

            // 추가 데이터 포함
            'with' => 'nullable|array|max:5',
            'with.*' => "string|in:{$allowedWithOptions}",

            // 페이지네이션
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',

            // 숨김 항목 포함 여부 (manifest hidden=true 확장)
            'include_hidden' => 'nullable|boolean',
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.module.index_validation_rules', $rules, $this);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'search.max' => __('modules.validation.search_max'),
            'filters.max' => __('modules.validation.filters_max'),
            'filters.*.field.required_with' => __('modules.validation.filter_field_required'),
            'filters.*.field.in' => __('modules.validation.filter_field_invalid'),
            'filters.*.value.required_with' => __('modules.validation.filter_value_required'),
            'filters.*.value.max' => __('modules.validation.filter_value_max'),
            'filters.*.operator.in' => __('modules.validation.filter_operator_invalid'),
            'status.in' => __('modules.validation.status_invalid'),
            'with.max' => __('modules.validation.with_max'),
            'with.*.in' => __('modules.validation.with_invalid'),
            'per_page.min' => __('modules.validation.per_page_min'),
            'per_page.max' => __('modules.validation.per_page_max'),
            'page.min' => __('modules.validation.page_min'),
        ];
    }

    /**
     * with 파라미터에 특정 옵션이 포함되어 있는지 확인합니다.
     *
     * @param  string  $option  확인할 옵션명
     * @return bool 포함 여부
     */
    public function hasWithOption(string $option): bool
    {
        $withOptions = $this->validated('with', []);

        return in_array($option, $withOptions, true);
    }
}
