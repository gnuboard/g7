/**
 * 인스톨러 SSE 모니터 — 연결 수립 뒤 스트림이 끊겼을 때의 결말 판정 회귀 테스트.
 *
 * 회귀: SseMonitor 는 연결 수립(connected) 뒤의 네이티브 onerror 를 무시했다.
 * 설치 워커가 실행 시간 제한 등으로 죽어 스트림이 끊기면 EventSource 는 자동 재연결을 시도하는데,
 * 재연결 요청이 실패(서버 상태 락 교착으로 응답 없음·5xx)하면 아무 콜백도 불리지 않아
 * 화면이 「설치 진행 중」에 영원히 멈췄다. 폴링 모드에는 있던 멈춤 감지가 SSE 모드에는 없었다.
 *
 * 회귀 2: 위 판정만 두면 워커가 오류 없이 스트림을 닫은 경우(종료 이벤트 없이 응답이 끝나고 워커 잠금은
 * 풀림 — 연결이 끊긴 것을 다음 출력에서 감지한 PHP 가 스크립트를 조용히 끝낸다)에도 다시 연결하지 않아,
 * 설치가 남은 작업을 두고 90초 뒤 「설치가 응답하지 않습니다」 로 끝났다.
 *
 * 고정하는 계약:
 *  1. 연결 수립 뒤 끊기면 EventSource 를 닫고(브라우저 자동 재연결에 맡기지 않음) 서버 상태로 결말을 판정한다.
 *  2. 상태가 failed / aborted / completed 면 해당 콜백을 부른다 (실패 화면에 재시도 버튼이 있다).
 *  3. 상태가 running 이고 워커 잠금이 비어 있으면(worker_active=false) 새 워커로 다시 연결해 남은 작업을 이어 받는다.
 *     잠금이 남아 있으면 앞 워커가 끝날 때까지 기다린다(같은 작업을 두 워커가 동시에 돌리지 않는다).
 *  4. 다시 연결이 진척 없이 거듭 끊기면 횟수 제한 뒤 더 연결하지 않고, 아래 5 로 끝난다.
 *  5. 상태가 running 인 채 last_updated 가 임계 시간 동안 변하지 않으면 error_installation_stuck 으로 실패를 알린다.
 *  6. 워커가 보낸 error 이벤트는 실패로 끝내고 다시 연결하지 않는다.
 *  7. 연결 수립 전 오류는 종전대로 onConnectionTimeout.
 *
 * 시나리오 SSoT: tests/scenarios/installer-worker-termination.yaml
 */
import { readFileSync } from 'fs';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

type Listener = (e: { data?: string }) => void;

class FakeEventSource {
    static instances: FakeEventSource[] = [];

    url: string;

    closed = false;

    listeners: Record<string, Listener[]> = {};

    onerror: ((e: unknown) => void) | null = null;

    constructor(url: string) {
        this.url = url;
        FakeEventSource.instances.push(this);
    }

    addEventListener(name: string, cb: Listener): void {
        (this.listeners[name] ||= []).push(cb);
    }

    emit(name: string, data?: unknown): void {
        for (const cb of this.listeners[name] || []) {
            cb({ data: data === undefined ? undefined : JSON.stringify(data) });
        }
    }

    close(): void {
        this.closed = true;
    }
}

interface MonitorNamespace {
    SseMonitor: new (callbacks: Record<string, (...args: unknown[]) => void>, options: Record<string, unknown>) => {
        start(): void;
        stop(): void;
    };
}

function loadMonitor(): MonitorNamespace {
    const file = path.resolve(__dirname, '../../../../public/install/assets/js/installation-monitor.js');
    const source = readFileSync(file, 'utf8');
    const fakeWindow: Record<string, unknown> = {};
    // 스크립트는 (function (global) { ... })(window) 형태 — window 를 대체 객체로 넘긴다.
    new Function('window', 'EventSource', 'fetch', source)(fakeWindow, FakeEventSource, (...args: unknown[]) =>
        (globalThis.fetch as (...a: unknown[]) => unknown)(...args),
    );

    return fakeWindow.G7InstallationMonitor as MonitorNamespace;
}

function jsonResponse(body: unknown) {
    return Promise.resolve({ ok: true, text: () => Promise.resolve(JSON.stringify(body)) });
}

describe('SseMonitor 연결 끊김 뒤 결말 판정', () => {
    let callbacks: Record<string, ReturnType<typeof vi.fn>>;
    let fetchMock: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        vi.useFakeTimers();
        FakeEventSource.instances = [];
        callbacks = {
            onConnected: vi.fn(),
            onTaskStart: vi.fn(),
            onTaskComplete: vi.fn(),
            onLog: vi.fn(),
            onError: vi.fn(),
            onAbort: vi.fn(),
            onComplete: vi.fn(),
            onConnectionTimeout: vi.fn(),
        };
        fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.unstubAllGlobals();
    });

    function startConnected() {
        const NS = loadMonitor();
        const monitor = new NS.SseMonitor(callbacks, {
            workerUrl: '/install/api/install-worker.php',
            stateUrl: '/install/api/state-management.php?action=get',
            connectionTimeoutMs: 5000,
            stuckThresholdMs: 30000,
            watchIntervalMs: 2000,
        });
        monitor.start();
        const es = FakeEventSource.instances[0];
        es.emit('connected', { message: 'ok' });

        return { monitor, es };
    }

    // @scenario situation=after_connected_state_failed
    // @effects monitor_closes_event_source_and_reads_state_on_disconnect, monitor_reports_worker_failure_from_state
    it('워커가 죽어 상태가 failed 면 EventSource 를 닫고 onError 로 실패를 알린다', async () => {
        fetchMock.mockImplementation(() =>
            jsonResponse({
                status: 'failed',
                failed_task: 'plugin_install:sirsoft-tosspayments',
                error_message_key: 'error_worker_terminated',
                error_detail: 'Maximum execution time of 600 seconds exceeded',
                last_updated: '2026-10-28T17:46:08Z',
            }),
        );

        const { es } = startConnected();
        es.onerror?.({});
        await vi.advanceTimersByTimeAsync(0);

        expect(es.closed).toBe(true);
        expect(fetchMock).toHaveBeenCalled();
        expect(String(fetchMock.mock.calls[0][0])).toContain('state-management.php?action=get');
        expect(callbacks.onError).toHaveBeenCalledTimes(1);
        expect(callbacks.onError.mock.calls[0][0]).toMatchObject({
            message_key: 'error_worker_terminated',
            error: 'Maximum execution time of 600 seconds exceeded',
            task: 'plugin_install',
            target: 'sirsoft-tosspayments',
        });
        expect(callbacks.onConnectionTimeout).not.toHaveBeenCalled();
    });

    // @scenario situation=after_connected_state_running_stalled
    // @effects monitor_reports_stuck_when_state_stalls, monitor_stops_polling_after_verdict
    it('상태가 running 인 채 갱신이 멈추면 임계 시간 뒤 error_installation_stuck 으로 알린다', async () => {
        fetchMock.mockImplementation(() =>
            jsonResponse({
                status: 'running',
                worker_active: true,
                current_task: 'plugin_install:sirsoft-tosspayments',
                last_updated: '2026-10-28T17:46:07Z',
            }),
        );

        const { es } = startConnected();
        es.onerror?.({});
        await vi.advanceTimersByTimeAsync(20000);
        expect(callbacks.onError).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(12000);
        expect(callbacks.onError).toHaveBeenCalledTimes(1);
        expect(callbacks.onError.mock.calls[0][0]).toMatchObject({
            message_key: 'error_installation_stuck',
            stuck: true,
        });

        // 판정 뒤에는 더 묻지 않는다
        const calls = fetchMock.mock.calls.length;
        await vi.advanceTimersByTimeAsync(10000);
        expect(fetchMock.mock.calls.length).toBe(calls);
    });

    // @scenario situation=after_connected_state_completed
    // @effects monitor_does_not_report_stuck_while_state_progresses, monitor_reports_completion_from_state
    it('상태 갱신이 이어지는 동안은 멈춤으로 보지 않고, 이후 completed 면 onComplete', async () => {
        let tick = 0;
        fetchMock.mockImplementation(() => {
            tick += 1;
            if (tick < 20) {
                return jsonResponse({ status: 'running', worker_active: true, last_updated: `t-${tick}` });
            }

            return jsonResponse({ status: 'completed', last_updated: `t-${tick}` });
        });

        const { es } = startConnected();
        es.onerror?.({});
        await vi.advanceTimersByTimeAsync(36000);
        expect(callbacks.onError).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(6000);
        expect(callbacks.onComplete).toHaveBeenCalledTimes(1);
        expect(callbacks.onError).not.toHaveBeenCalled();
    });

    // @scenario situation=after_connected_state_aborted
    // @effects monitor_reports_abort_from_state
    it('상태가 aborted 면 onAbort', async () => {
        fetchMock.mockImplementation(() => jsonResponse({ status: 'aborted', last_updated: 'x' }));

        const { es } = startConnected();
        es.onerror?.({});
        await vi.advanceTimersByTimeAsync(0);

        expect(callbacks.onAbort).toHaveBeenCalledTimes(1);
        expect(callbacks.onError).not.toHaveBeenCalled();
    });

    // @scenario situation=after_connected_state_unreachable
    // @effects monitor_reports_stuck_when_state_unreachable
    it('상태 조회 자체가 계속 실패해도 임계 시간 뒤에는 실패를 알린다', async () => {
        fetchMock.mockImplementation(() => Promise.reject(new Error('network')));

        const { es } = startConnected();
        es.onerror?.({});
        await vi.advanceTimersByTimeAsync(32000);

        expect(callbacks.onError).toHaveBeenCalledTimes(1);
        expect(callbacks.onError.mock.calls[0][0]).toMatchObject({ message_key: 'error_installation_stuck' });
    });

    // @scenario situation=after_connected_stream_closed_worker_released
    // @effects monitor_reconnects_new_worker_when_lock_released, monitor_resumes_event_feed_and_stops_state_polling_after_reconnect
    it('워커가 종료 이벤트 없이 스트림을 닫고 잠금을 풀었으면 새 워커로 다시 연결해 남은 작업을 이어 받는다', async () => {
        fetchMock.mockImplementation(() =>
            jsonResponse({ status: 'running', worker_active: false, current_task: 'module_activate', last_updated: 't-1' }),
        );

        const { es } = startConnected();
        es.onerror?.({});
        await vi.advanceTimersByTimeAsync(0);

        expect(es.closed).toBe(true);
        expect(FakeEventSource.instances).toHaveLength(2);
        const es2 = FakeEventSource.instances[1];
        expect(es2.url).toBe('/install/api/install-worker.php');

        es2.emit('connected', { message: 'ok' });
        es2.emit('task_start', { task: 'module_activate', target: 'sirsoft-board', name: '게시판 모듈 활성화' });
        es2.emit('task_complete', { task: 'module_activate', target: 'sirsoft-board', message: 'done' });
        expect(callbacks.onTaskStart).toHaveBeenCalledWith('module_activate', '게시판 모듈 활성화', 'sirsoft-board');
        expect(callbacks.onTaskComplete).toHaveBeenCalledWith('module_activate', 'done', 'sirsoft-board');

        // 이어 받는 동안에는 상태를 묻지 않고, 멈춤으로 판정하지도 않는다
        const calls = fetchMock.mock.calls.length;
        await vi.advanceTimersByTimeAsync(60000);
        expect(fetchMock.mock.calls.length).toBe(calls);
        expect(callbacks.onError).not.toHaveBeenCalled();

        es2.emit('completed', { message: 'ok', redirect: '/install/' });
        expect(callbacks.onComplete).toHaveBeenCalledTimes(1);
        expect(callbacks.onError).not.toHaveBeenCalled();
        expect(callbacks.onConnectionTimeout).not.toHaveBeenCalled();
        expect(FakeEventSource.instances).toHaveLength(2);
    });

    // @scenario situation=after_connected_stream_closed_worker_still_running
    // @effects monitor_waits_while_previous_worker_holds_lock
    it('앞 워커가 아직 잠금을 쥐고 있으면 풀릴 때까지 기다렸다가 다시 연결한다', async () => {
        let tick = 0;
        fetchMock.mockImplementation(() => {
            tick += 1;

            return jsonResponse({ status: 'running', worker_active: tick < 4, last_updated: 't-1' });
        });

        const { es } = startConnected();
        es.onerror?.({});
        await vi.advanceTimersByTimeAsync(4000);
        expect(FakeEventSource.instances).toHaveLength(1);

        await vi.advanceTimersByTimeAsync(2000);
        expect(FakeEventSource.instances).toHaveLength(2);
        expect(callbacks.onError).not.toHaveBeenCalled();
    });

    // @scenario situation=reconnect_rejected_busy
    // @effects monitor_treats_busy_rejection_on_reconnect_as_wait
    it('다시 연결한 요청이 「다른 워커 진행 중」 으로 거절되면 중단 화면 없이 기다렸다 다시 연결한다', async () => {
        fetchMock.mockImplementation(() => jsonResponse({ status: 'running', worker_active: false, last_updated: 't-1' }));

        const { es } = startConnected();
        es.onerror?.({});
        await vi.advanceTimersByTimeAsync(0);
        const es2 = FakeEventSource.instances[1];
        es2.emit('aborted', { reason: 'busy', message: 'busy' });
        es2.onerror?.({});

        expect(callbacks.onAbort).not.toHaveBeenCalled();
        expect(es2.closed).toBe(true);

        await vi.advanceTimersByTimeAsync(2000);
        expect(FakeEventSource.instances).toHaveLength(3);
        expect(callbacks.onAbort).not.toHaveBeenCalled();
        expect(callbacks.onError).not.toHaveBeenCalled();
    });

    // @scenario situation=reconnect_failing_repeatedly
    // @effects monitor_caps_reconnects_without_progress_then_reports_stuck
    it('다시 연결이 진척 없이 거듭 끊기면 횟수 제한 뒤 멈추고 임계 시간 뒤 실패를 알린다', async () => {
        fetchMock.mockImplementation(() => jsonResponse({ status: 'running', worker_active: false, last_updated: 't-1' }));

        const { es } = startConnected();
        es.onerror?.({});
        for (let i = 0; i < 10; i += 1) {
            await vi.advanceTimersByTimeAsync(2000);
            const last = FakeEventSource.instances[FakeEventSource.instances.length - 1];
            if (!last.closed) {
                // 연결 수립 전에 끊긴다 — 처음 연결과 달리 onConnectionTimeout 으로 끝내지 않는다
                last.onerror?.({});
            }
        }

        expect(FakeEventSource.instances).toHaveLength(1 + 3);
        expect(callbacks.onConnectionTimeout).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(12000);
        expect(callbacks.onError).toHaveBeenCalledTimes(1);
        expect(callbacks.onError.mock.calls[0][0]).toMatchObject({ message_key: 'error_installation_stuck' });
    });

    // @scenario situation=worker_error_event_then_close
    // @effects monitor_keeps_failure_on_error_event_without_reconnect
    it('다시 연결한 워커가 error 이벤트를 보낸 뒤 스트림을 닫으면 실패로 끝내고 더 연결하지 않는다', async () => {
        fetchMock.mockImplementation(() => jsonResponse({ status: 'running', worker_active: false, last_updated: 't-1' }));

        const { es } = startConnected();
        es.onerror?.({});
        await vi.advanceTimersByTimeAsync(0);
        const es2 = FakeEventSource.instances[1];
        es2.emit('connected', { message: 'ok' });
        es2.emit('error', {
            message_key: 'error_worker_terminated',
            error: 'Maximum execution time of 600 seconds exceeded',
            task: 'plugin_activate',
            target: 'sirsoft-message_bizppurio',
        });
        es2.onerror?.({});
        const calls = fetchMock.mock.calls.length;
        await vi.advanceTimersByTimeAsync(10000);

        expect(callbacks.onError).toHaveBeenCalledTimes(1);
        expect(callbacks.onError.mock.calls[0][0]).toMatchObject({
            message_key: 'error_worker_terminated',
            task: 'plugin_activate',
            target: 'sirsoft-message_bizppurio',
        });
        expect(FakeEventSource.instances).toHaveLength(2);
        expect(fetchMock.mock.calls.length).toBe(calls);
    });

    // @scenario situation=error_before_connected
    // @effects error_before_connected_keeps_connection_timeout
    it('연결 수립 전 오류는 종전대로 onConnectionTimeout', async () => {
        const NS = loadMonitor();
        const monitor = new NS.SseMonitor(callbacks, {
            workerUrl: '/install/api/install-worker.php',
            stateUrl: '/install/api/state-management.php?action=get',
        });
        monitor.start();
        FakeEventSource.instances[0].onerror?.({});
        await vi.advanceTimersByTimeAsync(0);

        expect(callbacks.onConnectionTimeout).toHaveBeenCalledTimes(1);
        expect(fetchMock).not.toHaveBeenCalled();
    });
});
