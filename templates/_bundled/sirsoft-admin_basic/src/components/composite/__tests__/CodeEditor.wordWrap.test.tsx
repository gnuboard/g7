import React from 'react';
import { render, screen, act } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { CodeEditor } from '../CodeEditor';

/**
 * CodeEditor — 자동 줄바꿈(D10) · 문서 테마 추종(D8) · 폴백 계약.
 *
 * 종전에는 줄바꿈이 없었고(모바일에서 가로 스크롤로만 코드를 읽어야 했다) 폴백 textarea 는
 * `wrap="off"` 고정이었다. 테마는 `vs-dark` 하드코딩이라 라이트 모드에서도 편집기만 검은 판이었다.
 */

/** 편집기에 실제로 전달된 옵션 — 대역이 여기에 담아 둔다. */
let lastOptions: Record<string, unknown> = {};

/** Monaco 로더 대역 — 실패 경로를 잴 때만 거부로 바꾼다. */
let loaderInit: () => Promise<unknown> = () => Promise.resolve({});

vi.mock('@monaco-editor/react', () => ({
  default: ({ defaultValue: value, onChange, theme, options }: any) => {
    lastOptions = options ?? {};

    return (
      <textarea
        data-testid="editor-textarea"
        data-theme={theme}
        data-word-wrap={String(options?.wordWrap ?? '')}
        value={value ?? ''}
        onChange={(event) => onChange?.(event.target.value)}
      />
    );
  },
  loader: { init: () => loaderInit(), config: vi.fn() },
}));

beforeEach(() => {
  lastOptions = {};
  loaderInit = () => Promise.resolve({});
  document.documentElement.classList.remove('dark');
});

afterEach(() => {
  document.documentElement.classList.remove('dark');
});

describe('CodeEditor — 자동 줄바꿈', () => {
  /** @effects code_editor_word_wrap_defaults_to_off */
  it('기본은 끔 — 데스크톱에서 들여쓰기가 무너지지 않게', () => {
    render(<CodeEditor value="x" />);

    expect(lastOptions.wordWrap).toBe('off');
  });

  /** @effects code_editor_word_wrap_maps_to_monaco_option */
  it('wordWrap 을 켜면 Monaco 옵션으로 전달된다', () => {
    render(<CodeEditor value="x" wordWrap />);

    expect(lastOptions.wordWrap).toBe('on');
  });

  /** @effects code_editor_font_size_is_configurable */
  it('글자 크기를 옵션으로 넘긴다', () => {
    render(<CodeEditor value="x" fontSize={13} />);

    expect(lastOptions.fontSize).toBe(13);
  });
});

describe('CodeEditor — 테마', () => {
  /**
   * 다크 문서에서 **처음 뜰 때부터** 다크다 — 한 프레임이라도 흰 판이 번쩍이지 않는다.
   *
   * @effects code_editor_auto_theme_follows_dark_class
   */
  it('auto 는 마운트 시점의 dark 클래스를 곧바로 반영한다', () => {
    document.documentElement.classList.add('dark');

    render(<CodeEditor value="x" theme="auto" />);

    expect(screen.getByTestId('editor-textarea')).toHaveAttribute('data-theme', 'vs-dark');
  });

  /**
   * 테마 전환은 **관측**으로 따라간다 — 사용자가 토글하는 순간 편집기도 함께 바뀌어야 한다.
   *
   * @effects code_editor_auto_theme_reacts_without_rerender
   */
  it('부모 재렌더 없이도 테마 전환을 따라간다', async () => {
    render(<CodeEditor value="x" theme="auto" />);

    expect(screen.getByTestId('editor-textarea')).toHaveAttribute('data-theme', 'vs-light');

    await act(async () => {
      document.documentElement.classList.add('dark');
      // MutationObserver 는 마이크로태스크로 전달된다.
      await Promise.resolve();
    });

    expect(screen.getByTestId('editor-textarea')).toHaveAttribute('data-theme', 'vs-dark');
  });
});

describe('CodeEditor — 폴백 textarea', () => {
  /**
   * Monaco 를 못 불러오면 textarea 로 내려앉는다(저장 계약 유지). 그 textarea 도 줄바꿈 토글을
   * 따라야 한다 — 종전에는 `wrap="off"` 고정이라 모바일에서 가로로만 읽혔다(D10).
   *
   * @effects code_editor_fallback_textarea_follows_word_wrap
   */
  it('Monaco 로드 실패 시의 textarea 폴백도 줄바꿈 토글을 따른다', async () => {
    loaderInit = () => Promise.reject(new Error('monaco unavailable'));

    const { rerender } = render(<CodeEditor value="x" />);

    const fallback = await screen.findByTestId('code-editor-fallback');

    expect(fallback).toHaveAttribute('wrap', 'off');

    rerender(<CodeEditor value="x" wordWrap />);

    expect(screen.getByTestId('code-editor-fallback')).toHaveAttribute('wrap', 'soft');
  });
});
