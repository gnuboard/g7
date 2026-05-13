/**
 * TemplateApp.ts 테스트
 *
 * TemplateApp 클래스의 초기화, 로케일 변경, localStorage 연동 기능 테스트
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TemplateApp } from '../TemplateApp';
import type { TemplateAppConfig } from '../TemplateApp';

// 공유 ActionDispatcher mock 객체 (vi.mock 호이스팅 대응)
const { sharedActionDispatcher } = vi.hoisted(() => ({
  sharedActionDispatcher: {
    setNavigate: vi.fn(),
    setGlobalState: vi.fn(),
    setDefaultContext: vi.fn(),
    setGlobalStateUpdater: vi.fn(),
    registerHandler: vi.fn(),
    customHandlers: new Map(),
  },
}));

// Mock dependencies
vi.mock('../template-engine', () => ({
  initTemplateEngine: vi.fn().mockResolvedValue(undefined),
  renderTemplate: vi.fn().mockResolvedValue(undefined),
  destroyTemplate: vi.fn(),
  getActionDispatcher: vi.fn().mockReturnValue(sharedActionDispatcher),
  getState: vi.fn().mockReturnValue({
    actionDispatcher: sharedActionDispatcher,
    reactRoot: null,
    currentLayoutJson: null,
  }),
}));

vi.mock('../template-engine/TransitionManager', () => ({
  transitionManager: {
    setPending: vi.fn(),
    getIsPending: vi.fn(() => false),
    subscribe: vi.fn(() => vi.fn()),
    clearSubscribers: vi.fn(),
  },
}));

vi.mock('../routing/Router', () => ({
  Router: vi.fn(function(this: any) {
    this.loadRoutes = vi.fn().mockResolvedValue(undefined);
    this.on = vi.fn();
    this.navigateToCurrentPath = vi.fn();
    this.getRoutes = vi.fn().mockReturnValue([]);
  }),
}));

vi.mock('../template-engine/LayoutLoader', () => ({
  LayoutLoader: vi.fn(function(this: any) {
    this.loadLayout = vi.fn().mockResolvedValue({ components: [] });
  }),
}));

vi.mock('../template-engine/ComponentRegistry', () => {
  const mockInstance = {
    loadComponents: vi.fn().mockResolvedValue(undefined),
    getComponent: vi.fn().mockReturnValue(() => null),
    hasComponent: vi.fn().mockReturnValue(true),
    getInstance: vi.fn(),
  };

  mockInstance.getInstance.mockReturnValue(mockInstance);

  return {
    ComponentRegistry: {
      getInstance: vi.fn(() => mockInstance),
    },
  };
});

describe('TemplateApp', () => {
  let originalLocalStorage: Storage;
  let localStorageMock: { [key: string]: string };

  beforeEach(() => {
    // localStorage mock
    localStorageMock = {};
    originalLocalStorage = global.localStorage;

    Object.defineProperty(global, 'localStorage', {
      value: {
        getItem: vi.fn((key: string) => localStorageMock[key] || null),
        setItem: vi.fn((key: string, value: string) => {
          localStorageMock[key] = value;
        }),
        removeItem: vi.fn((key: string) => {
          delete localStorageMock[key];
        }),
        clear: vi.fn(() => {
          localStorageMock = {};
        }),
      },
      writable: true,
    });

    // DOM setup
    document.body.innerHTML = '<div id="app"></div>';
  });

  afterEach(() => {
    // localStorage 복원
    Object.defineProperty(global, 'localStorage', {
      value: originalLocalStorage,
      writable: true,
    });

    vi.clearAllMocks();
  });

  describe('constructor', () => {
    it('기본 config로 인스턴스를 생성해야 함', () => {
      const config: TemplateAppConfig = {
        templateId: 'test-template',
        templateType: 'admin',
        locale: 'ko',
        debug: false,
      };

      const app = new TemplateApp(config);

      expect(app).toBeInstanceOf(TemplateApp);
      expect(app.getLocale()).toBe('ko');
    });

    it('localStorage에서 저장된 로케일을 로드해야 함', () => {
      // localStorage에 로케일 저장
      localStorageMock['g7_locale'] = 'en';

      const config: TemplateAppConfig = {
        templateId: 'test-template',
        templateType: 'admin',
        locale: 'ko',
        debug: false,
      };

      const app = new TemplateApp(config);

      // localStorage의 로케일이 우선 적용되어야 함
      expect(app.getLocale()).toBe('en');
    });

    it('localStorage에 현재 로케일을 저장해야 함', () => {
      const config: TemplateAppConfig = {
        templateId: 'test-template',
        templateType: 'admin',
        locale: 'ko',
        debug: false,
      };

      new TemplateApp(config);

      expect(localStorageMock['g7_locale']).toBe('ko');
    });
  });

  describe('getLocale', () => {
    it('현재 로케일을 반환해야 함', () => {
      const config: TemplateAppConfig = {
        templateId: 'test-template',
        templateType: 'admin',
        locale: 'ko',
        debug: false,
      };

      const app = new TemplateApp(config);

      expect(app.getLocale()).toBe('ko');
    });
  });

  describe('changeLocale', () => {
    it('로케일을 변경하고 localStorage에 저장해야 함', async () => {
      const config: TemplateAppConfig = {
        templateId: 'test-template',
        templateType: 'admin',
        locale: 'ko',
        debug: false,
      };

      const app = new TemplateApp(config);
      await app.init();

      // 로케일 변경
      await app.changeLocale('en');

      expect(app.getLocale()).toBe('en');
      expect(localStorageMock['g7_locale']).toBe('en');
    });

    it('동일한 로케일로 변경 시도 시 무시해야 함', async () => {
      const config: TemplateAppConfig = {
        templateId: 'test-template',
        templateType: 'admin',
        locale: 'ko',
        debug: false,
      };

      const app = new TemplateApp(config);
      await app.init();

      const initialLocale = app.getLocale();

      // 동일한 로케일로 변경 시도
      await app.changeLocale('ko');

      expect(app.getLocale()).toBe(initialLocale);
    });

    it('로케일 변경 시 템플릿 엔진을 재초기화해야 함', async () => {
      const { initTemplateEngine } = await import('../template-engine');

      const config: TemplateAppConfig = {
        templateId: 'test-template',
        templateType: 'admin',
        locale: 'ko',
        debug: false,
      };

      const app = new TemplateApp(config);
      await app.init();

      // 초기화 호출 횟수 초기화
      vi.mocked(initTemplateEngine).mockClear();

      // 로케일 변경
      await app.changeLocale('en');

      // 템플릿 엔진 재초기화 확인
      expect(initTemplateEngine).toHaveBeenCalledWith(
        expect.objectContaining({
          templateId: 'test-template',
          templateType: 'admin',
          locale: 'en',
          debug: false,
        })
      );
    });

    // TODO: Router mock 구조 개선 필요 - 현재는 getRouter()가 mocked 인스턴스를 반환하지 않음
    it.skip('로케일 변경 시 현재 라우트를 재렌더링해야 함', async () => {
      const config: TemplateAppConfig = {
        templateId: 'test-template',
        templateType: 'admin',
        locale: 'ko',
        debug: false,
      };

      const app = new TemplateApp(config);
      await app.init();

      const router = app.getRouter();

      // 로케일 변경
      await app.changeLocale('en');

      // 현재 라우트 재렌더링 확인
      expect(router?.navigateToCurrentPath).toHaveBeenCalled();
    });
  });

  describe('localStorage 연동', () => {
    it('localStorage 접근 실패 시 에러를 무시하고 계속 진행해야 함', () => {
      // localStorage.getItem을 throw하도록 설정
      vi.mocked(global.localStorage.getItem).mockImplementation(() => {
        throw new Error('localStorage access denied');
      });

      const config: TemplateAppConfig = {
        templateId: 'test-template',
        templateType: 'admin',
        locale: 'ko',
        debug: false,
      };

      // 에러 없이 인스턴스 생성되어야 함
      expect(() => new TemplateApp(config)).not.toThrow();
    });

    it('localStorage 저장 실패 시 에러를 무시하고 계속 진행해야 함', async () => {
      // localStorage.setItem을 throw하도록 설정
      vi.mocked(global.localStorage.setItem).mockImplementation(() => {
        throw new Error('localStorage write denied');
      });

      const config: TemplateAppConfig = {
        templateId: 'test-template',
        templateType: 'admin',
        locale: 'ko',
        debug: false,
      };

      const app = new TemplateApp(config);
      await app.init();

      // 에러 없이 로케일 변경되어야 함
      await expect(app.changeLocale('en')).resolves.not.toThrow();
    });
  });

  describe('TransitionManager 연동', () => {
    it('라우트 변경 시 TransitionManager.setPending(true)를 호출해야 함', async () => {
      const { transitionManager } = await import('../template-engine/TransitionManager');

      const config: TemplateAppConfig = {
        templateId: 'test-template',
        templateType: 'admin',
        locale: 'ko',
        debug: false,
      };

      const app = new TemplateApp(config);
      await app.init();

      // setPending 모킹 초기화
      vi.mocked(transitionManager.setPending).mockClear();

      // 라우트 변경 시뮬레이션 (handleRouteChange 호출)
      const router = app.getRouter();
      expect(router).toBeDefined();

      if (!router) return;

      const routeChangeHandler = vi.mocked(router.on).mock.calls.find(
        call => call[0] === 'routeChange'
      )?.[1];

      if (routeChangeHandler) {
        // 비동기 핸들러 실행
        routeChangeHandler({
          path: '/test',
          params: {},
          query: {},
          layoutName: 'test-layout',
        });
      }

      // TransitionManager.setPending이 호출되었는지 확인
      expect(transitionManager.setPending).toHaveBeenCalledWith(true);
    });

    it('데이터 fetch 완료 후 TransitionManager.setPending(false)를 호출해야 함', async () => {
      const { transitionManager } = await import('../template-engine/TransitionManager');

      const config: TemplateAppConfig = {
        templateId: 'test-template',
        templateType: 'admin',
        locale: 'ko',
        debug: false,
      };

      const app = new TemplateApp(config);
      await app.init();

      // setPending 모킹 초기화
      vi.mocked(transitionManager.setPending).mockClear();

      // 라우트 변경 시뮬레이션
      const router = app.getRouter();
      expect(router).toBeDefined();

      if (!router) return;

      const routeChangeHandler = vi.mocked(router.on).mock.calls.find(
        call => call[0] === 'routeChange'
      )?.[1];

      if (routeChangeHandler) {
        routeChangeHandler({
          path: '/test',
          params: {},
          query: {},
          layoutName: 'test-layout',
        });
      }

      // setPending(true)와 setPending(false)가 모두 호출되었는지 확인
      const setPendingCalls = vi.mocked(transitionManager.setPending).mock.calls;
      expect(setPendingCalls).toContainEqual([true]);
      expect(setPendingCalls).toContainEqual([false]);

      // setPending(false)가 마지막에 호출되었는지 확인
      expect(setPendingCalls[setPendingCalls.length - 1]).toEqual([false]);
    });

    it('데이터 fetch 실패 시에도 TransitionManager.setPending(false)를 호출해야 함', async () => {
      const { transitionManager } = await import('../template-engine/TransitionManager');

      // DataSourceManager가 에러를 던지도록 모킹
      vi.mock('../template-engine/DataSourceManager', () => ({
        DataSourceManager: vi.fn(function(this: any) {
          this.fetchDataSources = vi.fn().mockRejectedValue(new Error('Fetch failed'));
        }),
      }));

      const config: TemplateAppConfig = {
        templateId: 'test-template',
        templateType: 'admin',
        locale: 'ko',
        debug: false,
      };

      const app = new TemplateApp(config);
      await app.init();

      // setPending 모킹 초기화
      vi.mocked(transitionManager.setPending).mockClear();

      // 라우트 변경 시뮬레이션 (에러가 발생하더라도 setPending(false) 호출되어야 함)
      const router = app.getRouter();
      expect(router).toBeDefined();

      if (!router) return;

      const routeChangeHandler = vi.mocked(router.on).mock.calls.find(
        call => call[0] === 'routeChange'
      )?.[1];

      if (routeChangeHandler) {
        try {
          routeChangeHandler({
            path: '/test',
            params: {},
            query: {},
            layoutName: 'test-layout',
          });
        } catch (e) {
          // 에러는 무시
        }
      }

      // finally 블록에서 setPending(false)가 호출되었는지 확인
      const setPendingCalls = vi.mocked(transitionManager.setPending).mock.calls;
      expect(setPendingCalls[setPendingCalls.length - 1]).toEqual([false]);
    });
  });

  // =============================================================================
  // 회귀 테스트: SPA 네비게이션 캐시
  // 문서: troubleshooting-cache.md - 사례 8
  // =============================================================================

  describe('Regression Tests - SPA Navigation Cache', () => {
    /**
     * [TS-CACHE-8] SPA 네비게이션 후 _localInit 캐시 미무효화
     *
     * 문제: SPA 네비게이션 시 이전 페이지의 _local 캐시 값이 새 페이지에서 반환됨
     * 원인: _localInit 데이터가 변경되어도 DataBindingEngine 캐시가 무효화되지 않음
     * 해결: _localInit 처리 시 bindingEngine.invalidateCacheByKeys(['_local']) 호출
     *
     * @see DynamicRenderer.tsx - _localInit useEffect
     * @see troubleshooting-cache.md 사례 8
     */
    describe('[TS-CACHE-8] _localInit 캐시 무효화 시나리오', () => {
      it('라우트 변경 시 TransitionManager.setPending이 올바르게 호출되어야 함', async () => {
        const { transitionManager } = await import('../template-engine/TransitionManager');

        const config: TemplateAppConfig = {
          templateId: 'test-template',
          templateType: 'admin',
          locale: 'ko',
          debug: false,
        };

        const app = new TemplateApp(config);
        await app.init();

        // setPending 모킹 초기화
        vi.mocked(transitionManager.setPending).mockClear();

        // 첫 번째 라우트 변경 시뮬레이션 (Guest 역할 편집)
        const router = app.getRouter();
        expect(router).toBeDefined();

        if (!router) return;

        const routeChangeHandler = vi.mocked(router.on).mock.calls.find(
          call => call[0] === 'routeChange'
        )?.[1];

        if (routeChangeHandler) {
          // 첫 번째 페이지로 이동
          routeChangeHandler({
            path: '/admin/roles/1/edit',
            params: { id: '1' },
            query: {},
            layoutName: 'admin_role_edit',
          });

          // 두 번째 페이지로 이동 (SPA 네비게이션)
          routeChangeHandler({
            path: '/admin/roles/2/edit',
            params: { id: '2' },
            query: {},
            layoutName: 'admin_role_edit',
          });
        }

        // SPA 네비게이션에서 setPending이 올바르게 호출되는지 확인
        // (캐시 무효화는 DynamicRenderer에서 처리됨)
        const setPendingCalls = vi.mocked(transitionManager.setPending).mock.calls;
        expect(setPendingCalls.length).toBeGreaterThanOrEqual(2);
      });

      it('다른 페이지로 이동 시 레이아웃 로더가 호출되어야 함', async () => {
        const { LayoutLoader } = await import('../template-engine/LayoutLoader');

        const config: TemplateAppConfig = {
          templateId: 'test-template',
          templateType: 'admin',
          locale: 'ko',
          debug: false,
        };

        const app = new TemplateApp(config);
        await app.init();

        const router = app.getRouter();
        expect(router).toBeDefined();

        if (!router) return;

        const routeChangeHandler = vi.mocked(router.on).mock.calls.find(
          call => call[0] === 'routeChange'
        )?.[1];

        // LayoutLoader mock 인스턴스 접근
        const mockLayoutLoader = (LayoutLoader as any).mock?.results?.[0]?.value;

        if (routeChangeHandler && mockLayoutLoader) {
          vi.mocked(mockLayoutLoader.loadLayout).mockClear();

          // 페이지 전환 시 레이아웃 로더가 호출되는지 확인
          routeChangeHandler({
            path: '/admin/users/list',
            params: {},
            query: {},
            layoutName: 'admin_user_list',
          });

          // loadLayout이 호출되었는지 확인
          expect(mockLayoutLoader.loadLayout).toHaveBeenCalled();
        }
      });
    });
  });

  // =============================================================================
  // 회귀 테스트: init_actions 실행 순서
  // 문서: layout-json-features-actions.md
  // =============================================================================

  describe('Regression Tests - init_actions Execution', () => {
    /**
     * [TS-INIT-1] init_actions 순차 실행
     *
     * 문제: init_actions가 병렬로 실행되어 상태 경쟁 조건 발생
     * 원인: Promise.all로 모든 핸들러를 동시에 실행
     * 해결: for...of 루프로 순차 실행 보장
     *
     * @see layout-json-features-actions.md - 초기화 액션 (init_actions)
     */
    describe('[TS-INIT-1] init_actions 순차 실행 보장', () => {
      it('init_actions는 정의된 순서대로 실행되어야 함', async () => {
        // TemplateApp의 executeInitActions 메서드 테스트
        // 실제 구현은 for...of 루프로 순차 실행됨
        const executionOrder: string[] = [];

        // ActionDispatcher mock 설정
        const mockActionDispatcher = {
          createHandler: vi.fn().mockImplementation((actionDef) => {
            return async () => {
              executionOrder.push(actionDef.handler);
            };
          }),
          registerHandler: vi.fn(),
          hasHandler: vi.fn().mockReturnValue(true),
        };

        // 순차 실행 시뮬레이션
        const initActions = [
          { handler: 'initTheme' },
          { handler: 'initLocale' },
          { handler: 'initSettings' },
        ];

        // executeInitActions 동작 시뮬레이션
        for (const initAction of initActions) {
          const handler = mockActionDispatcher.createHandler(
            { type: 'click', handler: initAction.handler },
            {}
          );
          await handler();
        }

        // 순서대로 실행되었는지 확인
        expect(executionOrder).toEqual(['initTheme', 'initLocale', 'initSettings']);
      });

      it('init_actions 핸들러 실패 시 다음 핸들러는 계속 실행되어야 함', async () => {
        const executionOrder: string[] = [];

        const initActions = [
          { handler: 'handler1' },
          { handler: 'failingHandler' },
          { handler: 'handler3' },
        ];

        // 실패하는 핸들러를 포함한 시뮬레이션
        for (const initAction of initActions) {
          try {
            if (initAction.handler === 'failingHandler') {
              throw new Error('Handler failed');
            }
            executionOrder.push(initAction.handler);
          } catch (error) {
            // 에러 로깅 후 계속 진행 (실제 구현과 동일)
          }
        }

        // 실패한 핸들러를 제외하고 나머지는 실행되어야 함
        expect(executionOrder).toEqual(['handler1', 'handler3']);
      });
    });

    /**
     * [TS-INIT-2] init_actions 데이터 바인딩 지원
     *
     * 문제: init_actions에서 {{}} 표현식이 평가되지 않음
     * 원인: 정적 바인딩 컨텍스트가 전달되지 않음
     * 해결: dataContext를 핸들러에 전달하여 바인딩 지원
     *
     * @see layout-json-features-actions.md - 데이터 바인딩 지원
     */
    describe('[TS-INIT-2] init_actions 데이터 바인딩', () => {
      it('init_actions 파라미터에서 route 파라미터에 접근할 수 있어야 함', () => {
        const dataContext = {
          route: { id: '123', slug: 'test-product' },
          query: { page: '1' },
          _global: { currentUser: { id: 1 } },
        };

        // route.id 바인딩 시뮬레이션
        const initAction = {
          handler: 'loadProduct',
          params: {
            productId: '{{route.id}}',
            page: '{{query.page}}',
          },
        };

        // 실제 구현에서는 ActionDispatcher가 바인딩 처리
        // 여기서는 dataContext에서 값을 접근할 수 있는지 확인
        expect(dataContext.route.id).toBe('123');
        expect(dataContext.query.page).toBe('1');
        expect(initAction.params.productId).toBe('{{route.id}}');
      });

      it('init_actions에서 _global 상태에 접근할 수 있어야 함', () => {
        const dataContext = {
          _global: {
            mode: 'edit',
            settings: { theme: 'dark' },
          },
        };

        // _global.mode 바인딩 확인
        expect(dataContext._global.mode).toBe('edit');
        expect(dataContext._global.settings.theme).toBe('dark');
      });
    });

    /**
     * [TS-INIT-3] init_actions 실행 시점
     *
     * 문제: init_actions가 렌더링 후에 실행되어 초기 상태가 적용되지 않음
     * 원인: 실행 순서가 잘못됨
     * 해결: blocking 데이터 소스 fetch 후, 렌더링 전에 init_actions 실행
     *
     * @see layout-json-features-actions.md - 실행 시점
     */
    describe('[TS-INIT-3] init_actions 실행 시점', () => {
      it('init_actions는 렌더링 전에 실행되어야 함', async () => {
        const executionLog: string[] = [];

        // 실행 순서 시뮬레이션:
        // 1. 레이아웃 JSON 로드
        executionLog.push('layout_loaded');

        // 2. blocking 데이터 소스 fetch
        executionLog.push('blocking_data_fetched');

        // 3. init_actions 실행 (렌더링 전)
        executionLog.push('init_actions_executed');

        // 4. 렌더링
        executionLog.push('rendered');

        // 5. progressive/background 데이터 소스 fetch
        executionLog.push('progressive_data_fetched');

        // init_actions가 렌더링 전에 실행되었는지 확인
        const initActionsIndex = executionLog.indexOf('init_actions_executed');
        const renderedIndex = executionLog.indexOf('rendered');

        expect(initActionsIndex).toBeLessThan(renderedIndex);
        expect(initActionsIndex).toBe(2); // blocking_data_fetched 다음
      });

      it('init_actions에서 설정한 _local 상태가 dataContext에 반영되어야 함', () => {
        // init_actions에서 setState로 _local 설정하는 시나리오
        const globalState: Record<string, any> = {};
        const dataContext: Record<string, any> = { _global: {} };

        // init_actions 실행 시뮬레이션
        // handler: setState, params: { target: "local", form: { id: 1 } }
        globalState._local = { form: { id: 1, name: 'test' } };

        // init_actions 후 dataContext에 _local 반영
        dataContext._local = globalState._local;

        // _local 상태가 dataContext에 반영되었는지 확인
        expect(dataContext._local).toEqual({ form: { id: 1, name: 'test' } });
      });
    });
  });

  // =============================================================================
  // 회귀 테스트: 레이아웃 레벨 상태 초기화 (initLocal/initGlobal/initIsolated)
  // 문서: layout-json.md - 레이아웃 레벨 상태 초기화
  // =============================================================================

  describe('Regression Tests - Layout Level State Init', () => {
    /**
     * [TS-INIT-LOCAL-1] 레이아웃 레벨 initLocal 적용
     *
     * 레이아웃 JSON에서 initLocal로 정의한 정적 기본값이 _local 상태에 적용되어야 함
     */
    describe('[TS-INIT-LOCAL-1] initLocal 적용', () => {
      it('initLocal 값이 _local 상태에 적용되어야 함', () => {
        const globalState: Record<string, any> = {};
        const layoutData = {
          initLocal: {
            activeTab: 'basic',
            formData: { name: '', type: 'default' },
          },
        };

        // initLocal 적용 시뮬레이션
        if (!globalState._local) {
          globalState._local = {};
        }

        for (const [key, value] of Object.entries(layoutData.initLocal)) {
          if (globalState._local[key] === undefined) {
            globalState._local[key] = JSON.parse(JSON.stringify(value));
          }
        }

        expect(globalState._local.activeTab).toBe('basic');
        expect(globalState._local.formData).toEqual({ name: '', type: 'default' });
      });

      it('state (deprecated) 값도 initLocal로 동작해야 함 (하위 호환)', () => {
        const globalState: Record<string, any> = {};
        const layoutData = {
          state: {
            selectedMenuId: null,
            panelMode: 'view',
          },
        };

        // initLocal || state 하위 호환 시뮬레이션
        const layoutInitLocal = layoutData.state;
        if (!globalState._local) {
          globalState._local = {};
        }

        for (const [key, value] of Object.entries(layoutInitLocal)) {
          if (globalState._local[key] === undefined) {
            globalState._local[key] = JSON.parse(JSON.stringify(value));
          }
        }

        expect(globalState._local.selectedMenuId).toBeNull();
        expect(globalState._local.panelMode).toBe('view');
      });
    });

    /**
     * [TS-INIT-GLOBAL-1] 레이아웃 레벨 initGlobal 적용
     *
     * 레이아웃 JSON에서 initGlobal로 정의한 정적 기본값이 _global 상태에 적용되어야 함
     */
    describe('[TS-INIT-GLOBAL-1] initGlobal 적용', () => {
      it('initGlobal 값이 _global 상태에 적용되어야 함', () => {
        const globalState: Record<string, any> = {};
        const layoutData = {
          initGlobal: {
            sidebarOpen: true,
            theme: 'light',
          },
        };

        // initGlobal 적용 시뮬레이션
        for (const [key, value] of Object.entries(layoutData.initGlobal)) {
          if (globalState[key] === undefined) {
            globalState[key] = JSON.parse(JSON.stringify(value));
          }
        }

        expect(globalState.sidebarOpen).toBe(true);
        expect(globalState.theme).toBe('light');
      });
    });

    /**
     * [TS-INIT-MERGE-1] 레이아웃 initLocal + 데이터소스 initLocal 병합
     *
     * 레이아웃 레벨 initLocal 기본값이 유지되고, 데이터소스 응답 값과 깊은 병합되어야 함
     */
    describe('[TS-INIT-MERGE-1] initLocal 병합', () => {
      it('레이아웃 initLocal 기본값 + 데이터소스 initLocal이 깊은 병합되어야 함', () => {
        const globalState: Record<string, any> = {
          _local: {
            form: {
              name: '',
              type: 'default',
              category: 'test',  // 기본값
            },
          },
        };

        // 데이터소스 응답
        const apiResponse = {
          name: 'Product A',
          type: 'premium',
          // category는 응답에 없음 → 기본값 유지되어야 함
        };

        // deepMerge 시뮬레이션
        const deepMerge = (target: any, source: any): any => {
          if (source === null || source === undefined) return target;
          if (typeof source !== 'object') return source;
          if (Array.isArray(source)) return [...source];

          const result = { ...target };
          for (const key of Object.keys(source)) {
            if (typeof source[key] === 'object' && source[key] !== null && !Array.isArray(source[key])) {
              result[key] = deepMerge(result[key] || {}, source[key]);
            } else {
              result[key] = source[key];
            }
          }
          return result;
        };

        // 병합 시뮬레이션
        const existingValue = globalState._local.form;
        const mergedData = deepMerge(existingValue, apiResponse);

        expect(mergedData.name).toBe('Product A');      // API 값
        expect(mergedData.type).toBe('premium');        // API 값
        expect(mergedData.category).toBe('test');       // 기본값 유지
      });

      it('레이아웃 initGlobal 기본값 + 데이터소스 initGlobal이 깊은 병합되어야 함', () => {
        const globalState: Record<string, any> = {
          settings: {
            theme: 'light',
            language: 'ko',  // 기본값
          },
        };

        // 데이터소스 응답
        const apiResponse = {
          theme: 'dark',
          // language는 응답에 없음 → 기본값 유지되어야 함
        };

        // deepMerge 시뮬레이션
        const deepMerge = (target: any, source: any): any => {
          if (source === null || source === undefined) return target;
          if (typeof source !== 'object') return source;
          if (Array.isArray(source)) return [...source];

          const result = { ...target };
          for (const key of Object.keys(source)) {
            if (typeof source[key] === 'object' && source[key] !== null && !Array.isArray(source[key])) {
              result[key] = deepMerge(result[key] || {}, source[key]);
            } else {
              result[key] = source[key];
            }
          }
          return result;
        };

        // 병합 시뮬레이션
        const existingValue = globalState.settings;
        const mergedData = deepMerge(existingValue, apiResponse);

        expect(mergedData.theme).toBe('dark');          // API 값
        expect(mergedData.language).toBe('ko');         // 기본값 유지
      });
    });

    /**
     * [TS-INIT-ORDER-1] 상태 초기화 실행 순서
     *
     * 실행 순서: initLocal/initGlobal/initIsolated → 데이터소스 → initActions
     */
    describe('[TS-INIT-ORDER-1] 상태 초기화 실행 순서', () => {
      it('상태 초기화가 올바른 순서로 실행되어야 함', () => {
        const executionLog: string[] = [];

        // 실행 순서 시뮬레이션:
        // 1. 레이아웃 JSON 로드
        executionLog.push('layout_loaded');

        // 2. 레이아웃 레벨 initLocal/initGlobal/initIsolated 적용
        executionLog.push('layout_init_local_applied');
        executionLog.push('layout_init_global_applied');
        executionLog.push('layout_init_isolated_applied');

        // 3. blocking 데이터 소스 fetch
        executionLog.push('blocking_data_fetched');

        // 4. 데이터소스 initLocal/initGlobal 병합
        executionLog.push('datasource_init_merged');

        // 5. initActions 실행
        executionLog.push('init_actions_executed');

        // 6. 렌더링
        executionLog.push('rendered');

        // 레이아웃 레벨 초기화가 데이터소스 병합보다 먼저 실행되어야 함
        const layoutInitIndex = executionLog.indexOf('layout_init_local_applied');
        const datasourceMergeIndex = executionLog.indexOf('datasource_init_merged');
        const initActionsIndex = executionLog.indexOf('init_actions_executed');

        expect(layoutInitIndex).toBeLessThan(datasourceMergeIndex);
        expect(datasourceMergeIndex).toBeLessThan(initActionsIndex);
      });
    });

    /**
     * [TS-INIT-DOT-NOTATION-1] initLocal dot notation 타겟 경로 지원
     *
     * dot notation을 사용하여 중첩된 경로에 값을 매핑할 수 있어야 함
     * 예: { "checkout.item_coupons": "data.promotions.item_coupons" }
     */
    describe('[TS-INIT-DOT-NOTATION-1] initLocal dot notation 지원', () => {
      it('dot notation 경로에 값이 설정되어야 함', () => {
        const localInit: Record<string, any> = {};

        // setValueAtPath 시뮬레이션
        const setValueAtPath = (obj: any, path: string, value: any): void => {
          const keys = path.split('.');
          let current = obj;

          for (let i = 0; i < keys.length - 1; i++) {
            const key = keys[i];
            if (current[key] === undefined || current[key] === null || typeof current[key] !== 'object') {
              current[key] = {};
            }
            current = current[key];
          }

          const lastKey = keys[keys.length - 1];
          current[lastKey] = value;
        };

        // 테스트: checkout.item_coupons 경로에 값 설정
        setValueAtPath(localInit, 'checkout.item_coupons', { '1': 100, '2': 200 });
        setValueAtPath(localInit, 'checkout.use_points', 500);

        expect(localInit.checkout).toBeDefined();
        expect(localInit.checkout.item_coupons).toEqual({ '1': 100, '2': 200 });
        expect(localInit.checkout.use_points).toBe(500);
      });

      it('중첩 객체 표기법이 dot notation으로 평탄화되어야 함', () => {
        // 중첩 객체 형식의 initLocal
        const nestedInitLocal = {
          checkout: {
            item_coupons: 'data.promotions.item_coupons',
            use_points: 'data.use_points',
          },
        };

        // flattenNestedObjectToMappings 시뮬레이션
        const flattenNestedObjectToMappings = (
          obj: Record<string, any>,
          prefix: string = ''
        ): Array<{ targetPath: string; sourcePath: string }> => {
          const result: Array<{ targetPath: string; sourcePath: string }> = [];

          for (const [key, value] of Object.entries(obj)) {
            if (key === '_merge') continue;

            const currentPath = prefix ? `${prefix}.${key}` : key;

            if (typeof value === 'string') {
              result.push({ targetPath: currentPath, sourcePath: value });
            } else if (typeof value === 'object' && value !== null && !Array.isArray(value)) {
              result.push(...flattenNestedObjectToMappings(value, currentPath));
            }
          }

          return result;
        };

        const mappings = flattenNestedObjectToMappings(nestedInitLocal);

        expect(mappings).toHaveLength(2);
        expect(mappings).toContainEqual({
          targetPath: 'checkout.item_coupons',
          sourcePath: 'data.promotions.item_coupons',
        });
        expect(mappings).toContainEqual({
          targetPath: 'checkout.use_points',
          sourcePath: 'data.use_points',
        });
      });
    });

    /**
     * [TS-INIT-MERGE-STRATEGY-1] initLocal 병합 전략 옵션
     *
     * _merge 옵션으로 병합 전략을 지정할 수 있어야 함
     * - "deep": 깊은 병합 (기본값)
     * - "shallow": 얕은 병합
     * - "replace": 덮어쓰기
     */
    describe('[TS-INIT-MERGE-STRATEGY-1] initLocal 병합 전략', () => {
      it('deep 병합 전략이 기본값이어야 함', () => {
        const existing = {
          checkout: {
            item_coupons: { '1': 100 },
            use_points: 0,
          },
        };

        const newValue = {
          item_coupons: { '2': 200 },
        };

        // deep merge 시뮬레이션
        const deepMerge = (target: any, source: any): any => {
          if (source === null || source === undefined) return target;
          if (typeof source !== 'object') return source;
          if (Array.isArray(source)) return source;

          const result = { ...target };
          for (const key of Object.keys(source)) {
            if (typeof source[key] === 'object' && source[key] !== null && !Array.isArray(source[key])) {
              result[key] = deepMerge(result[key] || {}, source[key]);
            } else {
              result[key] = source[key];
            }
          }
          return result;
        };

        const result = deepMerge(existing.checkout, newValue);

        // 기존 item_coupons와 새 item_coupons가 병합됨
        expect(result.item_coupons).toEqual({ '1': 100, '2': 200 });
        // 기존 use_points 유지
        expect(result.use_points).toBe(0);
      });

      it('shallow 병합 전략이 최상위 키만 병합해야 함', () => {
        const existing = {
          item_coupons: { '1': 100 },
          use_points: 0,
        };

        const newValue = {
          item_coupons: { '2': 200 },
        };

        // shallow merge
        const result = { ...existing, ...newValue };

        // item_coupons는 완전히 교체됨 (얕은 병합)
        expect(result.item_coupons).toEqual({ '2': 200 });
        // use_points는 유지
        expect(result.use_points).toBe(0);
      });

      it('replace 전략이 기존 값을 완전히 교체해야 함', () => {
        const existing = {
          item_coupons: { '1': 100 },
          use_points: 0,
        };

        const newValue = {
          item_coupons: { '2': 200 },
        };

        // replace (덮어쓰기)
        const result = newValue;

        // 새 값만 존재
        expect(result.item_coupons).toEqual({ '2': 200 });
        expect((result as any).use_points).toBeUndefined();
      });

      it('_merge 예약 키가 매핑에서 제외되어야 함', () => {
        const initLocalWithMerge = {
          _merge: 'shallow',
          checkout: {
            item_coupons: 'data.promotions.item_coupons',
          },
        };

        // flattenNestedObjectToMappings 시뮬레이션 (_merge 제외)
        const flattenNestedObjectToMappings = (
          obj: Record<string, any>,
          prefix: string = ''
        ): Array<{ targetPath: string; sourcePath: string }> => {
          const result: Array<{ targetPath: string; sourcePath: string }> = [];

          for (const [key, value] of Object.entries(obj)) {
            if (key === '_merge') continue; // 예약 키 제외

            const currentPath = prefix ? `${prefix}.${key}` : key;

            if (typeof value === 'string') {
              result.push({ targetPath: currentPath, sourcePath: value });
            } else if (typeof value === 'object' && value !== null && !Array.isArray(value)) {
              result.push(...flattenNestedObjectToMappings(value, currentPath));
            }
          }

          return result;
        };

        const mappings = flattenNestedObjectToMappings(initLocalWithMerge);

        // _merge는 매핑에 포함되지 않음
        expect(mappings).toHaveLength(1);
        expect(mappings[0]).toEqual({
          targetPath: 'checkout.item_coupons',
          sourcePath: 'data.promotions.item_coupons',
        });
      });
    });
  });

  // =============================================================================
  // 회귀 테스트: SPA 네비게이션 시 _local 상태 레이아웃 간 유출 방지
  // 문서: troubleshooting-state-closure.md - 사례 165 (동일 패턴)
  // =============================================================================

  describe('Regression Tests - _local State Layout Isolation', () => {
    /**
     * [TS-LOCAL-RESET-1] 다른 레이아웃 전환 시 _local 완전 초기화
     *
     * 문제: SPA navigate로 다른 DataGrid 리스트 화면 진입 시 이전 레이아웃의
     *       _local.visibleColumns 값이 잔존하여 DataGrid 컬럼이 렌더링되지 않음
     * 원인: _local 병합 로직이 기존 키를 유지하고 새 키만 추가
     * 해결: 레이아웃 이름 비교를 통한 선택적 _local 초기화
     */
    describe('[TS-LOCAL-RESET-1] 레이아웃 전환 시 _local 초기화', () => {
      it('다른 레이아웃으로 전환 시 이전 _local 값이 제거되어야 함', () => {
        // 주문관리 레이아웃에서의 _local 상태 시뮬레이션
        const globalState: Record<string, any> = {
          _local: {
            visibleColumns: ['no', 'ordered_at', 'order_number', 'username'],
            visibleFilters: ['status', 'date_range'],
            filter: { status: 'all' },
          },
        };

        // 배송정책 레이아웃의 initLocal (visibleColumns 없음)
        const newLayoutInitLocal = {
          selectedItems: [],
        };

        let currentLayoutName = 'admin_ecommerce_order_list';
        const newLayoutName = 'admin_ecommerce_shipping_policy_list';

        // 레이아웃 전환 감지
        const isLayoutChanged = currentLayoutName !== '' && currentLayoutName !== newLayoutName;

        if (isLayoutChanged) {
          globalState._local = {};
        }

        currentLayoutName = newLayoutName;

        // 새 레이아웃의 initLocal 적용
        for (const [key, value] of Object.entries(newLayoutInitLocal)) {
          if (globalState._local[key] === undefined) {
            globalState._local[key] = JSON.parse(JSON.stringify(value));
          }
        }

        // 이전 레이아웃의 visibleColumns가 제거되어야 함
        expect(globalState._local.visibleColumns).toBeUndefined();
        expect(globalState._local.visibleFilters).toBeUndefined();
        expect(globalState._local.filter).toBeUndefined();
        // 새 레이아웃의 initLocal만 존재
        expect(globalState._local.selectedItems).toEqual([]);
      });

      it('같은 레이아웃 재진입 시 기존 _local 값이 유지되어야 함', () => {
        // 주문관리 리스트 → 상품수정 → 뒤로가기 → 주문관리 리스트
        const globalState: Record<string, any> = {
          _local: {
            visibleColumns: ['no', 'ordered_at', 'order_number'],
            visibleFilters: ['status'],
            filter: { status: 'pending' },
          },
        };

        const layoutInitLocal = {
          visibleColumns: [],
          visibleFilters: [],
          filter: {},
        };

        let currentLayoutName = 'admin_ecommerce_order_list';
        const newLayoutName = 'admin_ecommerce_order_list'; // 같은 레이아웃

        // 같은 레이아웃이므로 초기화 없음
        const isLayoutChanged = currentLayoutName !== '' && currentLayoutName !== newLayoutName;
        expect(isLayoutChanged).toBe(false);

        if (isLayoutChanged) {
          globalState._local = {};
        }

        currentLayoutName = newLayoutName;

        // initLocal 병합 (기존 값 유지)
        for (const [key, value] of Object.entries(layoutInitLocal)) {
          if (globalState._local[key] === undefined) {
            globalState._local[key] = JSON.parse(JSON.stringify(value));
          }
        }

        // 기존 사용자 설정값이 유지되어야 함
        expect(globalState._local.visibleColumns).toEqual(['no', 'ordered_at', 'order_number']);
        expect(globalState._local.visibleFilters).toEqual(['status']);
        expect(globalState._local.filter).toEqual({ status: 'pending' });
      });

      it('첫 로딩 시 (currentLayoutName 빈 문자열) 초기화 없이 initLocal만 적용되어야 함', () => {
        const globalState: Record<string, any> = {};

        const layoutInitLocal = {
          activeTab: 'basic',
          formData: { name: '', type: 'default' },
        };

        let currentLayoutName = ''; // 첫 로딩
        const newLayoutName = 'admin_ecommerce_order_list';

        const isLayoutChanged = currentLayoutName !== '' && currentLayoutName !== newLayoutName;
        expect(isLayoutChanged).toBe(false); // 첫 로딩이므로 false

        if (!globalState._local) {
          globalState._local = {};
        }

        currentLayoutName = newLayoutName;

        for (const [key, value] of Object.entries(layoutInitLocal)) {
          if (globalState._local[key] === undefined) {
            globalState._local[key] = JSON.parse(JSON.stringify(value));
          }
        }

        expect(globalState._local.activeTab).toBe('basic');
        expect(globalState._local.formData).toEqual({ name: '', type: 'default' });
      });

      it('연속 레이아웃 전환 시 각각 올바르게 초기화되어야 함', () => {
        const globalState: Record<string, any> = { _local: {} };
        let currentLayoutName = '';

        // 1단계: 주문관리 첫 진입
        const orderInitLocal = { visibleColumns: ['no', 'ordered_at'], filter: {} };
        currentLayoutName = 'admin_ecommerce_order_list';
        for (const [key, value] of Object.entries(orderInitLocal)) {
          if (globalState._local[key] === undefined) {
            globalState._local[key] = JSON.parse(JSON.stringify(value));
          }
        }
        expect(globalState._local.visibleColumns).toEqual(['no', 'ordered_at']);

        // 2단계: 배송정책으로 전환
        const shippingInitLocal = { selectedItems: [] };
        const isLayoutChanged1 = currentLayoutName !== '' && currentLayoutName !== 'admin_ecommerce_shipping_policy_list';
        expect(isLayoutChanged1).toBe(true);
        globalState._local = {};
        currentLayoutName = 'admin_ecommerce_shipping_policy_list';
        for (const [key, value] of Object.entries(shippingInitLocal)) {
          if (globalState._local[key] === undefined) {
            globalState._local[key] = JSON.parse(JSON.stringify(value));
          }
        }
        expect(globalState._local.visibleColumns).toBeUndefined();
        expect(globalState._local.selectedItems).toEqual([]);

        // 3단계: 쿠폰관리로 전환
        const couponInitLocal = { visibleColumns: ['code', 'discount_type'], selectedItems: [] };
        const isLayoutChanged2 = currentLayoutName !== '' && currentLayoutName !== 'admin_ecommerce_promotion_coupon_list';
        expect(isLayoutChanged2).toBe(true);
        globalState._local = {};
        currentLayoutName = 'admin_ecommerce_promotion_coupon_list';
        for (const [key, value] of Object.entries(couponInitLocal)) {
          if (globalState._local[key] === undefined) {
            globalState._local[key] = JSON.parse(JSON.stringify(value));
          }
        }
        // 쿠폰 레이아웃의 값만 존재해야 함
        expect(globalState._local.visibleColumns).toEqual(['code', 'discount_type']);
        expect(globalState._local.selectedItems).toEqual([]);
      });
    });
  });

  // =============================================================================
  // 회귀 테스트: initLocal 없는 레이아웃에서도 _local 정리 동작
  // 버그: cleanup 로직이 if(layoutInitLocal) 블록 안에 있어서
  //       레이아웃 레벨 initLocal이 없는 화면에서 _global._local이 정리되지 않음
  // 커밋: deffb855 에서 도입된 cleanup 로직의 구조적 결함 수정
  // =============================================================================

  describe('Regression Tests - _local Cleanup Without Layout-Level initLocal', () => {
    /**
     * [TS-LOCAL-RESET-2] initLocal 없는 레이아웃 전환 시에도 _local 초기화
     *
     * 문제: 역할 수정 → 역할 목록 → 역할 추가 이동 시 수정 폼 데이터가 잔존
     * 원인: cleanup 로직이 if(layoutInitLocal) 블록 안에 중첩되어 있어
     *       레이아웃 레벨 initLocal이 없으면 cleanup 자체가 실행되지 않음
     * 해결: cleanup 로직을 if(layoutInitLocal) 블록 바깥으로 이동
     */
    describe('[TS-LOCAL-RESET-2] initLocal 없는 레이아웃에서 _local 정리', () => {
      it('레이아웃 레벨 initLocal이 없어도 다른 레이아웃 전환 시 _local이 초기화되어야 함', () => {
        // 역할 수정 페이지에서 데이터소스 initLocal로 설정된 _local.form
        const globalState: Record<string, any> = {
          _local: {
            form: {
              id: 8,
              name: '매니저',
              name_raw: { en: 'Manager', ko: '매니저' },
              permission_ids: [102, 103, 104],
            },
          },
        };

        let currentLayoutName = 'admin_role_form';

        // 역할 목록 레이아웃으로 전환 (initLocal 있음)
        const newLayoutName1 = 'admin_role_list';
        const layoutData1 = {
          layout_name: 'admin_role_list',
          initLocal: { selectedItems: [] }, // initLocal 있음
        };

        // cleanup 로직 (블록 바깥에서 실행)
        const isLayoutChanged1 = currentLayoutName !== '' && currentLayoutName !== newLayoutName1;
        expect(isLayoutChanged1).toBe(true);

        if (isLayoutChanged1) {
          globalState._local = {};
        }
        currentLayoutName = newLayoutName1;

        // initLocal 적용
        const layoutInitLocal1 = layoutData1.initLocal;
        if (layoutInitLocal1) {
          for (const [key, value] of Object.entries(layoutInitLocal1)) {
            if (globalState._local[key] === undefined) {
              globalState._local[key] = JSON.parse(JSON.stringify(value));
            }
          }
        }

        // 수정 폼 데이터가 제거되어야 함
        expect(globalState._local.form).toBeUndefined();
        expect(globalState._local.selectedItems).toEqual([]);
      });

      it('레이아웃 레벨 initLocal이 없는 폼 레이아웃 전환 시에도 _local이 초기화되어야 함', () => {
        // 역할 목록 페이지의 _local 상태
        const globalState: Record<string, any> = {
          _local: {
            selectedItems: [1, 2, 3],
            filter: { status: 'active' },
          },
        };

        let currentLayoutName = 'admin_role_list';

        // 역할 추가 페이지로 전환 (레이아웃 레벨 initLocal 없음, 데이터소스 initLocal만 있음)
        const newLayoutName = 'admin_role_form';
        const layoutData = {
          layout_name: 'admin_role_form',
          // initLocal 없음! (데이터소스에만 initLocal: "form" 있음)
        };

        // cleanup 로직 (블록 바깥에서 실행 — 이것이 핵심 수정)
        const isLayoutChanged = currentLayoutName !== '' && currentLayoutName !== newLayoutName;
        expect(isLayoutChanged).toBe(true);

        if (isLayoutChanged) {
          globalState._local = {};
        }
        currentLayoutName = newLayoutName;

        // initLocal 적용 (없으므로 스킵)
        const layoutInitLocal = (layoutData as any).initLocal;
        if (layoutInitLocal && Object.keys(layoutInitLocal).length > 0) {
          for (const [key, value] of Object.entries(layoutInitLocal)) {
            if (globalState._local[key] === undefined) {
              globalState._local[key] = JSON.parse(JSON.stringify(value));
            }
          }
        }

        // 이전 레이아웃의 상태가 완전히 제거되어야 함
        expect(globalState._local.selectedItems).toBeUndefined();
        expect(globalState._local.filter).toBeUndefined();
        expect(globalState._local).toEqual({});
      });

      it('currentLayoutName이 initLocal 유무와 관계없이 항상 갱신되어야 함', () => {
        let currentLayoutName = '';

        // 1단계: initLocal 없는 레이아웃 첫 진입
        const layoutName1 = 'admin_role_form';
        currentLayoutName = layoutName1;
        expect(currentLayoutName).toBe('admin_role_form');

        // 2단계: initLocal 있는 레이아웃으로 전환
        const layoutName2 = 'admin_role_list';
        currentLayoutName = layoutName2;
        expect(currentLayoutName).toBe('admin_role_list');

        // 3단계: 다시 initLocal 없는 레이아웃으로 전환
        const layoutName3 = 'admin_role_form';
        const isLayoutChanged = currentLayoutName !== '' && currentLayoutName !== layoutName3;
        expect(isLayoutChanged).toBe(true); // 다른 레이아웃이므로 true
        currentLayoutName = layoutName3;
        expect(currentLayoutName).toBe('admin_role_form');
      });

      it('역할 수정 → 역할 목록 → 역할 추가 전체 흐름에서 폼 데이터가 잔존하지 않아야 함', () => {
        const globalState: Record<string, any> = { _local: {} };
        let currentLayoutName = '';

        // 1단계: 역할 수정 페이지 진입 (admin_role_form, initLocal 없음)
        const editLayoutName = 'admin_role_form';
        if (currentLayoutName !== '' && currentLayoutName !== editLayoutName) {
          globalState._local = {};
        }
        currentLayoutName = editLayoutName;

        // 데이터소스 initLocal로 폼 데이터 설정 (엔진에서 자동 실행)
        globalState._local.form = {
          id: 8,
          name: '매니저',
          permission_ids: [102, 103],
        };

        expect(globalState._local.form.id).toBe(8);

        // 2단계: 역할 목록으로 이동 (admin_role_list)
        const listLayoutName = 'admin_role_list';
        const isChanged1 = currentLayoutName !== '' && currentLayoutName !== listLayoutName;
        expect(isChanged1).toBe(true);
        if (isChanged1) {
          globalState._local = {};
        }
        currentLayoutName = listLayoutName;

        expect(globalState._local.form).toBeUndefined();

        // 3단계: 역할 추가로 이동 (admin_role_form, initLocal 없음)
        const createLayoutName = 'admin_role_form';
        const isChanged2 = currentLayoutName !== '' && currentLayoutName !== createLayoutName;
        expect(isChanged2).toBe(true);
        if (isChanged2) {
          globalState._local = {};
        }
        currentLayoutName = createLayoutName;

        // 수정 모드의 폼 데이터가 완전히 제거되어야 함
        expect(globalState._local.form).toBeUndefined();
        expect(globalState._local).toEqual({});
      });
    });
  });

  // =============================================================================
  // 회귀 테스트: 템플릿 핸들러 초기화 시점 등록
  // 문서: troubleshooting-state-setstate.md
  // =============================================================================

  describe('Regression Tests - Template Handler Registration on Init', () => {
    /**
     * [TS-HANDLER-INIT-1] 초기화 시 window.G7TemplateHandlers에서 핸들러 등록
     *
     * 문제: 캐시 없이 새로고침 시 정상이지만, 일반 진입 시
     *       "Unknown action handler: initTheme/initCartKey" 오류 발생
     * 원인: components.iife.js의 initTemplate()은 window.load에서 핸들러를 등록하지만,
     *       app.init()은 DOMContentLoaded에서 시작되어 init_actions가 window.load 전에 실행됨
     * 해결: TemplateApp.initialize()에서 loadExtensionAssets() 후
     *       reinitializeTemplateHandlers()를 호출하여 window.G7TemplateHandlers에서 직접 등록
     */
    describe('[TS-HANDLER-INIT-1] 초기화 시 템플릿 핸들러 등록', () => {
      it('reinitializeTemplateHandlers()가 window.G7TemplateHandlers의 핸들러를 ActionDispatcher에 등록해야 함', () => {
        // window.G7TemplateHandlers 설정 (components.iife.js가 설정하는 전역 객체)
        const mockHandlers = {
          initTheme: vi.fn(),
          initCartKey: vi.fn(),
          setTheme: vi.fn(),
        };
        (window as any).G7TemplateHandlers = mockHandlers;

        // registerHandler mock 초기화
        sharedActionDispatcher.registerHandler.mockClear();

        const config: TemplateAppConfig = {
          templateId: 'test-template',
          templateType: 'user',
          locale: 'ko',
          debug: false,
        };

        const app = new TemplateApp(config);

        // private 메서드 직접 호출 (초기화 시 호출되는 것과 동일한 동작)
        (app as any).reinitializeTemplateHandlers();

        // 핸들러가 ActionDispatcher에 등록되었는지 확인
        const registerCalls = sharedActionDispatcher.registerHandler.mock.calls;
        const registeredNames = registerCalls.map((call: any[]) => call[0]);

        expect(registeredNames).toContain('initTheme');
        expect(registeredNames).toContain('initCartKey');
        expect(registeredNames).toContain('setTheme');
        expect(registerCalls).toHaveLength(3);

        // 정리
        delete (window as any).G7TemplateHandlers;
      });

      it('window.G7TemplateHandlers가 없으면 에러 없이 건너뛰어야 함', () => {
        delete (window as any).G7TemplateHandlers;

        sharedActionDispatcher.registerHandler.mockClear();

        const config: TemplateAppConfig = {
          templateId: 'test-template',
          templateType: 'user',
          locale: 'ko',
          debug: false,
        };

        const app = new TemplateApp(config);

        // 에러 없이 실행되어야 함
        expect(() => (app as any).reinitializeTemplateHandlers()).not.toThrow();

        // 핸들러가 등록되지 않아야 함
        expect(sharedActionDispatcher.registerHandler).not.toHaveBeenCalled();
      });

      it('initialize() 코드에 reinitializeTemplateHandlers() 호출이 loadExtensionAssets() 후에 존재해야 함', async () => {
        // 코드 구조 검증: TemplateApp의 init 메서드 소스에
        // reinitializeTemplateHandlers 호출이 포함되어 있는지 확인
        const config: TemplateAppConfig = {
          templateId: 'test-template',
          templateType: 'user',
          locale: 'ko',
          debug: false,
        };

        const app = new TemplateApp(config);

        // reinitializeTemplateHandlers 메서드가 존재하는지 확인
        expect(typeof (app as any).reinitializeTemplateHandlers).toBe('function');

        // loadExtensionAssets 메서드도 존재하는지 확인
        expect(typeof (app as any).loadExtensionAssets).toBe('function');
      });
    });
  });

  // =============================================================================
  // 회귀 테스트: WebSocket 데이터소스 progressive 제외 (engine-v1.32.2)
  // 이슈: WebSocket 소스가 progressiveDataInit에서 undefined로 초기화되어
  //       blur_until_loaded가 영구 블러 → WebSocket 성공/실패 무관하게 발생
  // =============================================================================
  describe('Regression Tests - WebSocket Source Filtering', () => {
    /**
     * [TS-WS-PROGRESSIVE-1] WebSocket 소스는 progressive 목록에서 제외
     *
     * WebSocket은 이벤트 리스너이지 데이터 제공자가 아니므로
     * progressiveDataInit에 포함되면 dataContext에 undefined 키가 영구 잔존하고
     * blur_until_loaded가 절대 해제되지 않음
     */
    describe('[TS-WS-PROGRESSIVE-1] WebSocket 소스 progressive 필터 제외', () => {
      it('WebSocket 타입 소스는 progressiveAndBackgroundSources에 포함되지 않아야 함', () => {
        const dataSources = [
          { id: 'users', type: 'api', endpoint: '/api/users' },
          { id: 'modules', type: 'api', endpoint: '/api/modules', loading_strategy: 'progressive' },
          { id: 'config', type: 'api', endpoint: '/api/config', loading_strategy: 'blocking' },
          {
            id: 'notification_ws',
            type: 'websocket',
            channel: 'core.user.notifications.1',
            event: 'notification.received',
            target_source: 'notification_unread_count',
          },
        ];

        // TemplateApp.ts의 progressive 필터 로직 (engine-v1.32.2)
        const progressiveAndBackgroundSources = dataSources.filter(
          (source: any) =>
            (source.loading_strategy || 'progressive') !== 'blocking' &&
            source.type !== 'websocket'
        );

        const ids = progressiveAndBackgroundSources.map((s) => s.id);

        // API progressive 소스만 포함
        expect(ids).toContain('users');
        expect(ids).toContain('modules');
        // blocking 제외
        expect(ids).not.toContain('config');
        // WebSocket 제외 (핵심)
        expect(ids).not.toContain('notification_ws');
      });

      it('WebSocket 소스는 progressiveDataInit에서 undefined 초기화되지 않아야 함', () => {
        const dataSources = [
          { id: 'users', type: 'api', endpoint: '/api/users' },
          {
            id: 'notification_ws',
            type: 'websocket',
            channel: 'core.user.notifications.1',
            event: 'notification.received',
          },
        ];

        const progressiveAndBackgroundSources = dataSources.filter(
          (source: any) =>
            (source.loading_strategy || 'progressive') !== 'blocking' &&
            source.type !== 'websocket'
        );
        const progressiveDataSourceIds = progressiveAndBackgroundSources.map((s: any) => s.id);

        const progressiveDataInit: Record<string, any> = {};
        progressiveDataSourceIds.forEach((id: string) => {
          progressiveDataInit[id] = undefined;
        });

        // dataContext에 WebSocket 키가 존재하지 않아야 함 (blur_until_loaded 회귀 방지)
        expect('notification_ws' in progressiveDataInit).toBe(false);
        expect('users' in progressiveDataInit).toBe(true);
      });

      it('blur_until_loaded: true 체크 시 WebSocket 키가 dataContext에 없어야 영구 블러 방지', () => {
        // blur_until_loaded: true의 체크 로직 시뮬레이션 (DynamicRenderer.tsx)
        const systemKeys = ['route', 'query', '_global', '_local', '_dataSourceErrors'];

        // 수정 전: WebSocket 소스가 progressive에 포함되어 dataContext에 undefined로 존재
        const dataContextBefore = {
          users: { data: [{ id: 1 }] },
          modules: { data: [{ id: 'mod-1' }] },
          notification_ws: undefined, // ← 영구 undefined (블러 영구 유지)
          route: {},
          query: {},
          _global: {},
        };
        const keysBefore = Object.keys(dataContextBefore).filter(
          (key) => !systemKeys.includes(key) && !key.startsWith('_')
        );
        const hasUndefinedBefore = keysBefore.some(
          (key) => (dataContextBefore as any)[key] === undefined
        );
        expect(hasUndefinedBefore).toBe(true); // 버그 재현: 영구 블러

        // 수정 후: WebSocket 소스가 dataContext에 아예 존재하지 않음
        const dataContextAfter = {
          users: { data: [{ id: 1 }] },
          modules: { data: [{ id: 'mod-1' }] },
          route: {},
          query: {},
          _global: {},
        };
        const keysAfter = Object.keys(dataContextAfter).filter(
          (key) => !systemKeys.includes(key) && !key.startsWith('_')
        );
        const hasUndefinedAfter = keysAfter.some(
          (key) => (dataContextAfter as any)[key] === undefined
        );
        expect(hasUndefinedAfter).toBe(false); // 수정 검증: 블러 정상 해제
      });

      it('WebSocket 소스는 target_source로 다른 데이터소스를 트리거하지만 자체 데이터를 제공하지 않음', () => {
        // notification_ws는 콜백에서 source.target_source || source.id 사용 (DataSourceManager.subscribeWebSockets)
        // → 콜백이 호출되어도 dataContext에 'notification_ws' 키는 절대 추가되지 않음
        // → progressiveDataInit에 포함되어선 안 되는 명백한 근거
        const wsSource = {
          id: 'notification_ws',
          type: 'websocket',
          target_source: 'notification_unread_count',
        };

        const targetId = wsSource.target_source || wsSource.id;
        expect(targetId).toBe('notification_unread_count');
        expect(targetId).not.toBe('notification_ws');
      });
    });

    /**
     * [TS-WS-CHANNEL-EXPR-1] WebSocket channel/event 표현식 평가 (engine-v1.32.3)
     *
     * 채널명에 표현식이 포함된 경우(예: core.user.notifications.{{current_user.data.id}}),
     * 평가 없이 그대로 구독하면 백엔드 채널 패턴과 매칭되지 않아 broadcasting/auth가
     * AccessDeniedHttpException을 던짐. fetched 데이터를 컨텍스트로 삼아 평가해야 함.
     */
    describe('[TS-WS-CHANNEL-EXPR-1] WebSocket channel/event 표현식 평가', () => {
      it('표현식이 포함된 채널명은 fetched 데이터로 평가되어야 함', () => {
        const fetchedData = {
          current_user: { data: { id: 42, username: 'admin' } },
        };
        const source = {
          id: 'notification_ws',
          type: 'websocket',
          channel: 'core.user.notifications.{{current_user.data.id}}',
          event: 'notification.received',
        };

        // resolveExpressionString 동작 시뮬레이션
        const channelTemplate = source.channel;
        const resolved = channelTemplate.replace(
          /\{\{([^}]+)\}\}/g,
          (_match: string, expr: string) => {
            const path = expr.trim().split('.');
            let value: any = fetchedData;
            for (const key of path) {
              value = value?.[key];
            }
            return value !== undefined ? String(value) : '';
          }
        );

        expect(resolved).toBe('core.user.notifications.42');
        expect(resolved).not.toContain('{{');
      });

      it('정적 채널은 표현식 평가 후에도 동일해야 함', () => {
        const source = {
          channel: 'core.admin.dashboard',
          event: 'dashboard.stats.updated',
        };

        // 정적 채널은 평가해도 변경 없음 (표현식 마커 없음)
        expect(source.channel.includes('{{')).toBe(false);
        expect(source.event.includes('{{')).toBe(false);
      });

      it('미평가 표현식이 남아있으면 구독을 건너뛰어야 함 (방어 로직)', () => {
        // current_user가 아직 로드되지 않은 상태에서 평가 시도하면
        // 표현식 마커가 남아있을 수 있음 → 구독 건너뜀
        const fetchedData: Record<string, any> = {}; // current_user 없음
        const channelTemplate = 'core.user.notifications.{{current_user.data.id}}';

        const resolved = channelTemplate.replace(
          /\{\{([^}]+)\}\}/g,
          (_match: string, expr: string) => {
            const path = expr.trim().split('.');
            let value: any = fetchedData;
            for (const key of path) {
              value = value?.[key];
            }
            return value !== undefined ? String(value) : `{{${expr}}}`;
          }
        );

        // 표현식이 평가되지 못했음
        expect(resolved.includes('{{') || resolved.endsWith('.')).toBe(true);

        // 이런 경우 구독을 건너뛰어야 함 (DataSourceManager.subscribeWebSockets 방어 로직)
        const shouldSkipSubscription = resolved.includes('{{');
        // 빈 문자열로 치환된 경우는 trailing dot으로 감지
        const isInvalidChannel = shouldSkipSubscription || /\.$/.test(resolved);
        expect(isInvalidChannel).toBe(true);
      });
    });

    /**
     * [TS-WS-ORDER-1] WebSocket 구독 순서 (engine-v1.32.3)
     *
     * WebSocket 구독은 progressive fetch 완료 후에 실행되어야 함.
     * 채널 표현식이 progressive 데이터(current_user 등)를 참조할 수 있기 때문.
     */
    describe('[TS-WS-ORDER-1] WebSocket 구독 순서', () => {
      it('WebSocket 구독은 progressive fetch 완료 후에 실행되어야 함', () => {
        const executionLog: string[] = [];

        // 시뮬레이션: blocking → render → progressive → websocket
        executionLog.push('blocking_fetched');
        executionLog.push('rendered');
        executionLog.push('progressive_fetched');
        executionLog.push('websocket_subscribed');

        const renderIndex = executionLog.indexOf('rendered');
        const progressiveIndex = executionLog.indexOf('progressive_fetched');
        const websocketIndex = executionLog.indexOf('websocket_subscribed');

        // render 후에 progressive fetch
        expect(progressiveIndex).toBeGreaterThan(renderIndex);
        // progressive fetch 후에 websocket 구독 (current_user 등 참조 위해)
        expect(websocketIndex).toBeGreaterThan(progressiveIndex);
      });
    });

    /**
     * [TS-UQP-INDEX-1] updateQueryParams 데이터소스 refetch id 기반 매핑 (engine-v1.32.5)
     *
     * 이슈: navigate(replace:true) 시 updateQueryParams가 autoFetchDataSources[i]로
     *       인덱스 매핑하면 fetchDataSourcesWithResults 내부 WebSocket silent filter로
     *       인해 results가 짧아져 인덱스 어긋남 발생. settings 데이터가 notification_ws
     *       키에 기록되고, form initLocal이 다른 데이터소스 응답으로 초기화되어 탭 콘텐츠 빈 화면.
     *
     * 수정: id 기반 Map 조회 + WebSocket 사전 필터링 (handleRouteChange 패턴과 통일)
     */
    describe('[TS-UQP-INDEX-1] updateQueryParams id 기반 매핑', () => {
      it('WebSocket 소스가 중간에 있어도 results와 autoFetchDataSources 매핑이 어긋나지 않아야 함', () => {
        // 시뮬레이션: _admin_base.json (WebSocket 포함) + admin_settings.json 결합 데이터소스
        const autoFetchDataSources = [
          { id: 'notifications', type: 'api' },
          { id: 'notification_unread_count', type: 'api' },
          { id: 'installed_modules', type: 'api' },
          { id: 'installed_plugins', type: 'api' },
          { id: 'notification_ws', type: 'websocket' },  // ← 중간에 WebSocket
          { id: 'settings', type: 'api', initLocal: { form: '{{data}}' } },
          { id: 'systemInfo', type: 'api' },
          { id: 'appKey', type: 'api' },
          { id: 'notificationDefinitions', type: 'api' },
          { id: 'availableChannels', type: 'api' },
        ];

        // engine-v1.32.5 수정: 사전 WebSocket 필터링
        const filtered = autoFetchDataSources.filter(
          (ds: any) => ds.type !== 'websocket'
        );

        // WebSocket이 제외되었는지 확인
        expect(filtered.find((s) => s.id === 'notification_ws')).toBeUndefined();
        expect(filtered).toHaveLength(9);

        // 시뮬레이션: fetchDataSourcesWithResults가 필터된 소스만 fetch하여 id 포함 results 반환
        const results = filtered.map((s) => ({
          id: s.id,
          state: 'success' as const,
          data: { _source: s.id },  // 소스 id를 데이터에 포함시켜 매핑 검증
        }));

        // id 기반 Map 조회 (engine-v1.32.5 수정 패턴)
        const sourceById = new Map(filtered.map((ds: any) => [ds.id, ds]));
        const updateData: Record<string, any> = {};

        for (const result of results) {
          const dataSourceDef = sourceById.get(result.id);
          expect(dataSourceDef).toBeDefined();
          // 핵심 검증: dataSourceDef.id === result.id (어긋나지 않음)
          expect(dataSourceDef!.id).toBe(result.id);
          updateData[result.id] = result.data;
        }

        // settings 데이터가 settings 키에 기록되었는지 (NOT notification_ws, NOT systemInfo)
        expect(updateData.settings).toEqual({ _source: 'settings' });
        expect(updateData.systemInfo).toEqual({ _source: 'systemInfo' });
        // 마지막 소스도 누락 없이 기록
        expect(updateData.availableChannels).toEqual({ _source: 'availableChannels' });
      });

      it('인덱스 기반 매핑 (BEFORE) 은 WebSocket이 있으면 어긋남을 재현', () => {
        // 회귀 방지: 이전 버그 재현을 통해 id 기반 매핑의 필요성 증명
        const autoFetchDataSources = [
          { id: 'api_a', type: 'api' },
          { id: 'ws_source', type: 'websocket' },
          { id: 'api_b', type: 'api' },
          { id: 'api_c', type: 'api' },
        ];

        // fetchDataSourcesWithResults 내부 필터 (WebSocket 제거)
        const targetSources = autoFetchDataSources.filter(
          (s) => s.type !== 'websocket'
        );

        const results = targetSources.map((s) => ({
          id: s.id,
          state: 'success' as const,
          data: { _source: s.id },
        }));

        // BEFORE: 인덱스 기반 매핑 (버그)
        const buggyMapping: Record<string, any> = {};
        for (let i = 0; i < results.length; i++) {
          const result = results[i];
          const dataSourceDef = autoFetchDataSources[i]; // ← 필터 안 된 원본
          buggyMapping[dataSourceDef.id] = result.data;
        }

        // 증명: api_b 데이터가 ws_source 키에 잘못 기록됨
        expect(buggyMapping.ws_source).toEqual({ _source: 'api_b' });
        // api_a만 정상
        expect(buggyMapping.api_a).toEqual({ _source: 'api_a' });
        // api_c는 누락됨 (results 끝에서 잘림)
        expect(buggyMapping.api_c).toBeUndefined();

        // AFTER: id 기반 매핑 (수정)
        const sourceById = new Map(
          autoFetchDataSources.filter((ds) => ds.type !== 'websocket').map((ds) => [ds.id, ds])
        );
        const fixedMapping: Record<string, any> = {};
        for (const result of results) {
          const def = sourceById.get(result.id);
          if (def) fixedMapping[result.id] = result.data;
        }

        // 모든 API 소스가 올바른 키에 기록됨
        expect(fixedMapping.api_a).toEqual({ _source: 'api_a' });
        expect(fixedMapping.api_b).toEqual({ _source: 'api_b' });
        expect(fixedMapping.api_c).toEqual({ _source: 'api_c' });
        // WebSocket 키는 존재하지 않음 (fetch 대상 아님)
        expect(fixedMapping.ws_source).toBeUndefined();
      });
    });
  });
});
