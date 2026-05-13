/**
 * ActionDispatcher namedActions 기능 테스트
 *
 * named_actions 정의와 actionRef 참조 해석 검증
 *
 * @since engine-v1.19.0
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { ActionDispatcher, ActionDefinition } from '../ActionDispatcher';
import { Logger } from '../../utils/Logger';

// AuthManager mock
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

describe('ActionDispatcher - namedActions', () => {
  let dispatcher: ActionDispatcher;
  let mockNavigate: ReturnType<typeof vi.fn>;
  let mockFetch: ReturnType<typeof vi.fn>;
  let originalFetch: typeof fetch;

  beforeEach(() => {
    mockNavigate = vi.fn();
    mockGetToken.mockReset();
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

  // 헬퍼 함수: mock 이벤트 생성
  const createMockEvent = () => ({
    preventDefault: vi.fn(),
    type: 'click',
    target: null,
  } as unknown as Event);

  describe('setNamedActions / getNamedActions', () => {
    it('named_actions를 설정하고 조회할 수 있어야 함', () => {
      const namedActions = {
        searchProducts: {
          handler: 'navigate',
          params: { path: '/products', query: { page: 1 } },
        },
      };

      dispatcher.setNamedActions(namedActions as any);
      const result = dispatcher.getNamedActions();

      expect(result).toEqual(namedActions);
    });

    it('null/undefined를 빈 객체로 처리해야 함', () => {
      dispatcher.setNamedActions(null as any);
      expect(dispatcher.getNamedActions()).toEqual({});

      dispatcher.setNamedActions(undefined as any);
      expect(dispatcher.getNamedActions()).toEqual({});
    });

    it('여러 named_actions를 등록할 수 있어야 함', () => {
      const namedActions = {
        searchProducts: { handler: 'navigate', params: { path: '/products' } },
        resetFilters: { handler: 'setState', params: { target: 'local', key: 'filters', value: {} } },
        refreshList: { handler: 'apiCall', target: '/api/products', params: { method: 'GET' } },
      };

      dispatcher.setNamedActions(namedActions as any);
      const result = dispatcher.getNamedActions();

      expect(Object.keys(result)).toHaveLength(3);
      expect(result['searchProducts']).toBeDefined();
      expect(result['resetFilters']).toBeDefined();
      expect(result['refreshList']).toBeDefined();
    });
  });

  describe('resolveActionRef', () => {
    beforeEach(() => {
      dispatcher.setNamedActions({
        searchProducts: {
          handler: 'navigate',
          params: {
            path: '/admin/products',
            replace: true,
            mergeQuery: true,
            query: { page: 1, search_field: 'product_name' },
          },
        },
        fetchData: {
          handler: 'apiCall',
          target: '/api/products',
          params: { method: 'GET' },
        },
      } as any);
    });

    it('actionRef로 named_actions 정의를 올바르게 참조해야 함', () => {
      const action: ActionDefinition = {
        type: 'click',
        actionRef: 'searchProducts',
      };

      const resolved = dispatcher.resolveActionRef(action);

      expect(resolved.handler).toBe('navigate');
      expect(resolved.params).toEqual({
        path: '/admin/products',
        replace: true,
        mergeQuery: true,
        query: { page: 1, search_field: 'product_name' },
      });
    });

    it('이벤트 속성(type, key)이 보존되어야 함', () => {
      const action: ActionDefinition = {
        type: 'keypress',
        key: 'Enter',
        actionRef: 'searchProducts',
      };

      const resolved = dispatcher.resolveActionRef(action);

      expect(resolved.type).toBe('keypress');
      expect(resolved.key).toBe('Enter');
      expect(resolved.handler).toBe('navigate');
    });

    it('event 속성이 보존되어야 함', () => {
      const action: ActionDefinition = {
        type: 'custom',
        event: 'onCustomEvent',
        actionRef: 'fetchData',
      };

      const resolved = dispatcher.resolveActionRef(action);

      expect(resolved.event).toBe('onCustomEvent');
      expect(resolved.handler).toBe('apiCall');
      expect(resolved.target).toBe('/api/products');
    });

    it('actionRef가 없으면 원본 액션을 그대로 반환해야 함', () => {
      const action: ActionDefinition = {
        type: 'click',
        handler: 'navigate',
        params: { path: '/direct' },
      };

      const resolved = dispatcher.resolveActionRef(action);

      expect(resolved).toBe(action); // 동일 참조
    });

    it('존재하지 않는 actionRef에 대해 원본을 반환하고 경고해야 함', () => {
      const action: ActionDefinition = {
        type: 'click',
        actionRef: 'nonExistentAction',
      };

      const resolved = dispatcher.resolveActionRef(action);

      // actionRef가 해석되지 않으면 원본 반환
      expect(resolved).toBe(action);
    });

    it('인라인 handler가 actionRef를 override해야 함', () => {
      const action: ActionDefinition = {
        type: 'click',
        actionRef: 'searchProducts',
        handler: 'apiCall',
        target: '/api/custom',
      };

      const resolved = dispatcher.resolveActionRef(action);

      // 인라인 handler가 우선
      expect(resolved.handler).toBe('apiCall');
      expect(resolved.target).toBe('/api/custom');
    });

    it('인라인 params가 named_actions의 params를 override해야 함', () => {
      const action: ActionDefinition = {
        type: 'click',
        actionRef: 'searchProducts',
        params: { path: '/custom/path' },
      };

      const resolved = dispatcher.resolveActionRef(action);

      // 인라인 params가 우선
      expect(resolved.params).toEqual({ path: '/custom/path' });
    });
  });

  describe('executeAction with actionRef', () => {
    beforeEach(() => {
      dispatcher.setNamedActions({
        navigateToProducts: {
          handler: 'navigate',
          params: {
            path: '/admin/products',
            replace: true,
          },
        },
        callApi: {
          handler: 'apiCall',
          target: '/api/products',
          params: {
            method: 'GET',
            auth_required: false,
          },
        },
      } as any);
    });

    it('actionRef가 있는 액션을 executeAction에서 올바르게 실행해야 함 (navigate)', async () => {
      const action: ActionDefinition = {
        type: 'click',
        actionRef: 'navigateToProducts',
      };

      const handler = dispatcher.createHandler(action);
      await handler(createMockEvent());

      expect(mockNavigate).toHaveBeenCalledWith(
        '/admin/products',
        expect.objectContaining({ replace: true })
      );
    });

    it('actionRef가 있는 액션을 executeAction에서 올바르게 실행해야 함 (apiCall)', async () => {
      const action: ActionDefinition = {
        type: 'click',
        actionRef: 'callApi',
      };

      const handler = dispatcher.createHandler(action);
      await handler(createMockEvent());

      expect(mockFetch).toHaveBeenCalled();
      const call = mockFetch.mock.calls.find(
        (c: unknown[]) => c[0] === '/api/products'
      );
      expect(call).toBeDefined();
    });
  });

  describe('bindActionsToProps with actionRef', () => {
    beforeEach(() => {
      dispatcher.setNamedActions({
        searchProducts: {
          handler: 'navigate',
          params: {
            path: '/admin/products',
            query: { page: 1 },
          },
        },
      } as any);
    });

    it('props.actions의 actionRef가 bindActionsToProps에서 해석되어야 함', () => {
      const props = {
        actions: [
          { type: 'click', actionRef: 'searchProducts' },
          { type: 'keypress', key: 'Enter', actionRef: 'searchProducts' },
        ] as ActionDefinition[],
      };

      const result = dispatcher.bindActionsToProps(props);

      // click과 keypress 이벤트 핸들러가 바인딩되어야 함
      expect(result.onClick).toBeDefined();
      expect(result.onKeyPress || result.onKeyDown).toBeDefined();
    });
  });

  describe('named_actions 교체', () => {
    it('페이지 전환 시 named_actions가 교체되어야 함', () => {
      // 첫 번째 페이지의 named_actions
      dispatcher.setNamedActions({
        searchProducts: { handler: 'navigate', params: { path: '/products' } },
      } as any);

      expect(dispatcher.getNamedActions()['searchProducts']).toBeDefined();

      // 두 번째 페이지의 named_actions로 교체
      dispatcher.setNamedActions({
        searchOrders: { handler: 'navigate', params: { path: '/orders' } },
      } as any);

      // 이전 페이지의 named_actions는 없어야 함
      expect(dispatcher.getNamedActions()['searchProducts']).toBeUndefined();
      expect(dispatcher.getNamedActions()['searchOrders']).toBeDefined();
    });
  });
});
