/**
 * @file editorBulkConfirmSample.test.ts
 * @description 레이아웃 편집기에서 상품 일괄 변경 확인 창이 표본으로 채워져 열리는지
 *
 * 편집기에서 「일괄 변경 확인」(상품 관리) 창을 열면 「변경될 상품 (1개)」 아래 목록이 비고, 「변경될 옵션 (3개)」 는
 * 「>」 만, 실패 칸에는 「샘플 값」 이 보였다. 뒤 화면 버튼도 「판매가 변경 (샘플 값)」 이었다(이커머스 전수 실측 S5,
 * 2026-10-07). 같은 창 id(`modal_bulk_confirm`)를 주문 목록 일괄 확인 창도 써서 자동 표본이 두 창의 키를 섞어
 * 만들었고, 상품 창이 읽는 꼴(`products[].name`·`options[].productName`)이 아니라 「샘플 값」 글자 배열이 들어 있었다.
 *
 * 표본은 두 창을 모두 채워야 한다 — 상품 창: 상품·옵션 객체, 옵션 수 합계 = 머리글 개수, 판매가·재고 조건 표시 문자열,
 * 기본 상태의 실패 목록은 비어 있음(실패 칸은 실패가 있을 때만 보인다). 주문 창: 주문 상태·택배사·송장 번호.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { join, resolve } from 'node:path';

const MODULE_ROOT = resolve(__dirname, '../../../..');

type Json = Record<string, any>;

const spec: Json = JSON.parse(readFileSync(join(MODULE_ROOT, 'editor-spec.json'), 'utf8'));

/**
 * 창 id 의 표본 항목들을 찾습니다.
 *
 * @param modalId 창 id
 * @return 표본 항목 목록
 */
function itemsFor(modalId: string): Json[] {
    const found: Json[] = [];
    const walk = (node: any): void => {
        if (Array.isArray(node)) {
            node.forEach(walk);

            return;
        }
        if (node && typeof node === 'object') {
            if (node.scope?.kind === 'modal' && node.scope?.match === modalId && Array.isArray(node.items)) {
                found.push(...node.items);
            }
            Object.values(node).forEach(walk);
        }
    };
    walk(spec.states);

    return found;
}

const items = itemsFor('modal_bulk_confirm');
const defaultItem = items.find((i) => i.default) ?? items[0];

describe('편집기 일괄 변경 확인 창 표본(modal_bulk_confirm)', () => {
    /**
     * @scenario case=c01
     * @effects bulk_confirm_sample_renders
     */
    it('표본 항목이 있다', () => {
        expect(items.length).toBeGreaterThan(0);
    });

    /**
     * @scenario case=c02
     * @effects bulk_confirm_sample_renders
     */
    it.each(items.map((i) => [i.id, i] as const))('%s — 「샘플 값」 자리 채움 글자가 없다', (_id, item) => {
        expect(JSON.stringify(item.initialState)).not.toContain('샘플 값');
    });

    /**
     * @scenario case=c03
     * @effects bulk_confirm_sample_renders
     */
    it.each(items.map((i) => [i.id, i] as const))('%s — 상품 창이 읽는 상품·옵션 객체를 갖는다', (_id, item) => {
        const data = item.initialState.global.bulkConfirmData;
        expect(data.products.length).toBeGreaterThan(0);
        for (const product of data.products) {
            expect(typeof product.name).toBe('string');
            expect(typeof product.changes).toBe('string');
        }
        for (const option of data.options) {
            expect(typeof option.productName).toBe('string');
            expect(typeof option.changes).toBe('string');
            if (option.aggregate === true) {
                expect(typeof option.optionCount).toBe('number');
            } else {
                expect(typeof option.optionName).toBe('string');
            }
        }
        const optionTotal = data.options.reduce((sum: number, o: Json) => sum + (o.aggregate === true ? o.optionCount : 1), 0);
        expect(data.summary.optionCount).toBe(optionTotal);
        expect(data.summary.productCount).toBe(data.products.length);
    });

    /**
     * @scenario case=c04
     * @effects bulk_confirm_sample_renders
     */
    it.each(items.map((i) => [i.id, i] as const))('%s — 주문 창이 읽는 주문 상태·택배사·송장 번호를 갖는다', (_id, item) => {
        const data = item.initialState.global.bulkConfirmData;
        expect(typeof data.orderStatus).toBe('string');
        expect(typeof data.courierId).toBe('number');
        expect(String(data.trackingNumber)).toMatch(/^\d+$/);
    });

    /**
     * @scenario case=c05
     * @effects bulk_confirm_sample_renders
     */
    it('기본 상태는 실패 목록이 비어 있고, 실패 칸은 대안 상태가 채운다', () => {
        expect(defaultItem.initialState.global.bulkUpdateErrors ?? []).toEqual([]);
        const withErrors = items.filter((i) => (i.initialState.global.bulkUpdateErrors ?? []).length > 0);
        expect(withErrors.length).toBeGreaterThan(0);
        for (const item of withErrors) {
            for (const message of item.initialState.global.bulkUpdateErrors) {
                expect(typeof message).toBe('string');
            }
        }
    });

    /**
     * @scenario case=c06
     * @effects bulk_confirm_sample_renders
     */
    it('판매가·재고 조건은 화면이 버튼에 덧붙이는 표시 문자열이다', () => {
        const g = defaultItem.initialState.global;
        expect(g.bulkPriceCondition).toMatch(/^[+-]/);
        expect(g.bulkStockCondition).toMatch(/^[+-]/);
    });
});
