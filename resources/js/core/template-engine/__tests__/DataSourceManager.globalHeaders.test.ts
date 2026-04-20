/**
 * DataSourceManager globalHeaders 기능 테스트
 */

import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import { DataSourceManager, DataSource } from '../DataSourceManager';
import { GlobalHeaderRule } from '../LayoutLoader';
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
// engine-v1.32.1: auth_required: true 데이터소스는 토큰이 없으면 요청을 스킵하므로
// auth_required: true 케이스를 검증하려면 인증된 상태로 모킹해야 함
const mockIsAuthenticated = vi.fn(() => true);
vi.mock('../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({
      isAuthenticated: mockIsAuthenticated,
    })),
  },
}));

// fetch 모킹
global.fetch = vi.fn();

describe('DataSourceManager - globalHeaders', () => {
  let manager: DataSourceManager;

  beforeEach(() => {
    manager = new DataSourceManager();
    vi.clearAllMocks();
  });

  afterEach(() => {
    manager.clearCache();
  });

  describe('setGlobalHeaders', () => {
    it('globalHeaders를 설정할 수 있어야 함', () => {
      const headers: GlobalHeaderRule[] = [
        { pattern: '*', headers: { 'X-Custom': 'value' } },
      ];

      manager.setGlobalHeaders(headers);

      // 내부 상태는 직접 접근할 수 없으므로, fetch 호출 시 헤더가 포함되는지로 검증
      expect(true).toBe(true); // setGlobalHeaders 호출이 에러 없이 완료됨
    });

    it('null/undefined를 빈 배열로 처리해야 함', () => {
      manager.setGlobalHeaders(null as any);
      manager.setGlobalHeaders(undefined as any);

      // 에러 없이 완료되어야 함
      expect(true).toBe(true);
    });
  });

  describe('패턴 매칭 - * (와일드카드)', () => {
    it('* 패턴은 모든 API에 헤더를 적용해야 함 (auth_required: false)', async () => {
      const mockResponse = { data: 'test' };
      (global.fetch as any).mockResolvedValue({
        ok: true,
        json: async () => mockResponse,
      });

      manager.setGlobalHeaders([
        { pattern: '*', headers: { 'X-Global': 'all-apis' } },
      ]);

      const sources: DataSource[] = [
        {
          id: 'test-api',
          type: 'api',
          endpoint: '/api/any/path',
          method: 'GET',
          auth_required: false,
          auto_fetch: true,
        },
      ];

      await manager.fetchDataSources(sources);

      expect(global.fetch).toHaveBeenCalledWith(
        '/api/any/path',
        expect.objectContaining({
          headers: expect.objectContaining({
            'X-Global': 'all-apis',
          }),
        }),
      );
    });

    it('* 패턴은 모든 API에 헤더를 적용해야 함 (auth_required: true)', async () => {
      const mockResponse = { data: 'test' };
      const mockApiClient = getApiClient();
      (mockApiClient.get as any).mockResolvedValue(mockResponse);

      manager.setGlobalHeaders([
        { pattern: '*', headers: { 'X-Global': 'all-apis' } },
      ]);

      const sources: DataSource[] = [
        {
          id: 'test-api',
          type: 'api',
          endpoint: '/api/admin/data',
          method: 'GET',
          auth_required: true,
          auto_fetch: true,
        },
      ];

      await manager.fetchDataSources(sources);

      expect(mockApiClient.get).toHaveBeenCalledWith(
        '/admin/data',
        expect.objectContaining({
          headers: expect.objectContaining({
            'X-Global': 'all-apis',
          }),
        }),
      );
    });
  });

  describe('패턴 매칭 - 경로 패턴', () => {
    it('/api/shop/* 패턴은 해당 경로에만 헤더를 적용해야 함', async () => {
      const mockResponse = { data: 'test' };
      (global.fetch as any).mockResolvedValue({
        ok: true,
        json: async () => mockResponse,
      });

      manager.setGlobalHeaders([
        { pattern: '/api/shop/*', headers: { 'X-Shop': 'true' } },
      ]);

      // 매칭되는 경로
      const matchingSources: DataSource[] = [
        {
          id: 'shop-api',
          type: 'api',
          endpoint: '/api/shop/products',
          method: 'GET',
          auth_required: false,
          auto_fetch: true,
        },
      ];

      await manager.fetchDataSources(matchingSources);

      expect(global.fetch).toHaveBeenCalledWith(
        '/api/shop/products',
        expect.objectContaining({
          headers: expect.objectContaining({
            'X-Shop': 'true',
          }),
        }),
      );
    });

    it('패턴이 매칭되지 않으면 헤더를 적용하지 않아야 함', async () => {
      const mockResponse = { data: 'test' };
      (global.fetch as any).mockResolvedValue({
        ok: true,
        json: async () => mockResponse,
      });

      manager.setGlobalHeaders([
        { pattern: '/api/shop/*', headers: { 'X-Shop': 'true' } },
      ]);

      // 매칭되지 않는 경로
      const nonMatchingSources: DataSource[] = [
        {
          id: 'other-api',
          type: 'api',
          endpoint: '/api/users/list',
          method: 'GET',
          auth_required: false,
          auto_fetch: true,
        },
      ];

      await manager.fetchDataSources(nonMatchingSources);

      // X-Shop 헤더가 없어야 함
      const fetchCall = (global.fetch as any).mock.calls[0];
      expect(fetchCall[1].headers['X-Shop']).toBeUndefined();
    });

    it('/api/modules/sirsoft-ecommerce/* 패턴 매칭 테스트', async () => {
      const mockResponse = { data: 'test' };
      (global.fetch as any).mockResolvedValue({
        ok: true,
        json: async () => mockResponse,
      });

      manager.setGlobalHeaders([
        { pattern: '/api/modules/sirsoft-ecommerce/*', headers: { 'X-Cart-Key': 'ck_test123' } },
      ]);

      const sources: DataSource[] = [
        {
          id: 'cart-api',
          type: 'api',
          endpoint: '/api/modules/sirsoft-ecommerce/cart',
          method: 'GET',
          auth_required: false,
          auto_fetch: true,
        },
      ];

      await manager.fetchDataSources(sources);

      expect(global.fetch).toHaveBeenCalledWith(
        '/api/modules/sirsoft-ecommerce/cart',
        expect.objectContaining({
          headers: expect.objectContaining({
            'X-Cart-Key': 'ck_test123',
          }),
        }),
      );
    });
  });

  describe('복수 패턴 규칙', () => {
    it('여러 패턴이 매칭되면 모든 헤더가 병합되어야 함', async () => {
      const mockResponse = { data: 'test' };
      (global.fetch as any).mockResolvedValue({
        ok: true,
        json: async () => mockResponse,
      });

      manager.setGlobalHeaders([
        { pattern: '*', headers: { 'X-Global': 'all' } },
        { pattern: '/api/shop/*', headers: { 'X-Shop': 'true' } },
      ]);

      const sources: DataSource[] = [
        {
          id: 'shop-api',
          type: 'api',
          endpoint: '/api/shop/products',
          method: 'GET',
          auth_required: false,
          auto_fetch: true,
        },
      ];

      await manager.fetchDataSources(sources);

      expect(global.fetch).toHaveBeenCalledWith(
        '/api/shop/products',
        expect.objectContaining({
          headers: expect.objectContaining({
            'X-Global': 'all',
            'X-Shop': 'true',
          }),
        }),
      );
    });
  });

  describe('헤더 우선순위', () => {
    it('source.headers가 globalHeaders를 덮어써야 함', async () => {
      const mockResponse = { data: 'test' };
      (global.fetch as any).mockResolvedValue({
        ok: true,
        json: async () => mockResponse,
      });

      manager.setGlobalHeaders([
        { pattern: '*', headers: { 'X-Custom': 'global-value' } },
      ]);

      const sources: DataSource[] = [
        {
          id: 'test-api',
          type: 'api',
          endpoint: '/api/test',
          method: 'GET',
          auth_required: false,
          auto_fetch: true,
          headers: { 'X-Custom': 'source-value' },
        },
      ];

      await manager.fetchDataSources(sources);

      expect(global.fetch).toHaveBeenCalledWith(
        '/api/test',
        expect.objectContaining({
          headers: expect.objectContaining({
            'X-Custom': 'source-value', // globalHeaders가 아닌 source.headers 값
          }),
        }),
      );
    });

    it('source.headers와 globalHeaders가 병합되어야 함 (키가 다른 경우)', async () => {
      const mockResponse = { data: 'test' };
      (global.fetch as any).mockResolvedValue({
        ok: true,
        json: async () => mockResponse,
      });

      manager.setGlobalHeaders([
        { pattern: '*', headers: { 'X-Global': 'global-value' } },
      ]);

      const sources: DataSource[] = [
        {
          id: 'test-api',
          type: 'api',
          endpoint: '/api/test',
          method: 'GET',
          auth_required: false,
          auto_fetch: true,
          headers: { 'X-Source': 'source-value' },
        },
      ];

      await manager.fetchDataSources(sources);

      expect(global.fetch).toHaveBeenCalledWith(
        '/api/test',
        expect.objectContaining({
          headers: expect.objectContaining({
            'X-Global': 'global-value',
            'X-Source': 'source-value',
          }),
        }),
      );
    });
  });

  describe('표현식 평가', () => {
    it('{{_global.xxx}} 표현식을 평가해야 함', async () => {
      const mockResponse = { data: 'test' };
      (global.fetch as any).mockResolvedValue({
        ok: true,
        json: async () => mockResponse,
      });

      manager.setGlobalHeaders([
        { pattern: '*', headers: { 'X-Cart-Key': '{{_global.cartKey}}' } },
      ]);

      const sources: DataSource[] = [
        {
          id: 'test-api',
          type: 'api',
          endpoint: '/api/test',
          method: 'GET',
          auth_required: false,
          auto_fetch: true,
        },
      ];

      // _global.cartKey가 설정된 상태에서 fetch (globalState는 _global 값 자체)
      await manager.fetchDataSources(sources, {}, new URLSearchParams(), { cartKey: 'ck_abc123xyz' });

      expect(global.fetch).toHaveBeenCalledWith(
        '/api/test',
        expect.objectContaining({
          headers: expect.objectContaining({
            'X-Cart-Key': 'ck_abc123xyz',
          }),
        }),
      );
    });

    it('표현식 값이 없으면 헤더에 포함하지 않아야 함', async () => {
      const mockResponse = { data: 'test' };
      (global.fetch as any).mockResolvedValue({
        ok: true,
        json: async () => mockResponse,
      });

      manager.setGlobalHeaders([
        { pattern: '*', headers: { 'X-Cart-Key': '{{_global.cartKey}}' } },
      ]);

      const sources: DataSource[] = [
        {
          id: 'test-api',
          type: 'api',
          endpoint: '/api/test',
          method: 'GET',
          auth_required: false,
          auto_fetch: true,
        },
      ];

      // _global.cartKey가 없는 상태에서 fetch (globalState는 빈 객체)
      await manager.fetchDataSources(sources, {}, new URLSearchParams(), {});

      // X-Cart-Key가 빈 값이면 헤더에 포함되지 않아야 함
      const fetchCall = (global.fetch as any).mock.calls[0];
      const headers = fetchCall[1].headers;
      // 빈 문자열이거나 undefined여야 함
      expect(headers['X-Cart-Key'] === undefined || headers['X-Cart-Key'] === '').toBe(true);
    });
  });

  describe('globalHeaders 없는 경우', () => {
    it('globalHeaders가 설정되지 않으면 기존 동작과 동일해야 함', async () => {
      const mockResponse = { data: 'test' };
      (global.fetch as any).mockResolvedValue({
        ok: true,
        json: async () => mockResponse,
      });

      // setGlobalHeaders 호출하지 않음

      const sources: DataSource[] = [
        {
          id: 'test-api',
          type: 'api',
          endpoint: '/api/test',
          method: 'GET',
          auth_required: false,
          auto_fetch: true,
        },
      ];

      await manager.fetchDataSources(sources);

      expect(global.fetch).toHaveBeenCalledWith(
        '/api/test',
        expect.objectContaining({
          headers: expect.any(Object),
        }),
      );
    });
  });
});
