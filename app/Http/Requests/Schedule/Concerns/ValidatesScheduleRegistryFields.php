<?php

namespace App\Http\Requests\Schedule\Concerns;

use App\Enums\ScheduleDispatchMode;
use App\Enums\ScheduleHistoryMode;
use App\Enums\ScheduleType;
use App\Services\Schedule\ScheduleSecurityPolicy;
use Closure;
use Illuminate\Validation\Rule;

/**
 * 스케줄 등록·수정 요청이 함께 쓰는 DB 등록제(7.2.0) 필드 규칙과 입력 정규화
 *
 * - 운영자가 넣을 수 없는 필드(조건·명령 클래스·소유자)는 `prohibited` 로 막는다.
 * - 중복 방지는 잠금 유지 분(0 = 끔, 최대 7일)이다. 옛 boolean 입력은 분으로 바꾼다.
 * - 명칭·설명은 다국어 객체다. 문자열이 오면 현재 로케일 + 사이트 기본 로케일 객체로 올린다.
 */
trait ValidatesScheduleRegistryFields
{
    /**
     * 중복 방지 잠금 분의 상한 (7일).
     */
    public const OVERLAP_MAX_MINUTES = 10080;

    /**
     * 옛 boolean `without_overlapping=true` 를 옮길 분 값 (하루).
     */
    public const OVERLAP_LEGACY_TRUE_MINUTES = 1440;

    /**
     * 등록·수정 공통 규칙을 반환합니다.
     *
     * @param  bool  $partial  수정 요청이면 true (`sometimes` 를 앞에 붙인다)
     * @return array<string, mixed> 필드별 규칙
     */
    protected function registryRules(bool $partial): array
    {
        $sometimes = $partial ? ['sometimes'] : [];

        return [
            'dispatch_mode' => [...$sometimes, 'string', Rule::in(ScheduleDispatchMode::values())],
            'history_mode' => [...$sometimes, 'string', Rule::in(ScheduleHistoryMode::values())],
            'without_overlapping' => [...$sometimes, 'integer', 'min:0', 'max:'.self::OVERLAP_MAX_MINUTES],
            'on_one_server' => [...$sometimes, 'boolean'],
            'notify_on_failure' => [...$sometimes, 'boolean'],
            'queue_connection' => ['nullable', 'string', 'max:50'],
            'queue_name' => ['nullable', 'string', 'max:100'],
            'timezone' => ['nullable', 'string', 'max:64', 'timezone:all'],
            // 실행 조건·웹 컨텍스트 명령 클래스·소유자는 선언(카탈로그·확장)만 정한다 — 운영자 입력 금지
            'enabled_condition' => ['prohibited'],
            'command_class' => ['prohibited'],
            'extension_type' => ['prohibited'],
            'extension_identifier' => ['prohibited'],
        ];
    }

    /**
     * Job 유형의 클래스 허용목록 규칙을 반환합니다.
     *
     * 허용목록은 `config/schedule_security.php` 의 `job.allowed_classes` 에 서버 관리자 덮어쓰기
     * (`storage/app/schedule_security.json`)를 더하고 `core.schedule.filter_allowed_job_classes` 필터로
     * 넓힌 것이다(실행기와 같은 해석기·같은 목록).
     *
     * @return Closure 검증 클로저
     */
    protected function allowedJobClassRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $allowed = app(ScheduleSecurityPolicy::class)->filteredJobAllowedClasses();

            if (! is_string($value) || ! in_array(ltrim($value, '\\'), $allowed, true)) {
                $fail(__('schedule.validation.job_not_allowed'));
            }
        };
    }

    /**
     * 요청 값이 Job 유형인지 판정합니다(수정 요청은 저장된 유형을 본다).
     *
     * @return bool Job 유형이면 true
     */
    protected function isJobType(): bool
    {
        return $this->resolvesToScheduleType(ScheduleType::Job);
    }

    /**
     * 이번 요청 결과 스케줄이 주어진 유형이 되는지 판정합니다.
     *
     * @param  ScheduleType  $type  판정할 유형
     * @return bool 해당 유형이면 true
     */
    abstract protected function resolvesToScheduleType(ScheduleType $type): bool;

    /**
     * 공통 검증 메시지를 반환합니다.
     *
     * @return array<string, string> 규칙별 메시지
     */
    protected function registryMessages(): array
    {
        return [
            'label.required' => __('schedule.validation.label_required'),
            'dispatch_mode.in' => __('schedule.validation.dispatch_mode_invalid'),
            'history_mode.in' => __('schedule.validation.history_mode_invalid'),
            'without_overlapping.integer' => __('schedule.validation.without_overlapping_integer'),
            'without_overlapping.min' => __('schedule.validation.without_overlapping_range', ['max' => self::OVERLAP_MAX_MINUTES]),
            'without_overlapping.max' => __('schedule.validation.without_overlapping_range', ['max' => self::OVERLAP_MAX_MINUTES]),
            'queue_connection.max' => __('schedule.validation.queue_connection_max'),
            'queue_name.max' => __('schedule.validation.queue_name_max'),
            'timezone.timezone' => __('schedule.validation.timezone_invalid'),
            'enabled_condition.prohibited' => __('schedule.validation.field_not_editable'),
            'command_class.prohibited' => __('schedule.validation.field_not_editable'),
            'extension_type.prohibited' => __('schedule.validation.field_not_editable'),
            'extension_identifier.prohibited' => __('schedule.validation.field_not_editable'),
            'command.prohibited' => __('schedule.validation.owned_field_locked'),
            'type.prohibited' => __('schedule.validation.owned_field_locked'),
            'name.prohibited' => __('schedule.validation.owned_field_locked'),
            'timezone.prohibited' => __('schedule.validation.owned_field_locked'),
        ];
    }

    /**
     * 등록·수정 공통 검증 필드 표시 이름을 반환합니다.
     *
     * @return array<string, string> 필드 => 표시 이름
     */
    protected function registryAttributes(): array
    {
        $fields = [
            'name', 'label', 'description', 'type', 'command', 'expression', 'frequency',
            'run_in_maintenance', 'timeout', 'is_active', 'dispatch_mode', 'history_mode',
            'without_overlapping', 'on_one_server', 'notify_on_failure', 'queue_connection',
            'queue_name', 'timezone', 'enabled_condition', 'command_class',
        ];

        $attributes = [];
        foreach ($fields as $field) {
            $attributes[$field] = __('schedule.attributes.'.$field);
        }
        $attributes['extension_type'] = __('schedule.attributes.owner');
        $attributes['extension_identifier'] = __('schedule.attributes.owner_identifier');

        return $attributes;
    }

    /**
     * 등록·수정 공통 입력 정규화를 합니다.
     *
     * - `without_overlapping` 이 boolean 이면 분으로 바꾼다(true → 1440, false → 0).
     * - `label`·`description` 이 문자열이면 다국어 객체로 올린다.
     * - `command` 의 줄바꿈·연속 공백·앞뒤 공백을 공백 하나로 접는다 — 화면의 명령 칸이 여러 줄 입력이라도
     *   저장되는 명령은 한 줄이다(줄바꿈은 Artisan 파서·Shell 토큰화가 제어문자로 거부한다).
     *
     * @return void 반환값 없음
     */
    protected function normalizeRegistryInput(): void
    {
        $command = $this->input('command');
        if (is_string($command)) {
            $this->merge(['command' => trim((string) preg_replace('/\s+/u', ' ', $command))]);
        }

        $overlap = $this->input('without_overlapping');
        if (is_bool($overlap)) {
            $this->merge(['without_overlapping' => $overlap ? self::OVERLAP_LEGACY_TRUE_MINUTES : 0]);
        }

        foreach (['label', 'description'] as $field) {
            if ($this->has($field) && is_string($this->input($field))) {
                $this->merge([$field => $this->promoteToLocaleArray($this->input($field))]);
            }
        }
    }

    /**
     * 문자열을 현재 로케일 + 사이트 기본 로케일 키의 다국어 객체로 올립니다.
     *
     * 사이트 기본 로케일을 함께 채우는 것은 다국어 필수 규칙이 그 로케일 값을 요구하기 때문이다
     * (사이트 기본 언어가 아닌 언어로 접속한 관리자가 문자열을 보내도 통과해야 한다).
     *
     * @param  string  $value  원문
     * @return array<string, string> 로케일 => 원문
     */
    protected function promoteToLocaleArray(string $value): array
    {
        $siteLocale = (string) config('app.site_locale', config('app.locale'));

        return array_fill_keys(array_unique([app()->getLocale(), $siteLocale]), $value);
    }
}
