/**
 * generateOptionsHandler 가격 자동입력 테스트
 *
 * @description
 * - 옵션 생성 시 상품의 정가(list_price), 판매가(selling_price) 자동 입력 검증
 * - 다중통화 판매가(multi_currency_selling_price) 자동 입력 검증
 * - 기존 옵션 병합 시 기존 가격 유지 검증
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { generateOptionsHandler } from '../../handlers/optionHandlers';

// G7Core mock
let mockLocalState: Record<string, any> = {};
let mockGlobalState: Record<string, any> = {};

const mockG7Core = {
    state: {
        getLocal: () => mockLocalState,
        get: () => mockGlobalState,
        setLocal: vi.fn((updates: Record<string, any>) => {
            mockLocalState = { ...mockLocalState, ...updates };
        }),
    },
    modal: {
        open: vi.fn(),
    },
    config: (key: string) => {
        if (key === 'app.supported_locales') return ['ko', 'en'];
        if (key === 'app.locale') return 'ko';
        return undefined;
    },
    t: (key: string, params?: Record<string, any>) => {
        if (params?.count) return `${params.count} options have been generated.`;
        return key;
    },
    toast: {
        success: vi.fn(),
        error: vi.fn(),
        warning: vi.fn(),
    },
    createLogger: () => ({
        log: vi.fn(),
        warn: vi.fn(),
        error: vi.fn(),
    }),
};

const mockContext = {} as any;

/**
 * 테스트용 통화 설정
 */
const mockCurrencies = [
    {
        code: 'KRW',
        name: { ko: '원' },
        is_default: true,
        exchange_rate: 1,
        rounding_unit: '1',
        rounding_method: 'round',
        decimal_places: 0,
    },
    {
        code: 'USD',
        name: { ko: '달러' },
        is_default: false,
        exchange_rate: 0.85,
        rounding_unit: '0.01',
        rounding_method: 'round',
        decimal_places: 2,
    },
    {
        code: 'JPY',
        name: { ko: '엔' },
        is_default: false,
        exchange_rate: 115,
        rounding_unit: '1',
        rounding_method: 'floor',
        decimal_places: 0,
    },
];

describe('generateOptionsHandler - 가격 자동입력', () => {
    beforeEach(() => {
        (window as any).G7Core = mockG7Core;

        mockLocalState = {
            form: {
                list_price: 66000,
                selling_price: 53000,
                options: [],
            },
            ui: {
                optionInputs: [
                    {
                        name: { ko: '색상', en: 'Color' },
                        values: [
                            { ko: '빨강', en: 'Red' },
                            { ko: '파랑', en: 'Blue' },
                        ],
                    },
                ],
            },
        };

        mockGlobalState = {
            modules: {
                'sirsoft-ecommerce': {
                    language_currency: {
                        currencies: mockCurrencies,
                    },
                },
            },
        };

        vi.clearAllMocks();
    });

    afterEach(() => {
        delete (window as any).G7Core;
    });

    describe('정가/판매가 자동 입력', () => {
        it('생성된 옵션에 상품의 정가(list_price)가 입력되어야 한다', () => {
            generateOptionsHandler(
                { handler: 'sirsoft-ecommerce.generateOptions' },
                mockContext
            );

            const options = mockLocalState.form.options;
            expect(options.length).toBe(2);
            expect(options[0].list_price).toBe(66000);
            expect(options[1].list_price).toBe(66000);
        });

        it('생성된 옵션에 상품의 판매가(selling_price)가 입력되어야 한다', () => {
            generateOptionsHandler(
                { handler: 'sirsoft-ecommerce.generateOptions' },
                mockContext
            );

            const options = mockLocalState.form.options;
            expect(options[0].selling_price).toBe(53000);
            expect(options[1].selling_price).toBe(53000);
        });

        it('price_adjustment는 0이어야 한다 (상품 가격과 동일하므로)', () => {
            generateOptionsHandler(
                { handler: 'sirsoft-ecommerce.generateOptions' },
                mockContext
            );

            const options = mockLocalState.form.options;
            expect(options[0].price_adjustment).toBe(0);
            expect(options[1].price_adjustment).toBe(0);
        });

        it('상품에 정가/판매가가 없으면 0으로 설정되어야 한다', () => {
            mockLocalState.form.list_price = undefined;
            mockLocalState.form.selling_price = undefined;

            generateOptionsHandler(
                { handler: 'sirsoft-ecommerce.generateOptions' },
                mockContext
            );

            const options = mockLocalState.form.options;
            expect(options[0].list_price).toBe(0);
            expect(options[0].selling_price).toBe(0);
        });
    });

    describe('다중통화 판매가 자동 입력', () => {
        it('상품 판매가 기반으로 다중통화 가격이 자동 계산되어야 한다', () => {
            generateOptionsHandler(
                { handler: 'sirsoft-ecommerce.generateOptions' },
                mockContext
            );

            const options = mockLocalState.form.options;
            const multiCurrency = options[0].multi_currency_selling_price;

            // USD: (53000 / 1000) * 0.85 = 45.05
            expect(multiCurrency.USD.price).toBe(45.05);

            // JPY: (53000 / 1000) * 115 = 6095 (floor)
            expect(multiCurrency.JPY.price).toBe(6095);
        });

        it('다중통화 가격은 { price: number } 객체 구조여야 한다', () => {
            generateOptionsHandler(
                { handler: 'sirsoft-ecommerce.generateOptions' },
                mockContext
            );

            const multiCurrency = mockLocalState.form.options[0].multi_currency_selling_price;
            expect(multiCurrency.USD).toHaveProperty('price');
            expect(typeof multiCurrency.USD.price).toBe('number');
        });

        it('모든 생성된 옵션에 동일한 다중통화 가격이 입력되어야 한다', () => {
            generateOptionsHandler(
                { handler: 'sirsoft-ecommerce.generateOptions' },
                mockContext
            );

            const options = mockLocalState.form.options;
            expect(options[0].multi_currency_selling_price.USD.price)
                .toBe(options[1].multi_currency_selling_price.USD.price);
            expect(options[0].multi_currency_selling_price.JPY.price)
                .toBe(options[1].multi_currency_selling_price.JPY.price);
        });

        it('상품에 multi_currency_selling_price가 설정되어 있으면 그대로 복사되어야 한다', () => {
            mockLocalState.form.multi_currency_selling_price = {
                USD: { price: 50 },
                JPY: { price: 7000 },
            };

            generateOptionsHandler(
                { handler: 'sirsoft-ecommerce.generateOptions' },
                mockContext
            );

            const options = mockLocalState.form.options;
            expect(options[0].multi_currency_selling_price.USD.price).toBe(50);
            expect(options[0].multi_currency_selling_price.JPY.price).toBe(7000);
        });

        it('복사된 multi_currency_selling_price는 독립 객체여야 한다 (참조 공유 방지)', () => {
            mockLocalState.form.multi_currency_selling_price = {
                USD: { price: 50 },
            };

            generateOptionsHandler(
                { handler: 'sirsoft-ecommerce.generateOptions' },
                mockContext
            );

            const options = mockLocalState.form.options;
            // 서로 다른 참조여야 함
            expect(options[0].multi_currency_selling_price).not.toBe(
                options[1].multi_currency_selling_price
            );
        });

        it('판매가가 0이면 다중통화 가격도 빈 객체여야 한다', () => {
            mockLocalState.form.selling_price = 0;
            mockLocalState.form.multi_currency_selling_price = undefined;

            generateOptionsHandler(
                { handler: 'sirsoft-ecommerce.generateOptions' },
                mockContext
            );

            const multiCurrency = mockLocalState.form.options[0].multi_currency_selling_price;
            expect(Object.keys(multiCurrency).length).toBe(0);
        });

        it('환율 설정된 통화가 없으면 빈 객체여야 한다', () => {
            mockGlobalState.modules['sirsoft-ecommerce'].language_currency.currencies = [
                { code: 'KRW', is_default: true, exchange_rate: 1 },
            ];

            generateOptionsHandler(
                { handler: 'sirsoft-ecommerce.generateOptions' },
                mockContext
            );

            const multiCurrency = mockLocalState.form.options[0].multi_currency_selling_price;
            expect(Object.keys(multiCurrency).length).toBe(0);
        });
    });

    describe('기존 옵션 병합 시 가격 유지', () => {
        it('기존 옵션과 동일한 이름의 옵션은 기존 가격을 유지해야 한다', () => {
            // 기존 옵션 (사용자가 가격을 수정한 상태)
            mockLocalState.form.options = [
                {
                    id: 1,
                    option_code: 'OPT-001',
                    option_name: { ko: '빨강', en: 'Red' },
                    option_values: [{ key: { ko: '색상', en: 'Color' }, value: { ko: '빨강', en: 'Red' } }],
                    list_price: 70000,
                    selling_price: 60000,
                    price_adjustment: 7000,
                    multi_currency_selling_price: { USD: { price: 55 } },
                    is_default: true,
                    is_active: true,
                    sku: 'SKU-001',
                    stock_quantity: 10,
                    safe_stock_quantity: 2,
                },
            ];

            generateOptionsHandler(
                { handler: 'sirsoft-ecommerce.generateOptions', params: { skipConfirm: true } },
                mockContext
            );

            const options = mockLocalState.form.options;
            // 기존 옵션 (빨강)은 사용자가 수정한 가격 유지
            const redOption = options.find((opt: any) =>
                opt.option_name?.ko === '빨강'
            );
            expect(redOption.list_price).toBe(70000);
            expect(redOption.selling_price).toBe(60000);
            expect(redOption.multi_currency_selling_price.USD.price).toBe(55);

            // 새 옵션 (파랑)은 상품 가격으로 초기화
            const blueOption = options.find((opt: any) =>
                opt.option_name?.ko === '파랑'
            );
            expect(blueOption.list_price).toBe(66000);
            expect(blueOption.selling_price).toBe(53000);
        });
    });

    describe('다중 옵션 그룹', () => {
        it('2개 옵션 그룹의 카테시안 곱 결과에도 가격이 자동 입력되어야 한다', () => {
            mockLocalState.ui.optionInputs = [
                {
                    name: { ko: '색상', en: 'Color' },
                    values: [
                        { ko: '빨강', en: 'Red' },
                    ],
                },
                {
                    name: { ko: '사이즈', en: 'Size' },
                    values: [
                        { ko: 'S', en: 'S' },
                        { ko: 'M', en: 'M' },
                    ],
                },
            ];

            generateOptionsHandler(
                { handler: 'sirsoft-ecommerce.generateOptions' },
                mockContext
            );

            const options = mockLocalState.form.options;
            // 1 * 2 = 2 조합
            expect(options.length).toBe(2);

            // 모든 옵션에 상품 가격 반영
            options.forEach((opt: any) => {
                expect(opt.list_price).toBe(66000);
                expect(opt.selling_price).toBe(53000);
                expect(opt.multi_currency_selling_price.USD.price).toBe(45.05);
            });
        });
    });

    describe('상품 재고 자동 동기화', () => {
        it('신규 등록 시 옵션 2개 생성하면 상품 재고가 옵션 재고 합계(2)로 동기화되어야 한다', () => {
            // 색상 2개(빨강/파랑) → 옵션 2개, isCreate 면 옵션당 기본 재고 1
            generateOptionsHandler(
                { handler: 'sirsoft-ecommerce.generateOptions', params: { isCreate: true } },
                mockContext
            );

            const options = mockLocalState.form.options;
            expect(options.length).toBe(2);
            expect(options[0].stock_quantity).toBe(1);
            expect(options[1].stock_quantity).toBe(1);
            // 원 상품 재고 = 옵션 재고 합계 = 2 (사용자 조작 없이 즉시 반영)
            expect(mockLocalState.form.stock_quantity).toBe(2);
        });

        it('카테시안 곱(2×2=4) 옵션 생성 시 상품 재고가 합계(4)로 동기화되어야 한다', () => {
            mockLocalState.ui.optionInputs = [
                { name: { ko: '색상' }, values: [{ ko: '빨강' }, { ko: '파랑' }] },
                { name: { ko: '사이즈' }, values: [{ ko: 'S' }, { ko: 'M' }] },
            ];

            generateOptionsHandler(
                { handler: 'sirsoft-ecommerce.generateOptions', params: { isCreate: true } },
                mockContext
            );

            const options = mockLocalState.form.options;
            expect(options.length).toBe(4);
            expect(mockLocalState.form.stock_quantity).toBe(4);
        });

        it('수정 모드(isCreate 미지정)에서는 옵션 기본 재고 0 → 상품 재고 0 으로 동기화되어야 한다', () => {
            generateOptionsHandler(
                { handler: 'sirsoft-ecommerce.generateOptions' },
                mockContext
            );

            expect(mockLocalState.form.options.length).toBe(2);
            expect(mockLocalState.form.stock_quantity).toBe(0);
        });

        it('기존 옵션 병합 시에도 상품 재고가 전체 옵션 합계로 동기화되어야 한다', () => {
            // 기존 빨강(재고 10) + 신규 파랑(재고 1) → 합계 11
            mockLocalState.form.options = [
                {
                    id: 1,
                    option_code: 'OPT-001',
                    option_name: { ko: '빨강', en: 'Red' },
                    option_values: [{ key: { ko: '색상' }, value: { ko: '빨강' } }],
                    list_price: 66000,
                    selling_price: 53000,
                    price_adjustment: 0,
                    is_default: true,
                    is_active: true,
                    sku: 'SKU-001',
                    stock_quantity: 10,
                    safe_stock_quantity: 2,
                },
            ];

            generateOptionsHandler(
                { handler: 'sirsoft-ecommerce.generateOptions', params: { isCreate: true, skipConfirm: true } },
                mockContext
            );

            expect(mockLocalState.form.stock_quantity).toBe(11);
        });
    });

    describe('필드명 호환성', () => {
        it('multi_currency_selling_price 필드명을 사용해야 한다 (multi_currency_prices 아님)', () => {
            generateOptionsHandler(
                { handler: 'sirsoft-ecommerce.generateOptions' },
                mockContext
            );

            const option = mockLocalState.form.options[0];
            expect(option).toHaveProperty('multi_currency_selling_price');
            expect(option).not.toHaveProperty('multi_currency_prices');
        });
    });

    describe('기존 옵션 존재 시 확인 모달', () => {
        it('기존 옵션이 존재하면 확인 모달을 열고 생성을 중단해야 한다', () => {
            mockLocalState.form.options = [
                {
                    id: 1,
                    option_code: 'OPT-001',
                    option_name: { ko: '빨강', en: 'Red' },
                    option_values: [],
                    list_price: 66000,
                    selling_price: 53000,
                    price_adjustment: 0,
                    stock_quantity: 10,
                    safe_stock_quantity: 2,
                    is_default: true,
                    is_active: true,
                    sku: 'SKU-001',
                },
            ];

            generateOptionsHandler(
                { handler: 'sirsoft-ecommerce.generateOptions' },
                mockContext
            );

            expect(mockG7Core.modal.open).toHaveBeenCalledWith('modal_confirm_regenerate');
            expect(mockG7Core.state.setLocal).not.toHaveBeenCalled();
        });

        it('skipConfirm이 true이면 확인 모달 없이 바로 생성해야 한다', () => {
            mockLocalState.form.options = [
                {
                    id: 1,
                    option_code: 'OPT-001',
                    option_name: { ko: '빨강', en: 'Red' },
                    option_values: [],
                    list_price: 66000,
                    selling_price: 53000,
                    price_adjustment: 0,
                    stock_quantity: 10,
                    safe_stock_quantity: 2,
                    is_default: true,
                    is_active: true,
                    sku: 'SKU-001',
                },
            ];

            generateOptionsHandler(
                { handler: 'sirsoft-ecommerce.generateOptions', params: { skipConfirm: true } },
                mockContext
            );

            expect(mockG7Core.modal.open).not.toHaveBeenCalled();
            expect(mockG7Core.state.setLocal).toHaveBeenCalled();
            expect(mockLocalState.form.options.length).toBeGreaterThan(0);
        });

        it('기존 옵션이 없으면 확인 모달 없이 바로 생성해야 한다', () => {
            mockLocalState.form.options = [];

            generateOptionsHandler(
                { handler: 'sirsoft-ecommerce.generateOptions' },
                mockContext
            );

            expect(mockG7Core.modal.open).not.toHaveBeenCalled();
            expect(mockG7Core.state.setLocal).toHaveBeenCalled();
            expect(mockLocalState.form.options.length).toBeGreaterThan(0);
        });
    });
});
