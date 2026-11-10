/**
 * 내장 핸들러 `recordSearchKeyword` · `G7Core.searchHistory` 파사드 · `G7Core.useSearchHistory` 훅 (engine-v1.87.0)
 *
 * 레이아웃은 검색 이동 앞에 `<record-search-keyword scope="…" keyword="{{…}}"/>` 로 기록하고, 템플릿 검색창은
 * `G7Core.useSearchHistory(scope)` 로 목록을 그린다. 셋 다 같은 런타임 하나를 쓰고, 같은 범위의 검색창끼리 목록을 공유한다.
 * 실제 ActionDispatcher·G7Core 전역·React 렌더로 잡는다(런타임 의존성만 메모리 저장소로 흉내 낸다).
 *
 * @scenario-manifest tests/scenarios/search-history.yaml
 */

import React from 'react';
import { act, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { ActionDispatcher } from '../../template-engine/ActionDispatcher';
import { initializeG7CoreGlobals } from '../../template-engine/G7CoreGlobals';
import type { G7CoreDependencies } from '../../template-engine/G7CoreGlobals';
import { SEARCH_HISTORY_CHANGED_EVENT, installSearchHistoryRuntime, resetSearchHistoryRuntime, type SearchHistoryRuntimeDeps } from '..';

vi.mock('../../api/ApiClient', () => ({
    getApiClient: vi.fn(() => ({ getToken: vi.fn(() => null), get: vi.fn() })),
}));

vi.mock('../../auth/AuthManager', () => ({
    AuthManager: {
        getInstance: vi.fn(() => ({ on: vi.fn(() => () => {}), getState: vi.fn(() => ({ isAuthenticated: false })) })),
    },
}));

/** 메모리 저장소를 쓰는 비회원 런타임 의존성 */
function guestDeps(): SearchHistoryRuntimeDeps {
    const data = new Map<string, string>();

    return {
        getScopes: () => ({ 'core.site': { max_count: 5 }, 'acme-shop.products': { max_count: 5 } }),
        api: { get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn() },
        storage: {
            getItem: (key) => data.get(key) ?? null,
            setItem: (key, value) => void data.set(key, value),
            removeItem: (key) => void data.delete(key),
        },
        emit: (change) => void (window as any).G7Core?.componentEvent?.emit(SEARCH_HISTORY_CHANGED_EVENT, change),
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

/** 훅으로 범위 목록을 그리는 검색창 대역 */
function RecentList({ scope, testId }: { scope: string; testId: string }) {
    const history = (window as any).G7Core.useSearchHistory(scope);

    return (
        <div data-testid={testId} data-enabled={String(history.enabled)} data-auto-save={String(history.autoSave)}>
            {history.items.map((it: { keyword: string }) => it.keyword).join('|')}
        </div>
    );
}

describe('recordSearchKeyword · G7Core.searchHistory · useSearchHistory', () => {
    afterEach(() => {
        resetSearchHistoryRuntime();
        delete (window as any).G7Core;
    });

    // @scenario case=member_submit_records
    // @effects record_handler_resolves_bindings_and_records
    it('recordSearchKeyword 핸들러는 바인딩을 풀어 기록하고, 같은 범위를 쓰는 검색창 목록이 함께 바뀐다', async () => {
        initializeG7CoreGlobals(g7Deps());
        installSearchHistoryRuntime(guestDeps());

        render(
            <>
                <RecentList scope="core.site" testId="header" />
                <RecentList scope="core.site" testId="drawer" />
                <RecentList scope="acme-shop.products" testId="shop" />
            </>,
        );

        const dispatcher = new ActionDispatcher({ navigate: vi.fn() });
        await act(async () => {
            await (dispatcher as any).executeAction(
                { handler: 'recordSearchKeyword', params: { scope: 'core.site', keyword: '{{form.q}}' } },
                { data: { form: { q: '  노트북  가방 ' } }, state: {}, setState: vi.fn() },
            );
        });

        await waitFor(() => expect(screen.getByTestId('header').textContent).toBe('노트북 가방'));
        expect(screen.getByTestId('drawer').textContent).toBe('노트북 가방');
        expect(screen.getByTestId('shop').textContent).toBe('');
    });

    // @scenario case=undeclared_or_inactive_scope
    // @effects undeclared_scope_skipped_by_engine
    it('런타임이 없는 화면(관리자)·선언되지 않은 범위에서는 기록하지 않고 훅은 enabled=false 다', async () => {
        initializeG7CoreGlobals(g7Deps());
        const dispatcher = new ActionDispatcher({ navigate: vi.fn() });

        await expect(
            (dispatcher as any).executeAction({ handler: 'recordSearchKeyword', params: { scope: 'core.site', keyword: 'a' } }, { data: {}, state: {}, setState: vi.fn() }),
        ).resolves.not.toThrow();

        render(<RecentList scope="core.site" testId="admin" />);
        expect(screen.getByTestId('admin').dataset.enabled).toBe('false');

        installSearchHistoryRuntime(guestDeps());
        const G7Core = (window as any).G7Core;
        expect(await G7Core.searchHistory.record('acme-none.items', 'a')).toBe(false);
        expect(G7Core.searchHistory.isEnabled('acme-none.items')).toBe(false);
        expect(G7Core.searchHistory.isEnabled('core.site')).toBe(true);
    });

    // @scenario case=auto_save_off
    // @effects search_history_facade_frozen_and_subscribe_unsubscribes
    it('파사드는 동결돼 있고 subscribe 는 해제 함수를 돌려준다 — 자동 저장 끄기가 훅 목록을 감춘다', async () => {
        initializeG7CoreGlobals(g7Deps());
        installSearchHistoryRuntime(guestDeps());
        const G7Core = (window as any).G7Core;

        expect(Object.isFrozen(G7Core.searchHistory)).toBe(true);
        expect(typeof G7Core.useSearchHistory).toBe('function');

        const listener = vi.fn();
        const off = G7Core.searchHistory.subscribe(listener);
        await G7Core.searchHistory.record('core.site', '보임');
        expect(listener).toHaveBeenCalledWith({ scope: 'core.site', reason: 'record' });
        off();
        await G7Core.searchHistory.record('core.site', '또');
        expect(listener).toHaveBeenCalledTimes(1);

        render(<RecentList scope="core.site" testId="list" />);
        expect(screen.getByTestId('list').textContent).toBe('또|보임');

        await act(async () => {
            await G7Core.searchHistory.setAutoSave(false);
        });
        await waitFor(() => expect(screen.getByTestId('list').dataset.autoSave).toBe('false'));
        expect(screen.getByTestId('list').textContent).toBe('');
    });
});
