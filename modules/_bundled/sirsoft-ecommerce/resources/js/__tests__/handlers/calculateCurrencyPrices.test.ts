/**
 * calculateCurrencyPricesHandler base_unit 환산 테스트 (MP08-3 방향 B)
 *
 * 공식: (basePrice / 기본통화.base_unit) × exchange_rate
 * - base_unit 폴백: KRW=1000, JPY=100, 그 외=1 (백엔드 CurrencyConversionService 와 동일)
 * - USD base + base_unit=1 → ÷1000 잔재 없이 정상 환산
 * - KRW base + base_unit=1000 → 옛 ÷1000 공식과 등가
 */

import { describe, it, expect } from 'vitest';
import { calculateCurrencyPricesHandler } from '../../handlers/calculateCurrencyPrices';

const ctx = {} as any;

describe('calculateCurrencyPricesHandler - base_unit 환산', () => {
    it('USD base(base_unit=1)에서 ÷1000 잔재 없이 환산한다', () => {
        const currencies = [
            { code: 'USD', is_default: true, base_unit: 1, exchange_rate: null, decimal_places: 2 },
            { code: 'JPY', is_default: false, base_unit: 100, exchange_rate: 157, rounding_unit: '1', rounding_method: 'floor' },
            { code: 'KRW', is_default: false, base_unit: 1000, exchange_rate: 1300, rounding_unit: '1', rounding_method: 'floor' },
            { code: 'EUR', is_default: false, base_unit: 1, exchange_rate: 0.92, rounding_unit: '0.01', rounding_method: 'round' },
        ];

        const result = calculateCurrencyPricesHandler({ basePrice: 3, currencies }, ctx);

        // $3 → JPY: (3 / 1) × 157 = 471 (floor)
        expect(result.JPY.price).toBe(471);
        expect(result.JPY.price).not.toBe(0); // ÷1000 잔재 시 0 이 되던 버그
        // $3 → KRW: (3 / 1) × 1300 = 3900
        expect(result.KRW.price).toBe(3900);
        // $3 → EUR: (3 / 1) × 0.92 = 2.76
        expect(result.EUR.price).toBeCloseTo(2.76, 2);
        // 기본 통화는 원본
        expect(result.USD.price).toBe(3);
        expect(result.USD.is_default).toBe(true);
    });

    it('KRW base(base_unit=1000)는 옛 ÷1000 공식과 등가다', () => {
        const currencies = [
            { code: 'KRW', is_default: true, base_unit: 1000, exchange_rate: null },
            { code: 'USD', is_default: false, base_unit: 1, exchange_rate: 0.85, rounding_unit: '0.01', rounding_method: 'round' },
            { code: 'JPY', is_default: false, base_unit: 100, exchange_rate: 115, rounding_unit: '1', rounding_method: 'floor' },
        ];

        const result = calculateCurrencyPricesHandler({ basePrice: 53000, currencies }, ctx);

        // 53000 / 1000 × 0.85 = 45.05
        expect(result.USD.price).toBeCloseTo(45.05, 2);
        // 53000 / 1000 × 115 = 6095
        expect(result.JPY.price).toBe(6095);
    });

    it('base_unit 미설정 통화는 폴백을 적용한다 (KRW=1000, JPY=100)', () => {
        // base_unit 필드 없음 → 폴백. KRW 기본일 때 분모=1000
        const currencies = [
            { code: 'KRW', is_default: true, exchange_rate: null },
            { code: 'JPY', is_default: false, exchange_rate: 115, rounding_unit: '1', rounding_method: 'floor' },
        ];

        const result = calculateCurrencyPricesHandler({ basePrice: 53000, currencies }, ctx);

        // 폴백 KRW base_unit=1000 → 53000/1000 × 115 = 6095
        expect(result.JPY.price).toBe(6095);
    });

    it('통화 목록이 비면 빈 객체를 반환한다', () => {
        expect(calculateCurrencyPricesHandler({ basePrice: 1000, currencies: [] }, ctx)).toEqual({});
    });

    it('formatted 는 설정 기호를 따르고 CNY 는 ¥ 충돌을 피해 元 로 표기한다', () => {
        const currencies = [
            { code: 'USD', is_default: true, base_unit: 1, exchange_rate: null, symbol: '$', decimal_places: 2 },
            { code: 'JPY', is_default: false, base_unit: 100, exchange_rate: 157, symbol: '¥', decimal_places: 0, rounding_unit: '1', rounding_method: 'floor' },
            { code: 'CNY', is_default: false, base_unit: 1, exchange_rate: 7.2, symbol: '元', decimal_places: 2, rounding_unit: '0.01', rounding_method: 'round' },
            { code: 'KRW', is_default: false, base_unit: 1000, exchange_rate: 1300, symbol: '₩', decimal_places: 0, rounding_unit: '1', rounding_method: 'floor' },
        ];

        const result = calculateCurrencyPricesHandler({ basePrice: 3000, currencies }, ctx);

        // 기본 통화(USD)는 기호 접두
        expect(result.USD.formatted).toBe('$3,000.00');
        // JPY 는 ¥, CNY 는 元 — 서로 구분
        expect(result.JPY.formatted.startsWith('¥')).toBe(true);
        expect(result.CNY.formatted.startsWith('元')).toBe(true);
        expect(result.CNY.formatted.startsWith('¥')).toBe(false);
        // 원화 계열은 금액 뒤 "원" 표기
        expect(result.KRW.formatted.endsWith('원')).toBe(true);
    });

    it('symbol 미설정 시 코드 폴백 기호를 쓰며 CNY 폴백은 元 이다', () => {
        const currencies = [
            { code: 'USD', is_default: true, base_unit: 1, exchange_rate: null },
            { code: 'CNY', is_default: false, base_unit: 1, exchange_rate: 7.2, rounding_unit: '0.01', rounding_method: 'round' },
        ];

        const result = calculateCurrencyPricesHandler({ basePrice: 100, currencies }, ctx);

        // symbol 없음 → SYMBOL_FALLBACK.CNY = 元 (¥ 아님)
        expect(result.CNY.formatted.startsWith('元')).toBe(true);
        expect(result.CNY.formatted.includes('¥')).toBe(false);
    });
});
