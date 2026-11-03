<?php

namespace App\Scheduling\Exceptions;

use RuntimeException;

/**
 * 등록된 스케줄 이벤트에서 Artisan 커맨드를 되뽑지 못했을 때 던집니다.
 *
 * 되뽑기는 프레임워크 내부 표현(`Event::$command` 의 조립 형태)에 의존한다. Laravel
 * 업그레이드로 그 표현이 바뀌면 **조용히 건너뛰지 않고** 이 예외로 드러낸다 — 건너뛰면
 * 예약 작업이 오류 없이 영영 실행되지 않는다(A-5 ㉢). 계약 테스트가 등록된 전 이벤트에
 * 대해 되뽑기 성공을 단언하므로, 표현이 바뀌면 그 테스트가 먼저 red 가 된다.
 *
 * @since 7.1.0
 */
class ScheduleCommandExtractionException extends RuntimeException
{
    /**
     * @param  string  $rawCommand  되뽑기에 실패한 원본 커맨드 문자열
     * @param  string  $reason  번역된 실패 사유 ([지금 실행]·웹 크론 응답의 reason 으로 나간다)
     */
    public function __construct(
        public readonly string $rawCommand,
        string $reason,
    ) {
        parent::__construct($reason);
    }
}
