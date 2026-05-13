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

const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB
const MAX_IMAGES = 20;

/**
 * 이미지를 업로드합니다.
 *
 * - 최대 20장까지 업로드 가능
 * - 지원 형식: JPEG, PNG, GIF, WebP
 * - 최대 파일 크기: 10MB
 *
 * @param action 액션 객체 (params.files 필요)
 * @param _context 액션 컨텍스트
 */
export async function uploadImagesHandler(
    action: ActionWithParams,
    _context: ActionContext
): Promise<void> {
    const params = action.params || {};
    const files = params.files as FileList;

    if (!files || files.length === 0) {
        logger.warn('[uploadImages] No files provided');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state || !G7Core?.api) {
        logger.warn('[uploadImages] G7Core.state or G7Core.api is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const currentImages: ProductImage[] = state.form?.images ?? [];
    const maxAllowed = MAX_IMAGES - currentImages.length;

    if (files.length > maxAllowed) {
        G7Core.toast?.warning?.(
            G7Core.t?.('sirsoft-ecommerce.admin.product.images.messages.max_count', { max: maxAllowed })
            ?? `You can add up to ${maxAllowed} images.`
        );
    }

    const filesToUpload = Array.from(files).slice(0, maxAllowed);

    for (const file of filesToUpload) {
        // 파일 유효성 검사
        if (!ALLOWED_IMAGE_TYPES.includes(file.type)) {
            G7Core.toast?.error?.(
                G7Core.t?.('sirsoft-ecommerce.admin.product.images.messages.unsupported_format', { name: file.name })
                ?? `${file.name}: Unsupported format.`
            );
            continue;
        }

        if (file.size > MAX_FILE_SIZE) {
            G7Core.toast?.error?.(
                G7Core.t?.('sirsoft-ecommerce.admin.product.images.messages.size_exceeded', { name: file.name })
                ?? `${file.name}: Exceeds 10MB.`
            );
            continue;
        }

        // 진행률 초기화
        const currentState = G7Core.state.getLocal() || {};
        G7Core.state.setLocal({
            ui: {
                ...currentState.ui,
                uploadProgress: {
                    ...(currentState.ui?.uploadProgress || {}),
                    [file.name]: 0,
                },
            },
        });

        try {
            const formData = new FormData();
            formData.append('file', file);
            formData.append('collection', 'products');

            const response = await G7Core.api.upload(
                '/api/admin/media/upload',
                formData,
                {
                    onProgress: (percent: number) => {
                        const progressState = G7Core.state.getLocal() || {};
                        G7Core.state.setLocal({
                            ui: {
                                ...progressState.ui,
                                uploadProgress: {
                                    ...(progressState.ui?.uploadProgress || {}),
                                    [file.name]: percent,
                                },
                            },
                        });
                    },
                }
            );

            // 업로드 성공 - 이미지 목록에 추가
            const successState = G7Core.state.getLocal() || {};
            const existingImages: ProductImage[] = successState.form?.images ?? [];
            const newImage: ProductImage = {
                ...response.data,
                is_thumbnail: existingImages.length === 0,
                sort_order: existingImages.length,
            };

            G7Core.state.setLocal({
                form: {
                    ...successState.form,
                    images: [...existingImages, newImage],
                },
                hasChanges: true,
            });

            // 진행률에서 제거
            const cleanupState = G7Core.state.getLocal() || {};
            const { [file.name]: _, ...remainingProgress } =
                cleanupState.ui?.uploadProgress || {};
            G7Core.state.setLocal({
                ui: { ...cleanupState.ui, uploadProgress: remainingProgress },
            });

            logger.log(`[uploadImages] Uploaded ${file.name}`);
        } catch (error) {
            G7Core.toast?.error?.(
                G7Core.t?.('sirsoft-ecommerce.admin.product.images.messages.upload_failed', { name: file.name })
                ?? `${file.name}: Upload failed`
            );

            const errorState = G7Core.state.getLocal() || {};
            const { [file.name]: _, ...remainingProgress } =
                errorState.ui?.uploadProgress || {};
            G7Core.state.setLocal({
                ui: { ...errorState.ui, uploadProgress: remainingProgress },
            });

            logger.error(`[uploadImages] Failed to upload ${file.name}:`, error);
        }
    }
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

/**
 * 이미지 삭제 확인 모달을 표시합니다.
 *
 * @param action 액션 객체 (params.index 필요)
 * @param _context 액션 컨텍스트
 */
export function confirmDeleteImageHandler(
    action: ActionWithParams,
    _context: ActionContext
): void {
    const params = action.params || {};
    const index = params.index as number;

    if (index === undefined) {
        logger.warn('[confirmDeleteImage] Missing index param');
        return;
    }

    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[confirmDeleteImage] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    G7Core.state.setLocal({
        ui: {
            ...state.ui,
            showImageDeleteModal: true,
            imageToDeleteIndex: index,
        },
    });
}

/**
 * 이미지를 삭제합니다.
 *
 * - ui.imageToDeleteIndex에 저장된 인덱스의 이미지 삭제
 * - 삭제된 이미지가 대표였다면 첫 번째 이미지를 대표로 설정
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function deleteImageHandler(
    _action: ActionWithParams,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[deleteImage] G7Core.state API is not available');
        return;
    }

    const state = G7Core.state.getLocal() || {};
    const index = state.ui?.imageToDeleteIndex;

    if (index === undefined || index === null) {
        logger.warn('[deleteImage] No image index to delete');
        return;
    }

    const images = [...(state.form?.images ?? [])];
    const deletedImage = images[index];

    images.splice(index, 1);

    // sort_order 재정렬
    images.forEach((img, i) => {
        img.sort_order = i;
    });

    // 삭제된 이미지가 대표였다면 첫 번째 이미지를 대표로 설정
    if (deletedImage?.is_thumbnail && images.length > 0) {
        images[0].is_thumbnail = true;
    }

    G7Core.state.setLocal({
        form: { ...state.form, images },
        ui: {
            ...state.ui,
            showImageDeleteModal: false,
            imageToDeleteIndex: null,
        },
        hasChanges: true,
    });

    logger.log(`[deleteImage] Deleted image at index ${index}`);
}

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
