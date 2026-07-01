/**
 * GDPR functional 카테고리 cleanup 함수 (functionalCleaner — Phase 2 단순화)
 *
 * 사용자가 functional 동의를 철회하거나 부팅 시 functional 미동의 상태인 경우,
 * **strictly necessary allowlist 외 모든** localStorage / sessionStorage / cookie
 * 를 즉시 파기. EDPB Guidelines 05/2020 §117 "동의 철회 시 즉시 중단" 충족.
 *
 * cleanup 정책 (Phase 2 단순화):
 *   - 운영자 등록 표 (functional_storage_keys / functional_cookies) 불필요
 *   - strictly necessary allowlist (코드 상수) 외 모든 키/이름을 자동 파기
 *   - GDPR 원칙 "strictly necessary 외 비-필수는 동의 전 차단" 의 cleanup 측 적용
 *
 * 본 함수는 인터셉터 install 이후 호출되어도 안전 — removeItem 은 인터셉터가
 * 통과시키며, cookie 파기 패턴 (Max-Age=0) 도 cookieInterceptor 의 `isClearingCookie`
 * 가드로 통과.
 *
 * @module sirsoft-gdpr/functionalCleaner
 */

import {
    DEFAULT_NECESSARY_COOKIE_ALLOWLIST,
} from './cookieInterceptor';
import {
    DEFAULT_NECESSARY_ALLOWLIST,
    type NecessaryAllowlistEntry,
    type StorageKind,
} from './storageInterceptor';

/**
 * cleanup 옵션 (선택).
 *
 * @property storageAllowlist  strictly necessary storage allowlist (기본: DEFAULT_NECESSARY_ALLOWLIST)
 * @property cookieAllowlist   strictly necessary cookie allowlist (기본: DEFAULT_NECESSARY_COOKIE_ALLOWLIST)
 */
export interface FunctionalCleanupOptions {
    storageAllowlist?: readonly NecessaryAllowlistEntry[];
    cookieAllowlist?: readonly string[];
}

/**
 * 키가 strictly necessary storage allowlist 에 매칭되는지 검사합니다.
 *
 * @param  key  스토리지 키
 * @param  storage  호출 스토리지 종류
 * @param  allowlist  allowlist 항목 배열
 * @return 매칭 여부
 */
function isNecessaryStorage(
    key: string,
    storage: StorageKind,
    allowlist: readonly NecessaryAllowlistEntry[],
): boolean {
    for (const entry of allowlist) {
        if (entry.storage && entry.storage !== storage) {
            continue;
        }
        const matchType = entry.matchType ?? 'exact';
        if (matchType === 'exact' && entry.key === key) {
            return true;
        }
        if (matchType === 'prefix' && key.startsWith(entry.key)) {
            return true;
        }
    }
    return false;
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
    allowlist: readonly NecessaryAllowlistEntry[],
): void {
    const keys: string[] = [];
    for (let i = 0; i < storage.length; i++) {
        const k = storage.key(i);
        if (k !== null) keys.push(k);
    }

    for (const key of keys) {
        if (isNecessaryStorage(key, storageKind, allowlist)) {
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
 * @param  allowlist  necessary cookie allowlist (이름 배열)
 * @return void
 */
function purgeCookies(allowlist: readonly string[]): void {
    const raw = document.cookie;
    if (!raw) return;

    const cookies = raw.split(';');
    for (const cookie of cookies) {
        const eq = cookie.indexOf('=');
        const name = (eq > -1 ? cookie.substring(0, eq) : cookie).trim();
        if (!name) continue;
        if (allowlist.includes(name)) continue;

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
    const storageAllowlist = options.storageAllowlist ?? DEFAULT_NECESSARY_ALLOWLIST;
    const cookieAllowlist = options.cookieAllowlist ?? DEFAULT_NECESSARY_COOKIE_ALLOWLIST;

    purgeStorage(window.localStorage, 'localStorage', storageAllowlist);
    purgeStorage(window.sessionStorage, 'sessionStorage', storageAllowlist);
    purgeCookies(cookieAllowlist);
}