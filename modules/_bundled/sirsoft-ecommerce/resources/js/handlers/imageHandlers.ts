/**
 * 이미지 관련 핸들러
 *
 * 상품 등록/수정 화면에서 상품 이미지 업로드, 순서 변경, 삭제 기능을 처리합니다.
 */

import type { ActionContext } from '../types';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Ecom:Image')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:Image]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:Image]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:Image]', ...args),
};

interface ProductImage {
    id?: number | null;
    url: string;
    is_thumbnail: boolean;
    sort_order: number;
    [key: string]: any;
}

interface ActionWithParams {
    handler: string;
    params?: Record<string, any>;
    [key: string]: any;
}

/**
 * 대표 이미지를 설정합니다.
 *
 * @param action 액션 객체 (params.index 필요)
 * @param _context 액션 컨텍스트
 */
export function setThumbnailHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const index = params.index as number;

    if (index === undefined) {
        logger.warn('[setThumbnail] Missing index param');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[setThumbnail] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const images = (state.form?.images ?? []).map(
        (img: ProductImage, i: number) => ({
            ...img,
            is_thumbnail: i === index,
        })
    );

    G7Core.state.setLocal({
        form: { ...state.form, images },
        hasChanges: true,
    });

    logger.log(`[setThumbnail] Set thumbnail to image at index ${index}`);
}

// 이미지 삭제 모달(confirmDeleteImage/deleteImage)은 FileUploader 의 내장
// confirmBeforeRemove + onRemove 흐름으로 대체되어 제거되었다.

/**
 * 이미지 순서를 변경합니다.
 *
 * @param action 액션 객체 (params.oldIndex, params.newIndex 필요)
 * @param _context 액션 컨텍스트
 */
export function reorderImagesHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const oldIndex = params.oldIndex as number;
    const newIndex = params.newIndex as number;

    if (oldIndex === undefined || newIndex === undefined) {
        logger.warn('[reorderImages] Missing oldIndex or newIndex param');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[reorderImages] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const images = [...(state.form?.images ?? [])];

    // 배열 요소 이동
    const [movedImage] = images.splice(oldIndex, 1);
    images.splice(newIndex, 0, movedImage);

    // sort_order 재설정
    images.forEach((img, i) => {
        img.sort_order = i;
    });

    G7Core.state.setLocal({
        form: { ...state.form, images },
        hasChanges: true,
    });

    logger.log(`[reorderImages] Moved image from ${oldIndex} to ${newIndex}`);
}
