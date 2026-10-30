/**
 * @file HeroPreviewFrame.test.tsx
 * @description 관리자 미리보기의 구좌 틀 — 기기 viewport(PC 1366·모바일 390)는 그대로 두고, 그 안에 활성 사용자 템플릿의
 * 본문 상자 사슬(클래스·반응형 덮어쓰기)과 템플릿 CSS 를 실어 실제 사이트와 같은 구좌 폭에서 슬라이더를 그린다.
 * 반응형 키 선택은 코어 ResponsiveManager 와 같은 규칙이어야 한다. (매니페스트 preview-frame-and-conflict.yaml)
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import { describe, it, expect, afterEach, vi } from 'vitest';
import { render, cleanup, fireEvent } from '@testing-library/react';
import { responsiveManager } from '@core/template-engine/ResponsiveManager';
import { HeroBannerPreview } from '../../components/composite/HeroBannerPreview';
import { frameClass, matchResponsiveKey } from '../../support/editor';

vi.mock('swiper/react', () => import('../swiperMock'));
vi.mock('swiper/modules', () => ({ Autoplay: { name: 'autoplay' }, EffectFade: { name: 'effect-fade' }, EffectCube: { name: 'effect-cube' }, EffectFlip: { name: 'effect-flip' }, EffectCoverflow: { name: 'effect-coverflow' }, EffectCreative: { name: 'effect-creative' } }));
vi.mock('swiper/css', () => ({}));
vi.mock('swiper/css/effect-fade', () => ({}));
vi.mock('swiper/css/navigation', () => ({}));
vi.mock('swiper/css/pagination', () => ({}));
vi.mock('swiper/css/effect-cube', () => ({}));
vi.mock('swiper/css/effect-flip', () => ({}));
vi.mock('swiper/css/effect-coverflow', () => ({}));
vi.mock('swiper/css/effect-creative', () => ({}));

const banners = [{
  id: 1,
  title: '가을 신상품',
  image_alt: '가을',
  images: { pc: { url: '/pc.jpg', width: 1920, height: 640 }, mobile: null },
}];

const frame = {
  template: 'demo-user',
  stylesheets: ['/assets/demo-user/css/components.css?v=3'],
  positions: {
    home_hero: {
      layout: 'home',
      chain: [
        { className: 'min-h-screen flex flex-col', responsive: {} },
        { className: 'flex-1', responsive: {} },
        { className: 'min-h-fit px-4', responsive: { desktop: 'min-h-fit max-w-7xl mx-auto px-8' } },
      ],
    },
    shop_hero: null,
  },
};

/** iframe 을 띄우고 그 문서를 돌려준다. */
const mountFrame = (ui: React.ReactElement) => {
  const view = render(ui);
  const iframe = view.container.querySelector('iframe')!;
  fireEvent.load(iframe);
  return { view, iframe, doc: iframe.contentDocument! };
};

describe('미리보기 구좌 틀', () => {
  afterEach(() => cleanup());

  // @scenario surface=preview_frame_responsive_rule_matches_core
  it('반응형 키 선택이 코어 ResponsiveManager 와 같다(프리셋·사용자 범위·열린 범위, 사용자 범위 우선·좁은 범위 우선)', () => {
    const sets: Array<Record<string, string>> = [
      { desktop: 'a' },
      { mobile: 'a', tablet: 'b', desktop: 'c' },
      { portable: 'a', desktop: 'b' },
      { portable: 'a', mobile: 'b' },
      { '768-1023': 'a', tablet: 'b', desktop: 'c' },
      { '-599': 'a', '1200-': 'b', portable: 'c' },
      { 'bogus': 'a', desktop: 'b' },
    ];
    for (const responsive of sets) {
      for (const width of [0, 390, 599, 600, 767, 768, 800, 1023, 1024, 1199, 1200, 1366, 1920]) {
        expect(matchResponsiveKey(responsive, width), `${JSON.stringify(responsive)} @ ${width}`).toBe(responsiveManager.getMatchingKey(responsive, width));
      }
    }
    expect(frameClass({ className: 'min-h-fit px-4', responsive: { desktop: 'max-w-7xl px-8' } }, 1366)).toBe('max-w-7xl px-8');
    expect(frameClass({ className: 'min-h-fit px-4', responsive: { desktop: 'max-w-7xl px-8' } }, 390)).toBe('min-h-fit px-4');
  });

  // @scenario surface=preview_frame_slot_inside_device_viewport
  // @effects preview_matches_site_slot
  it('PC 는 viewport 1366 을 유지하고 안쪽에 데스크톱 클래스의 본문 상자를 두어 그 안에 슬라이더를 그린다', () => {
    const { iframe, doc } = mountFrame(<HeroBannerPreview banners={banners} device="pc" frame={frame} position="home_hero" />);

    expect(iframe.width).toBe('1366');
    const boxes = [...doc.body.querySelectorAll('[data-hs-frame]')];
    expect(boxes.map((b) => b.className)).toEqual(['min-h-screen flex flex-col', 'flex-1', 'min-h-fit max-w-7xl mx-auto px-8']);
    expect(boxes[2].querySelector('.hs')).not.toBeNull();
    expect(doc.body.querySelector(':scope > .hs')).toBeNull();
    const links = [...doc.head.querySelectorAll('link[data-hs-template-css]')].map((l) => l.getAttribute('href'));
    expect(links).toEqual(['/assets/demo-user/css/components.css?v=3']);
  });

  // @scenario surface=preview_frame_slot_inside_device_viewport
  // @effects preview_matches_site_slot
  it('모바일은 viewport 390 에 기본(모바일) 클래스를 쓴다', () => {
    const { iframe, doc } = mountFrame(<HeroBannerPreview banners={banners} device="mobile" frame={frame} position="home_hero" />);

    expect(iframe.width).toBe('390');
    const boxes = [...doc.body.querySelectorAll('[data-hs-frame]')];
    expect(boxes[2].className).toBe('min-h-fit px-4');
    expect(boxes[2].querySelector('.hs')).not.toBeNull();
  });

  // @scenario surface=preview_frame_slot_inside_device_viewport
  // @effects preview_matches_site_slot
  it('틀(위치 설정 응답)이 미리보기보다 늦게 와도 템플릿 CSS 를 싣고 상자 사슬로 옮겨 그린다', () => {
    const { view, iframe, doc } = mountFrame(<HeroBannerPreview banners={banners} device="pc" position="home_hero" />);
    expect(doc.head.querySelectorAll('link[data-hs-template-css]').length).toBe(0);

    view.rerender(<HeroBannerPreview banners={banners} device="pc" frame={frame} position="home_hero" />);

    expect(iframe.contentDocument).toBe(doc);
    expect([...doc.head.querySelectorAll('link[data-hs-template-css]')].map((l) => l.getAttribute('href'))).toEqual(['/assets/demo-user/css/components.css?v=3']);
    expect(doc.body.querySelectorAll('[data-hs-frame]').length).toBe(3);
    expect(doc.body.querySelectorAll('.hs').length).toBe(1);
  });

  // @scenario surface=preview_frame_missing_slot_falls_back
  it('틀이 없거나 그 위치에 확장점이 없으면 종전처럼 기기 폭 전체에 그린다(오버라이드·기존 호출 호환)', () => {
    for (const props of [{}, { frame, position: 'shop_hero' }, { frame: null, position: 'home_hero' }]) {
      const { doc, view } = mountFrame(<HeroBannerPreview banners={banners} device="pc" {...props} />);
      expect(doc.body.querySelectorAll('[data-hs-frame]').length).toBe(0);
      expect(doc.body.querySelector('.hs')).not.toBeNull();
      expect(doc.head.querySelectorAll('link[data-hs-template-css]').length).toBe(0);
      view.unmount();
    }
  });

  // @scenario surface=preview_frame_content_box
  // @effects preview_follows_width_before_save
  it('본문 폭이면 구좌 틀의 본문 상자(기기 폭에 맞는 클래스)로 슬라이더를 감싼다', () => {
    const withContent = { ...frame, positions: { ...frame.positions, home_hero: { ...frame.positions.home_hero, content: { className: 'px-4', responsive: { desktop: 'max-w-7xl mx-auto px-8' } } } } };
    const pc = mountFrame(<HeroBannerPreview banners={banners} device="pc" frame={withContent} position="home_hero" />);
    expect(pc.doc.querySelector('[data-hs-width="content"]')?.className).toBe('hs-frame max-w-7xl mx-auto px-8');
    cleanup();
    const mobile = mountFrame(<HeroBannerPreview banners={banners} device="mobile" frame={withContent} position="home_hero" />);
    expect(mobile.doc.querySelector('[data-hs-width="content"]')?.className).toBe('hs-frame px-4');
  });

  // @scenario surface=preview_frame_content_box
  // @effects preview_follows_width_before_save
  it('화면 전체 폭이면 감싸지 않고 문구 층에 본문 상자를 쓴다', () => {
    const withContent = { ...frame, positions: { ...frame.positions, home_hero: { ...frame.positions.home_hero, content: { className: 'px-4', responsive: { desktop: 'max-w-7xl mx-auto px-8' } } } } };
    const { doc } = mountFrame(<HeroBannerPreview banners={banners} device="pc" frame={withContent} position="home_hero" settings={{ width: 'full' }} />);
    expect(doc.querySelector('[data-hs-width]')).toBeNull();
    expect(doc.querySelector('section.hs')?.className).toContain('hs--full');
    expect(doc.querySelector('.hs__box')?.className).toBe('hs__box max-w-7xl mx-auto px-8');
  });
});
