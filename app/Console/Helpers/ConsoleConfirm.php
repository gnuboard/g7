<?php

namespace App\Console\Helpers;

/**
 * 콘솔 yes/no 프롬프트 표준 헬퍼.
 *
 * Symfony Console 비의존(fgets 기반) — Laravel Command 외부, upgrade step,
 * 단순 PHP 스크립트 어디서나 호출 가능하다. 입력 처리 규칙:
 *
 *  1. 입력을 trim 후 소문자 정규화한다
 *  2. empty 입력은 default 값으로 처리한다
 *  3. yes / y → true, no / n → false
 *  4. 위 외 입력은 안내 메시지 출력 후 다시 질문한다 (재질문 루프)
 *  5. STDIN 미연결(非TTY) 또는 EOF 시 default 를 즉시 반환한다 (CI/spawn 안전망)
 */
final class ConsoleConfirm
{
    /**
     * 표준 yes/no 프롬프트.
     *
     * @param  string  $question  질문 메시지 (말미 안내/콜론은 자동 부여)
     * @param  bool  $default  기본값 (true=[yes], false=[no])
     * @param  resource|null  $stdin  테스트용 STDIN 주입. null 이면 STDIN 사용
     * @param  callable|null  $writer  테스트용 출력 콜백. null 이면 echo 사용
     * @return bool yes 면 true, no 면 false (非TTY·EOF·빈 입력이면 $default)
     */
    public static function ask(
        string $question,
        bool $default = false,
        $stdin = null,
        ?callable $writer = null,
    ): bool {
        $writer ??= static fn (string $text) => print $text;

        $useStdin = $stdin !== null;

        if (! $useStdin && ! self::isTty()) {
            return $default;
        }

        $hint = $default ? '[yes]' : '[no]';
        $stream = $useStdin ? $stdin : (defined('STDIN') ? STDIN : null);

        if ($stream === null) {
            return $default;
        }

        while (true) {
            $writer("{$question} (yes/no) {$hint}: ");

            $raw = fgets($stream);
            if ($raw === false) {
                return $default;
            }

            $parsed = self::parse($raw, $default);
            if ($parsed !== null) {
                return $parsed;
            }

            $writer('  '.self::invalidAnswerMessage()."\n");
        }
    }

    /**
     * 입력 문자열을 yes/no/null 로 정규화한다.
     *
     * @param  string  $raw  사용자 입력 원문 (개행 포함 가능)
     * @param  bool  $default  empty 입력 시 반환할 값
     * @return bool|null true=yes, false=no, null=재질문 필요
     */
    public static function parse(string $raw, bool $default): ?bool
    {
        $answer = strtolower(trim($raw));

        if ($answer === '') {
            return $default;
        }
        if ($answer === 'yes' || $answer === 'y') {
            return true;
        }
        if ($answer === 'no' || $answer === 'n') {
            return false;
        }

        return null;
    }

    /**
     * STDIN 이 TTY 인지 검사한다.
     *
     * @return bool TTY 면 true (STDIN 이 없거나 판정 함수가 없으면 false)
     */
    private static function isTty(): bool
    {
        if (! defined('STDIN')) {
            return false;
        }
        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDIN);
        }
        if (function_exists('posix_isatty')) {
            return @posix_isatty(STDIN);
        }

        return false;
    }

    /**
     * 잘못된 답을 받았을 때의 재질문 안내 문구를 돌려준다.
     *
     * 이 헬퍼는 Laravel 이 부팅되지 않은 단순 PHP 스크립트에서도 불리므로, 번역기가 없으면 번역 키 대신
     * 원문(한국어)을 쓴다. Laravel 안에서는 사이트 언어의 문구가 나온다.
     *
     * @return string 안내 문구
     */
    private static function invalidAnswerMessage(): string
    {
        if (function_exists('app') && app()->bound('translator')) {
            return __('common.console.console_confirm.invalid_answer');
        }

        return 'yes, y, no, n 중 하나로 입력해 주세요.';
    }
}
