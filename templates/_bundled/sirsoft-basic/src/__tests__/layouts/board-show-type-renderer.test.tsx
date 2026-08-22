/**
 * @file board-show-type-renderer.test.tsx
 * @description 게시글 상세 - _type_renderer 유형별 분기 렌더링 테스트 (이슈 #153)
 *
 * 검증 항목:
 * 1. basic 유형: 게시글 본문(제목, 작성자, 내용) 렌더링
 * 2. gallery 유형: basic과 동일한 show.json 사용 (현재 동일 구현)
 * 3. card 유형: basic과 동일한 show.json 사용 (현재 동일 구현)
 * 4. 알 수 없는 유형 fallback: basic으로 렌더링
 * 5. 이전글/다음글 네비게이션 버튼 조건부 렌더링
 * 6. 답글 섹션 iteration.source {{ }} 버그 수정 검증 (replies 목록 렌더링)
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
  className?: string;
  children?: React.ReactNode;
  text?: string;
  type?: string;
  onClick?: () => void;
  'data-testid'?: string;
}> = ({ className, children, text, onClick, 'data-testid': testId }) => (
  <button className={className} onClick={onClick} data-testid={testId}>
    {children || text}
  </button>
);

const TestSpan: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <span className={className}>{children || text}</span>
);

const TestH1: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <h1 className={className}>{children || text}</h1>
);

const TestH3: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <h3 className={className}>{children || text}</h3>
);

const TestP: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <p className={className}>{children || text}</p>
);

const TestA: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
  href?: string;
  'data-testid'?: string;
}> = ({ className, children, text, href, 'data-testid': testId }) => (
  <a className={className} href={href} data-testid={testId}>
    {children || text}
  </a>
);

const TestIcon: React.FC<{
  name?: string;
  size?: string;
  className?: string;
}> = ({ name, size, className }) => (
  <i className={className} data-icon={name} data-size={size} />
);

const TestContainer: React.FC<{
  className?: string;
  children?: React.ReactNode;
}> = ({ className, children }) => (
  <div className={className} data-testid="container">
    {children}
  </div>
);

const TestAvatar: React.FC<{
  author?: any;
  size?: string;
}> = ({ author }) => (
  <span data-testid="avatar">{author?.name ?? ''}</span>
);

const TestUserInfo: React.FC<{
  author?: any;
  subText?: string;
  layout?: string;
}> = ({ author, subText }) => (
  <span data-testid="user-info">
    {author?.name ?? ''} {subText}
  </span>
);

const TestHtmlContent: React.FC<{
  content?: string;
  isHtml?: boolean;
  className?: string;
}> = ({ content }) => (
  <div data-testid="html-content">{content}</div>
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
    H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
    H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    A: { component: TestA, metadata: { name: 'A', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'composite' } },
    Container: { component: TestContainer, metadata: { name: 'Container', type: 'layout' } },
    Avatar: { component: TestAvatar, metadata: { name: 'Avatar', type: 'composite' } },
    UserInfo: { component: TestUserInfo, metadata: { name: 'UserInfo', type: 'composite' } },
    HtmlContent: { component: TestHtmlContent, metadata: { name: 'HtmlContent', type: 'composite' } },
  };

  return registry;
}

// ============================================================
// 공통 mock post 데이터
// ============================================================

function makePost(overrides: Record<string, any> = {}) {
  return {
    data: {
      id: 1,
      title: '테스트 게시글',
      content: '게시글 내용입니다.',
      content_mode: 'text',
      status: 'published',
      is_notice: false,
      is_secret: false,
      is_new: false,
      is_author: true,
      is_guest_post: false,
      deleted_at: null,
      view_count: 42,
      comment_count: 3,
      created_at: '2026-03-06',
      author: { name: '홍길동', email: 'hong@example.com' },
      board: {
        name: '자유게시판',
        type: 'basic',
        use_comment: true,
        use_reply: false,
        use_report: false,
        show_view_count: true,
      },
      permissions: {
        posts_write: true,
        comments_read: true,
        manager: false,
      },
      navigation: {
        prev: null,
        next: null,
      },
      replies: [],
      comments: [],
      ...overrides,
    },
  };
}

// ============================================================
// _type_renderer 유형별 분기 테스트용 레이아웃 JSON
// (partial 분리된 내용을 인라인으로 포함)
// ============================================================

function makeShowLayout(boardType: string, postOverrides: Record<string, any> = {}) {
  const postData = makePost({ board: { name: '자유게시판', type: boardType, use_comment: false, use_reply: false, use_report: false, show_view_count: true }, ...postOverrides });

  const layout = {
    version: '1.0.0',
    layout_name: 'board_show_type_renderer_test',
    // data_sources 없음 - initialData로 직접 주입 (static type은 processDataSources에서 미처리)
    components: [
      {
        comment: '_type_renderer 인라인 재현',
        type: 'basic',
        name: 'Div',
        children: [
          {
            comment: 'navigation (인라인)',
            type: 'basic',
            name: 'Div',
            props: { 'data-testid': 'navigation' },
            children: [
              {
                comment: '이전글 버튼',
                type: 'basic',
                name: 'Button',
                if: '{{post?.data?.navigation?.prev?.id}}',
                props: { 'data-testid': 'btn-prev' },
                children: [
                  { type: 'basic', name: 'Span', text: '$t:board.prev' },
                ],
              },
              {
                comment: '다음글 버튼',
                type: 'basic',
                name: 'Button',
                if: '{{post?.data?.navigation?.next?.id}}',
                props: { 'data-testid': 'btn-next' },
                children: [
                  { type: 'basic', name: 'Span', text: '$t:board.next' },
                ],
              },
            ],
          },
          {
            comment: 'basic 유형 (fallback 포함)',
            type: 'basic',
            name: 'Div',
            if: "{{post?.data?.board?.type === 'basic' || !['gallery','card'].includes(post?.data?.board?.type ?? 'basic')}}",
            props: { 'data-testid': 'show-basic' },
            children: [
              {
                type: 'basic',
                name: 'H1',
                text: '{{post?.data?.title ?? ""}}',
                props: { 'data-testid': 'post-title' },
              },
              {
                type: 'composite',
                name: 'HtmlContent',
                if: "{{post?.data?.status !== 'blinded' && post?.data?.content !== null}}",
                props: {
                  content: '{{post?.data?.content ?? ""}}',
                  isHtml: "{{(post?.data?.content_mode ?? 'text') === 'html'}}",
                },
              },
            ],
          },
          {
            comment: 'gallery 유형',
            type: 'basic',
            name: 'Div',
            if: "{{post?.data?.board?.type === 'gallery'}}",
            props: { 'data-testid': 'show-gallery' },
            children: [
              {
                type: 'basic',
                name: 'H1',
                text: '{{post?.data?.title ?? ""}}',
              },
            ],
          },
          {
            comment: 'card 유형',
            type: 'basic',
            name: 'Div',
            if: "{{post?.data?.board?.type === 'card'}}",
            props: { 'data-testid': 'show-card' },
            children: [
              {
                type: 'basic',
                name: 'H1',
                text: '{{post?.data?.title ?? ""}}',
              },
            ],
          },
        ],
      },
    ],
  };

  return { layout, initialData: { post: postData } };
}

// ============================================================
// 답글 iteration 테스트용 레이아웃 JSON
// (iteration.source {{ }} 버그 수정 검증)
// ============================================================

// data_sources: static은 processDataSources에서 처리되지 않으므로 initialData로 주입
const repliesIterationInitialData = {
  post: {
    data: {
      id: 1,
      title: '답글 테스트',
      board: { type: 'basic', use_comment: false },
      permissions: { comments_read: true },
      is_secret: false,
      content: '내용',
      replies: [
        { id: 10, title: '첫 번째 답글', content: '답글 내용 1', is_secret: false, deleted_at: null, author: { name: '답글작성자1' }, created_at: '2026-03-06' },
        { id: 20, title: '두 번째 답글', content: '답글 내용 2', is_secret: false, deleted_at: null, author: { name: '답글작성자2' }, created_at: '2026-03-06' },
      ],
    },
  },
};

const repliesIterationLayout = {
  version: '1.0.0',
  layout_name: 'board_show_replies_iteration_test',
  components: [
    {
      comment: '답글 섹션 - iteration.source {{ }} 수정 후 정상 동작 검증',
      type: 'basic',
      name: 'Div',
      if: '{{(post?.data?.replies ?? []).length > 0}}',
      props: { 'data-testid': 'replies-section' },
      children: [
        {
          type: 'basic',
          name: 'Div',
          iteration: {
            source: '{{post?.data?.replies ?? []}}',
            item_var: 'reply',
            index_var: 'replyIndex',
          },
          children: [
            {
              type: 'basic',
              name: 'A',
              props: {
                href: '/board/test/{{reply.id}}',
                'data-testid': 'reply-title-link',
              },
              text: '{{reply?.title ?? ""}}',
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

describe('board/show _type_renderer 유형별 분기 렌더링', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  describe('[1] basic 유형', () => {
    it('show-basic 영역이 렌더링되고 show-gallery/show-card는 렌더링되지 않는다', async () => {
      const { layout, initialData } = makeShowLayout('basic');
      const testUtils = createLayoutTest(layout as any, { componentRegistry: registry, initialData });
      await testUtils.render();

      expect(screen.getByTestId('show-basic')).toBeInTheDocument();
      expect(screen.queryByTestId('show-gallery')).not.toBeInTheDocument();
      expect(screen.queryByTestId('show-card')).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('게시글 제목이 표시된다', async () => {
      const { layout, initialData } = makeShowLayout('basic');
      const testUtils = createLayoutTest(layout as any, { componentRegistry: registry, initialData });
      await testUtils.render();

      expect(screen.getByText('테스트 게시글')).toBeInTheDocument();

      testUtils.cleanup();
    });

    it('게시글 내용이 HtmlContent로 렌더링된다', async () => {
      const { layout, initialData } = makeShowLayout('basic');
      const testUtils = createLayoutTest(layout as any, { componentRegistry: registry, initialData });
      await testUtils.render();

      const htmlContent = screen.getByTestId('html-content');
      expect(htmlContent).toBeInTheDocument();
      expect(htmlContent.textContent).toBe('게시글 내용입니다.');

      testUtils.cleanup();
    });
  });

  describe('[2] gallery 유형', () => {
    it('show-gallery 영역이 렌더링되고 show-basic은 렌더링되지 않는다', async () => {
      const { layout, initialData } = makeShowLayout('gallery');
      const testUtils = createLayoutTest(layout as any, { componentRegistry: registry, initialData });
      await testUtils.render();

      expect(screen.getByTestId('show-gallery')).toBeInTheDocument();
      expect(screen.queryByTestId('show-basic')).not.toBeInTheDocument();
      expect(screen.queryByTestId('show-card')).not.toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('[3] card 유형', () => {
    it('show-card 영역이 렌더링되고 show-basic/show-gallery는 렌더링되지 않는다', async () => {
      const { layout, initialData } = makeShowLayout('card');
      const testUtils = createLayoutTest(layout as any, { componentRegistry: registry, initialData });
      await testUtils.render();

      expect(screen.getByTestId('show-card')).toBeInTheDocument();
      expect(screen.queryByTestId('show-basic')).not.toBeInTheDocument();
      expect(screen.queryByTestId('show-gallery')).not.toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('[4] 알 수 없는 유형 fallback', () => {
    it('unknown 유형은 basic으로 fallback 렌더링된다', async () => {
      const { layout, initialData } = makeShowLayout('unknown_type');
      const testUtils = createLayoutTest(layout as any, { componentRegistry: registry, initialData });
      await testUtils.render();

      expect(screen.getByTestId('show-basic')).toBeInTheDocument();
      expect(screen.queryByTestId('show-gallery')).not.toBeInTheDocument();
      expect(screen.queryByTestId('show-card')).not.toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('[5] 네비게이션 - 이전글/다음글 버튼 조건부 렌더링', () => {
    it('prev/next 모두 없으면 버튼이 렌더링되지 않는다', async () => {
      const { layout, initialData } = makeShowLayout('basic', { navigation: { prev: null, next: null } });
      const testUtils = createLayoutTest(layout as any, { componentRegistry: registry, initialData });
      await testUtils.render();

      expect(screen.queryByTestId('btn-prev')).not.toBeInTheDocument();
      expect(screen.queryByTestId('btn-next')).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('prev가 있으면 이전글 버튼이 렌더링된다', async () => {
      const { layout, initialData } = makeShowLayout('basic', { navigation: { prev: { id: 5 }, next: null } });
      const testUtils = createLayoutTest(layout as any, { componentRegistry: registry, initialData });
      await testUtils.render();

      expect(screen.getByTestId('btn-prev')).toBeInTheDocument();
      expect(screen.queryByTestId('btn-next')).not.toBeInTheDocument();

      testUtils.cleanup();
    });

    it('next가 있으면 다음글 버튼이 렌더링된다', async () => {
      const { layout, initialData } = makeShowLayout('basic', { navigation: { prev: null, next: { id: 7 } } });
      const testUtils = createLayoutTest(layout as any, { componentRegistry: registry, initialData });
      await testUtils.render();

      expect(screen.queryByTestId('btn-prev')).not.toBeInTheDocument();
      expect(screen.getByTestId('btn-next')).toBeInTheDocument();

      testUtils.cleanup();
    });

    it('prev/next 모두 있으면 두 버튼 모두 렌더링된다', async () => {
      const { layout, initialData } = makeShowLayout('basic', { navigation: { prev: { id: 5 }, next: { id: 7 } } });
      const testUtils = createLayoutTest(layout as any, { componentRegistry: registry, initialData });
      await testUtils.render();

      expect(screen.getByTestId('btn-prev')).toBeInTheDocument();
      expect(screen.getByTestId('btn-next')).toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('[6] 답글 iteration.source {{ }} 버그 수정 검증', () => {
    /**
     * 증상: 원본 show.json line 1252에서 iteration.source가 {{ }} 없이
     *       "post?.data?.replies ?? []" 로 작성되어 answers 목록이 렌더링되지 않는 버그.
     * 해결: types/basic/show.json에서 "{{post?.data?.replies ?? []}}" 로 수정.
     */
    it('replies가 있을 때 iteration으로 각 답글 제목 링크가 렌더링된다', async () => {
      const testUtils = createLayoutTest(repliesIterationLayout as any, { componentRegistry: registry, initialData: repliesIterationInitialData });
      await testUtils.render();

      expect(screen.getByTestId('replies-section')).toBeInTheDocument();

      const links = screen.getAllByTestId('reply-title-link');
      expect(links).toHaveLength(2);
      expect(links[0].textContent).toBe('첫 번째 답글');
      expect(links[1].textContent).toBe('두 번째 답글');

      testUtils.cleanup();
    });

    it('replies가 비어있으면 답글 섹션이 렌더링되지 않는다', async () => {
      const noRepliesInitialData = {
        post: {
          data: {
            id: 1,
            title: '답글 없는 게시글',
            board: { type: 'basic' },
            permissions: { comments_read: true },
            is_secret: false,
            content: '내용',
            replies: [],
          },
        },
      };

      const testUtils = createLayoutTest(repliesIterationLayout as any, { componentRegistry: registry, initialData: noRepliesInitialData });
      await testUtils.render();

      expect(screen.queryByTestId('replies-section')).not.toBeInTheDocument();

      testUtils.cleanup();
    });
  });
});
