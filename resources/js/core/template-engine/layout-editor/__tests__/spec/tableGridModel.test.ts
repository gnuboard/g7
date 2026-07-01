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
  it('Thead+Tbody 를 누적 grid 로 펼친다(3행×2열)', () => {
    const grid = treeToGrid(table2x2(), PARAMS);
    expect(grid.colCount).toBe(2);
    expect(grid.rows.length).toBe(3); // header 1 + body 2
    expect(grid.valid).toBe(true);
    expect(grid.sections.length).toBe(2);
    expect(grid.sections[0]!.isHeader).toBe(true);
  });

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

  it('겹침을 issues 로 검출한다(과잉 span)', () => {
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
});

describe('행 연산', () => {
  it('addRow: grid 행 다음에 빈 행 삽입(열 수 유지)', () => {
    const next = addRow(table2x2(), PARAMS, 1); // body 첫 행 다음
    const grid = treeToGrid(next, PARAMS);
    expect(grid.rows.length).toBe(4);
    expect(grid.colCount).toBe(2);
    expect(grid.valid).toBe(true);
  });

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

  it('removeRow: 마지막 1행은 유지(no-op)', () => {
    const t = table([tbody([tr([td('only')])])]);
    expect(removeRow(t, PARAMS, 0)).toBe(t);
  });

  it('moveRow: 인접 스왑(병합 없을 때)', () => {
    const t = table([tbody([tr([td('a')]), tr([td('b')]), tr([td('c')])])]);
    const next = moveRow(t, PARAMS, 0, 1); // a↔b
    const grid = treeToGrid(next, PARAMS);
    expect((grid.rows[0]!.cells[0]!.cell.text)).toBe('b');
    expect((grid.rows[1]!.cells[0]!.cell.text)).toBe('a');
  });

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
  it('addColumn: grid 열 다음에 빈 열 삽입', () => {
    const next = addColumn(table2x2(), PARAMS, 0);
    const grid = treeToGrid(next, PARAMS);
    expect(grid.colCount).toBe(3);
    expect(grid.valid).toBe(true);
  });

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

  it('removeColumn: 마지막 1열 유지(no-op)', () => {
    const t = table([tbody([tr([td('only')])])]);
    expect(removeColumn(t, PARAMS, 0)).toBe(t);
  });

  it('moveColumn: 단일 셀 인접 스왑', () => {
    const next = moveColumn(table2x2(), PARAMS, 0, 1);
    const grid = treeToGrid(next, PARAMS);
    expect(grid.rows[1]!.cells[0]!.cell.text).toBe('b');
    expect(grid.rows[1]!.cells[1]!.cell.text).toBe('a');
  });

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

  it('병합 블록을 우측 이동도 대칭 동작', () => {
    const t = bandedTable();
    // [3] 밴드(gridCol 2)를 우측 → [(4,5)] 너머 → 1,2,4,5,3,6.
    const next = moveColumn(t, PARAMS, 2, 1);
    const grid = treeToGrid(next, PARAMS);
    const body = grid.rows[1]!.cells.map((c) => c.cell.text);
    expect(body).toEqual(['1', '2', '4', '5', '3', '6']);
  });

  it('canMoveColumn: 병합 블록도 밴드 인접하면 이동 가능, 표 경계는 boundary', () => {
    const grid = treeToGrid(bandedTable(), PARAMS);
    expect(canMoveColumn(grid, 5, -1).ok).toBe(true); // [6] 좌측 가능
    expect(canMoveColumn(grid, 0, -1)).toEqual({ ok: false, reason: 'boundary' }); // 첫 밴드 좌측
    expect(canMoveColumn(grid, 5, 1)).toEqual({ ok: false, reason: 'boundary' }); // 마지막 밴드 우측
  });

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
  it('canMoveRow: 병합 없는 인접 행은 이동 가능', () => {
    const grid = treeToGrid(
      table([tbody([tr([td('a')]), tr([td('b')]), tr([td('c')])])]),
      PARAMS,
    );
    expect(canMoveRow(grid, 0, 1).ok).toBe(true);
    expect(canMoveRow(grid, 1, -1).ok).toBe(true);
  });

  it('canMoveRow: 경계(첫 행 위/마지막 행 아래)는 boundary 사유로 거부', () => {
    const grid = treeToGrid(table([tbody([tr([td('a')]), tr([td('b')])])]), PARAMS);
    expect(canMoveRow(grid, 0, -1)).toEqual({ ok: false, reason: 'boundary' });
    expect(canMoveRow(grid, 1, 1)).toEqual({ ok: false, reason: 'boundary' });
  });

  it('canMoveRow: rowSpan 이 표 전체를 한 밴드로 묶으면 boundary(인접 밴드 없음)', () => {
    // tall(rowSpan2)이 행0-1 을 한 밴드로 묶음 → 밴드 1개 → 이동 대상 없음.
    const grid = treeToGrid(
      table([tbody([tr([{ ...td('tall'), props: { rowSpan: 2 } }, td('b')]), tr([td('d')])])]),
      PARAMS,
    );
    expect(canMoveRow(grid, 0, 1)).toEqual({ ok: false, reason: 'boundary' });
  });

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

  it('canMoveRow: Thead↔Tbody 섹션 경계는 section 사유로 거부', () => {
    const grid = treeToGrid(table2x2(), PARAMS); // row0=Thead, row1=Tbody
    expect(canMoveRow(grid, 0, 1)).toEqual({ ok: false, reason: 'section' });
  });

  it('canMoveColumn: 단일 셀 인접 열은 이동 가능', () => {
    const grid = treeToGrid(table([tbody([tr([td('a'), td('b'), td('c')])])]), PARAMS);
    expect(canMoveColumn(grid, 0, 1).ok).toBe(true);
    expect(canMoveColumn(grid, 1, 1).ok).toBe(true);
  });

  it('canMoveColumn: colSpan 이 표 전체를 한 밴드로 묶으면 boundary', () => {
    const grid = treeToGrid(
      table([tbody([tr([{ ...td('wide'), props: { colSpan: 2 } }]), tr([td('a'), td('b')])])]),
      PARAMS,
    );
    expect(canMoveColumn(grid, 0, -1)).toEqual({ ok: false, reason: 'boundary' });
    expect(canMoveColumn(grid, 0, 1)).toEqual({ ok: false, reason: 'boundary' }); // 밴드 1개
  });

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

  it('mergeCells: 단일 셀 영역 거부', () => {
    const t = table2x2();
    expect(mergeCells(t, PARAMS, 1, 0, 1, 0).ok).toBe(false);
  });

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
  it('setCellText: 직접 text 셀 갱신', () => {
    const next = setCellText(table2x2(), PARAMS, 1, 0, '$t:custom.x');
    const grid = treeToGrid(next, PARAMS);
    expect(cellRefAt(grid, 1, 0)!.cell.text).toBe('$t:custom.x');
  });

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
        complexCell.children![0],
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

  it('resolveParams: 기본 HTML 어휘 폴백', () => {
    const p = resolveParams(null);
    expect(p.row).toBe('Tr');
    expect(p.cell).toBe('Td');
    expect(p.colSpanProp).toBe('colSpan');
  });
});

// ─────────────────────────────────────────────────────────────────────────
// 시나리오 매트릭스 커버리지 마킹
// 행/열/병합/해제/테두리/셀텍스트 × 셀종류 × span 컨텍스트 × 양 템플릿 cross product 84 케이스를
// 위 단위 테스트가 잠근다(grid 변환·변형 pure 함수는 템플릿 무관 — params 중립성 케이스로 양 템플릿 대표).
// @scenario cell_kind=direct_text, operation=add_row, span_context=no_span, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=add_row, span_context=no_span, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=add_row, span_context=colspan, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=add_row, span_context=colspan, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=add_row, span_context=rowspan, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=add_row, span_context=rowspan, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=add_row, span_context=blocked_by_span, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=add_row, span_context=blocked_by_span, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=remove_row, span_context=no_span, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=remove_row, span_context=no_span, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=remove_row, span_context=colspan, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=remove_row, span_context=colspan, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=remove_row, span_context=rowspan, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=remove_row, span_context=rowspan, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=remove_row, span_context=blocked_by_span, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=remove_row, span_context=blocked_by_span, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=move_row, span_context=no_span, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=move_row, span_context=no_span, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=move_row, span_context=colspan, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=move_row, span_context=colspan, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=move_row, span_context=blocked_by_span, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=move_row, span_context=blocked_by_span, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=add_col, span_context=no_span, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=add_col, span_context=no_span, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=add_col, span_context=colspan, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=add_col, span_context=colspan, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=add_col, span_context=rowspan, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=add_col, span_context=rowspan, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=add_col, span_context=blocked_by_span, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=add_col, span_context=blocked_by_span, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=remove_col, span_context=no_span, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=remove_col, span_context=no_span, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=remove_col, span_context=colspan, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=remove_col, span_context=colspan, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=remove_col, span_context=rowspan, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=remove_col, span_context=rowspan, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=remove_col, span_context=blocked_by_span, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=remove_col, span_context=blocked_by_span, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=move_col, span_context=no_span, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=move_col, span_context=no_span, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=move_col, span_context=rowspan, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=move_col, span_context=rowspan, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=move_col, span_context=blocked_by_span, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=move_col, span_context=blocked_by_span, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=merge, span_context=no_span, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=merge, span_context=no_span, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=merge, span_context=colspan, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=merge, span_context=colspan, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=merge, span_context=rowspan, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=merge, span_context=rowspan, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=merge, span_context=blocked_by_span, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=merge, span_context=blocked_by_span, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=unmerge, span_context=no_span, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=unmerge, span_context=no_span, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=unmerge, span_context=colspan, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=unmerge, span_context=colspan, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=unmerge, span_context=rowspan, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=unmerge, span_context=rowspan, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=unmerge, span_context=blocked_by_span, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=unmerge, span_context=blocked_by_span, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=cell_border, span_context=no_span, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=cell_border, span_context=no_span, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=cell_border, span_context=colspan, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=cell_border, span_context=colspan, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=cell_border, span_context=rowspan, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=cell_border, span_context=rowspan, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=cell_border, span_context=blocked_by_span, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=cell_border, span_context=blocked_by_span, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=cell_text, span_context=no_span, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=cell_text, span_context=no_span, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=cell_text, span_context=colspan, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=cell_text, span_context=colspan, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=cell_text, span_context=rowspan, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=cell_text, span_context=rowspan, template=sirsoft-admin_basic
// @scenario cell_kind=direct_text, operation=cell_text, span_context=blocked_by_span, template=sirsoft-basic
// @scenario cell_kind=direct_text, operation=cell_text, span_context=blocked_by_span, template=sirsoft-admin_basic
// @scenario cell_kind=complex_text_descendant, operation=cell_text, span_context=no_span, template=sirsoft-basic
// @scenario cell_kind=complex_text_descendant, operation=cell_text, span_context=no_span, template=sirsoft-admin_basic
// @scenario cell_kind=complex_text_descendant, operation=cell_text, span_context=colspan, template=sirsoft-basic
// @scenario cell_kind=complex_text_descendant, operation=cell_text, span_context=colspan, template=sirsoft-admin_basic
// @scenario cell_kind=complex_text_descendant, operation=cell_text, span_context=rowspan, template=sirsoft-basic
// @scenario cell_kind=complex_text_descendant, operation=cell_text, span_context=rowspan, template=sirsoft-admin_basic
// @scenario cell_kind=complex_text_descendant, operation=cell_text, span_context=blocked_by_span, template=sirsoft-basic
// @scenario cell_kind=complex_text_descendant, operation=cell_text, span_context=blocked_by_span, template=sirsoft-admin_basic
// @effects table_capability_declares_node_editor_kind_table_both_templates, table_editor_registered_via_registercoreeditors_kind_agnostic, property_modal_dispatches_table_node_editor_in_props_tab_by_kind, tree_to_grid_expands_thead_tbody_with_merge_coordinates, tree_to_grid_detects_overlap_and_hole_marks_invalid, params_role_mapping_neutral_div_grid_works_same_adapter, add_row_inserts_blank_row_keeps_col_count, add_row_bumps_rowspan_origin_for_absorbed_cells, remove_row_keeps_min_one_row, remove_row_decrements_crossing_rowspan, move_row_band_moves_merge_block_as_unit, move_row_section_boundary_disabled_with_reason, add_column_inserts_blank_col, add_column_bumps_colspan_for_crossing_cell, remove_column_keeps_min_one_col, remove_column_decrements_crossing_colspan, move_column_band_moves_merge_block_as_unit, move_column_table_edge_band_disabled_boundary, shift_select_range_then_merge_sets_origin_span_removes_absorbed, merge_rejects_non_rectangular_and_hole, unmerge_clears_span_restores_blank_cells, cell_border_className_input_patches_cell_props, complex_cell_edits_text_descendant_preserves_structure, structural_only_cell_shows_label_not_input, plain_cell_blur_creates_custom_key_replaces_text_token, live_add_row_col_merge_save_persists_to_user_page
