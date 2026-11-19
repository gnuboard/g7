<?php

namespace App\Execution\Exceptions;

/**
 * 커맨드 실행 자체가 불가능할 때 던지는 예외.
 *
 * 커맨드가 "실행됐지만 실패" 한 경우(비-0 종료)는 예외가 아니라
 * CommandRunResult 로 표현한다 — 이 예외는 스폰 불가·러너 성립 불가 등
 * 실행이 시작조차 못 한 경우 전용이다.
 *
 * 메시지 키 + 치환 파라미터를 보관한다 (번역문을 들고 다니지 않는다 —
 * docs/backend/exceptions.md "예외 → 응답 매핑").
 */
class CommandExecutionException extends \RuntimeException
{
    /**
     * @param  string  $messageKey  다국어 메시지 키 (예: 'errors.command_execution_failed')
     * @param  array<string, string>  $messageParams  메시지 치환 파라미터
     */
    public function __construct(
        private readonly string $messageKey,
        private readonly array $messageParams = [],
    ) {
        parent::__construct(__($messageKey, $messageParams));
    }

    /**
     * 다국어 메시지 키를 반환합니다.
     *
     * @return string 메시지 키
     */
    public function getMessageKey(): string
    {
        return $this->messageKey;
    }

    /**
     * 메시지 치환 파라미터를 반환합니다.
     *
     * @return array<string, string> 치환 파라미터
     */
    public function getMessageParams(): array
    {
        return $this->messageParams;
    }
}
