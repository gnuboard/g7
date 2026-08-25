<?php

/**
 * 그누보드7 웹 인스톨러 - Progress Emitter 추상화
 *
 * SSE/폴링 듀얼 모드 지원을 위한 진행 상황 보고 인터페이스.
 * - SseEmitter: 실시간 SSE 스트리밍 + addLog
 * - NullEmitter: addLog만 수행 (폴링 모드, state.json 기반 조회)
 */
if (! defined('BASE_PATH')) {
    define('BASE_PATH', realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3));
}

interface ProgressEmitter
{
    /**
     * 임의 이벤트 송출 (SSE 이벤트 타입과 데이터 배열).
     *
     * @param  string  $event  이벤트 타입 (connected, task_start, task_complete, log, completed, error, aborted, rollback_failed)
     * @param  array  $data  이벤트 데이터
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
if (! function_exists('installerWorkerLockLost')) {
    /**
     * 이 요청이 워커 lock 을 잃었는가 — 세 emitter 가 공유하는 판정 (11차 #D13).
     *
     * 종전에는 같은 판정이 세 사본에 흩어져 있었고, **예외로 끝나는 한 사본만** 행위
     * 테스트가 덮었다. 나머지 둘(SSE·폴링, `exit(0)` 로 끝난다)은 조기 반환 조건을
     * 뒤집어도 초록이라, lock 을 뺏긴 워커가 계속 돌아 두 요청이 같은 DB 를 조작하는
     * 상태가 검사 밖이었다 — 7차 #14 가 두 오케스트레이터의 lock-lost 규칙 비대칭을
     * 없앤 것의 반대편이다.
     *
     * @param  string|null  $workerId  이 요청이 점유한 worker_id (없으면 판정하지 않는다)
     * @param  bool  $aborting  이미 물러나는 중인가
     * @return bool 물러나야 하면 true
     */
    function installerWorkerLockLost(?string $workerId, bool $aborting): bool
    {
        if ($workerId === null || $aborting) {
            return false;
        }

        return ! refreshWorkerHeartbeat($workerId);
    }
}

class SseEmitter implements ProgressEmitter
{
    /**
     * connection 끊김 감지 후 종료 처리 락 (재진입 방지).
     */
    private bool $aborting = false;

    /**
     * 워커 lock owner ID (시나리오 B). null 이면 lock 무관 모드 (legacy).
     */
    private ?string $workerId;

    public function __construct(?string $workerId = null)
    {
        $this->workerId = $workerId;
    }

    public function emit(string $event, array $data): void
    {
        // SSE data 라인은 항상 유효 JSON 이어야 한다 (gnuboard/g7#62).
        //
        // composer install 등 외부 프로세스 로그에 invalid UTF-8 바이트가 섞이면
        // 표준 json_encode 는 false 를 반환하고, 그러면 빈 data 라인이 송출되어
        // 프론트 EventSource 리스너의 JSON.parse(e.data) 가 throw 되고 로그 이벤트가 유실된다.
        //
        // installer_json_encode 는 값을 정규화하고 substitute 를 적용하며,
        // 실패하더라도 파싱 가능한 오류 JSON 을 돌려주므로 빈 data 라인이 나갈 수 없다.
        $payload = installer_json_encode($data);

        echo "event: {$event}\n";
        echo 'data: '.$payload."\n\n";
        if (ob_get_level()) {
            @ob_flush();
        }
        flush();

        // log 이벤트는 installation.log에도 기록 (disconnect 감지 전에 우선 기록)
        if ($event === 'log' && isset($data['message'])) {
            addLog($data['message']);
        }

        // 워커 lock heartbeat 갱신 — 자기가 owner 가 아니면 즉시 종료 (다른 워커 takeover)
        $this->exitIfNotOwner();

        // flush 직후 connection 상태 검사 — disconnect 면 워커 즉시 종료.
        // 이슈 #319 부수 발견: SSE 워커가 disconnect 후 백그라운드에서 진행을
        // 계속하여 폴링 워커와 race condition 발생. flush 시 EPIPE 가 발생해야
        // connection_status() 가 NORMAL 외 값을 반환하므로 emit 마다 검사.
        $this->exitIfDisconnected();
    }

    /**
     * 자기가 워커 lock owner 가 아니면 워커 종료.
     *
     * 다른 워커가 stale takeover 한 경우 자기는 stale loser 이므로 즉시 종료하여
     * race condition 차단. workerId 가 null 이면 lock 미사용 모드 (legacy).
     */
    private function exitIfNotOwner(): void
    {
        if (! installerWorkerLockLost($this->workerId, $this->aborting)) {
            return;
        }

        $this->aborting = true;
        addLog(lang('log_worker_lock_lost_retreat'));

        // 물러나기 전에 그 사실을 한 프레임 보낸다 (13차 #E5). 아무 말 없이 끊으면 브라우저는
        // 스트림 종료를 장애로 읽고 재연결하는데, 그 재연결은 승자 요청이 lock 을 쥐고 있어
        // busy 를 받고 「중단」 화면을 그린다 — 설치는 멀쩡히 진행 중인데 화면만 실패한다.
        // stepwise 는 예외 → busy → 자동 재시도로 같은 사실을 전하므로, 그 계약을 SSE 로 옮긴
        // 것이다. 클라이언트는 이 이벤트를 받으면 재연결 대신 진행 조회로 전환하면 된다.
        echo "event: worker_superseded\n";
        echo 'data: '.installer_json_encode(
            ['message' => lang('log_worker_lock_lost_retreat')],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        )."\n\n";
        if (ob_get_level()) {
            @ob_flush();
        }
        flush();

        exit(0);
    }

    public function isConnectionAborted(): bool
    {
        return connection_aborted() === 1 || connection_status() !== CONNECTION_NORMAL;
    }

    /**
     * 클라이언트 연결이 끊겼으면 워커 프로세스를 즉시 종료한다.
     *
     * 폴링 워커가 같은 인스톨러 흐름에 진입한 직후, 백그라운드에서 살아있는
     * SSE 워커가 DB 를 추가 조작하여 race 가 발생하는 것을 차단한다.
     */
    private function exitIfDisconnected(): void
    {
        if ($this->aborting) {
            return;
        }

        if (! $this->isConnectionAborted()) {
            return;
        }

        $this->aborting = true;
        addLog(lang('log_worker_disconnected_abort'));
        exit(0);
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
    /**
     * 워커 lock owner ID (시나리오 B). null 이면 lock 무관 모드 (legacy).
     */
    private ?string $workerId;

    /**
     * lock 손실 후 종료 처리 락 (재진입 방지).
     */
    private bool $aborting = false;

    public function __construct(?string $workerId = null)
    {
        $this->workerId = $workerId;
    }

    public function emit(string $event, array $data): void
    {
        if ($event === 'log' && isset($data['message'])) {
            addLog($data['message']);
        }
        // 그 외 이벤트는 state.json 업데이트(task-runner.php 내부)로 커버됨

        // 워커 lock heartbeat 갱신 — 자기가 owner 가 아니면 즉시 종료.
        $this->exitIfNotOwner();
    }

    public function isConnectionAborted(): bool
    {
        // 폴링 모드: fastcgi_finish_request() 이후 호출되므로 connection_aborted()는 의미 없음.
        // 사용자 중단은 state.json의 installation_status === 'aborted'로 감지.
        return false;
    }

    /**
     * 자기가 워커 lock owner 가 아니면 워커 종료 (race 차단).
     */
    private function exitIfNotOwner(): void
    {
        if (! installerWorkerLockLost($this->workerId, $this->aborting)) {
            return;
        }

        $this->aborting = true;
        addLog(lang('log_worker_lock_lost_retreat'));
        exit(0);
    }
}

/**
 * 버퍼 진행 상황 송출 (stepwise 진행 모드 전용).
 *
 * 태스크 하나가 한 HTTP 요청 안에서 돌고 그 요청의 JSON 응답으로 결과가 돌아가는
 * 구조이므로, 이벤트를 스트리밍하지 않고 모아 두었다가 응답에 실어 보낸다.
 * 클라이언트(StepwiseMonitor)는 받은 이벤트를 SSE 와 같은 순서·형태로 재생한다 —
 * 진행률 UI 가 세 모드에서 같은 콜백을 쓰는 이유다.
 *
 * log 이벤트는 installation.log 에도 기록한다 (새로고침 재개 시 로그 복원 경로).
 */
class BufferEmitter implements ProgressEmitter
{
    /**
     * 이 요청 동안 모인 이벤트 목록.
     *
     * @var array<int, array{event: string, data: array<string, mixed>}>
     */
    private array $events = [];

    /**
     * 워커 lock owner ID. null 이면 lock 무관 모드.
     */
    private ?string $workerId;

    /**
     * lock 손실 후 종료 처리 락 (재진입 방지).
     */
    private bool $aborting = false;

    public function __construct(?string $workerId = null)
    {
        $this->workerId = $workerId;
    }

    public function emit(string $event, array $data): void
    {
        // heartbeat 은 접는다 (13차 #F7). 진행 값은 단조 증가라 마지막 1건이 곧 현재 상태인데,
        // 전부 모아 두면 오래 도는 태스크 하나의 응답에 수백 개가 실려 클라이언트가 같은
        // 프레임을 되풀이 재생한다. 값이 아니라 개수만 버리는 것이므로 정보 손실이 없다.
        if ($event === 'heartbeat') {
            $this->events = array_values(array_filter(
                $this->events,
                static fn (array $buffered): bool => $buffered['event'] !== 'heartbeat',
            ));
        }

        $this->events[] = ['event' => $event, 'data' => $data];

        if ($event === 'log' && isset($data['message'])) {
            addLog($data['message']);
        }

        $this->exitIfNotOwner();
    }

    public function isConnectionAborted(): bool
    {
        // 응답은 태스크가 끝난 뒤 한 번에 나간다 — 연결 상태는 진행 판정에 쓰지 않는다.
        // 사용자 중단은 state.json 의 installation_status === 'aborted' 로 감지.
        return false;
    }

    /**
     * 모인 이벤트를 돌려준다 (응답 조립용).
     *
     * @return array<int, array{event: string, data: array<string, mixed>}> 이벤트 목록
     */
    public function drain(): array
    {
        $events = $this->events;
        $this->events = [];

        return $events;
    }

    /**
     * 자기가 워커 lock owner 가 아니면 워커 종료 (race 차단).
     */
    private function exitIfNotOwner(): void
    {
        if (! installerWorkerLockLost($this->workerId, $this->aborting)) {
            return;
        }

        $this->aborting = true;
        addLog(lang('log_worker_lock_lost_retreat'));

        // exit() 로 끊으면 본문 없는 200 이 나가 브라우저가 네트워크 실패로 오분류한다 —
        // 예외로 올려 run-task.php 가 busy 로 응답하게 한다 (점유자는 다른 요청이다).
        throw new InstallerWorkerLockLostException($this->workerId);
    }
}

/**
 * 태스크 도중 워커 lock 을 다른 요청에 빼앗긴 경우 — 이 요청은 물러나고 브라우저는 재시도한다.
 */
class InstallerWorkerLockLostException extends RuntimeException
{
    public function __construct(public readonly string $workerId)
    {
        parent::__construct('Stepwise task lock lost: '.$workerId);
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
    if (! isset($GLOBALS['g7_progress_emitter']) || ! ($GLOBALS['g7_progress_emitter'] instanceof ProgressEmitter)) {
        $GLOBALS['g7_progress_emitter'] = new SseEmitter;
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
