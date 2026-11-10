/**
 * 최근 검색어 React 훅 — `G7Core.useSearchHistory(scope)`
 *
 * 검색창 컴포넌트(템플릿마다 자기 UI)가 범위 하나의 최근 검색어를 읽고 고친다. 같은 범위를 쓰는 검색창끼리
 * 목록을 공유한다(런타임 하나 + `searchHistory:changed` 구독).
 *
 * 반환 모양:
 * ```ts
 * {
 *   items: Array<{ id: number | null, keyword: string, searchedAt: number }>, // 최신순 (자동 저장을 끄면 [])
 *   autoSave: boolean,   // 자동 저장 여부 (끄면 기록 멈춤·목록 감춤)
 *   enabled: boolean,    // 이 범위를 쓸 수 있는지 (선언 범위·사용자 템플릿·기능 켬)
 *   loading: boolean,    // 회원 목록을 받는 중
 *   maxCount: number,    // 보관 개수
 *   record(keyword): Promise<boolean>,
 *   remove(itemOrIdOrKeyword): Promise<boolean>,
 *   clear(): Promise<boolean>,          // 이 범위 전체
 *   setAutoSave(on): Promise<boolean>,  // 사람 단위 (모든 범위)
 *   refresh(): Promise<void>,           // 회원 목록 다시 받기
 * }
 * ```
 *
 * 런타임이 없는 화면(관리자 템플릿)에서는 `enabled: false` 와 빈 목록이며 동작 함수는 false 를 돌려준다.
 *
 * @since engine-v1.87.0
 */

import { useCallback, useEffect, useRef, useState } from 'react';

import { getSearchHistoryRuntime } from './index';
import {
    SEARCH_HISTORY_CHANGED_EVENT,
    type SearchHistoryChange,
    type SearchHistoryItem,
    type SearchHistoryState,
} from './SearchHistoryRuntime';

/** 훅 반환값 */
export interface UseSearchHistoryResult {
    items: SearchHistoryItem[];
    autoSave: boolean;
    enabled: boolean;
    loading: boolean;
    maxCount: number;
    record: (keyword: string) => Promise<boolean>;
    remove: (target: SearchHistoryItem | number | string) => Promise<boolean>;
    clear: () => Promise<boolean>;
    setAutoSave: (autoSave: boolean) => Promise<boolean>;
    refresh: () => Promise<void>;
}

/**
 * 범위의 지금 상태를 읽습니다 (런타임이 없으면 꺼진 상태).
 *
 * @param scope 범위 id
 * @returns 상태
 */
function snapshotOf(scope: string): SearchHistoryState {
    const runtime = getSearchHistoryRuntime();
    if (!runtime) {
        return { scope, enabled: false, autoSave: true, items: [], maxCount: 0, loading: false, source: 'none' };
    }

    return runtime.getSnapshot(scope);
}

/**
 * 범위 하나의 최근 검색어를 구독합니다.
 *
 * @param scope 범위 id (예: `core.site`, `sirsoft-ecommerce.products`)
 * @returns 목록·상태·동작
 */
export function useSearchHistory(scope: string): UseSearchHistoryResult {
    const [state, setState] = useState<SearchHistoryState>(() => snapshotOf(scope));
    // 늦게 끝난 조회가 그 사이 바뀐 상태(기록·자동 저장 끄기)를 옛 값으로 덮지 않게 차례 번호로 거른다(refresh 포함).
    const versionRef = useRef(0);
    const aliveRef = useRef(true);

    useEffect(() => {
        let alive = true;
        aliveRef.current = true;
        const runtime = getSearchHistoryRuntime();

        setState(snapshotOf(scope));

        if (!runtime) {
            return () => {
                alive = false;
            };
        }

        const load = (force: boolean) => {
            const mine = ++versionRef.current;
            void runtime.list(scope, { force }).then((next) => {
                if (alive && mine === versionRef.current) setState(next);
            });
        };

        load(false);

        const componentEvent = (window as any).G7Core?.componentEvent;
        const off = typeof componentEvent?.on === 'function'
            ? componentEvent.on(SEARCH_HISTORY_CHANGED_EVENT, (change?: SearchHistoryChange) => {
                if (!alive) return;
                if (change?.scope && change.scope !== scope) return;

                const next = runtime.getSnapshot(scope);
                versionRef.current++;
                setState(next);
                // 회원 캐시가 비었으면(로그인·병합·자동 저장 변경) 다시 받는다.
                if (next.loading) load(false);
            })
            : null;

        return () => {
            alive = false;
            aliveRef.current = false;
            if (typeof off === 'function') off();
        };
    }, [scope]);

    const record = useCallback(async (keyword: string) => (await getSearchHistoryRuntime()?.record(scope, keyword)) ?? false, [scope]);
    const remove = useCallback(async (target: SearchHistoryItem | number | string) => (await getSearchHistoryRuntime()?.remove(scope, target)) ?? false, [scope]);
    const clear = useCallback(async () => (await getSearchHistoryRuntime()?.clear(scope)) ?? false, [scope]);
    const setAutoSave = useCallback(async (autoSave: boolean) => (await getSearchHistoryRuntime()?.setAutoSave(autoSave)) ?? false, []);
    const refresh = useCallback(async () => {
        const runtime = getSearchHistoryRuntime();
        if (!runtime) return;
        const mine = ++versionRef.current;
        const next = await runtime.list(scope, { force: true });
        if (aliveRef.current && mine === versionRef.current) setState(next);
    }, [scope]);

    return {
        items: state.items,
        autoSave: state.autoSave,
        enabled: state.enabled,
        loading: state.loading,
        maxCount: state.maxCount,
        record,
        remove,
        clear,
        setAutoSave,
        refresh,
    };
}
