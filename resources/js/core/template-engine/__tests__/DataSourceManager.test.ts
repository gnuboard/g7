/**
 * DataSourceManager 테스트
 */

import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import { DataSourceManager, DataSource, ConditionContext } from '../DataSourceManager';
import { getApiClient } from '../../api/ApiClient';

// ApiClient 모킹
const mockApiClientInstance = {
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
  patch: vi.fn(),
  delete: vi.fn(),
  getInstance: vi.fn(() => ({
    interceptors: {
      response: {
        use: vi.fn(),
        handlers: [],
      },
    },
  })),
};

vi.mock('../../api/ApiClient', () => ({
  getApiClient: vi.fn(() => mockApiClientInstance),
}));

// AuthManager 모킹 (기본: 인증된 상태)
const mockIsAuthenticated = vi.fn(() => true);
vi.mock('../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({
      isAuthenticated: mockIsAuthenticated,
    })),
  },
}));

// ErrorHandlingResolver 모킹
const mockResolverExecute = vi.fn();
const mockResolverResolve = vi.fn(() => ({
  handler: null,
  source: 'default',
}));

vi.mock('../../error', () => ({
  getErrorHandlingResolver: vi.fn(() => ({
    resolve: mockResolverResolve,
    execute: mockResolverExecute,
    setLayoutConfig: vi.fn(),
    setTemplateConfig: vi.fn(),
    clearConfig: vi.fn(),
  })),
}));

// fetch 모킹
global.fetch = vi.fn();

describe('DataSourceManager', () => {
  let manager: DataSourceManager;

  beforeEach(() => {
    manager = new DataSourceManager();
    vi.clearAllMocks();
  });

  afterEach(() => {
    manager.clearCache();
  });

  describe('정적 데이터 소스', () => {
    it('static 타입 데이터 소스를 처리할 수 있어야 함', async () => {
      const sources: DataSource[] = [
        {
          id: 'static-data',
          type: 'static',
          data: { message: 'Hello' },
          auto_fetch: true,
        },
      ];

      const result = await manager.fetchDataSources(sources);
      expect(result['static-data']).toEqual({ message: 'Hello' });
    });
  });

  describe('라우트/쿼리 파라미터 데이터 소스', () => {
    it('route_params 타입 데이터 소스를 처리할 수 있어야 함', async () => {
      const sources: DataSource[] = [
        {
          id: 'route-params',
          type: 'route_params',
          auto_fetch: true,
        },
      ];

      const routeParams = { id: '123', slug: 'test' };
      const result = await manager.fetchDataSources(sources, routeParams);
      expect(result['route-params']).toEqual(routeParams);
    });

    it('query_params 타입 데이터 소스를 처리할 수 있어야 함', async () => {
      const sources: DataSource[] = [
        {
          id: 'query-params',
          type: 'query_params',
          auto_fetch: true,
        },
      ];

      const queryParams = new URLSearchParams('page=1&limit=10');
      const result = await manager.fetchDataSources(sources, {}, queryParams);
      expect(result['query-params']).toEqual({ page: '1', limit: '10' });
    });
  });

  describe('API 데이터 소스 (auth_required: false)', () => {
    it('인증이 필요 없는 GET 요청을 처리할 수 있어야 함', async () => {
      const mockResponse = { data: [1, 2, 3] };
      (global.fetch as any).mockResolvedValue({
        ok: true,
        json: async () => mockResponse,
      });

      const sources: DataSource[] = [
        {
          id: 'public-api',
          type: 'api',
          endpoint: '/api/public/data',
          method: 'GET',
          auth_required: false,
          auto_fetch: true,
        },
      ];

      const result = await manager.fetchDataSources(sources);
      expect(result['public-api']).toEqual(mockResponse);
      expect(global.fetch).toHaveBeenCalledWith(
        '/api/public/data',
        expect.objectContaining({
          method: 'GET',
          headers: expect.any(Object),
        }),
      );
    });

    it('쿼리 파라미터를 URL에 추가할 수 있어야 함', async () => {
      const mockResponse = { data: [] };
      (global.fetch as any).mockResolvedValue({
        ok: true,
        json: async () => mockResponse,
      });

      const sources: DataSource[] = [
        {
          id: 'api-with-params',
          type: 'api',
          endpoint: '/api/public/data',
          method: 'GET',
          auth_required: false,
          auto_fetch: true,
          params: { page: '1', limit: '10' },
        },
      ];

      await manager.fetchDataSources(sources);
      expect(global.fetch).toHaveBeenCalledWith(
        '/api/public/data?page=1&limit=10',
        expect.any(Object),
      );
    });
  });

  describe('API 데이터 소스 (auth_required: true)', () => {
    it('인증이 필요한 GET 요청을 ApiClient로 처리해야 함', async () => {
      const mockResponse = { data: 'protected' };
      const mockApiClient = getApiClient();
      (mockApiClient.get as any).mockResolvedValue(mockResponse);

      const sources: DataSource[] = [
        {
          id: 'protected-api',
          type: 'api',
          endpoint: '/api/admin/data',
          method: 'GET',
          auth_required: true,
          auto_fetch: true,
        },
      ];

      const result = await manager.fetchDataSources(sources);
      expect(result['protected-api']).toEqual(mockResponse);
      // ApiClient는 baseURL로 '/api'를 사용하므로, endpoint에서 '/api' 제거됨
      expect(mockApiClient.get).toHaveBeenCalledWith(
        '/admin/data',
        expect.objectContaining({ params: {} }),
      );
    });

    it('인증이 필요한 POST 요청을 ApiClient로 처리해야 함', async () => {
      const mockResponse = { success: true };
      const mockApiClient = getApiClient();
      (mockApiClient.post as any).mockResolvedValue(mockResponse);

      const sources: DataSource[] = [
        {
          id: 'create-api',
          type: 'api',
          endpoint: '/api/admin/users',
          method: 'POST',
          auth_required: true,
          auto_fetch: true,
          params: { name: 'John' },
        },
      ];

      const result = await manager.fetchDataSources(sources);
      expect(result['create-api']).toEqual(mockResponse);
      // ApiClient는 baseURL로 '/api'를 사용하므로, endpoint에서 '/api' 제거됨
      expect(mockApiClient.post).toHaveBeenCalledWith('/admin/users', { name: 'John' });
    });
  });

  describe('파라미터 치환', () => {
    it('라우트 파라미터를 치환할 수 있어야 함', async () => {
      const mockResponse = { data: 'user-123' };
      const mockApiClient = getApiClient();
      (mockApiClient.get as any).mockResolvedValue(mockResponse);

      const sources: DataSource[] = [
        {
          id: 'user-api',
          type: 'api',
          endpoint: '/api/admin/users',
          method: 'GET',
          auth_required: true,
          auto_fetch: true,
          params: { id: '{{route.id}}' },
        },
      ];

      const routeParams = { id: '123' };
      await manager.fetchDataSources(sources, routeParams);

      // ApiClient는 baseURL로 '/api'를 사용하므로, endpoint에서 '/api' 제거됨
      expect(mockApiClient.get).toHaveBeenCalledWith(
        '/admin/users',
        expect.objectContaining({
          params: { id: '123' },
        }),
      );
    });

    it('쿼리 파라미터를 치환할 수 있어야 함 (점 표기법)', async () => {
      const mockResponse = { data: [] };
      const mockApiClient = getApiClient();
      (mockApiClient.get as any).mockResolvedValue(mockResponse);

      const sources: DataSource[] = [
        {
          id: 'filtered-api',
          type: 'api',
          endpoint: '/api/admin/products',
          method: 'GET',
          auth_required: true,
          auto_fetch: true,
          params: { category: '{{query.category}}' },
        },
      ];

      const queryParams = new URLSearchParams('category=electronics');
      await manager.fetchDataSources(sources, {}, queryParams);

      // ApiClient는 baseURL로 '/api'를 사용하므로, endpoint에서 '/api' 제거됨
      expect(mockApiClient.get).toHaveBeenCalledWith(
        '/admin/products',
        expect.objectContaining({
          params: { category: 'electronics' },
        }),
      );
    });

    it('쿼리 파라미터를 치환할 수 있어야 함 (대괄호 표기법)', async () => {
      const mockResponse = { data: [] };
      const mockApiClient = getApiClient();
      (mockApiClient.get as any).mockResolvedValue(mockResponse);

      const sources: DataSource[] = [
        {
          id: 'filtered-api',
          type: 'api',
          endpoint: '/api/admin/users',
          method: 'GET',
          auth_required: true,
          auto_fetch: true,
          params: {
            'filters[0][field]': "{{query['filters[0][field]']}}",
            'filters[0][value]': "{{query['filters[0][value]']}}",
          },
        },
      ];

      const queryParams = new URLSearchParams('filters[0][field]=name&filters[0][value]=john');
      await manager.fetchDataSources(sources, {}, queryParams);

      // ApiClient는 baseURL로 '/api'를 사용하므로, endpoint에서 '/api' 제거됨
      expect(mockApiClient.get).toHaveBeenCalledWith(
        '/admin/users',
        expect.objectContaining({
          params: {
            'filters[0][field]': 'name',
            'filters[0][value]': 'john',
          },
        }),
      );
    });

    it('대괄호 표기법에서 fallback 값을 사용할 수 있어야 함', async () => {
      const mockResponse = { data: [] };
      const mockApiClient = getApiClient();
      (mockApiClient.get as any).mockResolvedValue(mockResponse);

      const sources: DataSource[] = [
        {
          id: 'filtered-api',
          type: 'api',
          endpoint: '/api/admin/users',
          method: 'GET',
          auth_required: true,
          auto_fetch: true,
          params: {
            'filters[0][field]': "{{query['filters[0][field]'] || 'all'}}",
            'filters[0][value]': "{{query['filters[0][value]']}}",
          },
        },
      ];

      // 쿼리 파라미터에 filters[0][field]가 없는 경우
      const queryParams = new URLSearchParams('filters[0][value]=john');
      await manager.fetchDataSources(sources, {}, queryParams);

      // filters[0][field]가 없으므로 fallback 값 'all'이 사용되어야 함
      expect(mockApiClient.get).toHaveBeenCalledWith(
        '/admin/users',
        expect.objectContaining({
          params: {
            'filters[0][field]': 'all',
            'filters[0][value]': 'john',
          },
        }),
      );
    });

    it('대괄호 표기법에서 값이 있으면 fallback을 사용하지 않아야 함', async () => {
      const mockResponse = { data: [] };
      const mockApiClient = getApiClient();
      (mockApiClient.get as any).mockResolvedValue(mockResponse);

      const sources: DataSource[] = [
        {
          id: 'filtered-api',
          type: 'api',
          endpoint: '/api/admin/users',
          method: 'GET',
          auth_required: true,
          auto_fetch: true,
          params: {
            'filters[0][field]': "{{query['filters[0][field]'] || 'all'}}",
          },
        },
      ];

      // 쿼리 파라미터에 filters[0][field]가 있는 경우
      const queryParams = new URLSearchParams('filters[0][field]=email');
      await manager.fetchDataSources(sources, {}, queryParams);

      // 값이 있으므로 fallback 값이 아닌 실제 값이 사용되어야 함
      expect(mockApiClient.get).toHaveBeenCalledWith(
        '/admin/users',
        expect.objectContaining({
          params: {
            'filters[0][field]': 'email',
          },
        }),
      );
    });

    it('점 표기법에서 fallback 값을 사용할 수 있어야 함', async () => {
      const mockResponse = { data: [] };
      const mockApiClient = getApiClient();
      (mockApiClient.get as any).mockResolvedValue(mockResponse);

      const sources: DataSource[] = [
        {
          id: 'filtered-api',
          type: 'api',
          endpoint: '/api/admin/products',
          method: 'GET',
          auth_required: true,
          auto_fetch: true,
          params: {
            sort_order: "{{query.sort_order || 'desc'}}",
          },
        },
      ];

      // 쿼리 파라미터에 sort_order가 없는 경우
      const queryParams = new URLSearchParams('');
      await manager.fetchDataSources(sources, {}, queryParams);

      // sort_order가 없으므로 fallback 값 'desc'가 사용되어야 함
      expect(mockApiClient.get).toHaveBeenCalledWith(
        '/admin/products',
        expect.objectContaining({
          params: {
            sort_order: 'desc',
          },
        }),
      );
    });
  });

  describe('auto_fetch', () => {
    it('auto_fetch가 false인 데이터 소스는 fetch하지 않아야 함', async () => {
      const sources: DataSource[] = [
        {
          id: 'manual-fetch',
          type: 'static',
          data: { message: 'test' },
          auto_fetch: false,
        },
      ];

      const result = await manager.fetchDataSources(sources);
      expect(result['manual-fetch']).toBeUndefined();
    });
  });

  describe('캐시', () => {
    it('fetch한 데이터를 캐시할 수 있어야 함', async () => {
      const sources: DataSource[] = [
        {
          id: 'cached-data',
          type: 'static',
          data: { message: 'cached' },
          auto_fetch: true,
        },
      ];

      await manager.fetchDataSources(sources);
      expect(manager.getCachedData('cached-data')).toEqual({ message: 'cached' });
    });

    it('캐시를 초기화할 수 있어야 함', async () => {
      const sources: DataSource[] = [
        {
          id: 'cached-data',
          type: 'static',
          data: { message: 'cached' },
          auto_fetch: true,
        },
      ];

      await manager.fetchDataSources(sources);
      manager.clearCache();
      expect(manager.getCachedData('cached-data')).toBeUndefined();
    });
  });

  describe('에러 처리', () => {
    it('에러 발생 시 onError 콜백을 호출해야 함', async () => {
      const onError = vi.fn();
      const managerWithError = new DataSourceManager({ onError });

      (global.fetch as any).mockRejectedValue(new Error('Network error'));

      const sources: DataSource[] = [
        {
          id: 'failing-api',
          type: 'api',
          endpoint: '/api/fail',
          method: 'GET',
          auth_required: false,
          auto_fetch: true,
        },
      ];

      await managerWithError.fetchDataSources(sources);
      expect(onError).toHaveBeenCalled();
    });

    it('fetch 경로 HTTP 에러 시 status 코드가 Error 객체에 포함되어야 함', async () => {
      const onError = vi.fn();
      const managerWithError = new DataSourceManager({ onError });

      // fetch가 401 응답을 반환하도록 모킹 (Once로 후속 테스트 영향 방지)
      (global.fetch as any).mockResolvedValueOnce({
        ok: false,
        status: 401,
        statusText: 'Unauthorized',
        json: () => Promise.resolve({ message: 'Unauthenticated.' }),
        headers: new Headers({ 'content-type': 'application/json' }),
      });

      const sources: DataSource[] = [
        {
          id: 'unauthorized-api',
          type: 'api',
          endpoint: '/api/products',
          method: 'GET',
          auto_fetch: true,
        },
      ];

      await managerWithError.fetchDataSources(sources);

      expect(onError).toHaveBeenCalled();
      const errorArg = onError.mock.calls[0][0];
      // Error 객체에 response.status가 포함되어야 함
      expect(errorArg.response?.status ?? errorArg.status).toBe(401);
    });

    it('fetch 경로 HTTP 403 에러 시 status 코드가 보존되어야 함', async () => {
      const onError = vi.fn();
      const managerWithError = new DataSourceManager({ onError });

      (global.fetch as any).mockResolvedValueOnce({
        ok: false,
        status: 403,
        statusText: 'Forbidden',
        json: () => Promise.resolve({ message: 'Forbidden.' }),
        headers: new Headers({ 'content-type': 'application/json' }),
      });

      const sources: DataSource[] = [
        {
          id: 'forbidden-api',
          type: 'api',
          endpoint: '/api/products',
          method: 'GET',
          auto_fetch: true,
        },
      ];

      await managerWithError.fetchDataSources(sources);

      expect(onError).toHaveBeenCalled();
      const errorArg = onError.mock.calls[0][0];
      expect(errorArg.response?.status ?? errorArg.status).toBe(403);
    });
  });

  describe('조건부 데이터 소스 필터링 (filterByCondition)', () => {
    it('if 속성이 없는 데이터 소스는 항상 선택되어야 함', () => {
      const sources: DataSource[] = [
        { id: 'always-load', type: 'static', data: { message: 'always' } },
      ];

      const result = manager.filterByCondition(sources);
      expect(result).toHaveLength(1);
      expect(result[0].id).toBe('always-load');
    });

    it('if 조건이 truthy인 데이터 소스만 선택되어야 함', () => {
      const sources: DataSource[] = [
        { id: 'user', type: 'api', endpoint: '/api/users/123', if: '{{route.id}}' },
      ];

      const context: ConditionContext = { route: { id: '123' } };
      const result = manager.filterByCondition(sources, context);

      expect(result).toHaveLength(1);
      expect(result[0].id).toBe('user');
    });

    it('if 조건이 falsy인 데이터 소스는 선택되지 않아야 함', () => {
      const sources: DataSource[] = [
        { id: 'user', type: 'api', endpoint: '/api/users/123', if: '{{route.id}}' },
      ];

      const context: ConditionContext = { route: {} };
      const result = manager.filterByCondition(sources, context);

      expect(result).toHaveLength(0);
    });

    it('같은 id를 가진 데이터 소스 중 조건을 만족하는 첫 번째만 선택되어야 함', () => {
      const sources: DataSource[] = [
        { id: 'user', type: 'api', endpoint: '/api/users/{{route.id}}', if: '{{route.id}}' },
        { id: 'user', type: 'api', endpoint: '/api/users/template', if: '{{!route.id}}' },
      ];

      // route.id가 있는 경우 - 첫 번째 선택
      const contextWithId: ConditionContext = { route: { id: '123' } };
      const resultWithId = manager.filterByCondition(sources, contextWithId);
      expect(resultWithId).toHaveLength(1);
      expect(resultWithId[0].endpoint).toBe('/api/users/{{route.id}}');

      // route.id가 없는 경우 - 두 번째 선택
      const contextWithoutId: ConditionContext = { route: {} };
      const resultWithoutId = manager.filterByCondition(sources, contextWithoutId);
      expect(resultWithoutId).toHaveLength(1);
      expect(resultWithoutId[0].endpoint).toBe('/api/users/template');
    });

    it('부정 연산자(!)를 사용한 조건을 올바르게 평가해야 함', () => {
      const sources: DataSource[] = [
        { id: 'template', type: 'api', endpoint: '/api/users/template', if: '{{!route.id}}' },
      ];

      // route.id가 없는 경우 - 선택됨
      const contextWithoutId: ConditionContext = { route: {} };
      const resultWithoutId = manager.filterByCondition(sources, contextWithoutId);
      expect(resultWithoutId).toHaveLength(1);

      // route.id가 있는 경우 - 선택 안됨
      const contextWithId: ConditionContext = { route: { id: '123' } };
      const resultWithId = manager.filterByCondition(sources, contextWithId);
      expect(resultWithId).toHaveLength(0);
    });

    it('query 파라미터로 조건을 평가할 수 있어야 함', () => {
      const sources: DataSource[] = [
        { id: 'data', type: 'api', endpoint: '/api/users', if: "{{query.mode === 'edit'}}" },
      ];

      // mode=edit인 경우 - 선택됨
      const contextEdit: ConditionContext = { query: { mode: 'edit' } };
      const resultEdit = manager.filterByCondition(sources, contextEdit);
      expect(resultEdit).toHaveLength(1);

      // mode가 다른 경우 - 선택 안됨
      const contextCreate: ConditionContext = { query: { mode: 'create' } };
      const resultCreate = manager.filterByCondition(sources, contextCreate);
      expect(resultCreate).toHaveLength(0);
    });

    it('_global 상태로 조건을 평가할 수 있어야 함', () => {
      const sources: DataSource[] = [
        { id: 'admin-data', type: 'api', endpoint: '/api/admin/stats', if: '{{_global.isAdmin}}' },
      ];

      // isAdmin=true인 경우 - 선택됨
      const contextAdmin: ConditionContext = { _global: { isAdmin: true } };
      const resultAdmin = manager.filterByCondition(sources, contextAdmin);
      expect(resultAdmin).toHaveLength(1);

      // isAdmin=false인 경우 - 선택 안됨
      const contextNotAdmin: ConditionContext = { _global: { isAdmin: false } };
      const resultNotAdmin = manager.filterByCondition(sources, contextNotAdmin);
      expect(resultNotAdmin).toHaveLength(0);
    });

    it('복잡한 조건 표현식을 평가할 수 있어야 함', () => {
      const sources: DataSource[] = [
        {
          id: 'conditional',
          type: 'api',
          endpoint: '/api/data',
          if: "{{route.id && query.mode === 'edit'}}",
        },
      ];

      // 둘 다 만족하는 경우 - 선택됨
      const contextBoth: ConditionContext = {
        route: { id: '123' },
        query: { mode: 'edit' },
      };
      const resultBoth = manager.filterByCondition(sources, contextBoth);
      expect(resultBoth).toHaveLength(1);

      // route.id만 있는 경우 - 선택 안됨
      const contextOnlyId: ConditionContext = {
        route: { id: '123' },
        query: { mode: 'create' },
      };
      const resultOnlyId = manager.filterByCondition(sources, contextOnlyId);
      expect(resultOnlyId).toHaveLength(0);
    });

    it('삼항 연산자를 사용한 조건을 평가할 수 있어야 함', () => {
      const sources: DataSource[] = [
        {
          id: 'data',
          type: 'api',
          endpoint: '/api/data',
          if: "{{route.type === 'premium' ? _global.isPremiumUser : true}}",
        },
      ];

      // premium 타입이지만 premium 사용자인 경우 - 선택됨
      const contextPremiumUser: ConditionContext = {
        route: { type: 'premium' },
        _global: { isPremiumUser: true },
      };
      const resultPremiumUser = manager.filterByCondition(sources, contextPremiumUser);
      expect(resultPremiumUser).toHaveLength(1);

      // premium 타입이지만 일반 사용자인 경우 - 선택 안됨
      const contextNormalUser: ConditionContext = {
        route: { type: 'premium' },
        _global: { isPremiumUser: false },
      };
      const resultNormalUser = manager.filterByCondition(sources, contextNormalUser);
      expect(resultNormalUser).toHaveLength(0);

      // 일반 타입인 경우 - 항상 선택됨
      const contextNormalType: ConditionContext = {
        route: { type: 'basic' },
        _global: { isPremiumUser: false },
      };
      const resultNormalType = manager.filterByCondition(sources, contextNormalType);
      expect(resultNormalType).toHaveLength(1);
    });

    it('if와 다른 속성이 함께 있는 데이터 소스를 올바르게 처리해야 함', () => {
      const sources: DataSource[] = [
        {
          id: 'user',
          type: 'api',
          endpoint: '/api/users/{{route.id}}',
          method: 'GET',
          auth_required: true,
          auto_fetch: true,
          loading_strategy: 'blocking',
          initLocal: 'form',
          if: '{{route.id}}',
        },
        {
          id: 'user',
          type: 'api',
          endpoint: '/api/users/template',
          method: 'GET',
          auth_required: true,
          auto_fetch: true,
          loading_strategy: 'blocking',
          initLocal: 'form',
          if: '{{!route.id}}',
        },
      ];

      const context: ConditionContext = { route: { id: '456' } };
      const result = manager.filterByCondition(sources, context);

      expect(result).toHaveLength(1);
      expect(result[0].endpoint).toBe('/api/users/{{route.id}}');
      expect(result[0].initLocal).toBe('form');
      expect(result[0].loading_strategy).toBe('blocking');
    });

    it('여러 개의 다른 id를 가진 데이터 소스를 각각 처리해야 함', () => {
      const sources: DataSource[] = [
        { id: 'user', type: 'api', endpoint: '/api/users/123', if: '{{route.id}}' },
        { id: 'user', type: 'api', endpoint: '/api/users/template', if: '{{!route.id}}' },
        { id: 'roles', type: 'api', endpoint: '/api/roles' }, // if 없음 - 항상 선택
        { id: 'permissions', type: 'api', endpoint: '/api/permissions', if: '{{_global.isAdmin}}' },
      ];

      const context: ConditionContext = {
        route: { id: '123' },
        _global: { isAdmin: true },
      };
      const result = manager.filterByCondition(sources, context);

      expect(result).toHaveLength(3);
      expect(result.map((s) => s.id)).toEqual(['user', 'roles', 'permissions']);
      expect(result.find((s) => s.id === 'user')?.endpoint).toBe('/api/users/123');
    });
  });

  describe('에러 핸들링과 fallback 우선순위', () => {
    beforeEach(() => {
      mockResolverResolve.mockReset();
      mockResolverExecute.mockReset();
    });

    it('fallback이 있어도 errorHandling이 먼저 실행되어야 함', async () => {
      const mockApiClient = getApiClient();
      const error403 = Object.assign(new Error('Forbidden'), {
        response: { status: 403, data: { message: 'Access denied' } },
      });
      (mockApiClient.get as any).mockRejectedValue(error403);

      (mockResolverResolve as any).mockReturnValue({
        handler: { handler: 'showErrorPage', params: { target: 'content' } },
        source: 'dataSource',
      });

      const sources: DataSource[] = [
        {
          id: 'user',
          type: 'api',
          endpoint: '/api/admin/users/1',
          method: 'GET',
          auto_fetch: true,
          auth_required: true,
          errorHandling: {
            '403': {
              handler: 'showErrorPage',
              params: { target: 'content' },
            },
          },
          fallback: { data: null },
        },
      ];

      const results = await manager.fetchDataSources(sources, {});

      // errorHandling이 실행되었는지 확인
      expect(mockResolverResolve).toHaveBeenCalledWith(403, expect.objectContaining({
        errorHandling: sources[0].errorHandling,
      }));
      expect(mockResolverExecute).toHaveBeenCalled();

      // fallback 데이터도 결과에 반영되었는지 확인
      expect(results['user']).toEqual({ data: null });
    });

    it('fallback과 errorHandling 모두 없으면 기본 에러 처리가 되어야 함', async () => {
      const mockApiClient = getApiClient();
      const error500 = Object.assign(new Error('Server Error'), {
        response: { status: 500, data: { message: 'Internal Server Error' } },
      });
      (mockApiClient.get as any).mockRejectedValue(error500);

      mockResolverResolve.mockReturnValue({
        handler: null,
        source: 'default',
      });

      const sources: DataSource[] = [
        {
          id: 'data',
          type: 'api',
          endpoint: '/api/test',
          method: 'GET',
          auto_fetch: true,
        },
      ];

      const results = await manager.fetchDataSources(sources, {});

      // 에러 핸들링 resolve가 호출되었는지 확인
      expect(mockResolverResolve).toHaveBeenCalledWith(500, expect.objectContaining({
        errorHandling: undefined,
      }));

      // fallback이 없으므로 결과에 데이터가 없어야 함
      expect(results['data']).toBeUndefined();
    });

    it('errorHandling 없이 fallback만 있으면 fallback 데이터가 사용되어야 함', async () => {
      const mockApiClient = getApiClient();
      const error404 = Object.assign(new Error('Not Found'), {
        response: { status: 404, data: { message: 'Not Found' } },
      });
      (mockApiClient.get as any).mockRejectedValue(error404);

      mockResolverResolve.mockReturnValue({
        handler: null,
        source: 'default',
      });

      const sources: DataSource[] = [
        {
          id: 'user',
          type: 'api',
          endpoint: '/api/admin/users/999',
          method: 'GET',
          auto_fetch: true,
          fallback: { data: [] },
        },
      ];

      const results = await manager.fetchDataSources(sources, {});

      // resolve는 호출되지만 handler가 null이므로 execute는 호출되지 않음
      expect(mockResolverResolve).toHaveBeenCalled();
      expect(mockResolverExecute).not.toHaveBeenCalled();

      // fallback 데이터가 결과에 반영되어야 함
      expect(results['user']).toEqual({ data: [] });
    });
  });

  describe('errorCondition', () => {
    beforeEach(() => {
      mockResolverResolve.mockReset();
      mockResolverExecute.mockReset();
    });

    it('errorCondition 조건이 true면 errorHandling이 트리거되어야 함', async () => {
      const mockApiClient = getApiClient();
      const responseData = { data: { id: 1, name: 'User', abilities: { can_update: false } } };
      (mockApiClient.get as any).mockResolvedValue(responseData);

      (mockResolverResolve as any).mockReturnValue({
        handler: { handler: 'showErrorPage', params: { target: 'content' } },
        source: 'dataSource',
      });

      const sources: DataSource[] = [
        {
          id: 'user',
          type: 'api',
          endpoint: '/api/admin/users/1',
          method: 'GET',
          auto_fetch: true,
          auth_required: true,
          errorCondition: {
            if: '{{response?.data?.abilities?.can_update !== true}}',
            errorCode: 403,
          },
          errorHandling: {
            '403': {
              handler: 'showErrorPage',
              params: { target: 'content' },
            },
          },
          fallback: { data: null },
        },
      ];

      const results = await manager.fetchDataSources(sources, {});

      // errorHandling이 실행되었는지 확인
      expect(mockResolverResolve).toHaveBeenCalledWith(403, expect.objectContaining({
        errorHandling: sources[0].errorHandling,
      }));
      expect(mockResolverExecute).toHaveBeenCalled();

      // fallback 데이터가 결과에 반영되었는지 확인
      expect(results['user']).toEqual({ data: null });
    });

    it('errorCondition 조건이 false면 정상 처리되어야 함 (onSuccess 실행)', async () => {
      const mockApiClient = getApiClient();
      const responseData = { data: { id: 1, name: 'User', abilities: { can_update: true } } };
      (mockApiClient.get as any).mockResolvedValue(responseData);

      const sources: DataSource[] = [
        {
          id: 'user',
          type: 'api',
          endpoint: '/api/admin/users/1',
          method: 'GET',
          auto_fetch: true,
          auth_required: true,
          errorCondition: {
            if: '{{response?.data?.abilities?.can_update !== true}}',
            errorCode: 403,
          },
          errorHandling: {
            '403': {
              handler: 'showErrorPage',
              params: { target: 'content' },
            },
          },
        },
      ];

      const results = await manager.fetchDataSources(sources, {});

      // errorHandling이 실행되지 않아야 함
      expect(mockResolverResolve).not.toHaveBeenCalled();
      expect(mockResolverExecute).not.toHaveBeenCalled();

      // 정상 데이터가 반환되어야 함
      expect(results['user']).toEqual(responseData);
    });

    it('errorCondition 미정의 시 기존 동작 유지 (하위 호환)', async () => {
      const mockApiClient = getApiClient();
      const responseData = { data: { id: 1, name: 'User' } };
      (mockApiClient.get as any).mockResolvedValue(responseData);

      const sources: DataSource[] = [
        {
          id: 'user',
          type: 'api',
          endpoint: '/api/admin/users/1',
          method: 'GET',
          auto_fetch: true,
          auth_required: true,
        },
      ];

      const results = await manager.fetchDataSources(sources, {});

      // errorHandling이 실행되지 않아야 함
      expect(mockResolverResolve).not.toHaveBeenCalled();

      // 정상 데이터가 반환되어야 함
      expect(results['user']).toEqual(responseData);
    });

    it('fetchDataSourcesWithResults에서 errorCondition + fallback 없으면 error 상태 반환', async () => {
      const mockApiClient = getApiClient();
      const responseData = { data: { id: 1, abilities: { can_update: false } } };
      (mockApiClient.get as any).mockResolvedValue(responseData);

      (mockResolverResolve as any).mockReturnValue({
        handler: { handler: 'showErrorPage', params: { target: 'content' } },
        source: 'dataSource',
      });

      const sources: DataSource[] = [
        {
          id: 'user',
          type: 'api',
          endpoint: '/api/admin/users/1',
          method: 'GET',
          auto_fetch: true,
          auth_required: true,
          errorCondition: {
            if: '{{response?.data?.abilities?.can_update !== true}}',
            errorCode: 403,
          },
          errorHandling: {
            '403': {
              handler: 'showErrorPage',
              params: { target: 'content' },
            },
          },
        },
      ];

      const results = await manager.fetchDataSourcesWithResults(sources, {});

      expect(results).toHaveLength(1);
      expect(results[0].id).toBe('user');
      expect(results[0].state).toBe('error');
      expect(results[0].errorCode).toBe(403);
      expect(results[0].errorHandled).toBe(true);
    });

    it('fetchDataSourcesWithResults에서 errorCondition + fallback이면 success 상태 + fallback 데이터', async () => {
      const mockApiClient = getApiClient();
      const responseData = { data: { id: 1, abilities: { can_update: false } } };
      (mockApiClient.get as any).mockResolvedValue(responseData);

      (mockResolverResolve as any).mockReturnValue({
        handler: { handler: 'showErrorPage', params: { target: 'content' } },
        source: 'dataSource',
      });

      const sources: DataSource[] = [
        {
          id: 'user',
          type: 'api',
          endpoint: '/api/admin/users/1',
          method: 'GET',
          auto_fetch: true,
          auth_required: true,
          errorCondition: {
            if: '{{response?.data?.abilities?.can_update !== true}}',
            errorCode: 403,
          },
          errorHandling: {
            '403': {
              handler: 'showErrorPage',
              params: { target: 'content' },
            },
          },
          fallback: { data: null },
        },
      ];

      const results = await manager.fetchDataSourcesWithResults(sources, {});

      expect(results).toHaveLength(1);
      expect(results[0].id).toBe('user');
      expect(results[0].state).toBe('success');
      expect(results[0].data).toEqual({ data: null });
    });
  });

  describe('auth_mode: required + 토큰 없을 때 요청 스킵', () => {
    it('auth_required: true이고 토큰이 없으면 요청을 스킵하고 fallback을 사용해야 함', async () => {
      // 비인증 상태 설정
      mockIsAuthenticated.mockReturnValue(false);

      const sources: DataSource[] = [
        {
          id: 'notifications',
          type: 'api',
          endpoint: '/api/user/notifications/unread-count',
          method: 'GET',
          auto_fetch: true,
          auth_required: true,
          loading_strategy: 'progressive',
          fallback: { data: { unread_count: 0 } },
        },
      ];

      const results = await manager.fetchDataSources(sources, {});

      // API 호출이 일어나지 않아야 함
      expect(mockApiClientInstance.get).not.toHaveBeenCalled();
      expect(global.fetch).not.toHaveBeenCalled();

      // fallback 데이터가 사용되어야 함
      expect(results.notifications).toEqual({ data: { unread_count: 0 } });

      // 인증 상태 복원
      mockIsAuthenticated.mockReturnValue(true);
    });

    it('auth_mode: "required"이고 토큰이 없으면 요청을 스킵하고 fallback을 사용해야 함', async () => {
      mockIsAuthenticated.mockReturnValue(false);

      const sources: DataSource[] = [
        {
          id: 'user_data',
          type: 'api',
          endpoint: '/api/user/profile',
          method: 'GET',
          auto_fetch: true,
          auth_mode: 'required',
          loading_strategy: 'progressive',
          fallback: { data: null },
        },
      ];

      const results = await manager.fetchDataSources(sources, {});

      expect(mockApiClientInstance.get).not.toHaveBeenCalled();
      expect(global.fetch).not.toHaveBeenCalled();
      expect(results.user_data).toEqual({ data: null });

      mockIsAuthenticated.mockReturnValue(true);
    });

    it('auth_required: true이고 토큰이 없고 fallback이 없으면 에러 상태여야 함', async () => {
      mockIsAuthenticated.mockReturnValue(false);

      const sources: DataSource[] = [
        {
          id: 'secure_data',
          type: 'api',
          endpoint: '/api/admin/data',
          method: 'GET',
          auto_fetch: true,
          auth_required: true,
          loading_strategy: 'progressive',
        },
      ];

      const results = await manager.fetchDataSources(sources, {});

      // API 호출이 일어나지 않아야 함
      expect(mockApiClientInstance.get).not.toHaveBeenCalled();
      expect(global.fetch).not.toHaveBeenCalled();

      // fallback 없으므로 결과에 데이터가 없어야 함
      expect(results.secure_data).toBeUndefined();

      mockIsAuthenticated.mockReturnValue(true);
    });

    it('auth_required: true이고 토큰이 있으면 정상적으로 API를 호출해야 함', async () => {
      mockIsAuthenticated.mockReturnValue(true);

      const mockResponse = { data: { unread_count: 5 } };
      mockApiClientInstance.get.mockResolvedValue(mockResponse);

      const sources: DataSource[] = [
        {
          id: 'notifications',
          type: 'api',
          endpoint: '/api/user/notifications/unread-count',
          method: 'GET',
          auto_fetch: true,
          auth_required: true,
          loading_strategy: 'progressive',
          fallback: { data: { unread_count: 0 } },
        },
      ];

      const results = await manager.fetchDataSources(sources, {});

      // API 호출이 발생해야 함
      expect(mockApiClientInstance.get).toHaveBeenCalled();
      expect(results.notifications).toEqual(mockResponse);
    });

    it('auth_mode: "optional"이고 토큰이 없으면 일반 fetch로 요청해야 함 (스킵하지 않음)', async () => {
      mockIsAuthenticated.mockReturnValue(false);

      const mockResponse = { data: { count: 3 } };
      (global.fetch as any).mockResolvedValue({
        ok: true,
        json: async () => mockResponse,
      });

      const sources: DataSource[] = [
        {
          id: 'cart',
          type: 'api',
          endpoint: '/api/cart',
          method: 'GET',
          auto_fetch: true,
          auth_mode: 'optional',
          loading_strategy: 'progressive',
        },
      ];

      const results = await manager.fetchDataSources(sources, {});

      // ApiClient가 아닌 일반 fetch가 사용되어야 함
      expect(mockApiClientInstance.get).not.toHaveBeenCalled();
      expect(global.fetch).toHaveBeenCalled();
      expect(results.cart).toEqual(mockResponse);

      mockIsAuthenticated.mockReturnValue(true);
    });
  });

});
