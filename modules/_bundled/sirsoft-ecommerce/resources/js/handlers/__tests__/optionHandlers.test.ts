/**
 * optionHandlers 테스트
 *
 * 특히 다국어 필드(name) 처리 시 spread 연산자 타입 오류 케이스를 검증합니다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import {
    addOptionInputHandler,
    updateOptionInputHandler,
    generateOptionsHandler,
    addAdditionalOptionHandler,
    clearAdditionalOptionsHandler,
    addAdditionalOptionValueHandler,
    updateAdditionalOptionValueHandler,
    removeAdditionalOptionValueHandler,
    deleteOptionHandler,
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

    describe('generateOptionsHandler - 신규 등록 시 재고 기본값 1', () => {
        const buildInputs = () => ({
            ui: { optionInputs: [{ name: { ko: '색상', en: '' }, values: ['빨강', '파랑'] }] },
            form: { options: [] },
        });

        it('등록 모드(isCreate=true)에서는 생성된 모든 옵션의 재고가 1이다', () => {
            mockG7Core = createMockG7Core(buildInputs());
            (window as any).G7Core = mockG7Core;
            mockContext = { datasources: { currencies: { data: { list: [] } } } };

            generateOptionsHandler({ params: { isCreate: true } }, mockContext);

            const setLocalCall = mockG7Core.state.setLocal.mock.calls[0][0];
            expect(setLocalCall.form.options.length).toBe(2);
            setLocalCall.form.options.forEach((opt: any) => {
                expect(opt.stock_quantity).toBe(1);
            });
        });

        it('isCreate가 문자열 "true"여도 재고 기본값은 1이다 (표현식 boolean 캐스팅 호환)', () => {
            mockG7Core = createMockG7Core(buildInputs());
            (window as any).G7Core = mockG7Core;
            mockContext = { datasources: { currencies: { data: { list: [] } } } };

            generateOptionsHandler({ params: { isCreate: 'true' } }, mockContext);

            const setLocalCall = mockG7Core.state.setLocal.mock.calls[0][0];
            setLocalCall.form.options.forEach((opt: any) => {
                expect(opt.stock_quantity).toBe(1);
            });
        });

        it('수정 모드(isCreate 미전달)에서는 재고 기본값이 0이다 (기존 동작 유지)', () => {
            mockG7Core = createMockG7Core(buildInputs());
            (window as any).G7Core = mockG7Core;
            mockContext = { datasources: { currencies: { data: { list: [] } } } };

            generateOptionsHandler({}, mockContext);

            const setLocalCall = mockG7Core.state.setLocal.mock.calls[0][0];
            setLocalCall.form.options.forEach((opt: any) => {
                expect(opt.stock_quantity).toBe(0);
            });
        });

        it('등록 모드에서 옵션 2개 생성 시 상품 재고가 옵션 합계(2)로 동기화된다', () => {
            mockG7Core = createMockG7Core(buildInputs());
            (window as any).G7Core = mockG7Core;
            mockContext = { datasources: { currencies: { data: { list: [] } } } };

            generateOptionsHandler({ params: { isCreate: true } }, mockContext);

            const setLocalCall = mockG7Core.state.setLocal.mock.calls[0][0];
            // 옵션당 기본 재고 1 × 2개 = 상품 재고 2 (사용자 조작 없이 즉시)
            expect(setLocalCall.form.stock_quantity).toBe(2);
        });
    });

    describe('deleteOptionHandler - 삭제 시 상품 재고 재동기화', () => {
        const buildOptions = () => ({
            form: {
                stock_quantity: 3,
                has_options: true,
                options: [
                    { option_code: 'OPT-001', stock_quantity: 1, is_active: true, is_default: true },
                    { option_code: 'OPT-002', stock_quantity: 2, is_active: true },
                ],
            },
        });

        it('옵션 삭제 시 상품 재고가 남은 활성 옵션 합계로 재계산된다', () => {
            mockG7Core = createMockG7Core(buildOptions());
            (window as any).G7Core = mockG7Core;

            // 재고 2짜리 두 번째 옵션 삭제 → 남은 재고 1
            deleteOptionHandler({ params: { rowIndex: 1 } }, mockContext);

            const setLocalCall = mockG7Core.state.setLocal.mock.calls[0][0];
            expect(setLocalCall.form.options.length).toBe(1);
            expect(setLocalCall.form.stock_quantity).toBe(1);
            expect(setLocalCall.form.has_options).toBe(true);
        });

        it('마지막 옵션까지 삭제하면 has_options 가 false 로 해제된다', () => {
            mockG7Core = createMockG7Core({
                form: {
                    stock_quantity: 1,
                    has_options: true,
                    options: [{ option_code: 'OPT-001', stock_quantity: 1, is_active: true, is_default: true }],
                },
            });
            (window as any).G7Core = mockG7Core;

            deleteOptionHandler({ params: { rowIndex: 0 } }, mockContext);

            const setLocalCall = mockG7Core.state.setLocal.mock.calls[0][0];
            expect(setLocalCall.form.options.length).toBe(0);
            expect(setLocalCall.form.has_options).toBe(false);
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

    describe('clearAdditionalOptionsHandler — §13-D-FAIL 토글 미갱신 회귀', () => {
        it('form 객체를 새 참조로 통째 교체하여 additional_options 를 [] 로 비운다', () => {
            // 기존 1행 보유 상태 (EDIT 폼 진입 시뮬레이션)
            const originalForm = {
                product_name: { ko: '상품', en: 'Product' },
                additional_options: [{ id: 'x1', name: { ko: '각인', en: 'Engrave' }, is_required: false, sort_order: 0 }],
            };
            mockG7Core = createMockG7Core({ form: originalForm });
            (window as any).G7Core = mockG7Core;

            clearAdditionalOptionsHandler({}, mockContext);

            expect(mockG7Core.state.setLocal).toHaveBeenCalled();
            const setLocalCall = mockG7Core.state.setLocal.mock.calls[0][0];
            // 비워짐
            expect(setLocalCall.form.additional_options).toEqual([]);
            // form 은 새 참조 (dot-path 부분 갱신이 아니라 객체 교체 → 파생 표현식 리렌더 보장)
            expect(setLocalCall.form).not.toBe(originalForm);
            // 다른 form 필드는 보존
            expect(setLocalCall.form.product_name).toEqual(originalForm.product_name);
            // 변경 플래그
            expect(setLocalCall.hasChanges).toBe(true);
        });

        it('add 핸들러와 동일한 form-replace 패턴을 사용한다 (참조 교체 일관성)', () => {
            mockG7Core = createMockG7Core({ form: { additional_options: [] } });
            (window as any).G7Core = mockG7Core;
            addAdditionalOptionHandler({}, mockContext);
            const addCall = mockG7Core.state.setLocal.mock.calls[0][0];
            // add 도 form 객체를 새로 만든다 — clear 와 동일 패턴임을 명문화
            expect(addCall.form.additional_options.length).toBe(1);
            expect(addCall.hasChanges).toBe(true);
        });

        it('G7Core.state 부재 시 안전하게 무시한다', () => {
            (window as any).G7Core = {};
            expect(() => clearAdditionalOptionsHandler({}, mockContext)).not.toThrow();
        });
    });

    describe('추가옵션 선택지(values) 핸들러 — 세션 B', () => {
        const groupWith = (values: any[]) => ({
            form: {
                additional_options: [
                    { id: 'g0', name: { ko: '각인', en: 'Engraving' }, is_required: true, sort_order: 0, values },
                ],
            },
        });

        describe('addAdditionalOptionHandler — values 초기화', () => {
            it('새 그룹은 선택지 1개로 시작한다 (빈 그룹 저장 차단 D11)', () => {
                mockG7Core = createMockG7Core({ form: { additional_options: [] } });
                (window as any).G7Core = mockG7Core;

                addAdditionalOptionHandler({}, mockContext);

                const setLocalCall = mockG7Core.state.setLocal.mock.calls[0][0];
                const group = setLocalCall.form.additional_options[0];
                expect(Array.isArray(group.values)).toBe(true);
                expect(group.values.length).toBe(1);
                // 첫 선택지는 기본 선택지 + 활성 + 추가금 0
                expect(group.values[0].is_default).toBe(true);
                expect(group.values[0].is_active).toBe(true);
                expect(group.values[0].price_adjustment).toBe(0);
                expect(group.values[0].name).toHaveProperty('ko');
            });
        });

        describe('addAdditionalOptionValueHandler', () => {
            it('그룹에 선택지를 추가한다', () => {
                mockG7Core = createMockG7Core(groupWith([
                    { id: 'v0', name: { ko: '없음' }, price_adjustment: 0, is_default: true, is_active: true, sort_order: 0 },
                ]));
                (window as any).G7Core = mockG7Core;

                addAdditionalOptionValueHandler({ params: { groupIndex: 0 } }, mockContext);

                const setLocalCall = mockG7Core.state.setLocal.mock.calls[0][0];
                const values = setLocalCall.form.additional_options[0].values;
                expect(values.length).toBe(2);
                // 추가된 선택지는 기본 선택지가 아니다 (sort_order > 0)
                expect(values[1].is_default).toBe(false);
                expect(values[1].sort_order).toBe(1);
            });

            it('그룹당 20개 초과 시 경고하고 추가하지 않는다 (D11)', () => {
                const twenty = Array.from({ length: 20 }, (_, i) => ({
                    id: `v${i}`, name: { ko: `v${i}` }, price_adjustment: 0, is_default: i === 0, is_active: true, sort_order: i,
                }));
                mockG7Core = createMockG7Core(groupWith(twenty));
                (window as any).G7Core = mockG7Core;

                addAdditionalOptionValueHandler({ params: { groupIndex: 0 } }, mockContext);

                expect(mockG7Core.toast.warning).toHaveBeenCalledWith('sirsoft-ecommerce.admin.product.options.messages.additional_value_max_20');
                expect(mockG7Core.state.setLocal).not.toHaveBeenCalled();
            });
        });

        describe('updateAdditionalOptionValueHandler', () => {
            it('name 다국어 객체를 그대로 유지한다 (문자 분해 없음)', () => {
                mockG7Core = createMockG7Core(groupWith([
                    { id: 'v0', name: { ko: '', en: '' }, price_adjustment: 0, is_default: true, is_active: true, sort_order: 0 },
                ]));
                (window as any).G7Core = mockG7Core;

                updateAdditionalOptionValueHandler(
                    { params: { groupIndex: 0, valueIndex: 0, field: 'name', value: { ko: '각인 추가', en: 'Add' } } },
                    mockContext
                );

                const v = mockG7Core.state.setLocal.mock.calls[0][0].form.additional_options[0].values[0];
                expect(v.name).toEqual({ ko: '각인 추가', en: 'Add' });
                expect(v.name).not.toHaveProperty('0');
            });

            it('price_adjustment 음수는 0으로 보정한다 (D16)', () => {
                mockG7Core = createMockG7Core(groupWith([
                    { id: 'v0', name: { ko: 'x' }, price_adjustment: 0, is_default: true, is_active: true, sort_order: 0 },
                ]));
                (window as any).G7Core = mockG7Core;

                updateAdditionalOptionValueHandler(
                    { params: { groupIndex: 0, valueIndex: 0, field: 'price_adjustment', value: '-500' } },
                    mockContext
                );

                const v = mockG7Core.state.setLocal.mock.calls[0][0].form.additional_options[0].values[0];
                expect(v.price_adjustment).toBe(0);
            });

            it('price_adjustment 양수는 숫자로 저장한다', () => {
                mockG7Core = createMockG7Core(groupWith([
                    { id: 'v0', name: { ko: 'x' }, price_adjustment: 0, is_default: true, is_active: true, sort_order: 0 },
                ]));
                (window as any).G7Core = mockG7Core;

                updateAdditionalOptionValueHandler(
                    { params: { groupIndex: 0, valueIndex: 0, field: 'price_adjustment', value: '5000' } },
                    mockContext
                );

                const v = mockG7Core.state.setLocal.mock.calls[0][0].form.additional_options[0].values[0];
                expect(v.price_adjustment).toBe(5000);
            });

            it('is_default 라디오 — 선택 시 그룹 내 다른 선택지는 해제된다', () => {
                mockG7Core = createMockG7Core(groupWith([
                    { id: 'v0', name: { ko: 'a' }, price_adjustment: 0, is_default: true, is_active: true, sort_order: 0 },
                    { id: 'v1', name: { ko: 'b' }, price_adjustment: 0, is_default: false, is_active: true, sort_order: 1 },
                ]));
                (window as any).G7Core = mockG7Core;

                updateAdditionalOptionValueHandler(
                    { params: { groupIndex: 0, valueIndex: 1, field: 'is_default', value: true } },
                    mockContext
                );

                const values = mockG7Core.state.setLocal.mock.calls[0][0].form.additional_options[0].values;
                expect(values[0].is_default).toBe(false);
                expect(values[1].is_default).toBe(true);
            });

            it('is_active 토글을 직접 반영한다', () => {
                mockG7Core = createMockG7Core(groupWith([
                    { id: 'v0', name: { ko: 'a' }, price_adjustment: 0, is_default: true, is_active: true, sort_order: 0 },
                ]));
                (window as any).G7Core = mockG7Core;

                updateAdditionalOptionValueHandler(
                    { params: { groupIndex: 0, valueIndex: 0, field: 'is_active', value: false } },
                    mockContext
                );

                const v = mockG7Core.state.setLocal.mock.calls[0][0].form.additional_options[0].values[0];
                expect(v.is_active).toBe(false);
            });

            it('allow_custom_text 토글을 boolean 으로 반영한다 (직접입력 허용)', () => {
                mockG7Core = createMockG7Core(groupWith([
                    { id: 'v0', name: { ko: 'a' }, price_adjustment: 0, is_default: true, is_active: true, allow_custom_text: false, sort_order: 0 },
                ]));
                (window as any).G7Core = mockG7Core;

                updateAdditionalOptionValueHandler(
                    { params: { groupIndex: 0, valueIndex: 0, field: 'allow_custom_text', value: true } },
                    mockContext
                );

                const v = mockG7Core.state.setLocal.mock.calls[0][0].form.additional_options[0].values[0];
                expect(v.allow_custom_text).toBe(true);
            });
        });

        describe('removeAdditionalOptionValueHandler', () => {
            it('선택지를 삭제하고 sort_order 를 재정렬한다', () => {
                mockG7Core = createMockG7Core(groupWith([
                    { id: 'v0', name: { ko: 'a' }, price_adjustment: 0, is_default: true, is_active: true, sort_order: 0 },
                    { id: 'v1', name: { ko: 'b' }, price_adjustment: 0, is_default: false, is_active: true, sort_order: 1 },
                    { id: 'v2', name: { ko: 'c' }, price_adjustment: 0, is_default: false, is_active: true, sort_order: 2 },
                ]));
                (window as any).G7Core = mockG7Core;

                removeAdditionalOptionValueHandler({ params: { groupIndex: 0, valueIndex: 1 } }, mockContext);

                const values = mockG7Core.state.setLocal.mock.calls[0][0].form.additional_options[0].values;
                expect(values.length).toBe(2);
                expect(values.map((v: any) => v.sort_order)).toEqual([0, 1]);
            });

            it('기본 선택지를 삭제하면 첫 선택지가 기본이 된다', () => {
                mockG7Core = createMockG7Core(groupWith([
                    { id: 'v0', name: { ko: 'a' }, price_adjustment: 0, is_default: true, is_active: true, sort_order: 0 },
                    { id: 'v1', name: { ko: 'b' }, price_adjustment: 0, is_default: false, is_active: true, sort_order: 1 },
                ]));
                (window as any).G7Core = mockG7Core;

                removeAdditionalOptionValueHandler({ params: { groupIndex: 0, valueIndex: 0 } }, mockContext);

                const values = mockG7Core.state.setLocal.mock.calls[0][0].form.additional_options[0].values;
                expect(values.length).toBe(1);
                expect(values[0].is_default).toBe(true);
            });

            it('마지막 선택지 1개는 삭제하지 않고 경고한다 (빈 그룹 차단 D11)', () => {
                mockG7Core = createMockG7Core(groupWith([
                    { id: 'v0', name: { ko: 'a' }, price_adjustment: 0, is_default: true, is_active: true, sort_order: 0 },
                ]));
                (window as any).G7Core = mockG7Core;

                removeAdditionalOptionValueHandler({ params: { groupIndex: 0, valueIndex: 0 } }, mockContext);

                expect(mockG7Core.toast.warning).toHaveBeenCalledWith('sirsoft-ecommerce.admin.product.options.messages.additional_value_min_1');
                expect(mockG7Core.state.setLocal).not.toHaveBeenCalled();
            });
        });
    });
});
