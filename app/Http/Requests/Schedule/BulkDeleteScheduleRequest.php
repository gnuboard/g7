<?php

namespace App\Http\Requests\Schedule;

use App\Extension\HookManager;
use App\Models\Schedule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkDeleteScheduleRequest extends FormRequest
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
        $rules = [
            'ids' => 'required|array|min:1',
            'ids.*' => ['required', 'integer', Rule::exists(Schedule::class, 'id')],
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.schedule.bulk_delete_validation_rules', $rules, $this);
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ids.required' => __('schedule.validation.ids_required'),
            'ids.array' => __('schedule.validation.ids_array'),
            'ids.min' => __('schedule.validation.ids_min'),
            'ids.*.integer' => __('schedule.validation.id_integer'),
            'ids.*.exists' => __('schedule.validation.id_exists'),
        ];
    }
}
