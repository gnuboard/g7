/**
 * @file board-deleted-toggle.test.tsx
 * @description 게시판 목록/상세 - 삭제 글/댓글 포함 토글 UI 렌더링 회귀 테스트
 *
 * 검증 항목:
 * 1. can_view_deleted = true → 토글 버튼 렌더링
 * 2. can_view_deleted = false → 토글 버튼 미렌더링
 * 3. can_manage = true → 댓글 섹션 토글 버튼 렌더링 (헤더 오른쪽)
 * 4. can_manage = false → 댓글 섹션 토글 버튼 미렌더링
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import { createLayoutTest, screen } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

// ============================================================
// 테스트용 컴포넌트 정의
// ============================================================

const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>
    {children}
  </div>
);

const TestButton: React.FC<{
  type?: string;
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
  onClick?: () => void;
}> = ({ type, className, children, 'data-testid': testId, onClick }) => (
  <button type={type as any} className={className} data-testid={testId} onClick={onClick}>
    {children}
  </button>
);

const TestSpan: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
  'data-testid'?: string;
}> = ({ className, children, text, 'data-testid': testId }) => (
  <span className={className} data-testid={testId}>
    {children || text}
  </span>
);

const TestH3: React.FC<{
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
  <h3 className={className} data-testid={testId}>
    {children}
  </h3>
);

// ============================================================
// 컴포넌트 레지스트리 설정
// ============================================================

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const Fragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;
  (registry as any).registry = {
    Fragment: { component: Fragment, metadata: { name: 'Fragment', type: 'layout' } },
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
  };
  return registry;
}

// ============================================================
// 테스트용 레이아웃 JSON (토글 블록만 추출)
// ============================================================

/** 게시글 목록 토글 — can_view_deleted 조건부 렌더링 */
const postToggleLayoutJson = {
  version: '1.0.0',
  layout_name: 'board_post_deleted_toggle_test',
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
      comment: '삭제된 게시글 포함 토글 (manager 권한 보유 시에만 표시)',
      type: 'basic',
      name: 'Button',
      if: '{{posts?.data?.abilities?.can_view_deleted}}',
      props: {
        type: 'button',
        'data-testid': 'include-deleted-toggle',
        className: 'inline-flex items-center gap-1.5 px-3 py-2 border border-gray-300 rounded-lg text-sm',
      },
      children: [
        {
          type: 'basic',
          name: 'Span',
          text: 'Include deleted posts',
        },
      ],
    },
  ],
};

/** 댓글 섹션 헤더 — 오른쪽에 토글 버튼 배치 */
const commentToggleLayoutJson = {
  version: '1.0.0',
  layout_name: 'board_comment_deleted_toggle_test',
  data_sources: [
    {
      id: 'post',
      type: 'api',
      endpoint: '/api/modules/sirsoft-board/boards/test/posts/1',
      method: 'GET',
      auto_fetch: true,
    },
  ],
  components: [
    {
      type: 'basic',
      name: 'H3',
      props: {
        'data-testid': 'comment-header',
        className: 'flex items-center justify-between px-6 pt-6 pb-4',
      },
      children: [
        {
          type: 'basic',
          name: 'Div',
          props: { className: 'flex items-center gap-2' },
          children: [
            {
              type: 'basic',
              name: 'Span',
              text: 'Comments',
            },
          ],
        },
        {
          comment: '삭제된 댓글 포함 토글 (manager 권한 보유 시에만 표시)',
          type: 'basic',
          name: 'Button',
          if: '{{post?.data?.abilities?.can_manage}}',
          props: {
            type: 'button',
            'data-testid': 'include-deleted-comments-toggle',
            className: 'inline-flex items-center gap-1.5 px-3 py-2 border border-gray-300 rounded-lg text-sm',
          },
          children: [
            {
              type: 'basic',
              name: 'Span',
              text: 'Include deleted comments',
            },
          ],
        },
      ],
    },
  ],
};

// ============================================================
// 테스트
// ============================================================

describe('게시판 삭제 글 포함 토글 UI 렌더링', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  it('can_view_deleted = true → 게시글 토글 버튼 렌더링', async () => {
    const testUtils = createLayoutTest(postToggleLayoutJson);
    testUtils.mockApi('posts', {
      response: {
        data: {
          abilities: { can_view_deleted: true },
        },
      },
    });
    await testUtils.render();

    expect(screen.getByTestId('include-deleted-toggle')).toBeInTheDocument();

    testUtils.cleanup();
  });

  it('can_view_deleted = false → 게시글 토글 버튼 미렌더링', async () => {
    const testUtils = createLayoutTest(postToggleLayoutJson);
    testUtils.mockApi('posts', {
      response: {
        data: {
          abilities: { can_view_deleted: false },
        },
      },
    });
    await testUtils.render();

    expect(screen.queryByTestId('include-deleted-toggle')).not.toBeInTheDocument();

    testUtils.cleanup();
  });

  it('can_view_deleted 없음 → 게시글 토글 미렌더링', async () => {
    const testUtils = createLayoutTest(postToggleLayoutJson);
    testUtils.mockApi('posts', {
      response: {
        data: {
          abilities: {},
        },
      },
    });
    await testUtils.render();

    expect(screen.queryByTestId('include-deleted-toggle')).not.toBeInTheDocument();

    testUtils.cleanup();
  });
});

describe('게시판 삭제 댓글 포함 토글 UI 렌더링', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  it('can_manage = true → 댓글 토글 버튼이 헤더 오른쪽에 렌더링', async () => {
    const testUtils = createLayoutTest(commentToggleLayoutJson);
    testUtils.mockApi('post', {
      response: {
        data: {
          abilities: { can_manage: true },
          comments: [],
        },
      },
    });
    await testUtils.render();

    const header = screen.getByTestId('comment-header');
    const toggleBtn = screen.getByTestId('include-deleted-comments-toggle');

    expect(toggleBtn).toBeInTheDocument();
    expect(header).toContainElement(toggleBtn);

    testUtils.cleanup();
  });

  it('can_manage = false → 댓글 토글 미렌더링', async () => {
    const testUtils = createLayoutTest(commentToggleLayoutJson);
    testUtils.mockApi('post', {
      response: {
        data: {
          abilities: { can_manage: false },
          comments: [],
        },
      },
    });
    await testUtils.render();

    expect(screen.queryByTestId('include-deleted-comments-toggle')).not.toBeInTheDocument();

    testUtils.cleanup();
  });

  it('can_manage 없음 → 댓글 토글 미렌더링', async () => {
    const testUtils = createLayoutTest(commentToggleLayoutJson);
    testUtils.mockApi('post', {
      response: {
        data: {
          abilities: {},
          comments: [],
        },
      },
    });
    await testUtils.render();

    expect(screen.queryByTestId('include-deleted-comments-toggle')).not.toBeInTheDocument();

    testUtils.cleanup();
  });
});
