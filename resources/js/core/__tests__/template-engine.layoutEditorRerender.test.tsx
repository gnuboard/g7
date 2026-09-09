/**
 * template-engine.ts — 레이아웃 편집기 모드 재렌더 보호 회귀 테스트
 *
 * 결함: 편집기 모드에서 `TemplateApp.init` 은 `renderTemplate({ layoutJson: { components: [] } })`
 * 로 부르고, `renderTemplate` 의 편집기 분기가 그 빈 배열 대신 `LayoutEditorChrome` 을 렌더한다.
 * 그런데 `updateTemplateData` 에는 그 분기가 없어 `state.currentLayoutJson.components`(= 빈 배열)
 * 로 **같은 reactRoot 에 두 번째 render** 를 걸었고, React 가 그 커밋에서 편집기 트리를 통째로
 * 제거해 화면이 백지가 됐다.
 *
 * 예외도 콘솔 오류도 남지 않는다 — 편집기가 잠깐 보였다가 사라지는 것이 유일한 증상이며,
 * `renderTemplate` 의 편집기 분기가 비동기(`loadLayoutEditorBundle`)라 부팅 중 `setGlobalState`
 * 가 그 커밋 뒤에 도착할 때만 발현하는 **경합**이라 간헐적으로 보인다.
 *
 * 브라우저 실측(2026-09-08): `app-children-up 1` (668ms) → `APP-EMPTIED removed=1` (704ms),
 * 제거 스택은 `init` → startTransition → React commit. `destroyTemplate` 은 호출되지 않았다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { act } from '@testing-library/react';
import React from 'react';
import {
  initTemplateEngine,
  renderTemplate,
  updateTemplateData,
  destroyTemplate,
} from '../template-engine';

const flushReactScheduler = async (): Promise<void> => {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 0));
  });
};

vi.mock('../template-engine/ComponentRegistry', () => {
  const mockInstance = {
    loadComponents: vi.fn().mockResolvedValue(undefined),
    getComponent: vi.fn().mockReturnValue(() => null),
    hasComponent: vi.fn().mockReturnValue(true),
    getInstance: vi.fn(),
  };
  mockInstance.getInstance.mockReturnValue(mockInstance);
  return { ComponentRegistry: { getInstance: vi.fn(() => mockInstance) } };
});

vi.mock('../template-engine/DataBindingEngine', () => {
  const DataBindingEngine = vi.fn(function (this: any) {
    this.bind = vi.fn();
    this.unbind = vi.fn();
    this.invalidateCacheByKeys = vi.fn();
  });
  return { DataBindingEngine, dataBindingEngine: new (DataBindingEngine as any)() };
});

vi.mock('../template-engine/TranslationEngine', () => {
  const mockInstance = {
    translate: vi.fn((key: string) => key),
    setLocale: vi.fn(),
    loadTranslations: vi.fn().mockResolvedValue({}),
    resolveTranslations: vi.fn((text: string) => text),
    clearCache: vi.fn(),
  };
  return {
    TranslationEngine: { getInstance: vi.fn(() => mockInstance), resetInstance: vi.fn() },
    TranslationContext: {} as any,
  };
});

vi.mock('../template-engine/ActionDispatcher', () => ({
  ActionDispatcher: vi.fn(function (this: any) {
    this.dispatch = vi.fn();
    this.register = vi.fn();
    this.createHandler = vi.fn(() => vi.fn());
  }),
  setActionDispatcherInstance: vi.fn(),
  getActionDispatcher: vi.fn(() => ({ dispatch: vi.fn(), register: vi.fn() })),
}));

vi.mock('../template-engine/DynamicRenderer', () => ({ default: vi.fn(() => null) }));

vi.mock('../template-engine/ResponsiveManager', () => ({
  responsiveManager: {
    getWidth: vi.fn(() => 1024),
    subscribe: vi.fn(() => () => {}),
    getMatchingKey: vi.fn(() => null),
    parseRange: vi.fn(() => null),
  },
  BREAKPOINT_PRESETS: {},
}));

vi.mock('../template-engine/ResponsiveContext', () => ({
  ResponsiveContext: {},
  ResponsiveProvider: ({ children }: { children: React.ReactNode }) => children,
  useResponsive: vi.fn(() => ({
    width: 1024,
    isMobile: false,
    isTablet: false,
    isDesktop: true,
    matchedPreset: 'desktop',
  })),
}));

const EDITOR_PATH = '/admin/layout-editor/sirsoft-basic';

/** 편집기 lazy 번들 stub — `loadLayoutEditorBundle` 이 즉시 반환하는 경로를 탄다. */
function EditorChromeStub(): React.ReactElement {
  return React.createElement('div', { 'data-testid': 'editor-chrome' }, 'EDITOR');
}

describe('[case:state-advanced-editor-blank] template-engine — 레이아웃 편집기 모드 재렌더 보호', () => {
  let container: HTMLDivElement;

  beforeEach(async () => {
    destroyTemplate();
    vi.clearAllMocks();

    container = document.createElement('div');
    container.id = 'app';
    document.body.appendChild(container);

    // `loadLayoutEditorBundle` 은 `G7Core.__LayoutEditorChrome` 이 있으면 즉시 resolve 한다.
    (window as any).G7Core = { ...(window as any).G7Core, __LayoutEditorChrome: EditorChromeStub };

    window.history.replaceState({}, '', EDITOR_PATH);

    await initTemplateEngine({ templateId: 'sirsoft-admin_basic', locale: 'ko' });
  });

  afterEach(async () => {
    destroyTemplate();
    await flushReactScheduler();
    container.remove();
    delete (window as any).G7Core.__LayoutEditorChrome;
    window.history.replaceState({}, '', '/');
  });

  it('편집기 렌더 후 updateTemplateData 가 들어와도 편집기 트리가 지워지지 않는다', async () => {
    // TemplateApp.init 이 편집기 모드에서 넘기는 것과 동일한 빈 레이아웃
    await act(async () => {
      await renderTemplate({
        containerId: 'app',
        layoutJson: { components: [] } as any,
        dataContext: {},
        translationContext: { templateId: 'sirsoft-admin_basic', locale: 'ko' },
      });
    });
    await flushReactScheduler();

    expect(
      container.querySelector('[data-testid="editor-chrome"]'),
      '편집기 분기가 LayoutEditorChrome 을 렌더해야 한다(사전 조건)',
    ).not.toBeNull();

    // 부팅 중 setGlobalState → updateTemplateData 가 도착하는 상황
    await act(async () => {
      updateTemplateData({ _global: { sidebarOpen: false } });
    });
    await flushReactScheduler();

    expect(
      container.querySelector('[data-testid="editor-chrome"]'),
      'updateTemplateData 재렌더가 편집기 트리를 제거하면 안 된다(백지 회귀)',
    ).not.toBeNull();
    expect(container.childElementCount, '#app 이 비어서는 안 된다').toBeGreaterThan(0);
  });

  it('편집기 모드가 아니면 updateTemplateData 재렌더가 종전대로 동작한다 (회귀 가드)', async () => {
    window.history.replaceState({}, '', '/admin/dashboard');

    await act(async () => {
      await renderTemplate({
        containerId: 'app',
        layoutJson: { components: [{ type: 'basic', name: 'Div' }] } as any,
        dataContext: {},
        translationContext: { templateId: 'sirsoft-admin_basic', locale: 'ko' },
      });
    });
    await flushReactScheduler();

    // DynamicRenderer 가 null 을 반환하는 mock 이라 DOM 노드는 없지만,
    // 편집기 stub 이 렌더되지 않았다는 것이 이 케이스의 핵심이다.
    expect(container.querySelector('[data-testid="editor-chrome"]')).toBeNull();

    await act(async () => {
      updateTemplateData({ _global: { x: 1 } });
    });
    await flushReactScheduler();

    expect(container.querySelector('[data-testid="editor-chrome"]')).toBeNull();
  });
});
