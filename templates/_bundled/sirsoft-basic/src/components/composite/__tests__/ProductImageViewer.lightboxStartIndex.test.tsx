/**
 * ProductImageViewer → ImageGallery 시작 사진 회귀 테스트 (#821)
 *
 * 썸네일로 큰 사진을 바꾼 뒤 큰 사진을 누르면 확대 창이 고른 사진으로 열려야 한다.
 * ImageGallery 는 항상 마운트돼 있고 openGallery 가 startIndex 만 바꾸므로,
 * 현재 번호를 useState(startIndex) 초기값으로만 잡으면 매번 첫 사진으로 열린다.
 * 실제 ImageGallery(yet-another-react-lightbox)를 렌더해 카운터 「N / M」 으로 잠근다.
 *
 * @scenario-manifest tests/scenarios/product-image-viewer-lightbox-start-index.yaml
 */

import React from 'react';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, beforeAll } from 'vitest';

beforeAll(() => {
  (window as any).G7Core = { t: (key: string) => key };
});

const images = [1, 2, 3, 4, 5].map((n) => ({
  id: n,
  url: `https://cdn.example.com/img${n}.jpg`,
  download_url: `/api/modules/sirsoft-ecommerce/product-image/h${n}`,
  alt_text_current: `사진 ${n}`,
  sort_order: n - 1,
}));

const loadViewer = async () => (await import('../ProductImageViewer')).ProductImageViewer;

const openFromMainImage = () => {
  fireEvent.click(screen.getByRole('button', { name: 'shop.view_image' }));
};

const counterText = () =>
  document.querySelector('.yarl__counter')?.textContent?.replace(/\s+/g, ' ').trim();

const closeLightbox = () => {
  fireEvent.click(document.querySelector('.yarl__button[aria-label="Close"]') as Element);
};

describe('ProductImageViewer 확대 창 시작 사진 (#821)', () => {
  /**
   * @scenario case=third_thumbnail
   * @effects lightbox_opens_at_selected_image
   */
  it('3번째 썸네일을 고른 뒤 큰 사진을 누르면 확대 창이 「3 / 5」 로 열린다', async () => {
    const ProductImageViewer = await loadViewer();
    render(<ProductImageViewer images={images} />);

    fireEvent.click(screen.getByRole('button', { name: '사진 3' }));
    openFromMainImage();

    await waitFor(() => expect(counterText()).toBe('3 / 5'));
  });

  /**
   * @scenario case=reopen_other_thumbnail
   * @effects lightbox_opens_at_selected_image
   */
  it('닫은 뒤 다른 썸네일로 다시 열면 그 사진에서 시작한다', async () => {
    const ProductImageViewer = await loadViewer();
    render(<ProductImageViewer images={images} />);

    fireEvent.click(screen.getByRole('button', { name: '사진 4' }));
    openFromMainImage();
    await waitFor(() => expect(counterText()).toBe('4 / 5'));

    closeLightbox();
    await waitFor(() => expect(document.querySelector('.yarl__counter')).toBeNull());

    fireEvent.click(screen.getByRole('button', { name: '사진 1' }));
    openFromMainImage();
    await waitFor(() => expect(counterText()).toBe('1 / 5'));
  });
});
