/**
 * ActionDispatcher 프리뷰 모드 테스트
 *
 * 프리뷰 모드에서 PREVIEW_SUPPRESSED_HANDLERS에 정의된 핸들러가
 * 억제되고, 나머지 핸들러는 정상 동작하는지 검증
 *
 * @since engine-v1.26.1
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

describe('ActionDispatcher - previewMode', () => {
  let dispatcher: ActionDispatcher;
  let mockNavigate: ReturnType<typeof vi.fn>;
  let mockFetch: ReturnType<typeof vi.fn>;
  let originalFetch: typeof fetch;

  beforeEach(() => {
    mockNavigate = vi.fn();
    mockGetToken.mockReset();
    mockLogin.mockReset();
    mockLogin.mockResolvedValue({ id: 1, name: 'Test User' });
    mockLogout.mockReset();
    mockLogout.mockResolvedValue(undefined);
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
    globalThis.fetch = originalFetch;
  });

  describe('setPreviewMode / isPreviewMode', () => {
    it('기본값은 false', () => {
      expect(dispatcher.isPreviewMode()).toBe(false);
    });

    it('setPreviewMode(true)로 활성화', () => {
      dispatcher.setPreviewMode(true);
      expect(dispatcher.isPreviewMode()).toBe(true);
    });

    it('setPreviewMode(false)로 비활성화', () => {
      dispatcher.setPreviewMode(true);
      dispatcher.setPreviewMode(false);
      expect(dispatcher.isPreviewMode()).toBe(false);
    });
  });

  describe('getPreviewSuppressedHandlers / getPreviewSuppressedLayoutFeatures', () => {
    it('억제 핸들러 목록에 navigate, navigateBack, navigateForward, replaceUrl, refresh, logout 포함', () => {
      const handlers = ActionDispatcher.getPreviewSuppressedHandlers();
      expect(handlers.has('navigate')).toBe(true);
      expect(handlers.has('navigateBack')).toBe(true);
      expect(handlers.has('navigateForward')).toBe(true);
      expect(handlers.has('replaceUrl')).toBe(true);
      expect(handlers.has('refresh')).toBe(true);
      expect(handlers.has('logout')).toBe(true);
    });

    it('억제하지 않는 핸들러: openWindow, apiCall, setState, toast, openModal', () => {
      const handlers = ActionDispatcher.getPreviewSuppressedHandlers();
      expect(handlers.has('openWindow')).toBe(false);
      expect(handlers.has('apiCall')).toBe(false);
      expect(handlers.has('setState')).toBe(false);
      expect(handlers.has('toast')).toBe(false);
      expect(handlers.has('openModal')).toBe(false);
    });

    it('억제 레이아웃 기능 목록에 redirect 포함', () => {
      const features = ActionDispatcher.getPreviewSuppressedLayoutFeatures();
      expect(features.has('redirect')).toBe(true);
    });
  });

  describe('프리뷰 모드에서 핸들러 억제', () => {
    const mockContext = {
      data: {},
      state: {},
      setState: vi.fn(),
    };

    it('navigate 핸들러가 프리뷰 모드에서 억제됨', async () => {
      dispatcher.setPreviewMode(true);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'navigate',
        target: '/admin/dashboard',
      };

      await dispatcher.executeAction(action, mockContext);

      // navigate 콜백이 호출되지 않아야 함
      expect(mockNavigate).not.toHaveBeenCalled();
    });

    it('navigateBack 핸들러가 프리뷰 모드에서 억제됨', async () => {
      const historyBackSpy = vi.spyOn(window.history, 'back').mockImplementation(() => {});
      dispatcher.setPreviewMode(true);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'navigateBack',
      };

      await dispatcher.executeAction(action, mockContext);
      expect(historyBackSpy).not.toHaveBeenCalled();

      historyBackSpy.mockRestore();
    });

    it('navigateForward 핸들러가 프리뷰 모드에서 억제됨', async () => {
      const historyForwardSpy = vi.spyOn(window.history, 'forward').mockImplementation(() => {});
      dispatcher.setPreviewMode(true);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'navigateForward',
      };

      await dispatcher.executeAction(action, mockContext);
      expect(historyForwardSpy).not.toHaveBeenCalled();

      historyForwardSpy.mockRestore();
    });

    it('replaceUrl 핸들러가 프리뷰 모드에서 억제됨', async () => {
      const replaceStateSpy = vi.spyOn(window.history, 'replaceState').mockImplementation(() => {});
      dispatcher.setPreviewMode(true);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'replaceUrl',
        target: '/admin/new-path',
      };

      await dispatcher.executeAction(action, mockContext);
      expect(replaceStateSpy).not.toHaveBeenCalled();

      replaceStateSpy.mockRestore();
    });

    it('logout 핸들러가 프리뷰 모드에서 억제됨', async () => {
      dispatcher.setPreviewMode(true);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'logout',
        target: '/api/admin/auth/logout',
      };

      await dispatcher.executeAction(action, mockContext);
      expect(mockLogout).not.toHaveBeenCalled();
    });
  });

  describe('프리뷰 모드에서 억제하지 않는 핸들러', () => {
    const mockContext = {
      data: {},
      state: {},
      setState: vi.fn(),
    };

    it('apiCall 핸들러는 프리뷰 모드에서도 정상 동작', async () => {
      dispatcher.setPreviewMode(true);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/test',
        params: { method: 'GET' },
      };

      await dispatcher.executeAction(action, mockContext);
      expect(mockFetch).toHaveBeenCalled();
    });

    it('setState 핸들러는 프리뷰 모드에서도 정상 동작', async () => {
      dispatcher.setPreviewMode(true);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'setState',
        params: { key: 'testKey', value: 'testValue' },
      };

      await dispatcher.executeAction(action, mockContext);
      // setState는 컨텍스트에 의존하므로 에러 없이 실행되면 성공
    });

    it('toast 핸들러는 프리뷰 모드에서도 정상 동작', async () => {
      // toast는 globalStateUpdater가 필요하므로 주입
      const mockGlobalStateUpdater = vi.fn();
      dispatcher.setGlobalStateUpdater(mockGlobalStateUpdater);
      dispatcher.setPreviewMode(true);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'toast',
        params: { type: 'info', message: 'Test toast' },
      };

      await dispatcher.executeAction(action, mockContext);
      // toast가 실행되면 globalStateUpdater가 호출됨
      expect(mockGlobalStateUpdater).toHaveBeenCalled();
    });
  });

  describe('프리뷰 모드 비활성 시 정상 동작', () => {
    it('프리뷰 모드 비활성 시 navigate 정상 실행', async () => {
      const contextNavigate = vi.fn();
      const mockContext = {
        data: {},
        state: {},
        setState: vi.fn(),
        navigate: contextNavigate,
      };

      // previewMode = false (기본값)
      const action: ActionDefinition = {
        type: 'click',
        handler: 'navigate',
        target: '/admin/dashboard',
      };

      await dispatcher.executeAction(action, mockContext);
      expect(contextNavigate).toHaveBeenCalledWith('/admin/dashboard', { replace: false });
    });

    it('setPreviewMode(false) 후 navigate 정상 실행', async () => {
      const contextNavigate = vi.fn();
      const mockContext = {
        data: {},
        state: {},
        setState: vi.fn(),
        navigate: contextNavigate,
      };

      dispatcher.setPreviewMode(true);
      dispatcher.setPreviewMode(false);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'navigate',
        target: '/admin/dashboard',
      };

      await dispatcher.executeAction(action, mockContext);
      expect(contextNavigate).toHaveBeenCalledWith('/admin/dashboard', { replace: false });
    });
  });
});
