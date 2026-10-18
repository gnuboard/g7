<?php

namespace App\Services\Schedule;

use App\Extension\HookManager;
use Illuminate\Support\Facades\Log;

/**
 * 스케줄 실행 보안 정책의 「유효 정책」을 만드는 해석기.
 *
 * 코어 정책은 `config/schedule_security.php`(코드 소유)이고, 서버 관리자는 선택 파일
 * `storage/app/schedule_security.json` 으로 **더하기만** 할 수 있다(코어 업데이트가 덮어쓰지 않는
 * 위치이며 관리자 화면이 쓰는 `storage/app/settings/` 와 분리된다).
 *
 *  - Artisan 허용목록: 새 명령을 더하거나, 코어 항목에 옵션을 더한다(합집합, 위치 인자 상한은 큰 값).
 *  - Artisan 거부목록·거부 접두사: 더하기만 한다. 코어 거부 항목은 덮어쓰기로 풀 수 없다.
 *    판정은 언제나 거부가 먼저다 — 덮어쓰기 허용 항목이 거부목록에도 있으면 거부가 이긴다.
 *  - Job 허용 클래스: 더하기만 한다(확장 훅 `core.schedule.filter_allowed_job_classes` 는 그 뒤에 적용).
 *  - Shell 게이트·허용 실행 파일은 `.env`(`SCHEDULE_SHELL_ENABLED`·`SCHEDULE_SHELL_ALLOWED_BINARIES`)만 따른다.
 *  - 파일 형식이 하나라도 어긋나면 덮어쓰기 **전체**를 무시하고(코어 정책만 적용) 경고 로그를 남긴다.
 *
 * 부팅 시 config 에 병합하지 않고 소비 지점에서 해석하는 이유:
 *  1. `config:cache` 가 병합 결과를 굳히면 파일에서 지운 항목이 캐시를 다시 만들 때까지 남는다.
 *  2. 항목마다 출처(core/override)를 화면에 보여 줘야 하는데 병합된 config 는 출처를 잃는다.
 *  3. 런타임 `config([...])` 로 코어 정책을 바꾸는 호출부(테스트 포함)가 그대로 동작한다.
 *  4. 파일 오류가 부팅 전체가 아니라 스케줄 판정에만 국한된다.
 *
 * 파일은 수정 시각·크기가 바뀔 때만 다시 읽는다 — 큐 워커처럼 오래 사는 프로세스도 재시작 없이 반영한다.
 */
class ScheduleSecurityPolicy
{
    /** 덮어쓰기 파일 최대 크기(바이트) — 정책 파일이 이보다 클 이유가 없다 */
    public const MAX_OVERRIDE_BYTES = 262144;

    /** 출처 — 코어 config */
    public const SOURCE_CORE = 'core';

    /** 출처 — 서버 관리자 덮어쓰기 파일 */
    public const SOURCE_OVERRIDE = 'override';

    /** 출처 — 확장 훅(Job 허용 클래스) */
    public const SOURCE_EXTENSION = 'extension';

    /** 덮어쓰기로 바꿀 수 없는(코드·.env 소유) 키 */
    private const LOCKED_TOP_KEYS = ['shell', 'url'];

    /** 덮어쓰기로 바꿀 수 없는 artisan 하위 키 */
    private const LOCKED_ARTISAN_KEYS = ['allow_extension_commands', 'extension_namespaces'];

    /** 명령명 형태 — 엄격 파서(ScheduleCommandValidator)와 같은 규칙 */
    private const COMMAND_NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_.-]*(?::[A-Za-z0-9_.-]+)*$/';

    /** 롱옵션 이름 형태 */
    private const OPTION_NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9-]*$/';

    /** 거부 접두사 형태 */
    private const PREFIX_PATTERN = '/^[A-Za-z][A-Za-z0-9_.:-]*$/';

    /** 클래스 FQCN 형태 */
    private const CLASS_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/';

    /**
     * 마지막으로 읽은 파일 서명과 해석 결과.
     *
     * @var array{signature: string, state: array{exists: bool, valid: bool, error_code: string|null, error_params: array<string, string>, data: array{allowlist: array<string, array{options: array<int, string>, max_arguments: int}>, denylist: array<int, string>, denylist_prefixes: array<int, string>, allowed_classes: array<int, string>}}}|null
     */
    private ?array $cache = null;

    /**
     * 유효 Artisan 허용목록(코어 + 덮어쓰기)을 돌려줍니다.
     *
     * @return array<string, array{options: array<int, string>, max_arguments: int}> 명령명(소문자) => 사양
     */
    public function artisanAllowlist(): array
    {
        $merged = $this->coreAllowlist();

        foreach ($this->overrideData()['allowlist'] as $name => $spec) {
            if (isset($merged[$name])) {
                $merged[$name] = [
                    'options' => array_values(array_unique([...$merged[$name]['options'], ...$spec['options']])),
                    'max_arguments' => max($merged[$name]['max_arguments'], $spec['max_arguments']),
                ];

                continue;
            }

            $merged[$name] = $spec;
        }

        return $merged;
    }

    /**
     * 유효 Artisan 거부목록(코어 + 덮어쓰기, 소문자)을 돌려줍니다.
     *
     * @return array<int, string> 거부 명령명
     */
    public function artisanDenylist(): array
    {
        return array_values(array_unique([...$this->coreDenylist(), ...$this->overrideData()['denylist']]));
    }

    /**
     * 유효 Artisan 거부 접두사(코어 + 덮어쓰기, 소문자)를 돌려줍니다.
     *
     * @return array<int, string> 거부 접두사
     */
    public function artisanDenylistPrefixes(): array
    {
        return array_values(array_unique([...$this->coreDenylistPrefixes(), ...$this->overrideData()['denylist_prefixes']]));
    }

    /**
     * 명령명이 유효 거부목록(정확 일치 또는 접두사)에 걸리는지 판정합니다.
     *
     * @param  string  $name  명령명
     * @return bool 거부 대상이면 true
     */
    public function isArtisanCommandDenied(string $name): bool
    {
        $normalized = strtolower(trim($name));

        if (in_array($normalized, $this->artisanDenylist(), true)) {
            return true;
        }

        foreach ($this->artisanDenylistPrefixes() as $prefix) {
            if ($prefix !== '' && str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 유효 Job 허용 클래스(코어 + 덮어쓰기)를 돌려줍니다. 확장 훅은 적용하지 않습니다.
     *
     * @return array<int, string> FQCN 목록(선행 백슬래시 없음)
     */
    public function jobAllowedClasses(): array
    {
        return array_values(array_unique([...$this->coreJobClasses(), ...$this->overrideData()['allowed_classes']]));
    }

    /**
     * 확장 훅까지 적용한 최종 Job 허용 클래스를 돌려줍니다(실행기·저장 검증이 쓰는 목록).
     *
     * @return array<int, string> FQCN 목록
     */
    public function filteredJobAllowedClasses(): array
    {
        return array_values(array_map(
            static fn ($class): string => ltrim((string) $class, '\\'),
            (array) HookManager::applyFilters('core.schedule.filter_allowed_job_classes', $this->jobAllowedClasses()),
        ));
    }

    /**
     * Shell 유형을 실제로 쓸 수 있는지(게이트 on + 허용 실행 파일 1개 이상) 판정합니다.
     *
     * @return bool 쓸 수 있으면 true
     */
    public function shellEnabled(): bool
    {
        return $this->shellGateEnabled() && $this->shellAllowedBinaries() !== [];
    }

    /**
     * 덮어쓰기 파일 상태를 돌려줍니다(서버 절대경로는 싣지 않는다).
     *
     * @return array{path: string, exists: bool, valid: bool, error_code: string|null, error: string|null}
     */
    public function overrideFileStatus(): array
    {
        $state = $this->overrideState();

        return [
            'path' => $this->displayPath(),
            'exists' => $state['exists'],
            'valid' => $state['valid'],
            'error_code' => $state['error_code'],
            'error' => $state['error_code'] === null
                ? null
                : __('schedule.security.override_error.'.$state['error_code'], $state['error_params']),
        ];
    }

    /**
     * 관리자 화면(읽기 전용)에 보일 유효 정책 전체를 항목별 출처와 함께 돌려줍니다.
     *
     * @return array<string, mixed> 유효 정책
     */
    public function describe(): array
    {
        $core = $this->coreAllowlist();
        $override = $this->overrideData();
        $coreDeny = $this->coreDenylist();
        $corePrefixes = $this->coreDenylistPrefixes();
        $coreClasses = $this->coreJobClasses();
        $policyClasses = $this->jobAllowedClasses();

        $allowlist = [];
        foreach ($this->artisanAllowlist() as $name => $spec) {
            $allowlist[] = [
                'command' => $name,
                'options' => $spec['options'],
                'max_arguments' => $spec['max_arguments'],
                'source' => isset($core[$name]) ? self::SOURCE_CORE : self::SOURCE_OVERRIDE,
                'override_options' => isset($core[$name], $override['allowlist'][$name])
                    ? array_values(array_diff($override['allowlist'][$name]['options'], $core[$name]['options']))
                    : [],
                'denied' => $this->isArtisanCommandDenied($name),
            ];
        }

        $classes = [];
        foreach ($this->filteredJobAllowedClasses() as $class) {
            $classes[$class] = [
                'class' => $class,
                'source' => in_array($class, $coreClasses, true)
                    ? self::SOURCE_CORE
                    : (in_array($class, $policyClasses, true) ? self::SOURCE_OVERRIDE : self::SOURCE_EXTENSION),
            ];
        }

        return [
            'override_file' => $this->overrideFileStatus(),
            'shell' => [
                'enabled' => $this->shellEnabled(),
                'gate_enabled' => $this->shellGateEnabled(),
                'allowed_binaries' => $this->shellAllowedBinaries(),
                'source' => 'env',
            ],
            'artisan' => [
                'allowlist' => $allowlist,
                'denylist' => array_map(
                    static fn (string $command): array => [
                        'command' => $command,
                        'source' => in_array($command, $coreDeny, true) ? self::SOURCE_CORE : self::SOURCE_OVERRIDE,
                    ],
                    $this->artisanDenylist(),
                ),
                'denylist_prefixes' => array_map(
                    static fn (string $prefix): array => [
                        'prefix' => $prefix,
                        'source' => in_array($prefix, $corePrefixes, true) ? self::SOURCE_CORE : self::SOURCE_OVERRIDE,
                    ],
                    $this->artisanDenylistPrefixes(),
                ),
                'allow_extension_commands' => (bool) config('schedule_security.artisan.allow_extension_commands', true),
            ],
            'job' => [
                'allowed_classes' => array_values($classes),
            ],
            'url' => [
                'block_private_hosts' => ! (bool) g7_core_settings('security.allow_internal_outbound_urls', false),
                'source' => 'settings',
            ],
        ];
    }

    /**
     * 코어 허용목록을 소문자 키·정규화된 사양으로 읽습니다.
     *
     * @return array<string, array{options: array<int, string>, max_arguments: int}> 명령명 => 사양
     */
    private function coreAllowlist(): array
    {
        $normalized = [];

        foreach ((array) config('schedule_security.artisan.allowlist', []) as $name => $spec) {
            $key = strtolower(trim((string) $name));

            if ($key === '') {
                continue;
            }

            $spec = (array) $spec;
            $normalized[$key] = [
                'options' => array_values(array_map('strval', (array) ($spec['options'] ?? []))),
                'max_arguments' => (int) ($spec['max_arguments'] ?? 0),
            ];
        }

        return $normalized;
    }

    /**
     * 코어 거부목록(소문자)을 읽습니다.
     *
     * @return array<int, string> 거부 명령명
     */
    private function coreDenylist(): array
    {
        return self::lowerList((array) config('schedule_security.artisan.denylist', []));
    }

    /**
     * 코어 거부 접두사(소문자)를 읽습니다.
     *
     * @return array<int, string> 거부 접두사
     */
    private function coreDenylistPrefixes(): array
    {
        return self::lowerList((array) config('schedule_security.artisan.denylist_prefixes', []));
    }

    /**
     * 코어 Job 허용 클래스를 읽습니다.
     *
     * @return array<int, string> FQCN 목록
     */
    private function coreJobClasses(): array
    {
        return array_values(array_filter(array_map(
            static fn ($class): string => ltrim(trim((string) $class), '\\'),
            (array) config('schedule_security.job.allowed_classes', []),
        )));
    }

    /**
     * Shell 게이트(.env) 값을 읽습니다.
     *
     * @return bool 게이트가 켜져 있으면 true
     */
    private function shellGateEnabled(): bool
    {
        return (bool) config('schedule_security.shell.enabled', false);
    }

    /**
     * Shell 허용 실행 파일(.env) 목록을 읽습니다.
     *
     * @return array<int, string> 실행 파일 basename 목록
     */
    private function shellAllowedBinaries(): array
    {
        return array_values(array_filter(array_map(
            static fn ($binary): string => trim((string) $binary),
            (array) config('schedule_security.shell.allowed_binaries', []),
        )));
    }

    /**
     * 유효한 덮어쓰기 데이터를 돌려줍니다(파일이 없거나 형식 오류면 빈 데이터).
     *
     * @return array{allowlist: array<string, array{options: array<int, string>, max_arguments: int}>, denylist: array<int, string>, denylist_prefixes: array<int, string>, allowed_classes: array<int, string>}
     */
    private function overrideData(): array
    {
        return $this->overrideState()['data'];
    }

    /**
     * 덮어쓰기 파일을 (바뀌었을 때만) 읽어 해석합니다.
     *
     * @return array{exists: bool, valid: bool, error_code: string|null, error_params: array<string, string>, data: array{allowlist: array<string, array{options: array<int, string>, max_arguments: int}>, denylist: array<int, string>, denylist_prefixes: array<int, string>, allowed_classes: array<int, string>}}
     */
    private function overrideState(): array
    {
        $path = $this->overridePath();
        clearstatcache(true, $path);

        $exists = $path !== '' && is_file($path);
        $signature = $exists ? $path.'|'.(int) @filemtime($path).'|'.(int) @filesize($path) : $path.'|missing';

        if ($this->cache !== null && $this->cache['signature'] === $signature) {
            return $this->cache['state'];
        }

        $state = $exists ? $this->parseOverrideFile($path) : self::state(false, true);

        if ($state['error_code'] !== null) {
            Log::warning('Schedule security override ignored', [
                'path' => $this->displayPath(),
                'error' => $state['error_code'],
                'detail' => $state['error_params'],
            ]);
        }

        $this->cache = ['signature' => $signature, 'state' => $state];

        return $state;
    }

    /**
     * 덮어쓰기 파일을 읽어 형식을 검사합니다. 하나라도 어긋나면 전체를 무효로 봅니다.
     *
     * @param  string  $path  파일 절대경로
     * @return array{exists: bool, valid: bool, error_code: string|null, error_params: array<string, string>, data: array{allowlist: array<string, array{options: array<int, string>, max_arguments: int}>, denylist: array<int, string>, denylist_prefixes: array<int, string>, allowed_classes: array<int, string>}}
     */
    private function parseOverrideFile(string $path): array
    {
        $size = @filesize($path);

        if ($size === false) {
            return self::state(true, false, 'unreadable');
        }

        if ($size > self::MAX_OVERRIDE_BYTES) {
            return self::state(true, false, 'too_large', ['max' => (string) self::MAX_OVERRIDE_BYTES]);
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            return self::state(true, false, 'unreadable');
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return self::state(true, false, 'invalid_json', ['detail' => json_last_error_msg()]);
        }

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return self::state(true, false, 'not_object', ['key' => '(root)']);
        }

        $data = ['allowlist' => [], 'denylist' => [], 'denylist_prefixes' => [], 'allowed_classes' => []];

        foreach ($decoded as $key => $section) {
            $key = (string) $key;

            // `_` 로 시작하는 키는 주석으로 본다(`_comment` 등).
            if (str_starts_with($key, '_')) {
                continue;
            }

            if (in_array($key, self::LOCKED_TOP_KEYS, true)) {
                return self::state(true, false, 'not_overridable', ['key' => $key]);
            }

            if ($key === 'artisan') {
                $error = $this->parseArtisanSection($section, $data);
            } elseif ($key === 'job') {
                $error = $this->parseJobSection($section, $data);
            } else {
                $error = ['unknown_key', ['key' => $key]];
            }

            if ($error !== null) {
                return self::state(true, false, $error[0], $error[1]);
            }
        }

        return self::state(true, true, null, [], $data);
    }

    /**
     * `artisan` 절을 검사해 데이터에 담습니다.
     *
     * @param  mixed  $section  절 값
     * @param  array{allowlist: array<string, array{options: array<int, string>, max_arguments: int}>, denylist: array<int, string>, denylist_prefixes: array<int, string>, allowed_classes: array<int, string>}  $data  누적 데이터
     * @return array{0: string, 1: array<string, string>}|null 오류(코드, 인자) — 정상이면 null
     */
    private function parseArtisanSection(mixed $section, array &$data): ?array
    {
        if (! self::isObject($section)) {
            return ['not_object', ['key' => 'artisan']];
        }

        foreach ($section as $key => $value) {
            $key = (string) $key;

            if (str_starts_with($key, '_')) {
                continue;
            }

            if (in_array($key, self::LOCKED_ARTISAN_KEYS, true)) {
                return ['not_overridable', ['key' => 'artisan.'.$key]];
            }

            if ($key === 'allowlist') {
                if (! self::isObject($value)) {
                    return ['not_object', ['key' => 'artisan.allowlist']];
                }

                foreach ($value as $command => $spec) {
                    $error = $this->parseAllowlistEntry((string) $command, $spec, $data);

                    if ($error !== null) {
                        return $error;
                    }
                }

                continue;
            }

            if ($key === 'denylist' || $key === 'denylist_prefixes') {
                $pattern = $key === 'denylist' ? self::COMMAND_NAME_PATTERN : self::PREFIX_PATTERN;
                $list = self::stringList($value, $pattern);

                if ($list === null) {
                    return ['invalid_list', ['key' => 'artisan.'.$key]];
                }

                $data[$key] = self::lowerList($list);

                continue;
            }

            return ['unknown_key', ['key' => 'artisan.'.$key]];
        }

        return null;
    }

    /**
     * 허용목록 항목 하나를 검사해 데이터에 담습니다.
     *
     * @param  string  $command  명령명
     * @param  mixed  $spec  항목 값(`{options: [...], max_arguments: n}`)
     * @param  array{allowlist: array<string, array{options: array<int, string>, max_arguments: int}>, denylist: array<int, string>, denylist_prefixes: array<int, string>, allowed_classes: array<int, string>}  $data  누적 데이터
     * @return array{0: string, 1: array<string, string>}|null 오류(코드, 인자) — 정상이면 null
     */
    private function parseAllowlistEntry(string $command, mixed $spec, array &$data): ?array
    {
        if (preg_match(self::COMMAND_NAME_PATTERN, $command) !== 1) {
            return ['invalid_command', ['command' => $command]];
        }

        if (! self::isObject($spec)) {
            return ['invalid_allowlist_entry', ['command' => $command]];
        }

        foreach (array_keys($spec) as $field) {
            if (! in_array($field, ['options', 'max_arguments'], true)) {
                return ['invalid_allowlist_entry', ['command' => $command]];
            }
        }

        $options = self::stringList($spec['options'] ?? [], self::OPTION_NAME_PATTERN);
        $maxArguments = $spec['max_arguments'] ?? 0;

        if ($options === null || ! is_int($maxArguments) || $maxArguments < 0 || $maxArguments > 10) {
            return ['invalid_allowlist_entry', ['command' => $command]];
        }

        $data['allowlist'][strtolower($command)] = [
            'options' => array_values(array_unique($options)),
            'max_arguments' => $maxArguments,
        ];

        return null;
    }

    /**
     * `job` 절을 검사해 데이터에 담습니다.
     *
     * @param  mixed  $section  절 값
     * @param  array{allowlist: array<string, array{options: array<int, string>, max_arguments: int}>, denylist: array<int, string>, denylist_prefixes: array<int, string>, allowed_classes: array<int, string>}  $data  누적 데이터
     * @return array{0: string, 1: array<string, string>}|null 오류(코드, 인자) — 정상이면 null
     */
    private function parseJobSection(mixed $section, array &$data): ?array
    {
        if (! self::isObject($section)) {
            return ['not_object', ['key' => 'job']];
        }

        foreach ($section as $key => $value) {
            $key = (string) $key;

            if (str_starts_with($key, '_')) {
                continue;
            }

            if ($key !== 'allowed_classes') {
                return ['unknown_key', ['key' => 'job.'.$key]];
            }

            $classes = is_array($value) ? array_map(
                static fn ($class) => is_string($class) ? ltrim(trim($class), '\\') : $class,
                $value,
            ) : $value;
            $list = self::stringList($classes, self::CLASS_PATTERN);

            if ($list === null) {
                return ['invalid_list', ['key' => 'job.allowed_classes']];
            }

            $data['allowed_classes'] = array_values(array_unique($list));
        }

        return null;
    }

    /**
     * 덮어쓰기 파일 절대경로를 돌려줍니다.
     *
     * @return string 절대경로
     */
    private function overridePath(): string
    {
        return (string) config('schedule_security.override_file', storage_path('app/schedule_security.json'));
    }

    /**
     * 화면에 보일 경로(프로젝트 루트 기준 상대경로 — 서버 절대경로는 내보내지 않는다)를 돌려줍니다.
     *
     * @return string 표시 경로
     */
    private function displayPath(): string
    {
        $path = str_replace('\\', '/', $this->overridePath());
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : basename($path);
    }

    /**
     * 해석 상태 배열을 만듭니다.
     *
     * @param  bool  $exists  파일 존재 여부
     * @param  bool  $valid  형식 정상 여부(파일이 없으면 true)
     * @param  string|null  $errorCode  오류 코드
     * @param  array<string, string>  $errorParams  오류 문구 인자
     * @param  array{allowlist: array<string, array{options: array<int, string>, max_arguments: int}>, denylist: array<int, string>, denylist_prefixes: array<int, string>, allowed_classes: array<int, string>}|null  $data  유효 데이터
     * @return array{exists: bool, valid: bool, error_code: string|null, error_params: array<string, string>, data: array{allowlist: array<string, array{options: array<int, string>, max_arguments: int}>, denylist: array<int, string>, denylist_prefixes: array<int, string>, allowed_classes: array<int, string>}}
     */
    private static function state(bool $exists, bool $valid, ?string $errorCode = null, array $errorParams = [], ?array $data = null): array
    {
        return [
            'exists' => $exists,
            'valid' => $valid,
            'error_code' => $errorCode,
            'error_params' => $errorParams,
            'data' => $data ?? ['allowlist' => [], 'denylist' => [], 'denylist_prefixes' => [], 'allowed_classes' => []],
        ];
    }

    /**
     * JSON 객체(연관 배열 또는 빈 배열)인지 판정합니다.
     *
     * @param  mixed  $value  값
     * @return bool 객체면 true
     */
    private static function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }

    /**
     * 형태 규칙을 지키는 문자열 배열이면 그대로, 아니면 null 을 돌려줍니다.
     *
     * @param  mixed  $value  값
     * @param  string  $pattern  원소 형태 정규식
     * @return array<int, string>|null 문자열 목록
     */
    private static function stringList(mixed $value, string $pattern): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        foreach ($value as $item) {
            if (! is_string($item) || preg_match($pattern, $item) !== 1) {
                return null;
            }
        }

        return $value;
    }

    /**
     * 문자열 목록을 소문자·공백 제거·빈 값 제외로 정규화합니다.
     *
     * @param  array<int|string, mixed>  $values  원본 목록
     * @return array<int, string> 정규화된 목록
     */
    private static function lowerList(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            $values,
        ), static fn (string $value): bool => $value !== '')));
    }
}
