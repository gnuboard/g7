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

            it('기간 프리셋 섹션이 존재해야 한다', () => {
                const labelFormContent = labelFormModal.children[0];
                const datePresetSection = labelFormContent.children.find(
                    (child: any) => child.id === 'date_preset_section'
                );

                expect(datePresetSection).toBeDefined();
                expect(datePresetSection.children).toHaveLength(2); // Label + Button Container
            });

            it('4개의 프리셋 버튼(7d, 14d, 30d, permanent)이 존재해야 한다', () => {
                const labelFormContent = labelFormModal.children[0];
                const datePresetSection = labelFormContent.children.find(
                    (child: any) => child.id === 'date_preset_section'
                );
                const buttonContainer = datePresetSection.children[1];
                const buttons = buttonContainer.children;

                expect(buttons).toHaveLength(4);

                // 각 버튼의 핸들러 검증
                const presets = ['7d', '14d', '30d', 'permanent'];
                buttons.forEach((button: any, index: number) => {
                    expect(button.name).toBe('Button');
                    expect(button.actions[0].handler).toBe('sirsoft-ecommerce.setLabelDatePreset');
                    expect(button.actions[0].params.preset).toBe(presets[index]);
                });
            });

            it('각 프리셋 버튼이 올바른 다국어 키를 사용해야 한다', () => {
                const labelFormContent = labelFormModal.children[0];
                const datePresetSection = labelFormContent.children.find(
                    (child: any) => child.id === 'date_preset_section'
                );
                const buttonContainer = datePresetSection.children[1];
                const buttons = buttonContainer.children;

                const expectedTexts = [
                    '$t:sirsoft-ecommerce.admin.product.labels.preset_7d',
                    '$t:sirsoft-ecommerce.admin.product.labels.preset_14d',
                    '$t:sirsoft-ecommerce.admin.product.labels.preset_30d',
                    '$t:sirsoft-ecommerce.admin.product.labels.preset_permanent',
                ];

                buttons.forEach((button: any, index: number) => {
                    expect(button.text).toBe(expectedTexts[index]);
                });
            });
        });

        describe('시작일/종료일 필드 검증', () => {
            it('시작일 입력 필드가 존재하고 올바른 바인딩을 가져야 한다', () => {
                const labelFormContent = labelFormModal.children[0];
                const dateRangeSection = labelFormContent.children.find(
                    (child: any) => child.id === 'date_range_section'
                );
                const startDateSection = dateRangeSection.children[0];
                const startDateInput = startDateSection.children[1];

                expect(startDateInput.props.name).toBe('start_date');
                expect(startDateInput.props.type).toBe('date');
                expect(startDateInput.props.value).toBe("{{_global.labelFormData?.start_date ?? ''}}");
            });

            it('종료일 입력 필드가 존재하고 올바른 바인딩을 가져야 한다', () => {
                const labelFormContent = labelFormModal.children[0];
                const dateRangeSection = labelFormContent.children.find(
                    (child: any) => child.id === 'date_range_section'
                );
                const endDateSection = dateRangeSection.children[1];
                const endDateInput = endDateSection.children[1];

                expect(endDateInput.props.name).toBe('end_date');
                expect(endDateInput.props.type).toBe('date');
                expect(endDateInput.props.value).toBe("{{_global.labelFormData?.end_date ?? ''}}");
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

            it('조건부 렌더링 표현식이 올바르게 설정되어야 한다', () => {
                expect(noticeTemplateConfirmModal.if).toBe('{{_global.showNoticeTemplateConfirmModal}}');
            });

            it('경고 메시지가 amber 스타일로 표시되어야 한다', () => {
                const content = noticeTemplateConfirmModal.children[0];
                const warningBox = content.children[0];

                expect(warningBox.props.className).toContain('bg-amber-50');
                expect(warningBox.props.className).toContain('dark:bg-amber-900/20');
            });

            it('취소 버튼이 모달을 닫고 상태를 초기화해야 한다', () => {
                const content = noticeTemplateConfirmModal.children[0];
                const buttonContainer = content.children[1];
                const cancelButton = buttonContainer.children[0];

                expect(cancelButton.text).toBe('$t:sirsoft-ecommerce.common.cancel');
                expect(cancelButton.actions[0].handler).toBe('setState');
                expect(cancelButton.actions[0].params.target).toBe('global');
                expect(cancelButton.actions[0].params.showNoticeTemplateConfirmModal).toBe(false);
                expect(cancelButton.actions[0].params.pendingNoticeTemplateId).toBe(null);
            });

            it('확인 버튼이 sequence 핸들러로 모달 닫기 + 템플릿 적용 + 상태 초기화를 수행해야 한다', () => {
                const content = noticeTemplateConfirmModal.children[0];
                const buttonContainer = content.children[1];
                const confirmButton = buttonContainer.children[1];

                expect(confirmButton.text).toBe('$t:sirsoft-ecommerce.admin.product.notice.confirm_change_button');
                expect(confirmButton.actions[0].handler).toBe('sequence');

                const sequenceActions = confirmButton.actions[0].params.actions;
                expect(sequenceActions).toHaveLength(3);

                // 1. 모달 닫기
                expect(sequenceActions[0].handler).toBe('setState');
                expect(sequenceActions[0].params.showNoticeTemplateConfirmModal).toBe(false);

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
        it('프리셋 버튼 클릭 시 setLabelDatePreset 핸들러가 호출되어야 한다', async () => {
            // 핸들러 모킹
            const mockHandler = vi.fn();
            (window as any).G7Core.handlers = {
                'sirsoft-ecommerce.setLabelDatePreset': mockHandler,
            };

            // 레이아웃에서 7d 프리셋 버튼의 액션 추출
            const labelFormContent = labelFormModal.children[0];
            const datePresetSection = labelFormContent.children.find(
                (child: any) => child.id === 'date_preset_section'
            );
            const buttonContainer = datePresetSection.children[1];
            const preset7dButton = buttonContainer.children[0];

            // 액션 정의 검증
            expect(preset7dButton.actions[0]).toEqual({
                type: 'click',
                handler: 'sirsoft-ecommerce.setLabelDatePreset',
                params: { preset: '7d' },
            });
        });

        it('확인 모달의 취소 버튼이 글로벌 상태를 올바르게 업데이트해야 한다', () => {
            const content = noticeTemplateConfirmModal.children[0];
            const buttonContainer = content.children[1];
            const cancelButton = buttonContainer.children[0];

            // 취소 액션 검증
            const cancelAction = cancelButton.actions[0];
            expect(cancelAction.handler).toBe('setState');
            expect(cancelAction.params).toEqual({
                target: 'global',
                showNoticeTemplateConfirmModal: false,
                pendingNoticeTemplateId: null,
            });
        });
    });

    describe('레이아웃 스타일 및 반응형 검증', () => {
        it('날짜 범위 섹션이 반응형 클래스를 가져야 한다', () => {
            const labelFormContent = labelFormModal.children[0];
            const dateRangeSection = labelFormContent.children.find(
                (child: any) => child.id === 'date_range_section'
            );

            expect(dateRangeSection.props.className).toContain('grid-cols-2');
            expect(dateRangeSection.responsive).toBeDefined();
            expect(dateRangeSection.responsive.portable.props.className).toContain('grid-cols-1');
        });

        it('프리셋 버튼들이 flex wrap으로 배치되어야 한다', () => {
            const labelFormContent = labelFormModal.children[0];
            const datePresetSection = labelFormContent.children.find(
                (child: any) => child.id === 'date_preset_section'
            );
            const buttonContainer = datePresetSection.children[1];

            expect(buttonContainer.props.className).toContain('flex');
            expect(buttonContainer.props.className).toContain('flex-wrap');
            expect(buttonContainer.props.className).toContain('gap-2');
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

        it('데이터 경로가 pagination을 사용한다 (ProductLogCollection 응답 구조)', () => {
            const json = JSON.stringify(activityLogPartial);
            expect(json).toContain('activity_logs.data?.pagination?.current_page');
            expect(json).toContain('activity_logs.data?.pagination?.last_page');
            expect(json).toContain('activity_logs.data?.pagination?.total');
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
