/**
 * @file board-post-navigation-list-return.test.tsx
 * @description 게시판 목록 컨텍스트 왕복 보존(45-1, #75) + 답글 상세 이전/다음 버튼 미렌더(47-4) 렌더링 회귀 테스트
 *
 * 검증 항목:
 * 1. 행 클릭(basic/card/gallery) navigate 가 mergeQuery:true 로 현재 목록 query(category/search/page/del/filters)를
 *    상세 URL 에 부착한다. (45-1)
 * 2. 상세 '목록' 버튼 navigate 가 mergeQuery:true 로 상세 URL 에 실린 목록 상태를 그대로 목록으로 복귀한다. (45-1)
 * 3. 상세 이전글/다음글 navigate 가 목록 상태를 형제 상세 URL 로 이어 나른다. (#75)
 * 4. 답글 상세(navigation prev/next null) 에서는 이전/다음 버튼이 미렌더링된다. (47-4)
 *
 * 1~3 은 테스트 안에 손으로 쓴 조각이 아니라 **실제 레이아웃 JSON 을 import** 해서 그 안의 액션
 * params 를 그대로 실행한다. 실파일이 규약을 어기면 반드시 red 가 되어야 하기 때문이다
 * (#75 는 손으로 쓴 조각만 검증하던 기존 테스트가 green 인 채로 유출된 결함이다).
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

// 실제 레이아웃 JSON — 액션 params 회귀 고정
import navigationPartial from '../../../layouts/partials/board/show/_navigation.json';
import basicIndexPartial from '../../../layouts/partials/board/types/basic/index.json';
import cardIndexPartial from '../../../layouts/partials/board/types/card/index.json';
import galleryIndexPartial from '../../../layouts/partials/board/types/gallery/index.json';

// ============================================================
// 실 JSON 에서 액션을 뽑아내는 헬퍼
// ============================================================

type NavAction = { type?: string; handler: string; params: Record<string, unknown> };

/**
 * 레이아웃 JSON 트리에서 navigate/replaceUrl 액션을 모두 수집합니다.
 *
 * @param node 순회 시작 노드
 * @returns 수집된 액션 목록 (문서 순서)
 */
function collectNavActions(node: unknown): NavAction[] {
  const out: NavAction[] = [];
  const walk = (n: unknown): void => {
    if (Array.isArray(n)) {
      n.forEach(walk);
      return;
    }
    if (!n || typeof n !== 'object') return;
    const obj = n as Record<string, unknown>;
    if (
      (obj.handler === 'navigate' || obj.handler === 'replaceUrl') &&
      obj.params &&
      typeof obj.params === 'object'
    ) {
      out.push(obj as unknown as NavAction);
    }
    Object.values(obj).forEach(walk);
  };
  walk(node);
  return out;
}

/**
 * 목적지 경로가 주어진 접두사로 시작하는 첫 navigate 액션을 찾습니다.
 *
 * @param root 레이아웃 JSON
 * @param pathPrefix 목적지 경로 접두사
 * @returns 찾은 액션
 * @throws 액션이 없으면 예외 (레이아웃 구조 변경을 조용히 통과시키지 않기 위함)
 */
function findNavAction(root: unknown, pathPrefix: string): NavAction {
  const hit = collectNavActions(root).find((a) =>
    String((a.params as { path?: string }).path ?? '').startsWith(pathPrefix)
  );
  if (!hit) {
    throw new Error(`레이아웃 JSON 에서 "${pathPrefix}" 로 시작하는 navigate 액션을 찾지 못했습니다.`);
  }
  return hit;
}

/**
 * navigate 결과 경로의 쿼리스트링을 파싱합니다.
 *
 * @param dest navigate 목적지 (경로 + 쿼리)
 * @returns 쿼리 파라미터
 */
function queryOf(dest: string): URLSearchParams {
  const idx = dest.indexOf('?');
  return new URLSearchParams(idx === -1 ? '' : dest.slice(idx + 1));
}

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
 * 실 JSON 액션을 현재 URL 상태에서 실행하고 목적지를 돌려줍니다.
 *
 * @param action 실 레이아웃 JSON 에서 뽑은 navigate 액션
 * @returns navigate 목적지
 */
async function runNav(action: NavAction): Promise<string> {
  const testUtils = createLayoutTest({
    version: '1.0.0',
    layout_name: 'nav_action_probe',
    data_sources: [],
    components: [{ type: 'basic', name: 'Div', props: { 'data-testid': 'probe' } }],
  });
  await testUtils.render();
  await testUtils.triggerAction({ type: 'click', ...action } as any);
  const dest = testUtils.getNavigationHistory()[0];
  testUtils.cleanup();
  return dest;
}

/**
 * @scenario post_kind=original
 * @effects list_return_preserves_query
 */
describe('45-1 행 클릭 시 목록 상태(query)를 상세 URL 에 부착 (실 JSON)', () => {
  beforeEach(() => {
    setupTestRegistry();
  });
  afterEach(() => {
    window.history.replaceState({}, '', '/');
  });

  const rowClickCases: Array<[string, unknown]> = [
    ['basic', basicIndexPartial],
    ['card', cardIndexPartial],
    ['gallery', galleryIndexPartial],
  ];

  it.each(rowClickCases)(
    '%s 타입 행 클릭이 목록 query(category/search/page)를 상세로 나른다',
    async (_type, partial) => {
      setUrl('?category=공지&search=테스트&page=2');

      // 실파일의 행 클릭 액션 — 상세(`/board/{slug}/{id}`)로 가는 첫 navigate
      const action = collectNavActions(partial).find((a) => {
        const p = String((a.params as { path?: string }).path ?? '');
        return /^\/board\/\{\{route\??\.slug\}\}\/\{\{post/.test(p);
      });
      expect(action, '행 클릭 navigate 액션을 실 JSON 에서 찾지 못함').toBeTruthy();

      const q = queryOf(await runNav(action as NavAction));
      expect(q.get('category')).toBe('공지');
      expect(q.get('search')).toBe('테스트');
      expect(q.get('page')).toBe('2');
    }
  );

  it('del=1(권한자 토글)도 상세 URL 로 함께 전달된다', async () => {
    setUrl('?del=1&page=3');

    const action = collectNavActions(basicIndexPartial).find((a) => {
      const p = String((a.params as { path?: string }).path ?? '');
      return /^\/board\/\{\{route\??\.slug\}\}\/\{\{post/.test(p);
    });

    const q = queryOf(await runNav(action as NavAction));
    expect(q.get('del')).toBe('1');
    expect(q.get('page')).toBe('3');
  });
});

// ============================================================
// 45-1: 상세 '목록' 버튼 → 목록 상태 복귀 (실 _navigation.json)
// ============================================================

describe("45-1 '목록' 버튼이 상세 URL 의 목록 상태를 목록으로 복귀 (실 JSON)", () => {
  beforeEach(() => {
    setupTestRegistry();
  });
  afterEach(() => {
    window.history.replaceState({}, '', '/');
  });

  it('상세 URL 의 query 가 목록 경로로 그대로 복귀된다', async () => {
    // 상세 화면 URL 에 목록 상태가 실려 있음
    window.history.replaceState({}, '', '/board/free/77?category=공지&search=테스트&page=2');

    // 실 _navigation.json 의 '목록으로' 버튼 액션
    const action = collectNavActions(navigationPartial).find(
      (a) => String((a.params as { path?: string }).path ?? '') === '/board/{{route.slug}}'
    );
    expect(action, "'목록으로' navigate 액션을 실 JSON 에서 찾지 못함").toBeTruthy();

    const q = queryOf(await runNav(action as NavAction));
    expect(q.get('category')).toBe('공지');
    expect(q.get('search')).toBe('테스트');
    expect(q.get('page')).toBe('2');
  });
});

// ============================================================
// #75: 이전글/다음글 → 목록 상태를 형제 상세로 승계 (실 _navigation.json)
// ============================================================

/**
 * @scenario leg=prev|next
 * @effects navigation_preserves_list_query
 */
describe('#75 이전글/다음글 버튼이 목록 상태를 형제 상세로 승계 (실 JSON)', () => {
  beforeEach(() => {
    setupTestRegistry();
  });
  afterEach(() => {
    window.history.replaceState({}, '', '/');
  });

  const legs: Array<[string, string]> = [
    ['이전글', '/board/{{route.slug}}/{{navigation.data.prev.id}}'],
    ['다음글', '/board/{{route.slug}}/{{navigation.data.next.id}}'],
  ];

  it.each(legs)('%s 클릭 시 page/search/category 가 모두 살아남는다', async (_label, destPath) => {
    window.history.replaceState(
      {},
      '',
      '/board/free/45?page=2&search=테스트&category=공지&del=1'
    );

    const action = findNavAction(navigationPartial, destPath);
    const q = queryOf(await runNav(action));

    expect(q.get('page')).toBe('2');
    expect(q.get('search')).toBe('테스트');
    expect(q.get('category')).toBe('공지');
    expect(q.get('del')).toBe('1');
  });

  it('이전글 → 목록 순서로 이어가도 목록 페이지가 복원된다', async () => {
    window.history.replaceState({}, '', '/board/free/45?page=2&search=테스트');

    // ① 이전글로 이동
    const prev = findNavAction(navigationPartial, '/board/{{route.slug}}/{{navigation.data.prev.id}}');
    const afterPrev = await runNav(prev);
    // 이동 결과 URL 을 실제 브라우저 상태로 반영 (다음 leg 의 mergeQuery 입력)
    window.history.replaceState({}, '', afterPrev);

    // ② '목록으로' 이동
    const toList = collectNavActions(navigationPartial).find(
      (a) => String((a.params as { path?: string }).path ?? '') === '/board/{{route.slug}}'
    ) as NavAction;
    const q = queryOf(await runNav(toList));

    expect(q.get('page')).toBe('2');
    expect(q.get('search')).toBe('테스트');
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
