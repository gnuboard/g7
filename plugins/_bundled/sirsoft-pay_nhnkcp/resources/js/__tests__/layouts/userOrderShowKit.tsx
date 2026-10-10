/**
 * NHN KCP 주문 상세 결제 정보 카드 조각(user_order_show) 렌더 테스트 공용 도구
 *
 * 설치되는 조각 JSON 의 컴포넌트를 엔진(createLayoutTest)에 그대로 태운다. 기본 컴포넌트만 HTML 태그로 등록하고,
 * 번역은 플러그인 사전(ko·en)을 그대로 넣는다.
 */

import React from 'react';
// 코어 엔진은 저장소 루트 기준 상대 경로로 부른다(설정의 `@core` 별칭은 한 단계 모자라 풀리지 않는다).
import { createLayoutTest } from '../../../../../../../resources/js/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '../../../../../../../resources/js/core/template-engine/ComponentRegistry';
import extension from '../../../extensions/user_order_show.json';
import ko from '../../../lang/ko.json';
import en from '../../../lang/en.json';

export type LayoutTest = ReturnType<typeof createLayoutTest>;

/** 조각이 쓰는 기본 컴포넌트 이름 → HTML 태그 */
const TAGS: Record<string, string> = {
    Div: 'div',
    Span: 'span',
    P: 'p',
    Button: 'button',
    Icon: 'i',
    Form: 'form',
    Input: 'input',
};

/**
 * 기본 컴포넌트를 HTML 태그로 등록한다.
 *
 * @returns 컴포넌트 레지스트리
 */
function setupRegistry(): ComponentRegistry {
    const registry = ComponentRegistry.getInstance();
    const entries: Record<string, unknown> = {};
    for (const [name, tag] of Object.entries(TAGS)) {
        entries[name] = {
            component: ({ text, children, className, type, disabled, id, name: inputName, value, 'data-testid': testId }: any) => (tag === 'input'
                ? React.createElement(tag, { className, type, id, name: inputName, defaultValue: value, 'data-testid': testId })
                : React.createElement(tag, { className, type, disabled, id, 'data-testid': testId }, children ?? text)),
            metadata: { name, type: 'basic' },
        };
    }
    entries.Fragment = { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } };
    (registry as any).registry = entries;

    return registry;
}

/**
 * 주어진 주문 데이터로 주문 상세 조각 전체를 렌더한다.
 *
 * @param order 주문 데이터 소스의 data
 * @param locale 화면 언어 (기본 ko)
 * @returns 렌더된 테스트 도구
 */
export async function renderUserOrderShow(order: Record<string, unknown>, locale: 'ko' | 'en' = 'ko'): Promise<LayoutTest> {
    (window as any).__templateApp = { getConfig: () => ({ templateId: 'test-template', locale }) };
    const utils = createLayoutTest(
        { version: '1.0.0', layout_name: 'nhnkcp_user_order_show_test', components: (extension as any).components },
        {
            initialData: { order: { data: order } },
            translations: { 'sirsoft-pay_nhnkcp': locale === 'en' ? en : ko },
            locale,
            componentRegistry: setupRegistry(),
        },
    );
    await utils.render();

    return utils;
}

/**
 * 렌더 뒤 정리한다.
 *
 * @param utils 렌더된 테스트 도구
 * @returns void
 */
export function cleanupRender(utils: LayoutTest | null): void {
    utils?.cleanup();
    (ComponentRegistry.getInstance() as any).registry = {};
    delete (window as any).__templateApp;
}

/**
 * 가상계좌 결제 주문 데이터를 만든다.
 *
 * @param payment 덮어쓸 결제 값
 * @param order 덮어쓸 주문 값
 * @returns 주문 데이터 소스의 data
 */
export function kcpVbankOrder(payment: Record<string, unknown> = {}, order: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        order_number: 'ORD-V',
        order_status: 'pending_payment',
        total_vat_amount: 0,
        total_tax_free_amount: 0,
        total_amount_formatted: '15,000원',
        ...order,
        payment: {
            pg_provider: 'nhnkcp',
            payment_method: 'vbank',
            payment_status: 'waiting_deposit',
            vbank_name: '기업은행',
            vbank_number: 'T0309',
            vbank_holder: 'NHN KCP',
            paid_at: null,
            ...payment,
        },
    };
}
