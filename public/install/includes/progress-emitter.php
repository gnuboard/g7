<?php

/**
 * 그누보드7 웹 인스톨러 - Progress Emitter 추상화
 *
 * SSE/폴링 듀얼 모드 지원을 위한 진행 상황 보고 인터페이스.
 * - SseEmitter: 실시간 SSE 스트리밍 + addLog
 * - NullEmitter: addLog만 수행 (폴링 모드, state.json 기반 조회)
 *
 * @package G7\Installer
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3));
}

interface ProgressEmitter
{
    /**
     * 임의 이벤트 송출 (SSE 이벤트 타입과 데이터 배열).
     *
     * @param string $event 이벤트 타입 (connected, task_start, task_complete, log, completed, error, aborted, rollback_failed)
     * @param array $data 이벤트 데이터
     */
    public function emit(string $event, array $data): void;

    /**
     * 브라우저 연결 끊김 여부.
     *
     * SSE 모드: connection_aborted() 기반.
     * 폴링 모드: 항상 false (응답은 이미 종료됨, 중단 감지는 state.json 기반).
     */
    public function isConnectionAborted(): bool;
}

/**
 * SSE 기반 진행 상황 송출.
 *
 * 기존 sendSSEEvent() 동작을 그대로 재현합니다.
 */
class SseEmitter implements ProgressEmitter
{
    public function emit(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
        if (ob_get_level()) {
            @ob_flush();
        }
        flush();

        // log 이벤트는 installation.log에도 기록
        if ($event === 'log' && isset($data['message'])) {
            addLog($data['message']);
        }
    }

    public function isConnectionAborted(): bool
    {
        return (bool) connection_aborted();
    }
}

/**
 * No-op 진행 상황 송출 (폴링 모드 전용).
 *
 * SSE 이벤트는 브라우저로 보내지 않고, log 메시지만 installation.log에 기록합니다.
 * 프론트엔드는 state-management.php?action=get 폴링으로 진행 상황을 조회합니다.
 */
class NullEmitter implements ProgressEmitter
{
    public function emit(string $event, array $data): void
    {
        if ($event === 'log' && isset($data['message'])) {
            addLog($data['message']);
        }
        // 그 외 이벤트는 state.json 업데이트(task-runner.php 내부)로 커버됨
    }

    public function isConnectionAborted(): bool
    {
        // 폴링 모드: fastcgi_finish_request() 이후 호출되므로 connection_aborted()는 의미 없음.
        // 사용자 중단은 state.json의 installation_status === 'aborted'로 감지.
        return false;
    }
}

/**
 * 현재 등록된 ProgressEmitter 조회.
 *
 * task-runner.php의 sendSSEEvent() 호환 래퍼가 이 함수를 통해 delegate 합니다.
 * 등록되지 않은 경우 안전한 기본값으로 SseEmitter를 반환합니다.
 */
function getProgressEmitter(): ProgressEmitter
{
    if (!isset($GLOBALS['g7_progress_emitter']) || !($GLOBALS['g7_progress_emitter'] instanceof ProgressEmitter)) {
        $GLOBALS['g7_progress_emitter'] = new SseEmitter();
    }
    return $GLOBALS['g7_progress_emitter'];
}

/**
 * ProgressEmitter 등록.
 *
 * 모드별 진입점(install-worker.php / install-process.php 인라인 실행)에서 호출합니다.
 */
function setProgressEmitter(ProgressEmitter $emitter): void
{
    $GLOBALS['g7_progress_emitter'] = $emitter;
}
