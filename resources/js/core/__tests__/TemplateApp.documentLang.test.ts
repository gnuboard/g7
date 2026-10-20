/**
 * TemplateApp — 문서 언어(`<html lang>`) 동기화 테스트
 *
 * 서버는 첫 응답의 `<html lang>` 을 요청 언어(Accept-Language·회원 언어)로 찍는다. 그런데 앱은
 * 사용자가 고른 언어(localStorage `g7_locale`)를 우선하고, 언어를 바꿔도 페이지를 다시 받지 않는다.
 * 그래서 화면은 한국어인데 `<html lang="en">` 이 남아 화면 읽기 프로그램·번역 제안·하이픈 처리가
 * 다른 언어 규칙을 썼다(7.1.0 전수 실측).
 *
 * 로그인 상태면 부팅 때 화면 언어를 회원 언어(users.language)로 다시 맞추는데(engine-v1.79.0), 이 경로는
 * 문서 언어를 맞추지 않아 English·日本語 계정의 관리자 화면이 그 언어로 그려져도 `<html lang>` 이 브라우저
 * 저장 언어(ko)로 남았다(7.1.1 출시 전 실측).
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TemplateApp } from '../TemplateApp';
import type { TemplateAppConfig } from '../TemplateApp';
import { AuthManager } from '../auth/AuthManager';

const { sharedActionDispatcher } = vi.hoisted(() => ({
  sharedActionDispatcher: {
    setNavigate: vi.fn(),
    setGlobalState: vi.fn(),
    setDefaultContext: vi.fn(),
    setGlobalStateUpdater: vi.fn(),
    registerHandler: vi.fn(),
    customHandlers: new Map(),
  },
}));

vi.mock('../template-engine', () => ({
  initTemplateEngine: vi.fn().mockResolvedValue(undefined),
  renderTemplate: vi.fn().mockResolvedValue(undefined),
  destroyTemplate: vi.fn(),
  releaseTemplateRoot: vi.fn(),
  getActionDispatcher: vi.fn().mockReturnValue(sharedActionDispatcher),
  getState: vi.fn().mockReturnValue({
    actionDispatcher: sharedActionDispatcher,
    reactRoot: null,
    currentLayoutJson: null,
  }),
}));

vi.mock('../template-engine/TransitionManager', () => ({
  transitionManager: {
    setPending: vi.fn(),
    getIsPending: vi.fn(() => false),
    subscribe: vi.fn(() => vi.fn()),
    clearSubscribers: vi.fn(),
  },
}));

vi.mock('../routing/Router', () => ({
  Router: vi.fn(function (this: any) {
    this.loadRoutes = vi.fn().mockResolvedValue(undefined);
    this.on = vi.fn();
    this.navigateToCurrentPath = vi.fn();
    this.getRoutes = vi.fn().mockReturnValue([]);
  }),
}));

vi.mock('../template-engine/LayoutLoader', () => ({
  LayoutLoader: vi.fn(function (this: any) {
    this.loadLayout = vi.fn().mockResolvedValue({ components: [] });
  }),
}));

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

const baseConfig: TemplateAppConfig = {
  templateId: 'test-template',
  templateType: 'user',
  locale: 'en',
  debug: false,
};

describe('TemplateApp — <html lang> 동기화', () => {
  let originalLocalStorage: Storage;
  let store: Record<string, string>;
  // 회원 언어 시험이 건 AuthManager 가로채기 — 실패해도 다음 시험에 새지 않게 afterEach 에서 푼다
  const authSpies: Array<{ mockRestore: () => void }> = [];

  beforeEach(() => {
    vi.mocked(global.fetch).mockResolvedValue({
      ok: false,
      status: 500,
      statusText: 'Internal Server Error',
      json: async () => ({ success: false }),
    } as unknown as Response);

    store = {};
    originalLocalStorage = global.localStorage;
    Object.defineProperty(global, 'localStorage', {
      value: {
        getItem: vi.fn((key: string) => store[key] ?? null),
        setItem: vi.fn((key: string, value: string) => {
          store[key] = value;
        }),
        removeItem: vi.fn((key: string) => {
          delete store[key];
        }),
        clear: vi.fn(() => {
          store = {};
        }),
      },
      writable: true,
    });

    document.body.innerHTML = '<div id="app"></div>';
    // 서버가 찍은 첫 응답 언어
    document.documentElement.lang = 'en';
  });

  afterEach(() => {
    Object.defineProperty(global, 'localStorage', { value: originalLocalStorage, writable: true });
    document.documentElement.lang = '';
    authSpies.splice(0).forEach((spy) => spy.mockRestore());
    vi.clearAllMocks();
  });

  /**
   * @scenario entry=boot_with_saved_locale, locale_form=plain
   * @effects document_lang_follows_app_locale
   */
  it('사용자가 고른 언어(localStorage)가 서버 언어와 다르면 시작할 때 <html lang> 을 그 언어로 맞춘다', () => {
    store['g7_locale'] = 'ko';

    const app = new TemplateApp(baseConfig);

    expect(app.getLocale()).toBe('ko');
    expect(document.documentElement.lang).toBe('ko');
  });

  /**
   * @scenario entry=change_locale, locale_form=plain
   * @effects document_lang_follows_app_locale
   */
  it('언어를 바꾸면 <html lang> 도 새 언어로 바뀐다', async () => {
    store['g7_locale'] = 'en';
    const app = new TemplateApp(baseConfig);
    await app.init();
    expect(document.documentElement.lang).toBe('en');

    await app.changeLocale('ko');
    expect(document.documentElement.lang).toBe('ko');

    await app.changeLocale('ja');
    expect(document.documentElement.lang).toBe('ja');
  });

  /**
   * @scenario entry=change_locale, locale_form=underscore
   * @effects document_lang_follows_app_locale
   */
  it('밑줄 로케일은 BCP 47 하이픈 형태로 쓴다(서버 템플릿과 같은 변환)', async () => {
    store['g7_locale'] = 'en';
    const app = new TemplateApp(baseConfig);
    await app.init();

    await app.changeLocale('zh_CN');
    expect(document.documentElement.lang).toBe('zh-CN');
  });

  /** 인증 프리로드 결과(로그인 회원의 언어)를 정한다 */
  function mockMember(language: string) {
    const am = AuthManager.getInstance();
    authSpies.push(
      vi.spyOn(am, 'preloadAuth').mockResolvedValue(true),
      vi.spyOn(am, 'getState').mockReturnValue({ isAuthenticated: true, user: { language } as any, type: 'admin' } as any),
    );
  }

  /**
   * @scenario entry=boot_member_language, locale_form=plain
   * @effects document_lang_follows_app_locale
   */
  it.each([
    ['ko', 'en'],
    ['ko', 'ja'],
    ['ja', 'ko'],
  ])('브라우저 저장 언어(%s)와 다른 회원 언어(%s)로 부팅을 맞추면 <html lang> 도 회원 언어로 바뀐다', async (saved, member) => {
    // 관리자 셸 요청에는 토큰이 실리지 않아 서버는 회원 언어를 모른다 — 기본 언어로 찍힌 상태
    document.documentElement.lang = 'ko';
    store['g7_locale'] = saved;
    mockMember(member);
    // 라우트를 받아야 부팅이 회원 언어 맞춤 단계까지 간다
    vi.mocked(globalThis.fetch).mockImplementation(async (url: any) => ({
      ok: true,
      json: async () => ({ success: true, data: String(url).includes('/routes') ? { routes: [] } : {} }),
    }) as unknown as Response);

    const app = new TemplateApp({ ...baseConfig, templateType: 'admin', locale: 'ko' });
    await app.init();

    expect(app.getLocale()).toBe(member);
    expect(document.documentElement.lang).toBe(member);
  });
});
