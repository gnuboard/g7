// e2e:allow 실 TemplateApp·Router·ActionDispatcher 로 「응답을 기다리는 동안 이동 → 같은 주소 재진입」 의 오류 화면을 잰다 — 브라우저 축은 7.1.1 출시 전 브라우저 재실측(2026-10-18, 회원 클레임 수정 주소 응답 지연 중 navigate 로 이동 → 같은 주소)이 덮는다
/**
 * @file TemplateApp.reentryErrorPage.test.ts
 * @description 앞 세대의 오류 화면 그리기가 끝나기 전에 같은 주소로 다시 들어오면, 새 세대 자기 응답의 showErrorPage 가 「중복」 으로 버려져
 *   빈 화면이 남던 결함 (7.1.1 engine-v1.81.1)
 *
 * Router 는 이동 중 들어온 navigate 를 마지막 하나만 남긴다. 그래서 로딩 중 「다른 메뉴 → 같은 주소」 는 같은 주소 재진입 하나가 된다.
 * 첫 진입의 응답은 아직 그 세대일 때 와서 오류 화면을 그리기 시작하고(렌더 뒤로 미룬 실행), 곧 새 세대가 열린다. 새 세대의 핸들러가
 * 자기 응답으로 showErrorPage 를 부를 때 앞 세대 호출이 아직 그리는 중이면, 세대를 가리지 않던 중복 방지 표식이 그 호출을 버렸다.
 * 앞 세대 호출은 그리기 직전 판정으로 그리지 않으므로 어느 쪽도 404 를 그리지 않았다.
 *
 * 고침: 중복 방지 표식을 출처 세대별로 둔다 — 같은 세대의 호출만 중복으로 버리고, 새 세대 호출은 표식을 넘겨받는다.
 *
 * @scenario-manifest tests/scenarios/reentry-error-page.yaml
 *
 * @effects reentry_own_error_page_drawn
 */
import { describe, it, expect, vi } from 'vitest';

const { realDispatcher } = vi.hoisted(() => ({ realDispatcher: { current: null as any, Ctor: null as any } }));

vi.mock('../template-engine', async () => {
    const { ActionDispatcher } = await vi.importActual<any>('../template-engine/ActionDispatcher');
    realDispatcher.Ctor = ActionDispatcher;
    realDispatcher.current = new ActionDispatcher({ navigate: () => {} });
    return {
        ...(await vi.importActual<any>('../template-engine')),
        initTemplateEngine: vi.fn().mockResolvedValue(undefined),
        renderTemplate: vi.fn(async () => {
            const app = document.getElementById('app')!;
            app.innerHTML = '<div id="main_content">form</div>';
        }),
        destroyTemplate: vi.fn(),
        releaseTemplateRoot: vi.fn(),
        updateTemplateData: vi.fn(),
        getActionDispatcher: () => realDispatcher.current,
        getState: () => ({ actionDispatcher: realDispatcher.current, reactRoot: null, currentLayoutJson: null }),
    };
});

vi.mock('../template-engine/LayoutLoader', async () => ({
    ...(await vi.importActual<any>('../template-engine/LayoutLoader')),
    LayoutLoader: vi.fn(function (this: any) {
        this.loadLayout = vi.fn(async (...args: any[]) => (args.some((a) => a === 'mypage/claims/apply')
            ? { layout_name: 'mypage/claims/apply', init_actions: [{ handler: 'probe.initEdit' }], components: [] }
            : { layout_name: 'mypage/mileage', components: [] }));
        this.setCacheVersion = vi.fn();
        this.clear = vi.fn();
    }),
}));

vi.mock('../template-engine/TransitionManager', () => ({
    transitionManager: { setPending: vi.fn(), getIsPending: vi.fn(() => false), subscribe: vi.fn(() => vi.fn()), clearSubscribers: vi.fn() },
}));

vi.mock('../template-engine/ComponentRegistry', () => {
    const mockInstance: any = { loadComponents: vi.fn().mockResolvedValue(undefined), getComponent: vi.fn().mockReturnValue(() => null), hasComponent: vi.fn().mockReturnValue(true), getInstance: vi.fn(), getMetadata: vi.fn(() => null), getMissingExtensionComponents: vi.fn(() => []) };
    mockInstance.getInstance.mockReturnValue(mockInstance);
    return { ComponentRegistry: { getInstance: vi.fn(() => mockInstance) } };
});

import { TemplateApp } from '../TemplateApp';
import { initializeG7CoreGlobals } from '../template-engine/G7CoreGlobals';

const lines: string[] = [];
const log = (s: string) => { lines.push(s); };
const tick = (ms = 20) => new Promise((r) => setTimeout(r, ms));

describe('[case:components-misc-reentry-error-page-guard] 같은 주소 재진입 — 앞 세대 오류 화면이 그리는 중이어도 새 세대 자기 404 를 그린다', () => {
    // @scenario first_render=slow
    // @effects reentry_own_error_page_drawn
    it('로딩 중 「다른 메뉴 → 같은 주소」 navigate 뒤, 두 번째 진입의 자기 응답으로 오류 화면을 그린다', async () => {
        const routes = [
            { path: '/', layout: 'home' },
            { path: '/mypage/claims/:claim_number/edit', layout: 'mypage/claims/apply' },
            { path: '/mypage/mileage', layout: 'mypage/mileage' },
        ];
        vi.mocked(globalThis.fetch).mockImplementation(async (url: any) => ({
            ok: true, status: 200, statusText: 'OK', headers: new Headers({ 'Content-Type': 'application/json' }),
            json: async () => (String(url).includes('routes') ? { success: true, data: { routes } } : { success: true, data: {} }),
            text: async () => '{}',
        }) as any);
        document.body.innerHTML = '<div id="app"></div>';
        const app: any = new TemplateApp({ templateId: 'sirsoft-basic', templateType: 'user', locale: 'ko', debug: false } as any);
        (window as any).__templateApp = app;
        const renderError = vi.fn(async (code: number, _id: string, shouldRender?: () => boolean) => {
            const idx = renderError.mock.calls.length - 1;
            await tick(idx === 0 ? 900 : 30);
            const ok = shouldRender ? shouldRender() : true;
            log(`renderError#${idx} code=${code} gen=${app.getRouteChangeId()} shouldRender=${ok}`);
            return ok;
        });
        app.getErrorPageHandler = () => ({ renderError });
        await app.init();
        initializeG7CoreGlobals({
            getState: () => ({ actionDispatcher: realDispatcher.current, translationEngine: null, translationContext: { templateId: 't', locale: 'ko' }, bindingEngine: null, templateMetadata: null }) as any,
            transitionManager: { getIsPending: () => false, subscribe: () => () => {} },
            responsiveManager: {},
            webSocketManager: {} as any,
        } as any);
        const resolvers: Array<(v?: any) => void> = [];
        realDispatcher.current.registerHandler('probe.initEdit', async () => {
            const G7Core = (window as any).G7Core;
            const token = { href: location.pathname + location.search, gen: G7Core.navigation.getRouteGeneration() };
            try {
                await new Promise((_r, rej) => resolvers.push(() => rej(Object.assign(new Error('nf'), { status: 404 }))));
            } catch (e: any) {
                const now = { href: location.pathname + location.search, gen: G7Core.navigation.getRouteGeneration() };
                const same = token.href === now.href && token.gen === now.gen;
                if (!same) return;
                const p = G7Core.dispatch({ handler: 'showErrorPage', params: { errorCode: 404, target: 'content' } });
                Promise.resolve(p).catch(() => {});
            }
        });

        app.getRouter().navigate('/mypage/claims/X/edit');
        await tick(50);
        app.getRouter().navigate('/mypage/mileage');
        app.getRouter().navigate('/mypage/claims/X/edit');
        await tick(50);
        // 진행 중인 첫 진입은 대기열에 들어온 새 이동에 밀려 세대가 올랐다(engine-v1.82.0 — 다른 메뉴 이동은 대기열에서 같은 주소
        // navigate 에 덮였다). 첫 진입의 핸들러는 응답 뒤 세대가 바뀐 것을 보고 오류 화면을 부르지 않는다
        expect(app.getRouteChangeId()).toBe(3);
        resolvers[0]?.();
        await tick(300);
        // 대기 중이던 같은 주소 navigate 가 새 세대(4)를 열고, 그 진입의 핸들러가 자기 요청을 보냈다
        expect(app.getRouteChangeId()).toBe(4);
        expect(resolvers.length).toBe(2);
        // 새 세대의 응답 — 자기 404 를 그린다
        resolvers[1]?.();
        await tick(1500);

        const drawn = renderError.mock.calls.map((_c: any, i: number) => lines.find((l) => l.startsWith(`renderError#${i}`)));
        // 종전(engine-v1.80.1): 새 세대 호출이 「Already active」 로 버려져 빈 수정 폼. 앞 세대가 그리는 중에도 새 세대가 표식을 넘겨받는
        // 갈래는 아래 「모양」 묶음이 잠근다
        expect(renderError).toHaveBeenCalledTimes(1);
        expect(drawn).toEqual(['renderError#0 code=404 gen=4 shouldRender=true']);
        delete (window as any).__templateApp;
        delete (window as any).G7Core;
    });
});

describe('[case:components-misc-reentry-error-page-guard] showErrorPage 중복 방지 표식 — 출처 세대별 (모양)', () => {
    let app: any;
    let pendingDraws: Array<() => void>;
    let renderError: ReturnType<typeof vi.fn>;

    const setup = (withGeneration = true) => {
        // 시험마다 새 실행기 — 중복 방지 표식이 앞 시험에서 넘어오지 않게
        realDispatcher.current = new realDispatcher.Ctor({ navigate: () => {} });
        document.body.innerHTML = '<div id="app"><div id="main_content"></div></div>';
        app = new TemplateApp({ templateId: 'sirsoft-basic', templateType: 'user', locale: 'ko', debug: false } as any);
        if (!withGeneration) app.getRouteChangeId = undefined;
        pendingDraws = [];
        // 그리기를 손으로 끝낸다 — 앞 호출이 그리는 중인 구간을 만든다
        renderError = vi.fn((_c: number, _i: string, shouldRender?: () => boolean) => new Promise<boolean>((resolve) => {
            pendingDraws.push(() => resolve(shouldRender ? shouldRender() : true));
        }));
        app.getErrorPageHandler = () => ({ renderError });
        (window as any).__templateApp = app;
    };
    const call = (data: Record<string, unknown> = {}) => realDispatcher.current.dispatchAction({ handler: 'showErrorPage', params: { errorCode: 404 } }, { data });

    // @scenario first_render=slow
    // @effects reentry_own_error_page_drawn
    it('같은 세대의 동시 호출은 종전처럼 한 번만 그린다(여러 데이터 소스 동시 오류)', async () => {
        setup();
        const a = call();
        const b = call();
        await tick();
        expect(renderError).toHaveBeenCalledTimes(1);
        pendingDraws.forEach((f) => f());
        await Promise.all([a, b]);
    });

    // @scenario first_render=slow
    // @effects reentry_own_error_page_drawn
    it('앞 세대 호출이 그리는 중이면 새 세대 호출이 표식을 넘겨받아 그리고, 앞 세대는 그리지 않는다', async () => {
        setup();
        const first = call();
        await tick();
        app.currentRouteChangeId += 1; // 같은 주소 재진입
        const second = call();
        await tick();
        expect(renderError).toHaveBeenCalledTimes(2);
        pendingDraws.forEach((f) => f());
        await Promise.all([first, second]);
        expect(await renderError.mock.results[0].value).toBe(false);
        expect(await renderError.mock.results[1].value).toBe(true);
    });

    // @scenario first_render=slow
    // @effects reentry_own_error_page_drawn
    it('늦게 온 앞 세대 오류(데이터 소스 __routeGeneration)는 새 세대의 표식을 빼앗지 않는다 — 새 세대 중복은 계속 막힌다', async () => {
        setup();
        const origin = app.currentRouteChangeId;
        app.currentRouteChangeId += 1;
        const current = call();
        await tick();
        await call({ __routeGeneration: origin }); // 앞 화면 fetch 의 늦은 오류 — 그리지 않고 끝
        const duplicate = call();
        await tick();
        expect(renderError).toHaveBeenCalledTimes(1);
        pendingDraws.forEach((f) => f());
        await Promise.all([current, duplicate]);
        // 앞 호출이 끝난 뒤의 같은 세대 호출은 다시 그린다(표식이 풀렸다)
        const after = call();
        await tick();
        expect(renderError).toHaveBeenCalledTimes(2);
        pendingDraws.forEach((f) => f());
        await after;
    });

    // @scenario first_render=slow
    // @effects reentry_own_error_page_drawn
    it('경로 세대를 모르는 환경에서는 종전처럼 진행 중이면 모두 중복으로 본다', async () => {
        setup(false);
        const a = call();
        await tick();
        const b = call();
        await tick();
        expect(renderError).toHaveBeenCalledTimes(1);
        pendingDraws.forEach((f) => f());
        await Promise.all([a, b]);
    });
});
