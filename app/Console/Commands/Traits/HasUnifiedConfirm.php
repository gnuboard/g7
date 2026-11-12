<?php

namespace App\Console\Commands\Traits;

use App\Console\Helpers\ConsoleConfirm;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;

/**
 * Laravel Command 컨텍스트에서 표준화된 yes/no 프롬프트를 제공하는 트레이트.
 *
 * Symfony QuestionHelper 를 거쳐 입력을 받기 때문에 Laravel 테스트의
 * expectsQuestion() / expectsConfirmation() 헬퍼와 호환된다. 입력 정규화 및
 * 재질문 루프는 ConsoleConfirm::parse() 와 공유한다.
 *
 * - `--yes`(`-y`) 시: 묻지 않고 true (부가 선택 질문은 $default)
 * - `--no-interaction` 시: $default 즉시 반환
 * - 응답을 받을 수 없는 실행(콘솔 없는 STDIN) 시: $default 즉시 반환
 * - empty 입력 시: $default 반환
 * - yes/y → true, no/n → false (대소문자 무시)
 * - 그 외 입력: "yes, y, no, n 중 하나로 입력해 주세요." 출력 후 재질문
 *
 * 묻지 못해 기본값 「아니오」로 떨어진 실행은 조용히 성공하지 않는다 — 경고를 내고, 명령은
 * `confirmAbortExitCode()` 로 실패 종료코드를 돌려준다. 터미널 없는 실행(스케줄러·배포 스크립트)에서
 * 「실행했는데 아무 일도 일어나지 않은」 것을 알 수 있어야 하기 때문이다.
 */
trait HasUnifiedConfirm
{
    /**
     * 이번 실행에서 묻지 못해 기본값 「아니오」로 떨어진 확인이 있었는지.
     */
    protected bool $declinedNonInteractive = false;

    /**
     * 모든 확인 질문에 「예」로 답하는 `--yes`(`-y`) 옵션을 더한다.
     *
     * 시그니처(`$signature`)를 쓰는 명령은 `getOptions()` 가 무시되므로 Symfony 의 `configure()` 로 더한다.
     * `configure()` 는 시그니처 옵션보다 먼저 실행된다 — 시그니처에 같은 이름의 옵션을 두면 생성 시 오류가 난다.
     *
     * @return void 반환값 없음
     */
    protected function configure(): void
    {
        parent::configure();

        if (! $this->getDefinition()->hasOption('yes')) {
            $this->addOption('yes', 'y', InputOption::VALUE_NONE, '모든 확인 질문에 예로 답하고 진행 (터미널 없는 실행용)');
        }
    }

    /**
     * `--yes` 옵션이 주어졌는지.
     *
     * @return bool 주어졌으면 true
     */
    protected function yesOptionGiven(): bool
    {
        return $this->input->hasOption('yes') && (bool) $this->input->getOption('yes');
    }

    /**
     * 이 실행이 질문을 던질 수 없는지 (비대화 실행이거나 응답을 받을 수 없는 환경).
     *
     * @return bool 물을 수 없으면 true
     */
    protected function cannotPrompt(): bool
    {
        return ! $this->input->isInteractive() || ! $this->canPromptForAnswer();
    }

    /**
     * 확인을 거절해 명령을 끝낼 때의 종료코드.
     *
     * 사람이 직접 「아니오」라고 답했으면 성공이고, 묻지 못해 기본값으로 떨어졌으면 실패다.
     *
     * @return int 종료코드
     */
    protected function confirmAbortExitCode(): int
    {
        return $this->declinedNonInteractive ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * 표준 yes/no 프롬프트.
     *
     * @param  string  $question  질문 메시지
     * @param  bool  $default  기본값 (true=[yes], false=[no])
     * @param  bool  $optional  진행 여부가 아니라 부가 선택을 묻는 질문인지. 참이면 `--yes`·비대화 실행에서
     *                          기본값을 그대로 쓰고 경고·실패 처리도 하지 않는다 (예: 「검색 색인도 다시 만들까요?」)
     * @return bool yes 면 true, no 면 false (응답을 받을 수 없거나 빈 입력이면 $default)
     */
    protected function unifiedConfirm(string $question, bool $default = false, bool $optional = false): bool
    {
        if ($this->yesOptionGiven()) {
            return $optional ? $default : true;
        }

        if ($this->cannotPrompt()) {
            if (! $default && ! $optional) {
                $this->warn(__('common.console.confirm_requires_yes', ['question' => $question]));
                $this->declinedNonInteractive = true;
            }

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
     * 선택 질문. 물을 수 없거나 `--yes` 면 기다리지 않고 기본값을 돌려준다.
     *
     * @param  string  $question  질문 메시지
     * @param  array<int|string, string>  $choices  선택지
     * @param  string|int  $default  기본 선택 (선택지의 키 또는 값)
     * @return string 고른 값
     */
    protected function unifiedChoice(string $question, array $choices, string|int $default): string
    {
        if ($this->yesOptionGiven() || $this->cannotPrompt()) {
            return (string) $default;
        }

        return (string) $this->choice($question, $choices, $default);
    }

    /**
     * 입력 질문. 물을 수 없거나 `--yes` 면 기다리지 않고 기본값을 돌려준다.
     *
     * @param  string  $question  질문 메시지
     * @param  string  $default  기본값
     * @return string 입력한 값 (빈 입력이면 기본값)
     */
    protected function unifiedAsk(string $question, string $default = ''): string
    {
        if ($this->yesOptionGiven() || $this->cannotPrompt()) {
            return $default;
        }

        return (string) ($this->ask($question, $default) ?? $default);
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
