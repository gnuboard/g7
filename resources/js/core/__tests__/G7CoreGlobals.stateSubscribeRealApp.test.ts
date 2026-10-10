/**
 * @file G7CoreGlobals.stateSubscribeRealApp.test.ts
 * @description `G7Core.state.subscribe` 가 실제 TemplateApp 위에서 해제 함수를 돌려주는지 잠근다 (#639).
 *
 * 기존 `G7CoreGlobals.test.ts` 는 `onGlobalStateChange: vi.fn(() => () => {})` 모의를 써서, 코어가
 * 해제 함수를 돌려주지 않는 결함을 보지 못했다. 템플릿 컴포넌트(AdminSidebar·ProductCard)와
 * 본인인증 실행기는 이미 반환값을 해제 함수로 쓰고 있어서, 코어가 `undefined` 를 돌려주는 동안
 * 화면을 떠날 때마다 리스너가 쌓였다(실측: 상품 목록 왕복 20회에 전역 리스너 29 → 281).
 * 그래서 모의 대신 실제 TemplateApp 인스턴스로 잰다.
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { TemplateApp } from '../TemplateApp';
import type { TemplateAppConfig } from '../TemplateApp';
import { initializeG7CoreGlobals } from '../template-engine/G7CoreGlobals';
import type { G7CoreDependencies } from '../template-engine/G7CoreGlobals';

vi.mock('../api/ApiClient', () => ({
    getApiClient: () => ({
        post: vi.fn().mockResolvedValue({}),
        get: vi.fn().mockResolvedValue({}),
        removeToken: vi.fn(),
        setToken: vi.fn(),
        getToken: vi.fn().mockReturnValue(null),
        setOnUnauthorized: vi.fn(),
    }),
}));

vi.mock('../template-engine', () => ({
    initTemplateEngine: vi.fn().mockResolvedValue(undefined),
    renderTemplate: vi.fn().mockResolvedValue(undefined),
    destroyTemplate: vi.fn(),
    updateTemplateData: vi.fn(),
    getActionDispatcher: vi.fn(),
    getState: vi.fn().mockReturnValue({ actionDispatcher: null, reactRoot: null, currentLayoutJson: null }),
}));

/** 사용자 템플릿 부팅 설정 */
function makeConfig(): TemplateAppConfig {
    return { templateId: 'sirsoft-basic', templateType: 'user', locale: 'ko', debug: false };
}

/** G7Core 전역을 세우는 최소 의존성 */
function makeDependencies(): G7CoreDependencies {
    return {
        getState: vi.fn(() => ({
            translationEngine: { translate: vi.fn((key: string) => key) } as any,
            translationContext: { templateId: 'sirsoft-basic', locale: 'ko' },
            bindingEngine: {} as any,
            actionDispatcher: {} as any,
            templateMetadata: { locales: ['ko'] },
        })),
        transitionManager: { getIsPending: vi.fn(() => false), subscribe: vi.fn(() => () => {}) },
        responsiveManager: {},
        webSocketManager: {} as any,
    };
}

describe('[case:state-global-subscribe-returns-void] G7Core.state.subscribe — 실제 TemplateApp (#639)', () => {
    let app: TemplateApp;

    beforeEach(() => {
        delete (window as any).G7Core;
        app = new TemplateApp(makeConfig());
        (window as any).__templateApp = app;
        initializeG7CoreGlobals(makeDependencies());
    });

    afterEach(() => {
        delete (window as any).__templateApp;
        delete (window as any).G7Core;
    });

    // @scenario surface=state
    // @effects state_subscribe_returns_working_unsubscribe
    it('subscribe 는 해제 함수를 돌려주고, 해제 뒤에는 상태가 바뀌어도 부르지 않는다', () => {
        const listener = vi.fn();
        const unsubscribe = (window as any).G7Core.state.subscribe(listener);

        expect(typeof unsubscribe).toBe('function');

        app.setGlobalState({ t639: 1 }, { render: false });
        expect(listener).toHaveBeenCalledTimes(1);

        unsubscribe();
        app.setGlobalState({ t639: 2 }, { render: false });
        expect(listener).toHaveBeenCalledTimes(1);
    });

    // @scenario surface=state
    // @effects state_subscribe_returns_working_unsubscribe
    it('같은 화면을 여러 번 오가도 구독-해제 짝이면 리스너가 쌓이지 않는다', () => {
        const calls: number[] = [];

        for (let i = 0; i < 20; i++) {
            const off = (window as any).G7Core.state.subscribe(() => calls.push(i));
            off();
        }

        app.setGlobalState({ t639: 'after' }, { render: false });
        expect(calls).toEqual([]);
    });
});
