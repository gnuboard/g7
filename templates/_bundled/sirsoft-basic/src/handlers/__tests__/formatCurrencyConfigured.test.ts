/**
 * 통화 포맷 핸들러의 설정 기반 동작 회귀 테스트
 *
 * 종전 formatCurrencyHandler 는 KRW/USD/JPY/CNY/EUR 5종 고정 표를 두고
 * `CURRENCY_CONFIGS[currency] || CURRENCY_CONFIGS.KRW` 로 폴백했다. 운영자가 관리자에서
 * 통화를 추가할 수 있으므로(예: GBP), 표에 없는 통화는 값은 그 통화인데 기호·자릿수만
 * 원화로 표기되는 상태가 됐다.
 *
 * 통화 표기는 설정(`language_currency.currencies`)이 정한다는 것을 고정한다.
 */

import { describe, it, expect, afterEach } from 'vitest';
import { formatCurrencyHandler, getCurrencySymbol } from '../formatCurrency';

/**
 * 전역 통화 설정과 표시 통화를 심습니다.
 *
 * @param currencies 설정에 등록된 통화 목록
 * @param preferred 표시 통화 코드
 */
function installState(currencies: any[], preferred?: string): any {
    const state: Record<string, any> = {
        modules: { 'sirsoft-ecommerce': { language_currency: { currencies } } },
    };

    if (preferred) {
        state.preferredCurrency = preferred;
    }

    (window as any).G7Core = { state: { get: () => state } };

    return {
        setState: () => {},
        getState: (path: string) => {
            if (path === '_global.preferredCurrency') return state.preferredCurrency;
            if (path === '_global.defaultCurrency') return state.defaultCurrency;

            return undefined;
        },
    } as any;
}

describe('formatCurrencyHandler — 설정 기반 통화 표기', () => {
    afterEach(() => {
        delete (window as any).G7Core;
    });

    it('설정에만 있는 통화(GBP)를 원화로 폴백하지 않는다', () => {
        const ctx = installState(
            [{ code: 'GBP', symbol: '£', decimal_places: 2, is_default: true }],
            'GBP'
        );

        const formatted = formatCurrencyHandler({ value: 1234.5 }, ctx);

        expect(formatted).toContain('£');
        expect(formatted).not.toContain('₩');
        expect(formatted).not.toContain('원');
    });

    it('설정의 decimal_places 를 따른다', () => {
        const ctx = installState(
            [{ code: 'GBP', symbol: '£', decimal_places: 2, is_default: true }],
            'GBP'
        );

        expect(formatCurrencyHandler({ value: 1234.5 }, ctx)).toBe('£1,234.50');
    });

    it('원화는 기존과 동일하게 금액 뒤에 원을 붙인다', () => {
        const ctx = installState(
            [{ code: 'KRW', symbol: '₩', decimal_places: 0, is_default: true }],
            'KRW'
        );

        expect(formatCurrencyHandler({ value: 10000 }, ctx)).toBe('10,000원');
    });

    it('표시 통화가 없으면 기본 통화로 표기한다', () => {
        const ctx = installState([
            { code: 'JPY', symbol: '¥', decimal_places: 0, is_default: true },
            { code: 'KRW', symbol: '₩', decimal_places: 0 },
        ]);

        const formatted = formatCurrencyHandler({ value: 5000 }, ctx);

        expect(formatted).toContain('¥');
        expect(formatted).not.toContain('원');
    });

    it('통화를 판정할 수 없으면 단위를 임의로 붙이지 않는다', () => {
        const ctx = installState([]);

        const formatted = formatCurrencyHandler({ value: 3000 }, ctx);

        expect(formatted).not.toContain('원');
        expect(formatted).not.toContain('₩');
    });

    it('getCurrencySymbol 은 설정의 기호를 우선한다', () => {
        installState([{ code: 'CNY', symbol: '元', decimal_places: 2 }]);

        expect(getCurrencySymbol('CNY')).toBe('元');
    });
});
