/**
 * 라벨 핸들러 테스트
 *
 * @description
 * - toggleLabelAssignmentHandler: ChipCheckbox 클릭 시 라벨 할당/해제 토글
 * - saveLabelSettingsHandler: 라벨 설정 모달에서 name/color API 저장 + 기간 로컬 업데이트
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi, Mock, afterEach } from 'vitest';
import {
    toggleLabelAssignmentHandler,
    saveLabelSettingsHandler,
    updateLabelPeriodInlineHandler,
    setLabelDatePresetInlineHandler,
    confirmUncheckLabelHandler,
} from '../labelHandlers';

/**
 * G7Core Mock 및 Action/Context 생성 함수
 *
 * 핸들러는 (action, context) 형태로 호출되며, G7Core API를 통해 상태를 관리합니다.
 */
const createMockSetup = (overrides?: {
    localState?: Record<string, any>;
    globalState?: Record<string, any>;
    params?: Record<string, any>;
    apiResponse?: any;
    apiError?: any;
}): { action: any; context: any; g7CoreMock: any } => {
    const localState: Record<string, any> = overrides?.localState ? { ...overrides.localState } : {};
    const globalState: Record<string, any> = overrides?.globalState ? { ...overrides.globalState } : {};

    const g7CoreMock = {
        state: {
            get: vi.fn(() => ({
                _local: localState,
                ...globalState,
            })),
            getLocal: vi.fn(() => localState),
            setLocal: vi.fn((updates: Record<string, any>) => {
                // 'form.label_assignments' 형태의 dot notation 처리
                for (const [key, value] of Object.entries(updates)) {
                    if (key.includes('.')) {
                        const parts = key.split('.');
                        let obj = localState;
                        for (let i = 0; i < parts.length - 1; i++) {
                            if (!obj[parts[i]]) obj[parts[i]] = {};
                            obj = obj[parts[i]];
                        }
                        obj[parts[parts.length - 1]] = value;
                    } else {
                        localState[key] = value;
                    }
                }
            }),
            setGlobal: vi.fn((updates: Record<string, any>) => {
                Object.assign(globalState, updates);
            }),
        },
        api: {
            put: overrides?.apiError
                ? vi.fn().mockRejectedValue(overrides.apiError)
                : vi.fn().mockResolvedValue(overrides?.apiResponse ?? { success: true }),
        },
        dataSource: {
            refetch: vi.fn(),
        },
        toast: {
            success: vi.fn(),
            error: vi.fn(),
        },
        modal: {
            close: vi.fn(),
        },
        t: vi.fn((key: string) => key),
    };

    (window as any).G7Core = g7CoreMock;

    return {
        action: {
            handler: 'testHandler',
            params: overrides?.params ?? {},
        },
        context: {
            data: {
                _local: localState,
                _global: globalState,
            },
        },
        g7CoreMock,
    };
};

describe('labelHandlers', () => {
    afterEach(() => {
        vi.clearAllMocks();
        delete (window as any).G7Core;
    });

    describe('toggleLabelAssignmentHandler', () => {
        describe('라벨 추가 (미할당 라벨 클릭)', () => {
            it('빈 배열에 새 라벨을 추가해야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            label_assignments: [],
                        },
                    },
                    params: { labelId: 1 },
                });

                toggleLabelAssignmentHandler(action, context);

                expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                    'form.label_assignments': [
                        {
                            label_id: 1,
                            start_date: null,
                            end_date: null,
                        },
                    ],
                    'ui.lastClickedLabelId': 1,
                    hasChanges: true,
                });
            });

            it('기존 라벨이 있는 경우 배열 끝에 추가해야 한다', () => {
                const existingAssignments = [
                    { label_id: 2, start_date: null, end_date: null },
                ];

                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            label_assignments: existingAssignments,
                        },
                    },
                    params: { labelId: 3 },
                });

                toggleLabelAssignmentHandler(action, context);

                expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                    'form.label_assignments': [
                        ...existingAssignments,
                        {
                            label_id: 3,
                            start_date: null,
                            end_date: null,
                        },
                    ],
                    'ui.lastClickedLabelId': 3,
                    hasChanges: true,
                });
            });

            it('label_assignments가 undefined인 경우에도 정상 동작해야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {},
                    },
                    params: { labelId: 5 },
                });

                toggleLabelAssignmentHandler(action, context);

                expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                    'form.label_assignments': [
                        {
                            label_id: 5,
                            start_date: null,
                            end_date: null,
                        },
                    ],
                    'ui.lastClickedLabelId': 5,
                    hasChanges: true,
                });
            });
        });

        describe('라벨 제거 (이미 할당된 라벨 클릭)', () => {
            it('할당된 라벨을 클릭하면 제거해야 한다', () => {
                const existingAssignments = [
                    { label_id: 1, start_date: null, end_date: null },
                    { label_id: 2, start_date: null, end_date: null },
                ];

                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            label_assignments: existingAssignments,
                        },
                    },
                    params: { labelId: 1 },
                });

                toggleLabelAssignmentHandler(action, context);

                expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                    'form.label_assignments': [
                        { label_id: 2, start_date: null, end_date: null },
                    ],
                    'ui.lastClickedLabelId': 1,
                    hasChanges: true,
                });
            });

            it('마지막 라벨을 제거하면 빈 배열이 되어야 한다', () => {
                const existingAssignments = [
                    { label_id: 1, start_date: null, end_date: null },
                ];

                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            label_assignments: existingAssignments,
                        },
                    },
                    params: { labelId: 1 },
                });

                toggleLabelAssignmentHandler(action, context);

                expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                    'form.label_assignments': [],
                    'ui.lastClickedLabelId': 1,
                    hasChanges: true,
                });
            });

            it('중간에 있는 라벨을 제거하면 나머지가 유지되어야 한다', () => {
                // 기간이 없는 라벨만 테스트 (기간이 있으면 확인 모달이 열림)
                const existingAssignments = [
                    { label_id: 1, start_date: null, end_date: null },
                    { label_id: 2, start_date: null, end_date: null },
                    { label_id: 3, start_date: null, end_date: null },
                ];

                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            label_assignments: existingAssignments,
                        },
                    },
                    params: { labelId: 2 },
                });

                toggleLabelAssignmentHandler(action, context);

                expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                    'form.label_assignments': [
                        { label_id: 1, start_date: null, end_date: null },
                        { label_id: 3, start_date: null, end_date: null },
                    ],
                    'ui.lastClickedLabelId': 2,
                    hasChanges: true,
                });
            });
        });

        describe('유효성 검사', () => {
            it('labelId가 없으면 아무 동작도 하지 않아야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: { label_assignments: [] },
                    },
                    params: {},
                });

                toggleLabelAssignmentHandler(action, context);

                expect(g7CoreMock.state.setLocal).not.toHaveBeenCalled();
            });

            it('labelId가 null이면 아무 동작도 하지 않아야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: { label_assignments: [] },
                    },
                    params: { labelId: null },
                });

                toggleLabelAssignmentHandler(action, context);

                expect(g7CoreMock.state.setLocal).not.toHaveBeenCalled();
            });

            it('labelId가 0이면 아무 동작도 하지 않아야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: { label_assignments: [] },
                    },
                    params: { labelId: 0 },
                });

                toggleLabelAssignmentHandler(action, context);

                expect(g7CoreMock.state.setLocal).not.toHaveBeenCalled();
            });
        });

        describe('상태 플래그', () => {
            it('hasChanges 플래그가 true로 설정되어야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: { label_assignments: [] },
                    },
                    params: { labelId: 1 },
                });

                toggleLabelAssignmentHandler(action, context);

                const setLocalCall = (g7CoreMock.state.setLocal as Mock).mock.calls[0];
                expect(setLocalCall[0].hasChanges).toBe(true);
            });

            it('lastClickedLabelId가 클릭한 라벨 ID로 설정되어야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: { label_assignments: [] },
                    },
                    params: { labelId: 42 },
                });

                toggleLabelAssignmentHandler(action, context);

                const setLocalCall = (g7CoreMock.state.setLocal as Mock).mock.calls[0];
                expect(setLocalCall[0]['ui.lastClickedLabelId']).toBe(42);
            });

            it('문자열 labelId도 Number 변환하여 정상 동작해야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: { label_assignments: [] },
                    },
                    params: { labelId: '7' },
                });

                toggleLabelAssignmentHandler(action, context);

                expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                    'form.label_assignments': [
                        { label_id: 7, start_date: null, end_date: null },
                    ],
                    'ui.lastClickedLabelId': 7,
                    hasChanges: true,
                });
            });
        });
    });

    describe('saveLabelSettingsHandler', () => {
        describe('성공 시나리오', () => {
            it('라벨 name/color를 API로 저장하고 기간을 로컬 업데이트해야 한다', async () => {
                const existingAssignments = [
                    { label_id: 1, start_date: null, end_date: null },
                    { label_id: 2, start_date: null, end_date: null },
                ];

                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            label_assignments: existingAssignments,
                        },
                    },
                    globalState: {
                        editingLabelId: 1,
                        labelFormData: {
                            name: { ko: '할인', en: 'Sale' },
                            color: '#FF0000',
                            start_date: '2025-01-01',
                            end_date: '2025-12-31',
                        },
                    },
                    apiResponse: { success: true },
                });

                await saveLabelSettingsHandler(action, context);

                // API 호출 확인
                expect(g7CoreMock.api.put).toHaveBeenCalledWith(
                    '/api/modules/sirsoft-ecommerce/admin/product-labels/1',
                    {
                        name: { ko: '할인', en: 'Sale' },
                        color: '#FF0000',
                    }
                );

                // 로컬 상태에서 기간 업데이트 확인
                expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                    'form.label_assignments': [
                        { label_id: 1, start_date: '2025-01-01', end_date: '2025-12-31' },
                        { label_id: 2, start_date: null, end_date: null },
                    ],
                    hasChanges: true,
                });

                // 데이터소스 리프레시 확인
                expect(g7CoreMock.dataSource.refetch).toHaveBeenCalledWith('product_labels');

                // 토스트 + 모달 닫기 확인
                expect(g7CoreMock.toast.success).toHaveBeenCalled();
                expect(g7CoreMock.modal.close).toHaveBeenCalled();
            });

            it('날짜가 없어도 저장이 가능해야 한다', async () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            label_assignments: [
                                { label_id: 5, start_date: null, end_date: null },
                            ],
                        },
                    },
                    globalState: {
                        editingLabelId: 5,
                        labelFormData: {
                            name: { ko: '신상', en: 'New' },
                            color: null,
                            start_date: null,
                            end_date: null,
                        },
                    },
                    apiResponse: { success: true },
                });

                await saveLabelSettingsHandler(action, context);

                expect(g7CoreMock.api.put).toHaveBeenCalledWith(
                    '/api/modules/sirsoft-ecommerce/admin/product-labels/5',
                    {
                        name: { ko: '신상', en: 'New' },
                        color: null,
                    }
                );

                expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                    'form.label_assignments': [
                        { label_id: 5, start_date: null, end_date: null },
                    ],
                    hasChanges: true,
                });

                expect(g7CoreMock.modal.close).toHaveBeenCalled();
            });
        });

        describe('에러 시나리오', () => {
            it('API가 실패 응답을 반환하면 에러 토스트를 표시해야 한다', async () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            label_assignments: [{ label_id: 1, start_date: null, end_date: null }],
                        },
                    },
                    globalState: {
                        editingLabelId: 1,
                        labelFormData: {
                            name: { ko: '할인', en: 'Sale' },
                            color: '#FF0000',
                            start_date: null,
                            end_date: null,
                        },
                    },
                    apiResponse: { success: false, message: 'Server error' },
                });

                await saveLabelSettingsHandler(action, context);

                expect(g7CoreMock.toast.error).toHaveBeenCalled();
                // 로컬 상태 업데이트하지 않아야 함
                expect(g7CoreMock.state.setLocal).not.toHaveBeenCalled();
                // 모달 닫지 않아야 함
                expect(g7CoreMock.modal.close).not.toHaveBeenCalled();
            });

            it('API 요청이 예외를 던지면 에러 토스트를 표시해야 한다', async () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            label_assignments: [{ label_id: 1, start_date: null, end_date: null }],
                        },
                    },
                    globalState: {
                        editingLabelId: 1,
                        labelFormData: {
                            name: { ko: '할인', en: 'Sale' },
                            color: '#FF0000',
                            start_date: null,
                            end_date: null,
                        },
                    },
                    apiError: { message: 'Network error' },
                });

                await saveLabelSettingsHandler(action, context);

                expect(g7CoreMock.toast.error).toHaveBeenCalled();
                expect(g7CoreMock.modal.close).not.toHaveBeenCalled();
            });
        });

        describe('유효성 검사', () => {
            it('editingLabelId가 없으면 아무 동작도 하지 않아야 한다', async () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: { label_assignments: [] },
                    },
                    globalState: {
                        editingLabelId: null,
                        labelFormData: {
                            name: { ko: '할인', en: 'Sale' },
                            color: '#FF0000',
                            start_date: null,
                            end_date: null,
                        },
                    },
                });

                await saveLabelSettingsHandler(action, context);

                expect(g7CoreMock.api.put).not.toHaveBeenCalled();
                expect(g7CoreMock.state.setGlobal).not.toHaveBeenCalled();
            });

            it('name.ko가 비어있으면 아무 동작도 하지 않아야 한다', async () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: { label_assignments: [] },
                    },
                    globalState: {
                        editingLabelId: 1,
                        labelFormData: {
                            name: { ko: '', en: 'Sale' },
                            color: '#FF0000',
                            start_date: null,
                            end_date: null,
                        },
                    },
                });

                await saveLabelSettingsHandler(action, context);

                expect(g7CoreMock.api.put).not.toHaveBeenCalled();
                expect(g7CoreMock.state.setGlobal).not.toHaveBeenCalled();
            });
        });

        describe('저장 상태 관리', () => {
            it('저장 시작 시 isSavingLabel이 true가 되어야 한다', async () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            label_assignments: [{ label_id: 1, start_date: null, end_date: null }],
                        },
                    },
                    globalState: {
                        editingLabelId: 1,
                        labelFormData: {
                            name: { ko: '할인', en: 'Sale' },
                            color: '#FF0000',
                            start_date: null,
                            end_date: null,
                        },
                    },
                    apiResponse: { success: true },
                });

                await saveLabelSettingsHandler(action, context);

                expect(g7CoreMock.state.setGlobal).toHaveBeenCalledWith({ isSavingLabel: true });
            });

            it('저장 완료 시 isSavingLabel이 false가 되어야 한다', async () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            label_assignments: [{ label_id: 1, start_date: null, end_date: null }],
                        },
                    },
                    globalState: {
                        editingLabelId: 1,
                        labelFormData: {
                            name: { ko: '할인', en: 'Sale' },
                            color: '#FF0000',
                            start_date: null,
                            end_date: null,
                        },
                    },
                    apiResponse: { success: true },
                });

                await saveLabelSettingsHandler(action, context);

                expect(g7CoreMock.state.setGlobal).toHaveBeenCalledWith({ isSavingLabel: false });
            });

            it('에러 발생 시에도 isSavingLabel이 false로 복원되어야 한다', async () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            label_assignments: [{ label_id: 1, start_date: null, end_date: null }],
                        },
                    },
                    globalState: {
                        editingLabelId: 1,
                        labelFormData: {
                            name: { ko: '할인', en: 'Sale' },
                            color: '#FF0000',
                            start_date: null,
                            end_date: null,
                        },
                    },
                    apiError: new Error('Network error'),
                });

                await saveLabelSettingsHandler(action, context);

                expect(g7CoreMock.state.setGlobal).toHaveBeenCalledWith({ isSavingLabel: false });
            });
        });
    });

    describe('toggleLabelAssignmentHandler - 기간 설정된 라벨 해제 시 확인 모달', () => {
        it('기간이 설정된 라벨 클릭 시 모달을 열어야 한다', () => {
            const g7CoreMock = {
                state: {
                    get: vi.fn(() => ({})),
                    getLocal: vi.fn(() => ({
                        form: {
                            label_assignments: [
                                { label_id: 1, start_date: '2025-01-01', end_date: '2025-12-31' },
                            ],
                        },
                    })),
                    setLocal: vi.fn(),
                    setGlobal: vi.fn(),
                },
                modal: {
                    open: vi.fn(),
                },
            };
            (window as any).G7Core = g7CoreMock;

            const action = { handler: 'toggleLabelAssignment', params: { labelId: 1 } };
            const context = {};

            toggleLabelAssignmentHandler(action, context);

            expect(g7CoreMock.state.setGlobal).toHaveBeenCalledWith({ labelToUncheckId: 1 });
            expect(g7CoreMock.modal.open).toHaveBeenCalledWith('modal_label_uncheck_confirm');
            expect(g7CoreMock.state.setLocal).not.toHaveBeenCalled();
        });

        it('start_date만 있어도 확인 모달을 열어야 한다', () => {
            const g7CoreMock = {
                state: {
                    get: vi.fn(() => ({})),
                    getLocal: vi.fn(() => ({
                        form: {
                            label_assignments: [
                                { label_id: 1, start_date: '2025-01-01', end_date: null },
                            ],
                        },
                    })),
                    setLocal: vi.fn(),
                    setGlobal: vi.fn(),
                },
                modal: {
                    open: vi.fn(),
                },
            };
            (window as any).G7Core = g7CoreMock;

            const action = { handler: 'toggleLabelAssignment', params: { labelId: 1 } };

            toggleLabelAssignmentHandler(action, {});

            expect(g7CoreMock.modal.open).toHaveBeenCalledWith('modal_label_uncheck_confirm');
        });

        it('기간이 없는 라벨은 바로 제거해야 한다', () => {
            const g7CoreMock = {
                state: {
                    get: vi.fn(() => ({})),
                    getLocal: vi.fn(() => ({
                        form: {
                            label_assignments: [
                                { label_id: 1, start_date: null, end_date: null },
                            ],
                        },
                    })),
                    setLocal: vi.fn(),
                    setGlobal: vi.fn(),
                },
                modal: {
                    open: vi.fn(),
                },
            };
            (window as any).G7Core = g7CoreMock;

            const action = { handler: 'toggleLabelAssignment', params: { labelId: 1 } };

            toggleLabelAssignmentHandler(action, {});

            expect(g7CoreMock.modal.open).not.toHaveBeenCalled();
            expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                'form.label_assignments': [],
                'ui.lastClickedLabelId': 1,
                hasChanges: true,
            });
        });
    });

    describe('updateLabelPeriodInlineHandler', () => {
        describe('성공 시나리오', () => {
            it('start_date를 업데이트해야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            label_assignments: [
                                { label_id: 1, start_date: null, end_date: null },
                            ],
                        },
                    },
                    params: { labelId: 1, field: 'start_date', value: '2025-01-01' },
                });

                updateLabelPeriodInlineHandler(action, context);

                expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                    'form.label_assignments': [
                        { label_id: 1, start_date: '2025-01-01', end_date: null },
                    ],
                    hasChanges: true,
                });
            });

            it('end_date를 업데이트해야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            label_assignments: [
                                { label_id: 1, start_date: '2025-01-01', end_date: null },
                            ],
                        },
                    },
                    params: { labelId: 1, field: 'end_date', value: '2025-12-31' },
                });

                updateLabelPeriodInlineHandler(action, context);

                expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                    'form.label_assignments': [
                        { label_id: 1, start_date: '2025-01-01', end_date: '2025-12-31' },
                    ],
                    hasChanges: true,
                });
            });

            it('value가 빈 문자열이면 null로 설정해야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            label_assignments: [
                                { label_id: 1, start_date: '2025-01-01', end_date: null },
                            ],
                        },
                    },
                    params: { labelId: 1, field: 'start_date', value: '' },
                });

                updateLabelPeriodInlineHandler(action, context);

                expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                    'form.label_assignments': [
                        { label_id: 1, start_date: null, end_date: null },
                    ],
                    hasChanges: true,
                });
            });
        });

        describe('유효성 검사', () => {
            it('labelId가 없으면 아무 동작도 하지 않아야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: { form: { label_assignments: [] } },
                    params: { field: 'start_date', value: '2025-01-01' },
                });

                updateLabelPeriodInlineHandler(action, context);

                expect(g7CoreMock.state.setLocal).not.toHaveBeenCalled();
            });

            it('field가 없으면 아무 동작도 하지 않아야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: { form: { label_assignments: [] } },
                    params: { labelId: 1, value: '2025-01-01' },
                });

                updateLabelPeriodInlineHandler(action, context);

                expect(g7CoreMock.state.setLocal).not.toHaveBeenCalled();
            });

            it('존재하지 않는 labelId면 아무 동작도 하지 않아야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            label_assignments: [
                                { label_id: 1, start_date: null, end_date: null },
                            ],
                        },
                    },
                    params: { labelId: 999, field: 'start_date', value: '2025-01-01' },
                });

                updateLabelPeriodInlineHandler(action, context);

                expect(g7CoreMock.state.setLocal).not.toHaveBeenCalled();
            });
        });
    });

    describe('setLabelDatePresetInlineHandler', () => {
        beforeEach(() => {
            vi.useFakeTimers();
            vi.setSystemTime(new Date('2025-01-28'));
        });

        afterEach(() => {
            vi.useRealTimers();
        });

        it('7d 프리셋: 오늘부터 7일 후까지 설정해야 한다', () => {
            const { action, context, g7CoreMock } = createMockSetup({
                localState: {
                    ui: { lastClickedLabelId: 1 },
                    form: {
                        label_assignments: [
                            { label_id: 1, start_date: null, end_date: null },
                        ],
                    },
                },
                params: { preset: '7d' },
            });

            setLabelDatePresetInlineHandler(action, context);

            expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                'form.label_assignments': [
                    { label_id: 1, start_date: '2025-01-28', end_date: '2025-02-04' },
                ],
                hasChanges: true,
            });
        });

        it('14d 프리셋: 오늘부터 14일 후까지 설정해야 한다', () => {
            const { action, context, g7CoreMock } = createMockSetup({
                localState: {
                    ui: { lastClickedLabelId: 1 },
                    form: {
                        label_assignments: [
                            { label_id: 1, start_date: null, end_date: null },
                        ],
                    },
                },
                params: { preset: '14d' },
            });

            setLabelDatePresetInlineHandler(action, context);

            expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                'form.label_assignments': [
                    { label_id: 1, start_date: '2025-01-28', end_date: '2025-02-11' },
                ],
                hasChanges: true,
            });
        });

        it('30d 프리셋: 오늘부터 30일 후까지 설정해야 한다', () => {
            const { action, context, g7CoreMock } = createMockSetup({
                localState: {
                    ui: { lastClickedLabelId: 1 },
                    form: {
                        label_assignments: [
                            { label_id: 1, start_date: null, end_date: null },
                        ],
                    },
                },
                params: { preset: '30d' },
            });

            setLabelDatePresetInlineHandler(action, context);

            expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                'form.label_assignments': [
                    { label_id: 1, start_date: '2025-01-28', end_date: '2025-02-27' },
                ],
                hasChanges: true,
            });
        });

        it('permanent 프리셋: start_date만 설정하고 end_date는 null이어야 한다', () => {
            const { action, context, g7CoreMock } = createMockSetup({
                localState: {
                    ui: { lastClickedLabelId: 1 },
                    form: {
                        label_assignments: [
                            { label_id: 1, start_date: null, end_date: null },
                        ],
                    },
                },
                params: { preset: 'permanent' },
            });

            setLabelDatePresetInlineHandler(action, context);

            expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                'form.label_assignments': [
                    { label_id: 1, start_date: '2025-01-28', end_date: null },
                ],
                hasChanges: true,
            });
        });

        it('lastClickedLabelId가 없으면 아무 동작도 하지 않아야 한다', () => {
            const { action, context, g7CoreMock } = createMockSetup({
                localState: {
                    ui: { lastClickedLabelId: null },
                    form: {
                        label_assignments: [
                            { label_id: 1, start_date: null, end_date: null },
                        ],
                    },
                },
                params: { preset: '7d' },
            });

            setLabelDatePresetInlineHandler(action, context);

            expect(g7CoreMock.state.setLocal).not.toHaveBeenCalled();
        });
    });

    describe('confirmUncheckLabelHandler', () => {
        it('라벨을 label_assignments에서 제거해야 한다', () => {
            const { action, context, g7CoreMock } = createMockSetup({
                localState: {
                    form: {
                        label_assignments: [
                            { label_id: 1, start_date: '2025-01-01', end_date: '2025-12-31' },
                            { label_id: 2, start_date: null, end_date: null },
                        ],
                    },
                },
                globalState: {
                    labelToUncheckId: 1,
                },
            });

            confirmUncheckLabelHandler(action, context);

            expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                'form.label_assignments': [
                    { label_id: 2, start_date: null, end_date: null },
                ],
                'ui.lastClickedLabelId': null,
                hasChanges: true,
            });
            expect(g7CoreMock.state.setGlobal).toHaveBeenCalledWith({ labelToUncheckId: null });
            expect(g7CoreMock.modal.close).toHaveBeenCalled();
        });

        it('labelToUncheckId가 없으면 아무 동작도 하지 않아야 한다', () => {
            const { action, context, g7CoreMock } = createMockSetup({
                localState: {
                    form: {
                        label_assignments: [
                            { label_id: 1, start_date: '2025-01-01', end_date: '2025-12-31' },
                        ],
                    },
                },
                globalState: {
                    labelToUncheckId: null,
                },
            });

            confirmUncheckLabelHandler(action, context);

            expect(g7CoreMock.state.setLocal).not.toHaveBeenCalled();
            expect(g7CoreMock.modal.close).not.toHaveBeenCalled();
        });
    });
});
