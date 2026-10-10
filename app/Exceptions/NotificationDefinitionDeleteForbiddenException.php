<?php

namespace App\Exceptions;

use Exception;

/**
 * 삭제할 수 없는 알림 정의를 지우려 할 때의 예외.
 *
 * 운영자가 추가한 본인인증 정책별 문구만 지울 수 있다 — 코어·확장이 선언한 정의는 재시딩으로
 * 되살아나고, 일반 알림은 삭제 개념이 없다.
 */
class NotificationDefinitionDeleteForbiddenException extends Exception
{
    /**
     * 삭제 거부 예외를 생성합니다.
     */
    public function __construct()
    {
        parent::__construct(__('notification.definition_delete_forbidden'));
    }
}
