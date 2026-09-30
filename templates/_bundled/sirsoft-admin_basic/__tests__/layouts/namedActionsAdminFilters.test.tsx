/**
 * 코어 템플릿 관리 필터 named_actions 검증 테스트
 *
 * @description
 * - 사용자관리: named_actions.searchUsers 정의, 필터 조각의 actionRef 2개(Enter·검색 버튼),
 *   신규 필터 쿼리 키 전량과 확장 채널(ext_filters) 존재 (#705 — 필터 영역을 조각으로 분리하며
 *   PC·모바일 두 벌이던 검색행이 반응형 한 벌이 되었다)
 * - 스케줄관리: named_actions.searchSchedules 정의 및 2개 actionRef 검증
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';

// 사용자관리 (메인 + 필터 조각)
import userList from '../../layouts/admin_user_list.json';
import userFilterSection from '../../layouts/partials/admin_user_list/_partial_filter_section.json';

// 스케줄관리 (메인 + 파샬)
import scheduleList from '../../layouts/admin_schedule_list.json';
import scheduleTab from '../../layouts/partials/admin_schedule_list/_tab_schedules.json';

/** JSON 전체에서 actionRef를 가진 액션을 찾는 유틸리티 */
function findActionRefs(obj: any, refName: string, results: any[] = []): any[] {
    if (!obj) return results;
    if (typeof obj === 'object') {
        if (obj.actionRef === refName) {
            results.push(obj);
        }
        for (const key of Object.keys(obj)) {
            findActionRefs(obj[key], refName, results);
        }
    }
    return results;
}

// ============================================
// 사용자관리
// ============================================

describe('사용자관리 named_actions 검증', () => {
    // @scenario filter_axis=status, outcome=narrows
    // @effects search_users_query_contract
    it('레이아웃에 named_actions.searchUsers가 정의되어 있어야 함', () => {
        const namedActions = (userList as any).named_actions;
        expect(namedActions).toBeDefined();
        expect(namedActions.searchUsers).toBeDefined();
    });

    // @scenario filter_axis=status, outcome=narrows
    // @effects search_users_query_contract
    it('searchUsers가 올바른 navigate 핸들러를 가져야 함', () => {
        const action = (userList as any).named_actions.searchUsers;
        expect(action.handler).toBe('navigate');
        expect(action.params.path).toBe('/admin/users');
        expect(action.params.mergeQuery).toBe(true);
        expect(action.params.query['filters[0][operator]']).toBe('like');
    });

    // @scenario filter_axis=status, outcome=narrows
    // @effects search_users_query_contract
    it('기존 다중 검색 형식(filters[0][*])을 유지해야 함', () => {
        const query = (userList as any).named_actions.searchUsers.params.query;
        expect(query['filters[0][field]']).toContain('_local.filter.searchField');
        expect(query['filters[0][value]']).toContain('_local.filter.searchKeyword');
    });

    // @scenario filter_axis=status, outcome=narrows
    // @effects search_users_query_contract
    // 되돌림은 `page: ""`(키 제거) — list-context.md 「페이지 되돌림은 빈 값」. `1` 을 실으면 URL 에 page=1 이 남는다.
    it('검색하면 쪽수 키를 떨궈 첫 쪽으로 되돌려야 함', () => {
        const query = (userList as any).named_actions.searchUsers.params.query;
        expect(query.page).toBe('');
    });

    // @scenario filter_axis=status, outcome=narrows
    // @effects search_users_query_contract
    it('신규 필터 쿼리 키를 전부 실어야 함 (#705 §5-3)', () => {
        const query = (userList as any).named_actions.searchUsers.params.query;
        const keys = [
            'status[]', 'role_ids[]', 'admin_scope', 'lock_state', 'start_date', 'end_date',
            'last_login_start', 'last_login_end', 'never_logged_in', 'dormant_start', 'dormant_end',
            'status_changed_type', 'status_changed_start', 'status_changed_end',
            'language', 'country', 'email_verified', 'identity_verified', 'ext_filters',
        ];
        for (const key of keys) {
            expect(query, key).toHaveProperty([key]);
        }
        expect(query.ext_filters).toContain('_local.userFilterExtraQuery');
    });

    // @scenario filter_axis=status, outcome=narrows
    // @effects search_users_query_contract
    it('데이터소스가 같은 키 이름으로 URL 을 되읽어야 함 (URL·데이터소스 이름 일치)', () => {
        const params = (userList as any).data_sources.find((d: any) => d.id === 'users').params;
        const expected: Record<string, string> = {
            status: "query['status[]']", role_ids: "query['role_ids[]']", admin_scope: 'query.admin_scope',
            lock_state: 'query.lock_state', start_date: 'query.start_date', end_date: 'query.end_date',
            last_login_start: 'query.last_login_start', last_login_end: 'query.last_login_end',
            never_logged_in: 'query.never_logged_in', dormant_start: 'query.dormant_start', dormant_end: 'query.dormant_end',
            status_changed_type: 'query.status_changed_type', status_changed_start: 'query.status_changed_start',
            status_changed_end: 'query.status_changed_end', language: 'query.language', country: 'query.country',
            email_verified: 'query.email_verified', identity_verified: 'query.identity_verified',
            ext_filters: "query['ext_filters[]']",
        };
        for (const [key, source] of Object.entries(expected)) {
            expect(params[key], key).toContain(source);
        }
    });

    // @scenario filter_axis=status, outcome=narrows
    // @effects search_users_query_contract
    it('필터 조각의 Enter keypress 와 검색 버튼 click 이 searchUsers 를 참조해야 함', () => {
        const refs = findActionRefs(userFilterSection, 'searchUsers');
        expect(refs.filter((r: any) => r.type === 'keypress' && r.key === 'Enter').length).toBe(1);
        expect(refs.filter((r: any) => r.type === 'click').length).toBe(1);
    });

    // @scenario filter_axis=status, outcome=narrows
    // @effects search_users_query_contract
    it('메인 + 조각을 합쳐 총 2개의 actionRef 가 searchUsers 를 참조해야 함', () => {
        const refs = [...findActionRefs(userList, 'searchUsers'), ...findActionRefs(userFilterSection, 'searchUsers')];
        expect(refs.length).toBe(2);
    });
});

// ============================================
// 스케줄관리
// ============================================

describe('스케줄관리 named_actions 검증', () => {
    // @scenario filter_axis=status, outcome=narrows
    // @effects admin_list_named_actions_wired
    it('메인 레이아웃에 named_actions.searchSchedules가 정의되어 있어야 함', () => {
        const namedActions = (scheduleList as any).named_actions;
        expect(namedActions).toBeDefined();
        expect(namedActions.searchSchedules).toBeDefined();
    });

    // @scenario filter_axis=status, outcome=narrows
    // @effects admin_list_named_actions_wired
    it('searchSchedules가 올바른 navigate 핸들러를 가져야 함', () => {
        const action = (scheduleList as any).named_actions.searchSchedules;
        expect(action.handler).toBe('navigate');
        expect(action.params.path).toBe('/admin/schedules');
        expect(action.params.mergeQuery).toBe(true);
        expect(action.params.query.page).toBe(1);
        expect(action.params.query.search).toBeDefined();
    });

    // @scenario filter_axis=status, outcome=narrows
    // @effects admin_list_named_actions_wired
    it('파샬 탭에서 Enter keypress가 actionRef로 searchSchedules를 참조해야 함', () => {
        const refs = findActionRefs(scheduleTab, 'searchSchedules');
        const enterRef = refs.find((r: any) => r.type === 'keypress' && r.key === 'Enter');
        expect(enterRef).toBeDefined();
    });

    // @scenario filter_axis=status, outcome=narrows
    // @effects admin_list_named_actions_wired
    it('파샬 탭에서 검색 버튼 click이 actionRef로 searchSchedules를 참조해야 함', () => {
        const refs = findActionRefs(scheduleTab, 'searchSchedules');
        const clickRef = refs.find((r: any) => r.type === 'click');
        expect(clickRef).toBeDefined();
    });

    // @scenario filter_axis=status, outcome=narrows
    // @effects admin_list_named_actions_wired
    it('총 2개의 actionRef가 searchSchedules를 참조해야 함', () => {
        const refs = findActionRefs(scheduleTab, 'searchSchedules');
        expect(refs.length).toBe(2);
    });
});
