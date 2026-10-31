/**
 * @file mypagePaymentPanelSingleSource.test.ts
 * @description 마이페이지 주문 상세 › 나이스페이먼츠 입금 안내·영수증 행은 레이아웃 조각 하나만 그린다 (#599, #639)
 *
 * 종전에는 조각(user_mypage_order_receipt)과 주입 스크립트(mypageOrderShowInjector)가 같은 「가상계좌 입금 안내」와
 * 「영수증」 행을 각각 그려, 입금 대기 주문은 입금 안내가, 결제 완료 주문은 영수증 행이 두 번 나왔다. 스크립트 쪽은
 * 한국어 고정 글자·자체 날짜 서식이었고 조각 쪽 입금 기한은 가공 전 ISO 값이었다.
 * #639 에서 그 주입 스크립트를 걷어냈다 — 이제 조각만 그리고, 입금 기한은 쇼핑몰이 서식을 입힌
 * vbank_due_at_formatted 를 쓰며, 스크립트가 열던 매출전표(type=0, API 의 receipt_url)는 조각의 영수증 행에 링크로 옮겼다.
 *
 * 시나리오: plugins/_bundled/sirsoft-pay_nicepayments/tests/scenarios/mypage-payment-panel-single-source.yaml
 */

import { readdirSync, readFileSync } from 'fs';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
// 코어 엔진은 저장소 루트 기준 상대 경로로 부른다(설정의 `@core` 별칭은 한 단계 모자라 풀리지 않는다).
import { DataBindingEngine } from '../../../../../../../resources/js/core/template-engine/DataBindingEngine';
import { createLayoutTest, screen } from '../../../../../../../resources/js/core/template-engine/__tests__/utils/layoutTestUtils';
import extensionLayout from '../../../extensions/user_mypage_order_receipt.json';
import {
    PLUGIN_TRANSLATIONS,
    clearTemplateConfig,
    resetFragmentRegistry,
    setupFragmentRegistry,
    stubTemplateConfig,
} from './fragmentRenderHelpers';

const MARKUP_PATH = path.resolve(__dirname, '../../../extensions/user_mypage_order_receipt.g7.html');
const JS_DIR = path.resolve(__dirname, '../..');
const SLIP_KEY = '_npMpSalesSlipUrl';
const SLIP_URL = 'https://npg.nicepay.co.kr/issue/IssueLoader.do?type=0&TID=NICE_TID_TEST';

type Node = Record<string, unknown>;

function find(node: unknown, match: (n: Node) => boolean): Node | undefined {
    if (!node || typeof node !== 'object') return undefined;
    const value = node as Node;
    if (match(value)) return value;
    for (const child of Object.values(value)) {
        const found = find(child, match);
        if (found) return found;
    }

    return undefined;
}

let utils: ReturnType<typeof createLayoutTest> | null = null;

/**
 * 결제 정보 카드 슬롯에 붙는 조각을 주문 응답으로 그립니다.
 *
 * @param payment 주문 응답의 결제 정보
 */
async function renderSlot(payment: Record<string, unknown>): Promise<void> {
    utils = createLayoutTest(
        { version: '1.0.0', layout_name: 'mypage_payment_slot_test', components: (extensionLayout as any).components } as any,
        {
            translations: PLUGIN_TRANSLATIONS,
            locale: 'ko',
            componentRegistry: setupFragmentRegistry(),
            initialData: { order: { data: { order_number: 'ORD-1', total_amount_formatted: '10,000원', payment: { pg_provider: 'nicepayments', ...payment } } } },
        },
    );
    utils.mockApi('sirsoft_pay_nicepayments', { response: { receipt_url: SLIP_URL, payment_receipt_url: SLIP_URL } });
    await utils.render();
    await new Promise((resolve) => setTimeout(resolve, 0));
    await utils.rerender();
}

/**
 * 화면에 글자가 몇 번 나오는지 셉니다.
 *
 * @param text 찾을 글자
 * @return 나온 횟수
 */
function occurrences(text: string): number {
    return (document.body.textContent ?? '').split(text).length - 1;
}

describe('나이스페이먼츠 마이페이지 — 입금 안내·영수증 행은 조각 하나만', () => {
    beforeEach(() => {
        stubTemplateConfig();
    });

    afterEach(() => {
        utils?.cleanup();
        utils = null;
        resetFragmentRegistry();
        clearTemplateConfig();
    });

    /**
     * @scenario order=vbank_waiting
     * @effects script_draws_no_duplicate
     */
    it('입금 대기 가상계좌 주문: 입금 안내는 조각이 한 번만 그리고, 화면을 고쳐 쓰는 주입 스크립트는 없다', async () => {
        await renderSlot({ payment_method: 'vbank', vbank_number: '12345678901234', vbank_name: '국민', vbank_due_at_formatted: '2026-09-30 23:59' });

        expect(occurrences('가상계좌 입금 안내')).toBe(1);
        expect(screen.getByText('2026-09-30 23:59')).toBeTruthy();
        // 결제 정보 카드를 DOM 에서 찾아 덧그리던 스크립트가 다시 생기지 않았는지 — 카드 id 를 소스에서 찾는 모듈이 없어야 한다
        const scripts = readdirSync(JS_DIR).filter((f) => f.endsWith('.ts'));
        for (const file of scripts) {
            expect(readFileSync(path.join(JS_DIR, file), 'utf8'), file).not.toContain('order_payment_info_panel');
        }
    });

    /**
     * @scenario order=card_paid
     * @effects script_draws_no_duplicate
     */
    it('결제 완료 주문: 영수증 행은 조각이 한 번만 그린다', async () => {
        await renderSlot({ payment_method: 'card', transaction_id: 'T-1', paid_at: '2026-09-28 10:00:00' });

        expect(occurrences('매출전표 보기')).toBe(1);
        expect(occurrences('가상계좌 입금 안내')).toBe(0);
    });

    /**
     * @scenario order=vbank_waiting
     * @effects due_date_uses_shop_formatted_value
     */
    it('조각의 입금 기한은 쇼핑몰이 서식을 입힌 vbank_due_at_formatted 를 쓴다', () => {
        const due = find(extensionLayout, (n) => n.text === '{{order.data.payment?.vbank_due_at_formatted}}');
        expect(due, '입금 기한 값 노드').toBeDefined();
        expect(JSON.stringify(extensionLayout)).not.toContain('order.data.payment?.vbank_due_at}}');
        expect(readFileSync(MARKUP_PATH, 'utf8')).not.toContain('order.data.payment?.vbank_due_at}}');
    });

    /**
     * @scenario order=card_paid
     * @effects sales_slip_link_in_fragment
     */
    it('조각의 영수증 행에 매출전표 링크가 있고 API 의 receipt_url 로 그린다', () => {
        const call = find(extensionLayout, (n) => n.handler === 'apiCall'
            && typeof n.target === 'string' && (n.target as string).endsWith('/user/orders/{{order.data.order_number}}/receipt')
            && JSON.stringify(n.onSuccess ?? {}).includes('payment_receipt_url'));
        expect(call, '마운트 영수증 조회').toBeDefined();
        expect(((call!.onSuccess as Node).params as Node)[SLIP_KEY]).toBe('{{response.data.receipt_url}}');

        const link = find(extensionLayout, (n) => typeof (n.props as Node | undefined)?.href === 'string'
            && ((n.props as Node).href as string).includes(SLIP_KEY));
        expect(link, '매출전표 링크').toBeDefined();
        expect(JSON.stringify(link)).toContain('$t:sirsoft-pay_nicepayments.user.view_sales_slip');

        const engine = new DataBindingEngine();
        const context = { _local: { [SLIP_KEY]: SLIP_URL } };
        expect(engine.resolveBindings((link!.props as Node).href as string, context)).toBe(SLIP_URL);
        expect(Boolean(engine.resolveBindings(link!.if as string, context))).toBe(true);
        expect(Boolean(engine.resolveBindings(link!.if as string, { _local: {} }))).toBe(false);
    });
});
