/**
 * initPreferredShippingCountry 핸들러 테스트 (이커머스 모듈 소유, MP08 후속)
 *
 * @description
 * "배송국가 = 커머스 책임" 원칙에 따라 선호 배송국가 초기화 핸들러를 이커머스 모듈이 소유한다.
 * _user_base 의 init_actions 및 current_user onSuccess 에서 호출된다(initPreferredCurrency 미러).
 *
 * 우선순위: 계정 영속(accountCountry/currentUser) > localStorage 세션 > defaultCountry > KR.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { initPreferredShippingCountryHandler } from '../../handlers/initPreferredShippingCountry';

const mockG7Core = {
    state: {
        set: vi.fn(),
        get: vi.fn(() => ({})),
    },
    createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
};

const mockG7Config: { user: any } = { user: null };

const mockLocalStorage = (() => {
    let store: Record<string, string> = {};
    return {
        getItem: (k: string) => (k in store ? store[k] : null),
        setItem: (k: string, v: string) => { store[k] = v; },
        removeItem: (k: string) => { delete store[k]; },
        clear: () => { store = {}; },
    };
})();

beforeEach(() => {
    (window as any).G7Core = mockG7Core;
    (window as any).G7Config = mockG7Config;
    Object.defineProperty(window, 'localStorage', { value: mockLocalStorage, configurable: true });
    mockG7Core.state.set.mockClear();
    mockG7Core.state.get.mockReturnValue({});
    mockG7Config.user = null;
    mockLocalStorage.clear();
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('initPreferredShippingCountry 핸들러', () => {
    it('localStorage 의 선택 국가(US)를 _global.preferredShippingCountry 로 복원한다', () => {
        mockLocalStorage.setItem('g7_preferred_shipping_country', 'US');

        initPreferredShippingCountryHandler({ params: { defaultCountry: 'KR' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredShippingCountry: 'US' });
    });

    it('로그인 유저 계정 영속 배송국가(JP)가 localStorage(US)보다 우선한다', () => {
        mockG7Config.user = { id: 7, ecommerce_preferred_shipping_country: 'JP' };
        mockLocalStorage.setItem('g7_preferred_shipping_country', 'US');

        initPreferredShippingCountryHandler({ params: { defaultCountry: 'KR' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredShippingCountry: 'JP' });
    });

    it('localStorage 가 비어 있으면 params.defaultCountry 로 폴백한다', () => {
        initPreferredShippingCountryHandler({ params: { defaultCountry: 'US' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredShippingCountry: 'US' });
    });

    it('잘못된 형식의 저장값은 무시하고 defaultCountry 로 폴백한다', () => {
        mockLocalStorage.setItem('g7_preferred_shipping_country', 'not-a-country');

        initPreferredShippingCountryHandler({ params: { defaultCountry: 'KR' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredShippingCountry: 'KR' });
    });

    it('defaultCountry 미지정 + localStorage 빈 경우 KR 최종 폴백', () => {
        initPreferredShippingCountryHandler({ params: {} });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredShippingCountry: 'KR' });
    });

    it('params.accountCountry(onSuccess 전달값)가 localStorage 보다 우선한다', () => {
        mockLocalStorage.setItem('g7_preferred_shipping_country', 'JP');

        initPreferredShippingCountryHandler({ params: { accountCountry: 'US', defaultCountry: 'KR' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredShippingCountry: 'US' });
    });

    it('params.accountCountry 가 무효/빈 값이면 기존 우선순위로 폴백', () => {
        mockLocalStorage.setItem('g7_preferred_shipping_country', 'JP');

        initPreferredShippingCountryHandler({ params: { accountCountry: '', defaultCountry: 'KR' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredShippingCountry: 'JP' });
    });

    it('소문자 코드는 정규식(/^[A-Z]{2}$/)에 불합치 → 무시되고 폴백', () => {
        mockLocalStorage.setItem('g7_preferred_shipping_country', 'us');

        initPreferredShippingCountryHandler({ params: { defaultCountry: 'KR' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredShippingCountry: 'KR' });
    });

    it('폴링/타이머(setTimeout)를 일절 등록하지 않는다 (이벤트 기반)', () => {
        const setTimeoutSpy = vi.spyOn(globalThis, 'setTimeout');
        try {
            mockLocalStorage.setItem('g7_preferred_shipping_country', 'JP');

            initPreferredShippingCountryHandler({ params: { defaultCountry: 'KR' } });
            initPreferredShippingCountryHandler({ params: { accountCountry: 'US', defaultCountry: 'KR' } });

            expect(setTimeoutSpy).not.toHaveBeenCalled();
        } finally {
            setTimeoutSpy.mockRestore();
        }
    });
});
