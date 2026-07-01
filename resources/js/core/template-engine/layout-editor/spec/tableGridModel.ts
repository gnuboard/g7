/**
 * tableGridModel.ts — 표 노드 children 트리 ↔ 논리적 grid 변환 어댑터
 *
 * 코어 빌트인 Table 에디터(`kind:"table"`)의 데이터 모델. 표 노드의 children 트리
 * (`section(Thead/Tbody) > row(Tr) > cell(Th/Td)`)를 **병합(colSpan/rowSpan)을 고려한
 * 행×열 논리 grid** 로 펼쳐 행/열/병합 연산을 수행하고, 다시 트리로 환원한다. 별도
 * `rows[][]` 직렬화 모델을 도입하지 않고 기존 노드/패치/렌더/드래그 파이프라인을 그대로
 * 재사용한다(부록4 데이터 모델 결정).
 *
 * 역할 매핑은 capability `nodeEditor.params`(rowContainer/row/cell/headerCell/colSpanProp/
 * rowSpanProp)로만 식별한다 — 컴포넌트명을 코어가 가정하지 않는다(부록4-ter 중립성). div-grid
 * 기반 표 템플릿도 params 에 자기 컴포넌트명을 넣으면 동일 어댑터를 쓴다.
 *
 * 논리 grid 정합(겹침/구멍 검출, 직사각형 병합만 허용)을 가드해 손상된 표를 만들지 않는다.
 *
 * @since engine-v1.50.0
 */

import type { EditorNode } from '../utils/layoutTreeUtils';

/** 표 역할 매핑 파라미터(capability `nodeEditor.params`). */
export interface TableParams {
  /** 행 컨테이너 역할 컴포넌트명(예 Tbody). Thead 도 추가 인식. */
  rowContainer?: string;
  /** 행 역할 컴포넌트명(예 Tr). */
  row?: string;
  /** 데이터 셀 역할 컴포넌트명(예 Td). */
  cell?: string;
  /** 머리글 셀 역할 컴포넌트명(예 Th). */
  headerCell?: string;
  /** 가로 병합 prop 키(예 colSpan). */
  colSpanProp?: string;
  /** 세로 병합 prop 키(예 rowSpan). */
  rowSpanProp?: string;
}

/** 기본 역할 매핑(표준 HTML 표 어휘) — params 미공급 키 폴백. */
const DEFAULTS: Required<Omit<TableParams, never>> = {
  rowContainer: 'Tbody',
  row: 'Tr',
  cell: 'Td',
  headerCell: 'Th',
  colSpanProp: 'colSpan',
  rowSpanProp: 'rowSpan',
};

/** params 를 기본값으로 채운 정규화 매핑 반환. */
export function resolveParams(params?: TableParams | null): Required<TableParams> {
  return {
    rowContainer: params?.rowContainer ?? DEFAULTS.rowContainer,
    row: params?.row ?? DEFAULTS.row,
    cell: params?.cell ?? DEFAULTS.cell,
    headerCell: params?.headerCell ?? DEFAULTS.headerCell,
    colSpanProp: params?.colSpanProp ?? DEFAULTS.colSpanProp,
    rowSpanProp: params?.rowSpanProp ?? DEFAULTS.rowSpanProp,
  };
}

/** 자식 노드 배열 안전 추출. */
function childArray(node: EditorNode | undefined | null): EditorNode[] {
  if (!node) return [];
  return Array.isArray(node.children) ? (node.children as EditorNode[]) : [];
}

/** prop 정수값 안전 추출(>=1, 없으면 1). */
function spanOf(node: EditorNode, prop: string): number {
  const raw = node.props?.[prop];
  const n =
    typeof raw === 'number' ? raw : typeof raw === 'string' ? parseInt(raw, 10) : NaN;
  return Number.isFinite(n) && n >= 1 ? Math.floor(n) : 1;
}

/**
 * 표의 "섹션"(행 컨테이너). 표 노드의 직접 자식 중 행(row)을 담는 컨테이너
 * (Thead/Tbody/Tfoot 등). 일부 표는 섹션 없이 표 노드가 직접 Tr 을 담을 수도 있어
 * 그 경우 가상 섹션(`node=table 자신`)으로 취급한다.
 */
export interface GridSection {
  /** 섹션 컨테이너 노드(가상 섹션이면 표 노드 자신). */
  container: EditorNode;
  /** 표 children 에서 이 섹션 컨테이너의 인덱스(가상 섹션이면 -1). */
  containerIndex: number;
  /** 머리글 섹션 여부(컨테이너 이름이 Thead 이거나 셀이 headerCell). */
  isHeader: boolean;
}

/** 논리 grid 한 칸이 가리키는 실제 셀 노드 + 좌표 정보. */
export interface GridCellRef {
  /** 셀 노드(병합 origin 셀). */
  cell: EditorNode;
  /** 셀이 속한 섹션 인덱스(sections 배열 기준). */
  sectionIndex: number;
  /** 섹션 안에서의 행 인덱스(row 노드 인덱스). */
  rowIndexInSection: number;
  /** 행 안에서의 셀 노드 인덱스(cell 노드 인덱스). */
  cellIndexInRow: number;
  /** 논리 grid 상의 origin 행(0-base, 전체 표 누적). */
  gridRow: number;
  /** 논리 grid 상의 origin 열(0-base). */
  gridCol: number;
  /** 가로 병합 수(>=1). */
  colSpan: number;
  /** 세로 병합 수(>=1). */
  rowSpan: number;
  /** 머리글 셀 여부. */
  isHeader: boolean;
}

/** 논리 grid 한 행(전체 표 누적 행). origin/흡수 구분. */
export interface GridRow {
  /** 이 행의 origin 셀들(좌→우). 병합 흡수칸은 제외. */
  cells: GridCellRef[];
  /** 섹션 인덱스. */
  sectionIndex: number;
  /** 섹션 안 행 인덱스. */
  rowIndexInSection: number;
  /** 행 노드. */
  rowNode: EditorNode;
}

/** 표 트리에서 변환한 논리 grid. */
export interface TableGrid {
  sections: GridSection[];
  rows: GridRow[];
  /** 논리 열 수(병합 고려 최대 너비). */
  colCount: number;
  /** 정합성: 겹침/구멍 없음. */
  valid: boolean;
  /** 부적합 사유(valid=false 시). */
  issues: string[];
}

/**
 * 표 노드를 논리 grid 로 변환한다. 병합(colSpan/rowSpan)을 고려해 각 행의 origin 셀을
 * 좌표(gridRow/gridCol)로 펼친다. 겹침/구멍이 있으면 `valid:false` + issues.
 *
 * @param table 표 노드
 * @param params 역할 매핑(capability nodeEditor.params)
 * @return 논리 grid
 */
export function treeToGrid(table: EditorNode, params?: TableParams | null): TableGrid {
  const p = resolveParams(params);
  const issues: string[] = [];

  // 1) 섹션 수집: 표의 직접 자식 중 row 를 담는 컨테이너.
  const directChildren = childArray(table);
  const sections: GridSection[] = [];
  directChildren.forEach((child, idx) => {
    const kids = childArray(child);
    const hasRows = kids.some((k) => k.name === p.row);
    if (hasRows || child.name === p.rowContainer || child.name === 'Thead' || child.name === 'Tfoot') {
      sections.push({
        container: child,
        containerIndex: idx,
        isHeader: child.name === 'Thead',
      });
    }
  });
  // 섹션이 없으면 표 노드가 직접 Tr 을 담는 형태 — 가상 섹션.
  if (sections.length === 0 && directChildren.some((c) => c.name === p.row)) {
    sections.push({ container: table, containerIndex: -1, isHeader: false });
  }

  // 2) 각 섹션의 행을 누적해 grid 점유 맵으로 펼친다.
  const occupied: boolean[][] = []; // [gridRow][gridCol]
  const rows: GridRow[] = [];
  let gridRow = 0;
  let colCount = 0;

  const ensureRow = (r: number): boolean[] => {
    while (occupied.length <= r) occupied.push([]);
    return occupied[r]!;
  };

  sections.forEach((section, sectionIndex) => {
    const rowNodes = childArray(section.container).filter((c) => c.name === p.row);
    rowNodes.forEach((rowNode, rowIndexInSection) => {
      const rowCells: GridCellRef[] = [];
      const rowOccRow = ensureRow(gridRow);
      let col = 0;
      const cellNodes = childArray(rowNode).filter(
        (c) => c.name === p.cell || c.name === p.headerCell,
      );
      cellNodes.forEach((cellNode, cellIndexInRow) => {
        // 다음 빈 열로 이동(상위 행의 rowSpan 흡수칸 건너뜀).
        while (rowOccRow[col]) col++;
        const colSpan = spanOf(cellNode, p.colSpanProp);
        const rowSpan = spanOf(cellNode, p.rowSpanProp);
        const ref: GridCellRef = {
          cell: cellNode,
          sectionIndex,
          rowIndexInSection,
          cellIndexInRow,
          gridRow,
          gridCol: col,
          colSpan,
          rowSpan,
          isHeader: cellNode.name === p.headerCell,
        };
        rowCells.push(ref);
        // 점유 마킹(겹침 검출).
        for (let dr = 0; dr < rowSpan; dr++) {
          const occ = ensureRow(gridRow + dr);
          for (let dc = 0; dc < colSpan; dc++) {
            if (occ[col + dc]) {
              issues.push(`overlap at row ${gridRow + dr}, col ${col + dc}`);
            }
            occ[col + dc] = true;
          }
        }
        col += colSpan;
      });
      colCount = Math.max(colCount, col);
      rows.push({ cells: rowCells, sectionIndex, rowIndexInSection, rowNode });
      gridRow++;
    });
  });

  // 3) 구멍 검출: 각 grid 행에서 colCount 미만으로 비어 있으면 구멍.
  for (let r = 0; r < gridRow; r++) {
    const occ = occupied[r] ?? [];
    for (let c = 0; c < colCount; c++) {
      if (!occ[c]) issues.push(`hole at row ${r}, col ${c}`);
    }
  }

  return { sections, rows, colCount, valid: issues.length === 0, issues };
}

/**
 * 셀 ref 가 표 노드 children 트리에서 갖는 **상대 자식 인덱스 경로**를 계산한다
 * (`[sectionChildIdx, rowChildIdx, cellChildIdx]`). 가상 섹션(표 노드가 직접 Tr 을
 * 담는 형태)이면 섹션 인덱스는 생략하고 `[rowChildIdx, cellChildIdx]` 를 반환한다.
 *
 * DynamicRenderer 가 각 노드에 부여하는 `data-editor-path`(`{tablePath}.children.{idx}...`)
 * 와 동형이므로, 캔버스 인플레이스 오버레이가 셀 DOM 박스를 grid 좌표로
 * 역매핑할 때 본 경로로 조회한다. grid 모델은 row/cell 을 **이름으로 필터**하지만 실제
 * 트리는 비-행/비-셀 형제(주석/공백 등)를 가질 수 있어, seen 카운트로 실제 자식 인덱스를
 * 환원한다(`tableGridMutations.childIndexOfCell` 동형 로직).
 *
 * @param table 표 노드
 * @param ref 셀 ref(treeToGrid 산출)
 * @param params 역할 매핑
 * @return 표 노드 기준 상대 자식 인덱스 경로
 */
export function cellTreePath(
  table: EditorNode,
  ref: GridCellRef,
  params?: TableParams | null,
): number[] {
  const p = resolveParams(params);
  const grid = treeToGrid(table, params);
  const section = grid.sections[ref.sectionIndex];
  const path: number[] = [];

  // 1) 섹션 컨테이너 자식 인덱스(가상 섹션이면 생략 — 표 노드 직속).
  let container: EditorNode;
  if (section && section.containerIndex >= 0) {
    path.push(section.containerIndex);
    container = childArray(table)[section.containerIndex] ?? table;
  } else {
    container = table;
  }

  // 2) 행 노드 자식 인덱스(이름 필터 → 실제 인덱스 환원).
  let rowSeen = -1;
  let rowChildIdx = -1;
  let rowNode: EditorNode | null = null;
  const containerKids = childArray(container);
  for (let i = 0; i < containerKids.length; i++) {
    if (containerKids[i]!.name === p.row) {
      rowSeen++;
      if (rowSeen === ref.rowIndexInSection) {
        rowChildIdx = i;
        rowNode = containerKids[i]!;
        break;
      }
    }
  }
  if (rowChildIdx < 0 || !rowNode) return path; // 행 미발견(손상) — 섹션까지만
  path.push(rowChildIdx);

  // 3) 셀 노드 자식 인덱스(셀 필터 → 실제 인덱스 환원).
  let cellSeen = -1;
  const rowKids = childArray(rowNode);
  for (let i = 0; i < rowKids.length; i++) {
    const nm = rowKids[i]!.name;
    if (nm === p.cell || nm === p.headerCell) {
      cellSeen++;
      if (cellSeen === ref.cellIndexInRow) {
        path.push(i);
        return path;
      }
    }
  }
  return path; // 셀 미발견 — 행까지만
}

/** grid 좌표(r,c)를 덮는 origin 셀 ref 를 찾는다(병합 흡수칸 포함 조회). */
export function cellRefAt(grid: TableGrid, r: number, c: number): GridCellRef | null {
  for (const row of grid.rows) {
    for (const ref of row.cells) {
      if (
        r >= ref.gridRow &&
        r < ref.gridRow + ref.rowSpan &&
        c >= ref.gridCol &&
        c < ref.gridCol + ref.colSpan
      ) {
        return ref;
      }
    }
  }
  return null;
}

/**
 * 열 밴드(병합 블록) 분할 — 모든 행에서 셀이 가로지르지 않는 "깨끗한 수직 경계"로 열을
 * 분할한다. 한 밴드 `[start, end)` 는 통째로 이동 가능한 단위(단일 열 또는 colSpan 병합 블록).
 *
 * 경계 x(열 x-1 과 x 사이)가 깨끗 = 어떤 행의 어떤 셀도 x 를 가로지르지 않음(모든 셀이
 * x 에서 끝나거나 x 에서 시작). : `[(1,2)][3][(4,5)][6]` 처럼 병합 블록을 하나의
 * 이동 단위로 본다. 6 을 좌측 이동 = `[6]` 밴드를 왼쪽 인접 밴드 `[(4,5)]` 너머로 통째 이동.
 *
 * @param grid 논리 grid
 * @return 밴드 배열(좌→우, 각 `{ start, end }` end 는 배타적)
 */
export function columnBands(grid: TableGrid): { start: number; end: number }[] {
  if (grid.colCount === 0) return [];
  // 깨끗한 경계 집합: 0 과 colCount 는 항상 경계. 내부 x 는 어떤 셀도 안 가로지르면 경계.
  const clean = new Set<number>([0, grid.colCount]);
  for (let x = 1; x < grid.colCount; x++) {
    let straddles = false;
    for (const row of grid.rows) {
      for (const ref of row.cells) {
        if (ref.gridCol < x && ref.gridCol + ref.colSpan > x) {
          straddles = true;
          break;
        }
      }
      if (straddles) break;
    }
    if (!straddles) clean.add(x);
  }
  const sorted = Array.from(clean).sort((a, b) => a - b);
  const bands: { start: number; end: number }[] = [];
  for (let i = 0; i < sorted.length - 1; i++) {
    bands.push({ start: sorted[i]!, end: sorted[i + 1]! });
  }
  return bands;
}

/**
 * 행 밴드(병합 블록) 분할 — 섹션별로, 모든 열에서 셀이 가로지르지 않는 "깨끗한 수평 경계"로
 * 행을 분할한다. 한 밴드는 통째로 이동 가능한 행 단위(단일 행 또는 rowSpan 병합 블록).
 * 섹션 경계는 항상 밴드 경계(Thead↔Tbody 이동 금지).
 *
 * @param grid 논리 grid
 * @return 밴드 배열(위→아래, 각 `{ start, end, sectionIndex }` end 배타적, gridRow 기준)
 */
export function rowBands(grid: TableGrid): { start: number; end: number; sectionIndex: number }[] {
  const total = grid.rows.length;
  if (total === 0) return [];
  // 섹션 경계 = 항상 깨끗. 내부 y 는 어떤 셀도 안 가로지르면 깨끗.
  const sectionOf = (gridRow: number): number =>
    grid.rows.find((r) => (r.cells[0]?.gridRow ?? -1) === gridRow)?.sectionIndex ??
    grid.rows[gridRow]?.sectionIndex ??
    0;
  const clean = new Set<number>([0, total]);
  for (let y = 1; y < total; y++) {
    // 섹션이 바뀌면 깨끗 경계.
    if (sectionOf(y) !== sectionOf(y - 1)) {
      clean.add(y);
      continue;
    }
    let straddles = false;
    for (const row of grid.rows) {
      for (const ref of row.cells) {
        if (ref.gridRow < y && ref.gridRow + ref.rowSpan > y) {
          straddles = true;
          break;
        }
      }
      if (straddles) break;
    }
    if (!straddles) clean.add(y);
  }
  const sorted = Array.from(clean).sort((a, b) => a - b);
  const bands: { start: number; end: number; sectionIndex: number }[] = [];
  for (let i = 0; i < sorted.length - 1; i++) {
    bands.push({ start: sorted[i]!, end: sorted[i + 1]!, sectionIndex: sectionOf(sorted[i]!) });
  }
  return bands;
}

/** 주어진 gridCol 이 속한 열 밴드 인덱스(columnBands 기준). */
export function columnBandIndexAt(grid: TableGrid, gridCol: number): number {
  return columnBands(grid).findIndex((b) => gridCol >= b.start && gridCol < b.end);
}

/** 주어진 gridRow 가 속한 행 밴드 인덱스(rowBands 기준). */
export function rowBandIndexAt(grid: TableGrid, gridRow: number): number {
  return rowBands(grid).findIndex((b) => gridRow >= b.start && gridRow < b.end);
}

/**
 * 행 이동 가능 여부 — 병합 블록(행 밴드) 단위. 대상 행이 속한 밴드를 인접 밴드 너머로 통째
 * 이동할 수 있는지 판정한다. 인접 밴드가 없으면(표 경계) boundary, 섹션이 다르면 section.
 * 밴드 모델이므로 rowSpan 병합 블록도 통째 이동 가능.
 *
 * @param grid 논리 grid
 * @param atGridRow 이동 대상 grid 행(밴드 안 아무 행)
 * @param dir 방향(-1 위 / +1 아래) — 인접 밴드 방향
 * @return `{ ok, reason? }`
 */
export function canMoveRow(
  grid: TableGrid,
  atGridRow: number,
  dir: -1 | 1,
): { ok: boolean; reason?: 'boundary' | 'section' } {
  const bands = rowBands(grid);
  const bi = bands.findIndex((b) => atGridRow >= b.start && atGridRow < b.end);
  if (bi < 0) return { ok: false, reason: 'boundary' };
  const ni = bi + dir;
  if (ni < 0 || ni >= bands.length) return { ok: false, reason: 'boundary' };
  if (bands[bi]!.sectionIndex !== bands[ni]!.sectionIndex) {
    return { ok: false, reason: 'section' };
  }
  return { ok: true };
}

/**
 * 열 이동 가능 여부 — 병합 블록(열 밴드) 단위. 대상 열이 속한 밴드를 인접 밴드 너머로 통째
 * 이동할 수 있는지 판정한다. 인접 밴드가 없으면(표 경계) boundary.
 * `[(1,2)][3][(4,5)][6]` 에서 6 좌측 → `[(1,2)][3][6][(4,5)]`(1,2,3,6,4,5).
 *
 * @param grid 논리 grid
 * @param atGridCol 이동 대상 grid 열(밴드 안 아무 열)
 * @param dir 방향(-1 왼 / +1 오른) — 인접 밴드 방향
 * @return `{ ok, reason? }`
 */
export function canMoveColumn(
  grid: TableGrid,
  atGridCol: number,
  dir: -1 | 1,
): { ok: boolean; reason?: 'boundary' } {
  const bands = columnBands(grid);
  const bi = bands.findIndex((b) => atGridCol >= b.start && atGridCol < b.end);
  if (bi < 0) return { ok: false, reason: 'boundary' };
  const ni = bi + dir;
  if (ni < 0 || ni >= bands.length) return { ok: false, reason: 'boundary' };
  return { ok: true };
}
