/**
 * @file PopupImageThumbnail.test.tsx
 * @description 팝업 이미지의 썸네일 srcset — PC 이미지와 모바일 이미지는 기기별로 따로 올린 각자의 원본이라 각자 srcset(코어 숫자 요청
 * 규격 중 원본보다 작은 것 + 원본)을 갖고, 레이어가 그리는 폭(PC 설정 폭·휴대폰 가운데 창·하단 시트, 비율을 알면 본문 높이 × 비율로 줄인 폭)으로 sizes 를 준다. 코어 썸네일 API 가
 * 없거나 서빙 경로가 아니면 종전과 같다. 글 상자 편집기는 sizes 를 주지 않아 원본 그대로다. (매니페스트 image-thumbnail.yaml)
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, cleanup } from '@testing-library/react';
import { PopupLayer } from '../../components/composite/PopupLayer';
import { ImageFrame } from '../../lib/ImageFrame';
import { resetClosedThisPage } from '../../lib/popupVisibility';
import { mediaSrc, popupImageSizes, responsiveSrcset } from '../../lib/thumbnail';
import type { PopupDisplaySettings, PublicPopup } from '../../lib/types';
import { contentSrcset, contentSrcsetWithin, isThumbnailableUrl, thumbnailSrcset, withThumbnailSize } from '@core/support/thumbnailUrl';
import { installCore, setViewportMobile } from './core';

const BASE = '/api/plugins/sirsoft-popup/images/';
const PC = `${BASE}${'p'.repeat(64)}`;
const MOBILE = `${BASE}${'m'.repeat(64)}`;

/** 코어 기본 규격(defaults.json) 중 화면이 받는 공개 목록 — 숫자 요청 대상은 md·lg·xl */
const PRESETS = [
  { name: 'sm', width: 160, height: 160, fit: 'cover', numeric: false },
  { name: 'md', width: 640, height: null, fit: 'width', numeric: true },
  { name: 'lg', width: 1280, height: null, fit: 'width', numeric: true },
  { name: 'xl', width: 1920, height: null, fit: 'width', numeric: true },
];

/** 이미지형 팝업 (PC 960×1040, 모바일 750×900) */
const popup = (): PublicPopup => ({
  id: 1,
  content_type: 'image',
  page_scope: 'all',
  device: 'all',
  image: { pc: { url: PC, width: 960, height: 1040 }, mobile: { url: MOBILE, width: 750, height: 900 } },
  image_alt: '대체',
  link_url: null,
  link_new_window: false,
});

/** 코어 썸네일 API 를 실제 코어 구현으로 설치한다(서버가 내려 주는 업로드 설정과 함께). */
const installThumbnailCore = () => {
  (window as any).G7Config = { settings: { upload: { thumbnail_url_patterns: [`${BASE}*`], thumbnail_presets_public: PRESETS } } };
  installCore({ thumbnail: { isThumbnailable: isThumbnailableUrl, url: withThumbnailSize, srcset: thumbnailSrcset, contentSrcset, contentSrcsetWithin } });
};

/** 레이어를 그린다 */
const show = (settings: Partial<PopupDisplaySettings> = {}) => render(
  <PopupLayer popups={[popup()]} settings={{ autoplay: false, ...settings }} path="/" />,
);

describe('팝업 이미지 썸네일 srcset', () => {
  beforeEach(() => {
    window.matchMedia = vi.fn().mockImplementation((query: string) => ({
      matches: false,
      media: query,
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
    })) as unknown as typeof window.matchMedia;
    document.cookie.split(';').forEach((c) => { document.cookie = `${c.split('=')[0].trim()}=; max-age=0; path=/`; });
    resetClosedThisPage();
    installThumbnailCore();
  });

  afterEach(() => {
    cleanup();
    delete (window as any).G7Config;
    delete (window as any).G7Core;
  });

  // @scenario thumbnail_query=layer_srcset
  // @effects popup_layer_pc_image_srcset_sized_by_pc_width
  it('PC 레이어는 PC 원본으로 srcset 을 만들고 설정 폭(화면 좌우 16px 여백 안)으로 sizes 를 준다', () => {
    setViewportMobile(false);
    const { container } = show({ pc_width: 480 });
    const img = container.querySelector('img.g7pp-media__img');

    expect(img?.getAttribute('src')).toBe(PC);
    expect(img?.getAttribute('srcset')).toBe(`${PC}?size=md 640w, ${PC} 960w`);
    // 본문 높이(설정 520, 화면 높이 - 116, 레이어가 줄인 상한 `--g7pp-fit-h` 중 작은 값) × 이미지 비율(960 ÷ 1040)이 폭을 더 줄일 수
    // 있다 — 잘리지 않게 담는(contain) 폭. jsdom 화면 높이 768 에서 레이어 상한은 768 - 여백 16 = 752px
    const fit = (container.querySelector('.g7pp-dialog') as HTMLElement).style.getPropertyValue('--g7pp-fit-h');
    expect(fit).toBe('752px');
    expect(img?.getAttribute('sizes')).toBe('(max-width: 512px) min(calc(100vw - 32px), calc(min(520px, 100vh - 116px, 752px) * 0.9231)), min(480px, calc(min(520px, 100vh - 116px, 752px) * 0.9231))');
  });

  // @scenario thumbnail_query=layer_srcset
  // @effects popup_layer_mobile_image_srcset_is_own_original
  it('휴대폰 레이어는 모바일 원본(750)으로 srcset 을 따로 만들고, 가운데 창·하단 시트 폭으로 sizes 를 준다', () => {
    setViewportMobile(true);
    const center = show({ mobile_style: 'center' });
    const img = center.container.querySelector('img.g7pp-media__img');

    expect(img?.getAttribute('src')).toBe(MOBILE);
    expect(img?.getAttribute('srcset')).toBe(`${MOBILE}?size=md 640w, ${MOBILE} 750w`);
    // 가운데 창도 레이어가 줄인 상한(jsdom 화면 높이 768 - 16 = 752px)을 본문 높이 식에 넣는다. 하단 시트는 줄이지 않아 종전 식
    expect(img?.getAttribute('sizes')).toBe('min(calc(100vw - 48px), calc(min(70vh, 420px, 100vh - 116px, 752px) * 0.8333))');
    cleanup();
    resetClosedThisPage();

    const sheet = show({ mobile_style: 'sheet' });
    expect(sheet.container.querySelector('img.g7pp-media__img')?.getAttribute('sizes')).toBe('min(100vw, calc(min(60vh, 380px, 100vh - 116px) * 0.8333))');
  });

  // @scenario thumbnail_query=layer_srcset
  // @effects popup_image_sizes_follow_layer_geometry
  it('sizes 는 레이어 배치를 따른다 — 미리보기는 기기 틀 폭 안이라 화면 폭 대신 상한을 쓴다', () => {
    expect(popupImageSizes({ isMobile: false, isSheet: false, pcWidth: 720, preview: false })).toBe('(max-width: 752px) calc(100vw - 32px), 720px');
    expect(popupImageSizes({ isMobile: false, isSheet: false, pcWidth: 720, preview: true })).toBe('720px');
    expect(popupImageSizes({ isMobile: true, isSheet: false, pcWidth: 720, preview: false })).toBe('calc(100vw - 48px)');
    expect(popupImageSizes({ isMobile: true, isSheet: true, pcWidth: 720, preview: false })).toBe('100vw');
    expect(popupImageSizes({ isMobile: true, isSheet: true, pcWidth: 720, preview: true })).toBe('767px');
  });

  // @scenario thumbnail_query=layer_sizes_contain_height
  // @effects popup_image_sizes_capped_by_body_height
  it('세로로 긴 이미지는 본문 높이에 맞춰 줄어든 폭으로 sizes 를 준다 — 375 하단 시트의 960×1280 이 표시 285px 인데 100vw 로 원본을 고르지 않게', () => {
    setViewportMobile(true);
    const tall = { ...popup(), image: { pc: { url: PC, width: 960, height: 1280 }, mobile: null } };
    const { container } = render(<PopupLayer popups={[tall]} settings={{ autoplay: false, mobile_style: 'sheet' }} path="/" />);

    // 시트 본문 높이 min(60vh, 380px, 100vh - 116px) × 0.75 — 812 높이 화면이면 380 × 0.75 = 285px(×2 = 570 → md 640w)
    expect(container.querySelector('img.g7pp-media__img')?.getAttribute('sizes')).toBe('min(100vw, calc(min(60vh, 380px, 100vh - 116px) * 0.75))');
  });

  // @scenario thumbnail_query=layer_sizes_contain_height
  // @effects popup_image_sizes_capped_by_body_height
  it('비율을 알면 레이어 배치마다 본문 높이 상한을 곱해 폭을 줄인다 — 미리보기는 기기 틀 안이라 숫자로 계산한다', () => {
    const base = { pcWidth: 720, pcHeight: 520, ratio: 0.75 };
    expect(popupImageSizes({ ...base, isMobile: false, isSheet: false, preview: false })).toBe('(max-width: 752px) min(calc(100vw - 32px), calc(min(520px, 100vh - 116px) * 0.75)), min(720px, calc(min(520px, 100vh - 116px) * 0.75))');
    expect(popupImageSizes({ ...base, isMobile: true, isSheet: false, preview: false })).toBe('min(calc(100vw - 48px), calc(min(70vh, 420px, 100vh - 116px) * 0.75))');
    expect(popupImageSizes({ ...base, isMobile: true, isSheet: true, preview: false })).toBe('min(100vw, calc(min(60vh, 380px, 100vh - 116px) * 0.75))');
    // 미리보기: PC 는 min(720, 520 × 0.75 = 390), 휴대폰은 min(767, 시트 380 × 0.75 = 285 / 가운데 창 420 × 0.75 = 315)
    expect(popupImageSizes({ ...base, isMobile: false, isSheet: false, preview: true })).toBe('390px');
    expect(popupImageSizes({ ...base, isMobile: true, isSheet: true, preview: true })).toBe('285px');
    expect(popupImageSizes({ ...base, isMobile: true, isSheet: false, preview: true })).toBe('315px');
    // 가로로 긴 이미지는 높이가 폭을 줄이지 못한다 — 미리보기 숫자는 폭 상한 그대로
    expect(popupImageSizes({ ...base, ratio: 3, isMobile: false, isSheet: false, preview: true })).toBe('720px');
    // 비율을 모르면 종전 값
    expect(popupImageSizes({ ...base, ratio: null, isMobile: true, isSheet: true, preview: false })).toBe('100vw');
  });

  // @scenario thumbnail_query=layer_sizes_contain_height
  // @effects popup_image_sizes_capped_by_body_height
  it('PC 창은 본문 상한(fit)을 잰 뒤에야 이미지를 붙인다 — 붙은 뒤 sizes 가 바뀌면 브라우저가 큰 후보를 먼저 받다가 끊는다(실측 lg 요청 취소)', async () => {
    setViewportMobile(false);
    const changed: string[] = [];
    const observer = new MutationObserver((records) => {
      for (const r of records) {
        if (r.type === 'attributes' && r.attributeName === 'sizes' && (r.target as Element).classList.contains('g7pp-media__img') && r.oldValue !== null) {
          changed.push(`${r.oldValue} → ${(r.target as Element).getAttribute('sizes')}`);
        }
      }
    });
    observer.observe(document.body, { subtree: true, attributes: true, attributeFilter: ['sizes'], attributeOldValue: true });
    const { container } = show({ pc_width: 480 });
    await Promise.resolve();
    observer.disconnect();

    expect(container.querySelector('img.g7pp-media__img')?.getAttribute('sizes')).toContain('752px');
    expect(changed).toEqual([]);
  });

  // @scenario thumbnail_query=layer_sizes_contain_height
  // @effects popup_image_sizes_capped_by_body_height
  it('레이어가 헤더·배너를 피해 줄인 본문 상한(fit)도 sizes 높이에 넣는다 — CSS `.g7pp-body` 높이 식과 같은 3항', () => {
    const base = { pcWidth: 480, pcHeight: 520, ratio: 0.75, isSheet: false, preview: false };
    // PC 1671×770: min(520, 770 - 116 = 654, fit 427) = 427 → × 0.75 = 320px 로 그린다(종전 sizes 는 390px 라 한 단계 큰 후보를 골랐다)
    expect(popupImageSizes({ ...base, isMobile: false, fitHeight: 427 })).toBe('(max-width: 512px) min(calc(100vw - 32px), calc(min(520px, 100vh - 116px, 427px) * 0.75)), min(480px, calc(min(520px, 100vh - 116px, 427px) * 0.75))');
    // 휴대폰 가운데 창도 같은 본문 높이 식을 쓴다
    expect(popupImageSizes({ ...base, isMobile: true, fitHeight: 300 })).toBe('min(calc(100vw - 48px), calc(min(70vh, 420px, 100vh - 116px, 300px) * 0.75))');
    // fit 이 없으면(하단 시트·미리보기·아직 재지 않음) 종전 식
    expect(popupImageSizes({ ...base, isMobile: false, fitHeight: null })).toBe('(max-width: 512px) min(calc(100vw - 32px), calc(min(520px, 100vh - 116px) * 0.75)), min(480px, calc(min(520px, 100vh - 116px) * 0.75))');
    expect(popupImageSizes({ ...base, isMobile: true, isSheet: true, fitHeight: null })).toBe('min(100vw, calc(min(60vh, 380px, 100vh - 116px) * 0.75))');
    // 미리보기는 기기 틀 안의 숫자 계산이라 fit 을 쓰지 않는다
    expect(popupImageSizes({ ...base, isMobile: false, preview: true, fitHeight: 200 })).toBe('390px');
  });

  // @scenario thumbnail_query=layer_srcset_fallback
  // @effects popup_layer_without_thumbnail_api_keeps_original
  it('코어 썸네일 API 가 없으면 종전과 같다 — srcset·sizes 없이 원본 주소 하나', () => {
    delete (window as any).G7Core.thumbnail;
    setViewportMobile(false);
    const { container } = show();
    const img = container.querySelector('img.g7pp-media__img');

    expect(img?.getAttribute('src')).toBe(PC);
    expect(img?.hasAttribute('srcset')).toBe(false);
    expect(img?.hasAttribute('sizes')).toBe(false);
  });

  // @scenario thumbnail_query=layer_srcset_fallback
  // @effects popup_srcset_skips_unknown_width_foreign_url_and_editor
  it('서빙 경로가 아닌 주소·가로를 모르는 원본·규격보다 작은 원본, sizes 를 주지 않은 편집기 프레임은 srcset 을 붙이지 않는다', () => {
    expect(responsiveSrcset('/img/pc.jpg', 960)).toBeUndefined();
    expect(responsiveSrcset(PC, null)).toBeUndefined();
    expect(responsiveSrcset(PC, 480)).toBeUndefined();

    const editor = render(<ImageFrame image={{ url: PC, width: 960, height: 1040 }} alt="" eager />);
    expect(editor.container.querySelector('img')?.hasAttribute('srcset')).toBe(false);
    cleanup();

    const unknown = render(<ImageFrame image={{ url: PC, width: null, height: null }} alt="" sizes="480px" />);
    expect(unknown.container.querySelector('img')?.hasAttribute('srcset')).toBe(false);
  });

  // @scenario thumbnail_query=layer_srcset
  // @effects popup_srcset_built_by_core_content_srcset_within
  it('후보 거르기는 코어 contentSrcsetWithin(원본 가로, 원본 덧붙임)이 한다', () => {
    const within = vi.fn(contentSrcsetWithin);
    (window as any).G7Core.thumbnail.contentSrcsetWithin = within;
    setViewportMobile(false);
    const { container } = show({ pc_width: 480 });

    expect(within).toHaveBeenCalledWith(PC, 960, { includeOriginal: true });
    expect(container.querySelector('img.g7pp-media__img')?.getAttribute('srcset')).toBe(`${PC}?size=md 640w, ${PC} 960w`);
  });

  // @scenario thumbnail_query=layer_srcset
  // @effects popup_srcset_built_by_core_content_srcset_within
  it('원본 가로별 srcset 은 코어 결과 그대로다 — 원본보다 작은 후보 + 원본, 남는 후보가 없으면 붙이지 않는다', () => {
    const cases: Array<[string, number | null]> = [[PC, 960], [MOBILE, 750], [PC, 1920], [PC, 2560], [PC, 640], [PC, 480], [PC, null], ['/img/pc.jpg', 960]];

    expect(cases.map(([url, width]) => responsiveSrcset(url, width))).toEqual([
      `${PC}?size=md 640w, ${PC} 960w`,
      `${MOBILE}?size=md 640w, ${MOBILE} 750w`,
      `${PC}?size=md 640w, ${PC}?size=lg 1280w, ${PC} 1920w`,
      `${PC}?size=md 640w, ${PC}?size=lg 1280w, ${PC}?size=xl 1920w, ${PC} 2560w`,
      undefined,
      undefined,
      undefined,
      undefined,
    ]);
  });

  // @scenario thumbnail_query=layer_srcset_fallback
  // @effects popup_without_within_api_has_no_srcset
  it('코어에 contentSrcsetWithin 이 없으면 contentSrcset 이 있어도 srcset 없이 원본 하나다 — 템플릿 본문 이미지와 같은 정책', () => {
    delete (window as any).G7Core.thumbnail.contentSrcsetWithin;

    expect(responsiveSrcset(PC, 960)).toBeUndefined();
    expect(responsiveSrcset(MOBILE, 750)).toBeUndefined();

    setViewportMobile(false);
    const { container } = show();
    const img = container.querySelector('img.g7pp-media__img');
    expect(img?.getAttribute('src')).toBe(PC);
    expect(img?.hasAttribute('srcset')).toBe(false);
  });

  // @scenario thumbnail_query=layer_srcset
  // @effects popup_image_sizes_capped_by_body_height
  it('이미지가 바뀌어 다시 붙을 때도 sizes → srcset → src 순서다 — WebKit 이 srcset 을 sizes 보다 먼저 보면 100vw 로 원본을 한 번 더 받는다', () => {
    const seen: string[] = [];
    const setAttribute = Element.prototype.setAttribute;
    const spy = vi.spyOn(Element.prototype, 'setAttribute').mockImplementation(function (this: Element, name: string, value: string) {
      if (this instanceof HTMLImageElement && ['src', 'srcset', 'sizes'].includes(name.toLowerCase())) seen.push(name.toLowerCase());
      return setAttribute.call(this, name, value);
    });
    try {
      const view = render(<ImageFrame image={{ url: PC, width: 960, height: 1040 }} alt="" sizes="480px" />);
      expect(seen, '처음 붙을 때').toEqual(['sizes', 'srcset', 'src']);
      const before = view.container.querySelector('img');
      seen.length = 0;
      // 기기 판정이 바뀌어 모바일 이미지로 갈 때 — 같은 img 를 갱신하면 새 sizes 가 옛 srcset 과 먼저 만나 WebKit 이 옛 이미지를 한 번 더
      // 고른다(브라우저 실측: 전환마다 2건). 이미지가 바뀌면 새 img 로 만들어 세 속성을 한 번에 붙인다
      view.rerender(<ImageFrame image={{ url: MOBILE, width: 750, height: 900 }} alt="" sizes="375px" />);
      expect(view.container.querySelector('img'), '이미지가 바뀌면 새 img').not.toBe(before);
      expect(seen, '새로 만들 때').toEqual(['sizes', 'srcset', 'src']);
      // 같은 이미지에서 sizes 만 바뀌면(화면 크기 변화) 그 img 를 그대로 두고 sizes 만 고친다
      const kept = view.container.querySelector('img');
      seen.length = 0;
      view.rerender(<ImageFrame image={{ url: MOBILE, width: 750, height: 900 }} alt="" sizes="320px" />);
      expect(view.container.querySelector('img')).toBe(kept);
      expect(seen).toEqual(['sizes']);
    } finally {
      spy.mockRestore();
    }
  });
});

/**
 * 공개 자산 디스크(#878) — 응답의 `direct_url`(원본 직접 주소)은 원본 한 장(`src`)과 srcset 의 **마지막 원본 후보**에만 쓴다.
 * 축소 후보는 공개 API 주소 `?size=` 그대로이고, 그린 크기 비교 키는 `url`(같은 출처)이다. (매니페스트 public-asset-disk.yaml)
 */
describe('팝업 이미지 공개 자산 디스크 직접 주소', () => {
  const CDN = 'https://cdn.example.com/p.jpg';

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
  });

  // @scenario surface=image_frame
  // @effects popup_image_frame_uses_direct_url
  it('ImageFrame 은 direct_url 이 있으면 src 를 직접 주소로, srcset 원본 후보만 직접 주소로 바꾼다', () => {
    const { container } = render(<ImageFrame image={{ url: PC, direct_url: CDN, width: 960, height: 1040 }} alt="" sizes="480px" />);
    const img = container.querySelector('img');

    expect(img?.getAttribute('src')).toBe(CDN);
    expect(img?.getAttribute('srcset')).toBe(`${PC}?size=md 640w, ${CDN} 960w`);
  });

  // @scenario surface=image_frame
  // @effects popup_image_frame_uses_direct_url
  it('direct_url 이 없으면 종전과 같다 — src·원본 후보 모두 API 주소, 편집기 프레임(sizes 없음)은 srcset 없이 직접 주소 하나', () => {
    const plain = render(<ImageFrame image={{ url: PC, direct_url: null, width: 960, height: 1040 }} alt="" sizes="480px" />);
    expect(plain.container.querySelector('img')?.getAttribute('src')).toBe(PC);
    expect(plain.container.querySelector('img')?.getAttribute('srcset')).toBe(`${PC}?size=md 640w, ${PC} 960w`);
    cleanup();

    const editor = render(<ImageFrame image={{ url: PC, direct_url: CDN, width: 960, height: 1040 }} alt="" eager />);
    expect(editor.container.querySelector('img')?.getAttribute('src')).toBe(CDN);
    expect(editor.container.querySelector('img')?.hasAttribute('srcset')).toBe(false);
  });

  // @scenario surface=image_frame
  // @effects popup_image_frame_uses_direct_url
  it('responsiveSrcset 은 원본 후보 꼬리가 정확할 때만 바꾸고, mediaSrc 는 비지 않은 direct_url 을 우선한다', () => {
    expect(responsiveSrcset(PC, 480, CDN)).toBeUndefined();
    expect(responsiveSrcset(PC, 960, PC)).toBe(`${PC}?size=md 640w, ${PC} 960w`);
    expect(responsiveSrcset(PC, 960, CDN)).toBe(`${PC}?size=md 640w, ${CDN} 960w`);
    expect(mediaSrc({ url: PC, direct_url: CDN })).toBe(CDN);
    expect(mediaSrc({ url: PC, direct_url: '' })).toBe(PC);
  });
});
