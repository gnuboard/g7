/**
 * ProductImageViewer → ImageGallery 시작 사진 회귀 테스트
 *
 * 썸네일로 큰 사진을 바꾼 뒤 큰 사진을 누르면 확대 창이 고른 사진으로 열려야 한다.
 * ImageGallery 는 항상 마운트돼 있고 openGallery 가 startIndex 만 바꾸므로,
 * 현재 번호를 useState(startIndex) 초기값으로만 잡으면 매번 첫 사진으로 열린다.
 * 실제 ImageGallery(yet-another-react-lightbox)를 렌더해 카운터 「N / M」 으로 잠근다.
 *
 * 시간 예산: 부하가 걸린 실행에서 첫 시험이 5초 제한에 걸렸다. 잰 결과(단독) 시험 안의 동적 import 가 320ms,
 * `getByRole` 한 번이 70~260ms(역할과 이름 계산, 숨김 판정용 getComputedStyle), 확대 창 열기가 320~450ms 였다.
 * 모듈은 파일 수집 때 한 번 읽고(제품 모듈이 읽힐 때 G7Core.t 를 잡으므로 vi.hoisted 로 먼저 심는다),
 * 단추는 aria-label 로 찾는다(단추로 좁혀 같은 단추를 고른다). 닫힘은 라이트박스 페이드(250ms)를 기다렸는데, 라이트박스는
 * `prefers-reduced-motion: reduce` 이면 페이드를 0 으로 두므로 그 질의만 「움직임 줄임」으로 답한다.
 * 시험이 재는 것(고른 사진에서 열리는가)은 그대로다.
 *
 * @scenario-manifest tests/scenarios/product-image-viewer-lightbox-start-index.yaml
 */

import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

// ProductImageViewer 는 모듈이 읽힐 때 window.G7Core 를 잡는다 — import 보다 먼저 심는다
vi.hoisted(() => {
  Object.assign(window, { G7Core: { t: (key: string) => key } });
});

import { ProductImageViewer } from '../ProductImageViewer';

const images = [1, 2, 3, 4, 5].map((n) => ({
  id: n,
  url: `https://cdn.example.com/img${n}.jpg`,
  download_url: `/api/modules/sirsoft-ecommerce/product-image/h${n}`,
  alt_text_current: `사진 ${n}`,
  sort_order: n - 1,
}));

/**
 * 이름(aria-label)이 같은 단추를 찾는다.
 *
 * @param name 단추 이름
 * @returns 단추 요소
 */
const buttonNamed = (name: string) => screen.getByLabelText(name, { selector: 'button' });

const openFromMainImage = () => {
  fireEvent.click(buttonNamed('shop.view_image'));
};

// 라이트박스 페이드를 끈다 — 움직임 줄임 질의만 참으로 답하고 나머지는 시험 준비 파일의 가짜에 맡긴다
let originalMatchMedia: typeof window.matchMedia;
beforeEach(() => {
  originalMatchMedia = window.matchMedia;
  window.matchMedia = (query: string) => {
    const result = originalMatchMedia(query);
    return query === '(prefers-reduced-motion: reduce)' ? { ...result, matches: true } : result;
  };
});
afterEach(() => {
  window.matchMedia = originalMatchMedia;
});

const counterText = () =>
  document.querySelector('.yarl__counter')?.textContent?.replace(/\s+/g, ' ').trim();

const closeLightbox = () => {
  fireEvent.click(document.querySelector('.yarl__button[aria-label="common.lightbox.close"]') as Element);
};

describe('ProductImageViewer 확대 창 시작 사진', () => {
  /**
   * @scenario case=third_thumbnail
   * @effects lightbox_opens_at_selected_image
   */
  it('3번째 썸네일을 고른 뒤 큰 사진을 누르면 확대 창이 「3 / 5」 로 열린다', async () => {
    render(<ProductImageViewer images={images} />);

    fireEvent.click(buttonNamed('사진 3'));
    openFromMainImage();

    await waitFor(() => expect(counterText()).toBe('3 / 5'));
  });

  /**
   * @scenario case=reopen_other_thumbnail
   * @effects lightbox_opens_at_selected_image
   */
  it('닫은 뒤 다른 썸네일로 다시 열면 그 사진에서 시작한다', async () => {
    render(<ProductImageViewer images={images} />);

    fireEvent.click(buttonNamed('사진 4'));
    openFromMainImage();
    await waitFor(() => expect(counterText()).toBe('4 / 5'));

    closeLightbox();
    await waitFor(() => expect(document.querySelector('.yarl__counter')).toBeNull());

    fireEvent.click(buttonNamed('사진 1'));
    openFromMainImage();
    await waitFor(() => expect(counterText()).toBe('1 / 5'));
  });
});
