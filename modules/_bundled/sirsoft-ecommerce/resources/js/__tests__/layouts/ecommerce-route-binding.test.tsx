/**
 * 이커머스 라우트 바인딩 테스트
 *
 * 레이아웃 JSON에서 {{_global.shopBase}} 바인딩이 navigate params와
 * href 속성에 올바르게 적용되는지 검증합니다.
 *
 * 관련 이슈: #64 (이커머스 환경설정 > 라우트 (shop) 연동 처리)
 *
 * 참고: init_actions의 shopBase 표현식 평가는 TemplateApp.resolveRouteExpressions
 * 유닛 테스트에서 검증. 이 테스트는 shopBase 값이 설정된 상태에서의 렌더링 검증에 집중.
 */

import React from 'react';
import { describe, it, expect, afterEach, beforeEach } from 'vitest';
import { createLayoutTest, screen, waitFor } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

// 테스트용 컴포넌트
const TestSpan: React.FC<{
  text?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ text, children, 'data-testid': testId }) => (
  <span data-testid={testId}>{children || text}</span>
);

const TestButton: React.FC<{
  text?: string;
  children?: React.ReactNode;
  onClick?: () => void;
  'data-testid'?: string;
}> = ({ text, children, onClick, 'data-testid': testId }) => (
  <button onClick={onClick} data-testid={testId}>
    {children || text}
  </button>
);

const TestA: React.FC<{
  href?: string;
  text?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ href, text, children, 'data-testid': testId }) => (
  <a href={href} data-testid={testId}>
    {children || text}
  </a>
);

const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => (
  <>{children}</>
);

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    A: { component: TestA, metadata: { name: 'A', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

describe('이커머스 라우트 바인딩', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    testUtils?.cleanup();
  });

  describe('_global.shopBase 렌더링', () => {
    it('shopBase="/shop"이 텍스트에 바인딩됨', async () => {
      const layoutJson = {
        version: '1.0.0',
        layout_name: 'shopbase_text_test',
        components: [
          {
            id: 'shop-base-text',
            type: 'basic',
            name: 'Span',
            props: { 'data-testid': 'shop-base-value' },
            text: '{{_global.shopBase}}',
          },
        ],
      };

      testUtils = createLayoutTest(layoutJson, {
        initialState: { _global: { shopBase: '/shop' } },
        componentRegistry: registry,
      });

      await testUtils.render();

      const el = screen.getByTestId('shop-base-value');
      expect(el.textContent).toBe('/shop');
    });

    it('shopBase="/store"이 텍스트에 바인딩됨', async () => {
      const layoutJson = {
        version: '1.0.0',
        layout_name: 'shopbase_store_text_test',
        components: [
          {
            id: 'shop-base-text',
            type: 'basic',
            name: 'Span',
            props: { 'data-testid': 'shop-base-value' },
            text: '{{_global.shopBase}}',
          },
        ],
      };

      testUtils = createLayoutTest(layoutJson, {
        initialState: { _global: { shopBase: '/store' } },
        componentRegistry: registry,
      });

      await testUtils.render();

      const el = screen.getByTestId('shop-base-value');
      expect(el.textContent).toBe('/store');
    });

    it('shopBase="/"이 텍스트에 바인딩됨 (no_route)', async () => {
      const layoutJson = {
        version: '1.0.0',
        layout_name: 'shopbase_root_text_test',
        components: [
          {
            id: 'shop-base-text',
            type: 'basic',
            name: 'Span',
            props: { 'data-testid': 'shop-base-value' },
            text: '{{_global.shopBase}}',
          },
        ],
      };

      testUtils = createLayoutTest(layoutJson, {
        initialState: { _global: { shopBase: '/' } },
        componentRegistry: registry,
      });

      await testUtils.render();

      const el = screen.getByTestId('shop-base-value');
      expect(el.textContent).toBe('/');
    });
  });

  describe('navigate 경로 바인딩', () => {
    it('장바구니 버튼 클릭 시 /shop/cart 으로 navigate', async () => {
      const layoutJson = {
        version: '1.0.0',
        layout_name: 'navigate_cart_test',
        components: [
          {
            id: 'cart-btn',
            type: 'basic',
            name: 'Button',
            props: { 'data-testid': 'cart-btn' },
            text: 'Cart',
            actions: [
              {
                type: 'click',
                handler: 'navigate',
                params: { path: '{{_global.shopBase}}/cart' },
              },
            ],
          },
        ],
      };

      testUtils = createLayoutTest(layoutJson, {
        initialState: { _global: { shopBase: '/shop' } },
        componentRegistry: registry,
      });

      await testUtils.render();

      const cartBtn = screen.getByTestId('cart-btn');
      await testUtils.user.click(cartBtn);

      await waitFor(() => {
        expect(testUtils.getNavigationHistory()).toContain('/shop/cart');
      });
    });

    it('route_path="store"일 때 결제 페이지 navigate → /store/checkout', async () => {
      const layoutJson = {
        version: '1.0.0',
        layout_name: 'navigate_store_checkout_test',
        components: [
          {
            id: 'checkout-btn',
            type: 'basic',
            name: 'Button',
            props: { 'data-testid': 'checkout-btn' },
            text: 'Checkout',
            actions: [
              {
                type: 'click',
                handler: 'navigate',
                params: { path: '{{_global.shopBase}}/checkout' },
              },
            ],
          },
        ],
      };

      testUtils = createLayoutTest(layoutJson, {
        initialState: { _global: { shopBase: '/store' } },
        componentRegistry: registry,
      });

      await testUtils.render();

      const checkoutBtn = screen.getByTestId('checkout-btn');
      await testUtils.user.click(checkoutBtn);

      await waitFor(() => {
        expect(testUtils.getNavigationHistory()).toContain('/store/checkout');
      });
    });

    it('no_route 시 장바구니 navigate → //cart', async () => {
      const layoutJson = {
        version: '1.0.0',
        layout_name: 'navigate_noroute_test',
        components: [
          {
            id: 'cart-btn',
            type: 'basic',
            name: 'Button',
            props: { 'data-testid': 'cart-btn' },
            text: 'Cart',
            actions: [
              {
                type: 'click',
                handler: 'navigate',
                params: { path: '{{_global.shopBase}}/cart' },
              },
            ],
          },
        ],
      };

      testUtils = createLayoutTest(layoutJson, {
        initialState: { _global: { shopBase: '/' } },
        componentRegistry: registry,
      });

      await testUtils.render();

      const cartBtn = screen.getByTestId('cart-btn');
      await testUtils.user.click(cartBtn);

      await waitFor(() => {
        expect(testUtils.getNavigationHistory()).toContain('//cart');
      });
    });

    it('상품 목록 페이지 (shopBase + /products)', async () => {
      const layoutJson = {
        version: '1.0.0',
        layout_name: 'shop_list_link_test',
        components: [
          {
            id: 'shop-link',
            type: 'basic',
            name: 'Button',
            props: { 'data-testid': 'shop-link' },
            text: 'Shop',
            actions: [
              {
                type: 'click',
                handler: 'navigate',
                params: { path: '{{_global.shopBase}}/products' },
              },
            ],
          },
        ],
      };

      testUtils = createLayoutTest(layoutJson, {
        initialState: { _global: { shopBase: '/shop' } },
        componentRegistry: registry,
      });

      await testUtils.render();

      const shopLink = screen.getByTestId('shop-link');
      await testUtils.user.click(shopLink);

      await waitFor(() => {
        expect(testUtils.getNavigationHistory()).toContain('/shop/products');
      });
    });
  });

  describe('href 속성 바인딩', () => {
    it('A 태그 href에 {{_global.shopBase}} 바인딩 적용', async () => {
      const layoutJson = {
        version: '1.0.0',
        layout_name: 'href_binding_test',
        initLocal: {
          item: { slug: 'electronics' },
        },
        components: [
          {
            id: 'cat-link',
            type: 'basic',
            name: 'A',
            props: {
              href: '{{_global.shopBase}}/category/{{_local.item.slug}}',
              'data-testid': 'cat-link',
            },
            text: 'Electronics',
          },
        ],
      };

      testUtils = createLayoutTest(layoutJson, {
        initialState: { _global: { shopBase: '/shop' } },
        componentRegistry: registry,
      });

      await testUtils.render();

      await waitFor(() => {
        const link = screen.getByTestId('cat-link');
        expect(link.getAttribute('href')).toBe('/shop/category/electronics');
      });
    });

    it('route_path="store"일 때 카테고리 링크', async () => {
      const layoutJson = {
        version: '1.0.0',
        layout_name: 'href_store_test',
        initLocal: {
          item: { slug: 'electronics' },
        },
        components: [
          {
            id: 'cat-link',
            type: 'basic',
            name: 'A',
            props: {
              href: '{{_global.shopBase}}/category/{{_local.item.slug}}',
              'data-testid': 'cat-link',
            },
            text: 'Electronics',
          },
        ],
      };

      testUtils = createLayoutTest(layoutJson, {
        initialState: { _global: { shopBase: '/store' } },
        componentRegistry: registry,
      });

      await testUtils.render();

      await waitFor(() => {
        const link = screen.getByTestId('cat-link');
        expect(link.getAttribute('href')).toBe('/store/category/electronics');
      });
    });

    it('상품 상세 페이지 링크 (동적 ID)', async () => {
      const layoutJson = {
        version: '1.0.0',
        layout_name: 'product_detail_link_test',
        initLocal: {
          product: { id: 42 },
        },
        components: [
          {
            id: 'product-link',
            type: 'basic',
            name: 'A',
            props: {
              href: '{{_global.shopBase}}/products/{{_local.product.id}}',
              'data-testid': 'product-link',
            },
            text: 'Product 42',
          },
        ],
      };

      testUtils = createLayoutTest(layoutJson, {
        initialState: { _global: { shopBase: '/store' } },
        componentRegistry: registry,
      });

      await testUtils.render();

      await waitFor(() => {
        const link = screen.getByTestId('product-link');
        expect(link.getAttribute('href')).toBe('/store/products/42');
      });
    });

    it('no_route 시 카테고리 링크', async () => {
      const layoutJson = {
        version: '1.0.0',
        layout_name: 'href_noroute_test',
        initLocal: {
          item: { slug: 'electronics' },
        },
        components: [
          {
            id: 'cat-link',
            type: 'basic',
            name: 'A',
            props: {
              href: '{{_global.shopBase}}/category/{{_local.item.slug}}',
              'data-testid': 'cat-link',
            },
            text: 'Electronics',
          },
        ],
      };

      testUtils = createLayoutTest(layoutJson, {
        initialState: { _global: { shopBase: '/' } },
        componentRegistry: registry,
      });

      await testUtils.render();

      await waitFor(() => {
        const link = screen.getByTestId('cat-link');
        expect(link.getAttribute('href')).toBe('//category/electronics');
      });
    });
  });
});
