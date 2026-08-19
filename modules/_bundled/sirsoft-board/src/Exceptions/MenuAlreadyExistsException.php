<?php

namespace Modules\Sirsoft\Board\Exceptions;

use Exception;

/**
 * 메뉴 중복 예외 클래스
 *
 * 동일한 URL의 메뉴가 이미 존재할 때 발생하는 예외를 처리합니다.
 */
class MenuAlreadyExistsException extends Exception
{
    /**
     * 기본 안내 문구의 다국어 키
     */
    private const DEFAULT_MESSAGE_KEY = 'sirsoft-board::messages.boards.menu_already_exists';

    /**
     * 메시지 키 (직접 문구를 받은 경우 null)
     */
    private ?string $messageKey;

    /**
     * 메뉴 중복 예외 생성자
     *
     * @param  string  $message  예외 메시지 (생략 시 기본 키로 해석)
     * @param  int  $code  예외 코드
     * @param  \Throwable|null  $previous  이전 예외
     */
    public function __construct(string $message = '', int $code = 409, ?\Throwable $previous = null)
    {
        $this->messageKey = $message === '' ? self::DEFAULT_MESSAGE_KEY : null;

        if ($message === '') {
            $message = __(self::DEFAULT_MESSAGE_KEY);
        }

        parent::__construct($message, $code, $previous);
    }

    /**
     * 다국어 메시지 키를 반환합니다.
     *
     * 호출부가 직접 문구를 넘긴 경우엔 키가 없으므로 기본 키를 돌려준다 — 응답에는
     * 언제나 키가 전달되어야 하며, 이미 번역된 문구가 키 자리에 들어가면 해석에
     * 실패해 원문이 그대로 노출된다.
     *
     * @return string 다국어 메시지 키
     */
    public function getMessageKey(): string
    {
        return $this->messageKey ?? self::DEFAULT_MESSAGE_KEY;
    }

    /**
     * 메시지 치환 파라미터를 반환합니다.
     *
     * @return array<string, mixed> 치환 파라미터
     */
    public function getMessageParams(): array
    {
        return [];
    }
}
