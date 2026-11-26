/**
 * tableGridSpanEdges.test.ts — 병합(rowSpan/colSpan)이 걸친 행과 열의 추가와 삭제
 *
 * 결함: addColumn 은 경계 왼쪽이나 오른쪽 칸이 위에서 내려온 흡수칸이기만 하면 경계를 가로지르지 않아도
 * 그 행을 건너뛰어, 흡수된 행에 새 칸이 생기지 않고 표가 깨졌다(valid=false). removeRow 는 지우는 행에서
 * 시작하는 세로 병합 셀을 행째 버려, 아래 행의 칸이 왼쪽으로 밀렸다. 같은 꼴의 나머지 연산(colSpan 이 걸친
 * 열에 행 추가, 병합 블록을 가로지르는 열 추가, 흡수칸 열 삭제)은 회귀 잠금으로 함께 잰다.
 */

import { describe, it, expect } from 'vitest';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import { treeToGrid, cellRefAt } from '../../spec/tableGridModel';
import { addColumn, addRow, removeColumn, removeRow } from '../../spec/tableGridMutations';

const PARAMS = {
  rowContainer: 'Tbody',
  row: 'Tr',
  cell: 'Td',
  headerCell: 'Th',
  colSpanProp: 'colSpan',
  rowSpanProp: 'rowSpan',
};

function td(text: string, props?: Record<string, unknown>): EditorNode {
  return { type: 'basic', name: 'Td', text, ...(props ? { props } : {}) };
}
function body(rows: EditorNode[][]): EditorNode {
  return {
    type: 'basic',
    name: 'Table',
    children: [{ type: 'basic', name: 'Tbody', children: rows.map((cells) => ({ type: 'basic', name: 'Tr', children: cells })) }],
  };
}
/** 행마다 origin 셀 텍스트(왼쪽부터). */
function texts(t: EditorNode): string[][] {
  return treeToGrid(t, PARAMS).rows.map((r) => r.cells.map((c) => String(c.cell.text ?? '')));
}

describe('열 추가 — 세로 병합 옆 경계', () => {
  /**
   * @scenario subject=table_grid_span_edges
   * @effects grid_add_col_left_absorbed_row_gets_new_cell
   */
  it('왼쪽이 흡수칸인 행: [[tall(rowSpan2), b], [d]] 에 at=0 → 아래 행에도 새 칸, 세로 병합 유지', () => {
    const next = addColumn(body([[td('tall', { rowSpan: 2 }), td('b')], [td('d')]]), PARAMS, 0);
    const grid = treeToGrid(next, PARAMS);
    expect(grid.valid).toBe(true);
    expect(grid.colCount).toBe(3);
    expect(texts(next)).toEqual([['tall', '', 'b'], ['', 'd']]);
    expect(cellRefAt(grid, 1, 0)!.cell.text).toBe('tall');
    expect(cellRefAt(grid, 1, 2)!.cell.text).toBe('d');
  });

  /**
   * @scenario subject=table_grid_span_edges
   * @effects grid_add_col_right_absorbed_row_gets_new_cell
   */
  it('오른쪽이 흡수칸인 행: [[a, tall(rowSpan2)], [c]] 에 at=0 → 아래 행 c 뒤에 새 칸, 세로 병합은 오른쪽으로', () => {
    const next = addColumn(body([[td('a'), td('tall', { rowSpan: 2 })], [td('c')]]), PARAMS, 0);
    const grid = treeToGrid(next, PARAMS);
    expect(grid.valid).toBe(true);
    expect(texts(next)).toEqual([['a', '', 'tall'], ['c', '']]);
    expect(cellRefAt(grid, 1, 2)!.cell.text).toBe('tall');
  });

  /**
   * @scenario subject=table_grid_span_edges
   * @effects grid_add_col_through_merged_block_widens_it
   */
  it('가로 세로 병합 블록 안 경계: [[big(colSpan2,rowSpan2), x], [y]] 에 at=0 → 블록 colSpan 3, 새 셀 없음', () => {
    const next = addColumn(body([[td('big', { colSpan: 2, rowSpan: 2 }), td('x')], [td('y')]]), PARAMS, 0);
    const grid = treeToGrid(next, PARAMS);
    expect(grid.valid).toBe(true);
    expect(cellRefAt(grid, 0, 0)!.colSpan).toBe(3);
    expect(texts(next)).toEqual([['big', 'x'], ['y']]);
  });
});

describe('행 추가 — 병합이 걸친 열', () => {
  /**
   * @scenario subject=table_grid_span_edges
   * @effects grid_add_row_under_colspan_fills_each_col
   */
  it('가로 병합 행 아래: [[wide(colSpan2)], [a, b]] 에 at=0 → 새 행은 열마다 칸 하나', () => {
    const next = addRow(body([[td('wide', { colSpan: 2 })], [td('a'), td('b')]]), PARAMS, 0);
    const grid = treeToGrid(next, PARAMS);
    expect(grid.valid).toBe(true);
    expect(texts(next)).toEqual([['wide'], ['', ''], ['a', 'b']]);
  });

  /**
   * @scenario subject=table_grid_span_edges
   * @effects grid_add_row_inside_rowspan_extends_it
   */
  it('세로 병합 사이: [[tall(rowSpan2), b], [d]] 에 at=0 → 병합 rowSpan 3, 새 행은 병합 밖 열만', () => {
    const next = addRow(body([[td('tall', { rowSpan: 2 }), td('b')], [td('d')]]), PARAMS, 0);
    const grid = treeToGrid(next, PARAMS);
    expect(grid.valid).toBe(true);
    expect(cellRefAt(grid, 0, 0)!.rowSpan).toBe(3);
    expect(texts(next)).toEqual([['tall', 'b'], [''], ['d']]);
  });
});

describe('행과 열 삭제 — 병합이 걸친 경우', () => {
  /**
   * @scenario subject=table_grid_span_edges
   * @effects grid_remove_row_moves_rowspan_origin_down
   */
  it('세로 병합이 시작하는 행 삭제: [[tall(rowSpan2), b], [d]] 에서 0행 → tall 이 아래 행으로 내려가고 d 는 제 열', () => {
    const next = removeRow(body([[td('tall', { rowSpan: 2 }), td('b')], [td('d')]]), PARAMS, 0);
    const grid = treeToGrid(next, PARAMS);
    expect(grid.valid).toBe(true);
    expect(grid.colCount).toBe(2);
    expect(texts(next)).toEqual([['tall', 'd']]);
    expect(cellRefAt(grid, 0, 0)!.rowSpan).toBe(1);
  });

  /**
   * @scenario subject=table_grid_span_edges
   * @effects grid_remove_row_moves_rowspan_origin_down
   */
  it('오른쪽 세로 병합이 시작하는 행 삭제: [[a, tall(rowSpan3)], [c], [e]] 에서 0행 → tall rowSpan 2 로 c 뒤에', () => {
    const next = removeRow(body([[td('a'), td('tall', { rowSpan: 3 })], [td('c')], [td('e')]]), PARAMS, 0);
    const grid = treeToGrid(next, PARAMS);
    expect(grid.valid).toBe(true);
    expect(texts(next)).toEqual([['c', 'tall'], ['e']]);
    expect(cellRefAt(grid, 0, 1)!.rowSpan).toBe(2);
  });

  /**
   * @scenario subject=table_grid_span_edges
   * @effects grid_remove_col_under_colspan_shrinks_it
   */
  it('가로 병합이 걸친 열 삭제: [[wide(colSpan2), z], [a, b, c]] 에서 1열 → wide colSpan 1, b 만 빠짐', () => {
    const next = removeColumn(body([[td('wide', { colSpan: 2 }), td('z')], [td('a'), td('b'), td('c')]]), PARAMS, 1);
    const grid = treeToGrid(next, PARAMS);
    expect(grid.valid).toBe(true);
    expect(texts(next)).toEqual([['wide', 'z'], ['a', 'c']]);
    expect(cellRefAt(grid, 0, 0)!.colSpan).toBe(1);
  });
});
