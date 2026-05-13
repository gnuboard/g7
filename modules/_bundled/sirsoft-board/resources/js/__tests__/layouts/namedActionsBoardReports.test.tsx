/**
 * 게시판 신고현황 named_actions 검증 테스트
 *
 * @description
 * - named_actions.searchBoardReports 정의 검증
 * - Desktop/Mobile Enter keypress + 검색 버튼 click이 actionRef 참조하는지 검증
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';

import boardReports from '../../../layouts/admin/admin_board_reports_index.json';

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

/** JSON 전체에서 특정 id를 가진 컴포넌트를 찾는 유틸리티 */
function findComponentById(obj: any, id: string): any | null {
    if (!obj) return null;
    if (typeof obj === 'object') {
        if (obj.id === id) return obj;
        for (const key of Object.keys(obj)) {
            const found = findComponentById(obj[key], id);
            if (found) return found;
        }
    }
    return null;
}

describe('게시판 신고현황 named_actions 검증', () => {
    it('레이아웃에 named_actions.searchBoardReports가 정의되어 있어야 함', () => {
        const namedActions = (boardReports as any).named_actions;
        expect(namedActions).toBeDefined();
        expect(namedActions.searchBoardReports).toBeDefined();
    });

    it('searchBoardReports가 올바른 navigate 핸들러를 가져야 함', () => {
        const action = (boardReports as any).named_actions.searchBoardReports;
        expect(action.handler).toBe('navigate');
        expect(action.params.path).toBe('/admin/boards/reports');
        expect(action.params.query['filters[0][operator]']).toBe('like');
        expect(action.params.query.status).toBeDefined();
        expect(action.params.query.type).toBeDefined();
    });

    it('Desktop + Mobile Enter keypress가 actionRef로 searchBoardReports를 참조해야 함', () => {
        const refs = findActionRefs(boardReports, 'searchBoardReports');
        const enterRefs = refs.filter((r: any) => r.type === 'keypress' && r.key === 'Enter');
        expect(enterRefs.length).toBe(2); // desktop + mobile
    });

    it('검색 버튼 click이 actionRef로 searchBoardReports를 참조해야 함', () => {
        const refs = findActionRefs(boardReports, 'searchBoardReports');
        const clickRef = refs.find((r: any) => r.type === 'click');
        expect(clickRef).toBeDefined();
    });

    it('총 3개의 actionRef가 searchBoardReports를 참조해야 함', () => {
        const refs = findActionRefs(boardReports, 'searchBoardReports');
        expect(refs.length).toBe(3);
    });
});

describe('모바일 Dropdown 일괄 처리 검증', () => {
    it('mobile_bulk_action_dropdown의 items가 value 필드를 가져야 함 (key 금지)', () => {
        const dropdown = findComponentById(boardReports, 'mobile_bulk_action_dropdown');
        expect(dropdown).toBeDefined();

        const items = dropdown.props.items as Array<{ value?: string; key?: string; label: string }>;
        expect(items).toBeDefined();
        expect(items.length).toBeGreaterThan(0);

        for (const item of items) {
            expect(item.key, `items에 "key" 필드 사용 금지 (value: ${item.key})`).toBeUndefined();
            expect(item.value, `items의 "value" 필드가 정의되어야 함`).toBeDefined();
        }
    });

    it('mobile_bulk_action_dropdown의 switch cases가 items의 value와 일치해야 함', () => {
        const dropdown = findComponentById(boardReports, 'mobile_bulk_action_dropdown');
        const items = dropdown.props.items as Array<{ value: string }>;
        const switchAction = dropdown.actions?.find((a: any) => a.handler === 'switch');

        expect(switchAction).toBeDefined();
        expect(switchAction.cases).toBeDefined();

        for (const item of items) {
            expect(
                switchAction.cases[item.value],
                `switch cases에 "${item.value}" 케이스가 존재해야 함`,
            ).toBeDefined();
        }
    });

    it('switch cases의 각 케이스가 sequence 객체여야 함 (배열 직접 사용 금지)', () => {
        const dropdown = findComponentById(boardReports, 'mobile_bulk_action_dropdown');
        const switchAction = dropdown.actions?.find((a: any) => a.handler === 'switch');

        for (const [key, caseValue] of Object.entries(switchAction.cases)) {
            expect(
                Array.isArray(caseValue),
                `"${key}" 케이스가 배열이면 안 됨 — sequence로 감싸야 함`,
            ).toBe(false);
            expect(
                (caseValue as any).handler,
                `"${key}" 케이스의 handler가 "sequence"여야 함`,
            ).toBe('sequence');
            expect(
                Array.isArray((caseValue as any).actions),
                `"${key}" 케이스의 actions가 배열이어야 함`,
            ).toBe(true);
        }
    });
});
