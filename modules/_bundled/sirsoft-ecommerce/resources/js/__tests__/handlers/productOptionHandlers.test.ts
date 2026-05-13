/**
 * productOptionHandlers 다중통화 계산 테스트
 *
 * @description
 * - 옵션 판매가 변경 시 다중통화 가격이 올바르게 계산되는지 검증
 * - 환율 공식: (basePrice / 1000) * exchange_rate
 * - 환경설정 rounding_unit, rounding_method, decimal_places 적용 검증
 * - 데이터 구조: { price: number } 객체 형태로 저장되는지 검증
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
    toggleAutoMultiCurrencyHandler,
    updateFormOptionFieldHandler,
    updateFormOptionCurrencyFieldHandler,
    recalculateOptionPriceAdjustmentsHandler,
    addOptionRowHandler,
} from '../../handlers/productOptionHandlers';

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
    createLogger: () => ({
        log: vi.fn(),
        warn: vi.fn(),
        error: vi.fn(),
    }),
};

const mockContext = {} as any;

/**
 * 테스트용 통화 설정 (이커머스 환경설정에서 가져오는 데이터 구조)
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
    {
        code: 'CNY',
        name: { ko: '위안' },
        is_default: false,
        exchange_rate: 5.8,
        rounding_unit: '0.1',
        rounding_method: 'round',
        decimal_places: 2,
    },
    {
        code: 'EUR',
        name: { ko: '유로' },
        is_default: false,
        exchange_rate: 0.78,
        rounding_unit: '0.01',
        rounding_method: 'ceil',
        decimal_places: 2,
    },
];

/**
 * 기본 옵션 데이터
 */
function createMockOption(overrides: Record<string, any> = {}) {
    return {
        id: 1,
        option_code: 'OPT-001',
        option_values: { '사이즈': 'S' },
        is_default: true,
        regular_price: 66000,
        sale_price: 53000,
        list_price: 66000,
        selling_price: 53000,
        price_adjustment: 0,
        multi_currency_selling_price: {},
        sku: '',
        stock_quantity: 21,
        safe_stock_quantity: 3,
        weight: 0,
        volume: 0,
        mileage_value: 0,
        mileage_type: 'percent' as const,
        is_active: true,
        ...overrides,
    };
}

describe('productOptionHandlers - 다중통화 계산', () => {
    beforeEach(() => {
        // G7Core를 window에 설정
        (window as any).G7Core = mockG7Core;

        // 기본 상태 초기화
        mockLocalState = {
            form: {
                selling_price: 53000,
                options: [createMockOption()],
            },
            ui: {
                multiCurrencyAutoFill: true,
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

    describe('환율 계산 공식', () => {
        it('올바른 공식 적용: (basePrice / 1000) * exchange_rate', () => {
            // 판매가 53000원 → USD: (53000 / 1000) * 0.85 = 45.05
            updateFormOptionFieldHandler(
                {
                    handler: 'sirsoft-ecommerce.updateFormOptionField',
                    params: { index: 0, field: 'selling_price', value: '53000' },
                },
                mockContext
            );

            const updatedOptions = mockLocalState.form.options;
            const multiCurrency = updatedOptions[0].multi_currency_selling_price;

            // USD: (53000 / 1000) * 0.85 = 45.05
            expect(multiCurrency.USD.price).toBe(45.05);

            // JPY: (53000 / 1000) * 115 = 6095 (floor)
            expect(multiCurrency.JPY.price).toBe(6095);

            // CNY: (53000 / 1000) * 5.8 = 307.4
            expect(multiCurrency.CNY.price).toBe(307.4);

            // EUR: (53000 / 1000) * 0.78 = 41.34 (ceil 0.01)
            expect(multiCurrency.EUR.price).toBe(41.34);
        });

        it('이전 잘못된 공식(basePrice * exchange_rate) 사용하지 않음', () => {
            updateFormOptionFieldHandler(
                {
                    handler: 'sirsoft-ecommerce.updateFormOptionField',
                    params: { index: 0, field: 'selling_price', value: '53000' },
                },
                mockContext
            );

            const multiCurrency = mockLocalState.form.options[0].multi_currency_selling_price;

            // 잘못된 공식: 53000 * 0.85 = 45050 (이 값이면 안 됨)
            expect(multiCurrency.USD.price).not.toBe(45050);
            // 잘못된 공식: 53000 * 115 = 6095000 (이 값이면 안 됨)
            expect(multiCurrency.JPY.price).not.toBe(6095000);
        });
    });

    describe('데이터 구조 (백엔드 API 응답과 일치)', () => {
        it('다중통화 가격은 { price: number } 객체로 저장됨', () => {
            updateFormOptionFieldHandler(
                {
                    handler: 'sirsoft-ecommerce.updateFormOptionField',
                    params: { index: 0, field: 'selling_price', value: '53000' },
                },
                mockContext
            );

            const multiCurrency = mockLocalState.form.options[0].multi_currency_selling_price;

            // 각 통화별로 { price: number } 객체여야 함
            expect(multiCurrency.USD).toHaveProperty('price');
            expect(typeof multiCurrency.USD.price).toBe('number');

            expect(multiCurrency.JPY).toHaveProperty('price');
            expect(typeof multiCurrency.JPY.price).toBe('number');
        });

        it('flat number가 아닌 객체로 저장됨 (레이아웃의 .price 접근 호환)', () => {
            updateFormOptionFieldHandler(
                {
                    handler: 'sirsoft-ecommerce.updateFormOptionField',
                    params: { index: 0, field: 'selling_price', value: '53000' },
                },
                mockContext
            );

            const multiCurrency = mockLocalState.form.options[0].multi_currency_selling_price;

            // flat number가 아님 (예: { USD: 45.05 } 형태가 아님)
            expect(typeof multiCurrency.USD).toBe('object');
            expect(typeof multiCurrency.USD).not.toBe('number');
        });
    });

    describe('환경설정 rounding_unit 적용', () => {
        it('USD: rounding_unit 0.01 적용', () => {
            // 55000원 → USD: (55000 / 1000) * 0.85 = 46.75
            mockLocalState.form.options = [createMockOption({ selling_price: 55000 })];

            updateFormOptionFieldHandler(
                {
                    handler: 'sirsoft-ecommerce.updateFormOptionField',
                    params: { index: 0, field: 'selling_price', value: '55000' },
                },
                mockContext
            );

            const usdPrice = mockLocalState.form.options[0].multi_currency_selling_price.USD.price;
            // (55000 / 1000) * 0.85 = 46.75 → rounding_unit=0.01, method=round → 46.75
            expect(usdPrice).toBe(46.75);
        });

        it('JPY: rounding_unit 1, method floor 적용', () => {
            // 53500원 → JPY: (53500 / 1000) * 115 = 6152.5 → floor → 6152
            mockLocalState.form.options = [createMockOption({ selling_price: 53500 })];

            updateFormOptionFieldHandler(
                {
                    handler: 'sirsoft-ecommerce.updateFormOptionField',
                    params: { index: 0, field: 'selling_price', value: '53500' },
                },
                mockContext
            );

            const jpyPrice = mockLocalState.form.options[0].multi_currency_selling_price.JPY.price;
            // (53500 / 1000) * 115 = 6152.5 → floor(6152.5/1)*1 = 6152
            expect(jpyPrice).toBe(6152);
        });

        it('CNY: rounding_unit 0.1, method round 적용', () => {
            // 51000원 → CNY: (51000 / 1000) * 5.8 = 295.8 → round(295.8/0.1)*0.1 = 295.8
            mockLocalState.form.options = [createMockOption({ selling_price: 51000 })];

            updateFormOptionFieldHandler(
                {
                    handler: 'sirsoft-ecommerce.updateFormOptionField',
                    params: { index: 0, field: 'selling_price', value: '51000' },
                },
                mockContext
            );

            const cnyPrice = mockLocalState.form.options[0].multi_currency_selling_price.CNY.price;
            expect(cnyPrice).toBe(295.8);
        });

        it('EUR: rounding_unit 0.01, method ceil 적용', () => {
            // 51500원 → EUR: (51500 / 1000) * 0.78 = 40.17 → ceil(40.17/0.01)*0.01 = 40.17
            mockLocalState.form.options = [createMockOption({ selling_price: 51500 })];

            updateFormOptionFieldHandler(
                {
                    handler: 'sirsoft-ecommerce.updateFormOptionField',
                    params: { index: 0, field: 'selling_price', value: '51500' },
                },
                mockContext
            );

            const eurPrice = mockLocalState.form.options[0].multi_currency_selling_price.EUR.price;
            expect(eurPrice).toBe(40.17);
        });
    });

    describe('환경설정 decimal_places 적용', () => {
        it('JPY: decimal_places 0 → 소수점 없음', () => {
            updateFormOptionFieldHandler(
                {
                    handler: 'sirsoft-ecommerce.updateFormOptionField',
                    params: { index: 0, field: 'selling_price', value: '53000' },
                },
                mockContext
            );

            const jpyPrice = mockLocalState.form.options[0].multi_currency_selling_price.JPY.price;
            expect(Number.isInteger(jpyPrice)).toBe(true);
        });

        it('USD: decimal_places 2 → 소수점 2자리', () => {
            updateFormOptionFieldHandler(
                {
                    handler: 'sirsoft-ecommerce.updateFormOptionField',
                    params: { index: 0, field: 'selling_price', value: '53000' },
                },
                mockContext
            );

            const usdPrice = mockLocalState.form.options[0].multi_currency_selling_price.USD.price;
            // 45.05는 소수점 2자리
            const decimalStr = String(usdPrice).split('.')[1] || '';
            expect(decimalStr.length).toBeLessThanOrEqual(2);
        });
    });

    describe('toggleAutoMultiCurrencyHandler', () => {
        it('토글 ON 시 모든 옵션에 대해 다중통화 가격 계산', () => {
            // 초기 상태: 다중통화 비어있음
            mockLocalState.form.options = [
                createMockOption({ selling_price: 53000, multi_currency_selling_price: {} }),
                createMockOption({ option_code: 'OPT-002', selling_price: 57000, multi_currency_selling_price: {} }),
            ];

            toggleAutoMultiCurrencyHandler(
                { handler: 'toggleAutoMultiCurrency', params: { enabled: true } },
                mockContext
            );

            const options = mockLocalState.form.options;

            // 첫 번째 옵션: 53000원
            expect(options[0].multi_currency_selling_price.USD.price).toBe(45.05);

            // 두 번째 옵션: 57000원 → USD: (57000 / 1000) * 0.85 = 48.45
            expect(options[1].multi_currency_selling_price.USD.price).toBe(48.45);
        });

        it('토글 ON 시 { price: number } 객체 구조로 저장', () => {
            toggleAutoMultiCurrencyHandler(
                { handler: 'toggleAutoMultiCurrency', params: { enabled: true } },
                mockContext
            );

            const multiCurrency = mockLocalState.form.options[0].multi_currency_selling_price;
            expect(multiCurrency.USD).toHaveProperty('price');
            expect(multiCurrency.JPY).toHaveProperty('price');
        });
    });

    describe('recalculateOptionPriceAdjustmentsHandler', () => {
        it('상품 판매가 변경 시 모든 옵션의 다중통화 가격 재계산', () => {
            mockLocalState.form.options = [
                createMockOption({ selling_price: 53000, price_adjustment: 0 }),
                createMockOption({
                    option_code: 'OPT-002',
                    selling_price: 57000,
                    price_adjustment: 4000,
                }),
            ];

            // 상품 판매가를 55000으로 변경
            recalculateOptionPriceAdjustmentsHandler(
                {
                    handler: 'recalculateOptionPriceAdjustments',
                    params: { newSellingPrice: 55000 },
                },
                mockContext
            );

            const options = mockLocalState.form.options;

            // OPT-001: 55000 + 0 = 55000 → USD: (55000/1000)*0.85 = 46.75
            expect(options[0].multi_currency_selling_price.USD.price).toBe(46.75);

            // OPT-002: 55000 + 4000 = 59000 → USD: (59000/1000)*0.85 = 50.15
            expect(options[1].multi_currency_selling_price.USD.price).toBe(50.15);
        });

        it('재계산 결과가 { price: number } 객체 구조', () => {
            recalculateOptionPriceAdjustmentsHandler(
                {
                    handler: 'recalculateOptionPriceAdjustments',
                    params: { newSellingPrice: 53000 },
                },
                mockContext
            );

            const multiCurrency = mockLocalState.form.options[0].multi_currency_selling_price;
            expect(multiCurrency.USD).toHaveProperty('price');
            expect(typeof multiCurrency.USD.price).toBe('number');
        });
    });

    describe('updateFormOptionCurrencyFieldHandler', () => {
        it('수동 입력 시 { price: number } 객체로 저장', () => {
            updateFormOptionCurrencyFieldHandler(
                {
                    handler: 'sirsoft-ecommerce.updateFormOptionCurrencyField',
                    params: { index: 0, currencyCode: 'USD', value: '50.25' },
                },
                mockContext
            );

            const multiCurrency = mockLocalState.form.options[0].multi_currency_selling_price;
            expect(multiCurrency.USD).toEqual({ price: 50.25 });
        });

        it('수동 입력 후 다른 통화 값은 유지', () => {
            // 기존 다중통화 설정
            mockLocalState.form.options[0].multi_currency_selling_price = {
                USD: { price: 45.05 },
                JPY: { price: 6095 },
            };

            updateFormOptionCurrencyFieldHandler(
                {
                    handler: 'sirsoft-ecommerce.updateFormOptionCurrencyField',
                    params: { index: 0, currencyCode: 'USD', value: '50.00' },
                },
                mockContext
            );

            const multiCurrency = mockLocalState.form.options[0].multi_currency_selling_price;
            expect(multiCurrency.USD.price).toBe(50);
            expect(multiCurrency.JPY.price).toBe(6095); // 유지
        });
    });

    describe('자동 계산 비활성화', () => {
        it('multiCurrencyAutoFill이 false이면 판매가 변경 시 다중통화 미계산', () => {
            mockLocalState.ui.multiCurrencyAutoFill = false;
            mockLocalState.form.options[0].multi_currency_selling_price = { USD: { price: 45.05 } };

            updateFormOptionFieldHandler(
                {
                    handler: 'sirsoft-ecommerce.updateFormOptionField',
                    params: { index: 0, field: 'selling_price', value: '60000' },
                },
                mockContext
            );

            // 자동 계산이 비활성화되었으므로 기존 값 유지
            const multiCurrency = mockLocalState.form.options[0].multi_currency_selling_price;
            expect(multiCurrency.USD.price).toBe(45.05);
        });
    });

    describe('판매가 0 또는 엣지 케이스', () => {
        it('판매가 0원이면 모든 다중통화 가격도 0', () => {
            updateFormOptionFieldHandler(
                {
                    handler: 'sirsoft-ecommerce.updateFormOptionField',
                    params: { index: 0, field: 'selling_price', value: '0' },
                },
                mockContext
            );

            const multiCurrency = mockLocalState.form.options[0].multi_currency_selling_price;
            expect(multiCurrency.USD.price).toBe(0);
            expect(multiCurrency.JPY.price).toBe(0);
        });
    });
});

/**
 * 옵션 재고 합산 테스트
 *
 * @description
 * - 옵션 재고 변경 시 상품 stock_quantity가 옵션 합계로 업데이트되는지 검증
 * - 등록 모드(has_options 미설정)에서도 정상 동작하는지 검증 (회귀 테스트)
 */
describe('productOptionHandlers - 옵션 재고 합산', () => {
    beforeEach(() => {
        (window as any).G7Core = mockG7Core;
        vi.clearAllMocks();
    });

    afterEach(() => {
        delete (window as any).G7Core;
    });

    it('has_options가 true일 때 stock_quantity 변경 시 합산 반영', () => {
        mockLocalState = {
            form: {
                has_options: true,
                selling_price: 10000,
                options: [
                    createMockOption({ id: 1, stock_quantity: 5, is_active: true }),
                    createMockOption({ id: 2, option_code: 'OPT-002', stock_quantity: 10, is_active: true }),
                ],
            },
        };
        mockGlobalState = { modules: { 'sirsoft-ecommerce': { language_currency: { currencies: [] } } } };

        updateFormOptionFieldHandler(
            {
                handler: 'sirsoft-ecommerce.updateFormOptionField',
                params: { index: 0, field: 'stock_quantity', value: '20' },
            },
            mockContext
        );

        // 옵션 0: 20, 옵션 1: 10 → 합계 30
        expect(mockLocalState.form.stock_quantity).toBe(30);
        expect(mockLocalState.form.options[0].stock_quantity).toBe(20);
    });

    it('has_options 미설정(등록 모드)에서도 stock_quantity 합산이 정상 동작해야 한다', () => {
        // 등록 모드: has_options 필드가 없음
        mockLocalState = {
            form: {
                selling_price: 10000,
                options: [
                    createMockOption({ id: null, stock_quantity: 0, is_active: true }),
                    createMockOption({ id: null, option_code: 'OPT-002', stock_quantity: 0, is_active: true }),
                ],
            },
        };
        mockGlobalState = { modules: { 'sirsoft-ecommerce': { language_currency: { currencies: [] } } } };

        // 옵션 0에 재고 3 입력
        updateFormOptionFieldHandler(
            {
                handler: 'sirsoft-ecommerce.updateFormOptionField',
                params: { index: 0, field: 'stock_quantity', value: '3' },
            },
            mockContext
        );

        // 옵션 0: 3, 옵션 1: 0 → 합계 3
        expect(mockLocalState.form.stock_quantity).toBe(3);
        expect(mockLocalState.form.options[0].stock_quantity).toBe(3);

        // 옵션 1에 재고 5 입력
        updateFormOptionFieldHandler(
            {
                handler: 'sirsoft-ecommerce.updateFormOptionField',
                params: { index: 1, field: 'stock_quantity', value: '5' },
            },
            mockContext
        );

        // 옵션 0: 3, 옵션 1: 5 → 합계 8
        expect(mockLocalState.form.stock_quantity).toBe(8);
    });

    it('비활성 옵션은 재고 합산에서 제외해야 한다', () => {
        mockLocalState = {
            form: {
                selling_price: 10000,
                options: [
                    createMockOption({ id: null, stock_quantity: 10, is_active: true }),
                    createMockOption({ id: null, option_code: 'OPT-002', stock_quantity: 5, is_active: false }),
                ],
            },
        };
        mockGlobalState = { modules: { 'sirsoft-ecommerce': { language_currency: { currencies: [] } } } };

        updateFormOptionFieldHandler(
            {
                handler: 'sirsoft-ecommerce.updateFormOptionField',
                params: { index: 0, field: 'stock_quantity', value: '15' },
            },
            mockContext
        );

        // 옵션 0: 15 (active), 옵션 1: 5 (inactive) → 합계 15 (비활성 제외)
        expect(mockLocalState.form.stock_quantity).toBe(15);
    });
});

/**
 * addOptionRowHandler 테스트
 *
 * @description
 * - 옵션 행 추가 시 option_values가 배열 포맷으로 생성되는지 검증
 * - option_name 다국어 필드가 포함되는지 검증
 * - validation 에러 방지: 레거시 {"구분": ""} 대신 [{key, value}] 배열 사용
 */
describe('productOptionHandlers - addOptionRowHandler', () => {
    beforeEach(() => {
        (window as any).G7Core = {
            ...mockG7Core,
            config: (key: string) => {
                if (key === 'app.supported_locales') return ['ko', 'en'];
                return undefined;
            },
        };
        vi.clearAllMocks();
    });

    afterEach(() => {
        delete (window as any).G7Core;
    });

    it('옵션 행 추가 시 option_values가 배열 포맷으로 생성됨', () => {
        mockLocalState = {
            form: {
                options: [],
                option_groups: [
                    { name: { ko: '색상', en: 'Color' }, values: [] },
                    { name: { ko: '사이즈', en: 'Size' }, values: [] },
                ],
            },
        };

        addOptionRowHandler(
            { handler: 'addOptionRow' },
            mockContext
        );

        const newOption = mockLocalState.form.options[0];

        // option_values가 배열이어야 함
        expect(Array.isArray(newOption.option_values)).toBe(true);
        expect(newOption.option_values.length).toBe(2);

        // 첫 번째 항목: key는 그룹 name (다국어 객체), value는 빈 다국어 객체
        expect(newOption.option_values[0].key).toEqual({ ko: '색상', en: 'Color' });
        expect(newOption.option_values[0].value).toEqual({ ko: '', en: '' });

        // 두 번째 항목
        expect(newOption.option_values[1].key).toEqual({ ko: '사이즈', en: 'Size' });
        expect(newOption.option_values[1].value).toEqual({ ko: '', en: '' });
    });

    it('옵션 행 추가 시 option_name 다국어 필드가 포함됨', () => {
        mockLocalState = {
            form: {
                options: [],
                option_groups: [
                    { name: { ko: '색상', en: 'Color' }, values: [] },
                ],
            },
        };

        addOptionRowHandler(
            { handler: 'addOptionRow' },
            mockContext
        );

        const newOption = mockLocalState.form.options[0];

        // option_name이 다국어 빈 객체로 생성되어야 함
        expect(newOption.option_name).toEqual({ ko: '', en: '' });
    });

    it('첫 번째 옵션은 is_default=true로 설정됨', () => {
        mockLocalState = {
            form: {
                options: [],
                option_groups: [],
            },
        };

        addOptionRowHandler(
            { handler: 'addOptionRow' },
            mockContext
        );

        expect(mockLocalState.form.options[0].is_default).toBe(true);
    });

    it('두 번째 이후 옵션은 is_default=false로 설정됨', () => {
        mockLocalState = {
            form: {
                options: [createMockOption()],
                option_groups: [],
            },
        };

        addOptionRowHandler(
            { handler: 'addOptionRow' },
            mockContext
        );

        expect(mockLocalState.form.options[1].is_default).toBe(false);
    });
});
