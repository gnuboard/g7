/**
 * PreviewCanvas — 프리뷰 프레임의 색상 테마 중립 표식
 *
 * 확장 CSS 는 템플릿 `.dark` 가 아니라 코어 표식 `data-g7-color-scheme` 만 본다(engine-v1.87.0).
 * 관리자 화면이 다크여도 프리뷰를 라이트로 두면 프리뷰 안 확장 화면은 라이트여야 하므로, 프레임이
 * 프리뷰 테마를 같은 표식으로 단다. 다크 규격의 「다크 안의 라이트 제외」 꼬리가 이 표식에 기댄다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import React from 'react';
import { render, waitFor, cleanup, act } from '@testing-library/react';
import { LayoutEditorProvider, useLayoutEditor } from '../../LayoutEditorContext';
import { EditorModalProvider } from '../../EditorModalContext';
import { PreviewCanvas } from '../../components/PreviewCanvas';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';
import { ComponentRegistry } from '../../../ComponentRegistry';

let dispatchRef: ((action: any) => void) | null = null;

/** 마운트 시 selectedRoute 를 설정하고 dispatch 를 밖으로 내보내는 테스트 헬퍼 */
function SelectRoute({ path, layoutName }: { path: string; layoutName: string }): null {
  const { dispatch } = useLayoutEditor();
  dispatchRef = dispatch;
  React.useEffect(() => {
    dispatch({ type: 'SELECT_ROUTE', route: { path, layoutName } });
  }, [dispatch, path, layoutName]);
  return null;
}

describe('PreviewCanvas — 프레임 data-g7-color-scheme', () => {
  let originalG7Core: any;
  function installFetchMock() {
    (global as any).fetch = vi.fn(async (url: string) => {
      if (url.includes('/editor-assets')) {
        return {
          ok: true,
          json: async () => ({
            data: { identifier: 'sirsoft-basic', js: [], css: [], manifest_present: true },
          }),
        };
      }
      if (url.includes('/components.json')) {
        return {
          ok: true,
          json: async () => ({
            version: '1.0.0',
            templateId: 'sirsoft-basic',
            components: { basic: [], composite: [], layout: [] },
          }),
        };
      }
      if (url.includes('/lang/')) {
        return { ok: true, json: async () => ({}) };
      }
      if (url.includes('/api/layouts/')) {
        return {
          ok: true,
          json: async () => ({
            data: { components: [{ type: 'basic', name: 'Div', props: {} }] },
          }),
        };
      }
      return { ok: false, status: 404, json: async () => ({}) };
    });
  }

  beforeEach(() => {
    ComponentRegistry.resetInstance();
    originalG7Core = (window as any).G7Core;
    (window as any).G7Core = { t: (k: string) => k };
    (window as any).SirsoftBasic = {};
  });

  afterEach(() => {
    cleanup();
    (window as any).G7Core = originalG7Core;
    delete (window as any).SirsoftBasic;
    delete (window as any).__templateApp;
    ComponentRegistry.resetInstance();
  });

  function renderCanvas(path: string, layoutName: string) {
    const engine = new TranslationEngine();
    return render(
      React.createElement(TranslationProvider, {
        translationEngine: engine,
        translationContext: { templateId: 'sirsoft-admin_basic', locale: 'ko' },
        children: React.createElement(LayoutEditorProvider, {
          templateIdentifier: 'sirsoft-basic',
          initialLocale: 'ko',
          children: React.createElement(
            EditorModalProvider,
            null,
            React.createElement(SelectRoute, { path, layoutName }),
            React.createElement(PreviewCanvas),
          ),
        }),
      }),
    );
  }

  // @scenario case=preview_scheme_c01
  // @effects preview_frame_carries_preview_scheme_marker
  it('프리뷰 테마를 바꾸면 프레임 표식이 매번 따라간다(관리자 html 이 다크여도)', async () => {
    installFetchMock();
    document.documentElement.setAttribute('data-g7-color-scheme', 'dark');
    const { findByTestId } = renderCanvas('/', 'home');
    const frame = (await findByTestId('g7le-preview-frame')) as HTMLElement;

    expect(frame.getAttribute('data-g7-color-scheme')).toBe('light');

    const seen: Array<string | null> = [];
    for (const scheme of ['dark', 'light', 'dark'] as const) {
      act(() => dispatchRef?.({ type: 'SET_PREVIEW_COLOR_SCHEME', scheme }));
      await waitFor(() => expect(frame.getAttribute('data-g7-color-scheme')).toBe(scheme));
      seen.push(frame.getAttribute('data-g7-color-scheme'));
    }

    expect(seen).toEqual(['dark', 'light', 'dark']);
    document.documentElement.removeAttribute('data-g7-color-scheme');
  });
});
