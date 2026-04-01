/**
 * @file board-index-blur.test.tsx
 * @description 게시판 목록 - 블라인드/삭제 게시글 표시 처리 회귀 테스트
 *
 * 검증 항목:
 * 1. [basic] 블라인드 게시글 제목에 연한 색상(text-gray-400) 클래스 적용
 * 2. [basic] 삭제된 게시글 제목에 연한 색상(text-gray-400) 클래스 적용
 * 3. [basic] 일반 게시글 제목에 연한 기본 색상(text-gray-700) 클래스 적용
 * 4. [card/gallery] 블라인드 게시글도 썸네일 있으면 이미지 표시
 * 5. [card/gallery] 삭제된 게시글도 썸네일 있으면 이미지 표시
 * 6. [card/gallery] 썸네일 없으면 플레이스홀더 표시
 * 7. [card/gallery] 블라인드 게시글 플레이스홀더에 연한 색상(text-gray-400) 클래스 적용
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
  'data-testid'?: string;
}> = ({ className, children, text, 'data-testid': testId }) => (
  <span className={className} data-testid={testId}>
    {children || text}
  </span>
);

const TestImg: React.FC<{
  src?: string;
  alt?: string;
  className?: string;
  'data-testid'?: string;
}> = ({ src, alt, className, 'data-testid': testId }) => (
  <img src={src} alt={alt} className={className} data-testid={testId} />
);

const TestIcon: React.FC<{
  name?: string;
  size?: string;
  className?: string;
}> = ({ name, size, className }) => (
  <i className={className} data-icon={name} data-size={size} />
);

const TestH3: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
  'data-testid'?: string;
}> = ({ className, children, text, 'data-testid': testId }) => (
  <h3 className={className} data-testid={testId}>
    {children || text}
  </h3>
);

const TestAvatar: React.FC<{
  author?: unknown;
  size?: string;
  className?: string;
}> = ({ className }) => <span className={className} data-testid="avatar" />;

const TestUserInfo: React.FC<{
  author?: unknown;
  showDropdown?: boolean;
  stopPropagation?: boolean;
  className?: string;
}> = ({ className }) => <span className={className} data-testid="userinfo" />;

const TestHtmlContent: React.FC<{
  content?: string;
  isHtml?: boolean;
  className?: string;
  'data-testid'?: string;
}> = ({ content, className, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>
    {content}
  </div>
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
    Img: { component: TestImg, metadata: { name: 'Img', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    H3: { component: TestH3, metadata: { name: 'H3', type: 'basic' } },
    Avatar: { component: TestAvatar, metadata: { name: 'Avatar', type: 'composite' } },
    UserInfo: { component: TestUserInfo, metadata: { name: 'UserInfo', type: 'composite' } },
    HtmlContent: { component: TestHtmlContent, metadata: { name: 'HtmlContent', type: 'composite' } },
  };

  return registry;
}

// ============================================================
// 레이아웃 JSON
// ============================================================

/**
 * basic형 제목 행 색상 처리 테스트 레이아웃
 * 블라인드/삭제 시 text-gray-400, 정상 시 text-gray-700
 */
const basicTitleColorLayoutJson = {
  version: '1.0.0',
  layout_name: 'board_basic_color_test',
  components: [
    {
      type: 'basic',
      name: 'Span',
      props: {
        'data-testid': 'post-title',
        className:
          "text-sm font-medium truncate {{(_local.post?.status === 'blinded' || _local.post?.deleted_at) ? 'text-gray-400 dark:text-gray-500' : 'text-gray-700 dark:text-gray-300'}}",
      },
      text: '{{_local.post?.title}}',
    },
  ],
};

/**
 * card/gallery형 이미지 조건 테스트 레이아웃
 * - 썸네일 있으면 항상 표시 (블라인드/삭제 여부 무관)
 * - 썸네일 없으면 플레이스홀더 표시
 */
const imageThumbnailConditionLayoutJson = {
  version: '1.0.0',
  layout_name: 'board_image_condition_test',
  components: [
    {
      type: 'basic',
      name: 'Div',
      props: { 'data-testid': 'image-area' },
      children: [
        {
          type: 'basic',
          name: 'Img',
          if: "{{_local.post?.thumbnail}}",
          props: {
            src: '{{_local.post?.thumbnail}}',
            alt: '{{_local.post?.title}}',
            'data-testid': 'post-img',
            className: 'w-full h-full object-cover',
          },
        },
        {
          type: 'basic',
          name: 'Div',
          if: "{{!_local.post?.thumbnail}}",
          props: {
            'data-testid': 'post-placeholder',
            className:
              "w-full h-full flex items-center justify-center gap-2 bg-gray-200 dark:bg-gray-700",
          },
        },
      ],
    },
  ],
};

/**
 * card/gallery형 플레이스홀더 색상 테스트 레이아웃
 * 이미지 없는 경우의 플레이스홀더 텍스트 색상 처리
 */
const imagePlaceholderColorLayoutJson = {
  version: '1.0.0',
  layout_name: 'board_placeholder_color_test',
  components: [
    {
      type: 'basic',
      name: 'Div',
      props: {
        'data-testid': 'post-placeholder',
        className:
          "w-full h-full flex items-center justify-center {{(_local.post?.status === 'blinded' || _local.post?.deleted_at) ? 'text-gray-400 dark:text-gray-500' : 'text-gray-500 dark:text-gray-400'}}",
      },
    },
  ],
};

// ============================================================
// 테스트
// ============================================================

describe('게시판 목록 - 블라인드/삭제 게시글 표시 처리', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  describe('[basic] 제목 색상 처리', () => {
    it('블라인드 게시글 제목에 연한 색상(text-gray-400) 클래스가 적용된다', async () => {
      const testUtils = createLayoutTest(basicTitleColorLayoutJson, {
        componentRegistry: registry,
        initialState: {
          _local: { post: { id: 1, title: '블라인드 글', status: 'blinded', deleted_at: null } },
        },
      });

      await testUtils.render();
      const titleEl = screen.getByTestId('post-title');
      expect(titleEl.className).toContain('text-gray-400');
      expect(titleEl.className).not.toContain('text-gray-700');
      testUtils.cleanup();
    });

    it('삭제된 게시글 제목에 연한 색상(text-gray-400) 클래스가 적용된다', async () => {
      const testUtils = createLayoutTest(basicTitleColorLayoutJson, {
        componentRegistry: registry,
        initialState: {
          _local: { post: { id: 2, title: '삭제된 글', status: 'published', deleted_at: '2024-01-01 00:00:00' } },
        },
      });

      await testUtils.render();
      const titleEl = screen.getByTestId('post-title');
      expect(titleEl.className).toContain('text-gray-400');
      expect(titleEl.className).not.toContain('text-gray-700');
      testUtils.cleanup();
    });

    it('일반 게시글 제목에 기본 색상(text-gray-700) 클래스가 적용된다', async () => {
      const testUtils = createLayoutTest(basicTitleColorLayoutJson, {
        componentRegistry: registry,
        initialState: {
          _local: { post: { id: 3, title: '일반 글', status: 'published', deleted_at: null } },
        },
      });

      await testUtils.render();
      const titleEl = screen.getByTestId('post-title');
      expect(titleEl.className).toContain('text-gray-700');
      expect(titleEl.className).not.toContain('text-gray-400');
      testUtils.cleanup();
    });
  });

  describe('[card/gallery] 이미지 표시 조건 처리 (썸네일 있으면 항상 표시)', () => {
    it('블라인드 게시글도 썸네일이 있으면 이미지가 표시된다', async () => {
      const testUtils = createLayoutTest(imageThumbnailConditionLayoutJson, {
        componentRegistry: registry,
        initialState: {
          _local: { post: { id: 1, title: '블라인드 글', status: 'blinded', deleted_at: null, thumbnail: '/img/thumb1.jpg' } },
        },
      });

      await testUtils.render();
      expect(screen.getByTestId('post-img')).toBeTruthy();
      expect(screen.queryByTestId('post-placeholder')).toBeNull();
      testUtils.cleanup();
    });

    it('삭제된 게시글도 썸네일이 있으면 이미지가 표시된다', async () => {
      const testUtils = createLayoutTest(imageThumbnailConditionLayoutJson, {
        componentRegistry: registry,
        initialState: {
          _local: { post: { id: 2, title: '삭제된 글', status: 'published', deleted_at: '2024-01-01 00:00:00', thumbnail: '/img/thumb2.jpg' } },
        },
      });

      await testUtils.render();
      expect(screen.getByTestId('post-img')).toBeTruthy();
      expect(screen.queryByTestId('post-placeholder')).toBeNull();
      testUtils.cleanup();
    });

    it('썸네일이 없으면 플레이스홀더가 표시된다', async () => {
      const testUtils = createLayoutTest(imageThumbnailConditionLayoutJson, {
        componentRegistry: registry,
        initialState: {
          _local: { post: { id: 3, title: '이미지없음', status: 'published', deleted_at: null, thumbnail: null } },
        },
      });

      await testUtils.render();
      expect(screen.queryByTestId('post-img')).toBeNull();
      expect(screen.getByTestId('post-placeholder')).toBeTruthy();
      testUtils.cleanup();
    });

    it('블라인드 게시글 플레이스홀더에 연한 색상(text-gray-400) 클래스가 적용된다', async () => {
      const testUtils = createLayoutTest(imagePlaceholderColorLayoutJson, {
        componentRegistry: registry,
        initialState: {
          _local: { post: { id: 4, title: '블라인드 이미지없음', status: 'blinded', deleted_at: null, thumbnail: null } },
        },
      });

      await testUtils.render();
      const placeholderEl = screen.getByTestId('post-placeholder');
      expect(placeholderEl.className).toContain('text-gray-400');
      expect(placeholderEl.className).not.toContain('text-gray-500 dark:text-gray-400');
      testUtils.cleanup();
    });
  });
});
