<?php

namespace App\Http\Requests\Schedule;

use App\Enums\ScheduleFrequency;
use App\Enums\ScheduleType;
use App\Extension\HookManager;
use App\Http\Requests\Schedule\Concerns\ValidatesScheduleRegistryFields;
use App\Models\Schedule;
use App\Rules\AllowedArtisanCommand;
use App\Rules\AllowedShellCommand;
use App\Rules\LocaleRequiredTranslatable;
use App\Rules\PublicOutboundUrl;
use App\Rules\TranslatableField;
use BackedEnum;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 스케줄 수정 요청
 *
 * 코어·확장 소유 행은 선언이 정한 명령·유형·이름·시간대를 바꿀 수 없다(`OWNED_LOCKED_FIELDS`).
 * 화면은 폼 전체를 보내므로, 저장값과 같은 잠금 필드는 검증 전에 빼고 **실제로 바꾸려 할 때만** 거부한다.
 */
class UpdateScheduleRequest extends FormRequest
{
    use ValidatesScheduleRegistryFields;

    /**
     * 소유 행(core/module/plugin)에서 운영자가 바꿀 수 없는 필드.
     *
     * `enabled_condition` 은 모든 행에서 입력 금지지만, 화면이 읽기 전용 배지로 그리도록 목록에 함께 둔다.
     *
     * @var array<int, string>
     */
    public const OWNED_LOCKED_FIELDS = ['command', 'type', 'name', 'enabled_condition', 'timezone'];

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
            // 직접 등록은 명칭이 필수(사이트 기본 언어 값), 소유 행은 비우면 이름으로 표시된다
            'label' => $this->isOwnedSchedule()
                ? ['nullable', new TranslatableField(maxLength: 255)]
                : ['sometimes', 'required', new LocaleRequiredTranslatable(maxLength: 255)],
            'description' => ['nullable', new TranslatableField(maxLength: 1000)],
            'type' => "sometimes|required|string|in:{$types}",
            // command 는 타입별로 서버의 outbound 목적지(URL)·OS 명령(Shell)·PHP 코드(Artisan)·
            // 큐 Job 클래스(Job)가 되므로 각 타입에 맞는 실행 허용 검증을 저장 시점에 건다
            'command' => [
                'sometimes',
                'required',
                'string',
                'max:2000',
                Rule::when($this->resolvesToScheduleType(ScheduleType::Url), [new PublicOutboundUrl]),
                Rule::when($this->resolvesToScheduleType(ScheduleType::Shell), [new AllowedShellCommand]),
                Rule::when($this->resolvesToScheduleType(ScheduleType::Artisan), [new AllowedArtisanCommand]),
                Rule::when($this->isJobType(), [$this->allowedJobClassRule()]),
            ],
            'expression' => 'sometimes|required|string|max:100',
            'frequency' => "sometimes|required|string|in:{$frequencies}",
            'run_in_maintenance' => 'boolean',
            'timeout' => 'nullable|integer|min:1|max:86400',
            'is_active' => 'boolean',
            ...$this->registryRules(partial: true),
        ];

        // 소유 행의 잠금 필드는 (값이 실제로 바뀌는 경우에만 남아 있으므로) 들어오면 거부한다
        if ($this->isOwnedSchedule()) {
            foreach (self::OWNED_LOCKED_FIELDS as $field) {
                $rules[$field] = ['prohibited'];
            }
        }

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.schedule.update_validation_rules', $rules, $this);
    }

    /**
     * 수정 대상이 코어·확장 소유 행인지 판정합니다.
     *
     * @return bool 소유 행이면 true
     */
    protected function isOwnedSchedule(): bool
    {
        $schedule = $this->route('schedule');

        return $schedule instanceof Schedule && $schedule->isOwned();
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
            ...$this->registryMessages(),
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string> 필드 => 표시 이름
     */
    public function attributes(): array
    {
        return $this->registryAttributes();
    }

    /**
     * Prepare the data for validation.
     *
     * 소유 행이면 잠금 필드 중 저장값과 같은 것을 요청에서 뺀다(폼이 전체를 다시 보내도 통과).
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeRegistryInput();

        $schedule = $this->route('schedule');
        if (! $schedule instanceof Schedule || ! $schedule->isOwned()) {
            return;
        }

        $unchanged = [];
        foreach (self::OWNED_LOCKED_FIELDS as $field) {
            if ($this->has($field) && $this->sameAsStored($schedule, $field, $this->input($field))) {
                $unchanged[] = $field;
            }
        }

        if ($unchanged !== []) {
            $this->replace($this->except($unchanged));
        }
    }

    /**
     * 요청 값이 저장된 값과 같은지 비교합니다(Enum 은 값으로, 빈 문자열은 null 로 본다).
     *
     * @param  Schedule  $schedule  수정 대상
     * @param  string  $field  필드명
     * @param  mixed  $value  요청 값
     * @return bool 같으면 true
     */
    private function sameAsStored(Schedule $schedule, string $field, mixed $value): bool
    {
        $stored = $schedule->getAttribute($field);
        $stored = $stored instanceof BackedEnum ? $stored->value : $stored;

        $normalize = fn (mixed $v) => $v === '' ? null : $v;

        if (is_array($stored) || is_array($value)) {
            return $normalize($value) == $normalize($stored);
        }

        return $normalize($value) === $normalize($stored === null ? null : (string) $stored);
    }
}
