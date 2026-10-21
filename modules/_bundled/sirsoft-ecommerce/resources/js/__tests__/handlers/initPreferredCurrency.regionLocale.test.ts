/**
 * initPreferredCurrency — 지역·세 글자 화면 언어의 통화 추정 (#148)
 *
 * @description
 * 백엔드 SignupCurrencyResolver 와 같은 순서로 고른다 — 통화 사용 언어의 정확일치(zh-TW → TWD)가 먼저이고,
 * 없으면 주 언어가 같은 통화 언어(fil → fil-PH 통화)다. 종전에는 화면 언어를 두 글자로 잘라 매칭이 실패했다.
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

describe('initPreferredCurrency — 지역·세 글자 화면 언어 (#148)', () => {
    // @scenario shape=region_tagged
    // @effects signup_currency_matches_region_then_primary
    it('지역 붙은 화면 언어(zh-TW)는 통화 사용 언어의 정확일치가 먼저다', () => {
        mockLocalStorage.setItem('g7_locale', 'zh-TW');
        mockCurrencies([
            { code: 'KRW', is_default: true, locales: ['ko'] },
            { code: 'TWD', is_default: false, locales: ['zh-TW'] },
            { code: 'CNY', is_default: false, locales: ['zh-CN'] },
        ]);

        initPreferredCurrencyHandler({ params: { defaultCurrency: 'KRW' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredCurrency: 'TWD' });
    });

    // @scenario shape=three_letter
    // @effects signup_currency_matches_region_then_primary
    it('정확일치가 없으면 주 언어가 같은 통화 언어로 고른다 (fil → fil-PH 통화)', () => {
        mockLocalStorage.setItem('g7_locale', 'fil');
        mockCurrencies([
            { code: 'KRW', is_default: true, locales: ['ko'] },
            { code: 'PHP', is_default: false, locales: ['fil-PH'] },
        ]);

        initPreferredCurrencyHandler({ params: { defaultCurrency: 'KRW' } });

        expect(mockG7Core.state.set).toHaveBeenCalledWith({ preferredCurrency: 'PHP' });
    });
});
