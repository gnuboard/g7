/**
 * LayoutEditorChrome — 툴바 「두 줄로 감기」 회귀 (설계 확정 2026-11-08)
 *
 * 배경: 툴바 자연 폭은 한국어 기준 1506px(격리 크로미움 실측 — 항목 1378 + gap 17×6 + 안쪽 여백 24)이라
 * 셸 최소 너비 1280 고정에서도 창 1280~1520 에서 툴바가 셸 밖으로 넘쳐 가로 스크롤이 생겼다. 이제 셸 폭이
 * 툴바 자연 폭보다 좁으면 툴바와 기기 툴바를 줄 단위로 감는다. 항목은 줄이지 않는다(50412256f3 의
 * 「요 소 추 가」 차단 유지). 셸 최소 너비는 1000(창 1024 − 세로 스크롤바)이다.
 *
 * 감기면 헤더가 높아지고, 캔버스 최소 높이(`calc(100vh - var(--g7le-header-h))`)와 트리 sticky 기준이 그
 * 높이를 따라 줄어든다. jsdom 은 레이아웃이 없어 헤더 높이와 ResizeObserver 를 대역으로 준다.
 */

import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
import { render, screen, cleanup, act } from '@testing-library/react';
import { LayoutEditorChrome, EDITOR_MIN_WIDTH } from '../../LayoutEditorChrome';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';

const observers: Array<{ cb: ResizeObserverCallback; targets: Element[] }> = [];
class FakeResizeObserver {
  private entry: { cb: ResizeObserverCallback; targets: Element[] };
  constructor(cb: ResizeObserverCallback) {
    this.entry = { cb, targets: [] };
    observers.push(this.entry);
  }
  observe(el: Element): void {
    this.entry.targets.push(el);
  }
  unobserve(): void {}
  disconnect(): void {
    this.entry.targets = [];
  }
}

function renderShell(): void {
  render(
    <TranslationProvider
      translationEngine={new TranslationEngine()}
      translationContext={{ templateId: 'test', locale: 'ko' }}
    >
      <LayoutEditorChrome templateIdentifier="sirsoft-admin_basic" initialLocale="ko" />
    </TranslationProvider>,
  );
}

let originalRO: unknown;
beforeEach(() => {
  observers.length = 0;
  originalRO = (globalThis as any).ResizeObserver;
  (globalThis as any).ResizeObserver = FakeResizeObserver;
  (window as any).ResizeObserver = FakeResizeObserver;
});

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
  (globalThis as any).ResizeObserver = originalRO;
  (window as any).ResizeObserver = originalRO;
});

describe('[case:wysiwyg-11] 툴바 두 줄로 감기', () => {
  /** @effects editor_toolbar_wraps_lines_without_squish */
  it('셸 최소 너비는 1000(창 1024 − 세로 스크롤바), 툴바·기기 줄·기기 툴바는 줄 단위로 감긴다', () => {
    renderShell();
    expect(EDITOR_MIN_WIDTH).toBe(1000);
    const chrome = screen.getByTestId('g7le-chrome') as HTMLElement;
    expect(chrome.style.minWidth).toBe('1000px');
    expect((screen.getByTestId('g7le-toolbar') as HTMLElement).style.flexWrap).toBe('wrap');
    expect((screen.getByTestId('g7le-chrome-device-toolbar') as HTMLElement).style.flexWrap).toBe('wrap');
    const device = screen.getByTestId('g7le-device-toolbar') as HTMLElement;
    expect(device.style.flexWrap).toBe('wrap');
    expect(device.style.maxWidth).toBe('100%');
  });

  /** @effects editor_toolbar_wraps_lines_without_squish */
  it('감겨도 항목은 줄지 않는다 — 툴바·기기 줄·기기 툴바 직접 자식 모두 flex-shrink:0', () => {
    renderShell();
    const styles = Array.from(document.querySelectorAll('style')).map((s) => s.textContent ?? '');
    expect(styles.some((t) => t.includes('.g7le-toolbar > *') && t.includes('flex-shrink: 0') && t.includes('white-space: nowrap'))).toBe(true);
    expect(styles.some((t) => t.includes('.g7le-chrome__device-toolbar > *') && t.includes('flex-shrink: 0'))).toBe(true);
    expect(styles.some((t) => t.includes('.g7le-device-toolbar > *') && t.includes('flex-shrink: 0') && t.includes('white-space: nowrap'))).toBe(true);
  });

  /** @effects editor_canvas_height_follows_wrapped_header */
  it('헤더가 두 줄로 높아지면 --g7le-header-h 가 그 높이를 따라 캔버스 최소 높이와 트리 sticky 기준이 줄어든다', () => {
    let headerHeight = 88; // 한 줄
    vi.spyOn(Element.prototype, 'getBoundingClientRect').mockImplementation(function (this: Element) {
      const h = (this as HTMLElement).dataset?.testid === 'g7le-chrome-header' ? headerHeight : 0;
      return { left: 0, top: 0, width: 0, height: h, right: 0, bottom: h, x: 0, y: 0, toJSON: () => ({}) } as DOMRect;
    });
    renderShell();
    const chrome = screen.getByTestId('g7le-chrome') as HTMLElement;
    expect(chrome.style.getPropertyValue('--g7le-header-h')).toBe('88px');

    headerHeight = 132; // 툴바가 두 줄로 감김
    const header = screen.getByTestId('g7le-chrome-header');
    act(() => {
      for (const o of observers) if (o.targets.includes(header)) o.cb([], o as unknown as ResizeObserver);
    });
    expect(chrome.style.getPropertyValue('--g7le-header-h')).toBe('132px');
  });
});
