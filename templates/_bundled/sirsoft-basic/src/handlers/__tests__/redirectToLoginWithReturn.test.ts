/**
 * @file redirectToLoginWithReturn.test.ts
 * @description 비로그인 게시판/비밀글 진입 → /login?redirect=현재경로 핸들러 단위 검증 (이슈 #413 item 28)
 *
 * 핸들러는 window.location(pathname+search)을 직접 읽어 redirect 를 보존하고,
 * G7Core.dispatch 로 navigate 를 발화한다. errorHandling context 에 route/query 가 없는
 * 제약을 우회하는 핵심 동작이므로 실제 호출 인자를 단언한다(거짓 통과 방지).
 *
 * 마킹 표기 주의 — `extractTestMarkings` 는 `key=value` 를 쉼표로 끊어 읽는다. 종전 이 파일은
 * `has_query:with_query,without_query` 로 적어 콜론 때문에 조합이 **하나도 파싱되지 않았고**,
 * 그 사실은 `test-scenario-coverage` 룰의 `appliesTo` 에 `templates/_bundled/**` 가 빠져 있어
 * 드러나지 않았다. 아래 「진입 지점 × query 유무 cross product」 블록이 6조합을 실제로 실행한다.
 *
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

/**
 * 진입 지점 × query 유무 cross product (6조합).
 *
 * 세 진입 지점(목록 401 / 상세 401 / 비밀글 로그인 버튼)은 모두 같은 핸들러를 발화하고,
 * 진입 지점별로 실제 경로 모양이 다르다. 레이아웃이 그 핸들러를 배선하고 있다는 사실은
 * 짝 레이아웃 테스트(`board-401-error-handling.test.tsx` · `board-secret-post-login-redirect.test.tsx`)가
 * 고정하고, 이 블록은 **각 진입 지점의 실제 경로에서 redirect 보존이 성립하는지**를 조합 단위로 고정한다.
 */
describe('진입 지점 × query 유무 cross product — redirect 보존 (이슈 #413 item 28)', () => {
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

  /**
   * 조합 1건을 실행하고 redirect 가 pathname(+search) 그대로 보존됐는지 단언한다.
   *
   * @param pathname 진입 지점의 실제 경로
   * @param search 쿼리스트링 ('' 이면 query 없는 조합)
   */
  async function assertRedirectPreserved(pathname: string, search: string): Promise<void> {
    mockLocation(pathname, search);

    await redirectToLoginWithReturnHandler();

    expect(dispatchSpy).toHaveBeenCalledTimes(1);
    const call = dispatchSpy.mock.calls[0][0];
    expect(call.handler).toBe('navigate');
    expect(call.params.path).toBe('/login');
    expect(call.params.query.redirect).toBe(`${pathname}${search}`);
  }

  /**
   * @scenario entry_point=board_list_401,has_query=without_query
   * @effects redirect_preserves_pathname,login_returns_to_original_path
   */
  it('목록 401 · query 없음 — /board/{slug} 가 보존된다', async () => {
    await assertRedirectPreserved('/board/notice', '');
  });

  /**
   * @scenario entry_point=board_list_401,has_query=with_query
   * @effects redirect_preserves_query_string,login_returns_to_original_path
   */
  it('목록 401 · query 있음 — 페이지 파라미터까지 보존된다', async () => {
    await assertRedirectPreserved('/board/notice', '?page=2');
  });

  /**
   * @scenario entry_point=board_show_401,has_query=without_query
   * @effects redirect_preserves_pathname,login_returns_to_original_path
   */
  it('상세 401 · query 없음 — /board/{slug}/posts/{id} 가 보존된다', async () => {
    await assertRedirectPreserved('/board/notice/posts/123', '');
  });

  /**
   * @scenario entry_point=board_show_401,has_query=with_query
   * @effects redirect_preserves_query_string,login_returns_to_original_path
   */
  it('상세 401 · query 있음 — 댓글 페이지 파라미터까지 보존된다', async () => {
    await assertRedirectPreserved('/board/notice/posts/123', '?comment_page=3');
  });

  /**
   * @scenario entry_point=secret_post_login_button,has_query=without_query
   * @effects secret_button_redirect_not_empty,login_returns_to_original_path
   */
  it('비밀글 로그인 버튼 · query 없음 — redirect 가 빈 값이 아니다', async () => {
    await assertRedirectPreserved('/board/notice/posts/456', '');
  });

  /**
   * @scenario entry_point=secret_post_login_button,has_query=with_query
   * @effects secret_button_redirect_not_empty,redirect_preserves_query_string
   */
  it('비밀글 로그인 버튼 · query 있음 — search 까지 보존된다', async () => {
    await assertRedirectPreserved('/board/notice/posts/456', '?from=list');
  });
});
