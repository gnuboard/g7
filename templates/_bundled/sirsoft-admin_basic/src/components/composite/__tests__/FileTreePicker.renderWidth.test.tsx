/**
 * FileTreePicker — `mode="auto"` 는 렌더 폭을 따른다 (이슈 #141 D4)
 *
 * 브라우저 창 폭이 아니라 **렌더 폭**(`G7Core.useResponsive()`)으로 인라인·서랍·시트를 고른다. 레이아웃
 * 편집기 미리보기는 iframe 이 아니라 같은 문서의 div 라서, 창 폭은 미리보기 기기 폭과 무관하다 —
 * 창 1440·모바일 미리보기 390 에서 인라인 트리가 그려졌다. 훅이 없는 환경에서만 창 폭으로 폴백한다.
 */

import React from 'react';
import { render, screen, fireEvent, act } from '@testing-library/react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { FileTreePicker } from '../FileTreePicker';
import type { FileTreeNode } from '../FileTree';

const NODES: FileTreeNode[] = [
  { key: 'layout:admin_dashboard', kind: 'layout', path: 'admin_dashboard', label: 'admin_dashboard' },
];

describe('FileTreePicker — mode=auto 는 렌더 폭을 따른다 (#141)', () => {
  let originalG7Core: any;
  let originalWidth: number;

  const setWindowWidth = (width: number) => {
    Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: width });
  };

  /** 닫힌 상태의 형태 판정: 인라인=separator 있음, 서랍/시트=여는 버튼 → 열어서 애니메이션 클래스로 구분 */
  const shape = (): 'inline' | 'drawer' | 'sheet' => {
    if (screen.queryByRole('separator')) return 'inline';
    fireEvent.click(screen.getByTestId('file-tree-picker-open'));
    const overlay = screen.getByTestId('file-tree-overlay');
    return overlay.className.includes('g7le-sheet-enter') ? 'sheet' : 'drawer';
  };

  beforeEach(() => {
    originalG7Core = (window as any).G7Core;
    originalWidth = window.innerWidth;
  });

  afterEach(() => {
    (window as any).G7Core = originalG7Core;
    setWindowWidth(originalWidth);
  });

  /**
   * @scenario component=FileTreePicker, window_width=1440, render_width=390|820|1280
   * @effects preview_component_width_branch_follows_render_width
   */
  it.each([
    [390, 'sheet'],
    [820, 'drawer'],
    [1280, 'inline'],
  ])('창 1440 · 렌더 폭 %i → %s', (renderWidth, expected) => {
    setWindowWidth(1440);
    (window as any).G7Core = { ...originalG7Core, useResponsive: vi.fn(() => ({ width: renderWidth })) };

    render(<FileTreePicker mode="auto" nodes={NODES} selectedKey="layout:admin_dashboard" />);

    expect(shape()).toBe(expected);
  });

  /**
   * @scenario component=FileTreePicker, use_responsive=absent, window_width=390→1440
   * @effects preview_component_width_falls_back_to_window_without_hook
   */
  it('훅이 없으면 창 폭으로 폴백하고 resize 를 따른다', () => {
    setWindowWidth(390);
    (window as any).G7Core = { ...originalG7Core, useResponsive: undefined };

    render(<FileTreePicker mode="auto" nodes={NODES} selectedKey="layout:admin_dashboard" />);
    expect(shape()).toBe('sheet');

    act(() => {
      setWindowWidth(1440);
      window.dispatchEvent(new Event('resize'));
    });
    expect(screen.queryByRole('separator')).not.toBeNull();
  });
});
