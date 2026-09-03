/**
 * functionalCleaner 단위 테스트 (Phase 2 단순화).
 *
 * cleanup 동작 검증 — strictly necessary 허용목록 외 모든 1st-party 저장소 파기:
 *   - 허용목록 외 모든 localStorage / sessionStorage 키 removeItem
 *   - 허용목록 외 모든 cookie Max-Age=0 파기
 *   - 허용목록 항목은 보존 (와일드카드 포함)
 *   - **허용목록을 못 읽어도 잠금 항목은 보존** (설정 미도달 시 안전 기본값)
 *
 * 허용목록은 운영자 설정이므로 이 테스트는 목록을 주입해 검증한다. 목록을 코드 상수에서
 * 읽던 시절에는 "설정을 바꿔도 판정이 안 바뀌는" 회귀를 이 테스트가 잡지 못했다.
 *
 * @scenario scope=localStorage, notation=wildcard, locked=locked_item, settings_state=empty, request=valid_item
 * @effects cleaner_preserves_items_listed_in_settings, cleaner_purges_items_removed_from_settings, cleaner_cookie_wildcard_preserves_prefix_match, cleaner_scope_does_not_leak_across_storages, cleaner_locked_items_survive_missing_settings
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { cleanupFunctionalArtifacts } from '../functionalCleaner';
import { LOCKED_FALLBACK, type NecessaryAllowlist } from '../necessaryAllowlist';

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

/**
 * 운영자 설정 + 잠금 집합을 흉내 낸 허용목록.
 *
 * @param  overrides  스코프별 덮어쓸 목록
 * @return 허용목록
 */
function allowlist(overrides: Partial<NecessaryAllowlist> = {}): NecessaryAllowlist {
    return {
        localStorage: overrides.localStorage ?? [...LOCKED_FALLBACK.localStorage],
        sessionStorage: overrides.sessionStorage ?? [...LOCKED_FALLBACK.sessionStorage],
        cookie: overrides.cookie ?? [...LOCKED_FALLBACK.cookie],
    };
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

    it('허용목록 외 localStorage 키 파기', () => {
        window.localStorage.setItem('app_pref', 'value');

        cleanupFunctionalArtifacts({ allowlist: allowlist() });

        expect(window.localStorage.getItem('app_pref')).toBeNull();
    });

    it('허용목록 외 sessionStorage 키 파기', () => {
        window.sessionStorage.setItem('app_session_pref', 'value');

        cleanupFunctionalArtifacts({ allowlist: allowlist() });

        expect(window.sessionStorage.getItem('app_session_pref')).toBeNull();
    });

    it('설정에 등재된 키 (g7_locale) 는 보존', () => {
        window.localStorage.setItem('g7_locale', 'ko');
        window.localStorage.setItem('app_pref', 'value');

        cleanupFunctionalArtifacts({ allowlist: allowlist({ localStorage: ['g7_locale'] }) });

        expect(window.localStorage.getItem('g7_locale')).toBe('ko');
        expect(window.localStorage.getItem('app_pref')).toBeNull();
    });

    // dev-g7#640: 동의 철회 정리에서도 테마는 언어 설정과 같이 남아야 한다.
    it('설정에 등재된 키 (g7_color_scheme) 는 보존', () => {
        window.localStorage.setItem('g7_color_scheme', 'dark');
        window.localStorage.setItem('app_pref', 'value');

        cleanupFunctionalArtifacts({
            allowlist: allowlist({ localStorage: ['g7_color_scheme'] }),
        });

        expect(window.localStorage.getItem('g7_color_scheme')).toBe('dark');
        expect(window.localStorage.getItem('app_pref')).toBeNull();
    });

    it('와일드카드 항목 (g7_devtools_*) 은 보존', () => {
        window.localStorage.setItem('g7_devtools_filter', 'enabled');
        window.localStorage.setItem('app_pref', 'value');

        cleanupFunctionalArtifacts({ allowlist: allowlist({ localStorage: ['g7_devtools_*'] }) });

        expect(window.localStorage.getItem('g7_devtools_filter')).toBe('enabled');
        expect(window.localStorage.getItem('app_pref')).toBeNull();
    });

    it('스코프는 서로 넘나들지 않는다 — localStorage 에만 등재한 키는 sessionStorage 에서 파기', () => {
        window.localStorage.setItem('scoped_key', 'kept');
        window.sessionStorage.setItem('scoped_key', 'purged');

        cleanupFunctionalArtifacts({ allowlist: allowlist({ localStorage: ['scoped_key'] }) });

        expect(window.localStorage.getItem('scoped_key')).toBe('kept');
        expect(window.sessionStorage.getItem('scoped_key')).toBeNull();
    });

    it('허용목록 외 cookie 파기', () => {
        document.cookie = 'app_pref_cookie=value; path=/';
        expect(getCookieValue('app_pref_cookie')).toBe('value');

        cleanupFunctionalArtifacts({ allowlist: allowlist() });

        expect(getCookieValue('app_pref_cookie')).toBeNull();
    });

    it('잠금 cookie (XSRF-TOKEN) 는 보존', () => {
        document.cookie = 'XSRF-TOKEN=safe; path=/';
        document.cookie = 'app_pref_cookie=value; path=/';

        cleanupFunctionalArtifacts({ allowlist: allowlist() });

        expect(getCookieValue('XSRF-TOKEN')).toBe('safe');
        expect(getCookieValue('app_pref_cookie')).toBeNull();
    });

    // 발견 ②: 쿠키 목록만 정확 일치 전용이던 시절에는 운영자가 쿠키 카드에 적은
    // `myplugin_*` 이 저장소 카드와 달리 동작하지 않았다.
    it('cookie 도 와일드카드로 보존된다 (저장소와 동일 규칙)', () => {
        document.cookie = 'myplugin_state=value; path=/';
        document.cookie = 'other_cookie=value; path=/';

        cleanupFunctionalArtifacts({ allowlist: allowlist({ cookie: ['myplugin_*'] }) });

        expect(getCookieValue('myplugin_state')).toBe('value');
        expect(getCookieValue('other_cookie')).toBeNull();
    });

    it('storage + cookie 동시 파기 (허용목록 외 전체)', () => {
        window.localStorage.setItem('app_pref', 'value');
        window.localStorage.setItem('g7_locale', 'ko');
        document.cookie = 'app_pref_cookie=value; path=/';
        document.cookie = 'XSRF-TOKEN=safe; path=/';

        cleanupFunctionalArtifacts({ allowlist: allowlist({ localStorage: ['g7_locale'] }) });

        expect(window.localStorage.getItem('app_pref')).toBeNull();
        expect(window.localStorage.getItem('g7_locale')).toBe('ko');
        expect(getCookieValue('app_pref_cookie')).toBeNull();
        expect(getCookieValue('XSRF-TOKEN')).toBe('safe');
    });

    it('빈 storage 상태 — silent (예외 없음)', () => {
        expect(() => {
            cleanupFunctionalArtifacts({ allowlist: allowlist() });
        }).not.toThrow();
    });

    // 함정 4: 인라인 페이로드가 도달하지 않으면 허용목록이 통째로 비는데, 그 상태에서
    // 로그인 토큰까지 파기되면 동의 없는 첫 방문자가 로그인을 유지하지 못한다.
    it('허용목록을 주지 않아도 잠금 항목은 보존한다 (설정 미도달 폴백)', () => {
        window.localStorage.setItem('auth_token', 'token');
        window.localStorage.setItem('app_pref', 'value');
        document.cookie = 'XSRF-TOKEN=safe; path=/';
        document.cookie = 'gdpr_session=sess; path=/';
        document.cookie = 'app_pref_cookie=value; path=/';

        cleanupFunctionalArtifacts();

        expect(window.localStorage.getItem('auth_token')).toBe('token');
        expect(window.localStorage.getItem('app_pref')).toBeNull();
        expect(getCookieValue('XSRF-TOKEN')).toBe('safe');
        expect(getCookieValue('gdpr_session')).toBe('sess');
        expect(getCookieValue('app_pref_cookie')).toBeNull();
    });

    it('설정이 비어 있어도 잠금 항목은 보존한다 (빈 목록 주입)', () => {
        window.localStorage.setItem('auth_token', 'token');
        window.localStorage.setItem('g7_locale', 'ko');

        cleanupFunctionalArtifacts({
            allowlist: { localStorage: ['auth_token'], sessionStorage: [], cookie: [] },
        });

        expect(window.localStorage.getItem('auth_token')).toBe('token');
        // 설정에서 뺀 항목은 실제로 파기된다 — 설정이 판정에 쓰인다는 증거.
        expect(window.localStorage.getItem('g7_locale')).toBeNull();
    });
});
