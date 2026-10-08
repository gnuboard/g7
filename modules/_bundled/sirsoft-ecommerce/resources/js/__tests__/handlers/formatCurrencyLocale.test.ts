/**
 * 원화 금액 표기는 보는 사람의 언어를 따른다 (S3-D23, 2026-10-07 실측)
 *
 * 서버(`CurrencyConversionService::formatPrice`)는 언어별 접두·접미로 원화를 적는다 — ko 「5,000원」·en 「₩5,000」·
 * ja 「5,000ウォン」. 프론트 `formatCurrency` 는 언어와 무관하게 「5,000원」으로 고정해, 영어 화면에서 서버가 만든
 * 금액은 「₩5,000」인데 화면에서 계산한 금액만 「5,000원」으로 섞였다(실측: 관리자 English · 주문 20261007-0650543600
 * 취소 창 「Subtotal 5,000원」, 같은 창 단가는 「₩5,000」). 서버와 같이 언어별 접두·접미 번역 키
 * (`sirsoft-ecommerce.currency.krw_prefix`·`krw_suffix`)로 적는다 — 문구 안에 금액과 단위를 붙여 두지 않는다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach } from 'vitest';

import { formatCurrency } from '../../handlers/calculateCurrencyPrices';

const KRW = { code: 'KRW', symbol: '₩', decimal_places: 0 };

/**
 * 언어별 번역을 흉내 낸 G7Core 를 심는다.
 *
 * @param affix 원화 접두·접미 (null 이면 번역 없음 — 키를 그대로 돌려준다). 빈 문자열도 유효한 번역 값이다.
 */
function installT(affix: { prefix: string; suffix: string } | null): void {
    const dict: Record<string, string> = affix === null
        ? {}
        : {
            'sirsoft-ecommerce.currency.krw_prefix': affix.prefix,
            'sirsoft-ecommerce.currency.krw_suffix': affix.suffix,
        };
    (window as any).G7Core = {
        t: (key: string) => (key in dict ? dict[key] : key),
    };
}

describe('원화 표기 — 언어별', () => {
    beforeEach(() => {
        delete (window as any).G7Core;
    });

    /**
     * @scenario locale_pattern=en
     *
     * @effects krw_amount_follows_locale_pattern
     */
    it('영어 접두 ₩ 를 앞에 붙인다', () => {
        installT({ prefix: '₩', suffix: '' });
        expect(formatCurrency(5000, KRW)).toBe('₩5,000');
    });

    /**
     * @scenario locale_pattern=ko
     *
     * @effects krw_amount_follows_locale_pattern
     */
    it('한국어 접미 원을 뒤에 붙인다', () => {
        installT({ prefix: '', suffix: '원' });
        expect(formatCurrency(5000, KRW)).toBe('5,000원');
    });

    /**
     * @scenario locale_pattern=ja
     *
     * @effects krw_amount_follows_locale_pattern
     */
    it('일본어 접미 ウォン 을 뒤에 붙인다', () => {
        installT({ prefix: '', suffix: 'ウォン' });
        expect(formatCurrency(12500, KRW)).toBe('12,500ウォン');
    });

    /**
     * @scenario locale_pattern=missing
     *
     * @effects krw_amount_falls_back_without_translation
     */
    it('번역을 못 읽으면 종전 표기(원)로 돌아간다', () => {
        installT(null);
        expect(formatCurrency(5000, KRW)).toBe('5,000원');
    });

    /**
     * @scenario locale_pattern=other_currency
     *
     * @effects other_currencies_unchanged
     */
    it('다른 통화는 바뀌지 않는다', () => {
        installT({ prefix: '₩', suffix: '' });
        expect(formatCurrency(7.5, { code: 'USD', symbol: '$', decimal_places: 2 })).toBe('$7.50');
    });
});
