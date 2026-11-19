// e2e:allow 메인·썸네일 줄·확대 창에 넘기는 주소 선택만 단위로 잠근다 — 실제 상품 상세의 썸네일 요청은 계획 §8 Chrome MCP 실측으로 확인한다.
/**
 * ProductImageViewer — 공개 자산 디스크(CDN) 행의 두 주소 (#878)
 *
 * 서버는 상품 이미지마다 원본 `download_url`(CDN 직접 주소일 수 있다)과 썸네일 기준 `preview_url`(항상 API 서빙
 * 주소)을 내린다. 직접 주소에는 썸네일 규격이 붙지 않으므로 메인(lg)·썸네일 줄(xs)·확대 창 아래 썸네일 줄(sm)은
 * `preview_url` 에 규격을 걸고, 확대 창 슬라이드와 다운로드는 원본(`download_url`)을 쓴다.
 *
 * Img 자리에는 `thumbPreset` 계약(규격이 있으면 코어 `withThumbnailSize` 로 주소에 붙인다)만 구현한 시험용
 * 컴포넌트를 둔다. 확대 창(ImageGallery)은 `openGallery` 로 넘긴 배열을 본다.
 *
 * @scenario-manifest tests/scenarios/product-image-viewer-component.yaml
 */

import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { withThumbnailSize } from '@core/support/thumbnailUrl';

const mockOpenGallery = vi.fn();

vi.mock('../ImageGallery', () => ({
  ImageGallery: () => null,
  useImageGallery: () => ({
    openGallery: mockOpenGallery,
    galleryProps: { images: [], isOpen: false, onClose: vi.fn(), startIndex: 0 },
  }),
}));

vi.mock('../../basic/Div', () => ({
  Div: ({ children, className, ...props }: React.PropsWithChildren<{ className?: string }>) => (
    <div className={className} {...props}>{children}</div>
  ),
}));

// thumbPreset 계약만 구현한 Img
vi.mock('../../basic/Img', () => ({
  Img: ({ src, alt, thumbPreset }: { src: string; alt: string; thumbPreset?: string }) => (
    <img src={thumbPreset ? (withThumbnailSize(src, thumbPreset) as string) : src} alt={alt} data-testid="image" data-preset={thumbPreset ?? ''} />
  ),
}));

vi.mock('../../basic/Button', () => ({
  Button: ({ children, onClick, className, ...props }: React.PropsWithChildren<{ onClick?: () => void; className?: string }>) => (
    <button onClick={onClick} className={className} {...props}>{children}</button>
  ),
}));

vi.mock('../../basic/Icon', () => ({
  Icon: ({ name }: { name?: string }) => <i data-name={name} />,
}));

vi.hoisted(() => {
  Object.assign(window, { G7Core: { t: (key: string) => key } });
});

import { ProductImageViewer } from '../ProductImageViewer';

const API = '/api/modules/sirsoft-ecommerce/product-image/';
const CDN = 'https://cdn.test/assets/sirsoft-ecommerce/images/products/P1/';

/** 공개 자산 디스크(CDN) 행 두 장 */
const cdnImages = [
  { id: 1, download_url: `${CDN}a.jpg`, preview_url: `${API}a1`, alt_text_current: '사진 1' },
  { id: 2, download_url: `${CDN}b.jpg`, preview_url: `${API}b2`, alt_text_current: '사진 2' },
];

beforeEach(() => {
  mockOpenGallery.mockClear();
  (globalThis as any).G7Config = {
    settings: { upload: { thumbnail_url_patterns: ['/api/modules/sirsoft-ecommerce/product-image/*'] } },
  };
  // 템플릿 어댑터(thumbUrl)는 코어 `G7Core.thumbnail.url` 을 부른다 — 코어 유틸로 잇는다
  (window as any).G7Core.thumbnail = { url: (url: string, preset: string) => withThumbnailSize(url, preset) };
});

afterEach(() => {
  delete (globalThis as any).G7Config;
  delete (window as any).G7Core.thumbnail;
});

describe('ProductImageViewer — preview_url 로 썸네일, download_url 은 원본', () => {
  // @scenario image_count=multiple
  // @effects viewer_thumbnails_use_preview_url_and_gallery_uses_download_url
  it('CDN 행의 메인(lg)·썸네일 줄(xs)은 preview_url(API) 규격 주소를 요청한다', () => {
    render(<ProductImageViewer images={cdnImages} />);
    const images = screen.getAllByTestId('image');

    expect(images.map((img) => img.getAttribute('src'))).toEqual([
      `${API}a1?size=lg`,
      `${API}a1?size=xs`,
      `${API}b2?size=xs`,
    ]);
  });

  // @scenario image_count=multiple
  // @effects viewer_thumbnails_use_preview_url_and_gallery_uses_download_url
  it('확대 창 슬라이드·다운로드는 원본(download_url), 확대 창 썸네일 줄은 preview_url 의 sm 이다', () => {
    render(<ProductImageViewer images={cdnImages} />);
    fireEvent.click(screen.getByLabelText('shop.view_image', { selector: 'button' }));

    expect(mockOpenGallery).toHaveBeenCalledTimes(1);
    const [galleryImages, startIndex] = mockOpenGallery.mock.calls[0];
    expect(startIndex).toBe(0);
    expect(galleryImages.map((g: any) => [g.src, g.downloadUrl, g.thumbnail])).toEqual([
      [`${CDN}a.jpg`, `${CDN}a.jpg`, `${API}a1?size=sm`],
      [`${CDN}b.jpg`, `${CDN}b.jpg`, `${API}b2?size=sm`],
    ]);
  });

  // @scenario image_count=single
  // @effects viewer_thumbnails_use_preview_url_and_gallery_uses_download_url
  it('preview_url 이 없는 예전 응답은 download_url 로 돌아간다', () => {
    render(<ProductImageViewer images={[{ id: 9, download_url: `${API}z9` }]} />);

    expect(screen.getAllByTestId('image').map((img) => img.getAttribute('src'))).toEqual([`${API}z9?size=lg`]);
  });
});
