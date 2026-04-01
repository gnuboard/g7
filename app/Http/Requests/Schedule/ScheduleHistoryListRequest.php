<?php

namespace App\Http\Requests\Schedule;

use App\Enums\ScheduleResultStatus;
use App\Enums\ScheduleTriggerType;
use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

class ScheduleHistoryListRequest extends FormRequest
{
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
        $statuses = implode(',', array_diff(ScheduleResultStatus::values(), ['never']));
        $triggerTypes = implode(',', ScheduleTriggerType::values());

        $rules = [
            'page' => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100',

            // 필터
            'status' => "nullable|string|in:{$statuses}",
            'trigger_type' => "nullable|string|in:{$triggerTypes}",

            // 날짜 필터
            'started_from' => 'nullable|date',
            'started_to' => 'nullable|date|after_or_equal:started_from',

            // 정렬
            'sort_by' => 'nullable|string|in:started_at,ended_at,duration,status',
            'sort_order' => 'nullable|string|in:asc,desc',
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.schedule.history_list_validation_rules', $rules, $this);
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
            'status.in' => __('schedule.validation.history_status_invalid'),
            'trigger_type.in' => __('schedule.validation.trigger_type_invalid'),
            'started_from.date' => __('schedule.validation.started_from_invalid'),
            'started_to.date' => __('schedule.validation.started_to_invalid'),
            'started_to.after_or_equal' => __('schedule.validation.started_to_after_from'),
            'sort_by.in' => __('schedule.validation.sort_by_invalid'),
            'sort_order.in' => __('schedule.validation.sort_order_invalid'),
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'per_page' => $this->per_page ?? 15,
            'page' => $this->page ?? 1,
            'sort_by' => $this->sort_by ?? 'started_at',
            'sort_order' => $this->sort_order ?? 'desc',
        ]);
    }
}
