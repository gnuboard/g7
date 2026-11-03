/**
 * 모니터가 살아 있는 설치 위에 실패·중단 화면을 그리지 않는다 (19차 J1·J2·J5).
 *
 * 세 결함은 성질이 같다 — **「지금 무슨 일이 일어났는가」를 잘못 읽어** 화면과 서버가
 * 어긋난다. 오류도 로그도 남지 않고, 어긋난 화면 자체가 유일한 증상이다.
 *
 *  - **J1**: SSE 는 연결 **후** 끊기면 EventSource 가 스스로 재연결한다. 그 재연결은 살아
 *    있는 워커의 lock 에 막혀 `aborted{reason:'busy'}` 를 받는데, 종전에는 `reason` 을 보지
 *    않고 곧장 중단 화면을 그렸다. `_handOffToPolling()` 의 docblock 이 이 상황을 이미
 *    정확히 적어 두었고 그 기계도 있었다 — 부르지 않았을 뿐이다.
 *  - **J2**: 폴링은 `setInterval` 이 응답과 무관하게 발사한다. 조회가 1초를 넘기면 요청이
 *    겹치고, 늦게 도착한 옛 응답이 진행 상태를 되감아 같은 로그가 다시 그려지고 지나간
 *    단계에 `onTaskStart` 가 재발화한다.
 *  - **J5**: 단계별 실행의 `stop()` 은 `AbortController.abort()` 를 부르는데, 그 취소가
 *    catch 로 돌아와 네트워크 실패로 세어졌다. 침묵 타이머가 먼저 발화한 경우 정확한 사유가
 *    틀린 사유로 덮인다.
 *
 * 커버 축: sse_abort_reason busy·user / poll_overlap slow·fast / stepwise_stop during_request·idle
 *
 * @effects sse_busy_abort_hands_off_instead_of_showing_aborted, polling_never_overlaps_requests, stepwise_stop_is_not_counted_as_network_failure
 */
import { readFileSync } from 'fs';
import path from 'path';
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';

const MONITOR_SOURCE = path.resolve(
  __dirname,
  '../../../../public/install/assets/js/installation-monitor.js',
);

const STATE_URL = '/install/api/state-management.php?action=get';

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

  emit(name: string, data?: string) {
    (this.listeners[name] ?? []).forEach((fn) => fn({ data }));
  }
}

let monitors: any;

beforeAll(() => {
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

function collector() {
  const calls: Record<string, any[][]> = {};
  const handler =
    (name: string) =>
    (...args: any[]) => {
      calls[name] = calls[name] ?? [];
      calls[name].push(args);
    };

  return {
    calls,
    callbacks: {
      onConnected: handler('onConnected'),
      onLog: handler('onLog'),
      onTaskStart: handler('onTaskStart'),
      onTaskComplete: handler('onTaskComplete'),
      onComplete: handler('onComplete'),
      onError: handler('onError'),
      onAbort: handler('onAbort'),
      onBusy: handler('onBusy'),
      onRetry: handler('onRetry'),
      onState: handler('onState'),
      onRollbackFailed: handler('onRollbackFailed'),
      onConnectionTimeout: handler('onConnectionTimeout'),
    },
  };
}

describe('J1 — busy 로 거부된 재연결', () => {
  // @scenario installation_mode=sse
  // @effects sse_busy_abort_hands_off_instead_of_showing_aborted
  it('중단 화면을 그리지 않고 진행 조회로 갈아탄다', () => {
    vi.stubGlobal('EventSource', FakeEventSource as unknown as typeof EventSource);
    vi.stubGlobal('fetch', vi.fn(async () => ({ status: 200, ok: true, json: async () => ({}) }) as any));

    const c = collector();
    const monitor = new monitors.SseMonitor(c.callbacks, {
      streamUrl: '/install/api/install-worker.php',
      stateUrl: STATE_URL,
    });
    monitor.start();

    FakeEventSource.last!.emit('aborted', JSON.stringify({ reason: 'busy', message: '다른 요청이 진행 중입니다' }));

    expect(
      c.calls.onAbort,
      '돌고 있는 설치 위에 「중단되었습니다」가 뜬다 — busy 는 중단이 아니라 이어받기 실패다',
    ).toBeUndefined();

    monitor.stop();
    if (monitor.successor) monitor.successor.stop();
  });

  // @scenario installation_mode=sse, task_outcome=aborted
  // @effects sse_busy_abort_hands_off_instead_of_showing_aborted
  it('사용자 중단은 그대로 중단 화면으로 간다 — 면제가 넓어지면 안 된다', () => {
    vi.stubGlobal('EventSource', FakeEventSource as unknown as typeof EventSource);

    const c = collector();
    const monitor = new monitors.SseMonitor(c.callbacks, {
      streamUrl: '/install/api/install-worker.php',
      stateUrl: STATE_URL,
    });
    monitor.start();

    FakeEventSource.last!.emit('aborted', JSON.stringify({ reason: 'user' }));

    expect(c.calls.onAbort, '운영자가 누른 중단은 중단 화면이 정답이다').toHaveLength(1);

    monitor.stop();
  });
});

describe('J2 — 폴링 요청 겹침', () => {
  // @scenario installation_mode=polling
  // @effects polling_never_overlaps_requests
  it('앞선 조회가 끝나기 전에는 다음 조회를 보내지 않는다', async () => {
    vi.useFakeTimers();

    let release: (() => void) | null = null;
    const inFlight = new Promise<void>((resolve) => {
      release = resolve;
    });

    const fetchSpy = vi.fn(async () => {
      await inFlight;

      return { status: 200, ok: true, json: async () => ({ status: 'running', logs: [] }) } as any;
    });
    vi.stubGlobal('fetch', fetchSpy);

    const c = collector();
    const monitor = new monitors.PollingMonitor(c.callbacks, { stateUrl: STATE_URL, intervalMs: 1000 });
    monitor.start();

    // start() 가 즉시 한 번 보낸다. 그 응답이 아직 오지 않은 채 tick 을 세 번 넘긴다.
    await vi.advanceTimersByTimeAsync(3000);

    expect(
      fetchSpy.mock.calls.length,
      '응답을 기다리는 동안 요청이 쌓이면, 늦게 온 옛 응답이 진행 상태를 되감는다',
    ).toBe(1);

    release!();
    monitor.stop();
  });
});

describe('J5 — 단계별 실행의 중단', () => {
  // @scenario installation_mode=stepwise, task_outcome=aborted
  // @effects stepwise_stop_is_not_counted_as_network_failure
  it('중단으로 끊은 요청을 네트워크 실패로 세지 않는다', async () => {
    const c = collector();
    let abortRequest: (() => void) | null = null;

    vi.stubGlobal(
      'fetch',
      vi.fn(
        (_url: string, init: any) =>
          new Promise((_resolve, reject) => {
            abortRequest = () => reject(Object.assign(new Error('aborted'), { name: 'AbortError' }));
            if (init && init.signal) {
              init.signal.addEventListener('abort', () => abortRequest && abortRequest());
            }
          }),
      ),
    );

    const monitor = new monitors.StepwiseMonitor(c.callbacks, {
      runTaskUrl: '/install/api/run-task.php',
      token: 'tok',
      stateUrl: STATE_URL,
    });
    monitor.start();

    await Promise.resolve();
    monitor.stop();
    await new Promise((resolve) => setTimeout(resolve, 10));

    expect(
      c.calls.onRetry,
      '중단이 「재시도 1/3」로 기록되면 중단 화면에 없는 진행이 남는다',
    ).toBeUndefined();
    expect(
      c.calls.onError,
      '중단 화면 위에 실패 화면이 덧그려진다 — 취소는 실패가 아니다',
    ).toBeUndefined();
  });
});
