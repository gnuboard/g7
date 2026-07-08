/**
 * @file admin-cross-navigation.test.tsx
 * @description 유저 화면 → 관리자 화면 크로스링크 노출 게이트 회귀 테스트 (이슈 #450)
 *
 * 검증 항목:
 * 1. 게시판: can_access_admin = true  → 관리자 진입 링크 2개(게시물 조회 + 게시판 관리) 렌더
 * 2. 게시판: can_access_admin = false → 관리자 진입 링크 미렌더
 * 3. 상품:   can_update = true        → 관리자 상품 수정 링크 렌더 + href 검증
 * 4. 상품:   can_update = false       → 관리자 상품 수정 링크 미렌더
 * 5. 게이트는 실제 파티셜 JSON(_admin_links.json / _admin_edit_link.json)의 if 표현식과 일치
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import { createLayoutTest, screen } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

// 실제 파티셜 JSON — 게이트 표현식/href 회귀 고정
import adminLinks from '../../../layouts/partials/board/index/_admin_links.json';
import adminEditLink from '../../../layouts/partials/shop/detail/_admin_edit_link.json';

// ============================================================
// 테스트용 컴포넌트
// ============================================================

const TestDiv: React.FC<{ className?: string; children?: React.ReactNode; 'data-testid'?: string }> = ({
  className,
  children,
  'data-testid': testId,
}) => (
  <div className={className} data-testid={testId}>
    {children}
  </div>
);

const TestA: React.FC<{
  href?: string;
  target?: string;
  rel?: string;
  title?: string;
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ href, target, rel, title, className, children, 'data-testid': testId }) => (
  <a href={href} target={target} rel={rel} title={title} className={className} data-testid={testId}>
    {children}
  </a>
);

const TestSpan: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> = ({
  className,
  children,
  text,
}) => <span className={className}>{children || text}</span>;

const TestIcon: React.FC<{ name?: string }> = ({ name }) => <i data-icon={name} />;

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const Fragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;
  (registry as unknown as { registry: Record<string, unknown> }).registry = {
    Fragment: { component: Fragment, metadata: { name: 'Fragment', type: 'layout' } },
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    A: { component: TestA, metadata: { name: 'A', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
  };
  return registry;
}

// ============================================================
// 게시판 admin-links 게이트 (can_access_admin)
// ============================================================

const boardAdminLinksLayout = {
  version: '1.0.0',
  layout_name: 'board_admin_links_test',
  data_sources: [
    {
      id: 'posts',
      type: 'api',
      endpoint: '/api/modules/sirsoft-board/boards/test/posts',
      method: 'GET',
      auto_fetch: true,
    },
  ],
  components: [
    {
      type: 'basic',
      name: 'A',
      if: '{{posts?.data?.abilities?.can_access_admin}}',
      props: {
        'data-testid': 'board-admin-posts-link',
        href: '/admin/board/{{route.slug}}',
        target: '_blank',
      },
      children: [{ type: 'basic', name: 'Span', text: 'Admin posts' }],
    },
    {
      type: 'basic',
      name: 'A',
      if: '{{posts?.data?.abilities?.can_access_admin}}',
      props: {
        'data-testid': 'board-admin-manage-link',
        href: '/admin/boards/{{route.slug}}/edit',
        target: '_blank',
      },
      children: [{ type: 'basic', name: 'Span', text: 'Board settings' }],
    },
  ],
};

// ============================================================
// 상품 admin edit 게이트 (can_update)
// ============================================================

const productAdminEditLayout = {
  version: '1.0.0',
  layout_name: 'product_admin_edit_test',
  data_sources: [
    {
      id: 'product',
      type: 'api',
      endpoint: '/api/modules/sirsoft-ecommerce/products/1',
      method: 'GET',
      auto_fetch: true,
    },
  ],
  components: [
    {
      type: 'basic',
      name: 'A',
      if: '{{product.data?.abilities?.can_update}}',
      props: {
        'data-testid': 'product-admin-edit-link',
        href: '/admin/ecommerce/products/{{product.data?.product_code ?? route.product_code}}/edit',
        target: '_blank',
      },
      children: [{ type: 'basic', name: 'Span', text: 'Edit in admin' }],
    },
  ],
};

// ============================================================
// 테스트
// ============================================================

describe('이슈 #450 — 유저→관리자 크로스링크 게이트', () => {
  beforeEach(() => {
    setupTestRegistry();
  });

  describe('게시판 관리자 진입 링크 (can_access_admin)', () => {
    it('can_access_admin = true → 게시물 조회 + 게시판 관리 링크 2개 렌더', async () => {
      const testUtils = createLayoutTest(boardAdminLinksLayout, { routeParams: { slug: 'free' } });
      testUtils.mockApi('posts', {
        response: { data: { abilities: { can_access_admin: true } } },
      });
      await testUtils.render();

      expect(screen.getByTestId('board-admin-posts-link')).toBeInTheDocument();
      expect(screen.getByTestId('board-admin-manage-link')).toBeInTheDocument();
      // "게시판 관리" 는 해당 게시판의 개별 설정 화면(/admin/boards/:slug/edit)으로 이동
      expect(screen.getByTestId('board-admin-manage-link').getAttribute('href')).toBe('/admin/boards/free/edit');

      testUtils.cleanup();
    });

    it('can_access_admin = false → 관리자 진입 링크 미렌더', async () => {
      const testUtils = createLayoutTest(boardAdminLinksLayout);
      testUtils.mockApi('posts', {
        response: { data: { abilities: { can_access_admin: false } } },
      });
      await testUtils.render();

      expect(screen.queryByTestId('board-admin-posts-link')).not.toBeInTheDocument();
      expect(screen.queryByTestId('board-admin-manage-link')).not.toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('상품 관리자 수정 링크 (can_update)', () => {
    it('can_update = true → 관리자 상품 수정 링크 렌더', async () => {
      const testUtils = createLayoutTest(productAdminEditLayout);
      testUtils.mockApi('product', {
        response: { data: { id: 42, product_code: 'KOWZBDA045RJQHDJ', abilities: { can_update: true } } },
      });
      await testUtils.render();

      const link = screen.getByTestId('product-admin-edit-link');
      expect(link).toBeInTheDocument();
      // 관리자 편집 링크는 product_code 기준
      expect(link.getAttribute('href')).toBe('/admin/ecommerce/products/KOWZBDA045RJQHDJ/edit');
      expect(link.getAttribute('target')).toBe('_blank');

      testUtils.cleanup();
    });

    it('can_update = false → 관리자 상품 수정 링크 미렌더', async () => {
      const testUtils = createLayoutTest(productAdminEditLayout);
      testUtils.mockApi('product', {
        response: { data: { id: 42, product_code: 'KOWZBDA045RJQHDJ', abilities: { can_update: false } } },
      });
      await testUtils.render();

      expect(screen.queryByTestId('product-admin-edit-link')).not.toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  // ============================================================
  // 실제 파티셜 JSON 게이트/속성 회귀 고정 (구조 분석)
  // ============================================================
  describe('파티셜 JSON 회귀 고정', () => {
    it('_admin_links.json 은 can_access_admin 게이트를 사용한다', () => {
      expect((adminLinks as { if?: string }).if).toBe('{{posts?.data?.abilities?.can_access_admin}}');
    });

    it('_admin_links.json 은 관리자 게시물 조회 + 게시판 관리 링크를 새 탭으로 연다', () => {
      const dump = JSON.stringify(adminLinks);
      expect(dump).toContain('/admin/board/{{route.slug}}');
      // "게시판 관리" 는 개별 게시판 설정 화면(:slug/edit)으로 이동
      expect(dump).toContain('/admin/boards/{{route.slug}}/edit');
      expect(dump).toContain('"target":"_blank"');
    });

    it('_admin_edit_link.json 은 can_update 게이트 + 새 탭 + 상품 수정 경로를 사용한다', () => {
      expect((adminEditLink as { if?: string }).if).toBe('{{product.data?.abilities?.can_update}}');
      const dump = JSON.stringify(adminEditLink);
      // 상품 관리자 편집 링크는 product_code 기준
      expect(dump).toContain('/admin/ecommerce/products/{{product.data?.product_code ?? route.product_code}}/edit');
      expect(dump).toContain('"target":"_blank"');
    });
  });
});
