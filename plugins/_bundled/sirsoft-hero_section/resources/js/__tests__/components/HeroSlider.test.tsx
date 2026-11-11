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
import { swiperState } from '../swiperMock';

// 넘김은 Swiper 가 맡는다 — jsdom 에서는 대역으로 연결 계약(옵션·호출·장 번호 동기)만 보고, 실제 넘김은 E2E 가 본다
vi.mock('swiper/react', () => import('../swiperMock'));
vi.mock('swiper/modules', () => ({ Autoplay: { name: 'autoplay' }, EffectFade: { name: 'effect-fade' }, EffectCube: { name: 'effect-cube' }, EffectFlip: { name: 'effect-flip' }, EffectCoverflow: { name: 'effect-coverflow' }, EffectCreative: { name: 'effect-creative' } }));

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
const activeId = (container: HTMLElement) => container.querySelector('.hs-slider__slide--active')?.getAttribute('data-banner-id');

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

  // @scenario surface=admin_edit_link_for_editors
  it('수정 권한이 있으면 지금 보이는 배너의 관리자 수정 화면으로 새 탭 링크를 그린다', () => {
    const { container } = render(<HeroSlider banners={[banner(7), banner(8)]} editable />);
    const link = container.querySelector<HTMLAnchorElement>('a.hs-slider__admin-edit');
    expect(link?.getAttribute('href')).toBe('/admin/plugins/sirsoft-hero_section/banners/7/edit');
    expect(link?.getAttribute('target')).toBe('_blank');
    expect(link?.getAttribute('rel')).toContain('noopener');
    expect(link?.textContent).toContain('관리자에서 수정');

    act(() => swiperState.props?.onRealIndexChange?.({ realIndex: 1 }));
    expect(container.querySelector('a.hs-slider__admin-edit')?.getAttribute('href')).toBe('/admin/plugins/sirsoft-hero_section/banners/8/edit');
  });

  // @scenario surface=admin_edit_link_for_editors
  it('수정 권한이 없거나 편집 미리보기면 수정 링크를 그리지 않는다', () => {
    const { container, rerender } = render(<HeroSlider banners={[banner(7)]} />);
    expect(container.querySelector('a.hs-slider__admin-edit')).toBeNull();

    rerender(<HeroSlider banners={[banner(7)]} editable forceDevice="pc" />);
    expect(container.querySelector('a.hs-slider__admin-edit')).toBeNull();
  });

  // @scenario surface=renders_nothing_when_empty
  // @effects slider_controls_follow_banner_count
  it('배너가 없으면 아무것도 그리지 않는다', () => {
    const { container } = render(<HeroSlider banners={[]} />);
    expect(container.innerHTML).toBe('');
  });

  // @scenario surface=single_banner_hides_controls
  // @effects slider_controls_follow_banner_count
  it('배너 1장이면 컨트롤·자동 재생·넘기기·무한 반복이 없다', () => {
    const { container } = render(<HeroSlider banners={[banner(1)]} settings={{ autoplay: true, interval_seconds: 3 }} />);
    expect(container.querySelector('.hs-slider__controls')).toBeNull();
    expect(swiperState.props?.autoplay).toBe(false);
    expect(swiperState.props?.allowTouchMove).toBe(false);
    expect(swiperState.props?.loop).toBe(false);
    expect(activeId(container)).toBe('1');
  });

  // @scenario surface=loop_and_touch_swipe_enabled
  // @effects slider_controls_follow_banner_count
  it('2장 이상이면 Swiper 무한 반복·손가락 넘기기·PC 마우스 끌기(잡기 커서)를 켜고, 사진은 끌려 나오지 않는다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: false }} />);
    expect(swiperState.props?.loop).toBe(true);
    expect(swiperState.props?.allowTouchMove).toBe(true);
    expect(swiperState.props?.simulateTouch).toBe(true);
    expect(swiperState.props?.grabCursor).toBe(true);
    expect(container.querySelector('img')?.getAttribute('draggable')).toBe('false');
    expect(swiperState.props?.className).toBe('hs-slider__track');
  });

  // @scenario surface=main_image_device_from_engine_width
  // @effects responsive_image_switch
  it('넓은 화면은 PC 이미지, 휴대폰 화면(엔진 폭 767px 이하)은 모바일 이미지를 주 이미지로 쓰고 창 폭 source 는 두지 않는다', () => {
    const wide = render(<HeroSlider banners={[banner(1)]} />);
    expect(wide.container.querySelector('source')).toBeNull();
    expect(wide.container.querySelector('img')?.getAttribute('src')).toBe('/img/pc-1.jpg');
    wide.unmount();

    (window as any).G7Core.useResponsive = () => ({ width: 375 });
    const phone = render(<HeroSlider banners={[banner(1)]} />);
    expect(phone.container.querySelector('source')).toBeNull();
    expect(phone.container.querySelector('img')?.getAttribute('src')).toBe('/img/m-1.jpg');
  });

  // @scenario surface=picture_omits_mobile_source_when_missing
  // @effects responsive_image_switch
  it('모바일 이미지가 없으면 source 없이 PC 이미지만 쓴다', () => {
    const { container } = render(<HeroSlider banners={[banner(1, { images: { pc: { url: '/img/pc-1.jpg', width: 1920, height: 640 }, mobile: null } })]} />);
    expect(container.querySelector('source')).toBeNull();
    expect(container.querySelector('img')?.getAttribute('src')).toBe('/img/pc-1.jpg');
  });

  // @scenario surface=text_visibility_color_align
  // @effects banner_text_follows_display_settings
  it('문구 표시 여부·색·정렬을 따른다', () => {
    const { container, rerender } = render(<HeroSlider banners={[banner(1, { title_color: '#112233', title_align: 'center', description_align: 'right' })]} />);
    const title = container.querySelector('.hs-slider__title') as HTMLElement;
    expect(title.textContent).toBe('제목 1');
    expect(title.style.color).toBe('rgb(17, 34, 51)');
    expect(title.style.textAlign).toBe('center');
    expect((container.querySelector('.hs-slider__description') as HTMLElement).style.textAlign).toBe('right');

    rerender(<HeroSlider banners={[banner(1, { title_visible: false, description_visible: false })]} />);
    expect(container.querySelector('.hs-slider__title')).toBeNull();
    expect(container.querySelector('.hs-slider__description')).toBeNull();
  });

  // @scenario surface=button_text_makes_button_link
  // @effects banner_link_follows_link_settings
  it('버튼 문구가 있으면 버튼만 링크다', () => {
    const { container } = render(<HeroSlider banners={[banner(1, { button_text: '보러 가기' })]} />);
    const links = container.querySelectorAll('a');
    expect(links).toHaveLength(1);
    expect(links[0].className).toBe('hs-slider__button');
    expect(links[0].textContent).toBe('보러 가기');
    expect(links[0].getAttribute('href')).toBe('/shop/1');
  });

  // @scenario surface=link_href_carries_site_mount_prefix
  // @effects banner_link_follows_link_settings
  it('하위 폴더 사이트에서는 사이트 안 링크 href 에 마운트 접두사를 붙이고, 바깥 주소는 그대로 둔다', () => {
    (window as any).G7Config = { basePath: '/g7' };
    try {
      const { container, rerender } = render(<HeroSlider banners={[banner(1)]} />);
      expect((container.querySelector('a.hs-slider__link') as HTMLAnchorElement).getAttribute('href')).toBe('/g7/shop/1');

      rerender(<HeroSlider banners={[banner(1, { button_text: '보러 가기', link_url: 'https://example.com/a', link_new_window: true })]} />);
      expect((container.querySelector('a.hs-slider__button') as HTMLAnchorElement).getAttribute('href')).toBe('https://example.com/a');
    } finally {
      delete (window as any).G7Config;
    }
  });

  // @scenario surface=empty_button_makes_whole_slide_link
  // @effects banner_link_follows_link_settings
  it('버튼 문구가 없으면 슬라이드 전체를 덮는 제목 이름의 링크를 두고, 문구는 링크 밖에 둔다', () => {
    const { container } = render(<HeroSlider banners={[banner(1)]} />);
    const link = container.querySelector('a.hs-slider__link') as HTMLAnchorElement;
    expect(link.getAttribute('href')).toBe('/shop/1');
    expect(link.getAttribute('aria-label')).toBe('제목 1');
    expect(container.querySelector('.hs-slider__button')).toBeNull();
    // 링크 안에 다른 요소를 넣지 않는다 — 넘칠 때 탭으로 들어오는 문구 영역이 링크 안에 겹치지 않게(중첩 상호작용 금지)
    expect(link.childElementCount).toBe(0);
    expect(link.parentElement?.classList.contains('hs-slider__slide')).toBe(true);
    // 탭 순서: 링크가 문구보다 먼저
    const text = container.querySelector('.hs-slider__text') as HTMLElement;
    expect(link.compareDocumentPosition(text) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  // @scenario surface=empty_button_makes_whole_slide_link
  // @effects banner_link_follows_link_settings
  it('전체 링크 배너는 문구를 눌러도 링크로 이동하고, 버튼 배너의 문구는 누르기만으로 이동하지 않는다', () => {
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
    try {
      const whole = render(<HeroSlider banners={[banner(1)]} />);
      fireEvent.click(whole.container.querySelector('.hs-slider__title') as HTMLElement);
      expect(click).toHaveBeenCalledTimes(1);
      expect(click.mock.contexts[0]).toBe(whole.container.querySelector('a.hs-slider__link'));
      cleanup();

      click.mockClear();
      const button = render(<HeroSlider banners={[banner(2, { button_text: '보기' })]} />);
      fireEvent.click(button.container.querySelector('.hs-slider__title') as HTMLElement);
      expect(click).not.toHaveBeenCalled();
    } finally {
      click.mockRestore();
    }
  });

  // @scenario surface=new_window_sets_rel_noopener_noreferrer
  // @effects banner_link_follows_link_settings
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
  // @effects autoplay_follows_interval_and_visitor
  it('자동 재생은 설정 간격(초)을 Swiper autoplay 지연(ms)으로 넘기고, Swiper 가 넘긴 장을 따라간다', () => {
    const { container, unmount } = render(<HeroSlider banners={[banner(1), banner(2), banner(3)]} settings={{ autoplay: true, interval_seconds: 3 }} />);
    expect(swiperState.props?.autoplay).toEqual({ delay: 3000, disableOnInteraction: false });
    expect(swiperState.instance?.autoplay.start).toHaveBeenCalled();
    // Swiper 가 다음 장으로 넘기면(자동 재생·손가락) 번호와 활성 장이 따라온다
    act(() => { swiperState.instance?.slideNext(); });
    expect(activeId(container)).toBe('2');
    expect(container.querySelector('.hs-slider__slide--active')?.getAttribute('aria-label')).toBe('3개 중 2번째');
    // 자동으로 넘어간 장은 알리지 않는다
    expect(container.querySelector('.hs-slider__counter')?.textContent).toBe('');
    unmount();

    render(<HeroSlider banners={[banner(1), banner(2)]} />);
    expect(swiperState.props?.autoplay).toEqual({ delay: 5000, disableOnInteraction: false });
  });

  // @scenario surface=autoplay_pauses_on_hover_and_focus
  // @effects autoplay_follows_interval_and_visitor
  it('마우스를 올리거나 포커스가 들어가면 Swiper 자동 재생을 멈추고, 나가면 다시 켠다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: true, interval_seconds: 3 }} />);
    const region = container.querySelector('section') as HTMLElement;
    const autoplay = swiperState.instance!.autoplay;

    fireEvent.pointerEnter(region, { pointerType: 'mouse' });
    expect(autoplay.running).toBe(false);
    fireEvent.pointerLeave(region, { pointerType: 'mouse' });
    expect(autoplay.running).toBe(true);

    fireEvent.focus(container.querySelector('.hs-slider__next') as HTMLElement);
    expect(autoplay.running).toBe(false);
    fireEvent.blur(container.querySelector('.hs-slider__next') as HTMLElement, { relatedTarget: document.body });
    expect(autoplay.running).toBe(true);
  });

  // @scenario surface=pause_button_toggles_autoplay
  // @scenario surface=autoplay_pauses_on_hover_and_focus
  // @effects autoplay_follows_interval_and_visitor
  it('포커스가 남아 있으면 마우스 이탈로 재생하지 않고, hover가 남으면 blur로 재생하지 않는다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} />);
    const region = container.querySelector('section')!;
    const next = container.querySelector('.hs-slider__next')!;
    const autoplay = swiperState.instance!.autoplay;
    fireEvent.focus(next);
    fireEvent.pointerEnter(region, { pointerType: 'mouse' });
    fireEvent.pointerLeave(region, { pointerType: 'mouse' });
    expect(autoplay.running).toBe(false);
    fireEvent.pointerEnter(region, { pointerType: 'mouse' });
    fireEvent.blur(next, { relatedTarget: document.body });
    expect(autoplay.running).toBe(false);
    fireEvent.pointerLeave(region, { pointerType: 'mouse' });
    expect(autoplay.running).toBe(true);
  });

  // @scenario surface=pointer_click_does_not_hold_autoplay
  // @effects autoplay_follows_interval_and_visitor
  it('[case:components-misc-carousel-autoplay-held-by-pointer-focus] 마우스로 화살표를 누르면(누르며 포커스가 들어가도) 마우스가 나간 뒤 자동 넘김이 다시 돈다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2), banner(3)]} settings={{ autoplay: true, interval_seconds: 3 }} />);
    const region = container.querySelector('section')!;
    const next = container.querySelector('.hs-slider__next')!;
    const autoplay = swiperState.instance!.autoplay;

    fireEvent.pointerEnter(region, { pointerType: 'mouse' });
    fireEvent.mouseEnter(region);
    fireEvent.pointerDown(next, { pointerType: 'mouse' });
    fireEvent.focus(next);
    fireEvent.pointerUp(next, { pointerType: 'mouse' });
    fireEvent.click(next);
    expect(autoplay.running).toBe(false);
    fireEvent.pointerLeave(region, { pointerType: 'mouse' });
    fireEvent.mouseLeave(region);
    expect(autoplay.running).toBe(true);
    expect(container.querySelector('[data-hs-control="toggle"]')?.getAttribute('aria-pressed')).toBe('false');
  });

  // @scenario surface=pointer_click_does_not_hold_autoplay
  // @effects autoplay_follows_interval_and_visitor
  it('[case:components-misc-carousel-autoplay-held-by-pointer-focus] 휴대폰에서 점을 탭해도(뒤따르는 흉내 마우스 이벤트·포커스 포함) 자동 넘김이 계속 돈다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2), banner(3)]} settings={{ autoplay: true, interval_seconds: 3 }} />);
    const region = container.querySelector('section')!;
    const dot = container.querySelectorAll('.hs-slider__bullet')[2] as HTMLElement;
    const autoplay = swiperState.instance!.autoplay;

    // 탭 순서: pointerenter·pointerdown·pointerup·pointerleave(touch) → 흉내 mouseenter·mousedown·focus·click — mouseleave 는 오지 않는다
    fireEvent.pointerEnter(region, { pointerType: 'touch' });
    fireEvent.pointerDown(dot, { pointerType: 'touch' });
    fireEvent.pointerUp(dot, { pointerType: 'touch' });
    fireEvent.pointerLeave(region, { pointerType: 'touch' });
    fireEvent.mouseEnter(region);
    fireEvent.focus(dot);
    fireEvent.click(dot);
    expect(activeId(container)).toBe('3');
    expect(autoplay.running).toBe(true);
  });

  // @scenario surface=pause_button_toggles_autoplay
  // @effects autoplay_follows_interval_and_visitor
  it('일시정지 단추가 자동 재생을 멈추고 다시 켠다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: true, interval_seconds: 3 }} />);
    const toggle = container.querySelector('[data-hs-control="toggle"]') as HTMLButtonElement;
    expect(toggle.getAttribute('aria-pressed')).toBe('false');

    const autoplay = swiperState.instance!.autoplay;

    fireEvent.click(toggle);
    expect(toggle.getAttribute('aria-pressed')).toBe('true');
    // 단추를 누르며 포커스가 들어갔다면 그 멈춤과 별개로 일시정지가 유지되는지 보려고 포커스를 뺀다
    fireEvent.blur(toggle, { relatedTarget: document.body });
    expect(autoplay.running).toBe(false);

    fireEvent.click(toggle);
    fireEvent.blur(toggle, { relatedTarget: document.body });
    expect(autoplay.running).toBe(true);
  });

  // @scenario surface=reduced_motion_disables_autoplay
  // @effects autoplay_follows_interval_and_visitor
  it('움직임 줄이기를 켠 사용자는 자동 재생·효과가 꺼진다', () => {
    mockReducedMotion(true);
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: true, interval_seconds: 3, effect: 'slide' }} />);
    expect(swiperState.props?.autoplay).toBe(false);
    expect(swiperState.props?.speed).toBe(0);
    expect(container.querySelector('section')?.className).toContain('hs-slider--none');
    expect(container.querySelector('[data-hs-control="toggle"]')).toBeNull();
  });

  // @scenario surface=effect_fade_and_none
  // @effects transition_effect_follows_setting
  it('효과는 Swiper effect·speed 로 옮긴다 — 슬라이드 500ms, 페이드(교차) 600ms, 없음은 slide 를 0ms 로', () => {
    const cases = [
      ['slide', 'slide', 500, null],
      ['fade', 'fade', 600, 'fadeEffect'],
      ['cube', 'cube', 800, 'cubeEffect'],
      ['flip', 'flip', 700, 'flipEffect'],
      ['coverflow', 'coverflow', 600, 'coverflowEffect'],
      ['creative', 'creative', 700, 'creativeEffect'],
      ['none', 'slide', 0, null],
    ] as const;
    for (const [setting, effect, speed, option] of cases) {
      const { container, unmount } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: false, effect: setting }} />);
      expect(container.querySelector('section')?.className).toContain(`hs-slider--${setting}`);
      expect(swiperState.props?.effect).toBe(effect);
      expect(swiperState.props?.speed).toBe(speed);
      if (option) expect(swiperState.props?.[option]).toBeTruthy();
      // 그림자는 끈다(배너 밖으로 번지거나 사진을 덮는다)
      for (const key of ['cubeEffect', 'flipEffect', 'coverflowEffect']) {
        if (swiperState.props?.[key]) expect(swiperState.props[key].slideShadows).toBe(false);
      }
      fireEvent.click(container.querySelector('.hs-slider__next') as HTMLElement);
      expect(activeId(container)).toBe('2');
      unmount();
    }
  });

  // @scenario surface=prev_next_buttons_and_keyboard
  // @effects slider_navigation_controls_operable
  it('이전·다음 단추와 좌우 화살표 키가 Swiper 를 넘긴다(무한 반복이라 끝에서 처음으로 돈다)', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2), banner(3)]} settings={{ autoplay: false }} />);
    const region = container.querySelector('section') as HTMLElement;
    const instance = swiperState.instance!;

    fireEvent.click(container.querySelector('.hs-slider__prev') as HTMLElement);
    expect(activeId(container)).toBe('3');
    fireEvent.keyDown(region, { key: 'ArrowRight' });
    expect(activeId(container)).toBe('1');
    fireEvent.keyDown(region, { key: 'ArrowRight' });
    fireEvent.keyDown(region, { key: 'ArrowLeft' });
    expect(activeId(container)).toBe('1');
    fireEvent.click(container.querySelector('.hs-slider__next') as HTMLElement);
    expect(activeId(container)).toBe('2');
    expect(instance.slidePrev).toHaveBeenCalledTimes(2);
    expect(instance.slideNext).toHaveBeenCalledTimes(3);
  });

  // @scenario surface=counter_not_announced_while_autoplaying
  // @effects accessible_carousel_semantics
  it('자동으로 넘어가는 동안은 장 번호를 읽지 않고(aria-live off), 멈추면 읽는다(polite)', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: true, interval_seconds: 3 }} />);
    const counter = container.querySelector('.hs-slider__counter') as HTMLElement;
    expect(counter.getAttribute('aria-live')).toBe('off');

    fireEvent.pointerEnter(container.querySelector('section') as HTMLElement, { pointerType: 'mouse' });
    expect(counter.getAttribute('aria-live')).toBe('polite');
    fireEvent.pointerLeave(container.querySelector('section') as HTMLElement, { pointerType: 'mouse' });
    fireEvent.click(container.querySelector('[data-hs-control="toggle"]') as HTMLElement);
    fireEvent.blur(container.querySelector('[data-hs-control="toggle"]') as HTMLElement, { relatedTarget: document.body });
    expect(counter.getAttribute('aria-live')).toBe('polite');

    cleanup();
    const manual = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: false }} />);
    expect(manual.container.querySelector('.hs-slider__counter')?.getAttribute('aria-live')).toBe('polite');
  });

  // @scenario surface=carousel_aria_roles_and_counter
  // @effects accessible_carousel_semantics
  it('캐러셀·슬라이드 역할과 번호를 사이트 언어로 준다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: false }} />);
    const region = container.querySelector('section') as HTMLElement;
    expect(region.getAttribute('role')).toBe('region');
    expect(region.getAttribute('aria-roledescription')).toBe('캐러셀');
    expect(region.getAttribute('aria-label')).toBeTruthy();

    const slides = container.querySelectorAll('[aria-roledescription="슬라이드"]');
    expect(slides).toHaveLength(2);
    expect(slides[0].getAttribute('aria-label')).toBe('2개 중 1번째');
    // 무한 반복은 슬라이드 DOM 순서를 바꾸므로 논리 순서를 data-index 로 남긴다
    expect(Array.from(slides).map((el) => el.getAttribute('data-index'))).toEqual(['0', '1']);
    expect(slides[1].getAttribute('aria-hidden')).toBe('true');
    // 장 번호는 슬라이드 이름에 있으므로 알림 칸은 처음에 비어 있다(훑어 읽기에서 두 번 읽지 않게)
    expect(container.querySelector('.hs-slider__counter')?.textContent).toBe('');
    expect(container.querySelector('.hs-slider__prev')?.getAttribute('aria-label')).toBeTruthy();
  });

  // @scenario surface=counter_announced_only_on_visitor_change
  // @effects accessible_carousel_semantics
  it('방문자가 넘긴 때만 장 번호를 잠깐 알리고 비운다 — 자동으로 넘어간 장은 알리지 않는다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2), banner(3)]} settings={{ autoplay: false }} />);
    const counter = () => container.querySelector('.hs-slider__counter')?.textContent;
    expect(counter()).toBe('');
    fireEvent.click(container.querySelector('.hs-slider__next') as HTMLElement);
    expect(counter()).toBe('3개 중 2번째');
    act(() => { vi.advanceTimersByTime(3000); });
    expect(counter()).toBe('');
    cleanup();

    const auto = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: true, interval_seconds: 3 }} />);
    act(() => { swiperState.instance?.slideNext(); });
    expect(auto.container.querySelector('.hs-slider__counter')?.textContent).toBe('');
  });

  // @scenario surface=title_read_once
  // @effects accessible_carousel_semantics
  it('화면 읽기 프로그램이 배너 제목을 한 번만 읽는다 — 전체 링크면 링크 이름만, 대체 문구가 제목과 같으면 이미지는 장식', () => {
    // 대체 문구를 비우면 공개 응답이 제목으로 채운다 — 전체 링크: 링크 「제목 1」, 이미지 alt 비움, 제목 줄은 화면 읽기에서 감춤
    const { container } = render(<HeroSlider banners={[banner(1, { image_alt: '제목 1' })]} />);
    expect(container.querySelector('a.hs-slider__link')?.getAttribute('aria-label')).toBe('제목 1');
    expect(container.querySelector('img.hs-slider__image')?.getAttribute('alt')).toBe('');
    expect(container.querySelector('.hs-slider__title')?.getAttribute('aria-hidden')).toBe('true');
    // 제목 글자는 화면에 그대로 보인다
    expect(container.querySelector('.hs-slider__title')?.textContent).toBe('제목 1');
    // 설명은 그대로 읽힌다
    expect(container.querySelector('.hs-slider__description')?.hasAttribute('aria-hidden')).toBe(false);
    cleanup();

    // 버튼 배너: 제목 줄이 읽고, 같은 대체 문구의 이미지는 장식
    const button = render(<HeroSlider banners={[banner(2, { image_alt: '제목 2', button_text: '보기' })]} />);
    expect(button.container.querySelector('img.hs-slider__image')?.getAttribute('alt')).toBe('');
    expect(button.container.querySelector('.hs-slider__title')?.hasAttribute('aria-hidden')).toBe(false);
    cleanup();

    // 운영자가 따로 쓴 대체 문구는 그대로 읽는다
    const own = render(<HeroSlider banners={[banner(3)]} />);
    expect(own.container.querySelector('img.hs-slider__image')?.getAttribute('alt')).toBe('대체 3');
    cleanup();

    // 제목 줄을 감춘 전체 링크 배너: 링크가 제목을 읽고, 같은 대체 문구의 이미지는 장식
    const hidden = render(<HeroSlider banners={[banner(4, { image_alt: '제목 4', title_visible: false })]} />);
    expect(hidden.container.querySelector('a.hs-slider__link')?.getAttribute('aria-label')).toBe('제목 4');
    expect(hidden.container.querySelector('img.hs-slider__image')?.getAttribute('alt')).toBe('');
    expect(hidden.container.querySelector('.hs-slider__title')).toBeNull();
    cleanup();

    // 제목도 버튼도 없는 전체 링크: 링크 이름이 대체 문구라 이미지는 장식
    const untitled = render(<HeroSlider banners={[banner(5, { title: '' })]} />);
    expect(untitled.container.querySelector('a.hs-slider__link')?.getAttribute('aria-label')).toBe('대체 5');
    expect(untitled.container.querySelector('img.hs-slider__image')?.getAttribute('alt')).toBe('');
  });

  // @scenario surface=long_text_scroll_keyboard_and_touch
  // @effects long_text_not_lost
  it('문구가 넘칠 때만 문구 영역을 탭으로 들어가 읽는 이름 붙은 영역으로 만들고, 보이지 않는 장은 탭 순서에서 뺀다', () => {
    let overflow = true;
    const observers: Array<() => void> = [];
    const OriginalObserver = (globalThis as any).ResizeObserver;
    (globalThis as any).ResizeObserver = class {
      constructor(callback: () => void) { observers.push(() => callback()); }
      observe() {}
      disconnect() {}
    };
    // jsdom 은 배치를 하지 않는다 — 문구 영역의 내용 높이만 넘침 여부에 따라 흉내 낸다
    Object.defineProperty(HTMLElement.prototype, 'scrollHeight', { configurable: true, get() { return this.classList.contains('hs-slider__scroll') && overflow ? 500 : 100; } });
    Object.defineProperty(HTMLElement.prototype, 'clientHeight', { configurable: true, get() { return 100; } });
    // 넘침은 줄의 배치 높이로 판정한다(등장 효과의 이동은 빼고) — 줄 높이도 함께 흉내 낸다
    Object.defineProperty(HTMLElement.prototype, 'offsetHeight', { configurable: true, get() { return this.classList.contains('hs-slider__line') && overflow ? 500 : 0; } });

    try {
      const { container } = render(<HeroSlider banners={[banner(1), banner(2, { title: '' })]} settings={{ autoplay: false }} />);
      const scrolls = () => Array.from(container.querySelectorAll<HTMLElement>('.hs-slider__scroll'));

      expect(scrolls().map((el) => [el.getAttribute('tabindex'), el.getAttribute('role'), el.getAttribute('aria-label')])).toEqual([
        // 영역 이름은 제목과 겹치지 않는 「배너 문구」 — 제목은 안의 제목 줄이 읽는다
        ['0', 'region', '배너 문구'],
        ['-1', 'region', '배너 문구'],
      ]);
      // 넘칠 때만 세로 스크롤을 켠다(CSS hs-slider__scroll--overflow)
      expect(scrolls().every((el) => el.classList.contains('hs-slider__scroll--overflow'))).toBe(true);

      // 화면 폭이 넓어져 더는 넘치지 않으면 속성을 모두 거둔다
      overflow = false;
      act(() => observers.forEach((notify) => notify()));
      for (const el of scrolls()) {
        expect(el.hasAttribute('tabindex')).toBe(false);
        expect(el.hasAttribute('role')).toBe(false);
        expect(el.hasAttribute('aria-label')).toBe(false);
        expect(el.classList.contains('hs-slider__scroll--overflow')).toBe(false);
      }
    } finally {
      delete (HTMLElement.prototype as any).scrollHeight;
      delete (HTMLElement.prototype as any).clientHeight;
      delete (HTMLElement.prototype as any).offsetHeight;
      (globalThis as any).ResizeObserver = OriginalObserver;
    }
  });

  // @scenario surface=motion_does_not_make_text_scroll
  // @effects long_text_not_lost
  it('등장 효과가 줄을 아래에서 올리는 동안 밀린 만큼으로는 넘친다고 보지 않는다 — 스크롤 막대·탭 진입 영역이 생기지 않는다', () => {
    // 떠오르기(translateY 20px) 재생 중: 스크롤 높이는 118 이지만 줄의 실제 배치 높이는 100 으로 영역(107) 안이다
    Object.defineProperty(HTMLElement.prototype, 'scrollHeight', { configurable: true, get() { return this.classList.contains('hs-slider__scroll') ? 118 : 0; } });
    Object.defineProperty(HTMLElement.prototype, 'clientHeight', { configurable: true, get() { return this.classList.contains('hs-slider__scroll') ? 107 : 0; } });
    Object.defineProperty(HTMLElement.prototype, 'offsetHeight', { configurable: true, get() { return this.classList.contains('hs-slider__line') ? 100 : 0; } });
    try {
      const { container } = render(<HeroSlider banners={[banner(1, { description_visible: false, presentation: { animation: 'rise' } })]} settings={{ autoplay: false }} />);
      expect(container.querySelector('.hs-slider__textbox--rise')).not.toBeNull();
      const scroll = container.querySelector('.hs-slider__scroll') as HTMLElement;
      expect(scroll.classList.contains('hs-slider__scroll--overflow')).toBe(false);
      expect(scroll.hasAttribute('tabindex')).toBe(false);
      expect(scroll.hasAttribute('role')).toBe(false);
    } finally {
      delete (HTMLElement.prototype as any).scrollHeight;
      delete (HTMLElement.prototype as any).clientHeight;
      delete (HTMLElement.prototype as any).offsetHeight;
    }
  });

  // @scenario surface=preview_static_without_swiper
  // @effects preview_slider_moves_without_swiper
  it('[case:components-misc-swiper-not-mounted-in-iframe-preview] 관리자 미리보기처럼 iframe 문서에 그리면 Swiper 를 부르지 않고(자동 넘김 없음) 화살표·점이 장 번호만 바꿔 그 장을 보인다', () => {
    const frame = document.createElement('iframe');
    document.body.appendChild(frame);
    try {
      const body = frame.contentDocument!.body;
      const { container } = render(<HeroSlider banners={[banner(1), banner(2), banner(3)]} settings={{ autoplay: true, interval_seconds: 3 }} />, { container: body });
      const instance = swiperState.instance!;
      const section = container.querySelector('section') as HTMLElement;
      expect(section.className).toContain('hs-slider--static');
      expect(section.className).not.toContain('hs-slider--autoplaying');
      expect(instance.autoplay.start).not.toHaveBeenCalled();
      expect(section.style.getPropertyValue('--hs-index')).toBe('0');

      fireEvent.click(container.querySelector('.hs-slider__next') as HTMLElement);
      expect(instance.slideNext).not.toHaveBeenCalled();
      expect(activeId(container)).toBe('2');
      expect(section.style.getPropertyValue('--hs-index')).toBe('1');
      fireEvent.click(container.querySelector('.hs-slider__prev') as HTMLElement);
      fireEvent.click(container.querySelector('.hs-slider__prev') as HTMLElement);
      expect(instance.slidePrev).not.toHaveBeenCalled();
      // 첫 장에서 이전이면 마지막 장으로 돈다
      expect(activeId(container)).toBe('3');
      fireEvent.click(container.querySelectorAll('.hs-slider__pagination button')[0]);
      expect(instance.slideToLoop).not.toHaveBeenCalled();
      expect(activeId(container)).toBe('1');
    } finally {
      cleanup();
      frame.remove();
    }
  });

  // @scenario surface=preview_static_without_swiper
  // @effects preview_slider_moves_without_swiper
  it('[case:components-misc-swiper-not-mounted-in-iframe-preview] 요소에 붙지 못한(초기화 안 된) Swiper 인스턴스에는 넘김을 부르지 않는다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: false }} />);
    swiperState.instance!.initialized = false;
    fireEvent.click(container.querySelector('.hs-slider__next') as HTMLElement);
    fireEvent.click(container.querySelectorAll('.hs-slider__pagination button')[1]);
    expect(swiperState.instance!.slideNext).not.toHaveBeenCalled();
    expect(swiperState.instance!.slideToLoop).not.toHaveBeenCalled();
  });

  // @scenario surface=slider_pagination_dots_default
  // @effects pagination_follows_position_setting
  it('페이징 모양이 없거나 모르는 값이면 종전 점이다', () => {
    for (const pagination of [undefined, 'bullets']) {
      const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: false, pagination: pagination as any }} />);
      expect(container.querySelector('section')?.className).toContain('hs-slider--pagination-dots');
      expect(container.querySelectorAll('.hs-slider__pagination--dots > button.hs-slider__bullet')).toHaveLength(2);
      expect(container.querySelector('.hs-slider__bullet-fill, .hs-slider__tabs, .hs-slider__fraction')).toBeNull();
      cleanup();
    }
  });

  // @scenario surface=slider_pagination_progress
  // @effects pagination_follows_position_setting
  it('진행 막대 점은 지금 장 점에만 막대를 넣고, 자동 넘김의 지난 비율을 CSS 변수로 흘리며, 자동 넘김이 멈추면 가득 찬 채로 둔다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2), banner(3)]} settings={{ autoplay: true, interval_seconds: 5, pagination: 'progress' }} />);
    const section = container.querySelector('section') as HTMLElement;
    const bullets = () => Array.from(container.querySelectorAll<HTMLButtonElement>('.hs-slider__pagination--progress > button.hs-slider__bullet'));
    expect(bullets().map((b) => !!b.querySelector('.hs-slider__bullet-fill'))).toEqual([true, false, false]);
    // 이름은 점과 같다
    expect(bullets()[1].getAttribute('aria-label')).toBe('2번째 배너로 이동');
    expect(section.className).toContain('hs-slider--autoplaying');
    // Swiper 가 남은 비율 0.4 를 알리면 지난 비율 0.6
    act(() => { swiperState.props?.onAutoplayTimeLeft?.(swiperState.instance, 2000, 0.4); });
    expect(section.style.getPropertyValue('--hs-progress')).toBe('0.6');
    act(() => { swiperState.instance?.slideNext(); });
    expect(bullets().map((b) => !!b.querySelector('.hs-slider__bullet-fill'))).toEqual([false, true, false]);
    // 마우스를 올리면 자동 넘김이 멈추고 막대는 가득 찬 채(CSS: hs-slider--autoplaying 이 빠짐)
    fireEvent.pointerEnter(section, { pointerType: 'mouse' });
    expect(section.className).not.toContain('hs-slider--autoplaying');
  });

  // @scenario surface=slider_pagination_tabs
  // @effects pagination_follows_position_setting
  it('배너 이름 탭은 장마다 배너 제목을 보이는 이름으로 단 단추이고, 누르면 그 장으로 간다 — 제목이 없으면 대체 문구, 그것도 없으면 「n번째 배너로 이동」', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2, { title: '' }), banner(3, { title: '', image_alt: '' })]} settings={{ autoplay: false, pagination: 'tabs' }} />);
    const tabs = Array.from(container.querySelectorAll<HTMLButtonElement>('.hs-slider__tabs > button.hs-slider__tab'));
    expect(tabs.map((tab) => tab.querySelector('.hs-slider__tab-label')?.textContent)).toEqual(['제목 1', '대체 2', '3번째 배너로 이동']);
    // 보이는 글자가 곧 단추 이름이다(따로 aria-label 을 달지 않는다)
    expect(tabs.every((tab) => !tab.hasAttribute('aria-label') && tab.getAttribute('type') === 'button')).toBe(true);
    expect(tabs.map((tab) => tab.getAttribute('aria-current'))).toEqual(['true', null, null]);
    expect(tabs[0].querySelector('.hs-slider__tab-track .hs-slider__bullet-fill')).not.toBeNull();
    fireEvent.click(tabs[2]);
    expect(swiperState.instance?.slideToLoop).toHaveBeenCalledWith(2);
    expect(tabs.map((tab) => tab.getAttribute('aria-current'))).toEqual([null, null, 'true']);
    expect(tabs[0].querySelector('.hs-slider__bullet-fill')).toBeNull();
    expect(container.querySelector('.hs-slider__bullet')).toBeNull();
  });

  // @scenario surface=slider_pagination_fraction
  // @effects pagination_follows_position_setting
  it('번호는 「01 / 03」을 보이고 장이 바뀌면 따라가며, 장 번호는 슬라이드 이름이 읽으므로 화면 읽기에서는 감춘다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2), banner(3)]} settings={{ autoplay: false, pagination: 'fraction' }} />);
    const fraction = container.querySelector('.hs-slider__fraction') as HTMLElement;
    expect(fraction.getAttribute('aria-hidden')).toBe('true');
    expect([fraction.querySelector('.hs-slider__fraction-current')?.textContent, fraction.querySelector('[data-hs-fraction="total"]')?.textContent]).toEqual(['01', '03']);
    fireEvent.click(container.querySelector('.hs-slider__next') as HTMLElement);
    expect(fraction.querySelector('.hs-slider__fraction-current')?.textContent).toBe('02');
    expect(container.querySelector('.hs-slider__bullet, .hs-slider__tab')).toBeNull();
  });

  // @scenario surface=slider_pagination_tabs
  it('배너 이름 탭은 화면 전체 폭이면 일시정지 단추와 같은 본문 상자 안에 두고, 본문 폭이면 종전처럼 구획에 바로 둔다', () => {
    const full = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: true, width: 'full', pagination: 'tabs' }} contentClass="max-w-7xl mx-auto px-8" />);
    const tabs = full.container.querySelector('.hs-slider__tabs-layer > .hs-slider__tabs-box > .hs-slider__tabs.hs-slider__tabs--boxed') as HTMLElement;
    expect(tabs).not.toBeNull();
    expect(tabs.parentElement?.className).toContain('max-w-7xl mx-auto px-8');
    // 일시정지 단추도 같은 상자 클래스 안
    expect(full.container.querySelector('.hs-slider__controls-box.max-w-7xl [data-hs-control="toggle"]')).not.toBeNull();
    expect(tabs.querySelectorAll('button.hs-slider__tab')).toHaveLength(2);
    full.unmount();
    const content = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: true, width: 'content', pagination: 'tabs' }} contentClass="max-w-7xl mx-auto px-8" />);
    expect(content.container.querySelector('.hs-slider__tabs-layer')).toBeNull();
    expect(content.container.querySelector('section.hs-slider > .hs-slider__tabs:not(.hs-slider__tabs--boxed)')).not.toBeNull();
  });

  // @scenario surface=slider_pagination_pill
  it('알약 번호는 문구 아래 왼쪽 둥근 상자에 「‹ 1 / 3 ›」을 두고, 양옆 화살표는 그리지 않으며, 상자 안 단추로 넘긴다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2), banner(3)]} settings={{ autoplay: false, pagination: 'pill' }} contentClass="max-w-7xl mx-auto px-8" />);
    expect(container.querySelector('section')?.className).toContain('hs-slider--pagination-pill');
    const pill = container.querySelector('.hs-slider__pill-layer .hs-slider__pill') as HTMLElement;
    expect(pill).not.toBeNull();
    // 본문 폭이면 문구와 같은 안쪽 여백에서 시작한다
    expect(container.querySelector('.hs-slider__pill-box')?.className).toContain('hs-slider__pill-box--inset');
    const count = pill.querySelector('.hs-slider__pill-count') as HTMLElement;
    expect(count.getAttribute('aria-hidden')).toBe('true');
    expect([count.querySelector('.hs-slider__pill-current')?.textContent, count.querySelector('[data-hs-fraction="total"]')?.textContent]).toEqual(['1', '3']);
    // 양옆 화살표 없이 상자 안의 이름 붙은 두 단추만
    expect(container.querySelectorAll('.hs-slider__arrow')).toHaveLength(0);
    const buttons = Array.from(pill.querySelectorAll<HTMLButtonElement>('button.hs-slider__pill-button'));
    expect(buttons.map((b) => [b.type, b.getAttribute('aria-label')])).toEqual([['button', '이전 배너'], ['button', '다음 배너']]);
    fireEvent.click(buttons[1]);
    expect(count.querySelector('.hs-slider__pill-current')?.textContent).toBe('2');
    fireEvent.click(buttons[0]);
    fireEvent.click(buttons[0]);
    expect(count.querySelector('.hs-slider__pill-current')?.textContent).toBe('3');
    expect(container.querySelector('.hs-slider__bullet, .hs-slider__tab, .hs-slider__fraction')).toBeNull();
  });

  // @scenario surface=slider_pagination_pill
  it('알약 번호는 화면 전체 폭이면 본문 상자 왼쪽 끝에 맞추고, 배너가 1장이면 그리지 않는다', () => {
    const full = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: false, width: 'full', pagination: 'pill' }} contentClass="max-w-7xl mx-auto px-8" />);
    const box = full.container.querySelector('.hs-slider__pill-box') as HTMLElement;
    expect(box.className).toContain('max-w-7xl mx-auto px-8');
    expect(box.className).not.toContain('hs-slider__pill-box--inset');
    full.unmount();
    const single = render(<HeroSlider banners={[banner(1)]} settings={{ autoplay: false, pagination: 'pill' }} />);
    expect(single.container.querySelector('.hs-slider__pill')).toBeNull();
  });

  // @scenario surface=index_resets_when_banners_change
  // @effects slider_controls_follow_banner_count
  it('배너 목록이 바뀌면 첫 장으로 돌아간다', () => {
    const { container, rerender } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: false }} />);
    fireEvent.click(container.querySelector('.hs-slider__next') as HTMLElement);
    expect(activeId(container)).toBe('2');

    rerender(<HeroSlider banners={[banner(5), banner(6), banner(7)]} settings={{ autoplay: false }} />);
    expect(activeId(container)).toBe('5');
  });

  // @scenario surface=first_image_eager_rest_lazy
  // @effects slide_images_load_by_priority
  it('첫 장 이미지는 즉시·높은 우선순위, 나머지는 지연 로딩', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2), banner(3)]} settings={{ autoplay: false }} />);
    const imgs = container.querySelectorAll('img');
    expect(imgs[0].getAttribute('loading')).toBe('eager');
    expect(imgs[0].getAttribute('fetchpriority')).toBe('high');
    expect(imgs[2].getAttribute('loading')).toBe('lazy');
    expect(imgs[2].getAttribute('fetchpriority')).toBeNull();
  });

  // @scenario surface=next_slide_preloaded_before_transition
  // @effects slide_images_load_by_priority
  it('다음으로 넘어갈 장은 전환 전에 미리 받는다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2), banner(3)]} settings={{ autoplay: false }} />);
    expect(container.querySelectorAll('img')[1].getAttribute('loading')).toBe('eager');

    fireEvent.click(container.querySelector('.hs-slider__next') as HTMLElement);
    expect(container.querySelectorAll('img')[2].getAttribute('loading')).toBe('eager');
  });

  // @scenario surface=images_have_dimensions_and_aspect_ratio
  // @effects responsive_image_switch
  it('이미지에 크기를, 영역에 PC·모바일 비율을 준다', () => {
    const { container } = render(<HeroSlider banners={[banner(1)]} />);
    const img = container.querySelector('img') as HTMLImageElement;
    expect(img.getAttribute('width')).toBe('1920');
    expect(img.getAttribute('height')).toBe('640');
    const style = (container.querySelector('section') as HTMLElement).style;
    expect(style.getPropertyValue('--hs-ratio-pc')).toBe('1920 / 640');
    expect(style.getPropertyValue('--hs-ratio-mobile')).toBe('750 / 1000');

    // 휴대폰 화면은 모바일 이미지의 크기를 싣는다
    (window as any).G7Core.useResponsive = () => ({ width: 375 });
    const phone = render(<HeroSlider banners={[banner(1)]} />);
    const mobileImg = phone.container.querySelector('img') as HTMLImageElement;
    expect(mobileImg.getAttribute('width')).toBe('750');
    expect(mobileImg.getAttribute('height')).toBe('1000');
  });

  // @scenario surface=aspect_ratio_falls_back_to_recommended
  // @effects responsive_image_switch
  it('첫 배너의 이미지 크기·모바일 유무와 무관하게 PC·모바일 표시 비율을 유지한다', () => {
    const { container, rerender } = render(<HeroSlider banners={[banner(1, { images: { pc: { url: '/p.jpg' }, mobile: null } })]} />);
    let style = (container.querySelector('section') as HTMLElement).style;
    expect(style.getPropertyValue('--hs-ratio-pc')).toBe('1920 / 640');
    expect(style.getPropertyValue('--hs-ratio-mobile')).toBe('750 / 1000');

    rerender(<HeroSlider banners={[banner(1, { images: { pc: { url: '/p.jpg', width: 1600, height: 600 }, mobile: { url: '/m.jpg' } } })]} />);
    style = (container.querySelector('section') as HTMLElement).style;
    expect(style.getPropertyValue('--hs-ratio-pc')).toBe('1920 / 640');
    expect(style.getPropertyValue('--hs-ratio-mobile')).toBe('750 / 1000');
  });

  // @scenario surface=slider_content_box_wrap
  // @effects site_slider_matches_width_setting
  it('본문 폭(기본)이면 확장점이 넘긴 본문 상자로 슬라이더를 감싸고, 상자가 없으면 감싸지 않는다', () => {
    const { container, rerender } = render(<HeroSlider banners={[banner(1), banner(2)]} contentClass="max-w-7xl mx-auto px-8" />);
    const frame = container.querySelector('[data-hs-width="content"]') as HTMLElement;
    expect(frame.className).toBe('max-w-7xl mx-auto px-8');
    expect(frame.querySelector(':scope > section.hs-slider:not(.hs-slider--full)')).not.toBeNull();
    // 문구·넘김 단추는 상자로 다시 감싸지 않는다(이미 상자 안)
    expect(container.querySelector('.hs-slider__box')).toBeNull();
    expect(container.querySelector('.hs-slider__controls-layer')).toBeNull();

    rerender(<HeroSlider banners={[banner(1)]} />);
    expect(container.querySelector('[data-hs-width]')).toBeNull();
    expect(container.firstElementChild?.tagName).toBe('SECTION');
  });

  // @scenario surface=slider_full_text_box
  // @effects site_slider_matches_width_setting
  it('화면 전체 폭이면 끝까지 그리고 제목·넘김 단추만 본문 상자에 맞춘다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ width: 'full' }} contentClass="max-w-7xl mx-auto px-8" />);
    const section = container.firstElementChild as HTMLElement;
    expect(section.tagName).toBe('SECTION');
    expect(section.className).toContain('hs-slider--full');
    expect(container.querySelector('[data-hs-width]')).toBeNull();
    const boxes = [...container.querySelectorAll('.hs-slider__text.hs-slider__text--boxed > .hs-slider__box')];
    expect(boxes).toHaveLength(2);
    expect(boxes[0].className).toBe('hs-slider__box max-w-7xl mx-auto px-8');
    expect(boxes[0].querySelector(':scope > .hs-slider__textbox > .hs-slider__scroll .hs-slider__title')?.textContent).toBe('제목 1');
    const controlsBox = container.querySelector('.hs-slider__controls-layer > .hs-slider__controls-box') as HTMLElement;
    expect(controlsBox.className).toBe('hs-slider__controls-box max-w-7xl mx-auto px-8');
    expect(controlsBox.querySelector(':scope > .hs-slider__controls.hs-slider__controls--boxed [data-hs-control="toggle"]')).not.toBeNull();
    // 좌우 화살표·점 표시는 배너 기준(Swiper 기본 자리)이라 상자로 감싸지 않는다
    expect(section.querySelector(':scope > .hs-slider__prev')).not.toBeNull();
    expect(section.querySelector(':scope > .hs-slider__pagination')).not.toBeNull();
  });

  // @scenario surface=slider_full_text_box
  // @effects site_slider_matches_width_setting
  it('화면 전체 폭인데 본문 상자를 받지 못하면(자리가 본문 상자 안인 템플릿) 문구를 감싸지 않고 종전 여백을 쓴다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ width: 'full' }} />);
    expect(container.querySelector('section')?.className).toContain('hs-slider--full');
    expect(container.querySelector('.hs-slider__box')).toBeNull();
    expect(container.querySelector('.hs-slider__text--boxed')).toBeNull();
    expect(container.querySelector('.hs-slider__controls-layer')).toBeNull();
    expect(container.querySelector('section > .hs-slider__controls')).not.toBeNull();
  });

  // @scenario surface=swiper_pagination_and_arrows
  // @effects slider_navigation_controls_operable
  it('2장 이상이면 Swiper 기본 모양의 좌우 화살표와 점 표시를 이름 붙은 단추로 그리고, 점을 누르면 그 장으로 간다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2), banner(3)]} settings={{ autoplay: false }} />);
    const prev = container.querySelector('button.hs-slider__arrow.hs-slider__prev') as HTMLButtonElement;
    const next = container.querySelector('button.hs-slider__arrow.hs-slider__next') as HTMLButtonElement;
    expect(prev.getAttribute('aria-label')).toBe('이전 배너');
    expect(container.querySelectorAll('.hs-slider__pagination button')[1].getAttribute('aria-label')).toBe('2번째 배너로 이동');
    expect(next.querySelector('svg.hs-slider__arrow-icon')).not.toBeNull();
    const bullets = [...container.querySelectorAll<HTMLButtonElement>('.hs-slider__pagination.hs-slider__bullets > button.hs-slider__bullet')];
    expect(bullets).toHaveLength(3);
    expect(bullets.map((b) => b.getAttribute('aria-current'))).toEqual(['true', null, null]);
    expect(bullets[0].className).toContain('hs-slider__bullet--active');
    fireEvent.click(bullets[2]);
    expect(swiperState.instance?.slideToLoop).toHaveBeenCalledWith(2);
    expect(activeId(container)).toBe('3');
    expect(bullets[2].getAttribute('aria-current')).toBe('true');
    // 방문자가 넘긴 장은 화면 읽기 프로그램에 알린다
    expect(container.querySelector('.hs-slider__counter')?.textContent).toBe('3개 중 3번째');
  });

  // @scenario surface=swiper_pagination_and_arrows
  // @effects slider_controls_follow_banner_count
  it('1장이면 화살표·점 표시가 없다', () => {
    const { container } = render(<HeroSlider banners={[banner(1)]} />);
    expect(container.querySelector('.hs-slider__prev, .hs-slider__next, .hs-slider__pagination')).toBeNull();
  });

  // @scenario surface=swiper_extra_effects
  // @effects transition_effect_follows_setting
  it('모르는 효과 값은 slide 로 그린다', () => {
    render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: false, effect: 'zoom' as any }} />);
    expect(swiperState.props?.effect).toBe('slide');
    expect(swiperState.props?.speed).toBe(500);
  });

  describe('받는 중 스켈레톤', () => {
    beforeEach(() => window.localStorage.clear());

    // @scenario surface=loading_skeleton_until_response
    // @effects loading_skeleton_reserves_slot
    it('받는 중이고 배너가 아직 없으면 같은 비율의 스켈레톤을 그리고(읽지 않음), 받은 뒤 0건이면 아무것도 그리지 않는다', () => {
      swiperState.props = null;
      const { container, rerender } = render(<HeroSlider banners={[]} loading position="home_hero" contentClass="max-w-7xl mx-auto px-8" />);
      const skeleton = container.querySelector('section.hs-slider.hs-slider--skeleton') as HTMLElement;
      expect(skeleton.getAttribute('aria-hidden')).toBe('true');
      expect(skeleton.style.getPropertyValue('--hs-ratio-pc')).toBe('1920 / 640');
      expect(skeleton.className).not.toContain('hs-slider--full');
      // 본문 폭이면 실제 슬라이더처럼 본문 상자 안
      expect(container.querySelector('[data-hs-width="content"] > section.hs-slider--skeleton')).not.toBeNull();
      expect(container.querySelectorAll('.hs-slider__skeleton-line')).toHaveLength(3);
      expect(swiperState.props).toBeNull();

      rerender(<HeroSlider banners={[]} loading={false} position="home_hero" contentClass="max-w-7xl mx-auto px-8" />);
      expect(container.innerHTML).toBe('');
    });

    // @scenario surface=loading_skeleton_until_response
    // @effects loading_skeleton_reserves_slot
    it('받은 배너가 오면 스켈레톤을 실제 슬라이더로 바꾼다', () => {
      const { container, rerender } = render(<HeroSlider banners={[]} loading position="home_hero" />);
      expect(container.querySelector('.hs-slider--skeleton')).not.toBeNull();
      rerender(<HeroSlider banners={[banner(1)]} loading={false} position="home_hero" />);
      expect(container.querySelector('.hs-slider--skeleton')).toBeNull();
      expect(container.querySelector('section.hs-slider [data-banner-id="1"]')).not.toBeNull();
    });

    // @scenario surface=loading_skeleton_remembers_slot
    // @effects loading_skeleton_reserves_slot
    it('직전 방문의 모양을 기억해, 0건이던 위치는 스켈레톤을 그리지 않고 화면 전체였던 위치는 끝까지 그린다', () => {
      const first = render(<HeroSlider banners={[]} loading={false} settings={{ width: 'content' }} position="shop_hero" />);
      first.unmount();
      expect(JSON.parse(window.localStorage.getItem('sirsoft-hero_section:slot:shop_hero')!)).toEqual({ banners: 0, width: 'content', overlay: false, tone: 'dark' });
      const again = render(<HeroSlider banners={[]} loading position="shop_hero" />);
      expect(again.container.innerHTML).toBe('');
      again.unmount();

      const full = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ width: 'full' }} position="home_hero" contentClass="px-8" />);
      full.unmount();
      expect(JSON.parse(window.localStorage.getItem('sirsoft-hero_section:slot:home_hero')!)).toEqual({ banners: 2, width: 'full', overlay: false, tone: 'dark' });
      const next = render(<HeroSlider banners={[]} loading position="home_hero" contentClass="px-8" />);
      const skeleton = next.container.querySelector('section.hs-slider--skeleton') as HTMLElement;
      expect(skeleton.className).toContain('hs-slider--full');
      expect(next.container.querySelector('[data-hs-width]')).toBeNull();
      // 화면 전체면 문구 자리만 본문 상자에 맞춘다
      expect(skeleton.querySelector('.hs-slider__text--boxed > .hs-slider__box.px-8 .hs-slider__skeleton-lines')).not.toBeNull();
    });

    // @scenario case=dynamic_skeleton_needs_memory
    it('동적 위치(대상 있음)는 기억을 위치 + 대상마다 따로 두고, 첫 방문에는 스켈레톤을 그리지 않으며 그 대상의 기억이 「배너 있음」일 때만 그린다(D8)', () => {
      // 첫 방문 — 기억이 없으면 받는 동안에도 자리를 잡지 않는다(배너 없는 게시판마다 빈 자리가 깜빡이지 않게)
      const first = render(<HeroSlider banners={[]} loading position="board_hero" target="free" />);
      expect(first.container.innerHTML).toBe('');
      first.rerender(<HeroSlider banners={[banner(1)]} loading={false} settings={{ width: 'content' }} position="board_hero" target="free" />);
      first.unmount();
      expect(JSON.parse(window.localStorage.getItem('sirsoft-hero_section:slot:board_hero:free')!)).toEqual({ banners: 1, width: 'content', overlay: false, tone: 'dark' });
      expect(window.localStorage.getItem('sirsoft-hero_section:slot:board_hero')).toBeNull();

      // 같은 게시판을 다시 찾으면 스켈레톤, 다른 게시판(기억 없음)은 그리지 않는다
      const again = render(<HeroSlider banners={[]} loading position="board_hero" target="free" />);
      expect(again.container.querySelector('section.hs-slider--skeleton')).not.toBeNull();
      again.unmount();
      const other = render(<HeroSlider banners={[]} loading position="board_hero" target="event" />);
      expect(other.container.innerHTML).toBe('');
      other.unmount();

      // 0건으로 기억된 대상도 그리지 않는다. 대상 값이 비었으면 기억하지 않는다
      window.localStorage.setItem('sirsoft-hero_section:slot:board_hero:gallery', JSON.stringify({ banners: 0, width: 'content' }));
      expect(render(<HeroSlider banners={[]} loading position="board_hero" target="gallery" />).container.innerHTML).toBe('');
      render(<HeroSlider banners={[banner(2)]} loading={false} settings={{ width: 'content' }} position="board_hero" target="" />).unmount();
      expect(Object.keys(window.localStorage).filter((k) => k.startsWith('sirsoft-hero_section:slot:board_hero'))).toEqual(
        expect.arrayContaining(['sirsoft-hero_section:slot:board_hero:free', 'sirsoft-hero_section:slot:board_hero:gallery']),
      );
      expect(window.localStorage.getItem('sirsoft-hero_section:slot:board_hero:')).toBeNull();
    });

    // @scenario surface=loading_skeleton_remembers_slot
    // @effects loading_skeleton_reserves_slot
    it('받기에 실패하면(설정 없이 끝남) 기억을 남기지 않아, 다음 방문에도 스켈레톤이 자리를 잡는다', () => {
      window.localStorage.setItem('sirsoft-hero_section:slot:home_hero', JSON.stringify({ banners: 3, width: 'full' }));
      const failed = render(<HeroSlider banners={[]} loading={false} position="home_hero" />);
      expect(failed.container.innerHTML).toBe('');
      failed.unmount();
      expect(JSON.parse(window.localStorage.getItem('sirsoft-hero_section:slot:home_hero')!)).toEqual({ banners: 3, width: 'full' });
      const next = render(<HeroSlider banners={[]} loading position="home_hero" />);
      expect(next.container.querySelector('section.hs-slider--skeleton.hs-slider--full')).not.toBeNull();
    });

    // @scenario surface=loading_skeleton_remembers_slot
    // @effects loading_skeleton_reserves_slot
    it('첫 로드 설정의 표시 폭 힌트가 브라우저 기억보다 먼저다(저장소를 지우는 개인정보 설정에서도 폭이 맞는다)', () => {
      window.localStorage.setItem('sirsoft-hero_section:slot:home_hero', JSON.stringify({ banners: 2, width: 'content' }));
      const { container, rerender } = render(<HeroSlider banners={[]} loading position="home_hero" skeletonWidth="full" contentClass="px-8" />);
      expect(container.querySelector('section.hs-slider--skeleton')?.className).toContain('hs-slider--full');
      window.localStorage.clear();
      rerender(<HeroSlider banners={[]} loading position="shop_hero" skeletonWidth="content" contentClass="px-8" />);
      expect(container.querySelector('[data-hs-width="content"] > section.hs-slider--skeleton')).not.toBeNull();
    });

    // @scenario surface=loading_skeleton_skipped_without_banners
    it('[case:components-misc-hero-skeleton-first-visit-without-banners] 첫 접속이어도 첫 로드 힌트가 「배너 없음」이면 스켈레톤을 그리지 않고, 그래도 배너가 오면 그린다', () => {
      const { container, rerender } = render(<HeroSlider banners={[]} loading position="home_hero" skeletonBanners={false} contentClass="px-8" />);
      expect(container.innerHTML).toBe('');
      rerender(<HeroSlider banners={[banner(1)]} loading={false} position="home_hero" skeletonBanners={false} contentClass="px-8" />);
      expect(container.querySelector('section.hs-slider[role="region"]')).not.toBeNull();
    });

    // @scenario surface=loading_skeleton_skipped_without_banners
    it('[case:components-misc-hero-skeleton-first-visit-without-banners] 힌트와 브라우저 기억 중 하나라도 「없음」이면 자리를 잡지 않는다 — 기간이 끝나 힌트가 낡아도 재방문자의 본문이 매번 밀리지 않는다', () => {
      window.localStorage.setItem('sirsoft-hero_section:slot:home_hero', JSON.stringify({ banners: 0, width: 'content' }));
      const expired = render(<HeroSlider banners={[]} loading position="home_hero" skeletonBanners />);
      expect(expired.container.innerHTML).toBe('');
      expired.unmount();
      window.localStorage.setItem('sirsoft-hero_section:slot:home_hero', JSON.stringify({ banners: 3, width: 'content' }));
      const removed = render(<HeroSlider banners={[]} loading position="home_hero" skeletonBanners={false} />);
      expect(removed.container.innerHTML).toBe('');
      removed.unmount();
      const present = render(<HeroSlider banners={[]} loading position="home_hero" skeletonBanners />);
      expect(present.container.querySelector('section.hs-slider--skeleton')).not.toBeNull();
    });

    // @scenario surface=loading_skeleton_skipped_without_banners
    it('힌트가 없으면(옛 설정) 종전처럼 브라우저 기억을 따른다', () => {
      const first = render(<HeroSlider banners={[]} loading position="home_hero" />);
      expect(first.container.querySelector('section.hs-slider--skeleton')).not.toBeNull();
      first.unmount();
      window.localStorage.setItem('sirsoft-hero_section:slot:home_hero', JSON.stringify({ banners: 0, width: 'content' }));
      expect(render(<HeroSlider banners={[]} loading position="home_hero" />).container.innerHTML).toBe('');
    });

    // @scenario surface=loading_skeleton_skipped_without_banners
    it('배너가 없다는 힌트면 받는 동안 사이트 메뉴도 겹치지 않는다(메뉴가 투명해졌다 돌아오지 않게)', () => {
      render(<HeroSlider banners={[]} loading position="home_hero" skeletonBanners={false} skeletonWidth="full" skeletonOverlay />);
      expect(document.documentElement.getAttribute('data-header-overlay')).toBeNull();
    });

    // @scenario surface=slider_max_height_vars
    it('위치 설정의 기기별 최대 높이를 px 변수로 달고, 비운 기기는 변수를 두지 않는다(CSS 기본 — 비율대로)', () => {
      const { container, rerender } = render(<HeroSlider banners={[banner(1)]} settings={{ width: 'content', max_height_pc: 480, max_height_mobile: null }} position="home_hero" />);
      const slider = container.querySelector('section.hs-slider') as HTMLElement;
      expect(slider.style.getPropertyValue('--hs-max-height-pc')).toBe('480px');
      expect(slider.style.getPropertyValue('--hs-max-height-mobile')).toBe('');
      expect(JSON.parse(window.localStorage.getItem('sirsoft-hero_section:slot:home_hero')!)).toEqual({ banners: 1, width: 'content', overlay: false, tone: 'dark', maxPc: 480 });

      // 0 이하·숫자가 아닌 값은 비운 것으로 본다
      rerender(<HeroSlider banners={[banner(1)]} settings={{ max_height_pc: 0, max_height_mobile: 'tall' as unknown as number }} position="home_hero" />);
      const cleared = container.querySelector('section.hs-slider') as HTMLElement;
      expect(cleared.style.getPropertyValue('--hs-max-height-pc')).toBe('');
      expect(cleared.style.getPropertyValue('--hs-max-height-mobile')).toBe('');
    });

    // @scenario surface=slider_max_height_vars
    it('미리보기의 기기 고정은 비율처럼 최대 높이도 그 기기 값으로 맞바꾼다', () => {
      const settings = { max_height_pc: 400, max_height_mobile: 520 };
      const mobile = render(<HeroSlider banners={[banner(1)]} settings={settings} forceDevice="mobile" />);
      const mobileSlider = mobile.container.querySelector('section.hs-slider') as HTMLElement;
      expect(mobileSlider.style.getPropertyValue('--hs-max-height-pc')).toBe('520px');
      expect(mobileSlider.style.getPropertyValue('--hs-max-height-mobile')).toBe('520px');
      mobile.unmount();
      const pc = render(<HeroSlider banners={[banner(1)]} settings={settings} forceDevice="pc" />);
      const pcSlider = pc.container.querySelector('section.hs-slider') as HTMLElement;
      expect(pcSlider.style.getPropertyValue('--hs-max-height-pc')).toBe('400px');
      expect(pcSlider.style.getPropertyValue('--hs-max-height-mobile')).toBe('400px');
    });

    // @scenario surface=skeleton_max_height_from_hint
    it('받는 동안 스켈레톤은 첫 로드 최대 높이 힌트를 브라우저 기억보다 먼저 쓰고, 힌트가 없으면 직전 방문의 높이를 쓴다', () => {
      window.localStorage.setItem('sirsoft-hero_section:slot:home_hero', JSON.stringify({ banners: 2, width: 'content', maxPc: 300, maxMobile: 360 }));
      const hinted = render(<HeroSlider banners={[]} loading position="home_hero" skeletonMaxHeight={{ pc: 480, mobile: null }} />);
      const hintedSkeleton = hinted.container.querySelector('section.hs-slider--skeleton') as HTMLElement;
      expect(hintedSkeleton.style.getPropertyValue('--hs-max-height-pc')).toBe('480px');
      // 힌트가 그 기기를 비웠으면 기억이 아니라 비율대로다(운영자가 지운 값을 옛 기억이 되살리지 않게)
      expect(hintedSkeleton.style.getPropertyValue('--hs-max-height-mobile')).toBe('');
      hinted.unmount();

      const remembered = render(<HeroSlider banners={[]} loading position="home_hero" />);
      const rememberedSkeleton = remembered.container.querySelector('section.hs-slider--skeleton') as HTMLElement;
      expect(rememberedSkeleton.style.getPropertyValue('--hs-max-height-pc')).toBe('300px');
      expect(rememberedSkeleton.style.getPropertyValue('--hs-max-height-mobile')).toBe('360px');

      // 배너가 오면 위치 설정을 따른다
      remembered.rerender(<HeroSlider banners={[banner(1)]} loading={false} settings={{ max_height_pc: null, max_height_mobile: 520 }} position="home_hero" />);
      const slider = remembered.container.querySelector('section.hs-slider') as HTMLElement;
      expect(slider.style.getPropertyValue('--hs-max-height-pc')).toBe('');
      expect(slider.style.getPropertyValue('--hs-max-height-mobile')).toBe('520px');
    });

    // @scenario surface=loading_skeleton_remembers_slot
    // @effects loading_skeleton_reserves_slot
    it('저장소를 쓸 수 없어도(개인정보 동의 전 차단 등) 기본 스켈레톤으로 동작한다', () => {
      const getItem = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('blocked'); });
      const setItem = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('blocked'); });
      const { container } = render(<HeroSlider banners={[]} loading position="home_hero" />);
      expect(container.querySelector('section.hs-slider--skeleton:not(.hs-slider--full)')).not.toBeNull();
      getItem.mockRestore();
      setItem.mockRestore();
    });

    // @scenario surface=loading_skeleton_until_response
    // @effects loading_skeleton_reserves_slot
    it('위치를 모르는 미리보기는 기억을 쓰지 않는다', () => {
      render(<HeroSlider banners={[banner(1)]} />);
      expect(window.localStorage.length).toBe(0);
    });
  });
});
