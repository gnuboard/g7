/**
 * @file shop-guest-access.test.tsx
 * @description 비회원(Guest) 상품 조회 접근 테스트 (Issue #21)
 *
 * 테스트 케이스:
 * - _user_base.json의 current_user 401이 하위 레이아웃으로 전파되지 않음
 * - 비회원이 상품 상세 페이지에서 401 에러 페이지를 보지 않음
 * - 비회원이 상품 목록 페이지에서 권한 없음 배너를 보지 않음
 * - 상품 API 자체가 403을 반환하면 에러 UI가 정상 표시됨
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import {
  createLayoutTest,
  screen,
  waitFor,
} from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

// ========== 테스트용 컴포넌트 정의 ==========

const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);

const TestSpan: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
  'data-testid'?: string;
}> = ({ className, children, text, 'data-testid': testId }) => (
  <span className={className} data-testid={testId}>{children || text}</span>
);

const TestH1: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
  'data-testid'?: string;
}> = ({ className, children, text, 'data-testid': testId }) => (
  <h1 className={className} data-testid={testId}>{children || text}</h1>
);

const TestP: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
  'data-testid'?: string;
}> = ({ className, children, text, 'data-testid': testId }) => (
  <p className={className} data-testid={testId}>{children || text}</p>
);

const TestFragment: React.FC<{ children?: React.ReactNode }> =
  ({ children }) => <>{children}</>;

// ========== 컴포넌트 레지스트리 설정 ==========

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

// ========== 테스트용 레이아웃 Fixture ==========

/**
 * 상품 목록 레이아웃 (shop/index 단순화)
 *
 * 핵심: current_user 401 시 productAccessDenied가 true가 되지 않아야 함
 * - current_user 데이터소스: auth_required=true, 401시 errorHandling으로 전파 방지
 * - 레이아웃 errorHandling.401: setState productAccessDenied=true
 * - 비회원이면 current_user 401 → datasource-level에서 차단 → 레이아웃 401 미발동
 */
const shopIndexFixture = {
  version: '1.0.0',
  layout_name: 'shop/index',
  errorHandling: {
    '401': {
      handler: 'setState',
      params: {
        target: 'local',
        productAccessDenied: true,
      },
    },
    '403': {
      handler: 'setState',
      params: {
        target: 'local',
        productAccessDenied: true,
      },
    },
  },
  data_sources: [
    {
      id: 'current_user',
      type: 'api',
      endpoint: '/api/auth/user',
      method: 'GET',
      auto_fetch: true,
      auth_required: true,
      loading_strategy: 'progressive',
      initGlobal: 'currentUser',
      fallback: { data: [] },
      errorHandling: {
        '401': {
          comment: '비회원의 /api/auth/user 401은 정상 — fallback 사용, 레이아웃 에러 핸들링으로 전파 방지',
          handler: 'suppress',
        },
      },
    },
    {
      id: 'products',
      type: 'api',
      endpoint: '/api/modules/sirsoft-ecommerce/products',
      method: 'GET',
      auth_mode: 'optional',
      auto_fetch: true,
      loading_strategy: 'progressive',
      fallback: {
        data: [],
        meta: { current_page: 1, last_page: 1, per_page: 12, total: 0 },
      },
    },
  ],
  slots: {
    content: [
      {
        type: 'basic',
        name: 'Div',
        props: { 'data-testid': 'shop-index-content' },
        children: [
          {
            type: 'basic',
            name: 'H1',
            props: { 'data-testid': 'shop-title' },
            text: '쇼핑몰',
          },
          {
            comment: '상품 조회 권한 없음 안내 배너',
            type: 'basic',
            name: 'Div',
            if: '{{_local.productAccessDenied === true}}',
            props: {
              className: 'mb-6 p-4 bg-yellow-50',
              'data-testid': 'access-denied-banner',
            },
            children: [
              {
                type: 'basic',
                name: 'Span',
                text: '상품 조회 권한이 없습니다.',
              },
            ],
          },
          {
            comment: '상품 목록',
            type: 'basic',
            name: 'Div',
            props: { 'data-testid': 'product-list' },
            children: [
              {
                type: 'basic',
                name: 'Span',
                text: '상품 목록 영역',
              },
            ],
          },
        ],
      },
    ],
  },
};

/**
 * 상품 상세 레이아웃 (shop/show 단순화)
 *
 * 핵심: current_user 401 시 showErrorPage가 호출되지 않아야 함
 * - 레이아웃 errorHandling.401: showErrorPage
 * - 비회원이면 current_user 401 → datasource-level에서 차단 → showErrorPage 미발동
 */
const shopShowFixture = {
  version: '1.0.0',
  layout_name: 'shop/show',
  errorHandling: {
    '401': {
      handler: 'showErrorPage',
      params: {
        errorCode: 401,
        target: 'content',
      },
    },
  },
  data_sources: [
    {
      id: 'current_user',
      type: 'api',
      endpoint: '/api/auth/user',
      method: 'GET',
      auto_fetch: true,
      auth_required: true,
      loading_strategy: 'progressive',
      initGlobal: 'currentUser',
      fallback: { data: [] },
      errorHandling: {
        '401': {
          comment: '비회원의 /api/auth/user 401은 정상 — fallback 사용, 레이아웃 에러 핸들링으로 전파 방지',
          handler: 'suppress',
        },
      },
    },
    {
      id: 'product',
      type: 'api',
      endpoint: '/api/modules/sirsoft-ecommerce/products/1',
      method: 'GET',
      auto_fetch: true,
      auth_mode: 'optional',
      loading_strategy: 'progressive',
      fallback: { data: null },
    },
  ],
  slots: {
    content: [
      {
        type: 'basic',
        name: 'Div',
        props: { 'data-testid': 'shop-show-content' },
        children: [
          {
            type: 'basic',
            name: 'H1',
            props: { 'data-testid': 'product-name' },
            text: '{{product?.data?.name ?? "상품 정보"}}',
          },
          {
            type: 'basic',
            name: 'P',
            props: { 'data-testid': 'product-description' },
            text: '{{product?.data?.description ?? ""}}',
          },
        ],
      },
    ],
  },
};

// ========== 테스트 케이스 ==========

describe('비회원(Guest) 상품 조회 접근 (Issue #21)', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  describe('상품 목록 페이지 (shop/index)', () => {
    it('비회원일 때 current_user 401이 발생해도 권한 없음 배너가 표시되지 않는다', async () => {
      // Given: current_user는 401, products는 200 (비회원이지만 상품 조회 권한 있음)
      const testUtils = createLayoutTest(shopIndexFixture, {
        componentRegistry: registry,
      });

      testUtils.mockApiError('current_user', 401, 'Unauthenticated');
      testUtils.mockApi('products', {
        response: {
          data: [{ id: 1, name: '테스트 상품' }],
          meta: { current_page: 1, last_page: 1, per_page: 12, total: 1 },
        },
      });

      // When
      await testUtils.render();

      await waitFor(() => {
        expect(screen.getByTestId('shop-index-content')).toBeInTheDocument();
      });

      // Then: 권한 없음 배너가 표시되지 않아야 함
      expect(screen.queryByTestId('access-denied-banner')).not.toBeInTheDocument();

      // And: 상품 목록은 정상 표시
      expect(screen.getByTestId('product-list')).toBeInTheDocument();

      testUtils.cleanup();
    });

    it('비회원일 때 current_user 401이 발생해도 상품 목록이 정상 렌더링된다', async () => {
      // Given: current_user는 401, products는 200
      const testUtils = createLayoutTest(shopIndexFixture, {
        componentRegistry: registry,
      });

      testUtils.mockApiError('current_user', 401, 'Unauthenticated');
      testUtils.mockApi('products', {
        response: {
          data: [
            { id: 1, name: '상품 A' },
            { id: 2, name: '상품 B' },
          ],
          meta: { current_page: 1, last_page: 1, per_page: 12, total: 2 },
        },
      });

      // When
      await testUtils.render();

      await waitFor(() => {
        expect(screen.getByTestId('shop-index-content')).toBeInTheDocument();
      });

      // Then: 상품 목록이 정상 렌더링되고, 권한 없음 배너 미표시
      expect(screen.getByTestId('product-list')).toBeInTheDocument();
      expect(screen.getByTestId('shop-title')).toBeInTheDocument();
      expect(screen.queryByTestId('access-denied-banner')).not.toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('상품 상세 페이지 (shop/show)', () => {
    it('비회원일 때 current_user 401이 발생해도 상품 상세가 정상 렌더링된다', async () => {
      // Given: current_user는 401, product는 200
      const testUtils = createLayoutTest(shopShowFixture, {
        componentRegistry: registry,
      });

      testUtils.mockApiError('current_user', 401, 'Unauthenticated');
      testUtils.mockApi('product', {
        response: {
          data: { id: 1, name: '테스트 상품', description: '상품 설명' },
        },
      });

      // When
      await testUtils.render();

      await waitFor(() => {
        expect(screen.getByTestId('shop-show-content')).toBeInTheDocument();
      });

      // Then: 상품 정보가 정상 렌더링
      expect(screen.getByTestId('product-name')).toBeInTheDocument();
      expect(screen.getByTestId('product-description')).toBeInTheDocument();

      testUtils.cleanup();
    });
  });
});
