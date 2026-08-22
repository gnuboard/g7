/**
 * PreviewCanvas — `window.G7Core.t` fallback wrapper 회귀 테스트
 *
 * 회귀 원인: 편집 캔버스 안의 sirsoft-basic 컴포넌트(Header/Footer 등) 가
 * React Context 가 아닌 전역 `window.G7Core.t()` 로 i18n 키를 해석한다.
 * 호스트 페이지가 sirsoft-admin_basic 사전을 점유한 채 캔버스 안에서
 * sirsoft-basic 키(`auth.login`, `nav.home`, `footer.community` 등) 를
 * 호출하면 호스트 사전에서 풀리지 않아 raw 키가 그대로 표시된다.
 *
 * 우선순위 = 편집 대상 우선 → 호스트 폴백 ("편집 대상
 * 사전으로 해석"). 캔버스는 편집 대상 템플릿(sirsoft-basic)을 그리므로, 편집 대상
 * 사전을 먼저 풀고 거기 없는 코어/chrome 키(`layout_editor.*` 등)만 호스트로 폴백한다.
 * 종전 "호스트 우선"은 양쪽에 같은 키가 다른 값으로 있을 때(예: editor.computed.
 * filter_default = admin "필터 기본값 정하기" vs basic "기본값 채우기") basic 화면에서도
 * admin 라벨이 떠 와 어긋났다.
 *
 * 본 테스트는 PreviewCanvas 가 마운트되면 `window.G7Core.t` 를 편집 대상 → 호스트
 * fallback 체인으로 감싸고, 언마운트 시 원본 함수를 복원함을 가드한다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import React from 'react';
import { render, waitFor, cleanup } from '@testing-library/react';
import { LayoutEditorProvider } from '../../LayoutEditorContext';
import { PreviewCanvas } from '../../components/PreviewCanvas';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';
import { ComponentRegistry } from '../../../ComponentRegistry';

describe('PreviewCanvas — G7Core.t fallback wrapper', () => {
  let originalG7Core: any;
  let originalT: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    ComponentRegistry.resetInstance();
    originalG7Core = (window as any).G7Core;

    // 호스트 사전 — sirsoft-admin_basic 가 점유 중이라고 가정.
    // `layout_editor.*` 같은 코어 키는 정상 해석, 편집 대상의 `auth.*`/`nav.*` 키는 raw 반환.
    originalT = vi.fn((key: string) => {
      if (key.startsWith('layout_editor.')) {
        return `[host] ${key}`;
      }
      // 충돌 키 — 호스트(admin)는 다른 값으로 푼다. 편집 대상 우선이므로 이 값은 채택 안 됨.
      if (key === 'editor.computed.filter_default.label') {
        return '필터 기본값 정하기(호스트)';
      }
      return key; // 호스트 사전에 없는 키 → 그대로 반환 (fallback 트리거)
    });

    (window as any).G7Core = { t: originalT };

    // editor-assets API mock
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
        return {
          ok: true,
          json: async () => ({
            auth: { login: '로그인' },
            nav: { home: '홈' },
            footer: { community: '커뮤니티' },
            // 양쪽(편집 대상 + 호스트)에 같은 키가 다른 값으로 존재하는 충돌 키 —
            // 편집 대상 우선이므로 편집 대상(basic) 값이 채택돼야 한다.
            editor: { computed: { filter_default: { label: '기본값 채우기(편집대상)' } } },
          }),
        };
      }
      if (url.includes('/api/layouts/')) {
        return { ok: true, json: async () => ({ data: { components: [] } }) };
      }
      return { ok: false, status: 404, json: async () => ({}) };
    });

    // 격리 인스턴스가 사용할 IIFE 번들 전역 변수
    (window as any).SirsoftBasic = {};
  });

  afterEach(() => {
    cleanup();
    (window as any).G7Core = originalG7Core;
    delete (window as any).SirsoftBasic;
    ComponentRegistry.resetInstance();
  });

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
          React.createElement(PreviewCanvas),
        ),
      ),
    );
  }

  it('마운트 후 G7Core.t 가 fallback wrapper 로 교체됨 — 편집 대상에 없는 코어 키는 호스트로 폴백', async () => {
    renderCanvas();

    await waitFor(() => {
      expect((window as any).G7Core.t).not.toBe(originalT);
    });

    // 편집 대상(basic) 사전에 없는 코어/chrome 키(`layout_editor.*`)는 호스트로 폴백.
    const hostResult = (window as any).G7Core.t('layout_editor.chrome.toolbar.exit');
    expect(hostResult).toBe('[host] layout_editor.chrome.toolbar.exit');
  });

  it('편집 대상 사전(translationEngine) 에서 우선 해석 — 호스트에 없는 키', async () => {
    renderCanvas();

    await waitFor(() => {
      expect((window as any).G7Core.t).not.toBe(originalT);
    });

    // 호스트 사전에는 auth.login 이 없고 편집 대상에는 있음 → 편집 대상 '로그인' 해석.
    const result = (window as any).G7Core.t('auth.login');
    expect(result).toBe('로그인');
  });

  it('충돌 키(양쪽 존재, 값 다름)는 편집 대상 우선 — basic 라벨 채택', async () => {
    renderCanvas();

    await waitFor(() => {
      expect((window as any).G7Core.t).not.toBe(originalT);
    });

    // editor.computed.filter_default.label 은 편집 대상(basic)·호스트(admin) 양쪽에 있으나 값이 다르다.
    // 편집 대상 우선이므로 basic 값이 채택돼야 한다(종전 "호스트 우선"이면 admin 값이 떴던 결함).
    const result = (window as any).G7Core.t('editor.computed.filter_default.label');
    expect(result).toBe('기본값 채우기(편집대상)');
    expect(result).not.toBe('필터 기본값 정하기(호스트)');
  });

  it('언마운트 시 원본 G7Core.t 복원 — 호스트 admin 화면 사이드이펙트 0', async () => {
    const { unmount } = renderCanvas();

    await waitFor(() => {
      expect((window as any).G7Core.t).not.toBe(originalT);
    });

    unmount();

    expect((window as any).G7Core.t).toBe(originalT);
  });
});
