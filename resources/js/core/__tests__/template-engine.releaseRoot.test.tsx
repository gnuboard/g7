/**
 * template-engine — 오류 화면이 루트 컨테이너를 차지할 때 React 루트 반납 (engine-v1.79.0, 7.1.0 실측 O-K2-2)
 *
 * 제한 관리자가 권한 없는 화면으로 SPA 이동하면 레이아웃 403 → TemplateApp.showRouteError → ErrorDisplay 가
 * #app 을 innerHTML 로 덮는다. React 루트를 그대로 두면 그 뒤 토스트 갱신이 지워진 노드 기준으로
 * insertBefore/removeChild 를 시도해 NotFoundError 가 수백 건 쌓였다(실측 198건 + React #185).
 * 실제 ReactDOM 루트로 렌더해 결함 조합(렌더 → 오류 화면 → 토스트 갱신 → 다음 화면)을 그대로 밟는다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { act } from '@testing-library/react';
import {
  initTemplateEngine,
  renderTemplate,
  updateTemplateData,
  destroyTemplate,
  releaseTemplateRoot,
  getState,
} from '../template-engine';
import { ErrorDisplay } from '../template-engine/ErrorDisplay';


// ComponentRegistry mock
vi.mock('../template-engine/ComponentRegistry', () => {
  const mockInstance = {
    loadComponents: vi.fn().mockResolvedValue(undefined),
    getComponent: vi.fn().mockReturnValue(() => null),
    hasComponent: vi.fn().mockReturnValue(true),
    getInstance: vi.fn(),
  };

  mockInstance.getInstance.mockReturnValue(mockInstance);

  return {
    ComponentRegistry: {
      getInstance: vi.fn(() => mockInstance),
    },
  };
});

// DataBindingEngine mock
// G7CoreGlobals.initCoreRuntimeExports가 싱글톤 `dataBindingEngine`도 참조하므로 함께 노출
vi.mock('../template-engine/DataBindingEngine', () => {
  const DataBindingEngine = vi.fn(function(this: any) {
    this.bind = vi.fn();
    this.unbind = vi.fn();
    this.invalidateCacheByKeys = vi.fn();
  });

  return {
    DataBindingEngine,
    dataBindingEngine: new (DataBindingEngine as any)(),
  };
});

// TranslationEngine mock
vi.mock('../template-engine/TranslationEngine', () => {
  const mockInstance = {
    translate: vi.fn((key: string) => key),
    setLocale: vi.fn(),
    loadTranslations: vi.fn().mockResolvedValue({}),
    resolveTranslations: vi.fn((text: string) => text),
    clearCache: vi.fn(),
  };

  return {
    TranslationEngine: {
      getInstance: vi.fn(() => mockInstance),
      resetInstance: vi.fn(),
    },
    TranslationContext: {} as any,
  };
});

// ActionDispatcher mock
vi.mock('../template-engine/ActionDispatcher', () => ({
  ActionDispatcher: vi.fn(function(this: any) {
    this.dispatch = vi.fn();
    this.register = vi.fn();
  }),
  setActionDispatcherInstance: vi.fn(),
  getActionDispatcher: vi.fn(() => ({
    dispatch: vi.fn(),
    register: vi.fn(),
  })),
}));

// DynamicRenderer mock
// DynamicRenderer 대역 — 실제 React DOM 을 그린다. global_toast 는 토스트마다 최상위 형제 노드를 만들어
// 실 화면의 Toast 처럼 커밋 때 루트 컨테이너에 insertBefore/removeChild 가 일어나게 한다.
vi.mock('../template-engine/DynamicRenderer', async () => {
  const ReactModule = await vi.importActual<typeof import('react')>('react');
  const h = ReactModule.createElement;
  return {
    default: ({ componentDef, dataContext }: any) => {
      if (componentDef.id === 'global_toast') {
        const toasts: Array<{ id: string; message: string }> = dataContext?._global?.toasts ?? [];
        return h(ReactModule.Fragment, null, toasts.map((t) => h('div', { key: t.id, 'data-toast': t.id }, t.message)));
      }
      return h('main', { 'data-layout': dataContext?.__layout ?? '' }, componentDef.id);
    },
  };
});

// ResponsiveManager mock
vi.mock('../template-engine/ResponsiveManager', () => ({
  responsiveManager: {
    getWidth: vi.fn(() => 1024),
    subscribe: vi.fn(() => () => {}),
    getMatchingKey: vi.fn(() => null),
    parseRange: vi.fn(() => null),
  },
  BREAKPOINT_PRESETS: {},
}));

// ResponsiveContext mock
// G7CoreGlobals.initCoreRuntimeExports가 Context 객체 자체도 참조하므로 함께 노출
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

const layout = (name: string) => ({
  version: '1.0.0',
  layout_name: name,
  components: [
    { id: 'global_toast', type: 'composite', name: 'Toast', _fromBase: true },
    { id: 'page_' + name, type: 'basic', name: 'Div' },
  ],
});

/**
 * 오류 화면 렌더 — TemplateApp.showRouteError 가 하는 순서(루트 반납 → ErrorDisplay)를 따른다.
 *
 * @param release 루트를 반납할지 (false 면 종전 동작)
 */
const showErrorScreen = (release: boolean) => {
  if (release) {
    releaseTemplateRoot();
  }
  ErrorDisplay.render('app', { title: '페이지 로딩 실패', message: '이 화면을 볼 권한이 없습니다.', debug: false });
};

describe('[case:components-misc-error-screen-releases-react-root] template-engine releaseTemplateRoot — 오류 화면 뒤 토스트 갱신', () => {
  let domErrors: string[];
  const onWindowError = (event: ErrorEvent) => {
    domErrors.push(String(event.error ?? event.message));
    event.preventDefault();
  };

  beforeEach(async () => {
    destroyTemplate();
    document.body.innerHTML = '<div id="app"></div>';
    domErrors = [];
    window.addEventListener('error', onWindowError);
    vi.spyOn(console, 'error').mockImplementation((...args: unknown[]) => {
      domErrors.push(args.map(String).join(' '));
    });
    await initTemplateEngine({ templateId: 'test-template' });
  });

  afterEach(async () => {
    window.removeEventListener('error', onWindowError);
    vi.restoreAllMocks();
    destroyTemplate();
    await act(async () => { await new Promise((r) => setTimeout(r, 0)); });
    document.body.innerHTML = '';
  });

  /**
   * @scenario screen_takeover=error_display
   * @effects error_screen_survives_later_toast_updates_without_dom_errors
   */
  it('오류 화면이 뜬 뒤 토스트가 쌓이고 사라져도 DOM 오류가 없고 오류 화면이 남는다', async () => {
    await act(async () => {
      await renderTemplate({ containerId: 'app', layoutJson: layout('users'), dataContext: { _global: { toasts: [] } } });
    });
    await act(async () => {
      updateTemplateData({ _global: { toasts: [{ id: 't1', message: '해당 권한이 없습니다.' }] } });
    });
    expect(document.querySelector('[data-toast="t1"]')).not.toBeNull();

    showErrorScreen(true);

    await act(async () => {
      updateTemplateData({ _global: { toasts: [{ id: 't1', message: 'a' }, { id: 't2', message: 'b' }] } });
    });
    await act(async () => {
      updateTemplateData({ _global: { toasts: [] } });
    });

    expect(domErrors.filter((e) => /NotFoundError|insertBefore|removeChild/.test(e))).toEqual([]);
    expect(document.getElementById('app')!.textContent).toContain('페이지 로딩 실패');
    expect(getState().reactRoot).toBeNull();
  });

  /**
   * @scenario screen_takeover=error_display
   * @effects next_render_after_error_screen_draws_fresh_layout_without_stale_updates
   */
  it('오류 화면 뒤 다음 화면은 새 루트로 그리고, 떠난 화면의 업데이트를 싣지 않는다', async () => {
    await act(async () => {
      await renderTemplate({ containerId: 'app', layoutJson: layout('users'), dataContext: { _global: { toasts: [] } } });
    });
    showErrorScreen(true);
    await act(async () => {
      updateTemplateData({ _local: { leaked: true }, _global: { toasts: [{ id: 'stale', message: 'x' }] } });
    });

    await act(async () => {
      await renderTemplate({ containerId: 'app', layoutJson: layout('detail'), dataContext: { _global: { toasts: [] } } });
    });

    const app = document.getElementById('app')!;
    expect(app.textContent).not.toContain('페이지 로딩 실패');
    expect(app.querySelector('main')?.textContent).toBe('page_detail');
    expect(app.querySelector('[data-toast="stale"]')).toBeNull();
    expect(getState().currentDataContext._local?.leaked).toBeUndefined();
    expect(getState().reactRoot).not.toBeNull();

    await act(async () => {
      updateTemplateData({ _global: { toasts: [{ id: 'fresh', message: 'ok' }] } });
    });
    expect(app.querySelector('[data-toast="fresh"]')).not.toBeNull();
    expect(domErrors.filter((e) => /NotFoundError|insertBefore|removeChild/.test(e))).toEqual([]);
  });

  /**
   * 편집기 번들을 받지 못해 renderTemplate 이 직접 오류 화면을 그리는 경로도 같은 규칙을 따른다 —
   * 이미 그려진 화면에서 편집기 주소로 SPA 이동하면 살아 있는 루트 위에 오류 화면이 덮인다.
   *
   * @scenario screen_takeover=editor_bundle_error
   * @effects error_screen_survives_later_toast_updates_without_dom_errors
   */
  it('편집기 번들 실패 오류 화면도 루트를 반납해, 이후 토스트 갱신에 DOM 오류가 없다', async () => {
    await act(async () => {
      await renderTemplate({ containerId: 'app', layoutJson: layout('users'), dataContext: { _global: { toasts: [{ id: 't1', message: 'a' }] } } });
    });
    const originalPath = window.location.pathname;
    window.history.pushState(null, '', '/admin/layout-editor/sirsoft-admin_basic');
    const originalAppend = document.head.appendChild.bind(document.head);
    vi.spyOn(document.head, 'appendChild').mockImplementation(((node: any) => {
      if (node.tagName === 'SCRIPT') {
        queueMicrotask(() => node.onerror?.(new Event('error')) ?? node.dispatchEvent(new Event('error')));
        return node;
      }
      return originalAppend(node);
    }) as any);

    try {
      await act(async () => {
        await renderTemplate({ containerId: 'app', layoutJson: layout('editor'), dataContext: { _global: { toasts: [] } } });
      });
      expect(document.getElementById('app')!.textContent).toContain('레이아웃 편집기');

      await act(async () => {
        updateTemplateData({ _global: { toasts: [{ id: 't1', message: 'a' }, { id: 't2', message: 'b' }] } });
      });
      await act(async () => {
        updateTemplateData({ _global: { toasts: [] } });
      });

      expect(domErrors.filter((e) => /NotFoundError|insertBefore|removeChild/.test(e))).toEqual([]);
      expect(document.getElementById('app')!.textContent).toContain('레이아웃 편집기');
      expect(getState().reactRoot).toBeNull();
    } finally {
      window.history.pushState(null, '', originalPath);
    }
  }, 20_000);

  /**
   * 오류 화면 뒤 엔진을 정리하고 다시 초기화하면(로케일 전환 재초기화 등) 반납 표식이 남지 않아,
   * 첫 렌더 전 업데이트가 종전처럼 큐에 담긴다.
   *
   * @scenario screen_takeover=error_display
   * @effects pre_render_updates_still_queued_until_first_render
   */
  it('오류 화면 뒤 destroyTemplate → 재초기화하면 첫 렌더 전 업데이트가 다시 큐에 담긴다', async () => {
    await act(async () => {
      await renderTemplate({ containerId: 'app', layoutJson: layout('users'), dataContext: { _global: { toasts: [] } } });
    });
    showErrorScreen(true);

    destroyTemplate();
    document.body.innerHTML = '<div id="app"></div>';
    await initTemplateEngine({ templateId: 'test-template' });
    updateTemplateData({ _global: { toasts: [{ id: 'after-reinit', message: 'q' }] } });

    await act(async () => {
      await renderTemplate({ containerId: 'app', layoutJson: layout('users'), dataContext: { _global: { toasts: [] } } });
    });

    expect(document.querySelector('[data-toast="after-reinit"]')).not.toBeNull();
  });

  /**
   * @scenario screen_takeover=none
   * @effects pre_render_updates_still_queued_until_first_render
   */
  it('첫 렌더 전 업데이트는 종전처럼 큐에 담겼다가 첫 렌더 뒤 적용된다', async () => {
    updateTemplateData({ _global: { toasts: [{ id: 'early', message: 'e' }] } });

    await act(async () => {
      await renderTemplate({ containerId: 'app', layoutJson: layout('users'), dataContext: { _global: { toasts: [] } } });
    });

    expect(document.querySelector('[data-toast="early"]')).not.toBeNull();
  });
});
