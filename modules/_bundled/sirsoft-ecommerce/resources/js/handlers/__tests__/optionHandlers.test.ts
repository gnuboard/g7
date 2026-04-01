/**
 * optionHandlers 테스트
 *
 * 특히 다국어 필드(name) 처리 시 spread 연산자 타입 오류 케이스를 검증합니다.
 * 참고: .claude/history/20260122_1128_옵션일괄생성_타입검사_추가수정.md
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import {
    addOptionInputHandler,
    updateOptionInputHandler,
    generateOptionsHandler,
} from '../optionHandlers';
import type { ActionContext } from '../../types';

// G7Core Mock 설정
const createMockG7Core = (initialState = {}) => {
    let localState = { ...initialState };

    return {
        state: {
            getLocal: vi.fn(() => localState),
            setLocal: vi.fn((newState) => {
                localState = { ...localState, ...newState };
            }),
            get: vi.fn(() => ({})),
        },
        toast: {
            success: vi.fn(),
            error: vi.fn(),
            warning: vi.fn(),
        },
        // 다국어 번역 함수 mock - 키를 그대로 반환 (폴백 테스트용)
        t: vi.fn((key: string, _params?: Record<string, any>) => key),
        config: vi.fn((key: string) => {
            if (key === 'app.supported_locales') return ['ko', 'en'];
            if (key === 'app.locale') return 'ko';
            return undefined;
        }),
        createLogger: vi.fn(() => ({
            log: vi.fn(),
            warn: vi.fn(),
            error: vi.fn(),
        })),
    };
};

describe('optionHandlers', () => {
    let mockG7Core: ReturnType<typeof createMockG7Core>;
    let mockContext: ActionContext;

    beforeEach(() => {
        mockG7Core = createMockG7Core();
        (window as any).G7Core = mockG7Core;
        mockContext = {};
    });

    describe('addOptionInputHandler', () => {
        it('빈 다국어 객체로 name을 초기화해야 한다', () => {
            mockG7Core = createMockG7Core({ ui: { optionInputs: [] } });
            (window as any).G7Core = mockG7Core;

            addOptionInputHandler({}, mockContext);

            expect(mockG7Core.state.setLocal).toHaveBeenCalled();
            const setLocalCall = mockG7Core.state.setLocal.mock.calls[0][0];
            const newInput = setLocalCall.ui.optionInputs[0];

            // name이 객체 형태인지 확인
            expect(typeof newInput.name).toBe('object');
            expect(newInput.name).toHaveProperty('ko');
            expect(newInput.name).toHaveProperty('en');
            expect(newInput.name.ko).toBe('');
            expect(newInput.name.en).toBe('');
        });

        it('최대 3개까지만 추가 가능해야 한다', () => {
            mockG7Core = createMockG7Core({
                ui: {
                    optionInputs: [
                        { name: { ko: '1', en: '' }, values: [] },
                        { name: { ko: '2', en: '' }, values: [] },
                        { name: { ko: '3', en: '' }, values: [] },
                    ],
                },
            });
            (window as any).G7Core = mockG7Core;

            addOptionInputHandler({}, mockContext);

            expect(mockG7Core.toast.warning).toHaveBeenCalledWith('sirsoft-ecommerce.admin.product.options.messages.input_max_3');
            expect(mockG7Core.state.setLocal).not.toHaveBeenCalled();
        });
    });

    describe('updateOptionInputHandler - spread 연산자 타입 안전성', () => {
        /**
         * 핵심 버그 케이스: spread 연산자가 문자열에 적용되면 문자를 분해함
         *
         * 예: { ..."테스트" } → { '0': '테', '1': '스', '2': '트' }
         *
         * 이 테스트는 해당 버그가 수정되었는지 검증합니다.
         */
        it('name이 문자열일 때 문자 분해 없이 객체로 변환해야 한다', () => {
            // 문제 상황: name이 이미 문자열로 저장된 경우
            mockG7Core = createMockG7Core({
                ui: {
                    optionInputs: [
                        { name: '기존문자열', values: [] }, // ← 문자열 타입!
                    ],
                },
            });
            (window as any).G7Core = mockG7Core;

            updateOptionInputHandler(
                { params: { index: 0, field: 'name', value: '새값' } },
                mockContext
            );

            const setLocalCall = mockG7Core.state.setLocal.mock.calls[0][0];
            const updatedName = setLocalCall.ui.optionInputs[0].name;

            // 핵심 검증: 문자 분해가 발생하지 않아야 함
            expect(updatedName).not.toHaveProperty('0'); // 문자 분해 시 '0': '새' 같은 키 생성
            expect(updatedName).not.toHaveProperty('1');

            // 올바른 다국어 객체 구조여야 함
            expect(typeof updatedName).toBe('object');
            expect(updatedName.ko).toBe('새값');
            expect(updatedName).toHaveProperty('en');
        });

        it('name이 객체일 때 정상적으로 업데이트해야 한다', () => {
            mockG7Core = createMockG7Core({
                ui: {
                    optionInputs: [
                        { name: { ko: '기존값', en: 'existing' }, values: [] },
                    ],
                },
            });
            (window as any).G7Core = mockG7Core;

            updateOptionInputHandler(
                { params: { index: 0, field: 'name', value: '새값' } },
                mockContext
            );

            const setLocalCall = mockG7Core.state.setLocal.mock.calls[0][0];
            const updatedName = setLocalCall.ui.optionInputs[0].name;

            expect(updatedName.ko).toBe('새값');
            expect(updatedName.en).toBe('existing'); // 다른 로케일 값 유지
        });

        it('name이 undefined일 때 새 객체를 생성해야 한다', () => {
            mockG7Core = createMockG7Core({
                ui: {
                    optionInputs: [
                        { name: undefined, values: [] },
                    ],
                },
            });
            (window as any).G7Core = mockG7Core;

            updateOptionInputHandler(
                { params: { index: 0, field: 'name', value: '새값' } },
                mockContext
            );

            const setLocalCall = mockG7Core.state.setLocal.mock.calls[0][0];
            const updatedName = setLocalCall.ui.optionInputs[0].name;

            expect(typeof updatedName).toBe('object');
            expect(updatedName.ko).toBe('새값');
        });

        it('name이 배열일 때 새 객체를 생성해야 한다 (배열도 객체이므로 명시적 체크)', () => {
            mockG7Core = createMockG7Core({
                ui: {
                    optionInputs: [
                        { name: ['잘못된', '배열'], values: [] }, // 잘못된 타입
                    ],
                },
            });
            (window as any).G7Core = mockG7Core;

            updateOptionInputHandler(
                { params: { index: 0, field: 'name', value: '새값' } },
                mockContext
            );

            const setLocalCall = mockG7Core.state.setLocal.mock.calls[0][0];
            const updatedName = setLocalCall.ui.optionInputs[0].name;

            // 배열이 아닌 객체여야 함
            expect(Array.isArray(updatedName)).toBe(false);
            expect(typeof updatedName).toBe('object');
            expect(updatedName.ko).toBe('새값');
        });

        it('values 필드 업데이트는 영향받지 않아야 한다', () => {
            mockG7Core = createMockG7Core({
                ui: {
                    optionInputs: [
                        { name: { ko: '색상', en: '' }, values: ['빨강'] },
                    ],
                },
            });
            (window as any).G7Core = mockG7Core;

            updateOptionInputHandler(
                { params: { index: 0, field: 'values', value: ['빨강', '파랑'] } },
                mockContext
            );

            const setLocalCall = mockG7Core.state.setLocal.mock.calls[0][0];
            const updatedInput = setLocalCall.ui.optionInputs[0];

            expect(updatedInput.values).toEqual(['빨강', '파랑']);
            expect(updatedInput.name).toEqual({ ko: '색상', en: '' }); // name은 변경되지 않음
        });
    });

    describe('generateOptionsHandler - 문자열/객체 호환성', () => {
        it('name이 객체일 때 정상적으로 옵션을 생성해야 한다', () => {
            mockG7Core = createMockG7Core({
                ui: {
                    optionInputs: [
                        { name: { ko: '색상', en: 'Color' }, values: ['빨강', '파랑'] },
                    ],
                },
                form: { options: [] },
            });
            (window as any).G7Core = mockG7Core;
            mockContext = { datasources: { currencies: { data: { list: [] } } } };

            generateOptionsHandler({}, mockContext);

            expect(mockG7Core.toast.success).toHaveBeenCalled();
            expect(mockG7Core.toast.error).not.toHaveBeenCalled();

            const setLocalCall = mockG7Core.state.setLocal.mock.calls[0][0];
            expect(setLocalCall.form.options.length).toBe(2);
            expect(setLocalCall.form.options[0].option_values).toEqual([
                { key: { ko: '색상', en: 'Color' }, value: { ko: '빨강', en: '' } },
            ]);
            expect(setLocalCall.form.options[1].option_values).toEqual([
                { key: { ko: '색상', en: 'Color' }, value: { ko: '파랑', en: '' } },
            ]);
        });

        it('name이 문자열일 때도 정상적으로 옵션을 생성해야 한다 (하위 호환성)', () => {
            mockG7Core = createMockG7Core({
                ui: {
                    optionInputs: [
                        { name: '사이즈', values: ['S', 'M', 'L'] }, // 문자열 name
                    ],
                },
                form: { options: [] },
            });
            (window as any).G7Core = mockG7Core;
            mockContext = { datasources: { currencies: { data: { list: [] } } } };

            generateOptionsHandler({}, mockContext);

            expect(mockG7Core.toast.success).toHaveBeenCalled();
            expect(mockG7Core.toast.error).not.toHaveBeenCalled();

            const setLocalCall = mockG7Core.state.setLocal.mock.calls[0][0];
            expect(setLocalCall.form.options.length).toBe(3);
            expect(setLocalCall.form.options[0].option_values).toEqual([
                { key: { ko: '사이즈', en: '' }, value: { ko: 'S', en: '' } },
            ]);
        });

        it('name이 빈 문자열이면 유효하지 않은 입력으로 처리해야 한다', () => {
            mockG7Core = createMockG7Core({
                ui: {
                    optionInputs: [
                        { name: '', values: ['값1', '값2'] },
                    ],
                },
                form: { options: [] },
            });
            (window as any).G7Core = mockG7Core;
            mockContext = { datasources: { currencies: { data: { list: [] } } } };

            generateOptionsHandler({}, mockContext);

            expect(mockG7Core.toast.error).toHaveBeenCalledWith('sirsoft-ecommerce.admin.product.options.messages.name_value_required');
            expect(mockG7Core.toast.success).not.toHaveBeenCalled();
        });

        it('name 객체의 기본 로케일 값이 빈 문자열이면 유효하지 않은 입력으로 처리해야 한다', () => {
            mockG7Core = createMockG7Core({
                ui: {
                    optionInputs: [
                        { name: { ko: '', en: 'Color' }, values: ['빨강'] }, // ko가 비어있음
                    ],
                },
                form: { options: [] },
            });
            (window as any).G7Core = mockG7Core;
            mockContext = { datasources: { currencies: { data: { list: [] } } } };

            generateOptionsHandler({}, mockContext);

            expect(mockG7Core.toast.error).toHaveBeenCalledWith('sirsoft-ecommerce.admin.product.options.messages.name_value_required');
        });

        it('option_groups에 문자열 name을 객체로 정규화하여 저장해야 한다', () => {
            mockG7Core = createMockG7Core({
                ui: {
                    optionInputs: [
                        { name: '색상', values: ['빨강'] }, // 문자열 name
                    ],
                },
                form: { options: [] },
            });
            (window as any).G7Core = mockG7Core;
            mockContext = { datasources: { currencies: { data: { list: [] } } } };

            generateOptionsHandler({}, mockContext);

            const setLocalCall = mockG7Core.state.setLocal.mock.calls[0][0];
            const optionGroup = setLocalCall.form.option_groups[0];

            // 문자열이 객체로 정규화되어야 함
            expect(typeof optionGroup.name).toBe('object');
            expect(optionGroup.name.ko).toBe('색상');
            expect(optionGroup.name).toHaveProperty('en');
        });

        it('여러 옵션 그룹으로 카테시안 곱을 생성해야 한다', () => {
            mockG7Core = createMockG7Core({
                ui: {
                    optionInputs: [
                        { name: { ko: '색상', en: '' }, values: ['빨강', '파랑'] },
                        { name: { ko: '사이즈', en: '' }, values: ['S', 'M'] },
                    ],
                },
                form: { options: [] },
            });
            (window as any).G7Core = mockG7Core;
            mockContext = { datasources: { currencies: { data: { list: [] } } } };

            generateOptionsHandler({}, mockContext);

            const setLocalCall = mockG7Core.state.setLocal.mock.calls[0][0];
            // 2 * 2 = 4개 조합
            expect(setLocalCall.form.options.length).toBe(4);
        });
    });

    describe('회귀 테스트 - spread 연산자 문자열 분해 버그', () => {
        /**
         * 실제 버그 시나리오 재현:
         * 1. Input에 "테스트" 입력
         * 2. 레이아웃이 name을 문자열로 저장 → name: "테스트"
         * 3. 핸들러에서 {...name}으로 spread → {'0':'테','1':'스','2':'트'}
         * 4. name.ko가 undefined → 유효성 검증 실패
         */
        it('실제 버그 시나리오: 문자열 name → spread → 유효성 검증이 정상 동작해야 한다', () => {
            // Step 1: 초기 상태 (빈 객체로 시작)
            mockG7Core = createMockG7Core({
                ui: { optionInputs: [{ name: { ko: '', en: '' }, values: [] }] },
                form: { options: [] },
            });
            (window as any).G7Core = mockG7Core;

            // Step 2: 사용자가 옵션명 입력 (레이아웃에서 문자열로 잘못 저장되는 상황 시뮬레이션)
            // 먼저 문자열로 name을 덮어씀 (버그 상황)
            const stateAfterBadInput = {
                ui: { optionInputs: [{ name: '색상', values: ['빨강', '파랑'] }] },
                form: { options: [] },
            };
            mockG7Core = createMockG7Core(stateAfterBadInput);
            (window as any).G7Core = mockG7Core;

            // Step 3: 핸들러를 통해 다시 name 업데이트
            updateOptionInputHandler(
                { params: { index: 0, field: 'name', value: '색상' } },
                mockContext
            );

            // Step 4: 옵션 생성 시도
            mockContext = { datasources: { currencies: { data: { list: [] } } } };
            generateOptionsHandler({}, mockContext);

            // 핵심 검증: 에러 없이 옵션이 생성되어야 함
            expect(mockG7Core.toast.error).not.toHaveBeenCalled();
            expect(mockG7Core.toast.success).toHaveBeenCalled();
        });
    });
});
