<?php

namespace Modules\Sirsoft\Board\Exceptions;

use Exception;

/**
 * 영구삭제 신고 상태 변경 예외 클래스
 *
 * 영구삭제(deleted) 처리된 신고 케이스의 상태를 다른 상태로 변경하려 할 때 발생합니다.
 * 영구삭제는 최종 상태이므로 다른 상태로 전환할 수 없습니다.
 *
 * 정상 API 경로는 UpdateStatusRequest 검증이 422로 선차단하므로 이 예외에 도달하지 않으며,
 * 서비스 직접 호출에 대한 2차 방어선으로 동작합니다.
 */
class DeletedReportStatusChangeException extends Exception
{
    /**
     * 영구삭제 신고 상태 변경 예외 생성자
     *
     * @param  int  $code  예외 코드
     * @param  \Throwable|null  $previous  이전 예외
     */
    public function __construct(int $code = 0, ?\Throwable $previous = null)
    {
        $message = __('sirsoft-board::messages.reports.cannot_change_deleted_status');

        parent::__construct($message, $code, $previous);
    }
}
