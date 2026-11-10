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
 * 조합과 효과 표식은 그 효과를 재는 각 시험 앞에 있다.
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

  /** 마지막으로 만든 인스턴스 — 정적 필드를 바로 읽으면 앞의 `last = null` 대입으로 null 로 좁혀진다 */
  static latest(): FakeEventSource | null {
    return FakeEventSource.last;
  }

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
  /**
   * @scenario installation_mode=stepwise, task_outcome=failed_db
   *
   * @effects stepwise_monitor_surfaces_envelope_failure_reason
   */
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

  /**
   * @scenario installation_mode=stepwise, task_outcome=failed_extension
   *
   * @effects stepwise_monitor_surfaces_envelope_failure_reason
   */
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

  /**
   * @scenario installation_mode=stepwise, task_outcome=done
   *
   * @effects stepwise_monitor_drains_events_before_outcome
   */
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

  /**
   * @scenario installation_mode=stepwise, task_outcome=aborted
   *
   * @effects stepwise_monitor_ignores_response_after_stop
   */
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

  /**
   * 단계별 실행의 응답 하나가 그 태스크의 **모든** 줄을 싣고 온다. 줄마다 `onLog` 로
   * 떨어뜨리면 화면이 줄 수만큼 `appendChild` + 강제 리플로우를 하고, 그 순간 메인
   * 스레드가 멈춘다 — 실측(2026-09-11, 수정 전): 207,699 자가 한 번에 와 1,579 ms.
   *
   * @effects progress_log_is_rendered_in_batches_not_per_line
   */
  it('연속한 log 이벤트를 한 묶음으로 넘긴다 (D2 L4)', async () => {
    stubFetch([
      {
        body: {
          outcome: 'completed',
          events: [
            { event: 'log', data: { message: 'a' } },
            { event: 'log', data: { message: 'b' } },
            { event: 'task_start', data: { task: 't1', name: '게시판 모듈 설치' } },
            { event: 'log', data: { message: 'c' } },
            { event: 'log', data: { message: 'd' } },
          ],
          state: { status: 'completed' },
        },
      },
    ]);

    const { calls, callbacks } = collector();
    const batches: string[][] = [];
    const monitor = new monitors.StepwiseMonitor(
      { ...callbacks, onLogBatch: (messages: string[]) => { batches.push(messages); } },
      { runTaskUrl: '/run-task', token: 't', busyRetryMs: 1 },
    );

    monitor.start();
    await vi.waitFor(() => expect(calls.onComplete).toBeDefined());

    // 이벤트 **순서**는 지켜야 한다 — 다른 종류를 만나면 그 자리에서 먼저 비운다.
    // 끝에 몰아 넘기면 진행 기록과 단계 표시의 시간 순서가 뒤집힌다.
    expect(batches).toEqual([['a', 'b'], ['c', 'd']]);
    expect(calls.onLog, '배치 콜백이 있으면 줄 단위 콜백은 쓰지 않는다').toBeUndefined();
  });

  /**
   * 서버가 이미 묶어 보낸 경우(단계별 경로의 정본 형태 — D2 L6)도 같은 통로로 간다.
   *
   * @effects stepwise_command_output_is_written_in_one_batch
   */
  it('서버가 보낸 log_batch 를 그대로 한 번에 넘긴다', async () => {
    stubFetch([
      {
        body: {
          outcome: 'completed',
          events: [{ event: 'log_batch', data: { messages: ['[cmd] x', '[cmd] y', '[cmd] z'] } }],
          state: { status: 'completed' },
        },
      },
    ]);

    const { calls, callbacks } = collector();
    const batches: string[][] = [];
    const monitor = new monitors.StepwiseMonitor(
      { ...callbacks, onLogBatch: (messages: string[]) => { batches.push(messages); } },
      { runTaskUrl: '/run-task', token: 't', busyRetryMs: 1 },
    );

    monitor.start();
    await vi.waitFor(() => expect(calls.onComplete).toBeDefined());

    expect(batches).toEqual([['[cmd] x', '[cmd] y', '[cmd] z']]);
  });

  /**
   * 배치 콜백을 주지 않는 호출부(기존 화면·테스트)에서는 결과가 같아야 한다.
   *
   * @scenario progress_log_volume=small, installation_mode=stepwise
   *
   * @effects progress_log_is_rendered_in_batches_not_per_line
   */
  it('onLogBatch 가 없으면 줄마다 onLog 로 떨어진다 (호환 축)', async () => {
    stubFetch([
      {
        body: {
          outcome: 'completed',
          events: [
            { event: 'log', data: { message: 'a' } },
            { event: 'log_batch', data: { messages: ['b', 'c'] } },
          ],
          state: { status: 'completed' },
        },
      },
    ]);

    const { calls, callbacks } = collector();
    const monitor = new monitors.StepwiseMonitor(callbacks, { runTaskUrl: '/run-task', token: 't', busyRetryMs: 1 });

    monitor.start();
    await vi.waitFor(() => expect(calls.onComplete).toBeDefined());

    expect(calls.onLog.map((c) => c[0])).toEqual(['a', 'b', 'c']);
  });

  /**
   * `task_done` 뒤 **최소 간격**이 없으면, 서버가 즉시 `task_done` 을 되풀이하는 구간에서
   * 요청 간격이 0 이 되어 폭주한다 (D2 L8).
   *
   * @effects stepwise_reports_progress_while_a_task_takes_long
   */
  it('task_done 사이에 최소 간격을 둔다 (D2 L8)', async () => {
    const spy = stubFetch([
      { body: { outcome: 'task_done', events: [], state: { status: 'running' } } },
      { body: { outcome: 'task_done', events: [], state: { status: 'running' } } },
      { body: { outcome: 'completed', events: [], state: { status: 'completed' } } },
    ]);

    const { calls, callbacks } = collector();
    const monitor = new monitors.StepwiseMonitor(callbacks, {
      runTaskUrl: '/run-task',
      token: 't',
      busyRetryMs: 1,
      taskGapMs: 40,
    });

    const startedAt = Date.now();
    monitor.start();
    await vi.waitFor(() => expect(calls.onComplete).toBeDefined());

    expect(spy.mock.calls.length).toBe(3);
    // 두 번의 task_done 사이에 각각 40ms → 최소 80ms. 여유를 두고 하한만 본다.
    expect(Date.now() - startedAt, '간격이 없으면 요청이 폭주한다').toBeGreaterThanOrEqual(70);
  });

  /**
   * 응답이 오지 않는 구간에 **화면 피드백이 없으면** 그것은 사용자에게 무응답이다 (D2 L9).
   * 실측(2026-09-11, 수정 전): 한 태스크가 44.0 초 도는 동안 화면이 한 글자도 바뀌지 않았다.
   *
   * @effects stepwise_reports_progress_while_a_task_takes_long
   */
  it('요청이 도는 동안 경과를 알리고, 문턱을 넘으면 한 번만 안내한다 (D2 L9)', async () => {
    let release: (() => void) | null = null;
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        await new Promise<void>((resolve) => { release = resolve; });

        return {
          status: 200,
          ok: true,
          json: async () => ({ outcome: 'completed', events: [], state: { status: 'completed' } }),
        } as unknown as Response;
      }),
    );

    const ticks: number[] = [];
    const notices: number[] = [];
    const { calls, callbacks } = collector();
    const monitor = new monitors.StepwiseMonitor(
      {
        ...callbacks,
        onProgressTick: (elapsed: number) => { ticks.push(elapsed); },
        onLongTask: (elapsed: number) => { notices.push(elapsed); },
      },
      {
        runTaskUrl: '/run-task',
        token: 't',
        busyRetryMs: 1,
        progressTickMs: 10,
        longTaskNoticeMs: 50,
      },
    );

    monitor.start();
    await vi.waitFor(() => expect(ticks.length).toBeGreaterThanOrEqual(3));
    await vi.waitFor(() => expect(notices.length).toBe(1));
    // 주기마다 남기면 그 자체가 로그 폭주가 되어 고치려던 것을 다시 만든다.
    await new Promise((resolve) => setTimeout(resolve, 60));
    expect(notices.length, '안내는 요청 한 번에 한 번뿐이어야 한다').toBe(1);

    release!();
    await vi.waitFor(() => expect(calls.onComplete).toBeDefined());

    // 요청이 끝나면 타이머도 멎는다 — 남으면 설치가 끝난 뒤에도 경과가 올라간다.
    const seen = ticks.length;
    await new Promise((resolve) => setTimeout(resolve, 40));
    expect(ticks.length, '요청이 끝났는데 경과 타이머가 살아 있다').toBe(seen);
  });

  /**
   * 경과 표시와 장시간 안내는 **지금 요청이 돌리는 단계**를 대야 한다. 단계별 실행은 한 요청이
   * 태스크 하나를 끝낸 뒤 그 태스크의 `task_start`·`task_complete` 를 함께 돌려주므로, 그 이름을
   * 다음 요청에 그대로 쓰면 언제나 직전에 끝난 단계를 가리킨다(실측: 「패키지 관리자 확인 실행 중
   * (43초 경과)」 동안 서버는 vendor 추출과 마이그레이션을 하고 있었다). 요청을 보내기 전에
   * 화면이 다음 단계를 정해 주면 그 이름을 쓴다.
   *
   * @effects stepwise_progress_names_the_task_being_run
   */
  it('경과 표시는 직전에 끝난 단계가 아니라 이번 요청이 돌리는 단계를 댄다', async () => {
    const responses = [
      { outcome: 'task_done', events: [
        { event: 'task_start', data: { task: 'composer_check', name: '패키지 관리자 확인' } },
        { event: 'task_complete', data: { task: 'composer_check' } },
      ], state: {} },
      { outcome: 'completed', events: [], state: { status: 'completed' } },
    ];
    let release: (() => void) | null = null;
    let call = 0;
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        const body = responses[Math.min(call, responses.length - 1)];
        call += 1;
        if (call === 2) {
          await new Promise<void>((resolve) => { release = resolve; });
        }

        return { status: 200, ok: true, json: async () => body } as unknown as Response;
      }),
    );

    const planned = ['패키지 관리자 확인', '필수 패키지 설치'];
    const asked: number[] = [];
    const ticks: Array<string | null> = [];
    const { calls, callbacks } = collector();
    const monitor = new monitors.StepwiseMonitor(
      {
        ...callbacks,
        resolveNextTask: () => { asked.push(call); return planned[asked.length - 1] ?? null; },
        onProgressTick: (_elapsed: number, name: string | null) => { ticks.push(name); },
      },
      { runTaskUrl: '/run-task', token: 't', busyRetryMs: 1, taskGapMs: 0, progressTickMs: 10, longTaskNoticeMs: 0 },
    );

    monitor.start();
    await vi.waitFor(() => expect(release).not.toBeNull());
    ticks.length = 0;
    await vi.waitFor(() => expect(ticks.length).toBeGreaterThanOrEqual(2));

    expect(asked, '요청마다 보내기 전에 다음 단계를 묻지 않는다').toEqual([0, 1]);
    expect(new Set(ticks), '두 번째 요청 동안 직전에 끝난 단계 이름을 댄다').toEqual(new Set(['필수 패키지 설치']));

    release!();
    await vi.waitFor(() => expect(calls.onComplete).toBeDefined());
  });

  /**
   * 경과 줄은 요청을 **보내는 순간** 이번 단계 이름으로 바뀌어야 한다. 첫 표시를 한 주기(1초) 뒤로
   * 미루면 그동안 직전 단계의 「… 실행 중 (24초 경과)」가 그대로 남는다(실측: 단계가 바뀐 25번 모두
   * 1~4초 남았고, 가장 긴 경우 2.5초 동안 다른 단계 4개가 지나갔다).
   *
   * @effects stepwise_progress_line_switches_when_request_starts
   */
  it('요청을 보내는 순간 경과 줄을 이번 단계 이름(0초)으로 바꾼다', async () => {
    let release: (() => void) | null = null;
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => {
        await new Promise<void>((resolve) => { release = resolve; });

        return {
          status: 200,
          ok: true,
          json: async () => ({ outcome: 'completed', events: [], state: { status: 'completed' } }),
        } as unknown as Response;
      }),
    );

    const ticks: Array<[number, string | null]> = [];
    const { calls, callbacks } = collector();
    const monitor = new monitors.StepwiseMonitor(
      {
        ...callbacks,
        resolveNextTask: () => '필수 패키지 설치',
        onProgressTick: (elapsed: number, name: string | null) => { ticks.push([elapsed, name]); },
      },
      // 주기를 길게 두어 「주기를 기다린 첫 표시」와 「보내는 순간의 표시」를 가른다
      { runTaskUrl: '/run-task', token: 't', busyRetryMs: 1, taskGapMs: 0, progressTickMs: 60000, longTaskNoticeMs: 0 },
    );

    monitor.start();
    await vi.waitFor(() => expect(release).not.toBeNull());

    expect(ticks, '요청을 보냈는데 경과 줄이 한 주기 동안 직전 단계에 머문다').toEqual([[0, '필수 패키지 설치']]);

    release!();
    await vi.waitFor(() => expect(calls.onComplete).toBeDefined());
  });

  /**
   * 로그 파일이 회전하면 서버가 알려 주는 총 줄 수가 **작아진다** (D2 L11). 이전 offset 을
   * 그대로 들고 있으면 서버는 「이미 봤다」로 읽어 회전 뒤의 줄을 한 줄도 싣지 않는다.
   *
   * @effects installation_log_rotates_before_it_grows_unbounded
   */
  it('log_total 이 작아지면 offset 을 그 값으로 되돌린다 (회전 내성)', async () => {
    const bodies = [
      { outcome: 'task_done', events: [], log_total: 9000, state: { status: 'running' } },
      { outcome: 'completed', events: [], log_total: 12, state: { status: 'completed' } },
    ];
    const sentOffsets: number[] = [];
    vi.stubGlobal(
      'fetch',
      vi.fn(async (_url: string, init: any) => {
        sentOffsets.push(JSON.parse(init.body).log_offset);

        return {
          status: 200,
          ok: true,
          json: async () => bodies[Math.min(sentOffsets.length - 1, bodies.length - 1)],
        } as unknown as Response;
      }),
    );

    const { calls, callbacks } = collector();
    const monitor = new monitors.StepwiseMonitor(callbacks, {
      runTaskUrl: '/run-task', token: 't', busyRetryMs: 1, taskGapMs: 0,
    });

    monitor.start();
    await vi.waitFor(() => expect(calls.onComplete).toBeDefined());

    expect(sentOffsets[0]).toBe(0);
    expect(sentOffsets[1], '첫 응답의 총 줄 수를 offset 으로 삼아야 한다').toBe(9000);
    expect(monitor.logOffset, '회전으로 줄어든 값을 그대로 따라야 그 뒤의 줄이 실린다').toBe(12);
  });
});

describe('PollingMonitor', () => {
  /**
   * 폴링도 **한 주기치 줄을 한 번에** 넘긴다 (D2 L4).
   *
   * 이 모드는 1초치 줄을 배열(`state.logs`)로 받는다. 배열을 손에 쥔 채 줄마다 콜백을
   * 떨어뜨리면 화면이 줄 수만큼 DOM 을 만지는데, 한 주기에 수백 줄이 오는 구간
   * (마이그레이션·시더)에서 그 자체가 메인 스레드 블록이 된다 — 실측(2026-09-11):
   * 단계별만 배치화하고 이 경로를 줄 단위로 두었을 때 **이 모드의 최장 long task 가
   * 1,541 ms → 5,337 ms 로 악화**했다. 세 모드가 같은 배치 통로를 쓴다.
   *
   * @scenario progress_log_volume=large, installation_mode=polling
   *
   * @effects progress_log_is_rendered_in_batches_not_per_line
   */
  it('한 주기의 로그 배열을 한 묶음으로 넘긴다 (D2 L4)', async () => {
    // 폴링은 본문을 `res.text()` 로 읽는다(빈 본문과 파싱 실패를 가르기 위해) — 공용
    // `stubFetch` 의 `json()` 만으로는 이 경로가 서지 않는다.
    const payload = JSON.stringify({
      success: true,
      status: 'running',
      logs: [{ message: 'a' }, { message: 'b' }, 'c'],
      log_total: 3,
      completed_tasks: [],
      current_task: null,
    });
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => ({
        status: 200,
        ok: true,
        text: async () => payload,
        json: async () => JSON.parse(payload),
      } as unknown as Response)),
    );

    const batches: string[][] = [];
    const c = collector();
    const monitor = new monitors.PollingMonitor(
      { ...c.callbacks, onLogBatch: (messages: string[]) => { batches.push(messages); } },
      { stateUrl: '/install/api/state-management.php?action=get', intervalMs: 1 },
    );

    monitor.start();
    await vi.waitFor(() => expect(batches.length).toBeGreaterThan(0));
    monitor.stop();

    expect(batches[0], '폴링이 배열을 줄 단위로 풀어 던지면 그 주기가 통째로 블록이 된다').toEqual(['a', 'b', 'c']);
    expect(c.calls.onLog, '배치 콜백이 있으면 줄 단위 콜백은 쓰지 않는다').toBeUndefined();
  });

  /**
   * @scenario installation_mode=polling
   *
   * @effects polling_monitor_surfaces_client_error_status
   */
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

  /**
   * @scenario installation_mode=polling
   *
   * @effects polling_monitor_surfaces_client_error_status
   */
  it('5xx 는 다음 tick 에서 재시도한다 (일시적 오류를 종료로 만들지 않는다)', async () => {
    stubFetch([{ status: 503, body: {} }]);

    const c = collector();
    const monitor = new monitors.PollingMonitor(c.callbacks, { stateUrl: '/install/api/state-management.php?action=get' });

    await monitor._poll();

    expect(c.calls.onError, '5xx 까지 즉시 끝내면 일시적 장애가 설치 실패가 된다').toBeUndefined();
  });

  /**
   * @scenario installation_mode=polling, task_outcome=aborted
   *
   * @effects polling_monitor_ignores_response_after_stop
   */
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

    const source = FakeEventSource.latest();
    expect(source, 'EventSource 가 생성되지 않았다').toBeTruthy();

    return { monitor, source: source as FakeEventSource, calls: c.calls };
  }

  /**
   * @scenario installation_mode=sse
   *
   * @effects sse_monitor_completes_on_broken_event_body
   */
  it('completed 본문이 깨져도 완료를 알린다 (8차 회귀 축)', () => {
    const { source, calls } = startSse();

    source.emit('connected', '{}');
    // 프록시가 스트림을 자른 상황 — 본문이 JSON 이 아니다.
    source.emit('completed', '{"message": bro');

    expect(calls.onComplete, '본문이 깨졌다고 완료를 삼키면 성공한 설치가 잠시 뒤 「응답 없음」으로 뒤집힌다').toBeTruthy();
    expect(source.closed, '완료했으면 스트림을 닫는다').toBe(true);
  });

  /**
   * @scenario installation_mode=sse
   *
   * @effects sse_monitor_reports_stall_after_connect
   */
  it('연결 뒤 침묵하면 정지로 보고한다 (8차 회귀 축)', () => {
    vi.useFakeTimers();
    const { calls } = startSse({ stuckThresholdMs: 1000, connectionTimeoutMs: 500 });

    FakeEventSource.last!.emit('connected', '{}');
    // 연결됐으므로 네이티브 onerror 경로(연결 전 실패)는 발화하지 않는다 —
    // 이 축이 없으면 워커가 죽어도 화면이 마지막 진행률에서 영구 정지한다.
    vi.advanceTimersByTime(1500);

    expect(calls.onError, '연결 후 정지를 아무도 보고하지 않으면 화면이 멈춘 채로 남는다').toBeTruthy();
  });

  /**
   * @scenario installation_mode=sse
   *
   * @effects sse_monitor_closes_stream_on_stop
   */
  it('stop() 은 스트림을 닫는다', () => {
    const { monitor, source } = startSse();

    source.emit('connected', '{}');
    monitor.stop();

    expect(source.closed).toBe(true);
  });

  /**
   * @scenario installation_mode=sse, skip_request=skippable
   *
   * @effects completion_keeps_passed_over_evidence
   */
  it('건너뛴 단계 표식을 떨어뜨리지 않는다 (best-effort 실패가 초록으로 보이면 안 된다)', () => {
    const { source, calls } = startSse();

    source.emit('connected', '{}');
    source.emit('task_complete', JSON.stringify({ task: 'config_cache', message: '경고', skipped: true }));

    expect(calls.onTaskComplete[0][3]).toBe(true);
  });
});
