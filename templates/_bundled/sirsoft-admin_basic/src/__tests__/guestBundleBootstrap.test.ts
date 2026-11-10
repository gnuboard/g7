/**
 * guestBundleBootstrap.test.ts — 다른 템플릿 페이지에 실린 번들은 페이지 전역을 건드리지 않는다
 *
 * 배경: 레이아웃 편집기는 페이지의 활성 템플릿이 아닌 편집 대상 템플릿 번들을 그 페이지에 함께 싣는다
 * (다른 관리자 템플릿이 활성인 사이트에서 이 템플릿을 편집할 때). 번들 부트스트랩이 그대로 돌면 호스트
 * 템플릿의 같은 이름 핸들러를 덮어 `[G7]` 소유권 경고가 나고, 호스트의 본인인증 창·사이드바 상태·로케일
 * 전환 재등록 맵이 이 템플릿 것으로 바뀐다.
 *
 * 판정 근거는 페이지 루트 `#app[data-template-id]` 다 — 다른 템플릿이면 손님 번들이다.
 */

import { describe, it, expect, vi, beforeEach, afterEach, type MockInstance } from 'vitest';
import { ActionDispatcher } from '@core/template-engine/ActionDispatcher';

const registerLauncher = vi.fn();
const initSidebar = vi.fn();
const registerWidgets = vi.fn();

vi.mock('../handlers/identityLauncher', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  registerSirsoftAdminBasicIdentityLauncher: () => registerLauncher(),
}));
vi.mock('../handlers/sidebarHandler', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  initSidebar: () => initSidebar(),
}));
vi.mock('../layout-editor/registerEditorWidgets', () => ({
  registerSirsoftAdminBasicEditorWidgets: () => registerWidgets(),
}));
// 부트스트랩 판정만 본다 — 컴포넌트·스타일·편집기 설정은 이 시험과 무관하고 무거운 의존을 끌어온다
vi.mock('../components/basic', () => ({}));
vi.mock('../components/composite', () => ({}));
vi.mock('../components/shared', () => ({}));
vi.mock('../components/layout', () => ({}));
vi.mock('../config/monaco.config', () => ({}));
vi.mock('../styles/main.css', () => ({}));

declare const window: Window &
  typeof globalThis & { G7Core?: Record<string, unknown>; G7TemplateHandlers?: Record<string, unknown> };

/**
 * 페이지 루트를 만든다.
 *
 * @param templateId `#app` 의 data-template-id (null 이면 루트를 만들지 않는다)
 */
function mountAppRoot(templateId: string | null): void {
  document.getElementById('app')?.remove();
  if (templateId === null) return;
  const app = document.createElement('div');
  app.id = 'app';
  app.setAttribute('data-template-id', templateId);
  document.body.appendChild(app);
}

describe('sirsoft-admin_basic 번들 부트스트랩 — 페이지 템플릿 판정', () => {
  const HOST = 'acme-admin_theme';
  let dispatcher: ActionDispatcher;
  let warnSpy: MockInstance<Console['warn']>;
  const hostSetTheme = vi.fn();
  const hostHandlerMap = { setTheme: hostSetTheme };

  beforeEach(() => {
    vi.resetModules();
    registerLauncher.mockClear();
    initSidebar.mockClear();
    registerWidgets.mockClear();

    // 호스트(다른 관리자) 템플릿이 먼저 부팅해 같은 이름을 등록해 둔 상태
    dispatcher = new ActionDispatcher({ navigate: () => {} });
    dispatcher.registerHandler('setTheme', hostSetTheme, { category: 'custom', source: HOST });
    window.G7Core = { getActionDispatcher: () => dispatcher };
    window.G7TemplateHandlers = hostHandlerMap;

    warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
  });

  afterEach(() => {
    warnSpy.mockRestore();
    delete window.G7Core;
    delete window.G7TemplateHandlers;
    document.getElementById('app')?.remove();
  });

  // @scenario page=other_template
  // @effects host_page_globals_untouched
  it('다른 템플릿 페이지(편집기)에 실리면 핸들러·launcher·사이드바·재등록 맵을 건드리지 않는다', async () => {
    mountAppRoot(HOST);

    await import('../index');

    expect(dispatcher.getHandler('setTheme')).toBe(hostSetTheme);
    expect(dispatcher.getHandlerSource('setTheme')).toBe(HOST);
    expect(dispatcher.getHandler('toggleSidebar')).toBeUndefined();
    expect(window.G7TemplateHandlers).toBe(hostHandlerMap);
    expect(registerLauncher).not.toHaveBeenCalled();
    expect(initSidebar).not.toHaveBeenCalled();
    const g7Warnings = warnSpy.mock.calls.filter((args) => String(args[0]).startsWith('[G7]'));
    expect(g7Warnings).toEqual([]);
    // 편집기 위젯 등록은 편집기를 위한 것이라 그대로 한다
    expect(registerWidgets).toHaveBeenCalledTimes(1);
  });

  // @scenario page=own_template
  // @effects page_globals_registered
  it('자기 페이지(관리자 화면)에서는 종전대로 등록한다', async () => {
    mountAppRoot('sirsoft-admin_basic');

    await import('../index');

    expect(dispatcher.getHandlerSource('setTheme')).toBe('sirsoft-admin_basic');
    expect(dispatcher.getHandler('toggleSidebar')).toBeTypeOf('function');
    expect(window.G7TemplateHandlers).toHaveProperty('toggleSidebar');
    expect(registerLauncher).toHaveBeenCalledTimes(1);
    expect(initSidebar).toHaveBeenCalledTimes(1);
    expect(registerWidgets).toHaveBeenCalledTimes(1);
  });

  // @scenario page=no_marker
  // @effects page_globals_registered
  it('페이지 루트 표식이 없으면(옛 코어·단독 실행) 종전대로 등록한다', async () => {
    mountAppRoot(null);

    await import('../index');

    expect(dispatcher.getHandlerSource('setTheme')).toBe('sirsoft-admin_basic');
    expect(window.G7TemplateHandlers).toHaveProperty('toggleSidebar');
    expect(registerLauncher).toHaveBeenCalledTimes(1);
  });
});
