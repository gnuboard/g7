/**
 * 확장 업데이트 창의 진행 시계와 응답이 끊긴 뒤 결과 확인(7.2.1).
 *
 * 1. 시계는 시작 시각·경과를 전역에 담고 1초마다 경과를 고친다. 끄면 멈춘다.
 * 2. 끊긴 뒤에는 그 확장을 다시 읽어 끝남·끝나지 않음·반영 안 됨·확인 못 함으로 가른다.
 *    필드가 없는 옛 응답(unfinished_update 없음)에서도 판본으로 가른다.
 *
 * @scenario-manifest tests/scenarios/extension-update-failure-rollback.yaml
 */

// e2e:allow 시계·판정은 순수 단위. 실제 끊김은 게이트웨이 시간 초과를 재현할 서버가 있어야 해 브라우저 실측으로 확인한다.

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
    formatElapsed,
    judgeReconcile,
    startExtensionUpdateClockHandler,
    stopExtensionUpdateClockHandler,
    reconcileExtensionUpdateHandler,
    captureExtensionUpdateFailureHandler,
    extractUpdateFailure,
    __resetExtensionUpdateClockForTests,
} from '../extensionUpdateHandler';

describe('확장 업데이트 — 진행 시계', () => {
    let globals: Record<string, unknown>;

    beforeEach(() => {
        vi.useFakeTimers();
        globals = {};
        (window as any).G7Core = {
            state: {
                setGlobal: (patch: Record<string, unknown>) => Object.assign(globals, patch),
                get: () => globals,
            },
        };
    });

    afterEach(() => {
        __resetExtensionUpdateClockForTests();
        vi.useRealTimers();
        delete (window as any).G7Core;
    });

    /**
     * @scenario source=module, path=web_update_input, unit=web_response
     * @effects ext_update_progress_shows_elapsed
     */
    it('켜면 시작 시각과 0:00 을 담고 1초마다 경과를 고친다', () => {
        startExtensionUpdateClockHandler();

        expect(globals.extUpdateBusy).toBe(true);
        expect(String(globals.extUpdateStartedAt)).toMatch(/^\d{2}:\d{2}:\d{2}$/);
        expect(globals.extUpdateElapsed).toBe('0:00');

        vi.advanceTimersByTime(75_000);
        expect(globals.extUpdateElapsed).toBe('1:15');
    });

    /**
     * @scenario source=module, path=web_update_input, unit=web_response
     * @effects ext_update_progress_shows_elapsed
     */
    it('끄면 바쁨을 풀고 더는 고치지 않는다', () => {
        startExtensionUpdateClockHandler();
        vi.advanceTimersByTime(3_000);
        stopExtensionUpdateClockHandler();
        const frozen = globals.extUpdateElapsed;

        vi.advanceTimersByTime(10_000);
        expect(globals.extUpdateBusy).toBe(false);
        expect(globals.extUpdateElapsed).toBe(frozen);
    });

    /**
     * @scenario source=module, path=web_update_input, unit=web_response
     * @effects ext_update_progress_shows_elapsed
     */
    it('경과를 분:초로 쓴다', () => {
        expect(formatElapsed(0)).toBe('0:00');
        expect(formatElapsed(9.7)).toBe('0:09');
        expect(formatElapsed(600)).toBe('10:00');
    });
});

describe('확장 업데이트 — 끊긴 뒤 결과 판정', () => {
    /**
     * @scenario source=module, path=web_update_failure, unit=web_response
     * @effects ext_update_disconnect_reconciles_with_server
     */
    it.each([
        ['새 판이면 끝남', { version: '1.2.0', status: 'active' }, 'completed'],
        ['끝나지 않은 업데이트가 남으면 끝나지 않음', { version: '1.1.0', unfinished_update: { from_version: '1.1.0', to_version: '1.2.0' } }, 'unfinished'],
        ['옛 응답 — 멈춘 업데이트 표지', { version: '1.1.0', stuck_update: true }, 'unfinished'],
        ['옛 응답 — 업데이트 중 상태', { version: '1.1.0', status: 'updating' }, 'unfinished'],
        ['옛 판 그대로면 반영 안 됨', { version: '1.1.0', status: 'active' }, 'not_applied'],
        ['행이 없으면 확인 못 함', null, 'unknown'],
    ])('%s', (_label, row, verdict) => {
        expect(judgeReconcile(row, '1.1.0', '1.2.0')).toBe(verdict);
    });
});

describe('확장 업데이트 — 끊긴 뒤 서버 확인', () => {
    let globals: Record<string, unknown>;
    let get: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        globals = {};
        get = vi.fn();
        (window as any).G7Core = {
            api: { get },
            state: {
                setGlobal: (patch: Record<string, unknown>) => Object.assign(globals, patch),
                get: () => globals,
            },
        };
    });

    afterEach(() => {
        delete (window as any).G7Core;
    });

    /**
     * @scenario source=module, path=web_update_failure, unit=web_response
     * @effects ext_update_disconnect_reconciles_with_server
     */
    it('그 확장을 다시 읽어 결과 창 상태에 끊김·판정을 담는다', async () => {
        get.mockResolvedValue({ data: { identifier: 'sirsoft-board', version: '1.2.0', status: 'active' } });

        const verdict = await reconcileExtensionUpdateHandler({
            params: { kind: 'module', identifier: 'sirsoft-board', name: '게시판', from_version: '1.1.0', to_version: '1.2.0', message: '응답 없음' },
        });

        expect(get).toHaveBeenCalledWith('/api/admin/modules/sirsoft-board');
        expect(verdict).toBe('completed');
        expect(globals.extUpdateBusy).toBe(false);
        expect(globals.extUpdateResult).toMatchObject({ kind: 'module', name: '게시판', disconnected: true, reconcile: 'completed', current_version: '1.2.0' });
    });

    /**
     * @scenario source=module, path=web_update_failure, unit=web_response
     * @effects ext_update_disconnect_reconciles_with_server
     */
    it('다시 읽기도 실패하면 확인 못 함으로 담는다', async () => {
        get.mockRejectedValue(new Error('network'));

        const verdict = await reconcileExtensionUpdateHandler({ params: { kind: 'lang_pack', identifier: '7', from_version: '1.0.0', to_version: '1.0.1' } });

        expect(get).toHaveBeenCalledWith('/api/admin/language-packs/7');
        expect(verdict).toBe('unknown');
        expect((globals.extUpdateResult as any).reconcile).toBe('unknown');
    });
});

describe('확장 업데이트 — 실패 결과 꺼내기', () => {
    let globals: Record<string, unknown>;

    beforeEach(() => {
        globals = {};
        (window as any).G7Core = { state: { setGlobal: (patch: Record<string, unknown>) => Object.assign(globals, patch) } };
    });

    afterEach(() => {
        delete (window as any).G7Core;
    });

    /**
     * @scenario source=module, path=web_update_failure, unit=web_response
     * @effects ext_update_failure_outcome_shown
     */
    it('오류 응답 본문 data 의 outcome·warnings·retry_command 를 전역에 담는다', () => {
        captureExtensionUpdateFailureHandler({}, { data: { error: { status: 422, message: '실패', errors: {}, data: { success: false, data: { outcome: 'restore_failed', warnings: ['w'], retry_command: 'php artisan module:update x --force' } } } } });

        expect(globals.extUpdateFailure).toEqual({ outcome: 'restore_failed', warnings: ['w'], retry_command: 'php artisan module:update x --force', recovered: null });
    });

    /**
     * 실측 회귀(브라우저): 엔진 오류 문맥은 실패 응답 본문의 data 를 `error.data` 로 바로 넘긴다
     * (`{status, message, errors, data: {outcome, …}, statusText}`). 종전 코드는 그 안의 data 를 한 번 더 찾아 늘 비었다.
     *
     * @scenario source=module, path=web_update_failure, unit=web_response
     * @effects ext_update_failure_outcome_shown
     */
    it('엔진 오류 문맥 모양(error.data 가 실패 필드)에서 꺼낸다', () => {
        captureExtensionUpdateFailureHandler({}, { data: { error: { status: 422, message: '실패', errors: { module_name: ['x'] }, data: { outcome: 'restore_failed', warnings: ['w'], retry_command: 'cmd' }, statusText: 'Unprocessable Entity' } } });

        expect(globals.extUpdateFailure).toEqual({ outcome: 'restore_failed', warnings: ['w'], retry_command: 'cmd', recovered: null });
    });

    /**
     * 실측 회귀: apiCall 의 onError 는 오류 객체의 `response` 에 응답 본문 전체를 싣는다(data 가 아니다).
     * 종전에는 data 만 봐서 브라우저에서 outcome·경고·다시 시도 명령이 하나도 보이지 않았다.
     *
     * @scenario source=module, path=web_update_failure, unit=web_response
     * @effects ext_update_failure_outcome_shown
     */
    it('apiCall onError 처럼 본문이 response 에 있어도 꺼낸다', () => {
        captureExtensionUpdateFailureHandler({}, { data: { error: { status: 422, message: '실패', statusText: 'Unprocessable Entity', response: { success: false, message: '실패', errors: { module_name: ['x'] }, data: { outcome: 'restore_failed', warnings: ['w'], retry_command: 'php artisan module:update x --force', recovered: null } } } } });

        expect(globals.extUpdateFailure).toEqual({ outcome: 'restore_failed', warnings: ['w'], retry_command: 'php artisan module:update x --force', recovered: null });
    });

    /**
     * 실측 회귀: `<parallel>` 안의 자식 액션에는 엔진이 오류 문맥을 넘기지 않는다 — 레이아웃이 `error="{{error}}"` 로 넘긴 값을 쓴다.
     *
     * @scenario source=module, path=web_update_failure, unit=web_response
     * @effects ext_update_failure_outcome_shown
     */
    it('레이아웃이 params.error 로 넘긴 오류에서 꺼낸다(문맥에 오류가 없어도)', () => {
        captureExtensionUpdateFailureHandler({ params: { error: { status: 422, response: { data: { outcome: 'kept_new', warnings: [], retry_command: 'php artisan module:update x --force' } } } } }, { data: {} });

        expect(globals.extUpdateFailure).toEqual({ outcome: 'kept_new', warnings: [], retry_command: 'php artisan module:update x --force', recovered: null });
    });

    /**
     * @scenario source=module, path=web_update_failure, unit=web_response
     * @effects ext_update_failure_outcome_shown
     */
    it('필드가 없는 옛 응답이면 비운다', () => {
        expect(extractUpdateFailure({ status: 422, message: '실패', errors: { module: ['x'] }, data: { success: false, message: '실패' } })).toBeNull();
        expect(extractUpdateFailure(undefined)).toBeNull();
    });
});
