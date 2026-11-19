import { describe, it, expect, afterEach } from 'vitest';
import {
  BREAKPOINTS,
  UNKNOWN_VIEWPORT_WIDTH,
  containerPaddingClass,
  pickByWidth,
  resolveViewportWidth,
} from '../viewportWidth';

/**
 * 공용 폭 판정 — 부품 안의 Tailwind 반응형 접두(`sm:` `md:` `lg:` …) 대신 엔진 폭으로 클래스를 고른다.
 * 경계는 Tailwind 와 같고, 폭을 모를 때는 모바일 우선 CSS 의 기본(가장 좁은 쪽)과 같게 고른다.
 */
describe('viewportWidth — 엔진 폭 판정 공용 헬퍼', () => {
  const savedInnerWidth = window.innerWidth;
  afterEach(() => {
    Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: savedInnerWidth });
  });

  // @scenario case=viewport_c01
  // @effects component_width_classes_follow_engine_width
  it('경계는 Tailwind 와 같다 (sm 640, md 768, lg 1024, xl 1280, 2xl 1536)', () => {
    expect(BREAKPOINTS).toEqual({ sm: 640, md: 768, lg: 1024, xl: 1280, '2xl': 1536 });
  });

  // @scenario case=viewport_c02
  // @effects component_width_classes_follow_engine_width
  it('폭은 엔진 훅 값 → 창 폭 순으로 읽는다', () => {
    Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: 1600 });
    expect(resolveViewportWidth({ width: 390 })).toBe(390);
    expect(resolveViewportWidth({ width: 0 })).toBe(0);
    expect(resolveViewportWidth(undefined)).toBe(1600);
    expect(resolveViewportWidth({})).toBe(1600);
    expect(resolveViewportWidth({ width: Number.NaN })).toBe(1600);
  });

  // @scenario case=viewport_c03
  // @effects component_width_unknown_defaults_to_engine_default
  it('폭을 모르면 엔진 기본 폭(1024, 검색 봇 렌더 폭)을 쓴다', () => {
    expect(UNKNOWN_VIEWPORT_WIDTH).toBe(1024);
    Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: undefined });
    expect(resolveViewportWidth(undefined)).toBe(UNKNOWN_VIEWPORT_WIDTH);
    expect(containerPaddingClass(resolveViewportWidth(undefined))).toBe('px-8');
  });

  // @scenario case=viewport_c04
  // @effects component_width_classes_follow_engine_width
  it('pickByWidth 는 Tailwind 와 같은 cascade 다 — 맞는 구간 중 가장 넓은 값, 없으면 기본값', () => {
    const steps = { sm: 'b', lg: 'c' };
    expect(pickByWidth(0, 'a', steps)).toBe('a');
    expect(pickByWidth(639, 'a', steps)).toBe('a');
    expect(pickByWidth(640, 'a', steps)).toBe('b');
    expect(pickByWidth(1023, 'a', steps)).toBe('b');
    expect(pickByWidth(1024, 'a', steps)).toBe('c');
    expect(pickByWidth(4000, 'a', steps)).toBe('c');
    expect(pickByWidth(4000, 'a', {})).toBe('a');
  });

  // @scenario case=viewport_c05
  // @effects component_width_classes_follow_engine_width
  it.each([
    [375, 'px-4'],
    [639, 'px-4'],
    [640, 'px-6'],
    [1023, 'px-6'],
    [1024, 'px-8'],
    [1280, 'px-8'],
  ])('본문 칸 좌우 여백 — 폭 %ipx 에서 %s (옛 px-4 sm:px-6 lg:px-8)', (width, expected) => {
    expect(containerPaddingClass(width)).toBe(expected);
  });
});
