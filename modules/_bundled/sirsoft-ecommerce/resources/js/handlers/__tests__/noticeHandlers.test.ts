/**
 * noticeHandlers 테스트
 *
 * @description
 * - confirmSelectNoticeTemplateHandler: 템플릿 변경 시 확인 모달 표시/직접 적용 분기
 * - selectNoticeTemplateHandler: 템플릿 선택 및 notice_items 설정
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import {
    confirmSelectNoticeTemplateHandler,
    selectNoticeTemplateHandler,
    fillNoticeItemsWithDetailReferenceHandler,
} from '../noticeHandlers';

/**
 * G7Core Mock 및 Action/Context 생성 함수
 */
const createMockSetup = (overrides?: {
    localState?: Record<string, any>;
    globalState?: Record<string, any>;
    params?: Record<string, any>;
    noticeTemplates?: any[];
}): { action: any; context: any; g7CoreMock: any } => {
    const localState: Record<string, any> = overrides?.localState ? { ...overrides.localState } : {};
    const globalState: Record<string, any> = overrides?.globalState ? { ...overrides.globalState } : {};

    const noticeTemplatesData = overrides?.noticeTemplates ?? [];

    const g7CoreMock = {
        state: {
            getLocal: vi.fn(() => localState),
            setLocal: vi.fn((updates: Record<string, any>) => {
                Object.assign(localState, updates);
            }),
            setGlobal: vi.fn((updates: Record<string, any>) => {
                Object.assign(globalState, updates);
            }),
        },
        dataSource: {
            get: vi.fn((id: string) => {
                if (id === 'notice_templates') {
                    return { data: noticeTemplatesData };
                }
                return null;
            }),
        },
        locale: {
            supported: vi.fn(() => ['ko', 'en']),
        },
        modal: {
            open: vi.fn(),
            close: vi.fn(),
        },
        toast: {
            success: vi.fn(),
            warning: vi.fn(),
            error: vi.fn(),
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
                notice_templates: {
                    data: overrides?.noticeTemplates ?? [],
                },
            },
            datasources: {
                notice_templates: {
                    data: overrides?.noticeTemplates ?? [],
                },
            },
        },
        g7CoreMock,
    };
};

describe('noticeHandlers', () => {
    afterEach(() => {
        vi.clearAllMocks();
        delete (window as any).G7Core;
    });

    describe('confirmSelectNoticeTemplateHandler', () => {
        describe('기존 항목이 있을 때', () => {
            it('값이 입력된 기존 항목이 있으면 확인 모달을 표시해야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            notice_items: [
                                { key: 'field_0', name: { ko: '품명' }, content: { ko: '테스트 상품' } },
                            ],
                        },
                    },
                    params: { templateId: 2 },
                });

                confirmSelectNoticeTemplateHandler(action, context);

                expect(g7CoreMock.state.setGlobal).toHaveBeenCalledWith({
                    pendingNoticeTemplateId: 2,
                });
                expect(g7CoreMock.modal.open).toHaveBeenCalledWith('notice_template_confirm_modal');
                // setLocal은 호출되지 않아야 함 (바로 적용 안 함)
                expect(g7CoreMock.state.setLocal).not.toHaveBeenCalled();
            });

            it('여러 항목 중 하나라도 값이 있으면 확인 모달을 표시해야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            notice_items: [
                                { key: 'field_0', name: { ko: '품명' }, content: { ko: '', en: '' } },
                                { key: 'field_1', name: { ko: '원산지' }, content: { ko: '대한민국', en: '' } },
                            ],
                        },
                    },
                    params: { templateId: 3 },
                });

                confirmSelectNoticeTemplateHandler(action, context);

                expect(g7CoreMock.state.setGlobal).toHaveBeenCalledWith({
                    pendingNoticeTemplateId: 3,
                });
                expect(g7CoreMock.modal.open).toHaveBeenCalledWith('notice_template_confirm_modal');
            });
        });

        describe('기존 항목이 없거나 비어있을 때', () => {
            it('notice_items가 비어있으면 바로 템플릿을 적용해야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            notice_items: [],
                        },
                    },
                    params: { templateId: 2 },
                    noticeTemplates: [
                        { id: 2, name: { ko: '의류' }, fields: [{ name: { ko: '품명' }, content: { ko: '' } }] },
                    ],
                });

                confirmSelectNoticeTemplateHandler(action, context);

                // 확인 모달 없이 바로 setLocal 호출
                expect(g7CoreMock.state.setGlobal).not.toHaveBeenCalled();
                expect(g7CoreMock.modal.open).not.toHaveBeenCalled();
                expect(g7CoreMock.state.setLocal).toHaveBeenCalled();
            });

            it('모든 항목의 content가 빈 문자열이면 바로 템플릿을 적용해야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            notice_items: [
                                { key: 'field_0', name: { ko: '품명' }, content: { ko: '', en: '' } },
                                { key: 'field_1', name: { ko: '원산지' }, content: { ko: '  ', en: '' } }, // 공백만 있는 경우
                            ],
                        },
                    },
                    params: { templateId: 2 },
                    noticeTemplates: [
                        { id: 2, name: { ko: '의류' }, fields: [{ name: { ko: '품명' }, content: { ko: '' } }] },
                    ],
                });

                confirmSelectNoticeTemplateHandler(action, context);

                // 공백만 있는 경우도 비어있는 것으로 처리
                expect(g7CoreMock.state.setGlobal).not.toHaveBeenCalled();
                expect(g7CoreMock.modal.open).not.toHaveBeenCalled();
                expect(g7CoreMock.state.setLocal).toHaveBeenCalled();
            });
        });

        describe('템플릿 해제(null)', () => {
            it('templateId가 null이면 확인 없이 바로 해제해야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            notice_items: [
                                { key: 'field_0', name: { ko: '품명' }, content: { ko: '테스트' } },
                            ],
                        },
                    },
                    params: { templateId: null },
                });

                confirmSelectNoticeTemplateHandler(action, context);

                // 확인 모달 없이 바로 해제
                expect(g7CoreMock.state.setGlobal).not.toHaveBeenCalled();
                expect(g7CoreMock.modal.open).not.toHaveBeenCalled();
                expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                    form: expect.objectContaining({
                        notice_items: [],
                    }),
                    ui: expect.objectContaining({
                        selectedNoticeTemplateId: null,
                    }),
                    hasChanges: true,
                });
            });
        });

        describe('유효성 검사', () => {
            it('G7Core.state가 없으면 아무 동작도 하지 않아야 한다', () => {
                const { action, context } = createMockSetup({
                    params: { templateId: 1 },
                });

                delete (window as any).G7Core.state;

                expect(() => confirmSelectNoticeTemplateHandler(action, context)).not.toThrow();
            });
        });
    });

    describe('selectNoticeTemplateHandler', () => {
        describe('템플릿 선택', () => {
            it('선택된 템플릿의 필드로 notice_items를 설정해야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {},
                    },
                    params: { templateId: 1 },
                    noticeTemplates: [
                        {
                            id: 1,
                            name: { ko: '전자제품' },
                            fields: [
                                { name: { ko: '제품명' }, content: { ko: '' } },
                                { name: { ko: '모델명' }, content: { ko: '' } },
                            ],
                        },
                    ],
                });

                selectNoticeTemplateHandler(action, context);

                expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                    form: expect.objectContaining({
                        notice_items: [
                            expect.objectContaining({ name: { ko: '제품명' } }),
                            expect.objectContaining({ name: { ko: '모델명' } }),
                        ],
                    }),
                    ui: expect.objectContaining({
                        selectedNoticeTemplateId: 1,
                    }),
                    hasChanges: true,
                });
            });

            it('템플릿 해제 시 notice_items를 빈 배열로 설정해야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            notice_items: [{ key: 'field_0', name: { ko: '품명' }, content: { ko: '' } }],
                        },
                        ui: {
                            selectedNoticeTemplateId: 1,
                        },
                    },
                    params: { templateId: null },
                });

                selectNoticeTemplateHandler(action, context);

                expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                    form: expect.objectContaining({
                        notice_items: [],
                    }),
                    ui: expect.objectContaining({
                        selectedNoticeTemplateId: null,
                    }),
                    hasChanges: true,
                });
            });

            it('존재하지 않는 templateId는 경고 로그를 출력하고 아무것도 하지 않아야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {},
                    },
                    params: { templateId: 999 },
                    noticeTemplates: [
                        { id: 1, name: { ko: '전자제품' }, fields: [] },
                    ],
                });

                selectNoticeTemplateHandler(action, context);

                expect(g7CoreMock.state.setLocal).not.toHaveBeenCalled();
            });

            it('템플릿 선택 시 notice_items에 content 필드가 설정되어야 한다 (value가 아닌 content)', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {},
                    },
                    params: { templateId: 1 },
                    noticeTemplates: [
                        {
                            id: 1,
                            name: { ko: '의류' },
                            fields: [
                                { name: { ko: '소재' }, content: { ko: '면 100%' } },
                            ],
                        },
                    ],
                });

                selectNoticeTemplateHandler(action, context);

                const setLocalCall = g7CoreMock.state.setLocal.mock.calls[0][0];
                const firstItem = setLocalCall.form.notice_items[0];

                // content 필드가 존재해야 함
                expect(firstItem).toHaveProperty('content');
                expect(firstItem.content).toEqual({ ko: '면 100%' });
                // value 필드가 존재하면 안 됨
                expect(firstItem).not.toHaveProperty('value');
            });

            it('기존 항목의 content 값을 유지하면서 새 템플릿을 적용해야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            notice_items: [
                                { key: 'field_0', name: { ko: '소재' }, content: { ko: '기존 값' } },
                            ],
                        },
                    },
                    params: { templateId: 1 },
                    noticeTemplates: [
                        {
                            id: 1,
                            name: { ko: '의류' },
                            fields: [
                                { name: { ko: '소재' }, content: { ko: '면 100%' } },
                                { name: { ko: '색상' }, content: { ko: '' } },
                            ],
                        },
                    ],
                });

                selectNoticeTemplateHandler(action, context);

                const setLocalCall = g7CoreMock.state.setLocal.mock.calls[0][0];
                const items = setLocalCall.form.notice_items;

                // 기존 값이 있는 항목은 기존 content 유지
                expect(items[0].content).toEqual({ ko: '기존 값' });
                // 새 항목은 템플릿 기본값 적용
                expect(items[1].content).toEqual({ ko: '' });
            });
        });
    });

    describe('fillNoticeItemsWithDetailReferenceHandler', () => {
        it('모든 notice_items의 content를 상세설명참조로 변경해야 한다', () => {
            const noticeItems = [
                { key: 'field_0', name: { ko: '품명' }, content: { ko: '기존값1' }, sort_order: 0 },
                { key: 'field_1', name: { ko: '원산지' }, content: { ko: '기존값2' }, sort_order: 1 },
            ];

            const { g7CoreMock } = createMockSetup({
                localState: {
                    form: { notice_items: noticeItems },
                },
            });

            const action = {
                handler: 'fillNoticeItemsWithDetailReference',
                params: { form: { notice_items: noticeItems } },
            };

            fillNoticeItemsWithDetailReferenceHandler(action, {} as any);

            expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                'form.notice_items': [
                    expect.objectContaining({ content: { ko: '상세설명참조', en: 'See detail description' } }),
                    expect.objectContaining({ content: { ko: '상세설명참조', en: 'See detail description' } }),
                ],
            });
        });

        it('항목이 없으면 경고 토스트를 표시하고 상태를 변경하지 않아야 한다', () => {
            const { g7CoreMock } = createMockSetup({
                localState: {
                    form: { notice_items: [] },
                },
            });

            const action = {
                handler: 'fillNoticeItemsWithDetailReference',
                params: { form: { notice_items: [] } },
            };

            fillNoticeItemsWithDetailReferenceHandler(action, {} as any);

            expect(g7CoreMock.toast.warning).toHaveBeenCalled();
            expect(g7CoreMock.state.setLocal).not.toHaveBeenCalled();
        });
    });
});
