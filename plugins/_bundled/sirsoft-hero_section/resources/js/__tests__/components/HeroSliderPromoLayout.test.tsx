/**
 * @file HeroSliderPromoLayout.test.tsx
 * @description 배너 구성(배경형·프로모션형)·기기별 문구 폭·순차 등장·그림 등장 효과·제목 줄바꿈 — 옛 배너는 화면 구조·값이 그대로다.
 * (매니페스트 promo-layout.yaml)
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, cleanup, fireEvent, act } from '@testing-library/react';
import { HeroSlider, type HeroBanner } from '../../components/composite/HeroSlider';
import { lineDelay, mediaMotion, textMotion, textVars, textWidth } from '../../support/text';
import { swiperState } from '../swiperMock';

// jsdom 25 에는 PointerEvent 가 없어 fireEvent.pointer* 가 일반 Event 로 나가고 clientX·pointerId 가 사라진다 —
// 끌어 옮기기 시험이 좌표를 받도록 MouseEvent 위에 pointerId 만 더한 대역을 둔다(브라우저에는 원래 있다).
if (typeof window.PointerEvent === 'undefined') {
    class PointerEventStub extends MouseEvent {
        readonly pointerId: number;

        constructor(type: string, init: PointerEventInit = {}) {
            super(type, init);
            this.pointerId = init.pointerId ?? 0;
        }
    }
    (window as unknown as { PointerEvent: typeof PointerEventStub }).PointerEvent = PointerEventStub;
}

vi.mock('swiper/react', () => import('../swiperMock'));
vi.mock('swiper/modules', () => ({ Autoplay: { name: 'autoplay' }, EffectFade: { name: 'effect-fade' }, EffectCube: { name: 'effect-cube' }, EffectFlip: { name: 'effect-flip' }, EffectCoverflow: { name: 'effect-coverflow' }, EffectCreative: { name: 'effect-creative' } }));

const banner = (id: number, extra: Partial<HeroBanner> = {}): HeroBanner => ({
  id,
  title: `제목 ${id}`,
  eyebrow: `머리글 ${id}`,
  eyebrow_visible: true,
  eyebrow_color: '#FDE68A',
  description: `설명 ${id}`,
  button_text: '보기',
  image_alt: `대체 ${id}`,
  title_visible: true,
  title_color: '#FFFFFF',
  title_align: 'center',
  description_visible: true,
  description_color: '#E5E7EB',
  description_align: 'left',
  link_url: `/shop/${id}`,
  link_new_window: false,
  images: { pc: { url: `/img/pc-${id}.jpg`, width: 1920, height: 640 }, mobile: null },
  ...extra,
});

const mockReducedMotion = (reduce: boolean) => {
  window.matchMedia = vi.fn().mockImplementation((query: string) => ({
    matches: reduce && query.includes('prefers-reduced-motion'),
    media: query,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
  })) as unknown as typeof window.matchMedia;
};


const style = (element: Element | null) => (element as HTMLElement).style;
const slide = (container: HTMLElement) => container.querySelector('.hs-slider__slide') as HTMLElement;

describe('배너 구성·프로모션형', () => {
  beforeEach(() => {
    mockReducedMotion(false);
    window.localStorage.clear();
  });

  afterEach(() => {
    cleanup();
  });

  // @scenario surface=promo_layout_legacy_render
  it('새 키가 없는 옛 배너는 배경형 그대로다 — 미디어 감싸개·프로모션 칸 없이 그림·문구 띠가 장 바로 아래에 있다', () => {
    const { container } = render(<HeroSlider banners={[banner(1, { presentation: { animation: 'letters', text_width: 60, text_pc_y: 40 } })]} settings={{ autoplay: false }} />);
    const el = slide(container);
    expect(el.className).not.toContain('hs-slider__slide--promo');
    expect(container.querySelector('.hs-promo, .hs-slider__media')).toBeNull();
    expect(el.querySelector(':scope > .hs-slider__picture')).not.toBeNull();
    expect(el.querySelector(':scope > .hs-slider__text')).not.toBeNull();
    const vars = style(el.querySelector('.hs-slider__text'));
    // 기기별 폭이 없으면 공통 폭을 두 기기에 쓴다
    expect([vars.getPropertyValue('--hs-tw'), vars.getPropertyValue('--hs-tw-pc'), vars.getPropertyValue('--hs-tw-mobile')]).toEqual(['60', '60', '60']);
    expect(el.querySelector('.hs-slider__textbox')?.className).toContain('hs-slider__textbox--letters');
  });

  // @scenario surface=promo_layout_render
  it('프로모션형은 바탕색 위에 문구 칸·그림 칸을 나누고, PC 문구 폭이 문구 칸 폭이며, 가로 위치·휴대폰 세로 위치로 좌우·위아래가 정해진다', () => {
    const presentation = { layout: 'promo', background_color: '#F4F5F8', text_width_pc: 40, text_width_mobile: 100, text_pc_x: 0, text_mobile_y: 100, text_width: 70 };
    const { container, rerender } = render(<HeroSlider banners={[banner(1, { presentation })]} settings={{ autoplay: false, width: 'full' }} contentClass="max-w-7xl mx-auto px-8" />);
    const el = slide(container);
    expect(el.className).toContain('hs-slider__slide--promo');
    expect(el.style.getPropertyValue('--hs-slide-bg')).toBe('#F4F5F8');
    expect([el.getAttribute('data-hs-side-pc'), el.getAttribute('data-hs-stack-mobile')]).toEqual(['left', 'media']);
    // 화면 전체 폭 — 본문 상자 클래스는 바깥 상자 한 번만(문구 띠는 상자를 다시 감싸지 않는다)
    expect(el.querySelector('.hs-promo > .hs-promo__box.max-w-7xl')).not.toBeNull();
    expect(el.querySelectorAll('.max-w-7xl')).toHaveLength(1);
    expect(el.querySelector('.hs-promo__text')?.getAttribute('style')).toContain('--hs-promo-tw: 40');
    expect(el.querySelector('.hs-promo__box > .hs-promo__media .hs-slider__image')).not.toBeNull();
    const vars = style(el.querySelector('.hs-slider__text'));
    expect([vars.getPropertyValue('--hs-tw-pc'), vars.getPropertyValue('--hs-tw-mobile')]).toEqual(['40', '100']);

    rerender(<HeroSlider banners={[banner(1, { presentation: { ...presentation, text_pc_x: 100, text_mobile_y: 0 } })]} settings={{ autoplay: false }} />);
    const moved = slide(container);
    expect([moved.getAttribute('data-hs-side-pc'), moved.getAttribute('data-hs-stack-mobile')]).toEqual(['right', 'text']);
    // 본문 폭 — 상자가 없으면 문구와 같은 안쪽 여백
    expect(moved.querySelector('.hs-promo')?.className).toContain('hs-promo--inset');
  });

  // @scenario surface=promo_layout_render
  it('프로모션형도 기존 옵션을 그대로 쓴다 — 문구 바탕·밝기·정렬·링크·영상 경로', () => {
    const { container } = render(<HeroSlider banners={[banner(1, { media_type: 'video', videos: { pc: { url: '/v/pc.mp4', mime: 'video/mp4' } }, button_text: '', presentation: { layout: 'promo', overlay: 'light', tone: 'light' } } as any)]} settings={{ autoplay: false }} />);
    const el = slide(container);
    expect(el.querySelector('.hs-promo__text .hs-slider__text.hs-slider__text--light')).not.toBeNull();
    expect(el.querySelector('.hs-promo__media .hs-slider__picture')).not.toBeNull();
    expect(el.querySelector('.hs-promo__media video, .hs-promo__media .hs-slider__video')).not.toBeNull();
    // 버튼 없는 배너는 전체 링크 — 그림 칸 위에서도 누를 수 있게 링크는 장 전체를 덮는다
    expect(el.querySelector(':scope > a.hs-slider__link')).not.toBeNull();
    expect(el.querySelector('.hs-slider__title')?.getAttribute('style')).toContain('text-align: center');
  });

  // @scenario surface=promo_motion
  it('순차 등장은 줄 간격 0.3초·보통 0.4초, 그림 등장 효과는 감싸개에 0.8초로 실리고, 움직임 줄이기면 둘 다 없다', () => {
    const presentation = { animation: 'sequence', animation_speed: 'normal', animation_delay: 0, media_animation: 'right' };
    expect(textMotion(presentation, false)).toEqual({ name: 'sequence', duration: 0.4, delay: 0 });
    expect([0, 1, 2].map((i) => lineDelay(textMotion(presentation, false), i)['--hs-line-delay' as keyof React.CSSProperties])).toEqual(['0s', '0.3s', '0.6s']);
    // 다른 효과는 종전 0.2초 간격·0.6초
    expect(lineDelay(textMotion({ animation: 'rise' }, false), 2)['--hs-line-delay' as keyof React.CSSProperties]).toBe('0.4s');
    expect(mediaMotion(presentation, false)).toEqual({ name: 'right', duration: 0.8, delay: 0 });
    expect(mediaMotion(presentation, true).name).toBe('none');

    const { container } = render(<HeroSlider banners={[banner(1, { presentation })]} settings={{ autoplay: false }} />);
    const media = container.querySelector('.hs-slider__media.hs-slider__media--right') as HTMLElement;
    expect(media.style.getPropertyValue('--hs-media-duration')).toBe('0.8s');
    expect(container.querySelector('.hs-slider__textbox--sequence')).not.toBeNull();
    cleanup();

    mockReducedMotion(true);
    const reduced = render(<HeroSlider banners={[banner(1, { presentation })]} settings={{ autoplay: false }} />);
    expect(reduced.container.querySelector('.hs-slider__media')).toBeNull();
    expect(reduced.container.querySelector('.hs-slider__textbox--none')).not.toBeNull();
  });

  // @scenario surface=promo_layout_render
  it('기기별 폭은 비우면 공통 폭, 미리보기 기기 고정은 두 칸에 그 기기 값을 넣는다. 바탕색이 형식에 맞지 않으면 쓰지 않는다', () => {
    expect(textWidth({ text_width: 70, text_width_pc: null }, 'pc')).toBe(70);
    expect(textWidth({ text_width: 70, text_width_mobile: 100 }, 'mobile')).toBe(100);
    expect(textWidth({}, 'pc')).toBe(100);
    const vars = textVars({ text_width_pc: 40, text_width_mobile: 90 }, 'mobile') as Record<string, unknown>;
    expect([vars['--hs-tw-pc'], vars['--hs-tw-mobile']]).toEqual([90, 90]);
    const { container } = render(<HeroSlider banners={[banner(1, { presentation: { layout: 'promo', background_color: 'red' } })]} settings={{ autoplay: false }} />);
    expect(slide(container).style.getPropertyValue('--hs-slide-bg')).toBe('');
  });

  // @scenario surface=promo_multiline
  it('제목·설명의 줄바꿈 입력을 글자 그대로 싣는다 — HTML 로 해석하지 않는다', () => {
    const { container } = render(<HeroSlider banners={[banner(1, { title: '가을 맞이\n<b>이벤트</b>', description: '첫 줄\n둘째 줄' })]} settings={{ autoplay: false }} />);
    const title = container.querySelector('.hs-slider__title') as HTMLElement;
    expect(title.textContent).toBe('가을 맞이\n<b>이벤트</b>');
    expect(title.querySelector('b')).toBeNull();
    expect(container.querySelector('.hs-slider__description')?.textContent).toBe('첫 줄\n둘째 줄');
  });
  // @scenario surface=promo_layout_render
  it('프로모션형이 한 장이라도 있으면 슬라이더에 hs-slider--promo 를 달고(PC·태블릿 최소 높이), 다음 방문 스켈레톤도 같은 높이로 잡는다', () => {
    const promo = banner(2, { presentation: { layout: 'promo' } });
    const { container, unmount } = render(<HeroSlider banners={[banner(1), promo]} settings={{ autoplay: false, width: 'full' }} position="product_hero" target="11" />);
    expect(container.querySelector('section.hs-slider')?.className).toContain('hs-slider--promo');
    unmount();
    // 배경형만 있으면 달지 않는다 — 기존 배너의 높이는 그대로
    const plain = render(<HeroSlider banners={[banner(1)]} settings={{ autoplay: false, width: 'full' }} position="product_hero" target="12" />);
    expect(plain.container.querySelector('section.hs-slider')?.className).not.toContain('hs-slider--promo');
    plain.unmount();
    // 같은 자리의 다음 방문 — 받는 동안의 스켈레톤도 프로모션형 높이(태블릿에서 256 → 400 으로 뛰지 않게)
    const again = render(<HeroSlider banners={[]} loading position="product_hero" target="11" />);
    expect(again.container.querySelector('section.hs-slider--skeleton')?.className).toContain('hs-slider--promo');
    again.unmount();
    const other = render(<HeroSlider banners={[]} loading position="product_hero" target="12" />);
    expect(other.container.querySelector('section.hs-slider--skeleton')?.className ?? '').not.toContain('hs-slider--promo');
  });
});
