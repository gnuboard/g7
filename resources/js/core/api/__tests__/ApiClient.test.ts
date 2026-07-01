/**
 * ApiClient 테스트
 */

import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import ApiClient, { createApiClient, getApiClient } from '../ApiClient';
import axios from 'axios';
import { IdentityGuardInterceptor } from '../../identity/IdentityGuardInterceptor';

// Axios 모킹
vi.mock('axios');
const mockedAxios = axios as any;

// IdentityGuardInterceptor 모킹 (428 중앙 처리 검증용)
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

describe('ApiClient', () => {
  let apiClient: ApiClient;

  beforeEach(() => {
    // localStorage 모킹
    const localStorageMock = (() => {
      let store: Record<string, string> = {};
      return {
        getItem: (key: string) => store[key] || null,
        setItem: (key: string, value: string) => {
          store[key] = value;
        },
        removeItem: (key: string) => {
          delete store[key];
        },
        clear: () => {
          store = {};
        },
      };
    })();

    Object.defineProperty(window, 'localStorage', {
      value: localStorageMock,
      writable: true,
    });

    // Axios 인스턴스 모킹
    mockedAxios.create.mockReturnValue({
      interceptors: {
        request: {
          use: vi.fn((onFulfilled) => {
            return 0;
          }),
        },
        response: {
          use: vi.fn((onFulfilled, onRejected) => {
            return 0;
          }),
        },
      },
      get: vi.fn(),
      post: vi.fn(),
      put: vi.fn(),
      patch: vi.fn(),
      delete: vi.fn(),
    });

    apiClient = new ApiClient();
  });

  afterEach(() => {
    vi.clearAllMocks();
    localStorage.clear();
  });

  describe('토큰 관리', () => {
    it('토큰을 저장할 수 있어야 함', () => {
      apiClient.setToken('test-token');
      expect(localStorage.getItem('auth_token')).toBe('test-token');
    });

    it('토큰을 조회할 수 있어야 함', () => {
      localStorage.setItem('auth_token', 'test-token');
      expect(apiClient.getToken()).toBe('test-token');
    });

    it('토큰을 삭제할 수 있어야 함', () => {
      localStorage.setItem('auth_token', 'test-token');
      apiClient.removeToken();
      expect(localStorage.getItem('auth_token')).toBeNull();
    });

    it('토큰이 없으면 null을 반환해야 함', () => {
      expect(apiClient.getToken()).toBeNull();
    });
  });

  describe('HTTP 메서드', () => {
    it('GET 요청을 수행할 수 있어야 함', async () => {
      const mockData = { data: 'test' };
      const mockResponse = { data: mockData };

      const instance = apiClient.getInstance();
      instance.get = vi.fn().mockResolvedValue(mockResponse);

      const result = await apiClient.get('/test');
      expect(result).toEqual(mockData);
      expect(instance.get).toHaveBeenCalledWith('/test', undefined);
    });

    it('POST 요청을 수행할 수 있어야 함', async () => {
      const mockData = { data: 'test' };
      const mockResponse = { data: mockData };
      const postData = { name: 'test' };

      const instance = apiClient.getInstance();
      instance.post = vi.fn().mockResolvedValue(mockResponse);

      const result = await apiClient.post('/test', postData);
      expect(result).toEqual(mockData);
      expect(instance.post).toHaveBeenCalledWith('/test', postData, undefined);
    });

    it('PUT 요청을 수행할 수 있어야 함', async () => {
      const mockData = { data: 'test' };
      const mockResponse = { data: mockData };
      const putData = { name: 'test' };

      const instance = apiClient.getInstance();
      instance.put = vi.fn().mockResolvedValue(mockResponse);

      const result = await apiClient.put('/test', putData);
      expect(result).toEqual(mockData);
      expect(instance.put).toHaveBeenCalledWith('/test', putData, undefined);
    });

    it('PATCH 요청을 수행할 수 있어야 함', async () => {
      const mockData = { data: 'test' };
      const mockResponse = { data: mockData };
      const patchData = { name: 'test' };

      const instance = apiClient.getInstance();
      instance.patch = vi.fn().mockResolvedValue(mockResponse);

      const result = await apiClient.patch('/test', patchData);
      expect(result).toEqual(mockData);
      expect(instance.patch).toHaveBeenCalledWith('/test', patchData, undefined);
    });

    it('DELETE 요청을 수행할 수 있어야 함', async () => {
      const mockData = { data: 'test' };
      const mockResponse = { data: mockData };

      const instance = apiClient.getInstance();
      instance.delete = vi.fn().mockResolvedValue(mockResponse);

      const result = await apiClient.delete('/test');
      expect(result).toEqual(mockData);
      expect(instance.delete).toHaveBeenCalledWith('/test', undefined);
    });
  });

  describe('싱글톤 인스턴스', () => {
    it('createApiClient는 싱글톤 인스턴스를 반환해야 함', () => {
      const client1 = createApiClient();
      const client2 = createApiClient();
      expect(client1).toBe(client2);
    });

    it('getApiClient는 싱글톤 인스턴스를 반환해야 함', () => {
      const client1 = getApiClient();
      const client2 = getApiClient();
      expect(client1).toBe(client2);
    });
  });

  describe('428 본인인증 중앙 처리 (IDV)', () => {
    /**
     * response 인터셉터의 onRejected 콜백을 캡처해 직접 호출하기 위한 헬퍼.
     * axios.create 가 use(onFulfilled, onRejected) 로 받은 onRejected 를 보관한다.
     */
    function buildClientCapturingRejected(): { onRejected: (e: any) => Promise<any> } {
      let captured: ((e: any) => Promise<any>) | null = null;
      mockedAxios.create.mockReturnValue({
        interceptors: {
          request: { use: vi.fn(() => 0) },
          response: {
            use: vi.fn((_onFulfilled: any, onRejected: any) => {
              captured = onRejected;
              return 0;
            }),
          },
        },
        get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(),
      });
      new ApiClient();
      return { onRejected: captured! };
    }

    const idv428Error = {
      config: { url: '/api/x', headers: { Authorization: 'Bearer t' }, data: JSON.stringify({ a: 1 }) },
      response: {
        status: 428,
        data: { error_code: 'identity_verification_required', verification: { return_request: { method: 'POST', url: '/api/x' } } },
      },
    };

    it('428 응답을 IdentityGuardInterceptor 로 인터셉트하고 verify 성공 시 재실행 응답을 반환한다', async () => {
      mockedGuard.isIdentityRequired.mockReturnValue(true);
      mockedGuard.handle.mockResolvedValue({
        ok: true, status: 200, json: async () => ({ success: true, data: { confirmed: true } }),
      } as any);

      const { onRejected } = buildClientCapturingRejected();
      const result = await onRejected(idv428Error);

      expect(mockedGuard.handle).toHaveBeenCalledOnce();
      expect(result.status).toBe(200);
      expect(result.data).toEqual({ success: true, data: { confirmed: true } });
    });

    it('428 인데 사용자가 본인인증을 취소(handle=null)하면 원 에러를 그대로 전파한다', async () => {
      mockedGuard.isIdentityRequired.mockReturnValue(true);
      mockedGuard.handle.mockResolvedValue(null);

      const { onRejected } = buildClientCapturingRejected();
      await expect(onRejected(idv428Error)).rejects.toBe(idv428Error);
    });

    it('428 이 아니면 IdentityGuard 를 호출하지 않고 일반 에러로 전파한다', async () => {
      mockedGuard.isIdentityRequired.mockReturnValue(false);

      const { onRejected } = buildClientCapturingRejected();
      const err422 = { config: { url: '/api/y' }, response: { status: 422, data: { message: 'invalid' } } };
      await expect(onRejected(err422)).rejects.toBe(err422);
      expect(mockedGuard.handle).not.toHaveBeenCalled();
    });

    /**
     * 신규(engine-v1.51.0) — axios(G7Core.api) 호출 경로도 config.identity_target 을 launcher 로 전달.
     * apiCall(handleApiCall, native fetch) 경로와 동일하게 모든 API 호출 방식에서 IDV target 지원.
     */
    it('config.identity_target 을 IdentityGuardInterceptor.handle 의 target 인자로 전달한다', async () => {
      mockedGuard.isIdentityRequired.mockReturnValue(true);
      mockedGuard.handle.mockResolvedValue({
        ok: true, status: 200, json: async () => ({ success: true }),
      } as any);

      const errWithTarget = {
        config: {
          url: '/api/orders',
          headers: {},
          data: JSON.stringify({ a: 1 }),
          identity_target: { email: 'guest@example.com', phone: '01099998888' },
        },
        response: {
          status: 428,
          data: { error_code: 'identity_verification_required', verification: { return_request: { method: 'POST', url: '/api/orders' } } },
        },
      };

      const { onRejected } = buildClientCapturingRejected();
      await onRejected(errWithTarget);

      expect(mockedGuard.handle).toHaveBeenCalledWith(
        errWithTarget.response.data,
        expect.any(Object),
        { email: 'guest@example.com', phone: '01099998888' },
      );
    });
  });
});
