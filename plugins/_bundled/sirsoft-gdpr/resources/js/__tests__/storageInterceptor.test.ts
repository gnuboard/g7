/**
 * storageInterceptor 단위 테스트 (Phase 2 단순화).
 *
 * Storage.prototype.setItem 가로채기 + 4단계 게이팅 규칙 검증:
 *   1. strictly necessary 허용목록 항상 통과
 *   2. functional 동의 시 모든 키 통과
 *   3. user-initiated (WP29 §3.6) 면제 — 사용자 인터랙션 직후 통과
 *   4. 그 외 → 차단
 *
 * 허용목록은 **운영자 설정**이므로 이 테스트는 목록을 주입해 검증한다 — 주입한 목록대로
 * 허용/차단이 갈리는지가 "설정이 실제로 판정에 쓰이는가" 의 증거다.
 *
 * "운영자 등록 표" 는 제거됨 (Phase 2 단순화).
 *
 * 조합 요약(마커 아님 — 평문): scope=localStorage, notation=exact, locked=operator_item, settings_state=populated, request=valid_item
 * 효과 요약(마커 아님 — 평문): storage_allows_item_listed_in_settings, storage_blocks_item_removed_from_settings, storage_wildcard_matches_prefix_only, storage_scope_does_not_leak_across_storages, storage_locked_item_survives_empty_settings, storage_config_update_applies_new_allowlist
 */

import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { LOCKED_FALLBACK, type NecessaryAllowlist } from '../necessaryAllowlist';
import {
    installStorageInterceptor,
    isStorageAllowed,
    uninstallStorageInterceptor,
    updateStorageInterceptorConfig,
} from '../storageInterceptor';
import { __setLastInteractionForTest, installUserInitiatedTracker, uninstallUserInitiatedTracker } from '../userInitiatedTracker';

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

    /**
     * 설정에 적힌 키(g7_locale)는 동의 전에도 저장된다.
     *
     * @scenario scope=localStorage, notation=exact, locked=operator_item, settings_state=populated
     * @effects storage_necessary_always_passes, storage_allows_item_listed_in_settings
     */
    it('설정에 등재된 키 (g7_locale) → 항상 통과 (미동의여도)', () => {
        installStorageInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist({ localStorage: ['g7_locale'] }),
        });

        window.localStorage.setItem('g7_locale', 'ko');
        expect(window.localStorage.getItem('g7_locale')).toBe('ko');
    });

    // 화면 테마가 목록에서 빠져 있어, 동의 전에는 테마를 바꿔도 저장이
    // 조용히 버려졌다 (새로고침하면 원래대로). 미인증 화면뿐 아니라 관리자 화면 전체가 같았다.
    /**
     * 설정에 적힌 키(g7_color_scheme)는 동의 전에도 저장된다.
     *
     * @scenario scope=localStorage, notation=exact, locked=operator_item, settings_state=populated
     * @effects storage_necessary_always_passes, storage_allows_item_listed_in_settings
     */
    it('설정에 등재된 키 (g7_color_scheme) → 항상 통과 (미동의여도)', () => {
        installStorageInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist({ localStorage: ['g7_color_scheme'] }),
        });

        window.localStorage.setItem('g7_color_scheme', 'dark');
        expect(window.localStorage.getItem('g7_color_scheme')).toBe('dark');
    });

    // 설정이 실제로 판정에 쓰이는지의 대조군 — 목록에서 빼면 같은 키가 차단되어야 한다.
    /**
     * 설정에서 뺀 키는 같은 키라도 저장이 막힌다.
     *
     * @scenario scope=localStorage, notation=exact, locked=operator_item, settings_state=empty
     * @effects storage_blocks_item_removed_from_settings
     */
    it('설정에서 뺀 키는 차단된다 (목록이 판정에 쓰인다는 증거)', () => {
        installStorageInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist({ localStorage: [] }),
        });

        window.localStorage.setItem('g7_color_scheme', 'dark');
        expect(window.localStorage.getItem('g7_color_scheme')).toBeNull();
    });

    /**
     * 와일드카드 항목(g7_devtools_*)에 맞는 키는 저장된다.
     *
     * @scenario scope=localStorage, notation=wildcard, locked=operator_item, settings_state=populated
     * @effects storage_prefix_match_passes
     */
    it('와일드카드 매칭 (g7_devtools_*) → 통과', () => {
        installStorageInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist({ localStorage: ['g7_devtools_*'] }),
        });

        window.localStorage.setItem('g7_devtools_filter', 'enabled');
        expect(window.localStorage.getItem('g7_devtools_filter')).toBe('enabled');
    });

    /**
     * 와일드카드는 앞부분만 맞춰 보고, 접두사가 다르면 막는다.
     *
     * @scenario scope=localStorage, notation=wildcard, locked=operator_item, settings_state=populated
     * @effects storage_wildcard_matches_prefix_only
     */
    it('와일드카드는 앞부분만 매칭한다 — 접두사가 다르면 차단', () => {
        installStorageInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist({ localStorage: ['g7_filters_*'] }),
        });

        window.localStorage.setItem('g7_filters_orders_1', 'a');
        window.localStorage.setItem('other_g7_filters_x', 'b');
        expect(window.localStorage.getItem('g7_filters_orders_1')).toBe('a');
        expect(window.localStorage.getItem('other_g7_filters_x')).toBeNull();
    });

    /**
     * localStorage 에 적은 키는 sessionStorage 에서는 막힌다.
     *
     * @scenario scope=sessionStorage, notation=exact, locked=operator_item, settings_state=populated
     * @effects storage_scope_does_not_leak_across_storages
     */
    it('스코프가 다르면 통과하지 않는다 (localStorage 등재 ≠ sessionStorage 허용)', () => {
        installStorageInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist({ localStorage: ['scoped_key'], sessionStorage: [] }),
        });

        window.localStorage.setItem('scoped_key', 'kept');
        window.sessionStorage.setItem('scoped_key', 'blocked');
        expect(window.localStorage.getItem('scoped_key')).toBe('kept');
        expect(window.sessionStorage.getItem('scoped_key')).toBeNull();
    });

    /**
     * sessionStorage 에 적은 항목은 그 저장소에서 저장된다.
     *
     * @scenario scope=sessionStorage, notation=exact, locked=operator_item, settings_state=populated
     * @effects storage_allows_item_listed_in_settings
     */
    it('sessionStorage 전용 항목도 그 스코프에서 통과한다', () => {
        installStorageInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist({
                sessionStorage: ['g7:sirsoft-pay_kginicis:pendingClose'],
            }),
        });

        window.sessionStorage.setItem('g7:sirsoft-pay_kginicis:pendingClose', '1');
        expect(window.sessionStorage.getItem('g7:sirsoft-pay_kginicis:pendingClose')).toBe('1');
    });

    /**
     * 기능 쿠키에 동의하면 허용목록 밖 키도 저장된다.
     *
     * @scenario area=storage_interceptor, consent=functional_granted, necessary=not_in_allowlist
     * @effects storage_consented_passes_all_keys
     */
    it('functional 동의 시 모든 키 통과 (허용목록 외 키도 통과)', () => {
        installStorageInterceptor({
            functionalConsented: true,
            necessaryAllowlist: allowlist(),
        });

        window.localStorage.setItem('app_pref', 'value');
        expect(window.localStorage.getItem('app_pref')).toBe('value');
    });

    /**
     * 동의 전이라도 사용자가 방금 조작했으면 저장된다.
     *
     * @scenario area=storage_interceptor, consent=functional_denied, user_initiated=recent_interaction, necessary=not_in_allowlist
     * @effects storage_denied_user_initiated_passes
     */
    it('미동의 + user-initiated (WP29 §3.6) → 통과', () => {
        installStorageInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist(),
        });

        __setLastInteractionForTest(Date.now());
        window.localStorage.setItem('app_pref', 'value');
        expect(window.localStorage.getItem('app_pref')).toBe('value');
    });

    /**
     * 동의 전이고 사용자 조작이 없으면 저장이 막힌다.
     *
     * @scenario area=storage_interceptor, consent=functional_denied, user_initiated=no_interaction, necessary=not_in_allowlist
     * @effects storage_denied_no_interaction_blocks
     */
    it('미동의 + 비-사용자 (background) → 차단', () => {
        installStorageInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist(),
        });

        // user-initiated 미발생 (timestamp=0)
        window.localStorage.setItem('app_pref', 'value');
        expect(window.localStorage.getItem('app_pref')).toBeNull();
    });

    /**
     * 동의 전, 사용자 조작 없이 임의 키를 쓰면 막힌다.
     *
     * @scenario area=storage_interceptor, consent=functional_denied, user_initiated=no_interaction, necessary=not_in_allowlist
     * @effects storage_denied_no_interaction_blocks
     */
    it('미동의 + 임의 키 + 비-사용자 → 차단 (보수적)', () => {
        installStorageInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist(),
        });

        window.localStorage.setItem('random_key', 'value');
        expect(window.localStorage.getItem('random_key')).toBeNull();
    });

    /**
     * 허용목록이 비어도 잠금 항목(auth_token)은 저장되고 설정 항목은 막힌다.
     *
     * @scenario scope=localStorage, notation=exact, locked=locked_item
     * @effects storage_locked_item_survives_empty_settings
     */
    it('허용목록이 통째로 비어도 잠금 항목(auth_token)은 통과한다', () => {
        installStorageInterceptor({
            functionalConsented: false,
            necessaryAllowlist: LOCKED_FALLBACK,
        });

        window.localStorage.setItem('auth_token', 'token');
        window.localStorage.setItem('g7_locale', 'ko');
        expect(window.localStorage.getItem('auth_token')).toBe('token');
        expect(window.localStorage.getItem('g7_locale')).toBeNull();
    });

    /**
     * 설정 갱신으로 동의 상태를 바꾸면 바로 판정에 반영된다.
     *
     * @scenario area=storage_interceptor, consent=functional_granted
     * @effects storage_update_config_applies_consent_change
     */
    it('updateStorageInterceptorConfig 으로 동의 상태 갱신 가능', () => {
        installStorageInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist(),
        });

        // 미동의 + 비-사용자 → 차단
        window.localStorage.setItem('app_pref', 'value1');
        expect(window.localStorage.getItem('app_pref')).toBeNull();

        // 동의 갱신
        updateStorageInterceptorConfig({
            functionalConsented: true,
            necessaryAllowlist: allowlist(),
        });
        window.localStorage.setItem('app_pref', 'value2');
        expect(window.localStorage.getItem('app_pref')).toBe('value2');
    });

    /**
     * 설정 갱신으로 허용목록을 바꾸면 바로 판정에 반영된다.
     *
     * @scenario scope=localStorage, notation=exact, locked=operator_item, settings_state=populated
     * @effects storage_config_update_applies_new_allowlist
     */
    it('updateStorageInterceptorConfig 으로 허용목록 갱신이 판정에 즉시 반영된다', () => {
        installStorageInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist({ localStorage: [] }),
        });

        window.localStorage.setItem('operator_added', 'v1');
        expect(window.localStorage.getItem('operator_added')).toBeNull();

        updateStorageInterceptorConfig({
            functionalConsented: false,
            necessaryAllowlist: allowlist({ localStorage: ['operator_added'] }),
        });
        window.localStorage.setItem('operator_added', 'v2');
        expect(window.localStorage.getItem('operator_added')).toBe('v2');
    });

    /**
     * 가로채기를 풀면 원래 setItem 이 돌아와 모든 쓰기가 된다.
     *
     * @scenario area=storage_interceptor, consent=functional_denied
     * @effects storage_uninstall_restores_original_setItem
     */
    it('uninstall 후 원본 setItem 복원 — 모든 쓰기 통과', () => {
        installStorageInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist(),
        });

        // 인터셉터 활성 시 차단
        window.localStorage.setItem('random_key', 'v1');
        expect(window.localStorage.getItem('random_key')).toBeNull();

        // uninstall 후 정상 동작
        uninstallStorageInterceptor();
        window.localStorage.setItem('random_key', 'v2');
        expect(window.localStorage.getItem('random_key')).toBe('v2');
    });

    /**
     * isStorageAllowed 는 판정만 하고 저장소를 바꾸지 않는다.
     *
     * @scenario area=storage_interceptor, consent=functional_denied, necessary=in_allowlist
     * @effects storage_is_storage_allowed_pure_evaluation
     */
    it('isStorageAllowed — 정책 평가 함수는 사이드 이펙트 없음', () => {
        installStorageInterceptor({
            functionalConsented: false,
            necessaryAllowlist: allowlist({ localStorage: ['g7_locale', 'g7_color_scheme'] }),
        });

        __setLastInteractionForTest(Date.now());
        expect(isStorageAllowed('app_pref', 'localStorage')).toBe(true);       // user-initiated 면제
        expect(isStorageAllowed('g7_locale', 'localStorage')).toBe(true);      // necessary
        expect(isStorageAllowed('g7_color_scheme', 'localStorage')).toBe(true); // necessary

        // user-initiated 가 만료된 시점엔 차단
        __setLastInteractionForTest(0);
        expect(isStorageAllowed('app_pref', 'localStorage')).toBe(false);
        expect(isStorageAllowed('g7_locale', 'localStorage')).toBe(true);      // necessary 는 항상 통과
        expect(isStorageAllowed('g7_color_scheme', 'localStorage')).toBe(true); // necessary 는 항상 통과

        // 함수 호출만으로 storage 변경 X
        expect(window.localStorage.getItem('app_pref')).toBeNull();
    });
});
