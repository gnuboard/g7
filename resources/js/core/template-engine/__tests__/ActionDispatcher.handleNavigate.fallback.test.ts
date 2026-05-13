/**
 * ActionDispatcher handleNavigate fallback 테스트
 *
 * 미등록 라우트로 navigate 시 fallback 핸들러(기본 openWindow)로 분기 검증.
 *
 * @since engine-v1.40.0
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher } from '../ActionDispatcher';
import { Logger } from '../../utils/Logger';

vi.mock('../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({
      login: vi.fn(),
      logout: vi.fn(),
    })),
  },
}));

vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({
    getToken: vi.fn(),
  })),
}));

describe('ActionDispatcher - handleNavigate fallback (engine-v1.40.0)', () => {
  let dispatcher: ActionDispatcher;
  let mockNavigate: ReturnType<typeof vi.fn>;
  let mockMatch: ReturnType<typeof vi.fn>;
  let mockWindowOpen: ReturnType<typeof vi.fn>;
  let originalWindowOpen: typeof window.open;

  beforeEach(() => {
    mockNavigate = vi.fn();
    dispatcher = new ActionDispatcher({ navigate: mockNavigate });
    Logger.getInstance().setDebug(false);

    mockMatch = vi.fn();
    (window as any).__templateApp = {
      getRouter: () => ({
        match: mockMatch,
      }),
    };

    originalWindowOpen = window.open;
    mockWindowOpen = vi.fn();
    window.open = mockWindowOpen as any;
  });

  afterEach(() => {
    window.open = originalWindowOpen;
    delete (window as any).__templateApp;
    vi.clearAllMocks();
  });

  it('라우트 매칭 성공 시 정상 navigate — fallback 미호출', async () => {
    mockMatch.mockReturnValue({ route: { path: '/admin/users' }, params: {} });

    await dispatcher.dispatchAction({
      type: 'click',
      handler: 'navigate',
      params: { path: '/admin/users' },
    });

    expect(mockMatch).toHaveBeenCalledWith('/admin/users');
    expect(mockNavigate).toHaveBeenCalledWith('/admin/users', { replace: false });
    expect(mockWindowOpen).not.toHaveBeenCalled();
  });

  it('라우트 매칭 실패 + fallback 미지정 → 기본 openWindow', async () => {
    mockMatch.mockReturnValue(null);

    await dispatcher.dispatchAction({
      type: 'click',
      handler: 'navigate',
      params: { path: '/shop/orders/123' },
    });

    expect(mockNavigate).not.toHaveBeenCalled();
    expect(mockWindowOpen).toHaveBeenCalledWith('/shop/orders/123', '_blank');
  });

  it('라우트 매칭 실패 + fallback: false → 기존 동작(navigate 강행)', async () => {
    mockMatch.mockReturnValue(null);

    await dispatcher.dispatchAction({
      type: 'click',
      handler: 'navigate',
      params: { path: '/shop/orders/123', fallback: false },
    });

    expect(mockNavigate).toHaveBeenCalledWith('/shop/orders/123', { replace: false });
    expect(mockWindowOpen).not.toHaveBeenCalled();
  });

  it('라우트 매칭 실패 + fallback: string → 해당 핸들러로 분기', async () => {
    mockMatch.mockReturnValue(null);

    await dispatcher.dispatchAction({
      type: 'click',
      handler: 'navigate',
      params: { path: '/shop/orders/123', fallback: 'openWindow' },
    });

    expect(mockNavigate).not.toHaveBeenCalled();
    expect(mockWindowOpen).toHaveBeenCalledWith('/shop/orders/123', '_blank');
  });

  it('라우트 매칭 실패 + fallback: { handler, params } → 상세 지정 동작', async () => {
    mockMatch.mockReturnValue(null);

    await dispatcher.dispatchAction({
      type: 'click',
      handler: 'navigate',
      params: {
        path: '/shop/orders/123',
        fallback: { handler: 'openWindow', params: { target: '_blank' } },
      },
    });

    expect(mockNavigate).not.toHaveBeenCalled();
    expect(mockWindowOpen).toHaveBeenCalledWith('/shop/orders/123', '_blank');
  });

  it('쿼리 파라미터가 있는 경로도 fallback pathname만 매칭', async () => {
    mockMatch.mockReturnValue(null);

    await dispatcher.dispatchAction({
      type: 'click',
      handler: 'navigate',
      params: {
        path: '/shop/orders',
        query: { id: '123', tab: 'detail' },
      },
    });

    expect(mockMatch).toHaveBeenCalledWith('/shop/orders');
    expect(mockNavigate).not.toHaveBeenCalled();
    // query가 병합된 finalPath가 openWindow로 전달됨
    expect(mockWindowOpen).toHaveBeenCalled();
    const openedUrl = mockWindowOpen.mock.calls[0][0];
    expect(openedUrl).toContain('/shop/orders?');
    expect(openedUrl).toContain('id=123');
    expect(openedUrl).toContain('tab=detail');
  });

  it('replace: true 경로는 fallback 미적용 (쿼리 갱신 전용)', async () => {
    mockMatch.mockReturnValue(null);
    (window as any).G7Core = {
      updateQueryParams: vi.fn().mockResolvedValue(undefined),
    };

    await dispatcher.dispatchAction({
      type: 'click',
      handler: 'navigate',
      params: { path: '/admin/users', query: { page: 2 }, replace: true },
    });

    // replace 경로는 updateQueryParams 사용 — fallback 미적용
    expect((window as any).G7Core.updateQueryParams).toHaveBeenCalled();
    expect(mockWindowOpen).not.toHaveBeenCalled();

    delete (window as any).G7Core;
  });

  it('router를 가져올 수 없으면 fallback 미적용 (정상 navigate)', async () => {
    (window as any).__templateApp = undefined;

    await dispatcher.dispatchAction({
      type: 'click',
      handler: 'navigate',
      params: { path: '/shop/orders/123' },
    });

    expect(mockNavigate).toHaveBeenCalledWith('/shop/orders/123', { replace: false });
    expect(mockWindowOpen).not.toHaveBeenCalled();
  });
});
