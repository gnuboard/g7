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
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';

const templatesRoot = path.resolve(__dirname, '../../../../../../../templates/_bundled/sirsoft-basic');
const engine = new DataBindingEngine();

/**
 * 재귀적으로 JSON 노드에서 주문 생성 apiCall 액션을 찾는다.
 *
 * body 는 통짜 표현식 문자열이다(#454 S3 — 확장 병합 칸). 예전처럼 객체가 아니므로
 * `params.body.payment_method` 로는 찾을 수 없고, endpoint 로 식별한다.
 */
function findOrderApiCall(node: any): any {
    if (node.handler === 'apiCall' && typeof node.target === 'string' && node.target.endsWith('/user/orders')) {
        return node;
    }
    for (const key of ['actions', 'children']) {
        if (Array.isArray(node[key])) {
            for (const child of node[key]) {
                const found = findOrderApiCall(child);
                if (found) return found;
            }
        }
    }
    if (Array.isArray(node.params?.actions)) {
        for (const action of node.params.actions) {
            const found = findOrderApiCall(action);
            if (found) return found;
        }
    }
    if (node.slots) {
        for (const slotChildren of Object.values(node.slots)) {
            if (Array.isArray(slotChildren)) {
                for (const child of slotChildren as any[]) {
                    const found = findOrderApiCall(child);
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
        const orderCall = findOrderApiCall(summaryJson);

        /**
         * body 표현식을 실제로 평가해 전송 payload 를 얻는다.
         *
         * 문자열 매칭 대신 평가하는 이유: 원래 이 테스트가 고정하려던 것은 "무통장을 고르면
         * dbank 가 실려 나가는가" 이지 body 가 객체 리터럴인가가 아니다. 표현식으로 바뀐 뒤에도
         * 그 행위는 그대로 검증되어야 한다.
         */
        const evalBody = (ctx: Record<string, any>) =>
            engine.evaluateExpression(String(orderCall.params.body).slice(2, -2), ctx);

        const context = (paymentMethod: string, localPaymentMethod?: string) => ({
            checkoutData: { data: { temp_order_id: 'T-1', calculation: { summary: { final_amount: 1000 } } } },
            // body 는 _computed.selectedCorePaymentMethod 를 싣는다(#454 S3 — 결제수단 id →
            // core_payment_method 매핑). selectedPaymentMethod 는 선택된 결제수단 id, core 는
            // 그 id 가 가리키는 코어 결제수단(dbank/card/vbank …)이다. 이 회귀 테스트의 관심사인
            // "고른 결제수단이 그대로 실려 나가는가" 는 core 값으로 검증한다.
            _computed: {
                selectedPaymentMethod: paymentMethod,
                selectedCorePaymentMethod: paymentMethod,
                ordererDefaults: { name: '홍길동' },
            },
            _local: {
                paymentMethod: localPaymentMethod,
                shipping: {},
                selectedDbank: { bank_code: '088', account_number: '110-1', account_holder: '홍길동' },
            },
            _global: { currentUser: { uuid: 'u-1' } },
        });

        /**
         * @scenario case=order_body
         * @effects order_body_carries_selected_payment_method
         */
        it('주문 생성 apiCall 이 존재한다', () => {
            expect(orderCall).not.toBeNull();
            expect(typeof orderCall.params.body).toBe('string');
        });

        /**
         * @scenario case=order_body
         * @effects order_body_carries_selected_payment_method
         */
        it('payment_method 는 _computed.selectedPaymentMethod 를 그대로 싣는다', () => {
            expect(evalBody(context('dbank')).payment_method).toBe('dbank');
            expect(evalBody(context('card')).payment_method).toBe('card');
        });

        /**
         * @scenario case=order_body
         * @effects order_body_carries_selected_payment_method
         */
        it('_local.paymentMethod 가 비어 있어도 computed 값이 실린다 (기본값 card 로 새지 않는다)', () => {
            // 회귀 원인: 사용자가 결제수단 버튼을 누르기 전에는 _local.paymentMethod 가 undefined 다.
            // body 가 그것을 직접 참조하면 무통장을 골라도 card 가 전송됐다.
            expect(evalBody(context('dbank', undefined)).payment_method).toBe('dbank');
        });

        /**
         * @scenario case=order_body
         * @effects order_body_carries_selected_payment_method
         */
        it('dbank 블록은 무통장일 때만 채워지고 그 외에는 null 이다', () => {
            expect(evalBody(context('dbank')).dbank).toMatchObject({ bank_code: '088' });
            expect(evalBody(context('card')).dbank).toBeNull();
        });

        /**
         * @scenario case=order_body
         * @effects order_body_carries_selected_payment_method
         */
        it('_local.paymentMethod 가 computed 와 어긋나도 computed 가 우선한다', () => {
            const body = evalBody(context('dbank', 'card'));
            expect(body.payment_method).toBe('dbank');
            expect(body.dbank).not.toBeNull();
        });
    });

    describe('checkout.json computed 정의', () => {
        /**
         * @scenario case=computed_definition
         * @effects computed_selects_first_selectable_method
         */
        it('selectedPaymentMethod computed가 정의되어 있다', () => {
            expect(checkoutJson.computed?.selectedPaymentMethod).toBeDefined();
        });

        /**
         * @scenario case=computed_definition
         * @effects computed_selects_first_selectable_method
         */
        it('computed가 _local.paymentMethod를 우선 사용하되 fallback을 제공한다', () => {
            const computed = checkoutJson.computed.selectedPaymentMethod;
            // _local.paymentMethod ?? (고객이 고를 수 있는 첫 결제수단) 패턴 — 활성·고객 선택·iOS 판정은
            // 계산값 selectablePaymentMethods 한 곳에 두고 여기서는 그 계산값을 참조한다(같은 식 4벌 중복 제거).
            expect(computed).toContain('_local.paymentMethod');
            expect(computed).toContain('_computed.selectablePaymentMethods');
            expect(checkoutJson.computed.selectablePaymentMethods).toContain('is_active');
        });

        /**
         * @scenario case=computed_definition
         * @effects computed_selects_first_selectable_method
         */
        it('initLocal에 paymentMethod가 없다 (computed로 대체됨)', () => {
            // paymentMethod를 initLocal에 넣으면 computed와 충돌할 수 있음
            expect(checkoutJson.initLocal?.checkout?.paymentMethod).toBeUndefined();
            expect(checkoutJson.initLocal?.paymentMethod).toBeUndefined();
        });
    });

    describe('checkout.json에 죽은 submitOrder 액션이 없다', () => {
        /**
         * @scenario case=dead_action
         * @effects no_dead_submit_order_action
         */
        it('actions 배열에 submitOrder가 없다', () => {
            const actions = checkoutJson.actions ?? [];
            const submitOrder = actions.find((a: any) => a.id === 'submitOrder');
            expect(submitOrder).toBeUndefined();
        });
    });
});
