/// <reference types="vitest/globals" />
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { ErrorPageHandler, type RenderFunction, type RenderOptions } from '../ErrorPageHandler';
import type { LayoutLoader } from '../LayoutLoader';
import type { DataSourceManager } from '../DataSourceManager';

describe('ErrorPageHandler', () => {
  let handler: ErrorPageHandler;
  let mockLayoutLoader: LayoutLoader;
  let mockDataSourceManager: DataSourceManager;
  let mockRenderFunction: RenderFunction;
  let renderCallArgs: RenderOptions | null;
  let globalState: Record<string, unknown>;

  beforeEach(() => {
    renderCallArgs = null;
    globalState = {};

    mockLayoutLoader = {
      loadLayout: vi.fn(),
    } as any;

    mockDataSourceManager = {
      fetchDataSources: vi.fn().mockResolvedValue({}),
    } as any;

    mockRenderFunction = vi.fn(async (options: RenderOptions) => {
      renderCallArgs = options;
    });

    // template config fetch 모킹
    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({
        success: true,
        data: {
          error_config: {
            layouts: { 404: '404', 403: '403', 500: '500' },
          },
        },
      }),
    });

    handler = new ErrorPageHandler({
      templateId: 'sirsoft-basic',
      layoutLoader: mockLayoutLoader,
      locale: 'ko',
      debug: false,
      renderFunction: mockRenderFunction,
      dataSourceManager: mockDataSourceManager,
      globalState,
    });
  });

  describe('initGlobal 처리', () => {
    it('문자열 형태 initGlobal — _global에 데이터 매핑', async () => {
      const mockUserData = { uuid: 'test-uuid', name: '테스트 사용자', email: 'test@example.com' };

      (mockLayoutLoader.loadLayout as any).mockResolvedValue({
        version: '1.0.0',
        layout_name: '404',
        data_sources: [
          {
            id: 'current_user',
            type: 'api',
            endpoint: '/api/auth/user',
            method: 'GET',
            auto_fetch: true,
            loading_strategy: 'progressive',
            initGlobal: 'currentUser',
          },
        ],
        components: [],
      });

      (mockDataSourceManager.fetchDataSources as any).mockResolvedValue({
        current_user: { data: mockUserData },
      });

      const result = await handler.renderError(404);

      expect(result).toBe(true);
      expect(renderCallArgs).not.toBeNull();
      expect((renderCallArgs!.dataContext as any)._global.currentUser).toEqual(mockUserData);
    });

    it('객체 형태 {key, path} initGlobal — 중첩 경로에서 값 추출', async () => {
      (mockLayoutLoader.loadLayout as any).mockResolvedValue({
        version: '1.0.0',
        layout_name: '404',
        data_sources: [
          {
            id: 'cart',
            type: 'api',
            endpoint: '/api/cart/count',
            method: 'GET',
            auto_fetch: true,
            loading_strategy: 'progressive',
            initGlobal: { key: 'cartCount', path: 'count' },
          },
        ],
        components: [],
      });

      (mockDataSourceManager.fetchDataSources as any).mockResolvedValue({
        cart: { data: { count: 5, items: [] } },
      });

      const result = await handler.renderError(404);

      expect(result).toBe(true);
      expect((renderCallArgs!.dataContext as any)._global.cartCount).toBe(5);
    });

    it('배열 형태 initGlobal — 복수 전역 상태 동시 초기화', async () => {
      const mockData = { name: '테스트', role: 'admin' };

      (mockLayoutLoader.loadLayout as any).mockResolvedValue({
        version: '1.0.0',
        layout_name: '404',
        data_sources: [
          {
            id: 'user_info',
            type: 'api',
            endpoint: '/api/user',
            method: 'GET',
            auto_fetch: true,
            loading_strategy: 'progressive',
            initGlobal: ['userProfile', 'currentAccount'],
          },
        ],
        components: [],
      });

      (mockDataSourceManager.fetchDataSources as any).mockResolvedValue({
        user_info: { data: mockData },
      });

      const result = await handler.renderError(404);

      expect(result).toBe(true);
      expect((renderCallArgs!.dataContext as any)._global.userProfile).toEqual(mockData);
      expect((renderCallArgs!.dataContext as any)._global.currentAccount).toEqual(mockData);
    });

    it('복수 데이터소스 각각 initGlobal 처리', async () => {
      const mockUserData = { uuid: 'u1', name: '사용자' };

      (mockLayoutLoader.loadLayout as any).mockResolvedValue({
        version: '1.0.0',
        layout_name: '404',
        data_sources: [
          {
            id: 'current_user',
            type: 'api',
            endpoint: '/api/auth/user',
            method: 'GET',
            auto_fetch: true,
            loading_strategy: 'progressive',
            initGlobal: 'currentUser',
          },
          {
            id: 'cart',
            type: 'api',
            endpoint: '/api/cart/count',
            method: 'GET',
            auto_fetch: true,
            loading_strategy: 'progressive',
            initGlobal: { key: 'cartCount', path: 'count' },
          },
        ],
        components: [],
      });

      (mockDataSourceManager.fetchDataSources as any).mockResolvedValue({
        current_user: { data: mockUserData },
        cart: { data: { count: 3 } },
      });

      const result = await handler.renderError(404);

      expect(result).toBe(true);
      expect((renderCallArgs!.dataContext as any)._global.currentUser).toEqual(mockUserData);
      expect((renderCallArgs!.dataContext as any)._global.cartCount).toBe(3);
    });
  });

  describe('initLocal 처리', () => {
    it('문자열 형태 initLocal — _local에 데이터 매핑', async () => {
      const mockFormData = { title: '', content: '' };

      (mockLayoutLoader.loadLayout as any).mockResolvedValue({
        version: '1.0.0',
        layout_name: '404',
        data_sources: [
          {
            id: 'form_defaults',
            type: 'api',
            endpoint: '/api/form/defaults',
            method: 'GET',
            auto_fetch: true,
            loading_strategy: 'progressive',
            initLocal: 'formData',
          },
        ],
        components: [],
      });

      (mockDataSourceManager.fetchDataSources as any).mockResolvedValue({
        form_defaults: { data: mockFormData },
      });

      const result = await handler.renderError(404);

      expect(result).toBe(true);
      expect((renderCallArgs!.dataContext as any)._local.formData).toEqual(mockFormData);
    });

    it('객체 형태 {key, path} initLocal — 중첩 경로에서 값 추출', async () => {
      (mockLayoutLoader.loadLayout as any).mockResolvedValue({
        version: '1.0.0',
        layout_name: '404',
        data_sources: [
          {
            id: 'settings',
            type: 'api',
            endpoint: '/api/settings',
            method: 'GET',
            auto_fetch: true,
            loading_strategy: 'progressive',
            initLocal: { key: 'theme', path: 'display.theme' },
          },
        ],
        components: [],
      });

      (mockDataSourceManager.fetchDataSources as any).mockResolvedValue({
        settings: { data: { display: { theme: 'dark' } } },
      });

      const result = await handler.renderError(404);

      expect(result).toBe(true);
      expect((renderCallArgs!.dataContext as any)._local.theme).toBe('dark');
    });
  });

  describe('initGlobal/initLocal 미정의 시', () => {
    it('initGlobal/initLocal 없는 데이터소스 — 기존 동작 유지', async () => {
      (mockLayoutLoader.loadLayout as any).mockResolvedValue({
        version: '1.0.0',
        layout_name: '404',
        data_sources: [
          {
            id: 'some_data',
            type: 'api',
            endpoint: '/api/data',
            method: 'GET',
            auto_fetch: true,
            loading_strategy: 'progressive',
          },
        ],
        components: [],
      });

      (mockDataSourceManager.fetchDataSources as any).mockResolvedValue({
        some_data: { data: { key: 'value' } },
      });

      const result = await handler.renderError(404);

      expect(result).toBe(true);
      // fetchedData에는 데이터 존재
      expect((renderCallArgs!.dataContext as any).some_data).toEqual({ data: { key: 'value' } });
      // _global/_local에는 매핑되지 않음
      expect((renderCallArgs!.dataContext as any)._global.some_data).toBeUndefined();
      expect((renderCallArgs!.dataContext as any)._local).toEqual({});
    });

    it('데이터소스 없는 레이아웃 — 정상 렌더링', async () => {
      (mockLayoutLoader.loadLayout as any).mockResolvedValue({
        version: '1.0.0',
        layout_name: '404',
        components: [],
      });

      const result = await handler.renderError(404);

      expect(result).toBe(true);
      expect((renderCallArgs!.dataContext as any)._global).toEqual({});
      expect((renderCallArgs!.dataContext as any)._local).toEqual({});
    });
  });

  describe('에러 처리', () => {
    it('데이터 fetch 실패 — 크래시 없이 렌더링 진행', async () => {
      (mockLayoutLoader.loadLayout as any).mockResolvedValue({
        version: '1.0.0',
        layout_name: '404',
        data_sources: [
          {
            id: 'current_user',
            type: 'api',
            endpoint: '/api/auth/user',
            method: 'GET',
            auto_fetch: true,
            loading_strategy: 'progressive',
            initGlobal: 'currentUser',
          },
        ],
        components: [],
      });

      (mockDataSourceManager.fetchDataSources as any).mockRejectedValue(
        new Error('Network error')
      );

      const result = await handler.renderError(404);

      expect(result).toBe(true);
      expect(renderCallArgs).not.toBeNull();
      // fetch 실패 시 _global.currentUser는 undefined
      expect((renderCallArgs!.dataContext as any)._global.currentUser).toBeUndefined();
    });

    it('fetch 데이터가 null인 데이터소스 — 건너뛰기', async () => {
      (mockLayoutLoader.loadLayout as any).mockResolvedValue({
        version: '1.0.0',
        layout_name: '404',
        data_sources: [
          {
            id: 'current_user',
            type: 'api',
            endpoint: '/api/auth/user',
            method: 'GET',
            auto_fetch: true,
            loading_strategy: 'progressive',
            initGlobal: 'currentUser',
          },
        ],
        components: [],
      });

      (mockDataSourceManager.fetchDataSources as any).mockResolvedValue({
        current_user: null,
      });

      const result = await handler.renderError(404);

      expect(result).toBe(true);
      expect((renderCallArgs!.dataContext as any)._global.currentUser).toBeUndefined();
    });
  });

  describe('globalState 참조 업데이트', () => {
    it('processInitOptions가 globalState를 직접 변이시킨다', async () => {
      const sharedState: Record<string, unknown> = { existingKey: 'preserved' };

      handler = new ErrorPageHandler({
        templateId: 'sirsoft-basic',
        layoutLoader: mockLayoutLoader,
        locale: 'ko',
        debug: false,
        renderFunction: mockRenderFunction,
        dataSourceManager: mockDataSourceManager,
        globalState: sharedState,
      });

      (mockLayoutLoader.loadLayout as any).mockResolvedValue({
        version: '1.0.0',
        layout_name: '404',
        data_sources: [
          {
            id: 'current_user',
            type: 'api',
            endpoint: '/api/auth/user',
            method: 'GET',
            auto_fetch: true,
            loading_strategy: 'progressive',
            initGlobal: 'currentUser',
          },
        ],
        components: [],
      });

      (mockDataSourceManager.fetchDataSources as any).mockResolvedValue({
        current_user: { data: { uuid: 'u1' } },
      });

      await handler.renderError(404);

      // 기존 키 유지 + 새 키 추가
      expect((renderCallArgs!.dataContext as any)._global.existingKey).toBe('preserved');
      expect((renderCallArgs!.dataContext as any)._global.currentUser).toEqual({ uuid: 'u1' });
    });
  });
});
