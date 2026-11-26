/**
 * useEditorUrlSync — 첫 복원 dispatch 가 상태를 바꾸지 못해도 다음 편집 모드 진입은 기록을 남긴다 (독립 검토 σ)
 *
 * 첫 복원과 같은 순회의 주소 보정은 건너뛰고(dispatched), 다음 보정을 복원으로 다룬다(settling, pushState 금지).
 * 그런데 복원 dispatch 가 상태를 바꾸지 못하면 다음 보정이 오지 않아 settling 이 남고, 사용자가 나중에
 * 별도 편집 모드로 들어갈 때 pushState 대신 replaceState 를 타 뒤로 가기 한 단계가 사라질 수 있었다.
 *
 * 리듀서는 지금 복원 동작마다 새 상태를 돌려주므로, 「상태가 안 바뀌는 복원」은 dispatch 를 감싸 복원 동작을
 * 버리는 것으로 만든다(리듀서가 거절한 경우와 같은 모양).
 */

import React from 'react';
import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
import { render, cleanup, waitFor, act } from '@testing-library/react';

let dropRestoreSelect = false;
const wrapped = new WeakMap<(a: any) => void, (a: any) => void>();

vi.mock('../../LayoutEditorContext', async (importOriginal) => {
  const mod: any = await importOriginal();
  return {
    ...mod,
    useLayoutEditor: () => {
      const ctx = mod.useLayoutEditor();
      let d = wrapped.get(ctx.dispatch);
      if (!d) {
        d = (action: any) => {
          if (dropRestoreSelect && action?.type === 'SELECT_ROUTE') {
            dropRestoreSelect = false;
            return;
          }
          ctx.dispatch(action);
        };
        wrapped.set(ctx.dispatch, d);
      }
      return { ...ctx, dispatch: d };
    },
  };
});

import { LayoutEditorProvider, useLayoutEditor, type RouteTreeNode } from '../../LayoutEditorContext';
import { useEditorUrlSync } from '../../hooks/useEditorUrlSync';

const TPL = 'sirsoft-admin_basic';
const TREE: RouteTreeNode[] = [
  {
    path: '*/admin/users',
    layoutName: 'admin_user_list',
    label: '회원',
    labelSource: 'path',
    source: { kind: 'template', identifier: TPL },
    kind: 'route',
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

describe('useEditorUrlSync — 상태를 바꾸지 못한 첫 복원', () => {
  beforeEach(() => {
    api = null;
    window.history.replaceState(null, '', '/');
  });
  afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    dropRestoreSelect = false;
    window.history.replaceState(null, '', '/');
  });

  // @scenario case=url_restore_noop_then_edit_entry
  // @effects editor_noop_restore_does_not_swallow_next_history_entry
  it('복원이 반영되지 않은 뒤 기반 편집에 들어가면 pushState 로 기록을 남긴다', async () => {
    window.history.replaceState(null, '', `/admin/layout-editor/${TPL}?route=*%2Fadmin%2Fusers`);
    dropRestoreSelect = true;
    render(
      <LayoutEditorProvider templateIdentifier={TPL} initialLocale="ko">
        <Harness />
      </LayoutEditorProvider>,
    );
    await waitFor(() => expect(api!.state.routeTree.length).toBe(1));
    await act(async () => {
      await new Promise((r) => setTimeout(r, 0));
    });
    expect(api!.state.selectedRoute, '복원 dispatch 가 버려졌다(사전 조건)').toBeNull();

    const pushSpy = vi.spyOn(window.history, 'pushState');
    const replaceSpy = vi.spyOn(window.history, 'replaceState');
    act(() => api!.dispatch({ type: 'ENTER_BASE_EDIT', layoutName: '_admin_base' }));
    await waitFor(() => expect(api!.state.editMode).toBe('base'));
    expect(pushSpy, '사용자 진입은 기록 항목을 남겨야 한다').toHaveBeenCalledTimes(1);
    expect(String(pushSpy.mock.calls[0][2])).toContain('?edit=__base__%2F_admin_base');
    expect(replaceSpy).not.toHaveBeenCalled();
  });
});
