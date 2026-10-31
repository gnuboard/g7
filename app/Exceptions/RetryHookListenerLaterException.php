<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 큐로 도는 훅 리스너가 「지금은 끝낼 수 없으니 잠시 뒤 다시 실행해 달라」고 알리는 예외 (#139)
 *
 * 다른 사본이 같은 일을 처리 중일 때(예: 알림 수신자의 발송 리스가 아직 살아 있을 때) 리스너가 던진다.
 * `DispatchHookListenerJob` 이 받아 큐 잡이면 `release($delaySeconds)` 로 되돌리고, 큐 드라이버가 sync 면
 * (재실행할 큐가 없으므로) 경고만 남긴다. 실패로 기록되지 않는다 — 되돌린 횟수는 잡의 시도 횟수(tries)를 쓴다.
 */
final class RetryHookListenerLaterException extends RuntimeException
{
    /**
     * @param  int  $delaySeconds  다시 실행하기까지 기다릴 시간(초)
     * @param  string  $message  사유 (로그용)
     */
    public function __construct(
        public readonly int $delaySeconds,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : "훅 리스너를 {$delaySeconds}초 뒤 다시 실행합니다.");
    }
}
