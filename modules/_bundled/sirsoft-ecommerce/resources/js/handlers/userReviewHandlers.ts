/**
 * 리뷰 작성 관련 핸들러
 *
 * 사용자 마이페이지 주문상세에서 리뷰 작성을 담당합니다.
 * - 리뷰 텍스트 등록 API 호출
 * - 이미지 순차 업로드
 * - 성공 시 모달 닫기 + 데이터 리패치 + 상태 초기화 + 토스트
 * - 실패 시 토스트 에러 + validation 에러 표시
 */

import type { ActionContext } from '../types';

const logger = ((window as any).G7Core?.createLogger?.('Ecom:Review')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:Review]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:Review]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:Review]', ...args),
};

interface ActionWithParams<T = Record<string, any>> {
    handler: string;
    params?: T;
    [key: string]: any;
}

/**
 * 리뷰 작성 핸들러
 *
 * 리뷰를 등록하고 이미지를 순차 업로드합니다.
 *
 * @example
 * {
 *   "handler": "sirsoft-ecommerce.submitReview",
 *   "params": { "orderId": 1, "optionId": 2, "productId": 3 }
 * }
 *
 * @param action 액션 객체
 * @param _context 액션 컨텍스트
 */
export async function submitReviewHandler(
    action: ActionWithParams<{
        orderId: number; optionId: number; productId: number;
        rating?: number; content?: string; images?: File[];
    }>,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const { orderId, optionId, productId } = action.params || {};
    if (!orderId || !optionId || !productId) {
        logger.error('[submitReview] orderId, optionId 또는 productId 누락');
        return;
    }

    // params 우선, fallback으로 local (모달 스코프 호환 — 취소 모달 패턴과 동일)
    const local = G7Core.state.getLocal();
    const rating = action.params?.rating ?? local.reviewRating ?? 5;
    const content = (action.params?.content ?? local.reviewContent ?? '').trim();
    // FileUploader의 onChange는 PendingFile[] 전달 (각 항목에 .file: File 포함)
    // 주의: _local 상태를 거치면 File 객체의 prototype이 소실될 수 있으므로
    // instanceof File 외에 Blob 기반 검증도 수행
    const rawImages: any[] = action.params?.images ?? local.reviewImages ?? [];
    const images: (File | Blob)[] = rawImages.map((item: any) => {
        if (item instanceof File) return item;
        if (item?.file instanceof File) return item.file;
        if (item?.file instanceof Blob) return item.file;
        // File prototype 소실 시: name, size, type이 있으면 유효한 파일로 간주
        if (item?.file && typeof item.file.name === 'string' && item.file.size > 0) return item.file;
        if (item instanceof Blob) return item;
        return null;
    }).filter((f: any) => f !== null);

    // 프론트엔드 검증
    if (!rating || rating < 1 || rating > 5) {
        G7Core.toast?.error?.(
            G7Core.t?.('sirsoft-ecommerce.user.review.validation.rating_required')
            ?? '별점을 선택해 주세요.'
        );
        return;
    }

    if (content.length < 10) {
        G7Core.state.setLocal({
            reviewError: G7Core.t?.('sirsoft-ecommerce.user.review.validation.content_min')
                ?? '리뷰 내용을 최소 10자 이상 입력해 주세요.',
        });
        return;
    }

    G7Core.state.setLocal({ isSubmittingReview: true, reviewError: null });

    try {
        // 1. 리뷰 텍스트 등록
        const response = await G7Core.api.post(
            '/api/modules/sirsoft-ecommerce/user/reviews',
            {
                order_option_id: optionId,
                product_id: productId,
                rating,
                content,
            }
        );

        if (!response?.success) {
            throw new Error(response?.message || 'Review creation failed');
        }

        const reviewId = response.data?.id;

        // 2. 이미지 순차 업로드
        if (reviewId && images.length > 0) {
            for (let i = 0; i < images.length; i++) {
                const formData = new FormData();
                formData.append('image', images[i]);
                formData.append('sort_order', String(i));

                try {
                    await G7Core.api.post(
                        `/api/modules/sirsoft-ecommerce/user/reviews/${reviewId}/images`,
                        formData,
                        { headers: { 'Content-Type': 'multipart/form-data' } }
                    );
                } catch (imgError: any) {
                    logger.warn(`[submitReview] 이미지 ${i + 1} 업로드 실패:`, imgError);
                    // 이미지 업로드 실패는 토스트로 알리고 계속 진행
                    G7Core.toast?.warning?.(
                        G7Core.t?.('sirsoft-ecommerce.user.review.image_upload_partial_fail')
                        ?? `이미지 ${i + 1}번 업로드에 실패했습니다.`
                    );
                }
            }
        }

        // 3. 성공 처리
        G7Core.state.setLocal({
            isSubmittingReview: false,
            reviewTarget: null,
            reviewRating: 5,
            reviewContent: '',
            reviewImages: [],
            reviewError: null,
        });
        G7Core.modal?.close?.('modal_write_review');
        G7Core.dispatch?.({ handler: 'refetchDataSource', params: { dataSourceId: 'order' } });
        G7Core.toast?.success?.(
            response.message
            ?? G7Core.t?.('sirsoft-ecommerce.user.review.submit_success')
            ?? '리뷰가 등록되었습니다.'
        );
    } catch (error: any) {
        logger.error('[submitReview] 실패:', error);

        const errorData = error?.response?.data || error?.data;
        const httpStatus = error?.response?.status || error?.status;
        const errorMessage = errorData?.message || error?.message
            || G7Core.t?.('sirsoft-ecommerce.user.review.submit_failed')
            || '리뷰 등록에 실패했습니다.';

        if (httpStatus === 422) {
            // Validation 에러: 필드별 에러 표시
            const validationErrors = errorData?.errors ?? {};
            const firstError = Object.values(validationErrors).flat()[0] as string | undefined;
            G7Core.state.setLocal({
                isSubmittingReview: false,
                reviewError: firstError || errorMessage,
            });
        } else {
            G7Core.state.setLocal({ isSubmittingReview: false });
        }

        G7Core.toast?.error?.(errorMessage);
    }
}
