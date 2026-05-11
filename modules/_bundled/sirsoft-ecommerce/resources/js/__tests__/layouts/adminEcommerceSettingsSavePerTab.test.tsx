/**
 * 환경설정 탭별 개별 저장 패턴 검증 테스트
 *
 * @description
 * - 저장 버튼의 apiCall body가 활성 탭 데이터만 전송하는지 검증
 * - 코어 환경설정과 동일한 탭별 개별 저장 패턴 확인
 * - _tab 메타 필드 포함 여부 검증
 * - 탭 8종 (basic_info, language_currency, seo, order_settings, claim, shipping,
 *   review_settings, notification_definitions) 정의 검증
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
 *
 * 탐색 대상:
 * - action.handler 직접 일치
 * - action.actions (legacy nested actions)
 * - action.params.actions (sequence/parallel handler 의 nested actions)
 */
function findActionByHandler(actions: any[], handler: string): any | null {
    for (const action of actions) {
        if (action.handler === handler) return action;
        if (Array.isArray(action.actions)) {
            const found = findActionByHandler(action.actions, handler);
            if (found) return found;
        }
        if (Array.isArray(action.params?.actions)) {
            const found = findActionByHandler(action.params.actions, handler);
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

        it('apiCall 핸들러가 존재해야 한다 (sequence 내부 포함)', () => {
            const apiCallAction = findActionByHandler(saveButton.actions, 'apiCall');
            expect(apiCallAction).not.toBeNull();
        });

        it('body 가 _local.form 전체가 아닌 동적 탭 필터링 표현식이어야 한다', () => {
            const apiCallAction = findActionByHandler(saveButton.actions, 'apiCall');
            const body = apiCallAction.params.body;

            expect(body).not.toBe('{{_local.form}}');
            // IIFE 표현식으로 탭별 페이로드 동적 구성
            expect(body).toContain('function()');
        });

        it('body 에 활성 탭 감지 로직이 포함되어야 한다', () => {
            const apiCallAction = findActionByHandler(saveButton.actions, 'apiCall');
            const body = apiCallAction.params.body;

            expect(body).toContain('_global.activeEcommerceSettingsTab');
            expect(body).toContain('query.tab');
            expect(body).toContain("'basic_info'");
        });

        it('body 에 _tab 메타 필드가 포함되어야 한다', () => {
            const apiCallAction = findActionByHandler(saveButton.actions, 'apiCall');
            const body = apiCallAction.params.body;

            // 일반 탭은 _tab: tab, notification_definitions 분기는 _tab: 'notifications'
            expect(body).toContain('_tab: tab');
            expect(body).toContain("_tab: 'notifications'");
        });

        it('body 에 동적 키([tab]) 로 해당 탭 데이터만 추출하는 패턴이 있어야 한다', () => {
            const apiCallAction = findActionByHandler(saveButton.actions, 'apiCall');
            const body = apiCallAction.params.body;

            expect(body).toContain('[tab]: form[tab]');
        });

        it('body 에 nullish fallback (?? {}) 이 있어야 한다', () => {
            const apiCallAction = findActionByHandler(saveButton.actions, 'apiCall');
            const body = apiCallAction.params.body;

            // form 미정의 시 빈 객체, 탭 미정의 시 빈 객체
            expect(body).toContain('form = _local.form ?? {}');
            expect(body).toContain('form[tab] ?? {}');
        });

        it('notification_definitions 탭은 notifications 페이로드로 변환되어야 한다', () => {
            const apiCallAction = findActionByHandler(saveButton.actions, 'apiCall');
            const body = apiCallAction.params.body;

            // 알림 탭 분기에서 notifications.channels 만 전송
            expect(body).toContain("tab === 'notification_definitions'");
            expect(body).toContain('notifications:');
            expect(body).toContain('form.notifications?.channels');
        });
    });

    describe('탭 네비게이션과 저장 연동', () => {
        const tabNav = findById(content, 'tab_navigation');

        it('탭 변경 시 _global.activeEcommerceSettingsTab 이 업데이트되어야 한다', () => {
            expect(tabNav).not.toBeNull();

            const tabChangeAction = tabNav.actions.find(
                (a: any) => a.event === 'onTabChange',
            );
            expect(tabChangeAction).toBeDefined();

            // sequence 내 첫 번째 setState 는 에러 초기화 (local)
            const sequenceActions = tabChangeAction.params?.actions ?? [];
            const firstSetState = sequenceActions.find(
                (a: any) => a.handler === 'setState' && a.params?.target === 'local',
            );
            expect(firstSetState).toBeDefined();

            // sequence 내 activeEcommerceSettingsTab 을 설정하는 setState (global)
            const globalSetState = sequenceActions.find(
                (a: any) => a.handler === 'setState' && a.params?.activeEcommerceSettingsTab,
            );
            expect(globalSetState).toBeDefined();
            expect(globalSetState.params.target).toBe('global');
            expect(globalSetState.params.activeEcommerceSettingsTab).toContain('$args[0]');
        });

        it('9개 탭이 정의되어야 한다', () => {
            const tabs = tabNav.props.tabs;
            expect(tabs).toHaveLength(9);

            const tabIds = tabs.map((t: any) => t.id);
            expect(tabIds).toEqual([
                'basic_info',
                'language_currency',
                'seo',
                'order_settings',
                'claim',
                'shipping',
                'review_settings',
                'notification_definitions',
                'identity_policies',
            ]);
        });

        it('activeTabId 가 저장 body 와 동일한 탭 감지 표현식을 사용해야 한다', () => {
            const activeTabExpr = tabNav.props.activeTabId;
            expect(activeTabExpr).toContain('_global.activeEcommerceSettingsTab');
            expect(activeTabExpr).toContain('query.tab');
            expect(activeTabExpr).toContain("'basic_info'");
        });
    });
});
