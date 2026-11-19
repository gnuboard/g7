// e2e:allow Vitest 단위 시험 — 같은 흐름의 브라우저 종단은 tests/Playwright/specs/sso-oidc-mock.spec.ts·sso-saml-mock.spec.ts, 그 밖의 축은 F1 Chrome MCP 계획서 §10 M1(W40)
/**
 * @file flow.test.ts
 * @description 흐름 영역 핸들러 — 같은 출처 플러그인 시작 경로로만 전체 페이지 이동, 시작 티켓 API 응답 처리.
 */
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { safeStartPath, startLoginHandler, startWithTicketHandler } from '../../handlers/flow';
import { handlerMap } from '../../handlers';

describe('safeStartPath', () => {
  /**
   * @scenario url=same_origin_start|foreign|other_path|protocol_relative
   * @effects only_plugin_start_paths
   */
  it.each([
    ['/plugins/sirsoft-sso/login/corp?guard=user', '/plugins/sirsoft-sso/login/corp?guard=user'],
    [`${window.location.origin}/plugins/sirsoft-sso/login/corp`, '/plugins/sirsoft-sso/login/corp'],
    ['https://evil.test/plugins/sirsoft-sso/login/corp', null],
    ['//evil.test/plugins/sirsoft-sso/login/corp', null],
    ['/mypage', null],
    ['/plugins/sirsoft-sso/mock/oidc/authorize', null],
    ['', null],
    [42, null],
  ])('%s → %s', (input, expected) => {
    expect(safeStartPath(input)).toBe(expected);
  });
});

describe('핸들러', () => {
  const assign = vi.fn();
  const dispatch = vi.fn();
  let originalLocation: Location;

  beforeEach(() => {
    originalLocation = window.location;
    Object.defineProperty(window, 'location', { configurable: true, value: { ...originalLocation, origin: originalLocation.origin, assign } });
    (window as any).G7Core = { dispatch, t: (k: string) => k, api: { post: vi.fn() } };
    assign.mockReset();
    dispatch.mockReset();
  });

  afterEach(() => {
    Object.defineProperty(window, 'location', { configurable: true, value: originalLocation });
    delete (window as any).G7Core;
  });

  /**
   * @scenario handler=startLogin, url=valid|invalid
   * @effects full_page_navigation_or_error_toast
   */
  it('startLogin 은 허용 경로만 이동하고 아니면 오류 토스트', () => {
    expect(startLoginHandler({ params: { url: '/plugins/sirsoft-sso/login/corp' } })).toBe(true);
    expect(assign).toHaveBeenCalledWith('/plugins/sirsoft-sso/login/corp');

    expect(startLoginHandler({ params: { url: 'https://evil.test' } })).toBe(false);
    expect(dispatch).toHaveBeenCalledWith(expect.objectContaining({ handler: 'toast' }));
  });

  /**
   * @scenario handler=startWithTicket, endpoint=plugin|foreign, response=start_url
   * @effects navigates_to_ticket_url
   */
  it('startWithTicket 은 플러그인 API 의 start_url 로 이동한다', async () => {
    (window as any).G7Core.api.post.mockResolvedValue({ data: { start_url: '/plugins/sirsoft-sso/login/corp?ticket=abc' } });

    await expect(startWithTicketHandler({ params: { endpoint: '/plugins/sirsoft-sso/me/links/start', body: { idp: 'corp' } } })).resolves.toBe(true);
    expect((window as any).G7Core.api.post).toHaveBeenCalledWith('/plugins/sirsoft-sso/me/links/start', { idp: 'corp' });
    expect(assign).toHaveBeenCalledWith('/plugins/sirsoft-sso/login/corp?ticket=abc');

    await expect(startWithTicketHandler({ params: { endpoint: '/api/other' } })).resolves.toBe(false);
  });

  /**
   * @scenario registry=handler_map
   * @effects flow_handlers_exported
   */
  it('handlerMap 은 흐름 핸들러를 싣는다', () => {
    expect(Object.keys(handlerMap)).toEqual(expect.arrayContaining(['startLogin', 'startWithTicket']));
  });
});
