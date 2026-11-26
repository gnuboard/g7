/**
 * devDuplicateIdMonitor.test.ts — 디버그 모드 중복 DOM id 경고 (engine-v1.99.0)
 *
 * 조합 요약(마커 아님 — 평문): 중복 삽입, 같은 id 재삽입, 경로 변경, 해제, 디버그 꺼짐, 디버그 켜짐 배선
 *
 * 한 화면에 같은 DOM id 가 둘 이상이면 getElementById·label for·aria-* 와 확장 주입이 첫 요소에만 걸린다.
 * 정적 감사와 크롤 E2E 가 모달·시트 열림 상태를 다 보지 못하므로 디버그 모드에서만 콘솔로 알린다(결정 D5).
 * ①~③⑤ 는 실제 MutationObserver 로 감시 동작을, ④ 는 template-engine 초기화가 debug 꺼짐에서
 * 감시를 만들지 않는지를 잠근다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { act } from '@testing-library/react';

vi.mock('../devDuplicateIdMonitor', async () => {
  const actual = await vi.importActual<typeof import('../devDuplicateIdMonitor')>('../devDuplicateIdMonitor');
  return { ...actual, startDuplicateIdMonitor: vi.fn(actual.startDuplicateIdMonitor) };
});

vi.mock('../ComponentRegistry', () => {
  const mockInstance: any = {
    loadComponents: vi.fn().mockResolvedValue(undefined),
    getComponent: vi.fn().mockReturnValue(() => null),
    hasComponent: vi.fn().mockReturnValue(true),
    getInstance: vi.fn(),
  };
  mockInstance.getInstance.mockReturnValue(mockInstance);
  return { ComponentRegistry: { getInstance: vi.fn(() => mockInstance) } };
});

vi.mock('../DataBindingEngine', () => {
  const DataBindingEngine = vi.fn(function (this: any) {
    this.bind = vi.fn();
    this.unbind = vi.fn();
    this.invalidateCacheByKeys = vi.fn();
  });
  return { DataBindingEngine, dataBindingEngine: new (DataBindingEngine as any)() };
});

vi.mock('../TranslationEngine', () => {
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

vi.mock('../ActionDispatcher', () => ({
  ActionDispatcher: vi.fn(function (this: any) {
    this.dispatch = vi.fn();
    this.register = vi.fn();
  }),
  setActionDispatcherInstance: vi.fn(),
  getActionDispatcher: vi.fn(() => ({ dispatch: vi.fn(), register: vi.fn() })),
}));

vi.mock('../DynamicRenderer', async () => {
  const ReactModule = await vi.importActual<typeof import('react')>('react');
  return { default: ({ componentDef }: any) => ReactModule.createElement('main', null, componentDef.id) };
});

vi.mock('../ResponsiveManager', () => ({
  responsiveManager: {
    getWidth: vi.fn(() => 1024),
    subscribe: vi.fn(() => () => {}),
    getMatchingKey: vi.fn(() => null),
    parseRange: vi.fn(() => null),
  },
  BREAKPOINT_PRESETS: {},
}));

vi.mock('../ResponsiveContext', () => ({
  ResponsiveContext: {},
  ResponsiveProvider: ({ children }: { children: any }) => children,
  useResponsive: vi.fn(() => ({ width: 1024, isMobile: false, isTablet: false, isDesktop: true, matchedPreset: 'desktop' })),
}));

import { startDuplicateIdMonitor, DUPLICATE_ID_SETTLE_MS, DUPLICATE_ID_WARNING } from '../devDuplicateIdMonitor';
import { initTemplateEngine, renderTemplate, destroyTemplate } from '../../template-engine';

/** MutationObserver 콜백(마이크로태스크)을 흘려보낸다 */
const flush = async () => {
  await Promise.resolve();
  await Promise.resolve();
};

const addDiv = (root: HTMLElement, id: string) => {
  const el = document.createElement('div');
  el.id = id;
  root.appendChild(el);
  return el;
};

describe('devDuplicateIdMonitor — 감시 동작', () => {
  let root: HTMLElement;
  let warn: ReturnType<typeof vi.spyOn>;
  let stop: () => void;

  beforeEach(() => {
    vi.useFakeTimers();
    window.history.replaceState(null, '', '/admin/a');
    document.body.innerHTML = '<div id="app"></div>';
    root = document.getElementById('app')!;
    warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
    stop = startDuplicateIdMonitor(root);
  });

  afterEach(() => {
    stop();
    vi.useRealTimers();
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  const dupWarnings = () => warn.mock.calls.filter((c: unknown[]) => c[0] === DUPLICATE_ID_WARNING);

  /**
   * @scenario case=monitor_duplicate_insert
   * @effects duplicate_dom_id_warns_once_after_settle
   */
  it('① 중복을 넣으면 변화가 멈춘 뒤 1000ms 에 warn 1회다', async () => {
    addDiv(root, 'dup_probe');
    addDiv(root, 'dup_probe');
    await flush();
    vi.advanceTimersByTime(DUPLICATE_ID_SETTLE_MS - 1);
    expect(dupWarnings()).toHaveLength(0);
    vi.advanceTimersByTime(1);
    expect(dupWarnings()).toHaveLength(1);
    expect(dupWarnings()[0][1]).toEqual({ id: 'dup_probe', count: 2, pathname: '/admin/a' });
  });

  /**
   * @scenario case=monitor_repeat_insert
   * @effects same_pathname_and_id_warns_only_once
   */
  it('② 같은 id 를 다시 넣어도 같은 경로에서는 다시 내지 않는다', async () => {
    addDiv(root, 'dup_probe');
    addDiv(root, 'dup_probe');
    await flush();
    vi.advanceTimersByTime(DUPLICATE_ID_SETTLE_MS);
    addDiv(root, 'dup_probe');
    await flush();
    vi.advanceTimersByTime(DUPLICATE_ID_SETTLE_MS);
    expect(dupWarnings()).toHaveLength(1);
  });

  /**
   * @scenario case=monitor_route_change
   * @effects pathname_change_resets_reported_pairs
   */
  it('③ pathname 이 바뀌면 기록을 비워 같은 id 를 다시 알린다', async () => {
    addDiv(root, 'dup_probe');
    addDiv(root, 'dup_probe');
    await flush();
    vi.advanceTimersByTime(DUPLICATE_ID_SETTLE_MS);
    window.history.pushState(null, '', '/admin/b');
    addDiv(root, 'other');
    await flush();
    vi.advanceTimersByTime(DUPLICATE_ID_SETTLE_MS);
    expect(dupWarnings()).toHaveLength(2);
    expect(dupWarnings()[1][1]).toEqual({ id: 'dup_probe', count: 2, pathname: '/admin/b' });
  });

  /**
   * @scenario case=monitor_stop
   * @effects stop_function_disconnects_observer
   */
  it('⑤ 해제 함수가 observer 를 끊어 이후 중복을 알리지 않는다', async () => {
    stop();
    addDiv(root, 'dup_probe');
    addDiv(root, 'dup_probe');
    await flush();
    vi.advanceTimersByTime(DUPLICATE_ID_SETTLE_MS * 2);
    expect(dupWarnings()).toHaveLength(0);
  });
});

describe('devDuplicateIdMonitor — template-engine 배선', () => {
  const layout = {
    version: '1.0.0',
    layout_name: 'probe',
    components: [{ id: 'page_probe', type: 'basic', name: 'Div' }],
  };

  beforeEach(() => {
    destroyTemplate();
    document.body.innerHTML = '<div id="app"></div>';
    vi.mocked(startDuplicateIdMonitor).mockClear();
  });

  afterEach(async () => {
    destroyTemplate();
    await act(async () => { await new Promise((r) => setTimeout(r, 0)); });
    document.body.innerHTML = '';
  });

  /**
   * @scenario case=monitor_debug_off
   * @effects production_mode_creates_no_observer
   */
  it('④ debug 꺼짐이면 감시를 시작하지 않는다', async () => {
    const observeSpy = vi.spyOn(MutationObserver.prototype, 'observe');
    await initTemplateEngine({ templateId: 'test-template', debug: false });
    await act(async () => { await renderTemplate({ containerId: 'app', layoutJson: layout as any }); });
    expect(startDuplicateIdMonitor).not.toHaveBeenCalled();
    expect(observeSpy.mock.calls.filter((c) => c[0] === document.body)).toHaveLength(0);
    observeSpy.mockRestore();
  });

  /**
   * @scenario case=monitor_debug_on_wiring
   * @effects debug_mode_starts_monitor_once_and_destroy_stops_it
   */
  it('④ debug 켜짐이면 첫 렌더에 한 번만 시작하고 destroyTemplate 이 해제한다', async () => {
    const disconnectSpy = vi.spyOn(MutationObserver.prototype, 'disconnect');
    await initTemplateEngine({ templateId: 'test-template', debug: true });
    await act(async () => { await renderTemplate({ containerId: 'app', layoutJson: layout as any }); });
    await act(async () => { await renderTemplate({ containerId: 'app', layoutJson: layout as any }); });
    expect(startDuplicateIdMonitor).toHaveBeenCalledTimes(1);
    expect(vi.mocked(startDuplicateIdMonitor).mock.calls[0][0]).toBe(document.body);
    const before = disconnectSpy.mock.calls.length;
    destroyTemplate();
    expect(disconnectSpy.mock.calls.length).toBeGreaterThan(before);
    disconnectSpy.mockRestore();
  });
});
