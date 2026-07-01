/**
 * PreviewCanvas — `$locales` 엔진 전역 lift 렌더 회귀 테스트
 *
 * 결함: 편집기 미리보기는 TemplateApp.init() 을 거치지 않아 런타임의
 * createGlobalVariables() 가 주입하는 `$locales`(시스템 활성 언어 목록)가 비어
 * 있었다. 로그인 화면 등의 로케일 선택 드롭다운이 `options="{{$locales}}"` 를
 * 바인딩하므로 캔버스에서 빈 채로 렌더됐다.
 *
 * 수정: editor-spec sampleGlobal 에 `$locale`/`$locales` 를 샘플 데이터로 선언하고
 * PreviewCanvas 가 병합 시드의 `$`-prefixed 키를 렌더 컨텍스트 최상위로 끌어올린다.
 *
 * 본 테스트는 sampleGlobal 소스에 `$locales` 를 주입한 뒤, 캔버스 레이아웃의
 * `{{$locales}}` 바인딩이 실제로 그 값으로 해석되어 화면에 렌더됨을 검증한다
 * (사용자 가시 결과 — 데이터/상태 확인만으로 완료 단정 금지 규율).
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import React from 'react';
import { render, waitFor, cleanup, screen } from '@testing-library/react';
import { LayoutEditorProvider, useLayoutEditor } from '../../LayoutEditorContext';
import { EditorModalProvider } from '../../EditorModalContext';
import { PreviewCanvas } from '../../components/PreviewCanvas';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';
import { ComponentRegistry } from '../../../ComponentRegistry';

// 편집 대상 자산 부트로더를 mock — 실제 IIFE 번들 로드 없이 `Span` probe 컴포넌트만
// 등록한 격리 registry 를 반환한다. probe 는 children 바인딩 결과를 그대로 렌더하므로
// `{{$locales?.length}}` 가 lift 되었는지 화면 텍스트로 검증할 수 있다.
vi.mock('../../hooks/useEditorTemplateAssets', () => {
  return {
    useEditorTemplateAssets: () => {
      const registry = ComponentRegistry.createIsolatedInstance();
      const Span = (props: any) =>
        React.createElement('span', { 'data-testid': props['data-testid'] }, props.children);
      (registry as any).registry.Span = { component: Span, metadata: { name: 'Span', type: 'basic' } };
      return {
        componentRegistry: registry,
        translationEngine: TranslationEngine.getInstance(),
        isReady: true,
        error: null,
      };
    },
  };
});

function SelectRoute({ path, layoutName }: { path: string; layoutName: string }): null {
  const { dispatch } = useLayoutEditor();
  React.useEffect(() => {
    dispatch({ type: 'SELECT_ROUTE', route: { path, layoutName } });
  }, [dispatch, path, layoutName]);
  return null;
}

describe('PreviewCanvas — $locales 엔진 전역 lift', () => {
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
            data: {
              // `{{$locales}}` 가 lift 되면 길이 2 가 텍스트로 렌더된다.
              // Span(코어 basic 컴포넌트)으로 가시 출력 — 로케일 드롭다운과 동일한 최상위 바인딩.
              components: [
                {
                  type: 'basic',
                  name: 'Span',
                  // text 는 DynamicRenderer 가 바인딩 평가 후 children 으로 전달한다.
                  text: '{{$locales?.length ?? 0}}',
                  props: { 'data-testid': 'locale-count' },
                },
              ],
            },
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
    installFetchMock();
  });

  afterEach(() => {
    cleanup();
    (window as any).G7Core = originalG7Core;
    delete (window as any).SirsoftBasic;
    delete (window as any).__templateApp;
    ComponentRegistry.resetInstance();
  });

  // sampleGlobal 에 `$locale`/`$locales` 를 샘플 데이터로 선언한 소스 (런타임에서
  // Chrome 이 loadEditorSpecBundle 결과로 주입하는 editor-spec.json.sampleGlobal 과 동형).
  const sampleGlobalSources = [
    {
      id: 'sirsoft-basic',
      kind: 'template' as const,
      sampleGlobal: {
        $locale: 'ko',
        $locales: ['ko', 'en'],
        currentUser: { uuid: 'u', name: '샘플', is_admin: false },
        settings: { general: { site_name: '샘플 사이트' } },
      },
    },
  ];

  function renderCanvas() {
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
            React.createElement(SelectRoute, { path: '/login', layoutName: 'auth/login' }),
            React.createElement(PreviewCanvas, { sampleGlobalSources }),
          ),
        ),
      ),
    );
  }

  it('sampleGlobal 의 $locales 가 렌더 컨텍스트 최상위로 lift 되어 {{$locales}} 바인딩이 해석된다', async () => {
    renderCanvas();

    await waitFor(
      () => {
        const el = screen.queryByTestId('locale-count');
        expect(el).not.toBeNull();
        // $locales 가 비어 있으면 '0' — lift 성공 시 길이 2 가 렌더된다.
        expect(el!.textContent).toBe('2');
      },
      { timeout: 3000 },
    );
  });
});
