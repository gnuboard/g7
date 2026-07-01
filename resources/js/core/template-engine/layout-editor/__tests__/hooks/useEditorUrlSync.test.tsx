/**
 * useEditorUrlSync 테스트
 *
 * 다음을 검증:
 * - 초기 ?route= 가 routeTree 로드 후 SELECT_ROUTE 로 적용됨
 * - popstate 발생 시 새 ?route= 로 selectedRoute 가 갱신됨
 * - selectedRoute 가 외부로 바뀌면 replaceState 로 URL 보정
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, act, cleanup } from '@testing-library/react';
import {
  LayoutEditorProvider,
  useLayoutEditor,
  type RouteTreeNode,
} from '../../LayoutEditorContext';
import { useEditorUrlSync } from '../../hooks/useEditorUrlSync';

const SAMPLE_TREE: RouteTreeNode[] = [
  {
    path: '/board/list',
    layoutName: 'board/list',
    label: '게시판',
    labelSource: 'path',
    source: { kind: 'module', identifier: 'sirsoft-board' },
    kind: 'route',
  },
  {
    path: '/mypage',
    layoutName: 'mypage',
    label: '내 정보',
    labelSource: 'path',
    source: { kind: 'template', identifier: 'sirsoft-basic' },
    kind: 'route',
  },
];

function SyncHarness({ tree }: { tree: RouteTreeNode[] }) {
  const { state, dispatch } = useLayoutEditor();
  useEditorUrlSync('sirsoft-basic');

  // 트리를 첫 렌더 직후 dispatch (useEditorRoutes 의 역할 대신)
  React.useEffect(() => {
    dispatch({ type: 'SET_ROUTE_TREE', tree });
  }, [tree, dispatch]);

  return (
    <div data-testid="harness-state">
      {JSON.stringify({
        selected: state.selectedRoute,
        treeLen: state.routeTree.length,
        editMode: state.editMode,
        returnRoute: state.returnRoute,
      })}
    </div>
  );
}

function renderHarness(initialSearch: string, tree: RouteTreeNode[] = SAMPLE_TREE) {
  // 테스트별 window.location.search 설정
  Object.defineProperty(window, 'location', {
    configurable: true,
    value: {
      ...window.location,
      pathname: '/admin/layout-editor/sirsoft-basic',
      search: initialSearch,
      href: `http://localhost/admin/layout-editor/sirsoft-basic${initialSearch}`,
    },
  });

  return render(
    <LayoutEditorProvider templateIdentifier="sirsoft-basic" initialLocale="ko">
      <SyncHarness tree={tree} />
    </LayoutEditorProvider>,
  );
}

describe('useEditorUrlSync — 초기 ?route= 복원', () => {
  afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
  });

  it('routeTree 로드 후 ?route= 매칭 노드로 SELECT_ROUTE', async () => {
    const { findByTestId } = renderHarness('?route=%2Fboard%2Flist');
    const el = await findByTestId('harness-state');
    // tree 로드 + effect 적용까지 한 사이클 더 필요
    await act(async () => {
      await Promise.resolve();
    });
    expect(el.textContent).toContain('"path":"/board/list"');
    expect(el.textContent).toContain('"layoutName":"board/list"');
  });

  it('?route= 가 트리에 없는 path → SELECT_ROUTE 미발화 (selectedRoute null)', async () => {
    const { findByTestId } = renderHarness('?route=%2Fnonexistent');
    const el = await findByTestId('harness-state');
    await act(async () => {
      await Promise.resolve();
    });
    expect(el.textContent).toContain('"selected":null');
  });

  it('?route= 없음 → selectedRoute null 유지', async () => {
    const { findByTestId } = renderHarness('');
    const el = await findByTestId('harness-state');
    await act(async () => {
      await Promise.resolve();
    });
    expect(el.textContent).toContain('"selected":null');
  });
});

describe('useEditorUrlSync — popstate 처리', () => {
  afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
  });

  it('popstate 발생 시 새 URL 의 ?route= 로 갱신', async () => {
    const { findByTestId } = renderHarness('?route=%2Fboard%2Flist');
    const el = await findByTestId('harness-state');
    await act(async () => {
      await Promise.resolve();
    });
    expect(el.textContent).toContain('"path":"/board/list"');

    // 브라우저 뒤로가기 시뮬레이션 — URL 을 /mypage 로 바꾸고 popstate 발화
    Object.defineProperty(window, 'location', {
      configurable: true,
      value: {
        ...window.location,
        pathname: '/admin/layout-editor/sirsoft-basic',
        search: '?route=%2Fmypage',
      },
    });
    await act(async () => {
      window.dispatchEvent(new PopStateEvent('popstate'));
    });
    expect(el.textContent).toContain('"path":"/mypage"');
  });

  it('popstate + ?route= 사라짐 → selectedRoute null', async () => {
    const { findByTestId } = renderHarness('?route=%2Fmypage');
    const el = await findByTestId('harness-state');
    await act(async () => {
      await Promise.resolve();
    });

    Object.defineProperty(window, 'location', {
      configurable: true,
      value: {
        ...window.location,
        pathname: '/admin/layout-editor/sirsoft-basic',
        search: '',
      },
    });
    await act(async () => {
      window.dispatchEvent(new PopStateEvent('popstate'));
    });
    expect(el.textContent).toContain('"selected":null');
  });
});

describe('useEditorUrlSync — URL 다이렉트 진입 시 returnRoute 합성', () => {
  afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
  });

  // 반복 항목 편집 URL 다이렉트 진입(?edit=__iteration__/…&host=board/list) 시, 사용자가 트리에서
  // 라우트를 고른 적 없어 selectedRoute=null 이지만 host(layoutName)로부터 호스트 라우트를 찾아
  // returnRoute 를 합성해야 한다 — 그래야 편집 종료 시 "라우트 선택" 화면이 아니라 호스트로 복귀.
  it('?edit=__iteration__/…&host= 다이렉트 진입 → iteration_item 모드 + host 라우트 returnRoute 합성', async () => {
    const { findByTestId } = renderHarness(
      '?edit=__iteration__%2F2.children.5&host=board%2Flist',
    );
    const el = await findByTestId('harness-state');
    await act(async () => {
      await Promise.resolve();
    });
    expect(el.textContent).toContain('"editMode":"iteration_item"');
    // host(board/list)로부터 트리에서 호스트 라우트(/board/list)를 찾아 returnRoute 로 합성.
    expect(el.textContent).toContain('"returnRoute":{"path":"/board/list","layoutName":"board/list"}');
  });

  it('확장 편집 URL 다이렉트 진입(?edit=__extension__/…&host=) → returnRoute 합성', async () => {
    const { findByTestId } = renderHarness('?edit=__extension__%2F9&host=board%2Flist');
    const el = await findByTestId('harness-state');
    await act(async () => {
      await Promise.resolve();
    });
    expect(el.textContent).toContain('"editMode":"extension"');
    expect(el.textContent).toContain('"returnRoute":{"path":"/board/list","layoutName":"board/list"}');
  });

  it('공통 레이아웃 편집 URL 다이렉트 진입(?edit=__base__/…&host=) → returnRoute 합성', async () => {
    const { findByTestId } = renderHarness('?edit=__base__%2F_user_base&host=mypage');
    const el = await findByTestId('harness-state');
    await act(async () => {
      await Promise.resolve();
    });
    expect(el.textContent).toContain('"editMode":"base"');
    expect(el.textContent).toContain('"returnRoute":{"path":"/mypage","layoutName":"mypage"}');
  });

  // host 가 트리의 어느 라우트와도 매칭 안 되면 returnRoute 는 null(reducer 가 selectedRoute 폴백) —
  // 회귀 없음(잘못된 host 로 진입 시 종전과 동일하게 종료 시 라우트 선택 화면).
  it('host 가 트리에 없으면 returnRoute=null (회귀 없음)', async () => {
    const { findByTestId } = renderHarness(
      '?edit=__iteration__%2F0&host=nonexistent%2Flayout',
    );
    const el = await findByTestId('harness-state');
    await act(async () => {
      await Promise.resolve();
    });
    expect(el.textContent).toContain('"editMode":"iteration_item"');
    expect(el.textContent).toContain('"returnRoute":null');
  });
});
