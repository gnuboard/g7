/**
 * useHeaderOverlayController — 맨 위 배너의 메뉴 겹침 신호(`<html data-header-overlay>`)를 따라 헤더 높이(`--g7-header-height`)를 알리고
 * 화면 맨 위에서만 `data-header-overlay-active` 를 단다. 신호가 없으면 아무것도 바꾸지 않는다(종전 화면). (#810)
 * @scenario-manifest tests/scenarios/hero-extension-points.yaml
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, afterEach, beforeEach } from 'vitest';
import { render, cleanup, act } from '@testing-library/react';
import { useHeaderOverlayController } from '../headerOverlay';

const root = document.documentElement;

/** 컨트롤러만 돌리는 시험 부품 — 헤더 자리(.g7-site-header)를 함께 그린다 */
const Host: React.FC = () => {
  useHeaderOverlayController();
  return <header className="g7-site-header" />;
};

/** MutationObserver 콜백(마이크로태스크)까지 흘려보낸다 */
const flush = () => act(async () => { await Promise.resolve(); });

/** 화면 스크롤 위치를 바꾸고 scroll 이벤트를 낸다 */
const scrollTo = (y: number) => act(() => {
  Object.defineProperty(window, 'scrollY', { value: y, configurable: true });
  window.dispatchEvent(new Event('scroll'));
});

describe('useHeaderOverlayController', () => {
  beforeEach(() => {
    Object.defineProperty(window, 'scrollY', { value: 0, configurable: true });
    HTMLElement.prototype.getBoundingClientRect = function () {
      return { height: this.classList.contains('g7-site-header') ? 114 : 0, width: 0, top: 0, left: 0, right: 0, bottom: 0, x: 0, y: 0, toJSON: () => ({}) } as DOMRect;
    };
  });

  afterEach(() => {
    cleanup();
    root.removeAttribute('data-header-overlay');
    root.removeAttribute('data-header-overlay-active');
    root.style.removeProperty('--g7-header-height');
  });

  // @scenario surface=header_overlay_no_signal
  // @effects header_unchanged_without_signal
  it('신호가 없으면 높이도 투명 상태도 달지 않는다', () => {
    render(<Host />);
    expect(root.style.getPropertyValue('--g7-header-height')).toBe('');
    expect(root.hasAttribute('data-header-overlay-active')).toBe(false);
  });

  // @scenario surface=header_overlay_signal_top_scroll
  // @effects header_overlays_banner, scroll_restores_header
  it('신호가 오면 헤더 높이를 알리고 맨 위에서만 바탕 밝기대로 투명하게, 내리면 풀고 다시 올리면 켠다', async () => {
    render(<Host />);
    root.setAttribute('data-header-overlay', 'dark');
    await flush();
    expect(root.style.getPropertyValue('--g7-header-height')).toBe('114px');
    expect(root.getAttribute('data-header-overlay-active')).toBe('dark');

    scrollTo(200);
    expect(root.hasAttribute('data-header-overlay-active')).toBe(false);
    // 높이는 내려도 남는다 — 배너 자리는 스크롤과 관계없이 메뉴 밑으로 당겨진 채다
    expect(root.style.getPropertyValue('--g7-header-height')).toBe('114px');
    scrollTo(5);
    expect(root.getAttribute('data-header-overlay-active')).toBe('dark');

    root.setAttribute('data-header-overlay', 'light');
    await flush();
    expect(root.getAttribute('data-header-overlay-active')).toBe('light');
  });

  // @scenario surface=header_overlay_signal_removed
  it('신호가 사라지거나 모르는 값이면 높이·투명 상태를 지우고, 헤더가 사라져도 지운다', async () => {
    const { unmount } = render(<Host />);
    root.setAttribute('data-header-overlay', 'dark');
    await flush();
    root.setAttribute('data-header-overlay', 'neon');
    await flush();
    expect(root.hasAttribute('data-header-overlay-active')).toBe(false);
    expect(root.style.getPropertyValue('--g7-header-height')).toBe('');

    root.setAttribute('data-header-overlay', 'dark');
    await flush();
    expect(root.getAttribute('data-header-overlay-active')).toBe('dark');
    unmount();
    expect(root.hasAttribute('data-header-overlay-active')).toBe(false);
    expect(root.style.getPropertyValue('--g7-header-height')).toBe('');
  });
});
