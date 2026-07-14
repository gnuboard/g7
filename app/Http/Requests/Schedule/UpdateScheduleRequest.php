<?php

namespace App\Http\Requests\Schedule;

use App\Enums\ExtensionOwnerType;
use App\Enums\ScheduleFrequency;
use App\Enums\ScheduleType;
use App\Extension\HookManager;
use App\Models\Schedule;
use App\Rules\AllowedArtisanCommand;
use App\Rules\AllowedShellCommand;
use App\Rules\PublicOutboundUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateScheduleRequest extends FormRequest
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
        $types = implode(',', ScheduleType::values());
        $frequencies = implode(',', ScheduleFrequency::values());

        $rules = [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'type' => "sometimes|required|string|in:{$types}",
            // command 는 타입별로 서버의 outbound 목적지(URL)·OS 명령(Shell)·PHP 코드(Artisan)가 되므로
            // 각 타입에 맞는 실행 허용 검증을 저장 시점에 건다
            'command' => [
                'sometimes',
                'required',
                'string',
                'max:2000',
                Rule::when($this->resolvesToScheduleType(ScheduleType::Url), [new PublicOutboundUrl]),
                Rule::when($this->resolvesToScheduleType(ScheduleType::Shell), [new AllowedShellCommand]),
                Rule::when($this->resolvesToScheduleType(ScheduleType::Artisan), [new AllowedArtisanCommand]),
            ],
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
     * 이번 수정 결과 스케줄이 주어진 타입이 되는지 판정합니다.
     *
     * PATCH 라 `type` 이 요청에 없을 수 있으므로, 없으면 저장된 스케줄의 타입을 기준으로
     * 판정한다 — 그렇지 않으면 기존 스케줄의 command 만 바꾸는 요청이 검증을 비껴간다.
     *
     * @param  ScheduleType  $type  판정할 스케줄 타입
     * @return bool 해당 타입의 스케줄이면 true
     */
    protected function resolvesToScheduleType(ScheduleType $type): bool
    {
        if ($this->has('type')) {
            return $this->input('type') === $type->value;
        }

        $schedule = $this->route('schedule');

        return $schedule instanceof Schedule && $schedule->type === $type;
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
