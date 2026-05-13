/**
 * 체크아웃 결제수단 회귀 테스트
 *
 * 버그: _checkout_summary.json의 결제 버튼 apiCall에서 _local.paymentMethod를 사용하여
 * 무통장입금(dbank) 선택 시에도 _local.paymentMethod가 undefined → 기본값 'card'가 전송됨
 *
 * 수정: _computed.selectedPaymentMethod를 사용하여 실제 선택/기본 결제수단이 전송되도록 변경
 *
 * @vitest-environment node
 */

import fs from 'fs';
import path from 'path';
import { describe, it, expect } from 'vitest';

const templatesRoot = path.resolve(__dirname, '../../../../../../../templates/_bundled/sirsoft-basic');

/**
 * 재귀적으로 JSON 노드에서 apiCall 핸들러의 body를 찾는다
 */
function findApiCallBody(node: any): any {
    if (node.handler === 'apiCall' && node.params?.body?.payment_method) {
        return node.params.body;
    }
    for (const key of ['actions', 'children']) {
        if (Array.isArray(node[key])) {
            for (const child of node[key]) {
                const found = findApiCallBody(child);
                if (found) return found;
            }
        }
    }
    if (Array.isArray(node.params?.actions)) {
        for (const action of node.params.actions) {
            const found = findApiCallBody(action);
            if (found) return found;
        }
    }
    if (node.slots) {
        for (const slotChildren of Object.values(node.slots)) {
            if (Array.isArray(slotChildren)) {
                for (const child of slotChildren as any[]) {
                    const found = findApiCallBody(child);
                    if (found) return found;
                }
            }
        }
    }
    return null;
}

describe('체크아웃 결제수단 회귀 테스트', () => {
    const summaryJson = JSON.parse(
        fs.readFileSync(path.join(templatesRoot, 'layouts/partials/shop/_checkout_summary.json'), 'utf-8')
    );
    const checkoutJson = JSON.parse(
        fs.readFileSync(path.join(templatesRoot, 'layouts/shop/checkout.json'), 'utf-8')
    );

    describe('_checkout_summary.json apiCall body', () => {
        const apiCallBody = findApiCallBody(summaryJson);

        it('apiCall body가 존재한다', () => {
            expect(apiCallBody).not.toBeNull();
        });

        it('payment_method가 _computed.selectedPaymentMethod를 사용한다', () => {
            expect(apiCallBody.payment_method).toBe('{{_computed.selectedPaymentMethod}}');
        });

        it('payment_method가 _local.paymentMethod를 직접 사용하지 않는다', () => {
            // _local.paymentMethod는 사용자가 결제수단 버튼을 클릭해야만 설정됨
            // 클릭 전에는 undefined이므로 직접 참조하면 안 됨
            expect(apiCallBody.payment_method).not.toContain('_local.paymentMethod');
        });

        it('dbank 조건이 _computed.selectedPaymentMethod를 사용한다', () => {
            expect(apiCallBody.dbank).toContain('_computed.selectedPaymentMethod');
        });

        it('dbank 조건이 _local.paymentMethod를 직접 사용하지 않는다', () => {
            expect(apiCallBody.dbank).not.toContain('_local.paymentMethod');
        });
    });

    describe('checkout.json computed 정의', () => {
        it('selectedPaymentMethod computed가 정의되어 있다', () => {
            expect(checkoutJson.computed?.selectedPaymentMethod).toBeDefined();
        });

        it('computed가 _local.paymentMethod를 우선 사용하되 fallback을 제공한다', () => {
            const computed = checkoutJson.computed.selectedPaymentMethod;
            // _local.paymentMethod ?? (첫 번째 활성 결제수단) 패턴
            expect(computed).toContain('_local.paymentMethod');
            expect(computed).toContain('is_active');
        });

        it('initLocal에 paymentMethod가 없다 (computed로 대체됨)', () => {
            // paymentMethod를 initLocal에 넣으면 computed와 충돌할 수 있음
            expect(checkoutJson.initLocal?.checkout?.paymentMethod).toBeUndefined();
            expect(checkoutJson.initLocal?.paymentMethod).toBeUndefined();
        });
    });

    describe('checkout.json에 죽은 submitOrder 액션이 없다', () => {
        it('actions 배열에 submitOrder가 없다', () => {
            const actions = checkoutJson.actions ?? [];
            const submitOrder = actions.find((a: any) => a.id === 'submitOrder');
            expect(submitOrder).toBeUndefined();
        });
    });
});
