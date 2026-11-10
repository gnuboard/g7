/**
 * 회원 목록 조회 중 변경 — 늦게 도착한 옛 목록이 바뀐 상태를 덮지 않는다 (engine-v1.87.0)
 *
 * 회원 검색창이 처음 그려지며 목록 GET 을 보낸 사이에 자동 저장을 끄거나 검색어를 기록하면, 그 변경 **전** 시점에
 * 출발한 GET 응답이 나중에 도착한다. 종전 런타임은 그 응답을 캐시·자동 저장 값에 그대로 써서 「자동 저장을 껐는데
 * 목록이 다시 보임」·「방금 기록한 검색어가 목록에서 빠짐」이 났다(훅의 차례 번호 가드로는 못 막는다 — 훅이 다시
 * 부른 조회가 런타임의 옛 진행 조회에 합류했기 때문). 실제 `useSearchHistory` 렌더로 잡는다.
 *
 * @scenario-manifest tests/scenarios/search-history.yaml
 */

import React from 'react';
import { act, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { initializeG7CoreGlobals } from '../../template-engine/G7CoreGlobals';
import type { G7CoreDependencies } from '../../template-engine/G7CoreGlobals';
import { SEARCH_HISTORY_CHANGED_EVENT, installSearchHistoryRuntime, resetSearchHistoryRuntime, type SearchHistoryRuntimeDeps } from '..';

vi.mock('../../api/ApiClient', () => ({
    getApiClient: vi.fn(() => ({ getToken: vi.fn(() => null), get: vi.fn() })),
}));

vi.mock('../../auth/AuthManager', () => ({
    AuthManager: {
        getInstance: vi.fn(() => ({ on: vi.fn(() => () => {}), getState: vi.fn(() => ({ isAuthenticated: true })) })),
    },
}));

/** 손으로 풀어 주는 Promise */
function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((r) => {
        resolve = r;
    });

    return { promise, resolve };
}

/** 목록 응답 본문 */
function listBody(autoSave: boolean, keywords: string[]) {
    return {
        success: true,
        data: {
            enabled: true,
            auto_save: autoSave,
            max_count: 10,
            items: autoSave ? keywords.map((keyword, i) => ({ id: 100 - i, keyword, searched_at_ms: 1000 - i })) : [],
        },
    };
}

/**
 * 회원 런타임 의존성 — 첫 GET 은 붙잡아 두고, 그 뒤 GET 은 `later()` 가 정한 서버 상태를 돌려준다.
 *
 * @param later 두 번째 이후 GET 응답
 * @returns 의존성과 첫 GET 해제기
 */
function memberDeps(later: () => unknown) {
    const first = deferred<unknown>();
    let gets = 0;
    const api = {
        get: vi.fn(() => (++gets === 1 ? first.promise : Promise.resolve(later()))),
        post: vi.fn(async () => ({ success: true, data: { recorded: true, item: { id: 200, keyword: '새것', searched_at_ms: 2000 } } })),
        put: vi.fn(async () => ({ success: true, data: { auto_save: false } })),
        delete: vi.fn(async () => ({ success: true, data: {} })),
    };
    const deps: SearchHistoryRuntimeDeps = {
        getScopes: () => ({ 'core.site': { max_count: 10 } }),
        api,
        storage: null,
        emit: (change) => void (window as any).G7Core?.componentEvent?.emit(SEARCH_HISTORY_CHANGED_EVENT, change),
        onAuthChange: () => () => {},
        getAuthState: () => ({ isAuthenticated: true }),
    };

    return { deps, api, releaseFirst: (body: unknown) => first.resolve(body) };
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
function RecentList() {
    const history = (window as any).G7Core.useSearchHistory('core.site');

    return (
        <div data-testid="list" data-auto-save={String(history.autoSave)} data-loading={String(history.loading)}>
            {history.items.map((it: { keyword: string }) => it.keyword).join('|')}
        </div>
    );
}

describe('회원 목록 조회 중 변경 — 늦은 옛 목록', () => {
    afterEach(() => {
        resetSearchHistoryRuntime();
        delete (window as any).G7Core;
    });

    // @scenario case=auto_save_off
    // @effects member_stale_list_does_not_override_changes
    it('조회 중 자동 저장을 끄면, 늦게 온 옛 목록(auto_save true)이 목록을 다시 보이게 하지 않는다', async () => {
        initializeG7CoreGlobals(g7Deps());
        const { deps, releaseFirst } = memberDeps(() => listBody(false, []));
        const runtime = installSearchHistoryRuntime(deps);

        render(<RecentList />);
        expect(screen.getByTestId('list').dataset.loading).toBe('true');

        await act(async () => {
            await runtime.setAutoSave(false);
        });
        await act(async () => {
            releaseFirst(listBody(true, ['옛 검색어', '더 옛 검색어']));
            await new Promise((r) => setTimeout(r, 20));
        });

        await waitFor(() => expect(screen.getByTestId('list').dataset.loading).toBe('false'));
        expect(screen.getByTestId('list').dataset.autoSave).toBe('false');
        expect(screen.getByTestId('list').textContent).toBe('');
        expect(runtime.getAutoSave()).toBe(false);
    });

    // @scenario case=member_submit_records
    // @effects member_stale_list_does_not_override_changes
    it('조회 중 검색어를 기록하면, 늦게 온 옛 목록이 방금 기록한 검색어를 지우지 않는다', async () => {
        initializeG7CoreGlobals(g7Deps());
        const { deps, api, releaseFirst } = memberDeps(() => listBody(true, ['새것', '옛 검색어']));
        const runtime = installSearchHistoryRuntime(deps);

        render(<RecentList />);

        await act(async () => {
            expect(await runtime.record('core.site', '새것')).toBe(true);
        });
        await act(async () => {
            releaseFirst(listBody(true, ['옛 검색어']));
            await new Promise((r) => setTimeout(r, 20));
        });

        await waitFor(() => expect(screen.getByTestId('list').textContent).toBe('새것|옛 검색어'));
        expect(runtime.getSnapshot('core.site').items.map((it) => it.keyword)).toEqual(['새것', '옛 검색어']);
        expect(api.post).toHaveBeenCalledTimes(1);
    });
});
