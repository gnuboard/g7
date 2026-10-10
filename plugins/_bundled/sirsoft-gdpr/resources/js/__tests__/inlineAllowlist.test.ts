/**
 * 인라인 허용목록 해석 테스트 — 운영자 ∪ 확장 제공 ∪ 잠금
 *
 * 인터셉터는 설정 조회 응답을 기다리지 않고 G7Config 인라인 페이로드로 선다. 확장이 계약 훅으로
 * 제공한 키(`necessary_storage_provided`)가 여기서 합쳐지지 않으면, 서버 판정은 통과시키는 키를
 * 브라우저가 부팅마다 지운다 — 결제창 복귀 기록·비회원 주문 토큰이 조용히 사라지는 경로다.
 *
 * @module sirsoft-gdpr/__tests__/inlineAllowlist
 *
 * @scenario scope=sessionStorage, notation=exact, locked=operator_item, settings_state=populated, request=valid_item
 * @effects provided_keys_merged_into_inline_allowlist, storage_locked_item_survives_empty_settings
 */

import { describe, it, expect } from 'vitest';

import { LOCKED_FALLBACK, isNecessary, resolveInlineAllowlist } from '../necessaryAllowlist';

describe('resolveInlineAllowlist', () => {
    /** @effects provided_keys_merged_into_inline_allowlist */
    it('확장 제공 키를 운영자 목록·잠금 집합과 합친다', () => {
        const allowlist = resolveInlineAllowlist({
            necessary_storage_allowlist: { localStorage: ['g7_locale'], sessionStorage: [], cookie: [] },
            necessary_storage_provided: { localStorage: [], sessionStorage: ['ext.pending'], cookie: ['ext_cookie_*'] },
            necessary_storage_locked: { localStorage: ['auth_token'], sessionStorage: [], cookie: ['XSRF-TOKEN'] },
        });

        expect(isNecessary('ext.pending', 'sessionStorage', allowlist)).toBe(true);
        expect(isNecessary('ext_cookie_mode', 'cookie', allowlist)).toBe(true);
        expect(isNecessary('g7_locale', 'localStorage', allowlist)).toBe(true);
        expect(isNecessary('auth_token', 'localStorage', allowlist)).toBe(true);
    });

    /** @effects provided_keys_merged_into_inline_allowlist */
    it('제공 키는 제공한 저장소 종류에만 적용된다', () => {
        const allowlist = resolveInlineAllowlist({
            necessary_storage_provided: { sessionStorage: ['ext.pending'] },
            necessary_storage_locked: LOCKED_FALLBACK,
        });

        expect(isNecessary('ext.pending', 'sessionStorage', allowlist)).toBe(true);
        expect(isNecessary('ext.pending', 'localStorage', allowlist)).toBe(false);
    });

    /** @effects provided_keys_merged_into_inline_allowlist */
    it('제공 키가 없거나 형식이 틀려도 나머지 판정은 그대로다', () => {
        const allowlist = resolveInlineAllowlist({
            necessary_storage_allowlist: { localStorage: ['g7_locale'] },
            necessary_storage_provided: 'broken',
            necessary_storage_locked: LOCKED_FALLBACK,
        });

        expect(isNecessary('g7_locale', 'localStorage', allowlist)).toBe(true);
        expect(allowlist.sessionStorage).toEqual([...LOCKED_FALLBACK.sessionStorage]);
    });

    /** @effects storage_locked_item_survives_empty_settings */
    it('페이로드가 도달하지 않으면 잠금 폴백으로 선다', () => {
        const allowlist = resolveInlineAllowlist(undefined);

        expect(allowlist).toEqual({
            localStorage: [...LOCKED_FALLBACK.localStorage],
            sessionStorage: [...LOCKED_FALLBACK.sessionStorage],
            cookie: [...LOCKED_FALLBACK.cookie],
        });
    });
});
