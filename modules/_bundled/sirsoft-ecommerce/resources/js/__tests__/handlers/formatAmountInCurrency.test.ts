/**
 * 설정 통화 기준 금액 포맷 회귀 테스트
 *
 * 관리자 주문 취소 모달은 단가·소계·쿠폰 할인액을 `금액.toLocaleString() + '원'` 으로
 * 조립했다. 기본 통화가 원화가 아닌 상점에서는 값은 기준 통화인데 단위만 원으로 표기돼,
 * 운영자가 환불 금액을 잘못 읽을 수 있었다.
 *
 * 통화 단위는 설정(`language_currency.currencies`)이 정한다는 것을 고정한다.
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { formatAmountInCurrency } from '../../handlers/calculateCurrencyPrices';

/**
 * 전역 통화 설정을 심습니다.
 *
 * @param defaultCode 기본 통화 코드
 */
function installCurrencies(defaultCode: string): void {
    (window as any).G7Core = {
        state: {
            get: () => ({
                modules: {
                    'sirsoft-ecommerce': {
                        language_currency: {
                            default_currency: defaultCode,
                            currencies: [
                                { code: 'KRW', symbol: '₩', decimal_places: 0, is_default: defaultCode === 'KRW' },
                                { code: 'JPY', symbol: '¥', decimal_places: 0, is_default: defaultCode === 'JPY' },
                                { code: 'USD', symbol: '$', decimal_places: 2, is_default: defaultCode === 'USD' },
                            ],
                        },
                    },
                },
            }),
        },
    };
}

describe('formatAmountInCurrency', () => {
    afterEach(() => {
        delete (window as any).G7Core;
    });

    it('기본 통화가 JPY 면 엔화 기호로 표기한다 (원화 고정 회귀 차단)', () => {
        installCurrencies('JPY');

        const formatted = formatAmountInCurrency(25000);

        expect(formatted).toContain('¥');
        expect(formatted).not.toContain('원');
    });

    it('기본 통화가 KRW 면 기존과 동일하게 금액 뒤에 원을 붙인다', () => {
        installCurrencies('KRW');

        expect(formatAmountInCurrency(25000)).toBe('25,000원');
    });

    it('통화 코드를 명시하면 기본 통화가 아니어도 그 통화로 표기한다', () => {
        installCurrencies('JPY');

        const formatted = formatAmountInCurrency(12.5, 'USD');

        expect(formatted).toBe('$12.50');
    });

    it('설정을 읽지 못하면 단위를 임의로 붙이지 않고 숫자만 돌려준다', () => {
        // 통화 설정 없음 — 원화를 추측해 붙이면 그것이 곧 하드코딩이다.
        (window as any).G7Core = { state: { get: () => ({}) } };

        const formatted = formatAmountInCurrency(3000);

        expect(formatted).not.toContain('원');
        expect(formatted).not.toContain('₩');
        expect(formatted).toBe((3000).toLocaleString());
    });
});
