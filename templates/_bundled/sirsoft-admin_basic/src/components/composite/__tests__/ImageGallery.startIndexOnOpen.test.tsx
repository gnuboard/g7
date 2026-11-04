// e2e:allow 관리자 화면에는 닫힌 채 마운트된 ImageGallery 를 여는 경로가 아직 없다(FileUploader 는 열 때마다 key 로 새로 마운트).
// 레이아웃 컴포넌트로 등록돼 있어 isOpen·startIndex 를 바인딩하면 같은 결함이 나므로 컴포넌트 단위로 잠근다(#821).
/**
 * ImageGallery 열림 시 시작 사진 회귀 테스트 (#821)
 *
 * 닫힌 채 마운트된 뒤 isOpen 이 true 로 바뀌면서 startIndex 가 함께 바뀌면, 현재 번호를
 * useState(startIndex) 초기값으로만 잡았을 때 확대 창이 항상 마운트 때 번호(0)로 열렸다.
 *
 * @scenario-manifest tests/scenarios/image-gallery-start-index-on-open.yaml
 */

import React from 'react';
import { render, waitFor } from '@testing-library/react';
import { describe, it, expect, beforeAll, beforeEach, afterEach } from 'vitest';
import { ImageGallery } from '../ImageGallery';

beforeAll(() => {
  // jsdom 은 작업자마다 첫 getComputedStyle 호출에서 기본 스타일시트를 한 번 파싱한다(단독 실측 약 0.13초).
  // 시험 장면과 무관한 환경 준비 비용이라 첫 시험의 제한 시간에 얹히지 않게 여기서 미리 치른다.
  window.getComputedStyle(document.body).getPropertyValue('padding-left');
});

beforeEach(() => {
  (window as any).G7Core = { t: (key: string) => key };
  // jsdom 은 배치를 계산하지 않아 documentElement.clientWidth 가 0 이다. 라이트박스는 열릴 때
  // innerWidth(1024) − clientWidth 를 세로 스크롤바 폭으로 읽고, 0 보다 크면 body 아래 모든 요소에
  // getComputedStyle 을 불러 position:fixed 요소를 찾는다. jsdom 의 getComputedStyle 은 요소마다
  // 스타일 규칙 전체를 다시 맞춰 보므로 열기 한 번이 수백 ms CPU 를 쓰고, 기계가 바쁘면 5초를 넘었다.
  // 스크롤바 없는 브라우저 화면(clientWidth = innerWidth)으로 맞춰 시험과 무관한 그 탐색을 건너뛴다.
  Object.defineProperty(document.documentElement, 'clientWidth', {
    configurable: true,
    get: () => window.innerWidth,
  });
});

afterEach(() => {
  // 인스턴스에 덧씌운 속성만 지워 Element.prototype 의 원래 getter 로 되돌린다.
  delete (document.documentElement as any).clientWidth;
});

const images = [1, 2, 3, 4, 5].map((n) => ({ src: `https://example.com/${n}.jpg`, title: `사진 ${n}` }));

const counterText = () =>
  document.querySelector('.yarl__counter')?.textContent?.replace(/\s+/g, ' ').trim();

describe('ImageGallery 열림 시 시작 사진 (#821)', () => {
  /**
   * @scenario case=open_after_mount
   * @effects lightbox_opens_at_start_index
   */
  it('닫힌 채 마운트된 뒤 startIndex=2 로 열면 「3 / 5」 로 열린다', async () => {
    const { rerender } = render(<ImageGallery images={images} isOpen={false} onClose={() => {}} startIndex={0} />);

    rerender(<ImageGallery images={images} isOpen={true} onClose={() => {}} startIndex={2} />);

    await waitFor(() => expect(counterText()).toBe('3 / 5'));
  });

  /**
   * @scenario case=reopen_other_index
   * @effects lightbox_opens_at_start_index
   */
  it('닫고 다른 startIndex 로 다시 열면 그 사진에서 시작한다', async () => {
    const { rerender } = render(<ImageGallery images={images} isOpen={false} onClose={() => {}} startIndex={0} />);

    rerender(<ImageGallery images={images} isOpen={true} onClose={() => {}} startIndex={3} />);
    await waitFor(() => expect(counterText()).toBe('4 / 5'));

    rerender(<ImageGallery images={images} isOpen={false} onClose={() => {}} startIndex={3} />);
    await waitFor(() => expect(document.querySelector('.yarl__counter')).toBeNull());

    rerender(<ImageGallery images={images} isOpen={true} onClose={() => {}} startIndex={0} />);
    await waitFor(() => expect(counterText()).toBe('1 / 5'));
  });
});
