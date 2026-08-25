/**
 * 인스톨러 진행 모니터 행위 회귀 테스트 (10차 #G9 · #M4 · #L·취소 축).
 *
 * `public/install/assets/js/installation-monitor.js` 는 빌드 파이프라인 밖의 순수 IIFE 라
 * 그동안 **소스 문자열 대조**(`InstallerMonitorContractTest`)만이 유일한 게이트였다. 그 채널은
 * 호출이 남아 있는지만 보므로 조건을 뒤집거나 임계값을 바꾸는 회귀를 잡지 못한다 — 8·9차가
 * 고친 정지 감지·취소 확인·busy 재시도가 전부 그 사각에 있었다.
 *
 * 여기서는 파일을 그대로 평가해 실제 클래스를 돌린다. 서버는 `fetch` 스텁으로 대신한다.
 *
 * 커버 축: monitor_mode stepwise·polling·sse / terminal_outcome task_done·busy·failed·completed·rejected / failure_channel state·envelope / cancellation before_dispatch·after_await
 *
 * @effects stepwise_monitor_surfaces_envelope_failure_reason, stepwise_monitor_drains_events_before_outcome, stepwise_monitor_ignores_response_after_stop, polling_monitor_ignores_response_after_stop, sse_monitor_completes_on_broken_event_body, sse_monitor_reports_stall_after_connect, sse_monitor_closes_stream_on_stop, polling_monitor_surfaces_client_error_status
 */
import { readFileSync } from 'fs';
import path from 'path';
import { beforeAll, afterEach, describe, expect, it, vi } from 'vitest';

const MONITOR_SOURCE = path.resolve(
  __dirname,
  '../../../../public/install/assets/js/installation-monitor.js',
);

type Monitors = {
  StepwiseMonitor: any;
  PollingMonitor: any;
  SseMonitor: any;
};

/**
 * EventSource 스텁 — 세 진행 모드 중 기본값(SSE)만 vitest 모집단 밖이었다 (11차 #B19).
 * 그 사각에서 8차가 고친 「연결 후 정지」와 「본문 깨진 completed」가 소스 대조로만
 * 지켜지고 있었다 — 그 배선을 지워도 전 스위트가 초록이었다.
 */
class FakeEventSource {
  static last: FakeEventSource | null = null;

  url: string;
  closed = false;
  onerror: ((e: any) => void) | null = null;
  private listeners: Record<string, Array<(e: any) => void>> = {};

  constructor(url: string) {
    this.url = url;
    FakeEventSource.last = this;
  }

  addEventListener(name: string, fn: (e: any) => void) {
    this.listeners[name] = this.listeners[name] ?? [];
    this.listeners[name].push(fn);
  }

  close() {
    this.closed = true;
  }

  /** 서버가 이벤트를 흘려보낸 것처럼 만든다. */
  emit(name: string, data?: string) {
    (this.listeners[name] ?? []).forEach((fn) => fn({ data }));
  }
}

let monitors: Monitors;

beforeAll(() => {
  // IIFE 는 `window` 를 인자로 받아 `window.G7InstallationMonitor` 에 노출한다.
  // 번들러를 거치지 않는 파일이므로 소스를 그대로 평가하는 것이 유일한 로드 방법이다.
  const source = readFileSync(MONITOR_SOURCE, 'utf8');
  // eslint-disable-next-line no-eval
  (0, eval)(source);
  monitors = (window as any).G7InstallationMonitor;
  expect(monitors, '모니터 전역이 노출되지 않았다 — 파일 구조가 바뀌었다').toBeTruthy();
});

afterEach(() => {
  vi.restoreAllMocks();
  vi.useRealTimers();
});

/** fetch 를 순서대로 정해진 응답으로 대신한다. */
function stubFetch(responses: Array<{ status?: number; body: unknown }>) {
  let call = 0;
  const spy = vi.fn(async () => {
    const next = responses[Math.min(call, responses.length - 1)];
    call += 1;

    const status = next.status ?? 200;

    return {
      status,
      ok: status >= 200 && status < 300,
      json: async () => next.body,
    } as unknown as Response;
  });
  vi.stubGlobal('fetch', spy);

  return spy;
}

/** 콜백을 기록하는 수집기. */
function collector() {
  const calls: Record<string, any[][]> = {};
  const handler = (name: string) =>
    (...args: any[]) => {
      calls[name] = calls[name] ?? [];
      calls[name].push(args);
    };

  return {
    calls,
    callbacks: {
      onConnected: handler('onConnected'),
      onTaskStart: handler('onTaskStart'),
      onTaskComplete: handler('onTaskComplete'),
      onLog: handler('onLog'),
      onComplete: handler('onComplete'),
      onError: handler('onError'),
      onAbort: handler('onAbort'),
      onState: handler('onState'),
      onBusy: handler('onBusy'),
      onRetry: handler('onRetry'),
      onRollbackFailed: handler('onRollbackFailed'),
    },
  };
}

describe('StepwiseMonitor', () => {
  it('잡히지 않은 예외로 끝난 응답의 사유를 화면까지 나른다 (#M4)', async () => {
    // 이 경로는 상태 파일에 기록될 겨를이 없어 사유가 **응답 최상위**로만 온다.
    // `state` 쪽만 읽으면 서버가 정확한 원인을 보냈는데 화면에는 일반 문구만 뜬다.
    stubFetch([
      {
        status: 500,
        body: {
          success: false,
          outcome: 'failed',
          message_key: 'error_unexpected_exception',
          error_detail: 'ParseError: syntax error in runtime.php',
          state: { status: 'running', installation_mode: 'stepwise' },
        },
      },
    ]);

    const { calls, callbacks } = collector();
    const monitor = new monitors.StepwiseMonitor(callbacks, { runTaskUrl: '/run-task', token: 't', busyRetryMs: 1 });

    monitor.start();
    await vi.waitFor(() => expect(calls.onError).toBeDefined());

    const [payload] = calls.onError[0];
    expect(payload.message_key).toBe('error_unexpected_exception');
    expect(payload.error).toBe('ParseError: syntax error in runtime.php');
  });

  it('상태 파일에 기록된 태스크 실패는 그 사유를 그대로 쓴다 (봉투 폴백이 상태를 덮지 않는다)', async () => {
    stubFetch([
      {
        status: 200,
        body: {
          success: true,
          outcome: 'failed',
          message: '봉투 문구',
          state: {
            status: 'failed',
            error_message_key: 'error_db_migration_failed',
            error_detail: 'SQLSTATE[42S01]',
            failed_task: 'db_migrate',
            failed_task_skippable: false,
          },
        },
      },
    ]);

    const { calls, callbacks } = collector();
    const monitor = new monitors.StepwiseMonitor(callbacks, { runTaskUrl: '/run-task', token: 't', busyRetryMs: 1 });

    monitor.start();
    await vi.waitFor(() => expect(calls.onError).toBeDefined());

    const [payload] = calls.onError[0];
    expect(payload.message_key).toBe('error_db_migration_failed');
    expect(payload.task).toBe('db_migrate');
  });

  it('완료 전까지 요청을 반복하고 모아 보낸 이벤트를 재생한다', async () => {
    stubFetch([
      {
        body: {
          outcome: 'task_done',
          events: [
            { event: 'task_start', data: { task: 'db_migrate', name: '데이터베이스 생성' } },
            { event: 'log', data: { message: '테이블을 만들었습니다.' } },
            { event: 'task_complete', data: { task: 'db_migrate' } },
          ],
          state: { status: 'running' },
        },
      },
      { body: { outcome: 'completed', events: [], state: { status: 'completed' } } },
    ]);

    const { calls, callbacks } = collector();
    const monitor = new monitors.StepwiseMonitor(callbacks, { runTaskUrl: '/run-task', token: 't', busyRetryMs: 1 });

    monitor.start();
    await vi.waitFor(() => expect(calls.onComplete).toBeDefined());

    expect(calls.onTaskStart[0][0]).toBe('db_migrate');
    expect(calls.onLog[0][0]).toBe('테이블을 만들었습니다.');
    expect(calls.onTaskComplete[0][0]).toBe('db_migrate');
  });

  it('중단된 뒤 도착한 응답은 버린다 — 중단 화면 위에 진행이 덧그려지지 않는다', async () => {
    let release: (value: unknown) => void = () => {};
    const pending = new Promise((resolve) => {
      release = resolve;
    });

    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        await pending;

        return {
          status: 200,
          ok: true,
          json: async () => ({ outcome: 'completed', events: [], state: { status: 'completed' } }),
        } as unknown as Response;
      }),
    );

    const { calls, callbacks } = collector();
    const monitor = new monitors.StepwiseMonitor(callbacks, { runTaskUrl: '/run-task', token: 't', busyRetryMs: 1 });

    monitor.start();
    monitor.stop();
    release(null);
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(calls.onComplete).toBeUndefined();
  });
});

describe('PollingMonitor', () => {
  it('4xx 는 즉시 사유로 끝낸다 — 재시도로 낫지 않는다 (11차 #B21)', async () => {
    // 종전에는 `if (!res.ok) return;` 하나로 401·403·409·410 이 5xx·네트워크와 함께
    // 삼켜져, 입구가 닫힌(410) 뒤에도 화면이 90초 동안 마지막 진행률에 멈춰 있다가
    // 「응답이 없습니다」라는 **틀린 사유**로 끝났다. 단계별 실행은 즉시 사유를 낸다.
    stubFetch([{ status: 410, body: { message_key: 'error_installer_completed' } }]);

    const c = collector();
    const monitor = new monitors.PollingMonitor(c.callbacks, { stateUrl: '/install/api/state-management.php?action=get' });

    await monitor._poll();

    expect(c.calls.onError, '4xx 를 삼키면 틀린 사유로 끝난다').toBeTruthy();
    expect(c.calls.onError[0][0].message_key).toBe('error_installer_completed');
  });

  it('5xx 는 다음 tick 에서 재시도한다 (일시적 오류를 종료로 만들지 않는다)', async () => {
    stubFetch([{ status: 503, body: {} }]);

    const c = collector();
    const monitor = new monitors.PollingMonitor(c.callbacks, { stateUrl: '/install/api/state-management.php?action=get' });

    await monitor._poll();

    expect(c.calls.onError, '5xx 까지 즉시 끝내면 일시적 장애가 설치 실패가 된다').toBeUndefined();
  });

  it('중단된 뒤 도착한 응답은 버린다 (#L — StepwiseMonitor 와 같은 규칙)', async () => {
    let release: (value: unknown) => void = () => {};
    const pending = new Promise((resolve) => {
      release = resolve;
    });

    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        await pending;

        return {
          status: 200,
          ok: true,
          json: async () => ({
            success: true,
            status: 'completed',
            completed_tasks: [],
            logs: [],
            current_task: null,
          }),
        } as unknown as Response;
      }),
    );

    const { calls, callbacks } = collector();
    const monitor = new monitors.PollingMonitor(callbacks, { stateUrl: '/state', intervalMs: 5 });

    monitor.start();
    monitor.stop();
    release(null);
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(calls.onComplete, '중단 뒤 남은 폴이 완료 콜백을 흘리면 중단 화면 위에 완료가 덧그려진다').toBeUndefined();
  });
});

describe('SseMonitor', () => {
  function startSse(overrides: Record<string, any> = {}) {
    FakeEventSource.last = null;
    vi.stubGlobal('EventSource', FakeEventSource as any);

    const c = collector();
    const monitor = new monitors.SseMonitor(c.callbacks, {
      workerUrl: '/install/api/install-worker.php',
      ...overrides,
    });
    monitor.start();

    const source = FakeEventSource.last;
    expect(source, 'EventSource 가 생성되지 않았다').toBeTruthy();

    return { monitor, source: source as FakeEventSource, calls: c.calls };
  }

  it('completed 본문이 깨져도 완료를 알린다 (8차 회귀 축)', () => {
    const { source, calls } = startSse();

    source.emit('connected', '{}');
    // 프록시가 스트림을 자른 상황 — 본문이 JSON 이 아니다.
    source.emit('completed', '{"message": bro');

    expect(calls.onComplete, '본문이 깨졌다고 완료를 삼키면 성공한 설치가 잠시 뒤 「응답 없음」으로 뒤집힌다').toBeTruthy();
    expect(source.closed, '완료했으면 스트림을 닫는다').toBe(true);
  });

  it('연결 뒤 침묵하면 정지로 보고한다 (8차 회귀 축)', () => {
    vi.useFakeTimers();
    const { calls } = startSse({ stuckThresholdMs: 1000, connectionTimeoutMs: 500 });

    FakeEventSource.last!.emit('connected', '{}');
    // 연결됐으므로 네이티브 onerror 경로(연결 전 실패)는 발화하지 않는다 —
    // 이 축이 없으면 워커가 죽어도 화면이 마지막 진행률에서 영구 정지한다.
    vi.advanceTimersByTime(1500);

    expect(calls.onError, '연결 후 정지를 아무도 보고하지 않으면 화면이 멈춘 채로 남는다').toBeTruthy();
  });

  it('stop() 은 스트림을 닫는다', () => {
    const { monitor, source } = startSse();

    source.emit('connected', '{}');
    monitor.stop();

    expect(source.closed).toBe(true);
  });

  it('건너뛴 단계 표식을 떨어뜨리지 않는다 (best-effort 실패가 초록으로 보이면 안 된다)', () => {
    const { source, calls } = startSse();

    source.emit('connected', '{}');
    source.emit('task_complete', JSON.stringify({ task: 'config_cache', message: '경고', skipped: true }));

    expect(calls.onTaskComplete[0][3]).toBe(true);
  });
});
