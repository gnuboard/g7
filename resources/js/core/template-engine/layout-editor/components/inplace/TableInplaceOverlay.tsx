// e2e:allow 레이아웃 편집기 캔버스 오버레이/속성패널 UI — dnd-kit/합성 이벤트 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스(T1~T8) 실측 + 단위/레이아웃 렌더링 테스트로 검증
/**
 * TableInplaceOverlay.tsx — `table` 캔버스 인플레이스 오버레이
 *
 * 캔버스에 렌더된 표 위에 **시중 편집기 수준**의 인플레이스 어포던스를 얹는다 —
 * 셀 선택(병합용), **전용 거터 레일**(표 상단=열 / 표 좌측=행)의 추가·삭제·이동 핸들,
 * 선택 셀 병합/해제. 코어가 측정해 주입한 셀 박스(`cellBoxes`, `data-editor-path` 단위)를
 * grid 좌표로 역매핑해 배치한다. 모든 구조 변형은 **속성 패널 TableEditor 와 동일한
 * `tableGridMutations` 순수 함수**를 호출하고 `onPatchNode(next)` 로 노드를 통째 교체한다
 * (단일 패치 경로 SSoT — 인플레이스/속성탭 양 경로가 같은 변형을 공유).
 *
 * **거터 레일 모델**: 거터 핸들을 표 셀 위가 아니라 표 **바깥의
 * 전용 레일**(상단 열 레일 / 좌측 행 레일)에 두어 셀·캔버스 콘텐츠·코어 선택 오버레이
 * (ⓘ/칩/리사이즈)와 겹치지 않게 한다. 핸들 그룹은 해당 행/열에 마우스를 올릴 때만 진하게
 * 표시(평소 흐림)해 시각 혼잡을 줄인다. z-index 는 `TABLE_INPLACE`(코어 어포던스 위)라
 * 거터 버튼 클릭이 드래그 핸들/코어 오버레이에 가로채이지 않는다.
 *
 * `registerCanvasOverlay('table', TableInplaceOverlay)` 로 코어 빌트인 등록(특권 분기 0 —
 * 템플릿이 같은 kind 재등록 시 대체). capability 역할 매핑은 `params`(rowContainer/row/
 * cell/headerCell/colSpanProp/rowSpanProp)로만 식별(컴포넌트명 가정 0 — 부록4-ter 중립성).
 *
 * 편집기 코어 컴포넌트 — `g7le-*` BEM + 인라인 스타일만, CSS 라이브러리 토큰 비종속
 * (메모리 `feedback_layout_editor_no_css_lib_dependency`).
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { CanvasOverlayProps, OverlayBox } from '../../spec/canvasOverlayRegistry';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import { TABLE_INPLACE } from '../../utils/overlayZIndex';
import {
  treeToGrid,
  cellRefAt,
  cellTreePath,
  canMoveRow,
  canMoveColumn,
  type TableParams,
  type GridCellRef,
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
  setCellStyleProp,
  setCellColorToken,
} from '../../spec/tableGridMutations';
import { CellBorderControl, type CellBorderCatalog } from '../property-controls/CellBorderControl';
import { CellFillControl, type CellFillCatalog } from '../property-controls/CellFillControl';
import { CellPaddingControl, type CellPaddingCatalog } from '../property-controls/CellPaddingControl';
import { ColorSchemeTabs } from '../property-controls/ColorSchemeTabs';

/** grid 좌표(논리) → 측정 셀 박스 매핑 1건. */
interface MeasuredCell {
  ref: GridCellRef;
  box: OverlayBox;
}

/**
 * `cellBoxes`(코어 측정, path 단위)를 표 노드 자식 인덱스 경로(`cellTreePath`)로 역매핑해
 * 각 origin 셀에 측정 박스를 붙인다. cellBoxes 의 path 는 표 노드 기준 상대 경로
 * (`children.a.children.b...`)이거나 그 접미사로 끝나는 절대 path 다.
 */
function buildMeasuredCells(
  node: EditorNode,
  params: TableParams | null,
  cellBoxes: Array<OverlayBox & { path: string }> | undefined,
): { measured: MeasuredCell[]; grid: ReturnType<typeof treeToGrid> } {
  const grid = treeToGrid(node, params);
  if (!cellBoxes || cellBoxes.length === 0) return { measured: [], grid };
  const measured: MeasuredCell[] = [];
  for (const row of grid.rows) {
    for (const ref of row.cells) {
      const rel = cellTreePath(node, ref, params);
      const suffix = rel.map((n) => `children.${n}`).join('.');
      const hit = cellBoxes.find((b) => b.path === suffix || b.path.endsWith('.' + suffix));
      if (hit) measured.push({ ref, box: { top: hit.top, left: hit.left, width: hit.width, height: hit.height } });
    }
  }
  return { measured, grid };
}

/** 레일 폭/여백 상수(표 바깥 전용 레일). 버튼이 편안한 클릭 타겟이도록 넉넉히. */
const COL_RAIL = 26; // 상단 열 레일 두께(가로 4버튼 1줄)
const ROW_RAIL = 50; // 좌측 행 레일 두께(2×2 버튼 그리드)
const RAIL_GAP = 6; // 표와 레일 사이 간격
// 코어 4방향 +(InsertionAffordances) 밴드 — 박스 변 중앙 -30px + 24px 버튼이라 변에서 ~42px
// 점유. 거터 레일을 그 너머로 밀어 코어 + 와 겹치지 않게 한다.
const CORE_PLUS_BAND = 44;

// 빈 셀 클릭 타겟 최소 크기(px) — 오버레이 셀-픽 영역 보장(셀 DOM 보정과 별개 안전망).
const MIN_CELL_PICK = 18;

/**
 * 편집기 캔버스에서만 빈 표 셀이 1px 로 찌부러지지 않도록 최소 크기를 부여하는 1회 주입 CSS.
 *  `.g7le-preview-frame` 안의 셀에만
 * 적용되며 저장 content·런타임 사용자 페이지에는 영향이 없다. 내용이 있는 셀은 이미 이 크기를
 * 초과하므로 무영향이고, 표 frame 전체 크기(상단 여백 등)와도 무관하다 — 캔버스 크기 출렁임의
 * 원인은 본 CSS 가 아니라 PreviewCanvas 의 VH_EXPAND 로직이었다(2026-06-06 측정으로 분리 확인).
 */
const EMPTY_CELL_STYLE_ID = 'g7le-table-inplace-empty-cell-style';
function ensureEmptyCellStyle(): void {
  if (typeof document === 'undefined') return;
  if (document.getElementById(EMPTY_CELL_STYLE_ID)) return;
  const el = document.createElement('style');
  el.id = EMPTY_CELL_STYLE_ID;
  el.textContent =
    '.g7le-preview-frame td[data-editor-name],.g7le-preview-frame th[data-editor-name]{min-width:56px;height:38px;}' +
    '.g7le-preview-frame table[data-editor-name]{min-width:120px;}';
  document.head.appendChild(el);
}

export function TableInplaceOverlay({
  node,
  params,
  nodeBox,
  cellBoxes,
  selectedCellPath,
  colorScheme: initialColorScheme,
  t,
  onPatchNode,
  onRequestInlineEdit,
}: CanvasOverlayProps): React.ReactElement | null {
  const tableParams = useMemo(
    () => (params && typeof params === 'object' ? (params as TableParams) : null),
    [params],
  );

  const { measured, grid } = useMemo(
    () => buildMeasuredCells(node, tableParams, cellBoxes),
    [node, tableParams, cellBoxes],
  );

  // 셀 선택 — **2단계 선택 모델**: 1차 클릭은 표를 선택(드래그 가능),
  // 표가 선택된 상태에서 셀을 한 번 더 클릭하면 그 셀이 선택된다(이동 그립 없이 — 시중
  // 컨테이너→자식 드릴 패턴). 내부 pickedCell(캔버스 셀-픽 클릭) 우선, 없으면 코어 직접
  // 선택(selectedCellPath, E2E/프로그램 선택)에서 파생. selectedCellPath 의 셀을 grid 좌표로 역매핑.
  const [pickedCell, setPickedCell] = useState<{ r: number; c: number } | null>(null);
  // Shift+클릭 영역 끝(다중 셀 선택 — 영역 병합/영역 테두리용). 앵커=pickedCell, 끝=pickedEnd.
  const [pickedEnd, setPickedEnd] = useState<{ r: number; c: number } | null>(null);
  const selFromCore = useMemo<{ r: number; c: number } | null>(() => {
    if (!selectedCellPath) return null;
    for (const row of grid.rows) {
      for (const ref of row.cells) {
        const rel = cellTreePath(node, ref, tableParams).map((n) => `children.${n}`).join('.');
        if (selectedCellPath === rel || selectedCellPath.startsWith(rel + '.')) {
          return { r: ref.gridRow, c: ref.gridCol };
        }
      }
    }
    return null;
  }, [selectedCellPath, grid, node, tableParams]);
  // pickedCell 이 현재 grid 에 유효하면 우선(병합/행삭제 후 좌표 밖이면 폐기).
  const sel = useMemo<{ r: number; c: number } | null>(() => {
    if (pickedCell && cellRefAt(grid, pickedCell.r, pickedCell.c)) return pickedCell;
    return selFromCore;
  }, [pickedCell, selFromCore, grid]);

  // 전체 셀 테두리 일괄 적용 모드(버튼 토글) — on 이면 borderTargets 가 전 셀.
  const [allCellsBorder, setAllCellsBorder] = useState(false);
  // 셀 색 라이트/다크 탭 — 캔버스 미리보기 스킴(previewColorScheme)을 초기값으로 받되 로컬
  // 토글 독립(다크 캔버스면 기본=다크). 컨트롤 로컬 상태(node editor scope 미보유 계약).
  const [colorScheme, setColorScheme] = useState<'light' | 'dark'>(initialColorScheme === 'dark' ? 'dark' : 'light');
  // hover 중인 행/열(레일 핸들 강조 — 시각 혼잡 감소).
  const [hoverRow, setHoverRow] = useState<number | null>(null);
  const [hoverCol, setHoverCol] = useState<number | null>(null);

  // hover-intent: 셀→거터 레일로 마우스를 옮기는 사이(셀 밖 빈 공간 통과) hover 가 즉시
  // 풀려 거터 버튼이 사라져 클릭 불가하던 결함. 클리어를 짧게 지연하고
  // 셀/레일 진입이 그 타이머를 취소해, 버튼에 도달할 때까지 hover 를 유지한다.
  const hoverClearTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const cancelHoverClear = useCallback(() => {
    if (hoverClearTimer.current !== null) { clearTimeout(hoverClearTimer.current); hoverClearTimer.current = null; }
  }, []);
  const scheduleHoverClear = useCallback(() => {
    cancelHoverClear();
    hoverClearTimer.current = setTimeout(() => { setHoverRow(null); setHoverCol(null); hoverClearTimer.current = null; }, 240);
  }, [cancelHoverClear]);
  useEffect(() => () => cancelHoverClear(), [cancelHoverClear]);

  // 빈 셀 최소 크기 CSS 1회 주입.
  useEffect(() => { ensureEmptyCellStyle(); }, []);

  const patch = useCallback((next: EditorNode) => onPatchNode(next), [onPatchNode]);

  const selectedRef: GridCellRef | null = useMemo(
    () => (sel ? cellRefAt(grid, sel.r, sel.c) : null),
    [grid, sel],
  );
  // 테두리 피커 미리보기/역해석 기준 = 앵커(sel) 셀 className.
  const anchorCellClassName = useMemo<string>(
    () => (selectedRef && typeof selectedRef.cell.props?.className === 'string' ? (selectedRef.cell.props.className as string) : ''),
    [selectedRef],
  );

  // ── 구조 연산(속성 패널 TableEditor 와 동일 mutation 함수) ───────────────────
  const onAddRow = useCallback((after: number) => patch(addRow(node, tableParams, after)), [node, tableParams, patch]);
  const onRemoveRow = useCallback(
    (r: number) => patch(removeRow(node, tableParams, r)),
    [node, tableParams, patch],
  );
  const onMoveRow = useCallback((r: number, dir: -1 | 1) => patch(moveRow(node, tableParams, r, dir)), [node, tableParams, patch]);
  const onAddCol = useCallback((after: number) => patch(addColumn(node, tableParams, after)), [node, tableParams, patch]);
  const onRemoveCol = useCallback(
    (c: number) => patch(removeColumn(node, tableParams, c)),
    [node, tableParams, patch],
  );
  const onMoveCol = useCallback((c: number, dir: -1 | 1) => patch(moveColumn(node, tableParams, c, dir)), [node, tableParams, patch]);

  // 병합 — 선택 셀을 **오른쪽/아래 인접 셀과 병합**(범위 드래그 없이 인접 병합, 직관). 코어
  // 선택은 단일 셀이라 시중 편집기의 "선택 셀 + 방향 병합" 모델을 쓴다. mergeCells 는 사각형
  // 영역(직사각형만)을 받으므로 선택 셀 origin 부터 인접 셀까지의 사각형을 전달한다.
  const mergeDir = useCallback(
    (dir: 'right' | 'down') => {
      if (!selectedRef) return;
      const r0 = selectedRef.gridRow, c0 = selectedRef.gridCol;
      const r1 = dir === 'down' ? r0 + selectedRef.rowSpan : r0 + selectedRef.rowSpan - 1;
      const c1 = dir === 'right' ? c0 + selectedRef.colSpan : c0 + selectedRef.colSpan - 1;
      // 경계 밖이면 no-op.
      if (r1 >= grid.rows.length || c1 >= grid.colCount) return;
      const res = mergeCells(node, tableParams, r0, c0, r1, c1);
      if (res.ok) patch(res.table);
    },
    [selectedRef, grid, node, tableParams, patch],
  );
  const canMergeRight = !!(selectedRef && selectedRef.gridCol + selectedRef.colSpan < grid.colCount);
  const canMergeDown = !!(selectedRef && selectedRef.gridRow + selectedRef.rowSpan < grid.rows.length);

  // Shift+클릭 영역 — 앵커(sel)~pickedEnd 직사각형 내 origin 셀들. pickedEnd 가 유효할 때만.
  const rangeRect = useMemo<{ r0: number; c0: number; r1: number; c1: number } | null>(() => {
    if (!sel || !pickedEnd) return null;
    if (!cellRefAt(grid, pickedEnd.r, pickedEnd.c)) return null;
    return {
      r0: Math.min(sel.r, pickedEnd.r), c0: Math.min(sel.c, pickedEnd.c),
      r1: Math.max(sel.r, pickedEnd.r), c1: Math.max(sel.c, pickedEnd.c),
    };
  }, [sel, pickedEnd, grid]);
  const rangeCells = useMemo<Array<{ r: number; c: number }>>(() => {
    if (!rangeRect) return [];
    const seen = new Set<string>();
    const out: Array<{ r: number; c: number }> = [];
    for (const row of grid.rows) {
      for (const ref of row.cells) {
        if (ref.gridRow >= rangeRect.r0 && ref.gridRow <= rangeRect.r1 && ref.gridCol >= rangeRect.c0 && ref.gridCol <= rangeRect.c1) {
          const k = `${ref.gridRow}:${ref.gridCol}`;
          if (!seen.has(k)) { seen.add(k); out.push({ r: ref.gridRow, c: ref.gridCol }); }
        }
      }
    }
    return out;
  }, [rangeRect, grid]);
  const hasRange = rangeCells.length > 1;

  // 영역 병합 — Shift 로 고른 직사각형 전체 병합(직사각형만 — mergeCells 가드).
  const onMergeRange = useCallback(() => {
    if (!rangeRect) return;
    const res = mergeCells(node, tableParams, rangeRect.r0, rangeRect.c0, rangeRect.r1, rangeRect.c1);
    if (res.ok) { patch(res.table); setPickedEnd(null); }
  }, [rangeRect, node, tableParams, patch]);

  const onUnmerge = useCallback(() => {
    if (!sel) return;
    patch(unmergeCell(node, tableParams, sel.r, sel.c));
  }, [node, tableParams, sel, patch]);

  // 셀 테두리 시각 피커 — 카탈로그(템플릿 params.cellBorder, 라이브러리 중립) + 적용.
  const cellBorderCatalog = useMemo<CellBorderCatalog | null>(() => {
    const cb = (tableParams as { cellBorder?: unknown } | null)?.cellBorder;
    return cb && typeof cb === 'object' ? (cb as CellBorderCatalog) : null;
  }, [tableParams]);
  // 셀 배경색(cellBackground) / 내부 여백(cellPadding) 카탈로그 — 인라인 style SSoT.
  const cellFillCatalog = useMemo<CellFillCatalog | null>(() => {
    const cf = (tableParams as { cellBackground?: unknown } | null)?.cellBackground;
    return cf && typeof cf === 'object' ? (cf as CellFillCatalog) : null;
  }, [tableParams]);
  const cellPaddingCatalog = useMemo<CellPaddingCatalog | null>(() => {
    const cp = (tableParams as { cellPadding?: unknown } | null)?.cellPadding;
    return cp && typeof cp === 'object' ? (cp as CellPaddingCatalog) : null;
  }, [tableParams]);
  // 테두리 적용 대상 origin 셀 — ① "전체 적용" 토글이면 전 셀, ② Shift 영역 선택이면 그
  // 사각형 내 셀들(다중 선택), ③ 아니면 선택 셀 1개.
  const borderTargets = useMemo<Array<{ r: number; c: number }>>(() => {
    if (allCellsBorder) {
      const seen = new Set<string>();
      const out: Array<{ r: number; c: number }> = [];
      for (const row of grid.rows) {
        for (const ref of row.cells) {
          const k = `${ref.gridRow}:${ref.gridCol}`;
          if (!seen.has(k)) { seen.add(k); out.push({ r: ref.gridRow, c: ref.gridCol }); }
        }
      }
      return out;
    }
    if (hasRange) return rangeCells;
    return sel ? [{ r: sel.r, c: sel.c }] : [];
  }, [allCellsBorder, hasRange, rangeCells, sel, grid]);

  // 두께/변(width/side) className 적용 — 색은 paintBorderColor 가 인라인으로 별도 처리.
  const onCellBorder = useCallback(
    (nextClassName: string) => {
      if (borderTargets.length === 0) return;
      let next = node;
      for (const tgt of borderTargets) {
        // 색 토큰은 className 에서 제거(색은 인라인 SSoT). 두께/변 토큰만 남긴다.
        const colorTokens = (cellBorderCatalog?.colors ?? []).map((c) => c.token);
        const cleaned = nextClassName.split(/\s+/).filter((tk) => tk && !colorTokens.includes(tk)).join(' ');
        next = setCellClassName(next, tableParams, tgt.r, tgt.c, cleaned);
      }
      patch(next);
    },
    [node, tableParams, borderTargets, cellBorderCatalog, patch],
  );

  // 카탈로그에서 "기본 테두리"(전체 변 + 가장 얇은 두께) className 토큰 — 새로 추가한 셀
  // (className 없음=두께 0)에 색만 칠하면 border-width:0 이라 안 보이므로, 색 적용 시
  // 두께 토큰이 없는 셀에는 이 기본 두께를 함께 부여한다.
  const defaultBorderTokens = useMemo<string[]>(() => {
    const allSide = (cellBorderCatalog?.sides ?? []).find((s) => s.value === 'all') ?? (cellBorderCatalog?.sides ?? [])[0];
    const thin = (cellBorderCatalog?.widths ?? []).find((w) => w.value !== 'none');
    if (!allSide || !thin) return [];
    const suffix = thin.suffix ?? '';
    return [suffix ? `${allSide.prefix}${suffix}` : allSide.prefix];
  }, [cellBorderCatalog]);

  // 셀에 테두리 두께 토큰이 하나도 없으면 기본 두께 토큰을 부여(있으면 그대로). 색 렌더 보장.
  const ensureBorderWidth = useCallback(
    (table: EditorNode, r: number, c: number): EditorNode => {
      if (defaultBorderTokens.length === 0) return table;
      const sidePrefixes = (cellBorderCatalog?.sides ?? []).map((s) => s.prefix);
      const ref = cellRefAt(treeToGrid(table, tableParams), r, c);
      const cn = typeof ref?.cell?.props?.className === 'string' ? (ref!.cell.props!.className as string) : '';
      const toks = cn.split(/\s+/).filter(Boolean);
      const hasWidth = toks.some((tk) =>
        sidePrefixes.some((p) => tk === p || (tk.startsWith(p + '-') && /^-?\d+$/.test(tk.slice(p.length + 1)))),
      );
      if (hasWidth) return table;
      const merged = [...toks, ...defaultBorderTokens].join(' ').trim();
      return setCellClassName(table, tableParams, r, c, merged);
    },
    [defaultBorderTokens, cellBorderCatalog, tableParams],
  );

  // 셀 테두리 **색**(프리셋 스와치 hex 또는 자유 HEX) — 인라인 per-side 색 적용 +
  // border-collapse 공유 변 보정: 선택 셀의 위/왼쪽 변은 비선택 인접 셀과
  // 공유돼 CSS collapse 규칙상 인접 셀이 이긴다 → 인접 비선택 셀의 맞닿은 변에도 같은 색을
  // 발라 공유 변이 항상 선택 색으로 렌더되게 한다. cells 외부와 맞닿은 위/왼쪽만 보정.
  const paintBorderColor = useCallback(
    (cells: Array<{ r: number; c: number }>, color: string) => {
      if (cells.length === 0) return;
      const inSet = new Set(cells.map((t) => `${t.r}:${t.c}`));
      let next = node;
      for (const tgt of cells) {
        // 두께 토큰 없는 셀(새로 추가한 셀 등)엔 기본 두께를 먼저 부여(색이 0폭에 묻혀 안
        // 보이는 결함 방지). 두께가 이미 있으면 그대로 둔다.
        next = ensureBorderWidth(next, tgt.r, tgt.c);
        // 대상 셀 4변 색 + 프리셋/자유 일관(인라인 SSoT).
        next = setCellStyleProp(next, tableParams, tgt.r, tgt.c, 'borderColor', color);
        // 위쪽 공유 변 — 위 셀이 비선택이면 그 셀 아래변 색 보정.
        if (tgt.r > 0 && !inSet.has(`${tgt.r - 1}:${tgt.c}`)) {
          const up = cellRefAt(treeToGrid(next, tableParams), tgt.r - 1, tgt.c);
          if (up) next = setCellStyleProp(next, tableParams, up.gridRow, up.gridCol, 'borderBottomColor', color);
        }
        // 왼쪽 공유 변 — 왼 셀이 비선택이면 그 셀 오른변 색 보정.
        if (tgt.c > 0 && !inSet.has(`${tgt.r}:${tgt.c - 1}`)) {
          const lf = cellRefAt(treeToGrid(next, tableParams), tgt.r, tgt.c - 1);
          if (lf) next = setCellStyleProp(next, tableParams, lf.gridRow, lf.gridCol, 'borderRightColor', color);
        }
      }
      patch(next);
    },
    [node, tableParams, patch, ensureBorderWidth],
  );

  // 테두리 색 group 토큰(카탈로그 색 token 전체) — 스킴별 교체 대상 패밀리.
  const borderColorGroupTokens = useMemo<string[]>(
    () => (cellBorderCatalog?.colors ?? []).map((c) => c.token).filter(Boolean),
    [cellBorderCatalog],
  );
  // 프리셋 테두리 색 → 활성 스킴 className 토큰(라이트=base, 다크=`dark:`). 두께 없는 셀엔
  // 기본 두께 부여(색만으론 border-width:0 → 안 보임). 토큰 경로라 per-side 공유 변 보정 불필요.
  const onCellBorderPresetToken = useCallback(
    (colorToken: string) => {
      if (borderTargets.length === 0 || borderColorGroupTokens.length === 0) return;
      let next = node;
      for (const tgt of borderTargets) {
        next = ensureBorderWidth(next, tgt.r, tgt.c);
        next = setCellColorToken(next, tableParams, tgt.r, tgt.c, colorToken, borderColorGroupTokens, colorScheme);
      }
      patch(next);
    },
    [borderTargets, node, tableParams, patch, ensureBorderWidth, borderColorGroupTokens, colorScheme],
  );

  // 자유 색(컬러 피커, 라이트 전용) → paintBorderColor(인라인 + 공유 변 보정). 다크 탭에선
  // 컨트롤이 비활성이라 미호출.
  const onCellBorderColorStyle = useCallback(
    (hex: string) => paintBorderColor(borderTargets, hex),
    [paintBorderColor, borderTargets],
  );
  // 앵커 셀 인라인 borderColor(컬러 피커 현재값) — 라이트 탭에서만 노출(다크는 토큰 역해석).
  const anchorColorStyle = useMemo<string>(() => {
    if (colorScheme === 'dark') return '';
    const st = selectedRef?.cell.props?.style as Record<string, unknown> | undefined;
    return typeof st?.borderColor === 'string' ? (st.borderColor as string) : '';
  }, [selectedRef, colorScheme]);

  // 셀 배경색/여백 인라인 style prop 일괄 적용 — borderTargets(전체/영역/단일) 동일 타깃에
  // 적용한다(테두리 색과 패리티). 공유 변 보정 불필요(배경/여백은 변 공유 개념 없음).
  const paintCellStyleProp = useCallback(
    (prop: string, value: string) => {
      if (borderTargets.length === 0) return;
      let next = node;
      for (const tgt of borderTargets) {
        next = setCellStyleProp(next, tableParams, tgt.r, tgt.c, prop, value);
      }
      patch(next);
    },
    [borderTargets, node, tableParams, patch],
  );
  // 배경 색 group 토큰(카탈로그 token 보유 색만) — 스킴별 교체 대상 패밀리.
  const fillColorGroupTokens = useMemo<string[]>(
    () => (cellFillCatalog?.colors ?? []).map((c) => c.token).filter((tk): tk is string => !!tk),
    [cellFillCatalog],
  );
  // 프리셋 배경색 → 활성 스킴 className 토큰(라이트/다크 분리). borderTargets 동일 타깃.
  const onFillPresetToken = useCallback(
    (token: string) => {
      if (borderTargets.length === 0 || fillColorGroupTokens.length === 0) return;
      let next = node;
      for (const tgt of borderTargets) {
        next = setCellColorToken(next, tableParams, tgt.r, tgt.c, token, fillColorGroupTokens, colorScheme);
      }
      patch(next);
    },
    [borderTargets, node, tableParams, patch, fillColorGroupTokens, colorScheme],
  );
  // 자유 HEX 배경색(인라인, 라이트 전용) — 다크 탭에선 컨트롤이 비활성이라 미호출.
  const onCellFill = useCallback((hex: string) => paintCellStyleProp('backgroundColor', hex), [paintCellStyleProp]);
  // 배경 제거 — 활성 스킴 색 토큰 제거 + (라이트면) 인라인 backgroundColor 제거.
  const onFillClear = useCallback(() => {
    if (borderTargets.length === 0) return;
    let next = node;
    for (const tgt of borderTargets) {
      next = setCellColorToken(next, tableParams, tgt.r, tgt.c, undefined, fillColorGroupTokens, colorScheme);
      if (colorScheme === 'light') next = setCellStyleProp(next, tableParams, tgt.r, tgt.c, 'backgroundColor', undefined);
    }
    patch(next);
  }, [borderTargets, node, tableParams, patch, fillColorGroupTokens, colorScheme]);
  const onCellPadding = useCallback((padding: string) => paintCellStyleProp('padding', padding), [paintCellStyleProp]);
  // 앵커 셀 인라인 배경색(현재값) — 전체 적용 모드면 비움. 다크 탭이면 비움(토큰 역해석).
  const anchorFillStyle = useMemo<string>(() => {
    if (colorScheme === 'dark') return '';
    const st = selectedRef?.cell.props?.style as Record<string, unknown> | undefined;
    return typeof st?.backgroundColor === 'string' ? (st.backgroundColor as string) : '';
  }, [selectedRef, colorScheme]);
  // 앵커 셀 className(배경 토큰 역해석용).
  const anchorFillClassName = useMemo<string>(
    () => (typeof selectedRef?.cell.props?.className === 'string' ? (selectedRef.cell.props.className as string) : ''),
    [selectedRef],
  );
  const anchorPaddingStyle = useMemo<string>(() => {
    const st = selectedRef?.cell.props?.style as Record<string, unknown> | undefined;
    return typeof st?.padding === 'string' ? (st.padding as string) : '';
  }, [selectedRef]);

  // 셀 픽 영역의 pointerdown 을 하위 표 드래그 핸들로 forward — 셀 영역을 잠시 통과
  // (pointerEvents none)시켜 같은 좌표 아래 dnd 핸들에 동일 pointerdown 을 재발행한다.
  // dnd-kit PointerSensor 가 그 pointerdown+이동으로 표 드래그를 시작(클릭=셀 선택 유지).
  const forwardPointerToHandle = useCallback((e: React.PointerEvent): void => {
    const selfEl = e.currentTarget as HTMLElement;
    const prev = selfEl.style.pointerEvents;
    selfEl.style.pointerEvents = 'none';
    const below = document.elementFromPoint(e.clientX, e.clientY) as HTMLElement | null;
    selfEl.style.pointerEvents = prev;
    const handle = below?.closest?.('[data-dnd-handle-path]') as HTMLElement | null;
    if (handle) {
      handle.dispatchEvent(new PointerEvent('pointerdown', {
        bubbles: true, cancelable: true, clientX: e.clientX, clientY: e.clientY,
        pointerId: e.pointerId, pointerType: e.pointerType, button: e.button, isPrimary: true,
      }));
    }
  }, []);

  // 인라인 편집은 셀 DOM 으로 이벤트를 forward 하지 않는다 — elementFromPoint 가 셀 위를
  // 덮은 드래그 핸들 DIV 를 잡아 인라인 편집이 진입하지 못했다.
  // 대신 단일 클릭에서 `onRequestInlineEdit(cellRel)` 콜백으로 호스트가 절대 path 를
  // 분류·진입(requestInlineEditAt)하므로 DOM 경유 forward 가 불필요.

  // 열/행 앵커 박스(각 논리 열/행의 첫 origin 셀 박스 좌표).
  const colAnchors = useMemo(() => {
    const map = new Map<number, OverlayBox>();
    for (const m of measured) if (!map.has(m.ref.gridCol)) map.set(m.ref.gridCol, m.box);
    return map;
  }, [measured]);
  const rowAnchors = useMemo(() => {
    const map = new Map<number, OverlayBox>();
    for (const m of measured) if (!map.has(m.ref.gridRow)) map.set(m.ref.gridRow, m.box);
    return map;
  }, [measured]);

  // 측정 실패(캔버스 미렌더/표 비가시) — 오버레이 미표시(코어 선택 오버레이로 디그레이드).
  if (measured.length === 0) return null;

  const canUnmerge = !!(selectedRef && (selectedRef.colSpan > 1 || selectedRef.rowSpan > 1));

  // 거터 노출 행/열 — hover 중이거나(레일 자체 hover) 선택 셀의 행/열. 코어 선택으로 셀을
  // 고르면 그 행/열 거터가 자동 노출돼 추가/삭제/이동/병합이 바로 닿는다(불투명 셀 레이어 없이).
  const activeCol = hoverCol !== null ? hoverCol : sel?.c ?? null;
  const activeRow = hoverRow !== null ? hoverRow : sel?.r ?? null;

  // 전용 레일 위치 — 코어 4방향 +(InsertionAffordances, 박스 변 중앙 -30px, 24px 버튼)
  // 밴드 바깥으로 이격해 겹치지 않게 한다.
  // 코어 + 밴드 ≈ 박스 변에서 18~42px → 거터 레일을 그 너머(CORE_PLUS_BAND)로 민다.
  const colRailTop = nodeBox.top - COL_RAIL - RAIL_GAP - CORE_PLUS_BAND;
  const rowRailLeft = nodeBox.left - ROW_RAIL - RAIL_GAP - CORE_PLUS_BAND;

  return (
    <div
      className="g7le-table-inplace"
      data-testid="g7le-table-inplace"
      style={{ position: 'absolute', inset: 0, pointerEvents: 'none', zIndex: TABLE_INPLACE }}
    >
      {/* 셀 픽 영역(2단계 선택) — 표가 선택돼 본 오버레이가 떠 있는 상태에서
          셀을 클릭하면 그 셀을 선택(병합·테두리 도구 표시). **드래그는 보존**: onPointerDown 을
          하위 표 드래그 핸들로 forward 해 dnd-kit 이 드래그를 그대로 잡게 한다(클릭=셀 선택,
          드래그=표 이동). 더블클릭은 forwardPointerDown 후 통과돼 셀 인라인 편집이 동작.
          선택 셀은 파란 외곽선. */}
      {measured.map((m) => {
        const isSel = sel && sel.r === m.ref.gridRow && sel.c === m.ref.gridCol;
        const inRange = rangeRect &&
          m.ref.gridRow >= rangeRect.r0 && m.ref.gridRow <= rangeRect.r1 &&
          m.ref.gridCol >= rangeRect.c0 && m.ref.gridCol <= rangeRect.c1;
        return (
          <div
            key={`cell-${m.ref.gridRow}-${m.ref.gridCol}`}
            data-testid={`g7le-inplace-cell-${m.ref.gridRow}-${m.ref.gridCol}`}
            role="button"
            tabIndex={-1}
            aria-label={t('layout_editor.table_inplace.select_cell')}
            onMouseEnter={() => { cancelHoverClear(); setHoverRow(m.ref.gridRow); setHoverCol(m.ref.gridCol); }}
            onMouseLeave={() => { scheduleHoverClear(); }}
            onPointerDown={(e) => forwardPointerToHandle(e)}
            onClick={(e) => {
              e.stopPropagation();
              if (e.shiftKey && (pickedCell || sel)) {
                // Shift+클릭 → 영역 끝(다중 선택만, 인라인 편집 진입 안 함).
                setPickedEnd({ r: m.ref.gridRow, c: m.ref.gridCol });
              } else {
                // 단일 클릭 → 셀 선택 + 인라인 텍스트 편집 동시 진입.
                setPickedCell({ r: m.ref.gridRow, c: m.ref.gridCol });
                setPickedEnd(null);
                const rel = cellTreePath(node, m.ref, tableParams).map((n) => `children.${n}`).join('.');
                onRequestInlineEdit?.(rel);
              }
            }}
            style={{
              position: 'absolute',
              top: m.box.top, left: m.box.left, width: m.box.width, height: m.box.height,
              // 빈 셀이 1px 로 붕괴해도 클릭 타겟은 확보(표 DOM 은 안 키움 — 위지윅 동일성 유지).
              minWidth: MIN_CELL_PICK, minHeight: MIN_CELL_PICK,
              boxSizing: 'border-box', pointerEvents: 'auto', cursor: 'pointer',
              outline: isSel ? '2px solid #2563eb' : 'none', outlineOffset: -2,
              background: inRange && !isSel ? 'rgba(37,99,235,0.14)' : 'transparent',
            }}
          />
        );
      })}

      {/* ── 상단 열 레일 — hover 중인 열에만 핸들 노출(겹침 해소) ── */}
      <div
        data-testid="g7le-inplace-col-rail"
        onMouseEnter={cancelHoverClear}
        onMouseLeave={scheduleHoverClear}
        style={{ position: 'absolute', top: colRailTop, left: nodeBox.left, width: nodeBox.width, height: COL_RAIL, pointerEvents: 'none' }}
      >
        {activeCol !== null && colAnchors.has(activeCol) && (() => {
          const c = activeCol;
          const box = colAnchors.get(c)!;
          const left = canMoveColumn(grid, c, -1);
          const right = canMoveColumn(grid, c, 1);
          const relLeft = box.left - nodeBox.left;
          return (
            <div
              key={`colgut-${c}`}
              data-testid={`g7le-inplace-colgutter-${c}`}
              onMouseEnter={() => { cancelHoverClear(); setHoverCol(c); }}
              onMouseLeave={scheduleHoverClear}
              style={{ position: 'absolute', top: 0, left: relLeft, width: box.width, minWidth: 96, height: COL_RAIL, display: 'flex', gap: 3, justifyContent: 'center', alignItems: 'center', pointerEvents: 'auto' }}
            >
              <button type="button" title={t('layout_editor.table_inplace.move_col_left')} disabled={!left.ok} onClick={() => onMoveCol(c, -1)} data-testid={`g7le-inplace-col-left-${c}`} style={left.ok ? gutBtn : gutBtnBlocked}>←</button>
              <button type="button" title={t('layout_editor.table_inplace.add_col')} onClick={() => onAddCol(c)} data-testid={`g7le-inplace-col-add-${c}`} style={gutBtn}>+</button>
              <button type="button" title={t('layout_editor.table_inplace.remove_col')} disabled={grid.colCount <= 1} onClick={() => onRemoveCol(c)} data-testid={`g7le-inplace-col-remove-${c}`} style={gutBtnDanger}>✕</button>
              <button type="button" title={t('layout_editor.table_inplace.move_col_right')} disabled={!right.ok} onClick={() => onMoveCol(c, 1)} data-testid={`g7le-inplace-col-right-${c}`} style={right.ok ? gutBtn : gutBtnBlocked}>→</button>
            </div>
          );
        })()}
      </div>

      {/* ── 좌측 행 레일 — hover 중인 행에만 핸들 노출(겹침 해소) ── */}
      <div
        data-testid="g7le-inplace-row-rail"
        style={{ position: 'absolute', top: nodeBox.top, left: rowRailLeft, width: ROW_RAIL, height: nodeBox.height, pointerEvents: 'none' }}
      >
        {activeRow !== null && rowAnchors.has(activeRow) && (() => {
          const r = activeRow;
          const box = rowAnchors.get(r)!;
          const up = canMoveRow(grid, r, -1);
          const down = canMoveRow(grid, r, 1);
          const relTop = box.top - nodeBox.top;
          return (
            <div
              key={`rowgut-${r}`}
              data-testid={`g7le-inplace-rowgutter-${r}`}
              onMouseEnter={() => { cancelHoverClear(); setHoverRow(r); }}
              onMouseLeave={scheduleHoverClear}
              style={{ position: 'absolute', top: relTop, left: 0, width: ROW_RAIL, height: box.height, minHeight: 48, display: 'grid', gridTemplateColumns: 'repeat(2, 22px)', gridAutoRows: '22px', gap: 3, justifyContent: 'center', alignContent: 'center', pointerEvents: 'auto' }}
            >
              <button type="button" title={t('layout_editor.table_inplace.move_row_up')} disabled={!up.ok} onClick={() => onMoveRow(r, -1)} data-testid={`g7le-inplace-row-up-${r}`} style={up.ok ? gutBtn : gutBtnBlocked}>↑</button>
              <button type="button" title={t('layout_editor.table_inplace.add_row')} onClick={() => onAddRow(r)} data-testid={`g7le-inplace-row-add-${r}`} style={gutBtn}>+</button>
              <button type="button" title={t('layout_editor.table_inplace.remove_row')} disabled={grid.rows.length <= 1} onClick={() => onRemoveRow(r)} data-testid={`g7le-inplace-row-remove-${r}`} style={gutBtnDanger}>✕</button>
              <button type="button" title={t('layout_editor.table_inplace.move_row_down')} disabled={!down.ok} onClick={() => onMoveRow(r, 1)} data-testid={`g7le-inplace-row-down-${r}`} style={down.ok ? gutBtn : gutBtnBlocked}>↓</button>
            </div>
          );
        })()}
      </div>

      {/* 표 하단 도구 패널 — 행/열 추가 + 병합/해제 + (셀 선택 시) 테두리 시각 피커.
          코어 ⓘ/칩과 분리(하단). pointerEvents auto. */}
      <div
        data-testid="g7le-inplace-corner-add"
        style={{ position: 'absolute', top: nodeBox.top + nodeBox.height + RAIL_GAP, left: nodeBox.left, display: 'flex', flexDirection: 'column', gap: 6, pointerEvents: 'auto', minWidth: 200, maxWidth: Math.max(240, nodeBox.width), background: '#fff', border: '1px solid #e2e8f0', borderRadius: 8, padding: 8, boxShadow: '0 2px 8px rgba(0,0,0,0.12)' }}
      >
        <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap' }}>
          <button type="button" data-testid="g7le-inplace-add-row-bottom" onClick={() => onAddRow(grid.rows.length - 1)} style={cornerBtn}>
            + {t('layout_editor.table_inplace.add_row')}
          </button>
          <button type="button" data-testid="g7le-inplace-add-col-right" onClick={() => onAddCol(grid.colCount - 1)} style={cornerBtn}>
            + {t('layout_editor.table_inplace.add_col')}
          </button>
        </div>
        {/* 셀 선택 안내 또는 선택 셀 도구(병합 오른쪽/아래·해제 + 테두리 피커). 코어 표준
            선택(캔버스에서 셀 클릭)으로 셀을 고르면 표시된다(불투명 레이어 없음 — 드래그/
            인라인 편집 보존). 선택 전엔 안내 문구. */}
        {!sel && (
          <div data-testid="g7le-inplace-select-hint" style={{ fontSize: 11, color: '#94a3b8', borderTop: '1px solid #f1f5f9', paddingTop: 6 }}>
            {t('layout_editor.table_inplace.select_cell_hint')}
          </div>
        )}
        {sel && (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 6, borderTop: '1px solid #f1f5f9', paddingTop: 6 }}>
            {hasRange && (
              <div data-testid="g7le-inplace-range-count" style={{ fontSize: 11, color: '#2563eb' }}>
                {t('layout_editor.table_inplace.range_selected', { count: rangeCells.length })}
              </div>
            )}
            <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', alignItems: 'center' }}>
              {/* Shift 영역 선택 시 영역 병합 버튼, 아니면 인접(오른쪽/아래) 병합 */}
              {hasRange ? (
                <button type="button" data-testid="g7le-inplace-merge-range" onClick={onMergeRange} style={toolBtn}>
                  {t('layout_editor.table_inplace.merge_range')}
                </button>
              ) : (
                <>
                  <button type="button" data-testid="g7le-inplace-merge-right" disabled={!canMergeRight} onClick={() => mergeDir('right')} style={canMergeRight ? toolBtn : toolBtnDisabled}>
                    {t('layout_editor.table_inplace.merge_right')}
                  </button>
                  <button type="button" data-testid="g7le-inplace-merge-down" disabled={!canMergeDown} onClick={() => mergeDir('down')} style={canMergeDown ? toolBtn : toolBtnDisabled}>
                    {t('layout_editor.table_inplace.merge_down')}
                  </button>
                </>
              )}
              <button type="button" data-testid="g7le-inplace-unmerge" disabled={!canUnmerge} onClick={onUnmerge} style={canUnmerge ? toolBtn : toolBtnDisabled}>
                {t('layout_editor.table_inplace.unmerge')}
              </button>
            </div>
            {/* 셀 색상(테두리 + 배경) — 라이트/다크 단일 공용 탭이 색상 전체에 적용(,
                속성 패널과 동일 UI). "표 전체 적용" 토글은 타깃 선택(스킴과 별개). */}
            <div data-testid="g7le-inplace-cell-color" style={{ borderTop: '1px solid #f1f5f9', paddingTop: 6, display: 'flex', flexDirection: 'column', gap: 6 }}>
              <label style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 11, color: '#475569', cursor: 'pointer' }}>
                <input type="checkbox" data-testid="g7le-inplace-border-all-toggle" checked={allCellsBorder} onChange={(e) => setAllCellsBorder(e.target.checked)} />
                {t('layout_editor.table_inplace.border_apply_all')}
              </label>
              <ColorSchemeTabs colorScheme={colorScheme} onChange={setColorScheme} t={t} />
              {cellBorderCatalog && (
                <div data-testid="g7le-inplace-cell-border">
                  <CellBorderControl
                    className={allCellsBorder ? '' : anchorCellClassName}
                    catalog={cellBorderCatalog}
                    t={t}
                    onChange={onCellBorder}
                    colorScheme={colorScheme}
                    onPresetToken={onCellBorderPresetToken}
                    colorStyle={allCellsBorder ? '' : anchorColorStyle}
                    onColorStyle={onCellBorderColorStyle}
                  />
                </div>
              )}
              {/* 셀 배경색 — 프리셋=className 토큰(스킴별), 자유 HEX=인라인(라이트). 전체/영역/단일 타깃. */}
              <div data-testid="g7le-inplace-cell-fill">
                <CellFillControl
                  colorStyle={allCellsBorder ? '' : anchorFillStyle}
                  className={allCellsBorder ? '' : anchorFillClassName}
                  catalog={cellFillCatalog}
                  t={t}
                  colorScheme={colorScheme}
                  onPresetToken={onFillPresetToken}
                  onCustomColor={onCellFill}
                  onClear={onFillClear}
                />
              </div>
            </div>
            {/* 셀 내부 여백 — 인라인 style.padding(프리셋 단계 + 자유 px). 전체/영역/단일 타깃. */}
            <div data-testid="g7le-inplace-cell-padding" style={{ borderTop: '1px solid #f1f5f9', paddingTop: 6 }}>
              <CellPaddingControl
                paddingStyle={allCellsBorder ? '' : anchorPaddingStyle}
                catalog={cellPaddingCatalog}
                t={t}
                onChange={onCellPadding}
              />
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

// 거터 버튼 — 편안한 클릭 타겟 22×22.
const gutBtn: React.CSSProperties = { width: 22, height: 22, lineHeight: '20px', padding: 0, fontSize: 13, border: '1px solid #cbd5e1', borderRadius: 5, background: '#fff', color: '#475569', cursor: 'pointer', boxShadow: '0 1px 3px rgba(0,0,0,0.18)' };
const gutBtnDanger: React.CSSProperties = { ...gutBtn, border: '1px solid #fecaca', color: '#dc2626' };
const gutBtnBlocked: React.CSSProperties = { ...gutBtn, cursor: 'not-allowed', opacity: 0.4, color: '#94a3b8', borderColor: '#e2e8f0' };
const toolBtn: React.CSSProperties = { padding: '4px 10px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer', boxShadow: '0 1px 2px rgba(0,0,0,0.12)' };
const toolBtnDisabled: React.CSSProperties = { ...toolBtn, cursor: 'not-allowed', opacity: 0.4 };
const cornerBtn: React.CSSProperties = { padding: '3px 8px', fontSize: 11, border: '1px dashed #94a3b8', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer' };
