/**
 * functionalCleaner 단위 테스트 (Phase 2 단순화).
 *
 * cleanup 동작 검증 — strictly necessary allowlist 외 모든 1st-party 저장소 파기:
 *   - allowlist 외 모든 localStorage / sessionStorage 키 removeItem
 *   - allowlist 외 모든 cookie Max-Age=0 파기
 *   - allowlist 키/cookie 는 보존
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { cleanupFunctionalArtifacts } from '../functionalCleaner';

/** document.cookie 에서 특정 이름의 값 추출 */
function getCookieValue(name: string): string | null {
    const match = document.cookie.split('; ').find((row) => row.startsWith(`${name}=`));
    return match ? match.substring(name.length + 1) : null;
}

function clearAllCookies(): void {
    const cookies = document.cookie.split(';');
    for (const cookie of cookies) {
        const eq = cookie.indexOf('=');
        const name = (eq > -1 ? cookie.substring(0, eq) : cookie).trim();
        if (name) {
            document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:01 GMT; path=/`;
        }
    }
}

describe('functionalCleaner', () => {
    beforeEach(() => {
        window.localStorage.clear();
        window.sessionStorage.clear();
        clearAllCookies();
    });

    afterEach(() => {
        window.localStorage.clear();
        window.sessionStorage.clear();
        clearAllCookies();
    });

    it('allowlist 외 localStorage 키 파기', () => {
        window.localStorage.setItem('app_pref', 'value');

        cleanupFunctionalArtifacts();

        expect(window.localStorage.getItem('app_pref')).toBeNull();
    });

    it('allowlist 외 sessionStorage 키 파기', () => {
        window.sessionStorage.setItem('app_session_pref', 'value');

        cleanupFunctionalArtifacts();

        expect(window.sessionStorage.getItem('app_session_pref')).toBeNull();
    });

    it('strictly necessary 키 (g7_locale) 는 보존', () => {
        window.localStorage.setItem('g7_locale', 'ko');
        window.localStorage.setItem('app_pref', 'value');

        cleanupFunctionalArtifacts();

        expect(window.localStorage.getItem('g7_locale')).toBe('ko');
        expect(window.localStorage.getItem('app_pref')).toBeNull();
    });

    it('prefix 매칭 키 (g7_devtools_*) 는 보존', () => {
        window.localStorage.setItem('g7_devtools_filter', 'enabled');
        window.localStorage.setItem('app_pref', 'value');

        cleanupFunctionalArtifacts();

        expect(window.localStorage.getItem('g7_devtools_filter')).toBe('enabled');
        expect(window.localStorage.getItem('app_pref')).toBeNull();
    });

    it('allowlist 외 cookie 파기', () => {
        document.cookie = 'app_pref_cookie=value; path=/';
        expect(getCookieValue('app_pref_cookie')).toBe('value');

        cleanupFunctionalArtifacts();

        expect(getCookieValue('app_pref_cookie')).toBeNull();
    });

    it('strictly necessary cookie (XSRF-TOKEN) 는 보존', () => {
        document.cookie = 'XSRF-TOKEN=safe; path=/';
        document.cookie = 'app_pref_cookie=value; path=/';

        cleanupFunctionalArtifacts();

        expect(getCookieValue('XSRF-TOKEN')).toBe('safe');
        expect(getCookieValue('app_pref_cookie')).toBeNull();
    });

    it('storage + cookie 동시 파기 (allowlist 외 전체)', () => {
        window.localStorage.setItem('app_pref', 'value');
        window.localStorage.setItem('g7_locale', 'ko'); // allowlist 보존
        document.cookie = 'app_pref_cookie=value; path=/';
        document.cookie = 'XSRF-TOKEN=safe; path=/'; // allowlist 보존

        cleanupFunctionalArtifacts();

        expect(window.localStorage.getItem('app_pref')).toBeNull();
        expect(window.localStorage.getItem('g7_locale')).toBe('ko');
        expect(getCookieValue('app_pref_cookie')).toBeNull();
        expect(getCookieValue('XSRF-TOKEN')).toBe('safe');
    });

    it('빈 storage 상태 — silent (예외 없음)', () => {
        expect(() => {
            cleanupFunctionalArtifacts();
        }).not.toThrow();
    });

    it('커스텀 allowlist 옵션으로 보존 키 확장 가능', () => {
        window.localStorage.setItem('custom_protected', 'value');
        window.localStorage.setItem('app_pref', 'value');

        cleanupFunctionalArtifacts({
            storageAllowlist: [
                { key: 'custom_protected', storage: 'localStorage', matchType: 'exact' },
            ],
        });

        expect(window.localStorage.getItem('custom_protected')).toBe('value');
        expect(window.localStorage.getItem('app_pref')).toBeNull();
    });
});