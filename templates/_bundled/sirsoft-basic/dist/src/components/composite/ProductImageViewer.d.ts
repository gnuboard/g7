import { default as React } from 'react';
import { EditorAttrs } from '../../types';
export interface ProductImage {
    /** 이미지 ID */
    id: number;
    /** 미사용 호환 — 서버가 내리지 않는다(예전 응답·편집기 샘플용). 있으면 미리보기 대체로만 쓴다 */
    url?: string | null;
    /** 원본 주소 (공개 자산 디스크면 CDN 직접 주소, 아니면 API 서빙 주소) — 확대 창·다운로드용 */
    download_url: string;
    /** 썸네일 기준 주소 (항상 API 서빙 주소) — 메인·썸네일 줄의 규격(`thumbPreset`)은 이 주소에 건다 */
    preview_url?: string | null;
    /** 현재 로케일 대체 텍스트 */
    alt_text_current?: string;
    /** 대표 이미지 여부 */
    is_thumbnail?: boolean;
    /** 정렬 순서 */
    sort_order?: number;
}
export interface ProductImageViewerProps {
    /** 상품 이미지 배열 (API 응답 그대로) */
    images: ProductImage[];
    /** 커스텀 클래스 */
    className?: string;
    /**
     * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
     */
    id?: string;
    /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
    editorAttrs?: EditorAttrs;
}
export declare const ProductImageViewer: React.FC<ProductImageViewerProps>;
export default ProductImageViewer;
