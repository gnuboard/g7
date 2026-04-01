/**
 * 환경설정 탭별 개별 저장 패턴 검증 테스트
 *
 * @description
 * - 저장 버튼의 apiCall body가 활성 탭 데이터만 전송하는지 검증
 * - 코어 환경설정과 동일한 탭별 개별 저장 패턴 확인
 * - _tab 메타 필드 포함 여부 검증
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

// 메인 레이아웃 JSON 임포트
import mainLayout from '../../../layouts/admin/admin_ecommerce_settings.json';

/**
 * 재귀적으로 컴포넌트 트리에서 id로 검색
 */
function findById(node: any, id: string): any | null {
    if (!node) return null;
    if (node.id === id) return node;
    if (Array.isArray(node.children)) {
        for (const child of node.children) {
            const found = findById(child, id);
            if (found) return found;
        }
    }
    return null;
}

/**
 * 액션 배열에서 특정 handler를 가진 액션 찾기 (중첩 sequence 포함)
 */
function findActionByHandler(actions: any[], handler: string): any | null {
    for (const action of actions) {
        if (action.handler === handler) return action;
        if (action.actions) {
            const found = findActionByHandler(action.actions, handler);
            if (found) return found;
        }
    }
    return null;
}

describe('환경설정 탭별 개별 저장 패턴 검증', () => {
    const content = (mainLayout as any).slots.content[0];

    describe('저장 버튼 apiCall body 구조', () => {
        const saveButton = findById(content, 'save_button');

        it('저장 버튼이 존재해야 한다', () => {
            expect(saveButton).not.toBeNull();
        });

        it('저장 버튼에 click 액션이 있어야 한다', () => {
            expect(saveButton.actions).toBeDefined();
            expect(saveButton.actions.length).toBeGreaterThan(0);
            expect(saveButton.actions[0].type).toBe('click');
        });

        it('apiCall 핸들러가 존재해야 한다', () => {
            const apiCallAction = findActionByHandler(saveButton.actions, 'apiCall');
            expect(apiCallAction).not.toBeNull();
        });

        it('body가 _local.form 전체가 아닌 동적 탭 필터링 함수여야 한다', () => {
            const apiCallAction = findActionByHandler(saveButton.actions, 'apiCall');
            const body = apiCallAction.params.body;

            // 전체 form을 보내는 패턴이 아닌지 확인
            expect(body).not.toBe('{{_local.form}}');
        });

        it('body에 활성 탭 감지 로직이 포함되어야 한다', () => {
            const apiCallAction = findActionByHandler(saveButton.actions, 'apiCall');
            const body = apiCallAction.params.body;

            // activeEcommerceSettingsTab을 사용하여 현재 탭 감지
            expect(body).toContain('_global.activeEcommerceSettingsTab');
            // query.tab 폴백
            expect(body).toContain('query.tab');
            // 기본값 basic_info
            expect(body).toContain("'basic_info'");
        });

        it('body에 _tab 메타 필드가 포함되어야 한다', () => {
            const apiCallAction = findActionByHandler(saveButton.actions, 'apiCall');
            const body = apiCallAction.params.body;

            // _tab 필드로 서버에 현재 탭 정보 전달
            expect(body).toContain('_tab: tab');
        });

        it('body에 동적 키([tab])로 해당 탭 데이터만 추출하는 패턴이 있어야 한다', () => {
            const apiCallAction = findActionByHandler(saveButton.actions, 'apiCall');
            const body = apiCallAction.params.body;

            // computed property [tab]으로 해당 탭의 form 데이터만 추출
            expect(body).toContain('[tab]: form[tab]');
        });

        it('body에 form 데이터 fallback이 있어야 한다', () => {
            const apiCallAction = findActionByHandler(saveButton.actions, 'apiCall');
            const body = apiCallAction.params.body;

            // form이 없을 때 빈 객체 fallback
            expect(body).toContain('form || {}');
            // 탭 데이터가 없을 때 빈 객체 fallback
            expect(body).toContain('form[tab] || {}');
        });
    });

    describe('탭 네비게이션과 저장 연동', () => {
        const tabNav = findById(content, 'tab_navigation');

        it('탭 변경 시 _global.activeEcommerceSettingsTab이 업데이트되어야 한다', () => {
            expect(tabNav).not.toBeNull();

            const tabChangeAction = tabNav.actions.find(
                (a: any) => a.event === 'onTabChange',
            );
            expect(tabChangeAction).toBeDefined();

            // sequence 내 첫 번째 setState는 에러 초기화 (local)
            const firstSetState = findActionByHandler(
                [tabChangeAction],
                'setState',
            );
            expect(firstSetState).not.toBeNull();
            expect(firstSetState.params.target).toBe('local');

            // sequence 내 activeEcommerceSettingsTab을 설정하는 setState (global)
            const globalSetState = tabChangeAction.actions.find(
                (a: any) => a.handler === 'setState' && a.params?.activeEcommerceSettingsTab,
            );
            expect(globalSetState).not.toBeNull();
            expect(globalSetState.params.target).toBe('global');
            expect(globalSetState.params.activeEcommerceSettingsTab).toContain(
                '$args[0]',
            );
        });

        it('5개 탭이 정의되어야 한다 (basic_info, language_currency, seo, order_settings, shipping)', () => {
            const tabs = tabNav.props.tabs;
            expect(tabs).toHaveLength(5);

            const tabIds = tabs.map((t: any) => t.id);
            expect(tabIds).toEqual([
                'basic_info',
                'language_currency',
                'seo',
                'order_settings',
                'shipping',
            ]);
        });

        it('activeTabId가 저장 body와 동일한 탭 감지 표현식을 사용해야 한다', () => {
            const activeTabExpr = tabNav.props.activeTabId;
            // 저장 body의 탭 감지와 동일한 source 사용
            expect(activeTabExpr).toContain(
                '_global.activeEcommerceSettingsTab',
            );
            expect(activeTabExpr).toContain('query.tab');
            expect(activeTabExpr).toContain("'basic_info'");
        });
    });
});
