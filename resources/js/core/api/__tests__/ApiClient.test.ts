/**
 * ApiClient 테스트
 */

import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import ApiClient, { createApiClient, getApiClient } from '../ApiClient';
import axios from 'axios';

// Axios 모킹
vi.mock('axios');
const mockedAxios = axios as any;

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
});
