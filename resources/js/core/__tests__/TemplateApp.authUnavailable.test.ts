/**
 * @file TemplateApp.authUnavailable.test.ts
 * @description 인증 확인이 「확인 불가」(서버 일시 오류)로 끝났을 때 TemplateApp 이 로그인 화면 대신 중립 오류 화면을 그리는지
 *
 * Router 는 `authUnavailable` 을 알리고 멈춘다. 받는 쪽은 로그인 여부를 모르는 상태라 템플릿 오류 레이아웃(base 를 상속해
 * 머리글이 `current_user` 로 로그인 분기를 한다 — 서버가 계속 실패하면 비로그인 모양)을 쓰지 않고, 코어 기본 오류 표시로
 * 「잠시 뒤 다시 시도」 안내와 새로고침 단추만 그린다(#869 N5). 토큰은 건드리지 않는다(서버가 돌아오면 새로고침으로 이어진다).
 *
 * @scenario-manifest tests/scenarios/auth-transient-check.yaml
 *
 * @vitest-environment jsdom
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TemplateApp } from '../TemplateApp';
import { installUnloadGuard, resetUnloadGuardForTesting } from '../template-engine/networkResilience';
import { AuthManager } from '../auth/AuthManager';

const { removeToken } = vi.hoisted(() => ({ removeToken: vi.fn() }));

vi.mock('../api/ApiClient', () => ({
  getApiClient: () => ({
    post: vi.fn().mockResolvedValue({}),
    get: vi.fn().mockResolvedValue({}),
    removeToken,
    setToken: vi.fn(),
    getToken: vi.fn().mockReturnValue('valid-token'),
    setOnUnauthorized: vi.fn(),
  }),
}));

describe('TemplateApp — 인증 확인 불가', () => {
  let app: TemplateApp;
  let renderError: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    document.body.innerHTML = '<div id="app"></div>';
    document.documentElement.lang = 'ko';
    resetUnloadGuardForTesting();
    installUnloadGuard();
    app = new TemplateApp({ templateId: 'test-template', templateType: 'admin', locale: 'ko', debug: false });
    renderError = vi.fn().mockResolvedValue(true);
    (app as any).errorPageHandler = { updateGlobalState: vi.fn(), updateLocale: vi.fn(), renderError };
  });

  afterEach(() => {
    vi.clearAllMocks();
    document.body.innerHTML = '';
  });

  // @scenario failure=server_5xx_persistent
  // @effects auth_unavailable_renders_503_page
  it('템플릿 오류 레이아웃(머리글 포함) 대신 중립 안내와 새로고침 단추를 그리고 토큰은 지우지 않는다', async () => {
    await (app as any).handleAuthUnavailable('/admin/dashboard');

    expect(renderError).not.toHaveBeenCalled();
    const appRoot = document.getElementById('app')!;
    expect(appRoot.textContent).toContain('요청을 처리하지 못했습니다. 잠시 뒤 다시 시도해 주세요.');
    expect(appRoot.textContent).toContain('새로고침');
    // 로그인 여부를 단정하는 요소(로그인·로그아웃 링크, 사용자 이름)는 없다
    expect(appRoot.querySelector('a[href*="login"]')).toBeNull();
    expect(appRoot.textContent).not.toContain('로그인');
    expect(removeToken).not.toHaveBeenCalled();
  });

  // @scenario failure=server_5xx_persistent
  // @effects auth_unavailable_renders_503_page
  it('문서를 떠나는 중이면 오류 화면을 그리지 않는다', async () => {
    const showRouteError = vi.spyOn(app as any, 'showRouteError');
    window.dispatchEvent(new Event('pagehide'));

    try {
      await (app as any).handleAuthUnavailable('/admin/dashboard');
      expect(showRouteError).not.toHaveBeenCalled();
      expect(document.getElementById('app')!.textContent).toBe('');
    } finally {
      resetUnloadGuardForTesting();
      installUnloadGuard();
    }
  });

  // 기다리는 동안 백지 대신 확인 중 표시 (설계 확정 2026-11-08)
  // @scenario failure=server_5xx_persistent
  // @effects auth_check_progress_announced
  it('인증 확인을 다시 묻는 동안 확인 중 표시를 띄우고 인증으로 끝나면 걷는다', () => {
    AuthManager.resetInstance();
    (app as any).installAuthCheckIndicator(AuthManager.getInstance());
    const indicator = (): HTMLElement | null => document.querySelector('[data-g7-auth-check-indicator]');

    (AuthManager.getInstance() as any).emit('authCheckRetry', { type: 'admin', attempt: 1 });
    expect(indicator()?.getAttribute('role')).toBe('status');
    expect(indicator()?.textContent).toBe('로그인 상태를 확인하는 중입니다. 잠시만 기다려 주세요.');

    (AuthManager.getInstance() as any).emit('authCheckRetryEnd', { type: 'admin', status: 'authenticated' });
    (AuthManager.getInstance() as any).emit('authCheckEnd', { type: 'admin', status: 'authenticated' });
    expect(indicator()).toBeNull();
  });

  // @scenario failure=server_5xx_persistent
  // @effects auth_check_progress_announced
  it('확인 중 표시는 화면 언어(en, ja)를 따른다', () => {
    AuthManager.resetInstance();
    (app as any).installAuthCheckIndicator(AuthManager.getInstance());
    const indicator = (): HTMLElement | null => document.querySelector('[data-g7-auth-check-indicator]');

    document.documentElement.lang = 'en';
    (AuthManager.getInstance() as any).emit('authCheckRetry', { type: 'user', attempt: 1 });
    expect(indicator()?.textContent).toBe('Checking your sign-in status. Please wait a moment.');
    (AuthManager.getInstance() as any).emit('authCheckRetryEnd', { type: 'user', status: 'authenticated' });
    (AuthManager.getInstance() as any).emit('authCheckEnd', { type: 'user', status: 'authenticated' });

    document.documentElement.lang = 'ja';
    (AuthManager.getInstance() as any).emit('authCheckRetry', { type: 'user', attempt: 1 });
    expect(indicator()?.textContent).toBe('ログイン状態を確認しています。しばらくお待ちください。');
    (AuthManager.getInstance() as any).emit('authCheckRetryEnd', { type: 'user', status: 'authenticated' });
    (AuthManager.getInstance() as any).emit('authCheckEnd', { type: 'user', status: 'authenticated' });
    expect(indicator()).toBeNull();
  });

  // 브라우저 실측(설계 확정 2026-11-08 「기다리는 동안 백지 대신 확인 중 표시」): 첫 요청이 늦으면 표시가 뜨기 전, 확인 불가로 끝난 뒤 오류 화면 전에 백지가 있었다
  // @scenario failure=server_5xx_persistent
  // @effects auth_check_progress_announced
  it('첫 요청이 늦으면 재시도 전에도 짧은 지연 뒤 표시를 띄우고, 빨리 끝나면 띄우지 않는다', () => {
    vi.useFakeTimers();
    try {
      AuthManager.resetInstance();
      (app as any).installAuthCheckIndicator(AuthManager.getInstance());
      const indicator = (): HTMLElement | null => document.querySelector('[data-g7-auth-check-indicator]');
      const am = AuthManager.getInstance() as any;

      am.emit('authCheckStart', { type: 'admin' });
      vi.advanceTimersByTime(200);
      am.emit('authCheckEnd', { type: 'admin', status: 'authenticated' });
      vi.advanceTimersByTime(1000);
      expect(indicator()).toBeNull();

      am.emit('authCheckStart', { type: 'admin' });
      vi.advanceTimersByTime(600);
      expect(indicator()?.getAttribute('role')).toBe('status');
      am.emit('authCheckEnd', { type: 'admin', status: 'unauthenticated' });
      expect(indicator()).toBeNull();
    } finally {
      vi.useRealTimers();
    }
  });

  // @scenario failure=server_5xx_persistent
  // @effects auth_check_progress_announced
  it('확인 불가로 끝나면 표시를 걷지 않고, 오류 화면을 그릴 때 걷는다', async () => {
    AuthManager.resetInstance();
    (app as any).installAuthCheckIndicator(AuthManager.getInstance());
    const indicator = (): HTMLElement | null => document.querySelector('[data-g7-auth-check-indicator]');
    const am = AuthManager.getInstance() as any;

    am.emit('authCheckStart', { type: 'admin' });
    am.emit('authCheckRetry', { type: 'admin', attempt: 1 });
    am.emit('authCheckRetryEnd', { type: 'admin', status: 'unavailable' });
    am.emit('authCheckEnd', { type: 'admin', status: 'unavailable' });
    expect(indicator()).not.toBeNull();

    await (app as any).handleAuthUnavailable('/admin/dashboard');
    expect(indicator()).toBeNull();
    expect(document.getElementById('app')!.textContent).toContain('요청을 처리하지 못했습니다');
  });

  // @scenario failure=server_5xx_persistent
  // @effects auth_check_progress_announced
  it('확인 불가로 끝났지만 공개 화면이 그려지면(오류 화면 없음) 화면 처리에서 걷는다', () => {
    AuthManager.resetInstance();
    (app as any).installAuthCheckIndicator(AuthManager.getInstance());
    const am = AuthManager.getInstance() as any;
    am.emit('authCheckStart', { type: 'user' });
    am.emit('authCheckEnd', { type: 'user', status: 'unavailable' });
    expect(document.querySelector('[data-g7-auth-check-indicator]')).not.toBeNull();

    (app as any).hideAuthCheckIndicator();
    expect(document.querySelector('[data-g7-auth-check-indicator]')).toBeNull();
  });
});
