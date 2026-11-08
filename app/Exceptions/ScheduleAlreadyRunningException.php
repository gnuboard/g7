<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * 「중복 실행 방지」를 켠 스케줄이 이미 실행 중이라 「지금 실행」을 거절할 때 던진다(409).
 *
 * 7.1.4 까지는 `without_overlapping` 값을 저장만 하고 수동 실행에 적용하지 않아, 버튼을 두 번 누르면 같은 작업이 두 번 돌았다
 * (#161 F4). 거절된 요청은 실행 이력·`last_result` 를 만들지 않는다.
 */
class ScheduleAlreadyRunningException extends HttpException
{
    /** 응답 메시지 키 */
    public const MESSAGE_KEY = 'schedule.already_running';

    /**
     * 스케줄이 이미 실행 중이라는 예외를 만듭니다.
     *
     * @param  int  $scheduleId  스케줄 ID
     */
    public function __construct(public readonly int $scheduleId)
    {
        parent::__construct(409, __(self::MESSAGE_KEY));
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
