<?php

namespace App\Http\Requests\Schedule;

use App\Extension\HookManager;
use App\Models\Schedule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkDeleteScheduleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool 권한 검사는 라우트의 permission 미들웨어가 담당하므로 항상 true
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'ids' => 'required|array|min:1',
            'ids.*' => [
                'required',
                'integer',
                Rule::exists(Schedule::class, 'id'),
                // 코어·확장 소유 행은 선언 동기화가 다시 만든다 — 지울 수 없다(비활성으로 끈다)
                Rule::exists(Schedule::class, 'id')->whereNull('extension_type'),
            ],
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
            'ids.*.exists' => __('schedule.validation.id_exists_or_owned'),
        ];
    }
}
