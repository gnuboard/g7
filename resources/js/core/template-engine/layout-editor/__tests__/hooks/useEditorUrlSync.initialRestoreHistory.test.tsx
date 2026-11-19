/**
 * useEditorUrlSync — 주소로 들어온 첫 복원이 주소와 기록을 건드리지 않는지
 *
 * 운영 화면의 편집 단추는 `?route={라우트 정의 path}` 를 붙여 같은 탭으로 편집기를 연다.
 * 첫 복원 효과(1)가 SELECT_ROUTE 를 보내는 같은 flush 에서 주소 보정 효과(3)가 아직 반영 전인
 * 상태(selectedRoute=null)로 돌면, `?route=` 를 지운 주소로 replaceState 했다가 다음 렌더에서 되살린다.
 * `?edit=` 새로고침 복원은 지운 주소 위에 pushState 를 한 번 더 해 기록 항목이 하나 늘었다
 * (뒤로 가기가 라우트 없는 주소로 한 번 더 멈춘다).
 *
 * 실제 LayoutEditorProvider 리듀서와 jsdom history 를 쓴다(주소를 손으로 고정하지 않는다).
 */

import React from 'react';
import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
import { render, cleanup, waitFor } from '@testing-library/react';
import {
  LayoutEditorProvider,
  useLayoutEditor,
  type RouteTreeNode,
} from '../../LayoutEditorContext';
import { useEditorUrlSync } from '../../hooks/useEditorUrlSync';

const EDITOR_PATH = '/admin/layout-editor/sirsoft-admin_basic';

const TREE: RouteTreeNode[] = [
  {
    path: '*/admin/users',
    layoutName: 'admin_user_list',
    label: '회원',
    labelSource: 'path',
    source: { kind: 'template', identifier: 'sirsoft-admin_basic' },
    kind: 'route',
  },
  {
    path: '*/admin/dashboard',
    layoutName: 'admin_dashboard',
    label: '대시보드',
    labelSource: 'path',
    source: { kind: 'template', identifier: 'sirsoft-admin_basic' },
    kind: 'route',
  },
];

function Harness() {
  const { state, dispatch } = useLayoutEditor();
  useEditorUrlSync('sirsoft-admin_basic');
  React.useEffect(() => {
    dispatch({ type: 'SET_ROUTE_TREE', tree: TREE });
  }, [dispatch]);
  return (
    <div data-testid="state">
      {JSON.stringify({ selected: state.selectedRoute?.path ?? null, editMode: state.editMode })}
    </div>
  );
}

function readState(getByTestId: (id: string) => HTMLElement): { selected: string | null; editMode: string } {
  return JSON.parse(getByTestId('state').textContent || '{}');
}

describe('useEditorUrlSync — 주소로 들어온 첫 복원', () => {
  let replaceSpy: ReturnType<typeof vi.spyOn>;
  let pushSpy: ReturnType<typeof vi.spyOn>;
  const written: string[] = [];

  beforeEach(() => {
    written.length = 0;
    window.history.replaceState(null, '', '/');
    replaceSpy = vi.spyOn(window.history, 'replaceState');
    pushSpy = vi.spyOn(window.history, 'pushState');
  });

  afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    window.history.replaceState(null, '', '/');
  });

  function enter(search: string): void {
    // 시험 준비 — 스파이를 거치지 않도록 원본으로 주소를 놓는다
    replaceSpy.mockRestore();
    window.history.replaceState(null, '', `${EDITOR_PATH}${search}`);
    replaceSpy = vi.spyOn(window.history, 'replaceState').mockImplementation(function (this: History, ...args: any[]) {
      written.push(String(args[2]));
      return History.prototype.replaceState.apply(this, args as any);
    });
    pushSpy.mockRestore();
    pushSpy = vi.spyOn(window.history, 'pushState').mockImplementation(function (this: History, ...args: any[]) {
      written.push(`push:${String(args[2])}`);
      return History.prototype.pushState.apply(this, args as any);
    });
  }

  // @scenario case=url_restore_ops_screen_floating_fab_definition_path
  // @effects editor_entry_route_param_selects_route_without_rewriting_url
  it('편집 단추가 넘긴 라우트 정의 path(`*/admin/users`)가 선택되고 주소의 ?route= 가 한 번도 지워지지 않는다', async () => {
    enter('?route=*%2Fadmin%2Fusers');
    const { getByTestId } = render(
      <LayoutEditorProvider templateIdentifier="sirsoft-admin_basic" initialLocale="ko">
        <Harness />
      </LayoutEditorProvider>,
    );

    await waitFor(() => expect(readState(getByTestId).selected).toBe('*/admin/users'));
    expect(window.location.search).toBe('?route=*%2Fadmin%2Fusers');
    // 지웠다 되살리는 중간 쓰기가 없어야 한다
    expect(written.filter((url) => !url.includes('route='))).toEqual([]);
    expect(pushSpy).not.toHaveBeenCalled();
  });

  // @scenario case=url_restore_reload_edit_mode
  // @effects editor_edit_param_reload_does_not_add_history_entry
  it('`?edit=__base__/…` 새로고침 복원은 기록 항목을 늘리지 않고 주소를 그대로 둔다', async () => {
    enter('?edit=__base__%2F_admin_base');
    const lengthBefore = window.history.length;
    const { getByTestId } = render(
      <LayoutEditorProvider templateIdentifier="sirsoft-admin_basic" initialLocale="ko">
        <Harness />
      </LayoutEditorProvider>,
    );

    await waitFor(() => expect(readState(getByTestId).editMode).toBe('base'));
    expect(window.location.search).toBe('?edit=__base__%2F_admin_base');
    expect(pushSpy).not.toHaveBeenCalled();
    expect(window.history.length).toBe(lengthBefore);
    expect(written.filter((url) => !url.includes('edit='))).toEqual([]);
  });

  // @scenario case=url_restore_ops_screen_floating_fab_unknown
  // @effects editor_entry_unknown_route_param_leaves_tree_unselected
  it('어느 정의와도 맞지 않는 값은 선택하지 않고 주소를 편집기 기본 주소로 맞춘다', async () => {
    enter('?route=%2Fnot-a-route%2Fx%2Fy');
    const { getByTestId } = render(
      <LayoutEditorProvider templateIdentifier="sirsoft-admin_basic" initialLocale="ko">
        <Harness />
      </LayoutEditorProvider>,
    );
    await waitFor(() => expect(window.location.search).toBe(''));
    expect(readState(getByTestId).selected).toBeNull();
  });
});
