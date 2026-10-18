/**
 * @file mypage-tab-navigation.test.tsx
 * @description 마이페이지 탭 — 꺼진 기능의 탭을 감춘다 (#524 WP-C3).
 *
 * 고치기 전에는 적립금 탭이 마일리지 기능을 꺼 둔 쇼핑몰에서도 보였다. 눌러 봐야 「사용하지 않는
 * 기능입니다」 안내만 나오는 탭이라, 회원은 자기 적립금이 사라진 것으로 읽었다. 문의 탭은 이미
 * 같은 이유로 연결 게시판 유무를 보고 있었다 — 적립금도 같은 규칙으로 맞춘다.
 *
 * 판정 근거는 «그 기능이 실제로 켜져 있는가» 다. 두 값 모두 `frontend_schema` 로 노출된 설정이라
 * 부팅 시 `_global.modules` 에 실린다 — 그러므로 화면이 따로 조회하지 않고 그 값만 읽는다.
 * `expose` 를 빠뜨리면 값이 `undefined` 가 되어 탭이 조용히 사라지므로, 백엔드 쪽
 * `EcommerceSettingsMileageTest` 가 그 노출을 함께 지킨다.
 */

import { describe, it, expect } from 'vitest';
import { asArray, actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

const nav = require('../../layouts/partials/mypage/_tab_navigation.json');

const tabs = asArray(nav.props.tabs);
const hidden: string = nav.props.hiddenTabIds;

describe('마이페이지 탭 — 기능 OFF 탭 숨김 (#524 WP-C3)', () => {
    // @scenario surface=mypage_tabs
    // @effects mypage_tabs_declare_all_nine
    it('탭 열 개가 모두 선언되어 있다', () => {
        // #524 스크랩(scrap)과 #599 클레임 신청내역(claims)이 병합에서 합류해 아홉이 열이 됐다.
        expect(tabs.map((t: any) => t.id)).toEqual([
            'profile', 'board', 'scrap', 'orders', 'claims', 'mileage', 'wishlist', 'addresses', 'notifications', 'inquiries',
        ]);
    });

    // @scenario surface=mypage_tabs, mileage=off
    // @effects mypage_tabs_hide_mileage_when_disabled
    it('마일리지가 꺼져 있으면 적립금 탭을 감춘다', () => {
        expect(hidden).toMatch(/mileage\?\.enabled \? \[\] : \['mileage'\]/);
    });

    // @scenario surface=mypage_tabs, inquiry=off
    // @effects mypage_tabs_hide_inquiries_without_board
    it('문의 게시판이 연결되지 않았으면 문의 탭을 감춘다 — 기존 규칙을 유지한다', () => {
        expect(hidden).toMatch(/inquiry\?\.board_slug \? \[\] : \['inquiries'\]/);
    });

    // @scenario surface=mypage_tabs, mileage=on
    // @effects mypage_tabs_read_settings_from_global_modules
    it('판정 근거를 부팅 설정에서 읽는다 — 탭 줄이 따로 조회하지 않는다', () => {
        expect(hidden).toMatch(/_global\.modules\?\.\['sirsoft-ecommerce'\]/);
        expect(JSON.stringify(nav)).not.toMatch(/apiCall|data_sources/);
    });

    // @scenario surface=mypage_tabs
    // @effects mypage_tabs_hidden_list_covers_only_two_ids
    it('감추는 대상은 둘뿐이다 — 나머지 탭은 조건 없이 선다', () => {
        const hiddenIds = Array.from(hidden.matchAll(/\['(\w+)'\]/g)).map((m) => m[1]);

        expect(new Set(hiddenIds)).toEqual(new Set(['mileage', 'inquiries']));
    });

    // @scenario surface=mypage_tabs
    // @effects mypage_tabs_switch_does_not_merge_query
    it('탭 전환은 이전 탭의 페이지·필터를 물려받지 않는다', () => {
        const action = actionsOf(nav).find((a: any) => a.event === 'onTabChange');

        expect(action.handler).toBe('navigate');
        expect(action.params.path).toMatch(/\/mypage\/\$\{\$args\[0\]\}/);
        expect(action.params.mergeQuery).toBeUndefined();
    });

    // @scenario surface=mypage_tabs
    // @effects mypage_tabs_active_state_comes_from_each_screen
    it('현재 탭은 각 화면이 계산해 준 값을 읽는다 — 탭이 독립 레이아웃이라 스스로 알 수 없다', () => {
        expect(nav.props.activeTabId).toMatch(/_computed\?\.currentTab/);
    });
});
