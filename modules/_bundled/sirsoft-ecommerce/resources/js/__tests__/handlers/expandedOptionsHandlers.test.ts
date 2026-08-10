/**
 * loadExpandedOptionsHandler 테스트 (상품 목록 확장 행 옵션 지연 로딩)
 *
 * @description
 * 목록 응답이 옵션을 싣지 않게 되면서, 옵션은 행을 펼칠 때 배치 엔드포인트가 공급한다.
 * 이 테스트가 고정하는 계약은 다음과 같다.
 *
 * - 아직 안 불러온 행만 요청한다 (`undefined` = 미로드, `[]` = 로드 완료·옵션 없음)
 * - `options_total_count === 0` 이면 네트워크를 쓰지 않는다
 * - 같은 상품에 대한 중복 요청을 병합한다
 * - 병합은 응답 콜백 안에서 데이터소스를 **다시 읽어** 수행한다 (대기 중 인라인 편집 보존)
 * - 응답에 없는 요청 ID 도 `[]` 로 확정한다 (무한 스켈레톤 방지)
 * - 체크된 상품의 옵션은 선택 상태에 합쳐진다
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
    loadExpandedOptionsHandler,
    retryExpandedOptionsHandler,
    reloadExpandedOptions,
    __resetExpandedOptionsState,
} from '../../handlers/expandedOptionsHandlers';

let mockLocalState: Record<string, any> = {};
let mockDataSource: Record<string, any> = {};
let apiGet: any;
let toastError: any;

const mockContext = {} as any;

function makeG7Core() {
    return {
        state: {
            getLocal: () => mockLocalState,
            get: () => ({ _local: mockLocalState }),
            setLocal: vi.fn((updates: Record<string, any>) => {
                mockLocalState = { ...mockLocalState, ...updates };
            }),
            set: vi.fn(),
        },
        dataSource: {
            get: vi.fn((id: string) => mockDataSource[id]),
            set: vi.fn((id: string, value: any) => {
                mockDataSource[id] = value;
            }),
            refetch: vi.fn(() => Promise.resolve()),
        },
        api: {
            get: apiGet,
        },
        toast: {
            error: toastError,
            success: vi.fn(),
        },
        t: (key: string) => key,
        createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
    };
}

/**
 * products 데이터소스를 시드합니다.
 *
 * @param products 상품 배열
 */
function seedProducts(products: any[]): void {
    mockDataSource['products'] = {
        success: true,
        data: {
            data: products,
            pagination: { current_page: 1 },
        },
    };
}

/** 데이터소스에서 상품을 찾습니다. */
function findProduct(id: number): any {
    return mockDataSource['products'].data.data.find((p: any) => p.id === id);
}

beforeEach(() => {
    __resetExpandedOptionsState();
    mockLocalState = {};
    mockDataSource = {};
    apiGet = vi.fn(() =>
        Promise.resolve({ data: { options: {}, product_ids: [] } })
    );
    toastError = vi.fn();
    (window as any).G7Core = makeG7Core();
    vi.clearAllMocks();
});

afterEach(() => {
    delete (window as any).G7Core;
});

describe('loadExpandedOptionsHandler', () => {
    it('확장 상태를 먼저 반영한 뒤 미로드 행만 요청한다', async () => {
        seedProducts([
            { id: 1, options_total_count: 2 },
            { id: 2, options_total_count: 1, options: [{ id: 20 }] },
        ]);

        apiGet.mockResolvedValue({
            data: { options: { '1': [{ id: 10 }, { id: 11 }] }, product_ids: [1] },
        });

        loadExpandedOptionsHandler(
            { handler: 'x', params: { expandedRows: [1, 2] } },
            mockContext
        );

        // 확장 상태가 즉시 반영된다 (fetch 결과를 기다리지 않는다)
        expect(mockLocalState.expandedRows).toEqual([1, 2]);

        // 이미 options 배열을 가진 상품 2 는 요청 대상이 아니다
        expect(apiGet).toHaveBeenCalledTimes(1);
        expect(apiGet.mock.calls[0][1]).toEqual({ params: { product_ids: [1] } });

        await vi.waitFor(() => {
            expect(findProduct(1).options).toHaveLength(2);
        });
    });

    // @scenario row_state=re_expanded,option_profile=mixed
    // @effects options_reloaded_after_bulk_save
    it('force 면 이미 로드된 행도 다시 요청한다', async () => {
        seedProducts([{ id: 1, options_total_count: 1, options: [{ id: 10 }] }]);

        apiGet.mockResolvedValue({
            data: { options: { '1': [{ id: 99 }] }, product_ids: [1] },
        });

        loadExpandedOptionsHandler(
            { handler: 'x', params: { productIds: [1], force: true } },
            mockContext
        );

        expect(apiGet).toHaveBeenCalledTimes(1);

        await vi.waitFor(() => {
            expect(findProduct(1).options).toEqual([{ id: 99 }]);
        });
    });

    // @scenario row_state=re_expanded,option_profile=active_only
    // @effects no_refetch_on_re_expand
    it('빈 배열은 "로드 완료·옵션 없음" 이므로 재요청하지 않는다', () => {
        seedProducts([{ id: 1, options_total_count: 0, options: [] }]);

        loadExpandedOptionsHandler(
            { handler: 'x', params: { expandedRows: [1] } },
            mockContext
        );

        expect(apiGet).not.toHaveBeenCalled();
    });

    it('options_total_count 가 0 이면 네트워크 없이 빈 배열로 확정한다', () => {
        seedProducts([{ id: 1, options_total_count: 0 }]);

        loadExpandedOptionsHandler(
            { handler: 'x', params: { expandedRows: [1] } },
            mockContext
        );

        expect(apiGet).not.toHaveBeenCalled();
        expect(findProduct(1).options).toEqual([]);
    });

    // @scenario row_state=expanding,option_profile=active_only
    // @effects inflight_requests_merged
    it('진행 중인 요청이 있는 상품은 중복 요청하지 않는다', () => {
        seedProducts([{ id: 1, options_total_count: 2 }]);

        // 응답이 오지 않는 요청 (in-flight 유지)
        apiGet.mockReturnValue(new Promise(() => {}));

        loadExpandedOptionsHandler({ handler: 'x', params: { expandedRows: [1] } }, mockContext);
        loadExpandedOptionsHandler({ handler: 'x', params: { expandedRows: [1] } }, mockContext);

        expect(apiGet).toHaveBeenCalledTimes(1);
    });

    // @scenario row_state=expanding,option_profile=mixed
    // @effects merge_reads_datasource_again
    it('병합 시 데이터소스를 다시 읽어 대기 중 편집을 보존한다', async () => {
        seedProducts([{ id: 1, options_total_count: 1, name: 'before' }]);

        let resolveApi: (value: any) => void = () => {};
        apiGet.mockReturnValue(new Promise((resolve) => { resolveApi = resolve; }));

        loadExpandedOptionsHandler({ handler: 'x', params: { expandedRows: [1] } }, mockContext);

        // 요청이 날아간 뒤 다른 경로가 같은 상품을 수정한다 (인라인 편집 시뮬레이션)
        seedProducts([{ id: 1, options_total_count: 1, name: 'edited-while-loading' }]);

        resolveApi({ data: { options: { '1': [{ id: 10 }] }, product_ids: [1] } });

        await vi.waitFor(() => {
            expect(findProduct(1).options).toEqual([{ id: 10 }]);
        });

        // 요청 전 스냅샷을 썼다면 'before' 로 되돌아갔을 것이다
        expect(findProduct(1).name).toBe('edited-while-loading');
    });

    // @scenario row_state=expanded,option_profile=active_only
    // @effects missing_ids_resolved_as_empty
    it('응답에 없는 요청 ID 도 빈 배열로 확정한다', async () => {
        seedProducts([
            { id: 1, options_total_count: 1 },
            { id: 2, options_total_count: 1 },
        ]);

        // 상품 2 는 권한 밖 — 응답에서 빠진다
        apiGet.mockResolvedValue({
            data: { options: { '1': [{ id: 10 }] }, product_ids: [1] },
        });

        loadExpandedOptionsHandler(
            { handler: 'x', params: { expandedRows: [1, 2] } },
            mockContext
        );

        await vi.waitFor(() => {
            expect(findProduct(2).options).toEqual([]);
        });
    });

    // @scenario row_state=expanded,option_profile=mixed
    // @effects selected_option_ids_hydrated
    it('체크된 상품의 옵션은 선택 상태에 합쳐진다', async () => {
        seedProducts([{ id: 1, options_total_count: 2 }]);
        mockLocalState.selectedItems = [1];
        mockLocalState.selectedOptionIds = [];

        apiGet.mockResolvedValue({
            data: { options: { '1': [{ id: 10 }, { id: 11 }] }, product_ids: [1] },
        });

        loadExpandedOptionsHandler(
            { handler: 'x', params: { expandedRows: [1] } },
            mockContext
        );

        await vi.waitFor(() => {
            expect(mockLocalState.selectedOptionIds).toEqual(['1-10', '1-11']);
        });
    });

    it('체크되지 않은 상품의 옵션은 선택 상태를 건드리지 않는다', async () => {
        seedProducts([{ id: 1, options_total_count: 1 }]);
        mockLocalState.selectedItems = [];
        mockLocalState.selectedOptionIds = [];

        apiGet.mockResolvedValue({
            data: { options: { '1': [{ id: 10 }] }, product_ids: [1] },
        });

        loadExpandedOptionsHandler({ handler: 'x', params: { expandedRows: [1] } }, mockContext);

        await vi.waitFor(() => {
            expect(findProduct(1).options).toHaveLength(1);
        });

        expect(mockLocalState.selectedOptionIds).toEqual([]);
    });

    it('로딩 중에는 optionsLoadingIds 에 담기고 완료 후 비워진다', async () => {
        seedProducts([{ id: 1, options_total_count: 1 }]);

        let resolveApi: (value: any) => void = () => {};
        apiGet.mockReturnValue(new Promise((resolve) => { resolveApi = resolve; }));

        loadExpandedOptionsHandler({ handler: 'x', params: { expandedRows: [1] } }, mockContext);

        expect(mockLocalState.optionsLoadingIds).toEqual(['1']);

        resolveApi({ data: { options: { '1': [] }, product_ids: [1] } });

        await vi.waitFor(() => {
            expect(mockLocalState.optionsLoadingIds).toEqual([]);
        });
    });

    // @scenario row_state=error,option_profile=active_only
    it('실패 시 optionsErrorIds 에 기록하고 토스트로 알린다', async () => {
        seedProducts([{ id: 1, options_total_count: 1 }]);

        apiGet.mockRejectedValue({ response: { data: { message: '서버 오류' } } });

        loadExpandedOptionsHandler({ handler: 'x', params: { expandedRows: [1] } }, mockContext);

        await vi.waitFor(() => {
            expect(mockLocalState.optionsErrorIds).toEqual(['1']);
        });

        expect(mockLocalState.optionsLoadingIds).toEqual([]);
        expect(toastError).toHaveBeenCalledWith('서버 오류');
    });

    // @scenario row_state=error,option_profile=mixed
    it('실패 후 재시도가 가능하다 (in-flight 가 해제된다)', async () => {
        seedProducts([{ id: 1, options_total_count: 1 }]);

        apiGet.mockRejectedValueOnce({ response: { data: { message: 'boom' } } });

        loadExpandedOptionsHandler({ handler: 'x', params: { expandedRows: [1] } }, mockContext);

        await vi.waitFor(() => {
            expect(mockLocalState.optionsErrorIds).toEqual(['1']);
        });

        apiGet.mockResolvedValue({ data: { options: { '1': [{ id: 10 }] }, product_ids: [1] } });

        loadExpandedOptionsHandler(
            { handler: 'x', params: { productId: 1, force: true } },
            mockContext
        );

        await vi.waitFor(() => {
            expect(findProduct(1).options).toEqual([{ id: 10 }]);
        });

        expect(mockLocalState.optionsErrorIds).toEqual([]);
    });

    it('데이터소스에 없는 ID 는 요청하지 않는다', () => {
        seedProducts([{ id: 1, options_total_count: 1, options: [] }]);

        loadExpandedOptionsHandler(
            { handler: 'x', params: { expandedRows: [999] } },
            mockContext
        );

        expect(apiGet).not.toHaveBeenCalled();
    });
});

describe('retryExpandedOptionsHandler', () => {
    it('레이아웃이 보내는 단수 productId 로 그 행만 다시 요청한다', async () => {
        // 3행을 펼쳐 두고 그중 2번만 실패한 상황 — 재시도는 2번만 다시 불러야 한다.
        seedProducts([
            { id: 1, options_total_count: 1, options: [{ id: 10 }] },
            { id: 2, options_total_count: 1 },
            { id: 3, options_total_count: 1, options: [{ id: 30 }] },
        ]);
        mockLocalState.expandedRows = [1, 2, 3];
        mockLocalState.optionsErrorIds = [2];

        apiGet.mockResolvedValue({
            data: { options: { '2': [{ id: 20 }] }, product_ids: [2] },
        });

        retryExpandedOptionsHandler(
            { handler: 'sirsoft-ecommerce.retryExpandedOptions', params: { productId: 2 } } as any,
            mockContext
        );

        expect(apiGet).toHaveBeenCalledTimes(1);

        // 요청 대상이 2번 한 행뿐이어야 한다. 단수 키가 포워딩되지 않으면 expandedRows 폴백으로
        // 떨어져 이미 성공한 1·3번까지 force 로 다시 불린다.
        const requested = apiGet.mock.calls[0][1]?.params?.product_ids;
        expect(requested).toEqual([2]);

        await vi.waitFor(() => {
            expect(findProduct(2).options).toEqual([{ id: 20 }]);
        });
    });

    it('복수 productIds 형태도 그대로 받는다', () => {
        seedProducts([
            { id: 1, options_total_count: 1, options: [{ id: 10 }] },
            { id: 2, options_total_count: 1 },
        ]);
        mockLocalState.expandedRows = [1, 2];

        retryExpandedOptionsHandler(
            { handler: 'sirsoft-ecommerce.retryExpandedOptions', params: { productIds: [1, 2] } } as any,
            mockContext
        );

        expect(apiGet).toHaveBeenCalledTimes(1);
        expect(apiGet.mock.calls[0][1]?.params?.product_ids).toEqual([1, 2]);
    });
});

describe('reloadExpandedOptions', () => {
    it('펼친 행이 없으면 아무것도 하지 않는다', () => {
        seedProducts([{ id: 1, options_total_count: 1 }]);
        mockLocalState.expandedRows = [];

        reloadExpandedOptions((window as any).G7Core, { force: true });

        expect(apiGet).not.toHaveBeenCalled();
    });

    // @scenario selection=individual_partial,target=stock,source=both_global_priority
    // @effects options_reloaded_after_save
    it('펼친 행을 force 로 다시 가져온다', async () => {
        seedProducts([{ id: 1, options_total_count: 1, options: [{ id: 10, stock_quantity: 1 }] }]);
        mockLocalState.expandedRows = [1];

        apiGet.mockResolvedValue({
            data: { options: { '1': [{ id: 10, stock_quantity: 77 }] }, product_ids: [1] },
        });

        reloadExpandedOptions((window as any).G7Core, { force: true });

        expect(apiGet).toHaveBeenCalledTimes(1);

        await vi.waitFor(() => {
            expect(findProduct(1).options[0].stock_quantity).toBe(77);
        });
    });
});
