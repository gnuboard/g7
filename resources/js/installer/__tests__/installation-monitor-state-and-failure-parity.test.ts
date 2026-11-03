/**
 * 모니터 3종의 **콜백 계약 대칭성** 회귀 테스트 (13차 F11 · F12).
 *
 * 이 모듈의 전제는 「세 모니터가 같은 콜백 계약을 만족한다」인데(파일 머리 주석) 실제로는
 * 두 축이 갈려 있었다.
 *
 *  - F11: `onState` 를 단계별 실행만 호출한다. 폴링은 `_diffAndEmit` 안에서 자체 보정하므로
 *    지금은 화면이 같아 보이지만, 계약이 갈린 채로 두면 `onState` 소비자가 늘어날 때마다
 *    그 소비자가 폴링에서만 조용히 발화하지 않는다.
 *  - F12: 실패 문구의 폴백 사슬이 경로마다 다르다. 새로고침 복원은 `state.error`,
 *    단계별 실행은 `state.error_detail → body.message`, 폴링은 `state.error_detail` 만
 *    본다 — **같은 실패가 경로에 따라 사유가 있기도 없기도** 하다.
 *
 * 소스 문자열 대조로는 잡히지 않는다(호출은 그대로 남고 값만 달라진다). 그래서 파일을
 * 그대로 평가해 실제 클래스를 돌린다.
 *
 * 커버 축: monitor polling·stepwise / callback on_state·on_error / failure_source detail·error·body
 */
import { readFileSync } from 'fs';
import path from 'path';
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';

const MONITOR_SOURCE = path.resolve(
  __dirname,
  '../../../../public/install/assets/js/installation-monitor.js',
);

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

/** 콜백 호출을 기록하는 수집기. */
function collector() {
  const calls: Record<string, any[][]> = {};
  const record =
    (name: string) =>
    (...args: any[]) => {
      calls[name] = calls[name] ?? [];
      calls[name].push(args);
    };

  return {
    calls,
    callbacks: {
      onConnected: record('onConnected'),
      onState: record('onState'),
      onError: record('onError'),
      onComplete: record('onComplete'),
      onAbort: record('onAbort'),
      onTaskStart: record('onTaskStart'),
      onTaskComplete: record('onTaskComplete'),
      onLog: record('onLog'),
      onBusy: record('onBusy'),
      onRetry: record('onRetry'),
      onRollbackFailed: record('onRollbackFailed'),
    },
  };
}

/** 한 번만 응답하는 fetch 스텁. */
function stubFetch(payload: any, ok = true) {
  // `PollingMonitor._poll` 은 본문을 `res.text()` 로 읽고 직접 `JSON.parse` 한다 — 파싱
  // 실패를 세어 「응답이 JSON 이 아님」을 별도 사유로 끝내기 위해서다. `json()` 만 둔
  // 스텁은 그 자리에서 TypeError 를 내고, 그 예외를 `_poll` 의 catch 가 삼켜 콜백이
  // 하나도 발화하지 않는다(테스트는 「계약 미충족」으로 보인다). 두 접근자를 함께 둔다.
  const fn = vi.fn().mockResolvedValue({
    ok,
    status: ok ? 200 : 500,
    json: async () => payload,
    text: async () => JSON.stringify(payload),
  });
  vi.stubGlobal('fetch', fn);

  return fn;
}

describe('폴링 모니터도 onState 계약을 만족한다 (F11)', () => {
  // @scenario installation_mode=polling
  // @effects polling_monitor_emits_on_state_like_stepwise
  it('폴 한 번마다 서버 상태 요약을 그대로 넘긴다', async () => {
    const state = { status: 'running', current_task: 'db_migrate', completed_tasks: [], logs: [] };
    stubFetch(state);

    const sink = collector();
    const monitor = new monitors.PollingMonitor(sink.callbacks, { stateUrl: '/state', intervalMs: 100000 });

    monitor.start();
    await vi.waitFor(() => expect(sink.calls.onState).toBeDefined());
    monitor.stop();

    expect(
      sink.calls.onState[0][0],
      'onState 가 서버 상태를 그대로 나르지 않는다 — 단계별 실행만 이 콜백을 부르면 계약이 갈린다',
    ).toMatchObject({ status: 'running', current_task: 'db_migrate' });
  });
});

describe('실패 문구 폴백 사슬을 세 경로가 공유한다 (F12)', () => {
  // @scenario installation_mode=polling
  // @effects monitors_share_failure_reason_fallback_chain
  it('공유 폴백 함수가 노출되어 있다', () => {
    expect(
      typeof monitors.failurePayload,
      '폴백 사슬이 한 함수로 모이지 않았다 — 경로마다 사본을 두면 그 사본이 각자 뒤처진다',
    ).toBe('function');
  });

  // @scenario installation_mode=polling
  // @effects monitors_share_failure_reason_fallback_chain
  it('폴링: 상세가 없으면 state.error 를 사유로 쓴다', async () => {
    stubFetch({ status: 'failed', error: '마이그레이션 실패', completed_tasks: [], logs: [] });

    const sink = collector();
    const monitor = new monitors.PollingMonitor(sink.callbacks, { stateUrl: '/state', intervalMs: 100000 });

    monitor.start();
    await vi.waitFor(() => expect(sink.calls.onError).toBeDefined());
    monitor.stop();

    expect(
      sink.calls.onError[0][0].message,
      '폴링만 state.error 를 버린다 — 같은 실패가 경로에 따라 사유 없이 표시된다',
    ).toBe('마이그레이션 실패');
  });

  // @scenario installation_mode=stepwise
  // @effects monitors_share_failure_reason_fallback_chain
  it('단계별 실행: 상세가 없으면 state.error 를 사유로 쓴다', async () => {
    stubFetch({ outcome: 'failed', state: { error: '마이그레이션 실패' }, events: [] });

    const sink = collector();
    const monitor = new monitors.StepwiseMonitor(sink.callbacks, { runTaskUrl: '/run', token: 't' });

    monitor.start();
    await vi.waitFor(() => expect(sink.calls.onError).toBeDefined());
    monitor.stop();

    expect(
      sink.calls.onError[0][0].message,
      '단계별 실행이 state.error 를 버린다 — 상세가 없는 실패에서 화면이 일반 문구만 띄운다',
    ).toBe('마이그레이션 실패');
  });

  // @scenario installation_mode=stepwise
  // @effects monitors_share_failure_reason_fallback_chain, stepwise_monitor_surfaces_envelope_failure_reason
  it('단계별 실행: 상태가 비어도 응답 봉투의 사유를 쓴다', async () => {
    stubFetch({ outcome: 'failed', state: {}, message: '치명적 오류', events: [] });

    const sink = collector();
    const monitor = new monitors.StepwiseMonitor(sink.callbacks, { runTaskUrl: '/run', token: 't' });

    monitor.start();
    await vi.waitFor(() => expect(sink.calls.onError).toBeDefined());
    monitor.stop();

    expect(sink.calls.onError[0][0].message).toBe('치명적 오류');
  });
});
