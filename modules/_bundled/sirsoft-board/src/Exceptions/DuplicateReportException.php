<?php

namespace Modules\Sirsoft\Board\Exceptions;

use Exception;

/**
 * 중복 신고 예외 클래스
 *
 * 동일한 사용자가 같은 대상을 중복으로 신고할 때 발생하는 예외를 처리합니다.
 */
class DuplicateReportException extends Exception
{
    /**
     * 중복 신고 예외 생성자
     *
     * @param  int  $code  예외 코드
     * @param  \Throwable|null  $previous  이전 예외
     */
    public function __construct(int $code = 0, ?\Throwable $previous = null)
    {
        $message = __('sirsoft-board::messages.errors.duplicate_report');

        parent::__construct($message, $code, $previous);
    }
}
