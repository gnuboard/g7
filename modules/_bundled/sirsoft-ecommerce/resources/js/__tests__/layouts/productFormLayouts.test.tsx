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
import activityLogPartial from '../../../layouts/admin/partials/admin_ecommerce_product_form/_partial_activity_log.json';
import productFormLayout from '../../../layouts/admin/admin_ecommerce_product_form.json';

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
                expect(labelFormModal.props.title).toBe('$t:sirsoft-ecommerce.admin.product.labels.modal_title');
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

            it('경고 메시지가 amber 스타일로 표시되어야 한다', () => {
                const content = noticeTemplateConfirmModal.children[0];
                const warningBox = content.children[0];

                expect(warningBox.props.className).toContain('bg-amber-50');
                expect(warningBox.props.className).toContain('dark:bg-amber-900/20');
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
            expect(activityLogPartial.name).toBe('Section');
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
});
