/**
 * 상품 목록 핸들러 테스트
 *
 * handleProductRowActionHandler의 동작을 검증합니다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { handleProductRowActionHandler } from '../productListHandlers';
import type { ActionContext } from '../../types';

describe('handleProductRowActionHandler', () => {
    let mockNavigate: ReturnType<typeof vi.fn>;
    let mockSetLocal: ReturnType<typeof vi.fn>;
    let mockApiGet: ReturnType<typeof vi.fn>;
    let mockContext: ActionContext;
    let originalG7Core: any;

    const mockRow = {
        id: 123,
        product_code: 'PROD-001',
        name: { ko: '테스트 상품', en: 'Test Product' },
        thumbnail: '/images/test.jpg',
    };

    beforeEach(() => {
        mockNavigate = vi.fn();
        mockSetLocal = vi.fn();
        mockApiGet = vi.fn();
        mockContext = {
            setLocalState: vi.fn(),
            getLocalState: vi.fn().mockReturnValue({}),
        };

        // 기존 G7Core 백업
        originalG7Core = (window as any).G7Core;

        // G7Core 모킹
        (window as any).G7Core = {
            navigate: mockNavigate,
            state: {
                setLocal: mockSetLocal,
            },
            api: {
                get: mockApiGet,
            },
        };
    });

    afterEach(() => {
        // G7Core 복원
        (window as any).G7Core = originalG7Core;
        vi.restoreAllMocks();
    });

    describe('파라미터 검증', () => {
        it('actionId가 없으면 실패를 반환해야 한다', async () => {
            const result = await handleProductRowActionHandler(
                { handler: 'test', params: { row: mockRow } as any },
                mockContext
            );

            expect(result.success).toBe(false);
        });

        it('row.id가 없으면 실패를 반환해야 한다', async () => {
            const result = await handleProductRowActionHandler(
                { handler: 'test', params: { actionId: 'edit', row: { product_code: 'PROD-001' } } as any },
                mockContext
            );

            expect(result.success).toBe(false);
        });

        it('params가 없으면 실패를 반환해야 한다', async () => {
            const result = await handleProductRowActionHandler(
                { handler: 'test' },
                mockContext
            );

            expect(result.success).toBe(false);
        });

        it('알 수 없는 actionId면 실패를 반환해야 한다', async () => {
            const result = await handleProductRowActionHandler(
                { handler: 'test', params: { actionId: 'unknown' as any, row: mockRow } },
                mockContext
            );

            expect(result.success).toBe(false);
        });
    });

    describe('edit 액션', () => {
        it('상품 수정 페이지로 이동해야 한다', async () => {
            const result = await handleProductRowActionHandler(
                { handler: 'test', params: { actionId: 'edit', row: mockRow } },
                mockContext
            );

            expect(result.success).toBe(true);
            expect(result.action).toBe('edit');
            expect(result.targetId).toBe(123);
            expect(mockNavigate).toHaveBeenCalledWith('/admin/ecommerce/products/PROD-001/edit');
        });

        it('G7Core.navigate가 없어도 에러가 발생하지 않아야 한다', async () => {
            (window as any).G7Core = { state: { setLocal: mockSetLocal } };

            const result = await handleProductRowActionHandler(
                { handler: 'test', params: { actionId: 'edit', row: mockRow } },
                mockContext
            );

            expect(result.success).toBe(true);
        });
    });

    describe('copy 액션', () => {
        it('복사 모달을 열고 타겟 상품을 설정해야 한다', async () => {
            const result = await handleProductRowActionHandler(
                { handler: 'test', params: { actionId: 'copy', row: mockRow } },
                mockContext
            );

            expect(result.success).toBe(true);
            expect(result.action).toBe('copy');
            expect(result.targetId).toBe(123);
            expect(mockSetLocal).toHaveBeenCalledWith({
                productList: { copyTargetProduct: mockRow },
                ui: { showCopyModal: true },
            });
        });
    });

    describe('delete 액션', () => {
        it('삭제 가능할 때 모달을 열어야 한다', async () => {
            const canDeleteResponse = {
                data: {
                    canDelete: true,
                    reason: null,
                    relatedData: {
                        orders: 0,
                        images: 3,
                        options: 2,
                        additionalOptions: 1,
                    },
                },
            };
            mockApiGet.mockResolvedValue(canDeleteResponse);

            const result = await handleProductRowActionHandler(
                { handler: 'test', params: { actionId: 'delete', row: mockRow } },
                mockContext
            );

            expect(result.success).toBe(true);
            expect(result.action).toBe('delete');
            expect(result.targetId).toBe(123);
            expect(mockApiGet).toHaveBeenCalledWith(
                '/api/modules/sirsoft-ecommerce/admin/products/123/can-delete'
            );
            expect(mockSetLocal).toHaveBeenCalledWith({
                productList: {
                    deleteTargetProduct: mockRow,
                    canDelete: true,
                    deleteBlockReason: null,
                    relatedData: canDeleteResponse.data.relatedData,
                },
                ui: { showDeleteModal: true },
            });
        });

        it('삭제 불가능할 때 사유와 함께 모달을 열어야 한다', async () => {
            const canDeleteResponse = {
                data: {
                    canDelete: false,
                    reason: '이 상품은 5건의 주문 이력이 있어 삭제할 수 없습니다.',
                    relatedData: {
                        orders: 5,
                        images: 3,
                        options: 2,
                    },
                },
            };
            mockApiGet.mockResolvedValue(canDeleteResponse);

            const result = await handleProductRowActionHandler(
                { handler: 'test', params: { actionId: 'delete', row: mockRow } },
                mockContext
            );

            expect(result.success).toBe(true);
            expect(mockSetLocal).toHaveBeenCalledWith({
                productList: {
                    deleteTargetProduct: mockRow,
                    canDelete: false,
                    deleteBlockReason: '이 상품은 5건의 주문 이력이 있어 삭제할 수 없습니다.',
                    relatedData: canDeleteResponse.data.relatedData,
                },
                ui: { showDeleteModal: true },
            });
        });

        it('API 호출 실패 시 삭제 불가로 처리해야 한다', async () => {
            mockApiGet.mockRejectedValue(new Error('Network error'));

            const result = await handleProductRowActionHandler(
                { handler: 'test', params: { actionId: 'delete', row: mockRow } },
                mockContext
            );

            expect(result.success).toBe(true);
            expect(mockSetLocal).toHaveBeenCalledWith({
                productList: {
                    deleteTargetProduct: mockRow,
                    canDelete: false,
                    deleteBlockReason: '삭제 가능 여부를 확인할 수 없습니다.',
                    relatedData: null,
                },
                ui: { showDeleteModal: true },
            });
        });

        it('API 응답이 null일 때 기본값을 사용해야 한다', async () => {
            mockApiGet.mockResolvedValue({ data: null });

            const result = await handleProductRowActionHandler(
                { handler: 'test', params: { actionId: 'delete', row: mockRow } },
                mockContext
            );

            expect(result.success).toBe(true);
            expect(mockSetLocal).toHaveBeenCalledWith({
                productList: {
                    deleteTargetProduct: mockRow,
                    canDelete: true,
                    deleteBlockReason: null,
                    relatedData: null,
                },
                ui: { showDeleteModal: true },
            });
        });
    });

    describe('G7Core 없음', () => {
        it('G7Core가 없어도 에러 없이 성공을 반환해야 한다', async () => {
            (window as any).G7Core = undefined;

            const result = await handleProductRowActionHandler(
                { handler: 'test', params: { actionId: 'edit', row: mockRow } },
                mockContext
            );

            expect(result.success).toBe(true);
        });

        it('G7Core.state가 없어도 에러 없이 성공을 반환해야 한다', async () => {
            (window as any).G7Core = { navigate: mockNavigate };

            const result = await handleProductRowActionHandler(
                { handler: 'test', params: { actionId: 'copy', row: mockRow } },
                mockContext
            );

            expect(result.success).toBe(true);
        });

        it('G7Core.api가 없어도 에러 없이 처리해야 한다', async () => {
            (window as any).G7Core = { state: { setLocal: mockSetLocal } };

            const result = await handleProductRowActionHandler(
                { handler: 'test', params: { actionId: 'delete', row: mockRow } },
                mockContext
            );

            // api가 없으면 api?.get이 undefined를 반환하고, await undefined는 에러를 일으키지 않음
            // 하지만 response가 undefined이므로 catch 블록으로 가지 않고 정상 흐름 진행
            expect(result.success).toBe(true);
        });
    });
});
