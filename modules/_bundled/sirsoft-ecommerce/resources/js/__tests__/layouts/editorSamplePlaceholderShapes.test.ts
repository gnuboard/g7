/**
 * @file editorSamplePlaceholderShapes.test.ts
 * @description 레이아웃 편집기 표본이 금액·일시·주소·오류 자리에 자동 채움 글자 「샘플 값」 을 넣지 않는지
 *
 * 편집기 표본 일부가 자동 생성되며 자리의 꼴과 관계없이 「샘플 값」 으로 채워져, 화면에 그대로 드러났다
 * (이커머스 전수 실측 S5, 2026-10-07):
 * - 마일리지 적립건 수정 창 — 금액 「샘플 값 KRW」, 적립일 「샘플 값」, 기본 상태인데 사유·만료일 칸에 검증 오류
 * - 현금영수증 발급 창 — 식별번호 칸 「샘플 값」, 기본 상태인데 빨간 오류 「샘플 값」
 * - 배송정책 기본 지정·삭제 창 — 정책 이름 「샘플 값」
 * - 상품 관리 — [판매가 변경 (샘플 값)]·[재고 일괄 변경 (샘플 값)] 버튼
 * - 브랜드·분류·공통정보·고시 화면 — 브랜드 웹사이트 「샘플 값」
 * - 배송정책 등록 — 배송비 API 시험 결과의 금액·요청 방식·오류
 *
 * 기본 상태는 정상 화면이어야 하고, 검증 오류 화면은 대안 상태(「검증 실패」)가 보여 준다.
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
 * 범위(match) 의 표본 항목들을 찾습니다.
 *
 * @param match 창 id 또는 라우트
 * @return 표본 항목 목록
 */
function itemsFor(match: string): Json[] {
    const found: Json[] = [];
    const walk = (node: any): void => {
        if (Array.isArray(node)) {
            node.forEach(walk);

            return;
        }
        if (node && typeof node === 'object') {
            if (node.scope?.match === match && Array.isArray(node.items)) {
                found.push(...node.items);
            }
            Object.values(node).forEach(walk);
        }
    };
    walk(spec.states);

    return found;
}

/**
 * 범위의 기본 상태 전역 표본을 돌려줍니다.
 *
 * @param match 창 id 또는 라우트
 * @return 기본 상태 global
 */
function defaultGlobal(match: string): Json {
    const items = itemsFor(match);

    return (items.find((i) => i.default) ?? items[0])?.initialState?.global ?? {};
}

const PLACEHOLDER = '샘플 값';

describe('편집기 표본 — 꼴이 정해진 자리에 「샘플 값」 을 넣지 않는다', () => {
    /**
     * @scenario case=c01
     * @effects sample_values_typed_not_placeholder
     */
    it('마일리지 적립건 수정 창: 금액·적립일이 값이고, 기본 상태는 검증 오류가 없다', () => {
        const edit = defaultGlobal('modal_edit_transaction').mileageEdit;
        expect(edit.amountFormatted).toMatch(/^[\d,]+$/);
        expect(edit.createdAtFormatted).toMatch(/^\d{4}-\d{2}-\d{2}/);
        expect(edit.errors ?? null).toBeNull();
    });

    /**
     * @scenario case=c02
     * @effects sample_values_typed_not_placeholder
     */
    it('마일리지 적립건 수정 창: 「검증 실패」 대안 상태가 칸별 오류를 보여 준다', () => {
        const failed = itemsFor('modal_edit_transaction').find((i) => i.label === '$t:editor.state.validation_failed');
        expect(failed).toBeDefined();
        expect(Object.keys(failed!.initialState.global.mileageEdit.errors.errors).length).toBeGreaterThan(0);
    });

    /**
     * @scenario case=c03
     * @effects sample_values_typed_not_placeholder
     */
    it('현금영수증 발급 창: 식별번호가 사업자번호 꼴이고, 기본 상태는 오류가 없다', () => {
        const form = defaultGlobal('modal_issue_cash_receipt').adminCashReceiptForm;
        expect(form.identifier).toMatch(/^\d{3}-\d{2}-\d{5}$/);
        expect(form.error ?? null).toBeNull();
        const failed = itemsFor('modal_issue_cash_receipt').find((i) => i.label === '$t:editor.state.validation_failed');
        expect(typeof failed?.initialState.global.adminCashReceiptForm.error).toBe('string');
        expect(failed?.initialState.global.adminCashReceiptForm.error).not.toBe(PLACEHOLDER);
    });

    /**
     * @scenario case=c04
     * @effects sample_values_typed_not_placeholder
     */
    it('배송정책 기본 지정·삭제 창: 정책 이름·국가 표시가 값이다', () => {
        expect(defaultGlobal('set_default_modal').targetDefaultPolicy.name_localized).not.toBe(PLACEHOLDER);
        const target = defaultGlobal('delete_modal').targetPolicy;
        expect(target.name_localized).not.toBe(PLACEHOLDER);
        expect(target.countries_display).not.toBe(PLACEHOLDER);
    });

    /**
     * @scenario case=c05
     * @effects sample_values_typed_not_placeholder
     */
    it('상품 관리: 판매가·재고 조건이 버튼에 덧붙는 표시 문자열이다', () => {
        const g = defaultGlobal('*/admin/ecommerce/products');
        expect(g.bulkPriceCondition).toMatch(/^[+-]/);
        expect(g.bulkStockCondition).toMatch(/^[+-]/);
    });

    /**
     * @scenario case=c06
     * @effects sample_values_typed_not_placeholder
     */
    it.each(['*/admin/ecommerce/brands', '*/admin/ecommerce/categories', '*/admin/ecommerce/common-info', '*/admin/ecommerce/product-notices'])('%s: 브랜드 웹사이트가 주소다', (route) => {
        expect(defaultGlobal(route).selectedBrand.website).toMatch(/^https?:\/\//);
    });

    /**
     * @scenario case=c07
     * @effects sample_values_typed_not_placeholder
     */
    it('배송정책 등록: 배송비 API 시험 결과가 금액·요청 방식·상태 코드 꼴이고 오류가 없다', () => {
        const r = defaultGlobal('*/admin/ecommerce/shipping-policies/create').apiTestResult;
        expect(r.error ?? null).toBeNull();
        expect(typeof r.extracted_fee).toBe('number');
        expect(r.extracted_fee_formatted).toMatch(/[\d,]+원/);
        expect(r.request.method).toMatch(/^(GET|POST)$/);
        expect(r.request.endpoint).toMatch(/^https?:\/\//);
        expect(typeof r.response.status).toBe('number');
    });
});
