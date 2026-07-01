/**
 * PreviewCanvas — guest_only 레이아웃 currentUser 시드 제외 회귀 테스트
 *
 * 회귀 원인: 결함 D' 보강에서 도입한 `coreSampleGlobalSeed` 가 편집 모드 격리
 * store 의 `_global.currentUser` 에 로그인된 샘플 사용자(uuid 보유) 를 무조건
 * 주입했다. `meta.guest_only: true` 레이아웃(로그인/회원가입/비밀번호 찾기 등)은
 * `_redirect_if_logged_in` 류 가드 partial 이
 * `{{_global?.currentUser?.uuid && !_local.isLoginAction}}` 조건으로 onMount 에서
 * "이미 로그인되어 있습니다" 토스트 + 홈 리다이렉트를 발화한다. 편집기 진입 시
 * 이 가드가 항상 발화해 비밀번호 찾기 등 게스트 전용 화면 편집이 토스트로
 * 오염되는 결함이 발생했다.
 *
 * 본 테스트는 PreviewCanvas 가 guest_only 레이아웃을 로드하면 격리 store 의
 * `_global.currentUser` 가 시드되지 않아(비로그인 분기) 가드가 발화하지 않음을
 * 가드한다. 일반 레이아웃(guest_only 아님)은 currentUser 가 그대로 시드됨도
 * 함께 검증한다.
 *
 * S6-2 정정: 코어 `coreSampleGlobalSeed` 가 빈 객체로 폐기되고
 * currentUser/settings 시드는 **템플릿 editor-spec.json.sampleGlobal** 이 제공한다.
 * 따라서 본 테스트는 PreviewCanvas 에 템플릿 sampleGlobal 소스를 주입(런타임에서
 * Chrome 이 loadEditorSpecBundle 결과로 주입하는 것과 동형)해 currentUser/settings
 * baseline 을 구성하고, guest_only 가 그 currentUser 를 최종 결과에서 제외함을 검증한다.
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

describe('PreviewCanvas — guest_only currentUser 시드 제외', () => {
  let originalG7Core: any;

  function installFetchMock(layoutMeta: Record<string, unknown> | undefined) {
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
              components: [{ type: 'basic', name: 'Div', props: {} }],
              ...(layoutMeta ? { meta: layoutMeta } : {}),
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
  });

  afterEach(() => {
    cleanup();
    (window as any).G7Core = originalG7Core;
    delete (window as any).SirsoftBasic;
    delete (window as any).__templateApp;
    ComponentRegistry.resetInstance();
  });

  // 템플릿 sampleGlobal 소스 — 런타임에서 Chrome 이 loadEditorSpecBundle 결과로 주입하는
  // sirsoft-basic editor-spec.json.sampleGlobal 과 동형. 코어 시드 폐기 후
  // currentUser/settings 는 본 템플릿 소스가 제공한다.
  const templateSampleGlobalSources = [
    {
      id: 'sirsoft-basic',
      kind: 'template' as const,
      sampleGlobal: {
        currentUser: { uuid: 'sample-uuid-current-user', name: '샘플 사용자', is_admin: false },
        settings: { general: { site_name: '샘플 사이트' }, site_name: '샘플 사이트' },
        site: { name: '샘플 사이트' },
      },
    },
  ];

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
            React.createElement(PreviewCanvas, { sampleGlobalSources: templateSampleGlobalSources }),
          ),
        ),
      ),
    );
  }

  it('guest_only 레이아웃 → 격리 store _global.currentUser 미시드 (비로그인 분기)', async () => {
    installFetchMock({ guest_only: true });
    renderCanvas('/forgot-password', 'auth/forgot_password');

    // document 로드 후 guest_only 판정 → store 재설치로 currentUser 제거되어야 함
    await waitFor(
      () => {
        const app = (window as any).__templateApp;
        expect(app?.getGlobalState).toBeTypeOf('function');
        expect(app.getGlobalState().currentUser).toBeUndefined();
      },
      { timeout: 3000 },
    );

    // baseline settings 는 그대로 유지 (헤더/푸터 폴백 방지)
    const globalState = (window as any).__templateApp.getGlobalState();
    expect(globalState.settings?.general?.site_name).toBe('샘플 사이트');
  });

  it('일반 레이아웃(guest_only 아님) → currentUser 정상 시드 (로그인 분기 보존)', async () => {
    installFetchMock(undefined);
    renderCanvas('/', 'home');

    await waitFor(
      () => {
        const app = (window as any).__templateApp;
        expect(app?.getGlobalState).toBeTypeOf('function');
        expect((app.getGlobalState().currentUser as { uuid?: string } | undefined)?.uuid).toBe(
          'sample-uuid-current-user',
        );
      },
      { timeout: 3000 },
    );
  });
});
