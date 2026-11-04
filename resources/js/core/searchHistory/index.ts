/**
 * 최근 검색어 런타임 진입점
 *
 * 부팅 코드(TemplateApp)는 {@link installSearchHistoryRuntime} 하나만 부르고, 내장 핸들러 `recordSearchKeyword`·
 * `G7Core.searchHistory` 파사드·`G7Core.useSearchHistory` 훅은 {@link getSearchHistoryRuntime} 으로 같은 인스턴스를 찾는다.
 *
 * 런타임은 **사용자 템플릿에서만** 선다. 관리자 화면에는 검색창 최근 검색어가 없고, 만들지 않으면
 * `getSearchHistoryRuntime()` 은 null 이다(파사드는 모든 범위를 `enabled: false` 로 답한다).
 *
 * @since engine-v1.87.0
 */

import { getApiClient } from '../api/ApiClient';
import { AuthManager } from '../auth/AuthManager';
import { createLogger } from '../utils/Logger';
import {
    SEARCH_HISTORY_CHANGED_EVENT,
    SearchHistoryRuntime,
    type SearchHistoryChange,
    type SearchHistoryRuntimeDeps,
} from './SearchHistoryRuntime';

export {
    SEARCH_HISTORY_CHANGED_EVENT,
    SEARCH_HISTORY_KEYWORD_MAX_LENGTH,
    SEARCH_HISTORY_MERGE_MAX_ITEMS,
    SEARCH_HISTORY_STORAGE_KEY,
    SEARCH_HISTORY_STORE_VERSION,
    SearchHistoryRuntime,
    normalizeSearchKeyword,
} from './SearchHistoryRuntime';
export type {
    SearchHistoryApi,
    SearchHistoryAuthState,
    SearchHistoryChange,
    SearchHistoryItem,
    SearchHistoryRuntimeDeps,
    SearchHistoryState,
} from './SearchHistoryRuntime';

const logger = createLogger('SearchHistory');

let runtime: SearchHistoryRuntime | null = null;

/**
 * 현재 최근 검색어 런타임을 반환합니다 (관리자 화면이거나 아직 부팅 전이면 null).
 *
 * @returns 런타임 또는 null
 */
export function getSearchHistoryRuntime(): SearchHistoryRuntime | null {
    return runtime;
}

/** 사용자 템플릿 미리보기 주소 (`LayoutPreviewService::previewUrl` — `/preview/` + UUID 토큰) */
const PREVIEW_PATH_PATTERN = /^\/preview\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\/?$/i;

/**
 * 레이아웃 편집기 미리보기 화면인지 호출 시점에 판정합니다.
 *
 * 미리보기 모드는 런타임 설치 뒤 화면 처리에서 켜진다 — 디스패처 미리보기 모드·`_global.__isPreview` 를 보고, 그 둘이 켜지기 전
 * (부팅 병합)에는 시스템 미리보기 경로(`/preview/{UUID}`, 코어 `__preview__` 라우트)로 판정한다.
 * 경로는 사이트 맨 앞 + UUID 토큰만 인정한다 — 끝이 `/preview/{값}` 인 일반 화면(이름이 preview 인 게시판 글 등)의 기록을 막지 않는다.
 *
 * @returns 미리보기면 true
 */
export function isEditorPreview(): boolean {
    if (typeof window === 'undefined') return false;

    const G7Core = (window as any).G7Core;
    if (G7Core?.getActionDispatcher?.()?.isPreviewMode?.() === true) return true;
    if (G7Core?.state?.get?.()?.__isPreview === true) return true;

    return PREVIEW_PATH_PATTERN.test(window.location?.pathname ?? '');
}

/**
 * 기본 의존성을 만듭니다 — `G7Config.searchScopes`, 코어 ApiClient, localStorage, 컴포넌트 이벤트, AuthManager.
 *
 * @returns 의존성
 */
export function defaultSearchHistoryRuntimeDeps(): SearchHistoryRuntimeDeps {
    const auth = AuthManager.getInstance();

    let storage: Storage | null = null;
    try {
        storage = typeof window !== 'undefined' ? window.localStorage : null;
    } catch {
        storage = null;
    }

    return {
        getScopes: () => {
            const scopes = (window as any).G7Config?.searchScopes;

            return scopes && typeof scopes === 'object' && !Array.isArray(scopes) ? scopes : {};
        },
        api: getApiClient(),
        storage,
        emit: (change: SearchHistoryChange) => {
            const componentEvent = (window as any).G7Core?.componentEvent;
            if (typeof componentEvent?.emit === 'function') {
                // componentEvent.emit 은 리스너 예외를 다시 던진다 — 확장 구독자 하나가 던져도 처리되지 않은 거부로 남기지 않는다.
                Promise.resolve(componentEvent.emit(SEARCH_HISTORY_CHANGED_EVENT, change)).catch((error: unknown) => {
                    logger.warn('searchHistory:changed listener failed', error);
                });
            }
        },
        onAuthChange: (handler) => auth.on('authStateChange', (state: { isAuthenticated: boolean }) => handler(state)),
        getAuthState: () => auth.getState(),
        isPreview: isEditorPreview,
    };
}

/**
 * 최근 검색어 런타임을 만들고 시작합니다. 이미 있으면 그것을 돌려줍니다.
 *
 * @param deps 의존성
 * @returns 런타임
 */
export function installSearchHistoryRuntime(deps: SearchHistoryRuntimeDeps): SearchHistoryRuntime {
    if (runtime) return runtime;

    runtime = new SearchHistoryRuntime(deps);
    void runtime.start();

    return runtime;
}

/**
 * 테스트용 — 런타임을 내립니다.
 *
 * @returns 없음
 */
export function resetSearchHistoryRuntime(): void {
    runtime?.stop();
    runtime = null;
}
