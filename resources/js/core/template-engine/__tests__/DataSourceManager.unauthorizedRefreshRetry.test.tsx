// e2e:allow 실물 ApiClient 401 인터셉터(토큰 갱신 → 재시도) · AuthManager.refreshToken · DataSourceManager · ErrorHandlingResolver · ActionDispatcher 로 데이터 요청 401 의 결말을 잰다(전송층만 axios 어댑터로 대신) — 브라우저 축은 총괄의 엔진 회귀 단계(관리자 목록 데이터 요청 401 을 매번 / 한 번만 대체)가 덮는다
/**
 * @file DataSourceManager.unauthorizedRefreshRetry.test.tsx
 * @description 데이터 요청이 401 을 받으면 ApiClient 가 토큰을 갱신해 다시 묻고, 그래도 401 이거나 갱신이 실패할 때만 로그인으로 간다
 *
 * 레이아웃에 401 처리가 없을 때의 설계상 기본 동작을 잠근다. 401 은 곧바로 로그인 이동이 아니다 — ApiClient 가 먼저 토큰을 갱신해 같은
 * 요청을 한 번 다시 보낸다. 다시 보낸 요청이 성공하면 화면은 그대로다(로그인 이동·토스트 없음). 다시 보낸 요청도 401 이면 오류 처리
 * 해석기의 시스템 기본값(`navigate {{auth.loginPath}}`)으로 로그인 화면에 간다. 갱신이 실패하면 ApiClient 의 onUnauthorized(세션 만료
 * 로그인 이동)가 맡는다. 첫 요청만 401 로 바꾸면 갱신과 재시도가 받아 화면이 머무는 것이 정상이다.
 *
 * @scenario-manifest tests/scenarios/data-source-unauthorized-refresh-retry.yaml
 *
 * @vitest-environment jsdom
 */

import { afterEach, describe, expect, it, vi } from 'vitest';
import React from 'react';

import { TemplateApp } from '../../TemplateApp';
import * as engine from '../../template-engine';
import { initializeG7CoreGlobals } from '../G7CoreGlobals';
import { ComponentRegistry } from '../ComponentRegistry';
import { AuthManager } from '../../auth/AuthManager';
import { getApiClient } from '../../api/ApiClient';

const passthrough = (tag: string) => ({ children, text, ...rest }: any) => {
    const domProps: Record<string, unknown> = {};
    for (const [k, v] of Object.entries(rest)) if (k === 'id') domProps[k] = v;

    return React.createElement(tag, domProps, children ?? text);
};

vi.setConfig({ testTimeout: 30_000 });
const settle = (ms: number): Promise<void> => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * 조건이 참이 될 때까지 기다린다(5초 상한 — 실패 판정용).
 *
 * @param check 조건
 */
async function until(check: () => boolean): Promise<void> {
    const end = Date.now() + 5_000;
    while (!check() && Date.now() < end) await settle(15);
}

type Scenario = { dataStatuses: number[]; refresh: 'ok' | 'fail' };

interface Run { navigate: ReturnType<typeof vi.fn>; unauthorized: () => number; seen: string[] }

/**
 * 관리자 목록 꼴 화면을 실물 엔진으로 연다. 전송층(axios 어댑터)만 대신해, 데이터 요청은 차례로 `dataStatuses` 를 돌려준다.
 *
 * @param scenario 데이터 요청 응답 차례와 토큰 갱신 결과
 * @returns 이동 함수 대역, onUnauthorized 호출 수, 나간 요청
 */
async function openList(scenario: Scenario): Promise<Run> {
    document.body.innerHTML = '<div id="app"></div>';
    const auth = AuthManager.getInstance() as any;
    vi.spyOn(auth, 'isAuthenticated').mockReturnValue(true);
    auth.state = { ...(auth.state || {}), type: 'admin', isAuthenticated: true };
    const api: any = getApiClient();
    api.setToken?.('token-a');
    const seen: string[] = [];
    const statuses = [...scenario.dataStatuses];
    const originalAdapter = api.client.defaults.adapter;
    api.client.defaults.adapter = async (config: any) => {
        const url = String(config.url);
        seen.push(`${config.method} ${url}`);
        const res = (status: number, data: any) => ({ data, status, statusText: String(status), headers: {}, config });
        const fail = (status: number) => Promise.reject(Object.assign(new Error(`Request failed with status code ${status}`), { response: res(status, { message: 'Unauthenticated.' }), config, isAxiosError: true }));
        if (url.includes('/auth/refresh')) {
            return scenario.refresh === 'ok' ? res(200, { success: true, data: { token: 'token-b' } }) : fail(401);
        }
        if (url.includes('/test/users')) {
            const status = statuses.shift() ?? 200;
            return status === 200 ? res(200, { success: true, data: { total: 3 } }) : fail(status);
        }

        return res(200, { success: true, data: {} });
    };
    cleanups.push(() => { api.client.defaults.adapter = originalAdapter; });
    let unauthorized = 0;
    api.setOnUnauthorized(() => { unauthorized++; });

    const app = new TemplateApp({ templateId: 'sirsoft-admin_basic', templateType: 'admin', locale: 'ko', debug: false } as any);
    (window as any).__templateApp = app;
    initializeG7CoreGlobals({
        getState: () => engine.getState() as any,
        transitionManager: { getIsPending: () => false, subscribe: () => () => {} },
        responsiveManager: {},
        webSocketManager: {} as any,
    } as any);
    await engine.initTemplateEngine({ templateId: 'sirsoft-admin_basic', locale: 'ko' } as any);
    (ComponentRegistry.getInstance() as any).registry = { Span: { component: passthrough('span'), metadata: {} } };
    const navigate = vi.fn();
    const dispatcher = engine.getActionDispatcher()!;
    dispatcher.setDefaultContext({ navigate });
    dispatcher.setGlobalStateUpdater((u: any, o?: any) => app.setGlobalState(u, o));
    (app as any).layoutLoader = {
        loadLayout: async () => ({
            version: '1.0.0',
            layout_name: 'admin_user_list',
            // 관리자 회원 목록과 같은 꼴 — 403 처리만 있고 401 처리는 레이아웃·템플릿 어디에도 없다
            data_sources: [{ id: 'users', type: 'api', endpoint: '/api/test/users', auth_required: true, errorHandling: { 403: { handler: 'showErrorPage', params: { target: 'content' } } } }],
            components: [{ id: 'total', type: 'basic', name: 'Span', props: { id: 'total' }, text: "{{users?.data?.total ?? 'none'}}" }],
        }),
    };
    window.history.replaceState({}, '', '/admin/users');
    void (app as any).handleRouteChange({ path: '/admin/users', layout: 'admin_user_list', params: {}, query: {} });

    return { navigate, unauthorized: () => unauthorized, seen };
}

const cleanups: Array<() => void> = [];

describe('데이터 요청 401 — 토큰 갱신·재시도 뒤에만 로그인 (설계상 기본)', () => {
    afterEach(() => {
        cleanups.splice(0).forEach((fn) => fn());
        try { engine.destroyTemplate(); } catch { /* 정리 */ }
        delete (window as any).__templateApp;
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    /**
     * @scenario retry=succeeds
     * @effects refreshed_retry_keeps_screen_without_login
     */
    it('첫 요청만 401 이면 토큰을 갱신해 다시 받고 화면은 그대로다(로그인 이동 없음)', async () => {
        const run = await openList({ dataStatuses: [401, 200], refresh: 'ok' });
        await until(() => document.getElementById('total')?.textContent === '3');
        await settle(100);

        expect(document.getElementById('total')?.textContent).toBe('3');
        expect(run.seen).toEqual(['get /test/users', 'post /admin/auth/refresh', 'get /test/users']);
        expect(run.navigate).not.toHaveBeenCalled();
        expect(run.unauthorized()).toBe(0);
    });

    /**
     * @scenario retry=still_unauthorized
     * @effects persistent_unauthorized_navigates_to_login
     */
    it('다시 받아도 401 이면 레이아웃에 401 처리가 없어도 시스템 기본값으로 로그인 화면에 간다', async () => {
        const run = await openList({ dataStatuses: [401, 401], refresh: 'ok' });
        await until(() => run.navigate.mock.calls.length > 0);

        expect(run.seen).toEqual(['get /test/users', 'post /admin/auth/refresh', 'get /test/users']);
        expect(run.navigate).toHaveBeenCalledTimes(1);
        expect(String(run.navigate.mock.calls[0][0])).toMatch(/login/);
    });

    /**
     * @scenario retry=refresh_fails
     * @effects refresh_failure_hands_over_to_session_expiry
     */
    it('토큰 갱신이 실패하면 다시 묻지 않고 ApiClient 의 세션 만료 처리(onUnauthorized)가 맡는다', async () => {
        const run = await openList({ dataStatuses: [401], refresh: 'fail' });
        await until(() => run.unauthorized() > 0);

        expect(run.seen).toEqual(['get /test/users', 'post /admin/auth/refresh']);
        expect(run.unauthorized()).toBe(1);
    });
});
