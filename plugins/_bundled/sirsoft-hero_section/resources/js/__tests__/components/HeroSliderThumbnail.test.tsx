/**
 * @file HeroSliderThumbnail.test.tsx
 * @description 사용자 슬라이더 이미지의 썸네일 srcset — PC 이미지와 모바일 이미지는 기기별로 따로 올린 각자의 원본이라 각자 srcset
 * (코어 숫자 요청 규격 md·lg·xl 중 원본보다 작은 것 + 원본)을 갖고, 그려진 상자를 잰 폭(잴 수 없으면 화면 폭 상한)으로 sizes 를 준다. 코어 썸네일 API 가
 * 없거나 대상 주소가 아니면 종전과 같다. (매니페스트 image-thumbnail.yaml)
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, cleanup } from '@testing-library/react';
import { HeroSlider, type HeroBanner } from '../../components/composite/HeroSlider';
import { contentSrcset, contentSrcsetWithin, isThumbnailableUrl, thumbnailSrcset, withThumbnailSize } from '@core/support/thumbnailUrl';
import { mediaSrc, responsiveSrcset } from '../../support/thumbnail';

vi.mock('swiper/react', () => import('../swiperMock'));
vi.mock('swiper/modules', () => ({ Autoplay: { name: 'autoplay' }, EffectFade: { name: 'effect-fade' }, EffectCube: { name: 'effect-cube' }, EffectFlip: { name: 'effect-flip' }, EffectCoverflow: { name: 'effect-coverflow' }, EffectCreative: { name: 'effect-creative' } }));

const BASE = '/api/plugins/sirsoft-hero_section/images/';
const PC = `${BASE}${'p'.repeat(64)}`;
const MOBILE = `${BASE}${'m'.repeat(64)}`;

/** 코어 기본 규격(defaults.json) 중 화면이 받는 공개 목록 — 숫자 요청 대상은 md·lg·xl */
const PRESETS = [
  { name: 'xs', width: 64, height: 64, fit: 'cover', numeric: false },
  { name: 'sm', width: 160, height: 160, fit: 'cover', numeric: false },
  { name: 'md', width: 640, height: null, fit: 'width', numeric: true },
  { name: 'lg', width: 1280, height: null, fit: 'width', numeric: true },
  { name: 'xl', width: 1920, height: null, fit: 'width', numeric: true },
];

/** 배너 하나 */
const banner = (extra: Partial<HeroBanner> = {}): HeroBanner => ({
  id: 1,
  title: '제목',
  image_alt: '대체',
  link_url: null,
  images: { pc: { url: PC, width: 1920, height: 640 }, mobile: { url: MOBILE, width: 750, height: 1000 } },
  ...extra,
});

/** 코어 썸네일 API 를 실제 코어 구현으로 설치한다(서버가 내려 주는 업로드 설정과 함께). */
const installThumbnailCore = () => {
  (window as any).G7Config = { settings: { upload: { thumbnail_url_patterns: [`${BASE}*`], thumbnail_presets_public: PRESETS } } };
  (window as any).G7Core = {
    t: (key: string) => key,
    thumbnail: {
      isThumbnailable: isThumbnailableUrl,
      url: withThumbnailSize,
      srcset: thumbnailSrcset,
      contentSrcset,
      contentSrcsetWithin,
    },
  };
};

describe('HeroSlider 이미지 썸네일 srcset', () => {
  beforeEach(() => {
    window.matchMedia = vi.fn().mockImplementation((query: string) => ({
      matches: false,
      media: query,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
    })) as unknown as typeof window.matchMedia;
    installThumbnailCore();
  });

  afterEach(() => {
    cleanup();
    delete (window as any).G7Config;
    delete (window as any).G7Core;
  });

  // @scenario thumbnail_query=slider_srcset
  // @effects hero_slider_pc_image_srcset_from_numeric_presets
  it('PC 이미지는 원본보다 작은 숫자 규격과 원본을 가로 기술자로 싣고, 화면 전체 폭 sizes 를 준다', () => {
    const { container } = render(<HeroSlider banners={[banner()]} settings={{}} />);
    const img = container.querySelector('img.hs-slider__image');

    expect(img?.getAttribute('src')).toBe(PC);
    expect(img?.getAttribute('srcset')).toBe(`${PC}?size=md 640w, ${PC}?size=lg 1280w, ${PC} 1920w`);
    expect(img?.getAttribute('sizes')).toBe('100vw');
  });

  // @scenario thumbnail_query=slider_srcset
  // @effects hero_slider_mobile_image_srcset_is_own_original
  it('휴대폰 화면(엔진 폭)의 모바일 이미지는 자기 원본(750)으로 srcset 을 만든다 — PC 원본 크기와 섞이지 않는다', () => {
    (window as any).G7Core.useResponsive = () => ({ width: 375 });
    const { container } = render(<HeroSlider banners={[banner()]} settings={{}} />);
    const img = container.querySelector('img.hs-slider__image');

    expect(container.querySelector('source')).toBeNull();
    expect(img?.getAttribute('src')).toBe(MOBILE);
    expect(img?.getAttribute('srcset')).toBe(`${MOBILE}?size=md 640w, ${MOBILE} 750w`);
    expect(img?.getAttribute('sizes')).toBe('100vw');
  });

  // @scenario thumbnail_query=slider_srcset
  // @effects hero_slider_forced_device_uses_that_device_original
  it('미리보기에서 휴대폰 기기로 고정하면 모바일 원본으로 img srcset 을 만든다', () => {
    const { container } = render(<HeroSlider banners={[banner()]} settings={{}} forceDevice="mobile" />);
    const img = container.querySelector('img.hs-slider__image');

    expect(container.querySelector('source')).toBeNull();
    expect(img?.getAttribute('src')).toBe(MOBILE);
    expect(img?.getAttribute('srcset')).toBe(`${MOBILE}?size=md 640w, ${MOBILE} 750w`);
  });

  // @scenario thumbnail_query=slider_srcset_fallback
  // @effects hero_slider_without_thumbnail_api_keeps_original
  it('코어 썸네일 API 가 없으면 종전과 같다 — PC·모바일 이미지 모두 원본 주소 하나, srcset 없음', () => {
    delete (window as any).G7Core.thumbnail;
    const { container } = render(<HeroSlider banners={[banner()]} settings={{}} />);

    expect(container.querySelector('img.hs-slider__image')?.hasAttribute('srcset')).toBe(false);
    expect(container.querySelector('img.hs-slider__image')?.hasAttribute('sizes')).toBe(false);
    cleanup();

    (window as any).G7Core.useResponsive = () => ({ width: 375 });
    const phone = render(<HeroSlider banners={[banner()]} settings={{}} />);
    expect(phone.container.querySelector('img.hs-slider__image')?.getAttribute('src')).toBe(MOBILE);
    expect(phone.container.querySelector('img.hs-slider__image')?.hasAttribute('srcset')).toBe(false);
    expect(phone.container.querySelector('img.hs-slider__image')?.hasAttribute('sizes')).toBe(false);
  });

  // @scenario thumbnail_query=slider_srcset_fallback
  // @effects hero_slider_srcset_skips_unknown_width_or_foreign_url
  it('서빙 경로가 아닌 주소·가로를 모르는 원본·모든 규격보다 작은 원본은 srcset 을 붙이지 않는다', () => {
    expect(responsiveSrcset('/img/pc.jpg', 1920)).toBeUndefined();
    expect(responsiveSrcset('https://cdn.example.com/a.jpg', 1920)).toBeUndefined();
    expect(responsiveSrcset(PC, null)).toBeUndefined();
    expect(responsiveSrcset(PC, 0)).toBeUndefined();
    expect(responsiveSrcset(PC, 600)).toBeUndefined();
    expect(responsiveSrcset(PC, 2560)).toBe(`${PC}?size=md 640w, ${PC}?size=lg 1280w, ${PC}?size=xl 1920w, ${PC} 2560w`);

    const { container } = render(<HeroSlider banners={[banner({ images: { pc: { url: PC, width: null, height: null }, mobile: null } })]} settings={{}} />);
    expect(container.querySelector('img.hs-slider__image')?.hasAttribute('srcset')).toBe(false);
  });

  // @scenario thumbnail_query=slider_srcset
  // @effects hero_slider_srcset_built_by_core_content_srcset_within
  it('후보 거르기는 코어 contentSrcsetWithin(원본 가로, 원본 덧붙임)이 한다 — PC·모바일 각자의 원본 가로로 부른다', () => {
    const within = vi.fn(contentSrcsetWithin);
    (window as any).G7Core.thumbnail.contentSrcsetWithin = within;
    const { container } = render(<HeroSlider banners={[banner()]} settings={{}} />);

    expect(within).toHaveBeenCalledWith(PC, 1920, { includeOriginal: true });
    expect(container.querySelector('img.hs-slider__image')?.getAttribute('srcset')).toBe(`${PC}?size=md 640w, ${PC}?size=lg 1280w, ${PC} 1920w`);
    cleanup();

    (window as any).G7Core.useResponsive = () => ({ width: 375 });
    const phone = render(<HeroSlider banners={[banner()]} settings={{}} />);
    expect(within).toHaveBeenCalledWith(MOBILE, 750, { includeOriginal: true });
    expect(phone.container.querySelector('img.hs-slider__image')?.getAttribute('srcset')).toBe(`${MOBILE}?size=md 640w, ${MOBILE} 750w`);
  });

  // @scenario thumbnail_query=slider_srcset
  // @effects hero_slider_srcset_built_by_core_content_srcset_within
  it('원본 가로별 srcset 은 코어 결과 그대로다 — 원본보다 작은 후보 + 원본, 남는 후보가 없으면 붙이지 않는다', () => {
    const cases: Array<[string, number | null]> = [[PC, 1920], [MOBILE, 750], [PC, 2560], [PC, 1280], [PC, 640], [PC, 600], [PC, null], ['/img/pc.jpg', 1920]];

    expect(cases.map(([url, width]) => responsiveSrcset(url, width))).toEqual([
      `${PC}?size=md 640w, ${PC}?size=lg 1280w, ${PC} 1920w`,
      `${MOBILE}?size=md 640w, ${MOBILE} 750w`,
      `${PC}?size=md 640w, ${PC}?size=lg 1280w, ${PC}?size=xl 1920w, ${PC} 2560w`,
      `${PC}?size=md 640w, ${PC} 1280w`,
      undefined,
      undefined,
      undefined,
      undefined,
    ]);
  });

  // @scenario thumbnail_query=slider_srcset_fallback
  // @effects hero_slider_without_within_api_has_no_srcset
  it('코어에 contentSrcsetWithin 이 없으면 contentSrcset 이 있어도 srcset 없이 원본 하나다 — 템플릿 본문 이미지와 같은 정책', () => {
    delete (window as any).G7Core.thumbnail.contentSrcsetWithin;

    expect(responsiveSrcset(PC, 1920)).toBeUndefined();
    expect(responsiveSrcset(MOBILE, 750)).toBeUndefined();

    const { container } = render(<HeroSlider banners={[banner()]} settings={{}} />);
    expect(container.querySelector('img.hs-slider__image')?.hasAttribute('srcset')).toBe(false);
    expect(container.querySelector('img.hs-slider__image')?.hasAttribute('sizes')).toBe(false);
    cleanup();

    (window as any).G7Core.useResponsive = () => ({ width: 375 });
    const phone = render(<HeroSlider banners={[banner()]} settings={{}} />);
    expect(phone.container.querySelector('img.hs-slider__image')?.getAttribute('src')).toBe(MOBILE);
    expect(phone.container.querySelector('img.hs-slider__image')?.hasAttribute('srcset')).toBe(false);
  });

  /**
   * 슬라이더 상자(`section.hs-slider`)의 배치 크기를 정한다 — jsdom 은 배치를 계산하지 않아 offsetWidth·offsetHeight 가 0 이다.
   *
   * @returns 원래대로 되돌리는 함수
   */
  const layoutSlider = (width: number, height: number) => {
    const original = {
      width: Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'offsetWidth')!,
      height: Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'offsetHeight')!,
    };
    const sized = (value: number) => ({ configurable: true, get(this: HTMLElement) { return this.classList?.contains('hs-slider') ? value : 0; } });
    Object.defineProperty(HTMLElement.prototype, 'offsetWidth', sized(width));
    Object.defineProperty(HTMLElement.prototype, 'offsetHeight', sized(height));
    return () => {
      Object.defineProperty(HTMLElement.prototype, 'offsetWidth', original.width);
      Object.defineProperty(HTMLElement.prototype, 'offsetHeight', original.height);
    };
  };

  // @scenario thumbnail_query=slider_sizes_slot_width
  // @effects hero_slider_sizes_follow_rendered_slot
  it('본문 폭에 맞춘 슬라이더는 화면 폭(100vw) 대신 실제 상자 폭으로 sizes 를 준다 — 1920 화면의 1216px 칸이 원본 대신 lg 를 고르게', () => {
    const restore = layoutSlider(1216, 405);
    try {
      const { container } = render(<HeroSlider banners={[banner()]} settings={{ width: 'content' }} contentClass="max-w-7xl mx-auto px-8" />);
      const img = container.querySelector('img.hs-slider__image');

      expect(img?.getAttribute('srcset')).toBe(`${PC}?size=md 640w, ${PC}?size=lg 1280w, ${PC} 1920w`);
      expect(img?.getAttribute('sizes')).toBe('1216px');
    } finally {
      restore();
    }
  });

  // @scenario thumbnail_query=slider_sizes_slot_width
  // @effects hero_slider_sizes_follow_rendered_slot
  it('이미지가 상자보다 가로로 길면 덮어 그리는(cover) 폭만큼 sizes 를 준다 — 높이에 맞춰 늘어난 가로가 모자라지 않게', () => {
    const restore = layoutSlider(1216, 405);
    try {
      const wide = banner({ images: { pc: { url: PC, width: 2560, height: 640 }, mobile: null } });
      const { container } = render(<HeroSlider banners={[wide]} settings={{ width: 'content' }} />);

      // 상자 높이 405(offsetHeight 는 정수) × (2560 ÷ 640) = 1620
      expect(container.querySelector('img.hs-slider__image')?.getAttribute('sizes')).toBe('1620px');
    } finally {
      restore();
    }
  });

  // @scenario thumbnail_query=slider_sizes_slot_width
  // @effects hero_slider_sizes_follow_rendered_slot
  it('상자를 재기 전의 sizes(100vw)로 이미지를 받기 시작하지 않는다 — 잰 폭이 붙은 뒤에 주소가 붙는다', () => {
    const restore = layoutSlider(1216, 405);
    const seen: string[] = [];
    const setAttribute = Element.prototype.setAttribute;
    const spy = vi.spyOn(Element.prototype, 'setAttribute').mockImplementation(function (this: Element, name: string, value: string) {
      if (this instanceof HTMLImageElement && this.classList.contains('hs-slider__image') && ['src', 'srcset', 'sizes'].includes(name.toLowerCase())) {
        seen.push(`${name.toLowerCase()}=${name.toLowerCase() === 'sizes' ? value : '…'} (sizes=${this.getAttribute('sizes') ?? '없음'})`);
      }
      return setAttribute.call(this, name, value);
    });
    try {
      render(<HeroSlider banners={[banner()]} settings={{ width: 'content' }} />);

      expect(seen.filter((entry) => entry.startsWith('sizes='))).toEqual(['sizes=1216px (sizes=없음)']);
      // 주소(src·srcset)는 잰 sizes 와 같은 갱신에서 처음 붙는다 — 그 전에 100vw 로 붙은 적이 없다
      expect(seen.some((entry) => entry.includes('100vw'))).toBe(false);
      expect(seen.filter((entry) => entry.startsWith('src=')).length).toBe(1);
      // 붙는 순서는 sizes → srcset → src — WebKit 은 srcset 이 sizes 보다 먼저 붙으면 기본 100vw 로 원본을 한 번 더 받는다(실측 2건)
      expect(seen.map((entry) => entry.split('=')[0])).toEqual(['sizes', 'srcset', 'src']);
    } finally {
      spy.mockRestore();
      restore();
    }
  });

  // @scenario thumbnail_query=slider_sizes_slot_width
  // @effects hero_slider_sizes_follow_rendered_slot
  it('상자 폭을 잴 수 없으면(배치 전·숨김) 종전처럼 화면 폭 상한 100vw 를 준다', () => {
    const { container } = render(<HeroSlider banners={[banner()]} settings={{ width: 'content' }} />);
    expect(container.querySelector('img.hs-slider__image')?.getAttribute('src')).toBe(PC);
    expect(container.querySelector('img.hs-slider__image')?.getAttribute('sizes')).toBe('100vw');
  });
});

/**
 * 공개 자산 디스크(#878) — 응답의 `direct_url`(원본 직접 주소)은 원본 한 장(`src`)과 srcset 의 **마지막 원본 후보**에만 쓴다.
 * 축소 후보는 공개 API 주소 `?size=`(서버가 공개 썸네일 디스크로 302) 그대로다. (매니페스트 public-asset-disk.yaml)
 */
describe('HeroSlider 공개 자산 디스크 직접 주소', () => {
  const CDN = 'https://cdn.example.com/a.jpg';

  beforeEach(() => {
    window.matchMedia = vi.fn().mockImplementation((query: string) => ({
      matches: false,
      media: query,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
    })) as unknown as typeof window.matchMedia;
    installThumbnailCore();
  });

  afterEach(() => {
    cleanup();
    delete (window as any).G7Config;
    delete (window as any).G7Core;
  });

  // @scenario surface=slider_src
  // @effects hero_slider_src_uses_direct_url
  // @effects hero_slider_srcset_original_candidate_is_direct_url
  it('direct_url 이 있으면 src 는 직접 주소, srcset 은 축소 후보만 API 이고 원본 후보는 직접 주소다', () => {
    const { container } = render(<HeroSlider banners={[banner({ images: { pc: { url: PC, direct_url: CDN, width: 1920, height: 640 }, mobile: null } })]} settings={{}} />);
    const img = container.querySelector('img.hs-slider__image');

    expect(img?.getAttribute('src')).toBe(CDN);
    expect(img?.getAttribute('srcset')).toBe(`${PC}?size=md 640w, ${PC}?size=lg 1280w, ${CDN} 1920w`);
  });

  // @scenario surface=slider_src
  // @effects hero_slider_src_uses_direct_url
  it('direct_url 이 null 이면 종전과 같다 — src·원본 후보 모두 API 주소', () => {
    const { container } = render(<HeroSlider banners={[banner({ images: { pc: { url: PC, direct_url: null, width: 1920, height: 640 }, mobile: null } })]} settings={{}} />);
    const img = container.querySelector('img.hs-slider__image');

    expect(img?.getAttribute('src')).toBe(PC);
    expect(img?.getAttribute('srcset')).toBe(`${PC}?size=md 640w, ${PC}?size=lg 1280w, ${PC} 1920w`);
  });

  // @scenario surface=slider_srcset_original
  // @effects hero_slider_srcset_original_candidate_is_direct_url
  it('responsiveSrcset 은 원본 후보 꼬리가 정확할 때만 바꾸고, 후보가 없거나 대상 주소가 아니면 손대지 않는다', () => {
    expect(responsiveSrcset(PC, 600, CDN)).toBeUndefined();
    expect(responsiveSrcset(CDN, 1920)).toBeUndefined();
    expect(responsiveSrcset(PC, 1920, PC)).toBe(`${PC}?size=md 640w, ${PC}?size=lg 1280w, ${PC} 1920w`);
    expect(responsiveSrcset(PC, 1920.7, CDN)).toBe(`${PC}?size=md 640w, ${PC}?size=lg 1280w, ${CDN} 1920w`);
    expect(responsiveSrcset(PC, 1920, 'https://cdn.example.com/a b.jpg')).toBe(`${PC}?size=md 640w, ${PC}?size=lg 1280w, https://cdn.example.com/a%20b.jpg 1920w`);
  });

  // @scenario surface=slider_src
  // @effects hero_slider_src_uses_direct_url
  it('mediaSrc 는 비지 않은 direct_url 을 우선하고, 없으면 url 이다', () => {
    expect(mediaSrc({ url: PC, direct_url: CDN })).toBe(CDN);
    expect(mediaSrc({ url: PC, direct_url: '' })).toBe(PC);
    expect(mediaSrc({ url: PC, direct_url: null })).toBe(PC);
    expect(mediaSrc(null)).toBeUndefined();
  });
});
