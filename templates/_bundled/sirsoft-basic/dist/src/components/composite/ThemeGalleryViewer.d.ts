import { default as React } from 'react';
export interface ThemeGalleryViewerProps {
    /** 갤러리 이미지 URL 배열 */
    images?: string[];
    /** 메인 이미지 대체 텍스트 */
    alt?: string;
}
/**
 * 테마 상세 페이지 갤러리.
 * 메인 큰 이미지 1장 + 하단 썸네일 4개. 썸네일 클릭 시 메인 이미지 교체.
 */
declare const ThemeGalleryViewer: React.FC<ThemeGalleryViewerProps>;
export default ThemeGalleryViewer;
