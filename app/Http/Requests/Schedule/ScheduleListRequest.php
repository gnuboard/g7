<?php

namespace App\Http\Requests\Schedule;

use App\Enums\ExtensionOwnerType;
use App\Enums\ScheduleFrequency;
use App\Enums\ScheduleResultStatus;
use App\Enums\ScheduleType;
use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

class ScheduleListRequest extends FormRequest
{
    /**
     * 검색 가능한 필드 목록
     */
    public const SEARCHABLE_FIELDS = ['name', 'description', 'command'];

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
        $searchableFields = implode(',', array_merge(['all'], self::SEARCHABLE_FIELDS));
        $types = implode(',', ScheduleType::values());
        $frequencies = implode(',', ScheduleFrequency::values());
        $results = implode(',', ScheduleResultStatus::values());

        $rules = [
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100',

            // 다중 검색 조건
            'filters' => 'nullable|array|max:10',
            'filters.*.field' => "required_with:filters|string|in:{$searchableFields}",
            'filters.*.value' => 'required_with:filters|string|max:255',
            'filters.*.operator' => 'nullable|string|in:like,eq,starts_with,ends_with',

            // 필터
            'type' => "nullable|string|in:{$types}",
            'frequency' => "nullable|string|in:{$frequencies}",
            'status' => 'nullable|string|in:active,inactive',
            'last_result' => "nullable|string|in:{$results}",
            'without_overlapping' => 'nullable|in:0,1',
            'run_in_maintenance' => 'nullable|in:0,1',
            'extension_type' => 'nullable|string|in:'.implode(',', ExtensionOwnerType::values()),
            'extension_identifier' => 'nullable|string|max:255',

            // 날짜 필터
            'created_from' => 'nullable|date',
            'created_to' => 'nullable|date|after_or_equal:created_from',

            // 정렬
            'sort_by' => 'nullable|string|in:created_at,name,next_run_at,last_run_at,is_active,last_result',
            'sort_order' => 'nullable|string|in:asc,desc',
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.schedule.list_validation_rules', $rules, $this);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'page.integer' => __('schedule.validation.page_integer'),
            'page.min' => __('schedule.validation.page_min'),
            'per_page.integer' => __('schedule.validation.per_page_integer'),
            'per_page.min' => __('schedule.validation.per_page_min'),
            'per_page.max' => __('schedule.validation.per_page_max'),
            'type.in' => __('schedule.validation.type_invalid'),
            'frequency.in' => __('schedule.validation.frequency_invalid'),
            'status.in' => __('schedule.validation.status_invalid'),
            'last_result.in' => __('schedule.validation.last_result_invalid'),
            'created_from.date' => __('schedule.validation.created_from_invalid'),
            'created_to.date' => __('schedule.validation.created_to_invalid'),
            'created_to.after_or_equal' => __('schedule.validation.created_to_after_from'),
            'sort_by.in' => __('schedule.validation.sort_by_invalid'),
            'sort_order.in' => __('schedule.validation.sort_order_invalid'),
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
            $filters = array_values($filters);
            $this->merge(['filters' => $filters ?: null]);
        }

        // 기본값 설정
        $this->merge([
            'per_page' => $this->per_page ?? 15,
            'page' => $this->page ?? 1,
            'sort_by' => $this->sort_by ?? 'created_at',
            'sort_order' => $this->sort_order ?? 'desc',
        ]);
    }
}
