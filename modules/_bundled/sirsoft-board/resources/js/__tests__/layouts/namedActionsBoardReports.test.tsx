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

describe('검색어 상태 스코프 정합 검증 (#413-72 회귀)', () => {
    /**
     * 검색 입력(Select/Input)의 setState 쓰기 스코프가, 검색 실행 액션 및
     * value 바인딩의 읽기 스코프(_local)와 일치해야 한다.
     *
     * 결함: 입력 setState가 target:"global"로 _global 에 저장하는데,
     * searchBoardReports 액션과 value 바인딩은 _local 을 읽어 검색어가 전달되지 않음.
     */

    it('searchBoardReports가 _local.searchField / _local.searchQuery 를 읽어야 함', () => {
        const action = (boardReports as any).named_actions.searchBoardReports;
        expect(action.params.query['filters[0][field]']).toContain('_local.searchField');
        expect(action.params.query['filters[0][value]']).toContain('_local.searchQuery');
    });

    it('검색 Select/Input의 value 바인딩이 _local 을 읽어야 함', () => {
        for (const id of ['search_field_select', 'mobile_search_field_select']) {
            const cmp = findComponentById(boardReports, id);
            expect(cmp, `${id} 컴포넌트 존재`).toBeDefined();
            expect(cmp.props.value).toContain('_local.searchField');
        }
        for (const id of ['search_input', 'mobile_search_input']) {
            const cmp = findComponentById(boardReports, id);
            expect(cmp, `${id} 컴포넌트 존재`).toBeDefined();
            expect(cmp.props.value).toContain('_local.searchQuery');
        }
    });

    it('검색 Select/Input의 change setState가 _local 에 저장해야 함 (target:"global" 금지)', () => {
        const ids = [
            'search_field_select',
            'search_input',
            'mobile_search_field_select',
            'mobile_search_input',
        ];
        for (const id of ids) {
            const cmp = findComponentById(boardReports, id);
            const changeAction = (cmp.actions || []).find(
                (a: any) => a.type === 'change' && a.handler === 'setState',
            );
            expect(changeAction, `${id} 의 change setState 존재`).toBeDefined();
            expect(
                changeAction.params.target,
                `${id}: 검색어 setState 는 _local 에 저장해야 함 (target:"global" 금지)`,
            ).not.toBe('global');
        }
    });

    it('reset 버튼이 검색어를 _local 스코프로 초기화해야 함', () => {
        const reset = findComponentById(boardReports, 'reset_button');
        expect(reset).toBeDefined();
        const setStates = (reset.actions || []).filter(
            (a: any) => a.handler === 'setState',
        );
        // 검색어(searchField/searchQuery)를 초기화하는 setState 는 _local 대상이어야 함
        const searchReset = setStates.find(
            (a: any) =>
                Object.prototype.hasOwnProperty.call(a.params, 'searchField') ||
                Object.prototype.hasOwnProperty.call(a.params, 'searchQuery'),
        );
        expect(searchReset, '검색어 초기화 setState 존재').toBeDefined();
        expect(
            searchReset.params.target,
            'reset: 검색어 초기화는 _local 대상이어야 함 (target:"global" 금지)',
        ).not.toBe('global');
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
