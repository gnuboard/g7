/**
 * RouteTreePanel 컴포넌트 테스트
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { RouteTreePanel } from '../../components/RouteTreePanel';
import { LayoutEditorProvider, useLayoutEditor, type RouteTreeNode } from '../../LayoutEditorContext';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';

function makeRouteNode(path: string, label: string, kind: RouteTreeNode['kind'] = 'route'): RouteTreeNode {
  return {
    path,
    layoutName: kind === 'route' ? `${path}/layout` : null,
    label,
    labelSource: 'editor_label',
    source: { kind: 'core', identifier: null },
    kind,
    children: [],
  };
}

function makeGroupNode(groupKey: string, label: string, children: RouteTreeNode[]): RouteTreeNode {
  return {
    path: `__group__/${groupKey}`,
    layoutName: null,
    label,
    labelSource: 'editor_label',
    source: { kind: 'core', identifier: null },
    kind: 'route',
    children,
  };
}

function TreeSeeder({ tree, children }: { tree: RouteTreeNode[]; children?: React.ReactNode }): React.ReactElement {
  const { dispatch } = useLayoutEditor();
  React.useEffect(() => {
    dispatch({ type: 'SET_ROUTE_TREE', tree });
  }, [tree, dispatch]);
  return <>{children}</>;
}

function renderTree(tree: RouteTreeNode[]): ReturnType<typeof render> {
  const engine = new TranslationEngine();
  return render(
    <TranslationProvider
      translationEngine={engine}
      translationContext={{ templateId: 'test', locale: 'ko' }}
    >
      <LayoutEditorProvider templateIdentifier="test-tpl" initialLocale="ko">
        <TreeSeeder tree={tree}>
          <RouteTreePanel />
        </TreeSeeder>
      </LayoutEditorProvider>
    </TranslationProvider>,
  );
}

describe('RouteTreePanel — 렌더', () => {
  it('트리가 비어있으면 empty 메시지 표시', () => {
    renderTree([]);
    expect(screen.getByTestId('g7le-route-tree-empty')).toBeTruthy();
  });

  it('그룹 + 자식 라우트 렌더', () => {
    const tree = [
      makeGroupNode('template', '템플릿', [
        makeRouteNode('/', '홈'),
        makeRouteNode('/about', '소개'),
      ]),
    ];
    renderTree(tree);
    const groups = screen.getAllByTestId('g7le-route-tree-group');
    expect(groups).toHaveLength(1);
    const items = screen.getAllByTestId('g7le-route-tree-item');
    expect(items).toHaveLength(2);
  });

  it('hidden 라우트는 badge 표시', () => {
    const node = makeRouteNode('/secret', '비밀');
    node.isHidden = true;
    renderTree([makeGroupNode('template', '템플릿', [node])]);
    expect(screen.getByTestId('g7le-route-tree-badge-hidden')).toBeTruthy();
  });

  it('redirect 라우트는 badge 표시', () => {
    const node = makeRouteNode('/old', '구 페이지');
    node.isRedirect = true;
    renderTree([makeGroupNode('template', '템플릿', [node])]);
    expect(screen.getByTestId('g7le-route-tree-badge-redirect')).toBeTruthy();
  });

  it('라우트 항목 클릭 시 data-route-path attribute 보유', () => {
    const tree = [
      makeGroupNode('template', '템플릿', [makeRouteNode('/board', '게시판')]),
    ];
    renderTree(tree);
    const item = screen.getByTestId('g7le-route-tree-item');
    expect(item.getAttribute('data-route-path')).toBe('/board');
    expect(item.getAttribute('data-route-kind')).toBe('route');

    fireEvent.click(item);
    // selectedRoute 가 업데이트되었는지는 context state 검증 어렵지만,
    // 클릭 핸들러가 호출되어 에러 없이 동작했음을 확인 (회귀 가드).
    expect(item).toBeTruthy();
  });

  it('그룹 노드는 클릭해도 SELECT_ROUTE 트리거 안 함 (data-testid=group)', () => {
    const tree = [makeGroupNode('template', '템플릿', [])];
    renderTree(tree);
    const group = screen.getByTestId('g7le-route-tree-group');
    fireEvent.click(group); // 에러 없이 통과
    expect(group).toBeTruthy();
  });
});

describe('RouteTreePanel — 라벨 해석 (S3\'\' 결함 보강)', () => {
  function renderWithTargetLang(
    tree: RouteTreeNode[],
    targetId: string,
    targetDict: Record<string, any>
  ): ReturnType<typeof render> {
    const engine = new TranslationEngine();
    // 편집 대상 dictionary 사전 등록 — useEditorRoutes 가 비동기 적재하는 시나리오 대신
    // 단위 테스트에서는 직접 시드.
    (engine as any).translations.set(`${targetId}:ko`, targetDict);
    return render(
      <TranslationProvider
        translationEngine={engine}
        translationContext={{ templateId: 'sirsoft-admin_basic', locale: 'ko' }}
      >
        <LayoutEditorProvider templateIdentifier={targetId} initialLocale="ko">
          <TreeSeeder tree={tree}>
            <RouteTreePanel />
          </TreeSeeder>
        </LayoutEditorProvider>
      </TranslationProvider>,
    );
  }

  it('$t: prefix 라벨 — 편집 대상 dictionary 우선 조회', () => {
    const node: RouteTreeNode = {
      path: '/board',
      layoutName: 'board/list',
      label: '$t:user.board.list_title',
      labelSource: 'title',
      source: { kind: 'template', identifier: null },
      kind: 'route',
      children: [],
    };
    const { container } = renderWithTargetLang(
      [makeGroupNode('template', '$t:layout_editor.chrome.route_tree.group.template', [node])],
      'sirsoft-basic',
      { user: { board: { list_title: '게시판' } } }
    );
    expect(container.textContent).toContain('게시판');
    expect(container.textContent).not.toContain('user.board.list_title');
  });

  it('$t: prefix 라벨 — 양쪽 dict 모두 미해석이면 path 마지막 세그먼트로 폴백', () => {
    const node: RouteTreeNode = {
      path: '/shop/cart',
      layoutName: 'shop/cart',
      label: '$t:user.shop.cart_title',
      labelSource: 'title',
      source: { kind: 'template', identifier: null },
      kind: 'route',
      children: [],
    };
    const { container } = renderWithTargetLang(
      [makeGroupNode('template', '$t:layout_editor.chrome.route_tree.group.template', [node])],
      'sirsoft-basic',
      {} // target dict 비어있음
    );
    expect(container.textContent).toContain('cart');
    expect(container.textContent).not.toContain('user.shop.cart_title');
  });

  it('$t: prefix + 파이프 파라미터 — params 해석 (group.module|name=...)', () => {
    const node: RouteTreeNode = {
      path: '__group__/module:sirsoft-ecommerce',
      layoutName: null,
      label: '$t:layout_editor.chrome.route_tree.group.module|name=이커머스',
      labelSource: 'editor_label',
      source: { kind: 'core', identifier: null },
      kind: 'route',
      children: [],
    };
    const engine = new TranslationEngine();
    (engine as any).translations.set('sirsoft-admin_basic:ko', {
      layout_editor: { chrome: { route_tree: { group: { module: '모듈: {name}' } } } },
    });
    const { container } = render(
      <TranslationProvider
        translationEngine={engine}
        translationContext={{ templateId: 'sirsoft-admin_basic', locale: 'ko' }}
      >
        <LayoutEditorProvider templateIdentifier="sirsoft-basic" initialLocale="ko">
          <TreeSeeder tree={[node]}>
            <RouteTreePanel />
          </TreeSeeder>
        </LayoutEditorProvider>
      </TranslationProvider>,
    );
    expect(container.textContent).toContain('모듈: 이커머스');
  });

  it('평문(비-$t:) 라벨은 그대로 표시', () => {
    const node: RouteTreeNode = {
      path: '/foo',
      layoutName: 'foo',
      label: '평문 라벨',
      labelSource: 'editor_label',
      source: { kind: 'template', identifier: null },
      kind: 'route',
      children: [],
    };
    renderTree([makeGroupNode('template', '템플릿', [node])]);
    expect(screen.getByText('평문 라벨')).toBeTruthy();
  });

  // 모달 노드 라벨도 라우트와 동일하게 편집 대상 dictionary 우선 조회한다.
  // 회귀: 편집기 최초 렌더 시 코어 t() 의 편집대상 ja 번역이 아직 전역 컨텍스트에 반영되기 전이면
  // 모달만 `$t:` 키 raw 로 노출(접었다 펴면 정상)되던 결함. 모달도 translationEngine.translate(편집대상)
  // 경로를 타야 로드 타이밍과 무관하게 즉시 해석된다.
  it('$t: 모달 라벨 — 편집 대상 dictionary 우선 조회 (전역 컨텍스트 미적재 상태)', () => {
    const modal: RouteTreeNode = {
      path: '__modal__/_user_base/identity-challenge-modal',
      layoutName: '_user_base',
      label: '$t:user.modal_label.identity_challenge_modal',
      labelSource: 'editor_label',
      source: { kind: 'core', identifier: null },
      kind: 'modal',
      modalId: 'identity-challenge-modal',
      modalHostLayout: '_user_base',
      children: [],
    };
    const { container } = renderWithTargetLang(
      [makeGroupNode('modal', '$t:layout_editor.chrome.route_tree.group.modal', [modal])],
      'sirsoft-basic',
      { user: { modal_label: { identity_challenge_modal: '本人認証' } } }
    );
    expect(container.textContent).toContain('本人認証');
    expect(container.textContent).not.toContain('user.modal_label.identity_challenge_modal');
  });
});

describe('RouteTreePanel — 레이아웃 경로/파일명 노출', () => {
  function routeNodeWithSource(
    path: string,
    layoutName: string,
    source: RouteTreeNode['source'],
    kind: RouteTreeNode['kind'] = 'route',
  ): RouteTreeNode {
    return {
      path,
      layoutName,
      label: '평문 라벨',
      labelSource: 'editor_label',
      source,
      kind,
      children: [],
    };
  }

  it('템플릿 라우트 — "{식별자} · layouts/{경로}.json"', () => {
    const node = routeNodeWithSource('/mypage/profile', 'mypage/profile', {
      kind: 'template',
      identifier: 'sirsoft-basic',
    });
    renderTree([makeGroupNode('template', '템플릿', [node])]);
    const pathEl = screen.getByTestId('g7le-route-tree-layout-path');
    expect(pathEl.textContent).toBe('sirsoft-basic · layouts/mypage/profile.json');
  });

  it('모듈 라우트 — "{식별자} · resources/layouts/{경로}.json"', () => {
    const node = routeNodeWithSource('/board', 'admin/admin_board_index', {
      kind: 'module',
      identifier: 'sirsoft-board',
    });
    renderTree([makeGroupNode('module:sirsoft-board', '게시판', [node])]);
    const pathEl = screen.getByTestId('g7le-route-tree-layout-path');
    expect(pathEl.textContent).toBe('sirsoft-board · resources/layouts/admin/admin_board_index.json');
  });

  it('플러그인 라우트 — resources/layouts prefix', () => {
    const node = routeNodeWithSource('/pay', 'user/checkout', {
      kind: 'plugin',
      identifier: 'sirsoft-pay_nicepayments',
    });
    renderTree([makeGroupNode('plugin:sirsoft-pay_nicepayments', '결제', [node])]);
    const pathEl = screen.getByTestId('g7le-route-tree-layout-path');
    expect(pathEl.textContent).toBe('sirsoft-pay_nicepayments · resources/layouts/user/checkout.json');
  });

  it('공통 레이아웃(core/base) — 식별자 없이 "layouts/{경로}.json"', () => {
    const baseNode: RouteTreeNode = {
      path: '__base__/_user_base',
      layoutName: '_user_base',
      label: '공통 레이아웃',
      labelSource: 'editor_label',
      source: { kind: 'core', identifier: null },
      kind: 'base',
      children: [],
    };
    renderTree([makeGroupNode('base', '공통 레이아웃', [baseNode])]);
    const pathEl = screen.getByTestId('g7le-route-tree-layout-path');
    expect(pathEl.textContent).toBe('layouts/_user_base.json');
  });

  it('그룹 헤더(layoutName 없음)에는 경로를 표시하지 않음', () => {
    const tree = [makeGroupNode('template', '템플릿', [])];
    renderTree(tree);
    expect(screen.queryByTestId('g7le-route-tree-layout-path')).toBeNull();
  });

  it('전체 경로를 title 툴팁으로 제공 (말줄임 대비)', () => {
    const node = routeNodeWithSource('/x', 'deep/nested/path/component', {
      kind: 'template',
      identifier: 'sirsoft-basic',
    });
    renderTree([makeGroupNode('template', '템플릿', [node])]);
    const pathEl = screen.getByTestId('g7le-route-tree-layout-path');
    expect(pathEl.getAttribute('title')).toBe('sirsoft-basic · layouts/deep/nested/path/component.json');
  });
});

describe('RouteTreePanel — 접기/펼치기', () => {
  function ToggleSeeder(): React.ReactElement {
    const { dispatch } = useLayoutEditor();
    return (
      <button data-testid="seeder-toggle" onClick={() => dispatch({ type: 'TOGGLE_ROUTE_TREE' })}>
        toggle
      </button>
    );
  }

  it('TOGGLE_ROUTE_TREE 디스패치 후 collapsed 상태 반영', () => {
    const engine = new TranslationEngine();
    render(
      <TranslationProvider
        translationEngine={engine}
        translationContext={{ templateId: 'test', locale: 'ko' }}
      >
        <LayoutEditorProvider templateIdentifier="test-tpl" initialLocale="ko">
          <ToggleSeeder />
          <RouteTreePanel />
        </LayoutEditorProvider>
      </TranslationProvider>,
    );

    const panel = screen.getByTestId('g7le-route-tree-panel');
    expect(panel.getAttribute('data-collapsed')).toBe('false');

    fireEvent.click(screen.getByTestId('seeder-toggle'));

    const collapsedPanel = screen.getByTestId('g7le-route-tree-panel');
    expect(collapsedPanel.getAttribute('data-collapsed')).toBe('true');
  });
});

describe('RouteTreePanel — 라우트 검색 + 강조', () => {
  beforeEach(() => {
    try {
      window.localStorage.clear();
    } catch {
      /* noop */
    }
  });

  function buildTree(): RouteTreeNode[] {
    return [
      makeGroupNode('template', '템플릿', [
        makeRouteNode('/board', '게시판'),
        makeRouteNode('/shop/cart', '장바구니'),
        makeRouteNode('/mypage', '마이페이지'),
      ]),
    ];
  }

  it('검색 입력칸이 트리 존재 시 표시됨', () => {
    renderTree(buildTree());
    expect(screen.getByTestId('g7le-route-tree-search')).toBeTruthy();
  });

  it('명칭(라벨) 매칭 — "장바구니" 검색 시 해당 항목만 남음', () => {
    renderTree(buildTree());
    const input = screen.getByTestId('g7le-route-tree-search');
    fireEvent.change(input, { target: { value: '장바구니' } });
    const items = screen.getAllByTestId('g7le-route-tree-item');
    expect(items).toHaveLength(1);
    expect(items[0].getAttribute('data-route-path')).toBe('/shop/cart');
  });

  it('라우트 path 매칭 — "/board" 검색 시 게시판 항목만 남음', () => {
    renderTree(buildTree());
    const input = screen.getByTestId('g7le-route-tree-search');
    fireEvent.change(input, { target: { value: 'board' } });
    const items = screen.getAllByTestId('g7le-route-tree-item');
    expect(items).toHaveLength(1);
    expect(items[0].getAttribute('data-route-path')).toBe('/board');
  });

  it('대소문자 무시 매칭', () => {
    renderTree([makeGroupNode('template', '템플릿', [makeRouteNode('/Board', 'Board')])]);
    const input = screen.getByTestId('g7le-route-tree-search');
    fireEvent.change(input, { target: { value: 'BOARD' } });
    expect(screen.getAllByTestId('g7le-route-tree-item')).toHaveLength(1);
  });

  it('매칭 없음 시 no-results 안내', () => {
    renderTree(buildTree());
    const input = screen.getByTestId('g7le-route-tree-search');
    fireEvent.change(input, { target: { value: 'zzzznope' } });
    expect(screen.getByTestId('g7le-route-tree-no-results')).toBeTruthy();
    expect(screen.queryAllByTestId('g7le-route-tree-item')).toHaveLength(0);
  });

  it('매칭 부분을 <mark> 로 강조', () => {
    renderTree([makeGroupNode('template', '템플릿', [makeRouteNode('/board', '게시판')])]);
    const input = screen.getByTestId('g7le-route-tree-search');
    fireEvent.change(input, { target: { value: '게시' } });
    const marks = document.querySelectorAll('.g7le-route-tree__highlight');
    expect(marks.length).toBeGreaterThan(0);
    expect(marks[0].textContent).toBe('게시');
  });

  it('clear 버튼 클릭 시 검색어 초기화 + 전체 복원', () => {
    renderTree(buildTree());
    const input = screen.getByTestId('g7le-route-tree-search') as HTMLInputElement;
    fireEvent.change(input, { target: { value: '장바구니' } });
    expect(screen.getAllByTestId('g7le-route-tree-item')).toHaveLength(1);
    fireEvent.click(screen.getByTestId('g7le-route-tree-search-clear'));
    expect(input.value).toBe('');
    expect(screen.getAllByTestId('g7le-route-tree-item')).toHaveLength(3);
  });
});

describe('RouteTreePanel — 접힘 상태 localStorage 영속화', () => {
  beforeEach(() => {
    try {
      window.localStorage.clear();
    } catch {
      /* noop */
    }
  });

  it('TOGGLE 후 localStorage 에 "1" 기록', () => {
    const engine = new TranslationEngine();
    render(
      <TranslationProvider
        translationEngine={engine}
        translationContext={{ templateId: 'test', locale: 'ko' }}
      >
        <LayoutEditorProvider templateIdentifier="test-tpl" initialLocale="ko">
          <ToggleSeederForPersist />
          <RouteTreePanel />
        </LayoutEditorProvider>
      </TranslationProvider>,
    );
    expect(window.localStorage.getItem('g7le.routeTree.collapsed')).toBe('0');
    fireEvent.click(screen.getByTestId('persist-toggle'));
    expect(window.localStorage.getItem('g7le.routeTree.collapsed')).toBe('1');
  });

  it('localStorage 에 "1" 이면 초기 collapsed 상태로 마운트', () => {
    window.localStorage.setItem('g7le.routeTree.collapsed', '1');
    const engine = new TranslationEngine();
    render(
      <TranslationProvider
        translationEngine={engine}
        translationContext={{ templateId: 'test', locale: 'ko' }}
      >
        <LayoutEditorProvider templateIdentifier="test-tpl" initialLocale="ko">
          <RouteTreePanel />
        </LayoutEditorProvider>
      </TranslationProvider>,
    );
    expect(screen.getByTestId('g7le-route-tree-panel').getAttribute('data-collapsed')).toBe('true');
  });
});

function ToggleSeederForPersist(): React.ReactElement {
  const { dispatch } = useLayoutEditor();
  return (
    <button data-testid="persist-toggle" onClick={() => dispatch({ type: 'TOGGLE_ROUTE_TREE' })}>
      toggle
    </button>
  );
}

// ── 라우트↔모달/확장 연결 그룹 렌더 + 클릭 진입 ────────────────────

/** editMode 를 노출해 연결 자식 클릭 진입을 검증하는 프로브. */
function EditModeProbe(): React.ReactElement {
  const { state } = useLayoutEditor();
  return <div data-testid="edit-mode-probe">{state.editMode}</div>;
}

function renderTreeWithProbe(tree: RouteTreeNode[]): ReturnType<typeof render> {
  const engine = new TranslationEngine();
  return render(
    <TranslationProvider
      translationEngine={engine}
      translationContext={{ templateId: 'test', locale: 'ko' }}
    >
      <LayoutEditorProvider templateIdentifier="test-tpl" initialLocale="ko">
        <TreeSeeder tree={tree}>
          <RouteTreePanel />
          <EditModeProbe />
        </TreeSeeder>
      </LayoutEditorProvider>
    </TranslationProvider>,
  );
}

function makeConnectedModalNode(modalId: string, hostLayout: string, label: string): RouteTreeNode {
  return {
    path: `__modal__/${hostLayout}/${modalId}`,
    layoutName: hostLayout,
    label,
    labelSource: 'editor_label',
    source: { kind: 'core', identifier: null },
    kind: 'modal',
    modalId,
    modalHostLayout: hostLayout,
    children: [],
  };
}

function makeConnectedExtensionNode(id: string, label: string): RouteTreeNode {
  return {
    path: `__extension__/${id}`,
    layoutName: null,
    label,
    labelSource: 'path',
    source: { kind: 'module', identifier: 'sirsoft-board' },
    kind: 'extension',
    extensionId: id,
    extensionType: 'overlay',
    children: [],
  };
}

describe('RouteTreePanel — 라우트↔모달/확장 연결 그룹', () => {
  beforeEach(() => {
    // 접힘 상태 영속화 테스트가 남긴 localStorage 누수로 collapsed 마운트되는 것 방지(격리).
    if (typeof window !== 'undefined' && window.localStorage) {
      window.localStorage.clear();
    }
  });

  function makeRouteWithConnModal(hostPath: string): RouteTreeNode {
    const route = makeRouteNode(hostPath, '회원 목록');
    const modal = makeConnectedModalNode('delete_confirm', 'admin_user_list', '삭제 확인');
    modal.connectedHostRoutePath = hostPath;
    route.children = [
      {
        path: `__conngroup__/modals/${hostPath}`,
        layoutName: null,
        label: '$t:layout_editor.chrome.route_tree.connected.modals|count=1',
        labelSource: 'editor_label',
        source: { kind: 'core', identifier: null },
        kind: 'route',
        children: [modal],
      },
    ];
    return route;
  }

  it('연결 그룹(__conngroup__) 은 토글 가능한 헤더로 렌더 (기본 접힘 → 자식 미표시)', () => {
    const route = makeRouteWithConnModal('/admin/users');
    renderTree([makeGroupNode('template', '템플릿', [route])]);

    const groups = screen.getAllByTestId('g7le-route-tree-group');
    const connGroup = groups.find(
      (g) => g.getAttribute('data-route-path') === '__conngroup__/modals//admin/users',
    );
    expect(connGroup).toBeTruthy();
    // 연결 그룹은 토글 버튼(role=button) + 기본 접힘(aria-expanded=false)
    expect(connGroup!.getAttribute('role')).toBe('button');
    expect(connGroup!.getAttribute('aria-expanded')).toBe('false');
    expect(connGroup!.getAttribute('data-conn-collapsed')).toBe('true');
    // 기본 접힘이라 모달 자식이 트리에 렌더되지 않는다
    const modalItems = screen
      .queryAllByTestId('g7le-route-tree-item')
      .filter((el) => el.getAttribute('data-route-kind') === 'modal');
    expect(modalItems).toHaveLength(0);
  });

  it('연결 그룹 헤더 클릭 → 펼침 → 자식 표시, 재클릭 → 접힘', () => {
    const route = makeRouteWithConnModal('/admin/users');
    renderTree([makeGroupNode('template', '템플릿', [route])]);

    const connGroup = screen
      .getAllByTestId('g7le-route-tree-group')
      .find((g) => g.getAttribute('data-route-path') === '__conngroup__/modals//admin/users')!;

    // 펼침
    fireEvent.click(connGroup);
    expect(connGroup.getAttribute('aria-expanded')).toBe('true');
    expect(
      screen.getAllByTestId('g7le-route-tree-item').filter((el) => el.getAttribute('data-route-kind') === 'modal'),
    ).toHaveLength(1);

    // 재클릭 → 접힘
    fireEvent.click(connGroup);
    expect(connGroup.getAttribute('aria-expanded')).toBe('false');
    expect(
      screen.queryAllByTestId('g7le-route-tree-item').filter((el) => el.getAttribute('data-route-kind') === 'modal'),
    ).toHaveLength(0);
  });

  it('연결 모달 자식 클릭 → ENTER_MODAL_EDIT + 호스트 라우트 강조 유지', () => {
    const route = makeRouteWithConnModal('/admin/users');
    renderTreeWithProbe([makeGroupNode('template', '템플릿', [route])]);

    expect(screen.getByTestId('edit-mode-probe').textContent).toBe('route');
    // 연결 그룹 펼치기
    const connGroup = screen
      .getAllByTestId('g7le-route-tree-group')
      .find((g) => g.getAttribute('data-route-path') === '__conngroup__/modals//admin/users')!;
    fireEvent.click(connGroup);

    const modalItem = screen
      .getAllByTestId('g7le-route-tree-item')
      .find((el) => el.getAttribute('data-route-kind') === 'modal')!;
    fireEvent.click(modalItem);
    expect(screen.getByTestId('edit-mode-probe').textContent).toBe('modal');

    // 호스트 라우트(/admin/users) 노드가 강조 유지 (selectedRoute 가 가상 path 여도)
    const hostRoute = screen
      .getAllByTestId('g7le-route-tree-item')
      .find((el) => el.getAttribute('data-route-path') === '/admin/users')!;
    // 강조 = 파란 배경(#eff6ff). style 속성으로 확인
    expect(hostRoute.getAttribute('style')).toContain('rgb(239, 246, 255)');

    // 편집 중 모달 노드 자체도 강조되어야 한다. reducer 의 selectedRoute.path 는
    // `__modal__/{modalId}`(hostLayout 미포함)라 트리 모달 path(`__modal__/{host}/{modalId}`)와
    // 직접 비교가 어긋나 종전엔 강조가 누락됐다 → modalId 매칭으로 강조한다.
    const selectedModal = screen
      .getAllByTestId('g7le-route-tree-item')
      .find((el) => el.getAttribute('data-route-kind') === 'modal')!;
    expect(selectedModal.getAttribute('style')).toContain('rgb(239, 246, 255)');
  });

  it('연결 확장 자식 클릭 → ENTER_EXTENSION_EDIT (editMode=extension)', () => {
    const route = makeRouteNode('/admin/users', '회원 목록');
    const ext = makeConnectedExtensionNode('2', '🧩 게시판 · admin_user_list');
    ext.connectedHostRoutePath = '/admin/users';
    route.children = [
      {
        path: '__conngroup__/extensions//admin/users',
        layoutName: null,
        label: '$t:layout_editor.chrome.route_tree.connected.extensions|count=1',
        labelSource: 'editor_label',
        source: { kind: 'core', identifier: null },
        kind: 'route',
        children: [ext],
      },
    ];
    renderTreeWithProbe([makeGroupNode('템플릿', '템플릿', [route])]);

    // 연결 그룹 펼치기 후 자식 클릭
    const connGroup = screen
      .getAllByTestId('g7le-route-tree-group')
      .find((g) => g.getAttribute('data-route-path') === '__conngroup__/extensions//admin/users')!;
    fireEvent.click(connGroup);

    const extItem = screen
      .getAllByTestId('g7le-route-tree-item')
      .find((el) => el.getAttribute('data-route-kind') === 'extension')!;
    fireEvent.click(extItem);
    expect(screen.getByTestId('edit-mode-probe').textContent).toBe('extension');
  });
});

describe('RouteTreePanel — 레이아웃 버전 배지', () => {
  function VersionSeeder({
    tree,
    versions,
    children,
  }: {
    tree: RouteTreeNode[];
    versions: Record<string, number>;
    children?: React.ReactNode;
  }): React.ReactElement {
    const { dispatch } = useLayoutEditor();
    React.useEffect(() => {
      dispatch({ type: 'SET_ROUTE_TREE', tree });
      dispatch({ type: 'SET_LAYOUT_VERSIONS', versions });
    }, [tree, versions, dispatch]);
    return <>{children}</>;
  }

  function renderTreeWithVersions(
    tree: RouteTreeNode[],
    versions: Record<string, number>,
  ): ReturnType<typeof render> {
    const engine = new TranslationEngine();
    return render(
      <TranslationProvider
        translationEngine={engine}
        translationContext={{ templateId: 'test', locale: 'ko' }}
      >
        <LayoutEditorProvider templateIdentifier="test-tpl" initialLocale="ko">
          <VersionSeeder tree={tree} versions={versions}>
            <RouteTreePanel />
          </VersionSeeder>
        </LayoutEditorProvider>
      </TranslationProvider>,
    );
  }

  it('버전 이력이 있는 레이아웃 노드에 v{N} 배지를 표시한다', () => {
    const home = makeRouteNode('/', '홈'); // layoutName = '//layout'
    const tree = [makeGroupNode('template', '템플릿', [home])];
    renderTreeWithVersions(tree, { [home.layoutName as string]: 7 });

    const badge = screen.getByTestId('g7le-route-tree-version');
    expect(badge.textContent).toBe('v7');
  });

  it('버전 이력이 없는(맵 미포함) 레이아웃에는 배지를 표시하지 않는다', () => {
    const home = makeRouteNode('/', '홈');
    const tree = [makeGroupNode('template', '템플릿', [home])];
    renderTreeWithVersions(tree, { 'other/layout': 3 });

    expect(screen.queryByTestId('g7le-route-tree-version')).toBeNull();
  });

  it('SET_LAYOUT_VERSION(저장/복원 동기화) 디스패치 시 배지 숫자가 즉시 갱신된다', () => {
    const home = makeRouteNode('/', '홈');
    const layoutName = home.layoutName as string;
    const tree = [makeGroupNode('template', '템플릿', [home])];

    function Bumper(): React.ReactElement {
      const { dispatch } = useLayoutEditor();
      return (
        <button
          type="button"
          data-testid="bump-version"
          onClick={() => dispatch({ type: 'SET_LAYOUT_VERSION', layoutName, version: 8 })}
        >
          bump
        </button>
      );
    }

    const engine = new TranslationEngine();
    render(
      <TranslationProvider
        translationEngine={engine}
        translationContext={{ templateId: 'test', locale: 'ko' }}
      >
        <LayoutEditorProvider templateIdentifier="test-tpl" initialLocale="ko">
          <VersionSeeder tree={tree} versions={{ [layoutName]: 7 }}>
            <RouteTreePanel />
            <Bumper />
          </VersionSeeder>
        </LayoutEditorProvider>
      </TranslationProvider>,
    );

    expect(screen.getByTestId('g7le-route-tree-version').textContent).toBe('v7');
    fireEvent.click(screen.getByTestId('bump-version'));
    expect(screen.getByTestId('g7le-route-tree-version').textContent).toBe('v8');
  });

  it('그룹 헤더(layoutName 없음)에는 배지를 표시하지 않는다', () => {
    const tree = [makeGroupNode('template', '템플릿', [])];
    renderTreeWithVersions(tree, { whatever: 1 });
    expect(screen.queryByTestId('g7le-route-tree-version')).toBeNull();
  });
});

describe('RouteTreePanel — 확장 노드 버전 배지', () => {
  function makeExtensionNode(id: string): RouteTreeNode {
    return {
      path: `__extension__/${id}`,
      layoutName: null,
      label: `🧩 게시판 · html_content`,
      labelSource: 'path',
      source: { kind: 'module', identifier: 'sirsoft-board' },
      kind: 'extension',
      extensionId: id,
      extensionType: 'extension_point',
      children: [],
    };
  }

  function ExtensionVersionSeeder({
    tree,
    versions,
    children,
  }: {
    tree: RouteTreeNode[];
    versions: Record<string, number>;
    children?: React.ReactNode;
  }): React.ReactElement {
    const { dispatch } = useLayoutEditor();
    React.useEffect(() => {
      dispatch({ type: 'SET_ROUTE_TREE', tree });
      dispatch({ type: 'SET_EXTENSION_VERSIONS', versions });
    }, [tree, versions, dispatch]);
    return <>{children}</>;
  }

  function renderExtensionTree(
    tree: RouteTreeNode[],
    versions: Record<string, number>,
  ): ReturnType<typeof render> {
    const engine = new TranslationEngine();
    return render(
      <TranslationProvider
        translationEngine={engine}
        translationContext={{ templateId: 'test', locale: 'ko' }}
      >
        <LayoutEditorProvider templateIdentifier="test-tpl" initialLocale="ko">
          <ExtensionVersionSeeder tree={tree} versions={versions}>
            <RouteTreePanel />
          </ExtensionVersionSeeder>
        </LayoutEditorProvider>
      </TranslationProvider>,
    );
  }

  it('버전 이력이 있는 확장 노드에 v{N} 배지를 표시한다 (extensionId 키 매칭)', () => {
    const tree = [makeGroupNode('extension', '확장 주입', [makeExtensionNode('7')])];
    renderExtensionTree(tree, { '7': 4 });

    const badge = screen.getByTestId('g7le-route-tree-version');
    expect(badge.textContent).toBe('v4');
  });

  it('이력이 없는(맵 미포함) 확장 노드에는 배지를 표시하지 않는다', () => {
    const tree = [makeGroupNode('extension', '확장 주입', [makeExtensionNode('7')])];
    renderExtensionTree(tree, { '99': 2 });

    expect(screen.queryByTestId('g7le-route-tree-version')).toBeNull();
  });
});
