<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 「중복 실행 방지」 스케줄의 실행 잠금 저장소(캐시)가 오류를 내 실행하지 않을 때 던진다(503).
 *
 * 잠금 없이 실행하면 중복 실행 방지를 보장할 수 없으므로 시작하지 않는다(#161 F4). 원본 오류는 `Log::error` 에만 남기고 응답에는
 * 번역문만 싣는다. 거절된 요청은 실행 이력·`last_result` 를 만들지 않는다.
 */
class ScheduleRunLockUnavailableException extends HttpException
{
    /** 응답 메시지 키 */
    public const MESSAGE_KEY = 'schedule.run_lock_unavailable';

    /**
     * 실행 잠금 저장소를 쓸 수 없다는 예외를 만듭니다.
     *
     * @param  int  $scheduleId  스케줄 ID
     */
    public function __construct(public readonly int $scheduleId)
    {
        parent::__construct(503, __(self::MESSAGE_KEY));
    }

    /**
     * 응답 메시지 키를 반환합니다.
     *
     * @return string 다국어 메시지 키
     */
    public function getMessageKey(): string
    {
        return self::MESSAGE_KEY;
    }
}
