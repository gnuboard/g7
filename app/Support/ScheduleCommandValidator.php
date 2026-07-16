<?php

namespace App\Support;

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
 *  2. Artisan — 기본 허용, 차단목록만 거부. `tinker` 처럼 임의 PHP 코드를 실행하거나
 *     `db:wipe` 처럼 파괴적인 명령을 스케줄로 실행하지 못하게 막는다.
 *
 * 예외를 던지지 않고 bool/배열만 반환한다 — 차단 시 어떤 응답/예외를 낼지는 호출부가 결정한다.
 */
class ScheduleCommandValidator
{
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
     * Artisan command 문자열에서 명령명(첫 토큰)을 추출합니다.
     *
     * @param  string  $command  스케줄에 저장된 artisan command 문자열
     * @return string|null 명령명, 빈 문자열이면 null
     */
    public static function extractArtisanCommandName(string $command): ?string
    {
        $trimmed = trim($command);

        if ($trimmed === '') {
            return null;
        }

        $tokens = preg_split('/\s+/', $trimmed);

        return ($tokens === false || $tokens === []) ? null : $tokens[0];
    }

    /**
     * Artisan command 가 차단목록에 걸리지 않는지 판정합니다.
     *
     * @param  string  $command  스케줄에 저장된 artisan command 문자열
     * @return bool 실행을 허용하면 true
     */
    public static function isArtisanCommandAllowed(string $command): bool
    {
        $name = self::extractArtisanCommandName($command);

        if ($name === null) {
            return false;
        }

        $denylist = array_map(
            static fn ($denied): string => strtolower(trim((string) $denied)),
            (array) config('schedule_security.artisan.denylist', []),
        );

        return ! in_array(strtolower($name), $denylist, true);
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
