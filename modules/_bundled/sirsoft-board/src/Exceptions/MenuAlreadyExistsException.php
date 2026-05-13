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
     * 메뉴 중복 예외 생성자
     *
     * @param  string  $message  예외 메시지
     * @param  int  $code  예외 코드
     * @param  \Throwable|null  $previous  이전 예외
     */
    public function __construct(string $message = '', int $code = 409, ?\Throwable $previous = null)
    {
        if (empty($message)) {
            $message = __('sirsoft-board::messages.boards.menu_already_exists');
        }

        parent::__construct($message, $code, $previous);
    }
}