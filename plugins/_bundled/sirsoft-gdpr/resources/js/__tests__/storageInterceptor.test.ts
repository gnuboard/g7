/**
 * storageInterceptor 단위 테스트 (Phase 2 단순화).
 *
 * Storage.prototype.setItem 가로채기 + 4단계 게이팅 규칙 검증:
 *   1. strictly necessary allowlist 항상 통과
 *   2. functional 동의 시 모든 키 통과
 *   3. user-initiated (WP29 §3.6) 면제 — 사용자 인터랙션 직후 통과
 *   4. 그 외 → 차단
 *
 * "운영자 등록 표" 는 제거됨 (Phase 2 단순화).
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import {
    DEFAULT_NECESSARY_ALLOWLIST,
    installStorageInterceptor,
    isStorageAllowed,
    uninstallStorageInterceptor,
    updateStorageInterceptorConfig,
} from '../storageInterceptor';
import { __setLastInteractionForTest, installUserInitiatedTracker, uninstallUserInitiatedTracker } from '../userInitiatedTracker';

describe('storageInterceptor', () => {
    beforeEach(() => {
        installUserInitiatedTracker();
        window.localStorage.clear();
        window.sessionStorage.clear();
    });

    afterEach(() => {
        uninstallStorageInterceptor();
        uninstallUserInitiatedTracker();
    });

    it('strictly necessary 키 (g7_locale) → 항상 통과 (미동의여도)', () => {
        installStorageInterceptor({
            functionalConsented: false,
            necessaryAllowlist: DEFAULT_NECESSARY_ALLOWLIST,
        });

        window.localStorage.setItem('g7_locale', 'ko');
        expect(window.localStorage.getItem('g7_locale')).toBe('ko');
    });

    it('prefix 매칭 (g7_devtools_*) → 통과', () => {
        installStorageInterceptor({
            functionalConsented: false,
            necessaryAllowlist: DEFAULT_NECESSARY_ALLOWLIST,
        });

        window.localStorage.setItem('g7_devtools_filter', 'enabled');
        expect(window.localStorage.getItem('g7_devtools_filter')).toBe('enabled');
    });

    it('functional 동의 시 모든 키 통과 (allowlist 외 키도 통과)', () => {
        installStorageInterceptor({
            functionalConsented: true,
            necessaryAllowlist: DEFAULT_NECESSARY_ALLOWLIST,
        });

        window.localStorage.setItem('app_pref', 'value');
        expect(window.localStorage.getItem('app_pref')).toBe('value');
    });

    it('미동의 + user-initiated (WP29 §3.6) → 통과', () => {
        installStorageInterceptor({
            functionalConsented: false,
            necessaryAllowlist: DEFAULT_NECESSARY_ALLOWLIST,
        });

        __setLastInteractionForTest(Date.now());
        window.localStorage.setItem('app_pref', 'value');
        expect(window.localStorage.getItem('app_pref')).toBe('value');
    });

    it('미동의 + 비-사용자 (background) → 차단', () => {
        installStorageInterceptor({
            functionalConsented: false,
            necessaryAllowlist: DEFAULT_NECESSARY_ALLOWLIST,
        });

        // user-initiated 미발생 (timestamp=0)
        window.localStorage.setItem('app_pref', 'value');
        expect(window.localStorage.getItem('app_pref')).toBeNull();
    });

    it('미동의 + 임의 키 + 비-사용자 → 차단 (보수적)', () => {
        installStorageInterceptor({
            functionalConsented: false,
            necessaryAllowlist: DEFAULT_NECESSARY_ALLOWLIST,
        });

        window.localStorage.setItem('random_key', 'value');
        expect(window.localStorage.getItem('random_key')).toBeNull();
    });

    it('updateStorageInterceptorConfig 으로 동의 상태 갱신 가능', () => {
        installStorageInterceptor({
            functionalConsented: false,
            necessaryAllowlist: DEFAULT_NECESSARY_ALLOWLIST,
        });

        // 미동의 + 비-사용자 → 차단
        window.localStorage.setItem('app_pref', 'value1');
        expect(window.localStorage.getItem('app_pref')).toBeNull();

        // 동의 갱신
        updateStorageInterceptorConfig({
            functionalConsented: true,
            necessaryAllowlist: DEFAULT_NECESSARY_ALLOWLIST,
        });
        window.localStorage.setItem('app_pref', 'value2');
        expect(window.localStorage.getItem('app_pref')).toBe('value2');
    });

    it('uninstall 후 원본 setItem 복원 — 모든 쓰기 통과', () => {
        installStorageInterceptor({
            functionalConsented: false,
            necessaryAllowlist: DEFAULT_NECESSARY_ALLOWLIST,
        });

        // 인터셉터 활성 시 차단
        window.localStorage.setItem('random_key', 'v1');
        expect(window.localStorage.getItem('random_key')).toBeNull();

        // uninstall 후 정상 동작
        uninstallStorageInterceptor();
        window.localStorage.setItem('random_key', 'v2');
        expect(window.localStorage.getItem('random_key')).toBe('v2');
    });

    it('isStorageAllowed — 정책 평가 함수는 사이드 이펙트 없음', () => {
        installStorageInterceptor({
            functionalConsented: false,
            necessaryAllowlist: DEFAULT_NECESSARY_ALLOWLIST,
        });

        __setLastInteractionForTest(Date.now());
        expect(isStorageAllowed('app_pref', 'localStorage')).toBe(true);       // user-initiated 면제
        expect(isStorageAllowed('g7_locale', 'localStorage')).toBe(true);      // necessary

        // user-initiated 가 만료된 시점엔 차단
        __setLastInteractionForTest(0);
        expect(isStorageAllowed('app_pref', 'localStorage')).toBe(false);
        expect(isStorageAllowed('g7_locale', 'localStorage')).toBe(true);      // necessary 는 항상 통과

        // 함수 호출만으로 storage 변경 X
        expect(window.localStorage.getItem('app_pref')).toBeNull();
    });
});