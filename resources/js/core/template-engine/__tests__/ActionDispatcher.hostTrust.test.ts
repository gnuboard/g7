/**
 * 교차 출처 대상의 창 열기·전역 헤더 회귀 (요청 Host·외부 주소 비신뢰)
 *
 * - openWindow 의 외부 주소 새 창은 https 만 열고 새 창이 opener 를 쥐지 못하게 한다(역방향 탭내빙 차단).
 *   같은 출처 경로는 종전과 같이 연다.
 * - 레이아웃 전역 헤더(globalHeaders — 비회원 주문 조회 토큰·비밀글 열람 토큰 등)는 같은 출처 요청에만
 *   붙는다. Bearer 토큰과 같은 판정(`isSameOriginRequestUrl`)을 쓴다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';
import { DataSourceManager, DataSource } from '../DataSourceManager';
import { Logger } from '../../utils/Logger';

vi.mock('../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({
      login: vi.fn(),
      logout: vi.fn(),
      isAuthenticated: vi.fn(() => false),
    })),
  },
}));

vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({
    getToken: vi.fn(() => null),
    get: vi.fn(),
  })),
}));

const GUEST_HEADER_RULES = [{ pattern: '*', headers: { 'X-Guest-Order-Token': 'guest-secret' } }];

describe('ActionDispatcher — openWindow 외부 주소', () => {
  let dispatcher: ActionDispatcher;
  let openSpy: ReturnType<typeof vi.fn>;
  let originalOpen: typeof window.open;
  let opened: { opener: unknown; location: { href: string } };

  beforeEach(() => {
    dispatcher = new ActionDispatcher({ navigate: vi.fn() });
    Logger.getInstance().setDebug(false);
    opened = { opener: window, location: { href: '' } };
    originalOpen = window.open;
    openSpy = vi.fn(() => opened);
    window.open = openSpy as unknown as typeof window.open;
  });

  afterEach(() => {
    window.open = originalOpen;
  });

  // @scenario host_header=forged, sink=front_open_window
  // @effects external_window_has_no_opener_and_https_only
  it('외부 https 주소는 opener 를 끊은 빈 창에 싣는다', async () => {
    await dispatcher.dispatchAction({ type: 'click', handler: 'openWindow', target: 'https://pg.example.com/receipt?id=1' });

    expect(openSpy).toHaveBeenCalledWith('', '_blank');
    expect(opened.opener).toBeNull();
    expect(opened.location.href).toBe('https://pg.example.com/receipt?id=1');
  });

  // @scenario host_header=forged, sink=front_open_window
  // @effects external_window_has_no_opener_and_https_only
  it('외부 http 주소는 열지 않는다', async () => {
    await dispatcher.dispatchAction({ type: 'click', handler: 'openWindow', target: 'http://pg.example.com/receipt' });

    expect(openSpy).not.toHaveBeenCalled();
  });

  // @scenario host_header=canonical, sink=front_open_window
  // @effects same_origin_window_unchanged
  it('같은 출처 경로는 종전처럼 바로 연다', async () => {
    await dispatcher.dispatchAction({ type: 'click', handler: 'openWindow', target: '/shop/orders/1' });

    expect(openSpy).toHaveBeenCalledWith('/shop/orders/1', '_blank');
  });
});

describe('ActionDispatcher — 전역 헤더 같은 출처 한정', () => {
  let dispatcher: ActionDispatcher;
  let fetchSpy: ReturnType<typeof vi.fn>;
  let originalFetch: typeof fetch;

  beforeEach(() => {
    dispatcher = new ActionDispatcher({ navigate: vi.fn() });
    Logger.getInstance().setDebug(false);
    originalFetch = globalThis.fetch;
    fetchSpy = vi.fn().mockResolvedValue({ ok: true, status: 200, json: () => Promise.resolve({ success: true, data: {} }) });
    globalThis.fetch = fetchSpy as unknown as typeof fetch;
    dispatcher.setGlobalHeaders(GUEST_HEADER_RULES);
  });

  afterEach(() => {
    globalThis.fetch = originalFetch;
  });

  // @scenario host_header=forged, sink=front_global_headers
  // @effects global_headers_only_for_same_origin
  it('교차 출처 apiCall 에는 전역 헤더를 싣지 않는다', async () => {
    await dispatcher.dispatchAction({
      type: 'click',
      handler: 'apiCall',
      target: 'https://evil.example/collect',
      params: { method: 'GET', auth_mode: 'none' },
    });

    const call = fetchSpy.mock.calls.find((c: unknown[]) => c[0] === 'https://evil.example/collect');
    expect(call).toBeDefined();
    expect((call![1] as RequestInit).headers).not.toHaveProperty('X-Guest-Order-Token');
  });

  // @scenario host_header=canonical, sink=front_global_headers
  // @effects global_headers_only_for_same_origin
  it('같은 출처 apiCall 에는 종전처럼 싣는다', async () => {
    await dispatcher.dispatchAction({
      type: 'click',
      handler: 'apiCall',
      target: '/api/shop/orders/1',
      params: { method: 'GET', auth_required: false },
    });

    const call = fetchSpy.mock.calls.find((c: unknown[]) => c[0] === '/api/shop/orders/1');
    expect((call![1] as RequestInit).headers).toHaveProperty('X-Guest-Order-Token', 'guest-secret');
  });
});

describe('DataSourceManager — 전역 헤더 같은 출처 한정', () => {
  let manager: DataSourceManager;
  let fetchSpy: ReturnType<typeof vi.fn>;
  let originalFetch: typeof fetch;

  beforeEach(() => {
    manager = new DataSourceManager();
    originalFetch = globalThis.fetch;
    fetchSpy = vi.fn().mockResolvedValue({ ok: true, status: 200, json: () => Promise.resolve({ data: {} }) });
    globalThis.fetch = fetchSpy as unknown as typeof fetch;
    manager.setGlobalHeaders(GUEST_HEADER_RULES);
  });

  afterEach(() => {
    globalThis.fetch = originalFetch;
    manager.clearCache();
  });

  const source = (endpoint: string): DataSource => ({
    id: `ds-${endpoint.length}`,
    type: 'api',
    endpoint,
    method: 'GET',
    auth_required: false,
    auto_fetch: true,
  });

  // @scenario host_header=forged, sink=front_global_headers
  // @effects global_headers_only_for_same_origin
  it('교차 출처 데이터소스에는 전역 헤더를 싣지 않고, 같은 출처에는 싣는다', async () => {
    await manager.fetchDataSources([source('https://evil.example/api/data'), source('/api/shop/orders')]);

    const external = fetchSpy.mock.calls.find((c: unknown[]) => c[0] === 'https://evil.example/api/data');
    const internal = fetchSpy.mock.calls.find((c: unknown[]) => c[0] === '/api/shop/orders');
    expect(external).toBeDefined();
    expect((external![1] as RequestInit).headers).not.toHaveProperty('X-Guest-Order-Token');
    expect((internal![1] as RequestInit).headers).toHaveProperty('X-Guest-Order-Token', 'guest-secret');
  });
});
