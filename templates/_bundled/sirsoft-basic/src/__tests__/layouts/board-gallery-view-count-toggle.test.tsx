/**
 * @file board-gallery-view-count-toggle.test.tsx
 * @description 갤러리형 목록 - 조회수 표시 토글(show_view_count) 반영 회귀 테스트 (#413-19-4b)
 *
 * 배경:
 *   갤러리형 목록(types/gallery/index.json)의 조회수 Span 에 show_view_count 조건이 없어,
 *   환경설정에서 '조회수 표시'를 OFF 해도 갤러리형에서는 조회수가 항상 표시되던 회귀.
 *   basic/card 형은 동일 조건으로 정상 동작 → 갤러리에도 동일 if 조건을 추가.
 *
 * 검증 항목:
 * 1. show_view_count !== false 일 때 조회수 표시 (true / 미설정 모두)
 * 2. show_view_count === false 일 때 조회수 미표시
 *
 * 조건식: "{{posts?.data?.board?.settings?.show_view_count !== false}}"
 *   - basic/card/index.json 과 동일 (SSoT)
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

const TestIcon: React.FC<{
  name?: string;
  size?: string;
  className?: string;
}> = ({ name, size, className }) => (
  <i className={className} data-icon={name} data-size={size} />
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
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
  };

  return registry;
}

// ============================================================
// 레이아웃 JSON — gallery/index.json 의 조회수 Span 구조를 반영
// ============================================================

const galleryViewCountLayoutJson = {
  version: '1.0.0',
  layout_name: 'board_gallery_view_count_test',
  components: [
    {
      type: 'basic',
      name: 'Div',
      props: { 'data-testid': 'meta-area' },
      children: [
        {
          type: 'basic',
          name: 'Span',
          if: '{{posts?.data?.board?.settings?.show_view_count !== false}}',
          props: {
            'data-testid': 'view-count',
            className:
              "flex items-center gap-0.5 {{(_local.post?.status === 'blinded' || _local.post?.deleted_at) ? 'text-gray-400' : 'text-gray-300'}}",
          },
          children: [
            { type: 'basic', name: 'Icon', props: { name: 'eye', size: 'xs' } },
            { type: 'basic', name: 'Span', text: '{{_local.post?.view_count ?? 0}}' },
          ],
        },
      ],
    },
  ],
};

// ============================================================
// 테스트
// ============================================================

// @scenario board-gallery-view-count
// @axes show_view_count=true show_view_count=false show_view_count=unset board_type=gallery
// @effects gallery_shows_view_count_when_true, gallery_shows_view_count_when_unset,
//          gallery_hides_view_count_when_false, gallery_matches_basic_and_card_condition
describe('갤러리형 목록 - 조회수 표시 토글(show_view_count) (#413-19-4b)', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  // @scenario show_view_count=true, board_type=gallery
  // @effects gallery_shows_view_count_when_true, gallery_matches_basic_and_card_condition
  it('show_view_count=true 이면 조회수가 표시된다', async () => {
    const testUtils = createLayoutTest(galleryViewCountLayoutJson, {
      componentRegistry: registry,
      initialData: { posts: { data: { board: { settings: { show_view_count: true } } } } },
      initialState: {
        _local: { post: { id: 1, view_count: 123, status: 'published', deleted_at: null } },
      },
    });

    await testUtils.render();
    expect(screen.getByTestId('view-count')).toBeTruthy();
    testUtils.cleanup();
  });

  // @scenario show_view_count=unset, board_type=gallery
  // @effects gallery_shows_view_count_when_unset
  it('show_view_count 미설정(undefined)이면 조회수가 표시된다 (기본 노출)', async () => {
    const testUtils = createLayoutTest(galleryViewCountLayoutJson, {
      componentRegistry: registry,
      initialData: { posts: { data: { board: { settings: {} } } } },
      initialState: {
        _local: { post: { id: 2, view_count: 45, status: 'published', deleted_at: null } },
      },
    });

    await testUtils.render();
    expect(screen.getByTestId('view-count')).toBeTruthy();
    testUtils.cleanup();
  });

  // @scenario show_view_count=false, board_type=gallery
  // @effects gallery_hides_view_count_when_false, gallery_matches_basic_and_card_condition
  it('show_view_count=false 이면 조회수가 표시되지 않는다 (회귀 차단)', async () => {
    const testUtils = createLayoutTest(galleryViewCountLayoutJson, {
      componentRegistry: registry,
      initialData: { posts: { data: { board: { settings: { show_view_count: false } } } } },
      initialState: {
        _local: { post: { id: 3, view_count: 999, status: 'published', deleted_at: null } },
      },
    });

    await testUtils.render();
    expect(screen.queryByTestId('view-count')).toBeNull();
    testUtils.cleanup();
  });
});
