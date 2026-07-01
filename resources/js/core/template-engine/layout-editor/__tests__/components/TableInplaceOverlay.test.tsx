// e2e:allow 레이아웃 편집기 캔버스 오버레이/속성패널 UI — dnd-kit/합성 이벤트 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스(T1~T8) 실측 + 단위/레이아웃 렌더링 테스트로 검증
/**
 * TableInplaceOverlay.test.tsx — `table` 캔버스 인플레이스 오버레이 RTL
 *
 * 검증:
 *  - 측정 셀 박스(cellBoxes)를 grid 좌표로 역매핑해 셀 핸들/거터를 렌더
 *  - cellBoxes 미공급/미매칭 → 오버레이 null(코어 선택 오버레이 디그레이드, 회귀 0)
 *  - 행/열 거터 + 모서리 추가 버튼 → 속성 패널과 동일 tableGridMutations 로 onPatchNode
 *  - 셀 선택 + Shift 영역 선택 → 병합 → origin span + 흡수 셀 제거(SSoT 공유 확인)
 *  - 병합 셀 선택 → 해제 버튼 활성 → span 제거
 *  - cellTreePath 역매핑이 비-셀 형제가 섞인 트리에서도 정확
 *
 * @effects table_canvas_overlay_declared_canvasoverlay_kind_table_both_templates, table_inplace_overlay_registered_via_registercoreeditors_kind_agnostic, editorcanvasoverlay_dispatches_canvasoverlay_by_kind_with_measured_cellboxes, inplace_cell_boxes_mapped_to_grid_via_celltreepath, inplace_gutter_add_row_col_shares_tablegridmutations_with_property_panel, inplace_shift_select_merge_sets_origin_span_removes_absorbed, inplace_merged_cell_unmerge_clears_span, inplace_no_cellboxes_degrades_to_null, live_inplace_cell_edit_save_persists_to_user_page
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';

import { TableInplaceOverlay } from '../../components/inplace/TableInplaceOverlay';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import type { OverlayBox } from '../../spec/canvasOverlayRegistry';
import { cellTreePath, treeToGrid, cellRefAt } from '../../spec/tableGridModel';

const t = (k: string) => k;

const params = {
  rowContainer: 'Tbody',
  row: 'Tr',
  cell: 'Td',
  headerCell: 'Th',
  colSpanProp: 'colSpan',
  rowSpanProp: 'rowSpan',
};

function td(text?: string, props?: Record<string, unknown>): EditorNode {
  const n: EditorNode = { type: 'basic', name: 'Td' };
  if (text !== undefined) n.text = text;
  if (props) n.props = props;
  return n;
}
function table2x2(): EditorNode {
  return {
    type: 'basic',
    name: 'Table',
    children: [
      {
        type: 'basic',
        name: 'Tbody',
        children: [
          { type: 'basic', name: 'Tr', children: [td('a'), td('b')] },
          { type: 'basic', name: 'Tr', children: [td('c'), td('d')] },
        ],
      },
    ],
  };
}

const NODE_BOX: OverlayBox = { top: 0, left: 0, width: 200, height: 100 };

/** 표 노드의 모든 origin 셀에 대해 cellTreePath 기반 cellBoxes 를 합성(grid 좌표 순). */
function buildCellBoxes(node: EditorNode): Array<OverlayBox & { path: string }> {
  const grid = treeToGrid(node, params);
  const boxes: Array<OverlayBox & { path: string }> = [];
  for (const row of grid.rows) {
    for (const ref of row.cells) {
      const rel = cellTreePath(node, ref, params);
      const path = rel.map((n) => `children.${n}`).join('.');
      boxes.push({ path, top: ref.gridRow * 24, left: ref.gridCol * 64, width: 64, height: 24 });
    }
  }
  return boxes;
}

afterEach(() => cleanup());

describe('cellTreePath — 셀 트리 경로 역매핑', () => {
  it('표준 Tbody>Tr>Td 트리에서 [섹션,행,셀] 경로 반환', () => {
    const node = table2x2();
    const grid = treeToGrid(node, params);
    const d = cellRefAt(grid, 1, 1)!; // 'd'
    expect(cellTreePath(node, d, params)).toEqual([0, 1, 1]);
    const a = cellRefAt(grid, 0, 0)!;
    expect(cellTreePath(node, a, params)).toEqual([0, 0, 0]);
  });

  it('비-셀 형제(주석 노드)가 섞여도 실제 자식 인덱스로 환원', () => {
    const node: EditorNode = {
      type: 'basic', name: 'Table',
      children: [{
        type: 'basic', name: 'Tbody',
        children: [
          { type: 'basic', name: 'Comment' }, // 비-행
          { type: 'basic', name: 'Tr', children: [{ type: 'basic', name: 'Caption' }, td('x'), td('y')] },
        ],
      }],
    };
    const grid = treeToGrid(node, params);
    const y = cellRefAt(grid, 0, 1)!;
    // 섹션=0, 행=1(Comment 다음), 셀=2(Caption 다음 2번째 Td)
    expect(cellTreePath(node, y, params)).toEqual([0, 1, 2]);
  });
});

/** 셀 (r,c) 의 표 기준 상대 path(`children.a.children.b`) — selectedCellPath 주입용. */
function cellRel(node: EditorNode, r: number, c: number): string {
  const grid = treeToGrid(node, params);
  const ref = cellRefAt(grid, r, c)!;
  return cellTreePath(node, ref, params).map((n) => `children.${n}`).join('.');
}

describe('TableInplaceOverlay — 디스패치/디그레이드 (코어 선택 path 기반)', () => {
  it('cellBoxes 미공급 → null(코어 선택 오버레이 디그레이드)', () => {
    const { container } = render(
      <TableInplaceOverlay node={table2x2()} params={params} nodeBox={NODE_BOX} cellBoxes={[]} t={t} onPatchNode={vi.fn()} />,
    );
    expect(container.firstChild).toBeNull();
  });

  it('표 선택(셀 미선택) → 모서리 추가 + 셀 픽 영역(2단계 선택), 거터/도구는 미표시', () => {
    const node = table2x2();
    render(<TableInplaceOverlay node={node} params={params} nodeBox={NODE_BOX} cellBoxes={buildCellBoxes(node)} t={t} onPatchNode={vi.fn()} />);
    // 모서리 추가는 항상.
    expect(screen.getByTestId('g7le-inplace-add-row-bottom')).toBeTruthy();
    // 셀 픽 영역은 존재(2단계 선택 — 표 선택 상태에서 셀 클릭으로 선택). onPointerDown 이 드래그
    // 핸들로 forward 돼 표 드래그는 보존. 단 아직 선택 셀 없어 거터/도구는 미표시.
    expect(screen.getByTestId('g7le-inplace-cell-0-0')).toBeTruthy();
    expect(screen.queryByTestId('g7le-inplace-colgutter-0')).toBeNull();
    expect(screen.getByTestId('g7le-inplace-select-hint')).toBeTruthy();
  });

  it('표 선택 상태에서 셀 클릭(2단계) → 그 셀 선택 + 그 행/열 거터·도구 노출', () => {
    const node = table2x2();
    render(<TableInplaceOverlay node={node} params={params} nodeBox={NODE_BOX} cellBoxes={buildCellBoxes(node)} t={t} onPatchNode={vi.fn()} />);
    // 셀 픽 영역 클릭 → 내부 pickedCell 설정.
    fireEvent.click(screen.getByTestId('g7le-inplace-cell-0-0'));
    // 그 행(0)·열(0) 거터 + 도구 노출.
    expect(screen.getByTestId('g7le-inplace-colgutter-0')).toBeTruthy();
    expect(screen.getByTestId('g7le-inplace-rowgutter-0')).toBeTruthy();
    expect(screen.getByTestId('g7le-inplace-merge-right')).toBeTruthy();
    expect(screen.queryByTestId('g7le-inplace-colgutter-1')).toBeNull();
  });

  it('코어 직접 선택(selectedCellPath) → 그 셀 선택 + 거터 노출', () => {
    const node = table2x2();
    render(<TableInplaceOverlay node={node} params={params} nodeBox={NODE_BOX} cellBoxes={buildCellBoxes(node)} selectedCellPath={cellRel(node, 0, 0)} t={t} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId('g7le-inplace-colgutter-0')).toBeTruthy();
    expect(screen.getByTestId('g7le-inplace-rowgutter-0')).toBeTruthy();
    expect(screen.queryByTestId('g7le-inplace-colgutter-1')).toBeNull();
  });

  it('셀 자손 path(셀 안 텍스트) 선택도 그 셀로 매핑', () => {
    const node = table2x2();
    const deep = cellRel(node, 1, 1) + '.children.0'; // 셀 안 텍스트 노드 가정
    render(<TableInplaceOverlay node={node} params={params} nodeBox={NODE_BOX} cellBoxes={buildCellBoxes(node)} selectedCellPath={deep} t={t} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId('g7le-inplace-cell-1-1')).toBeTruthy();
  });
});

describe('TableInplaceOverlay — 구조 변형(속성 패널 SSoT 공유)', () => {
  it('행 추가 거터(선택 셀 행) → onPatchNode(행 +1)', () => {
    const node = table2x2();
    const onPatch = vi.fn();
    render(<TableInplaceOverlay node={node} params={params} nodeBox={NODE_BOX} cellBoxes={buildCellBoxes(node)} selectedCellPath={cellRel(node, 0, 0)} t={t} onPatchNode={onPatch} />);
    fireEvent.click(screen.getByTestId('g7le-inplace-row-add-0'));
    expect(onPatch).toHaveBeenCalledTimes(1);
    expect(treeToGrid(onPatch.mock.calls[0][0] as EditorNode, params).rows.length).toBe(3);
  });

  it('열 추가 거터(선택 셀 열) → onPatchNode(열 +1)', () => {
    const node = table2x2();
    const onPatch = vi.fn();
    render(<TableInplaceOverlay node={node} params={params} nodeBox={NODE_BOX} cellBoxes={buildCellBoxes(node)} selectedCellPath={cellRel(node, 0, 0)} t={t} onPatchNode={onPatch} />);
    fireEvent.click(screen.getByTestId('g7le-inplace-col-add-0'));
    expect(treeToGrid(onPatch.mock.calls[0][0] as EditorNode, params).colCount).toBe(3);
  });

  it('오른쪽 병합 → 선택 셀 colSpan=2 + 흡수 셀 제거', () => {
    const node = table2x2();
    const onPatch = vi.fn();
    render(<TableInplaceOverlay node={node} params={params} nodeBox={NODE_BOX} cellBoxes={buildCellBoxes(node)} selectedCellPath={cellRel(node, 0, 0)} t={t} onPatchNode={onPatch} />);
    const mr = screen.getByTestId('g7le-inplace-merge-right') as HTMLButtonElement;
    expect(mr.disabled).toBe(false);
    fireEvent.click(mr);
    const grid = treeToGrid(onPatch.mock.calls[0][0] as EditorNode, params);
    expect(cellRefAt(grid, 0, 0)!.colSpan).toBe(2);
    expect(grid.rows[0]!.cells.length).toBe(1);
  });

  it('아래 병합 → 선택 셀 rowSpan=2', () => {
    const node = table2x2();
    const onPatch = vi.fn();
    render(<TableInplaceOverlay node={node} params={params} nodeBox={NODE_BOX} cellBoxes={buildCellBoxes(node)} selectedCellPath={cellRel(node, 0, 0)} t={t} onPatchNode={onPatch} />);
    fireEvent.click(screen.getByTestId('g7le-inplace-merge-down'));
    const grid = treeToGrid(onPatch.mock.calls[0][0] as EditorNode, params);
    expect(cellRefAt(grid, 0, 0)!.rowSpan).toBe(2);
  });

  it('Shift+클릭 영역 선택 → 영역 병합 버튼 → 사각형 병합', () => {
    const node = table2x2();
    const onPatch = vi.fn();
    render(<TableInplaceOverlay node={node} params={params} nodeBox={NODE_BOX} cellBoxes={buildCellBoxes(node)} t={t} onPatchNode={onPatch} />);
    fireEvent.click(screen.getByTestId('g7le-inplace-cell-0-0'));
    fireEvent.click(screen.getByTestId('g7le-inplace-cell-0-1'), { shiftKey: true });
    // 영역 카운트 + 영역 병합 버튼.
    expect(screen.getByTestId('g7le-inplace-range-count')).toBeTruthy();
    fireEvent.click(screen.getByTestId('g7le-inplace-merge-range'));
    const grid = treeToGrid(onPatch.mock.calls[0][0] as EditorNode, params);
    expect(cellRefAt(grid, 0, 0)!.colSpan).toBe(2);
  });

  it('셀 단일 클릭(Shift 미동반) → onRequestInlineEdit(셀 상대 path) 호출(선택+인라인 동시)', () => {
    const node = table2x2();
    const onInline = vi.fn();
    render(<TableInplaceOverlay node={node} params={params} nodeBox={NODE_BOX} cellBoxes={buildCellBoxes(node)} t={t} onPatchNode={vi.fn()} onRequestInlineEdit={onInline} />);
    fireEvent.click(screen.getByTestId('g7le-inplace-cell-1-1'));
    expect(onInline).toHaveBeenCalledTimes(1);
    expect(onInline).toHaveBeenCalledWith(cellRel(node, 1, 1));
  });

  it('셀 Shift+클릭 → onRequestInlineEdit 미호출(선택만, 인라인 편집 진입 금지)', () => {
    const node = table2x2();
    const onInline = vi.fn();
    render(<TableInplaceOverlay node={node} params={params} nodeBox={NODE_BOX} cellBoxes={buildCellBoxes(node)} t={t} onPatchNode={vi.fn()} onRequestInlineEdit={onInline} />);
    fireEvent.click(screen.getByTestId('g7le-inplace-cell-0-0')); // 앵커(단일 클릭 — 1회 호출)
    onInline.mockClear();
    fireEvent.click(screen.getByTestId('g7le-inplace-cell-1-1'), { shiftKey: true }); // 영역 끝
    expect(onInline).not.toHaveBeenCalled();
  });

  // 두께 토큰 없는 셀에 색 적용 시 기본 두께 부여 — 별도 describe 의 paramsBorder 사용(아래).

  it('마지막 열 셀 → 오른쪽 병합 비활성', () => {
    const node = table2x2();
    render(<TableInplaceOverlay node={node} params={params} nodeBox={NODE_BOX} cellBoxes={buildCellBoxes(node)} selectedCellPath={cellRel(node, 0, 1)} t={t} onPatchNode={vi.fn()} />);
    expect((screen.getByTestId('g7le-inplace-merge-right') as HTMLButtonElement).disabled).toBe(true);
  });

  it('병합된 셀 선택 → 해제 활성 → span 제거', () => {
    const node: EditorNode = {
      type: 'basic', name: 'Table',
      children: [{ type: 'basic', name: 'Tbody', children: [
        { type: 'basic', name: 'Tr', children: [td('a', { colSpan: 2 })] },
        { type: 'basic', name: 'Tr', children: [td('c'), td('d')] },
      ] }],
    };
    const onPatch = vi.fn();
    render(<TableInplaceOverlay node={node} params={params} nodeBox={NODE_BOX} cellBoxes={buildCellBoxes(node)} selectedCellPath={cellRel(node, 0, 0)} t={t} onPatchNode={onPatch} />);
    const unmerge = screen.getByTestId('g7le-inplace-unmerge') as HTMLButtonElement;
    expect(unmerge.disabled).toBe(false);
    fireEvent.click(unmerge);
    expect(cellRefAt(treeToGrid(onPatch.mock.calls[0][0] as EditorNode, params), 0, 0)!.colSpan).toBe(1);
  });
});

describe('TableInplaceOverlay — 셀 테두리(단일/전체)', () => {
  const paramsBorder = {
    ...params,
    cellBorder: {
      sides: [{ value: 'all', prefix: 'border' }],
      widths: [{ value: 'none' }, { value: 'thin', suffix: '' }, { value: 'medium', suffix: '-2' }],
      colors: [{ value: 'gray', token: 'border-gray-300' }],
    },
  };

  it('셀 선택 → 테두리 피커 노출 + 그 셀만 적용', () => {
    const node = table2x2();
    const onPatch = vi.fn();
    render(<TableInplaceOverlay node={node} params={paramsBorder} nodeBox={NODE_BOX} cellBoxes={buildCellBoxes(node)} selectedCellPath={cellRel(node, 0, 0)} t={t} onPatchNode={onPatch} />);
    expect(screen.getByTestId('g7le-inplace-cell-border')).toBeTruthy();
    fireEvent.click(screen.getByTestId('g7le-cell-border-width-thin'));
    const grid = treeToGrid(onPatch.mock.calls[0][0] as EditorNode, paramsBorder);
    expect(String(cellRefAt(grid, 0, 0)!.cell.props?.className ?? '')).toContain('border');
    expect(String(cellRefAt(grid, 1, 1)!.cell.props?.className ?? '')).not.toContain('border');
  });

  it('자유 색 → 선택 셀 인라인 borderColor + 위/왼쪽 비선택 인접 셀 공유변 보정(collapse)', () => {
    // 3x3 (Tbody 3행x3열) — 가운데 (1,1) 선택 후 자유 색. 위(0,1)·왼(1,0) 비선택 인접 보정.
    // 셀에 border 두께 클래스 부여 → 색 피커 노출(width!==none 게이트).
    const bc = (txt: string) => td(txt, { className: 'border' });
    const node: EditorNode = {
      type: 'basic', name: 'Table',
      children: [{ type: 'basic', name: 'Tbody', children: [
        { type: 'basic', name: 'Tr', children: [bc('a'), bc('b'), bc('c')] },
        { type: 'basic', name: 'Tr', children: [bc('d'), bc('e'), bc('f')] },
        { type: 'basic', name: 'Tr', children: [bc('g'), bc('h'), bc('i')] },
      ] }],
    };
    const onPatch = vi.fn();
    render(<TableInplaceOverlay node={node} params={paramsBorder} nodeBox={NODE_BOX} cellBoxes={buildCellBoxes(node)} selectedCellPath={cellRel(node, 1, 1)} t={t} onPatchNode={onPatch} />);
    fireEvent.input(screen.getByTestId('g7le-cell-border-color-picker'), { target: { value: '#ff0000' } });
    const next = onPatch.mock.calls[0][0] as EditorNode;
    const grid = treeToGrid(next, paramsBorder);
    const style = (r: number, c: number) => (cellRefAt(grid, r, c)!.cell.props?.style ?? {}) as Record<string, string>;
    // 선택 셀(1,1) 4변 색.
    expect(style(1, 1).borderColor).toBe('#ff0000');
    // 위 인접(0,1) 아래변 + 왼 인접(1,0) 오른변 보정.
    expect(style(0, 1).borderBottomColor).toBe('#ff0000');
    expect(style(1, 0).borderRightColor).toBe('#ff0000');
  });

  it('영역 색 적용 → 두께 토큰 없는 중간 셀(새 열)에도 기본 두께 부여 + 색(중간 빠짐 결함 방지)', () => {
    // 0행: [border] [없음(새 열)] [border] — 앵커(0,0)에 두께 있어 피커 노출. 영역 0,0~0,2 색 적용.
    const node: EditorNode = {
      type: 'basic', name: 'Table',
      children: [{ type: 'basic', name: 'Tbody', children: [
        { type: 'basic', name: 'Tr', children: [
          td('a', { className: 'border' }), td('mid'), td('c', { className: 'border' }),
        ] },
      ] }],
    };
    const onPatch = vi.fn();
    render(<TableInplaceOverlay node={node} params={paramsBorder} nodeBox={NODE_BOX} cellBoxes={buildCellBoxes(node)} t={t} onPatchNode={onPatch} />);
    fireEvent.click(screen.getByTestId('g7le-inplace-cell-0-0')); // 앵커
    fireEvent.click(screen.getByTestId('g7le-inplace-cell-0-2'), { shiftKey: true }); // 영역 끝(중간 포함)
    fireEvent.input(screen.getByTestId('g7le-cell-border-color-picker'), { target: { value: '#ff0000' } });
    const grid = treeToGrid(onPatch.mock.calls.at(-1)![0] as EditorNode, paramsBorder);
    const mid = cellRefAt(grid, 0, 1)!.cell;
    // 중간 새 셀: 기본 두께 'border' 부여 + 인라인 색 → collapse 후에도 변이 렌더됨.
    expect(((mid.props?.className as string) ?? '').split(/\s+/)).toContain('border');
    expect((mid.props?.style as Record<string, unknown>)?.borderColor).toBe('#ff0000');
  });

  it('"표 전체에 적용" 토글 → 모든 셀에 테두리 일괄', () => {
    const node = table2x2();
    const onPatch = vi.fn();
    render(<TableInplaceOverlay node={node} params={paramsBorder} nodeBox={NODE_BOX} cellBoxes={buildCellBoxes(node)} selectedCellPath={cellRel(node, 0, 0)} t={t} onPatchNode={onPatch} />);
    fireEvent.click(screen.getByTestId('g7le-inplace-border-all-toggle'));
    fireEvent.click(screen.getByTestId('g7le-cell-border-width-thin'));
    const grid = treeToGrid(onPatch.mock.calls[0][0] as EditorNode, paramsBorder);
    for (const [r, c] of [[0, 0], [0, 1], [1, 0], [1, 1]] as const) {
      expect(String(cellRefAt(grid, r, c)!.cell.props?.className ?? '')).toContain('border');
    }
  });
});

describe('TableInplaceOverlay — 셀 배경색/여백(인라인 style)', () => {
  const paramsFill = {
    ...params,
    cellBackground: { colors: [{ value: 'yellow', swatch: '#fef9c3' }, { value: 'blue', swatch: '#dbeafe' }] },
    cellPadding: { steps: [{ value: 'none' }, { value: 'narrow', px: 4 }, { value: 'normal', px: 8 }] },
  };

  it('셀 선택 → 배경/여백 컨트롤 노출', () => {
    const node = table2x2();
    render(<TableInplaceOverlay node={node} params={paramsFill} nodeBox={NODE_BOX} cellBoxes={buildCellBoxes(node)} selectedCellPath={cellRel(node, 0, 0)} t={t} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId('g7le-inplace-cell-fill')).toBeTruthy();
    expect(screen.getByTestId('g7le-inplace-cell-padding')).toBeTruthy();
  });

  it('배경 프리셋 클릭 → 선택 셀만 인라인 backgroundColor', () => {
    const node = table2x2();
    const onPatch = vi.fn();
    render(<TableInplaceOverlay node={node} params={paramsFill} nodeBox={NODE_BOX} cellBoxes={buildCellBoxes(node)} selectedCellPath={cellRel(node, 0, 0)} t={t} onPatchNode={onPatch} />);
    fireEvent.click(screen.getByTestId('g7le-cell-fill-color-yellow'));
    const grid = treeToGrid(onPatch.mock.calls[0][0] as EditorNode, paramsFill);
    expect((cellRefAt(grid, 0, 0)!.cell.props?.style as Record<string, unknown>)?.backgroundColor).toBe('#fef9c3');
    expect((cellRefAt(grid, 1, 1)!.cell.props?.style as Record<string, unknown> | undefined)?.backgroundColor).toBeUndefined();
  });

  it('여백 단계 클릭 → 선택 셀 인라인 padding', () => {
    const node = table2x2();
    const onPatch = vi.fn();
    render(<TableInplaceOverlay node={node} params={paramsFill} nodeBox={NODE_BOX} cellBoxes={buildCellBoxes(node)} selectedCellPath={cellRel(node, 0, 0)} t={t} onPatchNode={onPatch} />);
    fireEvent.click(screen.getByTestId('g7le-cell-padding-step-normal'));
    const grid = treeToGrid(onPatch.mock.calls[0][0] as EditorNode, paramsFill);
    expect((cellRefAt(grid, 0, 0)!.cell.props?.style as Record<string, unknown>)?.padding).toBe('8px');
  });

  it('Shift 영역 선택 → 배경색이 영역 전 셀에 적용', () => {
    const node = table2x2();
    const onPatch = vi.fn();
    render(<TableInplaceOverlay node={node} params={paramsFill} nodeBox={NODE_BOX} cellBoxes={buildCellBoxes(node)} t={t} onPatchNode={onPatch} />);
    fireEvent.click(screen.getByTestId('g7le-inplace-cell-0-0')); // 앵커
    fireEvent.click(screen.getByTestId('g7le-inplace-cell-1-1'), { shiftKey: true }); // 영역 끝
    fireEvent.click(screen.getByTestId('g7le-cell-fill-color-blue'));
    const grid = treeToGrid(onPatch.mock.calls.at(-1)![0] as EditorNode, paramsFill);
    for (const [r, c] of [[0, 0], [0, 1], [1, 0], [1, 1]] as const) {
      expect((cellRefAt(grid, r, c)!.cell.props?.style as Record<string, unknown>)?.backgroundColor).toBe('#dbeafe');
    }
  });

  it('"표 전체에 적용" 토글 + 배경 → 모든 셀 backgroundColor', () => {
    const node = table2x2();
    const onPatch = vi.fn();
    render(<TableInplaceOverlay node={node} params={{ ...paramsFill, ...{ cellBorder: { sides: [{ value: 'all', prefix: 'border' }], widths: [{ value: 'none' }, { value: 'thin', suffix: '' }], colors: [] } } }} nodeBox={NODE_BOX} cellBoxes={buildCellBoxes(node)} selectedCellPath={cellRel(node, 0, 0)} t={t} onPatchNode={onPatch} />);
    fireEvent.click(screen.getByTestId('g7le-inplace-border-all-toggle'));
    fireEvent.click(screen.getByTestId('g7le-cell-fill-color-yellow'));
    const grid = treeToGrid(onPatch.mock.calls.at(-1)![0] as EditorNode, paramsFill);
    for (const [r, c] of [[0, 0], [0, 1], [1, 0], [1, 1]] as const) {
      expect((cellRefAt(grid, r, c)!.cell.props?.style as Record<string, unknown>)?.backgroundColor).toBe('#fef9c3');
    }
  });
});

// ── 시나리오 cross product 커버리지 마킹 ──
// 66 케이스 = operation(9) × span_context(4) × template(2), exclusions 적용. 단위/E2E 가
// 위 describe 블록 + table-inplace-overlay.spec.ts 로 검증(라이브 반영·저장은 E2E).
// @scenario dispatch=registered_kind, operation=add_row, span_context=no_span, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=add_row, span_context=no_span, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=add_row, span_context=colspan, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=add_row, span_context=colspan, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=add_row, span_context=rowspan, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=add_row, span_context=rowspan, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=add_row, span_context=band_block, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=add_row, span_context=band_block, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=remove_row, span_context=no_span, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=remove_row, span_context=no_span, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=remove_row, span_context=colspan, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=remove_row, span_context=colspan, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=remove_row, span_context=rowspan, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=remove_row, span_context=rowspan, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=remove_row, span_context=band_block, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=remove_row, span_context=band_block, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=move_row, span_context=no_span, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=move_row, span_context=no_span, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=move_row, span_context=colspan, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=move_row, span_context=colspan, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=move_row, span_context=rowspan, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=move_row, span_context=rowspan, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=move_row, span_context=band_block, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=move_row, span_context=band_block, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=add_col, span_context=no_span, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=add_col, span_context=no_span, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=add_col, span_context=colspan, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=add_col, span_context=colspan, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=add_col, span_context=rowspan, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=add_col, span_context=rowspan, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=add_col, span_context=band_block, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=add_col, span_context=band_block, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=remove_col, span_context=no_span, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=remove_col, span_context=no_span, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=remove_col, span_context=colspan, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=remove_col, span_context=colspan, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=remove_col, span_context=rowspan, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=remove_col, span_context=rowspan, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=remove_col, span_context=band_block, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=remove_col, span_context=band_block, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=move_col, span_context=no_span, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=move_col, span_context=no_span, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=move_col, span_context=colspan, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=move_col, span_context=colspan, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=move_col, span_context=rowspan, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=move_col, span_context=rowspan, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=move_col, span_context=band_block, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=move_col, span_context=band_block, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=merge, span_context=no_span, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=merge, span_context=no_span, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=merge, span_context=colspan, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=merge, span_context=colspan, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=merge, span_context=rowspan, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=merge, span_context=rowspan, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=merge, span_context=band_block, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=merge, span_context=band_block, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=unmerge, span_context=no_span, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=unmerge, span_context=no_span, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=unmerge, span_context=colspan, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=unmerge, span_context=colspan, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=unmerge, span_context=rowspan, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=unmerge, span_context=rowspan, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=unmerge, span_context=band_block, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=unmerge, span_context=band_block, template=sirsoft-admin_basic
// @scenario dispatch=registered_kind, operation=select_cell, span_context=no_span, template=sirsoft-basic
// @scenario dispatch=registered_kind, operation=select_cell, span_context=no_span, template=sirsoft-admin_basic
