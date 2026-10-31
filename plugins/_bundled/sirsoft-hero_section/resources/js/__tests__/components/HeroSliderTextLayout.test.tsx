/**
 * @file HeroSliderTextLayout.test.tsx
 * @description 문구 자리·머리글·등장 효과·메뉴 겹침 신호 — 문구 띠·상자의 CSS 변수(PC·휴대폰 따로, 미리보기 기기 고정), 기본값이
 * 종전 화면(왼쪽 아래 전폭)과 같은지, 줄마다 0.2초 시차, 글자 하나씩의 화면 읽기 원문, 움직임 줄이기, `<html data-header-overlay>`
 * 신호(화면 전체 폭·겹치기 켬·사이트 화면에서만, 장마다 바탕 밝기, 사라지면 지움), 받는 동안의 힌트, 편집 미리보기 끌기·화살표 키,
 * 9칸 고르기 컴포넌트. (매니페스트 text-layout-overlay.yaml)
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, cleanup, fireEvent, act } from '@testing-library/react';
import { HeroSlider, type HeroBanner } from '../../components/composite/HeroSlider';
import { HeroTextPositionPad } from '../../components/composite/HeroTextPositionPad';
import { textMotion, textVars, percent } from '../../support/text';
import { swiperState } from '../swiperMock';

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

const overlay = () => document.documentElement.getAttribute('data-header-overlay');
const style = (element: Element | null) => (element as HTMLElement).style;

describe('문구 자리·머리글·등장 효과', () => {
  beforeEach(() => {
    mockReducedMotion(false);
    window.localStorage.clear();
  });

  afterEach(() => {
    cleanup();
    document.documentElement.removeAttribute('data-header-overlay');
  });

  // @scenario surface=slider_text_default_compat
  it('표현값이 없으면 종전 화면(왼쪽 아래·전폭·위에서 아래로 짙어지는 배경)이고 효과가 없다', () => {
    const { container } = render(<HeroSlider banners={[banner(1, { presentation: null })]} />);
    const text = style(container.querySelector('.hs__text'));
    expect(text.getPropertyValue('--hs-tx-pc')).toBe('0');
    expect(text.getPropertyValue('--hs-ty-pc')).toBe('100');
    expect(text.getPropertyValue('--hs-ty-mobile')).toBe('100');
    expect(text.getPropertyValue('--hs-tw')).toBe('100');
    expect(text.getPropertyValue('--hs-shade-pc')).toBe('to bottom');
    expect(container.querySelector('.hs__textbox')?.className).toContain('hs__textbox--none');
  });

  // @scenario surface=slider_text_spot_by_device
  it('PC·휴대폰 자리를 따로 싣고, 위쪽 문구는 배경이 위로 짙어진다. 미리보기 기기 고정이면 그 기기 값을 두 칸에 넣는다', () => {
    const presentation = { text_pc_x: 50, text_pc_y: 20, text_mobile_x: 100, text_mobile_y: 80, text_width: 60 };
    const { container, unmount } = render(<HeroSlider banners={[banner(1, { presentation })]} />);
    const text = style(container.querySelector('.hs__text'));
    expect([text.getPropertyValue('--hs-tx-pc'), text.getPropertyValue('--hs-ty-pc')]).toEqual(['50', '20']);
    expect([text.getPropertyValue('--hs-tx-mobile'), text.getPropertyValue('--hs-ty-mobile')]).toEqual(['100', '80']);
    expect(text.getPropertyValue('--hs-shade-pc')).toBe('to top');
    expect(text.getPropertyValue('--hs-shade-mobile')).toBe('to bottom');
    expect(text.getPropertyValue('--hs-tw')).toBe('60');
    unmount();

    const forced = render(<HeroSlider banners={[banner(1, { presentation })]} forceDevice="mobile" />);
    const vars = style(forced.container.querySelector('.hs__text'));
    expect([vars.getPropertyValue('--hs-tx-pc'), vars.getPropertyValue('--hs-ty-pc')]).toEqual(['100', '80']);
  });

  // @scenario surface=slider_eyebrow
  it('머리글은 제목 위에 그 색·제목 정렬로 보이고, 숨기거나 비우면 그리지 않는다', () => {
    const { container, rerender } = render(<HeroSlider banners={[banner(1)]} />);
    const lines = [...container.querySelectorAll('.hs__line')];
    expect(lines.map((l) => l.className.split(' ')[0])).toEqual(['hs__eyebrow', 'hs__title', 'hs__description', 'hs__actions']);
    expect(style(lines[0]).color).toBe('rgb(253, 230, 138)');
    expect(style(lines[0]).textAlign).toBe('center');
    rerender(<HeroSlider banners={[banner(1, { eyebrow_visible: false })]} />);
    expect(container.querySelector('.hs__eyebrow')).toBeNull();
    rerender(<HeroSlider banners={[banner(1, { eyebrow: '' })]} />);
    expect(container.querySelector('.hs__eyebrow')).toBeNull();
  });

  // @scenario surface=slider_motion_stagger
  it('등장 효과는 그려지는 줄만 세어 시작 지연 + 0.2초씩 늦추고, 속도를 줄 길이로 쓴다', () => {
    const presentation = { animation: 'rise', animation_speed: 'slow', animation_delay: 0.5 };
    const { container } = render(<HeroSlider banners={[banner(1, { presentation, description: '' })]} />);
    const box = container.querySelector('.hs__textbox') as HTMLElement;
    expect(box.className).toContain('hs__textbox--rise');
    expect(box.style.getPropertyValue('--hs-anim-duration')).toBe('1s');
    expect([...container.querySelectorAll<HTMLElement>('.hs__line')].map((l) => l.style.getPropertyValue('--hs-line-delay'))).toEqual(['0.5s', '0.7s', '0.9s']);
  });

  // @scenario surface=slider_motion_letters
  it('글자 하나씩은 머리글·제목을 글자로 나누고(읽기 원문은 한 번), 설명·버튼은 줄 단위다', () => {
    const { container } = render(<HeroSlider banners={[banner(1, { title: '가을 신상', presentation: { animation: 'letters' } })]} />);
    const title = container.querySelector('.hs__title')!;
    expect(title.querySelector('.hs__sr')?.textContent).toBe('가을 신상');
    expect(title.querySelector('[aria-hidden="true"]')).not.toBeNull();
    expect([...title.querySelectorAll('.hs__letter')].map((l) => l.textContent)).toEqual(['가', '을', '신', '상']);
    expect(title.querySelectorAll('.hs__word')).toHaveLength(2);
    expect(title.className).toContain('hs__line--letters');
    expect(container.querySelector('.hs__description')?.className).not.toContain('hs__line--letters');
    expect(container.querySelector('.hs__description .hs__letter')).toBeNull();
  });

  // @scenario surface=slider_motion_reduced
  it('움직임 줄이기와 모르는 효과 이름은 효과 없음이다', () => {
    mockReducedMotion(true);
    const { container } = render(<HeroSlider banners={[banner(1, { presentation: { animation: 'zoom' } })]} />);
    expect(container.querySelector('.hs__textbox')?.className).toContain('hs__textbox--none');
    expect(textMotion({ animation: 'spin' }, false).name).toBe('none');
    expect(textMotion({ animation: 'fade', animation_delay: 9 }, false).delay).toBe(2);
  });

  // @scenario surface=slider_text_spot_by_device
  it('잘못된 자리·폭 값은 범위 안으로 맞추거나 기본값을 쓴다', () => {
    expect(percent('abc', 100)).toBe(100);
    expect(percent(140, 0)).toBe(100);
    expect(percent(10, 100, 30)).toBe(30);
    const vars = textVars({ text_pc_x: -5, text_width: 5 } as never) as Record<string, unknown>;
    expect(vars['--hs-tx-pc']).toBe(0);
    expect(vars['--hs-tw']).toBe(30);
  });
});

describe('메뉴 겹침 신호', () => {
  beforeEach(() => {
    mockReducedMotion(false);
    window.localStorage.clear();
  });

  afterEach(() => {
    cleanup();
    document.documentElement.removeAttribute('data-header-overlay');
    document.documentElement.classList.remove('dark');
  });

  // @scenario surface=overlay_signal_full_only
  it('화면 전체 폭 + 겹치기면 지금 장의 바탕 밝기를 달고, 장이 바뀌면 바꾸며, 사라지면 지운다', () => {
    const banners = [banner(1, { presentation: { tone: 'dark' } }), banner(2, { presentation: { tone: 'light' } })];
    const { container, unmount } = render(<HeroSlider banners={banners} settings={{ width: 'full', header_overlay: true }} />);
    expect(overlay()).toBe('dark');
    expect(container.querySelector('section.hs')?.className).toContain('hs--header-overlay');
    act(() => { swiperState.instance?.slideNext(); });
    expect(overlay()).toBe('light');
    unmount();
    expect(overlay()).toBeNull();
  });

  // @scenario surface=overlay_signal_excluded
  it('본문 폭·겹치기 꺼짐·편집 미리보기(기기 고정)·배너 0건에서는 신호를 내지 않는다', () => {
    const banners = [banner(1)];
    const cases: React.ReactElement[] = [
      <HeroSlider banners={banners} settings={{ width: 'content', header_overlay: true }} />,
      <HeroSlider banners={banners} settings={{ width: 'full', header_overlay: false }} />,
      <HeroSlider banners={banners} settings={{ width: 'full', header_overlay: true }} forceDevice="pc" />,
      <HeroSlider banners={[]} settings={{ width: 'full', header_overlay: true }} />,
    ];
    for (const element of cases) {
      const { container, unmount } = render(element);
      expect(overlay()).toBeNull();
      expect(container.querySelector('.hs--header-overlay')).toBeNull();
      unmount();
    }
  });

  // @scenario surface=overlay_skeleton_hint
  it('받는 동안에는 힌트(화면 전체 + 겹침)대로 스켈레톤도 메뉴 밑으로 넣고, 바탕은 사이트 다크 모드를 따른다', () => {
    document.documentElement.classList.add('dark');
    const { container, rerender } = render(<HeroSlider banners={[]} loading skeletonWidth="full" skeletonOverlay position="home_hero" />);
    expect(container.querySelector('.hs--skeleton')?.className).toContain('hs--header-overlay');
    expect(overlay()).toBe('dark');
    rerender(<HeroSlider banners={[banner(1, { presentation: { tone: 'light' } })]} settings={{ width: 'full', header_overlay: true }} position="home_hero" />);
    expect(overlay()).toBe('light');
    expect(JSON.parse(window.localStorage.getItem('sirsoft-hero_section:slot:home_hero')!)).toEqual({ banners: 1, width: 'full', overlay: true, tone: 'light' });
    rerender(<HeroSlider banners={[]} loading skeletonWidth="full" skeletonOverlay={false} position="home_hero" />);
    expect(overlay()).toBeNull();
  });
});

describe('편집 미리보기 — 문구 옮기기', () => {
  beforeEach(() => mockReducedMotion(false));
  afterEach(() => cleanup());

  // @scenario surface=editor_text_keyboard
  it('문구 상자는 초점을 받고, 화살표 1%·Shift 10% 로 고정 기기의 새 자리를 알린다(범위 밖은 끝값)', () => {
    const onTextMove = vi.fn();
    const presentation = { text_mobile_x: 5, text_mobile_y: 95 };
    const { container } = render(<HeroSlider banners={[banner(1, { presentation })]} forceDevice="mobile" onTextMove={onTextMove} />);
    const box = container.querySelector('.hs__textbox') as HTMLElement;
    expect(box.tabIndex).toBe(0);
    expect(box.className).toContain('hs__textbox--editable');
    expect(box.getAttribute('aria-label')).toContain('5');
    fireEvent.keyDown(box, { key: 'ArrowRight' });
    expect(onTextMove).toHaveBeenLastCalledWith({ x: 6, y: 95 }, 'mobile');
    fireEvent.keyDown(box, { key: 'ArrowDown', shiftKey: true });
    expect(onTextMove).toHaveBeenLastCalledWith({ x: 5, y: 100 }, 'mobile');
    fireEvent.keyDown(box, { key: 'ArrowLeft', shiftKey: true });
    expect(onTextMove).toHaveBeenLastCalledWith({ x: 0, y: 95 }, 'mobile');
  });

  // @scenario surface=editor_text_keyboard
  it('사이트 화면(콜백 없음)에서는 문구 상자를 옮길 수 없다', () => {
    const { container } = render(<HeroSlider banners={[banner(1)]} />);
    const box = container.querySelector('.hs__textbox') as HTMLElement;
    expect(box.hasAttribute('tabindex')).toBe(false);
    expect(box.className).not.toContain('hs__textbox--editable');
  });

  // @scenario surface=editor_text_drag
  it('끌면 움직인 만큼(옮길 거리 대비 %) 화면에서만 따라오고 놓을 때 한 번 알린다', () => {
    const onTextMove = vi.fn();
    const { container } = render(<HeroSlider banners={[banner(1, { presentation: { text_pc_x: 0, text_pc_y: 100, text_width: 50 } })]} forceDevice="pc" onTextMove={onTextMove} />);
    const box = container.querySelector('.hs__textbox') as HTMLElement;
    const slide = container.querySelector('.hs__slide') as HTMLElement;
    const layer = container.querySelector('.hs__text') as HTMLElement;
    // jsdom 은 크기를 재지 않는다 — 띠 안 폭 1000 · 상자 500 → 가로 500px, 슬라이드 400 · 띠 200 → 세로 200px 옮길 수 있다
    Object.defineProperty(layer, 'clientWidth', { value: 1000 });
    Object.defineProperty(box, 'offsetWidth', { value: 500 });
    Object.defineProperty(slide, 'clientHeight', { value: 400 });
    Object.defineProperty(layer, 'offsetHeight', { value: 200 });
    fireEvent.pointerDown(box, { button: 0, pointerId: 1, clientX: 100, clientY: 300 });
    fireEvent.pointerMove(box, { pointerId: 1, clientX: 350, clientY: 200 });
    expect(style(container.querySelector('.hs__text')).getPropertyValue('--hs-tx-pc')).toBe('50');
    expect(onTextMove).not.toHaveBeenCalled();
    fireEvent.pointerUp(box, { pointerId: 1, clientX: 350, clientY: 200 });
    expect(onTextMove).toHaveBeenCalledTimes(1);
    expect(onTextMove).toHaveBeenCalledWith({ x: 50, y: 50 }, 'pc');
  });

  // @scenario surface=slider_motion_stagger
  it('효과 다시 보기 값이 바뀌면 문구 상자를 새로 그린다', () => {
    const { container, rerender } = render(<HeroSlider banners={[banner(1, { presentation: { animation: 'fade' } })]} forceDevice="pc" replay={0} />);
    const before = container.querySelector('.hs__textbox');
    rerender(<HeroSlider banners={[banner(1, { presentation: { animation: 'fade' } })]} forceDevice="pc" replay={1} />);
    expect(container.querySelector('.hs__textbox')).not.toBe(before);
  });
});

describe('HeroTextPositionPad', () => {
  afterEach(() => cleanup());

  // @scenario surface=editor_text_grid
  it('지금 기기의 자리를 9칸·숫자로 보이고, 고르면 그 기기 키로 알린다', () => {
    const onChange = vi.fn();
    const onDeviceChange = vi.fn();
    const presentation = { text_pc_x: 50, text_pc_y: 50, text_mobile_x: 0, text_mobile_y: 100 };
    const { container, rerender } = render(<HeroTextPositionPad presentation={presentation} device="pc" onChange={onChange} onDeviceChange={onDeviceChange} />);
    expect(container.querySelector('[data-spot="middle_center"]')?.getAttribute('aria-pressed')).toBe('true');
    expect(container.querySelectorAll('[aria-pressed="true"][data-spot]')).toHaveLength(1);
    fireEvent.click(container.querySelector('[data-spot="top_right"]')!);
    expect(onChange).toHaveBeenLastCalledWith({ text_pc_x: 100, text_pc_y: 0 });
    fireEvent.change(container.querySelector('input[data-axis="y"]')!, { target: { value: '130' } });
    expect(onChange).toHaveBeenLastCalledWith({ text_pc_x: 50, text_pc_y: 100 });

    fireEvent.click(container.querySelectorAll('.hs-pad__device')[1]);
    expect(onDeviceChange).toHaveBeenCalledWith('mobile');
    rerender(<HeroTextPositionPad presentation={presentation} device="mobile" onChange={onChange} />);
    expect(container.querySelector('[data-spot="bottom_left"]')?.getAttribute('aria-pressed')).toBe('true');
    fireEvent.click(container.querySelector('[data-spot="top_center"]')!);
    expect(onChange).toHaveBeenLastCalledWith({ text_mobile_x: 50, text_mobile_y: 0 });
  });
});
