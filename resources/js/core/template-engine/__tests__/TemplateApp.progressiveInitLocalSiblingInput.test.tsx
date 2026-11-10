// e2e:allow 실제 엔진 구조(TemplateApp.handleRouteChange·refetchDataSource·updateQueryParams·renderTemplate·DynamicRenderer·ActionDispatcher)에 하네스 레이아웃을 올려 응답 반영 커밋마다 입력칸 값을 잰다 — 브라우저 확인은 내 정보 수정 비밀번호 확인 칸에서 본인 정보 응답을 입력 도중에 풀어 본다
/**
 * @file TemplateApp.progressiveInitLocalSiblingInput.test.tsx
 * @description initLocal 응답이 입력 도중 도착해도 같은 화면의 다른 `_local` 칸에 친 글자가 지워지지 않는다 (engine-v1.93.0)
 *
 * 입력칸의 `set-state target="local"` 은 저장소 A 에 쓰고 B 에는 render:false 로 미러한다. 그래서 데이터 컨텍스트에 남은
 * B 사본(`currentDataContext._global._local`)은 마지막 루트 다시 그리기 때 값이다. initLocal 을 실은 갱신
 * (`updateTemplateData({ id, _localInit })`)이 `_global` 없이 오면 그 늦은 사본이 화면 `_local` 이 되고, 루트 렌더러는
 * 「아직 반영 안 한 _localInit」 렌더에서 저장소 A 를 빼고 그 사본만 쓴다(engine-v1.41.0). 그 한 번의 커밋 동안 제어 입력칸이
 * 사본의 값(레이아웃 init 의 빈 값)으로 그려지고, 그때 친 키는 빈 칸에 붙어 앞 글자를 잃는다. 고친 뒤에는 페이지 루트가 그
 * 렌더에서 렌더 시점의 저장소 B(`TemplateApp.getGlobalState()._local`)를 쓴다 — 응답 처리 뒤, 커밋 전에 친 키도 담긴다.
 *
 * 하네스: 실물 TemplateApp 경로 전환·다시 받기·쿼리 갱신, 실물 renderTemplate·DynamicRenderer·ActionDispatcher. API 응답은
 * 대역이 잡아 두었다가 입력 뒤에 푼다. 입력칸 대역은 커밋마다 받은 value 를 기록한다(화면에 실제로 그려진 값).
 *
 * @scenario-manifest tests/scenarios/progressive-initlocal-sibling-input.yaml
 *
 * @vitest-environment jsdom
 */

import { afterEach, describe, expect, it, vi } from 'vitest';
import React from 'react';
import { fireEvent, waitFor } from '@testing-library/react';

/** API 대역 — 잡아 두기를 켜면 풀 때까지 기다린다 */
const net = vi.hoisted(() => ({
    hold: false,
    pending: [] as Array<() => void>,
    meName: '서버 이름',
}));

vi.mock('../../api/ApiClient', () => ({
    getApiClient: () => ({
        get: (url: string) => {
            let body: any = { success: true, data: {} };
            if (url === '/test/me') body = { success: true, data: { name: net.meName, email: 'me@example.test' } };
            if (url === '/test/menus') body = { success: true, data: ['m1'] };
            if (!net.hold) return Promise.resolve(body);

            return new Promise((resolve) => { net.pending.push(() => resolve(body)); });
        },
        post: vi.fn().mockResolvedValue({}),
        removeToken: vi.fn(),
        setToken: vi.fn(),
        getToken: vi.fn().mockReturnValue('t'),
        setOnUnauthorized: vi.fn(),
    }),
}));

import { TemplateApp } from '../../TemplateApp';
import * as engine from '../../template-engine';
import { initializeG7CoreGlobals } from '../G7CoreGlobals';
import { ComponentRegistry } from '../ComponentRegistry';
import { AuthManager } from '../../auth/AuthManager';

type Node = Record<string, any>;

/** 커밋마다 입력칸이 받은 value — testid 별 */
const committed: Record<string, string[]> = {};

const passthrough = (tag: string) => ({ children, text, className, ...rest }: any) => {
    const domProps: Record<string, unknown> = { className };
    for (const [k, v] of Object.entries(rest)) {
        if (k.startsWith('data-') || ['id', 'role'].includes(k)) domProps[k] = v;
    }

    return React.createElement(tag, domProps, children ?? text);
};

/**
 * 제어 입력칸 대역 — 화면에 그린 value 를 커밋마다 기록한다(사용자가 그 순간 키를 치면 이 값에 붙는다).
 *
 * @param props value·onChange·data-testid
 * @returns 입력칸
 */
function RecordingInput({ value, onChange, ...rest }: any) {
    const id = rest['data-testid'] as string;
    React.useLayoutEffect(() => {
        (committed[id] ??= []).push(String(value ?? ''));
    });

    return React.createElement('input', { 'data-testid': id, value: value ?? '', onChange });
}

const src = (id: string, endpoint: string, extra: Node = {}): Node => ({ id, type: 'api', endpoint, auth_mode: 'required', loading_strategy: 'progressive', ...extra });
const span = (testid: string, text: string): Node => ({ id: `s_${testid}`, type: 'basic', name: 'Span', props: { 'data-testid': testid }, text });
/** 내 정보 수정의 비밀번호 확인 칸과 같은 꼴 — 값은 `_local` 의 형제 키, 바뀌면 `set-state target="local"` */
const input = (testid: string, key: string): Node => ({
    id: `in_${testid}`,
    type: 'basic',
    name: 'Input',
    props: { 'data-testid': testid, value: `{{_local.${key} ?? ''}}` },
    actions: [{ type: 'change', handler: 'setState', params: { target: 'local', [key]: '{{$event.target.value}}' } }],
});

const LAYOUTS: Record<string, Node> = {
    home: { layout_name: 'home', data_sources: [src('menu', '/api/test/menus')], components: [{ id: 'root_h', type: 'basic', name: 'Div', props: { 'data-testid': 'root-h' }, children: [span('h', 'home')] }] },
    // 내 정보 수정 꼴 — progressive 본인 정보가 initLocal 로 폼 초깃값을 채우고, init 이 비밀번호 확인 칸을 비운다
    profile_edit: {
        layout_name: 'profile_edit',
        data_sources: [src('user', '/api/test/me', { initLocal: 'profileEditForm', refetchOnMount: true })],
        init_actions: [{ handler: 'setState', params: { target: 'local', isPasswordVerified: false, verifyPassword: '', memo: '' } }],
        components: [{
            id: 'root_p',
            type: 'basic',
            name: 'Div',
            props: { 'data-testid': 'root-p' },
            children: [
                input('pw', 'verifyPassword'),
                input('memo', 'memo'),
                span('form-name', "{{'name:' + (_local.profileEditForm?.name ?? 'none')}}"),
            ],
        }],
    },
};

const ROUTES = {
    home: { path: '/mypage', layout: 'home' },
    edit: { path: '/mypage/profile/edit', layout: 'profile_edit' },
    editTab: { path: '/mypage/profile/edit', layout: 'profile_edit', query: { tab: '2' } },
};

const settle = (ms: number): Promise<void> => new Promise((r) => setTimeout(r, ms));
const waitLong = <T,>(cb: () => T): Promise<T> => waitFor(cb, { timeout: 10_000, interval: 15 });
vi.setConfig({ testTimeout: 60_000 });
const text = (testid: string): string | null => document.querySelector(`[data-testid="${testid}"]`)?.textContent ?? null;
const box = (testid: string): HTMLInputElement => document.querySelector(`[data-testid="${testid}"]`) as HTMLInputElement;
const liveB = (app: any): Record<string, any> => app.getGlobalState()._local ?? {};
/** 실제 writer(입력 이벤트 → setState 액션)로 친다 */
const type = async (testid: string, value: string): Promise<void> => {
    fireEvent.change(box(testid), { target: { value } });
    await waitLong(() => expect(box(testid).value).toBe(value));
    await settle(20);
};
const releaseAll = (): void => {
    net.hold = false;
    const p = net.pending;
    net.pending = [];
    p.forEach((release) => release());
};

async function mount(): Promise<{ app: any; go: (r: { path: string; layout: string; query?: Record<string, string> }) => Promise<void> }> {
    document.body.innerHTML = '<div id="app"></div>';
    net.hold = false;
    net.pending = [];
    net.meName = '서버 이름';
    for (const k of Object.keys(committed)) delete committed[k];
    vi.spyOn(AuthManager.getInstance(), 'isAuthenticated').mockReturnValue(true);
    const app = new TemplateApp({ templateId: 'sirsoft-basic', templateType: 'user', locale: 'ko', debug: false } as any);
    (window as any).__templateApp = app;
    initializeG7CoreGlobals({
        getState: () => engine.getState() as any,
        transitionManager: { getIsPending: () => false, subscribe: () => () => {} },
        responsiveManager: {},
        webSocketManager: {} as any,
    } as any);
    await engine.initTemplateEngine({ templateId: 'sirsoft-basic', locale: 'ko' } as any);
    (ComponentRegistry.getInstance() as any).registry = {
        Div: { component: passthrough('div'), metadata: {} },
        Span: { component: passthrough('span'), metadata: {} },
        Input: { component: RecordingInput, metadata: {} },
    };
    engine.getActionDispatcher()!.setGlobalStateUpdater((updates: any, o?: { render?: boolean }) => app.setGlobalState(updates, o));
    (app as any).layoutLoader = {
        loadLayout: async (_templateId: string, layoutPath: string) => JSON.parse(JSON.stringify({ version: '1.0.0', ...LAYOUTS[layoutPath.replace(/^[^.]+\./, '')] })),
    };
    const go = async (r: { path: string; layout: string; query?: Record<string, string> }): Promise<void> => {
        const qs = new URLSearchParams(r.query ?? {}).toString();
        window.history.pushState({}, '', r.path + (qs ? `?${qs}` : ''));
        await (app as any).handleRouteChange({ path: r.path, layout: r.layout, params: {}, query: r.query ?? {} });
    };
    await go(ROUTES.home);
    await waitLong(() => expect(text('h')).toBe('home'));

    return { app, go };
}

/** 친 뒤에 그려진 값 가운데 친 값과 다른 것 — 응답 반영 커밋이 칸을 다른 값으로 그렸는지 본다 */
const driftAfter = (testid: string, from: number, typed: string): string[] => (committed[testid] ?? []).slice(from).filter((v) => v !== typed);

describe('[case:state-global-progressive-initlocal-sibling-input] initLocal 응답이 입력 도중 와도 형제 `_local` 칸의 글자를 지우지 않는다 (engine-v1.93.0)', () => {
    afterEach(async () => {
        releaseAll();
        (window as any).__templateApp?.cancelEarlySpinnerTimer?.();
        try { engine.destroyTemplate(); } catch { /* 정리 */ }
        delete (window as any).__templateApp;
        window.history.replaceState({}, '', '/');
        delete (window as any).G7Core;
        (window as any).__g7PendingLocalState = null;
        (window as any).__g7ForcedLocalFields = undefined;
        (window as any).__g7SetLocalOverrideKeys = undefined;
        (window as any).__g7LastSetLocalSnapshot = undefined;
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    /**
     * 마이페이지 → 내 정보 수정(SPA). 본인 정보 응답을 잡아 둔 채 비밀번호 확인 칸에 치고 응답을 푼다.
     * 종전: 응답 반영 커밋 한 번이 칸을 빈 값(init 값)으로 그렸다 — 그 순간 친 키는 빈 칸에 붙는다.
     *
     * @scenario case=spa_entry_progressive_response_mid_typing
     * @effects sibling_input_value_kept_on_initlocal_commit, initlocal_target_still_applied
     */
    it('SPA 진입 뒤 progressive 응답이 입력 도중 와도 비밀번호 확인 칸은 친 값 그대로 그려진다', async () => {
        const { app, go } = await mount();
        net.hold = true;
        const run = go(ROUTES.edit);
        await waitLong(() => expect(net.pending.length).toBeGreaterThan(0));
        await waitLong(() => expect(box('pw')).not.toBeNull());

        await type('pw', 'abcde');
        const from = committed.pw.length;
        releaseAll();
        await run;
        await waitLong(() => expect(text('form-name')).toBe('name:서버 이름'));
        await settle(60);

        expect(driftAfter('pw', from, 'abcde')).toEqual([]);
        expect(box('pw').value).toBe('abcde');
        expect(liveB(app).verifyPassword).toBe('abcde');
        expect(liveB(app).profileEditForm?.name).toBe('서버 이름');
    });

    /**
     * 같은 화면에서 다시 받기(`refetchDataSource`) — 응답이 칸 두 개를 친 뒤 온다.
     *
     * @scenario case=refetch_response_mid_typing
     * @effects sibling_input_value_kept_on_initlocal_commit, initlocal_target_still_applied
     */
    it('다시 받기 응답이 입력 도중 와도 형제 칸 두 개 모두 친 값 그대로다', async () => {
        const { app, go } = await mount();
        await go(ROUTES.edit);
        await waitLong(() => expect(text('form-name')).toBe('name:서버 이름'));

        net.hold = true;
        net.meName = '다시 받은 이름';
        const refetch = app.refetchDataSource('user');
        await waitLong(() => expect(net.pending.length).toBeGreaterThan(0));
        await type('pw', 'secret-1');
        await type('memo', '메모 입력');
        const fromPw = committed.pw.length;
        const fromMemo = committed.memo.length;
        releaseAll();
        await refetch;
        await waitLong(() => expect(text('form-name')).toBe('name:다시 받은 이름'));
        await settle(60);

        expect(driftAfter('pw', fromPw, 'secret-1')).toEqual([]);
        expect(driftAfter('memo', fromMemo, '메모 입력')).toEqual([]);
        expect(liveB(app).verifyPassword).toBe('secret-1');
        expect(liveB(app).memo).toBe('메모 입력');
    });

    /**
     * 같은 화면 쿼리 갱신(탭 전환) — initLocal 대상(폼)은 종전 계약대로 서버 값으로 되돌리고, 형제 칸은 지킨다.
     *
     * @scenario case=query_update_response_mid_typing
     * @effects sibling_input_value_kept_on_initlocal_commit, initlocal_target_still_applied
     */
    it('쿼리 갱신 재조회 응답이 입력 도중 와도 형제 칸은 친 값 그대로고 폼은 서버 값으로 바뀐다', async () => {
        const { app, go } = await mount();
        await go(ROUTES.edit);
        await waitLong(() => expect(text('form-name')).toBe('name:서버 이름'));

        net.hold = true;
        net.meName = '탭 이름';
        window.history.pushState({}, '', '/mypage/profile/edit?tab=2');
        const update = app.updateQueryParams('/mypage/profile/edit?tab=2');
        await waitLong(() => expect(net.pending.length).toBeGreaterThan(0));
        await type('pw', 'tab-typed');
        const from = committed.pw.length;
        releaseAll();
        await update;
        await waitLong(() => expect(text('form-name')).toBe('name:탭 이름'));
        await settle(60);

        expect(driftAfter('pw', from, 'tab-typed')).toEqual([]);
        expect(liveB(app).verifyPassword).toBe('tab-typed');
    });

    /**
     * 응답을 처리해 `_localInit` 을 데이터 컨텍스트에 실은 뒤, React 가 그 갱신을 커밋하기 전에 한 글자 더 친다.
     * 응답 처리 시점에 B 를 복사해 싣는 방식으로는 이 키가 사본에 없어 그 커밋이 칸을 앞 값으로 그린다 — 렌더 시점의 B 를 읽어야 한다.
     *
     * @scenario case=keystroke_between_response_and_commit
     * @effects sibling_input_value_kept_on_initlocal_commit, initlocal_target_still_applied
     */
    it('응답 처리와 커밋 사이에 친 글자도 응답 반영 커밋에서 지워지지 않는다', async () => {
        const { app, go } = await mount();
        net.hold = true;
        const run = go(ROUTES.edit);
        await waitLong(() => expect(net.pending.length).toBeGreaterThan(0));
        await waitLong(() => expect(box('pw')).not.toBeNull());
        await type('pw', 'abcde');

        const from = committed.pw.length;
        releaseAll();
        // 응답이 처리돼 `_localInit` 이 실릴 때까지 마이크로태스크만 돌린다(React 의 전환 렌더는 아직 커밋되지 않는다)
        for (let i = 0; i < 500 && !engine.getState().currentDataContext?._localInit?.profileEditForm; i++) {
            await Promise.resolve();
        }
        expect(engine.getState().currentDataContext._localInit?.profileEditForm?.name).toBe('서버 이름');
        fireEvent.change(box('pw'), { target: { value: 'abcdef' } });
        await run;
        await waitLong(() => expect(text('form-name')).toBe('name:서버 이름'));
        await settle(60);

        expect((committed.pw ?? []).slice(from).filter((v) => v !== 'abcde' && v !== 'abcdef')).toEqual([]);
        expect(box('pw').value).toBe('abcdef');
        expect(liveB(app).verifyPassword).toBe('abcdef');
    });
});
