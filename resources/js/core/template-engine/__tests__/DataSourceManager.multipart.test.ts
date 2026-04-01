/**
 * DataSourceManager multipart/form-data 지원 테스트
 *
 * contentType: "multipart/form-data" 설정 시:
 * - params가 FormData로 변환
 * - Content-Type 헤더 미설정 (브라우저 자동)
 * - File/Blob 원본 유지, null/undefined 제외
 * - 인증/비인증 경로 모두 지원
 */

import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import { DataSourceManager, DataSource } from '../DataSourceManager';
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

// AuthManager 모킹
const mockIsAuthenticated = vi.fn(() => true);
vi.mock('../../auth/AuthManager', () => ({
  AuthManager: {
    getInstance: vi.fn(() => ({
      isAuthenticated: mockIsAuthenticated,
      state: { isAuthenticated: true },
    })),
  },
}));

// ErrorHandlingResolver 모킹
vi.mock('../../error', () => ({
  getErrorHandlingResolver: vi.fn(() => ({
    resolve: vi.fn(() => ({ handler: null, source: 'default' })),
    execute: vi.fn(),
    setLayoutConfig: vi.fn(),
    setTemplateConfig: vi.fn(),
    clearConfig: vi.fn(),
  })),
}));

// RenderHelpers 모킹
vi.mock('../helpers/RenderHelpers', () => ({
  resolveExpressionString: vi.fn((expr: string) => {
    // 간단한 표현식 평가: 문자열 그대로 반환
    if (typeof expr === 'string' && !expr.includes('{{')) return expr;
    return expr;
  }),
  evaluateRenderCondition: vi.fn(() => true),
}));

// DataBindingEngine 모킹
vi.mock('../DataBindingEngine', () => {
  return {
    DataBindingEngine: class MockDataBindingEngine {
      resolveExpression(expr: string) { return expr; }
      resolveTemplate(template: string) { return template; }
    },
  };
});

// ActionDispatcher 모킹
vi.mock('../ActionDispatcher', () => ({
  getActionDispatcher: vi.fn(() => null),
}));

// Logger 모킹
vi.mock('../../utils/Logger', () => ({
  createLogger: vi.fn(() => ({
    log: vi.fn(),
    warn: vi.fn(),
    error: vi.fn(),
    debug: vi.fn(),
  })),
}));

// WebSocketManager 모킹
vi.mock('../../websocket/WebSocketManager', () => ({
  webSocketManager: {
    subscribe: vi.fn(),
    unsubscribe: vi.fn(),
    isConnected: vi.fn(() => false),
  },
}));

// fetch 모킹
(globalThis as any).fetch = vi.fn();

describe('DataSourceManager - multipart/form-data 지원', () => {
  let manager: DataSourceManager;

  beforeEach(() => {
    manager = new DataSourceManager();
    vi.clearAllMocks();
    mockIsAuthenticated.mockReturnValue(true);
  });

  afterEach(() => {
    manager.clearCache();
  });

  describe('인증 경로 (auth_required: true)', () => {
    it('contentType: "multipart/form-data" → FormData로 변환하여 ApiClient POST 호출', async () => {
      const testFile = new File(['test content'], 'test.zip', { type: 'application/zip' });
      const mockApiClient = getApiClient();
      (mockApiClient.post as any).mockResolvedValue({ success: true });

      const sources: DataSource[] = [
        {
          id: 'upload',
          type: 'api',
          endpoint: '/api/admin/modules/manual-install',
          method: 'POST',
          auto_fetch: true,
          auth_required: true,
          contentType: 'multipart/form-data',
          params: {
            file: testFile,
            source: 'file_upload',
          },
        },
      ];

      // 에러 수집을 위한 onError 콜백
      const errors: Error[] = [];
      manager = new DataSourceManager({ onError: (err) => errors.push(err) });
      await manager.fetchDataSources(sources);

      // 내부 에러가 있으면 출력
      if (errors.length > 0) {
        console.error('DataSourceManager internal errors:', errors.map(e => e.message));
      }

      expect(mockApiClient.post).toHaveBeenCalled();

      const callArgs = (mockApiClient.post as any).mock.calls[0];
      const formData: FormData = callArgs[1];
      expect(formData).toBeInstanceOf(FormData);
      expect(formData.has('file')).toBe(true);
      expect(formData.get('source')).toBe('file_upload');
    });

    it('contentType 미지정 → JSON 방식 유지 (FormData 아님)', async () => {
      const mockApiClient = getApiClient();
      (mockApiClient.post as any).mockResolvedValue({ success: true });

      const sources: DataSource[] = [
        {
          id: 'json-api',
          type: 'api',
          endpoint: '/api/admin/data',
          method: 'POST',
          auto_fetch: true,
          auth_required: true,
          params: {
            name: 'test',
            value: 123,
          },
        },
      ];

      await manager.fetchDataSources(sources);

      expect(mockApiClient.post).toHaveBeenCalled();
      const callArgs = (mockApiClient.post as any).mock.calls[0];
      expect(callArgs[1]).not.toBeInstanceOf(FormData);
      expect(callArgs[1]).toEqual({ name: 'test', value: 123 });
    });

    it('multipart FormData에서 null/undefined 값은 제외', async () => {
      const mockApiClient = getApiClient();
      (mockApiClient.post as any).mockResolvedValue({ success: true });

      const sources: DataSource[] = [
        {
          id: 'upload-nullable',
          type: 'api',
          endpoint: '/api/admin/upload',
          method: 'POST',
          auto_fetch: true,
          auth_required: true,
          contentType: 'multipart/form-data',
          params: {
            name: 'test',
            file: null,
            description: undefined,
          },
        },
      ];

      await manager.fetchDataSources(sources);

      expect(mockApiClient.post).toHaveBeenCalled();
      const callArgs = (mockApiClient.post as any).mock.calls[0];
      const formData: FormData = callArgs[1];
      expect(formData.has('name')).toBe(true);
      expect(formData.has('file')).toBe(false);
      expect(formData.has('description')).toBe(false);
    });

    it('multipart FormData에서 객체/배열 값은 JSON.stringify로 변환', async () => {
      const mockApiClient = getApiClient();
      (mockApiClient.post as any).mockResolvedValue({ success: true });

      const sources: DataSource[] = [
        {
          id: 'upload-with-meta',
          type: 'api',
          endpoint: '/api/admin/upload',
          method: 'POST',
          auto_fetch: true,
          auth_required: true,
          contentType: 'multipart/form-data',
          params: {
            metadata: { key: 'value', nested: true },
            tags: ['a', 'b'],
          },
        },
      ];

      await manager.fetchDataSources(sources);

      expect(mockApiClient.post).toHaveBeenCalled();
      const callArgs = (mockApiClient.post as any).mock.calls[0];
      const formData: FormData = callArgs[1];
      expect(formData.get('metadata')).toBe(JSON.stringify({ key: 'value', nested: true }));
      expect(formData.get('tags')).toBe(JSON.stringify(['a', 'b']));
    });

    it('multipart PUT 요청도 FormData 변환', async () => {
      const mockApiClient = getApiClient();
      (mockApiClient.put as any).mockResolvedValue({ success: true });

      const sources: DataSource[] = [
        {
          id: 'update-upload',
          type: 'api',
          endpoint: '/api/admin/files/1',
          method: 'PUT',
          auto_fetch: true,
          auth_required: true,
          contentType: 'multipart/form-data',
          params: {
            file: new File(['data'], 'update.zip'),
            name: 'updated',
          },
        },
      ];

      await manager.fetchDataSources(sources);

      expect(mockApiClient.put).toHaveBeenCalled();
      const callArgs = (mockApiClient.put as any).mock.calls[0];
      expect(callArgs[1]).toBeInstanceOf(FormData);
    });
  });

  describe('비인증 경로 (auth_required: false)', () => {
    it('contentType: "multipart/form-data" → fetch에 FormData body 전송', async () => {
      mockIsAuthenticated.mockReturnValue(false);
      const testFile = new File(['data'], 'upload.zip', { type: 'application/zip' });
      ((globalThis as any).fetch as any).mockResolvedValue({
        ok: true,
        json: async () => ({ success: true }),
      });

      const sources: DataSource[] = [
        {
          id: 'public-upload',
          type: 'api',
          endpoint: '/api/public/upload',
          method: 'POST',
          auto_fetch: true,
          auth_required: false,
          contentType: 'multipart/form-data',
          params: {
            file: testFile,
            name: 'test',
          },
        },
      ];

      await manager.fetchDataSources(sources);

      expect((globalThis as any).fetch).toHaveBeenCalledWith(
        '/api/public/upload',
        expect.objectContaining({
          method: 'POST',
          body: expect.any(FormData),
        }),
      );

      // Content-Type 헤더가 없어야 함 (브라우저 자동 설정)
      const fetchCall = ((globalThis as any).fetch as any).mock.calls[0];
      const headers = fetchCall[1].headers;
      expect(headers['Content-Type']).toBeUndefined();
    });

    it('비인증 경로에서 contentType 미지정 → JSON body 전송', async () => {
      mockIsAuthenticated.mockReturnValue(false);
      ((globalThis as any).fetch as any).mockResolvedValue({
        ok: true,
        json: async () => ({ success: true }),
      });

      const sources: DataSource[] = [
        {
          id: 'public-json',
          type: 'api',
          endpoint: '/api/public/data',
          method: 'POST',
          auto_fetch: true,
          auth_required: false,
          params: {
            name: 'test',
          },
        },
      ];

      await manager.fetchDataSources(sources);

      expect((globalThis as any).fetch).toHaveBeenCalledWith(
        '/api/public/data',
        expect.objectContaining({
          method: 'POST',
          body: JSON.stringify({ name: 'test' }),
        }),
      );

      // Content-Type이 application/json으로 설정되어야 함
      const fetchCall = ((globalThis as any).fetch as any).mock.calls[0];
      const headers = fetchCall[1].headers;
      expect(headers['Content-Type']).toBe('application/json');
    });
  });

  describe('toFormData 변환 규칙', () => {
    it('File 객체는 원본 그대로 append', async () => {
      const mockApiClient = getApiClient();
      (mockApiClient.post as any).mockResolvedValue({ success: true });
      const testFile = new File(['binary data'], 'document.pdf', { type: 'application/pdf' });

      const sources: DataSource[] = [
        {
          id: 'file-upload',
          type: 'api',
          endpoint: '/api/admin/upload',
          method: 'POST',
          auto_fetch: true,
          auth_required: true,
          contentType: 'multipart/form-data',
          params: { file: testFile },
        },
      ];

      await manager.fetchDataSources(sources);

      const callArgs = (mockApiClient.post as any).mock.calls[0];
      const formData: FormData = callArgs[1];
      // jsdom에서 FormData.get(File)은 문자열을 반환할 수 있으므로 has만 확인
      expect(formData.has('file')).toBe(true);
    });

    it('Blob 객체는 원본 그대로 append', async () => {
      const mockApiClient = getApiClient();
      (mockApiClient.post as any).mockResolvedValue({ success: true });
      const testBlob = new Blob(['blob data'], { type: 'application/octet-stream' });

      const sources: DataSource[] = [
        {
          id: 'blob-upload',
          type: 'api',
          endpoint: '/api/admin/upload',
          method: 'POST',
          auto_fetch: true,
          auth_required: true,
          contentType: 'multipart/form-data',
          params: { data: testBlob },
        },
      ];

      await manager.fetchDataSources(sources);

      const callArgs = (mockApiClient.post as any).mock.calls[0];
      const formData: FormData = callArgs[1];
      expect(formData.has('data')).toBe(true);
    });

    it('number/boolean 값은 String으로 변환', async () => {
      const mockApiClient = getApiClient();
      (mockApiClient.post as any).mockResolvedValue({ success: true });

      const sources: DataSource[] = [
        {
          id: 'primitives',
          type: 'api',
          endpoint: '/api/admin/upload',
          method: 'POST',
          auto_fetch: true,
          auth_required: true,
          contentType: 'multipart/form-data',
          params: {
            count: 42,
            active: true,
            label: 'test',
          },
        },
      ];

      await manager.fetchDataSources(sources);

      const callArgs = (mockApiClient.post as any).mock.calls[0];
      const formData: FormData = callArgs[1];
      expect(formData.get('count')).toBe('42');
      expect(formData.get('active')).toBe('true');
      expect(formData.get('label')).toBe('test');
    });
  });
});
