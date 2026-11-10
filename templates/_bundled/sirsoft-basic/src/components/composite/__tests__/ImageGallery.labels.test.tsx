/**
 * ImageGallery 확대 창 라벨 다국어 회귀 테스트
 *
 * yet-another-react-lightbox 는 `labels` 를 넘기지 않으면 창 이름(「Lightbox」)·닫기·이전·다음·확대 같은
 * 화면 읽기용 이름을 영어 기본값으로 붙인다. 한국어·일본어 화면에서도 영어로 읽히지 않도록
 * 모든 라벨이 G7Core.t 번역 키를 거치는지 잠근다.
 *
 * @scenario-manifest tests/scenarios/image-gallery-labels.yaml
 */

import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi, afterEach } from 'vitest';

// 컴포넌트가 모듈을 읽을 때 G7Core 를 잡으므로 import 보다 먼저 심는다
const translations = vi.hoisted(() => {
  const map: Record<string, string> = {};
  (window as any).G7Core = { t: (key: string) => map[key] ?? key };
  return map;
});

import { ImageGallery } from '../ImageGallery';

afterEach(() => {
  for (const key of Object.keys(translations)) delete translations[key];
});

const images = [1, 2, 3].map((n) => ({ src: `https://example.com/${n}.jpg`, title: `사진 ${n}` }));

/** 라이브러리 영어 기본 라벨 — 화면에 하나도 남으면 안 된다 */
const ENGLISH_DEFAULTS = [
  'Lightbox',
  'Carousel',
  'Photo gallery',
  'Slide',
  'Previous',
  'Next',
  'Close',
  'Zoom in',
  'Zoom out',
  'Enter Fullscreen',
  'Exit Fullscreen',
  'Thumbnails',
];

const collectLabels = (): string[] =>
  Array.from(document.querySelectorAll('[aria-label],[aria-roledescription],[title]')).flatMap((el) =>
    ['aria-label', 'aria-roledescription', 'title']
      .map((attr) => el.getAttribute(attr))
      .filter((value): value is string => value !== null),
  );

describe('ImageGallery 확대 창 라벨 다국어', () => {
  /**
   * @scenario label=dialog
   * @effects dialog_name_translated
   */
  it('확대 창 dialog 이름이 번역 키를 거친다', () => {
    render(<ImageGallery images={images} isOpen onClose={() => {}} startIndex={1} />);

    expect(screen.getByRole('dialog')).toHaveAttribute('aria-label', 'common.lightbox.dialog');
  });

  /**
   * @scenario label=buttons
   * @effects button_names_translated
   */
  it('닫기·이전·다음·확대 단추 이름이 번역 키를 거친다', () => {
    render(<ImageGallery images={images} isOpen onClose={() => {}} startIndex={1} />);

    expect(screen.getByRole('button', { name: 'common.lightbox.close' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'common.lightbox.previous' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'common.lightbox.next' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'common.lightbox.zoom_in' })).toBeInTheDocument();
  });

  /**
   * @scenario label=slide_position
   * @effects slide_position_placeholders_filled
   */
  it('슬라이드 위치 이름은 라이브러리가 번호를 채울 자리표시를 그대로 받는다', () => {
    translations['common.lightbox.slide_position'] = '{total}장 중 {index}번째';
    render(<ImageGallery images={images} isOpen onClose={() => {}} startIndex={1} />);

    expect(document.querySelector('.yarl__slide_current')).toHaveAttribute('aria-label', '3장 중 2번째');
  });

  /**
   * @scenario label=all
   * @effects no_english_default_left
   */
  it('영어 기본 라벨이 화면에 남지 않는다', () => {
    render(<ImageGallery images={images} isOpen onClose={() => {}} startIndex={1} />);

    const leftovers = collectLabels().filter((label) => ENGLISH_DEFAULTS.includes(label));
    expect(leftovers).toEqual([]);
  });
});
