<?php

namespace App\Console\Commands\Traits;

use App\Console\Helpers\ConsoleConfirm;
use Symfony\Component\Console\Input\StreamableInputInterface;

/**
 * Laravel Command 컨텍스트에서 표준화된 yes/no 프롬프트를 제공하는 트레이트.
 *
 * Symfony QuestionHelper 를 거쳐 입력을 받기 때문에 Laravel 테스트의
 * expectsQuestion() / expectsConfirmation() 헬퍼와 호환된다. 입력 정규화 및
 * 재질문 루프는 ConsoleConfirm::parse() 와 공유한다.
 *
 * - `--no-interaction` 시: $default 즉시 반환
 * - 응답을 받을 수 없는 실행(콘솔 없는 STDIN) 시: $default 즉시 반환
 * - empty 입력 시: $default 반환
 * - yes/y → true, no/n → false (대소문자 무시)
 * - 그 외 입력: "yes, y, no, n 중 하나로 입력해 주세요." 출력 후 재질문
 */
trait HasUnifiedConfirm
{
    /**
     * 표준 yes/no 프롬프트.
     *
     * @param  string  $question  질문 메시지
     * @param  bool  $default  기본값 (true=[yes], false=[no])
     */
    protected function unifiedConfirm(string $question, bool $default = false): bool
    {
        if (! $this->input->isInteractive() || ! $this->canPromptForAnswer()) {
            return $default;
        }

        $hint = $default ? '[yes]' : '[no]';
        $prompt = "{$question} (yes/no) {$hint}";

        while (true) {
            // Symfony QuestionHelper 의 default 표시(`[default]`)를 회피하기 위해 default 를
            // null 로 넘긴다. empty 입력 시 Symfony 가 null 반환 → ConsoleConfirm::parse 가
            // empty 로 처리하여 자체 default 적용.
            $raw = (string) $this->ask($prompt);
            $parsed = ConsoleConfirm::parse($raw, $default);

            if ($parsed !== null) {
                return $parsed;
            }

            $this->warn('  yes, y, no, n 중 하나로 입력해 주세요.');
        }
    }

    /**
     * 이 실행이 프롬프트 응답을 실제로 받을 수 있는지 판정합니다.
     *
     * Symfony 는 `posix_isatty()` 로 비대화 실행을 감지해 `isInteractive()` 를 false 로
     * 내리는데, Windows PHP 에는 posix 확장이 없어 그 분기가 실행되지 않는다. 그래서
     * `--no-interaction` 없이 CI·스케줄러·에이전트처럼 콘솔이 없는 곳에서 부르면
     * `isInteractive()` 가 true 로 남고 `ask()` 가 오지 않을 응답을 무한히 기다린다.
     * 프롬프트 출력마저 버퍼에 갇혀 있어 겉으로는 "커맨드가 느리다" 로만 보인다.
     *
     * `stream_isatty()` 는 posix 확장 없이도 Windows 를 포함해 동작하므로 이를 쓴다.
     * 판정 재료가 없으면 true 를 돌려 기존 동작(질문)을 유지한다 — 물어보지 못해 멈추는
     * 것보다 물어볼 수 있는데 안 묻는 쪽이 더 위험하기 때문이다.
     *
     * @return bool 응답을 받을 수 있으면 true
     */
    protected function canPromptForAnswer(): bool
    {
        // 테스트는 QuestionHelper 를 대체하거나 입력을 주입하므로 STDIN 을 쓰지 않는다
        if ($this->laravel !== null && $this->laravel->runningUnitTests()) {
            return true;
        }

        // 입력 스트림이 주입된 실행(CommandTester::setInputs 등)도 STDIN 과 무관하다
        if ($this->input instanceof StreamableInputInterface && is_resource($this->input->getStream())) {
            return true;
        }

        if (! \defined('STDIN') || ! is_resource(STDIN) || ! \function_exists('stream_isatty')) {
            return true;
        }

        return stream_isatty(STDIN);
    }
}
