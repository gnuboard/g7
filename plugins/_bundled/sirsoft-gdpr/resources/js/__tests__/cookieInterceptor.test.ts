/**
 * cookieInterceptor 단위 테스트 (Phase 2 단순화).
 *
 * Document.prototype.cookie setter 가로채기 + 4단계 게이팅 규칙 검증:
 *   1. cleared cookie (Max-Age=0 / expires 과거) → 항상 통과 (§117 충돌 회피)
 *   2. strictly necessary allowlist → 통과
 *   3. functional 동의 → 통과
 *   4. user-initiated (WP29 §3.6) 면제 → 사용자 인터랙션 직후 통과
 *   5. 그 외 → 차단
 *
 * "운영자 등록 표" 는 제거됨 (Phase 2 단순화).
 *
 * 주의: jsdom 의 document.cookie 는 navigation 별 상태 — 테스트마다 clean 처리.
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import {
    DEFAULT_NECESSARY_COOKIE_ALLOWLIST,
    installCookieInterceptor,
    isCookieAllowed,
    uninstallCookieInterceptor,
    updateCookieInterceptorConfig,
} from '../cookieInterceptor';
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

    it('strictly necessary cookie (XSRF-TOKEN) → 항상 통과', () => {
        installCookieInterceptor({
            functionalConsented: false,
            necessaryAllowlist: DEFAULT_NECESSARY_COOKIE_ALLOWLIST,
        });

        document.cookie = 'XSRF-TOKEN=abc; path=/';
        expect(getCookieValue('XSRF-TOKEN')).toBe('abc');
    });

    it('functional 동의 시 모든 cookie 통과', () => {
        installCookieInterceptor({
            functionalConsented: true,
            necessaryAllowlist: DEFAULT_NECESSARY_COOKIE_ALLOWLIST,
        });

        document.cookie = 'app_pref=value; path=/';
        expect(getCookieValue('app_pref')).toBe('value');
    });

    it('미동의 + user-initiated (WP29 §3.6) → 통과', () => {
        installCookieInterceptor({
            functionalConsented: false,
            necessaryAllowlist: DEFAULT_NECESSARY_COOKIE_ALLOWLIST,
        });

        __setLastInteractionForTest(Date.now());
        document.cookie = 'app_pref=value; path=/';
        expect(getCookieValue('app_pref')).toBe('value');
    });

    it('미동의 + 비-사용자 → 차단', () => {
        installCookieInterceptor({
            functionalConsented: false,
            necessaryAllowlist: DEFAULT_NECESSARY_COOKIE_ALLOWLIST,
        });

        // user-initiated 미발생
        document.cookie = 'app_pref=value; path=/';
        expect(getCookieValue('app_pref')).toBeNull();
    });

    it('미동의 + 임의 cookie + 비-사용자 → 차단', () => {
        installCookieInterceptor({
            functionalConsented: false,
            necessaryAllowlist: DEFAULT_NECESSARY_COOKIE_ALLOWLIST,
        });

        document.cookie = 'random_cookie=value; path=/';
        expect(getCookieValue('random_cookie')).toBeNull();
    });

    it('파싱 불가 cookie 문자열 → 차단 (보수적)', () => {
        installCookieInterceptor({
            functionalConsented: true,
            necessaryAllowlist: DEFAULT_NECESSARY_COOKIE_ALLOWLIST,
        });

        document.cookie = 'malformed_no_equals';
        expect(document.cookie).not.toContain('malformed_no_equals');
    });

    it('cleared cookie (Max-Age=0) → 미동의여도 통과 (§117 충돌 회피)', () => {
        // 미동의 상태에서도 파기 cookie 발송은 허용
        installCookieInterceptor({
            functionalConsented: false,
            necessaryAllowlist: DEFAULT_NECESSARY_COOKIE_ALLOWLIST,
        });

        // 사전 저장된 cookie (uninstall 상태에서 미리 설정)
        // jsdom 에선 인터셉터 install 전에 직접 cookie 설정이 가능하다고 보장 안 됨 — isCookieAllowed 로 정책 검증
        // (실제 cookie 파기 효과는 functionalCleaner 테스트에서 검증)
        expect(isCookieAllowed('app_pref=; Max-Age=0; Path=/')).toBe(true);
    });

    it('updateCookieInterceptorConfig 으로 동의 갱신 → 후속 쓰기 통과', () => {
        installCookieInterceptor({
            functionalConsented: false,
            necessaryAllowlist: DEFAULT_NECESSARY_COOKIE_ALLOWLIST,
        });

        // 미동의 + 비-사용자 → 차단
        document.cookie = 'app_pref=v1; path=/';
        expect(getCookieValue('app_pref')).toBeNull();

        // 동의 갱신
        updateCookieInterceptorConfig({
            functionalConsented: true,
            necessaryAllowlist: DEFAULT_NECESSARY_COOKIE_ALLOWLIST,
        });
        document.cookie = 'app_pref=v2; path=/';
        expect(getCookieValue('app_pref')).toBe('v2');
    });

    it('uninstall 후 원본 setter 복원 — 모든 쓰기 통과', () => {
        installCookieInterceptor({
            functionalConsented: false,
            necessaryAllowlist: DEFAULT_NECESSARY_COOKIE_ALLOWLIST,
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
            necessaryAllowlist: DEFAULT_NECESSARY_COOKIE_ALLOWLIST,
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