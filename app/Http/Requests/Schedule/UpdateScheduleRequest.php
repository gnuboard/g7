<?php

namespace App\Http\Requests\Schedule;

use App\Enums\ExtensionOwnerType;
use App\Enums\ScheduleFrequency;
use App\Enums\ScheduleType;
use App\Extension\HookManager;
use Illuminate\Foundation\Http\FormRequest;

class UpdateScheduleRequest extends FormRequest
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
        $types = implode(',', ScheduleType::values());
        $frequencies = implode(',', ScheduleFrequency::values());

        $rules = [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'type' => "sometimes|required|string|in:{$types}",
            'command' => 'sometimes|required|string|max:2000',
            'expression' => 'sometimes|required|string|max:100',
            'frequency' => "sometimes|required|string|in:{$frequencies}",
            'without_overlapping' => 'boolean',
            'run_in_maintenance' => 'boolean',
            'timeout' => 'nullable|integer|min:1|max:86400',
            'is_active' => 'boolean',
            'extension_type' => 'nullable|string|in:'.implode(',', ExtensionOwnerType::values()),
            'extension_identifier' => 'nullable|string|max:255|required_with:extension_type',
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.schedule.update_validation_rules', $rules, $this);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => __('schedule.validation.name_required'),
            'name.max' => __('schedule.validation.name_max'),
            'type.required' => __('schedule.validation.type_required'),
            'type.in' => __('schedule.validation.type_invalid'),
            'command.required' => __('schedule.validation.command_required'),
            'command.max' => __('schedule.validation.command_max'),
            'expression.required' => __('schedule.validation.expression_required'),
            'expression.max' => __('schedule.validation.expression_max'),
            'frequency.required' => __('schedule.validation.frequency_required'),
            'frequency.in' => __('schedule.validation.frequency_invalid'),
            'timeout.integer' => __('schedule.validation.timeout_integer'),
            'timeout.min' => __('schedule.validation.timeout_min'),
            'timeout.max' => __('schedule.validation.timeout_max'),
        ];
    }
}
