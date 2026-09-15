<?php

namespace Modules\Sirsoft\Board\Exceptions;

use Exception;

/**
 * 게시판 유형 조작 실패 예외 클래스
 *
 * 사용 중인 유형이거나 기본 유형이어서 삭제할 수 없는 경우에 발생합니다.
 * 컨트롤러는 이 예외를 도메인 실패로 구분해 기존 422 응답으로 처리하고,
 * 그 외 예외는 서버 결함으로 보아 500 을 반환합니다.
 */
class BoardTypeOperationException extends Exception
{
    /**
     * 게시판 유형 조작 실패 예외 생성자
     *
     * @param  string  $messageKey  다국어 메시지 키 (sirsoft-board::messages.*)
     * @param  array<string, mixed>  $replace  메시지 치환 파라미터
     */
    public function __construct(
        private string $messageKey,
        private array $replace = []
    ) {
        parent::__construct(__($messageKey, $replace));
    }

    /**
     * 다국어 메시지 키를 반환합니다.
     *
     * 컨트롤러가 예외 메시지 원문 대신 이 키로 응답을 구성하기 위한 접근자입니다.
     *
     * @return string 다국어 메시지 키
     */
    public function getMessageKey(): string
    {
        return $this->messageKey;
    }

    /**
     * 메시지 치환 파라미터를 반환합니다.
     *
     * @return array<string, mixed> 치환 파라미터
     */
    public function getMessageParams(): array
    {
        return $this->replace;
    }
}
