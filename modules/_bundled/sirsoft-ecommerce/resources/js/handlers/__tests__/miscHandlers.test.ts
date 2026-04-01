/**
 * miscHandlers 테스트
 *
 * @description
 * - setLabelDatePresetHandler: 라벨 기간 프리셋(7d, 14d, 30d, 영구) 적용
 * - setDefaultShippingPolicyHandler: 기본 배송정책 자동 설정
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import {
    setLabelDatePresetHandler,
    setDefaultShippingPolicyHandler,
    toggleDefaultShippingPolicyHandler,
} from '../miscHandlers';

/**
 * G7Core Mock 및 Action/Context 생성 함수
 */
const createMockSetup = (overrides?: {
    localState?: Record<string, any>;
    globalState?: Record<string, any>;
    params?: Record<string, any>;
    datasources?: Record<string, any>;
}): { action: any; context: any; g7CoreMock: any } => {
    const localState: Record<string, any> = overrides?.localState ? { ...overrides.localState } : {};
    const globalState: Record<string, any> = overrides?.globalState ? { ...overrides.globalState } : {};

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
        // G7Core.dataSource API mock (핸들러에서 init_actions 호출 시 context.datasources가 비어있으므로 G7Core.dataSource 사용)
        dataSource: {
            get: vi.fn((id: string) => {
                return overrides?.datasources?.[id];
            }),
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
            },
            datasources: overrides?.datasources ?? {},
        },
        g7CoreMock,
    };
};

describe('miscHandlers', () => {
    afterEach(() => {
        vi.clearAllMocks();
        delete (window as any).G7Core;
    });

    describe('setLabelDatePresetHandler', () => {
        beforeEach(() => {
            // 날짜 고정 (2026-01-28)
            vi.useFakeTimers();
            vi.setSystemTime(new Date('2026-01-28'));
        });

        afterEach(() => {
            vi.useRealTimers();
        });

        describe('프리셋 적용', () => {
            it('7d 프리셋: 오늘부터 7일 후까지 설정해야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    params: { preset: '7d' },
                });

                setLabelDatePresetHandler(action, context);

                expect(g7CoreMock.state.setGlobal).toHaveBeenCalledWith({
                    'labelFormData.start_date': '2026-01-28',
                    'labelFormData.end_date': '2026-02-04',
                });
            });

            it('14d 프리셋: 오늘부터 14일 후까지 설정해야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    params: { preset: '14d' },
                });

                setLabelDatePresetHandler(action, context);

                expect(g7CoreMock.state.setGlobal).toHaveBeenCalledWith({
                    'labelFormData.start_date': '2026-01-28',
                    'labelFormData.end_date': '2026-02-11',
                });
            });

            it('30d 프리셋: 오늘부터 30일 후까지 설정해야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    params: { preset: '30d' },
                });

                setLabelDatePresetHandler(action, context);

                expect(g7CoreMock.state.setGlobal).toHaveBeenCalledWith({
                    'labelFormData.start_date': '2026-01-28',
                    'labelFormData.end_date': '2026-02-27',
                });
            });

            it('permanent 프리셋: 종료일을 null로 설정해야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    params: { preset: 'permanent' },
                });

                setLabelDatePresetHandler(action, context);

                expect(g7CoreMock.state.setGlobal).toHaveBeenCalledWith({
                    'labelFormData.start_date': '2026-01-28',
                    'labelFormData.end_date': null,
                });
            });
        });

        describe('유효성 검사', () => {
            it('preset 파라미터가 없으면 아무 동작도 하지 않아야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    params: {},
                });

                setLabelDatePresetHandler(action, context);

                expect(g7CoreMock.state.setGlobal).not.toHaveBeenCalled();
            });

            it('알 수 없는 preset이면 아무 동작도 하지 않아야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    params: { preset: 'unknown' },
                });

                setLabelDatePresetHandler(action, context);

                expect(g7CoreMock.state.setGlobal).not.toHaveBeenCalled();
            });

            it('G7Core.state가 없으면 아무 동작도 하지 않아야 한다', () => {
                const { action, context } = createMockSetup({
                    params: { preset: '7d' },
                });

                delete (window as any).G7Core.state;

                // 에러 없이 종료해야 함
                expect(() => setLabelDatePresetHandler(action, context)).not.toThrow();
            });
        });

        describe('날짜 형식', () => {
            it('날짜는 YYYY-MM-DD 형식이어야 한다', () => {
                // 한 자리 수 월/일 테스트를 위해 2026-01-05로 설정
                vi.setSystemTime(new Date('2026-01-05'));

                const { action, context, g7CoreMock } = createMockSetup({
                    params: { preset: '7d' },
                });

                setLabelDatePresetHandler(action, context);

                expect(g7CoreMock.state.setGlobal).toHaveBeenCalledWith({
                    'labelFormData.start_date': '2026-01-05',
                    'labelFormData.end_date': '2026-01-12',
                });
            });
        });
    });

    describe('setDefaultShippingPolicyHandler', () => {
        describe('기본 배송정책 설정', () => {
            it('is_default=true인 정책을 form에 설정해야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {},
                    },
                    datasources: {
                        shipping_policies: {
                            data: {
                                data: [
                                    { id: 1, name: '일반배송', is_default: false },
                                    { id: 2, name: '무료배송', is_default: true },
                                    { id: 3, name: '퀵배송', is_default: false },
                                ],
                            },
                        },
                    },
                });

                setDefaultShippingPolicyHandler(action, context);

                expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                    form: {
                        shipping_policy_id: 2,
                    },
                });
            });

            it('기본 정책이 없으면 아무것도 설정하지 않아야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {},
                    },
                    datasources: {
                        shipping_policies: {
                            data: {
                                data: [
                                    { id: 1, name: '일반배송', is_default: false },
                                    { id: 2, name: '무료배송', is_default: false },
                                ],
                            },
                        },
                    },
                });

                setDefaultShippingPolicyHandler(action, context);

                expect(g7CoreMock.state.setLocal).not.toHaveBeenCalled();
            });

            it('정책 목록이 비어있으면 아무것도 설정하지 않아야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {},
                    },
                    datasources: {
                        shipping_policies: {
                            data: {
                                data: [],
                            },
                        },
                    },
                });

                setDefaultShippingPolicyHandler(action, context);

                expect(g7CoreMock.state.setLocal).not.toHaveBeenCalled();
            });
        });

        describe('수정 모드 처리', () => {
            it('이미 배송정책이 설정된 경우 덮어쓰지 않아야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {
                            shipping_policy_id: 3,
                        },
                    },
                    datasources: {
                        shipping_policies: {
                            data: {
                                data: [
                                    { id: 1, name: '일반배송', is_default: false },
                                    { id: 2, name: '무료배송', is_default: true },
                                ],
                            },
                        },
                    },
                });

                setDefaultShippingPolicyHandler(action, context);

                // 기존 값 유지 (setLocal 호출 안 함)
                expect(g7CoreMock.state.setLocal).not.toHaveBeenCalled();
            });
        });

        describe('유효성 검사', () => {
            it('G7Core.state가 없으면 아무 동작도 하지 않아야 한다', () => {
                const { action, context } = createMockSetup({
                    localState: { form: {} },
                    datasources: {
                        shipping_policies: {
                            data: {
                                data: [{ id: 1, name: '배송', is_default: true }],
                            },
                        },
                    },
                });

                delete (window as any).G7Core.state;

                expect(() => setDefaultShippingPolicyHandler(action, context)).not.toThrow();
            });
        });

        describe('useDefaultShippingPolicy 상태 확인', () => {
            it('useDefaultShippingPolicy가 false이면 기본 배송정책을 설정하지 않아야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {},
                        ui: {
                            useDefaultShippingPolicy: false,
                        },
                    },
                    datasources: {
                        shipping_policies: {
                            data: {
                                data: [
                                    { id: 1, name: '일반배송', is_default: false },
                                    { id: 2, name: '무료배송', is_default: true },
                                ],
                            },
                        },
                    },
                });

                setDefaultShippingPolicyHandler(action, context);

                // useDefaultShippingPolicy가 false이므로 설정하지 않음
                expect(g7CoreMock.state.setLocal).not.toHaveBeenCalled();
            });

            it('useDefaultShippingPolicy가 undefined(기본값)이면 기본 배송정책을 설정해야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {},
                        ui: {},
                    },
                    datasources: {
                        shipping_policies: {
                            data: {
                                data: [
                                    { id: 1, name: '일반배송', is_default: false },
                                    { id: 2, name: '무료배송', is_default: true },
                                ],
                            },
                        },
                    },
                });

                setDefaultShippingPolicyHandler(action, context);

                expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                    form: {
                        shipping_policy_id: 2,
                    },
                });
            });

            it('useDefaultShippingPolicy가 true이면 기본 배송정책을 설정해야 한다', () => {
                const { action, context, g7CoreMock } = createMockSetup({
                    localState: {
                        form: {},
                        ui: {
                            useDefaultShippingPolicy: true,
                        },
                    },
                    datasources: {
                        shipping_policies: {
                            data: {
                                data: [
                                    { id: 1, name: '일반배송', is_default: false },
                                    { id: 2, name: '무료배송', is_default: true },
                                ],
                            },
                        },
                    },
                });

                setDefaultShippingPolicyHandler(action, context);

                expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                    form: {
                        shipping_policy_id: 2,
                    },
                });
            });
        });
    });

    describe('toggleDefaultShippingPolicyHandler', () => {
        describe('토글 ON', () => {
            it('기본 배송정책을 선택해야 한다', () => {
                const localState: Record<string, any> = {
                    form: { shipping_policy_id: null },
                    ui: { useDefaultShippingPolicy: false },
                };

                const g7CoreMock = {
                    state: {
                        getLocal: vi.fn(() => localState),
                        setLocal: vi.fn((updates: Record<string, any>) => {
                            Object.assign(localState, updates);
                        }),
                    },
                    dataSource: {
                        get: vi.fn(() => ({
                            data: {
                                data: [
                                    { id: 1, name: '일반배송', is_default: false },
                                    { id: 2, name: '무료배송', is_default: true },
                                ],
                            },
                        })),
                    },
                };

                (window as any).G7Core = g7CoreMock;

                const action = {
                    handler: 'toggleDefaultShippingPolicy',
                    params: { checked: true },
                };

                toggleDefaultShippingPolicyHandler(action, {} as any);

                expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                    ui: { useDefaultShippingPolicy: true },
                    form: { shipping_policy_id: 2 },
                });
            });

            it('기본 배송정책이 없으면 null로 설정해야 한다', () => {
                const localState: Record<string, any> = {
                    form: { shipping_policy_id: null },
                    ui: { useDefaultShippingPolicy: false },
                };

                const g7CoreMock = {
                    state: {
                        getLocal: vi.fn(() => localState),
                        setLocal: vi.fn((updates: Record<string, any>) => {
                            Object.assign(localState, updates);
                        }),
                    },
                    dataSource: {
                        get: vi.fn(() => ({
                            data: {
                                data: [
                                    { id: 1, name: '일반배송', is_default: false },
                                    { id: 2, name: '무료배송', is_default: false },
                                ],
                            },
                        })),
                    },
                };

                (window as any).G7Core = g7CoreMock;

                const action = {
                    handler: 'toggleDefaultShippingPolicy',
                    params: { checked: true },
                };

                toggleDefaultShippingPolicyHandler(action, {} as any);

                expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                    ui: { useDefaultShippingPolicy: true },
                    form: { shipping_policy_id: null },
                });
            });
        });

        describe('토글 OFF', () => {
            it('기존 배송정책을 유지해야 한다', () => {
                const localState: Record<string, any> = {
                    form: { shipping_policy_id: 2 },
                    ui: { useDefaultShippingPolicy: true },
                };

                const g7CoreMock = {
                    state: {
                        getLocal: vi.fn(() => localState),
                        setLocal: vi.fn((updates: Record<string, any>) => {
                            Object.assign(localState, updates);
                        }),
                    },
                    dataSource: {
                        get: vi.fn(() => ({})),
                    },
                };

                (window as any).G7Core = g7CoreMock;

                const action = {
                    handler: 'toggleDefaultShippingPolicy',
                    params: { checked: false },
                };

                toggleDefaultShippingPolicyHandler(action, {} as any);

                // 토글 상태만 변경하고, 기존 배송정책(shipping_policy_id)은 유지
                expect(g7CoreMock.state.setLocal).toHaveBeenCalledWith({
                    ui: { useDefaultShippingPolicy: false },
                });
            });
        });

        describe('유효성 검사', () => {
            it('G7Core.state가 없으면 아무 동작도 하지 않아야 한다', () => {
                (window as any).G7Core = {};

                const action = {
                    handler: 'toggleDefaultShippingPolicy',
                    params: { checked: true },
                };

                expect(() => toggleDefaultShippingPolicyHandler(action, {} as any)).not.toThrow();
            });
        });
    });
});
