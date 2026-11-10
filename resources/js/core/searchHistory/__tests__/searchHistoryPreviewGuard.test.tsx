/**
 * 편집기 미리보기에서 최근 검색어 쓰기 차단 (engine-v1.87.0)
 *
 * 레이아웃 편집기 미리보기(`/preview/{token}`)는 사용자 템플릿 앱이라 최근 검색어 런타임이 서고, 미리보기 모드는 그 **뒤에**
 * 켜진다(디스패처 `setPreviewMode(true)`·`_global.__isPreview`). 미리보기에서 검색창을 눌러 본 것이 실제 최근 검색어·자동 저장
 * 값·로그인 병합으로 남으면 안 된다 — 핸들러(`recordSearchKeyword`)만이 아니라 파사드·훅·병합의 쓰기 전부를 런타임이
 * **호출 시점에** 막는다. 읽기(list·getSnapshot)는 그대로다. 실제 ActionDispatcher 의 미리보기 모드와 기본 의존성으로 잡는다.
 *
 * @scenario-manifest tests/scenarios/search-history.yaml
 */

import React from 'react';
import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { ActionDispatcher } from '../../template-engine/ActionDispatcher';
import { initializeG7CoreGlobals } from '../../template-engine/G7CoreGlobals';
import type { G7CoreDependencies } from '../../template-engine/G7CoreGlobals';
import {
    SEARCH_HISTORY_CHANGED_EVENT,
    SEARCH_HISTORY_STORAGE_KEY,
    defaultSearchHistoryRuntimeDeps,
    installSearchHistoryRuntime,
    resetSearchHistoryRuntime,
    type SearchHistoryRuntimeDeps,
} from '..';

const api = vi.hoisted(() => ({
    get: vi.fn(async () => ({ success: true, data: { enabled: true, auto_save: true, max_count: 10, items: [] } })),
    post: vi.fn(async () => ({ success: true, data: { recorded: true, item: { id: 1, keyword: 'x', searched_at_ms: 1 } } })),
    put: vi.fn(async () => ({ success: true, data: { auto_save: false } })),
    delete: vi.fn(async () => ({ success: true, data: {} })),
}));
const auth = vi.hoisted(() => ({ isAuthenticated: false, handler: null as null | ((s: { isAuthenticated: boolean }) => void) }));

vi.mock('../../api/ApiClient', () => ({
    getApiClient: vi.fn(() => api),
}));

vi.mock('../../auth/AuthManager', () => ({
    AuthManager: {
        getInstance: vi.fn(() => ({
            on: vi.fn((_e: string, h: (s: { isAuthenticated: boolean }) => void) => {
                auth.handler = h;
                return () => {};
            }),
            getState: vi.fn(() => ({ isAuthenticated: auth.isAuthenticated })),
        })),
    },
}));

/** G7Core 전역을 세우는 최소 의존성 — 실제 ActionDispatcher 를 싣는다(미리보기 모드 판정용) */
function g7Deps(dispatcher: ActionDispatcher): G7CoreDependencies {
    return {
        getState: vi.fn(() => ({
            translationEngine: { translate: vi.fn((key: string) => key) } as any,
            translationContext: { templateId: 't', locale: 'ko' },
            bindingEngine: {} as any,
            actionDispatcher: dispatcher as any,
            templateMetadata: { locales: ['ko'] },
        })),
        transitionManager: { getIsPending: vi.fn(() => false), subscribe: vi.fn(() => () => {}) },
        responsiveManager: {},
        webSocketManager: {} as any,
    };
}

/** 훅으로 기록하는 검색창 대역 */
function RecordButton() {
    const history = (window as any).G7Core.useSearchHistory('core.site');

    return (
        <button type="button" data-testid="hook-record" onClick={() => void history.record('훅 기록')}>
            {history.items.map((it: { keyword: string }) => it.keyword).join('|')}
        </button>
    );
}

describe('편집기 미리보기 — 최근 검색어 쓰기 차단', () => {
    let dispatcher: ActionDispatcher;
    let setItem: ReturnType<typeof vi.spyOn>;
    let removeItem: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        vi.clearAllMocks();
        localStorage.clear();
        auth.isAuthenticated = false;
        auth.handler = null;
        (window as any).G7Config = { searchScopes: { 'core.site': { max_count: 10 } } };
        dispatcher = new ActionDispatcher({ navigate: vi.fn() });
        initializeG7CoreGlobals(g7Deps(dispatcher));
        setItem = vi.spyOn(Storage.prototype, 'setItem');
        removeItem = vi.spyOn(Storage.prototype, 'removeItem');
    });

    afterEach(() => {
        setItem.mockRestore();
        removeItem.mockRestore();
        resetSearchHistoryRuntime();
        delete (window as any).G7Core;
        delete (window as any).G7Config;
        localStorage.clear();
    });

    // @scenario case=preview_write_blocked
    // @effects preview_blocks_all_search_history_writes
    it('비회원 — 미리보기 모드(런타임 설치 뒤 켜짐)에서 파사드·훅·핸들러·자동 저장 쓰기가 localStorage 를 건드리지 않는다', async () => {
        installSearchHistoryRuntime(defaultSearchHistoryRuntimeDeps());
        dispatcher.setPreviewMode(true); // 설치 뒤에 켜진다 — 실제 부팅 순서
        const G7Core = (window as any).G7Core;

        render(<RecordButton />);
        expect(await G7Core.searchHistory.record('core.site', '파사드 기록')).toBe(false);
        await act(async () => {
            screen.getByTestId('hook-record').click();
            await new Promise((r) => setTimeout(r, 10));
        });
        expect(await G7Core.searchHistory.setAutoSave(false)).toBe(false);
        expect(await G7Core.searchHistory.clear('core.site')).toBe(false);
        expect(await G7Core.searchHistory.remove('core.site', '파사드 기록')).toBe(false);
        await (dispatcher as any).executeAction({ handler: 'recordSearchKeyword', params: { scope: 'core.site', keyword: '핸들러 기록' } }, { data: {}, state: {}, setState: vi.fn() });

        expect(setItem.mock.calls.filter(([k]) => k === SEARCH_HISTORY_STORAGE_KEY)).toEqual([]);
        expect(localStorage.getItem(SEARCH_HISTORY_STORAGE_KEY)).toBeNull();
        // 읽기는 그대로다
        expect(G7Core.searchHistory.getSnapshot('core.site').enabled).toBe(true);

        // 호출 시점 판정 — 미리보기를 끄면 같은 런타임이 다시 쓴다
        dispatcher.setPreviewMode(false);
        expect(await G7Core.searchHistory.record('core.site', '미리보기 밖')).toBe(true);
        expect(localStorage.getItem(SEARCH_HISTORY_STORAGE_KEY)).toContain('미리보기 밖');
    });

    // @scenario case=preview_write_blocked
    // @effects preview_blocks_all_search_history_writes
    it('회원 — 미리보기 모드에서 기록·삭제·전체 삭제·자동 저장·로그인 병합이 POST/PUT/DELETE 를 하나도 만들지 않는다', async () => {
        localStorage.setItem(SEARCH_HISTORY_STORAGE_KEY, JSON.stringify({ v: 1, autoSave: true, items: { 'core.site': [{ k: '브라우저 검색어', t: 5 }] } }));
        setItem.mockClear();
        installSearchHistoryRuntime(defaultSearchHistoryRuntimeDeps());
        dispatcher.setPreviewMode(true);
        const G7Core = (window as any).G7Core;

        // 미리보기 화면에서 로그인 — 병합하지 않고 브라우저 칸도 지우지 않는다
        auth.isAuthenticated = true;
        auth.handler?.({ isAuthenticated: true });
        await new Promise((r) => setTimeout(r, 10));

        expect(await G7Core.searchHistory.record('core.site', '회원 기록')).toBe(false);
        expect(await G7Core.searchHistory.remove('core.site', 1)).toBe(false);
        expect(await G7Core.searchHistory.clear()).toBe(false);
        expect(await G7Core.searchHistory.setAutoSave(false)).toBe(false);

        expect(api.post).not.toHaveBeenCalled();
        expect(api.put).not.toHaveBeenCalled();
        expect(api.delete).not.toHaveBeenCalled();
        expect(removeItem.mock.calls.filter(([k]) => k === SEARCH_HISTORY_STORAGE_KEY)).toEqual([]);
        expect(localStorage.getItem(SEARCH_HISTORY_STORAGE_KEY)).toContain('브라우저 검색어');
        // 읽기(회원 목록 GET)는 그대로다
        await G7Core.searchHistory.list('core.site', { force: true });
        expect(api.get).toHaveBeenCalled();
    });

    // @scenario case=preview_write_blocked
    // @effects preview_blocks_all_search_history_writes
    it('미리보기 주소(/preview/{token})로 부팅하면 미리보기 모드가 켜지기 전에도 부팅 병합을 하지 않는다', async () => {
        const deps: SearchHistoryRuntimeDeps = { ...defaultSearchHistoryRuntimeDeps() };
        window.history.pushState({}, '', '/preview/d14a9221-26b7-4c72-a74e-a89ff8ed1f24');
        try {
            localStorage.setItem(SEARCH_HISTORY_STORAGE_KEY, JSON.stringify({ v: 1, autoSave: true, items: { 'core.site': [{ k: '브라우저 검색어', t: 5 }] } }));
            auth.isAuthenticated = true;
            await installSearchHistoryRuntime(deps).start();
            expect(api.post).not.toHaveBeenCalled();
            expect(localStorage.getItem(SEARCH_HISTORY_STORAGE_KEY)).toContain('브라우저 검색어');
            void SEARCH_HISTORY_CHANGED_EVENT;
        } finally {
            window.history.pushState({}, '', '/');
        }
    });

    // @scenario case=preview_write_blocked
    // @effects preview_blocks_all_search_history_writes
    it('끝이 /preview/{값} 인 일반 화면 주소(예: 이름이 preview 인 게시판 글)에서는 쓰기를 막지 않는다', async () => {
        installSearchHistoryRuntime(defaultSearchHistoryRuntimeDeps());
        const G7Core = (window as any).G7Core;

        for (const path of ['/board/preview/123', '/shop/preview/d14a9221-26b7-4c72-a74e-a89ff8ed1f24', '/preview/not-a-token']) {
            window.history.pushState({}, '', path);
            try {
                expect(await G7Core.searchHistory.record('core.site', `기록 ${path}`)).toBe(true);
            } finally {
                window.history.pushState({}, '', '/');
            }
        }
        expect(localStorage.getItem(SEARCH_HISTORY_STORAGE_KEY)).toContain('/board/preview/123');
    });
});
