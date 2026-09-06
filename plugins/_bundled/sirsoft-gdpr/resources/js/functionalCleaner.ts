/**
 * GDPR functional 카테고리 cleanup 함수 (functionalCleaner — Phase 2 단순화)
 *
 * 사용자가 functional 동의를 철회하거나 부팅 시 functional 미동의 상태인 경우,
 * **strictly necessary allowlist 외 모든** localStorage / sessionStorage / cookie
 * 를 즉시 파기. EDPB Guidelines 05/2020 §117 "동의 철회 시 즉시 중단" 충족.
 *
 * cleanup 정책 (Phase 2 단순화):
 *   - 운영자 등록 표 (functional_storage_keys / functional_cookies) 불필요
 *   - strictly necessary 허용목록(운영자 설정 ∪ 잠금 집합) 외 모든 키/이름을 자동 파기
 *   - GDPR 원칙 "strictly necessary 외 비-필수는 동의 전 차단" 의 cleanup 측 적용
 *
 * 본 함수는 인터셉터 install 이후 호출되어도 안전 — removeItem 은 인터셉터가
 * 통과시키며, cookie 파기 패턴 (Max-Age=0) 도 cookieInterceptor 의 `isClearingCookie`
 * 가드로 통과.
 *
 * @module sirsoft-gdpr/functionalCleaner
 */

import {
    LOCKED_FALLBACK,
    isNecessary,
    type NecessaryAllowlist,
} from './necessaryAllowlist';
import { type StorageKind } from './storageInterceptor';

/**
 * cleanup 옵션 (선택).
 *
 * @property allowlist  strictly necessary 허용목록 (운영자 설정 ∪ 잠금 집합).
 *                      생략 시 잠금 집합만 남는 최소 폴백 — 설정을 읽지 못한 상황에서도
 *                      로그인 토큰·CSRF·동의 쿠키는 파기하지 않는다.
 */
export interface FunctionalCleanupOptions {
    allowlist?: NecessaryAllowlist;
}

/**
 * 한 Storage 인스턴스에서 strictly necessary allowlist 외 모든 키를 파기합니다.
 *
 * 순회 중 removeItem 으로 storage.length 가 줄어드는 문제를 피하기 위해 키 목록을 먼저 수집.
 *
 * @param  storage  대상 Storage (localStorage 또는 sessionStorage)
 * @param  storageKind  storage 종류 (allowlist 매칭용)
 * @param  allowlist  necessary allowlist
 * @return void
 */
function purgeStorage(
    storage: Storage,
    storageKind: StorageKind,
    allowlist: NecessaryAllowlist,
): void {
    const keys: string[] = [];
    for (let i = 0; i < storage.length; i++) {
        const k = storage.key(i);
        if (k !== null) keys.push(k);
    }

    for (const key of keys) {
        if (isNecessary(key, storageKind, allowlist)) {
            continue;
        }
        try {
            storage.removeItem(key);
        } catch {
            // SecurityError / QuotaExceeded 등 — 조용히 무시.
        }
    }
}

/**
 * document.cookie 에서 strictly necessary allowlist 외 모든 cookie 를 Max-Age=0 으로 파기합니다.
 *
 * @param  allowlist  necessary 허용목록 (`cookie` 스코프만 사용)
 * @return void
 */
function purgeCookies(allowlist: NecessaryAllowlist): void {
    const raw = document.cookie;
    if (!raw) return;

    const cookies = raw.split(';');
    for (const cookie of cookies) {
        const eq = cookie.indexOf('=');
        const name = (eq > -1 ? cookie.substring(0, eq) : cookie).trim();
        if (!name) continue;
        if (isNecessary(name, 'cookie', allowlist)) continue;

        // 표준 cookie 파기 패턴 — Max-Age=0 + 빈 값 + Path=/
        // cookieInterceptor 의 isClearingCookie 가드로 통과.
        try {
            document.cookie = `${name}=; Max-Age=0; Path=/`;
        } catch {
            // 무시.
        }
    }
}

/**
 * functional 카테고리 cleanup — strictly necessary allowlist 외 모든 1st-party 저장소 파기.
 *
 * 부팅 시점 (재방문 + 미동의) 또는 동의 철회 시점에 호출.
 *
 * @param  options  옵션 (생략 시 기본 allowlist 사용)
 * @return void
 */
export function cleanupFunctionalArtifacts(options: FunctionalCleanupOptions = {}): void {
    const allowlist = options.allowlist ?? LOCKED_FALLBACK;

    purgeStorage(window.localStorage, 'localStorage', allowlist);
    purgeStorage(window.sessionStorage, 'sessionStorage', allowlist);
    purgeCookies(allowlist);
}