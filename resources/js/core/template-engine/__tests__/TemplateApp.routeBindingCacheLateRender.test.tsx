// e2e:allow 실물 TemplateApp.handleRouteChange·renderTemplate·updateTemplateData·DynamicRenderer 로 화면 전환 중 떠나는 화면의 늦은 렌더를 잰다 — 브라우저 축은 총괄의 코어 빌드 뒤 엔진 회귀 단계(관리자 클레임 상세 A → B, 응답 지연 중 전역 상태 변경)가 덮는다
/**
 * @file TemplateApp.routeBindingCacheLateRender.test.tsx
 * @description 화면 전환으로 바인딩 캐시를 비운 뒤 늦게 처리된 떠나는 화면의 렌더가 앞 대상 값을 캐시에 다시 채우지 않는다
 *
 * 바인딩 값 캐시(영구 캐시, 키 = 경로 글자, 30초)와 렌더 사이클 캐시는 컨텍스트와 무관한 키를 쓴다. 화면 전환은 새 화면을 그리기 직전에
 * 둘을 비운다(engine-v1.82.1). 그런데 응답을 기다리는 동안 전역 상태가 바뀌면 떠나는 화면의 다시 그리기가 startTransition 으로 예약되고,
 * 부하가 있으면 그 렌더가 캐시를 비운 뒤에 처리된다. 그 렌더는 앞 대상 문맥으로 `{{claim.data.order_number}}` 를 평가해 비운 캐시에
 * `ORD-1` 을 채우고, 새 화면은 그 값을 읽어 30초 동안 앞 대상의 주문 링크를 보였다(부하 실측: TemplateApp.routeBindingCache 6회 중 1회).
 *
 * 하네스는 그 순서를 부하 없이 강제한다 — 전환이 캐시를 비우는 순간 떠나는 화면의 다시 그리기(`updateTemplateData`)를 `flushSync` 로
 * 처리하게 한다. 실물 `TemplateApp.handleRouteChange`(레이아웃 로더만 대역) · `renderTemplate` · `DynamicRenderer` · `DataBindingEngine`.
 *
 * @scenario-manifest tests/scenarios/engine-data-binding-and-pipes.yaml
 *
 * @vitest-environment jsdom
 */

import { afterEach, describe, expect, it, vi } from 'vitest';
import React from 'react';
import { flushSync } from 'react-dom';
import { waitFor } from '@testing-library/react';

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
import { BINDING_CACHE_EPOCH, DataBindingEngine } from '../DataBindingEngine';

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
    // 클레임 상세 꼴 — 같은 레이아웃의 다른 대상, blocking 응답 값을 href 에 쓴다(영구 캐시를 타는 단순 경로)
    claim_detail: {
        layout_name: 'claim_detail',
        data_sources: [{ id: 'claim', type: 'api', endpoint: '/api/test/posts/{{route.id}}', auth_mode: 'required', loading_strategy: 'blocking' }],
        components: [span('claim', { 'data-order': '/admin/orders/{{claim.data.order_number}}', 'data-reply': '/admin/claims/{{route.id}}/reply' })],
    },
};

interface RouteSpec { path: string; layout: string; params?: Record<string, string> }

vi.setConfig({ testTimeout: 30_000 });
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
    const go = async (r: RouteSpec): Promise<void> => {
        window.history.pushState({}, '', r.path);
        await (app as any).handleRouteChange({ path: r.path, layout: r.layout, params: r.params ?? {}, query: {} });
    };
    await go(first);

    return go;
}

/**
 * 다음 화면 전환이 캐시를 비우고 렌더 사이클을 새로 연 직후(새 화면을 그리기 직전), 떠나는 화면(엔진이 아직 들고 있는 지금 문맥)을 한 번
 * 동기로 다시 그리게 한다. 부하에서 startTransition 으로 예약된 떠나는 화면의 렌더가 비운 뒤에 처리되는 순서를 그대로 만든다.
 * 전환은 비우기 바로 다음에 `startRenderCycle` 을 부르므로(engine-v1.82.1 부터) 그 호출 뒤에 끼운다 — 화면 전환 경로의 호출만 잡는다.
 */
function renderLeavingScreenRightAfterCacheClear(): void {
    const bindingEngine: any = engine.getState().bindingEngine;
    const original = bindingEngine.startRenderCycle.bind(bindingEngine);
    let done = false;
    bindingEngine.startRenderCycle = () => {
        original();
        if (done || !String(new Error().stack).includes('handleRouteChange')) return;
        done = true;
        flushSync(() => engine.updateTemplateData({ _global: { tick: Date.now() } }, { sync: true }));
    };
}

describe('화면 전환으로 캐시를 비운 뒤 늦게 처리된 떠나는 화면의 렌더가 앞 값을 다시 채우지 않는다', () => {
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
     * 같은 레이아웃의 다른 대상(클레임 1 → 2). 캐시를 비운 직후 떠나는 화면이 앞 대상 문맥으로 다시 그려져도 새 화면은 새 대상 값이다.
     * 종전: 떠나는 화면 렌더가 `claim.data.order_number = ORD-1` 을 비운 캐시에 채우고 새 화면이 그 값을 30초 동안 읽었다.
     *
     * @scenario surface=cache
     * @effects leaving_screen_render_does_not_refill_cache, route_query_bindings_follow_current_screen
     */
    it('캐시를 비운 직후 떠나는 화면이 다시 그려져도 새 화면은 새 대상의 주문 링크와 경로 값을 그린다', async () => {
        const go = await mount({ path: '/admin/claims/1', layout: 'claim_detail', params: { id: '1' } });
        await waitFor(() => expect(attr('claim', 'data-order')).toBe('/admin/orders/ORD-1'), { timeout: 5_000, interval: 15 });

        renderLeavingScreenRightAfterCacheClear();
        await go({ path: '/admin/claims/2', layout: 'claim_detail', params: { id: '2' } });

        await waitFor(() => {
            expect(attr('claim', 'data-order')).toBe('/admin/orders/ORD-2');
            expect(attr('claim', 'data-reply')).toBe('/admin/claims/2/reply');
        }, { timeout: 3_000, interval: 15 });
    });
});

describe('바인딩 캐시 세대 — 다른 세대 문맥의 평가는 캐시를 읽지도 쓰지도 않는다', () => {
    const stamp = (context: Record<string, any>, epoch: number): Record<string, any> => {
        (context as any)[BINDING_CACHE_EPOCH] = epoch;

        return context;
    };

    /**
     * 영구 캐시(단순 데이터소스 경로)와 렌더 사이클 캐시(route·query 경로) 모두, 새 세대를 연 뒤 앞 세대 문맥으로 평가한 값이 새 세대 문맥의
     * 평가에 섞이지 않는다. 렌더 사이클 캐시 쪽은 실물 렌더러로 끊긴 렌더를 고정하기 어려워 엔진 함수로 잠근다.
     *
     * @scenario surface=cache
     * @effects leaving_screen_render_does_not_refill_cache
     */
    it('새 세대를 연 뒤 앞 세대 문맥의 평가 값이 새 세대 문맥에 보이지 않는다', () => {
        const be = new DataBindingEngine();
        const oldCtx = stamp({ claim: { data: { order_number: 'ORD-1' } }, route: { id: '1' } }, be.startCacheEpoch());
        be.startRenderCycle();
        expect(be.resolve('claim.data.order_number', oldCtx)).toBe('ORD-1');

        const newEpoch = be.startCacheEpoch();
        be.startRenderCycle();
        // 늦게 처리된 떠나는 화면의 렌더 — 앞 세대 문맥
        expect(be.resolve('claim.data.order_number', oldCtx)).toBe('ORD-1');
        expect(be.resolve('route.id', oldCtx)).toBe('1');
        expect(be.resolveBindings('/admin/orders/{{claim.data.order_number}}', oldCtx)).toBe('/admin/orders/ORD-1');

        const newCtx = stamp({ claim: { data: { order_number: 'ORD-2' } }, route: { id: '2' } }, newEpoch);
        expect(be.resolve('claim.data.order_number', newCtx)).toBe('ORD-2');
        expect(be.resolve('route.id', newCtx)).toBe('2');
        expect(be.resolveBindings('/admin/orders/{{claim.data.order_number}}', newCtx)).toBe('/admin/orders/ORD-2');
        expect(be.evaluatePipeExpression('claim.data.order_number | default("-")', newCtx)).toBe('ORD-2');
    });

    /**
     * 세대 표지가 없는 문맥(편집기 미리보기·단독 사용·종전 호출)은 종전처럼 캐시를 쓴다 — 같은 경로의 두 번째 평가는 캐시 값을 돌려준다.
     *
     * @scenario surface=cache
     * @effects cache_reuses_evaluated_results
     */
    it('세대 표지가 없는 문맥은 종전처럼 영구 캐시를 다시 쓴다', () => {
        const be = new DataBindingEngine();
        be.startCacheEpoch();
        expect(be.resolve('claim.data.order_number', { claim: { data: { order_number: 'A' } } })).toBe('A');
        expect(be.resolve('claim.data.order_number', { claim: { data: { order_number: 'B' } } })).toBe('A');
    });

    /**
     * 세대를 연 적이 없는 엔진(번역 매개변수 파이프를 평가하는 전역 싱글턴처럼 화면 전환에 참여하지 않는 인스턴스)은 세대 표지가 실린 렌더 문맥도
     * 종전처럼 캐시한다 — 판정하면 그런 엔진의 평가가 화면 안에서도 늘 캐시를 건너뛰어 느려진다.
     *
     * @scenario surface=cache
     * @effects cache_reuses_evaluated_results
     */
    it('세대를 연 적이 없는 엔진은 표지가 실린 문맥도 종전처럼 캐시한다', () => {
        const routeEngine = new DataBindingEngine();
        const ctx = (value: string) => stamp({ claim: { data: { order_number: value } } }, routeEngine.startCacheEpoch());
        const standalone = new DataBindingEngine();
        expect(standalone.resolve('claim.data.order_number', ctx('A'))).toBe('A');
        expect(standalone.resolve('claim.data.order_number', ctx('B'))).toBe('A');
    });
});
