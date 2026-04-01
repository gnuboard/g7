<?php

namespace App\Http\Requests\User;

use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

class UserListRequest extends FormRequest
{
    /**
     * 검색 가능한 필드 목록
     */
    public const SEARCHABLE_FIELDS = ['name', 'email'];

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
        // 'all'은 전체 필드 검색을 의미하는 특수 값
        $searchableFields = implode(',', array_merge(['all'], self::SEARCHABLE_FIELDS));

        $rules = [
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100',

            // 다중 검색 조건
            'filters' => 'nullable|array|max:10',
            'filters.*.field' => "required_with:filters|string|in:{$searchableFields}",
            'filters.*.value' => 'required_with:filters|string|max:255',
            'filters.*.operator' => 'nullable|string|in:like,eq,starts_with,ends_with',

            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'date_filter' => 'nullable|string|in:all,week,month,custom',
            'sort_by' => 'nullable|string|in:created_at,name,email,last_login_at',
            'sort_order' => 'nullable|string|in:asc,desc',
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.user.list_validation_rules', $rules, $this);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'page.integer' => __('user.validation.page_integer'),
            'page.min' => __('user.validation.page_min'),
            'per_page.integer' => __('user.validation.per_page_integer'),
            'per_page.min' => __('user.validation.per_page_min'),
            'per_page.max' => __('user.validation.per_page_max'),
            'start_date.date' => __('user.validation.start_date_invalid'),
            'end_date.date' => __('user.validation.end_date_invalid'),
            'end_date.after_or_equal' => __('user.validation.end_date_after_start'),
            'date_filter.in' => __('user.validation.date_filter_invalid'),
            'sort_by.in' => __('user.validation.sort_by_invalid'),
            'sort_order.in' => __('user.validation.sort_order_invalid'),
            // 다중 검색 조건 관련 메시지
            'filters.array' => __('user.validation.filters_array'),
            'filters.max' => __('user.validation.filters_max'),
            'filters.*.field.required_with' => __('user.validation.filter_field_required'),
            'filters.*.field.in' => __('user.validation.filter_field_invalid'),
            'filters.*.value.required_with' => __('user.validation.filter_value_required'),
            'filters.*.value.max' => __('user.validation.filter_value_max'),
            'filters.*.operator.in' => __('user.validation.filter_operator_invalid'),
        ];
    }

    /**
     * Prepare the data for validation.
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
            'per_page' => $this->per_page ?? 15,
            'page' => $this->page ?? 1,
            'sort_by' => $this->sort_by ?? 'created_at',
            'sort_order' => $this->sort_order ?? 'desc',
            'date_filter' => $this->date_filter ?? 'all',
        ]);

        // 날짜 필터에 따른 기본 날짜 설정
        if ($this->date_filter === 'week' && ! $this->start_date && ! $this->end_date) {
            $this->merge([
                'start_date' => now()->subWeek()->format('Y-m-d'),
                'end_date' => now()->format('Y-m-d'),
            ]);
        } elseif ($this->date_filter === 'month' && ! $this->start_date && ! $this->end_date) {
            $this->merge([
                'start_date' => now()->subMonth()->format('Y-m-d'),
                'end_date' => now()->format('Y-m-d'),
            ]);
        }
    }
}
