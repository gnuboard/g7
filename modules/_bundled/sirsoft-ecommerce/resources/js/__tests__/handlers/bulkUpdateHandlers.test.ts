// e2e:allow MP13 — i18n/UX 소품 묶음(라벨 표시·링크 분기·모달 조건부 필드·placeholder 치환). 핵심 회귀는 Vitest(모듈 22 + 템플릿 13)로 커버, 라이브 검수는 계획서 §14 Chrome MCP 매트릭스(PO 환경)로 수행.
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
import { buildConfirmDataHandler, bulkUpdateHandler } from '../../handlers/bulkUpdateHandlers';
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
            'sirsoft-ecommerce.admin.product.messages.bulk_summary_price_adjustment_inline': '조정가: {value}',
            'sirsoft-ecommerce.admin.product.messages.bulk_summary_stock': '재고: {method} {value}',
            'sirsoft-ecommerce.admin.product.messages.bulk_summary_stock_inline': '재고: {value}',
            'sirsoft-ecommerce.admin.product.messages.bulk_summary_safe_stock': '안전재고: {value}',
            'sirsoft-ecommerce.admin.product.messages.bulk_summary_is_active': '사용: {value}',
            'sirsoft-ecommerce.admin.product.messages.bulk_summary_selling_price': '판매가: {price}',
            'sirsoft-ecommerce.admin.product.messages.bulk_summary_sales_status': '판매상태: {status}',
            'sirsoft-ecommerce.admin.product.messages.bulk_summary_display_status': '전시상태: {status}',
            'sirsoft-ecommerce.admin.product.messages.bulk_summary_name_changed': '상품명 변경',
            'sirsoft-ecommerce.admin.product.messages.bulk_summary_list_price': '정가: {price}',
            'sirsoft-ecommerce.admin.product.messages.bulk_summary_inline_modified': '인라인 수정됨',
            'sirsoft-ecommerce.admin.product.messages.bulk_summary_bulk_applied': '일괄 변경 적용',
            'sirsoft-ecommerce.admin.product.messages.bulk_summary_selected': '선택됨',
            // 판매/전시 상태 enum 라벨 (드롭다운과 동일 SSoT)
            'sirsoft-ecommerce.enums.sales_status.on_sale': '판매중',
            'sirsoft-ecommerce.enums.sales_status.suspended': '판매중지',
            'sirsoft-ecommerce.enums.sales_status.sold_out': '품절',
            'sirsoft-ecommerce.enums.sales_status.coming_soon': '출시예정',
            'sirsoft-ecommerce.enums.display_status.visible': '전시중',
            'sirsoft-ecommerce.enums.display_status.hidden': '전시중지',
            // 일괄 업데이트 실패 폴백 메시지
            'sirsoft-ecommerce.admin.product.bulk.update_error': '일괄 업데이트에 실패했습니다.',
            'sirsoft-ecommerce.admin.product.bulk.option_update_error': '옵션 일괄 업데이트에 실패했습니다.',
            'sirsoft-ecommerce.admin.product.bulk.no_selection': '선택된 항목이 없습니다.',
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

    it('안전재고/사용 변경이 i18n 키 라벨로 표시됨 (하드코딩 한글 없음 — A20 결함C)', () => {
        const products = createMockProducts();
        products[0].options[0]._modified = true;

        mockDataSources.products = { data: { data: products } };
        mockLocalState = {
            selectedItems: [99],
            selectedOptionIds: ['99-506'],
            modifiedOptionIds: ['99-506'],
            modifiedOptionFields: { '99-506': ['safe_stock_quantity', 'is_active'] },
        };
        mockGlobalState = {};

        buildConfirmDataHandler(
            { handler: 'sirsoft-ecommerce.buildConfirmData' },
            mockContext
        );

        const optionChanges = mockGlobalState.bulkConfirmData.options[0].changes;
        // i18n 키 사용 확인 (mock 사전 라벨)
        expect(optionChanges).toContain('안전재고');
        expect(optionChanges).toContain('사용');
        // G7Core.t 가 새 키로 호출됨
        const calledKeys = (mockG7Core.t as any).mock.calls.map((c: any[]) => c[0]);
        expect(calledKeys).toContain('sirsoft-ecommerce.admin.product.messages.bulk_summary_safe_stock');
        expect(calledKeys).toContain('sirsoft-ecommerce.admin.product.messages.bulk_summary_is_active');
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

    // 회귀: 일괄 재고/가격 조건은 표시용 문자열("+10개")로 저장됨.
    // 옵션 요약에서 sc.method/sc.value(객체 접근)로 읽으면 "재고: undefined undefined" 노출
    it('일괄 재고 변경 시 옵션 요약에 undefined 미노출 (표시 문자열 직접 사용)', () => {
        const products = createMockProducts();
        mockDataSources.products = { data: { data: products } };
        mockLocalState = { selectedItems: [99] };
        mockGlobalState = {
            // 레이아웃이 실제로 저장하는 형태: 표시용 문자열
            bulkStockCondition: '+10개',
            bulkStockMethod: 'increase',
            bulkStockValue: '10',
        };

        buildConfirmDataHandler(
            { handler: 'sirsoft-ecommerce.buildConfirmData' },
            mockContext
        );

        const confirmData = mockGlobalState.bulkConfirmData;
        expect(confirmData.options.length).toBeGreaterThan(0);
        confirmData.options.forEach((opt: any) => {
            expect(opt.changes).toContain('재고');
            expect(opt.changes).not.toContain('undefined');
            // 표시 문자열이 그대로 반영
            expect(opt.changes).toContain('+10개');
        });
    });

    it('일괄 가격 변경 시 옵션 요약에 undefined 미노출 (표시 문자열 직접 사용)', () => {
        const products = createMockProducts();
        mockDataSources.products = { data: { data: products } };
        mockLocalState = { selectedItems: [99] };
        mockGlobalState = {
            bulkPriceCondition: '+1000원',
            bulkPriceMethod: 'increase',
            bulkPriceValue: '1000',
            bulkPriceUnit: 'won',
        };

        buildConfirmDataHandler(
            { handler: 'sirsoft-ecommerce.buildConfirmData' },
            mockContext
        );

        const confirmData = mockGlobalState.bulkConfirmData;
        expect(confirmData.options.length).toBeGreaterThan(0);
        confirmData.options.forEach((opt: any) => {
            expect(opt.changes).toContain('조정가');
            expect(opt.changes).not.toContain('undefined');
            expect(opt.changes).toContain('+1000원');
        });
    });
});

// ============================================================
// 판매/전시 상태 라벨 변환 (raw enum 값 노출 회귀 방지)
// ============================================================
describe('buildConfirmDataHandler - 판매/전시 상태 라벨 변환', () => {
    it('일괄 변경 판매상태가 raw enum(on_sale)이 아닌 라벨(판매중)로 표시됨', () => {
        const products = createMockProducts();
        mockDataSources.products = { data: { data: products } };
        mockLocalState = { selectedItems: [99] };
        mockGlobalState = {
            bulkSelectedItems: [99],
            bulkSalesStatus: 'on_sale',
            bulkDisplayStatus: 'visible',
        };

        buildConfirmDataHandler(
            { handler: 'sirsoft-ecommerce.buildConfirmData' },
            mockContext
        );

        const changes = mockGlobalState.bulkConfirmData.products[0].changes;
        expect(changes).toContain('판매상태: 판매중');
        expect(changes).toContain('전시상태: 전시중');
        // raw enum 값이 노출되지 않아야 함
        expect(changes).not.toContain('on_sale');
        expect(changes).not.toContain('visible');
    });

    it('인라인 판매상태 수정도 라벨로 표시됨 (suspended → 판매중지)', () => {
        const products = createMockProducts();
        products[0].sales_status = 'suspended';
        (products[0] as any)._modified = true;
        mockDataSources.products = { data: { data: products } };
        mockLocalState = {
            selectedItems: [99],
            modifiedProductIds: [99],
            modifiedProductFields: { '99': ['sales_status'] },
        };
        mockGlobalState = {};

        buildConfirmDataHandler(
            { handler: 'sirsoft-ecommerce.buildConfirmData' },
            mockContext
        );

        const changes = mockGlobalState.bulkConfirmData.products[0].changes;
        expect(changes).toContain('판매상태: 판매중지');
        expect(changes).not.toContain('suspended');
    });

    it('미정의 enum 값은 원본으로 폴백 (라벨 누락 시 빈 문자열 방지)', () => {
        const products = createMockProducts();
        mockDataSources.products = { data: { data: products } };
        mockLocalState = { selectedItems: [99] };
        mockGlobalState = {
            bulkSelectedItems: [99],
            bulkSalesStatus: 'unknown_status',
        };

        buildConfirmDataHandler(
            { handler: 'sirsoft-ecommerce.buildConfirmData' },
            mockContext
        );

        const changes = mockGlobalState.bulkConfirmData.products[0].changes;
        // 라벨이 없으면 원본 값으로 폴백 (빈 "판매상태: " 방지)
        expect(changes).toContain('판매상태: unknown_status');
    });
});

// ============================================================
// A27 — 전체선택 SSoT 통일 (buildConfirmData 가 _global 우선)
// ============================================================
describe('buildConfirmDataHandler - A27 선택 소스 SSoT', () => {
    it('상품 전체선택: _local 비어도 _global.bulkSelectedItems 로 confirmData 채움', () => {
        const products = createMockProducts();
        mockDataSources.products = { data: { data: products } };

        // 적용 sequence 가 setState(target:global) 로 복사한 상태 재현:
        // _local.selectedItems 는 비어있고(전체선택 경로) _global.bulkSelectedItems 에만 존재
        mockLocalState = {
            selectedItems: [],
            selectedOptionIds: [],
        };
        mockGlobalState = {
            bulkSelectedItems: [99],
            bulkSelectedOptionIds: [],
            bulkPriceCondition: { method: 'add', value: 1000 },
        };

        buildConfirmDataHandler(
            { handler: 'sirsoft-ecommerce.buildConfirmData' },
            mockContext
        );

        const confirmData = mockGlobalState.bulkConfirmData;
        // 수정 전(_local 단독)이면 0 → 수정 후 _global 우선이라 상품/옵션 채워짐
        expect(confirmData.products.length).toBeGreaterThan(0);
        expect(confirmData.products[0].id).toBe(99);
        // 상품 체크 시 옵션도 포함
        expect(confirmData.options.length).toBeGreaterThan(0);
    });

    it('옵션 헤더 전체선택: _global.bulkSelectedOptionIds 로 옵션 confirmData 채움', () => {
        const products = createMockProducts();
        mockDataSources.products = { data: { data: products } };

        mockLocalState = {
            selectedItems: [],
            selectedOptionIds: [],
        };
        mockGlobalState = {
            bulkSelectedItems: [],
            bulkSelectedOptionIds: ['99-506', '99-507'],
            bulkPriceCondition: { method: 'add', value: 500 },
        };

        buildConfirmDataHandler(
            { handler: 'sirsoft-ecommerce.buildConfirmData' },
            mockContext
        );

        const confirmData = mockGlobalState.bulkConfirmData;
        expect(confirmData.options.length).toBe(2);
    });

    it('개별 일부선택(대조군): _local 로도 정상 동작 (회귀)', () => {
        const products = createMockProducts();
        mockDataSources.products = { data: { data: products } };

        // _global 미설정, _local 만 (개별 선택은 모달 경유 없이도 동작)
        mockLocalState = {
            selectedItems: [99],
            selectedOptionIds: [],
        };
        mockGlobalState = {
            bulkPriceCondition: { method: 'add', value: 1000 },
        };

        buildConfirmDataHandler(
            { handler: 'sirsoft-ecommerce.buildConfirmData' },
            mockContext
        );

        const confirmData = mockGlobalState.bulkConfirmData;
        expect(confirmData.products.length).toBeGreaterThan(0);
    });

    it('_global 과 _local 둘 다 있으면 _global 우선 (bulkUpdate 와 동일)', () => {
        const products = createMockProducts();
        mockDataSources.products = { data: { data: products } };

        mockLocalState = {
            selectedItems: [], // local 비어있음
            selectedOptionIds: [],
        };
        mockGlobalState = {
            bulkSelectedItems: [99],
            bulkPriceCondition: { method: 'add', value: 1000 },
        };

        buildConfirmDataHandler(
            { handler: 'sirsoft-ecommerce.buildConfirmData' },
            mockContext
        );

        // _global 우선 → 99 반영
        expect(mockGlobalState.bulkConfirmData.products[0].id).toBe(99);
    });
});

describe('bulkUpdateHandler - 실패 시 서버 에러 노출', () => {
    it('검증 실패 시 서버 message 를 토스트에 그대로 표시하고 상세 에러를 _global.bulkUpdateErrors 에 저장한다', async () => {
        const products = createMockProducts();
        mockDataSources.products = { data: { data: products } };

        // 옵션명 인라인 수정 상태
        mockLocalState = {
            selectedItems: [99],
            modifiedOptionIds: ['99-506'],
            modifiedOptionFields: { '99-506': ['option_name'] },
        };
        mockGlobalState = { bulkSelectedItems: [99] };

        // 서버가 422 검증 에러로 거부 (axios 에러 형태)
        mockG7Core.api.patch.mockRejectedValueOnce({
            response: {
                data: {
                    message: 'option_items.0.option_name.en 필드는 문자열이어야 합니다. (and 1 more error)',
                    errors: {
                        'option_items.0.option_name.en': ['option_items.0.option_name.en 필드는 문자열이어야 합니다.'],
                        'option_items.0.option_name.ja': ['option_items.0.option_name.ja 필드는 문자열이어야 합니다.'],
                    },
                },
            },
        });

        bulkUpdateHandler({ handler: 'sirsoft-ecommerce.bulkUpdate' }, mockContext);

        await vi.waitFor(() => {
            expect(mockG7Core.toast.error).toHaveBeenCalled();
        });

        // 토스트: 고정 폴백이 아닌 서버 message 가 그대로 노출
        expect(mockG7Core.toast.error).toHaveBeenCalledWith(
            'option_items.0.option_name.en 필드는 문자열이어야 합니다. (and 1 more error)'
        );

        // 모달: 상세 에러 2건이 _global.bulkUpdateErrors 에 저장
        expect(mockGlobalState.bulkUpdateErrors).toEqual([
            'option_items.0.option_name.en 필드는 문자열이어야 합니다.',
            'option_items.0.option_name.ja 필드는 문자열이어야 합니다.',
        ]);

        // 모달은 닫지 않음 (사용자가 에러 확인 가능)
        expect(mockG7Core.modal.close).not.toHaveBeenCalled();
    });

    it('서버 message 가 없으면 폴백 다국어 문자열을 토스트로 표시한다', async () => {
        const products = createMockProducts();
        mockDataSources.products = { data: { data: products } };

        mockLocalState = {
            selectedItems: [99],
            modifiedOptionIds: ['99-506'],
            modifiedOptionFields: { '99-506': ['sku'] },
        };
        mockGlobalState = { bulkSelectedItems: [99] };

        // 네트워크 에러 (response 없음)
        mockG7Core.api.patch.mockRejectedValueOnce(new Error('Network Error'));

        bulkUpdateHandler({ handler: 'sirsoft-ecommerce.bulkUpdate' }, mockContext);

        await vi.waitFor(() => {
            expect(mockG7Core.toast.error).toHaveBeenCalled();
        });

        // error.message → 폴백 순. error.message 가 있으므로 그대로 노출
        expect(mockG7Core.toast.error).toHaveBeenCalledWith('Network Error');
    });
});
