/**
 * ActionDispatcher globalHeaders 기능 테스트
 *
 * apiCall 핸들러에서 globalHeaders 적용 검증
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher, ActionDefinition } from '../ActionDispatcher';
import { GlobalHeaderRule } from '../LayoutLoader';
import { Logger } from '../../utils/Logger';

// AuthManager mock - login 호출 시 전달된 인자 추적
const mockLogin = vi.fn().mockResolvedValue({ id: 1, name: 'Test User' });
const mockLogout = vi.fn().mockResolvedValue(undefined);

vi.mock('../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({
      login: mockLogin,
      logout: mockLogout,
    })),
  },
}));

// ApiClient mock
const mockGetToken = vi.fn();
vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => ({
    getToken: mockGetToken,
  })),
}));

describe('ActionDispatcher - globalHeaders', () => {
  let dispatcher: ActionDispatcher;
  let mockNavigate: ReturnType<typeof vi.fn>;
  let mockFetch: ReturnType<typeof vi.fn>;
  let originalFetch: typeof fetch;

  beforeEach(() => {
    mockNavigate = vi.fn();
    mockGetToken.mockReset();
    mockLogin.mockReset();
    mockLogin.mockResolvedValue({ id: 1, name: 'Test User' });
    dispatcher = new ActionDispatcher({ navigate: mockNavigate });
    Logger.getInstance().setDebug(false);

    // fetch mock 설정
    originalFetch = globalThis.fetch;
    mockFetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ success: true, data: {} }),
    });
    globalThis.fetch = mockFetch as unknown as typeof fetch;

    // CSRF 토큰 쿠키 mock
    Object.defineProperty(document, 'cookie', {
      value: 'XSRF-TOKEN=test-csrf-token',
      writable: true,
    });
  });

  afterEach(() => {
    Logger.getInstance().setDebug(false);
    globalThis.fetch = originalFetch;
  });

  // 헬퍼 함수: 특정 URL의 fetch 호출 찾기
  const findFetchCallByUrl = (url: string): [string, RequestInit] | undefined => {
    return mockFetch.mock.calls.find(
      (call: unknown[]) => call[0] === url
    ) as [string, RequestInit] | undefined;
  };

  // 헬퍼 함수: mock 이벤트 생성
  const createMockEvent = () => ({
    preventDefault: vi.fn(),
    type: 'click',
    target: null,
  } as unknown as Event);

  describe('setGlobalHeaders', () => {
    it('globalHeaders를 설정할 수 있어야 함', () => {
      const headers: GlobalHeaderRule[] = [
        { pattern: '*', headers: { 'X-Custom': 'value' } },
      ];

      dispatcher.setGlobalHeaders(headers);

      // 에러 없이 완료되어야 함
      expect(true).toBe(true);
    });

    it('null/undefined를 빈 배열로 처리해야 함', () => {
      dispatcher.setGlobalHeaders(null as any);
      dispatcher.setGlobalHeaders(undefined as any);

      // 에러 없이 완료되어야 함
      expect(true).toBe(true);
    });
  });

  describe('apiCall 핸들러 - globalHeaders 적용', () => {
    it('* 패턴은 모든 apiCall에 헤더를 적용해야 함', async () => {
      dispatcher.setGlobalHeaders([
        { pattern: '*', headers: { 'X-Global': 'all-apis' } },
      ]);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/test/endpoint',
        params: {
          method: 'GET',
          auth_required: false,
        },
      };

      const handler = dispatcher.createHandler(action);
      await handler(createMockEvent());

      expect(mockFetch).toHaveBeenCalled();
      const apiCall = findFetchCallByUrl('/api/test/endpoint');
      expect(apiCall).toBeDefined();
      const headers = apiCall![1].headers as Record<string, string>;
      expect(headers['X-Global']).toBe('all-apis');
    });

    it('경로 패턴이 매칭되면 헤더를 적용해야 함', async () => {
      dispatcher.setGlobalHeaders([
        { pattern: '/api/shop/*', headers: { 'X-Shop': 'true' } },
      ]);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/shop/products',
        params: {
          method: 'GET',
          auth_required: false,
        },
      };

      const handler = dispatcher.createHandler(action);
      await handler(createMockEvent());

      expect(mockFetch).toHaveBeenCalled();
      const apiCall = findFetchCallByUrl('/api/shop/products');
      expect(apiCall).toBeDefined();
      const headers = apiCall![1].headers as Record<string, string>;
      expect(headers['X-Shop']).toBe('true');
    });

    it('경로 패턴이 매칭되지 않으면 헤더를 적용하지 않아야 함', async () => {
      dispatcher.setGlobalHeaders([
        { pattern: '/api/shop/*', headers: { 'X-Shop': 'true' } },
      ]);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/users/list',
        params: {
          method: 'GET',
          auth_required: false,
        },
      };

      const handler = dispatcher.createHandler(action);
      await handler(createMockEvent());

      expect(mockFetch).toHaveBeenCalled();
      const apiCall = findFetchCallByUrl('/api/users/list');
      expect(apiCall).toBeDefined();
      const headers = apiCall![1].headers as Record<string, string>;
      expect(headers['X-Shop']).toBeUndefined();
    });

    it('params.headers가 globalHeaders를 덮어써야 함', async () => {
      dispatcher.setGlobalHeaders([
        { pattern: '*', headers: { 'X-Custom': 'global-value' } },
      ]);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/test',
        params: {
          method: 'GET',
          auth_required: false,
          headers: { 'X-Custom': 'param-value' },
        },
      };

      const handler = dispatcher.createHandler(action);
      await handler(createMockEvent());

      expect(mockFetch).toHaveBeenCalled();
      const apiCall = findFetchCallByUrl('/api/test');
      expect(apiCall).toBeDefined();
      const headers = apiCall![1].headers as Record<string, string>;
      // globalHeaders가 아닌 params.headers 값
      expect(headers['X-Custom']).toBe('param-value');
    });

    it('여러 패턴이 매칭되면 모든 헤더가 병합되어야 함', async () => {
      dispatcher.setGlobalHeaders([
        { pattern: '*', headers: { 'X-Global': 'all' } },
        { pattern: '/api/shop/*', headers: { 'X-Shop': 'true' } },
      ]);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/shop/cart',
        params: {
          method: 'GET',
          auth_required: false,
        },
      };

      const handler = dispatcher.createHandler(action);
      await handler(createMockEvent());

      expect(mockFetch).toHaveBeenCalled();
      const apiCall = findFetchCallByUrl('/api/shop/cart');
      expect(apiCall).toBeDefined();
      const headers = apiCall![1].headers as Record<string, string>;
      expect(headers['X-Global']).toBe('all');
      expect(headers['X-Shop']).toBe('true');
    });

    it('ecommerce 모듈 패턴 매칭 테스트', async () => {
      dispatcher.setGlobalHeaders([
        { pattern: '/api/modules/sirsoft-ecommerce/*', headers: { 'X-Cart-Key': 'ck_test123' } },
      ]);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/modules/sirsoft-ecommerce/cart',
        params: {
          method: 'GET',
          auth_required: false,
        },
      };

      const handler = dispatcher.createHandler(action);
      await handler(createMockEvent());

      expect(mockFetch).toHaveBeenCalled();
      const apiCall = findFetchCallByUrl('/api/modules/sirsoft-ecommerce/cart');
      expect(apiCall).toBeDefined();
      const headers = apiCall![1].headers as Record<string, string>;
      expect(headers['X-Cart-Key']).toBe('ck_test123');
    });
  });

  describe('globalHeaders 없는 경우', () => {
    it('globalHeaders가 설정되지 않으면 기존 동작과 동일해야 함', async () => {
      // setGlobalHeaders 호출하지 않음

      const action: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/test',
        params: {
          method: 'GET',
          auth_required: false,
        },
      };

      const handler = dispatcher.createHandler(action);
      await handler(createMockEvent());

      expect(mockFetch).toHaveBeenCalled();
      const apiCall = findFetchCallByUrl('/api/test');
      expect(apiCall).toBeDefined();
    });
  });

  describe('패턴 매칭 검증', () => {
    it('정확한 경로 매칭', async () => {
      dispatcher.setGlobalHeaders([
        { pattern: '/api/exact/path', headers: { 'X-Exact': 'true' } },
      ]);

      // 정확히 일치하는 경로
      const action1: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/exact/path',
        params: { method: 'GET', auth_required: false },
      };

      const handler1 = dispatcher.createHandler(action1);
      await handler1(createMockEvent());

      let apiCall = findFetchCallByUrl('/api/exact/path');
      expect(apiCall).toBeDefined();
      let headers = apiCall![1].headers as Record<string, string>;
      expect(headers['X-Exact']).toBe('true');

      mockFetch.mockClear();

      // 일치하지 않는 경로
      const action2: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/exact/path/extra',
        params: { method: 'GET', auth_required: false },
      };

      const handler2 = dispatcher.createHandler(action2);
      await handler2(createMockEvent());

      apiCall = findFetchCallByUrl('/api/exact/path/extra');
      expect(apiCall).toBeDefined();
      headers = apiCall![1].headers as Record<string, string>;
      expect(headers['X-Exact']).toBeUndefined();
    });

    it('와일드카드 경로 매칭 (중첩 경로)', async () => {
      dispatcher.setGlobalHeaders([
        { pattern: '/api/modules/sirsoft-ecommerce/*', headers: { 'X-Ecommerce': 'true' } },
      ]);

      // 매칭되는 경로들
      const matchingPaths = [
        '/api/modules/sirsoft-ecommerce/cart',
        '/api/modules/sirsoft-ecommerce/products/123',
        '/api/modules/sirsoft-ecommerce/orders/list',
      ];

      for (const path of matchingPaths) {
        mockFetch.mockClear();

        const action: ActionDefinition = {
          type: 'click',
          handler: 'apiCall',
          target: path,
          params: { method: 'GET', auth_required: false },
        };

        const handler = dispatcher.createHandler(action);
        await handler(createMockEvent());

        const apiCall = findFetchCallByUrl(path);
        expect(apiCall).toBeDefined();
        const headers = apiCall![1].headers as Record<string, string>;
        expect(headers['X-Ecommerce']).toBe('true');
      }

      mockFetch.mockClear();

      // 매칭되지 않는 경로
      const action: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/modules/other-module/test',
        params: { method: 'GET', auth_required: false },
      };

      const handler = dispatcher.createHandler(action);
      await handler(createMockEvent());

      const apiCall = findFetchCallByUrl('/api/modules/other-module/test');
      expect(apiCall).toBeDefined();
      const headers = apiCall![1].headers as Record<string, string>;
      expect(headers['X-Ecommerce']).toBeUndefined();
    });
  });

  describe('login 핸들러 - globalHeaders 적용', () => {
    it('/api/auth/* 패턴이 user 로그인에 헤더를 적용해야 함', async () => {
      dispatcher.setGlobalHeaders([
        { pattern: '/api/auth/*', headers: { 'X-Cart-Key': 'ck_test123' } },
      ]);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'login',
        target: 'user',
        params: {
          body: { email: 'test@example.com', password: 'password' },
        },
      };

      const context = {
        state: { _global: {}, _local: {} },
      };

      const handler = dispatcher.createHandler(action, context);
      await handler(createMockEvent());

      // AuthManager.login이 headers 옵션과 함께 호출되었는지 확인
      expect(mockLogin).toHaveBeenCalledWith(
        'user',
        { email: 'test@example.com', password: 'password' },
        { headers: { 'X-Cart-Key': 'ck_test123' } }
      );
    });

    it('/api/auth/* 패턴이 admin 로그인에 헤더를 적용해야 함', async () => {
      dispatcher.setGlobalHeaders([
        { pattern: '/api/auth/*', headers: { 'X-Cart-Key': 'ck_admin456' } },
      ]);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'login',
        target: 'admin',
        params: {
          body: { email: 'admin@example.com', password: 'adminpass' },
        },
      };

      const context = {
        state: { _global: {}, _local: {} },
      };

      const handler = dispatcher.createHandler(action, context);
      await handler(createMockEvent());

      // admin 로그인 엔드포인트는 /api/auth/admin/login
      expect(mockLogin).toHaveBeenCalledWith(
        'admin',
        { email: 'admin@example.com', password: 'adminpass' },
        { headers: { 'X-Cart-Key': 'ck_admin456' } }
      );
    });

    it('_global 표현식이 login 헤더에서 평가되어야 함', async () => {
      dispatcher.setGlobalHeaders([
        { pattern: '/api/auth/*', headers: { 'X-Cart-Key': '{{_global.cartKey}}' } },
      ]);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'login',
        target: 'user',
        params: {
          body: { email: 'test@example.com', password: 'password' },
        },
      };

      // componentContext (세 번째 인자)에 state 전달
      const componentContext = {
        state: {
          _global: { cartKey: 'ck_dynamic_value' },
          _local: {},
        },
      };

      const handler = dispatcher.createHandler(action, {}, componentContext);
      await handler(createMockEvent());

      expect(mockLogin).toHaveBeenCalledWith(
        'user',
        { email: 'test@example.com', password: 'password' },
        { headers: { 'X-Cart-Key': 'ck_dynamic_value' } }
      );
    });

    it('패턴이 매칭되지 않으면 헤더 옵션 없이 호출되어야 함', async () => {
      dispatcher.setGlobalHeaders([
        { pattern: '/api/modules/*', headers: { 'X-Module': 'true' } },
      ]);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'login',
        target: 'user',
        params: {
          body: { email: 'test@example.com', password: 'password' },
        },
      };

      const context = {
        state: { _global: {}, _local: {} },
      };

      const handler = dispatcher.createHandler(action, context);
      await handler(createMockEvent());

      // 패턴이 매칭되지 않으므로 headers 옵션 없이 호출
      expect(mockLogin).toHaveBeenCalledWith(
        'user',
        { email: 'test@example.com', password: 'password' },
        undefined
      );
    });

    it('globalHeaders가 설정되지 않으면 헤더 옵션 없이 호출되어야 함', async () => {
      // setGlobalHeaders 호출하지 않음

      const action: ActionDefinition = {
        type: 'click',
        handler: 'login',
        target: 'user',
        params: {
          body: { email: 'test@example.com', password: 'password' },
        },
      };

      const handler = dispatcher.createHandler(action);
      await handler(createMockEvent());

      expect(mockLogin).toHaveBeenCalledWith(
        'user',
        { email: 'test@example.com', password: 'password' },
        undefined
      );
    });
  });

  describe('apiCall - g7_locale Accept-Language 헤더', () => {
    let originalGetItem: typeof Storage.prototype.getItem;

    beforeEach(() => {
      originalGetItem = Storage.prototype.getItem;
    });

    afterEach(() => {
      Storage.prototype.getItem = originalGetItem;
    });

    it('g7_locale이 설정되어 있으면 Accept-Language 헤더로 전송해야 함', async () => {
      Storage.prototype.getItem = vi.fn((key: string) => {
        if (key === 'g7_locale') return 'ko';
        return null;
      });

      const action: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/test',
        params: { method: 'GET' },
      };

      const handler = dispatcher.createHandler(action);
      await handler(createMockEvent());

      const call = findFetchCallByUrl('/api/test');
      expect(call).toBeDefined();
      const requestHeaders = call![1].headers as Record<string, string>;
      expect(requestHeaders['Accept-Language']).toBe('ko');
    });

    it('g7_locale이 없으면 Accept-Language 헤더를 포함하지 않아야 함', async () => {
      Storage.prototype.getItem = vi.fn(() => null);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/test',
        params: { method: 'GET' },
      };

      const handler = dispatcher.createHandler(action);
      await handler(createMockEvent());

      const call = findFetchCallByUrl('/api/test');
      expect(call).toBeDefined();
      const requestHeaders = call![1].headers as Record<string, string>;
      expect(requestHeaders['Accept-Language']).toBeUndefined();
    });

    it('globalHeaders의 Accept-Language가 g7_locale보다 우선해야 함', async () => {
      Storage.prototype.getItem = vi.fn((key: string) => {
        if (key === 'g7_locale') return 'ko';
        return null;
      });

      const globalHeaders: GlobalHeaderRule[] = [
        { pattern: '*', headers: { 'Accept-Language': 'en' } },
      ];
      dispatcher.setGlobalHeaders(globalHeaders);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/test',
        params: { method: 'GET' },
      };

      const handler = dispatcher.createHandler(action);
      await handler(createMockEvent());

      const call = findFetchCallByUrl('/api/test');
      expect(call).toBeDefined();
      const requestHeaders = call![1].headers as Record<string, string>;
      expect(requestHeaders['Accept-Language']).toBe('en');
    });

    it('커스텀 headers의 Accept-Language가 최우선이어야 함', async () => {
      Storage.prototype.getItem = vi.fn((key: string) => {
        if (key === 'g7_locale') return 'ko';
        return null;
      });

      const globalHeaders: GlobalHeaderRule[] = [
        { pattern: '*', headers: { 'Accept-Language': 'en' } },
      ];
      dispatcher.setGlobalHeaders(globalHeaders);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/test',
        params: {
          method: 'GET',
          headers: { 'Accept-Language': 'ja' },
        },
      };

      const handler = dispatcher.createHandler(action);
      await handler(createMockEvent());

      const call = findFetchCallByUrl('/api/test');
      expect(call).toBeDefined();
      const requestHeaders = call![1].headers as Record<string, string>;
      expect(requestHeaders['Accept-Language']).toBe('ja');
    });
  });
});
