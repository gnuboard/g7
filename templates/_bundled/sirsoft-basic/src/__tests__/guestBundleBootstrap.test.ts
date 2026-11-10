/**
 * guestBundleBootstrap.test.ts — 다른 템플릿 페이지에 실린 번들은 페이지 전역을 건드리지 않는다
 *
 * 배경: 관리자 > 템플릿 관리 > 사용자 템플릿 레이아웃 편집기는 관리자 템플릿이 떠 있는 페이지에 이
 * 템플릿 번들을 함께 싣는다(캔버스 컴포넌트·편집기 위젯용). 번들 부트스트랩이 그대로 돌면 관리자
 * 템플릿이 등록한 같은 이름 핸들러(`setTheme`·`initTheme`·`downloadAttachment`)를 덮어 콘솔에 `[G7]`
 * 소유권 경고가 나고, 그 탭의 관리자 동작(테마 상태 반영·POST 다운로드·본인인증 창·로케일 전환 재등록
 * 맵)이 이 템플릿 것으로 바뀐다.
 *
 * 판정 근거는 페이지 루트 `#app[data-template-id]` 다 — 다른 템플릿이면 손님 번들이다.
 */

import { describe, it, expect, vi, beforeEach, afterEach, type MockInstance } from 'vitest';
import { ActionDispatcher } from '@core/template-engine/ActionDispatcher';

const registerLauncher = vi.fn();
const correctIos = vi.fn();
const installCleanup = vi.fn();
const registerWidgets = vi.fn();

vi.mock('../handlers/identityLauncher', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  registerSirsoftBasicIdentityLauncher: () => registerLauncher(),
}));
vi.mock('../handlers/deviceCorrection', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  correctIosDeviceState: () => correctIos(),
}));
vi.mock('../handlers/authBoundaryStorage', async (importOriginal) => ({
  ...(await importOriginal<Record<string, unknown>>()),
  installAuthBoundaryStorageCleanup: () => installCleanup(),
}));
vi.mock('../layout-editor/registerEditorWidgets', () => ({
  registerSirsoftBasicEditorWidgets: () => registerWidgets(),
}));
// 부트스트랩 판정만 본다 — 컴포넌트·스타일은 이 시험과 무관하고 외부 의존(dompurify 등)을 끌어온다
vi.mock('../components/basic', () => ({}));
vi.mock('../components/composite', () => ({}));
vi.mock('../components/layout', () => ({}));
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

describe('sirsoft-basic 번들 부트스트랩 — 페이지 템플릿 판정', () => {
  let dispatcher: ActionDispatcher;
  let warnSpy: MockInstance<typeof console.warn>;
  const hostSetTheme = vi.fn();
  const hostHandlerMap = { setTheme: hostSetTheme };

  beforeEach(() => {
    vi.resetModules();
    registerLauncher.mockClear();
    correctIos.mockClear();
    installCleanup.mockClear();
    registerWidgets.mockClear();

    // 호스트(관리자) 템플릿이 먼저 부팅해 같은 이름을 등록해 둔 상태
    dispatcher = new ActionDispatcher({ navigate: () => {} });
    dispatcher.registerHandler('setTheme', hostSetTheme, { category: 'custom', source: 'sirsoft-admin_basic' });
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
  it('관리자 템플릿 페이지(편집기)에 실리면 핸들러·launcher·재등록 맵을 건드리지 않는다', async () => {
    mountAppRoot('sirsoft-admin_basic');

    await import('../index');

    // 호스트의 핸들러와 등록자가 그대로다
    expect(dispatcher.getHandler('setTheme')).toBe(hostSetTheme);
    expect(dispatcher.getHandlerSource('setTheme')).toBe('sirsoft-admin_basic');
    expect(dispatcher.getHandler('toggleCartItemSelection')).toBeUndefined();
    // 로케일 전환 때 코어가 다시 등록하는 맵도 호스트 것 그대로다
    expect(window.G7TemplateHandlers).toBe(hostHandlerMap);
    // 페이지 전역 부팅 작업을 하지 않는다
    expect(registerLauncher).not.toHaveBeenCalled();
    expect(correctIos).not.toHaveBeenCalled();
    expect(installCleanup).not.toHaveBeenCalled();
    // 소유권 경고가 나지 않는다
    const g7Warnings = warnSpy.mock.calls.filter((args) => String(args[0]).startsWith('[G7]'));
    expect(g7Warnings).toEqual([]);
    // 편집기 위젯 등록은 편집기를 위한 것이라 그대로 한다
    expect(registerWidgets).toHaveBeenCalledTimes(1);
  });

  // @scenario page=own_template
  // @effects page_globals_registered
  it('자기 페이지(사용자 화면)에서는 종전대로 등록한다', async () => {
    mountAppRoot('sirsoft-basic');

    const mod = await import('../index');

    expect(dispatcher.getHandlerSource('setTheme')).toBe('sirsoft-basic');
    expect(dispatcher.getHandler('toggleCartItemSelection')).toBeTypeOf('function');
    expect(window.G7TemplateHandlers).not.toBe(hostHandlerMap);
    expect(window.G7TemplateHandlers).toHaveProperty('setTheme');
    expect(registerLauncher).toHaveBeenCalledTimes(1);
    expect(correctIos).toHaveBeenCalledTimes(1);
    expect(installCleanup).toHaveBeenCalledTimes(1);
    expect(registerWidgets).toHaveBeenCalledTimes(1);
    expect(typeof mod.initTemplate).toBe('function');
  });

  // @scenario page=no_marker
  // @effects page_globals_registered
  it('페이지 루트 표식이 없으면(옛 코어·단독 실행) 종전대로 등록한다', async () => {
    mountAppRoot(null);

    await import('../index');

    expect(dispatcher.getHandlerSource('setTheme')).toBe('sirsoft-basic');
    expect(window.G7TemplateHandlers).toHaveProperty('toggleCartItemSelection');
    expect(registerLauncher).toHaveBeenCalledTimes(1);
  });
});
