/**
 * TemplateApp.showRouteError 401 가드 테스트 (Issue #301)
 *
 * 레이아웃 fetch 가 401 재시도 후에도 실패하면 코어가
 * 로그인 페이지로 자동 리다이렉트하는 동작을 검증한다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TemplateApp } from '../TemplateApp';
import type { TemplateAppConfig } from '../TemplateApp';
import { LayoutLoaderError } from '../template-engine/LayoutLoader';
import { AuthManager } from '../auth/AuthManager';
import { releaseTemplateRoot } from '../template-engine';
import { ErrorDisplay } from '../template-engine/ErrorDisplay';

// Mock ApiClient (AuthManager 의존성)
const mockApiClient = {
  post: vi.fn().mockResolvedValue({}),
  get: vi.fn().mockResolvedValue({}),
  removeToken: vi.fn(),
  setToken: vi.fn(),
  getToken: vi.fn().mockReturnValue(null),
  setOnUnauthorized: vi.fn(),
};

vi.mock('../api/ApiClient', () => ({
  getApiClient: () => mockApiClient,
}));

// 공유 ActionDispatcher mock
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

vi.mock('../template-engine/LayoutLoader', async () => {
  const actual = await vi.importActual<any>('../template-engine/LayoutLoader');
  return {
    ...actual,
    LayoutLoader: vi.fn(function (this: any) {
      this.loadLayout = vi.fn().mockResolvedValue({ components: [] });
    }),
  };
});

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

describe('TemplateApp - 401 자동 리다이렉트 (Issue #301)', () => {
  let app: TemplateApp;

  beforeEach(() => {
    document.body.innerHTML = '<div id="app"></div>';

    // window.location mock — assignable href
    Object.defineProperty(window, 'location', {
      value: {
        href: '',
        pathname: '/admin/board/settings',
        search: '?id=42',
      },
      writable: true,
      configurable: true,
    });

    // AuthManager 싱글톤 리셋 (디폴트 config 로 복귀)
    (AuthManager as any).instance = undefined;

    (window as any).G7Core = {
      devTools: {
        trackAuthEvent: vi.fn(),
      },
    };

    vi.clearAllMocks();
  });

  afterEach(() => {
    delete (window as any).G7Core;
  });

  /**
   * @scenario screen_takeover=none
   * @effects unauthorized_layout_redirects_to_login_with_session_expired_reason
   */
  it('토큰 보유 + 401 → admin 로그인 페이지로 reason=session_expired 부여하여 리다이렉트되어야 한다', () => {
    mockApiClient.getToken.mockReturnValueOnce('expired-admin-token');

    const config: TemplateAppConfig = {
      templateId: 'sirsoft-admin_basic',
      templateType: 'admin',
      locale: 'ko',
      debug: false,
    };
    app = new TemplateApp(config);

    const err = new LayoutLoaderError('Failed to fetch layout: 401', 'FETCH_FAILED', {
      status: 401,
      statusText: 'Unauthorized',
      url: '/api/layouts/sirsoft-admin_basic/admin_dashboard.json',
    });

    (app as any).showRouteError(err);

    expect(window.location.href).toBe(
      `/admin/login?redirect=${encodeURIComponent('/admin/board/settings?id=42')}&reason=session_expired`
    );
  });

  /**
   * @scenario screen_takeover=none
   * @effects unauthorized_layout_redirects_to_login_with_session_expired_reason
   */
  it('토큰 보유 + user 템플릿 401 → user 로그인 페이지로 reason 부여 리다이렉트', () => {
    Object.defineProperty(window, 'location', {
      value: { href: '', pathname: '/board/free', search: '?p=2' },
      writable: true,
      configurable: true,
    });

    mockApiClient.getToken.mockReturnValueOnce('expired-user-token');

    const config: TemplateAppConfig = {
      templateId: 'sirsoft-basic',
      templateType: 'user',
      locale: 'ko',
      debug: false,
    };
    app = new TemplateApp(config);

    const err = new LayoutLoaderError('Failed to fetch layout: 401', 'FETCH_FAILED', {
      status: 401,
    });

    (app as any).showRouteError(err);

    expect(window.location.href).toBe(
      `/login?redirect=${encodeURIComponent('/board/free?p=2')}&reason=session_expired`
    );
  });

  /**
   * @scenario screen_takeover=none
   * @effects custom_login_path_used_for_unauthorized_redirect
   */
  it('AuthManager.updateConfig 로 커스텀 loginPath 설정 후 그 경로로 리다이렉트되어야 한다', () => {
    // user 템플릿의 커스텀 경로가 적용되려면 pathname 도 /admin 외 경로여야 함
    Object.defineProperty(window, 'location', {
      value: { href: '', pathname: '/mypage', search: '?tab=orders' },
      writable: true,
      configurable: true,
    });

    mockApiClient.getToken.mockReturnValueOnce('expired-user-token');

    const config: TemplateAppConfig = {
      templateId: 'sirsoft-basic',
      templateType: 'user',
      locale: 'ko',
      debug: false,
    };
    app = new TemplateApp(config);

    AuthManager.getInstance().updateConfig('user', { loginPath: '/account/signin' });

    const err = new LayoutLoaderError('Failed to fetch layout: 401', 'FETCH_FAILED', {
      status: 401,
    });

    (app as any).showRouteError(err);

    expect(window.location.href).toBe(
      `/account/signin?redirect=${encodeURIComponent('/mypage?tab=orders')}&reason=session_expired`
    );
  });

  /**
   * @scenario screen_takeover=none
   * @effects anonymous_unauthorized_redirect_without_reason
   */
  it('익명 사용자(토큰 미보유 + details.hadToken 없음) + 401 → reason 미부여 (정책: 익명 안내 토스트 차단)', () => {
    // 정책: 한 번도 로그인하지 않은 사용자에게 "세션 만료" 안내가 노출되는 것을 차단.
    // 가드는 (a) 현재 apiClient.getToken() 또는 (b) error.details.hadToken === true
    // 둘 중 하나라도 truthy 면 reason 부여, 둘 다 false 면 미부여.
    const config: TemplateAppConfig = {
      templateId: 'sirsoft-admin_basic',
      templateType: 'admin',
      locale: 'ko',
      debug: false,
    };
    app = new TemplateApp(config);

    const err = new LayoutLoaderError('Failed to fetch layout: 401', 'FETCH_FAILED', {
      status: 401,
      // hadToken 부재 (익명 진입)
    });

    (app as any).showRouteError(err);

    expect(window.location.href).toBe(
      `/admin/login?redirect=${encodeURIComponent('/admin/board/settings?id=42')}`
    );
    expect(window.location.href).not.toContain('reason=');
  });

  /**
   * @scenario screen_takeover=none
   * @effects unauthorized_layout_redirects_to_login_with_session_expired_reason
   */
  it('토큰 만료(LayoutLoader 가 retry 후 토큰 제거 상태) + details.hadToken=true → reason=session_expired 부여', () => {
    // 회귀 차단 (Issue #301 후속): LayoutLoader 가 401 시 토큰을 자동 제거하고
    // 재시도하므로 가드 진입 시점에 apiClient.getToken() = null 이지만,
    // LayoutLoader 가 details.hadToken=true 로 마킹하여 가드가 토큰 만료 케이스를
    // 인식할 수 있어야 한다. 마킹이 없으면 토큰 만료 사용자에게도 토스트가 안 떠
    // 사용자가 무슨 일이 일어났는지 알 수 없는 회귀 발생.
    // (mockApiClient.getToken 디폴트 null — LayoutLoader 가 이미 제거한 상태 시뮬레이션)
    const config: TemplateAppConfig = {
      templateId: 'sirsoft-admin_basic',
      templateType: 'admin',
      locale: 'ko',
      debug: false,
    };
    app = new TemplateApp(config);

    const err = new LayoutLoaderError('Failed to fetch layout: 401', 'FETCH_FAILED', {
      status: 401,
      hadToken: true, // LayoutLoader 가 첫 401 시 토큰 보유 상태였음을 마킹
    });

    (app as any).showRouteError(err);

    expect(window.location.href).toBe(
      `/admin/login?redirect=${encodeURIComponent('/admin/board/settings?id=42')}&reason=session_expired`
    );
  });

  /**
   * @scenario screen_takeover=error_display
   * @effects non_unauthorized_layout_failure_does_not_redirect
   */
  it('LayoutLoaderError 의 status 가 401 이 아니면 리다이렉트하지 않아야 한다 (404, 500 등)', () => {
    const config: TemplateAppConfig = {
      templateId: 'sirsoft-admin_basic',
      templateType: 'admin',
      locale: 'ko',
      debug: false,
    };
    app = new TemplateApp(config);

    const err500 = new LayoutLoaderError('Server error', 'FETCH_FAILED', {
      status: 500,
    });

    (app as any).showRouteError(err500);

    // 리다이렉트 발생 안 함
    expect(window.location.href).toBe('');
  });

  /**
   * 오류 화면(ErrorDisplay)이 #app 을 덮기 전에 React 루트를 반납한다 — 그대로 두면 이후 토스트 갱신이
   * 지워진 노드에 그려지며 NotFoundError 가 쌓인다(engine-v1.79.0, 7.1.0 실측 O-K2-2). 401 은 이동이라 반납하지 않는다.
   *
   * @scenario screen_takeover=error_display
   * @effects route_error_releases_root_before_error_screen
   */
  it('401 이 아닌 레이아웃 실패는 오류 화면을 그리기 전에 React 루트를 반납한다', () => {
    app = new TemplateApp({ templateId: 'sirsoft-admin_basic', templateType: 'admin', locale: 'ko', debug: false });
    const order: string[] = [];
    vi.mocked(releaseTemplateRoot).mockImplementation(() => { order.push('release'); });
    const renderSpy = vi.spyOn(ErrorDisplay, 'renderFromError').mockImplementation(() => { order.push('error-screen'); });

    (app as any).showRouteError(new LayoutLoaderError('Forbidden', 'FETCH_FAILED', { status: 403 }));

    expect(order).toEqual(['release', 'error-screen']);
    renderSpy.mockRestore();
  });

  /**
   * @scenario screen_takeover=none
   * @effects unauthorized_layout_failure_does_not_release_root
   */
  it('401 레이아웃 실패(로그인 이동)는 React 루트를 반납하지 않는다', () => {
    app = new TemplateApp({ templateId: 'sirsoft-admin_basic', templateType: 'admin', locale: 'ko', debug: false });
    vi.mocked(releaseTemplateRoot).mockClear();

    (app as any).showRouteError(new LayoutLoaderError('Unauthorized', 'FETCH_FAILED', { status: 401 }));

    expect(releaseTemplateRoot).not.toHaveBeenCalled();
  });

  /**
   * @scenario screen_takeover=error_display
   * @effects non_unauthorized_layout_failure_does_not_redirect
   */
  it('일반 Error(LayoutLoaderError 가 아닌)는 리다이렉트하지 않아야 한다', () => {
    const config: TemplateAppConfig = {
      templateId: 'sirsoft-admin_basic',
      templateType: 'admin',
      locale: 'ko',
      debug: false,
    };
    app = new TemplateApp(config);

    const genericErr = new Error('Some other error');

    (app as any).showRouteError(genericErr);

    expect(window.location.href).toBe('');
  });

  /**
   * @scenario screen_takeover=none
   * @effects admin_pathname_selects_admin_login
   */
  it('templateId 가 admin 을 포함하지 않아도 pathname 이 /admin 이면 admin 로그인으로 가야 한다', () => {
    Object.defineProperty(window, 'location', {
      value: { href: '', pathname: '/admin/some/page', search: '' },
      writable: true,
      configurable: true,
    });

    mockApiClient.getToken.mockReturnValueOnce('expired-token');

    const config: TemplateAppConfig = {
      templateId: 'custom-template',
      templateType: 'admin',
      locale: 'ko',
      debug: false,
    };
    app = new TemplateApp(config);

    const err = new LayoutLoaderError('Failed to fetch layout: 401', 'FETCH_FAILED', {
      status: 401,
    });

    (app as any).showRouteError(err);

    expect(window.location.href).toBe(
      `/admin/login?redirect=${encodeURIComponent('/admin/some/page')}&reason=session_expired`
    );
  });
});
