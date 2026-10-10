/**
 * CodeWorkspace — 기기 기본값은 렌더 폭을 따른다 (이슈 #141 D5)
 *
 * 기기 기본값(글자 크기 13/14·기본 줄바꿈)은 브라우저 창 폭이 아니라 **렌더 폭**(`G7Core.useResponsive()`)
 * 으로 정한다. 레이아웃 편집기 미리보기는 같은 문서의 div 라서, 창 1440 · 모바일 미리보기 390 에서
 * 데스크톱 값(14px)이 쓰였다. 훅이 없을 때 창 폭 폴백은 `CodeWorkspace.buffers.test.tsx` 가 잠근다.
 */

import React from 'react';
import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { CodeWorkspace } from '../CodeWorkspace';

// Monaco 대역 — 글자 크기만 드러내면 충분하다
vi.mock('@monaco-editor/react', () => ({
  default: ({ defaultValue: value, options }: any) => (
    <textarea data-testid="editor-textarea" data-font-size={String(options?.fontSize ?? '')} value={value ?? ''} readOnly />
  ),
  DiffEditor: () => <div data-testid="diff-editor" />,
  loader: { init: () => Promise.resolve({}), config: vi.fn() },
}));

describe('CodeWorkspace — 기기 기본값은 렌더 폭을 따른다 (#141)', () => {
  let originalG7Core: any;
  let originalWidth: number;

  beforeEach(() => {
    window.localStorage.clear();
    originalG7Core = (window as any).G7Core;
    originalWidth = window.innerWidth;
  });

  afterEach(() => {
    (window as any).G7Core = originalG7Core;
    Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: originalWidth });
  });

  /**
   * @scenario component=CodeWorkspace, window_width=1440, render_width=390|1280
   * @effects preview_component_width_branch_follows_render_width
   */
  it.each([
    [390, '13', 'true'],
    [1280, '14', 'false'],
  ])('창 1440 · 렌더 폭 %i → 글자 %spx, 기본 줄바꿈 %s', (renderWidth, fontSize, wrap) => {
    Object.defineProperty(window, 'innerWidth', { configurable: true, writable: true, value: 1440 });
    (window as any).G7Core = { ...originalG7Core, useResponsive: vi.fn(() => ({ width: renderWidth })) };

    render(<CodeWorkspace fileKey="layout:a" serverValue={'<layout name="a"/>'} serverRevision={1} />);

    expect(screen.getByTestId('editor-textarea')).toHaveAttribute('data-font-size', fontSize);
    expect(screen.getByTestId('word-wrap-toggle')).toHaveAttribute('aria-pressed', wrap);
  });
});
