/**
 * 상품 관리 어드민 통화 기호 바인딩 회귀 테스트
 *
 * 통화 기호를 하드코딩('원'/currency.won/없는 currencies[0].symbol)하지 않고
 * 통화 설정의 base 통화(is_default) symbol 을 따라가는지 검증한다.
 *
 * 배경: 통화는 유저가 자유롭게 추가/제거하므로 특정 기호/코드 하드코딩 금지.
 * base 통화 기호 SSoT = language_currency.currencies 의 is_default 통화 symbol,
 * 없으면 default_currency 코드로 폴백.
 */
import { describe, it, expect } from 'vitest';

import bulkPriceModal from '../../../layouts/admin/partials/admin_ecommerce_product_list/_modal_bulk_price.json';
import salesInfo from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_sales_info.json';
import productForm from '../../../layouts/admin/admin_ecommerce_product_form.json';

/** 레이아웃 트리를 JSON 문자열로 직렬화 (표현식 전수 검색용) */
function serialize(node: unknown): string {
    return JSON.stringify(node);
}

/** base 통화 symbol 참조 표현식이 포함되어 있는지 */
function referencesBaseCurrencySymbol(json: string): boolean {
    return (
        json.includes('language_currency?.currencies') &&
        json.includes('.find(c => c.is_default)?.symbol')
    );
}

describe('상품 어드민 통화 기호 바인딩 (하드코딩 제거 회귀)', () => {
    describe('판매가 일괄 변경 모달 (_modal_bulk_price)', () => {
        const json = serialize(bulkPriceModal);

        it("통화 기호 '원' 리터럴을 하드코딩하지 않는다", () => {
            // 단위 텍스트/미리보기/조건 어디에도 '원' 리터럴이 없어야 한다
            expect(json).not.toContain("'원'");
            expect(json).not.toContain('currency.won');
        });

        it('통화 코드별 기호 하드코딩(매핑 사전)을 두지 않는다', () => {
            // KRW/USD/JPY 등 코드 → 기호 매핑 IIFE 잔재가 없어야 한다
            expect(json).not.toContain("d === 'KRW' ? '원'");
            expect(json).not.toContain("d === 'USD' ? '$'");
        });

        it('base 통화(is_default) symbol 을 참조한다', () => {
            expect(referencesBaseCurrencySymbol(json)).toBe(true);
        });

        it('백분율(%) 단위 분기는 유지한다', () => {
            expect(json).toContain("=== 'percent' ? '%'");
        });
    });

    describe('상품 폼 판매정보 (_partial_sales_info)', () => {
        const json = serialize(salesInfo);

        it('존재하지 않는 currencies[0].symbol 을 참조하지 않는다', () => {
            expect(json).not.toContain('currencies?.[0]?.symbol');
        });

        it('base 통화(is_default) symbol 을 참조한다', () => {
            expect(referencesBaseCurrencySymbol(json)).toBe(true);
        });
    });

    describe('상품 폼 복사 모달 (admin_ecommerce_product_form)', () => {
        const json = serialize(productForm);

        it("판매가 표시에 currency.won 고정 키를 쓰지 않는다", () => {
            expect(json).not.toContain('currency.won');
        });

        it('base 통화(is_default) symbol 을 참조한다', () => {
            expect(referencesBaseCurrencySymbol(json)).toBe(true);
        });
    });
});
