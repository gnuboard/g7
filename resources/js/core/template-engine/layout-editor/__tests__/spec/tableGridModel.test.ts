/**
 * tableGridModel.test.ts — 표 트리↔논리 grid 변환 + 변형 연산
 *
 * 코어 빌트인 Table 에디터의 데이터 모델을 전수 가드한다: grid 변환(병합 좌표),
 * 행/열 추가·삭제·이동, 셀 병합/해제 round-trip, 겹침/구멍 검출·거부, 행/열 삽입 시
 * span 보정, 복합 셀(자식 트리) 보존, cellBorder className 적용.
 */

import { describe, it, expect } from 'vitest';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import {
  treeToGrid,
  cellRefAt,
  resolveParams,
  canMoveRow,
  canMoveColumn,
} from '../../spec/tableGridModel';
import {
  addRow,
  removeRow,
  moveRow,
  addColumn,
  removeColumn,
  moveColumn,
  mergeCells,
  unmergeCell,
  setCellClassName,
  setCellText,
  setCellNode,
} from '../../spec/tableGridMutations';

/** 셀 생성 헬퍼(텍스트 또는 자식). */
function td(text?: string, props?: Record<string, unknown>, children?: EditorNode[]): EditorNode {
  const n: EditorNode = { type: 'basic', name: 'Td' };
  if (text !== undefined) n.text = text;
  if (props) n.props = props;
  if (children) n.children = children;
  return n;
}
function th(text?: string): EditorNode {
  return { type: 'basic', name: 'Th', ...(text !== undefined ? { text } : {}) };
}
function tr(cells: EditorNode[]): EditorNode {
  return { type: 'basic', name: 'Tr', children: cells };
}
function tbody(rows: EditorNode[]): EditorNode {
  return { type: 'basic', name: 'Tbody', children: rows };
}
function thead(rows: EditorNode[]): EditorNode {
  return { type: 'basic', name: 'Thead', children: rows };
}
function table(children: EditorNode[]): EditorNode {
  return { type: 'basic', name: 'Table', children };
}

/** 표준 2×2 표(Thead 1행 + Tbody 2행). */
function table2x2(): EditorNode {
  return table([
    thead([tr([th('H1'), th('H2')])]),
    tbody([tr([td('a'), td('b')]), tr([td('c'), td('d')])]),
  ]);
}

const PARAMS = null; // 표준 HTML 어휘 기본값 사용

describe('tableGridModel.treeToGrid', () => {
  /**
   * @scenario behavior=tree_to_grid_expands_thead_tbody_with_merge_coordinates
   * @effects tree_to_grid_expands_thead_tbody_with_merge_coordinates
   */
  it('Thead+Tbody 를 누적 grid 로 펼친다(3행×2열)', () => {
    const grid = treeToGrid(table2x2(), PARAMS);
    expect(grid.colCount).toBe(2);
    expect(grid.rows.length).toBe(3); // header 1 + body 2
    expect(grid.valid).toBe(true);
    expect(grid.sections.length).toBe(2);
    expect(grid.sections[0]!.isHeader).toBe(true);
  });

  /**
   * @scenario behavior=tree_to_grid_expands_thead_tbody_with_merge_coordinates
   * @effects tree_to_grid_expands_thead_tbody_with_merge_coordinates
   */
  it('colSpan 셀의 좌표/너비를 반영한다', () => {
    const t = table([
      tbody([
        tr([{ ...td('wide'), props: { colSpan: 2 } }]),
        tr([td('a'), td('b')]),
      ]),
    ]);
    const grid = treeToGrid(t, PARAMS);
    expect(grid.colCount).toBe(2);
    const wide = cellRefAt(grid, 0, 0)!;
    expect(wide.colSpan).toBe(2);
    // (0,1) 도 같은 origin 을 가리킨다(흡수칸).
    expect(cellRefAt(grid, 0, 1)!.cell).toBe(wide.cell);
  });

  /**
   * @scenario behavior=tree_to_grid_expands_thead_tbody_with_merge_coordinates
   * @effects tree_to_grid_expands_thead_tbody_with_merge_coordinates
   */
  it('rowSpan 셀이 아래 행 열을 흡수한다', () => {
    const t = table([
      tbody([
        tr([{ ...td('tall'), props: { rowSpan: 2 } }, td('b')]),
        tr([td('d')]), // 한 칸만 — tall 이 (1,0) 흡수
      ]),
    ]);
    const grid = treeToGrid(t, PARAMS);
    expect(grid.colCount).toBe(2);
    expect(grid.valid).toBe(true);
    const tall = cellRefAt(grid, 0, 0)!;
    expect(tall.rowSpan).toBe(2);
    expect(cellRefAt(grid, 1, 0)!.cell).toBe(tall.cell); // 흡수
    expect(cellRefAt(grid, 1, 1)!.cell).toBe(grid.rows[1]!.cells.find((c) => c.gridCol === 1)!.cell);
  });

  /**
   * @scenario behavior=tree_to_grid_detects_overlap_and_hole_marks_invalid
   * @effects tree_to_grid_detects_overlap_and_hole_marks_invalid
   */
  it('과잉 colSpan 은 구멍(hole)으로 검출한다', () => {
    const t = table([
      tbody([
        tr([{ ...td('x'), props: { colSpan: 3 } }]), // 2열 표에 colSpan 3
        tr([td('a'), td('b')]),
      ]),
    ]);
    const grid = treeToGrid(t, PARAMS);
    // colCount 가 3 으로 늘어 두 번째 행(2칸)에 (1,2) 구멍 → invalid.
    expect(grid.colCount).toBe(3);
    expect(grid.issues.some((s) => s.includes('hole'))).toBe(true);
    expect(grid.valid).toBe(false);
  });

  /**
   * @scenario behavior=tree_to_grid_detects_overlap_and_hole_marks_invalid
   * @effects tree_to_grid_detects_overlap_and_hole_marks_invalid
   */
  it('rowSpan 흡수칸을 colSpan 이 덮으면 겹침(overlap)으로 검출한다', () => {
    const t = table([
      tbody([
        tr([td('x'), { ...td('tall'), props: { rowSpan: 2 } }]), // (0,1)~(1,1) 점유
        tr([{ ...td('wide'), props: { colSpan: 2 } }]), // (1,0)~(1,1) — (1,1) 이 tall 과 겹침
      ]),
    ]);
    const grid = treeToGrid(t, PARAMS);
    expect(grid.issues).toContain('overlap at row 1, col 1');
    expect(grid.valid).toBe(false);
  });
});

describe('행 연산', () => {
  // @scenario cell_kind=direct_text, operation=add_row, span_context=no_span, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=add_row, span_context=no_span, template=sirsoft-admin_basic
  /**
   * @scenario behavior=add_row_inserts_blank_row_keeps_col_count
   * @effects add_row_inserts_blank_row_keeps_col_count
   */
  it('addRow: grid 행 다음에 빈 행 삽입(열 수 유지)', () => {
    const next = addRow(table2x2(), PARAMS, 1); // body 첫 행 다음
    const grid = treeToGrid(next, PARAMS);
    expect(grid.rows.length).toBe(4);
    expect(grid.colCount).toBe(2);
    expect(grid.valid).toBe(true);
  });

  // @scenario cell_kind=direct_text, operation=add_row, span_context=blocked_by_span, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=add_row, span_context=blocked_by_span, template=sirsoft-admin_basic
  /**
   * @scenario behavior=add_row_bumps_rowspan_origin_for_absorbed_cells
   * @effects add_row_bumps_rowspan_origin_for_absorbed_cells
   */
  it('addRow: rowSpan 흡수칸은 origin rowSpan++ (셀 미생성)', () => {
    const t = table([
      tbody([
        tr([{ ...td('tall'), props: { rowSpan: 2 } }, td('b')]),
        tr([td('d')]),
      ]),
    ]);
    // grid 행 0(tall 시작) 다음에 행 추가 → tall 이 새 행을 가로지름 → rowSpan 3.
    const next = addRow(t, PARAMS, 0);
    const grid = treeToGrid(next, PARAMS);
    expect(grid.valid).toBe(true);
    const tall = cellRefAt(grid, 0, 0)!;
    expect(tall.rowSpan).toBe(3);
    expect(grid.rows.length).toBe(3);
  });

  // @scenario cell_kind=direct_text, operation=remove_row, span_context=rowspan, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=remove_row, span_context=rowspan, template=sirsoft-admin_basic
  /**
   * @scenario behavior=remove_row_decrements_crossing_rowspan
   * @effects remove_row_decrements_crossing_rowspan
   */
  it('removeRow: 행 제거 + 내려오던 rowSpan 감소', () => {
    const t = table([
      tbody([
        tr([{ ...td('tall'), props: { rowSpan: 2 } }, td('b')]),
        tr([td('d')]),
      ]),
    ]);
    const next = removeRow(t, PARAMS, 1); // tall 흡수 행 제거
    const grid = treeToGrid(next, PARAMS);
    expect(grid.rows.length).toBe(1);
    expect(grid.valid).toBe(true);
    expect(cellRefAt(grid, 0, 0)!.rowSpan).toBe(1); // 감소
  });

  /**
   * @scenario behavior=remove_row_keeps_min_one_row
   * @effects remove_row_keeps_min_one_row
   */
  it('removeRow: 마지막 1행은 유지(no-op)', () => {
    const t = table([tbody([tr([td('only')])])]);
    expect(removeRow(t, PARAMS, 0)).toBe(t);
  });

  // @scenario cell_kind=direct_text, operation=move_row, span_context=no_span, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=move_row, span_context=no_span, template=sirsoft-admin_basic
  /**
   * @scenario behavior=move_row_adjacent_swap
   * @effects table_move_row_adjacent_swap
   */
  it('moveRow: 인접 스왑(병합 없을 때)', () => {
    const t = table([tbody([tr([td('a')]), tr([td('b')]), tr([td('c')])])]);
    const next = moveRow(t, PARAMS, 0, 1); // a↔b
    const grid = treeToGrid(next, PARAMS);
    expect((grid.rows[0]!.cells[0]!.cell.text)).toBe('b');
    expect((grid.rows[1]!.cells[0]!.cell.text)).toBe('a');
  });

  // @scenario cell_kind=direct_text, operation=move_row, span_context=blocked_by_span, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=move_row, span_context=blocked_by_span, template=sirsoft-admin_basic
  /**
   * @scenario behavior=move_whole_table_band_no_op
   * @effects table_move_whole_table_band_no_op
   */
  it('moveRow: rowSpan 이 표 전체를 한 밴드로 묶으면 인접 밴드 없음 → no-op(경계)', () => {
    // tall(rowSpan2)이 행0-1 을 한 밴드로 묶음 → 밴드 1개 → 이동 대상 없음.
    const t = table([
      tbody([
        tr([{ ...td('tall'), props: { rowSpan: 2 } }, td('b')]),
        tr([td('d')]),
      ]),
    ]);
    expect(moveRow(t, PARAMS, 0, 1)).toBe(t);
  });

  /**
   * @scenario behavior=move_row_band_moves_merge_block_as_unit
   * @effects move_row_band_moves_merge_block_as_unit
   */
  it('moveRow: rowSpan 병합 블록(밴드)을 인접 단일 행과 통째 이동', () => {
    // 행0-1 이 tall(rowSpan2) 밴드, 행2 가 단일 행 밴드 → 밴드 통째 스왑.
    const t = table([
      tbody([
        tr([{ ...td('tall'), props: { rowSpan: 2 } }, td('b1')]),
        tr([td('b2')]),
        tr([td('c1'), td('c2')]),
      ]),
    ]);
    // 행2(단일) 를 위로 → [c][tall블록] 순서.
    const next = moveRow(t, PARAMS, 2, -1);
    const grid = treeToGrid(next, PARAMS);
    expect(grid.valid).toBe(true);
    // 첫 행이 이제 c 행(c1,c2), 그 다음이 tall 블록.
    expect(grid.rows[0]!.cells[0]!.cell.text).toBe('c1');
    expect(cellRefAt(grid, 1, 0)!.rowSpan).toBe(2); // tall 블록 보존
    expect(cellRefAt(grid, 1, 0)!.cell.text).toBe('tall');
  });
});

describe('열 연산', () => {
  // @scenario cell_kind=direct_text, operation=add_col, span_context=no_span, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=add_col, span_context=no_span, template=sirsoft-admin_basic
  /**
   * @scenario behavior=add_column_inserts_blank_col
   * @effects add_column_inserts_blank_col
   */
  it('addColumn: grid 열 다음에 빈 열 삽입', () => {
    const next = addColumn(table2x2(), PARAMS, 0);
    const grid = treeToGrid(next, PARAMS);
    expect(grid.colCount).toBe(3);
    expect(grid.valid).toBe(true);
  });

  // @scenario cell_kind=direct_text, operation=add_col, span_context=blocked_by_span, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=add_col, span_context=blocked_by_span, template=sirsoft-admin_basic
  /**
   * @scenario behavior=add_column_bumps_colspan_for_crossing_cell
   * @effects add_column_bumps_colspan_for_crossing_cell
   */
  it('addColumn: 경계 가로지르는 colSpan 셀은 colSpan++', () => {
    const t = table([
      tbody([
        tr([{ ...td('wide'), props: { colSpan: 2 } }]),
        tr([td('a'), td('b')]),
      ]),
    ]);
    // grid 열 0 다음(경계=열1) — wide 가 0~1 을 덮어 경계 가로지름 → colSpan 3.
    const next = addColumn(t, PARAMS, 0);
    const grid = treeToGrid(next, PARAMS);
    expect(grid.colCount).toBe(3);
    expect(grid.valid).toBe(true);
    expect(cellRefAt(grid, 0, 0)!.colSpan).toBe(3);
  });

  // @scenario cell_kind=direct_text, operation=remove_col, span_context=colspan, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=remove_col, span_context=colspan, template=sirsoft-admin_basic
  /**
   * @scenario behavior=remove_column_decrements_crossing_colspan
   * @effects remove_column_decrements_crossing_colspan
   */
  it('removeColumn: 열 제거 + 가로지르는 colSpan 감소', () => {
    const t = table([
      tbody([
        tr([{ ...td('wide'), props: { colSpan: 2 } }, td('z')]),
        tr([td('a'), td('b'), td('c')]),
      ]),
    ]);
    const next = removeColumn(t, PARAMS, 0); // wide colSpan--
    const grid = treeToGrid(next, PARAMS);
    expect(grid.colCount).toBe(2);
    expect(grid.valid).toBe(true);
    expect(cellRefAt(grid, 0, 0)!.colSpan).toBe(1);
  });

  /**
   * @scenario behavior=remove_column_keeps_min_one_col
   * @effects remove_column_keeps_min_one_col
   */
  it('removeColumn: 마지막 1열 유지(no-op)', () => {
    const t = table([tbody([tr([td('only')])])]);
    expect(removeColumn(t, PARAMS, 0)).toBe(t);
  });

  // @scenario cell_kind=direct_text, operation=move_col, span_context=no_span, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=move_col, span_context=no_span, template=sirsoft-admin_basic
  /**
   * @scenario behavior=move_column_adjacent_swap
   * @effects table_move_column_adjacent_swap
   */
  it('moveColumn: 단일 셀 인접 스왑', () => {
    const next = moveColumn(table2x2(), PARAMS, 0, 1);
    const grid = treeToGrid(next, PARAMS);
    // 헤더 행도 같이 움직여야 한다(본문만 옮기면 열 머리글이 어긋난다).
    expect(grid.rows[0]!.cells.map((c) => c.cell.text)).toEqual(['H2', 'H1']);
    expect(grid.rows[1]!.cells[0]!.cell.text).toBe('b');
    expect(grid.rows[1]!.cells[1]!.cell.text).toBe('a');
  });

  // @scenario cell_kind=direct_text, operation=move_col, span_context=blocked_by_span, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=move_col, span_context=blocked_by_span, template=sirsoft-admin_basic
  /**
   * @scenario behavior=move_whole_table_band_no_op
   * @effects table_move_whole_table_band_no_op
   */
  it('moveColumn: colSpan 이 표 전체를 한 밴드로 묶으면 인접 밴드 없음 → no-op', () => {
    const t = table([
      tbody([tr([{ ...td('wide'), props: { colSpan: 2 } }]), tr([td('a'), td('b')])]),
    ]);
    expect(moveColumn(t, PARAMS, 0, 1)).toBe(t);
  });
});

describe('병합 블록 단위 열/행 이동', () => {
  // 1,2,3,4,5,6 열 중 (1,2)·(4,5) 병합 → 셀 경계 밴드 [(1,2)][3][(4,5)][6].
  // 헤더 행은 병합, 본문 행은 단일 셀 6개. 모든 행에서 col2/col4 경계가 깨끗해야 밴드 성립.
  function bandedTable(): EditorNode {
    return table([
      thead([
        tr([
          { ...td('h12'), props: { colSpan: 2 } }, // 밴드 [0,2)
          td('h3'), // 밴드 [2,3)
          { ...td('h45'), props: { colSpan: 2 } }, // 밴드 [3,5)
          td('h6'), // 밴드 [5,6)
        ]),
      ]),
      tbody([tr([td('1'), td('2'), td('3'), td('4'), td('5'), td('6')])]),
    ]);
  }

  /**
   * @scenario behavior=move_column_band_moves_merge_block_as_unit
   * @effects move_column_band_moves_merge_block_as_unit
   */
  it('6 을 좌측 이동 → 1,2,3,6,4,5 (밴드 [6] 가 [(4,5)] 너머로)', () => {
    const t = bandedTable();
    // gridCol 5 가 [6] 밴드 → 좌측 이동.
    const next = moveColumn(t, PARAMS, 5, -1);
    const grid = treeToGrid(next, PARAMS);
    expect(grid.valid).toBe(true);
    // 본문 행 순서가 1,2,3,6,4,5.
    const body = grid.rows[1]!.cells.map((c) => c.cell.text);
    expect(body).toEqual(['1', '2', '3', '6', '4', '5']);
    // 헤더 밴드도 [(1,2)][3][6][(4,5)] — h6 가 h45 앞.
    const head = grid.rows[0]!.cells.map((c) => c.cell.text);
    expect(head).toEqual(['h12', 'h3', 'h6', 'h45']);
  });

  /**
   * @scenario behavior=move_column_band_moves_merge_block_as_unit
   * @effects move_column_band_moves_merge_block_as_unit
   */
  it('(4,5) 를 좌측 이동 → 1,2,4,5,3,6 (밴드 [(4,5)] 가 [3] 너머로)', () => {
    const t = bandedTable();
    // gridCol 3 이 [(4,5)] 밴드 → 좌측 이동.
    const next = moveColumn(t, PARAMS, 3, -1);
    const grid = treeToGrid(next, PARAMS);
    expect(grid.valid).toBe(true);
    const body = grid.rows[1]!.cells.map((c) => c.cell.text);
    expect(body).toEqual(['1', '2', '4', '5', '3', '6']);
    const head = grid.rows[0]!.cells.map((c) => c.cell.text);
    expect(head).toEqual(['h12', 'h45', 'h3', 'h6']);
  });

  /**
   * @scenario behavior=move_column_band_moves_merge_block_as_unit
   * @effects move_column_band_moves_merge_block_as_unit
   */
  it('병합 블록을 우측 이동도 대칭 동작', () => {
    const t = bandedTable();
    // [3] 밴드(gridCol 2)를 우측 → [(4,5)] 너머 → 1,2,4,5,3,6.
    const next = moveColumn(t, PARAMS, 2, 1);
    const grid = treeToGrid(next, PARAMS);
    expect(grid.valid).toBe(true);
    const body = grid.rows[1]!.cells.map((c) => c.cell.text);
    expect(body).toEqual(['1', '2', '4', '5', '3', '6']);
    const head = grid.rows[0]!.cells.map((c) => c.cell.text);
    expect(head).toEqual(['h12', 'h45', 'h3', 'h6']);
  });

  /**
   * @scenario behavior=move_column_table_edge_band_disabled_boundary
   * @effects move_column_table_edge_band_disabled_boundary
   */
  it('canMoveColumn: 병합 블록도 밴드 인접하면 이동 가능, 표 경계는 boundary', () => {
    const grid = treeToGrid(bandedTable(), PARAMS);
    expect(canMoveColumn(grid, 5, -1).ok).toBe(true); // [6] 좌측 가능
    expect(canMoveColumn(grid, 0, -1)).toEqual({ ok: false, reason: 'boundary' }); // 첫 밴드 좌측
    expect(canMoveColumn(grid, 5, 1)).toEqual({ ok: false, reason: 'boundary' }); // 마지막 밴드 우측
  });

  /**
   * @scenario behavior=move_column_band_moves_merge_block_as_unit
   * @effects move_column_band_moves_merge_block_as_unit
   */
  it('canMoveColumn: 밴드 경계가 행마다 어긋나면(한 행만 병합) 그 두 열은 같은 밴드 → 통째 이동', () => {
    // 헤더만 (1,2) 병합, 본문은 단일 → col1 경계가 헤더에서 안 깨끗 → [0,2) 한 밴드.
    const t = table([
      thead([tr([{ ...td('h12'), props: { colSpan: 2 } }, td('h3')])]),
      tbody([tr([td('1'), td('2'), td('3')])]),
    ]);
    const grid = treeToGrid(t, PARAMS);
    // 밴드 = [(0,2)][2,3) → col0/col1 은 한 밴드(개별 이동 불가), 밴드는 [3] 과 스왑 가능.
    expect(canMoveColumn(grid, 0, 1).ok).toBe(true); // [(1,2)] 밴드를 [3] 너머로
    const next = moveColumn(t, PARAMS, 0, 1);
    const body = treeToGrid(next, PARAMS).rows[1]!.cells.map((c) => c.cell.text);
    expect(body).toEqual(['3', '1', '2']); // 밴드 통째 이동
  });
});

describe('이동 가능 판정(canMoveRow/canMoveColumn, UI 비활성화 피드백)', () => {
  /**
   * @scenario behavior=can_move_adjacent_allowed
   * @effects table_can_move_adjacent_allowed
   */
  it('canMoveRow: 병합 없는 인접 행은 이동 가능', () => {
    const grid = treeToGrid(
      table([tbody([tr([td('a')]), tr([td('b')]), tr([td('c')])])]),
      PARAMS,
    );
    expect(canMoveRow(grid, 0, 1).ok).toBe(true);
    expect(canMoveRow(grid, 1, -1).ok).toBe(true);
  });

  /**
   * @scenario behavior=can_move_boundary_rejected
   * @effects table_can_move_boundary_rejected
   */
  it('canMoveRow: 경계(첫 행 위/마지막 행 아래)는 boundary 사유로 거부', () => {
    const grid = treeToGrid(table([tbody([tr([td('a')]), tr([td('b')])])]), PARAMS);
    expect(canMoveRow(grid, 0, -1)).toEqual({ ok: false, reason: 'boundary' });
    expect(canMoveRow(grid, 1, 1)).toEqual({ ok: false, reason: 'boundary' });
  });

  /**
   * @scenario behavior=can_move_boundary_rejected
   * @effects table_can_move_boundary_rejected
   */
  it('canMoveRow: rowSpan 이 표 전체를 한 밴드로 묶으면 boundary(인접 밴드 없음)', () => {
    // tall(rowSpan2)이 행0-1 을 한 밴드로 묶음 → 밴드 1개 → 이동 대상 없음.
    const grid = treeToGrid(
      table([tbody([tr([{ ...td('tall'), props: { rowSpan: 2 } }, td('b')]), tr([td('d')])])]),
      PARAMS,
    );
    expect(canMoveRow(grid, 0, 1)).toEqual({ ok: false, reason: 'boundary' });
  });

  /**
   * @scenario behavior=move_row_band_moves_merge_block_as_unit
   * @effects move_row_band_moves_merge_block_as_unit
   */
  it('canMoveRow: rowSpan 밴드 + 인접 단일 행이면 이동 가능(밴드 단위)', () => {
    const grid = treeToGrid(
      table([
        tbody([
          tr([{ ...td('tall'), props: { rowSpan: 2 } }, td('b1')]),
          tr([td('b2')]),
          tr([td('c1'), td('c2')]),
        ]),
      ]),
      PARAMS,
    );
    expect(canMoveRow(grid, 0, 1).ok).toBe(true); // tall 밴드 ↓ (인접 [c] 밴드)
    expect(canMoveRow(grid, 2, -1).ok).toBe(true); // [c] 밴드 ↑
  });

  /**
   * @scenario behavior=move_row_section_boundary_disabled_with_reason
   * @effects move_row_section_boundary_disabled_with_reason
   */
  it('canMoveRow: Thead↔Tbody 섹션 경계는 section 사유로 거부', () => {
    const grid = treeToGrid(table2x2(), PARAMS); // row0=Thead, row1=Tbody
    expect(canMoveRow(grid, 0, 1)).toEqual({ ok: false, reason: 'section' });
  });

  /**
   * @scenario behavior=can_move_adjacent_allowed
   * @effects table_can_move_adjacent_allowed
   */
  it('canMoveColumn: 단일 셀 인접 열은 이동 가능', () => {
    const grid = treeToGrid(table([tbody([tr([td('a'), td('b'), td('c')])])]), PARAMS);
    expect(canMoveColumn(grid, 0, 1).ok).toBe(true);
    expect(canMoveColumn(grid, 1, 1).ok).toBe(true);
  });

  /**
   * @scenario behavior=move_column_table_edge_band_disabled_boundary
   * @effects move_column_table_edge_band_disabled_boundary
   */
  it('canMoveColumn: colSpan 이 표 전체를 한 밴드로 묶으면 boundary', () => {
    const grid = treeToGrid(
      table([tbody([tr([{ ...td('wide'), props: { colSpan: 2 } }]), tr([td('a'), td('b')])])]),
      PARAMS,
    );
    expect(canMoveColumn(grid, 0, -1)).toEqual({ ok: false, reason: 'boundary' });
    expect(canMoveColumn(grid, 0, 1)).toEqual({ ok: false, reason: 'boundary' }); // 밴드 1개
  });

  /**
   * @scenario behavior=can_move_matches_move_no_op
   * @effects table_can_move_matches_move_no_op
   */
  it('canMove* 판정은 move* 변형의 no-op 여부와 일치(SSoT)', () => {
    const oneBand = table([
      tbody([tr([{ ...td('wide'), props: { colSpan: 2 } }]), tr([td('a'), td('b')])]),
    ]);
    const grid = treeToGrid(oneBand, PARAMS);
    // 밴드 1개 → 이동 불가 → moveColumn no-op(같은 참조).
    expect(canMoveColumn(grid, 0, 1).ok).toBe(false);
    expect(moveColumn(oneBand, PARAMS, 0, 1)).toBe(oneBand);
  });
});

describe('셀 병합/해제', () => {
  // @scenario cell_kind=direct_text, operation=merge, span_context=no_span, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=merge, span_context=no_span, template=sirsoft-admin_basic
  /**
   * @scenario behavior=shift_select_range_then_merge_sets_origin_span_removes_absorbed
   * @effects shift_select_range_then_merge_sets_origin_span_removes_absorbed
   */
  it('mergeCells: 2×2 영역 병합 → origin colSpan2 rowSpan2 + 흡수 3셀 제거', () => {
    const t = table([
      tbody([tr([td('a'), td('b')]), tr([td('c'), td('d')])]),
    ]);
    const { table: merged, ok } = mergeCells(t, PARAMS, 0, 0, 1, 1);
    expect(ok).toBe(true);
    const grid = treeToGrid(merged, PARAMS);
    expect(grid.valid).toBe(true);
    const o = cellRefAt(grid, 0, 0)!;
    expect(o.colSpan).toBe(2);
    expect(o.rowSpan).toBe(2);
    expect(o.cell.text).toBe('a');
    // 흡수칸은 같은 origin.
    expect(cellRefAt(grid, 1, 1)!.cell).toBe(o.cell);
  });

  /**
   * @scenario behavior=unmerge_clears_span_restores_blank_cells
   * @effects unmerge_clears_span_restores_blank_cells
   */
  it('unmergeCell: 병합 해제 → span 제거 + 빈 셀 복원(round-trip 형태)', () => {
    const t = table([
      tbody([tr([td('a'), td('b')]), tr([td('c'), td('d')])]),
    ]);
    const { table: merged } = mergeCells(t, PARAMS, 0, 0, 1, 1);
    const restored = unmergeCell(merged, PARAMS, 0, 0);
    const grid = treeToGrid(restored, PARAMS);
    expect(grid.valid).toBe(true);
    expect(grid.colCount).toBe(2);
    expect(grid.rows.length).toBe(2);
    expect(cellRefAt(grid, 0, 0)!.colSpan).toBe(1);
    expect(cellRefAt(grid, 0, 0)!.rowSpan).toBe(1);
    // 4칸 모두 존재(origin a 유지 + 3 빈 셀 복원).
    expect(cellRefAt(grid, 0, 1)).not.toBeNull();
    expect(cellRefAt(grid, 1, 0)).not.toBeNull();
    expect(cellRefAt(grid, 1, 1)).not.toBeNull();
  });

  /**
   * @scenario behavior=merge_rejects_single_cell
   * @effects table_merge_rejects_single_cell
   */
  it('mergeCells: 단일 셀 영역 거부', () => {
    const t = table2x2();
    expect(mergeCells(t, PARAMS, 1, 0, 1, 0).ok).toBe(false);
  });

  // @scenario cell_kind=direct_text, operation=merge, span_context=blocked_by_span, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=merge, span_context=blocked_by_span, template=sirsoft-admin_basic
  /**
   * @scenario behavior=merge_rejects_non_rectangular_and_hole
   * @effects merge_rejects_non_rectangular_and_hole
   */
  it('mergeCells: 비직사각형(기존 병합 삐져나옴) 거부', () => {
    // (1,1) 에 rowSpan 2 가 있어 2×2 영역(1..2,0..1) 병합 시 삐져나옴.
    const t = table([
      tbody([
        tr([td('a'), td('b')]),
        tr([td('c'), { ...td('tall'), props: { rowSpan: 2 } }]),
        tr([td('e')]),
      ]),
    ]);
    const res = mergeCells(t, PARAMS, 0, 0, 1, 1);
    expect(res.ok).toBe(false);
    expect(res.reason).toBe('not-rectangular');
  });
});

describe('셀 콘텐츠(복합/텍스트/className)', () => {
  // @scenario cell_kind=direct_text, operation=cell_text, span_context=no_span, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=cell_text, span_context=no_span, template=sirsoft-admin_basic
  /**
   * @scenario behavior=set_cell_text_direct
   * @effects table_set_cell_text_direct
   */
  it('setCellText: 직접 text 셀 갱신', () => {
    const next = setCellText(table2x2(), PARAMS, 1, 0, '$t:custom.x');
    const grid = treeToGrid(next, PARAMS);
    expect(cellRefAt(grid, 1, 0)!.cell.text).toBe('$t:custom.x');
  });

  // @scenario cell_kind=complex_text_descendant, operation=cell_text, span_context=no_span, template=sirsoft-basic
  // @scenario cell_kind=complex_text_descendant, operation=cell_text, span_context=no_span, template=sirsoft-admin_basic
  /**
   * @scenario behavior=complex_cell_edits_text_descendant_preserves_structure
   * @effects complex_cell_edits_text_descendant_preserves_structure
   */
  it('setCellNode: 복합 셀(자식 Span/Icon) 전체 교체로 자손 텍스트만 보존 패치', () => {
    // 셀이 [Icon, Span("라벨")] 구조 — 텍스트는 Span 자손.
    const complexCell = td(undefined, { className: 'flex' }, [
      { type: 'basic', name: 'Icon', props: { name: 'star' } },
      { type: 'basic', name: 'Span', text: '라벨' },
    ]);
    const t = table([tbody([tr([complexCell, td('b')])])]);
    // Span 텍스트만 키 토큰으로 바꾼 셀 사본.
    const patchedCell: EditorNode = {
      ...complexCell,
      children: [
        (complexCell.children as EditorNode[])[0],
        { ...(complexCell.children as EditorNode[])[1]!, text: '$t:custom.k' },
      ],
    };
    const next = setCellNode(t, PARAMS, 0, 0, patchedCell);
    const grid = treeToGrid(next, PARAMS);
    const cell = cellRefAt(grid, 0, 0)!.cell;
    expect((cell.children as EditorNode[])[0]!.name).toBe('Icon'); // 구조 보존
    expect((cell.children as EditorNode[])[1]!.text).toBe('$t:custom.k');
    expect(cell.props?.className).toBe('flex'); // className 보존
  });

  // @scenario cell_kind=direct_text, operation=cell_border, span_context=no_span, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=cell_border, span_context=no_span, template=sirsoft-admin_basic
  /**
   * @scenario behavior=cell_border_className_input_patches_cell_props
   * @effects cell_border_className_input_patches_cell_props
   */
  it('setCellClassName: 셀 className(테두리) 설정 + 빈 문자열 제거', () => {
    const set = setCellClassName(table2x2(), PARAMS, 1, 0, 'border border-red-500');
    let grid = treeToGrid(set, PARAMS);
    expect(cellRefAt(grid, 1, 0)!.cell.props?.className).toBe('border border-red-500');
    const cleared = setCellClassName(set, PARAMS, 1, 0, '');
    grid = treeToGrid(cleared, PARAMS);
    expect(cellRefAt(grid, 1, 0)!.cell.props?.className).toBeUndefined();
  });
});

describe('params 중립성(div-grid 어휘)', () => {
  /**
   * @scenario behavior=params_role_mapping_neutral_div_grid_works_same_adapter
   * @effects params_role_mapping_neutral_div_grid_works_same_adapter
   */
  it('커스텀 역할 매핑으로 div 기반 표도 동일 어댑터', () => {
    const t: EditorNode = {
      type: 'basic',
      name: 'GridTable',
      children: [
        {
          type: 'basic',
          name: 'GridBody',
          children: [
            {
              type: 'basic',
              name: 'GridRow',
              children: [
                { type: 'basic', name: 'GridCell', text: 'a' },
                { type: 'basic', name: 'GridCell', text: 'b' },
              ],
            },
          ],
        },
      ],
    };
    const params = {
      rowContainer: 'GridBody',
      row: 'GridRow',
      cell: 'GridCell',
      headerCell: 'GridHeadCell',
      colSpanProp: 'cols',
      rowSpanProp: 'rows',
    };
    const grid = treeToGrid(t, params);
    expect(grid.colCount).toBe(2);
    expect(grid.valid).toBe(true);
    const next = addColumn(t, params, 0);
    expect(treeToGrid(next, params).colCount).toBe(3);
  });

  /**
   * @scenario behavior=params_default_html_fallback
   * @effects table_params_default_html_fallback
   */
  it('resolveParams: 기본 HTML 어휘 폴백', () => {
    const p = resolveParams(null);
    expect(p.row).toBe('Tr');
    expect(p.cell).toBe('Td');
    expect(p.colSpanProp).toBe('colSpan');
  });
});

// ─────────────────────────────────────────────────────────────────────────
// 시나리오 매트릭스 — 연산 × 셀 종류 × span 문맥(layout-editor-table-editor.yaml)
// span 문맥: no_span(병합 없는 표), colspan(가로 병합 셀이 있는 표), rowspan(세로 병합 셀이 있는 표),
// blocked_by_span(조작 좌표가 병합 셀에 흡수된 칸이거나 병합이 조작을 가로막는 경우).
// 위쪽 시험이 재는 조합은 그 시험 앞에 두었고, 여기는 그 밖의 조합을 잰다. 템플릿 축은 grid 변환과
// 변형이 템플릿 무관 순수 함수라(params 중립성 시험) 두 템플릿 조합을 같은 시험에 병기한다.
// ─────────────────────────────────────────────────────────────────────────

/** 가로 병합 표: 0행 [wide(colSpan2)], 1행 [a, b]. */
function wideTable(): EditorNode {
  return table([tbody([tr([{ ...td('wide'), props: { colSpan: 2 } }]), tr([td('a'), td('b')])])]);
}
/** 세로 병합 표: 0행 [tall(rowSpan2), b], 1행 [d]. */
function tallTable(): EditorNode {
  return table([tbody([tr([{ ...td('tall'), props: { rowSpan: 2 } }, td('b')]), tr([td('d')])])]);
}
/** 복합 셀(자식 Icon + Span 텍스트) — span prop 을 함께 줄 수 있다. */
function complexTd(label: string, props: Record<string, unknown> = {}): EditorNode {
  return td(undefined, { className: 'flex', ...props }, [
    { type: 'basic', name: 'Icon', props: { name: 'star' } },
    { type: 'basic', name: 'Span', text: label },
  ]);
}
/** 복합 셀 사본에서 Span 텍스트만 바꾼다(편집기의 patchTextAtPath 와 같은 모양). */
function withSpanText(cell: EditorNode, text: string): EditorNode {
  const kids = cell.children as EditorNode[];
  return { ...cell, children: [kids[0]!, { ...kids[1]!, text }] };
}

describe('시나리오 매트릭스 — 행 연산 × span 문맥', () => {
  // @scenario cell_kind=direct_text, operation=add_row, span_context=colspan, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=add_row, span_context=colspan, template=sirsoft-admin_basic
  /**
   * @effects grid_add_row_under_colspan_fills_col_count
   */
  it('addRow(colspan 표): 새 행은 열 수만큼 칸을 갖고 가로 병합은 그대로', () => {
    const grid = treeToGrid(addRow(wideTable(), PARAMS, 0), PARAMS);
    expect(grid.valid).toBe(true);
    expect(grid.rows.length).toBe(3);
    expect(grid.rows[1]!.cells.length).toBe(2);
    expect(cellRefAt(grid, 0, 0)!.colSpan).toBe(2);
  });

  // @scenario cell_kind=direct_text, operation=add_row, span_context=rowspan, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=add_row, span_context=rowspan, template=sirsoft-admin_basic
  /**
   * @effects grid_add_row_below_rowspan_keeps_span
   */
  it('addRow(rowspan 표): 병합 끝 아래에 넣으면 세로 병합은 늘지 않고 새 행이 열 전체를 채운다', () => {
    const grid = treeToGrid(addRow(tallTable(), PARAMS, 1), PARAMS);
    expect(grid.valid).toBe(true);
    expect(grid.rows.length).toBe(3);
    expect(cellRefAt(grid, 0, 0)!.rowSpan).toBe(2);
    expect(grid.rows[2]!.cells.length).toBe(2);
  });

  // @scenario cell_kind=direct_text, operation=remove_row, span_context=no_span, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=remove_row, span_context=no_span, template=sirsoft-admin_basic
  /**
   * @effects grid_remove_row_drops_that_row
   */
  it('removeRow(병합 없음): 그 행만 빠지고 나머지 순서 유지', () => {
    const grid = treeToGrid(removeRow(table2x2(), PARAMS, 1), PARAMS);
    expect(grid.valid).toBe(true);
    expect(grid.rows.map((r) => r.cells.map((c) => c.cell.text))).toEqual([['H1', 'H2'], ['c', 'd']]);
  });

  // @scenario cell_kind=direct_text, operation=remove_row, span_context=colspan, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=remove_row, span_context=colspan, template=sirsoft-admin_basic
  /**
   * @effects grid_remove_row_with_colspan_keeps_valid
   */
  it('removeRow(colspan 표): 가로 병합 행을 지우면 남은 표가 유효하다', () => {
    const grid = treeToGrid(removeRow(wideTable(), PARAMS, 0), PARAMS);
    expect(grid.valid).toBe(true);
    expect(grid.colCount).toBe(2);
    expect(grid.rows.map((r) => r.cells.map((c) => c.cell.text))).toEqual([['a', 'b']]);
  });

  // @scenario cell_kind=direct_text, operation=remove_row, span_context=blocked_by_span, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=remove_row, span_context=blocked_by_span, template=sirsoft-admin_basic
  /**
   * @effects grid_remove_rowspan_origin_row_keeps_valid
   */
  it('removeRow(세로 병합의 시작 행): 지운 뒤에도 표에 구멍이 없다', () => {
    const grid = treeToGrid(removeRow(tallTable(), PARAMS, 0), PARAMS);
    expect(grid.rows.length).toBe(1);
    expect(grid.valid).toBe(true);
  });

  // @scenario cell_kind=direct_text, operation=move_row, span_context=colspan, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=move_row, span_context=colspan, template=sirsoft-admin_basic
  /**
   * @effects grid_move_row_with_colspan_swaps_rows
   */
  it('moveRow(colspan 표): 가로 병합 행도 인접 행과 자리를 바꾼다', () => {
    const grid = treeToGrid(moveRow(wideTable(), PARAMS, 0, 1), PARAMS);
    expect(grid.valid).toBe(true);
    expect(grid.rows[0]!.cells.map((c) => c.cell.text)).toEqual(['a', 'b']);
    expect(cellRefAt(grid, 1, 0)!.colSpan).toBe(2);
  });
});

describe('시나리오 매트릭스 — 열 연산 × span 문맥', () => {
  // @scenario cell_kind=direct_text, operation=add_col, span_context=colspan, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=add_col, span_context=colspan, template=sirsoft-admin_basic
  /**
   * @effects grid_add_col_outside_colspan_keeps_span
   */
  it('addColumn(colspan 표): 병합 바깥 경계에 넣으면 가로 병합은 늘지 않는다', () => {
    const grid = treeToGrid(addColumn(wideTable(), PARAMS, 1), PARAMS);
    expect(grid.valid).toBe(true);
    expect(grid.colCount).toBe(3);
    expect(cellRefAt(grid, 0, 0)!.colSpan).toBe(2);
  });

  // @scenario cell_kind=direct_text, operation=add_col, span_context=rowspan, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=add_col, span_context=rowspan, template=sirsoft-admin_basic
  /**
   * @effects grid_add_col_beside_rowspan_fills_each_row
   */
  it('addColumn(rowspan 표): 세로 병합 바로 오른쪽 경계에 넣은 열도 행마다 칸이 생기고 세로 병합은 그대로', () => {
    const grid = treeToGrid(addColumn(tallTable(), PARAMS, 0), PARAMS);
    expect(grid.valid).toBe(true);
    expect(grid.colCount).toBe(3);
    expect(cellRefAt(grid, 0, 0)!.rowSpan).toBe(2);
    expect(grid.rows[1]!.cells.length).toBe(2);
    expect(cellRefAt(grid, 1, 2)).not.toBeNull();
  });

  // @scenario cell_kind=direct_text, operation=remove_col, span_context=no_span, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=remove_col, span_context=no_span, template=sirsoft-admin_basic
  /**
   * @effects grid_remove_col_drops_that_col
   */
  it('removeColumn(병합 없음): 그 열만 빠진다(머리글 포함)', () => {
    const grid = treeToGrid(removeColumn(table2x2(), PARAMS, 0), PARAMS);
    expect(grid.valid).toBe(true);
    expect(grid.rows.map((r) => r.cells.map((c) => c.cell.text))).toEqual([['H2'], ['b'], ['d']]);
  });

  // @scenario cell_kind=direct_text, operation=remove_col, span_context=rowspan, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=remove_col, span_context=rowspan, template=sirsoft-admin_basic
  /**
   * @effects grid_remove_col_beside_rowspan_keeps_span
   */
  it('removeColumn(rowspan 표): 세로 병합 옆 열을 지우면 병합 셀은 남는다', () => {
    const grid = treeToGrid(removeColumn(tallTable(), PARAMS, 1), PARAMS);
    expect(grid.valid).toBe(true);
    expect(grid.colCount).toBe(1);
    expect(cellRefAt(grid, 0, 0)!.rowSpan).toBe(2);
  });

  // @scenario cell_kind=direct_text, operation=remove_col, span_context=blocked_by_span, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=remove_col, span_context=blocked_by_span, template=sirsoft-admin_basic
  /**
   * @effects grid_remove_absorbed_col_shrinks_colspan
   */
  it('removeColumn(가로 병합에 흡수된 열): 셀을 지우지 않고 병합 너비만 줄인다', () => {
    const grid = treeToGrid(removeColumn(wideTable(), PARAMS, 1), PARAMS);
    expect(grid.valid).toBe(true);
    expect(grid.colCount).toBe(1);
    expect(cellRefAt(grid, 0, 0)!.cell.text).toBe('wide');
    expect(cellRefAt(grid, 0, 0)!.colSpan).toBe(1);
  });

  // @scenario cell_kind=direct_text, operation=move_col, span_context=rowspan, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=move_col, span_context=rowspan, template=sirsoft-admin_basic
  /**
   * @effects grid_move_col_with_rowspan_swaps_cols
   */
  it('moveColumn(rowspan 표): 세로 병합 열도 인접 열과 자리를 바꾼다', () => {
    const grid = treeToGrid(moveColumn(tallTable(), PARAMS, 0, 1), PARAMS);
    expect(grid.valid).toBe(true);
    expect(cellRefAt(grid, 0, 0)!.cell.text).toBe('b');
    expect(cellRefAt(grid, 1, 0)!.cell.text).toBe('d');
    expect(cellRefAt(grid, 0, 1)!.cell.text).toBe('tall');
    expect(cellRefAt(grid, 0, 1)!.rowSpan).toBe(2);
  });
});

describe('시나리오 매트릭스 — 병합과 해제 × span 문맥', () => {
  // @scenario cell_kind=direct_text, operation=merge, span_context=colspan, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=merge, span_context=colspan, template=sirsoft-admin_basic
  /**
   * @effects grid_merge_area_containing_colspan
   */
  it('mergeCells(colspan 을 품은 영역): 기존 가로 병합을 품는 직사각형은 하나로 합친다', () => {
    const { table: merged, ok } = mergeCells(wideTable(), PARAMS, 0, 0, 1, 1);
    expect(ok).toBe(true);
    const grid = treeToGrid(merged, PARAMS);
    expect(grid.valid).toBe(true);
    expect(cellRefAt(grid, 0, 0)!.colSpan).toBe(2);
    expect(cellRefAt(grid, 0, 0)!.rowSpan).toBe(2);
    expect(cellRefAt(grid, 1, 1)!.cell.text).toBe('wide');
  });

  // @scenario cell_kind=direct_text, operation=merge, span_context=rowspan, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=merge, span_context=rowspan, template=sirsoft-admin_basic
  /**
   * @effects grid_merge_area_containing_rowspan
   */
  it('mergeCells(rowspan 을 품은 영역): 기존 세로 병합을 품는 직사각형은 하나로 합친다', () => {
    const { table: merged, ok } = mergeCells(tallTable(), PARAMS, 0, 0, 1, 1);
    expect(ok).toBe(true);
    const grid = treeToGrid(merged, PARAMS);
    expect(grid.valid).toBe(true);
    expect(cellRefAt(grid, 0, 0)!.colSpan).toBe(2);
    expect(cellRefAt(grid, 0, 0)!.rowSpan).toBe(2);
    expect(cellRefAt(grid, 1, 1)!.cell.text).toBe('tall');
  });

  // @scenario cell_kind=direct_text, operation=unmerge, span_context=no_span, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=unmerge, span_context=no_span, template=sirsoft-admin_basic
  /**
   * @effects grid_unmerge_plain_cell_is_noop
   */
  it('unmergeCell(병합 없는 셀): 바꿀 것이 없어 원본 그대로', () => {
    const t = table2x2();
    expect(unmergeCell(t, PARAMS, 1, 0)).toBe(t);
  });

  // @scenario cell_kind=direct_text, operation=unmerge, span_context=colspan, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=unmerge, span_context=colspan, template=sirsoft-admin_basic
  /**
   * @effects grid_unmerge_colspan_restores_cells
   */
  it('unmergeCell(가로 병합): 병합을 풀고 빈 칸을 되살린다', () => {
    const grid = treeToGrid(unmergeCell(wideTable(), PARAMS, 0, 0), PARAMS);
    expect(grid.valid).toBe(true);
    expect(cellRefAt(grid, 0, 0)!.colSpan).toBe(1);
    expect(cellRefAt(grid, 0, 0)!.cell.text).toBe('wide');
    expect(cellRefAt(grid, 0, 1)!.cell).not.toBe(cellRefAt(grid, 0, 0)!.cell);
  });

  // @scenario cell_kind=direct_text, operation=unmerge, span_context=rowspan, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=unmerge, span_context=rowspan, template=sirsoft-admin_basic
  /**
   * @effects grid_unmerge_rowspan_restores_cells
   */
  it('unmergeCell(세로 병합): 병합을 풀고 아래 행에 빈 칸을 되살린다', () => {
    const grid = treeToGrid(unmergeCell(tallTable(), PARAMS, 0, 0), PARAMS);
    expect(grid.valid).toBe(true);
    expect(cellRefAt(grid, 0, 0)!.rowSpan).toBe(1);
    expect(grid.rows[1]!.cells.length).toBe(2);
    expect(cellRefAt(grid, 1, 1)!.cell.text).toBe('d');
  });

  // @scenario cell_kind=direct_text, operation=unmerge, span_context=blocked_by_span, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=unmerge, span_context=blocked_by_span, template=sirsoft-admin_basic
  /**
   * @effects grid_unmerge_from_absorbed_coordinate
   */
  it('unmergeCell(흡수칸 좌표): 흡수칸을 가리켜도 그 병합의 origin 을 푼다', () => {
    const grid = treeToGrid(unmergeCell(wideTable(), PARAMS, 0, 1), PARAMS);
    expect(grid.valid).toBe(true);
    expect(cellRefAt(grid, 0, 0)!.colSpan).toBe(1);
    expect(cellRefAt(grid, 0, 0)!.cell.text).toBe('wide');
  });
});

describe('시나리오 매트릭스 — 셀 테두리와 텍스트 × span 문맥', () => {
  // @scenario cell_kind=direct_text, operation=cell_border, span_context=colspan, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=cell_border, span_context=colspan, template=sirsoft-admin_basic
  // @scenario cell_kind=direct_text, operation=cell_border, span_context=rowspan, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=cell_border, span_context=rowspan, template=sirsoft-admin_basic
  /**
   * @effects grid_cell_border_on_span_cell_keeps_span
   */
  it.each([
    ['colspan', wideTable, 'colSpan'],
    ['rowspan', tallTable, 'rowSpan'],
  ] as const)('setCellClassName(%s 셀): 테두리를 달아도 병합 prop 이 남는다', (_ctx, make, spanProp) => {
    const grid = treeToGrid(setCellClassName(make(), PARAMS, 0, 0, 'border border-red-500'), PARAMS);
    const cell = cellRefAt(grid, 0, 0)!.cell;
    expect(cell.props?.className).toBe('border border-red-500');
    expect(cell.props?.[spanProp]).toBe(2);
    expect(grid.valid).toBe(true);
  });

  // @scenario cell_kind=direct_text, operation=cell_border, span_context=blocked_by_span, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=cell_border, span_context=blocked_by_span, template=sirsoft-admin_basic
  /**
   * @effects grid_cell_border_on_absorbed_coordinate_hits_origin
   */
  it('setCellClassName(흡수칸 좌표): 흡수칸을 가리키면 그 병합 origin 셀에 단다', () => {
    const grid = treeToGrid(setCellClassName(tallTable(), PARAMS, 1, 0, 'border'), PARAMS);
    expect(cellRefAt(grid, 0, 0)!.cell.props?.className).toBe('border');
    expect(cellRefAt(grid, 1, 1)!.cell.props?.className).toBeUndefined();
  });

  // @scenario cell_kind=direct_text, operation=cell_text, span_context=colspan, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=cell_text, span_context=colspan, template=sirsoft-admin_basic
  // @scenario cell_kind=direct_text, operation=cell_text, span_context=rowspan, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=cell_text, span_context=rowspan, template=sirsoft-admin_basic
  /**
   * @effects grid_cell_text_on_span_cell_keeps_span
   */
  it.each([
    ['colspan', wideTable, 'colSpan'],
    ['rowspan', tallTable, 'rowSpan'],
  ] as const)('setCellText(%s 셀): 텍스트를 바꿔도 병합 prop 이 남는다', (_ctx, make, spanProp) => {
    const grid = treeToGrid(setCellText(make(), PARAMS, 0, 0, '$t:custom.x'), PARAMS);
    const cell = cellRefAt(grid, 0, 0)!.cell;
    expect(cell.text).toBe('$t:custom.x');
    expect(cell.props?.[spanProp]).toBe(2);
  });

  // @scenario cell_kind=direct_text, operation=cell_text, span_context=blocked_by_span, template=sirsoft-basic
  // @scenario cell_kind=direct_text, operation=cell_text, span_context=blocked_by_span, template=sirsoft-admin_basic
  /**
   * @effects grid_cell_text_on_absorbed_coordinate_hits_origin
   */
  it('setCellText(흡수칸 좌표): 흡수칸을 가리키면 origin 셀 텍스트가 바뀐다', () => {
    const grid = treeToGrid(setCellText(wideTable(), PARAMS, 0, 1, '바뀜'), PARAMS);
    expect(cellRefAt(grid, 0, 0)!.cell.text).toBe('바뀜');
    expect(grid.rows[0]!.cells.length).toBe(1);
  });

  // @scenario cell_kind=complex_text_descendant, operation=cell_text, span_context=colspan, template=sirsoft-basic
  // @scenario cell_kind=complex_text_descendant, operation=cell_text, span_context=colspan, template=sirsoft-admin_basic
  // @scenario cell_kind=complex_text_descendant, operation=cell_text, span_context=rowspan, template=sirsoft-basic
  // @scenario cell_kind=complex_text_descendant, operation=cell_text, span_context=rowspan, template=sirsoft-admin_basic
  /**
   * @effects grid_complex_cell_text_on_span_cell_keeps_span
   */
  it.each([
    ['colspan', { colSpan: 2 }, () => table([tbody([tr([complexTd('라벨', { colSpan: 2 })]), tr([td('a'), td('b')])])])],
    ['rowspan', { rowSpan: 2 }, () => table([tbody([tr([complexTd('라벨', { rowSpan: 2 }), td('b')]), tr([td('d')])])])],
  ] as const)('setCellNode(%s 복합 셀): 자손 텍스트만 바꾸고 구조와 병합 prop 은 남긴다', (_ctx, span, make) => {
    const t = make();
    const origin = cellRefAt(treeToGrid(t, PARAMS), 0, 0)!.cell;
    const grid = treeToGrid(setCellNode(t, PARAMS, 0, 0, withSpanText(origin, '$t:custom.k')), PARAMS);
    const cell = cellRefAt(grid, 0, 0)!.cell;
    expect((cell.children as EditorNode[])[0]!.name).toBe('Icon');
    expect((cell.children as EditorNode[])[1]!.text).toBe('$t:custom.k');
    expect(cell.props).toMatchObject({ className: 'flex', ...span });
    expect(grid.valid).toBe(true);
  });

  // @scenario cell_kind=complex_text_descendant, operation=cell_text, span_context=blocked_by_span, template=sirsoft-basic
  // @scenario cell_kind=complex_text_descendant, operation=cell_text, span_context=blocked_by_span, template=sirsoft-admin_basic
  /**
   * @effects grid_complex_cell_text_on_absorbed_coordinate_hits_origin
   */
  it('setCellNode(흡수칸 좌표의 복합 셀): 흡수칸을 가리키면 origin 복합 셀이 바뀐다', () => {
    const t = table([tbody([tr([complexTd('라벨', { rowSpan: 2 }), td('b')]), tr([td('d')])])]);
    const origin = cellRefAt(treeToGrid(t, PARAMS), 1, 0)!.cell;
    const grid = treeToGrid(setCellNode(t, PARAMS, 1, 0, withSpanText(origin, '바뀐 라벨')), PARAMS);
    expect(((cellRefAt(grid, 0, 0)!.cell.children as EditorNode[])[1]!).text).toBe('바뀐 라벨');
    expect(cellRefAt(grid, 1, 1)!.cell.text).toBe('d');
  });
});
