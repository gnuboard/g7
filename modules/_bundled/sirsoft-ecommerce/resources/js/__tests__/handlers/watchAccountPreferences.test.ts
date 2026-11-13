/**
 * watchAccountPreferences 핸들러 테스트 (이커머스 모듈 소유, #490)
 *
 * @description
 * 내 정보 수정에서 결제 통화나 배송국가를 바꿔 저장하면 화면은 로그인 사용자(current_user)를 다시 받고 그 응답이
 * 전역 currentUser 로 올라온다. 이 핸들러가 걸어 둔 구독이 그 변화를 보고 계정 값을 전역 표시 값
 * (_global.preferredCurrency, _global.preferredShippingCountry)에 옮긴다. 사용자 템플릿은 두 키를 몰라도 된다.
 *
 * @scenario-manifest tests/scenarios/user-preference-member-fields.yaml
 *
 * @vitest-environment jsdom
 */
// e2e:allow 전역 상태 구독 핸들러의 단위 계약 — 화면에서의 동작(내 정보에서 통화를 바꾼 직후 새로고침 없이 가격 표시가 바뀜)은 브라우저 실측 대상이다.

import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
    applyAccountPreferences,
    resetAccountPreferenceWatch,
    watchAccountPreferencesHandler,
} from '../../handlers/watchAccountPreferences';
import { handlerMap } from '../../handlers';

type Listener = (state: Record<string, any>) => void;

/**
 * 전역 상태 저장소 흉내 — set 이 얕게 합치고 구독자를 모두 부른다.
 *
 * @param initial 초기 상태
 * @returns G7Core 흉내 객체와 상태 접근자
 */
function createCore(initial: Record<string, any> = {}) {
    let state: Record<string, any> = { ...initial };
    const listeners = new Set<Listener>();
    const set = vi.fn((updates: Record<string, any>) => {
        state = { ...state, ...updates };
        listeners.forEach((listener) => listener(state));
    });

    return {
        core: {
            state: {
                get: () => state,
                set,
                subscribe: vi.fn((listener: Listener) => {
                    listeners.add(listener);
                    return () => listeners.delete(listener);
                }),
            },
            createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
        },
        set,
        get: () => state,
        listenerCount: () => listeners.size,
    };
}

const shippingSettings = {
    'sirsoft-ecommerce': {
        shipping: {
            available_countries: [
                { code: 'KR', is_active: true },
                { code: 'US', is_active: true },
                { code: 'JP', is_active: false },
            ],
        },
    },
};

afterEach(() => {
    resetAccountPreferenceWatch();
    delete (window as any).G7Core;
});

describe('watchAccountPreferencesHandler', () => {
    let store: ReturnType<typeof createCore>;

    beforeEach(() => {
        store = createCore({ preferredCurrency: 'KRW', preferredShippingCountry: 'KR', modules: shippingSettings });
        (window as any).G7Core = store.core;
    });

    // @scenario case=global_sync
    // @effects account_preferences_synced_to_global_on_current_user_change
    it('currentUser 가 새 응답으로 바뀌면 계정 통화와 배송국가를 전역 표시 값에 옮긴다', () => {
        watchAccountPreferencesHandler();

        store.core.state.set({
            currentUser: { uuid: 'u1', ecommerce_preferred_currency: 'USD', ecommerce_preferred_shipping_country: 'US' },
        });

        expect(store.get().preferredCurrency).toBe('USD');
        expect(store.get().preferredShippingCountry).toBe('US');
    });

    // @scenario case=global_sync
    // @effects account_preferences_synced_to_global_on_current_user_change
    it('내 정보에서 통화를 바꿔 저장한 뒤 다시 받은 응답도 그대로 따른다 (새로고침 없이)', () => {
        watchAccountPreferencesHandler();
        store.core.state.set({ currentUser: { uuid: 'u1', ecommerce_preferred_currency: 'KRW' } });

        // 저장 성공 뒤 current_user 를 다시 받는다 — 새 객체가 올라온다
        store.core.state.set({ currentUser: { uuid: 'u1', ecommerce_preferred_currency: 'USD' } });

        expect(store.get().preferredCurrency).toBe('USD');
    });

    // @scenario case=global_sync
    // @effects account_preferences_synced_to_global_on_current_user_change
    it('응답 본문이 data 로 감싸여 올라와도 읽는다', () => {
        watchAccountPreferencesHandler();

        store.core.state.set({ currentUser: { data: { ecommerce_preferred_currency: 'USD' } } });

        expect(store.get().preferredCurrency).toBe('USD');
    });

    // @scenario case=global_sync
    // @effects header_choice_not_reverted_by_stale_current_user
    it('헤더 셀렉터로 통화만 바꾼 경우(currentUser 는 그대로) 방금 고른 값을 옛 응답 값으로 되돌리지 않는다', () => {
        watchAccountPreferencesHandler();
        store.core.state.set({ currentUser: { uuid: 'u1', ecommerce_preferred_currency: 'KRW' } });

        store.core.state.set({ preferredCurrency: 'USD' });
        store.core.state.set({ showCurrencyDropdown: false });

        expect(store.get().preferredCurrency).toBe('USD');
    });

    // @scenario case=global_sync
    // @effects invalid_account_preference_ignored
    it('형식에 맞지 않는 값과 지금 배송할 수 없는 나라는 옮기지 않는다', () => {
        watchAccountPreferencesHandler();

        store.core.state.set({
            currentUser: { uuid: 'u1', ecommerce_preferred_currency: 'usd', ecommerce_preferred_shipping_country: 'JP' },
        });

        expect(store.get().preferredCurrency).toBe('KRW');
        expect(store.get().preferredShippingCountry).toBe('KR');
    });

    // @scenario case=global_sync
    // @effects invalid_account_preference_ignored
    it('로그아웃(currentUser 가 비워짐)과 값이 없는 응답에서는 전역 값을 건드리지 않는다', () => {
        watchAccountPreferencesHandler();
        store.core.state.set({ currentUser: { uuid: 'u1', ecommerce_preferred_currency: 'USD' } });
        store.set.mockClear();

        store.core.state.set({ currentUser: null });
        store.core.state.set({ currentUser: { uuid: 'u2', ecommerce_preferred_currency: null } });

        expect(store.get().preferredCurrency).toBe('USD');
        // 위 두 번의 set 말고 핸들러가 따로 쓴 것이 없다
        expect(store.set).toHaveBeenCalledTimes(2);
    });

    // @scenario case=global_sync
    // @effects account_preference_watch_subscribes_once
    it('초기화 액션이 화면마다 다시 돌아도 구독은 한 번만 건다', () => {
        watchAccountPreferencesHandler();
        watchAccountPreferencesHandler();
        watchAccountPreferencesHandler();

        expect(store.core.state.subscribe).toHaveBeenCalledTimes(1);
        expect(store.listenerCount()).toBe(1);
    });

    // @scenario case=global_sync
    // @effects account_preference_watch_subscribes_once
    it('전역 상태 구독을 쓸 수 없으면 아무것도 하지 않는다', () => {
        (window as any).G7Core = { state: { get: () => ({}), set: vi.fn() } };

        expect(() => watchAccountPreferencesHandler()).not.toThrow();
    });
});

describe('applyAccountPreferences', () => {
    // @scenario case=global_sync
    // @effects account_preferences_synced_to_global_on_current_user_change
    it('전역 값이 이미 같으면 다시 쓰지 않는다', () => {
        const store = createCore({ preferredCurrency: 'USD', preferredShippingCountry: 'US', modules: shippingSettings });
        (window as any).G7Core = store.core;

        applyAccountPreferences({ ecommerce_preferred_currency: 'USD', ecommerce_preferred_shipping_country: 'US' });

        expect(store.set).not.toHaveBeenCalled();
    });
});

describe('user-account-preference-sync.json — _user_base 대상 조각', () => {
    const fragment = JSON.parse(fs.readFileSync(
        path.resolve(__dirname, '../../../extensions/user-account-preference-sync.json'),
        'utf-8',
    ));

    // @scenario case=global_sync
    // @effects account_preference_watch_contributed_by_module_fragment
    it('사용자 기반 레이아웃에 그리는 것 없이 구독 핸들러 호출 하나만 기여한다', () => {
        expect(fragment.target_layout).toBe('_user_base');
        expect(fragment.injections ?? []).toEqual([]);
        expect(fragment.init_actions).toEqual([{ handler: 'sirsoft-ecommerce.watchAccountPreferences' }]);
    });

    // @scenario case=global_sync
    // @effects account_preference_watch_contributed_by_module_fragment
    it('핸들러가 모듈 핸들러 목록에 등록돼 있다', () => {
        expect((handlerMap as Record<string, unknown>).watchAccountPreferences).toBe(watchAccountPreferencesHandler);
    });
});
