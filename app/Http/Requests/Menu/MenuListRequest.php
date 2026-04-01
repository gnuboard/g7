<?php

namespace App\Http\Requests\Menu;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 메뉴 목록 조회 요청 검증
 *
 * 메뉴 목록 조회 시 사용되는 필터링, 정렬 조건을 검증합니다.
 */
class MenuListRequest extends FormRequest
{
    /**
     * 검색 가능한 필드 목록
     */
    public const SEARCHABLE_FIELDS = ['name', 'slug', 'url'];

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool 인증 여부
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
        // 'all'은 전체 필드 검색을 의미하는 특수 값
        $searchableFields = implode(',', array_merge(['all'], self::SEARCHABLE_FIELDS));

        $rules = [
            // 활성화 상태 필터
            'is_active' => 'nullable|boolean',

            // 다중 검색 조건
            'filters' => 'nullable|array|max:10',
            'filters.*.field' => "required_with:filters|string|in:{$searchableFields}",
            'filters.*.value' => 'required_with:filters|string|max:255',
            'filters.*.operator' => 'nullable|string|in:like,eq,starts_with,ends_with',

            // 정렬
            'sort_by' => 'nullable|string|in:created_at,name,slug,order',
            'sort_order' => 'nullable|string|in:asc,desc',
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.menu.list_validation_rules', $rules, $this);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'is_active.boolean' => __('menu.validation.is_active_boolean'),
            'sort_by.in' => __('menu.validation.sort_by_invalid'),
            'sort_order.in' => __('menu.validation.sort_order_invalid'),
            // 다중 검색 조건 관련 메시지
            'filters.array' => __('menu.validation.filters_array'),
            'filters.max' => __('menu.validation.filters_max'),
            'filters.*.field.required_with' => __('menu.validation.filter_field_required'),
            'filters.*.field.in' => __('menu.validation.filter_field_invalid'),
            'filters.*.value.required_with' => __('menu.validation.filter_value_required'),
            'filters.*.value.max' => __('menu.validation.filter_value_max'),
            'filters.*.operator.in' => __('menu.validation.filter_operator_invalid'),
        ];
    }

    /**
     * Prepare the data for validation.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // value가 비어있는 filters 제거
        $filters = $this->filters;
        if (is_array($filters)) {
            $filters = array_filter($filters, function ($filter) {
                return ! empty($filter['value']);
            });
            // 인덱스 재정렬
            $filters = array_values($filters);
            $this->merge(['filters' => $filters ?: null]);
        }

        // 기본값 설정
        $this->merge([
            'sort_by' => $this->sort_by ?? 'order',
            'sort_order' => $this->sort_order ?? 'asc',
        ]);

        // is_active 파라미터 처리 (문자열 'true'/'false'를 boolean으로 변환)
        if ($this->has('is_active') && $this->is_active !== null) {
            $isActive = $this->is_active;
            if (is_string($isActive)) {
                $isActive = filter_var($isActive, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }
            $this->merge(['is_active' => $isActive]);
        }
    }
}