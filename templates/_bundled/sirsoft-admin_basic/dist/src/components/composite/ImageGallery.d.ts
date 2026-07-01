import { default as React } from 'react';
import { EditorAttrs } from '../../types';
export interface GalleryImage {
    /** 이미지 표시용 URL (라이트박스에서 보여줄 이미지) */
    src: string;
    /** 다운로드용 URL (src와 다를 수 있음, 없으면 src 사용) */
    downloadUrl?: string;
    /** 이미지 제목/캡션 */
    title?: string;
    /** 이미지 설명 */
    description?: string;
    /** 썸네일 URL (없으면 src 사용) */
    thumbnail?: string;
    /** 원본 파일명 (다운로드 시 사용) */
    filename?: string;
    /** 다운로드 시 인증된 요청이 필요한지 여부 (기본값: false) */
    downloadRequiresAuth?: boolean;
}
export interface ImageGalleryProps {
    /** 갤러리에 표시할 이미지 배열 */
    images: GalleryImage[];
    /** 라이트박스 열기 여부 */
    isOpen: boolean;
    /** 라이트박스 닫기 콜백 */
    onClose: () => void;
    /** 시작 인덱스 (기본값: 0) */
    startIndex?: number;
    /** 줌 기능 활성화 (기본값: true) */
    enableZoom?: boolean;
    /** 슬라이드쇼 기능 활성화 (기본값: false) */
    enableSlideshow?: boolean;
    /** 전체화면 기능 활성화 (기본값: true) */
    enableFullscreen?: boolean;
    /** 이미지 카운터 표시 (기본값: true) */
    showCounter?: boolean;
    /** 다운로드 버튼 표시 (기본값: true) */
    showDownload?: boolean;
    /** 썸네일 네비게이션 표시 (기본값: true) */
    showThumbnails?: boolean;
    /** 커스텀 다운로드 핸들러 (제공 시 기본 다운로드 로직 대신 실행) */
    onDownload?: (image: GalleryImage, index: number) => void;
    /**
     * 레이아웃 편집기 주입 속성 (편집 모드 전용).
     * 본 컴포넌트는 서드파티 Lightbox 모달(`createPortal` 로 body 렌더)이라 런타임에는
     * 인라인 DOM 이 없다. 편집 모드(editorAttrs 존재)에서는 캔버스 선택·속성 편집이
     * 가능하도록 인라인 placeholder(이미지 썸네일 그리드)를 대신 렌더하고 거기에
     * editorAttrs·id 를 부착한다. 런타임(editorAttrs 미주입)은 종전 Lightbox 동작 그대로.
     *
     */
    editorAttrs?: EditorAttrs;
    /** DOM id 속성 (레이아웃 편집기 코어 일괄 ID — 편집 placeholder 루트에 부착) */
    id?: string;
}
/**
 * 이미지 다운로드 실행
 */
export declare const executeImageDownload: (image: GalleryImage) => Promise<void>;
export declare const ImageGallery: React.FC<ImageGalleryProps>;
/**
 * ImageGallery를 쉽게 사용하기 위한 커스텀 훅
 *
 * @example
 * const { openGallery, galleryProps } = useImageGallery();
 *
 * // 이미지 클릭 시
 * <img onClick={() => openGallery(images, 0)} />
 *
 * // 컴포넌트 렌더링
 * <ImageGallery {...galleryProps} />
 */
export declare const useImageGallery: () => {
    isOpen: boolean;
    openGallery: (galleryImages: GalleryImage[], index?: number) => void;
    closeGallery: () => void;
    galleryProps: {
        images: GalleryImage[];
        isOpen: boolean;
        onClose: () => void;
        startIndex: number;
    };
};
export default ImageGallery;
