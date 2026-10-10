/**
 * @file admin-user-ecommerce-data-tab.test.tsx
 * @description 회원 상세 「쇼핑 데이터」 탭 (#524 WP-E) — 네 목록으로 가는 갈림길.
 *
 * 이 탭이 지키는 계약:
 *  - 탭 버튼(`inject_props` → `tabs._append`)과 본문(`append_child`)은 서로 다른 앵커에 붙고
 *    `id`(`ext_ecommerce_data`) 하나로 이어진다. 한쪽만 고치면 탭은 있고 내용은 빈 화면이 된다.
 *  - `ecommerce_data` 는 리스너가 **관리자 응답에만** 넣는다. 프로필(/api/me)에서는 조각 전체가
 *    조건에서 걸러져야 한다.
 *  - 탭은 네 건수만 보여 주고 목록은 각 전용 화면으로 넘긴다 — 같은 표를 회원 상세에 네 벌 더
 *    두면 화면도 무겁고 검색·일괄 삭제가 두 곳으로 갈린다.
 *  - 링크는 이름이 아니라 uuid 로 좁힌다(개명·동명이인에 흔들리지 않는다).
 */

import { describe, it, expect } from 'vitest';
import { asArray, flattenNodes, findById } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

const extension = require('../../../extensions/admin-user-ecommerce-data-tab.json');

const injections = asArray(extension.injections);
const tabInjection = injections.find((i: any) => i.position === 'inject_props');
const contentInjection = injections.find((i: any) => i.position === 'append_child');
const tabItem = asArray(tabInjection?.props?.tabs?._append)[0];
const content = asArray(contentInjection?.components)[0];

const LINKS = [
    ['user-wishlists-link', '/admin/ecommerce/wishlists', 'wishlist_count'],
    ['user-carts-link', '/admin/ecommerce/carts', 'cart_count'],
    ['user-addresses-link', '/admin/ecommerce/user-addresses', 'address_count'],
    ['user-inquiries-link', '/admin/ecommerce/inquiries', 'inquiry_count'],
] as const;

describe('회원 상세 쇼핑 데이터 탭 (#524 WP-E)', () => {
    // @scenario surface=member_detail_tab
    // @effects ecommerce_member_tab_targets_admin_user_detail
    it('회원 상세 레이아웃을 대상으로 삼는다', () => {
        expect(extension.target_layout).toBe('admin_user_detail');
        expect(typeof extension.priority).toBe('number');
    });

    // @scenario surface=member_detail_tab
    // @effects ecommerce_member_tab_injects_button_and_body_separately
    it('탭 버튼과 본문이 서로 다른 앵커에 붙고 같은 id 로 이어진다', () => {
        expect(tabInjection.target_id).toBe('user_detail_tabs');
        expect(contentInjection.target_id).toBe('extension_tab_content');
        expect(tabItem.id).toBe('ext_ecommerce_data');
        expect(content.props.id).toBe('ext_ecommerce_data');
    });

    // @scenario surface=member_detail_tab, response=admin
    // @effects ecommerce_member_tab_hidden_without_admin_payload
    it('ecommerce_data 가 없으면 탭도 본문도 서지 않는다 — 프로필 응답에는 없다', () => {
        expect(tabItem.if).toMatch(/user\?\.data\?\.ecommerce_data/);
        expect(content.if).toMatch(/user\?\.data\?\.ecommerce_data/);
    });

    // @scenario surface=member_detail_tab
    // @effects ecommerce_member_tab_label_uses_module_lang_key
    it('탭 라벨이 모듈 접두사를 가진 언어 키를 쓴다', () => {
        expect(tabItem.label).toBe('$t:sirsoft-ecommerce.admin.user_ecommerce_data.tab_label');
    });

    // @scenario surface=member_detail_tab
    // @effects ecommerce_member_tab_shows_four_counts
    it('네 갈래의 건수를 모두 읽는다', () => {
        const serialized = JSON.stringify(content);

        for (const key of ['wishlist_count', 'cart_count', 'address_count', 'inquiry_count']) {
            expect(serialized).toContain(`ecommerce_data?.${key}`);
        }
    });

    // @scenario surface=member_detail_tab, list=inquiry
    // @effects ecommerce_member_tab_shows_pending_inquiry_count
    it('문의는 미답변 건수를 따로 띄운다 — 전체 건수는 «답할 것이 있는가» 를 말하지 않는다', () => {
        const pending = flattenNodes(content)
            .find((n: any) => n?.id === 'field_inquiry_pending_count');

        expect(pending).toBeTruthy();
        expect(JSON.stringify(pending)).toContain('ecommerce_data?.inquiry_pending_count');
        // 0 건이면 굳이 띄우지 않는다 — 빈 경고는 곧 무시된다
        expect(pending.if).toMatch(/inquiry_pending_count.*>\s*0/);
    });

    // @scenario surface=member_detail_tab, list=user_address
    // @effects ecommerce_member_tab_shows_saved_address_lines
    it('배송지는 건수 말고 «주소 자체» 도 편다 — 서버가 접어 보낸 줄을 그대로 읽는다', () => {
        // 건수만으로는 「어디로 보내는 회원인가」 를 알 수 없어, 상담 중에 배송지 관리 화면으로
        // 나갔다 돌아오면 그 화면의 조회 조건을 잃는다(2026-09-21 검수 지적).
        const block = flattenNodes(content).find((n: any) => n?.id === 'ecommerce_saved_addresses');

        expect(block).toBeTruthy();
        // 블록 자체에는 조건이 없다 — 0건이어도 자리를 지킨다(아래 시험이 이유를 적는다).
        expect(block.if).toBeUndefined();
        expect(JSON.stringify(block)).toMatch(/ecommerce_data\?\.addresses/);

        // 국내/해외 주소를 합치는 규칙은 서버가 소유한다 — 화면이 다시 쓰면 한쪽만 고쳐져 어긋난다.
        const json = JSON.stringify(block);

        expect(json).toContain('address.address_summary');
        expect(json).not.toMatch(/address\.zipcode/);
        // 담기지 않은 나머지는 건수로 알린다 — 목록을 다 편 것처럼 보이면 안 된다.
        expect(JSON.stringify(content)).toContain('address_more');
    });

    // @scenario surface=member_detail_tab, list=user_address
    // @effects ecommerce_member_tab_address_block_stands_when_empty
    it('배송지가 0건이어도 블록은 서고, 없다고 말한다 — 감추면 미구현과 구분되지 않는다', () => {
        // 종전에는 0건이면 블록을 통째로 접어, 배송지 없는 회원을 연 운영자에게 「버튼만 딸랑」
        // 남은 화면으로 보였다(2026-09-21). 「이 회원은 배송지가 없다」 와 「이 기능이 없다」 는
        // 다른 사실인데 화면에서 같아 보이면 안 된다.
        const nodes = flattenNodes(content);
        const block = nodes.find((n: any) => n?.id === 'ecommerce_saved_addresses');
        const empty = nodes.find((n: any) => n?.id === 'ecommerce_saved_addresses_empty');

        expect(block.if).toBeUndefined();
        expect(empty).toBeTruthy();
        expect(empty.if).toMatch(/length === 0/);
        expect(JSON.stringify(empty)).toContain('user_ecommerce_data.address_empty');

        // 줄 목록 쪽은 반대 조건을 들어야 0건에서 빈 상자가 겹쳐 서지 않는다.
        const lines = (block.children ?? []).find((n: any) => typeof n?.if === 'string' && /length > 0/.test(n.if));

        expect(lines).toBeTruthy();
    });

    // @scenario surface=member_detail_tab
    // @effects ecommerce_member_tab_carries_no_list_of_its_own
    it('탭이 자기 목록을 들고 있지 않다 — 표도 조회도 없다', () => {
        expect(flattenNodes(content).some((n: any) => n?.name === 'DataGrid')).toBe(false);
        expect(JSON.stringify(content)).not.toMatch(/apiCall/);
    });

    for (const [testId, path, countKey] of LINKS) {
        // @scenario surface=member_detail_tab, list=wishlist
        // @effects ecommerce_member_tab_links_carry_user_uuid
        it(`${testId} 가 회원 uuid 를 실어 ${path} 로 보낸다`, () => {
            const link = flattenNodes(content).find((n: any) => n?.props?.['data-testid'] === testId);

            expect(link).toBeTruthy();

            // 이동은 `navigate` 액션이 한다. `href="{{식}}"` 로 주면 렌더 시 prop 값이
            // undefined 가 되어 DOM 에 속성이 실리지 않는다 — 링크가 조용히 죽는다
            // (2026-09-15 브라우저 실측). 그래서 href 가 «없는 것» 까지 계약으로 고정한다.
            expect(link.props.href).toBeUndefined();

            const nav = JSON.stringify(link).match(/"handler":"navigate"[^}]*"path":"([^"]+)"/);
            expect(nav?.[1]).toBe(path);
            expect(JSON.stringify(link)).toMatch(/user_uuid/);
            expect(JSON.stringify(link)).toMatch(/user\?\.data\?\.uuid/);

            // 건수와 링크가 같은 갈래를 가리켜야 한다.
            expect(JSON.stringify(content)).toContain(countKey);
        });
    }
});
