/**
 * ProductImageViewer 컴포넌트
 *
 * 상품 상세 페이지용 이미지 뷰어입니다.
 * 메인 이미지 + 썸네일 리스트 + 라이트박스(ImageGallery) 연동을 지원합니다.
 *
 * @module composite/ProductImageViewer
 */

import React, { useState, useMemo } from 'react';
import { Div } from '../basic/Div';
import { Img } from '../basic/Img';
import { Button } from '../basic/Button';
import { Icon } from '../basic/Icon';
import { ImageGallery, useImageGallery, GalleryImage } from './ImageGallery';
import type { EditorAttrs } from '../../types';
import { thumbUrl } from '../../support/thumbnail';

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const G7Core = (window as any).G7Core;

const t = (key: string, params?: Record<string, string | number>) =>
  G7Core?.t?.(key, params) ?? key;

// ========== Types ==========

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

// ========== Helper ==========

/**
 * 미리보기(규격 썸네일) 주소 — preview_url → url → download_url 순.
 *
 * 공개 자산 디스크(CDN) 행의 download_url 은 직접 주소라 썸네일 규격이 걸리지 않는다(원본 그대로).
 * 서버가 함께 내리는 preview_url(API 서빙 주소)에 규격을 걸어야 메인·썸네일 줄이 썸네일을 받는다.
 *
 * @param image 상품 이미지
 * @returns 미리보기 주소
 */
const getPreviewSrc = (image: ProductImage): string => {
  return image.preview_url || image.url || image.download_url;
};

/**
 * 원본 주소 — 확대 창 슬라이드·다운로드용 (download_url, 없으면 미리보기 주소).
 *
 * @param image 상품 이미지
 * @returns 원본 주소
 */
const getOriginalSrc = (image: ProductImage): string => {
  return image.download_url || getPreviewSrc(image);
};

// ========== Main Component ==========

export const ProductImageViewer: React.FC<ProductImageViewerProps> = ({
  images = [],
  className = '',
  id,
  editorAttrs,
}) => {
  const [selectedIndex, setSelectedIndex] = useState(0);
  const { openGallery, galleryProps } = useImageGallery();

  // 이미지를 ImageGallery용 형식으로 변환
  const galleryImages: GalleryImage[] = useMemo(
    () =>
      images.map((img) => ({
        src: getOriginalSrc(img),
        title: img.alt_text_current ?? '',
        // 라이트박스 아래 썸네일 줄은 120×80 칸(ImageGallery thumbnails 설정) — 원본 대신 sm. 메인 슬라이드는 원본 그대로다.
        thumbnail: thumbUrl(getPreviewSrc(img), 'sm'),
        downloadUrl: getOriginalSrc(img),
        filename: img.alt_text_current ?? `image-${img.id}`,
      })),
    [images],
  );

  // 이미지 없음
  if (!images || images.length === 0) {
    return (
      <Div
        className={`flex items-center justify-center bg-gray-100 dark:bg-gray-700 rounded-lg aspect-square ${className}`}
        id={id} {...editorAttrs}
      >
        <Div className="text-center text-gray-600 dark:text-gray-300">
          <Icon name="image" size="3x" className="mb-3 opacity-50" />
          <Div className="text-sm">{t('shop.no_image')}</Div>
        </Div>
      </Div>
    );
  }

  const currentImage = images[selectedIndex] ?? images[0];

  return (
    <Div id={id} className={className} {...editorAttrs}>
      {/* 메인 이미지 */}
      <Div className="relative overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-700 aspect-square mb-3">
        <Button
          type="button"
          className="w-full h-full cursor-zoom-in block"
          onClick={() => openGallery(galleryImages, selectedIndex)}
          aria-label={t('shop.view_image')}
        >
          <Img
            src={getPreviewSrc(currentImage)}
            alt={currentImage.alt_text_current ?? ''}
            className="w-full h-full object-contain"
            // 상세 메인은 첫 화면이라 지연 로딩하지 않는다. lg(가로 1280)에 2배 밀도는 srcset 의 xl
            thumbPreset="lg"
            loading="eager"
          />
        </Button>
      </Div>

      {/* 썸네일 리스트 (2개 이상일 때만) */}
      {images.length > 1 && (
        <Div className="flex gap-2 overflow-x-auto pb-1">
          {images.map((img, index) => (
            <Button
              key={img.id}
              type="button"
              className={`flex-shrink-0 w-16 h-16 rounded-md overflow-hidden border-2 transition-colors cursor-pointer ${
                index === selectedIndex
                  ? 'border-gray-900 dark:border-white'
                  : 'border-gray-200 dark:border-gray-600 hover:border-gray-400 dark:hover:border-gray-400'
              }`}
              onClick={() => setSelectedIndex(index)}
              aria-label={img.alt_text_current ?? `${t('shop.image')} ${index + 1}`}
            >
              <Img
                src={getPreviewSrc(img)}
                alt={img.alt_text_current ?? ''}
                className="w-full h-full object-cover"
                thumbPreset="xs"
                loading="lazy"
              />
            </Button>
          ))}
        </Div>
      )}

      {/* 라이트박스 */}
      <ImageGallery {...galleryProps} />
    </Div>
  );
};

ProductImageViewer.displayName = 'ProductImageViewer';

export default ProductImageViewer;
