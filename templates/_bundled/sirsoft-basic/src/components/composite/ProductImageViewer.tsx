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

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const G7Core = (window as any).G7Core;

const t = (key: string, params?: Record<string, string | number>) =>
  G7Core?.t?.(key, params) ?? key;

// ========== Types ==========

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

// ========== Helper ==========

/**
 * 이미지 표시용 URL 반환 (url 우선, 없으면 download_url)
 */
const getImageSrc = (image: ProductImage): string => {
  return image.url ?? image.download_url;
};

// ========== Main Component ==========

export const ProductImageViewer: React.FC<ProductImageViewerProps> = ({
  images = [],
  className = '',
}) => {
  const [selectedIndex, setSelectedIndex] = useState(0);
  const { openGallery, galleryProps } = useImageGallery();

  // 이미지를 ImageGallery용 형식으로 변환
  const galleryImages: GalleryImage[] = useMemo(
    () =>
      images.map((img) => ({
        src: getImageSrc(img),
        title: img.alt_text_current ?? '',
        thumbnail: getImageSrc(img),
        downloadUrl: img.download_url,
        filename: img.alt_text_current ?? `image-${img.id}`,
      })),
    [images],
  );

  // 이미지 없음
  if (!images || images.length === 0) {
    return (
      <Div
        className={`flex items-center justify-center bg-gray-100 dark:bg-gray-700 rounded-lg aspect-square ${className}`}
      >
        <Div className="text-center text-gray-400 dark:text-gray-500">
          <Icon name="image" size="3x" className="mb-3 opacity-50" />
          <Div className="text-sm">{t('shop.no_image')}</Div>
        </Div>
      </Div>
    );
  }

  const currentImage = images[selectedIndex] ?? images[0];

  return (
    <Div className={className}>
      {/* 메인 이미지 */}
      <Div className="relative overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-700 aspect-square mb-3">
        <Button
          type="button"
          className="w-full h-full cursor-zoom-in block"
          onClick={() => openGallery(galleryImages, selectedIndex)}
          aria-label={t('shop.view_image')}
        >
          <Img
            src={getImageSrc(currentImage)}
            alt={currentImage.alt_text_current ?? ''}
            className="w-full h-full object-contain"
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
                src={getImageSrc(img)}
                alt={img.alt_text_current ?? ''}
                className="w-full h-full object-cover"
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
