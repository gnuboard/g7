// e2e:allow 가드 판별력 보강 전용(7차 검증, 부록 B D10) — `hits.length >= 2` 느슨한 하한을 탭 버튼 id ↔ 본문 노드 id 상호 대조로 바꿨 것뿐이고, 대상 조각·주입 동작은 불변이다. 브라우저 흐름은 admin/board-user-reactions-tab.spec.ts 가 담당한다.
/**
 * 회원 상세 「반응」 요약 탭 (#525 WP-C) — 확장 주입 계약 회귀.
 *
 * 이 조각이 지키는 계약:
 *  - 주입이 **두 군데**로 갈린다(탭 버튼 / 본문). 둘을 잇는 것은 `id` 하나뿐이라 한쪽만
 *    고치면 탭은 있고 내용은 빈 화면이 된다.
 *  - 관리자 응답에만 실리는 `board_reactions` 로 노출을 가른다 — 프로필(/api/me)에서는
 *    이 조각 전체가 걸러진다.
 *  - 목록으로 가는 이동은 `navigate` 액션으로 한다. `href="{{식}}"` 은 렌더 시 값이
 *    undefined 가 되어 속성 자체가 실리지 않아 링크가 조용히 죽는다(2026-09-15 실측).
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import ext from '../../../extensions/admin-user-reactions-tab.json';

const extJson = JSON.stringify(ext);

describe('회원 상세 반응 탭 — 주입 계약', () => {
    // @scenario case=member_detail_tab_links_with_uuid
    // @effects admin_user_tab_navigates_with_user_uuid_not_href
    it('회원 상세 화면에 스크랩 탭 뒤 차례로 끼어든다', () => {
        expect((ext as any).target_layout ?? (ext as any).targetLayout).toBe('admin_user_detail');
        // 스크랩 탭이 310 이므로 그 뒤에 선다.
        expect(Number((ext as any).priority)).toBe(320);
    });

    // @scenario case=member_detail_tab_links_with_uuid
    // @effects admin_user_tab_navigates_with_user_uuid_not_href
    it('탭 버튼과 본문이 같은 id 로 이어진다', () => {
        // 이 id 가 어긋나면 탭은 뜨는데 눌러도 빈 화면이다.
        // 등장 횟수만 세면(>= 2) 한쪽 id 를 고쳐 둘이 어긋나도 초록이 유지된다 —
        // 두 주입 지점의 id 를 각각 집어 서로 같은지 본다.
        const injections = (ext as any).injections as any[];

        const tabInjection = injections.find((inj) => inj.position === 'inject_props');
        expect(tabInjection, '탭 버튼 주입(inject_props)이 없다').toBeDefined();
        const tabButton = tabInjection.props.tabs._append[0];

        const contentInjection = injections.find((inj) => inj.position === 'append_child');
        expect(contentInjection, '본문 주입(append_child)이 없다').toBeDefined();
        const tabBody = contentInjection.components[0];

        // 탭 버튼의 id 와 본문 노드가 내보내는 DOM id(props.id)가 같은 값이어야 한다.
        expect(tabButton.id).toBe('ext_board_reactions');
        expect(tabBody.props.id).toBe('ext_board_reactions');
        expect(tabBody.props.id, '탭 버튼과 본문의 id 가 어긋났다').toBe(tabButton.id);
    });

    // @scenario case=member_detail_tab_links_with_uuid
    // @effects admin_user_tab_navigates_with_user_uuid_not_href
    it('관리자 응답에 board_reactions 가 있을 때만 노출된다', () => {
        // 프로필 응답에는 이 값이 없으므로 조각 전체가 걸러진다.
        expect(/!!user\?\.data\?\.board_reactions/.test(extJson)).toBe(true);
    });
});

describe('회원 상세 반응 탭 — 요약 카드와 링크', () => {
    // @scenario case=member_detail_tab_links_with_uuid
    // @effects admin_user_tab_navigates_with_user_uuid_not_href
    it('총 반응·글댓글 구분·최근 반응 세 칸을 낸다', () => {
        expect(/board_reactions\?\.count/.test(extJson)).toBe(true);
        expect(/board_reactions\?\.post_count/.test(extJson)).toBe(true);
        expect(/board_reactions\?\.comment_count/.test(extJson)).toBe(true);
        expect(/board_reactions\?\.latest_reacted_at/.test(extJson)).toBe(true);
    });

    // @scenario case=member_detail_tab_links_with_uuid
    // @effects admin_user_tab_navigates_with_user_uuid_not_href
    it('0건이면 링크 대신 안내 문구를 낸다', () => {
        // 눌러 봐야 빈 목록이고, 그 사실은 위 숫자가 이미 말한다.
        expect(/\(user\?\.data\?\.board_reactions\?\.count \?\? 0\) === 0/.test(extJson)).toBe(true);
        expect(/users\.reactions\.none/.test(extJson)).toBe(true);
        expect(/\(user\?\.data\?\.board_reactions\?\.count \?\? 0\) > 0/.test(extJson)).toBe(true);
    });

    // @scenario case=member_detail_tab_links_with_uuid
    // @effects admin_user_tab_navigates_with_user_uuid_not_href
    it('목록 이동은 navigate 로 하고 회원 uuid 를 실어 보낸다', () => {
        expect(/\/admin\/boards\/reactions/.test(extJson)).toBe(true);
        expect(/"user_uuid"/.test(extJson)).toBe(true);
        // href 에 식을 넣으면 속성 자체가 실리지 않아 링크가 죽는다.
        expect(/"href"/.test(extJson)).toBe(false);
    });

    // @scenario case=member_detail_tab_links_with_uuid
    // @effects admin_user_tab_navigates_with_user_uuid_not_href
    it('문구는 전부 언어 키로 낸다', () => {
        for (const key of ['tab_label', 'title', 'count', 'breakdown', 'latest_reacted_at', 'view_list']) {
            expect(extJson, key).toContain(`admin.users.reactions.${key}`);
        }
    });
});
