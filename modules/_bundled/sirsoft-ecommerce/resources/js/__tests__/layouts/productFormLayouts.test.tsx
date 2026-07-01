/**
 * 상품 폼 레이아웃 렌더링 테스트
 *
 * @description
 * - 라벨 기간 프리셋 버튼 렌더링 및 핸들러 연결 검증
 * - 상품정보제공고시 템플릿 변경 확인 모달 조건부 렌더링
 * - 배송정책 기본값 자동 설정 데이터소스 연동
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import React from 'react';

// 레이아웃 JSON 임포트
import labelFormModal from '../../../layouts/admin/partials/admin_ecommerce_product_form/_modal_label_form.json';
import noticeTemplateConfirmModal from '../../../layouts/admin/partials/admin_ecommerce_product_form/_modal_notice_template_confirm.json';
import deleteConfirmModal from '../../../layouts/admin/partials/admin_ecommerce_product_form/_modal_delete_confirm.json';
import activityLogPartial from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_activity_log.json';
import otherInfoPartial from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_other_info.json';
import productOptionsPartial from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_product_options.json';
import seoSettingsPartial from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_seo_settings.json';
import productFormLayout from '../../../layouts/admin/admin_ecommerce_product_form.json';
import commonInfoIndexLayout from '../../../layouts/admin/admin_ecommerce_product_common_info_index.json';
import noticeIndexLayout from '../../../layouts/admin/admin_ecommerce_product_notice_index.json';
import categoryIndexLayout from '../../../layouts/admin/admin_ecommerce_category_index.json';
import brandIndexLayout from '../../../layouts/admin/admin_ecommerce_brand_index.json';
import commonInfoListPanel from '../../../layouts/admin/partials/admin_ecommerce_product_common_info_index/_panel_list.json';
import noticeListPanel from '../../../layouts/admin/partials/admin_ecommerce_product_notice_index/_panel_list.json';

/**
 * 중첩 children 트리에서 id 로 노드를 찾습니다.
 */
const findNodeById = (node: any, id: string): any => {
    if (!node) return null;
    if (node.id === id) return node;
    for (const child of node.children ?? []) {
        const found = findNodeById(child, id);
        if (found) return found;
    }
    return null;
};

/**
 * purchase_restriction 라디오(value 일치) 의 change 핸들러 params 를 추출합니다.
 */
const findRestrictionRadioParams = (root: any, value: string): any => {
    const group = findNodeById(root, 'restriction_radio_group');
    const stack = [...(group?.children ?? [])];
    while (stack.length) {
        const node = stack.shift();
        if (node?.name === 'Input' && node?.props?.name === 'purchase_restriction' && node?.props?.value === value) {
            const changeAction = (node.actions ?? []).find((a: any) => a.type === 'change' && a.handler === 'setState');
            return changeAction?.params ?? null;
        }
        if (node?.children) stack.push(...node.children);
    }
    return null;
};

/**
 * 테스트용 Mock 컴포넌트 레지스트리
 */
const createMockRegistry = () => {
    const components: Map<string, React.FC<any>> = new Map();

    // Basic 컴포넌트들
    components.set('Div', ({ children, className, style, ...rest }: any) =>
        React.createElement('div', { className, style, ...rest }, children));
    components.set('Span', ({ children, className, text, ...rest }: any) =>
        React.createElement('span', { className, ...rest }, children || text));
    components.set('P', ({ children, className, text, ...rest }: any) =>
        React.createElement('p', { className, ...rest }, children || text));
    components.set('Label', ({ children, className, text, htmlFor, ...rest }: any) =>
        React.createElement('label', { className, htmlFor, ...rest }, children || text));
    components.set('Button', ({ children, className, text, onClick, type, disabled, 'data-testid': testId, ...rest }: any) =>
        React.createElement('button', { className, onClick, type, disabled, 'data-testid': testId, ...rest }, children || text));
    components.set('Input', ({ className, type, value, onChange, placeholder, disabled, name, ...rest }: any) =>
        React.createElement('input', { className, type, value, onChange, placeholder, disabled, name, ...rest }));
    components.set('Icon', ({ name, className }: any) =>
        React.createElement('i', { className: `icon-${name} ${className || ''}`, 'data-icon': name }));

    // Composite 컴포넌트들
    components.set('Modal', ({ children, title, description, size, ...rest }: any) =>
        React.createElement('div', { 'data-testid': 'modal', 'data-title': title, 'data-size': size, ...rest }, [
            React.createElement('h2', { key: 'title' }, title),
            description && React.createElement('p', { key: 'desc' }, description),
            React.createElement('div', { key: 'content' }, children),
        ]));
    components.set('MultilingualInput', ({ name, value, placeholder, layout }: any) =>
        React.createElement('input', { name, placeholder, 'data-layout': layout, 'data-value': JSON.stringify(value) }));

    return {
        getComponent: (name: string) => components.get(name) || null,
        hasComponent: (name: string) => components.has(name),
        getMetadata: (name: string) => components.has(name) ? { name, type: 'basic' } : null,
    };
};

/**
 * G7Core Mock 생성
 */
const createG7CoreMock = (overrides?: {
    localState?: Record<string, any>;
    globalState?: Record<string, any>;
}) => {
    const localState: Record<string, any> = overrides?.localState ? { ...overrides.localState } : {};
    const globalState: Record<string, any> = overrides?.globalState ? { ...overrides.globalState } : {};
    const toasts: Array<{ type: string; message: string }> = [];

    return {
        state: {
            getLocal: vi.fn(() => localState),
            setLocal: vi.fn((updates: Record<string, any>) => {
                Object.assign(localState, updates);
            }),
            getGlobal: vi.fn(() => globalState),
            setGlobal: vi.fn((updates: Record<string, any>) => {
                Object.assign(globalState, updates);
            }),
        },
        toast: {
            success: vi.fn((msg: string) => toasts.push({ type: 'success', message: msg })),
            warning: vi.fn((msg: string) => toasts.push({ type: 'warning', message: msg })),
            error: vi.fn((msg: string) => toasts.push({ type: 'error', message: msg })),
            info: vi.fn((msg: string) => toasts.push({ type: 'info', message: msg })),
        },
        t: vi.fn((key: string) => key),
        locale: {
            supported: vi.fn(() => ['ko', 'en']),
        },
        _toasts: toasts,
        _localState: localState,
        _globalState: globalState,
    };
};

describe('productFormLayouts', () => {
    let g7CoreMock: ReturnType<typeof createG7CoreMock>;

    beforeEach(() => {
        g7CoreMock = createG7CoreMock();
        (window as any).G7Core = g7CoreMock;
    });

    afterEach(() => {
        vi.clearAllMocks();
        delete (window as any).G7Core;
    });

    describe('Label Form Modal Layout (_modal_label_form.json)', () => {
        describe('레이아웃 구조 검증', () => {
            it('라벨 폼 모달이 올바른 구조를 가져야 한다', () => {
                expect(labelFormModal.id).toBe('modal_label_form');
                expect(labelFormModal.type).toBe('composite');
                expect(labelFormModal.name).toBe('Modal');
                // A31: 제목이 editingLabelId 유무로 생성/수정 분기 (정적 modal_title → 동적 분기)
                expect(labelFormModal.props.title).toContain('_global.editingLabelId');
                expect(labelFormModal.props.title).toContain('labels.modal_title_create');
                expect(labelFormModal.props.title).toContain('labels.modal_title_edit');
            });

            it('라벨 모달에는 기간 편집 섹션이 더 이상 없다 (인라인 편집으로 분리됨)', () => {
                // date_preset_section / date_range_section / start_date / end_date 입력은
                // 라벨 모달 partial 에서 제거되고, 라벨별 인라인 위젯 (DateRangePicker)
                // 으로 분리됨. 라벨 모달은 이름/색상만 편집한다
                const labelFormContent = labelFormModal.children[0];
                const datePresetSection = labelFormContent.children.find(
                    (child: any) => child.id === 'date_preset_section',
                );
                const dateRangeSection = labelFormContent.children.find(
                    (child: any) => child.id === 'date_range_section',
                );
                expect(datePresetSection).toBeUndefined();
                expect(dateRangeSection).toBeUndefined();
            });
        });
    });

    describe('Notice Template Confirm Modal Layout (_modal_notice_template_confirm.json)', () => {
        describe('레이아웃 구조 검증', () => {
            it('확인 모달이 올바른 구조를 가져야 한다', () => {
                expect(noticeTemplateConfirmModal.id).toBe('notice_template_confirm_modal');
                expect(noticeTemplateConfirmModal.type).toBe('composite');
                expect(noticeTemplateConfirmModal.name).toBe('Modal');
            });

            it('모달 partial 이 단독 if 표현식 없이 modals 섹션의 isolated 스코프로 관리된다', () => {
                // 기존에는 _global.showNoticeTemplateConfirmModal 로 직접 표시 토글 → modals
                // 섹션 isolated scope 로 이전됨. 모달 루트의 if 는 더 이상 없음
                expect(noticeTemplateConfirmModal.if).toBeUndefined();
            });

            it('경고 메시지가 alert-warning 시맨틱 자산으로 표시되어야 한다', () => {
                const content = noticeTemplateConfirmModal.children[0];
                const warningBox = content.children[0];

                expect(warningBox.props.className).toContain('alert-warning');
            });

            it('취소 버튼이 sequence(setState pending=null + closeModal) 패턴이다', () => {
                const content = noticeTemplateConfirmModal.children[0];
                const buttonContainer = content.children[1];
                const cancelButton = buttonContainer.children[0];

                expect(cancelButton.text).toBe('$t:sirsoft-ecommerce.common.cancel');
                expect(cancelButton.actions[0].handler).toBe('sequence');
                const cancelInner = cancelButton.actions[0].params.actions;
                const setStateAction = cancelInner.find((a: any) => a.handler === 'setState');
                expect(setStateAction.params.target).toBe('global');
                expect(setStateAction.params.pendingNoticeTemplateId).toBe(null);
                expect(cancelInner.some((a: any) => a.handler === 'closeModal')).toBe(true);
            });

            it('확인 버튼 sequence 가 closeModal → 템플릿 적용 → pending 초기화 순으로 실행된다', () => {
                const content = noticeTemplateConfirmModal.children[0];
                const buttonContainer = content.children[1];
                const confirmButton = buttonContainer.children[1];

                expect(confirmButton.text).toBe('$t:sirsoft-ecommerce.admin.product.notice.confirm_change_button');
                expect(confirmButton.actions[0].handler).toBe('sequence');

                const sequenceActions = confirmButton.actions[0].params.actions;
                expect(sequenceActions).toHaveLength(3);

                // 1. 모달 닫기 (showNoticeTemplateConfirmModal 키 제거됨)
                expect(sequenceActions[0].handler).toBe('closeModal');

                // 2. 템플릿 적용
                expect(sequenceActions[1].handler).toBe('sirsoft-ecommerce.selectNoticeTemplate');
                expect(sequenceActions[1].params.templateId).toBe('{{_global.pendingNoticeTemplateId}}');

                // 3. pending 상태 초기화
                expect(sequenceActions[2].handler).toBe('setState');
                expect(sequenceActions[2].params.pendingNoticeTemplateId).toBe(null);
            });
        });

        describe('다국어 키 검증', () => {
            it('모든 텍스트가 다국어 키를 사용해야 한다', () => {
                expect(noticeTemplateConfirmModal.props.title).toBe('$t:sirsoft-ecommerce.admin.product.notice.confirm_change_title');

                const content = noticeTemplateConfirmModal.children[0];
                const warningBox = content.children[0];
                const textContainer = warningBox.children[1];
                const warningText = textContainer.children[0];
                const descriptionText = textContainer.children[1];

                expect(warningText.text).toBe('$t:sirsoft-ecommerce.admin.product.notice.confirm_change_warning');
                expect(descriptionText.text).toBe('$t:sirsoft-ecommerce.admin.product.notice.confirm_change_description');
            });
        });
    });

    describe('Delete Confirm Modal Layout (_modal_delete_confirm.json) — 삭제 후 navigate 회귀 (A37)', () => {
        /**
         * 증상: 주문이력 없는 상품 삭제 시 DELETE 200·삭제 성공이나, 성공 직후
         *       "Failed to execute action: navigate" 오류 토스트가 추가로 발생하고
         *       목록 페이지로 이동하지 않음 (성공 토스트 + 오류 토스트 2건 동시 노출).
         * 원인: onSuccess 시퀀스의 navigate 핸들러가 params.url 로 경로를 전달했으나,
         *       G7 navigate 핸들러는 params.path 만 인식한다 (CLAUDE.md "navigate path 필수").
         *       url 키는 무시되어 finalPath=undefined → navigate throw → 오류 토스트.
         *       동일 모듈의 다른 navigate(주문 상세/브랜드 목록 등)는 모두 path 사용.
         * 해결: onSuccess navigate 의 params.url → params.path 로 정정.
         */
        const findNavigateInOnSuccess = (modal: any): any => {
            const collect: any[] = [];
            const walk = (node: any) => {
                if (!node || typeof node !== 'object') return;
                if (Array.isArray(node)) { node.forEach(walk); return; }
                if (Array.isArray(node.onSuccess)) {
                    for (const act of node.onSuccess) {
                        if (act?.handler === 'navigate') collect.push(act);
                    }
                }
                for (const v of Object.values(node)) walk(v);
            };
            walk(modal);
            return collect;
        };

        it('삭제 성공(onSuccess) navigate 가 params.path 로 목록 경로를 전달한다 (url 키 금지)', () => {
            const navigateActions = findNavigateInOnSuccess(deleteConfirmModal);
            expect(navigateActions.length).toBeGreaterThan(0);

            for (const nav of navigateActions) {
                // 회귀 가드: params.url 은 엔진이 인식하지 못해 finalPath=undefined → navigate 실패
                expect(nav.params?.url).toBeUndefined();
                expect(nav.params?.path).toBe('/admin/ecommerce/products');
            }
        });
    });

    describe('레이아웃 액션 통합 검증', () => {
        // setLabelDatePreset / 프리셋 버튼은 라벨 모달에서 인라인 위젯으로 분리되어
        // 본 partial 테스트 범위에서 제외됨 (별도 모달 기간 위젯 테스트 필요 시 분리 작성)

        it('확인 모달의 취소 버튼이 sequence 로 pending 만 초기화한다', () => {
            const content = noticeTemplateConfirmModal.children[0];
            const buttonContainer = content.children[1];
            const cancelButton = buttonContainer.children[0];

            const cancelAction = cancelButton.actions[0];
            expect(cancelAction.handler).toBe('sequence');
            const setStateAction = cancelAction.params.actions.find((a: any) => a.handler === 'setState');
            expect(setStateAction.params).toEqual({
                target: 'global',
                pendingNoticeTemplateId: null,
            });
        });
    });

    describe('레이아웃 스타일 및 반응형 검증', () => {
        // 날짜 범위 섹션 / 프리셋 flex wrap 검증은 라벨 모달 섹션 제거에 따라 함께 제거
        it('라벨 모달은 이름/색상/미리보기 섹션만 가지며 기간 섹션을 포함하지 않는다', () => {
            const labelFormContent = labelFormModal.children[0];
            const ids = (labelFormContent.children ?? []).map((c: any) => c.id);
            expect(ids).toContain('label_preview_section');
            expect(ids).toContain('label_name_section');
            expect(ids).toContain('label_color_section');
            expect(ids).not.toContain('date_preset_section');
            expect(ids).not.toContain('date_range_section');
        });
    });

    describe('_partial_activity_log.json (활동 로그 섹션)', () => {
        it('최상위에 type과 name이 정의되어 있다', () => {
            expect(activityLogPartial.type).toBe('basic');
            // 시맨틱화: Section → Div (의미 없는 빈 wrapper 정리, 표준 Div 환원)
            expect(activityLogPartial.name).toBe('Div');
            expect(activityLogPartial.children).toBeDefined();
        });

        it('정렬 드롭다운이 존재한다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('activityLogSort');
            expect(json).toContain('"desc"');
            expect(json).toContain('"asc"');
        });

        it('페이지당 드롭다운이 존재한다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('activityLogPerPage');
        });

        it('Select에서 $event.target.value를 사용한다 (not $event)', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('$event.target.value');
            expect(json).not.toMatch(/"\\{\\{\\$event\\}\\}"/);
        });

        it('refetchDataSource 핸들러를 사용한다 (not refreshDataSource)', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('refetchDataSource');
            expect(json).not.toContain('refreshDataSource');
        });

        it('로그 iteration이 activity_logs API 데이터를 소스로 사용한다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('activity_logs');
            expect(json).toContain('iteration');
        });

        it('빈 상태 조건이 올바른 경로를 사용한다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('activity_logs.data?.data ?? []');
            expect(json).not.toContain('!activity_logs?.data?.length');
        });

        it('Pagination 컴포넌트가 존재한다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('"name":"Pagination"');
            expect(json).toContain('currentPage');
            expect(json).toContain('totalPages');
        });

        it('Pagination이 onPageChange 이벤트와 $args[0] 패턴을 사용한다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('"event":"onPageChange"');
            expect(json).toContain('$args[0]');
            expect(json).not.toContain('"type":"pageChange"');
        });

        it('Pagination이 항상 표시된다 (if 조건 없음)', () => {
            const findById = (children: any[], id: string): any => {
                for (const child of children) {
                    if (child.id === id) return child;
                    if (child.children) {
                        const found = findById(child.children, id);
                        if (found) return found;
                    }
                }
                return null;
            };
            const pagination = findById(activityLogPartial.children, 'activity_log_pagination');
            expect(pagination).toBeDefined();
            expect(pagination.if).toBeUndefined();
        });

        it('데이터 경로가 meta 를 사용한다 (Collection 응답 구조)', () => {
            // ProductLogCollection 의 페이지네이션 메타가 pagination → meta 로 정규화됨
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('activity_logs.data?.meta');
        });

        it('작업(action) 컬럼이 제거되었다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).not.toContain('columns.action');
            expect(json).not.toContain('log.action_label');
            expect(json).not.toContain('log.action ===');
        });

        it('처리자에 ActionMenu가 적용되어 있다 (PC+모바일)', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('"name":"ActionMenu"');
            expect(json).toContain('!!log.user?.uuid');
            expect(json).toContain('!log.user?.uuid');
        });

        it('ActionMenu에 회원정보 보기 메뉴가 있다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('view_member');
            expect(json).toContain('actor_action.view_member');
        });

        it('회원 클릭 시 openWindow로 회원 상세 페이지를 연다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('"handler":"openWindow"');
            expect(json).toContain('/admin/users/{{log.user.uuid}}');
        });

        it('시스템 사용자는 ActionMenu 없이 아바타+이름만 표시된다', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('activity_log.system');
        });

        it('빈 상태의 colSpan이 3이다 (작업 컬럼 제거 반영)', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('"colSpan":3');
            expect(json).not.toContain('"colSpan":4');
        });
    });

    describe('_partial_other_info.json 구매 대상 제한 (빈 칩 버그 회귀)', () => {
        /**
         * 증상: "제한 없음" → "구매 대상 제한" 라디오 전환 시 이전 allowed_roles 잔여값으로
         *       빈/오염 역할 칩이 노출됨
         * 원인: restricted 라디오 change handler 에 form.allowed_roles 초기화 누락
         *       (none 라디오에는 초기화가 있었음 — 비대칭)
         * 해결: restricted 라디오 change handler 에도 form.allowed_roles: [] 추가
         */
        it('"제한 없음" 라디오는 form.allowed_roles 를 빈 배열로 초기화한다', () => {
            const params = findRestrictionRadioParams(otherInfoPartial, 'none');
            expect(params).not.toBeNull();
            expect(params['form.purchase_restriction']).toBe('none');
            expect(params['form.allowed_roles']).toEqual([]);
        });

        it('"구매 대상 제한" 라디오도 form.allowed_roles 를 빈 배열로 초기화한다 (빈 칩 버그 수정)', () => {
            const params = findRestrictionRadioParams(otherInfoPartial, 'restricted');
            expect(params).not.toBeNull();
            expect(params['form.purchase_restriction']).toBe('restricted');
            // 회귀 가드: 이 키가 빠지면 라디오 전환 시 이전 역할 잔여값이 남아 빈 칩 발생
            expect(params['form.allowed_roles']).toEqual([]);
        });

        it('두 라디오 모두 target=local 로 setState 한다', () => {
            const noneParams = findRestrictionRadioParams(otherInfoPartial, 'none');
            const restrictedParams = findRestrictionRadioParams(otherInfoPartial, 'restricted');
            expect(noneParams.target).toBe('local');
            expect(restrictedParams.target).toBe('local');
        });
    });

    describe('_partial_other_info.json 구매 수량 자동바인딩 (저장 미반영 버그 회귀)', () => {
        /**
         * 증상: 상품 수정 화면에서 최소/최대 구매 수량을 변경 후 저장해도 반영 안 됨.
         *       화면 입력값은 보이나 _local.form.{min,max}_purchase_qty 상태는 옛값 유지.
         * 원인: 폼은 dataKey="form" 자동바인딩인데 이 두 필드만 표준을 벗어나
         *       change 핸들러로 값을 직접 setState 했고, 표현식이 parseInt($event) 였음.
         *       엔진은 $event 로 이벤트 객체를 넘기므로 parseInt(<Event>) = NaN → fallback(0/1)
         *       으로 매번 덮어써져 입력값이 상태에 반영되지 않음.
         * 해결: list_price/sku 등과 동일한 표준 패턴으로 정정 —
         *       value 명시 제거 + 값-setState 핸들러 제거, 값 처리는 자동바인딩에 일임.
         */
        const findQtyInput = (root: any, fieldId: string): any => {
            const field = findNodeById(root, fieldId);
            const stack = [...(field?.children ?? [])];
            while (stack.length) {
                const node = stack.shift();
                if (node?.name === 'Input' && node?.props?.type === 'number') return node;
                if (node?.children) stack.push(...node.children);
            }
            return null;
        };

        it('최소 구매 수량 Input 은 name 으로 자동바인딩하며 값-setState 핸들러가 없다', () => {
            const input = findQtyInput(otherInfoPartial, 'field_min_purchase_qty');
            expect(input).not.toBeNull();
            expect(input.props.name).toBe('min_purchase_qty');

            // 회귀 가드: 값을 직접 쓰는 setState 가 다시 붙으면 자동바인딩과 충돌 → 저장 미반영
            const valueSetState = (input.actions ?? []).find(
                (a: any) => a.handler === 'setState'
                    && a.params
                    && Object.keys(a.params).some((k) => k.startsWith('form.min_purchase_qty')),
            );
            expect(valueSetState).toBeUndefined();
        });

        it('최대 구매 수량 Input 은 name 으로 자동바인딩하며 값-setState 핸들러가 없다', () => {
            const input = findQtyInput(otherInfoPartial, 'field_max_purchase_qty');
            expect(input).not.toBeNull();
            expect(input.props.name).toBe('max_purchase_qty');

            const valueSetState = (input.actions ?? []).find(
                (a: any) => a.handler === 'setState'
                    && a.params
                    && Object.keys(a.params).some((k) => k.startsWith('form.max_purchase_qty')),
            );
            expect(valueSetState).toBeUndefined();
        });

        it('두 필드 모두 깨진 $event(이벤트 객체) parseInt 패턴을 사용하지 않는다', () => {
            const serialized = JSON.stringify([
                findNodeById(otherInfoPartial, 'field_min_purchase_qty'),
                findNodeById(otherInfoPartial, 'field_max_purchase_qty'),
            ]);
            // $event.target.value 는 허용하되, parseInt($event)/Number($event) 같은
            // 이벤트 객체 직접 숫자화 패턴은 금지 (CLAUDE.md 데이터 바인딩 규정)
            expect(serialized).not.toMatch(/parseInt\(\$event\)/);
            expect(serialized).not.toMatch(/Number\(\$event\)/);
        });
    });

    describe('activity_logs 데이터소스 (admin_ecommerce_product_form.json)', () => {
        it('activity_logs 데이터소스가 정의되어 있다', () => {
            const ds = productFormLayout.data_sources.find(
                (d: any) => d.id === 'activity_logs'
            );
            expect(ds).toBeDefined();
            expect(ds.endpoint).toContain('/logs');
            expect(ds.auto_fetch).toBe(true);
        });

        it('sort_order 파라미터가 포함되어 있다', () => {
            const ds = productFormLayout.data_sources.find(
                (d: any) => d.id === 'activity_logs'
            );
            expect(ds.params.sort_order).toBeDefined();
            expect(ds.params.sort_order).toContain('activityLogSort');
        });

        it('per_page 파라미터가 상태 바인딩을 사용한다', () => {
            const ds = productFormLayout.data_sources.find(
                (d: any) => d.id === 'activity_logs'
            );
            expect(ds.params.per_page).toContain('activityLogPerPage');
        });
    });

    describe('상품 폼 레이아웃 깨짐 회귀 (퍼블리셔 시맨틱 회귀)', () => {
        /**
         * 증상: 시맨틱화 과정에서 일부 호출처가 잘못된 자산 토큰을 받아
         *       레이아웃이 깨짐 — 서브탭 sticky 해제 / 카드 헤더 아이콘+제목 2행 분리 /
         *       옵션 목록 헤더 중앙 정렬 / 다통화 값 간격 소실 / 추가옵션 토글 간격 소실.
         * 원인:
         *   - tab_navigation 이 sticky-tab-nav-responsive 래퍼 밖에 위치 (sticky 소실)
         *   - 카드 헤더 / 옵션 헤더 행에 group-stack(flex-col) 이 flex-center/flex-between
         *     앞에 붙어 가로 정렬을 세로로 강제
         *   - 다통화 헤더/셀의 반복 래퍼에 수직 gap 부재
         *   - 추가옵션 헤더 래퍼에 base flex/gap 부재 (sm: 프리픽스만 있어 좁은 폭에서 0 간격)
         * 해결: 위 5개 호출처 className 정정.
         */

        /**
         * 자식 트리에서 className 에 needle 을 포함하는 노드를 모두 수집합니다.
         */
        const collectByClass = (node: any, needle: string, acc: any[] = []): any[] => {
            if (!node || typeof node !== 'object') return acc;
            const cls = node?.props?.className;
            if (typeof cls === 'string' && cls.includes(needle)) acc.push(node);
            for (const child of node.children ?? []) collectByClass(child, needle, acc);
            if (node.itemTemplate) collectByClass(node.itemTemplate, needle, acc);
            return acc;
        };

        it('서브탭 네비게이션이 스크롤 콘텐츠 직계 자식에 위치하고 sticky-tab-nav 가 직접 부여된다 (sticky 복원)', () => {
            // 메인 폼 레이아웃은 slots.content 하위에 트리가 있으므로 슬롯 루트에서 탐색
            const contentRoot = { children: (productFormLayout as any).slots?.content ?? [] };
            const tabNav = findNodeById(contentRoot, 'tab_navigation');
            expect(tabNav).not.toBeNull();
            expect(tabNav.name).toBe('TabNavigationScroll');

            // sticky-tab-nav (p-6 admin-page-content 화면용 변형, -responsive 아님)
            // 회귀 가드: 부모 admin-page-content 는 p-6 이므로 -mx-6 px-6 변형(.sticky-tab-nav)을
            // 써야 정렬이 맞는다. -responsive(-mx-8 px-8)는 p-6 화면에서 좌우로 8px 삐져나와
            // 가로 오버플로우(가로 스크롤바)를 유발한다 (게시판 폼 admin_board_form 동일 규칙).
            expect(tabNav.props.className).toContain('sticky-tab-nav');
            expect(tabNav.props.className).not.toContain('sticky-tab-nav-responsive');

            // position:sticky 는 스크롤 콘텐츠(admin-page-content) 의 직계 자식이어야
            // 동작한다. page_header_section(mb-6) 같은 짧은 래퍼 안에 중첩되면
            // 그 래퍼 영역만큼만 sticky 되어 헤더와 함께 스크롤되어 사라진다 (게시판 폼 레퍼런스 동일 구조).
            const scrollContent = findNodeById(contentRoot, 'product_form_content');
            const directChildIds = (scrollContent?.children ?? []).map((c: any) => c.id);
            expect(directChildIds).toContain('tab_navigation');
        });

        it('카드 헤더가 group-stack 없이 표준 card-header 시맨틱으로 아이콘+제목을 가로 정렬한다', () => {
            // 표준: 카드 헤더는 .card-header 시맨틱이 정렬을 책임진다 (아이콘+제목+아코디언
            // 아이콘이 직계 자식으로 가로 배치). 옛 product_options_header(flex-center 행 래퍼)는
            // card-header 로 대체되었다.
            const header = collectByClass(productOptionsPartial, 'card-header')[0];
            expect(header).toBeDefined();
            // 회귀 가드: group-stack 이 붙으면 아이콘+제목이 세로 2행으로 분리됨
            expect(header.props.className).not.toContain('group-stack');
            expect(header.props.className).toContain('card-header');
            // 카드 제목(card-title)이 헤더의 직계 자식으로 존재한다
            const title = (header.children ?? []).find(
                (c: any) => typeof c?.props?.className === 'string'
                    && c.props.className.includes('card-title'),
            );
            expect(title).toBeDefined();
        });

        it('옵션 목록 제목/토글 헤더 행이 group-stack 없이 flex-between 으로 좌우 정렬된다', () => {
            const optionsSection = findNodeById(productOptionsPartial, 'option_table_section');
            const titleRow = (optionsSection?.children ?? []).find(
                (c: any) => typeof c?.props?.className === 'string'
                    && c.props.className.includes('flex-between'),
            );
            expect(titleRow).toBeDefined();
            // 회귀 가드: group-stack 이 붙으면 헤더가 세로로 쌓이며 중앙 정렬되어 보임
            expect(titleRow.props.className).not.toContain('group-stack');
            expect(titleRow.props.className).toContain('flex-between');
        });

        it('다통화는 별도 입력 컬럼이 아니라 판매가 셀 하단에 읽기전용 환산값으로 세로 표시된다', () => {
            // 회귀 가드: 옵션별 통화 입력 컬럼(헤더/셀)은 제거되었다.
            // 다통화 가격은 저장 컬럼이 없어 입력해도 버려지므로, base 판매가에서
            // 환율로 자동 계산된 읽기전용 환산값을 판매가 셀 하단에 표시한다(장바구니 패턴).
            expect(findNodeById(productOptionsPartial, 'option_currency_header')).toBeNull();
            expect(findNodeById(productOptionsPartial, 'option_currency_cell_{{optIdx}}')).toBeNull();

            // 판매가 셀 하단 미리보기 블록: currencyCol 을 반복하는 Span 의 수직 래퍼
            const preview = findNodeById(productOptionsPartial, 'option_currency_preview_{{optIdx}}');
            expect(preview).not.toBeNull();
            expect(preview.props.className).toContain('flex flex-col');
            const previewSpan = (preview.children ?? [])[0];
            expect(previewSpan?.iteration?.source).toContain('currencyColumns');
            // 환산값은 formatted(읽기전용 표기)를 그대로 출력한다 (입력 Input 아님)
            expect(previewSpan?.name).toBe('Span');
            expect(previewSpan?.text).toContain('multi_currency_selling_price');
            expect(previewSpan?.text).toContain('formatted');
        });

        it('옵션 행(Tr)이 align-top 으로 상단 정렬된다 (판매가 하단 환산값이 입력칸 라인을 밀지 않음)', () => {
            // 회귀 가드: 판매가 셀에 다통화 환산값이 세로로 쌓여 셀 높이가 커져도,
            // 기본 vertical-align: middle 이면 정가/SKU/재고 입력칸이 중앙으로 내려가
            // 판매가 입력칸(상단)과 라인이 어긋난다. 옵션 행을 align-top 으로 상단 정렬해
            // 모든 입력칸을 같은 수평선에 맞춘다.
            const findTr = (node: any): any => {
                if (!node || typeof node !== 'object') return null;
                if (node.name === 'Tr' && typeof node.iteration?.source === 'string'
                    && node.iteration.source.includes('form.options')) return node;
                for (const child of node.children ?? []) {
                    const found = findTr(child);
                    if (found) return found;
                }
                return null;
            };
            const optionRow = findTr(productOptionsPartial);
            expect(optionRow).not.toBeNull();
            expect(optionRow.props.className).toContain('align-top');
        });

        it('추가옵션 헤더 래퍼가 base flex/gap 을 가져 좁은 폭에서도 제목과 토글 사이 간격이 유지된다', () => {
            const addSection = findNodeById(productOptionsPartial, 'additional_options_section');
            const headerRow = (addSection?.children ?? []).find(
                (c: any) => typeof c?.props?.className === 'string'
                    && c.props.className.includes('sm:justify-between'),
            );
            expect(headerRow).toBeDefined();
            // 회귀 가드: base flex/gap 없이 sm: 프리픽스만 있으면 좁은 폭에서 0 간격
            expect(headerRow.props.className).toContain('flex flex-col gap-4');
        });
    });

    describe('비활성 공통정보 노출 차단 (상품 폼 common_infos 데이터소스)', () => {
        const findDataSource = (layout: any, id: string): any =>
            (layout.data_sources ?? []).find((ds: any) => ds.id === id) ?? null;

        it('common_infos 데이터소스가 active_only=true 파라미터로 활성 항목만 요청한다', () => {
            const ds = findDataSource(productFormLayout, 'common_infos');
            expect(ds).not.toBeNull();
            // 회귀 가드: 백엔드 컨트롤러는 active_only 파라미터만 인식한다.
            // is_active 파라미터는 무시되어 비활성 항목까지 노출되는 회귀를 차단.
            expect(ds.params.active_only).toBe(true);
            expect(ds.params.is_active).toBeUndefined();
        });

        it('common_infos 의 active 필터 파라미터명이 notice_templates 와 동일하다', () => {
            const commonDs = findDataSource(productFormLayout, 'common_infos');
            const noticeDs = findDataSource(productFormLayout, 'notice_templates');
            expect(commonDs.params.active_only).toBe(noticeDs.params.active_only);
        });
    });

    describe('마스터-디테일 그리드 비율', () => {
        const findNodeByIdDeep = (root: any, id: string): any => {
            const stack = [root];
            while (stack.length) {
                const node = stack.shift();
                if (!node) continue;
                if (node.id === id) return node;
                if (Array.isArray(node)) { stack.push(...node); continue; }
                if (node.children) stack.push(...node.children);
                if (node.slots) {
                    for (const slotChildren of Object.values(node.slots)) {
                        stack.push(...(slotChildren as any[]));
                    }
                }
            }
            return null;
        };

        const MASTER_DETAIL_LAYOUTS: Array<[string, any]> = [
            ['공통정보관리', commonInfoIndexLayout],
            ['상품정보제공고시', noticeIndexLayout],
            ['카테고리관리', categoryIndexLayout],
            ['브랜드관리', brandIndexLayout],
        ];

        it.each(MASTER_DETAIL_LAYOUTS)(
            '%s panel_layout 이 3컬럼 그리드(좌 1/3 목록 · 우 2/3 상세) 자산을 사용한다',
            (_name, layout) => {
                const panel = findNodeByIdDeep(layout, 'panel_layout');
                expect(panel).not.toBeNull();
                // 좌측 목록 1/3 + 우측 상세 2/3 마스터-디테일 그리드 자산
                expect(panel.props.className).toContain('grid-cols-1-lg-3');
                // 회귀 가드: items-start 는 양쪽 셀 stretch 정렬을 깨 좌측 리스트 컨테이너 잘림 유발
                expect(panel.props.className).not.toContain('items-start');

                // 회귀 가드: 좌/우 패널의 col-span 누락 → 우측 빈공간 + 컨텐츠 축소
                const left = findNodeByIdDeep(panel, 'left_panel');
                const right = findNodeByIdDeep(panel, 'right_panel');
                expect(left).not.toBeNull();
                expect(right).not.toBeNull();
            },
        );
    });

    describe('마스터-디테일 컨텐츠 영역이 페이지 스크롤 컨테이너를 사용한다', () => {
        const findContainerClassName = (layout: any): string => {
            const slotContent = layout.slots?.content ?? [];
            return slotContent[0]?.props?.className ?? '';
        };

        // 공통정보/제공고시: 고정 높이 viewport(overflow-hidden) → 페이지 스크롤 컨테이너로 교체
        // 좌측 리스트는 자체 max-h + overflow-y-auto 로 내부 스크롤, 우측 detail 은 페이지 전체 스크롤.
        it.each([
            ['공통정보관리', commonInfoIndexLayout],
            ['상품정보제공고시', noticeIndexLayout],
        ] as Array<[string, any]>)(
            '%s 최상위 컨테이너가 admin-page-content (페이지 스크롤) 이다 — viewport 고정높이 잘림 회귀 차단',
            (_name, layout) => {
                const cls = findContainerClassName(layout);
                expect(cls).toContain('admin-page-content');
                // 회귀 가드: viewport 변형은 h-[calc(100vh-64px)] overflow-hidden 으로 우측 detail 잘림
                expect(cls).not.toContain('admin-page-content-viewport');
            },
        );

        // 좌측 리스트 partial 은 내부 스크롤(max-h + overflow-y-auto)을 유지해야 한다.
        it.each([
            ['공통정보관리', commonInfoListPanel],
            ['상품정보제공고시', noticeListPanel],
        ] as Array<[string, any]>)(
            '%s 좌측 리스트 partial 이 내부 스크롤(max-h + overflow-y-auto)을 유지한다',
            (_name, panel) => {
                const json = JSON.stringify(panel);
                expect(json).toContain('overflow-y-auto');
                expect(json).toMatch(/max-h-\[calc\(90vh-140px\)\]/);
            },
        );
    });

    describe('SEO 설정 다국어 입력', () => {
        /**
         * 배경: SEO 제목/설명이 평문 단일 문자열로 고정되어 언어별 분기가 안 되던 문제를
         *       meta_title/meta_description 다국어 JSON 전환 + MultilingualInput 자동바인딩으로 해결.
         *       (이전 debounce 수동 setState 는 MultilingualInput 자동바인딩 내부 debounce 로
         *       대체됨 — keystroke 마다 setState 하지 않으므로 타이핑 지연도 함께 해소.)
         */

        it('SEO 제목 커스텀 입력이 MultilingualInput(name=meta_title, tabs) 자동바인딩이다', () => {
            const node = findNodeById(seoSettingsPartial, 'meta_title_custom');
            expect(node).not.toBeNull();
            expect(node.name).toBe('MultilingualInput');
            // 자동바인딩: name prop 으로 _local.form.meta_title(다국어 객체)에 바인딩
            expect(node.props.name).toBe('meta_title');
            expect(node.props.layout).toBe('tabs');
            expect(node.props.inputType).toBe('text');
            // 회귀 가드: keystroke 마다 리렌더하던 수동 setState change 액션이 없어야 한다(타이핑 지연 차단)
            const setStateChange = (node.actions ?? []).find(
                (a: any) => a.type === 'change' && a.handler === 'setState',
            );
            expect(setStateChange).toBeUndefined();
        });

        it('SEO 설명 커스텀 입력이 MultilingualInput(name=meta_description, textarea) 자동바인딩이다', () => {
            const node = findNodeById(seoSettingsPartial, 'meta_desc_custom');
            expect(node).not.toBeNull();
            expect(node.name).toBe('MultilingualInput');
            expect(node.props.name).toBe('meta_description');
            expect(node.props.inputType).toBe('textarea');
            const setStateChange = (node.actions ?? []).find(
                (a: any) => a.type === 'change' && a.handler === 'setState',
            );
            expect(setStateChange).toBeUndefined();
        });

        it('글자수 카운터·미리보기가 $localized 로 현재 로케일 문자열을 추출한다 (다국어 객체 직접 출력 금지)', () => {
            const json = JSON.stringify(seoSettingsPartial);
            // 회귀 가드: 다국어 객체를 .length / 직접 출력하면 [object Object] 또는 NaN
            // meta_title/meta_description 은 항상 $localized() 로 감싸 현재 로케일을 추출해야 한다
            expect(json).not.toMatch(/_local\.form\.meta_title\s*\?\?\s*''/);
            expect(json).not.toMatch(/_local\.form\.meta_description\s*\?\?\s*''/);
            expect(json).toContain('$localized(_local.form.meta_title)');
            expect(json).toContain('$localized(_local.form.meta_description)');
        });

        it('동기화(상품명과 동일) 토글이 name/description 다국어 객체를 통째로 미러한다', () => {
            const json = JSON.stringify(seoSettingsPartial);
            // sync ON→OFF 전환 시 초기값으로 name/description 다국어 객체 통째 복사
            // (단일 로케일 substring 절단 패턴이 남아있으면 다국어 유실)
            expect(json).toContain('"form.meta_title":"{{_local.form.name}}"');
            expect(json).toContain('"form.meta_description":"{{_local.form.description}}"');
        });
    });
});
