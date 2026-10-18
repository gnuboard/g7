/**
 * @file admin-user-detail-notifications-tab.test.tsx
 * @description 회원 상세 「알림함」 탭 (#524 WP-F) — 요약만 보여 주고 목록은 전용 화면으로 넘긴다.
 *
 * 이 탭이 지키는 계약:
 *  - 탭 항목과 섹션이 짝을 이룬다. 탭만 늘리고 섹션을 빠뜨리면 스크롤 스파이가 갈 곳이 없어
 *    탭을 눌러도 아무 일도 일어나지 않는다(`tab-navigation-scroll` 은 `id` 로 섹션을 찾는다).
 *  - 총건·안읽음은 상세 응답이 이미 싣고 온 `notifications_summary` 를 읽는다 — 탭 전환으로
 *    다시 부르지 않는다.
 *  - 「알림함 보기」는 uuid 로 회원을 한정해 전용 목록으로 보낸다. 그쪽 화면이 배너로
 *    "이 회원만 보는 중" 임을 알린다.
 *  - 확장 주입 영역(`extension_tab_content`)보다 **앞**에 있어야 한다 — 코어 섹션과 확장 섹션이
 *    섞이면 탭 순서와 스크롤 순서가 어긋난다.
 */

import { describe, it, expect } from 'vitest';
import { asArray, actionsOf, flattenNodes, findById } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

const layout = require('../../layouts/admin_user_detail.json');

const tabNav = flattenNodes(layout).find((n: any) => n?.name === 'TabNavigationScroll' || n?.props?.tabs);
const tabs = asArray(tabNav?.props?.tabs);
const section = findById(layout, 'section_notifications_info');

describe('회원 상세 「알림함」 탭 (#524 WP-F)', () => {
    // @scenario surface=member_detail_tab
    // @effects member_detail_tab_follows_consent_tab
    it('동의이력 탭 뒤에 알림함 탭이 붙는다', () => {
        const ids = tabs.map((t: any) => t.id);

        expect(ids).toContain('notifications_info');
        expect(ids.indexOf('notifications_info')).toBe(ids.indexOf('consent_info') + 1);
    });

    // @scenario surface=member_detail_tab
    // @effects member_detail_tab_label_uses_lang_key
    it('탭 라벨이 언어 키를 쓴다 — 하드코딩 금지', () => {
        const tab = tabs.find((t: any) => t.id === 'notifications_info');

        expect(tab.label).toBe('$t:admin.users.detail.tabs.notifications_info');
    });

    // @scenario surface=member_detail_tab
    // @effects member_detail_tab_anchors_to_section
    it('탭 id 와 같은 앵커를 가진 섹션이 있다', () => {
        expect(section).toBeTruthy();
        // 스크롤 스파이는 props.id 로 섹션을 찾는다 — data-ref 만 붙이면 탭이 갈 곳이 없다.
        expect(section.props.id).toBe('notifications_info');
        expect(section.props.className).toContain('admin-card');
    });

    // @scenario surface=member_detail_tab
    // @effects member_detail_tab_reads_notifications_summary
    it('총건·안읽음은 상세 응답의 notifications_summary 를 읽는다', () => {
        const total = findById(section, 'field_notifications_total');
        const unread = findById(section, 'field_notifications_unread');

        expect(JSON.stringify(total)).toMatch(/user\?\.data\?\.notifications_summary\?\.total/);
        expect(JSON.stringify(unread)).toMatch(/user\?\.data\?\.notifications_summary\?\.unread/);
        // 값이 없을 때 빈칸이 아니라 0 으로 읽힌다.
        expect(JSON.stringify(total)).toMatch(/\?\? 0/);
        expect(JSON.stringify(unread)).toMatch(/\?\? 0/);
    });

    // @scenario surface=member_detail_tab
    // @effects member_detail_tab_makes_no_extra_request
    it('탭 안에서 목록을 다시 부르지 않는다 — 새 데이터소스도 api 호출도 없다', () => {
        const serialized = JSON.stringify(section);

        expect(serialized).not.toMatch(/apiCall/);
        expect(serialized).not.toMatch(/refetchDataSource/);
        expect(layout.data_sources.map((d: any) => d.id)).not.toContain('memberNotifications');
    });

    // @scenario surface=member_detail_tab
    // @effects member_detail_tab_links_to_member_scoped_list
    it('「알림함 보기」가 uuid 로 회원을 한정해 전용 목록으로 보낸다', () => {
        const link = findById(section, 'notifications_inbox_link');
        const button = flattenNodes(link).find((n: any) => n?.name === 'Button');
        const navigate = actionsOf(button).find((a: any) => a.handler === 'navigate');

        expect(navigate.params.path).toBe('/admin/member-notifications');
        expect(navigate.params.query.user_uuid).toMatch(/user\?\.data\?\.uuid/);
    });

    // @scenario surface=member_detail_tab
    // @effects member_detail_section_precedes_extension_slot
    it('섹션이 확장 주입 영역보다 앞에 온다', () => {
        const serialized = JSON.stringify(layout);

        expect(serialized.indexOf('section_notifications_info'))
            .toBeLessThan(serialized.indexOf('extension_tab_content'));
    });
});
