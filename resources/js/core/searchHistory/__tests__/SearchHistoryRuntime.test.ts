/**
 * 최근 검색어 런타임 (engine-v1.87.0)
 *
 * 비회원 브라우저 보관(정규화·같은 검색어 하나·보관 개수)·선언 범위만 받기·자동 저장 끄기(기록 멈춤·목록 감춤)·
 * 로그인 병합 후 비움(실패하면 남김)·회원 서버 기록을 실제 런타임 인스턴스로 잡는다. 의존성(범위·API·저장소·인증)만 흉내 낸다.
 *
 * @scenario-manifest tests/scenarios/search-history.yaml
 */

import { describe, expect, it, vi } from 'vitest';

import {
    SEARCH_HISTORY_STORAGE_KEY,
    SearchHistoryRuntime,
    normalizeSearchKeyword,
    type SearchHistoryAuthState,
    type SearchHistoryChange,
    type SearchHistoryRuntimeDeps,
} from '../SearchHistoryRuntime';

/** 메모리 localStorage */
function memoryStorage(initial: Record<string, string> = {}) {
    const data = new Map(Object.entries(initial));

    return {
        getItem: vi.fn((key: string) => (data.has(key) ? data.get(key)! : null)),
        setItem: vi.fn((key: string, value: string) => void data.set(key, value)),
        removeItem: vi.fn((key: string) => void data.delete(key)),
        raw: () => data.get(SEARCH_HISTORY_STORAGE_KEY) ?? null,
    };
}

/** 조작 가능한 의존성 묶음 */
function harness(options: { authenticated?: boolean; storage?: ReturnType<typeof memoryStorage>; scopes?: Record<string, { max_count?: number }> } = {}) {
    let auth: SearchHistoryAuthState = { isAuthenticated: options.authenticated ?? false };
    let authHandler: ((state: SearchHistoryAuthState) => void) | null = null;
    let clock = 1_000_000;
    const changes: SearchHistoryChange[] = [];
    const storage = options.storage ?? memoryStorage();
    const api = {
        get: vi.fn(async () => ({ success: true, data: { enabled: true, auto_save: true, max_count: 10, items: [] } })),
        post: vi.fn(async () => ({ success: true, data: { recorded: true, item: { id: 1, keyword: 'x', searched_at_ms: 1 } } })),
        put: vi.fn(async () => ({ success: true, data: {} })),
        delete: vi.fn(async () => ({ success: true, data: {} })),
    };

    const deps: SearchHistoryRuntimeDeps = {
        getScopes: () => options.scopes ?? { 'core.site': { max_count: 3 }, 'acme-shop.products': { max_count: 10 } },
        api,
        storage,
        emit: (change) => changes.push(change),
        onAuthChange: (handler) => {
            authHandler = handler;
            return () => {
                authHandler = null;
            };
        },
        getAuthState: () => auth,
        now: () => ++clock,
    };

    const runtime = new SearchHistoryRuntime(deps);

    return {
        runtime,
        api,
        storage,
        changes,
        setAuth(state: SearchHistoryAuthState) {
            auth = state;
            authHandler?.(state);
        },
    };
}

describe('SearchHistoryRuntime — 비회원', () => {
    // @scenario case=guest_submit_records
    // @effects keyword_normalized_on_record, same_keyword_deduped_and_moved_first, guest_list_capped_to_max_count
    it('공백을 정리해 기록하고 같은 검색어는 하나로 맨 앞에, 보관 개수를 넘으면 오래된 것부터 버린다', async () => {
        const { runtime, storage, changes } = harness();

        expect(await runtime.record('core.site', '  노트북\n  가방 ')).toBe(true);
        await runtime.record('core.site', '모니터');
        await runtime.record('core.site', 'Keyboard');
        await runtime.record('core.site', '노트북 가방');
        await runtime.record('core.site', 'keyboard');

        const state = await runtime.list('core.site');
        expect(state.items.map((it) => it.keyword)).toEqual(['keyboard', '노트북 가방', '모니터']);
        expect(state.source).toBe('guest');

        const stored = JSON.parse(storage.raw()!);
        expect(stored.v).toBe(1);
        expect(stored.autoSave).toBe(true);
        expect(stored.items['core.site'].map((it: { k: string }) => it.k)).toEqual(['keyboard', '노트북 가방', '모니터']);
        expect(changes.every((c) => c.scope === 'core.site' && c.reason === 'record')).toBe(true);
    });

    // @scenario case=undeclared_or_inactive_scope
    // @effects undeclared_scope_skipped_by_engine
    it('선언되지 않은 범위·빈 검색어는 아무것도 하지 않는다', async () => {
        const { runtime, storage, api } = harness({ scopes: { 'core.site': { max_count: 3 } } });

        expect(await runtime.record('acme-shop.products', '가방')).toBe(false);
        expect(await runtime.record('core.site', '   ')).toBe(false);
        expect(storage.setItem).not.toHaveBeenCalled();
        expect(api.post).not.toHaveBeenCalled();
        expect((await runtime.list('acme-shop.products')).enabled).toBe(false);
    });

    // @scenario case=auto_save_off
    // @effects auto_save_off_stops_recording, auto_save_off_hides_list_keeps_rows
    it('자동 저장을 끄면 기록하지 않고 목록을 감추며, 저장된 검색어는 남긴다', async () => {
        const { runtime, storage } = harness();
        await runtime.record('core.site', '남는 검색어');

        expect(await runtime.setAutoSave(false)).toBe(true);
        expect(await runtime.record('core.site', '새 검색어')).toBe(false);
        expect((await runtime.list('core.site')).items).toEqual([]);
        expect(runtime.getAutoSave()).toBe(false);
        expect(JSON.parse(storage.raw()!).items['core.site'].map((it: { k: string }) => it.k)).toEqual(['남는 검색어']);

        await runtime.setAutoSave(true);
        expect((await runtime.list('core.site')).items.map((it) => it.keyword)).toEqual(['남는 검색어']);
    });

    // @scenario case=delete_owner_only
    // @effects guest_remove_and_clear_by_scope
    it('검색어 하나·범위 전체를 지운다 (다른 범위는 남긴다)', async () => {
        const { runtime } = harness();
        await runtime.record('core.site', 'a');
        await runtime.record('core.site', 'b');
        await runtime.record('acme-shop.products', 'c');

        expect(await runtime.remove('core.site', 'a')).toBe(true);
        expect((await runtime.list('core.site')).items.map((it) => it.keyword)).toEqual(['b']);

        expect(await runtime.clear('core.site')).toBe(true);
        expect((await runtime.list('core.site')).items).toEqual([]);
        expect((await runtime.list('acme-shop.products')).items.map((it) => it.keyword)).toEqual(['c']);
    });

    // @scenario case=guest_submit_records
    // @effects keyword_normalized_on_record
    it('정규화는 연속 공백을 하나로, 100자(코드 포인트)까지 자른다', () => {
        expect(normalizeSearchKeyword(' a \t b ')).toBe('a b');
        expect(normalizeSearchKeyword(42)).toBe('42');
        expect(normalizeSearchKeyword(null)).toBe('');
        expect(Array.from(normalizeSearchKeyword('😀'.repeat(150))).length).toBe(100);
    });
});

describe('SearchHistoryRuntime — 로그인 병합', () => {
    // @scenario case=login_merge
    // @effects guest_list_merged_then_cleared_on_login
    it('비회원 → 회원이 되면 브라우저 목록을 최신순으로 서버에 보내고 성공하면 브라우저 칸을 지운다', async () => {
        const h = harness();
        await h.runtime.start();
        await h.runtime.record('core.site', '먼저');
        await h.runtime.record('acme-shop.products', '나중');

        h.setAuth({ isAuthenticated: true });
        await h.runtime.mergeGuestIntoMember();

        expect(h.api.post).toHaveBeenCalledWith('/me/search-history/merge', {
            items: [
                expect.objectContaining({ scope: 'acme-shop.products', keyword: '나중' }),
                expect.objectContaining({ scope: 'core.site', keyword: '먼저' }),
            ],
        });
        expect(h.storage.raw()).toBeNull();
        expect(h.changes.at(-1)).toEqual({ scope: null, reason: 'merge' });
    });

    // @scenario case=login_merge
    // @effects merge_failure_keeps_browser_history
    it('병합이 실패하면 브라우저 칸을 남긴다 (다음 로그인 때 다시 보낸다)', async () => {
        const h = harness();
        await h.runtime.start();
        await h.runtime.record('core.site', '남김');
        h.api.post.mockRejectedValueOnce(new Error('network'));

        h.setAuth({ isAuthenticated: true });
        await h.runtime.mergeGuestIntoMember();

        expect(JSON.parse(h.storage.raw()!).items['core.site'][0].k).toBe('남김');
    });

    // @scenario case=login_merge
    // @effects guest_list_merged_then_cleared_on_login
    it('부팅 때 이미 회원이면 시작하면서 병합한다', async () => {
        const storage = memoryStorage({ [SEARCH_HISTORY_STORAGE_KEY]: JSON.stringify({ v: 1, autoSave: true, items: { 'core.site': [{ k: '이전', t: 5 }] } }) });
        const h = harness({ authenticated: true, storage });

        await h.runtime.start();

        expect(h.api.post).toHaveBeenCalledWith('/me/search-history/merge', { items: [{ scope: 'core.site', keyword: '이전', searched_at: 5 }] });
        expect(storage.raw()).toBeNull();
    });
});

describe('SearchHistoryRuntime — 회원', () => {
    // @scenario case=member_submit_records
    // @effects member_record_goes_to_server_and_updates_cache
    it('회원은 서버에 기록하고, 받은 목록 캐시 맨 앞에 넣는다 (브라우저에는 쓰지 않는다)', async () => {
        const h = harness({ authenticated: true });
        h.api.get.mockResolvedValueOnce({ success: true, data: { enabled: true, auto_save: true, max_count: 3, items: [{ id: 7, keyword: '이전', searched_at_ms: 10 }] } });
        h.api.post.mockResolvedValueOnce({ success: true, data: { recorded: true, item: { id: 8, keyword: '새것', searched_at_ms: 20 } } });

        await h.runtime.list('core.site');
        expect(await h.runtime.record('core.site', ' 새것 ')).toBe(true);

        expect(h.api.post).toHaveBeenCalledWith('/me/search-history', { scope: 'core.site', keyword: '새것' });
        expect(h.runtime.getSnapshot('core.site').items.map((it) => it.keyword)).toEqual(['새것', '이전']);
        expect(h.storage.setItem).not.toHaveBeenCalled();

        expect(await h.runtime.remove('core.site', 8)).toBe(true);
        expect(h.api.delete).toHaveBeenCalledWith('/me/search-history/8');
        expect(h.runtime.getSnapshot('core.site').items.map((it) => it.keyword)).toEqual(['이전']);
    });

    // @scenario case=auto_save_off
    // @effects auto_save_off_stops_recording
    it('서버가 기록하지 않았다고 답하면(자동 저장 꺼짐) false 이고 캐시를 비워 다시 받게 한다', async () => {
        const h = harness({ authenticated: true });
        await h.runtime.list('core.site');
        h.api.post.mockResolvedValueOnce({ success: true, data: { recorded: false, item: null } });

        expect(await h.runtime.record('core.site', '꺼짐')).toBe(false);
        expect(h.runtime.getSnapshot('core.site').loading).toBe(true);
    });

    // @scenario case=login_merge
    // @effects logout_switches_back_to_guest_store
    it('받는 사이 계정이 바뀌면(로그아웃 → 다른 회원 로그인) 앞 계정 목록을 캐시에 넣지 않는다', async () => {
        const h = harness({ authenticated: true });
        await h.runtime.start();
        let release: (value: unknown) => void = () => {};
        h.api.get.mockImplementationOnce(() => new Promise((resolve) => (release = resolve)));

        const pending = h.runtime.list('core.site');
        h.setAuth({ isAuthenticated: false });
        h.setAuth({ isAuthenticated: true });
        release({ success: true, data: { enabled: true, auto_save: true, max_count: 3, items: [{ id: 1, keyword: '앞 계정', searched_at_ms: 1 }] } });
        await pending;

        expect(h.runtime.getSnapshot('core.site').loading).toBe(true);
        expect(h.runtime.getSnapshot('core.site').items).toEqual([]);
    });

    // @scenario case=login_merge
    // @effects logout_switches_back_to_guest_store
    it('로그아웃하면 회원 캐시를 버리고 비회원 저장소로 돌아간다', async () => {
        const h = harness({ authenticated: true });
        await h.runtime.start();
        await h.runtime.list('core.site');

        h.setAuth({ isAuthenticated: false });

        expect(h.changes.at(-1)).toEqual({ scope: null, reason: 'auth' });
        expect(h.runtime.getSnapshot('core.site').source).toBe('guest');
    });
});
