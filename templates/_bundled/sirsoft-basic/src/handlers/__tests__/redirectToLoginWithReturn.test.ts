/**
 * @file redirectToLoginWithReturn.test.ts
 * @description 비로그인 게시판/비밀글 진입 → /login?redirect=현재경로 핸들러 단위 검증 (이슈 #413 item 28)
 *
 * 핸들러는 window.location(pathname+search)을 직접 읽어 redirect 를 보존하고,
 * G7Core.dispatch 로 navigate 를 발화한다. errorHandling context 에 route/query 가 없는
 * 제약을 우회하는 핵심 동작이므로 실제 호출 인자를 단언한다(거짓 통과 방지).
 *
 * @scenario has_query:with_query,without_query
 * @effects redirect_preserves_pathname,redirect_preserves_query_string
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { redirectToLoginWithReturnHandler } from '../redirectToLoginWithReturn';

/**
 * jsdom 의 window.location 을 지정 경로로 교체한다.
 *
 * @param pathname 경로
 * @param search 쿼리스트링 (예: "?page=2")
 */
function mockLocation(pathname: string, search = ''): void {
  Object.defineProperty(window, 'location', {
    configurable: true,
    writable: true,
    value: { pathname, search } as unknown as Location,
  });
}

describe('redirectToLoginWithReturnHandler — /login?redirect=현재경로 (이슈 #413 item 28)', () => {
  let dispatchSpy: ReturnType<typeof vi.fn>;
  const originalLocation = window.location;

  beforeEach(() => {
    dispatchSpy = vi.fn().mockResolvedValue(undefined);
    (window as any).G7Core = { dispatch: dispatchSpy };
  });

  afterEach(() => {
    Object.defineProperty(window, 'location', {
      configurable: true,
      writable: true,
      value: originalLocation,
    });
    delete (window as any).G7Core;
    vi.restoreAllMocks();
  });

  it('query 없는 경로에서 redirect 가 pathname 으로 보존되어야 한다', async () => {
    mockLocation('/board/members');

    await redirectToLoginWithReturnHandler();

    expect(dispatchSpy).toHaveBeenCalledTimes(1);
    expect(dispatchSpy).toHaveBeenCalledWith({
      handler: 'navigate',
      params: {
        path: '/login',
        query: { redirect: '/board/members' },
      },
    });
  });

  it('query 포함 경로에서 redirect 가 search 까지 보존되어야 한다', async () => {
    mockLocation('/board/members', '?page=2');

    await redirectToLoginWithReturnHandler();

    const call = dispatchSpy.mock.calls[0][0];
    expect(call.handler).toBe('navigate');
    expect(call.params.path).toBe('/login');
    expect(call.params.query.redirect).toBe('/board/members?page=2');
  });

  it('navigate 핸들러로만 발화한다 (redirect 표현식 미사용)', async () => {
    mockLocation('/board/members/123');

    await redirectToLoginWithReturnHandler();

    const call = dispatchSpy.mock.calls[0][0];
    expect(call.handler).toBe('navigate');
    expect(call.params.query.redirect).toBe('/board/members/123');
  });

  it('G7Core.dispatch 가 없으면 throw 하지 않고 조용히 반환한다', async () => {
    mockLocation('/board/members');
    delete (window as any).G7Core;

    await expect(redirectToLoginWithReturnHandler()).resolves.toBeUndefined();
  });
});
