// e2e:allow 설치 마법사는 미설치 사이트에서만 열려 설치된 사이트를 대상으로 하는 Playwright 스위트 밖이다 — 마무리 구간 새로고침은 빈 사본 브라우저 실측으로 확인한다
/**
 * 설치 마법사 Step 5 — 마무리 구간(완료 표식 뒤 설정·라우트 캐시 굽기·정적 게시)에 새로고침한 화면의 복원 경로를 잠근다.
 *
 * 서버(index.php)는 이 구간에 설치 소유 세션에만 Step 5 화면을 다시 연다. 그 화면은
 *  1. 상태가 completed 라도 마무리 작업이 남았으면(tasks_finished=false) 완료 화면과 .env 확정(finalize-env)을 미루고
 *  2. 마무리 작업이 끝나면(tasks_finished=true) 완료 화면을 열고 finalize-env 를 한 번만 부른다.
 *
 * installer.js 를 전역 스크립트로 평가한 뒤 DOMContentLoaded 처리기를 직접 불러 새로고침 직후 상태를 재현한다.
 */
import { afterEach, describe, expect, it, vi } from 'vitest';
import fs from 'fs';
import path from 'path';

const root = path.resolve(__dirname, '../../../..');
const INSTALLER_SOURCE = fs.readFileSync(path.join(root, 'public/install/assets/js/installer.js'), 'utf8');

/**
 * 새로고침 직후의 Step 5 화면을 띄운다.
 *
 * @param stateRef 상태 조회 응답을 돌려줄 참조(테스트가 도중에 바꾼다)
 */
function bootReloadedStep5(stateRef: { current: Record<string, unknown> }) {
    document.body.innerHTML = `
        <h1 id="installer-title"></h1>
        <div id="install-warning"></div>
        <div id="installation-start-section"></div>
        <div id="installation-progress-card" style="display:none"></div>
        <div id="installation-card-body"></div>
        <div id="task-list"></div>
        <div id="log-container"></div>
        <p id="current-task"></p>
        <div id="completion-section" class="result-section hidden"></div>`;

    const w = window as any;
    w.INSTALLER_BASE_URL = '/install';
    w.CURRENT_STEP = '5';
    w.INSTALLER_LANG = {};
    w.INSTALLER_TASKS = [];
    w.INSTALLER_TASK_GROUPS = [];
    w.INSTALLER_CSRF = 'tok';
    (Element.prototype as any).scrollIntoView = vi.fn();

    const calls: Array<{ url: string; init?: RequestInit }> = [];
    w.fetch = vi.fn(async (url: string, init?: RequestInit) => {
        calls.push({ url: String(url), init });
        if (String(url).includes('finalize-env.php')) {
            return { ok: true, json: async () => ({ accepted: true }) };
        }

        return { ok: true, json: async () => ({ ...stateRef.current }) };
    });

    const handlers: Array<() => void> = [];
    const original = document.addEventListener.bind(document);
    const spy = vi.spyOn(document, 'addEventListener').mockImplementation(((type: string, cb: any, opts?: any) => {
        if (type === 'DOMContentLoaded') {
            handlers.push(cb);

            return;
        }
        original(type, cb, opts);
    }) as typeof document.addEventListener);
    new Function(INSTALLER_SOURCE)();
    spy.mockRestore();
    handlers.forEach((h) => h());

    return { calls };
}

const finalizeCalls = (calls: Array<{ url: string }>) => calls.filter((c) => c.url.includes('/api/finalize-env.php'));
const completionVisible = () => !document.getElementById('completion-section')!.classList.contains('hidden');

describe('설치 마무리 구간 새로고침 — 완료 화면과 .env 확정', () => {
    afterEach(() => {
        vi.useRealTimers();
        document.body.innerHTML = '';
        vi.restoreAllMocks();
    });

    /**
     * @effects installer_reload_in_finalize_window_finalizes_once_after_final_tasks
     */
    it('마무리 작업이 끝날 때까지 기다린 뒤 완료 화면을 열고 finalize-env 를 한 번 부른다', async () => {
        vi.useFakeTimers();
        const stateRef = {
            current: { status: 'completed', current_step: 5, tasks_finished: false, last_updated: 't1', logs: [], log_total: 0 } as Record<string, unknown>,
        };
        const { calls } = bootReloadedStep5(stateRef);

        // 마무리 작업 진행 중 — 상태가 계속 갱신되는 동안은 완료 처리하지 않는다
        await vi.advanceTimersByTimeAsync(0);
        for (const tick of ['t2', 't3']) {
            stateRef.current = { ...stateRef.current, last_updated: tick };
            await vi.advanceTimersByTimeAsync(1000);
            expect(completionVisible()).toBe(false);
            expect(finalizeCalls(calls)).toHaveLength(0);
        }

        // 마무리 작업 끝 → 완료 화면 + finalize-env 1회
        stateRef.current = { ...stateRef.current, tasks_finished: true, last_updated: 't4' };
        await vi.advanceTimersByTimeAsync(1000);
        await vi.advanceTimersByTimeAsync(200);

        expect(completionVisible()).toBe(true);
        const finalize = finalizeCalls(calls);
        expect(finalize).toHaveLength(1);
        expect(finalize[0].url).toBe(`${window.location.origin}/install/api/finalize-env.php`);
        expect(finalize[0].init?.method).toBe('POST');
        expect((finalize[0].init?.headers as Record<string, string>)['X-CSRF-Token']).toBe('tok');

        // 이후 시간이 흘러도 다시 부르지 않는다
        await vi.advanceTimersByTimeAsync(5000);
        expect(finalizeCalls(calls)).toHaveLength(1);
    });

    /**
     * @effects installer_reload_in_finalize_window_finalizes_once_after_final_tasks
     */
    it('마무리 작업이 이미 끝난 뒤(.env 확정 전) 새로고침하면 바로 완료 화면과 finalize-env 1회', async () => {
        vi.useFakeTimers();
        const stateRef = {
            current: { status: 'completed', current_step: 5, tasks_finished: true, last_updated: 't9', logs: [], log_total: 0 } as Record<string, unknown>,
        };
        const { calls } = bootReloadedStep5(stateRef);

        await vi.advanceTimersByTimeAsync(200);

        expect(completionVisible()).toBe(true);
        expect(finalizeCalls(calls)).toHaveLength(1);
    });
});
