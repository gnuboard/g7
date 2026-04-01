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
