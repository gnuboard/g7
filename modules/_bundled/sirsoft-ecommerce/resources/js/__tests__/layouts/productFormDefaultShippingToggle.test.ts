// e2e:allow 이커머스 전수 실측 S5 — Playwright MCP 브라우저로 화면 재현·재실측(이력 「Chrome MCP 정밀 점검 매트릭스」·결함 표). 커밋 단위 E2E spec 은 후속 판단으로 이월
/**
 * @file productFormDefaultShippingToggle.test.ts
 * @description 상품 수정·복사 화면의 「기본 배송정책 사용」 스위치가 저장된 정책과 맞는지
 *
 * 상품 폼은 `ui.useDefaultShippingPolicy` 를 늘 true 로 시작해, 기본이 아닌 배송정책을 쓰는 상품을 열어도
 * 스위치가 「기본 사용」 으로 켜지고 정책 선택지가 잠겼다 — 화면은 기본 정책을 쓴다고 말하는데 실제로는
 * 다른 정책이 골라져 있었다(이커머스 전수 실측 S5-D40, 2026-10-08 — §5 회귀 축 배송정책 → 상품 연결 뒤 다시 열기).
 * 진입 시 저장된 정책이 기본 정책일 때만 스위치를 켜는지 확인한다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

const LAYOUT = path.resolve(__dirname, '../../../layouts/admin/admin_ecommerce_product_form.json');

/**
 * 트리를 순회하며 조건에 맞는 노드를 모읍니다.
 *
 * @param node 시작 노드
 * @param predicate 수집 조건
 * @return 일치 노드 목록
 */
function collectNodes(node: any, predicate: (n: any) => boolean): any[] {
    const found: any[] = [];
    const walk = (current: any): void => {
        if (Array.isArray(current)) {
            current.forEach(walk);

            return;
        }
        if (current && typeof current === 'object') {
            if (predicate(current)) {
                found.push(current);
            }
            Object.values(current).forEach(walk);
        }
    };
    walk(node);

    return found;
}

const layout = JSON.parse(fs.readFileSync(LAYOUT, 'utf8'));
const initActions = layout.init_actions ?? [];
const toggleInit = collectNodes(initActions, (n) => n.handler === 'setState' && n.params && 'ui.useDefaultShippingPolicy' in n.params);

/**
 * 진입 시 스위치 값 식을 주어진 데이터로 계산합니다.
 *
 * @param data 데이터소스 값
 * @return 스위치 값
 */
function evaluate(data: { product?: any; copy_source?: any; shipping_policies?: any }): unknown {
    const expr = String(toggleInit[0].params['ui.useDefaultShippingPolicy']).replace(/^\{\{|\}\}$/g, '');
    // eslint-disable-next-line no-new-func
    return new Function('product', 'copy_source', 'shipping_policies', `return (${expr});`)(data.product, data.copy_source, data.shipping_policies);
}

const policies = { data: { data: [{ id: 1, is_default: true }, { id: 194, is_default: false }] } };

describe('상품 폼 — 기본 배송정책 사용 스위치 초기값', () => {
    /**
     * @scenario case=c01
     * @effects product_form_default_shipping_toggle_matches_saved
     */
    it('수정·복사 진입에서 스위치 값을 저장된 정책으로 다시 정한다', () => {
        expect(toggleInit.length).toBe(1);
        expect(String(toggleInit[0].if ?? '')).toMatch(/route\.itemCode/);
    });

    /**
     * @scenario case=c02
     * @effects product_form_default_shipping_toggle_matches_saved
     */
    it('기본이 아닌 정책을 쓰는 상품이면 스위치를 끈다(선택지가 열린다)', () => {
        expect(evaluate({ product: { data: { shipping_policy_id: 194 } }, shipping_policies: policies })).toBe(false);
    });

    /**
     * @scenario case=c03
     * @effects product_form_default_shipping_toggle_matches_saved
     */
    it('기본 정책을 쓰는 상품·복사 원본이면 스위치를 켠다', () => {
        expect(evaluate({ product: { data: { shipping_policy_id: 1 } }, shipping_policies: policies })).toBe(true);
        expect(evaluate({ copy_source: { data: { shipping_policy_id: 1 } }, shipping_policies: policies })).toBe(true);
        expect(evaluate({ copy_source: { data: { shipping_policy_id: 194 } }, shipping_policies: policies })).toBe(false);
    });
});
