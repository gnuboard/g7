<?php

namespace App\Console\Commands\Traits;

use App\Console\Helpers\ConsoleConfirm;
use Illuminate\Console\Command;
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
 *   (기본값 「아니오」가 곧 작업 취소인 확인은 `canAnswerConfirmation()` 으로 먼저 갈라
 *   `refuseUnanswerableConfirmation()` 으로 실패를 알린다 — 취소가 종료 코드 0 으로 끝나면 자동화가 성공으로 읽는다)
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
     * @return bool yes 면 true, no 면 false (응답을 받을 수 없거나 빈 입력이면 $default)
     */
    protected function unifiedConfirm(string $question, bool $default = false): bool
    {
        if (! $this->canAnswerConfirmation()) {
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

            $this->warn('  '.__('common.console.console_confirm.invalid_answer'));
        }
    }

    /**
     * 이 실행이 확인 질문에 답을 받을 수 있는지 판정합니다 — 답할 수 없는 실행 판정의 단일 지점.
     *
     * `--no-interaction` 이거나 입력이 콘솔이 아닌 실행(cron, CI, 원격 명령)은 답할 수 없다. `unifiedConfirm()` 은 그런
     * 실행에서 묻지 않고 기본값을 돌려준다. 업데이트, 제거처럼 기본값 「아니오」가 곧 작업 취소인 확인은 이 판정으로 먼저
     * 갈라 `refuseUnanswerableConfirmation()` 으로 끝낸다. 대화형에서 운영자가 직접 답한 「아니오」는 의도한 취소라 그대로 둔다.
     *
     * @return bool 답을 받을 수 있으면 true
     */
    protected function canAnswerConfirmation(): bool
    {
        return $this->input->isInteractive() && $this->canPromptForAnswer();
    }

    /**
     * 확인에 답할 수 없어 작업을 시작하지 않았음을 알리고 실패 종료 코드를 돌려줍니다.
     *
     * @param  string  $reason  시작하지 않은 사유 (오류로 출력)
     * @param  string  $hint  확인 없이 진행하는 방법 안내
     * @return int Command::FAILURE
     */
    protected function refuseUnanswerableConfirmation(string $reason, string $hint): int
    {
        $this->error($reason);
        $this->line($hint);

        return Command::FAILURE;
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
