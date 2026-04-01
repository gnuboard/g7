import { default as React } from 'react';
export interface ProductImage {
    /** 이미지 ID */
    id: number;
    /** 외부 URL (CDN 등, nullable) */
    url: string | null;
    /** API 서빙 URL (항상 존재) */
    download_url: string;
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
}
export declare const ProductImageViewer: React.FC<ProductImageViewerProps>;
export default ProductImageViewer;
