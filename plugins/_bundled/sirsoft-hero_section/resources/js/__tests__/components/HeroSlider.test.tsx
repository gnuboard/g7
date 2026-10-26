/**
 * @file HeroSlider.test.tsx
 * @description 사용자 슬라이더 — 빈 목록 숨김, 1장 컨트롤 없음, picture 전환, 문구 표시, 링크 방식, 새 창 rel,
 * 자동 재생·멈춤·움직임 줄이기, 효과, 키보드, 접근성 역할, 목록 변경 시 처음으로, 이미지 로딩·크기·비율.
 * (매니페스트 hero-slider.yaml)
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, cleanup, fireEvent, act } from '@testing-library/react';
import { HeroSlider, type HeroBanner } from '../../components/composite/HeroSlider';

/** 배너 하나를 만든다. */
const banner = (id: number, extra: Partial<HeroBanner> = {}): HeroBanner => ({
  id,
  title: `제목 ${id}`,
  description: `설명 ${id}`,
  button_text: '',
  image_alt: `대체 ${id}`,
  title_visible: true,
  title_color: '#FFFFFF',
  title_align: 'left',
  description_visible: true,
  description_color: '#E5E7EB',
  description_align: 'left',
  link_url: `/shop/${id}`,
  link_new_window: false,
  images: { pc: { url: `/img/pc-${id}.jpg`, width: 1920, height: 640 }, mobile: { url: `/img/m-${id}.jpg`, width: 750, height: 1000 } },
  ...extra,
});

/** 움직임 줄이기 설정을 흉내 낸다. */
const mockReducedMotion = (reduce: boolean) => {
  window.matchMedia = vi.fn().mockImplementation((query: string) => ({
    matches: reduce && query.includes('prefers-reduced-motion'),
    media: query,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
  })) as unknown as typeof window.matchMedia;
};

/** 지금 보이는 슬라이드의 배너 id */
const activeId = (container: HTMLElement) => container.querySelector('.hs__slide--active')?.getAttribute('data-banner-id');

describe('HeroSlider', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    mockReducedMotion(false);
    (window as any).G7Core = { t: (key: string) => key };
  });

  afterEach(() => {
    cleanup();
    vi.useRealTimers();
    delete (window as any).G7Core;
  });

  // @scenario surface=renders_nothing_when_empty
  it('배너가 없으면 아무것도 그리지 않는다', () => {
    const { container } = render(<HeroSlider banners={[]} />);
    expect(container.innerHTML).toBe('');
  });

  // @scenario surface=single_banner_hides_controls
  it('배너 1장이면 컨트롤과 자동 재생이 없다', () => {
    const { container } = render(<HeroSlider banners={[banner(1)]} settings={{ autoplay: true, interval_seconds: 3 }} />);
    expect(container.querySelector('.hs__controls')).toBeNull();
    act(() => { vi.advanceTimersByTime(10000); });
    expect(activeId(container)).toBe('1');
  });

  // @scenario surface=picture_has_mobile_source
  // @effects responsive_image_switch
  it('모바일 이미지가 있으면 767px 이하 source 를 둔다', () => {
    const { container } = render(<HeroSlider banners={[banner(1)]} />);
    const source = container.querySelector('source');
    expect(source?.getAttribute('media')).toBe('(max-width: 767px)');
    expect(source?.getAttribute('srcset')).toBe('/img/m-1.jpg');
    expect(container.querySelector('img')?.getAttribute('src')).toBe('/img/pc-1.jpg');
  });

  // @scenario surface=picture_omits_mobile_source_when_missing
  // @effects responsive_image_switch
  it('모바일 이미지가 없으면 source 없이 PC 이미지만 쓴다', () => {
    const { container } = render(<HeroSlider banners={[banner(1, { images: { pc: { url: '/img/pc-1.jpg', width: 1920, height: 640 }, mobile: null } })]} />);
    expect(container.querySelector('source')).toBeNull();
    expect(container.querySelector('img')?.getAttribute('src')).toBe('/img/pc-1.jpg');
  });

  // @scenario surface=text_visibility_color_align
  it('문구 표시 여부·색·정렬을 따른다', () => {
    const { container, rerender } = render(<HeroSlider banners={[banner(1, { title_color: '#112233', title_align: 'center', description_align: 'right' })]} />);
    const title = container.querySelector('.hs__title') as HTMLElement;
    expect(title.textContent).toBe('제목 1');
    expect(title.style.color).toBe('rgb(17, 34, 51)');
    expect(title.style.textAlign).toBe('center');
    expect((container.querySelector('.hs__description') as HTMLElement).style.textAlign).toBe('right');

    rerender(<HeroSlider banners={[banner(1, { title_visible: false, description_visible: false })]} />);
    expect(container.querySelector('.hs__title')).toBeNull();
    expect(container.querySelector('.hs__description')).toBeNull();
  });

  // @scenario surface=button_text_makes_button_link
  it('버튼 문구가 있으면 버튼만 링크다', () => {
    const { container } = render(<HeroSlider banners={[banner(1, { button_text: '보러 가기' })]} />);
    const links = container.querySelectorAll('a');
    expect(links).toHaveLength(1);
    expect(links[0].className).toBe('hs__button');
    expect(links[0].textContent).toBe('보러 가기');
    expect(links[0].getAttribute('href')).toBe('/shop/1');
  });

  // @scenario surface=empty_button_makes_whole_slide_link
  it('버튼 문구가 없으면 슬라이드 전체가 제목 이름의 링크다', () => {
    const { container } = render(<HeroSlider banners={[banner(1)]} />);
    const link = container.querySelector('a.hs__link');
    expect(link?.getAttribute('href')).toBe('/shop/1');
    expect(link?.getAttribute('aria-label')).toBe('제목 1');
    expect(link?.querySelector('img')).not.toBeNull();
    expect(container.querySelector('.hs__button')).toBeNull();
  });

  // @scenario surface=new_window_sets_rel_noopener_noreferrer
  it('새 창이면 target=_blank 와 rel=noopener noreferrer 를 붙인다', () => {
    const { container } = render(<HeroSlider banners={[banner(1, { link_new_window: true }), banner(2, { link_new_window: true, button_text: '보기' })]} />);
    const anchors = Array.from(container.querySelectorAll('a'));
    expect(anchors).toHaveLength(2);
    anchors.forEach((a) => {
      expect(a.getAttribute('target')).toBe('_blank');
      expect(a.getAttribute('rel')).toBe('noopener noreferrer');
    });
  });

  // @scenario surface=autoplay_advances_by_interval
  it('자동 재생은 설정 간격마다 다음 장으로 간다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2), banner(3)]} settings={{ autoplay: true, interval_seconds: 3 }} />);
    expect(activeId(container)).toBe('1');
    act(() => { vi.advanceTimersByTime(2999); });
    expect(activeId(container)).toBe('1');
    act(() => { vi.advanceTimersByTime(1); });
    expect(activeId(container)).toBe('2');
    act(() => { vi.advanceTimersByTime(6000); });
    expect(activeId(container)).toBe('1');
  });

  // @scenario surface=autoplay_pauses_on_hover_and_focus
  it('마우스를 올리거나 포커스가 들어가면 자동 재생이 멈춘다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: true, interval_seconds: 3 }} />);
    const region = container.querySelector('section') as HTMLElement;

    fireEvent.mouseEnter(region);
    act(() => { vi.advanceTimersByTime(9000); });
    expect(activeId(container)).toBe('1');
    fireEvent.mouseLeave(region);
    act(() => { vi.advanceTimersByTime(3000); });
    expect(activeId(container)).toBe('2');

    fireEvent.focus(container.querySelector('.hs__next') as HTMLElement);
    act(() => { vi.advanceTimersByTime(9000); });
    expect(activeId(container)).toBe('2');
  });

  // @scenario surface=pause_button_toggles_autoplay
  it('일시정지 단추가 자동 재생을 멈추고 다시 켠다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: true, interval_seconds: 3 }} />);
    const toggle = container.querySelector('.hs__toggle') as HTMLButtonElement;
    expect(toggle.getAttribute('aria-pressed')).toBe('false');

    fireEvent.click(toggle);
    expect(toggle.getAttribute('aria-pressed')).toBe('true');
    // 단추를 누르며 포커스가 들어갔다면 그 멈춤과 별개로 일시정지가 유지되는지 보려고 포커스를 뺀다
    fireEvent.blur(toggle, { relatedTarget: document.body });
    act(() => { vi.advanceTimersByTime(9000); });
    expect(activeId(container)).toBe('1');

    fireEvent.click(toggle);
    fireEvent.blur(toggle, { relatedTarget: document.body });
    act(() => { vi.advanceTimersByTime(3000); });
    expect(activeId(container)).toBe('2');
  });

  // @scenario surface=reduced_motion_disables_autoplay
  it('움직임 줄이기를 켠 사용자는 자동 재생·효과가 꺼진다', () => {
    mockReducedMotion(true);
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: true, interval_seconds: 3, effect: 'slide' }} />);
    act(() => { vi.advanceTimersByTime(9000); });
    expect(activeId(container)).toBe('1');
    expect(container.querySelector('section')?.className).toContain('hs--none');
    expect(container.querySelector('.hs__toggle')).toBeNull();
  });

  // @scenario surface=effect_fade_and_none
  it('효과 fade·none 은 이동 대신 보이는 장만 바꾼다', () => {
    for (const effect of ['fade', 'none'] as const) {
      const { container, unmount } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: false, effect }} />);
      expect(container.querySelector('section')?.className).toContain(`hs--${effect}`);
      expect((container.querySelector('.hs__track') as HTMLElement).style.transform).toBe('');
      fireEvent.click(container.querySelector('.hs__next') as HTMLElement);
      expect(activeId(container)).toBe('2');
      unmount();
    }
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: false, effect: 'slide' }} />);
    fireEvent.click(container.querySelector('.hs__next') as HTMLElement);
    expect((container.querySelector('.hs__track') as HTMLElement).style.transform).toBe('translateX(-100%)');
  });

  // @scenario surface=prev_next_buttons_and_keyboard
  it('이전·다음 단추와 좌우 화살표 키로 넘긴다(끝에서 처음으로 돈다)', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2), banner(3)]} settings={{ autoplay: false }} />);
    const region = container.querySelector('section') as HTMLElement;

    fireEvent.click(container.querySelector('.hs__prev') as HTMLElement);
    expect(activeId(container)).toBe('3');
    fireEvent.keyDown(region, { key: 'ArrowRight' });
    expect(activeId(container)).toBe('1');
    fireEvent.keyDown(region, { key: 'ArrowRight' });
    fireEvent.keyDown(region, { key: 'ArrowLeft' });
    expect(activeId(container)).toBe('1');
    fireEvent.click(container.querySelector('.hs__next') as HTMLElement);
    expect(activeId(container)).toBe('2');
  });

  // @scenario surface=carousel_aria_roles_and_counter
  // @effects accessible_carousel_semantics
  it('캐러셀·슬라이드 역할과 번호를 준다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: false }} />);
    const region = container.querySelector('section') as HTMLElement;
    expect(region.getAttribute('role')).toBe('region');
    expect(region.getAttribute('aria-roledescription')).toBe('carousel');
    expect(region.getAttribute('aria-label')).toBeTruthy();

    const slides = container.querySelectorAll('[aria-roledescription="slide"]');
    expect(slides).toHaveLength(2);
    expect(slides[0].getAttribute('aria-label')).toBe('1 / 2');
    expect(slides[1].getAttribute('aria-hidden')).toBe('true');
    expect(container.querySelector('.hs__counter')?.textContent).toBe('1 / 2');
    expect(container.querySelector('.hs__prev')?.getAttribute('aria-label')).toBeTruthy();
  });

  // @scenario surface=index_resets_when_banners_change
  it('배너 목록이 바뀌면 첫 장으로 돌아간다', () => {
    const { container, rerender } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: false }} />);
    fireEvent.click(container.querySelector('.hs__next') as HTMLElement);
    expect(activeId(container)).toBe('2');

    rerender(<HeroSlider banners={[banner(5), banner(6), banner(7)]} settings={{ autoplay: false }} />);
    expect(activeId(container)).toBe('5');
  });

  // @scenario surface=first_image_eager_rest_lazy
  it('첫 장 이미지는 즉시·높은 우선순위, 나머지는 지연 로딩', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2), banner(3)]} settings={{ autoplay: false }} />);
    const imgs = container.querySelectorAll('img');
    expect(imgs[0].getAttribute('loading')).toBe('eager');
    expect(imgs[0].getAttribute('fetchpriority')).toBe('high');
    expect(imgs[2].getAttribute('loading')).toBe('lazy');
    expect(imgs[2].getAttribute('fetchpriority')).toBeNull();
  });

  // @scenario surface=next_slide_preloaded_before_transition
  it('다음으로 넘어갈 장은 전환 전에 미리 받는다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2), banner(3)]} settings={{ autoplay: false }} />);
    expect(container.querySelectorAll('img')[1].getAttribute('loading')).toBe('eager');

    fireEvent.click(container.querySelector('.hs__next') as HTMLElement);
    expect(container.querySelectorAll('img')[2].getAttribute('loading')).toBe('eager');
  });

  // @scenario surface=images_have_dimensions_and_aspect_ratio
  // @effects responsive_image_switch
  it('이미지에 크기를, 영역에 PC·모바일 비율을 준다', () => {
    const { container } = render(<HeroSlider banners={[banner(1)]} />);
    const img = container.querySelector('img') as HTMLImageElement;
    expect(img.getAttribute('width')).toBe('1920');
    expect(img.getAttribute('height')).toBe('640');
    expect(container.querySelector('source')?.getAttribute('width')).toBe('750');
    const style = (container.querySelector('section') as HTMLElement).style;
    expect(style.getPropertyValue('--hs-ratio-pc')).toBe('1920 / 640');
    expect(style.getPropertyValue('--hs-ratio-mobile')).toBe('750 / 1000');
  });

  // @scenario surface=aspect_ratio_falls_back_to_recommended
  it('크기 정보가 없으면 권장 비율, 모바일 이미지가 없으면 모바일도 PC 비율을 쓴다', () => {
    const { container, rerender } = render(<HeroSlider banners={[banner(1, { images: { pc: { url: '/p.jpg' }, mobile: null } })]} />);
    let style = (container.querySelector('section') as HTMLElement).style;
    expect(style.getPropertyValue('--hs-ratio-pc')).toBe('1920 / 640');
    expect(style.getPropertyValue('--hs-ratio-mobile')).toBe('1920 / 640');

    rerender(<HeroSlider banners={[banner(1, { images: { pc: { url: '/p.jpg', width: 1600, height: 600 }, mobile: { url: '/m.jpg' } } })]} />);
    style = (container.querySelector('section') as HTMLElement).style;
    expect(style.getPropertyValue('--hs-ratio-pc')).toBe('1600 / 600');
    expect(style.getPropertyValue('--hs-ratio-mobile')).toBe('750 / 1000');
  });
});
