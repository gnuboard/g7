/**
 * ActionDispatcher identity_target 기능 테스트.
 *
 * apiCall 액션의 `identity_target` 속성이 표현식 평가되어, 428 IDV 인터셉트 시
 * IdentityGuardInterceptor.handle 의 target 인자로 전달되는지 검증.
 *
 * 비회원 주문 등 비로그인 흐름의 핵심 — 서버 428 payload 에는 target 이 없고 흐름이 선언한다.
 *
 * @since engine-v1.50.0
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher, ActionDefinition } from '../ActionDispatcher';
import { IdentityGuardInterceptor } from '../../identity/IdentityGuardInterceptor';
import { Logger } from '../../utils/Logger';

vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({ getToken: vi.fn(() => null) })),
}));

// IdentityGuardInterceptor 의 인터셉트 메서드만 모킹 (handle 호출 인자 검증)
vi.mock('../../identity/IdentityGuardInterceptor', () => ({
  IdentityGuardInterceptor: {
    isIdentityRequired: vi.fn(),
    handle: vi.fn(),
  },
}));

const mockedGuard = IdentityGuardInterceptor as unknown as {
  isIdentityRequired: ReturnType<typeof vi.fn>;
  handle: ReturnType<typeof vi.fn>;
};

describe('ActionDispatcher - apiCall identity_target', () => {
  let dispatcher: ActionDispatcher;
  let mockFetch: ReturnType<typeof vi.fn>;
  let originalFetch: typeof fetch;

  const createMockEvent = () =>
    ({ preventDefault: vi.fn(), type: 'click', target: null }) as unknown as Event;

  beforeEach(() => {
    dispatcher = new ActionDispatcher({ navigate: vi.fn() });
    Logger.getInstance().setDebug(false);

    originalFetch = globalThis.fetch;
    // 기본: 428 응답 (IDV 필요)
    mockFetch = vi.fn().mockResolvedValue({
      status: 428,
      ok: false,
      json: () =>
        Promise.resolve({
          success: false,
          error_code: 'identity_verification_required',
          verification: { return_request: { method: 'POST', url: '/api/orders' } },
        }),
    });
    globalThis.fetch = mockFetch as unknown as typeof fetch;

    Object.defineProperty(document, 'cookie', {
      value: 'XSRF-TOKEN=test-csrf-token',
      writable: true,
    });

    mockedGuard.isIdentityRequired.mockReset();
    mockedGuard.handle.mockReset();
  });

  afterEach(() => {
    Logger.getInstance().setDebug(false);
    globalThis.fetch = originalFetch;
    vi.clearAllMocks();
  });

  it('428 인터셉트 시 평가된 identity_target 을 handle 의 target 인자로 전달한다', async () => {
    mockedGuard.isIdentityRequired.mockReturnValue(true);
    mockedGuard.handle.mockResolvedValue(null); // 재실행 없이 종료 (인자 검증만)

    const action: ActionDefinition = {
      type: 'click',
      handler: 'apiCall',
      target: '/api/orders',
      identity_target: {
        email: '{{_local.orderer.email}}',
        phone: '{{_local.orderer.phone}}',
      },
      params: { method: 'POST', body: { x: 1 } },
    };

    // _local 은 componentContext.state (createHandler 3번째 인자)에서 평가됨
    const componentContext = {
      state: { orderer: { email: 'guest@example.com', phone: '01012345678' } },
    };

    const handler = dispatcher.createHandler(action, {}, componentContext);
    await handler(createMockEvent());

    expect(mockedGuard.handle).toHaveBeenCalledOnce();
    const callArgs = mockedGuard.handle.mock.calls[0];
    // 3번째 인자 = 평가된 identity_target
    expect(callArgs[2]).toEqual({
      email: 'guest@example.com',
      phone: '01012345678',
    });
  });

  it('identity_target 미선언 apiCall 은 handle 의 target 인자가 undefined 다 (하위호환)', async () => {
    mockedGuard.isIdentityRequired.mockReturnValue(true);
    mockedGuard.handle.mockResolvedValue(null);

    const action: ActionDefinition = {
      type: 'click',
      handler: 'apiCall',
      target: '/api/orders',
      params: { method: 'POST', body: { x: 1 } },
    };

    const handler = dispatcher.createHandler(action, { state: { _global: {}, _local: {} } });
    await handler(createMockEvent());

    expect(mockedGuard.handle).toHaveBeenCalledOnce();
    expect(mockedGuard.handle.mock.calls[0][2]).toBeUndefined();
  });

  it('428 이 아니면 IdentityGuard.handle 을 호출하지 않는다', async () => {
    mockFetch.mockResolvedValue({
      status: 200,
      ok: true,
      json: () => Promise.resolve({ success: true, data: {} }),
    });
    mockedGuard.isIdentityRequired.mockReturnValue(false);

    const action: ActionDefinition = {
      type: 'click',
      handler: 'apiCall',
      target: '/api/orders',
      identity_target: { email: '{{_local.orderer.email}}' },
      params: { method: 'POST' },
    };

    const handler = dispatcher.createHandler(action, {}, { state: { orderer: { email: 'a@b.com' } } });
    await handler(createMockEvent());

    expect(mockedGuard.handle).not.toHaveBeenCalled();
  });
});
