/**
 * @file bulkConfirmModalBinding.test.tsx
 * @description A27 — 일괄변경 전체선택 SSoT (구조 검증)
 *
 * 적용 sequence(setState global → buildConfirmData → openModal) 와
 * 모달 no_changes/섹션 표시가 _global.bulkConfirmData 기준인지 검증한다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import productList from '../../../layouts/admin/admin_ecommerce_product_list.json';
import bulkConfirmModal from '../../../layouts/admin/partials/admin_ecommerce_product_list/_modal_bulk_confirm.json';
import bulkPriceModal from '../../../layouts/admin/partials/admin_ecommerce_product_list/_modal_bulk_price.json';
import bulkStockModal from '../../../layouts/admin/partials/admin_ecommerce_product_list/_modal_bulk_stock.json';

const flat = (obj: unknown) => JSON.stringify(obj);

/**
 * 객체 트리에서 조건을 만족하는 첫 노드를 찾는다.
 */
function findNode(node: any, pred: (n: any) => boolean): any {
    if (!node || typeof node !== 'object') return null;
    if (pred(node)) return node;
    for (const key of Object.keys(node)) {
        const child = node[key];
        if (Array.isArray(child)) {
            for (const c of child) {
                const found = findNode(c, pred);
                if (found) return found;
            }
        } else if (child && typeof child === 'object') {
            const found = findNode(child, pred);
            if (found) return found;
        }
    }
    return null;
}

describe('A27 — 일괄변경 전체선택 SSoT', () => {
    it('적용 버튼 sequence: setState(global) → buildConfirmData → openModal 순서', () => {
        // buildConfirmData 를 포함하는 sequence 액션 탐색
        const seq = findNode(productList, (n) =>
            n.handler === 'sequence' &&
            Array.isArray(n.actions) &&
            n.actions.some((a: any) => a.handler === 'sirsoft-ecommerce.buildConfirmData'),
        );
        expect(seq).toBeTruthy();

        const handlers = seq.actions.map((a: any) => a.handler);
        const setStateIdx = handlers.indexOf('setState');
        const buildIdx = handlers.indexOf('sirsoft-ecommerce.buildConfirmData');
        const openIdx = handlers.indexOf('openModal');

        expect(setStateIdx).toBeGreaterThanOrEqual(0);
        expect(setStateIdx).toBeLessThan(buildIdx);
        expect(buildIdx).toBeLessThan(openIdx);

        // setState 가 _local.selected* → _global.bulkSelected* 복사
        const setStateAction = seq.actions[setStateIdx];
        expect(setStateAction.params.target).toBe('global');
        expect(setStateAction.params.bulkSelectedItems).toBe('{{_local.selectedItems}}');
        expect(setStateAction.params.bulkSelectedOptionIds).toBe('{{_local.selectedOptionIds}}');
    });

    it('모달 no_changes/섹션은 _global.bulkConfirmData 기준으로 판정', () => {
        const s = flat(bulkConfirmModal);
        expect(s).toContain('_global.bulkConfirmData?.products?.length > 0');
        expect(s).toContain('_global.bulkConfirmData?.options?.length > 0');
        // no_changes 는 products/options 둘 다 0 일 때만
        expect(s).toContain('bulk_confirm.no_changes');
    });

    it('§T5 — 적용 sequence 가 bulkPriceCondition/bulkStockCondition 을 _local 로 덮어쓰지 않는다', () => {
        // 판매가/재고 모달은 "변경" 시 _global.bulkPriceCondition / bulkStockCondition 에 직접 쓴다.
        // 적용 sequence 가 _local.bulkPriceCondition(null) 로 _global 을 clobber 하면 빈 모달이 된다.
        const seq = findNode(productList, (n) =>
            n.handler === 'sequence' &&
            Array.isArray(n.actions) &&
            n.actions.some((a: any) => a.handler === 'sirsoft-ecommerce.buildConfirmData'),
        );
        expect(seq).toBeTruthy();
        const setStateAction = seq.actions.find((a: any) => a.handler === 'setState');
        expect(setStateAction).toBeTruthy();

        // bulkPriceCondition / bulkStockCondition 는 sequence setState 에서 제거되어야 한다
        expect(setStateAction.params).not.toHaveProperty('bulkPriceCondition');
        expect(setStateAction.params).not.toHaveProperty('bulkStockCondition');

        // 판매/노출 상태와 선택 소스는 _local 이 유효하므로 유지
        expect(setStateAction.params.bulkSalesStatus).toBe('{{_local.bulkSalesStatus}}');
        expect(setStateAction.params.bulkDisplayStatus).toBe('{{_local.bulkDisplayStatus}}');
        expect(setStateAction.params.bulkSelectedItems).toBe('{{_local.selectedItems}}');
    });

    it('일괄 실패 시 _global.bulkUpdateErrors 를 상세로 노출하는 에러 섹션이 존재한다', () => {
        const s = flat(bulkConfirmModal);
        // 에러 섹션은 bulkUpdateErrors 가 있을 때만 표시
        expect(s).toContain('_global.bulkUpdateErrors?.length > 0');
        // 각 에러 메시지를 iteration 으로 렌더
        expect(s).toContain('_global.bulkUpdateErrors');
        expect(s).toContain('error_message');
        expect(s).toContain('bulk_confirm.error_section');

        // 에러 섹션 노드 구조 검증: iteration source 가 bulkUpdateErrors
        const iter = findNode(bulkConfirmModal, (n) =>
            n.iteration && n.iteration.source === '_global.bulkUpdateErrors',
        );
        expect(iter).toBeTruthy();
        expect(iter.iteration.item_var).toBe('error_message');
        expect(iter.text).toBe('{{error_message}}');
    });

    it('§T5 — 판매가/재고 모달은 condition 을 _global 에 직접 쓴다 (SSoT)', () => {
        const priceFlat = flat(bulkPriceModal);
        const stockFlat = flat(bulkStockModal);
        // "변경" 버튼이 target:global 로 bulkPriceCondition / bulkStockCondition 설정
        expect(priceFlat).toContain('bulkPriceCondition');
        expect(priceFlat).toContain('"target":"global"');
        expect(stockFlat).toContain('bulkStockCondition');
        expect(stockFlat).toContain('"target":"global"');
    });
});
