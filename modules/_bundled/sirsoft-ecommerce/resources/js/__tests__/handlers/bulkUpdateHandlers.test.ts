/**
 * bulkUpdateHandlers 테스트
 *
 * @description
 * - buildConfirmData: 수정된 필드만 보고, 다국어 객체 로컬라이즈 검증
 * - bulkUpdate: 수정된 필드만 API에 전송 검증
 * - updateOptionField/updateProductField: 수정 필드 추적 검증
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { buildConfirmDataHandler } from '../../handlers/bulkUpdateHandlers';
import { updateOptionFieldHandler } from '../../handlers/updateOptionField';
import { updateProductFieldHandler } from '../../handlers/updateProductField';

// G7Core mock
let mockLocalState: Record<string, any> = {};
let mockGlobalState: Record<string, any> = {};
let mockDataSources: Record<string, any> = {};

const mockG7Core = {
    state: {
        getLocal: () => mockLocalState,
        get: () => mockGlobalState,
        setLocal: vi.fn((updates: Record<string, any>) => {
            mockLocalState = { ...mockLocalState, ...updates };
        }),
        set: vi.fn((updates: Record<string, any>) => {
            mockGlobalState = { ...mockGlobalState, ...updates };
        }),
    },
    dataSource: {
        get: vi.fn((id: string) => mockDataSources[id]),
        set: vi.fn((id: string, data: any) => {
            mockDataSources[id] = data;
        }),
        refetch: vi.fn(),
    },
    t: vi.fn((key: string, params?: Record<string, any>) => {
        // 간단한 번역 시뮬레이션: {key} 형식 치환
        const translations: Record<string, string> = {
            'sirsoft-ecommerce.admin.product.messages.bulk_summary_option_name': '옵션명: {name}',
            'sirsoft-ecommerce.admin.product.messages.bulk_summary_sku': 'SKU: {sku}',
            'sirsoft-ecommerce.admin.product.messages.bulk_summary_price_adjustment': '조정가: {method} {value}',
            'sirsoft-ecommerce.admin.product.messages.bulk_summary_stock': '재고: {method} {value}',
            'sirsoft-ecommerce.admin.product.messages.bulk_summary_selling_price': '판매가: {price}',
            'sirsoft-ecommerce.admin.product.messages.bulk_summary_sales_status': '판매상태: {status}',
            'sirsoft-ecommerce.admin.product.messages.bulk_summary_display_status': '전시상태: {status}',
            'sirsoft-ecommerce.admin.product.messages.bulk_summary_name_changed': '상품명 변경',
            'sirsoft-ecommerce.admin.product.messages.bulk_summary_list_price': '정가: {price}',
            'sirsoft-ecommerce.admin.product.messages.bulk_summary_inline_modified': '인라인 수정됨',
            'sirsoft-ecommerce.admin.product.messages.bulk_summary_bulk_applied': '일괄 변경 적용',
            'sirsoft-ecommerce.admin.product.messages.bulk_summary_selected': '선택됨',
        };

        let text = translations[key] || '';
        if (params && text) {
            for (const [k, v] of Object.entries(params)) {
                text = text.replace(new RegExp(`\\{${k}\\}`, 'g'), String(v));
            }
        }
        return text;
    }),
    toast: {
        success: vi.fn(),
        warning: vi.fn(),
        error: vi.fn(),
    },
    modal: {
        close: vi.fn(),
    },
    api: {
        patch: vi.fn(),
    },
    createLogger: () => ({
        log: vi.fn(),
        warn: vi.fn(),
        error: vi.fn(),
    }),
};

const mockContext = {} as any;

/**
 * 테스트용 상품 데이터 (다국어 옵션 포함)
 */
function createMockProducts() {
    return [
        {
            id: 99,
            name: { en: 'Test Product', ko: '테스트 상품' },
            sales_status: 'on_sale',
            display_status: 'visible',
            list_price: 86000,
            selling_price: 64000,
            stock_quantity: 59,
            options: [
                {
                    id: 506,
                    option_name: { en: 'Black', ko: '블랙' },
                    option_name_localized: '블랙',
                    price_adjustment: 0,
                    stock_quantity: 26,
                    sku: 'MS-0099-BLK',
                    safe_stock_quantity: 3,
                    is_default: true,
                    is_active: true,
                },
                {
                    id: 507,
                    option_name: { en: 'White', ko: '화이트' },
                    option_name_localized: '화이트',
                    price_adjustment: 3000,
                    stock_quantity: 7,
                    sku: 'MS-0099-WHT',
                    safe_stock_quantity: 3,
                    is_default: false,
                    is_active: true,
                },
            ],
        },
    ];
}

beforeEach(() => {
    mockLocalState = {};
    mockGlobalState = {};
    mockDataSources = {};
    vi.clearAllMocks();
    (window as any).G7Core = mockG7Core;
});

describe('updateOptionFieldHandler - 수정 필드 추적', () => {
    it('option_name 수정 시 modifiedOptionFields에 해당 필드만 기록', () => {
        const products = createMockProducts();
        mockDataSources.products = {
            data: { data: products },
        };

        updateOptionFieldHandler(
            {
                handler: 'sirsoft-ecommerce.updateOptionField',
                params: {
                    productId: 99,
                    optionId: 506,
                    field: 'option_name',
                    value: { en: 'Black', ko: '블랙11' },
                },
            },
            mockContext
        );

        expect(mockLocalState.modifiedOptionFields).toBeDefined();
        expect(mockLocalState.modifiedOptionFields['99-506']).toContain('option_name');
        expect(mockLocalState.modifiedOptionFields['99-506']).not.toContain('price_adjustment');
        expect(mockLocalState.modifiedOptionFields['99-506']).not.toContain('stock_quantity');
    });

    it('여러 필드 수정 시 모든 수정 필드가 추적됨', () => {
        const products = createMockProducts();
        mockDataSources.products = {
            data: { data: products },
        };

        // option_name 수정
        updateOptionFieldHandler(
            {
                handler: 'sirsoft-ecommerce.updateOptionField',
                params: { productId: 99, optionId: 506, field: 'option_name', value: { en: 'Black', ko: '블랙11' } },
            },
            mockContext
        );

        // sku 수정
        updateOptionFieldHandler(
            {
                handler: 'sirsoft-ecommerce.updateOptionField',
                params: { productId: 99, optionId: 506, field: 'sku', value: 'MS-0099-BLK-V2' },
            },
            mockContext
        );

        expect(mockLocalState.modifiedOptionFields['99-506']).toContain('option_name');
        expect(mockLocalState.modifiedOptionFields['99-506']).toContain('sku');
        expect(mockLocalState.modifiedOptionFields['99-506']).toHaveLength(2);
    });
});

describe('updateProductFieldHandler - 수정 필드 추적', () => {
    it('sales_status 수정 시 modifiedProductFields에 해당 필드만 기록', () => {
        const products = createMockProducts();
        mockDataSources.products = {
            data: { data: products },
        };

        updateProductFieldHandler(
            {
                handler: 'sirsoft-ecommerce.updateProductField',
                params: {
                    productId: 99,
                    field: 'sales_status',
                    value: 'suspended',
                },
            },
            mockContext
        );

        expect(mockLocalState.modifiedProductFields).toBeDefined();
        expect(mockLocalState.modifiedProductFields['99']).toContain('sales_status');
        expect(mockLocalState.modifiedProductFields['99']).not.toContain('name');
        expect(mockLocalState.modifiedProductFields['99']).not.toContain('list_price');
    });
});

describe('buildConfirmDataHandler - 수정된 필드만 보고', () => {
    it('option_name만 수정 시 옵션명 변경만 보고 (price_adjustment, stock_quantity 미포함)', () => {
        const products = createMockProducts();
        // option_name이 수정된 상태 시뮬레이션
        products[0].options[0].option_name = { en: 'Black', ko: '블랙11' } as any;
        products[0].options[0]._modified = true;

        mockDataSources.products = { data: { data: products } };
        mockLocalState = {
            selectedItems: [99],
            selectedOptionIds: ['99-506'],
            modifiedOptionIds: ['99-506'],
            modifiedOptionFields: { '99-506': ['option_name'] },
        };
        mockGlobalState = {};

        buildConfirmDataHandler(
            { handler: 'sirsoft-ecommerce.buildConfirmData' },
            mockContext
        );

        const confirmData = mockGlobalState.bulkConfirmData;
        expect(confirmData).toBeDefined();
        expect(confirmData.options.length).toBeGreaterThan(0);

        // 옵션 변경사항에 option_name만 포함
        const optionChanges = confirmData.options[0].changes;
        expect(optionChanges).toContain('옵션명');
        expect(optionChanges).not.toContain('조정가');
        expect(optionChanges).not.toContain('재고');
        expect(optionChanges).not.toContain('SKU');
    });

    it('다국어 option_name이 로컬라이즈되어 표시됨 ([object Object] 미표시)', () => {
        const products = createMockProducts();
        products[0].options[0].option_name = { en: 'Black', ko: '블랙11' } as any;
        products[0].options[0]._modified = true;

        mockDataSources.products = { data: { data: products } };
        mockLocalState = {
            selectedItems: [99],
            selectedOptionIds: ['99-506'],
            modifiedOptionIds: ['99-506'],
            modifiedOptionFields: { '99-506': ['option_name'] },
        };
        mockGlobalState = {};

        buildConfirmDataHandler(
            { handler: 'sirsoft-ecommerce.buildConfirmData' },
            mockContext
        );

        const confirmData = mockGlobalState.bulkConfirmData;
        const option = confirmData.options[0];

        // optionName이 로컬라이즈된 문자열
        expect(option.optionName).not.toContain('[object Object]');
        expect(typeof option.optionName).toBe('string');

        // changes에 [object Object]가 포함되지 않음
        expect(option.changes).not.toContain('[object Object]');
        expect(option.changes).toContain('블랙11');
    });

    it('productName이 로컬라이즈되어 표시됨', () => {
        const products = createMockProducts();
        products[0].options[0]._modified = true;

        mockDataSources.products = { data: { data: products } };
        mockLocalState = {
            selectedItems: [99],
            modifiedOptionIds: ['99-506'],
            modifiedOptionFields: { '99-506': ['option_name'] },
            modifiedProductIds: [99],
            modifiedProductFields: { '99': ['name'] },
        };
        mockGlobalState = {};

        buildConfirmDataHandler(
            { handler: 'sirsoft-ecommerce.buildConfirmData' },
            mockContext
        );

        const confirmData = mockGlobalState.bulkConfirmData;
        // 상품명이 한국어로 로컬라이즈
        expect(confirmData.products[0].name).toBe('테스트 상품');
        expect(confirmData.options[0].productName).toBe('테스트 상품');
    });

    it('modifiedOptionFields가 비어있으면 인라인 수정으로 표시되나 필드별 변경 없음', () => {
        const products = createMockProducts();
        products[0].options[0]._modified = true;

        mockDataSources.products = { data: { data: products } };
        mockLocalState = {
            selectedItems: [99],
            modifiedOptionIds: ['99-506'],
            modifiedOptionFields: {}, // 필드 추적 없음 (레거시 호환)
        };
        mockGlobalState = {};

        buildConfirmDataHandler(
            { handler: 'sirsoft-ecommerce.buildConfirmData' },
            mockContext
        );

        const confirmData = mockGlobalState.bulkConfirmData;
        const option = confirmData.options.find((o: any) => o.optionId === 506);
        // 수정된 필드가 명시되지 않으므로 "인라인 수정됨" 표시
        expect(option.changes).toBe('인라인 수정됨');
    });

    it('일괄 변경 + 인라인 수정 혼합 시 올바르게 보고', () => {
        const products = createMockProducts();
        products[0].options[1]._modified = true;
        (products[0].options[1] as any).option_name = { en: 'White', ko: '화이트22' };

        mockDataSources.products = { data: { data: products } };
        mockLocalState = {
            selectedItems: [99],
            modifiedOptionIds: ['99-507'],
            modifiedOptionFields: { '99-507': ['option_name'] },
        };
        mockGlobalState = {
            bulkSalesStatus: 'suspended',
        };

        buildConfirmDataHandler(
            { handler: 'sirsoft-ecommerce.buildConfirmData' },
            mockContext
        );

        const confirmData = mockGlobalState.bulkConfirmData;
        expect(confirmData.summary.hasBulkChanges).toBe(true);
        expect(confirmData.summary.hasInlineChanges).toBe(true);

        // 상품에 일괄 변경 반영
        expect(confirmData.products[0].changes).toContain('판매상태');

        // 옵션 507에 옵션명 변경만 반영 (조정가/재고 미포함)
        const opt507 = confirmData.options.find((o: any) => o.optionId === 507);
        expect(opt507.changes).toContain('옵션명');
        expect(opt507.changes).toContain('화이트22');
        expect(opt507.changes).not.toContain('조정가');
    });
});
