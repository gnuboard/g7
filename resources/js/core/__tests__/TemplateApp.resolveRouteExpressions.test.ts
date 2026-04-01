/**
 * TemplateApp.resolveRouteExpressions() 테스트
 *
 * routes.json의 path/redirect 내 {{expression}} 표현식을 DataBindingEngine으로
 * 평가하는 기능을 검증합니다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TemplateApp } from '../TemplateApp';
import type { TemplateAppConfig } from '../TemplateApp';
import type { Route } from '../routing/Router';

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
  Router: vi.fn(function (this: any) {
    this.loadRoutes = vi.fn().mockResolvedValue(undefined);
    this.on = vi.fn();
    this.navigateToCurrentPath = vi.fn();
    this.getRoutes = vi.fn().mockReturnValue([]);
    this.setRoutes = vi.fn();
  }),
}));

vi.mock('../template-engine/LayoutLoader', () => ({
  LayoutLoader: vi.fn(function (this: any) {
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

describe('resolveRouteExpressions', () => {
  let app: TemplateApp;
  let resolveRouteExpressions: (routes: Route[]) => Route[];

  const config: TemplateAppConfig = {
    templateId: 'test-template',
    templateType: 'user',
    locale: 'ko',
    debug: false,
  };

  /** routes.json에서 사용하는 이커머스 경로 표현식 */
  const ECOMMERCE_PATH_EXPR =
    "/{{_global.modules?.['sirsoft-ecommerce']?.basic_info?.no_route ? '' : (_global.modules?.['sirsoft-ecommerce']?.basic_info?.route_path ?? 'shop')}}";

  beforeEach(() => {
    // localStorage mock
    const store: Record<string, string> = {};
    Object.defineProperty(global, 'localStorage', {
      value: {
        getItem: vi.fn((key: string) => store[key] ?? null),
        setItem: vi.fn((key: string, val: string) => {
          store[key] = val;
        }),
        removeItem: vi.fn((key: string) => {
          delete store[key];
        }),
      },
      writable: true,
      configurable: true,
    });

    // window.G7Config mock
    (window as any).G7Config = {};

    // DOM 요소 mock
    const appDiv = document.createElement('div');
    appDiv.id = 'app';
    document.body.appendChild(appDiv);

    app = new TemplateApp(config);
    resolveRouteExpressions = (app as any).resolveRouteExpressions.bind(app);
  });

  afterEach(() => {
    const appDiv = document.getElementById('app');
    if (appDiv) document.body.removeChild(appDiv);
    vi.clearAllMocks();
    delete (window as any).G7Config;
  });

  describe('기본 표현식 치환', () => {
    it('route_path가 "shop"일 때 경로를 올바르게 치환', () => {
      (app as any).globalState.modules = {
        'sirsoft-ecommerce': { basic_info: { route_path: 'shop' } },
      };

      const routes: Route[] = [{ path: `${ECOMMERCE_PATH_EXPR}/cart`, layout: 'shop/cart' }];
      const result = resolveRouteExpressions(routes);

      expect(result[0].path).toBe('/shop/cart');
    });

    it('route_path가 "store"일 때 경로를 올바르게 치환', () => {
      (app as any).globalState.modules = {
        'sirsoft-ecommerce': { basic_info: { route_path: 'store' } },
      };

      const routes: Route[] = [{ path: `${ECOMMERCE_PATH_EXPR}/cart`, layout: 'shop/cart' }];
      const result = resolveRouteExpressions(routes);

      expect(result[0].path).toBe('/store/cart');
    });

    it('route_path가 "mall/items"일 때 중첩 경로를 올바르게 치환', () => {
      (app as any).globalState.modules = {
        'sirsoft-ecommerce': { basic_info: { route_path: 'mall/items' } },
      };

      const routes: Route[] = [{ path: `${ECOMMERCE_PATH_EXPR}/cart`, layout: 'shop/cart' }];
      const result = resolveRouteExpressions(routes);

      expect(result[0].path).toBe('/mall/items/cart');
    });

    it('경로 prefix만 있는 경우 (index 페이지 → /products)', () => {
      (app as any).globalState.modules = {
        'sirsoft-ecommerce': { basic_info: { route_path: 'shop' } },
      };

      const routes: Route[] = [{ path: `${ECOMMERCE_PATH_EXPR}/products`, layout: 'shop/index' }];
      const result = resolveRouteExpressions(routes);

      expect(result[0].path).toBe('/shop/products');
    });

    it('라우트 파라미터가 포함된 경로 치환', () => {
      (app as any).globalState.modules = {
        'sirsoft-ecommerce': { basic_info: { route_path: 'shop' } },
      };

      const routes: Route[] = [
        { path: `${ECOMMERCE_PATH_EXPR}/products/:id`, layout: 'shop/show' },
        { path: `${ECOMMERCE_PATH_EXPR}/category/:slug`, layout: 'shop/category' },
        { path: `${ECOMMERCE_PATH_EXPR}/orders/:id/complete`, layout: 'shop/order_complete' },
      ];
      const result = resolveRouteExpressions(routes);

      expect(result[0].path).toBe('/shop/products/:id');
      expect(result[1].path).toBe('/shop/category/:slug');
      expect(result[2].path).toBe('/shop/orders/:id/complete');
    });
  });

  describe('no_route 처리', () => {
    it('no_route=true일 때 빈 prefix (이중 슬래시 자동 정리)', () => {
      (app as any).globalState.modules = {
        'sirsoft-ecommerce': { basic_info: { no_route: true, route_path: 'shop' } },
      };

      const routes: Route[] = [{ path: `${ECOMMERCE_PATH_EXPR}/cart`, layout: 'shop/cart' }];
      const result = resolveRouteExpressions(routes);

      expect(result[0].path).toBe('/cart');
    });

    it('no_route=false일 때 정상 prefix', () => {
      (app as any).globalState.modules = {
        'sirsoft-ecommerce': { basic_info: { no_route: false, route_path: 'shop' } },
      };

      const routes: Route[] = [{ path: `${ECOMMERCE_PATH_EXPR}/cart`, layout: 'shop/cart' }];
      const result = resolveRouteExpressions(routes);

      expect(result[0].path).toBe('/shop/cart');
    });

    it('no_route 미설정(undefined)일 때 정상 prefix', () => {
      (app as any).globalState.modules = {
        'sirsoft-ecommerce': { basic_info: { route_path: 'shop' } },
      };

      const routes: Route[] = [{ path: `${ECOMMERCE_PATH_EXPR}/cart`, layout: 'shop/cart' }];
      const result = resolveRouteExpressions(routes);

      expect(result[0].path).toBe('/shop/cart');
    });

    it('no_route=true + index 경로 → /products로 정리', () => {
      (app as any).globalState.modules = {
        'sirsoft-ecommerce': { basic_info: { no_route: true, route_path: 'shop' } },
      };

      const routes: Route[] = [{ path: `${ECOMMERCE_PATH_EXPR}/products`, layout: 'shop/index' }];
      const result = resolveRouteExpressions(routes);

      expect(result[0].path).toBe('/products');
    });
  });

  describe('이중 슬래시 정리', () => {
    it('빈 값 치환 시 이중 슬래시를 단일 슬래시로 정리', () => {
      // no_route=true → 빈 문자열 → //cart → /cart
      (app as any).globalState.modules = {
        'sirsoft-ecommerce': { basic_info: { no_route: true } },
      };

      const routes: Route[] = [{ path: `${ECOMMERCE_PATH_EXPR}/checkout`, layout: 'shop/checkout' }];
      const result = resolveRouteExpressions(routes);

      expect(result[0].path).toBe('/checkout');
      expect(result[0].path).not.toContain('//');
    });

    it('여러 하위 경로에서도 이중 슬래시 정리', () => {
      (app as any).globalState.modules = {
        'sirsoft-ecommerce': { basic_info: { no_route: true } },
      };

      const routes: Route[] = [
        { path: `${ECOMMERCE_PATH_EXPR}/orders/:id/complete`, layout: 'shop/order_complete' },
      ];
      const result = resolveRouteExpressions(routes);

      expect(result[0].path).toBe('/orders/:id/complete');
    });
  });

  describe('표현식 없는 경로', () => {
    it('일반 정적 경로를 그대로 통과', () => {
      const routes: Route[] = [
        { path: '/about', layout: 'policy/about' },
        { path: '/login', layout: 'auth/login' },
        { path: '/mypage/profile', layout: 'mypage/profile' },
      ];
      const result = resolveRouteExpressions(routes);

      expect(result[0].path).toBe('/about');
      expect(result[1].path).toBe('/login');
      expect(result[2].path).toBe('/mypage/profile');
    });

    it('라우트 파라미터가 포함된 정적 경로를 그대로 통과', () => {
      const routes: Route[] = [
        { path: '/board/:slug', layout: 'board/index' },
        { path: '/board/:slug/:id', layout: 'board/show' },
      ];
      const result = resolveRouteExpressions(routes);

      expect(result[0].path).toBe('/board/:slug');
      expect(result[1].path).toBe('/board/:slug/:id');
    });

    it('다른 route 속성(layout, meta 등)을 변경하지 않음', () => {
      const routes: Route[] = [
        {
          path: `${ECOMMERCE_PATH_EXPR}/cart`,
          layout: 'shop/cart',
          auth_required: false,
          meta: { title: '$t:user.shop.cart_title' },
        },
      ];
      (app as any).globalState.modules = {
        'sirsoft-ecommerce': { basic_info: { route_path: 'shop' } },
      };
      const result = resolveRouteExpressions(routes);

      expect(result[0].layout).toBe('shop/cart');
      expect(result[0].auth_required).toBe(false);
      expect(result[0].meta?.title).toBe('$t:user.shop.cart_title');
    });
  });

  describe('redirect 표현식', () => {
    it('redirect에 표현식이 있으면 치환', () => {
      (app as any).globalState.modules = {
        'sirsoft-ecommerce': { basic_info: { route_path: 'shop' } },
      };

      const routes: Route[] = [
        { path: '/old-shop', redirect: `${ECOMMERCE_PATH_EXPR}` },
      ];
      const result = resolveRouteExpressions(routes);

      expect(result[0].redirect).toBe('/shop');
      expect(result[0].path).toBe('/old-shop');
    });

    it('redirect가 없는 route는 무시', () => {
      (app as any).globalState.modules = {
        'sirsoft-ecommerce': { basic_info: { route_path: 'shop' } },
      };

      const routes: Route[] = [
        { path: `${ECOMMERCE_PATH_EXPR}/cart`, layout: 'shop/cart' },
      ];
      const result = resolveRouteExpressions(routes);

      expect(result[0].redirect).toBeUndefined();
      expect(result[0].path).toBe('/shop/cart');
    });
  });

  describe('플러그인/코어 설정 참조', () => {
    it('플러그인 설정을 참조하여 경로 치환', () => {
      (app as any).globalState.plugins = {
        'sirsoft-payment': { api_mode: 'sandbox' },
      };

      const routes: Route[] = [
        { path: "/pay/{{_global.plugins?.['sirsoft-payment']?.api_mode ?? 'live'}}", layout: 'pay' },
      ];
      const result = resolveRouteExpressions(routes);

      expect(result[0].path).toBe('/pay/sandbox');
    });

    it('코어 설정을 참조하여 경로 치환', () => {
      (app as any).globalState.settings = {
        general: { site_slug: 'mysite' },
      };

      const routes: Route[] = [
        { path: "/{{_global.settings?.general?.site_slug}}/home", layout: 'home' },
      ];
      const result = resolveRouteExpressions(routes);

      expect(result[0].path).toBe('/mysite/home');
    });
  });

  describe('엣지 케이스', () => {
    it('빈 routes 배열을 처리', () => {
      const result = resolveRouteExpressions([]);
      expect(result).toEqual([]);
    });

    it('_global에 modules 키가 없을 때 fallback 기본값 사용', () => {
      // globalState에 modules가 없는 상태
      (app as any).globalState.modules = undefined;

      const routes: Route[] = [
        {
          path: "/{{_global.modules?.['sirsoft-ecommerce']?.basic_info?.route_path ?? 'shop'}}/cart",
          layout: 'shop/cart',
        },
      ];
      const result = resolveRouteExpressions(routes);

      expect(result[0].path).toBe('/shop/cart');
    });

    it('경로에 다중 표현식을 처리', () => {
      (app as any).globalState.settings = { section: 'admin' };
      (app as any).globalState.modules = {
        'sirsoft-ecommerce': { basic_info: { route_path: 'shop' } },
      };

      const routes: Route[] = [
        {
          path: "/{{_global.settings?.section ?? 'user'}}/{{_global.modules?.['sirsoft-ecommerce']?.basic_info?.route_path ?? 'shop'}}",
          layout: 'test',
        },
      ];
      const result = resolveRouteExpressions(routes);

      expect(result[0].path).toBe('/admin/shop');
    });

    it('원본 route 객체를 변경하지 않음 (immutability)', () => {
      (app as any).globalState.modules = {
        'sirsoft-ecommerce': { basic_info: { route_path: 'shop' } },
      };

      const originalRoutes: Route[] = [
        { path: `${ECOMMERCE_PATH_EXPR}/cart`, layout: 'shop/cart' },
      ];
      const originalPath = originalRoutes[0].path;

      resolveRouteExpressions(originalRoutes);

      expect(originalRoutes[0].path).toBe(originalPath);
    });

    it('여러 route를 한꺼번에 처리 (실제 routes.json 시나리오)', () => {
      (app as any).globalState.modules = {
        'sirsoft-ecommerce': { basic_info: { route_path: 'shop' } },
      };

      const routes: Route[] = [
        { path: '/', layout: 'home' },
        { path: '/login', layout: 'auth/login' },
        { path: `${ECOMMERCE_PATH_EXPR}/products`, layout: 'shop/index' },
        { path: `${ECOMMERCE_PATH_EXPR}/category/:slug`, layout: 'shop/category' },
        { path: `${ECOMMERCE_PATH_EXPR}/products/:id`, layout: 'shop/show' },
        { path: `${ECOMMERCE_PATH_EXPR}/cart`, layout: 'shop/cart' },
        { path: `${ECOMMERCE_PATH_EXPR}/checkout`, layout: 'shop/checkout' },
        { path: `${ECOMMERCE_PATH_EXPR}/orders/:id/complete`, layout: 'shop/order_complete' },
        { path: '/mypage/orders', layout: 'mypage/orders' },
      ];
      const result = resolveRouteExpressions(routes);

      expect(result[0].path).toBe('/');
      expect(result[1].path).toBe('/login');
      expect(result[2].path).toBe('/shop/products');
      expect(result[3].path).toBe('/shop/category/:slug');
      expect(result[4].path).toBe('/shop/products/:id');
      expect(result[5].path).toBe('/shop/cart');
      expect(result[6].path).toBe('/shop/checkout');
      expect(result[7].path).toBe('/shop/orders/:id/complete');
      expect(result[8].path).toBe('/mypage/orders');
    });
  });
});
