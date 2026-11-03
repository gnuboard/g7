/**
 * SSE 워커가 물러날 때 화면이 재연결이 아니라 진행 조회로 갈아타는가 (13차 E5).
 *
 * 서버는 워커 lock 을 잃으면 물러나기 **전에** 한 프레임을 보낸다:
 *
 *   event: worker_superseded
 *   data: {"message": "..."}
 *
 * 화면이 이 이름을 모르면 EventSource 는 스트림 종료를 장애로 읽고 재연결한다. 그 재연결은
 * 승자 요청이 쥔 lock 에 막혀 `aborted{busy}` 를 받고 **「중단」 화면**을 그린다 — 승자 요청은
 * 멀쩡히 돌고 있는데 운영자는 설치가 중단된 줄 안다. 서버측 프레임만으로는 아무것도 달라지지
 * 않으므로, 소비가 이 계약의 나머지 절반이다.
 *
 * 커버 축: takeover_signal worker_superseded / handoff_target polling·none / stateUrl present·absent / cancellation after_handoff
 *
 * @effects sse_monitor_hands_off_to_polling_when_superseded
 */
import { readFileSync } from 'fs';
import path from 'path';
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';

const MONITOR_SOURCE = path.resolve(
  __dirname,
  '../../../../public/install/assets/js/installation-monitor.js',
);

const STATE_URL = '/install/api/state-management.php?action=get';

/** EventSource 스텁 — 이 파일은 번들러를 거치지 않으므로 소스를 그대로 평가해 돌린다. */
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

/** 서버 상태 조회를 대신한다 (호출된 URL 을 기록한다). */
function stubStateFetch(state: Record<string, unknown> = { status: 'running', logs: [] }) {
  const spy = vi.fn(
    async () =>
      ({
        status: 200,
        ok: true,
        json: async () => state,
      }) as unknown as Response,
  );
  vi.stubGlobal('fetch', spy);

  return spy;
}

/** 콜백 호출을 기록하는 수집기. */
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
      onTaskStart: handler('onTaskStart'),
      onTaskComplete: handler('onTaskComplete'),
      onLog: handler('onLog'),
      onComplete: handler('onComplete'),
      onError: handler('onError'),
      onAbort: handler('onAbort'),
      onState: handler('onState'),
    },
  };
}

function startSse(overrides: Record<string, any> = {}) {
  FakeEventSource.last = null;
  vi.stubGlobal('EventSource', FakeEventSource as any);

  const c = collector();
  const monitor = new monitors.SseMonitor(c.callbacks, {
    workerUrl: '/install/api/install-worker.php',
    stateUrl: STATE_URL,
    ...overrides,
  });
  monitor.start();

  const source = FakeEventSource.last;
  expect(source, 'EventSource 가 생성되지 않았다').toBeTruthy();

  return { monitor, source: source as FakeEventSource, calls: c.calls };
}

/** 마이크로태스크 큐를 비운다 (폴링 첫 요청이 await 뒤에서 끝난다). */
const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('SseMonitor — worker_superseded', () => {
  // @scenario installation_mode=sse
  // @effects sse_monitor_hands_off_to_polling_when_superseded
  it('물러남 프레임을 받으면 스트림을 닫고 진행 조회로 갈아탄다', async () => {
    const fetchSpy = stubStateFetch();
    const { source, calls } = startSse();

    source.emit('connected', '{}');
    source.emit('worker_superseded', JSON.stringify({ message: '다른 요청이 설치 진행을 이어받았습니다.' }));

    await flush();

    expect(
      source.closed,
      '스트림을 닫지 않으면 EventSource 가 재연결하고, 그 재연결은 lock 에 막혀 「중단」 화면을 그린다',
    ).toBe(true);
    expect(fetchSpy, '진행 조회로 갈아타지 않으면 승자 요청이 도는 동안 화면이 아무것도 받지 못한다').toHaveBeenCalled();
    expect(String(fetchSpy.mock.calls[0][0])).toContain('state-management.php');

    expect(calls.onError, '이어받기는 실패가 아니다 — 실패로 보고하면 정상 진행이 중단 화면이 된다').toBeUndefined();
    expect(calls.onAbort, '이어받기는 중단이 아니다').toBeUndefined();
    expect(calls.onLog, '무슨 일이 일어났는지는 진행 기록에 남겨야 한다').toBeTruthy();
  });

  // @scenario installation_mode=sse
  // @effects sse_monitor_hands_off_to_polling_when_superseded
  it('갈아탄 뒤에도 stop() 이 진행 조회를 멈춘다', async () => {
    const fetchSpy = stubStateFetch();
    const { monitor, source } = startSse();

    source.emit('connected', '{}');
    source.emit('worker_superseded', '{}');
    await flush();

    const before = fetchSpy.mock.calls.length;
    monitor.stop();

    vi.useFakeTimers();
    vi.advanceTimersByTime(5000);
    await Promise.resolve();

    expect(
      fetchSpy.mock.calls.length,
      '[중단] 을 눌러도 이어받은 폴링이 계속 돌면 중단 화면 위에 진행이 덧그려진다',
    ).toBe(before);
  });

  // @scenario installation_mode=sse
  // @effects sse_monitor_hands_off_to_polling_when_superseded
  it('진행 조회 주소가 없으면 종전 동작을 유지한다 (갈아탈 곳이 없다)', async () => {
    const fetchSpy = stubStateFetch();
    const { source } = startSse({ stateUrl: undefined });

    source.emit('connected', '{}');
    source.emit('worker_superseded', '{}');
    await flush();

    expect(fetchSpy, '주소 없이 폴링을 시작하면 undefined 로 요청이 나간다').not.toHaveBeenCalled();
  });
});
