/**
 * SSE `heartbeat` 소비 회귀 테스트 (12차 #B27 · #B35).
 *
 * 서버는 번들 추출 구간(`includes/task-runner.php`)에서 **`heartbeat` 만** 내보낸다. 그 구간은
 * 10~15초 이상 걸리는 최장 태스크인데, 화면의 침묵 감지(기본 90초)는 살아 있음의 증거로 세는
 * 이벤트 목록에서 `heartbeat` 를 빠뜨리고 있었다. `EventSource` 는 이름 있는 이벤트를 `message`
 * 리스너로 보내지 않으므로 캡처 단계도 이를 잡지 못한다 — 추출이 90초를 넘기면 서버가 정상
 * 동작 중인데도 화면이 「설치가 멈춘 것 같다」로 넘어간다.
 *
 * 같은 이벤트의 `done`/`total` 페이로드도 소비처가 없어 통째로 버려지고 있었다(#B35) —
 * 최장 태스크가 화면에서는 정지한 스피너였다.
 *
 * 소스 문자열 대조로는 이 둘을 잡을 수 없다. 목록에서 이름 하나를 빼도, 페이로드를 버려도
 * 호출은 그대로 남기 때문이다. 그래서 파일을 그대로 평가해 실제 클래스를 돌린다.
 *
 * 커버 축: sse_event heartbeat·log / liveness_evidence armed·ignored / heartbeat_payload progress·empty
 *
 * @effects sse_monitor_counts_heartbeat_as_liveness, sse_monitor_forwards_heartbeat_progress
 */
import { readFileSync } from 'fs';
import path from 'path';
import { beforeAll, afterEach, describe, expect, it, vi } from 'vitest';

const MONITOR_SOURCE = path.resolve(
  __dirname,
  '../../../../public/install/assets/js/installation-monitor.js',
);

/** EventSource 스텁 — 이름 있는 이벤트만 그 이름의 리스너에게 전달한다(브라우저와 동일). */
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
  FakeEventSource.last = null;
});

/** 콜백을 기록하는 수집기. */
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
      onHeartbeat: handler('onHeartbeat'),
      onRollbackFailed: handler('onRollbackFailed'),
    },
  };
}

describe('SseMonitor heartbeat (#B27 · #B35)', () => {
  it('heartbeat 를 「살아 있음」의 증거로 세어 정지 판정을 미룬다 (#B27)', () => {
    vi.useFakeTimers();
    vi.stubGlobal('EventSource', FakeEventSource as any);

    const c = collector();
    const monitor = new monitors.SseMonitor(c.callbacks, {
      workerUrl: '/install/api/install-worker.php',
      stuckThresholdMs: 1000,
    });
    monitor.start();

    const es = FakeEventSource.last!;
    es.emit('connected', '{}');

    // 번들 추출 구간은 heartbeat 말고는 아무것도 내보내지 않는다.
    // 임계의 80% 마다 하나씩 도착하면 정지 판정은 영원히 오지 않아야 한다.
    for (let i = 0; i < 5; i += 1) {
      vi.advanceTimersByTime(800);
      es.emit('heartbeat', JSON.stringify({ done: i + 1, total: 25 }));
    }
    vi.advanceTimersByTime(800);

    expect(
      c.calls.onError,
      'heartbeat 가 도착하는 동안 정지로 판정했다 — 번들 추출이 90초를 넘기면 정상 설치가 실패 화면으로 넘어간다',
    ).toBeUndefined();

    // 반대 방향: heartbeat 가 끊기면 임계 뒤에 정지로 판정해야 한다.
    vi.advanceTimersByTime(1000);
    expect(c.calls.onError?.[0]?.[0]?.stuck, '침묵이 이어지는데 정지 판정이 나오지 않았다').toBe(true);
  });

  it('heartbeat 의 done/total 진행을 화면으로 나른다 (#B35)', () => {
    vi.stubGlobal('EventSource', FakeEventSource as any);

    const c = collector();
    const monitor = new monitors.SseMonitor(c.callbacks, {
      workerUrl: '/install/api/install-worker.php',
    });
    monitor.start();

    const es = FakeEventSource.last!;
    es.emit('connected', '{}');
    es.emit('heartbeat', JSON.stringify({ done: 7, total: 25 }));

    expect(
      c.calls.onHeartbeat?.[0]?.[0],
      'heartbeat 의 진행 페이로드가 버려졌다 — 최장 태스크가 화면에서 정지한 스피너로 남는다',
    ).toEqual({ done: 7, total: 25 });

    monitor.stop();
  });

  it('진행 값이 없는 heartbeat 는 진행을 덮어쓰지 않는다', () => {
    vi.stubGlobal('EventSource', FakeEventSource as any);

    const c = collector();
    const monitor = new monitors.SseMonitor(c.callbacks, {
      workerUrl: '/install/api/install-worker.php',
    });
    monitor.start();

    const es = FakeEventSource.last!;
    es.emit('connected', '{}');
    // 확장 설치 구간의 heartbeat 는 페이로드가 비어 있다 (task-runner.php 의 emit('heartbeat', [])).
    es.emit('heartbeat', '{}');

    expect(
      c.calls.onHeartbeat,
      '진행 값이 없는 heartbeat 까지 진행 표시로 넘겼다 — 「n/N」이 0/0 으로 덮인다',
    ).toBeUndefined();

    monitor.stop();
  });
});
