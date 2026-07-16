/**
 * ImageGallery 컴포넌트
 *
 * yet-another-react-lightbox 기반 이미지 갤러리 컴포넌트입니다.
 * 줌, 슬라이드쇼, 전체화면, 다운로드 기능을 지원합니다.
 *
 * @module composite/ImageGallery
 */

import React, { useState, useCallback, useEffect, useRef } from 'react';
import Lightbox, { Slide } from 'yet-another-react-lightbox';
import Zoom from 'yet-another-react-lightbox/plugins/zoom';
import Counter from 'yet-another-react-lightbox/plugins/counter';
import Slideshow from 'yet-another-react-lightbox/plugins/slideshow';
import Fullscreen from 'yet-another-react-lightbox/plugins/fullscreen';
import Thumbnails from 'yet-another-react-lightbox/plugins/thumbnails';
import 'yet-another-react-lightbox/styles.css';
import 'yet-another-react-lightbox/plugins/counter.css';
import 'yet-another-react-lightbox/plugins/thumbnails.css';

import { Button } from '../basic/Button';
import { I } from '../basic/I';
import { Div } from '../basic/Div';
import { Img } from '../basic/Img';
import type { EditorAttrs } from '../../types';

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const G7Core = (window as any).G7Core;

// G7Core.t() 번역 함수 참조
const t = (key: string, params?: Record<string, string | number>) =>
  G7Core?.t?.(key, params) ?? key;

// ========== Types ==========

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

// ========== Helper Functions ==========

/**
 * 인증된 이미지 다운로드 (Blob으로 변환 후 다운로드)
 */
const downloadAuthenticatedFile = async (url: string, filename: string): Promise<void> => {
  try {
    const blob = await G7Core.api.get(url, {
      responseType: 'blob',
    });

    if (blob) {
      const objectUrl = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = objectUrl;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      document.body.removeChild(link);
      URL.revokeObjectURL(objectUrl);
    }
  } catch (error) {
    console.error('Failed to download file:', error);
    G7Core?.toast?.error?.(t('common.download_failed'));
  }
};

/**
 * 일반 파일 다운로드
 */
const downloadFile = (url: string, filename: string): void => {
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  link.target = '_blank';
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
};

/**
 * 이미지 다운로드 실행
 */
export const executeImageDownload = async (image: GalleryImage): Promise<void> => {
  const downloadUrl = image.downloadUrl || image.src;
  const filename = image.filename || image.title || 'image';

  if (image.downloadRequiresAuth) {
    await downloadAuthenticatedFile(downloadUrl, filename);
  } else {
    downloadFile(downloadUrl, filename);
  }
};

// ========== Custom Download Button Component ==========

interface DownloadButtonProps {
  image: GalleryImage;
  index: number;
  onDownload?: (image: GalleryImage, index: number) => void;
}

const DownloadButton: React.FC<DownloadButtonProps> = ({ image, index, onDownload }) => {
  const [isDownloading, setIsDownloading] = useState(false);

  const handleDownload = async (e: React.MouseEvent) => {
    e.stopPropagation();

    if (onDownload) {
      onDownload(image, index);
      return;
    }

    setIsDownloading(true);
    try {
      await executeImageDownload(image);
    } finally {
      setIsDownloading(false);
    }
  };

  return (
    <Button
      type="button"
      onClick={handleDownload}
      disabled={isDownloading}
      className="yarl__button flex items-center justify-center"
      aria-label={t('common.download')}
      title={t('common.download')}
    >
      {isDownloading ? (
        <I className="fa-solid fa-spinner fa-spin text-white" />
      ) : (
        <I className="fa-solid fa-download text-white" />
      )}
    </Button>
  );
};

// ========== Main Component ==========

export const ImageGallery: React.FC<ImageGalleryProps> = ({
  images,
  isOpen,
  onClose,
  startIndex = 0,
  enableZoom = true,
  enableSlideshow = false,
  enableFullscreen = true,
  showCounter = true,
  showDownload = true,
  showThumbnails = true,
  onDownload,
  editorAttrs,
  id,
}) => {
  // 현재 슬라이드 인덱스 (다운로드 버튼용)
  const [currentIndex, setCurrentIndex] = useState(startIndex);
  // 최신 인덱스를 ref로 추적 (클로저 문제 방지)
  const currentIndexRef = useRef(startIndex);

  // 이미지를 라이트박스 슬라이드 형식으로 변환 (썸네일 포함)
  const slides: Slide[] = images.map((image) => ({
    src: image.src,
    title: image.title,
    description: image.description,
  }));

  // 활성화할 플러그인 목록
  const plugins = [];
  if (enableZoom) plugins.push(Zoom);
  if (enableSlideshow) plugins.push(Slideshow);
  if (enableFullscreen) plugins.push(Fullscreen);
  if (showCounter) plugins.push(Counter);
  if (showThumbnails) plugins.push(Thumbnails);

  // 현재 이미지
  const currentImage = images[currentIndex];

  // currentIndex가 변경되면 ref도 업데이트
  useEffect(() => {
    currentIndexRef.current = currentIndex;
  }, [currentIndex]);

  // 편집 모드(편집기 캔버스) — Lightbox 모달은 인라인 DOM 이 없어 선택·편집 불가.
  // editorAttrs 가 주입되면 인라인 placeholder(썸네일 그리드)를 대신 렌더해
  // 캔버스에서 선택·속성 편집이 가능하게 한다. 런타임(editorAttrs 미주입)은 미진입.
  // (Hooks 규칙 — 모든 hook 호출 이후에 분기 return)
  if (editorAttrs) {
    const previewImages = (images ?? []).slice(0, 4);
    return (
      <Div
        id={id}
        className="grid grid-cols-2 gap-2 p-3 rounded-lg border border-dashed border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-800"
        {...editorAttrs}
      >
        {previewImages.length > 0 ? (
          previewImages.map((image, index) => (
            <Img
              key={index}
              src={image.thumbnail || image.src}
              alt={image.title || ''}
              className="w-full aspect-square object-cover rounded bg-gray-200 dark:bg-gray-700"
            />
          ))
        ) : (
          <Div className="col-span-2 flex items-center justify-center py-6 text-sm text-gray-400 dark:text-gray-500">
            <I className="fa-regular fa-images mr-2" />
            {t('editor.component.image_gallery')}
          </Div>
        )}
      </Div>
    );
  }

  return (
    <Lightbox
      open={isOpen}
      close={onClose}
      slides={slides}
      index={currentIndex}
      plugins={plugins}
      on={{
        view: ({ index }) => {
          if (index !== currentIndexRef.current) {
            setCurrentIndex(index);
          }
        },
      }}
      zoom={{
        maxZoomPixelRatio: 3,
        zoomInMultiplier: 2,
        doubleTapDelay: 300,
        doubleClickDelay: 300,
        doubleClickMaxStops: 2,
        keyboardMoveDistance: 50,
        wheelZoomDistanceFactor: 100,
        pinchZoomDistanceFactor: 100,
        scrollToZoom: true,
      }}
      carousel={{
        finite: true,
        preload: 2,
        padding: '16px',
        spacing: '30%',
      }}
      animation={{
        fade: 250,
        swipe: 500,
        easing: {
          fade: 'ease',
          swipe: 'ease-out',
          navigation: 'ease-in-out',
        },
      }}
      controller={{
        closeOnBackdropClick: true,
        closeOnPullDown: true,
        closeOnPullUp: true,
      }}
      thumbnails={{
        position: 'bottom',
        width: 120,
        height: 80,
        border: 2,
        borderRadius: 4,
        padding: 4,
        gap: 16,
        showToggle: false,
        vignette: true,
      }}
      toolbar={{
        buttons: [
          showDownload && currentImage && (
            <DownloadButton
              key="download"
              image={currentImage}
              index={currentIndex}
              onDownload={onDownload}
            />
          ),
          'close',
        ].filter(Boolean),
      }}
      styles={{
        container: {
          backgroundColor: 'rgba(0, 0, 0, 0.9)',
        },
      }}
    />
  );
};

// ========== Utility Hook ==========

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
export const useImageGallery = () => {
  const [isOpen, setIsOpen] = useState(false);
  const [images, setImages] = useState<GalleryImage[]>([]);
  const [startIndex, setStartIndex] = useState(0);

  const openGallery = useCallback((galleryImages: GalleryImage[], index = 0) => {
    setImages(galleryImages);
    setStartIndex(index);
    setIsOpen(true);
  }, []);

  const closeGallery = useCallback(() => {
    setIsOpen(false);
  }, []);

  return {
    isOpen,
    openGallery,
    closeGallery,
    galleryProps: {
      images,
      isOpen,
      onClose: closeGallery,
      startIndex,
    },
  };
};

ImageGallery.displayName = 'ImageGallery';

export default ImageGallery;
