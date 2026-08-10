/**
 * updateOptionFieldHandler 테스트 (상품 목록 인라인 옵션 편집)
 *
 * @description
 * - 기본 옵션 판매가 변경 시 상품 판매가 역동기화
 * - 비기본 옵션 판매가 변경 시 상품 판매가 불변
 * - 역동기화 시 비기본 옵션은 product.selling_price + price_adjustment 로 재계산
 * - 역동기화 시 상품/옵션이 modified 로 추적되어 일괄 저장에 포함
 *
 * 백엔드 모델 정의(ProductOption::getSellingPrice):
 *   option.selling_price = product.selling_price + price_adjustment
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { updateOptionFieldHandler } from '../../handlers/updateOptionField';

let mockLocalState: Record<string, any> = {};
let mockGlobalState: Record<string, any> = {};
let mockDataSource: Record<string, any> = {};

const mockG7Core = {
    state: {
        getLocal: () => mockLocalState,
        get: () => ({ ...mockGlobalState, _local: mockLocalState }),
        setLocal: vi.fn((updates: Record<string, any>) => {
            mockLocalState = { ...mockLocalState, ...updates };
        }),
    },
    dataSource: {
        get: vi.fn((id: string) => mockDataSource[id]),
        set: vi.fn((id: string, value: any) => {
            mockDataSource[id] = value;
        }),
    },
    createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
};

const mockContext = {} as any;

// 통화 설정 (기본 KRW + 외화 USD)
const mockCurrencies = [
    { code: 'KRW', name: { ko: '원' }, is_default: true, exchange_rate: 1, rounding_unit: '1', rounding_method: 'round' },
    { code: 'USD', name: { ko: '달러' }, is_default: false, exchange_rate: 0.85, rounding_unit: '0.01', rounding_method: 'round' },
];

/** products 데이터소스 시드: 상품 1개 + 기본/비기본 옵션 2개 */
function seedProducts() {
    mockDataSource['products'] = {
        success: true,
        data: {
            data: [
                {
                    id: 313,
                    selling_price: 4000,
                    list_price: 5000,
                    options: [
                        // 기본 옵션: 상품 판매가와 동일 (price_adjustment 0)
                        { id: 1, is_default: true, selling_price: 4000, list_price: 5000, price_adjustment: 0 },
                        // 비기본 옵션: +1000 가산 (price_adjustment 1000)
                        { id: 2, is_default: false, selling_price: 5000, list_price: 6000, price_adjustment: 1000 },
                    ],
                },
            ],
        },
    };
}

beforeEach(() => {
    mockLocalState = {};
    mockGlobalState = {
        modules: {
            'sirsoft-ecommerce': {
                language_currency: { currencies: mockCurrencies },
            },
        },
    };
    mockDataSource = {};
    seedProducts();
    (window as any).G7Core = mockG7Core;
    vi.clearAllMocks();
});

afterEach(() => {
    delete (window as any).G7Core;
});

function getProduct() {
    return mockDataSource['products'].data.data[0];
}

describe('updateOptionFieldHandler - 기본 옵션 판매가 역동기화', () => {
    it('기본 옵션 판매가 변경 시 상품 판매가가 동기화된다', () => {
        updateOptionFieldHandler(
            { handler: 'updateOptionField', params: { productId: 313, optionId: 1, field: 'selling_price', value: 4500 } },
            mockContext
        );

        const product = getProduct();
        expect(product.selling_price).toBe(4500);
        // 기본 옵션 price_adjustment 는 0 유지
        const defaultOpt = product.options.find((o: any) => o.id === 1);
        expect(defaultOpt.selling_price).toBe(4500);
        expect(defaultOpt.price_adjustment).toBe(0);
    });

    it('기본 옵션 판매가 변경 시 비기본 옵션도 product.selling_price + price_adjustment 로 재계산된다', () => {
        updateOptionFieldHandler(
            { handler: 'updateOptionField', params: { productId: 313, optionId: 1, field: 'selling_price', value: 4500 } },
            mockContext
        );

        const product = getProduct();
        const otherOpt = product.options.find((o: any) => o.id === 2);
        // 비기본 옵션 = 새 상품가(4500) + 기존 가산액(1000) = 5500
        expect(otherOpt.selling_price).toBe(5500);
        // price_adjustment 는 유지
        expect(otherOpt.price_adjustment).toBe(1000);
    });

    it('역동기화 시 상품이 modified 로 추적되어 일괄 저장 대상에 포함된다', () => {
        updateOptionFieldHandler(
            { handler: 'updateOptionField', params: { productId: 313, optionId: 1, field: 'selling_price', value: 4500 } },
            mockContext
        );

        // 상품 selling_price 가 modifiedProductFields 에 기록되어야 함
        expect(mockLocalState.modifiedProductIds).toContain(313);
        expect(mockLocalState.modifiedProductFields['313']).toContain('selling_price');
        // 상품 행 _modified 플래그
        expect(getProduct()._modified).toBe(true);
    });

    it('역동기화 시 상품 다중통화 판매가도 재계산된다', () => {
        updateOptionFieldHandler(
            { handler: 'updateOptionField', params: { productId: 313, optionId: 1, field: 'selling_price', value: 4500 } },
            mockContext
        );

        const product = getProduct();
        expect(product.multi_currency_selling_price).toBeDefined();
        expect(product.multi_currency_selling_price.KRW.price).toBe(4500);
        // USD = (4500/1000) * 0.85 = 3.825 → round(382.5)/100 = 3.82 (IEEE754 round-half-to-even/floor 경계)
        expect(product.multi_currency_selling_price.USD.price).toBeCloseTo(3.82, 2);
    });
});

describe('updateOptionFieldHandler - 비기본 옵션 판매가 변경 (상품 판매가 불변)', () => {
    it('비기본 옵션 판매가 변경 시 상품 판매가는 변하지 않는다', () => {
        updateOptionFieldHandler(
            { handler: 'updateOptionField', params: { productId: 313, optionId: 2, field: 'selling_price', value: 5500 } },
            mockContext
        );

        const product = getProduct();
        // 상품 판매가 불변
        expect(product.selling_price).toBe(4000);
        const otherOpt = product.options.find((o: any) => o.id === 2);
        // 비기본 옵션 price_adjustment = 새 판매가(5500) - 상품가(4000) = 1500
        expect(otherOpt.price_adjustment).toBe(1500);
    });

    it('비기본 옵션 변경 시 상품은 modifiedProductFields 에 selling_price 가 기록되지 않는다', () => {
        updateOptionFieldHandler(
            { handler: 'updateOptionField', params: { productId: 313, optionId: 2, field: 'selling_price', value: 5500 } },
            mockContext
        );

        const productFields = (mockLocalState.modifiedProductFields ?? {})['313'] ?? [];
        expect(productFields).not.toContain('selling_price');
    });
});

/**
 * 옵션 지연 로딩(#518 / 공개 #76) 이후 생긴 상태: 목록 행의 `options` 는 펼치기 전까지
 * `undefined` 다. 이 핸들러는 그 행을 만나도 **아무것도 바꾸지 않아야** 한다.
 *
 * 특히 `options` 를 `[]` 로 채워 넣으면 안 된다. 지연 로딩은 `Array.isArray(row.options)` 로
 * 미로드(`undefined`)와 로드완료·옵션없음(`[]`)을 구분하므로, `[]` 로 뒤바뀌면 그 행은
 * "이미 불러왔다" 로 오인되어 펼쳐도 영영 옵션을 받지 못한다 — 에러도 토스트도 없이.
 */
describe('updateOptionFieldHandler - 옵션 미로드 행 (지연 로딩)', () => {
    /** 옵션을 아직 불러오지 않은 행을 하나 더 붙인다 */
    function seedUnloadedRow() {
        mockDataSource['products'].data.data.push({
            id: 999,
            selling_price: 7000,
            list_price: 9000,
            // options 키 자체가 없다 — 서버가 목록에서 싣지 않는다
        });
    }

    it('options 가 undefined 인 행에 편집이 들어와도 undefined 로 남는다', () => {
        seedUnloadedRow();

        updateOptionFieldHandler(
            { handler: 'updateOptionField', params: { productId: 999, optionId: 1, field: 'selling_price', value: 8000 } },
            mockContext
        );

        const unloaded = mockDataSource['products'].data.data.find((p: any) => p.id === 999);
        expect(unloaded.options).toBeUndefined();
        expect(
            Array.isArray(unloaded.options),
            'options 가 배열이 되면 지연 로딩이 "로드 완료" 로 오인해 영영 불러오지 않는다'
        ).toBe(false);
    });

    it('options 가 undefined 인 행의 다른 값도 바뀌지 않는다', () => {
        seedUnloadedRow();

        updateOptionFieldHandler(
            { handler: 'updateOptionField', params: { productId: 999, optionId: 1, field: 'selling_price', value: 8000 } },
            mockContext
        );

        const unloaded = mockDataSource['products'].data.data.find((p: any) => p.id === 999);
        expect(unloaded.selling_price).toBe(7000);
    });

    it('미로드 행이 섞여 있어도 로드된 행의 편집은 정상 동작한다', () => {
        seedUnloadedRow();

        updateOptionFieldHandler(
            { handler: 'updateOptionField', params: { productId: 313, optionId: 1, field: 'selling_price', value: 4500 } },
            mockContext
        );

        expect(getProduct().selling_price).toBe(4500);
        // 미로드 행은 그대로
        const unloaded = mockDataSource['products'].data.data.find((p: any) => p.id === 999);
        expect(unloaded.options).toBeUndefined();
    });

    it('미로드 행은 modified 추적에도 들어가지 않는다', () => {
        seedUnloadedRow();

        updateOptionFieldHandler(
            { handler: 'updateOptionField', params: { productId: 999, optionId: 1, field: 'selling_price', value: 8000 } },
            mockContext
        );

        const modifiedProducts = mockLocalState.modifiedProductFields ?? {};
        expect(modifiedProducts['999']).toBeUndefined();

        // 존재하지 않는 옵션이 수정 대상으로 기록되면 일괄 저장이 유령 키를 option_items 에
        // 실어 보낸다. 옵션 지연 로딩 전에는 모든 행이 옵션을 갖고 있어 드러나지 않던 경로다.
        expect(mockLocalState.modifiedOptionIds ?? []).not.toContain('999-1');
        expect(mockLocalState.modifiedOptionFields ?? {}).not.toHaveProperty('999-1');
    });

    it('찾지 못한 옵션에는 데이터소스 쓰기 자체가 일어나지 않는다', () => {
        seedUnloadedRow();

        updateOptionFieldHandler(
            { handler: 'updateOptionField', params: { productId: 999, optionId: 1, field: 'selling_price', value: 8000 } },
            mockContext
        );

        // 같은 값을 다시 써서 불필요한 리렌더를 만들지 않는다
        expect(mockG7Core.dataSource.set).not.toHaveBeenCalled();
        expect(mockG7Core.state.setLocal).not.toHaveBeenCalled();
    });
});
