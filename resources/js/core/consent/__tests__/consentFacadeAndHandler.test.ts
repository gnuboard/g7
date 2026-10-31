/**
 * 내장 핸들러 `refreshConsent` 와 `G7Core.consent` 파사드 (#639, 결정 D6·D7)
 *
 * 레이아웃은 동의 저장 성공 뒤 `<action handler="refreshConsent"/>` 로 런타임을 다시 조회하게 하고,
 * TS 확장은 `G7Core.consent.subscribe()` 로 변경을 받아 해제 함수로 푼다. 둘 다 같은 런타임 하나를 쓴다.
 */

import { afterEach, describe, expect, it, vi } from 'vitest';

import { ActionDispatcher } from '../../template-engine/ActionDispatcher';
import { initializeG7CoreGlobals } from '../../template-engine/G7CoreGlobals';
import type { G7CoreDependencies } from '../../template-engine/G7CoreGlobals';
import { installConsentRuntime, resetConsentRuntime, type ConsentRuntimeDeps } from '..';

vi.mock('../../api/ApiClient', () => ({
    getApiClient: vi.fn(() => ({ getToken: vi.fn(() => null), get: vi.fn() })),
}));

vi.mock('../../auth/AuthManager', () => ({
    AuthManager: {
        getInstance: vi.fn(() => ({ on: vi.fn(() => () => {}), getState: vi.fn(() => ({ isAuthenticated: false })) })),
    },
}));

/** 조회 횟수를 세는 런타임 의존성 */
function deps(): ConsentRuntimeDeps & { fetchSnapshot: ReturnType<typeof vi.fn> } {
    return {
        fetchSnapshot: vi.fn(async () => ({
            success: true,
            data: { contract_version: 1, status: 'ready', categories: { analytics: true }, needs_renewal: false, policy_version: '1' },
        })),
        writeGlobal: vi.fn(),
        emit: async (snapshot) => (window as any).G7Core?.componentEvent?.emit('consent:changed', snapshot),
        onAuthChange: () => () => {},
        getAuthState: () => ({ isAuthenticated: false }),
    };
}

/** G7Core 전역을 세우는 최소 의존성 */
function g7Deps(): G7CoreDependencies {
    return {
        getState: vi.fn(() => ({
            translationEngine: { translate: vi.fn((key: string) => key) } as any,
            translationContext: { templateId: 't', locale: 'ko' },
            bindingEngine: {} as any,
            actionDispatcher: {} as any,
            templateMetadata: { locales: ['ko'] },
        })),
        transitionManager: { getIsPending: vi.fn(() => false), subscribe: vi.fn(() => () => {}) },
        responsiveManager: {},
        webSocketManager: {} as any,
    };
}

describe('refreshConsent · G7Core.consent', () => {
    afterEach(() => {
        resetConsentRuntime();
        delete (window as any).G7Core;
    });

    // @scenario surface=handler, change=refresh, response=ok
    // @effects refresh_consent_handler_refreshes_runtime_once
    it('refreshConsent 핸들러는 런타임을 한 번 다시 조회한다', async () => {
        initializeG7CoreGlobals(g7Deps());
        const d = deps();
        const runtime = installConsentRuntime(d);
        await runtime.refresh();
        d.fetchSnapshot.mockClear();

        const dispatcher = new ActionDispatcher({ navigate: vi.fn() });
        await (dispatcher as any).executeAction({ handler: 'refreshConsent' }, { data: {}, state: {}, setState: vi.fn() });

        expect(d.fetchSnapshot).toHaveBeenCalledTimes(1);
    });

    // @scenario surface=handler, change=refresh, response=none
    // @effects refresh_consent_handler_refreshes_runtime_once
    it('런타임이 없는 화면(관리자)에서는 아무 일도 하지 않는다', async () => {
        const dispatcher = new ActionDispatcher({ navigate: vi.fn() });

        await expect(
            (dispatcher as any).executeAction({ handler: 'refreshConsent' }, { data: {}, state: {}, setState: vi.fn() }),
        ).resolves.not.toThrow();
    });

    // @scenario surface=facade, change=refresh, response=ok
    // @effects consent_facade_is_frozen_and_subscribe_returns_unsubscribe
    it('파사드는 동결돼 있고, subscribe 는 해제 함수를 돌려준다', async () => {
        initializeG7CoreGlobals(g7Deps());
        const G7Core = (window as any).G7Core;

        expect(Object.isFrozen(G7Core.consent)).toBe(true);
        expect(G7Core.consent.getSnapshot(), '런타임 전에는 null').toBeNull();

        installConsentRuntime(deps());
        const listener = vi.fn();
        const off = G7Core.consent.subscribe(listener);
        expect(typeof off).toBe('function');

        await G7Core.consent.refresh();
        expect(listener).toHaveBeenCalledTimes(1);
        expect(G7Core.consent.getSnapshot()).toMatchObject({ status: 'ready', contractVersion: 1 });

        off();
        await G7Core.componentEvent.emit('consent:changed', {});
        expect(listener).toHaveBeenCalledTimes(1);
    });
});
