import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest';
import { Router } from '../Router';
import { RouteResolver } from '../RouteResolver';
import { Logger } from '../../utils/Logger';

// fetch 모킹
global.fetch = vi.fn();

describe('RouteResolver', () => {
  let router: Router;
  let resolver: RouteResolver;
  let mockCallback: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    router = new Router('sirsoft-admin_basic');
    resolver = new RouteResolver(router);
    mockCallback = vi.fn();
    vi.clearAllMocks();
    // Logger 디버그 모드 활성화
    Logger.getInstance().setDebug(true);

    // 기본 라우트 목록 모킹
    // API 응답 형태: { success: true, data: { version: "1.0.0", routes: [...] } }
    (global.fetch as any).mockResolvedValue({
      ok: true,
      json: async () => ({
        success: true,
        data: {
          version: '1.0.0',
          routes: [
            { path: '/admin', layout: 'dashboard' },
            { path: '/admin/users/:id', layout: 'user-detail' },
          ],
        },
      }),
    });
  });

  afterEach(() => {
    resolver.destroy();
    // Logger 디버그 모드 비활성화
    Logger.getInstance().setDebug(false);
  });

  describe('init', () => {
    it('router.loadRoutes()를 호출해야 합니다', async () => {
      const loadRoutesSpy = vi.spyOn(router, 'loadRoutes');

      await resolver.init(mockCallback);

      expect(loadRoutesSpy).toHaveBeenCalledOnce();
    });

    it('popstate 이벤트 리스너를 등록해야 합니다', async () => {
      const addEventListenerSpy = vi.spyOn(window, 'addEventListener');

      await resolver.init(mockCallback);

      expect(addEventListenerSpy).toHaveBeenCalledWith('popstate', expect.any(Function));
    });

    it('초기 라우트를 해석하고 콜백을 호출해야 합니다', async () => {
      // 현재 경로를 /admin으로 설정
      Object.defineProperty(window, 'location', {
        value: { pathname: '/admin' },
        writable: true,
      });

      await resolver.init(mockCallback);

      expect(mockCallback).toHaveBeenCalledOnce();
      expect(mockCallback).toHaveBeenCalledWith(
        expect.objectContaining({
          route: expect.objectContaining({ layout: 'dashboard' }),
          params: {},
        })
      );
    });

    it('이미 초기화된 경우 경고를 출력하고 재초기화하지 않아야 합니다', async () => {
      const consoleWarnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});

      await resolver.init(mockCallback);
      await resolver.init(mockCallback);

      expect(consoleWarnSpy).toHaveBeenCalledWith('[RouteResolver]', 'RouteResolver is already initialized');
      expect(mockCallback).toHaveBeenCalledOnce(); // 첫 번째 init만 콜백 호출
    });
  });

  describe('resolve', () => {
    beforeEach(async () => {
      await resolver.init(mockCallback);
      mockCallback.mockClear(); // init에서 호출된 것 제외
    });

    it('현재 경로와 매칭되는 라우트를 찾아 콜백을 호출해야 합니다', () => {
      Object.defineProperty(window, 'location', {
        value: { pathname: '/admin/users/123' },
        writable: true,
      });

      resolver.resolve();

      expect(mockCallback).toHaveBeenCalledOnce();
      expect(mockCallback).toHaveBeenCalledWith(
        expect.objectContaining({
          route: expect.objectContaining({ layout: 'user-detail' }),
          params: { id: '123' },
        })
      );
    });

    it('매칭되지 않는 경로는 null로 콜백을 호출해야 합니다', () => {
      Object.defineProperty(window, 'location', {
        value: { pathname: '/unknown' },
        writable: true,
      });

      resolver.resolve();

      expect(mockCallback).toHaveBeenCalledOnce();
      expect(mockCallback).toHaveBeenCalledWith(null);
    });
  });

  describe('navigate', () => {
    beforeEach(async () => {
      await resolver.init(mockCallback);
      mockCallback.mockClear();
    });

    it('window.history.pushState()를 호출해야 합니다', () => {
      const pushStateSpy = vi.spyOn(window.history, 'pushState');

      resolver.navigate('/admin/users/456');

      expect(pushStateSpy).toHaveBeenCalledOnce();
      expect(pushStateSpy).toHaveBeenCalledWith(null, '', '/admin/users/456');
    });

    it('state를 전달하면 pushState에 포함되어야 합니다', () => {
      const pushStateSpy = vi.spyOn(window.history, 'pushState');
      const state = { fromSearch: true };

      resolver.navigate('/admin/users/456', state);

      expect(pushStateSpy).toHaveBeenCalledWith(state, '', '/admin/users/456');
    });

    it('navigate 후 resolve()를 호출하여 콜백이 실행되어야 합니다', () => {
      Object.defineProperty(window, 'location', {
        value: { pathname: '/admin/users/456' },
        writable: true,
      });

      resolver.navigate('/admin/users/456');

      expect(mockCallback).toHaveBeenCalledOnce();
      expect(mockCallback).toHaveBeenCalledWith(
        expect.objectContaining({
          route: expect.objectContaining({ layout: 'user-detail' }),
          params: { id: '456' },
        })
      );
    });
  });

  describe('popstate 이벤트 처리', () => {
    beforeEach(async () => {
      await resolver.init(mockCallback);
      mockCallback.mockClear();
    });

    it('popstate 이벤트 발생 시 resolve()가 호출되어야 합니다', () => {
      Object.defineProperty(window, 'location', {
        value: { pathname: '/admin' },
        writable: true,
      });

      window.dispatchEvent(new PopStateEvent('popstate'));

      expect(mockCallback).toHaveBeenCalledOnce();
      expect(mockCallback).toHaveBeenCalledWith(
        expect.objectContaining({
          route: expect.objectContaining({ layout: 'dashboard' }),
          params: {},
        })
      );
    });
  });

  describe('destroy', () => {
    it('popstate 이벤트 리스너를 제거해야 합니다', async () => {
      const removeEventListenerSpy = vi.spyOn(window, 'removeEventListener');

      await resolver.init(mockCallback);
      resolver.destroy();

      expect(removeEventListenerSpy).toHaveBeenCalledWith('popstate', expect.any(Function));
    });

    it('destroy 후 popstate 이벤트가 발생해도 콜백이 호출되지 않아야 합니다', async () => {
      await resolver.init(mockCallback);
      mockCallback.mockClear();

      resolver.destroy();

      window.dispatchEvent(new PopStateEvent('popstate'));

      expect(mockCallback).not.toHaveBeenCalled();
    });

    it('destroy 후 isInitialized가 false로 설정되어야 합니다', async () => {
      await resolver.init(mockCallback);
      resolver.destroy();

      // 다시 init 호출 시 경고 없이 초기화되어야 함
      const consoleWarnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
      await resolver.init(mockCallback);

      expect(consoleWarnSpy).not.toHaveBeenCalled();
    });
  });
});
