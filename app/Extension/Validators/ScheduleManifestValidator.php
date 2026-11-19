<?php

namespace App\Extension\Validators;

use App\Enums\ExtensionOwnerType;
use App\Enums\ScheduleDispatchMode;
use App\Enums\ScheduleFrequency;
use App\Enums\ScheduleHistoryMode;
use App\Enums\ScheduleType;
use App\Services\Schedule\ScheduleConditionEvaluator;
use Cron\CronExpression;
use DateTimeZone;

/**
 * 코어 카탈로그·확장 `getSchedules()` 의 스케줄 선언 1건을 검증합니다.
 *
 * 검증 대상은 `ExtensionScheduleSyncHelper::normalizeDefinition()` 이 정규화한 결과(DB 속성 모양)다.
 * 위반이 있으면 사유 목록을 돌려주고, 동기화 헬퍼는 그 선언만 건너뛴다(확장 설치 전체를 막지 않는다).
 *
 * 명령 보안 규칙(config/schedule_security.php):
 * - 거부 목록·거부 접두사에 걸린 artisan 명령은 어느 소유자든 거부한다.
 * - 코어 선언의 artisan 명령은 허용목록에 있어야 한다.
 * - 확장 선언의 artisan 명령은 허용목록 밖이어도 받는다 — 확장 소유 판정(명령 클래스 네임스페이스)은
 *   설치 직후 프로세스에 명령이 아직 등록되지 않을 수 있어 실행기가 실행 시점에 한다.
 * - job 은 허용 클래스 목록(+ `core.schedule.filter_allowed_job_classes` 필터)으로 실행기가 거른다 — 여기서는 클래스 존재만 본다.
 * - 선언으로는 artisan·job 만 받는다. shell·url 은 운영자 직접 등록 전용이다.
 *
 * @since 7.2.0
 */
class ScheduleManifestValidator
{
    /** @var int without_overlapping 상한 (분, 7일) */
    public const MAX_OVERLAP_MINUTES = 10080;

    /**
     * @param  ScheduleConditionEvaluator  $conditionEvaluator  실행 조건 형식 검사
     */
    public function __construct(
        private readonly ScheduleConditionEvaluator $conditionEvaluator,
    ) {}

    /**
     * 정규화된 선언 1건의 위반 사유를 반환합니다.
     *
     * @param  array<string, mixed>  $definition  정규화된 선언 (DB 속성 모양)
     * @param  ExtensionOwnerType  $ownerType  소유 타입
     * @return array<int, string> 위반 사유 목록 (비어 있으면 통과)
     */
    public function errors(array $definition, ExtensionOwnerType $ownerType): array
    {
        $errors = [];

        $name = $definition['name'] ?? null;
        if (! is_string($name) || strlen($name) > 255 || ! preg_match('/^[a-z0-9][a-z0-9_.\-]*$/', $name)) {
            $errors[] = 'name 은 소문자·숫자·`-`·`_`·`.` 로 된 255자 이하 문자열이어야 합니다';
        }

        $type = ScheduleType::tryFrom((string) ($definition['type'] ?? ''));
        if (! in_array($type, [ScheduleType::Artisan, ScheduleType::Job], true)) {
            $errors[] = 'type 은 artisan 또는 job 이어야 합니다 (shell·url 은 직접 등록 전용)';
        }

        $command = $definition['command'] ?? null;
        if (! is_string($command) || trim($command) === '') {
            $errors[] = 'command 가 비어 있습니다';
        } elseif ($type === ScheduleType::Artisan) {
            $errors = array_merge($errors, $this->artisanCommandErrors($command, $ownerType));
        } elseif ($type === ScheduleType::Job && ! class_exists($command)) {
            $errors[] = "job 클래스 {$command} 를 찾을 수 없습니다";
        }

        $frequency = ScheduleFrequency::tryFrom((string) ($definition['frequency'] ?? ''));
        if ($frequency === null) {
            $errors[] = 'frequency 값이 올바르지 않습니다';
        }
        $expression = $definition['expression'] ?? null;
        if (! is_string($expression) || ! CronExpression::isValidExpression($expression)) {
            $errors[] = 'expression 이 올바른 cron 식이 아닙니다';
        }

        $timezone = $definition['timezone'] ?? null;
        if ($timezone !== null && (! is_string($timezone) || ! in_array($timezone, DateTimeZone::listIdentifiers(), true))) {
            $errors[] = 'timezone 이 올바른 시간대 식별자가 아닙니다';
        }

        $overlap = $definition['without_overlapping'] ?? 0;
        if (! is_int($overlap) || $overlap < 0 || $overlap > self::MAX_OVERLAP_MINUTES) {
            $errors[] = 'without_overlapping 은 0~'.self::MAX_OVERLAP_MINUTES.' 분 정수여야 합니다';
        }

        if (ScheduleDispatchMode::tryFrom((string) ($definition['dispatch_mode'] ?? '')) === null) {
            $errors[] = 'dispatch_mode 는 sync 또는 queue 여야 합니다';
        }
        if (ScheduleHistoryMode::tryFrom((string) ($definition['history_mode'] ?? '')) === null) {
            $errors[] = 'history_mode 는 all 또는 failures_only 여야 합니다';
        }

        $timeout = $definition['timeout'] ?? null;
        if ($timeout !== null && (! is_int($timeout) || $timeout < 1)) {
            $errors[] = 'timeout 은 1 이상의 초 단위 정수여야 합니다';
        }

        foreach (['label', 'description'] as $field) {
            $value = $definition[$field] ?? null;
            if ($value !== null && (! is_array($value) || array_filter($value, fn ($v) => ! is_string($v)) !== [])) {
                $errors[] = "{$field} 는 {로케일: 문자열} 형식이어야 합니다";
            }
        }

        $conditions = $definition['enabled_condition'] ?? null;
        if ($conditions !== null) {
            if (! is_array($conditions) || ! array_is_list($conditions)) {
                $errors[] = 'enabled_condition 은 조건 목록이어야 합니다';
            } else {
                foreach ($conditions as $idx => $condition) {
                    if (! is_array($condition) || ! $this->conditionEvaluator->isWellFormed($condition)) {
                        $errors[] = "enabled_condition[{$idx}] 형식이 올바르지 않습니다 (source·key·operator, equals 는 value 필요)";
                    }
                }
            }
        }

        $commandClass = $definition['command_class'] ?? null;
        if ($commandClass !== null && (! is_string($commandClass) || ! class_exists($commandClass))) {
            $errors[] = 'command_class 클래스를 찾을 수 없습니다';
        }

        return $errors;
    }

    /**
     * artisan 명령의 보안 규칙 위반 사유를 반환합니다.
     *
     * @param  string  $command  명령 문자열 (옵션 포함)
     * @param  ExtensionOwnerType  $ownerType  소유 타입
     * @return array<int, string> 위반 사유 목록
     */
    private function artisanCommandErrors(string $command, ExtensionOwnerType $ownerType): array
    {
        $commandName = strtok(trim($command), ' ') ?: '';
        $policy = (array) config('schedule_security.artisan', []);

        if (in_array($commandName, (array) ($policy['denylist'] ?? []), true)) {
            return ["artisan 명령 {$commandName} 은 거부 목록에 있습니다"];
        }
        foreach ((array) ($policy['denylist_prefixes'] ?? []) as $prefix) {
            if (is_string($prefix) && $prefix !== '' && str_starts_with($commandName, $prefix)) {
                return ["artisan 명령 {$commandName} 은 거부 접두사 {$prefix} 에 걸립니다"];
            }
        }

        if ($ownerType === ExtensionOwnerType::Core
            && ! array_key_exists($commandName, (array) ($policy['allowlist'] ?? []))) {
            return ["코어 artisan 명령 {$commandName} 이 config/schedule_security.php 허용목록에 없습니다"];
        }

        return [];
    }
}
