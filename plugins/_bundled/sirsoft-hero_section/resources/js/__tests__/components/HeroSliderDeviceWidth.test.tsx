/**
 * @file HeroSliderDeviceWidth.test.tsx
 * @description 사용자 슬라이더의 기기 폭 판정 — 휴대폰(0~767px)·휴대 기기(0~1023px)를 엔진 반응형 폭(`G7Core.useResponsive()`,
 * 레이아웃 편집기 미리보기에서는 기기 폭)으로 판정해 루트 수식 클래스(`hs-slider--mobile`·`hs-slider--portable`)로 알리고, 영역 비율·
 * 이미지와 영상 초점·문구 자리·탭 모양·화살표 숨김 CSS 가 그 클래스를 본다. 미리보기 기기 고정(`forceDevice`)이 엔진 폭보다 먼저고,
 * 엔진 훅이 없을 때만 창 폭을 쓴다. 영상 파일도 같은 판정으로 기기 것을 고른다. CSS 원본에는 슬라이더용 화면 폭 미디어가 없다.
 * (매니페스트 hero-slider.yaml)
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, cleanup, act } from '@testing-library/react';
import { HeroSlider, type HeroBanner } from '../../components/composite/HeroSlider';
import { SCREEN_MAX_WIDTH } from '../../support/media';
import { matchResponsiveKey } from '../../support/editor';

vi.mock('swiper/react', () => import('../swiperMock'));
vi.mock('swiper/modules', () => ({ Autoplay: { name: 'autoplay' }, EffectFade: { name: 'effect-fade' }, EffectCube: { name: 'effect-cube' }, EffectFlip: { name: 'effect-flip' }, EffectCoverflow: { name: 'effect-coverflow' }, EffectCreative: { name: 'effect-creative' } }));

const MAIN_CSS = path.resolve(__dirname, '../../../css/main.css');

/** 배너 하나를 만든다. */
const banner = (id: number, extra: Partial<HeroBanner> = {}): HeroBanner => ({
  id,
  title: `제목 ${id}`,
  image_alt: `대체 ${id}`,
  link_url: null,
  images: { pc: { url: `/img/pc-${id}.jpg`, width: 1920, height: 640 }, mobile: { url: `/img/m-${id}.jpg`, width: 750, height: 1000 } },
  ...extra,
});

/** 영상 배너 — PC·모바일 영상을 모두 둔다. */
const videoBanner = (id: number): HeroBanner => banner(id, {
  media_type: 'video',
  videos: { pc: { url: `/vid/pc-${id}.mp4`, mime: 'video/mp4' }, mobile: { url: `/vid/m-${id}.mp4`, mime: 'video/mp4' } },
});

/** 브라우저 폭 미디어 판정을 흉내 낸다 — `narrow` 면 `(max-width: 767px)` 가 맞는다(창 폭 판정이 엔진 폭과 갈라진 상황). */
const mockMatchMedia = (narrow: boolean) => {
  window.matchMedia = vi.fn().mockImplementation((query: string) => ({
    matches: narrow && query.includes('max-width'),
    media: query,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
  })) as unknown as typeof window.matchMedia;
};

/** 창 폭을 바꾼다. */
const setWindowWidth = (width: number) => {
  Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: width });
};

/** 엔진 반응형 폭을 준다(`G7Core.useResponsive`). */
const installEngineWidth = (width: number) => {
  (window as any).G7Core = { t: (key: string) => key, useResponsive: () => ({ width }) };
};

/** 루트 슬라이더의 기기 수식 클래스 */
const deviceClasses = (container: HTMLElement) => {
  const root = container.querySelector('section.hs-slider') as HTMLElement;
  return ['hs-slider--mobile', 'hs-slider--portable'].filter((name) => root.classList.contains(name));
};

/**
 * CSS 원본의 화면 폭 미디어 블록마다 그 안의 규칙 선택자를 모은다(주석 제외, 중괄호 짝 맞춤).
 *
 * @param css CSS 원문
 * @returns 폭 미디어 블록별 선택자 목록
 */
const widthMediaSelectors = (css: string): string[][] => {
  const text = css.replace(/\/\*[\s\S]*?\*\//g, '');
  const blocks: string[][] = [];
  const media = /@media[^{]*\((?:min-|max-)?width\s*:[^{]*\{/g;
  let match: RegExpExecArray | null;
  while ((match = media.exec(text)) !== null) {
    let depth = 1;
    let i = match.index + match[0].length;
    const start = i;
    while (i < text.length && depth > 0) {
      if (text[i] === '{') depth++;
      else if (text[i] === '}') depth--;
      i++;
    }
    const body = text.slice(start, i - 1);
    blocks.push([...body.matchAll(/([^{}]+)\{[^{}]*\}/g)].map((rule) => rule[1].trim()));
  }

  return blocks;
};

describe('HeroSlider 기기 폭 판정', () => {
  beforeEach(() => {
    mockMatchMedia(false);
    setWindowWidth(1280);
    (window as any).G7Core = { t: (key: string) => key };
  });

  afterEach(() => {
    cleanup();
    delete (window as any).G7Core;
    setWindowWidth(1024);
  });

  // @scenario surface=device_class_from_engine_width
  // @effects device_layout_follows_engine_width
  it.each([
    [375, ['hs-slider--mobile', 'hs-slider--portable']],
    [767, ['hs-slider--mobile', 'hs-slider--portable']],
    [768, ['hs-slider--portable']],
    [1023, ['hs-slider--portable']],
    [1024, []],
    [1280, []],
  ])('엔진 폭 %ipx 이면 창 폭(1280px)과 관계없이 루트 기기 클래스가 %j 다', (width, expected) => {
    installEngineWidth(width as number);
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: false, pagination: 'tabs' }} />);
    expect(deviceClasses(container)).toEqual(expected);
  });

  // @scenario surface=device_class_from_engine_width
  // @effects device_layout_follows_engine_width
  it('받는 중 스켈레톤도 같은 기기 클래스를 받아 실제 슬라이더와 같은 비율로 자리를 잡는다', () => {
    installEngineWidth(390);
    const { container } = render(<HeroSlider banners={[]} loading position="device_width_skeleton" />);
    const skeleton = container.querySelector('section.hs-slider--skeleton') as HTMLElement;
    expect(skeleton).not.toBeNull();
    expect(deviceClasses(container)).toEqual(['hs-slider--mobile', 'hs-slider--portable']);
  });

  // @scenario surface=device_class_force_device_first
  // @effects device_layout_follows_engine_width
  it('미리보기 기기 고정이 엔진 폭보다 먼저다 — PC 고정은 휴대 기기 판정도 끄고(화살표 보임), 모바일 고정은 둘 다 켠다', () => {
    installEngineWidth(390);
    const pc = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: false }} forceDevice="pc" />);
    expect(deviceClasses(pc.container)).toEqual([]);
    pc.unmount();

    installEngineWidth(1280);
    const mobile = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: false }} forceDevice="mobile" />);
    expect(deviceClasses(mobile.container)).toEqual(['hs-slider--mobile', 'hs-slider--portable']);
  });

  // @scenario surface=device_class_window_fallback
  // @effects device_layout_follows_engine_width
  it('엔진 훅이 없으면 창 폭으로 판정하고, 창 폭이 바뀌면 다시 판정한다', () => {
    setWindowWidth(500);
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: false }} />);
    expect(deviceClasses(container)).toEqual(['hs-slider--mobile', 'hs-slider--portable']);

    act(() => {
      setWindowWidth(1100);
      window.dispatchEvent(new Event('resize'));
    });
    expect(deviceClasses(container)).toEqual([]);
  });

  // @scenario surface=video_device_from_engine_width
  // @effects device_layout_follows_engine_width
  it('영상 파일도 엔진 폭으로 기기 것을 고른다 — 창 폭 미디어 판정과 갈라져도 엔진 폭을 따른다', () => {
    Object.defineProperty(HTMLMediaElement.prototype, 'play', { configurable: true, value: vi.fn(() => Promise.resolve()) });
    Object.defineProperty(HTMLMediaElement.prototype, 'pause', { configurable: true, value: vi.fn() });

    installEngineWidth(390);
    mockMatchMedia(false);
    const narrow = render(<HeroSlider banners={[videoBanner(1)]} />);
    expect(narrow.container.querySelector('video')?.getAttribute('src')).toBe('/vid/m-1.mp4');
    narrow.unmount();

    installEngineWidth(1280);
    mockMatchMedia(true);
    const wide = render(<HeroSlider banners={[videoBanner(1)]} />);
    expect(wide.container.querySelector('video')?.getAttribute('src')).toBe('/vid/pc-1.mp4');
  });

  // @scenario surface=main_image_device_from_engine_width
  // @effects device_layout_follows_engine_width
  // @effects responsive_image_switch
  it.each([
    [375, '/img/m-1.jpg', '750'],
    [767, '/img/m-1.jpg', '750'],
    [768, '/img/pc-1.jpg', '1920'],
    [1280, '/img/pc-1.jpg', '1920'],
  ])('엔진 폭 %ipx 이면 창 폭·창 폭 미디어와 관계없이 주 이미지가 %s 다 — 창 폭 source 에 맡기지 않는다', (width, src, imageWidth) => {
    const narrowEngine = (width as number) <= SCREEN_MAX_WIDTH.mobile;
    installEngineWidth(width as number);
    // 창 폭과 창 폭 미디어는 엔진 폭의 반대쪽을 가리키게 한다(편집기 미리보기 틀이 창보다 좁거나 넓은 상황)
    setWindowWidth(narrowEngine ? 1280 : 375);
    mockMatchMedia(!narrowEngine);
    const { container } = render(<HeroSlider banners={[banner(1)]} />);
    const img = container.querySelector('img.hs-slider__image');
    expect(img?.getAttribute('src')).toBe(src);
    expect(img?.getAttribute('width')).toBe(imageWidth);
    expect(container.querySelector('source')).toBeNull();
  });

  // @scenario surface=main_image_device_from_engine_width
  // @effects responsive_image_switch
  it('엔진 폭이 바뀌면 주 이미지도 바뀌고, 휴대폰 폭이라도 모바일 이미지가 없으면 PC 이미지를 쓴다', () => {
    let width = 375;
    (window as any).G7Core = { t: (key: string) => key, useResponsive: () => ({ width }) };
    const pcOnly = banner(2, { images: { pc: { url: '/img/pc-2.jpg', width: 1920, height: 640 }, mobile: null } });
    const { container, rerender } = render(<HeroSlider banners={[banner(1), pcOnly]} settings={{ autoplay: false }} />);
    const srcOf = (id: number) => container.querySelector(`[data-banner-id="${id}"] img.hs-slider__image`)?.getAttribute('src');
    expect(srcOf(1)).toBe('/img/m-1.jpg');
    expect(srcOf(2)).toBe('/img/pc-2.jpg');

    width = 1280;
    rerender(<HeroSlider banners={[banner(1), pcOnly]} settings={{ autoplay: false }} />);
    expect(srcOf(1)).toBe('/img/pc-1.jpg');
  });

  // @scenario surface=main_image_device_from_engine_width
  // @effects responsive_image_switch
  it('엔진 훅이 없으면 창 폭으로 주 이미지를 고르고, 창 폭이 바뀌면(resize) 다시 고른다 — 실제 브라우저와 같은 767px 경계', () => {
    setWindowWidth(767);
    const { container } = render(<HeroSlider banners={[banner(1)]} />);
    expect(container.querySelector('img.hs-slider__image')?.getAttribute('src')).toBe('/img/m-1.jpg');

    act(() => {
      setWindowWidth(768);
      window.dispatchEvent(new Event('resize'));
    });
    expect(container.querySelector('img.hs-slider__image')?.getAttribute('src')).toBe('/img/pc-1.jpg');
  });

  // @scenario surface=device_breakpoints_match_engine_presets
  // @effects device_layout_follows_engine_width
  it('경계는 엔진 반응형 프리셋(mobile 0~767 · portable 0~1023)과 같다', () => {
    expect(SCREEN_MAX_WIDTH).toEqual({ mobile: 767, portable: 1023 });
    expect(matchResponsiveKey({ mobile: 1 }, SCREEN_MAX_WIDTH.mobile)).toBe('mobile');
    expect(matchResponsiveKey({ mobile: 1 }, SCREEN_MAX_WIDTH.mobile + 1)).toBeNull();
    expect(matchResponsiveKey({ portable: 1 }, SCREEN_MAX_WIDTH.portable)).toBe('portable');
    expect(matchResponsiveKey({ portable: 1 }, SCREEN_MAX_WIDTH.portable + 1)).toBeNull();
  });

  // @scenario surface=css_width_media_free
  // @effects device_layout_follows_engine_width
  it('슬라이더 CSS 는 화면 폭 미디어 없이 기기 클래스로 갈린다', () => {
    const css = fs.readFileSync(MAIN_CSS, 'utf8');
    // 슬라이더(.hs-slider 계열) 규칙은 화면 폭 미디어 안에 두지 않는다(관리자 편집 화면 규칙은 이 시험의 대상이 아니다)
    const sliderInWidthMedia = widthMediaSelectors(css).flat().filter((selector) => /\.hs-slider/.test(selector));
    expect(sliderInWidthMedia).toEqual([]);

    const flat = css.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\s+/g, ' ');
    expect(flat).toContain('.hs-slider--mobile { aspect-ratio: var(--hs-ratio-mobile, 750 / 1000); }');
    expect(flat).toContain('.hs-slider--mobile .hs-slider__image { object-position: var(--hs-focus-mobile, 50% 50%); }');
    expect(flat).toContain('.hs-slider--mobile .hs-slider__video { object-position: var(--hs-focus-mobile, 50% 50%); }');
    expect(flat).toMatch(/\.hs-slider--mobile \.hs-slider__text \{ --hs-tx: var\(--hs-tx-mobile, 0\); --hs-ty: var\(--hs-ty-mobile, 100\); --hs-shade: var\(--hs-shade-mobile, to bottom\); \}/);
    expect(flat).toMatch(/\.hs-slider--mobile \.hs-slider__tabs \{ width: calc\(100% - 120px\); gap: 8px; \}/);
    expect(flat).toMatch(/\.hs-slider--mobile \.hs-slider__tab \{ justify-content: center; min-height: 24px; padding: 0; \}/);
    expect(flat).toMatch(/\.hs-slider--mobile \.hs-slider__tab-label \{ position: absolute;/);
    expect(flat).toContain('.hs-slider--portable .hs-slider__arrow { display: none; }');
  });
});
