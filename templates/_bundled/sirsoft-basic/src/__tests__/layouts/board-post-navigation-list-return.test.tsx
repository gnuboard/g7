/**
 * @file board-post-navigation-list-return.test.tsx
 * @description 게시판 목록 복귀 상태 보존(45-1) + 답글 상세 이전/다음 버튼 미렌더(47-4) 렌더링 회귀 테스트
 *
 * 검증 항목:
 * 1. 행 클릭(basic/card/gallery) navigate 가 mergeQuery:true 로 현재 목록 query(category/search/page/del/filters)를
 *    상세 URL 에 부착한다. (45-1)
 * 2. 상세 '목록' 버튼 navigate 가 mergeQuery:true 로 상세 URL 에 실린 목록 상태를 그대로 목록으로 복귀한다. (45-1)
 * 3. 답글 상세(navigation prev/next null) 에서는 이전/다음 버튼이 미렌더링된다. (47-4)
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
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

const TestIcon: React.FC<{ name?: string; size?: string }> = ({ name }) => (
  <i data-icon={name} />
);

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const Fragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;
  (registry as any).registry = {
    Fragment: { component: Fragment, metadata: { name: 'Fragment', type: 'layout' } },
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'composite' } },
  };
  return registry;
}

/**
 * 현재 URL(window.location)을 목록/상세 상태로 설정합니다.
 * navigate 핸들러의 mergeQuery 는 window.location.search 를 읽어 병합합니다.
 */
function setUrl(search: string): void {
  window.history.replaceState({}, '', `/board/free${search}`);
}

// ============================================================
// 45-1: 행 클릭 → 상세 URL 에 목록 상태 부착 (mergeQuery)
// ============================================================

/**
 * @scenario post_kind=original
 * @effects list_return_preserves_query
 */
describe('45-1 행 클릭 시 목록 상태(query)를 상세 URL 에 부착', () => {
  beforeEach(() => {
    setupTestRegistry();
  });
  afterEach(() => {
    window.history.replaceState({}, '', '/');
  });

  const rowClickLayout = {
    version: '1.0.0',
    layout_name: 'board_row_click_test',
    data_sources: [],
    components: [
      {
        comment: '행 클릭 시 현재 목록 상태를 상세 URL 에 부착 (45-1)',
        type: 'basic',
        name: 'Button',
        props: { 'data-testid': 'row' },
        actions: [
          {
            type: 'click',
            handler: 'navigate',
            params: { path: '/board/free/77', mergeQuery: true, query: {} },
          },
        ],
        text: 'row',
      },
    ],
  };

  it('목록 query(category/search/page)가 상세 navigate 경로에 보존된다', async () => {
    setUrl('?category=공지&search=테스트&page=2');

    const testUtils = createLayoutTest(rowClickLayout);
    await testUtils.render();

    await testUtils.triggerAction({
      type: 'click',
      handler: 'navigate',
      params: { path: '/board/free/77', mergeQuery: true, query: {} },
    } as any);

    const history = testUtils.getNavigationHistory();
    expect(history.length).toBe(1);
    const dest = history[0];
    expect(dest.startsWith('/board/free/77')).toBe(true);
    expect(dest).toContain('category=%EA%B3%B5%EC%A7%80'); // '공지' URL 인코딩
    expect(dest).toContain('search=%ED%85%8C%EC%8A%A4%ED%8A%B8'); // '테스트'
    expect(dest).toContain('page=2');

    testUtils.cleanup();
  });

  it('del=1(권한자 토글)도 상세 URL 로 함께 전달된다', async () => {
    setUrl('?del=1&page=3');

    const testUtils = createLayoutTest(rowClickLayout);
    await testUtils.render();

    await testUtils.triggerAction({
      type: 'click',
      handler: 'navigate',
      params: { path: '/board/free/77', mergeQuery: true, query: {} },
    } as any);

    const dest = testUtils.getNavigationHistory()[0];
    expect(dest).toContain('del=1');
    expect(dest).toContain('page=3');

    testUtils.cleanup();
  });
});

// ============================================================
// 45-1: 상세 '목록' 버튼 → 목록 상태 복귀
// ============================================================

describe("45-1 '목록' 버튼이 상세 URL 의 목록 상태를 목록으로 복귀", () => {
  beforeEach(() => {
    setupTestRegistry();
  });
  afterEach(() => {
    window.history.replaceState({}, '', '/');
  });

  it('상세 URL 의 query 가 목록 경로로 그대로 복귀된다', async () => {
    // 상세 화면 URL 에 목록 상태가 실려 있음
    window.history.replaceState({}, '', '/board/free/77?category=공지&search=테스트&page=2');

    const listButtonLayout = {
      version: '1.0.0',
      layout_name: 'board_list_button_test',
      data_sources: [],
      components: [
        {
          comment: '목록으로 버튼 (45-1)',
          type: 'basic',
          name: 'Button',
          props: { 'data-testid': 'back-to-list' },
          actions: [
            {
              type: 'click',
              handler: 'navigate',
              params: { path: '/board/free', mergeQuery: true, query: {} },
            },
          ],
          text: 'list',
        },
      ],
    };

    const testUtils = createLayoutTest(listButtonLayout);
    await testUtils.render();

    await testUtils.triggerAction({
      type: 'click',
      handler: 'navigate',
      params: { path: '/board/free', mergeQuery: true, query: {} },
    } as any);

    const dest = testUtils.getNavigationHistory()[0];
    expect(dest.startsWith('/board/free?')).toBe(true);
    expect(dest).toContain('category=%EA%B3%B5%EC%A7%80');
    expect(dest).toContain('search=%ED%85%8C%EC%8A%A4%ED%8A%B8');
    expect(dest).toContain('page=2');

    testUtils.cleanup();
  });
});

// ============================================================
// 47-4: 답글 상세 → 이전/다음 버튼 미렌더
// ============================================================

describe('47-4 답글 상세에서 이전/다음 버튼 미렌더', () => {
  beforeEach(() => {
    setupTestRegistry();
  });

  // _navigation.json 의 이전/다음 버튼 if 조건 구조를 그대로 재현
  const navButtonsLayout = {
    version: '1.0.0',
    layout_name: 'board_nav_buttons_test',
    data_sources: [
      {
        id: 'navigation',
        type: 'api',
        endpoint: '/api/modules/sirsoft-board/boards/free/posts/77/navigation',
        method: 'GET',
        auto_fetch: true,
      },
    ],
    components: [
      {
        type: 'basic',
        name: 'Div',
        children: [
          {
            comment: '이전글 버튼',
            type: 'basic',
            name: 'Button',
            if: '{{navigation?.data?.prev?.id}}',
            props: { 'data-testid': 'nav-prev' },
            text: 'prev',
          },
          {
            comment: '다음글 버튼',
            type: 'basic',
            name: 'Button',
            if: '{{navigation?.data?.next?.id}}',
            props: { 'data-testid': 'nav-next' },
            text: 'next',
          },
        ],
      },
    ],
  };

  it('답글 상세(navigation prev/next null) → 이전/다음 버튼 모두 미렌더', async () => {
    const testUtils = createLayoutTest(navButtonsLayout);
    testUtils.mockApi('navigation', {
      response: { data: { prev: null, next: null } },
    });
    await testUtils.render();

    expect(screen.queryByTestId('nav-prev')).not.toBeInTheDocument();
    expect(screen.queryByTestId('nav-next')).not.toBeInTheDocument();

    testUtils.cleanup();
  });

  it('원글 상세(navigation prev/next 존재) → 이전/다음 버튼 렌더 (대조군)', async () => {
    const testUtils = createLayoutTest(navButtonsLayout);
    testUtils.mockApi('navigation', {
      response: { data: { prev: { id: 76 }, next: { id: 78 } } },
    });
    await testUtils.render();

    expect(screen.getByTestId('nav-prev')).toBeInTheDocument();
    expect(screen.getByTestId('nav-next')).toBeInTheDocument();

    testUtils.cleanup();
  });
});
