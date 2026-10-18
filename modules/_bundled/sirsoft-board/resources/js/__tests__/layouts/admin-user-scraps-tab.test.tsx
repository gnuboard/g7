/**
 * @file admin-user-scraps-tab.test.tsx
 * @description 회원 상세 「스크랩」 탭 확장 (#524 WP-A) — 주입이 두 군데로 갈리는 조각의 계약.
 *
 * 이 조각이 지키는 계약:
 *  - 탭 버튼(`inject_props` → `tabs._append`)과 본문(`append_child`)은 **서로 다른 앵커**에 붙고
 *    둘을 잇는 것은 `id`(`ext_board_scraps`) 하나뿐이다. 한쪽만 고치면 탭은 있고 내용은 빈 화면이
 *    된다 — 그 회귀를 여기서 막는다.
 *  - `board_scraps` 는 `UserScrapInfoListener` 가 **관리자 응답에만** 넣는다. 프로필(/api/me)에서는
 *    조각 전체가 조건에서 걸러져야 한다(회원 본인 화면에 관리자 요약이 새면 안 된다).
 *  - 탭은 **요약만** 낸다(PO 결정 R1). 목록은 스크랩 관리 화면이 이미 검색·필터·일괄 삭제까지
 *    갖추고 있으므로, 같은 표를 회원 상세에 한 벌 더 두지 않고 uuid 를 실어 보낸다.
 */

import { describe, it, expect } from 'vitest';
import { asArray, flattenNodes, findById } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

const extension = require('../../../extensions/admin-user-scraps-tab.json');

const injections = asArray(extension.injections);
const tabInjection = injections.find((i: any) => i.position === 'inject_props');
const contentInjection = injections.find((i: any) => i.position === 'append_child');
const tabItem = asArray(tabInjection?.props?.tabs?._append)[0];
const content = asArray(contentInjection?.components)[0];

describe('회원 상세 스크랩 탭 확장 (#524 WP-A)', () => {
    // @scenario surface=member_detail_tab
    // @effects member_tab_targets_admin_user_detail
    it('회원 상세 레이아웃을 대상으로 삼는다', () => {
        expect(extension.target_layout).toBe('admin_user_detail');
        expect(typeof extension.priority).toBe('number');
    });

    // @scenario surface=member_detail_tab
    // @effects member_tab_injects_button_and_body_separately
    it('탭 버튼과 본문이 서로 다른 앵커에 붙는다', () => {
        expect(tabInjection.target_id).toBe('user_detail_tabs');
        expect(contentInjection.target_id).toBe('extension_tab_content');
    });

    // @scenario surface=member_detail_tab
    // @effects member_tab_button_and_body_share_one_id
    it('탭 버튼의 id 와 본문 앵커 id 가 같다 — 어긋나면 빈 화면이 된다', () => {
        expect(tabItem.id).toBe('ext_board_scraps');
        // 본문은 바깥 래퍼의 props.id 가 앵커다(스크롤 스파이가 그것을 찾는다).
        expect(content.props.id).toBe('ext_board_scraps');
    });

    // @scenario surface=member_detail_tab, response=admin
    // @effects member_tab_hidden_without_admin_payload
    it('board_scraps 가 없으면 탭도 본문도 서지 않는다 — 프로필 응답에는 그 값이 없다', () => {
        expect(tabItem.if).toMatch(/user\?\.data\?\.board_scraps/);
        expect(content.if).toMatch(/user\?\.data\?\.board_scraps/);
    });

    // @scenario surface=member_detail_tab
    // @effects member_tab_label_uses_module_lang_key
    it('탭 라벨이 모듈 접두사를 가진 언어 키를 쓴다', () => {
        expect(tabItem.label).toBe('$t:sirsoft-board.admin.users.scraps.tab_label');
        expect(tabItem.iconName).toBe('bookmark');
    });

    // @scenario surface=member_detail_tab
    // @effects member_tab_shows_count_and_latest_only
    it('요약은 건수와 최근 스크랩 시각 둘뿐이다 — 목록을 옮겨 오지 않는다', () => {
        const serialized = JSON.stringify(content);

        expect(serialized).toMatch(/board_scraps\?\.count/);
        expect(serialized).toMatch(/board_scraps\?\.latest_scrapped_at/);
        // 목록을 그리면 DataGrid 나 반복이 생긴다 — 없어야 한다.
        expect(flattenNodes(content).some((n: any) => n?.name === 'DataGrid')).toBe(false);
        expect(serialized).not.toMatch(/apiCall/);
    });

    // @scenario surface=member_detail_tab, scrap_count=0
    // @effects member_tab_link_hidden_when_empty
    it('스크랩이 0건이면 목록 링크 대신 빈 상태 문구를 낸다', () => {
        const link = findById(content, 'view_member_scraps');

        expect(link.if).toMatch(/board_scraps\?\.count \?\? 0\) > 0/);
        expect(JSON.stringify(content)).toMatch(/sirsoft-board\.admin\.users\.scraps\.none/);
    });

    // @scenario surface=member_detail_tab, scrap_count=positive
    // @effects member_tab_link_carries_user_uuid
    it('목록 링크가 회원 uuid 를 실어 스크랩 관리 화면으로 보낸다', () => {
        const link = findById(content, 'view_member_scraps');

        expect(link.props['data-testid']).toBe('user-scraps-link');

        // 이동은 `navigate` 액션이 한다. `href="{{식}}"` 로 주면 렌더 시 prop 값이 undefined 가
        // 되어 DOM 에 속성 자체가 실리지 않아 링크가 조용히 죽는다(2026-09-15 브라우저 실측).
        // href 가 «없는 것» 까지 계약으로 고정한다.
        expect(link.props.href).toBeUndefined();

        const nav = JSON.stringify(link).match(/"handler":"navigate"[^}]*"path":"([^"]+)"/);
        expect(nav?.[1]).toBe('/admin/boards/scraps');

        // 개명·동명이인에 흔들리지 않도록 이름이 아니라 uuid 로 좁힌다.
        expect(JSON.stringify(link)).toMatch(/user_uuid/);
        expect(JSON.stringify(link)).toMatch(/user\?\.data\?\.uuid/);
    });
});
