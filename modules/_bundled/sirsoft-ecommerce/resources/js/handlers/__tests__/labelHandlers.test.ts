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
            post: overrides?.apiError
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
            open: vi.fn(),
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
                            started_at: null,
                            ended_at: null,
                        },
                    ],
                    'ui.lastClickedLabelId': 1,
                    hasChanges: true,
                });
            });

            it('기존 라벨이 있는 경우 배열 끝에 추가해야 한다', () => {
                const existingAssignments = [
                    { label_id: 2, started_at: null, ended_at: null },
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
                            started_at: null,
                            ended_at: null,
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
                            started_at: null,
                            ended_at: null,
                        },
                    ],
                    'ui.lastClickedLabelId': 5,
                    hasChanges: true,
                });
            });
        });

        describe('A31 — 이미 할당된 라벨 클릭 = 기간 패널 전환만 (해제 안 함)', () => {
            it('기간 없는 할당 칩 클릭 시 label_assignments 불변 + lastClickedLabelId 만 변경', () => {
                const existingAssignments = [
                    { label_id: 1, started_at: null, ended_at: null },
                    { label_id: 2, started_at: null, ended_at: null },
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

                // 패널 전환만 — label_assignments 미변경
                expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                    'ui.lastClickedLabelId': 1,
                });
                // 해제(필터링)되지 않음
                expect(g7CoreMock.state.setLocal).not.toHaveBeenCalledWith(
                    expect.objectContaining({ 'form.label_assignments': expect.anything() })
                );
            });

            it('기간 있는 할당 칩 클릭 시에도 패널 전환만 (해제 모달 미호출)', () => {
                const existingAssignments = [
                    { label_id: 1, started_at: '2026-06-01', ended_at: '2026-06-30' },
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
                    'ui.lastClickedLabelId': 1,
                });
                // 해제 확인 모달은 더 이상 토글에서 열리지 않음 (미리보기 "할당 해제" 전용)
                expect(g7CoreMock.modal.open).not.toHaveBeenCalled();
            });

            it('다른 할당 칩으로 전환 시 둘 다 유지 + lastClickedLabelId 만 이동', () => {
                const existingAssignments = [
                    { label_id: 1, started_at: '2026-06-01', ended_at: null },
                    { label_id: 2, started_at: null, ended_at: null },
                ];

                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            label_assignments: existingAssignments,
                        },
                        ui: { lastClickedLabelId: 1 },
                    },
                    params: { labelId: 2 },
                });

                toggleLabelAssignmentHandler(action, context);

                expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                    'ui.lastClickedLabelId': 2,
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
                        { label_id: 7, started_at: null, ended_at: null },
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
                    { label_id: 1, started_at: null, ended_at: null },
                    { label_id: 2, started_at: null, ended_at: null },
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
                            started_at: '2025-01-01',
                            ended_at: '2025-12-31',
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
                        { label_id: 1, started_at: '2025-01-01', ended_at: '2025-12-31' },
                        { label_id: 2, started_at: null, ended_at: null },
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
                                { label_id: 5, started_at: null, ended_at: null },
                            ],
                        },
                    },
                    globalState: {
                        editingLabelId: 5,
                        labelFormData: {
                            name: { ko: '신상', en: 'New' },
                            color: null,
                            started_at: null,
                            ended_at: null,
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
                        { label_id: 5, started_at: null, ended_at: null },
                    ],
                    hasChanges: true,
                });

                expect(g7CoreMock.modal.close).toHaveBeenCalled();
            });
        });

        describe('A31 — 생성 시나리오 (editingLabelId 없음 → POST)', () => {
            it('신규 라벨을 POST 로 생성하고 새 id 를 label_assignments 에 추가', async () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            label_assignments: [
                                { label_id: 2, started_at: null, ended_at: null },
                            ],
                        },
                    },
                    globalState: {
                        editingLabelId: null,
                        labelFormData: {
                            name: { ko: '신규라벨', en: 'New Label' },
                            color: '#6B7280',
                            started_at: null,
                            ended_at: null,
                        },
                    },
                    apiResponse: { success: true, data: { id: 99 } },
                });

                await saveLabelSettingsHandler(action, context);

                // POST 호출 (name + color)
                expect(g7CoreMock.api.post).toHaveBeenCalledWith(
                    '/api/modules/sirsoft-ecommerce/admin/product-labels',
                    {
                        name: { ko: '신규라벨', en: 'New Label' },
                        color: '#6B7280',
                    }
                );
                // PUT 미호출
                expect(g7CoreMock.api.put).not.toHaveBeenCalled();

                // 새 id 가 label_assignments 에 추가 + lastClickedLabelId 설정
                expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                    'form.label_assignments': [
                        { label_id: 2, started_at: null, ended_at: null },
                        { label_id: 99, started_at: null, ended_at: null },
                    ],
                    'ui.lastClickedLabelId': 99,
                    hasChanges: true,
                });

                expect(g7CoreMock.dataSource.refetch).toHaveBeenCalledWith('product_labels');
                expect(g7CoreMock.modal.close).toHaveBeenCalled();
            });

            it('color 미지정 시 기본 색상(#6B7280)으로 보정', async () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: { form: { label_assignments: [] } },
                    globalState: {
                        editingLabelId: null,
                        labelFormData: {
                            name: { ko: '색상없음', en: 'No Color' },
                            color: null,
                            started_at: null,
                            ended_at: null,
                        },
                    },
                    apiResponse: { success: true, data: { id: 50 } },
                });

                await saveLabelSettingsHandler(action, context);

                expect(g7CoreMock.api.post).toHaveBeenCalledWith(
                    '/api/modules/sirsoft-ecommerce/admin/product-labels',
                    {
                        name: { ko: '색상없음', en: 'No Color' },
                        color: '#6B7280',
                    }
                );
            });

            it('name.ko 가 없으면 아무 동작도 하지 않는다', async () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: { form: { label_assignments: [] } },
                    globalState: {
                        editingLabelId: null,
                        labelFormData: {
                            name: { ko: '', en: 'No Korean' },
                            color: '#6B7280',
                            started_at: null,
                            ended_at: null,
                        },
                    },
                });

                await saveLabelSettingsHandler(action, context);

                expect(g7CoreMock.api.post).not.toHaveBeenCalled();
                expect(g7CoreMock.api.put).not.toHaveBeenCalled();
            });
        });

        describe('에러 시나리오', () => {
            it('API가 실패 응답을 반환하면 에러 토스트를 표시해야 한다', async () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            label_assignments: [{ label_id: 1, started_at: null, ended_at: null }],
                        },
                    },
                    globalState: {
                        editingLabelId: 1,
                        labelFormData: {
                            name: { ko: '할인', en: 'Sale' },
                            color: '#FF0000',
                            started_at: null,
                            ended_at: null,
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
                            label_assignments: [{ label_id: 1, started_at: null, ended_at: null }],
                        },
                    },
                    globalState: {
                        editingLabelId: 1,
                        labelFormData: {
                            name: { ko: '할인', en: 'Sale' },
                            color: '#FF0000',
                            started_at: null,
                            ended_at: null,
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
            it('A31: editingLabelId 없음 + name.ko 있음 → 생성(POST) 경로로 동작한다', async () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: { label_assignments: [] },
                    },
                    globalState: {
                        editingLabelId: null,
                        labelFormData: {
                            name: { ko: '할인', en: 'Sale' },
                            color: '#FF0000',
                            started_at: null,
                            ended_at: null,
                        },
                    },
                    apiResponse: { success: true, data: { id: 7 } },
                });

                await saveLabelSettingsHandler(action, context);

                // 생성 경로 → PUT 대신 POST 호출
                expect(g7CoreMock.api.put).not.toHaveBeenCalled();
                expect(g7CoreMock.api.post).toHaveBeenCalled();
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
                            started_at: null,
                            ended_at: null,
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
                            label_assignments: [{ label_id: 1, started_at: null, ended_at: null }],
                        },
                    },
                    globalState: {
                        editingLabelId: 1,
                        labelFormData: {
                            name: { ko: '할인', en: 'Sale' },
                            color: '#FF0000',
                            started_at: null,
                            ended_at: null,
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
                            label_assignments: [{ label_id: 1, started_at: null, ended_at: null }],
                        },
                    },
                    globalState: {
                        editingLabelId: 1,
                        labelFormData: {
                            name: { ko: '할인', en: 'Sale' },
                            color: '#FF0000',
                            started_at: null,
                            ended_at: null,
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
                            label_assignments: [{ label_id: 1, started_at: null, ended_at: null }],
                        },
                    },
                    globalState: {
                        editingLabelId: 1,
                        labelFormData: {
                            name: { ko: '할인', en: 'Sale' },
                            color: '#FF0000',
                            started_at: null,
                            ended_at: null,
                        },
                    },
                    apiError: new Error('Network error'),
                });

                await saveLabelSettingsHandler(action, context);

                expect(g7CoreMock.state.setGlobal).toHaveBeenCalledWith({ isSavingLabel: false });
            });
        });
    });

    describe('A31 — 토글이 더 이상 해제/제거를 수행하지 않음 (패널 전환 전용)', () => {
        const setup = (assignment: any) => {
            const g7CoreMock = {
                state: {
                    get: vi.fn(() => ({})),
                    getLocal: vi.fn(() => ({
                        form: { label_assignments: [assignment] },
                    })),
                    setLocal: vi.fn(),
                    setGlobal: vi.fn(),
                },
                modal: { open: vi.fn() },
            };
            (window as any).G7Core = g7CoreMock;
            return g7CoreMock;
        };

        it('기간 설정된 라벨 클릭 시 해제 모달을 열지 않는다 (패널 전환만)', () => {
            const g7CoreMock = setup({ label_id: 1, started_at: '2025-01-01', ended_at: '2025-12-31' });

            toggleLabelAssignmentHandler({ handler: 'toggleLabelAssignment', params: { labelId: 1 } }, {});

            expect(g7CoreMock.modal.open).not.toHaveBeenCalled();
            expect(g7CoreMock.state.setGlobal).not.toHaveBeenCalled();
            expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({ 'ui.lastClickedLabelId': 1 });
        });

        it('started_at만 있어도 패널 전환만 (해제 모달 미호출)', () => {
            const g7CoreMock = setup({ label_id: 1, started_at: '2025-01-01', ended_at: null });

            toggleLabelAssignmentHandler({ handler: 'toggleLabelAssignment', params: { labelId: 1 } }, {});

            expect(g7CoreMock.modal.open).not.toHaveBeenCalled();
            expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({ 'ui.lastClickedLabelId': 1 });
        });

        it('기간 없는 할당 라벨도 제거하지 않고 패널 전환만 한다', () => {
            const g7CoreMock = setup({ label_id: 1, started_at: null, ended_at: null });

            toggleLabelAssignmentHandler({ handler: 'toggleLabelAssignment', params: { labelId: 1 } }, {});

            expect(g7CoreMock.modal.open).not.toHaveBeenCalled();
            expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({ 'ui.lastClickedLabelId': 1 });
            // 제거(빈 배열) 호출 없음
            expect(g7CoreMock.state.setLocal).not.toHaveBeenCalledWith(
                expect.objectContaining({ 'form.label_assignments': [] })
            );
        });
    });

    describe('updateLabelPeriodInlineHandler', () => {
        describe('성공 시나리오', () => {
            it('started_at를 업데이트해야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            label_assignments: [
                                { label_id: 1, started_at: null, ended_at: null },
                            ],
                        },
                    },
                    params: { labelId: 1, field: 'started_at', value: '2025-01-01' },
                });

                updateLabelPeriodInlineHandler(action, context);

                expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                    'form.label_assignments': [
                        { label_id: 1, started_at: '2025-01-01', ended_at: null },
                    ],
                    hasChanges: true,
                });
            });

            it('ended_at를 업데이트해야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            label_assignments: [
                                { label_id: 1, started_at: '2025-01-01', ended_at: null },
                            ],
                        },
                    },
                    params: { labelId: 1, field: 'ended_at', value: '2025-12-31' },
                });

                updateLabelPeriodInlineHandler(action, context);

                expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                    'form.label_assignments': [
                        { label_id: 1, started_at: '2025-01-01', ended_at: '2025-12-31' },
                    ],
                    hasChanges: true,
                });
            });

            it('value가 빈 문자열이면 null로 설정해야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            label_assignments: [
                                { label_id: 1, started_at: '2025-01-01', ended_at: null },
                            ],
                        },
                    },
                    params: { labelId: 1, field: 'started_at', value: '' },
                });

                updateLabelPeriodInlineHandler(action, context);

                expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                    'form.label_assignments': [
                        { label_id: 1, started_at: null, ended_at: null },
                    ],
                    hasChanges: true,
                });
            });
        });

        describe('유효성 검사', () => {
            it('labelId가 없으면 아무 동작도 하지 않아야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: { form: { label_assignments: [] } },
                    params: { field: 'started_at', value: '2025-01-01' },
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
                                { label_id: 1, started_at: null, ended_at: null },
                            ],
                        },
                    },
                    params: { labelId: 999, field: 'started_at', value: '2025-01-01' },
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
                            { label_id: 1, started_at: null, ended_at: null },
                        ],
                    },
                },
                params: { preset: '7d' },
            });

            setLabelDatePresetInlineHandler(action, context);

            expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                'form.label_assignments': [
                    { label_id: 1, started_at: '2025-01-28', ended_at: '2025-02-04' },
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
                            { label_id: 1, started_at: null, ended_at: null },
                        ],
                    },
                },
                params: { preset: '14d' },
            });

            setLabelDatePresetInlineHandler(action, context);

            expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                'form.label_assignments': [
                    { label_id: 1, started_at: '2025-01-28', ended_at: '2025-02-11' },
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
                            { label_id: 1, started_at: null, ended_at: null },
                        ],
                    },
                },
                params: { preset: '30d' },
            });

            setLabelDatePresetInlineHandler(action, context);

            expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                'form.label_assignments': [
                    { label_id: 1, started_at: '2025-01-28', ended_at: '2025-02-27' },
                ],
                hasChanges: true,
            });
        });

        it('permanent 프리셋: started_at만 설정하고 ended_at는 null이어야 한다', () => {
            const { action, context, g7CoreMock } = createMockSetup({
                localState: {
                    ui: { lastClickedLabelId: 1 },
                    form: {
                        label_assignments: [
                            { label_id: 1, started_at: null, ended_at: null },
                        ],
                    },
                },
                params: { preset: 'permanent' },
            });

            setLabelDatePresetInlineHandler(action, context);

            expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                'form.label_assignments': [
                    { label_id: 1, started_at: '2025-01-28', ended_at: null },
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
                            { label_id: 1, started_at: null, ended_at: null },
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
        it('부모 레이아웃의 label_assignments 에서 라벨을 제거해야 한다', () => {
            // 모달 partial 분리 → 모달은 isolated scope, 부모 레이아웃의 _local 을
            // 직접 수정해야 하므로 setParentLocal/getParent API 사용으로 변경됨
            const parentLocal = {
                form: {
                    label_assignments: [
                        { label_id: 1, started_at: '2025-01-01', ended_at: '2025-12-31' },
                        { label_id: 2, started_at: null, ended_at: null },
                    ],
                },
            };
            const g7CoreMock = {
                state: {
                    get: vi.fn(() => ({ labelToUncheckId: 1 })),
                    getParent: vi.fn(() => ({ _local: parentLocal })),
                    setParentLocal: vi.fn(),
                    setGlobal: vi.fn(),
                },
                modal: { close: vi.fn() },
            };
            (window as any).G7Core = g7CoreMock;

            confirmUncheckLabelHandler({ handler: 'confirmUncheckLabel' }, {} as any);

            expect(g7CoreMock.state.setParentLocal).toHaveBeenCalledWith({
                'form.label_assignments': [
                    { label_id: 2, started_at: null, ended_at: null },
                ],
                hasChanges: true,
            });
            expect(g7CoreMock.state.setGlobal).toHaveBeenCalledWith({ labelToUncheckId: null });
            expect(g7CoreMock.modal.close).toHaveBeenCalled();
        });

        it('labelToUncheckId 가 없으면 아무 동작도 하지 않아야 한다', () => {
            const g7CoreMock = {
                state: {
                    get: vi.fn(() => ({ labelToUncheckId: null })),
                    getParent: vi.fn(() => ({ _local: {} })),
                    setParentLocal: vi.fn(),
                    setGlobal: vi.fn(),
                },
                modal: { close: vi.fn() },
            };
            (window as any).G7Core = g7CoreMock;

            confirmUncheckLabelHandler({ handler: 'confirmUncheckLabel' }, {} as any);

            expect(g7CoreMock.state.setParentLocal).not.toHaveBeenCalled();
            expect(g7CoreMock.modal.close).not.toHaveBeenCalled();
        });
    });
});
