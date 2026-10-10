/**
 * @file adminIconOnlyButtonNames.test.ts
 * @description 관리자 설정·카탈로그·판촉·배송정책·배너·회원 배송지 화면의 아이콘만 있는 버튼이 읽을 수 있는 이름을 갖는지
 *
 * 글자 없이 아이콘만 그리는 버튼(휴지통·연필·×·체크·새로고침·뒤로·이전/다음·펼침)은 aria-label 이 없으면 화면 낭독기가
 * 아이콘 이름(「trash」·「xmark」·「pencil」 등)으로 읽거나 그냥 「버튼」 이라고만 읽는다(이커머스 전수 실측 S5, 2026-10-07 —
 * 환경설정 통화·결제수단·택배사 표, 상품 옵션 줄, 쿠폰 회원 칩 등 26개 파일 50여 곳). 각 버튼에 하는 일을 이름으로 단다.
 *
 * 상품 목록 머리의 새로고침 버튼은 이름에 더해 **눌러도 아무 일이 없었다**(처리기 없음) — 목록을 다시 부르게 한다.
 *
 * 예시 값만 박힌 골격 화면(개인결제·입금·결제 실패·엑셀 내려받기·할인코드)은 배선 때 함께 다루므로 여기서 제외한다.
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

const LAYOUTS = resolve(__dirname, '../../../layouts/admin');

const FILES = [
    'admin_ecommerce_main_banner_index',
    'admin_ecommerce_product_list',
    'admin_ecommerce_promotion_coupon_form',
    'partials/admin_ecommerce_main_banner_index/_partial_preview_slider',
    'partials/admin_ecommerce_product_form/_partial_basic_info',
    'partials/admin_ecommerce_product_form/_partial_product_options',
    'partials/admin_ecommerce_product_list/_modal_preset_manage',
    'partials/admin_ecommerce_promotion_coupon_list/_modal_direct_issue',
    'partials/admin_ecommerce_promotion_coupon_list/_modal_issue_history',
    'partials/admin_ecommerce_promotion_coupon_list/_partial_filter_section',
    'partials/admin_ecommerce_settings/_bank_accounts_cards',
    'partials/admin_ecommerce_settings/_bank_accounts_table',
    'partials/admin_ecommerce_settings/_bank_management_modal',
    'partials/admin_ecommerce_settings/_currency_exchange_cards',
    'partials/admin_ecommerce_settings/_currency_exchange_table',
    'partials/admin_ecommerce_settings/_payment_methods_cards',
    'partials/admin_ecommerce_settings/_payment_methods_list',
    'partials/admin_ecommerce_settings/_shipping_carrier_cards',
    'partials/admin_ecommerce_settings/_shipping_country_cards',
    'partials/admin_ecommerce_settings/_tab_mileage_currency_cards',
    'partials/admin_ecommerce_settings/_tab_mileage_currency_table',
    'partials/admin_ecommerce_settings/_tab_notification_definitions',
    'partials/admin_ecommerce_shipping_policy_form/_modal_extra_fee_template',
    'partials/admin_ecommerce_shipping_policy_form/_partial_charge_settings',
    'partials/admin_ecommerce_shipping_policy_form/_partial_extra_fee',
    'partials/admin_ecommerce_user_address_index/_partial_filter_section',
];

type Node = Record<string, any>;

/**
 * 노드의 보이는 글자를 모읍니다. 아이콘은 글자가 아니다.
 *
 * @param n 노드
 * @return 글자
 */
function textOf(n: any): string {
    if (n == null) return '';
    if (typeof n === 'string') return n;
    if (Array.isArray(n)) return n.map(textOf).join('');
    if (typeof n === 'object') {
        if (n.name === 'Icon') return '';

        return String(n.text ?? '') + textOf(n.children);
    }

    return '';
}

/**
 * 아이콘만 있고 이름(aria-label·title)이 없는 버튼을 모읍니다.
 *
 * @param node 시작 노드
 * @param acc 누적
 * @return 버튼 목록
 */
function unnamedIconButtons(node: any, acc: Node[] = []): Node[] {
    if (Array.isArray(node)) {
        node.forEach((n) => unnamedIconButtons(n, acc));

        return acc;
    }
    if (node && typeof node === 'object') {
        if (
            node.name === 'Button'
            && JSON.stringify(node.children ?? []).includes('"name":"Icon"')
            && !textOf({ ...node, name: 'Button' }).trim()
            && !node.props?.['aria-label']
            && !node.props?.title
        ) {
            acc.push(node);
        }
        Object.values(node).forEach((v) => unnamedIconButtons(v, acc));
    }

    return acc;
}

const load = (rel: string): Node => JSON.parse(readFileSync(resolve(LAYOUTS, `${rel}.json`), 'utf8'));

describe('관리자 아이콘 버튼 — 읽을 수 있는 이름', () => {
    /**
     * @scenario case=c01
     * @effects icon_only_buttons_named
     */
    it.each(FILES)('%s — 아이콘만 있는 버튼마다 aria-label 이 있다', (rel) => {
        const missing = unnamedIconButtons(load(rel)).map((b) => JSON.stringify(b.children).match(/"name":"Icon","props":\{"name":"([^"]+)"/)?.[1] ?? '?');
        expect(missing).toEqual([]);
    });

    /**
     * @scenario case=c02
     * @effects icon_only_buttons_named
     */
    it('상품 목록 새로고침 버튼은 목록(products)을 다시 부른다', () => {
        const layout = load('admin_ecommerce_product_list');
        const buttons: Node[] = [];
        (function walk(n: any) {
            if (Array.isArray(n)) return n.forEach(walk);
            if (n && typeof n === 'object') {
                if (n.name === 'Button' && JSON.stringify(n.children ?? []).includes('arrows-rotate')) buttons.push(n);
                Object.values(n).forEach(walk);
            }
        })(layout);
        expect(buttons.length).toBe(1);
        expect(buttons[0].props?.['aria-label']).toBe('$t:common.refresh');
        expect(JSON.stringify(buttons[0].actions ?? [])).toContain('"dataSourceId":"products"');
    });

    /**
     * @scenario case=c03
     * @effects icon_only_buttons_named
     */
    it('대표 계좌 버튼은 눌림 상태를 알린다(aria-pressed)', () => {
        for (const rel of ['partials/admin_ecommerce_settings/_bank_accounts_cards', 'partials/admin_ecommerce_settings/_bank_accounts_table']) {
            const s = JSON.stringify(load(rel));
            expect(s, rel).toContain('"aria-pressed":"{{account.is_default ? \'true\' : \'false\'}}"');
        }
    });
});
