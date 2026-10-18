/**
 * 시스템 기본 401 처리(`navigate {{auth.loginPath}}`)와 세션 만료 이동 중 오류 처리
 *
 * 라이브 실측(7.1.0 영역 A·B 후속 O-F1): 토큰이 만료된 채 비회원 허용 화면(게시판 글쓰기)을 열면
 * 로그인 화면으로 옮겨지기 직전 「요청을 보내지 못했습니다. 네트워크 연결을 확인…」 토스트가 떴다.
 *
 * 원인 두 겹:
 * 1. 기본 401 처리는 `{{auth.loginPath}}` 로 이동하는데, 어느 실행 문맥도 `auth` 를 싣지 않아 경로가
 *    빈 값으로 풀렸다 → 이동 핸들러가 빈 경로로 TypeError → 그 TypeError 를 「네트워크 실패」 로 분류해
 *    기본 처리(토스트)가 네트워크 안내를 띄웠다.
 * 2. 같은 401 로 ApiClient 가 이미 세션 만료 로그인 화면으로 문서를 옮기는 중인데도 데이터소스·액션의
 *    오류 처리가 그대로 돌았다 — 떠나는 문서의 실패는 화면에 그리지 않는다(이탈 가드와 같은 원칙).
 *
 * @since engine-v1.78.2
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';
import { getErrorHandlingResolver } from '../../error/ErrorHandlingResolver';
import { DEFAULT_ERROR_HANDLING } from '../../types/ErrorHandling';
import { markDocumentLeaving, resetUnloadGuardForTesting } from '../networkResilience';
import { Logger } from '../../utils/Logger';

vi.mock('../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({
      login: vi.fn(),
      logout: vi.fn(),
      getConfig: vi.fn(() => ({ loginPath: '/login' })),
      getLoginRedirectUrl: vi.fn(() => '/login'),
    })),
  },
}));

vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({
    getToken: vi.fn(() => null),
  })),
}));

describe('[case:network-5] 기본 401 처리 · 세션 만료 이동 중 오류 처리 (O-F1)', () => {
  let dispatcher: ActionDispatcher;
  let navigate: ReturnType<typeof vi.fn>;
  let globalUpdates: any[];

  beforeEach(() => {
    Logger.getInstance().setDebug(false);
    resetUnloadGuardForTesting();
    navigate = vi.fn();
    globalUpdates = [];
    dispatcher = new ActionDispatcher({ navigate });
    dispatcher.setGlobalStateUpdater((updates: any) => {
      globalUpdates.push(updates);
    });
    (window as any).G7Core = { state: { get: () => ({ toasts: [] }) } };
    (window as any).__templateApp = { getRouter: () => ({ match: () => ({ route: {} }) }) };
  });

  afterEach(() => {
    resetUnloadGuardForTesting();
    vi.restoreAllMocks();
    delete (window as any).__templateApp;
  });

  const toasts = () => globalUpdates.flatMap((u) => u.toasts ?? []);

  // @scenario path=data_source_error, state=no_redirect_pending
  // @effects default_unauthorized_navigates_to_login_path
  it('데이터소스 401 의 기본 처리는 로그인 경로(복귀 경로 포함)로 이동하고 네트워크 토스트를 띄우지 않는다', async () => {
    dispatcher.setLoginPathResolver(() => '/login?redirect=%2Fboard%2Fqna%2Fwrite');
    const resolver = getErrorHandlingResolver();

    await resolver.execute(DEFAULT_ERROR_HANDLING[401] as any, { status: 401, message: 'Unauthenticated.' });

    expect(navigate.mock.calls[0]?.[0]).toBe('/login?redirect=%2Fboard%2Fqna%2Fwrite');
    expect(toasts()).toEqual([]);
  });

  // @scenario path=action_error, state=no_redirect_pending
  // @effects default_unauthorized_navigates_to_login_path
  it('apiCall 401 의 기본 처리도 로그인 경로로 이동하고 네트워크 토스트를 띄우지 않는다', async () => {
    dispatcher.setLoginPathResolver(() => '/login?redirect=%2Fmypage');
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ message: 'Unauthenticated.' }), { status: 401, statusText: 'Unauthorized' })
    );

    await dispatcher.dispatchAction({ type: 'click', handler: 'apiCall', target: '/api/me', params: { method: 'GET' } } as any, {});

    expect(navigate.mock.calls[0]?.[0]).toBe('/login?redirect=%2Fmypage');
    expect(toasts()).toEqual([]);
  });

  // @scenario path=data_source_error, state=session_expiry_redirect_pending
  // @effects errors_suppressed_while_leaving_for_login
  it('세션 만료로 로그인 화면으로 옮기는 중이면 데이터소스·액션 오류 처리를 돌리지 않는다', async () => {
    markDocumentLeaving();
    const resolver = getErrorHandlingResolver();

    await resolver.execute(DEFAULT_ERROR_HANDLING.default as any, { status: 500, message: 'boom' });
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(JSON.stringify({ message: 'Unauthenticated.' }), { status: 401, statusText: 'Unauthorized' })
    );
    await dispatcher.dispatchAction({ type: 'click', handler: 'apiCall', target: '/api/me', params: { method: 'GET' } } as any, {});

    expect(navigate).not.toHaveBeenCalled();
    expect(toasts()).toEqual([]);
  });

  // @scenario path=action_error, state=no_redirect_pending
  // @effects default_unauthorized_navigates_to_login_path
  it('빈 경로로 이동하라는 액션은 조용히 건너뛴다 (TypeError → 「네트워크 실패」 오분류 없음)', async () => {
    await dispatcher.executeAction({ type: 'click', handler: 'navigate', params: { path: '' } } as any, {} as any);

    expect(navigate).not.toHaveBeenCalled();
    expect(toasts()).toEqual([]);
  });
});
