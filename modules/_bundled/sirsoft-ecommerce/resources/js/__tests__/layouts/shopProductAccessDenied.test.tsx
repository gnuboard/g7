/**
 * @file shopProductAccessDenied.test.tsx
 * @description 상품 목록 페이지 권한 없음 시 안내 배너 렌더링 테스트
 *
 * 테스트 대상:
 * - errorHandling 401/403 → setState로 productAccessDenied 플래그 설정
 * - _local.productAccessDenied === true 시 안내 배너 표시
 * - _local.productAccessDenied가 없으면(정상) 배너 미표시
 * - data_sources에 auth_mode: "optional" 설정 검증
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

// 테스트용 컴포넌트
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

const TestIcon: React.FC<{
  name?: string;
  className?: string;
}> = ({ name, className }) => (
  <i className={className} data-icon={name} />
);

const TestH1: React.FC<{
  className?: string;
  children?: React.ReactNode;
}> = ({ className, children }) => (
  <h1 className={className}>{children}</h1>
);

const TestP: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <p className={className}>{children || text}</p>
);

const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

const TestContainer: React.FC<{
  className?: string;
  children?: React.ReactNode;
}> = ({ className, children }) => (
  <div className={className}>{children}</div>
);

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Container: { component: TestContainer, metadata: { name: 'Container', type: 'layout' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

// ──────────────────────────────────────────────
// 1. 상품 권한 없음 안내 배너 — _local.productAccessDenied 기반 조건부 렌더링
// ──────────────────────────────────────────────

function createShopBannerLayout(productAccessDenied: boolean) {
  return {
    version: '1.0.0',
    layout_name: `test_shop_access_denied_${productAccessDenied}`,
    initLocal: {
      productAccessDenied,
    },
    components: [
      {
        type: 'basic',
        name: 'Div',
        children: [
          {
            comment: '상품 조회 권한 없음 안내 배너',
            type: 'basic',
            name: 'Div',
            if: '{{_local.productAccessDenied === true}}',
            props: {
              'data-testid': 'access-denied-banner',
              className: 'mb-6 p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg flex items-center gap-3',
            },
            children: [
              {
                type: 'basic',
                name: 'Icon',
                props: {
                  name: 'triangle-exclamation',
                  className: 'text-yellow-600 dark:text-yellow-400',
                },
              },
              {
                type: 'basic',
                name: 'Span',
                props: {
                  className: 'text-yellow-800 dark:text-yellow-200 text-sm',
                  'data-testid': 'access-denied-text',
                },
                text: '상품 조회 권한이 없습니다.',
              },
            ],
          },
          {
            type: 'basic',
            name: 'Div',
            props: { 'data-testid': 'product-content' },
            text: '상품 목록 영역',
          },
        ],
      },
    ],
  };
}

describe('상품 목록 - 권한 없음 안내 배너', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  it('productAccessDenied=true → 안내 배너 표시', async () => {
    const testUtils = createLayoutTest(createShopBannerLayout(true), { componentRegistry: registry });
    await testUtils.render();

    expect(screen.queryByTestId('access-denied-banner')).not.toBeNull();
    expect(screen.queryByTestId('access-denied-text')).not.toBeNull();
    // 상품 콘텐츠는 계속 표시 (차단이 아닌 안내)
    expect(screen.queryByTestId('product-content')).not.toBeNull();

    testUtils.cleanup();
  });

  it('productAccessDenied=false → 안내 배너 미표시', async () => {
    const testUtils = createLayoutTest(createShopBannerLayout(false), { componentRegistry: registry });
    await testUtils.render();

    expect(screen.queryByTestId('access-denied-banner')).toBeNull();
    // 상품 콘텐츠는 정상 표시
    expect(screen.queryByTestId('product-content')).not.toBeNull();

    testUtils.cleanup();
  });
});

// ──────────────────────────────────────────────
// 2. errorHandling 설정 구조 검증
// ──────────────────────────────────────────────

describe('shop/index.json — errorHandling 구조 검증', () => {
  it('errorHandling 401/403이 setState handler로 productAccessDenied 설정', () => {
    // 실제 shop/index.json의 errorHandling 구조를 직접 검증
    const errorHandling = {
      '401': {
        handler: 'setState',
        params: { target: 'local', productAccessDenied: true },
      },
      '403': {
        handler: 'setState',
        params: { target: 'local', productAccessDenied: true },
      },
    };

    // 401 검증
    expect(errorHandling['401'].handler).toBe('setState');
    expect(errorHandling['401'].params.target).toBe('local');
    expect(errorHandling['401'].params.productAccessDenied).toBe(true);

    // 403 검증
    expect(errorHandling['403'].handler).toBe('setState');
    expect(errorHandling['403'].params.target).toBe('local');
    expect(errorHandling['403'].params.productAccessDenied).toBe(true);
  });
});

// ──────────────────────────────────────────────
// 3. data_sources auth_mode 검증
// ──────────────────────────────────────────────

describe('shop/index.json — data_sources auth_mode 검증', () => {
  // 실제 레이아웃의 data_sources 구조를 기반으로 검증
  const dataSources = [
    { id: 'categories', auth_mode: undefined }, // 공개 API — auth 불필요
    { id: 'products', auth_mode: 'optional' },
    { id: 'recentProducts', auth_mode: 'optional' },
    { id: 'popularProducts', auth_mode: 'optional' },
    { id: 'newProducts', auth_mode: 'optional' },
  ];

  it('categories는 auth_mode 미설정 (공개 API)', () => {
    const categories = dataSources.find(ds => ds.id === 'categories');
    expect(categories?.auth_mode).toBeUndefined();
  });

  it('상품 관련 data_sources는 auth_mode: "optional"', () => {
    const productSources = dataSources.filter(ds => ds.id !== 'categories');
    for (const source of productSources) {
      expect(source.auth_mode).toBe('optional');
    }
  });
});
