/**
 * PreviewCanvas — frame 클리핑 컨텍스트 회귀 테스트
 *
 * 회귀 원인: 어포던스 오버레이를 frame 의 형제 레이어로 분리하면서
 * `transform: scale()` 을 새 래퍼 `g7le-preview-frame-wrapper` 로 이동시켰고,
 * frame 에는 `overflow: hidden` 만 남았다. 모바일 드로어(`mobile_nav_drawer`)는
 * 닫힌 상태에서 `position: fixed` + `translate-x-full` 로 렌더되는데,
 * `position: fixed` 자손의 containing block 은 가장 가까운 transform 보유 조상이다.
 * transform 이 wrapper 로 빠지면서 드로어의 containing block 이 frame → wrapper 로
 * 바뀌어, frame 의 `overflow: hidden` 이 더 이상 드로어를 클리핑하지 못해
 * 편집기 모바일 프리뷰 우측에 닫힌 메뉴 드로어가 노출되는 회귀가 발생했다.
 *
 * 클리핑이 동작하려면 frame 이 (1) 자체적으로 transform 을 보유해 fixed 자손의
 * containing block 이 되고 (2) overflow:hidden 으로 frame 밖 자손을 잘라야 한다.
 * 본 테스트는 frame 요소가 이 두 CSS 계약을 동시에 만족함을 가드한다.
 * (jsdom 은 레이아웃을 계산하지 않으므로 인라인 스타일 계약으로 검증한다.)
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import React from 'react';
import { render, waitFor, cleanup } from '@testing-library/react';
import { LayoutEditorProvider, useLayoutEditor } from '../../LayoutEditorContext';
import { EditorModalProvider } from '../../EditorModalContext';
import { PreviewCanvas } from '../../components/PreviewCanvas';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';
import { ComponentRegistry } from '../../../ComponentRegistry';

/** 마운트 시 selectedRoute 를 강제 설정하는 테스트 헬퍼 */
function SelectRoute({ path, layoutName }: { path: string; layoutName: string }): null {
  const { dispatch } = useLayoutEditor();
  React.useEffect(() => {
    dispatch({ type: 'SELECT_ROUTE', route: { path, layoutName } });
  }, [dispatch, path, layoutName]);
  return null;
}

describe('PreviewCanvas — frame 클리핑 컨텍스트(fixed 드로어 노출 방지)', () => {
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
      React.createElement(
        TranslationProvider,
        {
          translationEngine: engine,
          translationContext: { templateId: 'sirsoft-admin_basic', locale: 'ko' },
        },
        React.createElement(
          LayoutEditorProvider,
          { templateIdentifier: 'sirsoft-basic', initialLocale: 'ko' },
          React.createElement(
            EditorModalProvider,
            null,
            React.createElement(SelectRoute, { path, layoutName }),
            React.createElement(PreviewCanvas),
          ),
        ),
      ),
    );
  }

  it('frame 은 overflow:hidden + 자체 transform 을 동시에 보유해 fixed 자손을 클리핑한다', async () => {
    installFetchMock();
    const { findByTestId } = renderCanvas('/', 'home');

    const frame = (await findByTestId('g7le-preview-frame')) as HTMLElement;

    await waitFor(() => {
      expect(frame.style.overflow).toBe('hidden');
    });

    // frame 자체가 transform 을 보유해야 position:fixed 자손의 containing block 이
    // 되고, 그래야 frame 의 overflow:hidden 이 닫힌 드로어를 클리핑한다.
    // transform 이 비어 있거나 'none' 이면 containing block 이 상위 transform 조상
    // (wrapper)으로 올라가 클리핑이 무력화되는 회귀 상태다.
    expect(frame.style.transform).toBeTruthy();
    expect(frame.style.transform).not.toBe('none');
  });
});
