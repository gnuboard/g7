/**
 * busy 로 기다리는 동안 다른 요청이 쓴 진행 기록이 이 창에도 도달한다 (14차 #9).
 *
 * 단계별 실행의 화면 로그 통로는 둘뿐이었다 — ① 재개 시 1회 `displayLogs(state.logs)`
 * ② 자기 응답의 `events`. 그런데 새로고침 직후에는 **이전 요청이 아직 태스크를 돌고 있고**,
 * 이 창은 그동안 `busy` 만 받는다. 그 요청이 쓴 진행 기록 줄은 이 창의 `events` 에 오지
 * 않으므로 **영영 화면에 나타나지 않는다** — 「단계는 초록인데 그 단계의 기록이 없다」가 된다.
 * 폴링 모드는 `log_offset` 증분 조회로 같은 구멍이 없다.
 *
 * 그래서 단계별 실행도 자기가 본 줄 수를 함께 보내고, 서버는 못 본 꼬리만 실어 준다.
 * 오프셋은 이 창이 스스로 세지 않고 **서버가 알려 준 총 줄 수**로 다시 잡는다 — 재시도·
 * 재개에서 자체 계수는 쉽게 어긋난다.
 *
 * 커버 축: monitor_mode stepwise / outcome busy·task_done / log_channel events·tail
 *
 * @effects stepwise_busy_response_delivers_missed_log_lines, stepwise_log_offset_follows_the_server_total
 */
import { readFileSync } from 'fs';
import path from 'path';
import { beforeAll, afterEach, describe, expect, it, vi } from 'vitest';

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

/** fetch 를 순서대로 정해진 응답으로 대신하고, 보낸 본문을 기록한다. */
function stubFetch(responses: Array<{ status?: number; body: unknown }>) {
  const sent: any[] = [];
  let call = 0;

  const spy = vi.fn(async (_url: string, init: any) => {
    sent.push(JSON.parse(init.body));
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

  return { spy, sent };
}

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
      onBusy: handler('onBusy'),
      onRetry: handler('onRetry'),
      onRollbackFailed: handler('onRollbackFailed'),
    },
  };
}

describe('StepwiseMonitor — busy 구간의 진행 기록', () => {
  it('busy 응답이 실어 준 못 본 줄을 화면에 넘긴다', async () => {
    const { sent } = stubFetch([
      {
        body: {
          success: true,
          outcome: 'busy',
          message: '다른 창에서 진행 중입니다',
          state: { status: 'running', installation_mode: 'stepwise', completed_tasks: ['env_update'] },
          logs: [
            { timestamp: '2026-08-24 12:00:00', message: '환경 설정을 기록했습니다.' },
            { timestamp: '2026-08-24 12:00:02', message: '보안 키를 만들었습니다.' },
          ],
          log_total: 7,
        },
      },
      {
        body: {
          success: true,
          outcome: 'completed',
          state: { status: 'completed', installation_mode: 'stepwise' },
          log_total: 9,
        },
      },
    ]);

    const { calls, callbacks } = collector();
    const monitor = new monitors.StepwiseMonitor(callbacks, {
      runTaskUrl: '/install/api/run-task.php',
      token: 'tok',
      busyRetryMs: 1,
      logOffset: 5,
    });

    monitor.start();
    await vi.waitFor(() => expect(calls.onComplete).toBeDefined());

    const logged = (calls.onLog ?? []).map((args) => args[0]);
    expect(logged, 'busy 응답의 못 본 꼬리가 화면으로 가지 않았다').toContain('환경 설정을 기록했습니다.');
    expect(logged).toContain('보안 키를 만들었습니다.');

    // 첫 요청은 이 창이 이미 그린 줄 수를 기준으로 보낸다.
    expect(sent[0].log_offset, '첫 요청이 자기 기준을 보내지 않았다').toBe(5);
    // 그 뒤로는 서버가 알려 준 총 줄 수로 다시 잡는다 — 자체 계수는 쓰지 않는다.
    expect(sent[1].log_offset, '서버가 알려 준 총 줄 수로 오프셋을 갱신하지 않았다').toBe(7);
  });

  it('busy 가 로그를 싣지 않아도 진행은 그대로다 (하위호환)', async () => {
    stubFetch([
      {
        body: {
          success: true,
          outcome: 'busy',
          message: '대기 중',
          state: { status: 'running', installation_mode: 'stepwise' },
        },
      },
      {
        body: {
          success: true,
          outcome: 'completed',
          state: { status: 'completed', installation_mode: 'stepwise' },
        },
      },
    ]);

    const { calls, callbacks } = collector();
    const monitor = new monitors.StepwiseMonitor(callbacks, {
      runTaskUrl: '/install/api/run-task.php',
      token: 'tok',
      busyRetryMs: 1,
    });

    monitor.start();
    await vi.waitFor(() => expect(calls.onComplete).toBeDefined());

    expect(calls.onBusy, 'busy 안내가 화면에 가지 않았다').toBeTruthy();
    expect(calls.onComplete, '완료까지 진행되지 않았다').toBeTruthy();
    expect(calls.onLog ?? []).toHaveLength(0);
  });
});
