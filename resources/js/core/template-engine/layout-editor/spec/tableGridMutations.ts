/**
 * tableGridMutations.ts — 표 논리 grid 변형 연산
 *
 * `tableGridModel.treeToGrid` 로 펼친 논리 grid 좌표를 기준으로 행/열 추가·삭제·이동,
 * 셀 병합/해제, 셀 className(테두리 등) 설정을 수행한다. 모든 연산은 **pure** —
 * 표 노드를 immutable 복제로 패치한 새 노드를 반환한다. 좌표 추론은 grid 로 하되 실제
 * 패치는 노드 인덱스(sectionIndex/rowIndexInSection/cellIndexInRow)로 해 병합/구멍을
 * 깨지 않는다. 직사각형 병합만 허용하고 겹침/구멍을 가드한다(부록4 정합성).
 *
 * @since engine-v1.50.0
 */

import type { EditorNode } from '../utils/layoutTreeUtils';
import {
  treeToGrid,
  cellRefAt,
  resolveParams,
  canMoveRow,
  canMoveColumn,
  columnBands,
  rowBands,
  type TableParams,
  type TableGrid,
  type GridCellRef,
} from './tableGridModel';
import { replaceScopedGroupToken } from './recipeEngine';

/** 자식 노드 배열 안전 추출. */
function childArray(node: EditorNode | undefined | null): EditorNode[] {
  if (!node) return [];
  return Array.isArray(node.children) ? (node.children as EditorNode[]) : [];
}

/** 셀 노드의 span prop 설정(1 이면 prop 제거). */
function withSpan(cell: EditorNode, prop: string, value: number): EditorNode {
  const props = { ...(cell.props ?? {}) };
  if (value <= 1) delete props[prop];
  else props[prop] = value;
  const next: EditorNode = { ...cell, props };
  if (Object.keys(props).length === 0) delete next.props;
  return next;
}

/** 새 빈 셀 노드 생성(역할=cell|headerCell, template className 상속 옵션). */
function makeCell(
  p: Required<TableParams>,
  template?: EditorNode | null,
  header = false,
): EditorNode {
  const cell: EditorNode = { type: 'basic', name: header ? p.headerCell : p.cell };
  const cn = template?.props?.className;
  if (typeof cn === 'string' && cn) cell.props = { className: cn };
  cell.text = '';
  return cell;
}

/** 셀 노드 여부(데이터/머리글 셀). */
function isCell(node: EditorNode, p: Required<TableParams>): boolean {
  return node.name === p.cell || node.name === p.headerCell;
}

/** 섹션 컨테이너 노드를 패치한 표 노드 반환(가상 섹션이면 표 자신 패치). */
function patchSection(
  table: EditorNode,
  grid: TableGrid,
  sectionIndex: number,
  patch: (container: EditorNode) => EditorNode,
): EditorNode {
  const section = grid.sections[sectionIndex];
  if (!section) return table;
  if (section.containerIndex === -1) return patch(table);
  const children = childArray(table).map((c, i) =>
    i === section.containerIndex ? patch(c) : c,
  );
  return { ...table, children };
}

/** 섹션 안 rowIndexInSection 번째 행 노드를 패치. */
function patchRowNode(
  container: EditorNode,
  rowName: string,
  rowIndexInSection: number,
  patch: (row: EditorNode) => EditorNode,
): EditorNode {
  let seen = -1;
  const children = childArray(container).map((c) => {
    if (c.name === rowName) {
      seen++;
      if (seen === rowIndexInSection) return patch(c);
    }
    return c;
  });
  return { ...container, children };
}

/** 행에서 cellIndexInRow(셀 필터 기준) → children 실제 인덱스 변환(없으면 -1). */
function childIndexOfCell(
  row: EditorNode,
  p: Required<TableParams>,
  cellIndexInRow: number,
): number {
  let seen = -1;
  const kids = childArray(row);
  for (let i = 0; i < kids.length; i++) {
    if (isCell(kids[i]!, p)) {
      seen++;
      if (seen === cellIndexInRow) return i;
    }
  }
  return -1;
}

/**
 * 행 추가 — `atGridRow` grid 행 **다음**에 새 행 삽입(-1 이면 맨 앞).
 * 새 행을 가로질러 내려오는 rowSpan 흡수칸은 origin 셀 rowSpan++ (셀 미생성),
 * 그 외 열은 새 빈 셀.
 */
export function addRow(
  table: EditorNode,
  params: TableParams | null,
  atGridRow: number,
): EditorNode {
  const p = resolveParams(params);
  const grid = treeToGrid(table, params);

  // 빈 표 — 1행×1열.
  if (grid.rows.length === 0 || grid.colCount === 0) {
    const row: EditorNode = { type: 'basic', name: p.row, children: [makeCell(p)] };
    if (grid.sections.length > 0) {
      return patchSection(table, grid, grid.sections.length - 1, (c) => ({
        ...c,
        children: [...childArray(c), row],
      }));
    }
    return { ...table, children: [...childArray(table), row] };
  }

  const refIdx = Math.max(-1, Math.min(atGridRow, grid.rows.length - 1));
  const refRow = refIdx < 0 ? grid.rows[0]! : grid.rows[refIdx]!;
  const refGridRowNo = refRow.cells[0]?.gridRow ?? refIdx;
  const newGridRowNo = refIdx < 0 ? 0 : refGridRowNo + 1;
  const sectionIndex = refRow.sectionIndex;
  const rowIndexInSection = refIdx < 0 ? 0 : refRow.rowIndexInSection + 1;

  // 새 행 셀 구성 + 흡수 origin span bump 목록.
  const newCells: EditorNode[] = [];
  const spanBumps: GridCellRef[] = [];
  let col = 0;
  while (col < grid.colCount) {
    const above = newGridRowNo > 0 ? cellRefAt(grid, newGridRowNo - 1, col) : null;
    if (above && above.gridRow + above.rowSpan > newGridRowNo && above.rowSpan > 1) {
      spanBumps.push(above);
      col += above.colSpan;
    } else {
      newCells.push(makeCell(p));
      col += 1;
    }
  }

  let next = table;
  for (const ref of spanBumps) {
    next = patchSection(next, grid, ref.sectionIndex, (container) =>
      patchRowNode(container, p.row, ref.rowIndexInSection, (row) => ({
        ...row,
        children: childArray(row).map((c, i) =>
          i === ref.cellIndexInRow ? withSpan(c, p.rowSpanProp, ref.rowSpan + 1) : c,
        ),
      })),
    );
  }

  const newRow: EditorNode = { type: 'basic', name: p.row, children: newCells };
  const grid2 = treeToGrid(next, params);
  next = patchSection(next, grid2, sectionIndex, (container) => {
    const kids = childArray(container);
    let rowSeen = 0;
    let insertAt = kids.length;
    for (let i = 0; i < kids.length; i++) {
      if (kids[i]!.name === p.row) {
        if (rowSeen === rowIndexInSection) {
          insertAt = i;
          break;
        }
        rowSeen++;
      }
    }
    return { ...container, children: [...kids.slice(0, insertAt), newRow, ...kids.slice(insertAt)] };
  });
  return next;
}

/** 행 삭제 — origin 행 제거 + 내려오던 rowSpan 흡수칸 감소. 최소 1행 유지. */
export function removeRow(
  table: EditorNode,
  params: TableParams | null,
  atGridRow: number,
): EditorNode {
  const p = resolveParams(params);
  const grid = treeToGrid(table, params);
  if (atGridRow < 0 || atGridRow >= grid.rows.length || grid.rows.length <= 1) return table;
  const target = grid.rows[atGridRow]!;
  const gridRowNo = target.cells[0]?.gridRow ?? atGridRow;

  let next = table;
  const decremented = new Set<EditorNode>();
  // 위에서 내려오는 흡수 셀 rowSpan--.
  for (let c = 0; c < grid.colCount; ) {
    const ref = cellRefAt(grid, gridRowNo, c);
    if (ref && ref.gridRow < gridRowNo && !decremented.has(ref.cell)) {
      decremented.add(ref.cell);
      next = patchSection(next, grid, ref.sectionIndex, (container) =>
        patchRowNode(container, p.row, ref.rowIndexInSection, (row) => ({
          ...row,
          children: childArray(row).map((cc, i) =>
            i === ref.cellIndexInRow ? withSpan(cc, p.rowSpanProp, ref.rowSpan - 1) : cc,
          ),
        })),
      );
    }
    c += ref ? ref.colSpan : 1;
  }
  // 대상 행 노드 제거.
  const grid2 = treeToGrid(next, params);
  next = patchSection(next, grid2, target.sectionIndex, (container) => {
    let rowSeen = -1;
    const kids = childArray(container).filter((c) => {
      if (c.name === p.row) {
        rowSeen++;
        return rowSeen !== target.rowIndexInSection;
      }
      return true;
    });
    return { ...container, children: kids };
  });
  return next;
}

/**
 * 행 이동(병합 블록 단위) — 대상 행이 속한 행 밴드를 인접 밴드 너머로 통째 이동(canMoveRow
 * SSoT). 같은 섹션 안에서 밴드(단일 행 또는 rowSpan 병합 블록)를 묶음으로 옮긴다.
 * 병합 블록도 통째 이동. 인접 밴드 없음/섹션 경계 시 no-op.
 */
export function moveRow(
  table: EditorNode,
  params: TableParams | null,
  atGridRow: number,
  dir: -1 | 1,
): EditorNode {
  const p = resolveParams(params);
  const grid = treeToGrid(table, params);
  if (!canMoveRow(grid, atGridRow, dir).ok) return table;
  const bands = rowBands(grid);
  const bi = bands.findIndex((b) => atGridRow >= b.start && atGridRow < b.end);
  const ni = bi + dir;
  const A = bands[bi]!;
  const B = bands[ni]!;
  const sectionIndex = A.sectionIndex;

  // 밴드 A 의 행들과 밴드 B 의 행들을 통째로 순서 교환(같은 섹션 children 안에서).
  // 각 밴드의 행은 grid.rows 의 rowIndexInSection 으로 식별 → 섹션 children 의 row 노드.
  const rowsInBand = (band: { start: number; end: number }): number[] =>
    grid.rows
      .filter((r) => {
        const no = r.cells[0]?.gridRow ?? -1;
        return r.sectionIndex === sectionIndex && no >= band.start && no < band.end;
      })
      .map((r) => r.rowIndexInSection);

  const aRows = rowsInBand(A);
  const bRows = rowsInBand(B);
  if (aRows.length === 0 || bRows.length === 0) return table;

  return patchSection(table, grid, sectionIndex, (container) => {
    const kids = [...childArray(container)];
    // 섹션 children 에서 row 노드 인덱스 목록(rowIndexInSection 순).
    const rowChildIdx: number[] = [];
    kids.forEach((c, i) => {
      if (c.name === p.row) rowChildIdx.push(i);
    });
    const aChild = aRows.map((ri) => rowChildIdx[ri]!).filter((x) => x !== undefined);
    const bChild = bRows.map((ri) => rowChildIdx[ri]!).filter((x) => x !== undefined);
    if (aChild.length === 0 || bChild.length === 0) return container;
    // 두 밴드의 children 슬라이스를 추출해 순서 교환.
    const aNodes = aChild.map((i) => kids[i]!);
    const bNodes = bChild.map((i) => kids[i]!);
    // 위(작은 index)에 있는 밴드가 먼저 오도록 — dir 무관하게 A,B 의 실제 위치로 판단.
    const aFirst = Math.min(...aChild) < Math.min(...bChild);
    const firstIdx = aFirst ? aChild : bChild;
    const firstNodes = aFirst ? aNodes : bNodes;
    const secondIdx = aFirst ? bChild : aChild;
    const secondNodes = aFirst ? bNodes : aNodes;
    // 교환: first 위치들에 second 노드를, second 위치들에 first 노드를 채운다(개수 다를 수 있어
    // 구간 재배치). 두 밴드는 인접하므로 [first...second] 연속 구간을 [second, first] 로 교체.
    const lo = Math.min(...firstIdx);
    const hi = Math.max(...secondIdx);
    const rebuilt = [...secondNodes, ...firstNodes];
    const out = [...kids.slice(0, lo), ...rebuilt, ...kids.slice(hi + 1)];
    return { ...container, children: out };
  });
}

/**
 * 열 이동(병합 블록 단위) — 대상 열이 속한 열 밴드를 인접 밴드 너머로 통째 이동(canMoveColumn
 * SSoT). : `[(1,2)][3][(4,5)][6]` 에서 6 좌측 → 1,2,3,6,4,5,
 * (4,5) 좌측 → 1,2,4,5,3,6. 각 행에서 두 밴드에 속한 셀들의 순서를 교환한다.
 */
export function moveColumn(
  table: EditorNode,
  params: TableParams | null,
  atGridCol: number,
  dir: -1 | 1,
): EditorNode {
  const p = resolveParams(params);
  const grid = treeToGrid(table, params);
  if (!canMoveColumn(grid, atGridCol, dir).ok) return table;
  const bands = columnBands(grid);
  const bi = bands.findIndex((b) => atGridCol >= b.start && atGridCol < b.end);
  const ni = bi + dir;
  const A = bands[bi]!;
  const B = bands[ni]!;
  const loBand = A.start < B.start ? A : B; // 위치상 왼쪽 밴드
  const hiBand = A.start < B.start ? B : A;

  // 각 행에서 두 밴드 구간 셀 노드를 [hi, lo] 순으로 재배치(밴드 단위 통째 스왑).
  // 그 행의 origin 셀(row.cells)만 children 에 실재하므로, cellIndexInRow → children 인덱스
  // 매핑으로 각 셀의 gridCol(밴드 소속)을 판정한다(흡수칸은 children 에 없어 자연 제외).
  let next = table;
  for (const row of grid.rows) {
    // 이 행의 origin 셀들을 밴드별로 분류(원본 grid 기준 — gridCol 안정).
    const inLoCells = row.cells.filter((x) => x.gridCol >= loBand.start && x.gridCol < loBand.end);
    const inHiCells = row.cells.filter((x) => x.gridCol >= hiBand.start && x.gridCol < hiBand.end);
    if (inLoCells.length === 0 || inHiCells.length === 0) continue; // 이 행엔 한쪽만 — 스킵
    const loCellIdx = inLoCells.map((x) => x.cellIndexInRow);
    const hiCellIdx = inHiCells.map((x) => x.cellIndexInRow);
    const g = treeToGrid(next, params);
    next = patchSection(next, g, row.sectionIndex, (container) =>
      patchRowNode(container, p.row, row.rowIndexInSection, (r) => {
        const kids = [...childArray(r)];
        const inLo = loCellIdx.map((ci) => childIndexOfCell(r, p, ci)).filter((x) => x >= 0);
        const inHi = hiCellIdx.map((ci) => childIndexOfCell(r, p, ci)).filter((x) => x >= 0);
        if (inLo.length === 0 || inHi.length === 0) return r;
        const loNodes = inLo.map((i) => kids[i]!);
        const hiNodes = inHi.map((i) => kids[i]!);
        const lo = Math.min(...inLo, ...inHi);
        const hi = Math.max(...inLo, ...inHi);
        const rebuilt = [...hiNodes, ...loNodes];
        const out = [...kids.slice(0, lo), ...rebuilt, ...kids.slice(hi + 1)];
        return { ...r, children: out };
      }),
    );
  }
  return next;
}

/**
 * 열 추가 — `atGridCol` grid 열 **다음**에 새 열 삽입(-1 이면 맨 앞).
 * 경계를 가로지르는 colSpan 셀은 colSpan++ (한 번만), origin 사이면 그 행에 빈 셀 삽입.
 */
export function addColumn(
  table: EditorNode,
  params: TableParams | null,
  atGridCol: number,
): EditorNode {
  const p = resolveParams(params);
  const grid = treeToGrid(table, params);
  if (grid.rows.length === 0 || grid.colCount === 0) return addRow(table, params, -1);

  const boundary = Math.max(0, Math.min(atGridCol + 1, grid.colCount));
  let next = table;
  const bumped = new Set<EditorNode>();

  for (const row of grid.rows) {
    const no = row.cells[0]?.gridRow ?? 0;
    const leftRef = boundary > 0 ? cellRefAt(grid, no, boundary - 1) : null;
    const rightRef = boundary < grid.colCount ? cellRefAt(grid, no, boundary) : null;

    // 경계를 가로지르는 colSpan 셀(좌=우 동일 origin, colSpan>1).
    if (leftRef && rightRef && leftRef.cell === rightRef.cell && leftRef.colSpan > 1) {
      if (!bumped.has(leftRef.cell)) {
        bumped.add(leftRef.cell);
        const g = treeToGrid(next, params);
        next = patchSection(next, g, leftRef.sectionIndex, (container) =>
          patchRowNode(container, p.row, leftRef.rowIndexInSection, (r) => ({
            ...r,
            children: childArray(r).map((c, i) =>
              i === leftRef.cellIndexInRow ? withSpan(c, p.colSpanProp, leftRef.colSpan + 1) : c,
            ),
          })),
        );
      }
      continue;
    }
    // 흡수칸(위에서 내려오는 rowSpan)이 경계를 덮으면 origin 행에서 처리됨 — skip.
    if (leftRef && leftRef.gridRow < no) continue;
    if (rightRef && rightRef.gridRow < no) continue;

    // origin 사이 경계 — 이 행에 빈 셀 삽입(leftRef 셀 노드 뒤, 없으면 맨 앞).
    const afterCellIdx = leftRef && leftRef.gridRow === no ? leftRef.cellIndexInRow : -1;
    const headerRow = row.cells.length > 0 && row.cells.every((x) => x.isHeader);
    const g = treeToGrid(next, params);
    next = patchSection(next, g, row.sectionIndex, (container) =>
      patchRowNode(container, p.row, row.rowIndexInSection, (r) => {
        const kids = [...childArray(r)];
        let childInsertAt: number;
        if (afterCellIdx < 0) {
          childInsertAt = kids.findIndex((c) => isCell(c, p));
          if (childInsertAt < 0) childInsertAt = kids.length;
        } else {
          const ci = childIndexOfCell(r, p, afterCellIdx);
          childInsertAt = ci < 0 ? kids.length : ci + 1;
        }
        kids.splice(childInsertAt, 0, makeCell(p, undefined, headerRow));
        return { ...r, children: kids };
      }),
    );
  }
  return next;
}

/** 열 삭제 — origin 셀 제거 또는 colSpan--. 흡수칸은 origin 행에서 처리. 최소 1열 유지. */
export function removeColumn(
  table: EditorNode,
  params: TableParams | null,
  atGridCol: number,
): EditorNode {
  const p = resolveParams(params);
  const grid = treeToGrid(table, params);
  if (atGridCol < 0 || atGridCol >= grid.colCount || grid.colCount <= 1) return table;

  let next = table;
  const handled = new Set<EditorNode>();
  for (const row of grid.rows) {
    const no = row.cells[0]?.gridRow ?? 0;
    const ref = cellRefAt(grid, no, atGridCol);
    if (!ref || handled.has(ref.cell) || ref.gridRow < no) continue;
    handled.add(ref.cell);
    if (ref.colSpan > 1) {
      const g = treeToGrid(next, params);
      next = patchSection(next, g, ref.sectionIndex, (container) =>
        patchRowNode(container, p.row, ref.rowIndexInSection, (r) => ({
          ...r,
          children: childArray(r).map((c, i) =>
            i === ref.cellIndexInRow ? withSpan(c, p.colSpanProp, ref.colSpan - 1) : c,
          ),
        })),
      );
    } else {
      const g = treeToGrid(next, params);
      next = patchSection(next, g, ref.sectionIndex, (container) =>
        patchRowNode(container, p.row, ref.rowIndexInSection, (r) => {
          let seen = -1;
          const kids = childArray(r).filter((c) => {
            if (isCell(c, p)) {
              seen++;
              return seen !== ref.cellIndexInRow;
            }
            return true;
          });
          return { ...r, children: kids };
        }),
      );
    }
  }
  return next;
}

/**
 * 셀 병합 — 직사각형 영역 (r0..r1, c0..c1)을 좌상단 origin 으로 병합.
 * 영역이 직사각형이 아니거나 origin 이 영역 밖으로 삐져나오면 거부(ok:false).
 * 흡수 셀 노드는 제거하고 origin 셀 colSpan/rowSpan 설정.
 */
export function mergeCells(
  table: EditorNode,
  params: TableParams | null,
  r0: number,
  c0: number,
  r1: number,
  c1: number,
): { table: EditorNode; ok: boolean; reason?: string } {
  const p = resolveParams(params);
  const grid = treeToGrid(table, params);
  const top = Math.min(r0, r1);
  const bottom = Math.max(r0, r1);
  const left = Math.min(c0, c1);
  const right = Math.max(c0, c1);
  if (top === bottom && left === right) return { table, ok: false, reason: 'single' };
  if (!grid.valid) return { table, ok: false, reason: 'invalid-grid' };

  const originsInArea = new Set<GridCellRef>();
  for (let r = top; r <= bottom; r++) {
    for (let c = left; c <= right; c++) {
      const ref = cellRefAt(grid, r, c);
      if (!ref) return { table, ok: false, reason: 'hole' };
      if (
        ref.gridRow < top ||
        ref.gridCol < left ||
        ref.gridRow + ref.rowSpan - 1 > bottom ||
        ref.gridCol + ref.colSpan - 1 > right
      ) {
        return { table, ok: false, reason: 'not-rectangular' };
      }
      originsInArea.add(ref);
    }
  }
  const originRef = cellRefAt(grid, top, left)!;
  const absorbed = Array.from(originsInArea).filter((ref) => ref !== originRef);

  // origin span 설정.
  let next = patchSection(table, grid, originRef.sectionIndex, (container) =>
    patchRowNode(container, p.row, originRef.rowIndexInSection, (r) => ({
      ...r,
      children: childArray(r).map((c, i) => {
        if (i !== originRef.cellIndexInRow) return c;
        let m = withSpan(c, p.colSpanProp, right - left + 1);
        m = withSpan(m, p.rowSpanProp, bottom - top + 1);
        return m;
      }),
    })),
  );

  // 흡수 셀 제거 — (section,row) 별 묶음.
  const bySectionRow = new Map<string, GridCellRef[]>();
  for (const ref of absorbed) {
    const key = `${ref.sectionIndex}:${ref.rowIndexInSection}`;
    const arr = bySectionRow.get(key) ?? [];
    arr.push(ref);
    bySectionRow.set(key, arr);
  }
  for (const [key, refs] of bySectionRow) {
    const sectionIndex = parseInt(key.split(':')[0]!, 10);
    const rowIndexInSection = refs[0]!.rowIndexInSection;
    const removeIdx = new Set(refs.map((r) => r.cellIndexInRow));
    const g = treeToGrid(next, params);
    next = patchSection(next, g, sectionIndex, (container) =>
      patchRowNode(container, p.row, rowIndexInSection, (r) => {
        let seen = -1;
        const kids = childArray(r).filter((c) => {
          if (isCell(c, p)) {
            seen++;
            return !removeIdx.has(seen);
          }
          return true;
        });
        return { ...r, children: kids };
      }),
    );
  }
  return { table: next, ok: true };
}

/**
 * 셀 병합 해제 — origin 셀(r,c) span 을 1×1 로 되돌리고 흡수했던 칸에 빈 셀 복원.
 * 단일 셀이면 no-op. 복원은 origin 영역의 각 grid 행에 대해 origin 우측/하단 칸을
 * 좌→우 순서로 삽입한다.
 */
export function unmergeCell(
  table: EditorNode,
  params: TableParams | null,
  r: number,
  c: number,
): EditorNode {
  const p = resolveParams(params);
  const grid = treeToGrid(table, params);
  const ref = cellRefAt(grid, r, c);
  if (!ref || (ref.colSpan <= 1 && ref.rowSpan <= 1)) return table;

  const top = ref.gridRow;
  const left = ref.gridCol;
  const bottom = ref.gridRow + ref.rowSpan - 1;
  const right = ref.gridCol + ref.colSpan - 1;
  const isHeader = ref.isHeader;
  const className =
    typeof ref.cell.props?.className === 'string' ? (ref.cell.props.className as string) : undefined;

  // 1) origin span 제거.
  let next = patchSection(table, grid, ref.sectionIndex, (container) =>
    patchRowNode(container, p.row, ref.rowIndexInSection, (row) => ({
      ...row,
      children: childArray(row).map((cc, i) => {
        if (i !== ref.cellIndexInRow) return cc;
        let cleared = withSpan(cc, p.colSpanProp, 1);
        cleared = withSpan(cleared, p.rowSpanProp, 1);
        return cleared;
      }),
    })),
  );

  // 2) 흡수 칸 복원 — 각 grid 행(top..bottom)에서 origin 칸 제외하고 좌→우 빈 셀 삽입.
  for (let rr = top; rr <= bottom; rr++) {
    // 매 행 직전 grid 재계산(앞 삽입이 인덱스 이동).
    for (let cc = left; cc <= right; cc++) {
      if (rr === top && cc === left) continue; // origin
      const g = treeToGrid(next, params);
      // rr 번째 grid 행의 메타.
      const meta = g.rows.find((row) => row.cells.some((x) => x.gridRow === rr)) ?? g.rows[rr];
      if (!meta) continue;
      // cc 직전 origin 셀의 cellIndexInRow(이 행 기준).
      let afterCellIdx = -1;
      for (const x of meta.cells) {
        if (x.gridRow === rr && x.gridCol < cc) {
          afterCellIdx = Math.max(afterCellIdx, x.cellIndexInRow);
        }
      }
      const template = className ? ({ props: { className } } as EditorNode) : null;
      next = patchSection(next, g, meta.sectionIndex, (container) =>
        patchRowNode(container, p.row, meta.rowIndexInSection, (row) => {
          const kids = [...childArray(row)];
          let childInsertAt: number;
          if (afterCellIdx < 0) {
            childInsertAt = kids.findIndex((x) => isCell(x, p));
            if (childInsertAt < 0) childInsertAt = kids.length;
          } else {
            const ci = childIndexOfCell(row, p, afterCellIdx);
            childInsertAt = ci < 0 ? kids.length : ci + 1;
          }
          kids.splice(childInsertAt, 0, makeCell(p, template, isHeader));
          return { ...row, children: kids };
        }),
      );
    }
  }
  return next;
}

/** 셀 className 설정(테두리 등 — 코어는 토큰 의미 미가정). 빈 문자열이면 className 제거. */
export function setCellClassName(
  table: EditorNode,
  params: TableParams | null,
  r: number,
  c: number,
  className: string,
): EditorNode {
  const p = resolveParams(params);
  const grid = treeToGrid(table, params);
  const ref = cellRefAt(grid, r, c);
  if (!ref) return table;
  return patchSection(table, grid, ref.sectionIndex, (container) =>
    patchRowNode(container, p.row, ref.rowIndexInSection, (row) => ({
      ...row,
      children: childArray(row).map((cc, i) => {
        if (i !== ref.cellIndexInRow) return cc;
        const props = { ...(cc.props ?? {}) };
        if (className.trim() === '') delete props.className;
        else props.className = className;
        const out: EditorNode = { ...cc, props };
        if (Object.keys(props).length === 0) delete out.props;
        return out;
      }),
    })),
  );
}

/**
 * 셀 인라인 style 단일 prop 설정/제거 — `cell.props.style[prop]`. 자유 색(테두리 색
 * 컬러피커) 등 클래스 토큰으로 표현 못하는(임의값 빌드 불가) 값을 인라인으로 적용한다
 * (편집기·사용자 페이지 동일 렌더 — 라이브러리 빌드 비의존). value 빈문자/undefined 면 제거.
 *
 * @param prop CSS 속성(camelCase, 예 borderColor)
 * @param value 값(빈/undefined = 제거)
 */
export function setCellStyleProp(
  table: EditorNode,
  params: TableParams | null,
  r: number,
  c: number,
  prop: string,
  value: string | undefined,
): EditorNode {
  const p = resolveParams(params);
  const grid = treeToGrid(table, params);
  const ref = cellRefAt(grid, r, c);
  if (!ref) return table;
  return patchSection(table, grid, ref.sectionIndex, (container) =>
    patchRowNode(container, p.row, ref.rowIndexInSection, (row) => ({
      ...row,
      children: childArray(row).map((cc, i) => {
        if (i !== ref.cellIndexInRow) return cc;
        const props = { ...(cc.props ?? {}) };
        const style = { ...((props.style as Record<string, unknown> | undefined) ?? {}) };
        if (!value || value.trim() === '') delete style[prop];
        else style[prop] = value;
        if (Object.keys(style).length === 0) delete props.style;
        else props.style = style;
        const out: EditorNode = { ...cc, props };
        if (Object.keys(props).length === 0) delete out.props;
        return out;
      }),
    })),
  );
}

/**
 * 셀 색 className 토큰을 **스킴별**로 설정/교체(불변) — 프리셋 테두리/배경 색의 라이트/다크
 * 분리 적용. `replaceScopedGroupToken` 으로 같은 group(색 패밀리) 토큰을
 * 스킴별로 교체한다(라이트=비-`dark:` 토큰, 다크=`dark:` 토큰만 — 한쪽 편집이 다른쪽 보존).
 *
 * 자유 HEX 색은 인라인 `style`(setCellStyleProp)로 라이트 전용 적용하고, 프리셋 색(카탈로그
 * `token` 보유)만 본 토큰 경로를 쓴다. 토큰 어휘(색 패밀리 group)는 호출자가 `groupTokens`
 * 로 공급(템플릿 카탈로그 — 코어 라이브러리 중립). `token` 빈/undefined = 그 스킴 색 해제.
 *
 * @param token 적용할 base 형 색 토큰(예 `border-gray-500`/`bg-gray-100`). 빈/undefined = 해제.
 * @param groupTokens 이 색 group 의 base 형 토큰 전체(예 카탈로그 colors[].token 목록)
 * @param scheme 'light' 면 base 토큰, 'dark' 면 `dark:` 토큰으로 적용
 * @return 패치된 표 노드 사본
 */
export function setCellColorToken(
  table: EditorNode,
  params: TableParams | null,
  r: number,
  c: number,
  token: string | undefined | null,
  groupTokens: string[],
  scheme: 'light' | 'dark',
): EditorNode {
  const p = resolveParams(params);
  const grid = treeToGrid(table, params);
  const ref = cellRefAt(grid, r, c);
  if (!ref) return table;
  const dark = scheme === 'dark';
  return patchSection(table, grid, ref.sectionIndex, (container) =>
    patchRowNode(container, p.row, ref.rowIndexInSection, (row) => ({
      ...row,
      children: childArray(row).map((cc, i) => {
        if (i !== ref.cellIndexInRow) return cc;
        const props = { ...(cc.props ?? {}) };
        const cur = typeof props.className === 'string' ? (props.className as string) : '';
        const next = replaceScopedGroupToken(cur, groupTokens, token, dark);
        if (next === '') delete props.className;
        else props.className = next;
        const out: EditorNode = { ...cc, props };
        if (Object.keys(props).length === 0) delete out.props;
        return out;
      }),
    })),
  );
}

/** 셀 text 설정(다국어 키 또는 평문). 직접 text 셀 전용 — 복합 셀은 setCellNode 사용. */
export function setCellText(
  table: EditorNode,
  params: TableParams | null,
  r: number,
  c: number,
  text: string,
): EditorNode {
  const p = resolveParams(params);
  const grid = treeToGrid(table, params);
  const ref = cellRefAt(grid, r, c);
  if (!ref) return table;
  return patchSection(table, grid, ref.sectionIndex, (container) =>
    patchRowNode(container, p.row, ref.rowIndexInSection, (row) => ({
      ...row,
      children: childArray(row).map((cc, i) => (i === ref.cellIndexInRow ? { ...cc, text } : cc)),
    })),
  );
}

/**
 * 셀 노드 전체 교체 — origin 셀(r,c)의 노드를 `nextCell` 로 치환한다. span prop 보존은
 * 호출자 책임(보통 patchTextAtPath 로 텍스트 자손만 바꾼 동일 셀 사본을 넘김).
 * 셀이 **임의 HTML/컴포넌트 자식**(Span/A/Img/Icon/Button 등)을 품을 때 그 안의 텍스트
 * 노드만 바꾼 셀 사본을 안전하게 반영하는 일반 경로(단순 text 셀은 setCellText 로 충분).
 */
export function setCellNode(
  table: EditorNode,
  params: TableParams | null,
  r: number,
  c: number,
  nextCell: EditorNode,
): EditorNode {
  const p = resolveParams(params);
  const grid = treeToGrid(table, params);
  const ref = cellRefAt(grid, r, c);
  if (!ref) return table;
  return patchSection(table, grid, ref.sectionIndex, (container) =>
    patchRowNode(container, p.row, ref.rowIndexInSection, (row) => ({
      ...row,
      children: childArray(row).map((cc, i) => (i === ref.cellIndexInRow ? nextCell : cc)),
    })),
  );
}
