/**
 * TabNavInplaceOverlay.test.tsx — `tabnav` 캔버스 인플레이스 오버레이 레퍼런스
 *
 * 검증:
 *  - 측정 박스(cellBoxes path=`props.tabs.<i>`) → 각 탭 어포던스 렌더
 *  - +추가 → `node.props.tabs` 끝/사이/앞 push (onPatchNode 노드 전체 교체, 동일 패치 SSoT)
 *  - ✕삭제 → tabs 에서 제거
 *  - ◀▶이동 → 인접 스왑(경계 비활성)
 *  - 바인딩(`{{...}}`)/비배열 → null (정적값만 가드)
 *  - 측정 전(cellBoxes 없음) → null
 *
 * @scenario unit=inplace_add
 * @effects tabnav_header_shows_add_remove_move_affordances, inplace_add_pushes_new_tab_to_node_props_tabs, inplace_add_front_inserts_at_index_zero, inplace_new_tab_id_is_max_plus_one_no_collision, inplace_patch_preserves_other_props_keys, canvas_overlay_dispatched_kind_agnostic_by_capability_canvasoverlay_kind
 */

import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { TabNavInplaceOverlay } from '../TabNavInplaceOverlay';

const t = (k: string) => k;

const nodeBox = { top: 0, left: 0, width: 300, height: 40 };

function makeTabsNode(tabs: unknown) {
  return { type: 'composite', name: 'TabNavigation', props: { tabs } } as any;
}

/** 탭 N개에 대한 측정 박스(가로 배치). */
function boxesFor(count: number) {
  return Array.from({ length: count }, (_, i) => ({
    path: `props.tabs.${i}`,
    top: 0,
    left: i * 80,
    width: 76,
    height: 36,
  }));
}

function renderOverlay(tabs: unknown, onPatchNode = vi.fn(), cellBoxes = boxesFor(Array.isArray(tabs) ? tabs.length : 0)) {
  render(
    <TabNavInplaceOverlay
      node={makeTabsNode(tabs)}
      params={{ arrayProp: 'tabs' }}
      nodeBox={nodeBox}
      cellBoxes={cellBoxes}
      t={t}
      onPatchNode={onPatchNode}
    />,
  );
  return onPatchNode;
}

describe('TabNavInplaceOverlay (registerCanvasOverlay 레퍼런스)', () => {
  it('각 탭 측정 박스마다 삭제/추가 어포던스를 렌더한다', () => {
    renderOverlay([
      { id: 1, label: 'A' },
      { id: 2, label: 'B' },
    ]);
    expect(screen.getByTestId('g7le-tabnav-aff-0')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-tabnav-aff-1')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-tabnav-remove-0')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-tabnav-remove-1')).toBeInTheDocument();
    // 첫 탭 앞 + / 각 탭 다음 +
    expect(screen.getByTestId('g7le-tabnav-add-0')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-tabnav-add-1')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-tabnav-add-2')).toBeInTheDocument();
  });

  it('+추가(끝) → tabs 끝에 새 탭 push (노드 전체 교체)', () => {
    const onPatchNode = renderOverlay([{ id: 1, label: 'A' }]);
    fireEvent.click(screen.getByTestId('g7le-tabnav-add-1'));
    const patched = onPatchNode.mock.calls[0][0];
    expect(patched.props.tabs).toHaveLength(2);
    expect(patched.props.tabs[1].id).toBe(2);
    expect(patched.props.tabs[1].label).toBe('editor.tabnav_inplace.new_tab');
  });

  it('+추가(앞) → tabs 맨 앞에 삽입', () => {
    const onPatchNode = renderOverlay([{ id: 1, label: 'A' }]);
    fireEvent.click(screen.getByTestId('g7le-tabnav-add-0'));
    const patched = onPatchNode.mock.calls[0][0];
    expect(patched.props.tabs).toHaveLength(2);
    // 새 탭이 0번에 삽입, 기존 A 가 1번
    expect(patched.props.tabs[1].label).toBe('A');
  });

  /**
   * @scenario unit=inplace_remove
   * @effects inplace_remove_drops_only_that_tab
   */
  it('✕삭제 → 해당 탭 제거', () => {
    const onPatchNode = renderOverlay([
      { id: 1, label: 'A' },
      { id: 2, label: 'B' },
    ]);
    fireEvent.click(screen.getByTestId('g7le-tabnav-remove-0'));
    const patched = onPatchNode.mock.calls[0][0];
    expect(patched.props.tabs).toEqual([{ id: 2, label: 'B' }]);
  });

  /**
   * @scenario unit=inplace_move
   * @effects inplace_move_swaps_adjacent_with_boundary_guard
   */
  it('▶이동 → 인접 탭과 스왑', () => {
    const onPatchNode = renderOverlay([
      { id: 1, label: 'A' },
      { id: 2, label: 'B' },
    ]);
    fireEvent.click(screen.getByTestId('g7le-tabnav-move-right-0'));
    const patched = onPatchNode.mock.calls[0][0];
    expect(patched.props.tabs.map((x: any) => x.label)).toEqual(['B', 'A']);
  });

  it('경계 이동 어포던스는 미렌더(첫 탭 ◀ / 끝 탭 ▶ 없음)', () => {
    renderOverlay([
      { id: 1, label: 'A' },
      { id: 2, label: 'B' },
    ]);
    expect(screen.queryByTestId('g7le-tabnav-move-left-0')).toBeNull();
    expect(screen.queryByTestId('g7le-tabnav-move-right-1')).toBeNull();
    expect(screen.getByTestId('g7le-tabnav-move-right-0')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-tabnav-move-left-1')).toBeInTheDocument();
  });

  it('새 탭 id 는 기존 최대 id+1 (충돌 회피)', () => {
    const onPatchNode = renderOverlay([
      { id: 3, label: 'A' },
      { id: 7, label: 'B' },
    ]);
    fireEvent.click(screen.getByTestId('g7le-tabnav-add-2'));
    const patched = onPatchNode.mock.calls[0][0];
    expect(patched.props.tabs[2].id).toBe(8);
  });

  /**
   * @scenario unit=static_value_guard
   * @effects binding_or_non_array_or_unmeasured_tabs_renders_no_overlay
   */
  it('바인딩(`{{...}}`) tabs → null (정적값만 가드)', () => {
    const { container } = render(
      <TabNavInplaceOverlay
        node={makeTabsNode('{{user.tabs}}')}
        params={{ arrayProp: 'tabs' }}
        nodeBox={nodeBox}
        cellBoxes={[]}
        t={t}
        onPatchNode={vi.fn()}
      />,
    );
    expect(container.querySelector('[data-testid="g7le-tabnav-inplace"]')).toBeNull();
  });

  it('측정 전(cellBoxes 없음) → null', () => {
    const { container } = render(
      <TabNavInplaceOverlay
        node={makeTabsNode([{ id: 1, label: 'A' }])}
        params={{ arrayProp: 'tabs' }}
        nodeBox={nodeBox}
        cellBoxes={[]}
        t={t}
        onPatchNode={vi.fn()}
      />,
    );
    expect(container.querySelector('[data-testid="g7le-tabnav-inplace"]')).toBeNull();
  });

  it('동일 패치 경로 SSoT — 다른 props 키는 보존', () => {
    const onPatchNode = vi.fn();
    render(
      <TabNavInplaceOverlay
        node={{ type: 'composite', name: 'TabNavigation', props: { tabs: [{ id: 1, label: 'A' }], variant: 'pills', activeTabId: 1 } } as any}
        params={{ arrayProp: 'tabs' }}
        nodeBox={nodeBox}
        cellBoxes={boxesFor(1)}
        t={t}
        onPatchNode={onPatchNode}
      />,
    );
    fireEvent.click(screen.getByTestId('g7le-tabnav-add-1'));
    const patched = onPatchNode.mock.calls[0][0];
    expect(patched.props.variant).toBe('pills');
    expect(patched.props.activeTabId).toBe(1);
    expect(patched.props.tabs).toHaveLength(2);
  });
});
