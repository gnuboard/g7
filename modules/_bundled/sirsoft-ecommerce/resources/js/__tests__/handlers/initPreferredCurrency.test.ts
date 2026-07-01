/**
 * initPreferredCurrency 핸들러 테스트 (이커머스 모듈 소유)
 *
 * @description
 * "통화 = 커머스 책임" 원칙에 따라 표시 통화 초기화 핸들러를 sirsoft-basic 템플릿에서
 * 이커머스 모듈로 이전했다. 유저·관리자 양 템플릿의 init_actions 에서
 * `sirsoft-ecommerce.initPreferredCurrency` 로 호출된다.
 *
 * 회귀: 종전 loadFromLocalStorage(_local)+setState 승격은 init_actions 에서 _local 미반영 →
 *       새로고침 시 KRW 리셋. 이 핸들러는 G7Core.state.set 직접 주입으로 localStorage 값을 복원한다.
 * 우선순위(D-USERCUR-3 / D-LOGIN-CUR): 계정 영속 통화 > localStorage 세션 > defaultCurrency > KRW.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { initPreferredCurrencyHandler } from '../../handlers/initPreferredCurrency';

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

describe('initPreferredCurrency 핸들러 (영속성 회귀)', () => {
    it('localStorage 의 선택 통화(USD)를 _global.preferredCurrency 로 복원한다 (핵심 회귀)', () => {
        mockG7Config.user = null;
        mockLocalStorage.setItem('g7_preferred_currency', 'USD');

        initPreferredCurrencyHandler({ params: { defaultCurrency: 'KRW' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredCurrency: 'USD' });
    });

    it('로그인 유저 계정 영속 통화(JPY)가 localStorage(USD)보다 우선한다 (D-LOGIN-CUR)', () => {
        mockG7Config.user = { id: 7, ecommerce_preferred_currency: 'JPY' };
        mockLocalStorage.setItem('g7_preferred_currency', 'USD');

        initPreferredCurrencyHandler({ params: { defaultCurrency: 'KRW' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredCurrency: 'JPY' });
    });

    it('localStorage 가 비어 있으면 params.defaultCurrency(관리자 기본통화)로 폴백한다', () => {
        mockG7Config.user = null;

        initPreferredCurrencyHandler({ params: { defaultCurrency: 'USD' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredCurrency: 'USD' });
    });

    it('잘못된 형식의 저장값은 무시하고 defaultCurrency 로 폴백한다', () => {
        mockG7Config.user = null;
        mockLocalStorage.setItem('g7_preferred_currency', 'not-a-currency');

        initPreferredCurrencyHandler({ params: { defaultCurrency: 'KRW' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredCurrency: 'KRW' });
    });

    it('defaultCurrency 미지정 + localStorage 빈 경우 KRW 최종 폴백', () => {
        mockG7Config.user = null;

        initPreferredCurrencyHandler({ params: {} });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredCurrency: 'KRW' });
    });

    it('params.accountCurrency(onSuccess 전달값)가 localStorage 보다 우선한다 (D-LOGIN-CUR 타이밍 회귀)', () => {
        mockG7Config.user = null;
        mockLocalStorage.setItem('g7_preferred_currency', 'JPY');

        initPreferredCurrencyHandler({ params: { accountCurrency: 'USD', defaultCurrency: 'KRW' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredCurrency: 'USD' });
    });

    it('params.accountCurrency 가 무효/빈 값이면 기존 우선순위(getCurrentUser>localStorage)로 폴백', () => {
        mockG7Config.user = null;
        mockLocalStorage.setItem('g7_preferred_currency', 'JPY');

        initPreferredCurrencyHandler({ params: { accountCurrency: '', defaultCurrency: 'KRW' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredCurrency: 'JPY' });
    });

    it('폴링/타이머(setTimeout)를 일절 등록하지 않는다 (이벤트 기반 onSuccess)', () => {
        const setTimeoutSpy = vi.spyOn(globalThis, 'setTimeout');
        try {
            mockG7Config.user = null;
            mockLocalStorage.setItem('g7_preferred_currency', 'JPY');

            initPreferredCurrencyHandler({ params: { defaultCurrency: 'KRW' } });
            initPreferredCurrencyHandler({ params: { accountCurrency: 'USD', defaultCurrency: 'KRW' } });

            expect(setTimeoutSpy).not.toHaveBeenCalled();
        } finally {
            setTimeoutSpy.mockRestore();
        }
    });
});

describe('initPreferredCurrency — 게스트 사이트 언어(g7_locale) 기반 통화 추정', () => {
    /**
     * _global.modules['sirsoft-ecommerce'].language_currency.currencies 를 mock 합니다.
     *
     * @param currencies 통화 배열(code/is_default/locales)
     */
    function mockCurrencies(currencies: any[]): void {
        mockG7Core.state.get.mockReturnValue({
            modules: { 'sirsoft-ecommerce': { language_currency: { currencies } } },
        });
    }

    it('영문 사이트(g7_locale=en) → en 단일 매칭 통화(KRW)로 추정한다', () => {
        mockG7Config.user = null;
        mockLocalStorage.setItem('g7_locale', 'en');
        mockCurrencies([
            { code: 'USD', is_default: true, locales: ['ko'] },
            { code: 'KRW', is_default: false, locales: ['en'] },
            { code: 'JPY', is_default: false, locales: ['ja'] },
        ]);

        initPreferredCurrencyHandler({ params: { defaultCurrency: 'USD' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredCurrency: 'KRW' });
    });

    it('localStorage 수동 선택값이 locale 추정보다 우선한다', () => {
        mockG7Config.user = null;
        mockLocalStorage.setItem('g7_locale', 'en');
        mockLocalStorage.setItem('g7_preferred_currency', 'JPY');
        mockCurrencies([
            { code: 'KRW', is_default: false, locales: ['en'] },
            { code: 'JPY', is_default: false, locales: ['ja'] },
        ]);

        initPreferredCurrencyHandler({ params: { defaultCurrency: 'USD' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredCurrency: 'JPY' });
    });

    it('locale region suffix(en-US)를 정규화해 매칭한다', () => {
        mockG7Config.user = null;
        mockLocalStorage.setItem('g7_locale', 'en-US');
        mockCurrencies([
            { code: 'USD', is_default: true, locales: ['ko'] },
            { code: 'KRW', is_default: false, locales: ['en'] },
        ]);

        initPreferredCurrencyHandler({ params: { defaultCurrency: 'USD' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredCurrency: 'KRW' });
    });

    it('locale 이 여러 통화에 중복 매칭되면 매칭 중 is_default 통화로 추정한다', () => {
        mockG7Config.user = null;
        mockLocalStorage.setItem('g7_locale', 'en');
        mockCurrencies([
            { code: 'KRW', is_default: false, locales: ['en'] },
            { code: 'USD', is_default: true, locales: ['en'] },
            { code: 'EUR', is_default: false, locales: ['en'] },
        ]);

        initPreferredCurrencyHandler({ params: { defaultCurrency: 'USD' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredCurrency: 'USD' });
    });

    it('중복 매칭 + is_default 없음 → 모호 회피, default_currency 로 폴백', () => {
        mockG7Config.user = null;
        mockLocalStorage.setItem('g7_locale', 'en');
        mockCurrencies([
            { code: 'KRW', is_default: false, locales: ['en'] },
            { code: 'EUR', is_default: false, locales: ['en'] },
        ]);

        initPreferredCurrencyHandler({ params: { defaultCurrency: 'USD' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredCurrency: 'USD' });
    });

    it('매칭되는 통화가 없으면 default_currency 로 폴백', () => {
        mockG7Config.user = null;
        mockLocalStorage.setItem('g7_locale', 'fr');
        mockCurrencies([
            { code: 'KRW', is_default: false, locales: ['ko'] },
            { code: 'USD', is_default: true, locales: ['en'] },
        ]);

        initPreferredCurrencyHandler({ params: { defaultCurrency: 'USD' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredCurrency: 'USD' });
    });

    it('로그인 유저는 계정 통화가 1순위 — locale 추정을 건너뛴다', () => {
        mockG7Config.user = { id: 7, ecommerce_preferred_currency: 'JPY' };
        mockLocalStorage.setItem('g7_locale', 'en');
        mockCurrencies([
            { code: 'KRW', is_default: false, locales: ['en'] },
            { code: 'JPY', is_default: false, locales: ['ja'] },
        ]);

        initPreferredCurrencyHandler({ params: { defaultCurrency: 'USD' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredCurrency: 'JPY' });
    });

    it('g7_locale 미설정 시 default_currency 로 폴백 (추정 불가)', () => {
        mockG7Config.user = null;
        mockCurrencies([
            { code: 'KRW', is_default: false, locales: ['en'] },
            { code: 'USD', is_default: true, locales: ['ko'] },
        ]);

        initPreferredCurrencyHandler({ params: { defaultCurrency: 'USD' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredCurrency: 'USD' });
    });
});
