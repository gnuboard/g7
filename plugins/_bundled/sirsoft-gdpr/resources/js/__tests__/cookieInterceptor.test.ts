/**
 * cookieInterceptor 단위 테스트 (Phase 2 단순화).
 *
 * Document.prototype.cookie setter 가로채기 + 게이팅 규칙 검증:
 *   1. cleared cookie (Max-Age=0 / expires 과거) → 항상 통과 (§117 충돌 회피)
 *   2. strictly necessary 허용목록 → 통과 (와일드카드 포함)
 *   3. functional 동의 → 통과
 *   4. user-initiated (WP29 §3.6) 면제 → 사용자 인터랙션 직후 통과
 *   5. 그 외 → 차단
 *
 * 허용목록은 운영자 설정이므로 목록을 주입해 검증한다.
 *
 * 주의: jsdom 의 document.cookie 는 navigation 별 상태 — 테스트마다 clean 처리.
 *
 * @scenario scope=cookie, notation=wildcard, locked=operator_item, settings_state=populated, request=valid_item
 * @effects cookie_allows_item_listed_in_settings, cookie_blocks_item_removed_from_settings, cookie_wildcard_matches_prefix_only, cookie_scope_does_not_borrow_storage_scope, cookie_config_update_applies_new_allowlist
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import {
    installCookieInterceptor,
    isCookieAllowed,
    uninstallCookieInterceptor,
    updateCookieInterceptorConfig,
} from '../cookieInterceptor';
import { LOCKED_FALLBACK, type NecessaryAllowlist } from '../necessaryAllowlist';
import { __setLastInteractionForTest, installUserInitiatedTracker, uninstallUserInitiatedTracker } from '../userInitiatedTracker';

/** document.cookie 에서 특정 이름의 값 추출 (없으면 null) */
function getCookieValue(name: string): string | null {
    const match = document.cookie.split('; ').find((row) => row.startsWith(`${name}=`));
    return match ? match.substring(name.length + 1) : null;
}

/** 모든 cookie 파기 — uninstall 상태에서만 호출 */
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

describe('cookieInterceptor', () => {
    beforeEach(() => {
        installUserInitiatedTracker();
        clearAllCookies();
    });

    afterEach(() => {
        uninstallCookieInterceptor();
        uninstallUserInitiatedTracker();
        clearAllCookies();
    });

    it('잠금 cookie (XSRF-TOKEN) → 항상 통과', () => {
        installCookieInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist(),
        });

        document.cookie = 'XSRF-TOKEN=abc; path=/';
        expect(getCookieValue('XSRF-TOKEN')).toBe('abc');
    });

    it('설정에 등재한 cookie 는 통과한다', () => {
        installCookieInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist({ cookie: ['laravel_maintenance'] }),
        });

        document.cookie = 'laravel_maintenance=on; path=/';
        expect(getCookieValue('laravel_maintenance')).toBe('on');
    });

    it('설정에서 뺀 cookie 는 차단된다 (목록이 판정에 쓰인다는 증거)', () => {
        installCookieInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist({ cookie: [] }),
        });

        document.cookie = 'laravel_maintenance=on; path=/';
        expect(getCookieValue('laravel_maintenance')).toBeNull();
    });

    // 발견 ②: 쿠키 목록만 `includes()` 정확 일치 전용이라 운영자가 쿠키 카드에 적은
    // `myplugin_*` 이 저장소 카드와 달리 아무 효과가 없었다.
    it('cookie 도 와일드카드로 통과한다 (저장소와 동일 규칙)', () => {
        installCookieInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist({ cookie: ['myplugin_*'] }),
        });

        document.cookie = 'myplugin_state=value; path=/';
        expect(getCookieValue('myplugin_state')).toBe('value');
    });

    it('와일드카드는 앞부분만 매칭한다 — 접두사가 다르면 차단', () => {
        installCookieInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist({ cookie: ['myplugin_*'] }),
        });

        document.cookie = 'other_myplugin_state=value; path=/';
        expect(getCookieValue('other_myplugin_state')).toBeNull();
    });

    it('저장소 스코프에만 등재한 이름은 cookie 로 통과하지 않는다', () => {
        installCookieInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist({ localStorage: ['scoped_name'], cookie: [] }),
        });

        document.cookie = 'scoped_name=value; path=/';
        expect(getCookieValue('scoped_name')).toBeNull();
    });

    it('functional 동의 시 모든 cookie 통과', () => {
        installCookieInterceptor({
            functionalConsented: true,
            necessaryAllowlist: allowlist(),
        });

        document.cookie = 'app_pref=value; path=/';
        expect(getCookieValue('app_pref')).toBe('value');
    });

    it('미동의 + user-initiated (WP29 §3.6) → 통과', () => {
        installCookieInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist(),
        });

        __setLastInteractionForTest(Date.now());
        document.cookie = 'app_pref=value; path=/';
        expect(getCookieValue('app_pref')).toBe('value');
    });

    it('미동의 + 비-사용자 → 차단', () => {
        installCookieInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist(),
        });

        // user-initiated 미발생
        document.cookie = 'app_pref=value; path=/';
        expect(getCookieValue('app_pref')).toBeNull();
    });

    it('미동의 + 임의 cookie + 비-사용자 → 차단', () => {
        installCookieInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist(),
        });

        document.cookie = 'random_cookie=value; path=/';
        expect(getCookieValue('random_cookie')).toBeNull();
    });

    it('파싱 불가 cookie 문자열 → 차단 (보수적)', () => {
        installCookieInterceptor({
            functionalConsented: true,
            necessaryAllowlist: allowlist(),
        });

        document.cookie = 'malformed_no_equals';
        expect(document.cookie).not.toContain('malformed_no_equals');
    });

    it('cleared cookie (Max-Age=0) → 미동의여도 통과 (§117 충돌 회피)', () => {
        // 미동의 상태에서도 파기 cookie 발송은 허용
        installCookieInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist(),
        });

        // 사전 저장된 cookie (uninstall 상태에서 미리 설정)
        // jsdom 에선 인터셉터 install 전에 직접 cookie 설정이 가능하다고 보장 안 됨 — isCookieAllowed 로 정책 검증
        // (실제 cookie 파기 효과는 functionalCleaner 테스트에서 검증)
        expect(isCookieAllowed('app_pref=; Max-Age=0; Path=/')).toBe(true);
    });

    it('updateCookieInterceptorConfig 으로 동의 갱신 → 후속 쓰기 통과', () => {
        installCookieInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist(),
        });

        // 미동의 + 비-사용자 → 차단
        document.cookie = 'app_pref=v1; path=/';
        expect(getCookieValue('app_pref')).toBeNull();

        // 동의 갱신
        updateCookieInterceptorConfig({
            functionalConsented: true,
            necessaryAllowlist: allowlist(),
        });
        document.cookie = 'app_pref=v2; path=/';
        expect(getCookieValue('app_pref')).toBe('v2');
    });

    it('updateCookieInterceptorConfig 으로 허용목록 갱신이 판정에 즉시 반영된다', () => {
        installCookieInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist({ cookie: [] }),
        });

        document.cookie = 'operator_added=v1; path=/';
        expect(getCookieValue('operator_added')).toBeNull();

        updateCookieInterceptorConfig({
            functionalConsented: false,
            necessaryAllowlist: allowlist({ cookie: ['operator_added'] }),
        });
        document.cookie = 'operator_added=v2; path=/';
        expect(getCookieValue('operator_added')).toBe('v2');
    });

    it('uninstall 후 원본 setter 복원 — 모든 쓰기 통과', () => {
        installCookieInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist(),
        });

        document.cookie = 'random=v1; path=/';
        expect(getCookieValue('random')).toBeNull();

        uninstallCookieInterceptor();
        document.cookie = 'random=v2; path=/';
        expect(getCookieValue('random')).toBe('v2');
    });

    it('isCookieAllowed — 정책 평가 함수는 사이드 이펙트 없음', () => {
        installCookieInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist(),
        });

        __setLastInteractionForTest(Date.now());
        expect(isCookieAllowed('app_pref=v')).toBe(true);            // user-initiated
        expect(isCookieAllowed('XSRF-TOKEN=t')).toBe(true);          // necessary
        expect(isCookieAllowed('malformed')).toBe(false);            // 파싱 불가

        // user-initiated 만료
        __setLastInteractionForTest(0);
        expect(isCookieAllowed('app_pref=v')).toBe(false);           // 미동의 + 비-사용자
        expect(isCookieAllowed('XSRF-TOKEN=t')).toBe(true);          // necessary 는 항상
        expect(isCookieAllowed('any=; Max-Age=0; Path=/')).toBe(true); // cleared 항상 통과

        // 함수 호출만으로 cookie 변경 X
        expect(getCookieValue('app_pref')).toBeNull();
    });
});
