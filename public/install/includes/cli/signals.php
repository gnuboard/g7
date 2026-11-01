<?php

/**
 * 그누보드7 터미널 설치 - 중단 신호
 *
 * Ctrl+C·SIGTERM(도커 stop) 을 받으면 중단 플래그만 세운다. 설치 실행 코어는 작업 사이마다 송출기의
 * `isConnectionAborted()` 로 이 플래그를 보고, 웹의 연결 끊김과 같은 경로로 현재 작업을 되돌린 뒤 멈춘다.
 *
 * Ctrl+C 는 같은 프로세스 그룹의 하위 artisan 에도 전달된다 — 하위 명령이 중간에 끝나면 그 작업은 실패로
 * 기록되고 같은 롤백 경로로 수습된다. 상태는 「중단」으로 남아 `--resume` 으로 이어서 설치할 수 있다.
 */

if (! function_exists('g7_cli_install_signal_handlers')) {
    /**
     * 신호 처리기를 건다 (리눅스 pcntl, Windows 콘솔 제어 처리기).
     *
     * @return bool 처리기 등록 여부
     */
    function g7_cli_install_signal_handlers(): bool
    {
        $GLOBALS['g7_cli_abort'] = false;
        $flag = static function (): void {
            $GLOBALS['g7_cli_abort'] = true;
        };

        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, $flag);
            pcntl_signal(SIGTERM, $flag);

            return true;
        }

        if (function_exists('sapi_windows_set_ctrl_handler')) {
            return @sapi_windows_set_ctrl_handler(static function (int $event) use ($flag): void {
                $flag();
            });
        }

        return false;
    }
}

if (! function_exists('g7_cli_abort_requested')) {
    /**
     * 중단 신호를 받았는지.
     *
     * @return bool 받았으면 true
     */
    function g7_cli_abort_requested(): bool
    {
        return ! empty($GLOBALS['g7_cli_abort']);
    }
}
