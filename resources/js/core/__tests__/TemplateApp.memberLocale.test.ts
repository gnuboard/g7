/**
 * @file TemplateApp.memberLocale.test.ts
 * @description 로그인 회원의 언어(users.language)를 화면 언어의 기준으로 삼는다 (engine-v1.79.0)
 *
 * 버그 (7.1.0 실측 O-C1-2, O-C2a-3):
 *   이 브라우저에 남은 언어(g7_locale)가 회원 언어와 다르면(다른 기기에서 언어를 바꾼 경우 등) 부팅 때 화면은
 *   브라우저 언어로 그려지는데, 서버는 로그인 회원의 요청 언어로 users.language 를 써서 필수 언어 검증 문구와
 *   확장 목록 이름이 회원 언어로 섞였다. 헤더에서 같은 언어를 다시 골라도 changeLocale 이 같은 언어면 바로
 *   돌아가 회원 언어가 갱신되지 않았다.
 *
 * 수정 (결정 2026-10-08 — 회원이 설정한 언어 기준):
 *   - 부팅: 인증 프리로드가 끝난 뒤 첫 렌더 전에 회원 언어로 엔진을 다시 초기화한다(비로그인은 종전대로)
 *   - 같은 언어 재선택: 화면은 그대로 두고 로그인 회원의 언어만 저장한다
 *
 * [case:state-global-member-locale-align]
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

const { sharedActionDispatcher, routerCtor } = vi.hoisted(() => ({
  sharedActionDispatcher: {
    setNavigate: vi.fn(),
    setGlobalState: vi.fn(),
    setDefaultContext: vi.fn(),
    setGlobalStateUpdater: vi.fn(),
    registerHandler: vi.fn(),
    customHandlers: new Map(),
  },
  routerCtor: vi.fn(),
}));

vi.mock('../template-engine', () => ({
  initTemplateEngine: vi.fn().mockResolvedValue(undefined),
  renderTemplate: vi.fn().mockResolvedValue(undefined),
  destroyTemplate: vi.fn(),
  releaseTemplateRoot: vi.fn(),
  updateTemplateData: vi.fn(),
  getActionDispatcher: vi.fn().mockReturnValue(sharedActionDispatcher),
  getState: vi.fn().mockReturnValue({ actionDispatcher: sharedActionDispatcher, reactRoot: null, currentLayoutJson: null }),
}));

vi.mock('../template-engine/TransitionManager', () => ({
  transitionManager: { setPending: vi.fn(), getIsPending: vi.fn(() => false), subscribe: vi.fn(() => vi.fn()), clearSubscribers: vi.fn() },
}));

vi.mock('../routing/Router', () => ({
  Router: vi.fn(function (this: any) {
    routerCtor();
    this.loadRoutes = vi.fn().mockResolvedValue(undefined);
    this.on = vi.fn();
    this.navigateToCurrentPath = vi.fn();
    this.getRoutes = vi.fn().mockReturnValue([]);
    this.setRoutes = vi.fn();
    this.match = vi.fn().mockReturnValue(null);
  }),
}));

vi.mock('../template-engine/LayoutLoader', () => ({
  LayoutLoader: vi.fn(function (this: any) {
    this.loadLayout = vi.fn().mockResolvedValue({ components: [] });
    this.setCacheVersion = vi.fn();
    this.clear = vi.fn();
  }),
}));

vi.mock('../template-engine/ComponentRegistry', () => {
  const mockInstance: any = {
    loadComponents: vi.fn().mockResolvedValue(undefined),
    getComponent: vi.fn().mockReturnValue(() => null),
    hasComponent: vi.fn().mockReturnValue(true),
    getInstance: vi.fn(),
  };
  mockInstance.getInstance.mockReturnValue(mockInstance);
  return { ComponentRegistry: { getInstance: vi.fn(() => mockInstance) } };
});

import { TemplateApp } from '../TemplateApp';
import { AuthManager } from '../auth/AuthManager';
import { initTemplateEngine, destroyTemplate } from '../template-engine';

type FetchCall = { url: string; init?: RequestInit };

describe('회원 언어 기준 화면 언어 (engine-v1.79.0)', () => {
  let fetchCalls: FetchCall[];

  /** 인증 프리로드 결과를 정한다 */
  function mockAuth(user: { language?: string } | null) {
    const am = AuthManager.getInstance();
    vi.spyOn(am, 'preloadAuth').mockResolvedValue(!!user);
    vi.spyOn(am, 'getState').mockReturnValue({ isAuthenticated: !!user, user: user as any, type: user ? 'admin' : null } as any);
  }

  beforeEach(() => {
    localStorage.clear();
    delete (window as any).G7Config;
    document.body.innerHTML = '<div id="app"></div>';
    fetchCalls = [];
    vi.stubGlobal('fetch', vi.fn(async (url: string, init?: RequestInit) => {
      fetchCalls.push({ url: String(url), init });
      return { ok: true, json: async () => ({ success: true, data: String(url).includes('/routes') ? { routes: [] } : {} }) };
    }));
    vi.mocked(initTemplateEngine).mockClear();
    vi.mocked(destroyTemplate).mockClear();
    routerCtor.mockClear();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    localStorage.clear();
    delete (window as any).G7Config;
  });

  // @scenario case=member_differs_on_boot
  // @effects boot_aligns_to_member_language
  it('브라우저 저장 언어(ko)와 회원 언어(ja)가 다르면 첫 렌더 전에 회원 언어로 맞춘다', async () => {
    localStorage.setItem('g7_locale', 'ko');
    mockAuth({ language: 'ja' });

    const app = new TemplateApp({ templateId: 'sirsoft-admin_basic', templateType: 'admin', locale: 'ko', debug: false });
    await app.init();

    expect(app.getLocale()).toBe('ja');
    expect(localStorage.getItem('g7_locale')).toBe('ja');
    const locales = vi.mocked(initTemplateEngine).mock.calls.map((c) => (c[0] as any).locale);
    expect(locales).toEqual(['ko', 'ja']);
    expect(destroyTemplate).toHaveBeenCalledTimes(1);
    // 다시 초기화한 엔진이 라우터·디스패처 문맥 설정보다 먼저다(첫 렌더가 회원 언어)
    const reinitOrder = vi.mocked(initTemplateEngine).mock.invocationCallOrder[1];
    expect(routerCtor).toHaveBeenCalled();
    expect(routerCtor.mock.invocationCallOrder[0]).toBeGreaterThan(reinitOrder);
    expect(sharedActionDispatcher.setDefaultContext.mock.invocationCallOrder.at(-1)!).toBeGreaterThan(reinitOrder);
  });

  // @scenario case=guest_boot
  // @effects guest_keeps_browser_language
  it('비로그인이면 종전대로 브라우저 언어를 쓴다', async () => {
    localStorage.setItem('g7_locale', 'en');
    mockAuth(null);

    const app = new TemplateApp({ templateId: 'sirsoft-basic', templateType: 'user', locale: 'ko', debug: false });
    await app.init();

    expect(app.getLocale()).toBe('en');
    expect(vi.mocked(initTemplateEngine).mock.calls.map((c) => (c[0] as any).locale)).toEqual(['en']);
    expect(destroyTemplate).not.toHaveBeenCalled();
  });

  // @scenario case=member_same_on_boot
  // @effects no_reinit_when_aligned
  it('회원 언어가 이미 화면 언어와 같으면 다시 초기화하지 않는다', async () => {
    localStorage.setItem('g7_locale', 'en');
    mockAuth({ language: 'en' });

    const app = new TemplateApp({ templateId: 'sirsoft-basic', templateType: 'user', locale: 'ko', debug: false });
    await app.init();

    expect(app.getLocale()).toBe('en');
    expect(initTemplateEngine).toHaveBeenCalledTimes(1);
  });

  // @scenario case=member_unsupported
  // @effects unsupported_member_language_ignored
  it('회원 언어가 사이트 지원 언어가 아니면(언어팩 비활성 등) 브라우저 언어를 유지한다', async () => {
    (window as any).G7Config = { appConfig: { supportedLocales: ['ko', 'en'] } };
    localStorage.setItem('g7_locale', 'ko');
    mockAuth({ language: 'ja' });

    const app = new TemplateApp({ templateId: 'sirsoft-admin_basic', templateType: 'admin', locale: 'ko', debug: false });
    await app.init();

    expect(app.getLocale()).toBe('ko');
    expect(initTemplateEngine).toHaveBeenCalledTimes(1);
  });

  // @scenario case=reselect_same_language
  // @effects reselect_saves_member_language
  it('같은 언어를 다시 고르면 화면은 그대로 두고 로그인 회원의 언어를 한 번 저장한다(세션 중 다른 기기에서 바뀐 경우 포함)', async () => {
    localStorage.setItem('g7_locale', 'ko');
    localStorage.setItem('auth_token', 'qa736-token');
    mockAuth({ language: 'ko' });

    const app = new TemplateApp({ templateId: 'sirsoft-admin_basic', templateType: 'admin', locale: 'ko', debug: false });
    await app.init();
    vi.mocked(initTemplateEngine).mockClear();
    fetchCalls = [];

    // 이 화면이 모르는 사이 다른 기기에서 회원 언어가 en 으로 바뀌었다(인증 확인 값은 여전히 ko)
    await app.changeLocale('ko');

    expect(app.getLocale()).toBe('ko');
    expect(initTemplateEngine).not.toHaveBeenCalled();
    const saves = fetchCalls.filter((c) => c.url === '/api/admin/users/me/language');
    expect(saves).toHaveLength(1);
    expect(saves[0].init?.method).toBe('PATCH');
    expect(JSON.parse(String(saves[0].init?.body))).toEqual({ language: 'ko' });
  });

  // @scenario case=persist_false
  // @effects single_save_request
  it('persist: false 면 언어를 바꿔도 저장 요청을 보내지 않는다(호출자가 이미 저장 — 요청 1회)', async () => {
    localStorage.setItem('g7_locale', 'ko');
    localStorage.setItem('auth_token', 'qa736-token');
    mockAuth({ language: 'ko' });

    const app = new TemplateApp({ templateId: 'sirsoft-admin_basic', templateType: 'admin', locale: 'ko', debug: false });
    await app.init();
    fetchCalls = [];

    await app.changeLocale('en', { persist: false });
    expect(app.getLocale()).toBe('en');
    expect(fetchCalls.some((c) => c.url.includes('/users/me/language'))).toBe(false);

    // 지정하지 않으면 종전대로 저장한다(모바일 헤더·사용자 헤더의 setLocale 경로)
    await app.changeLocale('ja');
    expect(fetchCalls.filter((c) => c.url === '/api/admin/users/me/language')).toHaveLength(1);
  });

  // @scenario case=login_switch_route_in_flight
  // @effects locale_switch_waits_for_route_change
  it('로그인 직후 언어 전환: 진행 중인 화면 전환(홈 이동)이 끝난 뒤에 엔진을 내린다', async () => {
    localStorage.setItem('g7_locale', 'ko');
    mockAuth(null);
    const app = new TemplateApp({ templateId: 'sirsoft-basic', templateType: 'user', locale: 'ko', debug: false });
    await app.init();
    const router: any = app.getRouter();
    const onRoute = router.on.mock.calls.find((c: any[]) => c[0] === 'routeChange')[1];
    let releaseLayout!: (v: any) => void;
    (app as any).layoutLoader.loadLayout = vi.fn(() => new Promise((r) => { releaseLayout = r; }));
    vi.mocked(destroyTemplate).mockClear();

    // 홈 이동이 레이아웃을 받는 중
    const routeRun = onRoute({ path: '/', layout: 'home', params: {} });
    // 그 사이 로그인 후처리가 언어 전환을 시작
    const switching = app.changeLocale('ja', { persist: false });
    await new Promise((r) => setTimeout(r, 20));
    expect(destroyTemplate).not.toHaveBeenCalled();

    releaseLayout({ components: [] });
    await routeRun.catch(() => {});
    await switching;
    expect(destroyTemplate).toHaveBeenCalledTimes(1);
    expect(app.getLocale()).toBe('ja');
  });

  // @scenario case=login_switch_route_starts_during_reinit
  // @effects route_change_waits_for_engine_ready
  it('언어 전환으로 엔진이 내려가 있는 동안 시작한 화면 전환은 새 엔진·핸들러 준비 뒤에 진행한다', async () => {
    localStorage.setItem('g7_locale', 'ko');
    mockAuth(null);
    const app = new TemplateApp({ templateId: 'sirsoft-basic', templateType: 'user', locale: 'ko', debug: false });
    await app.init();
    const router: any = app.getRouter();
    const onRoute = router.on.mock.calls.find((c: any[]) => c[0] === 'routeChange')[1];
    const loadLayout = vi.fn().mockResolvedValue({ components: [] });
    (app as any).layoutLoader.loadLayout = loadLayout;
    let releaseInit!: () => void;
    vi.mocked(initTemplateEngine).mockImplementationOnce(() => new Promise<void>((r) => { releaseInit = r; }));

    const switching = app.changeLocale('ja', { persist: false });
    await new Promise((r) => setTimeout(r, 20));
    expect(destroyTemplate).toHaveBeenCalled();

    // 엔진이 내려가 있는 동안 홈 이동 시작
    const routeRun = onRoute({ path: '/', layout: 'home', params: {} });
    await new Promise((r) => setTimeout(r, 20));
    expect(loadLayout).not.toHaveBeenCalled();

    releaseInit();
    await switching;
    await routeRun.catch(() => {});
    expect(loadLayout).toHaveBeenCalled();
    // 새 디스패처에 핸들러를 다시 단 뒤에 레이아웃을 받았다
    const handlersOrder = sharedActionDispatcher.setDefaultContext.mock.invocationCallOrder.at(-1)!;
    expect(loadLayout.mock.invocationCallOrder[0]).toBeGreaterThan(handlersOrder);
  });

  // @scenario case=guest_reselect
  // @effects guest_keeps_browser_language
  it('비로그인(토큰 없음)이면 같은 언어 재선택에 저장 요청을 보내지 않는다', async () => {
    localStorage.setItem('g7_locale', 'ko');
    mockAuth(null);

    const app = new TemplateApp({ templateId: 'sirsoft-basic', templateType: 'user', locale: 'ko', debug: false });
    await app.init();
    fetchCalls = [];

    await app.changeLocale('ko');

    expect(fetchCalls.some((c) => c.url.includes('language'))).toBe(false);
  });
});
