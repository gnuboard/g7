/**
 * userReviewHandlers 테스트
 *
 * @description
 * - submitReview: 리뷰 작성 API 호출 + 이미지 순차 업로드 + 성공/실패 시 상태 업데이트
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { submitReviewHandler } from '../../handlers/userReviewHandlers';

let mockLocalState: Record<string, any> = {};

const mockG7Core = {
    state: {
        getLocal: () => mockLocalState,
        setLocal: vi.fn((updates: Record<string, any>) => {
            mockLocalState = { ...mockLocalState, ...updates };
        }),
    },
    api: {
        post: vi.fn(),
    },
    toast: {
        success: vi.fn(),
        warning: vi.fn(),
        error: vi.fn(),
    },
    modal: {
        close: vi.fn(),
        open: vi.fn(),
    },
    dispatch: vi.fn(),
    t: vi.fn((key: string) => key),
    createLogger: () => ({
        log: vi.fn(),
        warn: vi.fn(),
        error: vi.fn(),
    }),
};

describe('submitReviewHandler', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockLocalState = {
            reviewRating: 5,
            reviewContent: '아주 좋은 상품입니다. 만족합니다!',
            reviewImages: [],
            isSubmittingReview: false,
            reviewError: null,
            reviewTarget: { id: 1, product_name: 'Test Product' },
        };
        (window as any).G7Core = mockG7Core;
    });

    it('리뷰 등록 성공 시 상태 초기화 + 모달 닫기 + 리패치', async () => {
        mockG7Core.api.post.mockResolvedValue({
            success: true,
            data: { id: 100 },
            message: '리뷰가 등록되었습니다.',
        });

        await submitReviewHandler(
            { handler: 'submitReview', params: { orderId: 1, optionId: 2, productId: 3 } },
            {} as any
        );

        // API 호출 확인
        expect(mockG7Core.api.post).toHaveBeenCalledWith(
            '/api/modules/sirsoft-ecommerce/user/reviews',
            expect.objectContaining({
                order_option_id: 2,
                product_id: 3,
                rating: 5,
                content: '아주 좋은 상품입니다. 만족합니다!',
            })
        );

        // 상태 초기화 확인
        expect(mockG7Core.state.setLocal).toHaveBeenCalledWith(
            expect.objectContaining({
                isSubmittingReview: false,
                reviewTarget: null,
                reviewRating: 5,
                reviewContent: '',
                reviewImages: [],
                reviewError: null,
            })
        );

        // 모달 닫기 확인
        expect(mockG7Core.modal.close).toHaveBeenCalledWith('modal_write_review');

        // 데이터 리패치 확인
        expect(mockG7Core.dispatch).toHaveBeenCalledWith(
            expect.objectContaining({
                handler: 'refetchDataSource',
                params: { dataSourceId: 'order' },
            })
        );

        // 성공 토스트
        expect(mockG7Core.toast.success).toHaveBeenCalledWith('리뷰가 등록되었습니다.');
    });

    it('리뷰 내용이 10자 미만이면 에러 상태 설정', async () => {
        mockLocalState.reviewContent = '짧은글';

        await submitReviewHandler(
            { handler: 'submitReview', params: { orderId: 1, optionId: 2, productId: 3 } },
            {} as any
        );

        // API 호출 없어야 함
        expect(mockG7Core.api.post).not.toHaveBeenCalled();

        // reviewError 설정 확인
        expect(mockG7Core.state.setLocal).toHaveBeenCalledWith(
            expect.objectContaining({ reviewError: expect.any(String) })
        );
    });

    it('이미지 포함 시 순차 업로드', async () => {
        const mockFile1 = new File(['img1'], 'test1.jpg', { type: 'image/jpeg' });
        const mockFile2 = new File(['img2'], 'test2.jpg', { type: 'image/jpeg' });
        mockLocalState.reviewImages = [mockFile1, mockFile2];

        mockG7Core.api.post
            .mockResolvedValueOnce({ success: true, data: { id: 100 }, message: 'OK' }) // 리뷰 생성
            .mockResolvedValueOnce({ success: true }) // 이미지 1
            .mockResolvedValueOnce({ success: true }); // 이미지 2

        await submitReviewHandler(
            { handler: 'submitReview', params: { orderId: 1, optionId: 2, productId: 3 } },
            {} as any
        );

        // 총 3회 호출: 리뷰 1 + 이미지 2
        expect(mockG7Core.api.post).toHaveBeenCalledTimes(3);

        // 이미지 업로드 호출 확인
        expect(mockG7Core.api.post).toHaveBeenNthCalledWith(
            2,
            '/api/modules/sirsoft-ecommerce/user/reviews/100/images',
            expect.any(FormData)
        );
        expect(mockG7Core.api.post).toHaveBeenNthCalledWith(
            3,
            '/api/modules/sirsoft-ecommerce/user/reviews/100/images',
            expect.any(FormData)
        );
    });

    it('이미지 업로드 실패 시 warning 토스트 + 나머지 계속 진행', async () => {
        const mockFile1 = new File(['img1'], 'test1.jpg', { type: 'image/jpeg' });
        const mockFile2 = new File(['img2'], 'test2.jpg', { type: 'image/jpeg' });
        mockLocalState.reviewImages = [mockFile1, mockFile2];

        mockG7Core.api.post
            .mockResolvedValueOnce({ success: true, data: { id: 100 }, message: 'OK' })
            .mockRejectedValueOnce(new Error('Upload failed')) // 이미지 1 실패
            .mockResolvedValueOnce({ success: true }); // 이미지 2 성공

        await submitReviewHandler(
            { handler: 'submitReview', params: { orderId: 1, optionId: 2, productId: 3 } },
            {} as any
        );

        // warning 토스트 호출 (이미지 1 실패)
        expect(mockG7Core.toast.warning).toHaveBeenCalledTimes(1);

        // 성공 토스트도 호출 (리뷰 자체는 성공)
        expect(mockG7Core.toast.success).toHaveBeenCalled();
    });

    it('422 에러 시 reviewError에 validation 메시지 설정', async () => {
        mockG7Core.api.post.mockRejectedValue({
            response: {
                status: 422,
                data: {
                    message: 'Validation failed',
                    errors: { content: ['리뷰 내용은 필수입니다.'] },
                },
            },
        });

        await submitReviewHandler(
            { handler: 'submitReview', params: { orderId: 1, optionId: 2, productId: 3 } },
            {} as any
        );

        // reviewError에 첫 번째 validation 에러 설정
        expect(mockG7Core.state.setLocal).toHaveBeenCalledWith(
            expect.objectContaining({
                isSubmittingReview: false,
                reviewError: '리뷰 내용은 필수입니다.',
            })
        );

        // 에러 토스트
        expect(mockG7Core.toast.error).toHaveBeenCalled();
    });

    it('params 누락 시 조기 반환', async () => {
        await submitReviewHandler(
            { handler: 'submitReview', params: {} as any },
            {} as any
        );

        expect(mockG7Core.api.post).not.toHaveBeenCalled();
    });
});
