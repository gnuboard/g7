/**
 * useEditorUrlSync — 새로고침과 붙여 넣은 주소의 복원 충실도
 *
 * (A) 기반 레이아웃(`__base__/…`)·확장(`__extension__/…`) 편집 주소에 `&host=` 가 붙지 않아, 새로고침하면
 *     복귀할 라우트를 잃었다(편집 종료 시 「라우트 선택」 빈 화면). 복원은 host(복귀 라우트의 레이아웃 이름)로
 *     복귀 라우트를 찾는데, buildEditorUrl 이 모달·반복 항목에만 host 를 붙였다(useEditorMode.ts).
 * (B) `?route=` 가 트리 노드 path 와 정확히 같을 때만 선택했다. 사이트 주소(`/board/notice`, `/admin/users`)를
 *     붙여 넣으면 정의 path(`/board/:slug`, `*\/admin/users`)를 못 찾고 `?route=` 까지 지워 빈 편집기가 됐다.
 *     정확 일치가 없으면 라우터와 같은 규칙으로 정의 path 를 찾아 선택하고 주소를 그 정의 path 로 맞춘다.
 *
 * 실제 LayoutEditorProvider 리듀서와 jsdom history 를 쓴다.
 */

import React from 'react';
import { describe, it, expect, afterEach, beforeEach } from 'vitest';
import { render, cleanup, waitFor, act } from '@testing-library/react';
import {
  LayoutEditorProvider,
  useLayoutEditor,
  type RouteTreeNode,
} from '../../LayoutEditorContext';
import { useEditorUrlSync } from '../../hooks/useEditorUrlSync';
import { buildEditorUrl } from '../../hooks/useEditorMode';

const TPL = 'sirsoft-admin_basic';
const EDITOR_PATH = `/admin/layout-editor/${TPL}`;

function route(path: string, layoutName: string): RouteTreeNode {
  return {
    path,
    layoutName,
    label: path,
    labelSource: 'path',
    source: { kind: 'template', identifier: TPL },
    kind: 'route',
  };
}

const TREE: RouteTreeNode[] = [
  {
    path: '__group__/template',
    layoutName: null,
    label: 'group',
    labelSource: 'editor_label',
    source: { kind: 'core', identifier: null },
    kind: 'route',
    children: [
      route('*/admin/users', 'admin_user_list'),
      route('*/admin/users/:id', 'admin_user_detail'),
      route('/board/write', 'board_write'),
      route('/board/:slug', 'board_list'),
    ],
  },
];

let api: ReturnType<typeof useLayoutEditor> | null = null;

function Harness() {
  const ctx = useLayoutEditor();
  api = ctx;
  useEditorUrlSync(TPL);
  React.useEffect(() => {
    ctx.dispatch({ type: 'SET_ROUTE_TREE', tree: TREE });
  }, [ctx.dispatch]);
  return null;
}

function mount(search: string): ReturnType<typeof render> {
  window.history.replaceState(null, '', `${EDITOR_PATH}${search}`);
  return render(
    <LayoutEditorProvider templateIdentifier={TPL} initialLocale="ko">
      <Harness />
    </LayoutEditorProvider>,
  );
}

describe('useEditorUrlSync — 기반·확장 편집 주소의 복귀 라우트 (A)', () => {
  beforeEach(() => {
    api = null;
  });
  afterEach(() => {
    cleanup();
    window.history.replaceState(null, '', '/');
  });

  // @scenario case=url_restore_base_click
  // @effects base_edit_url_carries_return_route_host
  it('라우트에서 기반 레이아웃 편집에 들어가면 주소에 복귀 라우트의 레이아웃 이름이 &host= 로 붙는다', async () => {
    mount('?route=*%2Fadmin%2Fusers');
    await waitFor(() => expect(api!.state.selectedRoute?.path).toBe('*/admin/users'));
    act(() => api!.dispatch({ type: 'ENTER_BASE_EDIT', layoutName: '_admin_base' }));
    await waitFor(() => expect(window.location.search).toBe('?edit=__base__%2F_admin_base&host=admin_user_list'));
  });

  // @scenario case=url_restore_base_reload
  // @effects base_edit_reload_keeps_return_route
  it('그 주소로 새로고침하면 기반 편집으로 복원되고, 편집을 끝내면 들어오기 전 라우트로 돌아간다', async () => {
    mount('?edit=__base__%2F_admin_base&host=admin_user_list');
    await waitFor(() => expect(api!.state.editMode).toBe('base'));
    expect(api!.state.returnRoute?.path).toBe('*/admin/users');
    expect(window.location.search).toBe('?edit=__base__%2F_admin_base&host=admin_user_list');
    act(() => api!.dispatch({ type: 'EXIT_BASE_EDIT' }));
    await waitFor(() => expect(api!.state.selectedRoute?.path).toBe('*/admin/users'));
  });

  // @scenario case=url_restore_extension_click
  // @effects extension_edit_url_carries_return_route_host
  it('확장 편집 주소에도 복귀 라우트의 &host= 가 붙는다', async () => {
    mount('?route=%2Fboard%2F%3Aslug');
    await waitFor(() => expect(api!.state.selectedRoute?.path).toBe('/board/:slug'));
    act(() => api!.dispatch({ type: 'ENTER_EXTENSION_EDIT', extensionId: '9' }));
    await waitFor(() => expect(window.location.search).toBe('?edit=__extension__%2F9&host=board_list'));
  });

  // @scenario case=url_build_base
  // @effects base_edit_url_carries_return_route_host
  it('buildEditorUrl 네 번째 인자(복귀 라우트 레이아웃)는 기반·확장에만 host 로 붙는다 — 세 번째 인자 뜻은 그대로', () => {
    expect(buildEditorUrl(TPL, '__base__/_admin_base', '_admin_base', 'admin_user_list')).toBe(
      `${EDITOR_PATH}?edit=__base__%2F_admin_base&host=admin_user_list`,
    );
    expect(buildEditorUrl(TPL, '__base__/_admin_base', '_admin_base')).toBe(`${EDITOR_PATH}?edit=__base__%2F_admin_base`);
    expect(buildEditorUrl(TPL, '__modal__/login', '_admin_base', 'admin_user_list')).toBe(
      `${EDITOR_PATH}?edit=__modal__%2Flogin&host=_admin_base`,
    );
    expect(buildEditorUrl(TPL, '*/admin/users', 'admin_user_list', 'x')).toBe(`${EDITOR_PATH}?route=*%2Fadmin%2Fusers`);
  });
});

describe('useEditorUrlSync — 붙여 넣은 사이트 주소의 ?route= (B)', () => {
  beforeEach(() => {
    api = null;
  });
  afterEach(() => {
    cleanup();
    window.history.replaceState(null, '', '/');
  });

  // @scenario case=url_restore_browser_pathname
  // @effects pasted_site_path_selects_route_definition
  it.each([
    ['%2Fadmin%2Fusers', '*/admin/users', '?route=*%2Fadmin%2Fusers'],
    ['%2Fen%2Fadmin%2Fusers%2F42', '*/admin/users/:id', `?route=${encodeURIComponent('*/admin/users/:id')}`],
    ['%2Fboard%2Fnotice', '/board/:slug', `?route=${encodeURIComponent('/board/:slug')}`],
    ['%2Fboard%2Fnotice%2F', '/board/:slug', `?route=${encodeURIComponent('/board/:slug')}`],
  ])('?route=%s → %s 를 선택하고 주소를 정의 path 로 맞춘다', async (param, expected, search) => {
    mount(`?route=${param}`);
    await waitFor(() => expect(api!.state.selectedRoute?.path).toBe(expected));
    await waitFor(() => expect(window.location.search).toBe(search));
  });

  // @scenario case=url_restore_definition_path
  // @effects pasted_site_path_prefers_exact_route
  it('정확히 같은 정의가 있으면 패턴보다 그것을 고른다(`/board/write` 는 `/board/:slug` 가 아니다)', async () => {
    mount('?route=%2Fboard%2Fwrite');
    await waitFor(() => expect(api!.state.selectedRoute?.path).toBe('/board/write'));
  });

  // @scenario case=url_restore_unknown
  // @effects pasted_unknown_path_leaves_tree_unselected
  it('어느 정의와도 맞지 않으면 선택하지 않는다', async () => {
    mount('?route=%2Fnope%2Fx%2Fy');
    await waitFor(() => expect(window.location.search).toBe(''));
    expect(api!.state.selectedRoute).toBeNull();
  });
});
