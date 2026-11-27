/**
 * 코어 업데이트 진행 중 화면 알림 플래그와 선택 충돌 (7.2.1).
 *
 * 터미널은 응답이 끊기거나 자동으로 이어 가지 못하면 그 사실을 바로 찍는다. 웹 화면은 핸들러가 전역 플래그를
 * 세워 화면이 그린다. 여기서 잠그는 것은 셋이다:
 *
 * 1. 응답이 끊겨 서버 상태를 다시 읽는 동안 `coreUpdateReconnecting` 이 참이고, 끝나면 거짓으로 돌아온다.
 * 2. 연달아 실패하거나 자동 이어받기 상한에 닿으면 `coreUpdateAutoStopped` 가 참이 되고, 다시 몰면 거짓이 된다.
 * 3. 선택 제출이 409(다른 화면이 먼저 답함)면 상태를 다시 읽어 낡은 선택 창을 닫고 고르던 값을 버린다.
 */

// e2e:allow 전역 플래그·409 분기의 순수 단위. 화면 동선은 settings-core-update-tab.spec.ts 담당.

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
    autoResumeCoreUpdateHandler,
    resumeCoreUpdateHandler,
    submitCoreUpdateSelectionHandler,
    __setCoreUpdateSleepForTests,
} from '../coreUpdateHandler';

/** 진행 중 상태를 만든다. */
function running(overrides: Record<string, unknown> = {}): any {
    return {
        run: { status: 'running', next_step: 'apply', completed_count: 5, log_seq: 10, claim: null, ...overrides },
        time_budget: { request_seconds: 300, client_wait_seconds: 330 },
    };
}

/** 완료 상태를 만든다. */
function completed(): any {
    return { run: { status: 'completed', next_step: null, completed_count: 12, log_seq: 30, claim: null } };
}

/** 선택을 기다리는 상태. */
function awaiting(): any {
    return running({
        next_step: 'bundled',
        awaiting: { kind: 'bundled_updates', items: [{ key: 'module:sirsoft-board', type: 'module', identifier: 'sirsoft-board' }] },
    });
}

/** HTTP 오류 모양. */
function httpError(status: number): any {
    const error: any = new Error(`Request failed with status code ${status}`);
    error.response = { status, data: { message: '충돌' } };

    return error;
}

/** 응답 없는 시간 초과. */
function timeoutError(): any {
    const error: any = new Error('timeout of 330000ms exceeded');
    error.code = 'ECONNABORTED';

    return error;
}

describe('코어 업데이트 — 끊김 대기·자동 멈춤 플래그와 선택 충돌', () => {
    let get: ReturnType<typeof vi.fn>;
    let post: ReturnType<typeof vi.fn>;
    let toastInfo: ReturnType<typeof vi.fn>;
    let globals: Record<string, unknown>;
    let history: Array<Record<string, unknown>>;

    beforeEach(() => {
        get = vi.fn();
        post = vi.fn();
        toastInfo = vi.fn();
        globals = {};
        history = [];

        (window as any).G7Core = {
            api: { get, post },
            state: {
                setGlobal: (patch: Record<string, unknown>) => {
                    history.push({ ...patch });
                    Object.assign(globals, patch);
                },
                get: () => globals,
            },
            toast: { error: vi.fn(), info: toastInfo },
        };

        __setCoreUpdateSleepForTests(async () => undefined);
    });

    afterEach(() => {
        __setCoreUpdateSleepForTests(null);
        delete (window as any).G7Core;
    });

    /**
     * @scenario run_state=running, concurrency=stale_claim
     * @effects a_cut_off_response_is_shown_while_waiting
     */
    it('응답이 끊겨 서버 상태를 다시 읽는 동안 끊김 대기 플래그가 서고, 다시 이어지면 내려간다', async () => {
        let advanceCalls = 0;
        let reconnectingDuringStatus = false;

        post.mockImplementation(async () => {
            advanceCalls++;
            if (advanceCalls === 1) {
                throw timeoutError();
            }

            return { data: completed() };
        });
        get.mockImplementation(async () => {
            reconnectingDuringStatus = reconnectingDuringStatus || globals.coreUpdateReconnecting === true;

            return { data: running({ log_seq: 11 }) };
        });

        globals.coreUpdateStatus = running();
        await resumeCoreUpdateHandler();

        expect(reconnectingDuringStatus, '상태를 다시 읽는 동안 끊김 대기 플래그가 서지 않았다').toBe(true);
        expect(globals.coreUpdateReconnecting).toBe(false);
        expect(globals.coreUpdateAutoStopped).toBe(false);
    });

    /**
     * @scenario run_state=running, concurrency=stale_claim
     * @effects a_cut_off_response_is_shown_while_waiting
     */
    it('같은 자리에서 연달아 실패하면 자동 멈춤 플래그가 서고, 다시 몰기 시작하면 내려간다', async () => {
        post.mockRejectedValue(httpError(500));
        get.mockResolvedValue({ data: running() });

        await resumeCoreUpdateHandler();

        expect(globals.coreUpdateAutoStopped).toBe(true);
        expect(globals.coreUpdateReconnecting).toBe(false);

        // 운영자가 [이어서 진행] 을 누르면 다시 몰기 시작하면서 안내를 내린다.
        post.mockReset();
        post.mockResolvedValue({ data: completed() });
        await resumeCoreUpdateHandler();

        expect(globals.coreUpdateAutoStopped).toBe(false);
    });

    /**
     * @scenario run_state=running, concurrency=single
     * @effects a_cut_off_response_is_shown_while_waiting
     */
    it('자동 이어받기 상한에 닿으면 자동 멈춤 플래그를 세우고 더 부르지 않는다', async () => {
        get.mockResolvedValue({ data: running() });
        // 매번 멈춘 채 돌아오게 한다 — 같은 자리 실패 5회로 끝난다.
        post.mockRejectedValue(httpError(500));

        for (let i = 0; i < 3; i++) {
            await autoResumeCoreUpdateHandler();
        }

        const before = post.mock.calls.length;
        globals.coreUpdateAutoStopped = false;
        await autoResumeCoreUpdateHandler();

        expect(post.mock.calls.length, '상한 뒤에도 자동으로 다시 불렀다').toBe(before);
        expect(globals.coreUpdateAutoStopped).toBe(true);
    });

    /**
     * @scenario run_state=running, concurrency=duplicate_request
     * @effects a_stale_selection_is_reloaded_on_conflict
     */
    it('선택 제출이 409 면 상태를 다시 읽어 낡은 선택 창을 닫고 고르던 값을 버린다 (진행은 먼저 답한 화면이 몬다)', async () => {
        globals.coreUpdateStatus = awaiting();
        globals.coreUpdateChoices = { 'module:sirsoft-board': { selected: false } };

        post.mockImplementation(async (url: string) => {
            if (String(url).endsWith('/selection')) {
                throw httpError(409);
            }

            return { data: completed() };
        });
        get.mockResolvedValue({ data: running({ next_step: 'bundled', awaiting: null }) });

        await submitCoreUpdateSelectionHandler();

        expect(get).toHaveBeenCalled();
        expect((globals.coreUpdateStatus as any).run.awaiting).toBeNull();
        expect(globals.coreUpdateChoices).toBeNull();
        expect(toastInfo).toHaveBeenCalledTimes(1);
        // 이 화면은 단계를 몰지 않는다 — 다른 화면과 겹쳐 부르면 점유 충돌만 난다.
        expect(post.mock.calls.filter(([url]) => String(url).endsWith('/advance'))).toHaveLength(0);
        expect(globals.coreUpdateBusy).toBe(false);
    });
});
