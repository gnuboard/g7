// e2e:allow 실물 TemplateApp.handleRouteChange·renderTemplate·DynamicRenderer 로 화면 전환 뒤 props 바인딩을 잰다 — 브라우저 축은 tests/Playwright/specs/route-binding-after-navigation.spec.ts 와 게시판 SPA 이동 재실측이 덮는다
/**
 * @file TemplateApp.routeBindingCache.test.tsx
 * @description 화면을 옮긴 뒤 반복 밖 props 의 단순 경로(`{{route.*}}`·`{{query.*}}`·데이터소스 경로)가 새 화면 값으로 그려진다 (engine-v1.82.1)
 *
 * 정적 경로 영구 캐시(키 = 경로 글자, 30초)가 화면 전환 때 비워지지 않아, 글 보기(`/board/notice/226`) → SPA 로
 * 다른 게시판 목록에 가면 `<a href="/board/{{route.slug}}/write">` 가 `/board/notice/write` 로, 같은 레이아웃의
 * 다른 대상(클레임 A → B)에 가면 `{{claim.data.order_number}}` 가 A 값으로 30초 동안 남았다. text 는 늘 캐시를
 * 건너뛰므로 props(data-*) 로 잰다.
 *
 * 하네스: 실물 `TemplateApp.handleRouteChange`(레이아웃 로더만 대역) · 실물 `renderTemplate`·`DynamicRenderer`·`DataSourceManager`.
 *
 * @vitest-environment jsdom
 */

import { afterEach, describe, expect, it, vi } from 'vitest';
import React from 'react';
import { waitFor } from '@testing-library/react';

/** 응답 대역 — 잡아 두기를 켜면 풀 때까지 기다린다 */
const net = vi.hoisted(() => ({ hold: false, pending: [] as Array<() => void> }));

vi.mock('../../api/ApiClient', () => ({
    getApiClient: () => ({
        get: (url: string) => {
            const post = url.match(/^\/test\/posts\/([^/]+)$/)?.[1];
            const body = post ? { success: true, data: { id: post, order_number: `ORD-${post}` } } : { success: true, data: {} };
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

const passthrough = (tag: string) => ({ children, text, className, ...rest }: any) => {
    const domProps: Record<string, unknown> = { className };
    for (const [k, v] of Object.entries(rest)) {
        if (k.startsWith('data-')) domProps[k] = v;
    }

    return React.createElement(tag, domProps, children ?? text);
};

const span = (testid: string, props: Node): Node => ({ id: `s_${testid}`, type: 'basic', name: 'Span', props: { 'data-testid': testid, ...props }, text: '-' });

const LAYOUTS: Record<string, Node> = {
    // 게시판 글 보기 꼴 — 렌더 시점 href 가 route.slug·route.id 를 읽는다
    board_show: {
        layout_name: 'board_show',
        data_sources: [],
        components: [span('show', { 'data-write': '/board/{{route.slug}}/write', 'data-reply': '/board/{{route.slug}}/write?parent_id={{route.id}}' })],
    },
    // 게시판 목록 꼴 — 「글쓰기」 링크와 쿼리 탭
    board_index: {
        layout_name: 'board_index',
        data_sources: [],
        components: [span('index', { 'data-write': '/board/{{route.slug}}/write', 'data-tab': '{{query.tab}}' })],
    },
    // 클레임 상세 꼴 — 같은 레이아웃의 다른 대상, blocking 응답 값을 href 에 쓴다
    claim_detail: {
        layout_name: 'claim_detail',
        data_sources: [{ id: 'claim', type: 'api', endpoint: '/api/test/posts/{{route.id}}', auth_mode: 'required', loading_strategy: 'blocking' }],
        components: [span('claim', { 'data-order': '/admin/orders/{{claim.data.order_number}}' })],
    },
};

interface RouteSpec { path: string; layout: string; params?: Record<string, string>; query?: Record<string, string> }

vi.setConfig({ testTimeout: 30_000 });
const waitLong = <T,>(cb: () => T): Promise<T> => waitFor(cb, { timeout: 10_000, interval: 15 });
const attr = (testid: string, name: string): string | null => document.querySelector(`[data-testid="${testid}"]`)?.getAttribute(name) ?? null;

/**
 * 실물 엔진을 올리고 첫 화면으로 들어간다.
 *
 * @param first 첫 화면
 * @returns 화면 이동 함수
 */
async function mount(first: RouteSpec): Promise<(r: RouteSpec) => Promise<void>> {
    document.body.innerHTML = '<div id="app"></div>';
    net.hold = false;
    net.pending = [];
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
    (ComponentRegistry.getInstance() as any).registry = { Span: { component: passthrough('span'), metadata: {} } };
    engine.getActionDispatcher()!.setGlobalStateUpdater((updates: any, o?: { render?: boolean }) => app.setGlobalState(updates, o));
    (app as any).layoutLoader = {
        loadLayout: async (_templateId: string, layoutPath: string) => JSON.parse(JSON.stringify({ version: '1.0.0', ...LAYOUTS[layoutPath.replace(/^[^.]+\./, '')] })),
    };
    // Router.executeNavigation 과 같은 순서: pushState → 화면 전환
    const go = async (r: RouteSpec): Promise<void> => {
        const qs = new URLSearchParams(r.query ?? {}).toString();
        window.history.pushState({}, '', r.path + (qs ? `?${qs}` : ''));
        await (app as any).handleRouteChange({ path: r.path, layout: r.layout, params: r.params ?? {}, query: r.query ?? {} });
    };
    await go(first);

    return go;
}

describe('화면을 옮긴 뒤 반복 밖 props 의 단순 경로가 새 화면 값으로 그려진다 (engine-v1.82.1)', () => {
    afterEach(() => {
        net.hold = false;
        net.pending.splice(0).forEach((release) => release());
        try { engine.destroyTemplate(); } catch { /* 정리 */ }
        delete (window as any).__templateApp;
        window.history.replaceState({}, '', '/');
        delete (window as any).G7Core;
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    /**
     * 글 보기(notice/226) → 다른 게시판 목록(opinion). 종전: `/board/notice/write`.
     *
     * @scenario surface=cache
     * @effects route_query_bindings_follow_current_screen
     */
    it('글 보기 → 다른 게시판 목록: 글쓰기 href 가 새 게시판 slug 다', async () => {
        const go = await mount({ path: '/board/notice/226', layout: 'board_show', params: { slug: 'notice', id: '226' } });
        await waitLong(() => expect(attr('show', 'data-write')).toBe('/board/notice/write'));

        await go({ path: '/board/opinion', layout: 'board_index', params: { slug: 'opinion' }, query: { tab: 'new' } });
        await waitLong(() => expect(attr('index', 'data-tab')).toBe('new'));
        expect(attr('index', 'data-write')).toBe('/board/opinion/write');
    });

    /**
     * 같은 레이아웃의 다른 글(82 → 81). 종전: 답글 `parent_id=82`.
     *
     * @scenario surface=cache
     * @effects route_query_bindings_follow_current_screen
     */
    it('같은 게시판 다른 글: 답글 parent_id 가 새 글 id 다', async () => {
        const go = await mount({ path: '/board/b1/82', layout: 'board_show', params: { slug: 'b1', id: '82' } });
        await waitLong(() => expect(attr('show', 'data-reply')).toBe('/board/b1/write?parent_id=82'));

        await go({ path: '/board/b1/81', layout: 'board_show', params: { slug: 'b1', id: '81' } });
        await waitLong(() => expect(attr('show', 'data-reply')).toBe('/board/b1/write?parent_id=81'));
    });

    /**
     * 같은 레이아웃의 다른 대상(클레임 1 → 2) — blocking 데이터소스 경로. 종전: `/admin/orders/ORD-1`.
     *
     * @scenario surface=cache
     * @effects route_query_bindings_follow_current_screen
     */
    it('같은 상세 레이아웃의 다른 대상: blocking 응답 경로 href 가 새 대상 값이다', async () => {
        const go = await mount({ path: '/admin/claims/1', layout: 'claim_detail', params: { id: '1' } });
        await waitLong(() => expect(attr('claim', 'data-order')).toBe('/admin/orders/ORD-1'));

        await go({ path: '/admin/claims/2', layout: 'claim_detail', params: { id: '2' } });
        // 응답 반영·커밋을 넉넉히 기다린 뒤 판정 — 종전엔 이 시간 내내 ORD-1
        await new Promise((r) => setTimeout(r, 300));
        expect(attr('claim', 'data-order')).toBe('/admin/orders/ORD-2');
    });

    /**
     * 새 대상 응답을 기다리는 동안 떠나는 화면이 다시 그려져도(전환 상태·전역 상태 변경) 앞 대상 값이 캐시에 다시 차지 않는다.
     * 캐시를 응답 대기 앞에서만 비우면 이 사이에 `ORD-1` 이 다시 들어가 새 화면에 그려진다.
     *
     * @scenario surface=cache
     * @effects route_query_bindings_follow_current_screen
     */
    it('응답 대기 중 떠나는 화면이 다시 그려져도 새 대상 값으로 그린다', async () => {
        const go = await mount({ path: '/admin/claims/1', layout: 'claim_detail', params: { id: '1' } });
        await waitLong(() => expect(attr('claim', 'data-order')).toBe('/admin/orders/ORD-1'));

        net.hold = true;
        const run = go({ path: '/admin/claims/2', layout: 'claim_detail', params: { id: '2' } });
        await waitLong(() => expect(net.pending.length).toBeGreaterThan(0));
        // 떠나는 화면을 다시 그린다 — 앞 컨텍스트로 `claim.data.order_number` 를 다시 평가
        (window as any).__templateApp.setGlobalState({ tick: Date.now() });
        await new Promise((r) => setTimeout(r, 50));
        expect(attr('claim', 'data-order')).toBe('/admin/orders/ORD-1');

        net.hold = false;
        net.pending.splice(0).forEach((release) => release());
        await run;
        await new Promise((r) => setTimeout(r, 300));
        expect(attr('claim', 'data-order')).toBe('/admin/orders/ORD-2');
    });
});
