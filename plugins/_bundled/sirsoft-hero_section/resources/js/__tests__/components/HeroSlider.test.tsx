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
vi.mock('swiper/css', () => ({}));
vi.mock('swiper/css/effect-fade', () => ({}));
vi.mock('swiper/css/navigation', () => ({}));
vi.mock('swiper/css/pagination', () => ({}));
vi.mock('swiper/css/effect-cube', () => ({}));
vi.mock('swiper/css/effect-flip', () => ({}));
vi.mock('swiper/css/effect-coverflow', () => ({}));
vi.mock('swiper/css/effect-creative', () => ({}));

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
  it('배너 1장이면 컨트롤·자동 재생·넘기기·무한 반복이 없다', () => {
    const { container } = render(<HeroSlider banners={[banner(1)]} settings={{ autoplay: true, interval_seconds: 3 }} />);
    expect(container.querySelector('.hs__controls')).toBeNull();
    expect(swiperState.props?.autoplay).toBe(false);
    expect(swiperState.props?.allowTouchMove).toBe(false);
    expect(swiperState.props?.loop).toBe(false);
    expect(activeId(container)).toBe('1');
  });

  // @scenario surface=loop_and_touch_swipe_enabled
  it('2장 이상이면 Swiper 무한 반복·손가락 넘기기·PC 마우스 끌기(잡기 커서)를 켜고, 사진은 끌려 나오지 않는다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: false }} />);
    expect(swiperState.props?.loop).toBe(true);
    expect(swiperState.props?.allowTouchMove).toBe(true);
    expect(swiperState.props?.simulateTouch).toBe(true);
    expect(swiperState.props?.grabCursor).toBe(true);
    expect(container.querySelector('img')?.getAttribute('draggable')).toBe('false');
    expect(swiperState.props?.className).toBe('hs__track');
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
  it('버튼 문구가 없으면 슬라이드 전체를 덮는 제목 이름의 링크를 두고, 문구는 링크 밖에 둔다', () => {
    const { container } = render(<HeroSlider banners={[banner(1)]} />);
    const link = container.querySelector('a.hs__link') as HTMLAnchorElement;
    expect(link.getAttribute('href')).toBe('/shop/1');
    expect(link.getAttribute('aria-label')).toBe('제목 1');
    expect(container.querySelector('.hs__button')).toBeNull();
    // 링크 안에 다른 요소를 넣지 않는다 — 넘칠 때 탭으로 들어오는 문구 영역이 링크 안에 겹치지 않게(중첩 상호작용 금지)
    expect(link.childElementCount).toBe(0);
    expect(link.parentElement?.classList.contains('hs__slide')).toBe(true);
    // 탭 순서: 링크가 문구보다 먼저
    const text = container.querySelector('.hs__text') as HTMLElement;
    expect(link.compareDocumentPosition(text) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  // @scenario surface=empty_button_makes_whole_slide_link
  it('전체 링크 배너는 문구를 눌러도 링크로 이동하고, 버튼 배너의 문구는 누르기만으로 이동하지 않는다', () => {
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
    try {
      const whole = render(<HeroSlider banners={[banner(1)]} />);
      fireEvent.click(whole.container.querySelector('.hs__title') as HTMLElement);
      expect(click).toHaveBeenCalledTimes(1);
      expect(click.mock.contexts[0]).toBe(whole.container.querySelector('a.hs__link'));
      cleanup();

      click.mockClear();
      const button = render(<HeroSlider banners={[banner(2, { button_text: '보기' })]} />);
      fireEvent.click(button.container.querySelector('.hs__title') as HTMLElement);
      expect(click).not.toHaveBeenCalled();
    } finally {
      click.mockRestore();
    }
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
  it('자동 재생은 설정 간격(초)을 Swiper autoplay 지연(ms)으로 넘기고, Swiper 가 넘긴 장을 따라간다', () => {
    const { container, unmount } = render(<HeroSlider banners={[banner(1), banner(2), banner(3)]} settings={{ autoplay: true, interval_seconds: 3 }} />);
    expect(swiperState.props?.autoplay).toEqual({ delay: 3000, disableOnInteraction: false });
    expect(swiperState.instance?.autoplay.start).toHaveBeenCalled();
    // Swiper 가 다음 장으로 넘기면(자동 재생·손가락) 번호와 활성 장이 따라온다
    act(() => { swiperState.instance?.slideNext(); });
    expect(activeId(container)).toBe('2');
    expect(container.querySelector('.hs__counter')?.textContent).toBe('2 / 3');
    unmount();

    render(<HeroSlider banners={[banner(1), banner(2)]} />);
    expect(swiperState.props?.autoplay).toEqual({ delay: 5000, disableOnInteraction: false });
  });

  // @scenario surface=autoplay_pauses_on_hover_and_focus
  it('마우스를 올리거나 포커스가 들어가면 Swiper 자동 재생을 멈추고, 나가면 다시 켠다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: true, interval_seconds: 3 }} />);
    const region = container.querySelector('section') as HTMLElement;
    const autoplay = swiperState.instance!.autoplay;

    fireEvent.pointerEnter(region, { pointerType: 'mouse' });
    expect(autoplay.running).toBe(false);
    fireEvent.pointerLeave(region, { pointerType: 'mouse' });
    expect(autoplay.running).toBe(true);

    fireEvent.focus(container.querySelector('.hs__next') as HTMLElement);
    expect(autoplay.running).toBe(false);
    fireEvent.blur(container.querySelector('.hs__next') as HTMLElement, { relatedTarget: document.body });
    expect(autoplay.running).toBe(true);
  });

  // @scenario surface=pause_button_toggles_autoplay
  // @scenario surface=autoplay_pauses_on_hover_and_focus
  it('포커스가 남아 있으면 마우스 이탈로 재생하지 않고, hover가 남으면 blur로 재생하지 않는다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} />);
    const region = container.querySelector('section')!;
    const next = container.querySelector('.hs__next')!;
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
  it('[case:components-misc-carousel-autoplay-held-by-pointer-focus] 마우스로 화살표를 누르면(누르며 포커스가 들어가도) 마우스가 나간 뒤 자동 넘김이 다시 돈다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2), banner(3)]} settings={{ autoplay: true, interval_seconds: 3 }} />);
    const region = container.querySelector('section')!;
    const next = container.querySelector('.hs__next')!;
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
    expect(container.querySelector('.hs__toggle')?.getAttribute('aria-pressed')).toBe('false');
  });

  // @scenario surface=pointer_click_does_not_hold_autoplay
  it('[case:components-misc-carousel-autoplay-held-by-pointer-focus] 휴대폰에서 점을 탭해도(뒤따르는 흉내 마우스 이벤트·포커스 포함) 자동 넘김이 계속 돈다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2), banner(3)]} settings={{ autoplay: true, interval_seconds: 3 }} />);
    const region = container.querySelector('section')!;
    const dot = container.querySelectorAll('.swiper-pagination-bullet')[2] as HTMLElement;
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
  it('일시정지 단추가 자동 재생을 멈추고 다시 켠다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: true, interval_seconds: 3 }} />);
    const toggle = container.querySelector('.hs__toggle') as HTMLButtonElement;
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
  it('움직임 줄이기를 켠 사용자는 자동 재생·효과가 꺼진다', () => {
    mockReducedMotion(true);
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: true, interval_seconds: 3, effect: 'slide' }} />);
    expect(swiperState.props?.autoplay).toBe(false);
    expect(swiperState.props?.speed).toBe(0);
    expect(container.querySelector('section')?.className).toContain('hs--none');
    expect(container.querySelector('.hs__toggle')).toBeNull();
  });

  // @scenario surface=effect_fade_and_none
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
      expect(container.querySelector('section')?.className).toContain(`hs--${setting}`);
      expect(swiperState.props?.effect).toBe(effect);
      expect(swiperState.props?.speed).toBe(speed);
      if (option) expect(swiperState.props?.[option]).toBeTruthy();
      // 그림자는 끈다(배너 밖으로 번지거나 사진을 덮는다)
      for (const key of ['cubeEffect', 'flipEffect', 'coverflowEffect']) {
        if (swiperState.props?.[key]) expect(swiperState.props[key].slideShadows).toBe(false);
      }
      fireEvent.click(container.querySelector('.hs__next') as HTMLElement);
      expect(activeId(container)).toBe('2');
      unmount();
    }
  });

  // @scenario surface=prev_next_buttons_and_keyboard
  it('이전·다음 단추와 좌우 화살표 키가 Swiper 를 넘긴다(무한 반복이라 끝에서 처음으로 돈다)', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2), banner(3)]} settings={{ autoplay: false }} />);
    const region = container.querySelector('section') as HTMLElement;
    const instance = swiperState.instance!;

    fireEvent.click(container.querySelector('.hs__prev') as HTMLElement);
    expect(activeId(container)).toBe('3');
    fireEvent.keyDown(region, { key: 'ArrowRight' });
    expect(activeId(container)).toBe('1');
    fireEvent.keyDown(region, { key: 'ArrowRight' });
    fireEvent.keyDown(region, { key: 'ArrowLeft' });
    expect(activeId(container)).toBe('1');
    fireEvent.click(container.querySelector('.hs__next') as HTMLElement);
    expect(activeId(container)).toBe('2');
    expect(instance.slidePrev).toHaveBeenCalledTimes(2);
    expect(instance.slideNext).toHaveBeenCalledTimes(3);
  });

  // @scenario surface=counter_not_announced_while_autoplaying
  // @effects accessible_carousel_semantics
  it('자동으로 넘어가는 동안은 장 번호를 읽지 않고(aria-live off), 멈추면 읽는다(polite)', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: true, interval_seconds: 3 }} />);
    const counter = container.querySelector('.hs__counter') as HTMLElement;
    expect(counter.getAttribute('aria-live')).toBe('off');

    fireEvent.pointerEnter(container.querySelector('section') as HTMLElement, { pointerType: 'mouse' });
    expect(counter.getAttribute('aria-live')).toBe('polite');
    fireEvent.pointerLeave(container.querySelector('section') as HTMLElement, { pointerType: 'mouse' });
    fireEvent.click(container.querySelector('.hs__toggle') as HTMLElement);
    fireEvent.blur(container.querySelector('.hs__toggle') as HTMLElement, { relatedTarget: document.body });
    expect(counter.getAttribute('aria-live')).toBe('polite');

    cleanup();
    const manual = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: false }} />);
    expect(manual.container.querySelector('.hs__counter')?.getAttribute('aria-live')).toBe('polite');
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
    // 무한 반복은 슬라이드 DOM 순서를 바꾸므로 논리 순서를 data-index 로 남긴다
    expect(Array.from(slides).map((el) => el.getAttribute('data-index'))).toEqual(['0', '1']);
    expect(slides[1].getAttribute('aria-hidden')).toBe('true');
    expect(container.querySelector('.hs__counter')?.textContent).toBe('1 / 2');
    expect(container.querySelector('.hs__prev')?.getAttribute('aria-label')).toBeTruthy();
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
    Object.defineProperty(HTMLElement.prototype, 'scrollHeight', { configurable: true, get() { return this.classList.contains('hs__scroll') && overflow ? 500 : 100; } });
    Object.defineProperty(HTMLElement.prototype, 'clientHeight', { configurable: true, get() { return 100; } });

    try {
      const { container } = render(<HeroSlider banners={[banner(1), banner(2, { title: '' })]} settings={{ autoplay: false }} />);
      const scrolls = () => Array.from(container.querySelectorAll<HTMLElement>('.hs__scroll'));

      expect(scrolls().map((el) => [el.getAttribute('tabindex'), el.getAttribute('role'), el.getAttribute('aria-label')])).toEqual([
        ['0', 'region', '제목 1'],
        // 제목이 없으면 이미지 대체 텍스트로 이름 짓는다
        ['-1', 'region', '대체 2'],
      ]);

      // 화면 폭이 넓어져 더는 넘치지 않으면 속성을 모두 거둔다
      overflow = false;
      act(() => observers.forEach((notify) => notify()));
      for (const el of scrolls()) {
        expect(el.hasAttribute('tabindex')).toBe(false);
        expect(el.hasAttribute('role')).toBe(false);
        expect(el.hasAttribute('aria-label')).toBe(false);
      }
    } finally {
      delete (HTMLElement.prototype as any).scrollHeight;
      delete (HTMLElement.prototype as any).clientHeight;
      (globalThis as any).ResizeObserver = OriginalObserver;
    }
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
  it('본문 폭(기본)이면 확장점이 넘긴 본문 상자로 슬라이더를 감싸고, 상자가 없으면 감싸지 않는다', () => {
    const { container, rerender } = render(<HeroSlider banners={[banner(1), banner(2)]} contentClass="max-w-7xl mx-auto px-8" />);
    const frame = container.querySelector('[data-hs-width="content"]') as HTMLElement;
    expect(frame.className).toBe('hs-frame max-w-7xl mx-auto px-8');
    expect(frame.querySelector(':scope > section.hs.hs--content')).not.toBeNull();
    // 문구·넘김 단추는 상자로 다시 감싸지 않는다(이미 상자 안)
    expect(container.querySelector('.hs__box')).toBeNull();
    expect(container.querySelector('.hs__controls-layer')).toBeNull();

    rerender(<HeroSlider banners={[banner(1)]} />);
    expect(container.querySelector('[data-hs-width]')).toBeNull();
    expect(container.firstElementChild?.tagName).toBe('SECTION');
  });

  // @scenario surface=slider_full_text_box
  it('화면 전체 폭이면 끝까지 그리고 제목·넘김 단추만 본문 상자에 맞춘다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ width: 'full' }} contentClass="max-w-7xl mx-auto px-8" />);
    const section = container.firstElementChild as HTMLElement;
    expect(section.tagName).toBe('SECTION');
    expect(section.className).toContain('hs--full');
    expect(container.querySelector('[data-hs-width]')).toBeNull();
    const boxes = [...container.querySelectorAll('.hs__text.hs__text--boxed > .hs__box')];
    expect(boxes).toHaveLength(2);
    expect(boxes[0].className).toBe('hs__box max-w-7xl mx-auto px-8');
    expect(boxes[0].querySelector(':scope > .hs__textbox > .hs__scroll .hs__title')?.textContent).toBe('제목 1');
    const controlsBox = container.querySelector('.hs__controls-layer > .hs__controls-box') as HTMLElement;
    expect(controlsBox.className).toBe('hs__controls-box max-w-7xl mx-auto px-8');
    expect(controlsBox.querySelector(':scope > .hs__controls.hs__controls--boxed .hs__toggle')).not.toBeNull();
    // 좌우 화살표·점 표시는 배너 기준(Swiper 기본 자리)이라 상자로 감싸지 않는다
    expect(section.querySelector(':scope > .hs__prev')).not.toBeNull();
    expect(section.querySelector(':scope > .hs__pagination')).not.toBeNull();
  });

  // @scenario surface=slider_full_text_box
  it('화면 전체 폭인데 본문 상자를 받지 못하면(자리가 본문 상자 안인 템플릿) 문구를 감싸지 않고 종전 여백을 쓴다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ width: 'full' }} />);
    expect(container.querySelector('section')?.className).toContain('hs--full');
    expect(container.querySelector('.hs__box')).toBeNull();
    expect(container.querySelector('.hs__text--boxed')).toBeNull();
    expect(container.querySelector('.hs__controls-layer')).toBeNull();
    expect(container.querySelector('section > .hs__controls')).not.toBeNull();
  });

  // @scenario surface=swiper_pagination_and_arrows
  it('2장 이상이면 Swiper 기본 모양의 좌우 화살표와 점 표시를 이름 붙은 단추로 그리고, 점을 누르면 그 장으로 간다', () => {
    const { container } = render(<HeroSlider banners={[banner(1), banner(2), banner(3)]} settings={{ autoplay: false }} />);
    const prev = container.querySelector('button.swiper-button-prev.hs__prev') as HTMLButtonElement;
    const next = container.querySelector('button.swiper-button-next.hs__next') as HTMLButtonElement;
    expect(prev.getAttribute('aria-label')).toBe('이전 배너');
    expect(container.querySelectorAll('.hs__pagination button')[1].getAttribute('aria-label')).toBe('2번째 배너로 이동');
    expect(next.querySelector('svg.swiper-navigation-icon')).not.toBeNull();
    const bullets = [...container.querySelectorAll<HTMLButtonElement>('.hs__pagination.swiper-pagination-bullets > button.swiper-pagination-bullet')];
    expect(bullets).toHaveLength(3);
    expect(bullets.map((b) => b.getAttribute('aria-current'))).toEqual(['true', null, null]);
    expect(bullets[0].className).toContain('swiper-pagination-bullet-active');
    fireEvent.click(bullets[2]);
    expect(swiperState.instance?.slideToLoop).toHaveBeenCalledWith(2);
    expect(activeId(container)).toBe('3');
    expect(bullets[2].getAttribute('aria-current')).toBe('true');
    // 장 번호는 화면 읽기용으로 남는다
    expect(container.querySelector('.hs__counter')?.textContent).toBe('3 / 3');
  });

  // @scenario surface=swiper_pagination_and_arrows
  it('1장이면 화살표·점 표시가 없다', () => {
    const { container } = render(<HeroSlider banners={[banner(1)]} />);
    expect(container.querySelector('.hs__prev, .hs__next, .hs__pagination')).toBeNull();
  });

  // @scenario surface=swiper_extra_effects
  it('모르는 효과 값은 slide 로 그린다', () => {
    render(<HeroSlider banners={[banner(1), banner(2)]} settings={{ autoplay: false, effect: 'zoom' as any }} />);
    expect(swiperState.props?.effect).toBe('slide');
    expect(swiperState.props?.speed).toBe(500);
  });

  describe('받는 중 스켈레톤', () => {
    beforeEach(() => window.localStorage.clear());

    // @scenario surface=loading_skeleton_until_response
    it('받는 중이고 배너가 아직 없으면 같은 비율의 스켈레톤을 그리고(읽지 않음), 받은 뒤 0건이면 아무것도 그리지 않는다', () => {
      swiperState.props = null;
      const { container, rerender } = render(<HeroSlider banners={[]} loading position="home_hero" contentClass="max-w-7xl mx-auto px-8" />);
      const skeleton = container.querySelector('section.hs.hs--skeleton') as HTMLElement;
      expect(skeleton.getAttribute('aria-hidden')).toBe('true');
      expect(skeleton.style.getPropertyValue('--hs-ratio-pc')).toBe('1920 / 640');
      expect(skeleton.className).toContain('hs--content');
      // 본문 폭이면 실제 슬라이더처럼 본문 상자 안
      expect(container.querySelector('[data-hs-width="content"] > section.hs--skeleton')).not.toBeNull();
      expect(container.querySelectorAll('.hs__skeleton-line')).toHaveLength(3);
      expect(swiperState.props).toBeNull();

      rerender(<HeroSlider banners={[]} loading={false} position="home_hero" contentClass="max-w-7xl mx-auto px-8" />);
      expect(container.innerHTML).toBe('');
    });

    // @scenario surface=loading_skeleton_until_response
    it('받은 배너가 오면 스켈레톤을 실제 슬라이더로 바꾼다', () => {
      const { container, rerender } = render(<HeroSlider banners={[]} loading position="home_hero" />);
      expect(container.querySelector('.hs--skeleton')).not.toBeNull();
      rerender(<HeroSlider banners={[banner(1)]} loading={false} position="home_hero" />);
      expect(container.querySelector('.hs--skeleton')).toBeNull();
      expect(container.querySelector('section.hs [data-banner-id="1"]')).not.toBeNull();
    });

    // @scenario surface=loading_skeleton_remembers_slot
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
      const skeleton = next.container.querySelector('section.hs--skeleton') as HTMLElement;
      expect(skeleton.className).toContain('hs--full');
      expect(next.container.querySelector('[data-hs-width]')).toBeNull();
      // 화면 전체면 문구 자리만 본문 상자에 맞춘다
      expect(skeleton.querySelector('.hs__text--boxed > .hs__box.px-8 .hs__skeleton-lines')).not.toBeNull();
    });

    // @scenario surface=loading_skeleton_remembers_slot
    it('받기에 실패하면(설정 없이 끝남) 기억을 남기지 않아, 다음 방문에도 스켈레톤이 자리를 잡는다', () => {
      window.localStorage.setItem('sirsoft-hero_section:slot:home_hero', JSON.stringify({ banners: 3, width: 'full' }));
      const failed = render(<HeroSlider banners={[]} loading={false} position="home_hero" />);
      expect(failed.container.innerHTML).toBe('');
      failed.unmount();
      expect(JSON.parse(window.localStorage.getItem('sirsoft-hero_section:slot:home_hero')!)).toEqual({ banners: 3, width: 'full' });
      const next = render(<HeroSlider banners={[]} loading position="home_hero" />);
      expect(next.container.querySelector('section.hs--skeleton.hs--full')).not.toBeNull();
    });

    // @scenario surface=loading_skeleton_remembers_slot
    it('첫 로드 설정의 표시 폭 힌트가 브라우저 기억보다 먼저다(저장소를 지우는 개인정보 설정에서도 폭이 맞는다)', () => {
      window.localStorage.setItem('sirsoft-hero_section:slot:home_hero', JSON.stringify({ banners: 2, width: 'content' }));
      const { container, rerender } = render(<HeroSlider banners={[]} loading position="home_hero" skeletonWidth="full" contentClass="px-8" />);
      expect(container.querySelector('section.hs--skeleton')?.className).toContain('hs--full');
      window.localStorage.clear();
      rerender(<HeroSlider banners={[]} loading position="shop_hero" skeletonWidth="content" contentClass="px-8" />);
      expect(container.querySelector('[data-hs-width="content"] > section.hs--skeleton')).not.toBeNull();
    });

    // @scenario surface=loading_skeleton_remembers_slot
    it('저장소를 쓸 수 없어도(개인정보 동의 전 차단 등) 기본 스켈레톤으로 동작한다', () => {
      const getItem = vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => { throw new Error('blocked'); });
      const setItem = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => { throw new Error('blocked'); });
      const { container } = render(<HeroSlider banners={[]} loading position="home_hero" />);
      expect(container.querySelector('section.hs--skeleton.hs--content')).not.toBeNull();
      getItem.mockRestore();
      setItem.mockRestore();
    });

    // @scenario surface=loading_skeleton_until_response
    it('위치를 모르는 미리보기는 기억을 쓰지 않는다', () => {
      render(<HeroSlider banners={[banner(1)]} />);
      expect(window.localStorage.length).toBe(0);
    });
  });
});
