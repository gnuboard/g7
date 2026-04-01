/// <reference types="vitest/globals" />
import { describe, it, expect, beforeEach, vi } from 'vitest';
import { LayoutLoader, LayoutLoaderError, type LayoutData } from '../LayoutLoader';
import type { ComponentRegistry } from '../ComponentRegistry';

// getApiClient 모킹
const mockGetToken = vi.fn();
const mockRemoveToken = vi.fn();
vi.mock('../../api/ApiClient', () => ({
  getApiClient: () => ({
    getToken: mockGetToken,
    removeToken: mockRemoveToken,
  }),
}));

describe('LayoutLoader', () => {
  let loader: LayoutLoader;
  let mockRegistry: ComponentRegistry;

  beforeEach(() => {
    // ComponentRegistry 모킹
    mockRegistry = {
      getComponent: vi.fn(),
    } as any;

    loader = new LayoutLoader(mockRegistry);

    // fetch 모킹 초기화
    global.fetch = vi.fn();

    // 기본적으로 토큰 없음 (비회원)
    mockGetToken.mockReturnValue(null);
  });

  describe('loadLayout', () => {
    it('성공적으로 레이아웃 데이터를 로드한다', async () => {
      const mockLayoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'dashboard',
        endpoint: '/admin/dashboard',
        components: [
          {
            type: 'Container',
            props: { className: 'dashboard' },
            children: [],
          },
        ],
      };

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => mockLayoutData,
      });

      const result = await loader.loadLayout('admin-template', 'dashboard');

      expect(result).toEqual(mockLayoutData);
      expect(global.fetch).toHaveBeenCalledWith('/api/layouts/admin-template/dashboard.json', {
        headers: { Accept: 'application/json' },
      });
    });

    it('API 응답 실패 시 LayoutLoaderError를 던진다', async () => {
      (global.fetch as any).mockResolvedValueOnce({
        ok: false,
        status: 404,
        statusText: 'Not Found',
      });

      await expect(loader.loadLayout('admin-template', 'missing')).rejects.toThrow(
        LayoutLoaderError
      );
    });

    it('version 필드가 없으면 검증 에러를 던진다', async () => {
      const invalidData = {
        endpoint: '/admin/dashboard',
        components: [],
      };

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => invalidData,
      });

      await expect(loader.loadLayout('admin-template', 'dashboard')).rejects.toThrow(
        'Layout data missing required field: version'
      );
    });

    it('layout_name 필드가 없으면 검증 에러를 던진다', async () => {
      const invalidData = {
        version: '1.0.0',
        components: [],
      };

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => invalidData,
      });

      await expect(loader.loadLayout('admin-template', 'dashboard')).rejects.toThrow(
        'Layout data missing required field: layout_name'
      );
    });

    it('components가 배열이 아니면 검증 에러를 던진다', async () => {
      const invalidData = {
        version: '1.0.0',
        layout_name: 'dashboard',
        endpoint: '/admin/dashboard',
        components: 'invalid',
      };

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => invalidData,
      });

      await expect(loader.loadLayout('admin-template', 'dashboard')).rejects.toThrow(
        'Layout data field "components" must be an array'
      );
    });

    it('네트워크 에러 시 LayoutLoaderError를 던진다', async () => {
      (global.fetch as any).mockRejectedValueOnce(new Error('Network error'));

      await expect(loader.loadLayout('admin-template', 'dashboard')).rejects.toThrow(
        LayoutLoaderError
      );
      await expect(loader.loadLayout('admin-template', 'dashboard')).rejects.toThrow(
        'Failed to load layout'
      );
    });
  });

  describe('renderLayout', () => {
    let container: HTMLElement;

    beforeEach(() => {
      container = document.createElement('div');
      container.innerHTML = '<p>Existing content</p>';
    });

    it('컨테이너를 초기화하고 레이아웃을 렌더링한다', async () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'dashboard',
        endpoint: '/admin/dashboard',
        components: [
          {
            type: 'Container',
            props: { className: 'dashboard' },
            children: [],
          },
        ],
      };

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => layoutData,
      });

      await loader.loadLayout('admin-template', 'dashboard');

      (mockRegistry.getComponent as any).mockReturnValue(() => null);

      loader.renderLayout(container);

      // 기존 내용이 제거되었는지 확인
      expect(container.querySelector('p')).toBeNull();
      // 새 컴포넌트가 추가되었는지 확인
      expect(container.querySelector('[data-component="Container"]')).not.toBeNull();
    });

    it('레이아웃 데이터가 없을 때 에러 UI를 렌더링한다', () => {
      loader.renderLayout(container);

      expect(container.querySelector('.layout-error')).not.toBeNull();
      expect(container.textContent).toContain('레이아웃 로드 실패');
    });

    it('컴포넌트를 찾을 수 없을 때 플레이스홀더를 렌더링한다', async () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'dashboard',
        endpoint: '/admin/dashboard',
        components: [
          {
            type: 'UnknownComponent',
            children: [],
          },
        ],
      };

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => layoutData,
      });

      await loader.loadLayout('admin-template', 'dashboard');

      (mockRegistry.getComponent as any).mockReturnValue(null);

      loader.renderLayout(container);

      expect(container.querySelector('.component-placeholder')).not.toBeNull();
      expect(container.textContent).toContain('Component not found: UnknownComponent');
    });

    it('자식 컴포넌트를 재귀적으로 렌더링한다', async () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'dashboard',
        endpoint: '/admin/dashboard',
        components: [
          {
            type: 'Container',
            children: [
              {
                type: 'Button',
                props: { label: 'Click me' },
                children: [],
              },
            ],
          },
        ],
      };

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => layoutData,
      });

      await loader.loadLayout('admin-template', 'dashboard');

      (mockRegistry.getComponent as any).mockReturnValue(() => null);

      loader.renderLayout(container);

      expect(container.querySelector('[data-component="Container"]')).not.toBeNull();
      expect(
        container.querySelector('[data-component="Container"] [data-component="Button"]')
      ).not.toBeNull();
    });

    it('텍스트 콘텐츠를 렌더링한다', async () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'dashboard',
        endpoint: '/admin/dashboard',
        components: [
          {
            type: 'Text',
            text: 'Hello World',
            children: [],
          },
        ],
      };

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => layoutData,
      });

      await loader.loadLayout('admin-template', 'dashboard');

      (mockRegistry.getComponent as any).mockReturnValue(() => null);

      loader.renderLayout(container);

      const textElement = container.querySelector('[data-component="Text"]');
      expect(textElement?.textContent).toBe('Hello World');
    });

    it('props를 data 속성으로 설정한다', async () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'dashboard',
        endpoint: '/admin/dashboard',
        components: [
          {
            type: 'Button',
            props: {
              label: 'Submit',
              variant: 'primary',
              disabled: false,
            },
            children: [],
          },
        ],
      };

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => layoutData,
      });

      await loader.loadLayout('admin-template', 'dashboard');

      (mockRegistry.getComponent as any).mockReturnValue(() => null);

      loader.renderLayout(container);

      const button = container.querySelector('[data-component="Button"]');
      expect(button?.getAttribute('data-prop-label')).toBe('"Submit"');
      expect(button?.getAttribute('data-prop-variant')).toBe('"primary"');
      expect(button?.getAttribute('data-prop-disabled')).toBe('false');
    });
  });

  describe('renderErrorState', () => {
    let container: HTMLElement;

    beforeEach(() => {
      container = document.createElement('div');
    });

    it('에러 상태 UI를 렌더링한다', () => {
      const error = new LayoutLoaderError('Test error', 'TEST_ERROR');

      loader.renderLayout(container);

      expect(container.querySelector('.layout-error')).not.toBeNull();
      expect(container.querySelector('[data-error-code="NO_LAYOUT_DATA"]')).not.toBeNull();
    });

    it('에러 메시지를 표시한다', () => {
      loader.renderLayout(container);

      expect(container.textContent).toContain('No layout data to render');
    });

    it('새로고침 버튼을 포함한다', () => {
      loader.renderLayout(container);

      const button = container.querySelector('button');
      expect(button).not.toBeNull();
      expect(button?.textContent).toContain('페이지 새로고침');
      expect(button?.getAttribute('onclick')).toBe('window.location.reload()');
    });
  });

  describe('유틸리티 메서드', () => {
    it('getCurrentLayout - 현재 레이아웃을 반환한다', async () => {
      expect(loader.getCurrentLayout()).toBeNull();

      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'dashboard',
        endpoint: '/admin/dashboard',
        components: [],
      };

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => layoutData,
      });

      await loader.loadLayout('admin-template', 'dashboard');

      expect(loader.getCurrentLayout()).toEqual(layoutData);
    });

    it('clear - 레이아웃 데이터를 초기화한다', async () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'dashboard',
        endpoint: '/admin/dashboard',
        components: [],
      };

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => layoutData,
      });

      await loader.loadLayout('admin-template', 'dashboard');
      expect(loader.getCurrentLayout()).not.toBeNull();

      loader.clear();
      expect(loader.getCurrentLayout()).toBeNull();
    });
  });

  describe('캐시 버전 관리', () => {
    it('초기 캐시 버전은 0이다', () => {
      expect(loader.getCacheVersion()).toBe(0);
    });

    it('setCacheVersion으로 캐시 버전을 설정할 수 있다', () => {
      loader.setCacheVersion(1735000000);
      expect(loader.getCacheVersion()).toBe(1735000000);
    });

    it('캐시 버전이 설정되면 API URL에 쿼리 파라미터가 추가된다', async () => {
      loader.setCacheVersion(1735000000);

      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'dashboard',
        endpoint: '/admin/dashboard',
        components: [],
      };

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => layoutData,
      });

      await loader.loadLayout('admin-template', 'dashboard');

      expect(global.fetch).toHaveBeenCalledWith(
        '/api/layouts/admin-template/dashboard.json?v=1735000000',
        { headers: { Accept: 'application/json' } }
      );
    });

    it('캐시 버전이 0이면 쿼리 파라미터가 추가되지 않는다', async () => {
      loader.setCacheVersion(0);

      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'dashboard',
        endpoint: '/admin/dashboard',
        components: [],
      };

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => layoutData,
      });

      await loader.loadLayout('admin-template', 'dashboard');

      expect(global.fetch).toHaveBeenCalledWith('/api/layouts/admin-template/dashboard.json', {
        headers: { Accept: 'application/json' },
      });
    });

    it('캐시 버전 변경 시 기존 캐시가 클리어된다', async () => {
      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'dashboard',
        endpoint: '/admin/dashboard',
        components: [],
      };

      // 첫 번째 로드
      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => layoutData,
      });
      await loader.loadLayout('admin-template', 'dashboard');

      // 캐시에서 로드 (fetch 호출 없음)
      const cachedResult = await loader.loadLayout('admin-template', 'dashboard');
      expect(cachedResult).toEqual(layoutData);
      expect(global.fetch).toHaveBeenCalledTimes(1);

      // 캐시 버전 변경
      loader.setCacheVersion(1735000000);

      // 새 버전으로 다시 로드 (fetch 호출됨)
      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => layoutData,
      });
      await loader.loadLayout('admin-template', 'dashboard');

      expect(global.fetch).toHaveBeenCalledTimes(2);
      expect(global.fetch).toHaveBeenLastCalledWith(
        '/api/layouts/admin-template/dashboard.json?v=1735000000',
        { headers: { Accept: 'application/json' } }
      );
    });

    it('동일한 캐시 버전으로 설정하면 캐시가 유지된다', async () => {
      loader.setCacheVersion(1735000000);

      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'dashboard',
        endpoint: '/admin/dashboard',
        components: [],
      };

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => layoutData,
      });
      await loader.loadLayout('admin-template', 'dashboard');

      // 동일한 버전으로 다시 설정
      loader.setCacheVersion(1735000000);

      // 캐시에서 로드 (fetch 호출 없음)
      await loader.loadLayout('admin-template', 'dashboard');
      expect(global.fetch).toHaveBeenCalledTimes(1);
    });
  });

  describe('인증 헤더', () => {
    it('토큰이 없으면 Authorization 헤더 없이 요청한다', async () => {
      mockGetToken.mockReturnValue(null);

      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'dashboard',
        endpoint: '/admin/dashboard',
        components: [],
      };

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => layoutData,
      });

      await loader.loadLayout('admin-template', 'dashboard');

      expect(global.fetch).toHaveBeenCalledWith('/api/layouts/admin-template/dashboard.json', {
        headers: { Accept: 'application/json' },
      });
    });

    it('토큰이 있으면 Authorization 헤더를 포함하여 요청한다', async () => {
      mockGetToken.mockReturnValue('test-bearer-token');

      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'admin_dashboard',
        endpoint: '/admin/dashboard',
        components: [],
        permissions: ['core.dashboard.read'],
      };

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => layoutData,
      });

      await loader.loadLayout('sirsoft-admin_basic', 'admin_dashboard');

      expect(global.fetch).toHaveBeenCalledWith(
        '/api/layouts/sirsoft-admin_basic/admin_dashboard.json',
        {
          headers: {
            Accept: 'application/json',
            Authorization: 'Bearer test-bearer-token',
          },
        }
      );
    });

    it('빈 문자열 토큰은 Authorization 헤더에 포함되지 않는다', async () => {
      mockGetToken.mockReturnValue('');

      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'dashboard',
        endpoint: '/admin/dashboard',
        components: [],
      };

      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => layoutData,
      });

      await loader.loadLayout('admin-template', 'dashboard');

      expect(global.fetch).toHaveBeenCalledWith('/api/layouts/admin-template/dashboard.json', {
        headers: { Accept: 'application/json' },
      });
    });
  });

  describe('401 에러 처리', () => {
    beforeEach(() => {
      mockRemoveToken.mockClear();
    });

    it('토큰이 있는 상태에서 401 발생 시 토큰을 삭제하고 토큰 없이 재시도한다', async () => {
      // 첫 번째 호출: 토큰 있음
      // 두 번째 호출: 토큰 없음 (재시도)
      mockGetToken
        .mockReturnValueOnce('invalid-token')
        .mockReturnValueOnce(null);

      const layoutData: LayoutData = {
        version: '1.0.0',
        layout_name: 'admin_login',
        endpoint: '/admin/login',
        components: [],
      };

      // 첫 번째 요청: 401 에러
      (global.fetch as any).mockResolvedValueOnce({
        ok: false,
        status: 401,
        statusText: 'Unauthorized',
        json: async () => ({ message: 'Invalid authentication token.' }),
      });

      // 두 번째 요청 (재시도): 성공
      (global.fetch as any).mockResolvedValueOnce({
        ok: true,
        json: async () => layoutData,
      });

      const result = await loader.loadLayout('sirsoft-admin_basic', 'admin_login');

      // 토큰 삭제 확인
      expect(mockRemoveToken).toHaveBeenCalledTimes(1);

      // 두 번 fetch 호출 확인
      expect(global.fetch).toHaveBeenCalledTimes(2);

      // 첫 번째 호출: 토큰 포함
      expect(global.fetch).toHaveBeenNthCalledWith(
        1,
        '/api/layouts/sirsoft-admin_basic/admin_login.json',
        {
          headers: {
            Accept: 'application/json',
            Authorization: 'Bearer invalid-token',
          },
        }
      );

      // 두 번째 호출: 토큰 없음
      expect(global.fetch).toHaveBeenNthCalledWith(
        2,
        '/api/layouts/sirsoft-admin_basic/admin_login.json',
        {
          headers: { Accept: 'application/json' },
        }
      );

      // 결과 확인
      expect(result).toEqual(layoutData);
    });

    it('토큰이 없는 상태에서 401 발생 시 재시도 없이 에러를 던진다', async () => {
      mockGetToken.mockReturnValue(null);

      (global.fetch as any).mockResolvedValueOnce({
        ok: false,
        status: 401,
        statusText: 'Unauthorized',
        json: async () => ({ message: 'Unauthorized access.' }),
      });

      await expect(loader.loadLayout('sirsoft-admin_basic', 'admin_dashboard')).rejects.toThrow(
        LayoutLoaderError
      );

      // 토큰 삭제 호출 안됨
      expect(mockRemoveToken).not.toHaveBeenCalled();

      // fetch는 1회만 호출
      expect(global.fetch).toHaveBeenCalledTimes(1);
    });

    it('재시도 후에도 401이면 에러를 던진다', async () => {
      // 첫 번째 호출: 토큰 있음
      // 두 번째 호출: 토큰 없음 (재시도)
      mockGetToken
        .mockReturnValueOnce('invalid-token')
        .mockReturnValueOnce(null);

      // 첫 번째 요청: 401 에러
      (global.fetch as any).mockResolvedValueOnce({
        ok: false,
        status: 401,
        statusText: 'Unauthorized',
        json: async () => ({ message: 'Invalid token.' }),
      });

      // 두 번째 요청 (재시도): 여전히 401 (권한 필요한 레이아웃)
      (global.fetch as any).mockResolvedValueOnce({
        ok: false,
        status: 401,
        statusText: 'Unauthorized',
        json: async () => ({ message: 'Authentication required.' }),
      });

      await expect(loader.loadLayout('sirsoft-admin_basic', 'admin_dashboard')).rejects.toThrow(
        LayoutLoaderError
      );

      // 토큰 삭제 1회 호출
      expect(mockRemoveToken).toHaveBeenCalledTimes(1);

      // fetch는 2회 호출
      expect(global.fetch).toHaveBeenCalledTimes(2);
    });
  });
});
