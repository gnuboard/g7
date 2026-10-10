/**
 * 환경설정 탭별 개별 저장 패턴 검증 테스트
 * e2e:allow 테스트 설명 문구만 변경(공개 릴리즈 사전점검 — 내부 호칭 정리).
 * 런타임 코드/레이아웃 무변경이라 브라우저 동작 차이가 없다.
 *
 * @description
 * - 저장 버튼의 apiCall body가 활성 탭 데이터만 전송하는지 검증
 * - 코어 환경설정과 동일한 탭별 개별 저장 패턴 확인
 * - _tab 메타 필드 포함 여부 검증
 * - 탭 11종 (basic_info, language_currency, seo, order_settings, claim, shipping,
 *   review_settings, notification_definitions, identity_policies, mileage, privacy_retention) 정의 검증
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';

// 메인 레이아웃 JSON 임포트
import mainLayout from '../../../layouts/admin/admin_ecommerce_settings.json';
import { asArray, callbacksOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

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
 * - callbacksOf(action, 'actions') (sequence/parallel handler 의 nested actions)
 */
function findActionByHandler(actions: any[], handler: string): any | null {
    for (const action of actions) {
        if (action.handler === handler) return action;
        if (asArray(action?.actions).length > 0) {
            const found = findActionByHandler(action.actions, handler);
            if (found) return found;
        }
        if (callbacksOf(action, 'actions').length > 0) {
            const found = findActionByHandler(callbacksOf(action, 'actions'), handler);
            if (found) return found;
        }
    }
    return null;
}

describe('환경설정 탭별 개별 저장 패턴 검증', () => {
    const content = (mainLayout as any).slots.content[0];

    describe('저장 버튼 apiCall body 구조', () => {
        const saveButton = findById(content, 'save_button');

        /**
         * @scenario order_state=confirmed, toggle=on, mode=anonymize
         *
         * @effects settings_save_sends_only_active_tab
         */
        it('저장 버튼이 존재해야 한다', () => {
            expect(saveButton).not.toBeNull();
        });

        /**
         * @scenario order_state=confirmed, toggle=on, mode=anonymize
         *
         * @effects settings_save_sends_only_active_tab
         */
        it('저장 버튼에 click 액션이 있어야 한다', () => {
            expect(saveButton.actions).toBeDefined();
            expect(asArray(saveButton?.actions).length).toBeGreaterThan(0);
            expect(asArray(saveButton?.actions)[0].type).toBe('click');
        });

        /**
         * @scenario order_state=confirmed, toggle=on, mode=anonymize
         *
         * @effects settings_save_sends_only_active_tab
         */
        it('apiCall 핸들러가 존재해야 한다 (sequence 내부 포함)', () => {
            const apiCallAction = findActionByHandler(saveButton.actions, 'apiCall');
            expect(apiCallAction).not.toBeNull();
        });

        /**
         * @scenario order_state=confirmed, toggle=on, mode=anonymize
         *
         * @effects settings_save_sends_only_active_tab
         */
        it('body 가 _local.form 전체가 아닌 동적 탭 필터링 표현식이어야 한다', () => {
            const apiCallAction = findActionByHandler(saveButton.actions, 'apiCall');
            const body = apiCallAction.params.body;

            expect(body).not.toBe('{{_local.form}}');
            // IIFE 표현식으로 탭별 페이로드 동적 구성
            expect(body).toContain('function()');
        });

        /**
         * @scenario order_state=confirmed, toggle=on, mode=anonymize
         *
         * @effects settings_save_sends_only_active_tab
         */
        it('body 에 활성 탭 감지 로직이 포함되어야 한다', () => {
            const apiCallAction = findActionByHandler(saveButton.actions, 'apiCall');
            const body = apiCallAction.params.body;

            expect(body).toContain('_global.activeEcommerceSettingsTab');
            expect(body).toContain('query.tab');
            expect(body).toContain("'basic_info'");
        });

        /**
         * @scenario order_state=confirmed, toggle=on, mode=anonymize
         *
         * @effects settings_save_failure_shows_server_message
         */
        it('저장 실패 토스트가 서버 오류 메시지를 노출해야 한다 (회귀 — 운영 실측 제보)', () => {
            // 고정 문구만 쓰면 검증 실패 사유(예: 필드별 422 메시지)가 사용자에게
            // 도달하지 못하고 "설정 저장에 실패했습니다" 로 뭉개진다.
            const apiCallAction = findActionByHandler(saveButton.actions, 'apiCall');
            const errorToast = (apiCallAction.onError ?? []).find(
                (a: any) => a.handler === 'toast',
            );

            expect(errorToast).toBeDefined();
            expect(errorToast.params.message).toContain('error.message');
            // 서버 메시지 부재 시 기존 고정 문구로 폴백
            expect(errorToast.params.message).toContain('save_error');
        });

        /**
         * @scenario order_state=confirmed, toggle=on, mode=anonymize
         *
         * @effects settings_save_sends_only_active_tab
         */
        it('body 에 _tab 메타 필드가 포함되어야 한다', () => {
            const apiCallAction = findActionByHandler(saveButton.actions, 'apiCall');
            const body = apiCallAction.params.body;

            // 일반 탭은 _tab: tab, notification_definitions 분기는 _tab: 'notifications'
            expect(body).toContain('_tab: tab');
            expect(body).toContain("_tab: 'notifications'");
        });

        /**
         * @scenario order_state=confirmed, toggle=on, mode=anonymize
         *
         * @effects settings_save_sends_only_active_tab
         */
        it('body 에 동적 키([tab]) 로 해당 탭 데이터만 추출하는 패턴이 있어야 한다', () => {
            const apiCallAction = findActionByHandler(saveButton.actions, 'apiCall');
            const body = apiCallAction.params.body;

            expect(body).toContain('[tab]: form[tab]');
        });

        /**
         * @scenario order_state=confirmed, toggle=on, mode=anonymize
         *
         * @effects settings_save_sends_only_active_tab
         */
        it('body 에 nullish fallback (?? {}) 이 있어야 한다', () => {
            const apiCallAction = findActionByHandler(saveButton.actions, 'apiCall');
            const body = apiCallAction.params.body;

            // form 미정의 시 빈 객체, 탭 미정의 시 빈 객체
            expect(body).toContain('form = _local.form ?? {}');
            expect(body).toContain('form[tab] ?? {}');
        });

        /**
         * @scenario order_state=confirmed, toggle=on, mode=anonymize
         *
         * @effects settings_save_sends_only_active_tab
         */
        it('notification_definitions 탭은 notifications 페이로드로 변환되어야 한다', () => {
            const apiCallAction = findActionByHandler(saveButton.actions, 'apiCall');
            const body = apiCallAction.params.body;

            // 알림 탭 분기에서 notifications.channels 만 전송
            expect(body).toContain("tab === 'notification_definitions'");
            expect(body).toContain('notifications:');
            expect(body).toContain('form.notifications?.channels');
        });

        /**
         * @scenario order_state=confirmed, toggle=on, mode=anonymize
         *
         * @effects settings_save_sends_only_active_tab
         */
        it('mileage 탭은 _tab:mileage + mileage 데이터만 전송한다 (inquiry 미포함)', () => {
            const apiCallAction = findActionByHandler(saveButton.actions, 'apiCall');
            const body = apiCallAction.params.body;

            // 마일리지 탭 분기: 해당 카테고리만 전송 (다른 탭 데이터 오염 방지)
            expect(body).toContain("tab === 'mileage'");
            expect(body).toContain("_tab: 'mileage'");
            expect(body).toContain('mileage: form.mileage');
        });
    });

    describe('탭 네비게이션과 저장 연동', () => {
        const tabNav = findById(content, 'tab_navigation');

        /**
         * @scenario order_state=confirmed, toggle=on, mode=anonymize
         *
         * @effects settings_tabs_include_privacy_retention
         */
        it('탭 변경 시 _global.activeEcommerceSettingsTab 이 업데이트되어야 한다', () => {
            expect(tabNav).not.toBeNull();

            const tabChangeAction = asArray(tabNav?.actions).find(
                (a: any) => a.event === 'onTabChange',
            );
            expect(tabChangeAction).toBeDefined();

            // 고친 것이 있으면 확인 창을 먼저 띄운다(7.1.0 조치 G2 — admin-ecommerce-settings-unsaved-tab-confirm.test.ts).
            // 고친 것이 없을 때 바로 옮기는 갈래(conditions 의 else)가 아래 순서다
            expect(tabChangeAction.handler).toBe('conditions');
            const directSwitch = tabChangeAction.conditions[1].then;

            // sequence 내 첫 번째 setState 는 에러 초기화 (local)
            const sequenceActions = callbacksOf(directSwitch, 'actions');
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

        /**
         * @scenario order_state=confirmed, toggle=on, mode=anonymize
         *
         * @effects settings_tabs_include_privacy_retention
         */
        it('11개 탭이 정의되어야 한다 (마일리지·개인정보 보유기간 탭 포함)', () => {
            const tabs = tabNav.props.tabs;
            expect(tabs).toHaveLength(11);

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
                'mileage',
                // 이슈 #636 — 저장은 기본 분기(`{ _tab: tab, [tab]: form[tab] }`)를 그대로 탄다
                'privacy_retention',
            ]);
        });

        /**
         * @scenario order_state=confirmed, toggle=on, mode=anonymize
         *
         * @effects settings_tabs_include_privacy_retention
         */
        it('activeTabId 가 저장 body 와 동일한 탭 감지 표현식을 사용해야 한다', () => {
            const activeTabExpr = tabNav.props.activeTabId;
            expect(activeTabExpr).toContain('_global.activeEcommerceSettingsTab');
            expect(activeTabExpr).toContain('query.tab');
            expect(activeTabExpr).toContain("'basic_info'");
        });
    });
});

describe('환경설정 저장 본문 — 실제 엔진 평가 (#703)', () => {
    // 문자열 포함 검사만으로는 식이 평가되는지 알 수 없다. 평가기가 못 읽는 문법(구조분해 매개변수)이 들어가자
    // 본문 전체가 식 원문 문자열로 전송돼 모든 탭 저장이 아무것도 저장하지 못했다 — 실제 엔진으로 평가한다.
    const engine = new DataBindingEngine();
    const content = (mainLayout as any).slots.content[0];
    const body = String(findActionByHandler(findById(content, 'save_button').actions, 'apiCall').params.body);
    const expr = body.trim().replace(/^\{\{/, '').replace(/\}\}$/, '');

    const form = {
        basic_info: { shop_name: 'shop' },
        order_settings: { payment_methods: [{ id: 'card', is_active: true }] },
        mileage: { enabled: true },
        notifications: { channels: [{ id: 'mail' }] },
        language_currency: {
            default_currency: 'KRW',
            currencies: [
                { code: 'KRW', exchange_rate: null, is_default: true },
                { code: 'USD', exchange_rate: 0.85, decimal_places: 2 },
                { code: 'EUR', exchange_rate: 0.78, decimal_places: 2 },
            ],
        },
        inquiry: {},
    };
    const ctx = (tab: string) => ({
        _global: { activeEcommerceSettingsTab: tab },
        _local: { form },
        _computed: { managedRateCodes: ['USD'] },
        query: {},
        settings: { data: { language_currency: { currencies: [{ code: 'KRW' }, { code: 'USD' }, { code: 'EUR' }] } } },
    });

    /**
     * @scenario order_state=confirmed, toggle=on, mode=anonymize
     *
     * @effects settings_save_sends_only_active_tab
     */
    it('탭마다 본문이 객체로 평가되고 그 탭 데이터만 싣는다', () => {
        for (const tab of ['basic_info', 'order_settings', 'mileage', 'notification_definitions', 'language_currency']) {
            const result = engine.evaluateExpression(expr, ctx(tab));
            expect(typeof result, `${tab}: 본문이 객체가 아닙니다(평가 실패 시 식 원문 문자열).`).toBe('object');
            expect(result._tab).toBe(tab === 'notification_definitions' ? 'notifications' : tab);
        }
    });

    /**
     * @scenario order_state=confirmed, toggle=on, mode=anonymize
     *
     * @effects settings_save_sends_only_active_tab
     */
    it('통화 탭은 관리 환율 통화의 환율만 빼고 나머지 값은 그대로 보낸다', () => {
        const result = engine.evaluateExpression(expr, ctx('language_currency'));
        const byCode = Object.fromEntries(result.language_currency.currencies.map((c: any) => [c.code, c]));
        expect('exchange_rate' in byCode.USD).toBe(false);
        expect(byCode.USD.decimal_places).toBe(2);
        expect(byCode.EUR.exchange_rate).toBe(0.78);
        expect(byCode.KRW.is_default).toBe(true);
    });
});
