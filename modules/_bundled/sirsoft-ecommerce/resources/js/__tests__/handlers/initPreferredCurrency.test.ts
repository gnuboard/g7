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
    // @scenario case=stored
    // @effects preferred_currency_restores_stored_choice
    it('localStorage 의 선택 통화(USD)를 _global.preferredCurrency 로 복원한다 (핵심 회귀)', () => {
        mockG7Config.user = null;
        mockLocalStorage.setItem('g7_preferred_currency', 'USD');

        initPreferredCurrencyHandler({ params: { defaultCurrency: 'KRW' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredCurrency: 'USD' });
    });

    // @scenario case=account_user
    // @effects preferred_currency_account_first
    it('로그인 유저 계정 영속 통화(JPY)가 localStorage(USD)보다 우선한다 (D-LOGIN-CUR)', () => {
        mockG7Config.user = { id: 7, ecommerce_preferred_currency: 'JPY' };
        mockLocalStorage.setItem('g7_preferred_currency', 'USD');

        initPreferredCurrencyHandler({ params: { defaultCurrency: 'KRW' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredCurrency: 'JPY' });
    });

    // @scenario case=default
    // @effects preferred_currency_falls_back_to_default
    it('localStorage 가 비어 있으면 params.defaultCurrency(관리자 기본통화)로 폴백한다', () => {
        mockG7Config.user = null;

        initPreferredCurrencyHandler({ params: { defaultCurrency: 'USD' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredCurrency: 'USD' });
    });

    // @scenario case=invalid_stored
    // @effects preferred_currency_falls_back_to_default
    it('잘못된 형식의 저장값은 무시하고 defaultCurrency 로 폴백한다', () => {
        mockG7Config.user = null;
        mockLocalStorage.setItem('g7_preferred_currency', 'not-a-currency');

        initPreferredCurrencyHandler({ params: { defaultCurrency: 'KRW' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredCurrency: 'KRW' });
    });

    // @scenario case=final_fallback
    // @effects preferred_currency_falls_back_to_default
    it('defaultCurrency 미지정 + localStorage 빈 경우 KRW 최종 폴백', () => {
        mockG7Config.user = null;

        initPreferredCurrencyHandler({ params: {} });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredCurrency: 'KRW' });
    });

    // @scenario case=account_param
    // @effects preferred_currency_account_first
    it('params.accountCurrency(onSuccess 전달값)가 localStorage 보다 우선한다 (D-LOGIN-CUR 타이밍 회귀)', () => {
        mockG7Config.user = null;
        mockLocalStorage.setItem('g7_preferred_currency', 'JPY');

        initPreferredCurrencyHandler({ params: { accountCurrency: 'USD', defaultCurrency: 'KRW' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredCurrency: 'USD' });
    });

    // @scenario case=account_param_invalid
    // @effects preferred_currency_account_first
    it('params.accountCurrency 가 무효/빈 값이면 기존 우선순위(getCurrentUser>localStorage)로 폴백', () => {
        mockG7Config.user = null;
        mockLocalStorage.setItem('g7_preferred_currency', 'JPY');

        initPreferredCurrencyHandler({ params: { accountCurrency: '', defaultCurrency: 'KRW' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredCurrency: 'JPY' });
    });

    // @scenario case=no_polling
    // @effects preferred_currency_registers_no_timer
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

    // @scenario case=locale_single_match
    // @effects guest_currency_guessed_by_locale
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

    // @scenario case=locale_stored_wins
    // @effects preferred_currency_restores_stored_choice
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

    // @scenario case=locale_region_suffix
    // @effects guest_currency_guessed_by_locale
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

    // @scenario case=locale_multi_default
    // @effects guest_currency_guessed_by_locale
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

    // @scenario case=locale_ambiguous
    // @effects preferred_currency_falls_back_to_default
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

    // @scenario case=locale_no_match
    // @effects preferred_currency_falls_back_to_default
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

    // @scenario case=locale_logged_in
    // @effects preferred_currency_account_first
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

    // @scenario case=locale_unset
    // @effects preferred_currency_falls_back_to_default
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

describe('initPreferredCurrency — 관리자 화면은 언어로 통화를 추정하지 않는다 (#869 S13)', () => {
    /**
     * 2차 브라우저 실측 때의 개발 설치본 통화 설정 — 기본 통화 JPY, ko 는 KRW 하나에 매칭,
     * en 은 CNY 와 EUR 둘에 매칭(기본 아님 → 모호).
     */
    const measuredCurrencies = [
        { code: 'KRW', is_default: false, locales: ['ko'] },
        { code: 'USD', is_default: false },
        { code: 'JPY', is_default: true },
        { code: 'CNY', is_default: false, locales: ['en'] },
        { code: 'EUR', is_default: false, locales: ['en'] },
    ];

    /**
     * 관리자 셸의 인증 상태를 흉내 낸다 — 관리자 레이아웃은 current_user 를 전역(currentUser)으로
     * 올리지 않으므로 핸들러가 볼 수 있는 것은 AuthManager 의 인증 종류뿐이다.
     *
     * @param authType 인증 종류('admin' | 'user' | null)
     */
    function mockAuthType(authType: 'admin' | 'user' | null): void {
        (mockG7Core as any).AuthManager = {
            getInstance: () => ({ getAuthType: () => authType }),
        };
    }

    beforeEach(() => {
        mockG7Core.state.get.mockReturnValue({
            modules: { 'sirsoft-ecommerce': { language_currency: { currencies: measuredCurrencies } } },
        });
    });

    afterEach(() => {
        delete (mockG7Core as any).AuthManager;
    });

    // @scenario case=admin_locale_switch
    // @effects admin_header_currency_ignores_admin_locale
    it('관리자 언어를 ko 에서 en 으로 바꿔 init 이 다시 돌아도 표시 통화는 기본 통화 그대로다', () => {
        mockAuthType('admin');

        mockLocalStorage.setItem('g7_locale', 'ko');
        initPreferredCurrencyHandler({ params: { defaultCurrency: 'JPY' } });
        mockLocalStorage.setItem('g7_locale', 'en');
        initPreferredCurrencyHandler({ params: { defaultCurrency: 'JPY' } });

        // 종전에는 ko 에서 KRW(언어 추정), en 에서 JPY(모호 → 기본)로 갈려 헤더가 「KR KRW」→「KR JPY」로 바뀌었다
        expect(mockG7Core.state.set.mock.calls).toEqual([
            [{ preferredCurrency: 'JPY' }],
            [{ preferredCurrency: 'JPY' }],
        ]);
    });

    // @scenario case=admin_stored_choice
    // @effects admin_header_currency_keeps_stored_choice
    it('관리자 화면에서도 헤더에서 고른 통화(브라우저 저장값)는 그대로 지킨다', () => {
        mockAuthType('admin');
        mockLocalStorage.setItem('g7_locale', 'ko');
        mockLocalStorage.setItem('g7_preferred_currency', 'EUR');

        initPreferredCurrencyHandler({ params: { defaultCurrency: 'JPY' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredCurrency: 'EUR' });
    });

    // @scenario case=user_guest
    // @effects guest_currency_still_guessed_by_locale
    it('사용자 화면 게스트(인증 없음)는 종전대로 언어로 추정한다', () => {
        mockAuthType(null);
        mockLocalStorage.setItem('g7_locale', 'ko');

        initPreferredCurrencyHandler({ params: { defaultCurrency: 'JPY' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredCurrency: 'KRW' });
    });
});
