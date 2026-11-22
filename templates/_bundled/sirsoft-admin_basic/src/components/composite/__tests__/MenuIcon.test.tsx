/**
 * @file MenuIcon.test.tsx
 * @description 관리자 메뉴 아이콘 렌더러 — FA 클래스 · 이미지 · 인라인 SVG · 호환 문자열 (#381)
 *
 * 사이드바·메뉴 관리 목록·상세(보기 판)·편집 미리보기가 같은 규칙으로 그린다. 보기 판은 레이아웃에서
 * `<menu-icon spec icon fallbackIcon="file" class="text-base text-secondary">` 로 쓰므로, 보기 판 조합은
 * 그 속성 그대로 그려 잰다.
 *
 * @scenario-manifest tests/scenarios/menu-icon-rendering.yaml
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import { render, fireEvent } from '@testing-library/react';
import { MenuIcon, resolveMenuIconSpec, hasMenuIcon, MENU_ICON_CLASS_PATTERN } from '../MenuIcon';
import type { MenuIconSpecData } from '../MenuIcon';

/** 보기 판(_panel_view)이 MenuIcon 에 넘기는 속성 */
const VIEW_PANEL = { fallbackIcon: 'file', className: 'text-base text-secondary' } as const;

const SVG_WITH_SCRIPT = '<svg viewBox="0 0 24 24"><script>alert(1)</script><path d="M0 0h24v24H0z" fill="currentColor"/></svg>';

describe('MenuIcon — class', () => {
  /**
   * @scenario surface=view_panel, icon_type=class
   * @effects structured_icon_renders_per_type
   */
  it('스타일 토큰이 있는 FA 클래스는 원문 클래스 + className 으로 꾸밈 <i> 를 그린다', () => {
    const { container } = render(<MenuIcon spec={{ type: 'class', value: 'fas fa-users' }} {...VIEW_PANEL} />);
    const icon = container.querySelector('i');

    expect(icon?.className).toBe('fas fa-users text-base text-secondary');
    expect(icon?.getAttribute('aria-hidden')).toBe('true');
    expect(container.children).toHaveLength(1);
  });

  /**
   * @scenario surface=view_panel, icon_type=class
   * @effects structured_icon_renders_per_type
   */
  it('FA6 장형(fa-solid)도 원문 그대로 그린다', () => {
    const { container } = render(<MenuIcon spec={{ type: 'class', value: 'fa-solid fa-pen' }} className="w-4" />);

    expect(container.querySelector('i')?.className).toBe('fa-solid fa-pen w-4');
  });

  /**
   * @scenario surface=view_panel, icon_type=class
   * @effects structured_icon_renders_per_type
   */
  it('className 이 없으면 원문 클래스만 남는다', () => {
    const { container } = render(<MenuIcon spec={{ type: 'class', value: 'far fa-star' }} />);

    expect(container.querySelector('i')?.className).toBe('far fa-star');
  });

  /**
   * @scenario surface=view_panel, icon_type=class
   * @effects invalid_icon_falls_back
   */
  it.each([
    ['fas fa-<script>'],
    ['fas "fa-x"'],
    ['fas fa-x;color:red'],
    ['   '],
  ])('형식에 맞지 않는 클래스 %j 는 fallbackIcon 을 그린다', (value) => {
    const { container } = render(<MenuIcon spec={{ type: 'class', value }} fallbackIcon="fas fa-circle" />);
    const icons = container.querySelectorAll('i');

    expect(icons).toHaveLength(1);
    expect(icons[0].className).toBe('fas fa-circle');
  });

  /**
   * @scenario surface=view_panel, icon_type=class
   * @effects invalid_icon_falls_back
   */
  it('형식에 맞지 않는 클래스에 fallbackIcon 이 없으면 아무것도 그리지 않는다', () => {
    const { container } = render(<MenuIcon spec={{ type: 'class', value: 'fas fa-<x>' }} />);

    expect(container.innerHTML).toBe('');
  });
});

describe('MenuIcon — image', () => {
  const image: MenuIconSpecData = { type: 'image', source: 'site', path: '/images/menu.png', url: '/images/menu.png' };

  /**
   * @scenario surface=view_panel, icon_type=image
   * @effects structured_icon_renders_per_type
   */
  it('이미지는 꾸밈 <img> 로 그린다', () => {
    const { container } = render(<MenuIcon spec={image} {...VIEW_PANEL} />);
    const img = container.querySelector('img');

    expect(img).not.toBeNull();
    expect(img?.getAttribute('src')).toBe('/images/menu.png');
    expect(img?.getAttribute('alt')).toBe('');
    expect(img?.getAttribute('aria-hidden')).toBe('true');
    expect(img?.className).toBe('menu-icon-media text-base text-secondary');
    expect(container.querySelector('i')).toBeNull();
  });

  /**
   * @scenario surface=view_panel, icon_type=image
   * @effects broken_image_falls_back_to_circle
   */
  it('이미지가 깨지면(onError) fallbackIcon fas fa-circle 로 바뀐다', () => {
    const { container } = render(<MenuIcon spec={image} fallbackIcon="fas fa-circle" className="w-5 h-5" />);

    fireEvent.error(container.querySelector('img')!);

    expect(container.querySelector('img')).toBeNull();
    expect(container.querySelector('i')?.className).toBe('fas fa-circle w-5 h-5');
  });

  /**
   * @scenario surface=view_panel, icon_type=image
   * @effects broken_image_falls_back_to_circle
   */
  it('깨진 뒤 주소가 바뀌면 다시 이미지를 그린다', () => {
    const { container, rerender } = render(<MenuIcon spec={image} fallbackIcon="fas fa-circle" />);

    fireEvent.error(container.querySelector('img')!);
    expect(container.querySelector('img')).toBeNull();

    rerender(<MenuIcon spec={{ ...image, url: '/images/other.png' }} fallbackIcon="fas fa-circle" />);

    expect(container.querySelector('img')?.getAttribute('src')).toBe('/images/other.png');
  });

  /**
   * @scenario surface=view_panel, icon_type=image
   * @effects invalid_icon_falls_back
   */
  it('주소가 없는 이미지는 fallbackIcon(맨 이름 file → Icon)을 그린다', () => {
    const { container } = render(<MenuIcon spec={{ type: 'image', source: 'site', url: '' }} {...VIEW_PANEL} />);

    expect(container.querySelector('img')).toBeNull();
    expect(container.querySelector('i')?.className).toBe('fas fa-file text-base text-secondary');
  });
});

describe('MenuIcon — svg', () => {
  /**
   * @scenario surface=view_panel, icon_type=svg
   * @effects structured_icon_renders_per_type, svg_sanitized_inline
   */
  it('SVG 는 정제 후 span.menu-icon-media 안에 인라인으로 하나만 그린다', () => {
    const { container } = render(<MenuIcon spec={{ type: 'svg', value: SVG_WITH_SCRIPT }} {...VIEW_PANEL} />);
    const span = container.querySelector('span.menu-icon-media');

    expect(span).not.toBeNull();
    expect(span?.className).toBe('menu-icon-media text-base text-secondary');
    expect(span?.getAttribute('aria-hidden')).toBe('true');
    expect(container.querySelectorAll('svg')).toHaveLength(1);
    expect(container.querySelector('script')).toBeNull();
    expect(container.innerHTML).not.toContain('alert(1)');
    expect(container.querySelector('path')?.getAttribute('fill')).toBe('currentColor');
  });

  /**
   * @scenario surface=view_panel, icon_type=svg
   * @effects svg_sanitized_inline, invalid_icon_falls_back
   */
  it('정제 뒤 svg 가 남지 않으면 fallbackIcon 을 그린다', () => {
    const { container } = render(<MenuIcon spec={{ type: 'svg', value: '<script>alert(1)</script>' }} fallbackIcon="fas fa-circle" />);

    expect(container.querySelector('svg')).toBeNull();
    expect(container.querySelector('script')).toBeNull();
    expect(container.querySelector('i')?.className).toBe('fas fa-circle');
  });

  /**
   * 6차 D-6a: 서버 검사를 통과하지 못한 옛 저장 SVG 는 응답이 코드를 가린다(`value: null`) — 칸이 비지 않고 대체 아이콘.
   *
   * @scenario surface=sidebar, icon_type=svg
   * @effects invalid_icon_falls_back
   */
  it('서버가 코드를 가린 svg(value null)는 fallbackIcon 을 그린다', () => {
    const { container } = render(<MenuIcon spec={{ type: 'svg', value: null }} fallbackIcon="fas fa-circle" />);

    expect(container.querySelector('svg')).toBeNull();
    expect(container.querySelector('i')?.className).toBe('fas fa-circle');
  });

  /**
   * @scenario surface=view_panel, icon_type=svg
   * @effects svg_sanitized_inline, invalid_icon_falls_back
   */
  it('svg 로 시작하지 않는 마크업(도형만)은 그리지 않는다', () => {
    const { container } = render(<MenuIcon spec={{ type: 'svg', value: '<path d="M0 0"/>' }} />);

    expect(container.querySelector('span.menu-icon-media')).toBeNull();
  });
});

describe('MenuIcon — 호환 문자열 icon', () => {
  /**
   * @scenario surface=view_panel, icon_type=legacy_string
   * @effects legacy_icon_string_still_renders
   */
  it('spec 이 없으면 icon 문자열을 class 로 그린다', () => {
    const { container } = render(<MenuIcon spec={null} icon="fas fa-bell" {...VIEW_PANEL} />);

    expect(container.querySelector('i')?.className).toBe('fas fa-bell text-base text-secondary');
  });

  /**
   * @scenario surface=view_panel, icon_type=legacy_string
   * @effects legacy_icon_string_still_renders
   */
  it('스타일 토큰 없는 맨 이름은 Icon 이 fas 를 붙인다', () => {
    const { container } = render(<MenuIcon icon="home" className="w-4 h-4" />);

    expect(container.querySelector('i')?.className).toBe('fas fa-home w-4 h-4');
  });

  /**
   * @scenario surface=view_panel, icon_type=legacy_string
   * @effects legacy_icon_string_still_renders
   */
  it('spec 이 있으면 icon 문자열보다 spec 이 이긴다', () => {
    const { container } = render(<MenuIcon spec={{ type: 'class', value: 'fas fa-users' }} icon="fas fa-bell" />);

    expect(container.querySelector('i')?.className).toBe('fas fa-users');
  });

  /**
   * @scenario surface=view_panel, icon_type=legacy_string
   * @effects legacy_icon_string_still_renders, invalid_icon_falls_back
   */
  it('spec·icon 이 모두 없으면 fallbackIcon 을, fallbackIcon 도 없으면 아무것도 그리지 않는다', () => {
    const withFallback = render(<MenuIcon spec={null} icon={null} {...VIEW_PANEL} />);
    expect(withFallback.container.querySelector('i')?.className).toBe('fas fa-file text-base text-secondary');
    withFallback.unmount();

    const without = render(<MenuIcon spec={null} icon="" />);
    expect(without.container.innerHTML).toBe('');
  });
});

describe('resolveMenuIconSpec · hasMenuIcon · MENU_ICON_CLASS_PATTERN', () => {
  /**
   * @scenario surface=view_panel, icon_type=legacy_string
   * @effects legacy_icon_string_still_renders
   */
  it('resolveMenuIconSpec 는 spec 우선, 없으면 공백을 걷은 icon 을 class 로 돌려준다', () => {
    const spec: MenuIconSpecData = { type: 'svg', value: '<svg/>' };

    expect(resolveMenuIconSpec(spec, 'fas fa-bell')).toBe(spec);
    expect(resolveMenuIconSpec(null, '  fas fa-bell  ')).toEqual({ type: 'class', value: 'fas fa-bell' });
    expect(resolveMenuIconSpec(undefined, undefined)).toBeNull();
    expect(resolveMenuIconSpec(null, '   ')).toBeNull();
    // type 이 문자열이 아니면 spec 으로 보지 않는다
    expect(resolveMenuIconSpec({ value: 'x' } as unknown as MenuIconSpecData, 'home')).toEqual({ type: 'class', value: 'home' });
  });

  /**
   * @scenario surface=view_panel, icon_type=legacy_string
   * @effects legacy_icon_string_still_renders
   */
  it('hasMenuIcon 은 그릴 아이콘이 있을 때만 true 다', () => {
    expect(hasMenuIcon({ type: 'image', url: '/a.png' }, null)).toBe(true);
    expect(hasMenuIcon(null, 'fas fa-home')).toBe(true);
    expect(hasMenuIcon(null, null)).toBe(false);
    expect(hasMenuIcon(undefined, '')).toBe(false);
  });

  /**
   * @scenario surface=view_panel, icon_type=class
   * @effects invalid_icon_falls_back
   */
  it('MENU_ICON_CLASS_PATTERN 은 소문자·숫자·하이픈 토큰을 공백으로 이은 값만 받는다', () => {
    for (const ok of ['fas fa-users', 'fa-solid fa-pen-to-square', 'home', 'fas fa-spinner fa-spin', 'fa-2x']) {
      expect(MENU_ICON_CLASS_PATTERN.test(ok), ok).toBe(true);
    }
    for (const bad of ['fas fa-<x>', 'fas "x"', 'fa--x', '-fa', 'fas fa-x;', 'fas fa-x/y', '']) {
      expect(MENU_ICON_CLASS_PATTERN.test(bad), bad).toBe(false);
    }
  });
});
