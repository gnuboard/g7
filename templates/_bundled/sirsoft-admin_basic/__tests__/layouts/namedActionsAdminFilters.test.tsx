/**
 * 코어 템플릿 관리 필터 named_actions 검증 테스트
 *
 * @description
 * - 사용자관리: named_actions.searchUsers 정의 및 4개 actionRef 검증
 * - 스케줄관리: named_actions.searchSchedules 정의 및 2개 actionRef 검증
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';

// 사용자관리 (단일 파일)
import userList from '../../layouts/admin_user_list.json';

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
    it('레이아웃에 named_actions.searchUsers가 정의되어 있어야 함', () => {
        const namedActions = (userList as any).named_actions;
        expect(namedActions).toBeDefined();
        expect(namedActions.searchUsers).toBeDefined();
    });

    it('searchUsers가 올바른 navigate 핸들러를 가져야 함', () => {
        const action = (userList as any).named_actions.searchUsers;
        expect(action.handler).toBe('navigate');
        expect(action.params.path).toBe('/admin/users');
        expect(action.params.mergeQuery).toBe(true);
        expect(action.params.query['filters[0][operator]']).toBe('like');
    });

    it('Desktop + Mobile Enter keypress가 actionRef로 searchUsers를 참조해야 함', () => {
        const refs = findActionRefs(userList, 'searchUsers');
        const enterRefs = refs.filter((r: any) => r.type === 'keypress' && r.key === 'Enter');
        expect(enterRefs.length).toBe(2); // desktop + mobile
    });

    it('Desktop + Mobile 검색 버튼 click이 actionRef로 searchUsers를 참조해야 함', () => {
        const refs = findActionRefs(userList, 'searchUsers');
        const clickRefs = refs.filter((r: any) => r.type === 'click');
        expect(clickRefs.length).toBe(2); // desktop + mobile
    });

    it('총 4개의 actionRef가 searchUsers를 참조해야 함', () => {
        const refs = findActionRefs(userList, 'searchUsers');
        expect(refs.length).toBe(4);
    });
});

// ============================================
// 스케줄관리
// ============================================

describe('스케줄관리 named_actions 검증', () => {
    it('메인 레이아웃에 named_actions.searchSchedules가 정의되어 있어야 함', () => {
        const namedActions = (scheduleList as any).named_actions;
        expect(namedActions).toBeDefined();
        expect(namedActions.searchSchedules).toBeDefined();
    });

    it('searchSchedules가 올바른 navigate 핸들러를 가져야 함', () => {
        const action = (scheduleList as any).named_actions.searchSchedules;
        expect(action.handler).toBe('navigate');
        expect(action.params.path).toBe('/admin/schedules');
        expect(action.params.mergeQuery).toBe(true);
        expect(action.params.query.page).toBe(1);
        expect(action.params.query.search).toBeDefined();
    });

    it('파샬 탭에서 Enter keypress가 actionRef로 searchSchedules를 참조해야 함', () => {
        const refs = findActionRefs(scheduleTab, 'searchSchedules');
        const enterRef = refs.find((r: any) => r.type === 'keypress' && r.key === 'Enter');
        expect(enterRef).toBeDefined();
    });

    it('파샬 탭에서 검색 버튼 click이 actionRef로 searchSchedules를 참조해야 함', () => {
        const refs = findActionRefs(scheduleTab, 'searchSchedules');
        const clickRef = refs.find((r: any) => r.type === 'click');
        expect(clickRef).toBeDefined();
    });

    it('총 2개의 actionRef가 searchSchedules를 참조해야 함', () => {
        const refs = findActionRefs(scheduleTab, 'searchSchedules');
        expect(refs.length).toBe(2);
    });
});
