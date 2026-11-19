/**
 * 코어 업데이트 단계 요청의 대기 시간과 끊긴 뒤 이어 가기.
 *
 * 단계 요청을 화면 API 기본 제한(30초)으로 부르면, 그보다 긴 단계(사전 점검·ZIP 추출·
 * 백업)는 서버가 계속 도는데 화면만 끊겨 [이어서 진행] 에서 멈춘다. 그동안 사이트는
 * 점검 모드다. 여기서 잠그는 것은 둘이다:
 *
 * 1. 단계 요청은 서버가 알려 준 대기 시간(요청 수명 + 여유)만큼 기다린다.
 * 2. 그래도 응답이 끊기면 서버 상태를 다시 읽어 점유가 풀린 뒤 스스로 다시 부른다.
 *    권한·입력 오류처럼 기다려도 풀리지 않는 응답은 곧바로 멈춘다.
 */

// e2e:allow 반복 호출·대기 분기의 순수 단위. 화면 동선은 settings-core-update-tab.spec.ts 담당.

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
    startCoreUpdateHandler,
    __setCoreUpdateSleepForTests,
} from '../coreUpdateHandler';

/** 진행 중 상태를 만든다. */
function running(overrides: Record<string, unknown> = {}, claim: unknown = null): any {
    return {
        run: {
            status: 'running',
            next_step: 'preflight',
            completed_count: 0,
            updated_at: '2026-11-04T10:00:00+00:00',
            // 실제 상태 응답은 착수 때부터 진행 기록을 싣는다.
            logs: ['[10:00:00] 업데이트 시작'],
            claim,
            ...overrides,
        },
        time_budget: { request_seconds: 300, client_wait_seconds: 330 },
    };
}

/** 완료 상태를 만든다. */
function completed(): any {
    return {
        run: { status: 'completed', next_step: null, completed_count: 12, updated_at: '2026-11-04T10:20:00+00:00', claim: null },
        time_budget: { request_seconds: 300, client_wait_seconds: 330 },
    };
}

/** axios 시간 초과 오류 모양 (응답 없음). */
function timeoutError(): any {
    const error: any = new Error('timeout of 330000ms exceeded');
    error.code = 'ECONNABORTED';

    return error;
}

/** HTTP 오류 모양. */
function httpError(status: number, message = '거부되었습니다'): any {
    const error: any = new Error(`Request failed with status code ${status}`);
    error.response = { status, data: { message } };

    return error;
}

describe('코어 업데이트 단계 요청 — 대기 시간과 끊긴 뒤 이어 가기', () => {
    let get: ReturnType<typeof vi.fn>;
    let post: ReturnType<typeof vi.fn>;
    let toastError: ReturnType<typeof vi.fn>;
    let globals: Record<string, unknown>;
    let sleeps: number[];

    beforeEach(() => {
        get = vi.fn();
        post = vi.fn();
        toastError = vi.fn();
        globals = {};

        (window as any).G7Core = {
            api: { get, post },
            state: {
                setGlobal: (patch: Record<string, unknown>) => Object.assign(globals, patch),
                get: () => globals,
            },
            toast: { error: toastError },
        };

        sleeps = [];
        __setCoreUpdateSleepForTests(async (ms: number) => {
            sleeps.push(ms);
        });
    });

    afterEach(() => {
        __setCoreUpdateSleepForTests(null);
        delete (window as any).G7Core;
    });

    /**
     * @scenario request_time_limit=short, response=delivered
     * @effects the_screen_waits_as_long_as_the_server_step_may_run
     */
    it('단계 요청은 서버가 알려 준 대기 시간으로 부른다 (화면 API 기본 30초가 아니다)', async () => {
        post.mockImplementation(async (url: string) => {
            if (url.endsWith('/start')) {
                return { data: running() };
            }

            return { data: completed() };
        });

        await startCoreUpdateHandler({ params: {} });

        const advanceCall = post.mock.calls.find(([url]) => String(url).endsWith('/advance'));

        expect(advanceCall).toBeDefined();
        expect(advanceCall?.[2]).toEqual({ timeout: 330_000 });
    });

    /**
     * @scenario request_time_limit=short, response=cut_off
     * @effects a_cut_off_step_request_resumes_on_its_own
     */
    it('응답이 끊기면 점유가 풀릴 때까지 상태를 다시 읽고 스스로 다시 부른다', async () => {
        let advanceCalls = 0;

        post.mockImplementation(async (url: string) => {
            if (url.endsWith('/start')) {
                return { data: running() };
            }

            advanceCalls++;

            if (advanceCalls === 1) {
                throw timeoutError();
            }

            return { data: completed() };
        });

        // 서버는 그 단계를 아직 돌리는 중이다(점유 보유) → 두 번째 조회에서 끝나 점유가 풀린다.
        get
            .mockResolvedValueOnce({ data: running({}, { held: true, step: 'preflight', stale_after: 330 }) })
            .mockResolvedValueOnce({ data: running({ next_step: 'maintenance', completed_count: 1, updated_at: '2026-11-04T10:04:43+00:00' }, null) })
            .mockResolvedValue({ data: completed() });

        await startCoreUpdateHandler({ params: {} });

        expect(advanceCalls).toBe(2);
        expect(toastError).not.toHaveBeenCalled();
        expect((globals.coreUpdateStatus as any)?.run?.status).toBe('completed');
        expect(globals.coreUpdateBusy).toBe(false);
    });

    /**
     * @scenario request_time_limit=short, response=rejected
     * @effects a_permission_error_stops_without_retrying
     */
    it('권한 오류는 기다려도 풀리지 않으므로 다시 부르지 않고 사유를 보인다', async () => {
        let advanceCalls = 0;

        post.mockImplementation(async (url: string) => {
            if (url.endsWith('/start')) {
                return { data: running() };
            }

            advanceCalls++;
            throw httpError(403, '권한이 없습니다');
        });
        get.mockResolvedValue({ data: running() });

        await startCoreUpdateHandler({ params: {} });

        expect(advanceCalls).toBe(1);
        expect(toastError).toHaveBeenCalledWith('권한이 없습니다');
    });

    /**
     * @scenario request_time_limit=short, response=cut_off_repeatedly
     * @effects repeated_failures_without_progress_stop_with_a_reason
     */
    it('진행 없이 계속 끊기면 상한에서 멈추고 [이어서 진행] 을 남긴다', async () => {
        let advanceCalls = 0;

        post.mockImplementation(async (url: string) => {
            if (url.endsWith('/start')) {
                return { data: running() };
            }

            advanceCalls++;
            throw httpError(502, '게이트웨이 오류');
        });
        // 서버 기록이 한 번도 바뀌지 않는다(같은 갱신 시각).
        get.mockResolvedValue({ data: running() });

        await startCoreUpdateHandler({ params: {} });

        expect(advanceCalls).toBe(5);
        expect(toastError).toHaveBeenCalledTimes(1);
        expect((globals.coreUpdateStatus as any)?.run?.status).toBe('running');
    });

    /**
     * @scenario request_time_limit=short, response=cut_off_repeatedly
     * @effects repeated_failures_after_the_claim_still_back_off
     */
    it('점유 뒤 실패(409·500)가 반복되면 갱신 시각이 바뀌어도 진척으로 보지 않고, 매번 쉬었다가 상한에서 멈춘다', async () => {
        let advanceCalls = 0;
        let tick = 0;

        post.mockImplementation(async (url: string) => {
            if (url.endsWith('/start')) {
                return { data: running() };
            }

            advanceCalls++;
            throw httpError(409, '다른 업데이트가 진행 중입니다');
        });
        // 실제 서버는 점유와 해제가 모두 실행 기록을 고쳐 갱신 시각을 바꾼다 — 단계·완료 수·기록 줄은 그대로다.
        get.mockImplementation(async () => {
            tick++;

            return { data: running({ updated_at: `2026-11-04T10:00:${String(tick).padStart(2, '0')}+00:00`, logs: ['[10:00:00] 업데이트 시작'] }, null) };
        });

        await startCoreUpdateHandler({ params: {} });

        expect(advanceCalls).toBe(5);
        expect(toastError).toHaveBeenCalledTimes(1);
        expect(sleeps.length).toBeGreaterThanOrEqual(5);
    });

    /**
     * @scenario request_time_limit=short, response=cut_off_repeatedly
     * @effects repeated_failures_after_the_claim_still_back_off
     */
    it('진행 기록이 상한(500줄)에서 잘려 줄 수가 그대로여도, 누적 순번이 늘면 진척으로 본다', async () => {
        let advanceCalls = 0;
        let seq = 1000;
        const capped = Array.from({ length: 500 }, (_, i) => `[10:00:00] line ${i}`);

        post.mockImplementation(async (url: string) => {
            if (url.endsWith('/start')) {
                return { data: running({ logs: capped, log_seq: seq }) };
            }

            advanceCalls++;

            if (advanceCalls <= 7) {
                // 나눠 이어 가는 단계 — 같은 단계, 같은 완료 수, 기록은 500줄에서 잘려 줄 수가 같다.
                throw timeoutError();
            }

            return { data: completed() };
        });
        get.mockImplementation(async () => {
            seq++;

            return { data: running({ logs: capped, log_seq: seq }, null) };
        });

        await startCoreUpdateHandler({ params: {} });

        expect(advanceCalls).toBe(8);
        expect(toastError).not.toHaveBeenCalled();
    });
});
