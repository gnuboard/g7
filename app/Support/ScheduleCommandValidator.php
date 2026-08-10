<?php

namespace App\Support;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputDefinition;
use Throwable;

/**
 * 스케줄 Shell/Artisan command 가 실행 가능한 안전 명령인지 판정하는 순수 유틸.
 *
 * 스케줄 command 는 저장된 문자열이 그대로 서버에서 실행되는 값이므로, 스케줄 생성 권한을
 * 위임받은 계정이 임의 OS 명령·임의 PHP 코드를 실행하는 통로가 될 수 있다(권한 상승형 RCE).
 * 본 유틸은 두 축의 판정을 제공한다:
 *
 *  1. Shell — 기본 차단. `config/schedule_security.php` 의 opt-in 게이트가 켜져 있고,
 *     셸 메타문자가 없으며, 첫 토큰의 basename 이 허용 실행 파일 목록과 완전 일치할 때만 허용.
 *     허용되더라도 호출부는 `tokenizeShellCommand()` 로 얻은 인자 배열로 실행해야 한다
 *     (`/bin/sh -c` 를 경유하지 않으므로 파이프·`;`·`$()` 가 무력화된다).
 *
 *  2. Artisan — 기본 차단, 허용목록만 통과. `config/schedule_security.php` 의 허용목록에
 *     명령명이 있고 그 항목이 선언한 옵션만 쓰였을 때 통과한다. 설치된 확장이 소유한 명령은
 *     게이트가 켜져 있으면 자동으로 허용된다. 차단목록은 최종 거부권으로 가장 먼저 평가된다.
 *
 *     명령 문자열은 엄격 파서로만 해석한다 — 따옴표·백슬래시·단축 옵션이 있으면 거부하고,
 *     호출부는 `resolveArtisanCommand()` 가 돌려준 (명령명, 인자배열) 로 실행해야 한다.
 *     문자열째 `Artisan::call()` 에 넘기면 Symfony 가 따옴표를 다시 해석해
 *     검증한 이름과 실행되는 이름이 갈린다(`"tinker" --execute=…`).
 *
 * 예외를 던지지 않고 bool/배열만 반환한다 — 차단 시 어떤 응답/예외를 낼지는 호출부가 결정한다.
 */
class ScheduleCommandValidator
{
    /** 파싱 불가 — 따옴표·백슬래시·제어문자·단축 옵션 등 정규 형태가 아님 */
    public const ARTISAN_REASON_MALFORMED = 'malformed';

    /** 차단목록(최종 거부권)에 걸림 */
    public const ARTISAN_REASON_DENIED = 'denied';

    /** 허용목록에도 없고 확장 소유 명령도 아님 */
    public const ARTISAN_REASON_NOT_ALLOWED = 'not_allowlisted';

    /** 명령은 허용되나 선언되지 않은 옵션이 쓰임 */
    public const ARTISAN_REASON_OPTION = 'option_denied';

    /** 허용 개수를 넘는 위치 인자가 쓰임 */
    public const ARTISAN_REASON_ARGUMENT = 'argument_denied';

    /** 셸 해석을 유발하는 메타문자 — 하나라도 있으면 인자 배열 실행이 불가하므로 거부 */
    private const SHELL_METACHARACTERS = [
        '|', '&', ';', '$', '`', '>', '<', '(', ')', '{', '}',
        '*', '?', '!', '\\', '"', "'", "\n", "\r", "\t",
    ];

    /**
     * Shell 타입 command 를 실행해도 되는지 판정합니다.
     *
     * 게이트가 켜져 있고, 화이트리스트가 비어있지 않으며, 메타문자가 없고,
     * 첫 토큰의 basename 이 화이트리스트와 완전 일치할 때만 true.
     *
     * @param  string  $command  스케줄에 저장된 shell command 문자열
     * @return bool 실행을 허용하면 true
     */
    public static function isShellCommandAllowed(string $command): bool
    {
        if (! (bool) config('schedule_security.shell.enabled', false)) {
            return false;
        }

        $allowed = array_values(array_filter(array_map(
            static fn ($binary): string => trim((string) $binary),
            (array) config('schedule_security.shell.allowed_binaries', []),
        )));

        if ($allowed === []) {
            return false;
        }

        $tokens = self::tokenize($command);

        if ($tokens === null || $tokens === []) {
            return false;
        }

        return in_array(basename($tokens[0]), $allowed, true);
    }

    /**
     * Shell command 를 `Process::run(array)` 에 넘길 안전한 인자 배열로 변환합니다.
     *
     * 메타문자가 섞여 있으면 셸 미경유 실행이 불가하므로 null 을 반환한다.
     *
     * @param  string  $command  스케줄에 저장된 shell command 문자열
     * @return array<int, string>|null 인자 배열, 안전하게 토큰화할 수 없으면 null
     */
    public static function tokenizeShellCommand(string $command): ?array
    {
        return self::tokenize($command);
    }

    /**
     * Artisan command 문자열에서 명령명을 추출합니다.
     *
     * 엄격 파서를 경유하므로, Symfony 가 다르게 해석할 수 있는 형태(따옴표·백슬래시·
     * 선행 옵션)는 명령명을 돌려주지 않고 null 이 된다 — 여기서 반환된 이름은
     * 실제 실행되는 이름과 반드시 같다.
     *
     * @param  string  $command  스케줄에 저장된 artisan command 문자열
     * @return string|null 명령명, 정규 형태가 아니면 null
     */
    public static function extractArtisanCommandName(string $command): ?string
    {
        $parsed = self::parseArtisanCommand($command);

        return $parsed === null ? null : $parsed['name'];
    }

    /**
     * Artisan command 를 실행 가능한지 판정하고 사유·실행 계획을 함께 돌려줍니다.
     *
     * @param  string  $command  스케줄에 저장된 artisan command 문자열
     * @return array{allowed: bool, reason: string|null, name: string|null, parameters: array<string, mixed>}
     */
    public static function inspectArtisanCommand(string $command): array
    {
        $parsed = self::parseArtisanCommand($command);

        if ($parsed === null) {
            return self::artisanVerdict(false, self::ARTISAN_REASON_MALFORMED);
        }

        $name = $parsed['name'];

        // ① 최종 거부권 — 허용목록·확장 자동 허용보다 먼저 평가한다.
        //    확장이 코어 명령 이름을 가로채 등록하는 경우도 여기서 걸린다.
        if (self::isArtisanCommandDenied($name)) {
            return self::artisanVerdict(false, self::ARTISAN_REASON_DENIED, $name);
        }

        $allowlist = (array) config('schedule_security.artisan.allowlist', []);
        $spec = self::findArtisanAllowlistSpec($allowlist, $name);

        if ($spec !== null) {
            return self::verifyArtisanUsage(
                $name,
                $parsed,
                static fn (string $option): bool => in_array($option, $spec['options'], true),
                $spec['max_arguments'],
            );
        }

        // ③ 확장 소유 명령 — 자기 정의(getDefinition)에 있는 옵션만 허용한다.
        $definition = self::resolveExtensionCommandDefinition($name);

        if ($definition === null) {
            return self::artisanVerdict(false, self::ARTISAN_REASON_NOT_ALLOWED, $name);
        }

        return self::verifyArtisanUsage(
            $name,
            $parsed,
            static fn (string $option): bool => $definition->hasOption($option),
            0,
        );
    }

    /**
     * 실행 허용 시 (명령명, 인자배열) 실행 계획을 돌려줍니다.
     *
     * 호출부는 반드시 이 계획으로 `Artisan::call($name, $parameters, ...)` 를 호출해야 한다.
     * 문자열째 넘기면 Symfony 가 재파싱해 검증한 이름과 다른 명령이 실행될 수 있다.
     *
     * 코어의 두 호출부(FormRequest Rule · 실행부)는 거부 사유 코드가 필요해
     * `inspectArtisanCommand()` 를 직접 쓴다. 이 메서드는 사유가 필요 없는 호출부
     * (확장의 자체 스케줄러 등)를 위한 형태이며, 판정은 전적으로 위임하므로
     * 두 경로의 결과가 갈릴 여지가 없다.
     *
     * @param  string  $command  스케줄에 저장된 artisan command 문자열
     * @return array{name: string, parameters: array<string, mixed>}|null 허용되지 않으면 null
     */
    public static function resolveArtisanCommand(string $command): ?array
    {
        $verdict = self::inspectArtisanCommand($command);

        if (! $verdict['allowed'] || $verdict['name'] === null) {
            return null;
        }

        return ['name' => $verdict['name'], 'parameters' => $verdict['parameters']];
    }

    /**
     * Artisan command 를 실행해도 되는지 판정합니다.
     *
     * @param  string  $command  스케줄에 저장된 artisan command 문자열
     * @return bool 실행을 허용하면 true
     */
    public static function isArtisanCommandAllowed(string $command): bool
    {
        return self::inspectArtisanCommand($command)['allowed'];
    }

    /**
     * Artisan command 문자열을 엄격 규칙으로 파싱합니다.
     *
     * 규칙(순서대로, 하나라도 걸리면 null):
     *  1. trim 후 빈 문자열
     *  2. 제어문자 포함
     *  3. 따옴표(`"` `'`)·백슬래시 포함 — Symfony 가 재해석하는 형태를 원천 배제
     *  4. 첫 토큰이 명령명 형태가 아님 (`-` 로 시작 불가)
     *  5. 나머지 토큰이 `--이름` / `--이름=값` 롱옵션이 아니거나 옵션 이름이 중복
     *
     * @param  string  $command  artisan command 문자열
     * @return array{name: string, options: array<string, mixed>, arguments: array<int, string>}|null
     */
    private static function parseArtisanCommand(string $command): ?array
    {
        $command = trim($command);

        if ($command === '') {
            return null;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $command) === 1) {
            return null;
        }

        // 따옴표·백슬래시가 있으면 Symfony 의 재해석 여지가 생긴다 (`"tinker"` → `tinker`).
        if (preg_match('/["\'\\\\]/', $command) === 1) {
            return null;
        }

        $tokens = preg_split('/\s+/', $command);

        if ($tokens === false || $tokens === []) {
            return null;
        }

        $name = array_shift($tokens);

        if (preg_match('/^[A-Za-z][A-Za-z0-9_.-]*(?::[A-Za-z0-9_.-]+)*$/', $name) !== 1) {
            return null;
        }

        $options = [];
        $arguments = [];

        foreach ($tokens as $token) {
            if ($token === '--' || $token === '-') {
                return null;
            }

            if (str_starts_with($token, '--')) {
                $body = substr($token, 2);
                $separator = strpos($body, '=');

                $optionName = $separator === false ? $body : substr($body, 0, $separator);
                $optionValue = $separator === false ? true : substr($body, $separator + 1);

                if (preg_match('/^[A-Za-z][A-Za-z0-9-]*$/', $optionName) !== 1) {
                    return null;
                }

                // 중복 옵션은 어느 값이 이기는지가 파서에 좌우되므로 거부한다.
                if (array_key_exists($optionName, $options)) {
                    return null;
                }

                $options[$optionName] = $optionValue;

                continue;
            }

            // 단축 옵션(`-v`)은 이름이 정의에 따라 달라져 화이트리스트 대조가 불가능하다.
            if (str_starts_with($token, '-')) {
                return null;
            }

            $arguments[] = $token;
        }

        return ['name' => $name, 'options' => $options, 'arguments' => $arguments];
    }

    /**
     * 파싱된 명령의 옵션·위치 인자가 허용 범위 안인지 확인합니다.
     *
     * @param  string  $name  명령명
     * @param  array{name: string, options: array<string, mixed>, arguments: array<int, string>}  $parsed  파싱 결과
     * @param  callable(string): bool  $optionAllowed  옵션 허용 판정
     * @param  int  $maxArguments  허용 위치 인자 개수
     * @return array{allowed: bool, reason: string|null, name: string|null, parameters: array<string, mixed>}
     */
    private static function verifyArtisanUsage(string $name, array $parsed, callable $optionAllowed, int $maxArguments): array
    {
        foreach (array_keys($parsed['options']) as $option) {
            if (! $optionAllowed((string) $option)) {
                return self::artisanVerdict(false, self::ARTISAN_REASON_OPTION, $name);
            }
        }

        if (count($parsed['arguments']) > $maxArguments) {
            return self::artisanVerdict(false, self::ARTISAN_REASON_ARGUMENT, $name);
        }

        $parameters = [];

        foreach ($parsed['options'] as $option => $value) {
            $parameters['--'.$option] = $value;
        }

        // 위치 인자는 이름이 있어야 ArrayInput 으로 넘길 수 있다. 허용 개수가 0 인 현재
        // 설정에서는 도달하지 않지만, 상한을 넓히면 이름을 해석할 수 없는 인자는 거부한다.
        if ($parsed['arguments'] !== []) {
            $names = self::resolveArtisanArgumentNames($name, count($parsed['arguments']));

            if ($names === null) {
                return self::artisanVerdict(false, self::ARTISAN_REASON_ARGUMENT, $name);
            }

            foreach ($parsed['arguments'] as $index => $value) {
                $parameters[$names[$index]] = $value;
            }
        }

        return self::artisanVerdict(true, null, $name, $parameters);
    }

    /**
     * 명령명이 차단목록(정확 일치 또는 접두사)에 걸리는지 판정합니다.
     *
     * @param  string  $name  명령명
     * @return bool 차단 대상이면 true
     */
    private static function isArtisanCommandDenied(string $name): bool
    {
        $normalized = strtolower($name);

        $denylist = array_map(
            static fn ($denied): string => strtolower(trim((string) $denied)),
            (array) config('schedule_security.artisan.denylist', []),
        );

        if (in_array($normalized, $denylist, true)) {
            return true;
        }

        foreach ((array) config('schedule_security.artisan.denylist_prefixes', []) as $prefix) {
            $prefix = strtolower(trim((string) $prefix));

            if ($prefix !== '' && str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 허용목록에서 명령 항목을 찾아 정규화합니다 (대소문자 무관).
     *
     * @param  array<string, mixed>  $allowlist  허용목록 설정
     * @param  string  $name  명령명
     * @return array{options: array<int, string>, max_arguments: int}|null 미등재면 null
     */
    private static function findArtisanAllowlistSpec(array $allowlist, string $name): ?array
    {
        $normalized = strtolower($name);

        foreach ($allowlist as $key => $spec) {
            if (strtolower(trim((string) $key)) !== $normalized) {
                continue;
            }

            $spec = (array) $spec;

            return [
                'options' => array_map('strval', (array) ($spec['options'] ?? [])),
                'max_arguments' => (int) ($spec['max_arguments'] ?? 0),
            ];
        }

        return null;
    }

    /**
     * 확장이 소유한 명령이면 그 정의를 돌려줍니다.
     *
     * 이름이 아니라 등록된 명령 인스턴스의 클래스 네임스페이스로 소유자를 판정한다 —
     * Symfony 가 실제 실행에 쓰는 레지스트리이므로 이름만으로 위조할 수 없다.
     *
     * @param  string  $name  명령명
     * @return InputDefinition|null 확장 소유가 아니면 null
     */
    private static function resolveExtensionCommandDefinition(string $name): ?InputDefinition
    {
        if (! (bool) config('schedule_security.artisan.allow_extension_commands', true)) {
            return null;
        }

        $namespaces = array_filter(array_map(
            static fn ($namespace): string => trim((string) $namespace),
            (array) config('schedule_security.artisan.extension_namespaces', []),
        ));

        if ($namespaces === []) {
            return null;
        }

        $command = self::findRegisteredCommand($name);

        if ($command === null) {
            return null;
        }

        $class = get_class($command);

        foreach ($namespaces as $namespace) {
            if (str_starts_with($class, $namespace)) {
                return $command->getDefinition();
            }
        }

        return null;
    }

    /**
     * 등록된 artisan 명령 인스턴스를 찾습니다.
     *
     * 콘솔 부트스트랩 비용이 있으므로 허용목록에서 찾지 못했을 때만 호출한다.
     * 조회 자체가 실패하면 "허용되지 않음" 으로 취급한다(fail-closed).
     *
     * @param  string  $name  명령명
     * @return SymfonyCommand|null 없으면 null
     */
    private static function findRegisteredCommand(string $name): ?SymfonyCommand
    {
        try {
            $commands = Artisan::all();
        } catch (Throwable) {
            return null;
        }

        return $commands[$name] ?? null;
    }

    /**
     * 위치 인자에 대응하는 인자 이름을 순서대로 해석합니다.
     *
     * @param  string  $name  명령명
     * @param  int  $count  위치 인자 개수
     * @return array<int, string>|null 해석 불가하면 null
     */
    private static function resolveArtisanArgumentNames(string $name, int $count): ?array
    {
        $command = self::findRegisteredCommand($name);

        if ($command === null) {
            return null;
        }

        $names = array_values(array_filter(
            array_keys($command->getDefinition()->getArguments()),
            static fn (string $argument): bool => $argument !== 'command',
        ));

        return count($names) >= $count ? $names : null;
    }

    /**
     * 판정 결과 배열을 만듭니다.
     *
     * @param  bool  $allowed  허용 여부
     * @param  string|null  $reason  거부 사유 코드
     * @param  string|null  $name  명령명
     * @param  array<string, mixed>  $parameters  실행 인자 배열
     * @return array{allowed: bool, reason: string|null, name: string|null, parameters: array<string, mixed>}
     */
    private static function artisanVerdict(bool $allowed, ?string $reason = null, ?string $name = null, array $parameters = []): array
    {
        return ['allowed' => $allowed, 'reason' => $reason, 'name' => $name, 'parameters' => $parameters];
    }

    /**
     * 메타문자 없는 공백 구분 토큰화. 메타문자가 있으면 null 을 반환합니다.
     *
     * 따옴표 escape 처리 버그를 원천 회피하기 위해, 따옴표를 포함한 복잡한 명령은
     * 지원하지 않고 거부하는 보수적 구현이다(복합 명령은 래퍼 스크립트로 유도).
     *
     * @param  string  $command  command 문자열
     * @return array<int, string>|null 토큰 배열, 안전하지 않으면 null
     */
    private static function tokenize(string $command): ?array
    {
        $command = trim($command);

        if ($command === '') {
            return null;
        }

        // 제어문자(개행 포함)가 섞인 명령은 즉시 거부
        if (preg_match('/[\x00-\x1F\x7F]/', $command) === 1) {
            return null;
        }

        foreach (self::SHELL_METACHARACTERS as $meta) {
            if (str_contains($command, $meta)) {
                return null;
            }
        }

        $tokens = preg_split('/\s+/', $command);

        return ($tokens === false || $tokens === []) ? null : $tokens;
    }
}
