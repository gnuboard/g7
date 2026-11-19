/**
 * ActionDispatcher 프리뷰 모드 테스트
 *
 * 프리뷰 모드에서 PREVIEW_SUPPRESSED_HANDLERS에 정의된 핸들러가
 * 억제되고, 나머지 핸들러는 정상 동작하는지 검증
 *
 * @since engine-v1.26.1
 */

import { describe, it, expect, beforeEach, afterEach, vi, type Mock } from 'vitest';
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
  let mockNavigate: Mock;
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
    /**
     * @scenario case=default_off
     *
     * @effects preview_mode_off_by_default
     */
    it('기본값은 false', () => {
      expect(dispatcher.isPreviewMode()).toBe(false);
    });

    /**
     * @scenario case=toggle_on
     *
     * @effects preview_mode_toggles
     */
    it('setPreviewMode(true)로 활성화', () => {
      dispatcher.setPreviewMode(true);
      expect(dispatcher.isPreviewMode()).toBe(true);
    });

    /**
     * @scenario case=toggle_off
     *
     * @effects preview_mode_toggles
     */
    it('setPreviewMode(false)로 비활성화', () => {
      dispatcher.setPreviewMode(true);
      dispatcher.setPreviewMode(false);
      expect(dispatcher.isPreviewMode()).toBe(false);
    });
  });

  describe('getPreviewSuppressedHandlers / getPreviewSuppressedLayoutFeatures', () => {
    /**
     * @scenario case=suppressed_list
     *
     * @effects suppressed_handler_list_published
     */
    it('억제 핸들러 목록에 navigate, navigateBack, navigateForward, replaceUrl, refresh, logout 포함', () => {
      const handlers = ActionDispatcher.getPreviewSuppressedHandlers();
      expect(handlers.has('navigate')).toBe(true);
      expect(handlers.has('navigateBack')).toBe(true);
      expect(handlers.has('navigateForward')).toBe(true);
      expect(handlers.has('replaceUrl')).toBe(true);
      expect(handlers.has('refresh')).toBe(true);
      expect(handlers.has('logout')).toBe(true);
    });

    /**
     * @scenario case=non_suppressed_list
     *
     * @effects suppressed_handler_list_published
     */
    it('억제하지 않는 핸들러: openWindow, apiCall, setState, toast, openModal', () => {
      const handlers = ActionDispatcher.getPreviewSuppressedHandlers();
      expect(handlers.has('openWindow')).toBe(false);
      expect(handlers.has('apiCall')).toBe(false);
      expect(handlers.has('setState')).toBe(false);
      expect(handlers.has('toast')).toBe(false);
      expect(handlers.has('openModal')).toBe(false);
    });

    /**
     * @scenario case=suppressed_layout_features
     *
     * @effects suppressed_layout_feature_list_includes_redirect
     */
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

    /**
     * @scenario case=suppress_navigate
     *
     * @effects suppressed_handler_skipped_in_preview
     */
    it('navigate 핸들러가 프리뷰 모드에서 억제됨', async () => {
      dispatcher.setPreviewMode(true);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'navigate',
        target: '/admin/dashboard',
      };

      await dispatcher['executeAction'](action, mockContext);

      // navigate 콜백이 호출되지 않아야 함
      expect(mockNavigate).not.toHaveBeenCalled();
    });

    /**
     * @scenario case=suppress_navigate_back
     *
     * @effects suppressed_handler_skipped_in_preview
     */
    it('navigateBack 핸들러가 프리뷰 모드에서 억제됨', async () => {
      const historyBackSpy = vi.spyOn(window.history, 'back').mockImplementation(() => {});
      dispatcher.setPreviewMode(true);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'navigateBack',
      };

      await dispatcher['executeAction'](action, mockContext);
      expect(historyBackSpy).not.toHaveBeenCalled();

      historyBackSpy.mockRestore();
    });

    /**
     * @scenario case=suppress_navigate_forward
     *
     * @effects suppressed_handler_skipped_in_preview
     */
    it('navigateForward 핸들러가 프리뷰 모드에서 억제됨', async () => {
      const historyForwardSpy = vi.spyOn(window.history, 'forward').mockImplementation(() => {});
      dispatcher.setPreviewMode(true);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'navigateForward',
      };

      await dispatcher['executeAction'](action, mockContext);
      expect(historyForwardSpy).not.toHaveBeenCalled();

      historyForwardSpy.mockRestore();
    });

    /**
     * @scenario case=suppress_replace_url
     *
     * @effects suppressed_handler_skipped_in_preview
     */
    it('replaceUrl 핸들러가 프리뷰 모드에서 억제됨', async () => {
      const replaceStateSpy = vi.spyOn(window.history, 'replaceState').mockImplementation(() => {});
      dispatcher.setPreviewMode(true);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'replaceUrl',
        target: '/admin/new-path',
      };

      await dispatcher['executeAction'](action, mockContext);
      expect(replaceStateSpy).not.toHaveBeenCalled();

      replaceStateSpy.mockRestore();
    });

    /**
     * @scenario case=suppress_logout
     *
     * @effects suppressed_handler_skipped_in_preview
     */
    it('logout 핸들러가 프리뷰 모드에서 억제됨', async () => {
      dispatcher.setPreviewMode(true);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'logout',
        target: '/api/admin/auth/logout',
      };

      await dispatcher['executeAction'](action, mockContext);
      expect(mockLogout).not.toHaveBeenCalled();
    });
  });

  describe('프리뷰 모드에서 억제하지 않는 핸들러', () => {
    const mockContext = {
      data: {},
      state: {},
      setState: vi.fn(),
    };

    /**
     * @scenario case=allow_api_call
     *
     * @effects non_suppressed_handler_runs_in_preview
     */
    it('apiCall 핸들러는 프리뷰 모드에서도 정상 동작', async () => {
      dispatcher.setPreviewMode(true);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'apiCall',
        target: '/api/test',
        params: { method: 'GET' },
      };

      await dispatcher['executeAction'](action, mockContext);
      expect(mockFetch).toHaveBeenCalled();
    });

    /**
     * @scenario case=allow_set_state
     *
     * @effects non_suppressed_handler_runs_in_preview
     */
    it('setState 핸들러는 프리뷰 모드에서도 정상 동작', async () => {
      dispatcher.setPreviewMode(true);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'setState',
        params: { key: 'testKey', value: 'testValue' },
      };

      await dispatcher['executeAction'](action, mockContext);
      // setState는 컨텍스트에 의존하므로 에러 없이 실행되면 성공
    });

    /**
     * @scenario case=allow_toast
     *
     * @effects non_suppressed_handler_runs_in_preview
     */
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

      await dispatcher['executeAction'](action, mockContext);
      // toast가 실행되면 globalStateUpdater가 호출됨
      expect(mockGlobalStateUpdater).toHaveBeenCalled();
    });
  });

  describe('프리뷰 모드 비활성 시 정상 동작', () => {
    /**
     * @scenario case=off_navigate_runs
     *
     * @effects navigate_runs_when_preview_off
     */
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

      await dispatcher['executeAction'](action, mockContext);
      expect(contextNavigate).toHaveBeenCalledWith('/admin/dashboard', { replace: false });
    });

    /**
     * @scenario case=off_after_on_navigate_runs
     *
     * @effects navigate_runs_when_preview_off
     */
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

      await dispatcher['executeAction'](action, mockContext);
      expect(contextNavigate).toHaveBeenCalledWith('/admin/dashboard', { replace: false });
    });
  });

  // ============================================================================
  // S4'' 보완 — handleCustomAction silent skip
  //
  // PreviewCanvas 의 격리 ActionDispatcher 인스턴스는 호스트의 customHandlers
  // (예: sirsoft-ckeditor5.initEditor) 를 공유하지 않으므로, lifecycle onMount
  // 단계에서 외부 플러그인 핸들러가 호출되면 throw → errorHandling.onError →
  // setState(500) → ErrorBoundary 발화 → 캔버스 unmount 회귀.
  //
  // 본 보완: previewMode 일 때 미등록 핸들러는 throw 대신 warn 후 undefined 반환.
  // ============================================================================
  describe("S4'' — 미등록 커스텀 핸들러 silent skip (previewMode)", () => {
    const mockContext = {
      data: {},
      state: {},
      setState: vi.fn(),
    };

    /**
     * @scenario case=unknown_handler_no_throw
     *
     * @effects unknown_custom_handler_silently_skipped_in_preview
     */
    it('previewMode=true 일 때 미등록 커스텀 핸들러 호출 시 throw 하지 않음', async () => {
      dispatcher.setPreviewMode(true);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'sirsoft-ckeditor5.initEditor',
      };

      // 본 보완 전: ActionError("Unknown action handler: sirsoft-ckeditor5.initEditor") throw
      // 본 보완 후: silent skip — promise 가 reject 되지 않음
      await expect(dispatcher['executeAction'](action, mockContext)).resolves.toBeDefined();
    });

    /**
     * @scenario case=unknown_handler_success
     *
     * @effects unknown_custom_handler_silently_skipped_in_preview
     */
    it('previewMode=true 일 때 미등록 핸들러 호출 시 success=true 반환 (errorHandling 미발화)', async () => {
      dispatcher.setPreviewMode(true);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'someUnknownHandler',
      };

      const result = await dispatcher['executeAction'](action, mockContext);
      // executeAction 의 반환 형식 — { success: boolean, data?: any, error?: any }
      // silent skip 시 result.success 는 truthy 여야 한다 (errorHandling 분기 미발화)
      expect(result?.success).toBeTruthy();
    });

    /**
     * @scenario case=unknown_handler_warn_once
     *
     * @effects unknown_custom_handler_skip_warns_once
     */
    it('previewMode=true silent skip 시 콘솔 warn 1회 호출 (로그 디그레이드)', async () => {
      dispatcher.setPreviewMode(true);
      // Logger 의 warn 을 spy — 본 보완 코드의 logger.warn 호출 검증
      const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      const action: ActionDefinition = {
        type: 'click',
        handler: 'sirsoft-ckeditor5.initEditor',
      };

      // debug 모드 활성화 — Logger.warn 이 console.warn 으로 흐르도록
      Logger.getInstance().setDebug(true);
      await dispatcher['executeAction'](action, mockContext);
      Logger.getInstance().setDebug(false);

      // warn 호출 메시지에 "Unknown action handler" 또는 "Preview" 포함 확인
      const warnCalls = warnSpy.mock.calls.flat().map(String).join(' ');
      expect(warnCalls).toMatch(/sirsoft-ckeditor5\.initEditor|Preview|Unknown/i);

      warnSpy.mockRestore();
    });

    /**
     * @scenario case=unknown_handler_throws_when_off
     *
     * @effects unknown_custom_handler_throws_when_preview_off
     */
    it('previewMode=false (일반 라우트) 일 때 미등록 핸들러는 여전히 throw 발생 — 일반 동작 보존', async () => {
      // previewMode 미설정 (기본값 false)
      const action: ActionDefinition = {
        type: 'click',
        handler: 'sirsoft-ckeditor5.initEditor',
      };

      // 본 보완은 previewMode=true 분기에만 영향 — false 일 때 기존 throw 동작 그대로
      // executeAction 가 try/catch 로 감싸 result.error 로 반환할 수 있으므로 양쪽 케이스 허용
      const result = await dispatcher['executeAction'](action, mockContext).catch((e) => ({
        success: false,
        error: e,
      }));
      expect(result?.success).toBeFalsy();
    });

    /**
     * @scenario case=registered_custom_runs
     *
     * @effects registered_custom_handler_runs_in_preview
     */
    it('previewMode=true 일 때 등록된 커스텀 핸들러는 정상 실행 — silent skip 분기가 등록 핸들러를 가리지 않음', async () => {
      dispatcher.setPreviewMode(true);

      const handlerSpy = vi.fn().mockResolvedValue({ ok: true });
      dispatcher.registerHandler('myCustom', handlerSpy);

      const action: ActionDefinition = {
        type: 'click',
        handler: 'myCustom',
        params: { x: 1 },
      };

      await dispatcher['executeAction'](action, mockContext);
      expect(handlerSpy).toHaveBeenCalled();
    });
  });
});
