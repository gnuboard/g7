<?php

namespace App\Http\Requests\Schedule;

use App\Enums\ScheduleFrequency;
use App\Enums\ScheduleType;
use App\Extension\HookManager;
use App\Http\Requests\Schedule\Concerns\ValidatesScheduleRegistryFields;
use App\Rules\AllowedArtisanCommand;
use App\Rules\AllowedShellCommand;
use App\Rules\LocaleRequiredTranslatable;
use App\Rules\PublicOutboundUrl;
use App\Rules\TranslatableField;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * 스케줄 직접 등록 요청
 *
 * 화면에서 만드는 행은 언제나 「직접 등록」이다(소유자 없음). 코어·확장 소유 행은 선언 동기화로만 생긴다.
 */
class CreateScheduleRequest extends FormRequest
{
    use ValidatesScheduleRegistryFields;

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
            // 식별자는 비우면 작업명으로 채운다(prepareForValidation). 작업명까지 모든 언어에서 비었으면
            // 작업명 오류만 내고 식별자 필수 오류는 겹쳐 내지 않는다 — 고칠 칸은 작업명 하나다
            'name' => [
                Rule::requiredIf(fn (): bool => $this->hasAnyLabelValue()),
                'nullable',
                'string',
                'max:255',
            ],
            // 직접 등록은 표시 명칭이 필수다(사이트 기본 언어 값)
            'label' => ['required', new LocaleRequiredTranslatable(maxLength: 255)],
            'description' => ['nullable', new TranslatableField(maxLength: 1000)],
            'type' => "required|string|in:{$types}",
            // command 는 타입별로 서버의 outbound 목적지(URL)·OS 명령(Shell)·PHP 코드(Artisan)·
            // 큐 Job 클래스(Job)가 되므로 각 타입에 맞는 실행 허용 검증을 저장 시점에 건다
            'command' => [
                'required',
                'string',
                'max:2000',
                Rule::when($this->resolvesToScheduleType(ScheduleType::Url), [new PublicOutboundUrl]),
                Rule::when($this->resolvesToScheduleType(ScheduleType::Shell), [new AllowedShellCommand]),
                Rule::when($this->resolvesToScheduleType(ScheduleType::Artisan), [new AllowedArtisanCommand]),
                Rule::when($this->isJobType(), [$this->allowedJobClassRule()]),
            ],
            'expression' => 'required|string|max:100',
            'frequency' => "required|string|in:{$frequencies}",
            'run_in_maintenance' => 'boolean',
            'timeout' => 'nullable|integer|min:1|max:86400',
            'is_active' => 'boolean',
            ...$this->registryRules(partial: false),
        ];

        // 모듈/플러그인이 validation rules를 동적으로 추가할 수 있도록 훅 제공
        return HookManager::applyFilters('core.schedule.create_validation_rules', $rules, $this);
    }

    /**
     * 작업명(label)에 비어 있지 않은 값이 한 언어라도 있는지 판정합니다.
     *
     * @return bool 한 언어라도 값이 있으면 true
     */
    protected function hasAnyLabelValue(): bool
    {
        $label = $this->input('label');

        if (is_string($label)) {
            return trim($label) !== '';
        }

        if (! is_array($label)) {
            return false;
        }

        foreach ($label as $value) {
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * 요청한 유형이 주어진 유형인지 판정합니다.
     *
     * @param  ScheduleType  $type  판정할 유형
     * @return bool 해당 유형이면 true
     */
    protected function resolvesToScheduleType(ScheduleType $type): bool
    {
        return $this->input('type') === $type->value;
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
     * 7.2.0 이전 형식(이름만, 중복 방지 boolean)으로 보내는 호출도 받는다 —
     * 명칭이 없으면 이름을, 이름이 없으면 명칭(사이트 기본 언어)을 서로 채운다.
     * 명칭을 **빈 객체로** 보낸 경우는 채우지 않는다(필수 위반으로 돌려준다).
     *
     * @return void 반환값 없음
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeRegistryInput();

        $name = $this->input('name');
        if (! $this->has('label') && is_string($name) && $name !== '') {
            $this->merge(['label' => $this->promoteToLocaleArray($name)]);
        }

        $label = $this->input('label');
        if (($name === null || $name === '') && is_array($label)) {
            $siteLocale = (string) config('app.site_locale', config('app.locale'));
            $derived = $label[$siteLocale] ?? collect($label)->first(fn ($v) => is_string($v) && $v !== '');
            if (is_string($derived) && $derived !== '') {
                $this->merge(['name' => mb_substr($derived, 0, 255)]);
            }
        }

        $this->merge([
            // 미지정이면 끔(0) — 7.2.0 이전 서버 기본값(false)과 같다. 화면은 켤 때 1440 을 채워 보낸다
            'without_overlapping' => $this->input('without_overlapping') ?? 0,
            'run_in_maintenance' => $this->run_in_maintenance ?? false,
            'is_active' => $this->is_active ?? true,
        ]);
    }

    /**
     * 검증된 데이터에 생성자(created_by)를 덧붙여 반환합니다.
     *
     * @return array<string, mixed> 검증된 입력 + created_by
     */
    public function validatedWithCreator(): array
    {
        return array_merge($this->validated(), [
            'created_by' => Auth::id(),
        ]);
    }
}
