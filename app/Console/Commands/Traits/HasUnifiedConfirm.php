<?php

namespace App\Console\Commands\Traits;

use App\Console\Helpers\ConsoleConfirm;

/**
 * Laravel Command 컨텍스트에서 표준화된 yes/no 프롬프트를 제공하는 트레이트.
 *
 * Symfony QuestionHelper 를 거쳐 입력을 받기 때문에 Laravel 테스트의
 * expectsQuestion() / expectsConfirmation() 헬퍼와 호환된다. 입력 정규화 및
 * 재질문 루프는 ConsoleConfirm::parse() 와 공유한다.
 *
 * - `--no-interaction` 시: $default 즉시 반환
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
        if (! $this->input->isInteractive()) {
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
}
