<?php

namespace App\Http\Requests\Plugin;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 플러그인 목록 조회 요청
 *
 * 다중 검색 조건을 지원합니다.
 * - search: 단일 검색어 (이름, 식별자, 설명, 벤더 OR 검색) - 하위 호환성
 * - filters: 다중 검색 조건 (AND 조건)
 */
class IndexPluginRequest extends FormRequest
{
    /**
     * 검색 가능한 필드 목록
     */
    public const SEARCHABLE_FIELDS = ['name', 'identifier', 'description', 'vendor'];

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

        $rules = [
            // 단일 검색어 (하위 호환성 - 이름, 식별자, 설명, 벤더 OR 검색)
            'search' => 'nullable|string|max:255',

            // 다중 검색 조건
            'filters' => 'nullable|array|max:10',
            'filters.*.field' => "required_with:filters|string|in:{$searchableFields}",
            'filters.*.value' => 'required_with:filters|string|max:255',
            'filters.*.operator' => 'nullable|string|in:like,eq,starts_with,ends_with',

            // 상태 필터
            'status' => 'nullable|string|in:installed,uninstalled,active,inactive',

            // 페이지네이션
            'per_page' => 'nullable|integer|min:1|max:100',
            'page' => 'nullable|integer|min:1',

            // 숨김 항목 포함 여부 (manifest hidden=true 확장)
            'include_hidden' => 'nullable|boolean',
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.plugin.index_validation_rules', $rules, $this);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'search.max' => __('plugins.validation.search_max'),
            'filters.max' => __('plugins.validation.filters_max'),
            'filters.*.field.required_with' => __('plugins.validation.filter_field_required'),
            'filters.*.field.in' => __('plugins.validation.filter_field_invalid'),
            'filters.*.value.required_with' => __('plugins.validation.filter_value_required'),
            'filters.*.value.max' => __('plugins.validation.filter_value_max'),
            'filters.*.operator.in' => __('plugins.validation.filter_operator_invalid'),
            'status.in' => __('plugins.validation.status_invalid'),
            'per_page.min' => __('plugins.validation.per_page_min'),
            'per_page.max' => __('plugins.validation.per_page_max'),
            'page.min' => __('plugins.validation.page_min'),
        ];
    }
}
