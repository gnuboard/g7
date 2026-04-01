/**
 * @file page-show.test.tsx
 * @description 페이지 상세 레이아웃 렌더링 테스트 (Issue #99)
 *
 * 테스트 케이스:
 * - 기본 렌더링: 페이지 데이터 있을 때 제목/본문/첨부파일 영역 확인
 * - 조건부 렌더링: 데이터 없을 때 not_found 영역 표시, 데이터 있을 때 back_button 표시
 * - 첨부파일: 없을 때 섹션 미표시, 있을 때 섹션 표시
 * - 상태 배지: draft/archived 상태에 따른 배지 조건부 렌더링
 * - 관리자 편집 버튼: 관리자만 표시
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import {
  createLayoutTest,
  screen,
  waitFor,
} from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

// ========== 테스트용 컴포넌트 정의 ==========

const TestDiv: React.FC<{ className?: string; children?: React.ReactNode; 'data-testid'?: string }> =
  ({ className, children, 'data-testid': testId }) => <div className={className} data-testid={testId}>{children}</div>;

const TestSpan: React.FC<{ className?: string; children?: React.ReactNode; text?: string; 'data-testid'?: string }> =
  ({ className, children, text, 'data-testid': testId }) => <span className={className} data-testid={testId}>{children ?? text}</span>;

const TestH1: React.FC<{ className?: string; children?: React.ReactNode; text?: string; 'data-testid'?: string }> =
  ({ className, children, text, 'data-testid': testId }) => <h1 className={className} data-testid={testId}>{children ?? text}</h1>;

const TestH2: React.FC<{ className?: string; children?: React.ReactNode; text?: string; 'data-testid'?: string }> =
  ({ className, children, text, 'data-testid': testId }) => <h2 className={className} data-testid={testId}>{children ?? text}</h2>;

const TestP: React.FC<{ className?: string; children?: React.ReactNode; text?: string; 'data-testid'?: string }> =
  ({ className, children, text, 'data-testid': testId }) => <p className={className} data-testid={testId}>{children ?? text}</p>;

const TestA: React.FC<{ href?: string; className?: string; children?: React.ReactNode; 'data-testid'?: string }> =
  ({ href, className, children, 'data-testid': testId }) => <a href={href} className={className} data-testid={testId}>{children}</a>;

const TestButton: React.FC<{ type?: string; className?: string; children?: React.ReactNode; text?: string; onClick?: () => void; 'data-testid'?: string }> =
  ({ type, className, children, text, onClick, 'data-testid': testId }) => (
    <button type={type as 'button' | 'submit'} className={className} onClick={onClick} data-testid={testId}>{children ?? text}</button>
  );

const TestIcon: React.FC<{ name?: string; className?: string; 'data-testid'?: string }> =
  ({ name, 'data-testid': testId }) => <i data-testid={testId} data-icon={name} />;

const TestHtmlContent: React.FC<{ content?: string; className?: string; isHtml?: boolean; 'data-testid'?: string }> =
  ({ content, 'data-testid': testId }) => <div data-testid={testId} dangerouslySetInnerHTML={{ __html: content ?? '' }} />;

const TestContainer: React.FC<{ className?: string; children?: React.ReactNode }> =
  ({ className, children }) => <div className={className}>{children}</div>;

const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

// ========== 레이아웃 Fixture ==========

/**
 * page/show.json의 핵심 구조 (data_sources 포함)
 */
const pageShowFixture = {
  version: '1.0.0',
  layout_name: 'page/show',
  state: {},
  data_sources: [
    {
      id: 'page',
      type: 'api',
      endpoint: '/api/pages/terms',
      method: 'GET',
      auto_fetch: true,
      auth_mode: 'optional',
      loading_strategy: 'blocking',
      fallback: { data: null },
    },
  ],
  components: [
    {
      id: 'page_content_card',
      type: 'basic',
      name: 'Div',
      if: '{{page?.data}}',
      props: { 'data-testid': 'page-content-card' },
      children: [
        {
          type: 'basic',
          name: 'H1',
          props: { 'data-testid': 'page-title' },
          text: '{{page?.data?.title ?? ""}}',
        },
        {
          type: 'basic',
          name: 'Span',
          if: '{{page?.data?.status === "draft"}}',
          props: { 'data-testid': 'badge-draft' },
          text: 'status_draft',
        },
        {
          type: 'basic',
          name: 'Span',
          if: '{{page?.data?.status === "archived"}}',
          props: { 'data-testid': 'badge-archived' },
          text: 'status_archived',
        },
        {
          id: 'page_admin_edit_button',
          type: 'basic',
          name: 'A',
          if: '{{_global.currentUser?.is_admin}}',
          props: { 'data-testid': 'admin-edit-btn', href: '/admin/pages/{{page?.data?.id}}/edit' },
          children: [{ type: 'basic', name: 'Span', text: 'admin_edit' }],
        },
        {
          type: 'composite',
          name: 'HtmlContent',
          props: {
            'data-testid': 'page-html-content',
            content: '{{page?.data?.content ?? ""}}',
          },
        },
        {
          id: 'page_attachments_section',
          type: 'basic',
          name: 'Div',
          if: '{{(page?.data?.attachments ?? []).length > 0}}',
          props: { 'data-testid': 'attachments-section' },
          children: [
            {
              type: 'basic',
              name: 'Span',
              text: 'attachments',
            },
          ],
        },
      ],
    },
    {
      id: 'page_not_found',
      type: 'basic',
      name: 'Div',
      if: '{{!page?.data && !page?.loading}}',
      props: { 'data-testid': 'page-not-found' },
      children: [
        { type: 'basic', name: 'H2', text: 'not_found_title', props: { 'data-testid': 'not-found-title' } },
      ],
    },
    {
      id: 'page_back_button',
      type: 'basic',
      name: 'Div',
      if: '{{page?.data}}',
      props: { 'data-testid': 'back-button-area' },
      children: [
        {
          type: 'basic',
          name: 'Button',
          props: { type: 'button', 'data-testid': 'back-button' },
          children: [{ type: 'basic', name: 'Span', text: 'back_to_home' }],
        },
      ],
    },
  ],
};

// ========== 컴포넌트 레지스트리 설정 ==========

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
    H2: { component: TestH2, metadata: { name: 'H2', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    A: { component: TestA, metadata: { name: 'A', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    HtmlContent: { component: TestHtmlContent, metadata: { name: 'HtmlContent', type: 'composite' } },
    Container: { component: TestContainer, metadata: { name: 'Container', type: 'layout' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

// ========== 헬퍼 ==========

/**
 * mockApi URL 추출 규칙:
 * endpoint "/api/pages/terms" → parts[2] = "terms" → dataSourceId = "terms"
 * createMockResponse(response)가 { success: true, data: response } 로 래핑됨
 * 언래핑 후 page = response 이므로,
 * 레이아웃에서 page?.data?.title 접근하려면 response = { data: { id, title, ... } } 형태로 설정
 */
function makePageApiResponse(overrides: Record<string, unknown> = {}) {
  return {
    response: {
      data: {
        id: 1,
        title: '이용약관',
        content: '<p>내용</p>',
        status: 'active',
        attachments: [],
        version: 1,
        ...overrides,
      },
    },
  };
}

// ========== 테스트 케이스 ==========

describe('page/show.json 레이아웃 렌더링 (Issue #99)', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  describe('기본 렌더링 - 페이지 데이터 있음', () => {
    it('페이지 데이터가 있으면 콘텐츠 카드가 렌더링된다', async () => {
      const testUtils = createLayoutTest(pageShowFixture, { componentRegistry: registry });
      testUtils.mockApi('terms', makePageApiResponse());

      await testUtils.render();

      await waitFor(() => {
        expect(screen.getByTestId('page-content-card')).toBeInTheDocument();
      });
      expect(screen.queryByTestId('page-not-found')).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('페이지 제목이 렌더링된다', async () => {
      const testUtils = createLayoutTest(pageShowFixture, { componentRegistry: registry });
      testUtils.mockApi('terms', makePageApiResponse({ title: '이용약관' }));

      await testUtils.render();

      await waitFor(() => {
        expect(screen.getByTestId('page-title')).toHaveTextContent('이용약관');
      });

      testUtils.cleanup();
    });

    it('홈으로 돌아가기 버튼이 렌더링된다', async () => {
      const testUtils = createLayoutTest(pageShowFixture, { componentRegistry: registry });
      testUtils.mockApi('terms', makePageApiResponse());

      await testUtils.render();

      await waitFor(() => {
        expect(screen.getByTestId('back-button-area')).toBeInTheDocument();
      });

      testUtils.cleanup();
    });
  });

  describe('조건부 렌더링 - 페이지 데이터 없음', () => {
    it('페이지 데이터가 null이면 not_found가 표시된다', async () => {
      const testUtils = createLayoutTest(pageShowFixture, { componentRegistry: registry });
      testUtils.mockApi('terms', { response: { data: null } });

      await testUtils.render();

      await waitFor(() => {
        expect(screen.getByTestId('page-not-found')).toBeInTheDocument();
      });
      expect(screen.queryByTestId('page-content-card')).not.toBeInTheDocument();
      expect(screen.queryByTestId('back-button-area')).not.toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('상태 배지 조건부 렌더링', () => {
    it('active 상태에서는 draft/archived 배지가 표시되지 않는다', async () => {
      const testUtils = createLayoutTest(pageShowFixture, { componentRegistry: registry });
      testUtils.mockApi('terms', makePageApiResponse({ status: 'active' }));

      await testUtils.render();

      await waitFor(() => {
        expect(screen.getByTestId('page-content-card')).toBeInTheDocument();
      });
      expect(screen.queryByTestId('badge-draft')).not.toBeInTheDocument();
      expect(screen.queryByTestId('badge-archived')).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('draft 상태에서는 draft 배지가 표시된다', async () => {
      const testUtils = createLayoutTest(pageShowFixture, { componentRegistry: registry });
      testUtils.mockApi('terms', makePageApiResponse({ status: 'draft' }));

      await testUtils.render();

      await waitFor(() => {
        expect(screen.getByTestId('badge-draft')).toBeInTheDocument();
      });
      expect(screen.queryByTestId('badge-archived')).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('archived 상태에서는 archived 배지가 표시된다', async () => {
      const testUtils = createLayoutTest(pageShowFixture, { componentRegistry: registry });
      testUtils.mockApi('terms', makePageApiResponse({ status: 'archived' }));

      await testUtils.render();

      await waitFor(() => {
        expect(screen.getByTestId('badge-archived')).toBeInTheDocument();
      });
      expect(screen.queryByTestId('badge-draft')).not.toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('첨부파일 섹션', () => {
    it('첨부파일이 없으면 첨부파일 섹션이 표시되지 않는다', async () => {
      const testUtils = createLayoutTest(pageShowFixture, { componentRegistry: registry });
      testUtils.mockApi('terms', makePageApiResponse({ attachments: [] }));

      await testUtils.render();

      await waitFor(() => {
        expect(screen.getByTestId('page-content-card')).toBeInTheDocument();
      });
      expect(screen.queryByTestId('attachments-section')).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('첨부파일이 있으면 첨부파일 섹션이 표시된다', async () => {
      const testUtils = createLayoutTest(pageShowFixture, { componentRegistry: registry });
      testUtils.mockApi('terms', makePageApiResponse({
        attachments: [{ original_filename: 'test.pdf', download_url: '/download/1', is_image: false }],
      }));

      await testUtils.render();

      await waitFor(() => {
        expect(screen.getByTestId('attachments-section')).toBeInTheDocument();
      });

      testUtils.cleanup();
    });
  });

  describe('관리자 편집 버튼', () => {
    it('일반 사용자에게는 편집 버튼이 표시되지 않는다', async () => {
      const testUtils = createLayoutTest(pageShowFixture, {
        componentRegistry: registry,
        initialState: { _global: { currentUser: { is_admin: false } } },
      });
      testUtils.mockApi('terms', makePageApiResponse());

      await testUtils.render();

      await waitFor(() => {
        expect(screen.getByTestId('page-content-card')).toBeInTheDocument();
      });
      expect(screen.queryByTestId('admin-edit-btn')).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('관리자에게는 편집 버튼이 표시된다', async () => {
      const testUtils = createLayoutTest(pageShowFixture, {
        componentRegistry: registry,
        initialState: { _global: { currentUser: { is_admin: true } } },
      });
      testUtils.mockApi('terms', makePageApiResponse({ id: 5 }));

      await testUtils.render();

      await waitFor(() => {
        expect(screen.getByTestId('admin-edit-btn')).toBeInTheDocument();
      });

      testUtils.cleanup();
    });
  });
});
