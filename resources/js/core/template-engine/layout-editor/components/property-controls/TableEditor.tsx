// e2e:allow 레이아웃 편집기 캔버스 오버레이/속성패널 UI — dnd-kit/합성 이벤트 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스(T1~T8) 실측 + 단위/레이아웃 렌더링 테스트로 검증
/**
 * TableEditor.tsx — `table` 노드 에디터
 *
 * 표 노드(`section > row > cell`)를 **시중 편집기 수준**으로 구조 편집한다 — 행/열 추가·
 * 삭제·이동, 셀 병합/해제, 셀 테두리(className), 셀 텍스트 다국어. 데이터 모델은
 * `tableGridModel`(트리↔논리 grid 어댑터) + `tableGridMutations`(pure 변형) 이며, 역할
 * 매핑은 capability `nodeEditor.params`(rowContainer/row/cell/headerCell/colSpanProp/
 * rowSpanProp)로만 식별한다(컴포넌트명 가정 0 — 부록4-ter 중립성).
 *
 * 코어 빌트인은 `registerCoreEditors` 가 `registerNodeEditor('table', TableEditor)` 로
 * 일반 레지스트리에 올린다(특권 분기 0 — 템플릿이 같은 kind 재등록 시 대체 가능).
 *
 * 모든 조작은 `onPatchNode({...})` 로 노드 전체를 교체 → PATCH_LAYOUT 으로 캔버스 즉시
 * 반영 + history. 캔버스 인플레이스(셀 단위 오버레이)는 단계 3-b 가 동일 패치 경로를 공유.
 *
 * 편집기 코어 컴포넌트 — `g7le-*` BEM + 인라인 스타일만, CSS 라이브러리 토큰 비종속.
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useMemo, useState } from 'react';
import type { NodeEditorProps } from '../../spec/nodeEditorRegistry';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import { useLayoutEditor } from '../../LayoutEditorContext';
import {
  treeToGrid,
  cellRefAt,
  resolveParams,
  canMoveRow,
  canMoveColumn,
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
  setCellText,
  setCellNode,
} from '../../spec/tableGridMutations';
import {
  createCustomKey,
  updateCustomKeyValue,
  bustTranslationCache,
} from '../../hooks/useInlineEdit';
import { findTextNodePath, nodeAtTextPath, patchTextAtPath } from '../../utils/nodeTextPath';
import { extractParamBindings } from '../../spec/inlineBindingUtils';
import { TranslationEngine } from '../../../TranslationEngine';
import { CellBorderControl, type CellBorderCatalog } from './CellBorderControl';
import { CellFillControl, type CellFillCatalog } from './CellFillControl';
import { CellPaddingControl, type CellPaddingCatalog } from './CellPaddingControl';
import { ColorSchemeTabs } from './ColorSchemeTabs';
import { I18nTextField } from './I18nTextField';
import { cellBorderPreviewStyle, cellFillPaddingPreviewStyle } from '../../spec/cellBorderPreview';

const CUSTOM_KEY_RE = /^\s*\$t:(custom\.[a-zA-Z0-9._-]+)\s*$/;
const ANY_T_KEY_RE = /^\s*\$t:([a-zA-Z0-9._-]+)\s*$/;
const BINDING_RE = /\{\{.*?\}\}/;

/** 단일 텍스트 노드의 시작값 — 키는 현재 로케일 해석, 평문은 그대로, 바인딩은 비대상. */
function resolveTextNode(
  textNode: EditorNode | null,
  tid: string,
  locale: string,
): { display: string; editable: boolean; isKey: boolean } {
  const text = textNode?.text;
  if (typeof text !== 'string') {
    return { display: '', editable: false, isKey: false };
  }
  // 데이터 칩이 든 custom param 키(`$t:custom.X|pN={{}}`) — BINDING_RE(키 안의 `{{}}`)보다 **먼저**
  // 분기한다. 종전엔 `{{}}` 포함만 보고 BINDING_RE 에 걸려 raw `$t:custom.*|...`
  // 를 그리드 셀 미니맵에 그대로 노출하고 편집 input 도 안 떴다(셀 텍스트만의 결함 — OptionsList/
  // ChildrenList 는 I18nTextField 가 classifyCustomText 로 이미 처리). param 키는 **편집 가능한 다국어
  // 문구**이므로 키의 현재 로케일 값을 해석해 표시하고 editable 로 둔다(키 식별자는 paramized.key).
  const paramized = extractParamBindings(text);
  if (paramized && paramized.key.startsWith('custom.')) {
    let resolved = '';
    try {
      const r = TranslationEngine.getInstance().translate(paramized.key, { templateId: tid, locale });
      resolved = r && r !== paramized.key ? r : '';
    } catch {
      resolved = '';
    }
    return { display: resolved, editable: true, isKey: true };
  }
  if (BINDING_RE.test(text)) return { display: text, editable: false, isKey: false };
  const m = ANY_T_KEY_RE.exec(text);
  if (m) {
    const key = m[1]!;
    let resolved = '';
    try {
      const r = TranslationEngine.getInstance().translate(key, { templateId: tid, locale });
      resolved = r && r !== key ? r : '';
    } catch {
      resolved = '';
    }
    return { display: resolved, editable: true, isKey: true };
  }
  return { display: text, editable: true, isKey: false };
}

/**
 * 셀의 **편집 대상 텍스트 노드**(직접 text 또는 의미 텍스트 자손)와 그 상대 경로를 찾는다.
 * 셀이 임의 HTML/컴포넌트(Span/A/Img/Icon/Button 등)를 품어도 그 안의 텍스트만 편집한다.
 * 텍스트 자손이 전혀 없는 순수 구조 셀(아이콘/이미지만)은 `path:null`(텍스트 편집 비대상).
 */
function cellTextTarget(cell: EditorNode): { node: EditorNode | null; path: number[] | null } {
  const path = findTextNodePath(cell);
  if (path === null) return { node: null, path: null };
  return { node: nodeAtTextPath(cell, path), path };
}

/** 텍스트 노드 text 의 커스텀 키 추출(없으면 null). bare(`$t:custom.X`) + 데이터 칩이 든 param
 *  키(`$t:custom.X|pN={{}}`) 둘 다 인식한다. param 키도 그 키의 현재 로케일 값만
 *  갱신해야 하므로(새 키 생성 회피), 키 식별자를 함께 추출한다. */
function extractCustomKey(textNode: EditorNode | null): string | null {
  if (!textNode || typeof textNode.text !== 'string') return null;
  const m = CUSTOM_KEY_RE.exec(textNode.text);
  if (m) return m[1];
  const paramized = extractParamBindings(textNode.text);
  return paramized && paramized.key.startsWith('custom.') ? paramized.key : null;
}

export function TableEditor({
  node,
  params,
  t,
  onPatchNode,
  templateIdentifier,
  candidates,
}: NodeEditorProps): React.ReactElement {
  const { state } = useLayoutEditor();
  const layoutName = state.selectedRoute?.layoutName ?? null;
  const locale = state.locale;
  const tid = templateIdentifier ?? state.templateIdentifier;

  const tableParams = useMemo(
    () => (params && typeof params === 'object' ? (params as Record<string, unknown>) : null),
    [params],
  );
  const p = useMemo(() => resolveParams(tableParams as never), [tableParams]);
  const grid = useMemo(() => treeToGrid(node, tableParams as never), [node, tableParams]);
  // 셀 테두리 시각 피커 카탈로그(템플릿 editor-spec params.cellBorder — 라이브러리 중립).
  const cellBorderCatalog = useMemo<CellBorderCatalog | null>(() => {
    const cb = (tableParams as { cellBorder?: unknown } | null)?.cellBorder;
    return cb && typeof cb === 'object' ? (cb as CellBorderCatalog) : null;
  }, [tableParams]);
  // 셀 배경색(cellBackground) / 내부 여백(cellPadding) 카탈로그 — 인라인 style SSoT(라이브러리 중립).
  const cellFillCatalog = useMemo<CellFillCatalog | null>(() => {
    const cf = (tableParams as { cellBackground?: unknown } | null)?.cellBackground;
    return cf && typeof cf === 'object' ? (cf as CellFillCatalog) : null;
  }, [tableParams]);
  const cellPaddingCatalog = useMemo<CellPaddingCatalog | null>(() => {
    const cp = (tableParams as { cellPadding?: unknown } | null)?.cellPadding;
    return cp && typeof cp === 'object' ? (cp as CellPaddingCatalog) : null;
  }, [tableParams]);

  // 선택 셀(논리 grid 좌표). 병합 대상 선택은 sel + selEnd 직사각형.
  const [sel, setSel] = useState<{ r: number; c: number } | null>(null);
  const [selEnd, setSelEnd] = useState<{ r: number; c: number } | null>(null);
  const [drafts, setDrafts] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  // hover 중인 행/열 — 거터 버튼을 hover/선택 행·열에만 노출(: 마우스 오버한
  // 것만 버튼, 인플레이스와 동일). 미선택·미hover 시 거터 숨김(빽빽한 버튼 줄 해소).
  const [hoverRow, setHoverRow] = useState<number | null>(null);
  const [hoverCol, setHoverCol] = useState<number | null>(null);
  // 셀 색(테두리/배경) 라이트/다크 탭 — 컨트롤 로컬 상태(node editor 는 scope 미보유 계약,
  // 모달 BASE_SCOPE 비파괴). 기본 light(PC).
  const [colorScheme, setColorScheme] = useState<'light' | 'dark'>('light');

  const patch = useCallback((next: EditorNode) => onPatchNode(next), [onPatchNode]);

  const selectedRef: GridCellRef | null = useMemo(
    () => (sel ? cellRefAt(grid, sel.r, sel.c) : null),
    [grid, sel],
  );
  const activeColGut = hoverCol !== null ? hoverCol : sel?.c ?? null;
  const activeRowGut = hoverRow !== null ? hoverRow : sel?.r ?? null;

  // 카탈로그 "기본 테두리"(전체 변 + 가장 얇은 두께) 토큰 — 두께 토큰 없는 셀(새로 추가한
  // 셀 등)에 색만 칠하면 border-width:0 이라 안 보이므로 색 적용 시 함께 부여.
  const defaultBorderTokens = useMemo<string[]>(() => {
    const allSide = (cellBorderCatalog?.sides ?? []).find((s) => s.value === 'all') ?? (cellBorderCatalog?.sides ?? [])[0];
    const thin = (cellBorderCatalog?.widths ?? []).find((w) => w.value !== 'none');
    if (!allSide || !thin) return [];
    const suffix = thin.suffix ?? '';
    return [suffix ? `${allSide.prefix}${suffix}` : allSide.prefix];
  }, [cellBorderCatalog]);
  const ensureBorderWidth = useCallback(
    (table: EditorNode, r: number, c: number): EditorNode => {
      if (defaultBorderTokens.length === 0) return table;
      const sidePrefixes = (cellBorderCatalog?.sides ?? []).map((s) => s.prefix);
      const ref = cellRefAt(treeToGrid(table, tableParams as never), r, c);
      const cn = typeof ref?.cell?.props?.className === 'string' ? (ref!.cell.props!.className as string) : '';
      const toks = cn.split(/\s+/).filter(Boolean);
      const hasWidth = toks.some((tk) =>
        sidePrefixes.some((p) => tk === p || (tk.startsWith(p + '-') && /^-?\d+$/.test(tk.slice(p.length + 1)))),
      );
      if (hasWidth) return table;
      return setCellClassName(table, tableParams as never, r, c, [...toks, ...defaultBorderTokens].join(' ').trim());
    },
    [defaultBorderTokens, cellBorderCatalog, tableParams],
  );

  // 셀 테두리 색(프리셋 swatch hex 또는 자유 HEX) — 인라인 per-side 색 + border-collapse
  // 공유 변 보정. **영역(Shift) 다중 선택 시 영역 전 셀에 적용**한다( —
  // 종전엔 sel 1개만 칠해 다중 선택해도 하나만 색이 바뀌던 결함, 인플레이스 paintBorderColor
  // 와 패리티). 공유 변 보정은 영역 **바깥** 비선택 인접 셀의 맞닿은 변에만 적용한다.
  const paintCellBorderColor = useCallback(
    (hex: string) => {
      if (!sel) return;
      // 선택 영역 직사각형(단일 선택이면 1칸). selEnd 없으면 sel 1칸.
      const r0 = Math.min(sel.r, selEnd?.r ?? sel.r);
      const r1 = Math.max(sel.r, selEnd?.r ?? sel.r);
      const c0 = Math.min(sel.c, selEnd?.c ?? sel.c);
      const c1 = Math.max(sel.c, selEnd?.c ?? sel.c);
      const inSet = new Set<string>();
      const cells: Array<{ r: number; c: number }> = [];
      for (let r = r0; r <= r1; r++) for (let c = c0; c <= c1; c++) { inSet.add(`${r}:${c}`); cells.push({ r, c }); }
      let next = node;
      for (const t of cells) {
        next = ensureBorderWidth(next, t.r, t.c);
        next = setCellStyleProp(next, tableParams as never, t.r, t.c, 'borderColor', hex);
        // 위쪽 공유 변 — 위 셀이 영역 밖이면 그 셀 아래변 보정.
        if (t.r > 0 && !inSet.has(`${t.r - 1}:${t.c}`)) {
          const up = cellRefAt(treeToGrid(next, tableParams as never), t.r - 1, t.c);
          if (up) next = setCellStyleProp(next, tableParams as never, up.gridRow, up.gridCol, 'borderBottomColor', hex);
        }
        // 왼쪽 공유 변 — 왼 셀이 영역 밖이면 그 셀 오른변 보정.
        if (t.c > 0 && !inSet.has(`${t.r}:${t.c - 1}`)) {
          const lf = cellRefAt(treeToGrid(next, tableParams as never), t.r, t.c - 1);
          if (lf) next = setCellStyleProp(next, tableParams as never, lf.gridRow, lf.gridCol, 'borderRightColor', hex);
        }
      }
      patch(next);
    },
    [sel, selEnd, node, tableParams, patch, ensureBorderWidth],
  );
  // 자유 HEX 테두리 색(인라인, 라이트 전용) — 다크 탭에선 컨트롤이 비활성이라 미호출.
  const onCellColorStyle = useCallback((hex: string) => paintCellBorderColor(hex), [paintCellBorderColor]);
  // 테두리 색 group 토큰(카탈로그 색 token 전체) — 스킴별 교체 대상 패밀리.
  const borderColorGroupTokens = useMemo<string[]>(
    () => (cellBorderCatalog?.colors ?? []).map((c) => c.token).filter(Boolean),
    [cellBorderCatalog],
  );
  // 프리셋 테두리 색 — 카탈로그 token 을 활성 스킴 className 토큰으로 적용(라이트=base,
  // 다크=`dark:` prefix). 영역(Shift) 다중 선택 시 영역 전 셀(인플레이스와 패리티). 두께
  // 토큰 없는 셀엔 기본 두께 부여(색 토큰만으론 border-width:0 → 안 보임). 토큰 경로라
  // per-side 공유 변 인라인 보정은 불필요(드롭 — 사용자 페이지 Tailwind border 색과 동일 렌더).
  const onCellBorderPresetToken = useCallback(
    (colorToken: string) => {
      if (!sel || borderColorGroupTokens.length === 0) return;
      const r0 = Math.min(sel.r, selEnd?.r ?? sel.r);
      const r1 = Math.max(sel.r, selEnd?.r ?? sel.r);
      const c0 = Math.min(sel.c, selEnd?.c ?? sel.c);
      const c1 = Math.max(sel.c, selEnd?.c ?? sel.c);
      let next = node;
      for (let r = r0; r <= r1; r++) for (let c = c0; c <= c1; c++) {
        next = ensureBorderWidth(next, r, c);
        next = setCellColorToken(next, tableParams as never, r, c, colorToken, borderColorGroupTokens, colorScheme);
      }
      patch(next);
    },
    [sel, selEnd, node, tableParams, patch, ensureBorderWidth, borderColorGroupTokens, colorScheme],
  );
  // 미리보기/역해석 기준 — 라이트는 인라인 borderColor(자유 HEX), 다크는 className 토큰(컨트롤
  // 이 className 으로 역해석). 인라인 색은 라이트 탭에서만 노출.
  const selColorStyle = useMemo<string>(() => {
    if (colorScheme === 'dark') return '';
    const st = selectedRef?.cell.props?.style as Record<string, unknown> | undefined;
    return typeof st?.borderColor === 'string' ? (st.borderColor as string) : '';
  }, [selectedRef, colorScheme]);

  // 셀 인라인 style prop(배경색/여백 등) 영역 일괄 적용 — paintCellBorderColor 와 동일하게
  // 영역(Shift) 다중 선택 시 영역 전 셀에 적용한다(단일 선택이면 1칸). 공유 변 보정 불필요
  // (배경/여백은 변 공유 개념이 없음).
  const paintCellStyleProp = useCallback(
    (prop: string, value: string) => {
      if (!sel) return;
      const r0 = Math.min(sel.r, selEnd?.r ?? sel.r);
      const r1 = Math.max(sel.r, selEnd?.r ?? sel.r);
      const c0 = Math.min(sel.c, selEnd?.c ?? sel.c);
      const c1 = Math.max(sel.c, selEnd?.c ?? sel.c);
      let next = node;
      for (let r = r0; r <= r1; r++) for (let c = c0; c <= c1; c++) {
        next = setCellStyleProp(next, tableParams as never, r, c, prop, value);
      }
      patch(next);
    },
    [sel, selEnd, node, tableParams, patch],
  );
  // 배경 색 group 토큰(카탈로그 token 보유 색만) — 스킴별 교체 대상 패밀리.
  const fillColorGroupTokens = useMemo<string[]>(
    () => (cellFillCatalog?.colors ?? []).map((c) => c.token).filter((tk): tk is string => !!tk),
    [cellFillCatalog],
  );
  // 영역(전체 sel/Shift) 직사각형 좌표 helper.
  const fillRect = useCallback((): { r0: number; r1: number; c0: number; c1: number } | null => {
    if (!sel) return null;
    return {
      r0: Math.min(sel.r, selEnd?.r ?? sel.r), r1: Math.max(sel.r, selEnd?.r ?? sel.r),
      c0: Math.min(sel.c, selEnd?.c ?? sel.c), c1: Math.max(sel.c, selEnd?.c ?? sel.c),
    };
  }, [sel, selEnd]);
  // 프리셋 배경색 — 카탈로그 token 을 활성 스킴 className 토큰으로 적용(라이트/다크 분리).
  const onFillPresetToken = useCallback(
    (token: string) => {
      const rect = fillRect();
      if (!rect || fillColorGroupTokens.length === 0) return;
      let next = node;
      for (let r = rect.r0; r <= rect.r1; r++) for (let c = rect.c0; c <= rect.c1; c++) {
        next = setCellColorToken(next, tableParams as never, r, c, token, fillColorGroupTokens, colorScheme);
      }
      patch(next);
    },
    [fillRect, node, tableParams, patch, fillColorGroupTokens, colorScheme],
  );
  // 자유 HEX 배경색(인라인, 라이트 전용) — 다크 탭에선 컨트롤이 비활성이라 미호출.
  const onCellFill = useCallback((hex: string) => paintCellStyleProp('backgroundColor', hex), [paintCellStyleProp]);
  // 배경 제거 — 활성 스킴 색 토큰 제거 + (라이트면) 인라인 backgroundColor 제거.
  const onFillClear = useCallback(() => {
    const rect = fillRect();
    if (!rect) return;
    let next = node;
    for (let r = rect.r0; r <= rect.r1; r++) for (let c = rect.c0; c <= rect.c1; c++) {
      next = setCellColorToken(next, tableParams as never, r, c, undefined, fillColorGroupTokens, colorScheme);
      if (colorScheme === 'light') next = setCellStyleProp(next, tableParams as never, r, c, 'backgroundColor', undefined);
    }
    patch(next);
  }, [fillRect, node, tableParams, patch, fillColorGroupTokens, colorScheme]);
  const onCellPadding = useCallback((padding: string) => paintCellStyleProp('padding', padding), [paintCellStyleProp]);
  // 라이트 탭에서만 인라인 배경색 노출(자유 HEX). 다크는 className 토큰으로 컨트롤이 역해석.
  const selFillStyle = useMemo<string>(() => {
    if (colorScheme === 'dark') return '';
    const st = selectedRef?.cell.props?.style as Record<string, unknown> | undefined;
    return typeof st?.backgroundColor === 'string' ? (st.backgroundColor as string) : '';
  }, [selectedRef, colorScheme]);
  // 선택 셀 className(배경 토큰 역해석용).
  const selFillClassName = useMemo<string>(
    () => (typeof selectedRef?.cell.props?.className === 'string' ? (selectedRef.cell.props.className as string) : ''),
    [selectedRef],
  );
  const selPaddingStyle = useMemo<string>(() => {
    const st = selectedRef?.cell.props?.style as Record<string, unknown> | undefined;
    return typeof st?.padding === 'string' ? (st.padding as string) : '';
  }, [selectedRef]);

  // 행/열 이동 가능 여부(섹션 경계/표 끝 시 거부) — 버튼 비활성화 + 사유 풍선도움말.
  // 조용한 no-op 대신 사용자에게 "왜 이동 불가인지"를 명시.
  // 병합 블록은 밴드 단위로 통째 이동하므로 더 이상 '병합' 사유로 막지 않는다(밴드 모델).
  const moveReasonLabel = useCallback(
    (reason?: 'boundary' | 'section'): string => {
      if (reason === 'section') return t('layout_editor.table_editor.move_blocked_section');
      return ''; // boundary 는 기본 이동 라벨 유지(표 끝 — 자명)
    },
    [t],
  );
  const rowMove = useCallback(
    (gridRowNo: number, dir: -1 | 1) => canMoveRow(grid, gridRowNo, dir),
    [grid],
  );
  const colMove = useCallback(
    (gridColNo: number, dir: -1 | 1) => canMoveColumn(grid, gridColNo, dir),
    [grid],
  );

  // ── 행/열 구조 연산 ──────────────────────────────────────────────────────
  const onAddRow = useCallback(
    (after: number) => patch(addRow(node, tableParams as never, after)),
    [node, tableParams, patch],
  );
  const onRemoveRow = useCallback(
    (r: number) => {
      patch(removeRow(node, tableParams as never, r));
      setSel(null);
      setSelEnd(null);
    },
    [node, tableParams, patch],
  );
  const onMoveRow = useCallback(
    (r: number, dir: -1 | 1) => patch(moveRow(node, tableParams as never, r, dir)),
    [node, tableParams, patch],
  );
  const onAddCol = useCallback(
    (after: number) => patch(addColumn(node, tableParams as never, after)),
    [node, tableParams, patch],
  );
  const onRemoveCol = useCallback(
    (c: number) => {
      patch(removeColumn(node, tableParams as never, c));
      setSel(null);
      setSelEnd(null);
    },
    [node, tableParams, patch],
  );
  const onMoveCol = useCallback(
    (c: number, dir: -1 | 1) => patch(moveColumn(node, tableParams as never, c, dir)),
    [node, tableParams, patch],
  );

  // ── 병합/해제 ────────────────────────────────────────────────────────────
  const onMerge = useCallback(() => {
    if (!sel || !selEnd) return;
    const res = mergeCells(node, tableParams as never, sel.r, sel.c, selEnd.r, selEnd.c);
    if (res.ok) {
      patch(res.table);
      setSel({ r: Math.min(sel.r, selEnd.r), c: Math.min(sel.c, selEnd.c) });
      setSelEnd(null);
    }
  }, [node, tableParams, sel, selEnd, patch]);

  const onUnmerge = useCallback(() => {
    if (!sel) return;
    patch(unmergeCell(node, tableParams as never, sel.r, sel.c));
    setSelEnd(null);
  }, [node, tableParams, sel, patch]);

  // ── 셀 테두리(className) ─────────────────────────────────────────────────
  const onCellClassName = useCallback(
    (value: string) => {
      if (!sel) return;
      // 두께/변(width/side) 만 className 으로. 색은 인라인 SSoT 라 건드리지 않는다(두께 변경 시
      // 색 유지). value 에는 색 토큰이 없다(CellBorderControl 가 색을 className 에서 제외).
      // **영역(Shift) 다중 선택 시 영역 전 셀에 적용**(인플레이스 onCellBorder 와 패리티 —
      // 종전엔 sel 1개만 바뀌던 결함).
      const r0 = Math.min(sel.r, selEnd?.r ?? sel.r);
      const r1 = Math.max(sel.r, selEnd?.r ?? sel.r);
      const c0 = Math.min(sel.c, selEnd?.c ?? sel.c);
      const c1 = Math.max(sel.c, selEnd?.c ?? sel.c);
      let next = node;
      for (let r = r0; r <= r1; r++) for (let c = c0; c <= c1; c++) {
        next = setCellClassName(next, tableParams as never, r, c, value);
      }
      patch(next);
    },
    [node, tableParams, sel, selEnd, patch],
  );

  // ── 셀 텍스트 다국어 (복합 셀: 텍스트 자손까지 탐색해 그 노드만 키화) ─────────────
  const commitCellText = useCallback(
    async (r: number, c: number): Promise<void> => {
      const dkey = `${r}:${c}`;
      const draft = drafts[dkey];
      if (draft === undefined) return;
      const ref = cellRefAt(grid, r, c);
      if (!ref) return;
      const { node: textNode, path: textPath } = cellTextTarget(ref.cell);
      if (textPath === null) return; // 순수 구조 셀 — 텍스트 편집 비대상
      const info = resolveTextNode(textNode, tid, locale);
      if (!info.editable) return;
      if (draft === info.display) {
        setDrafts((d) => {
          const n = { ...d };
          delete n[dkey];
          return n;
        });
        return;
      }
      setBusy(true);
      try {
        const existing = extractCustomKey(textNode);
        if (existing) {
          // 기존 커스텀 키 — 현재 로케일 값만 PUT(노드 키 토큰 유지).
          await updateCustomKeyValue(tid, existing, locale, draft);
          await bustTranslationCache(tid, locale);
        } else {
          // 평문/언어팩 키 → 새 커스텀 키 생성 후 **그 텍스트 노드** text 치환.
          const created = await createCustomKey(tid, layoutName, locale, draft);
          if (created.kind === 'ok' && created.resource) {
            const token = `$t:${created.resource.translation_key}`;
            if (textPath.length === 0) {
              // 직접 text 셀 — 단순 경로.
              patch(setCellText(node, tableParams as never, r, c, token));
            } else {
              // 복합 셀 — 텍스트 자손만 치환한 셀 사본을 통째 반영(형제/구조 보존).
              const nextCell = patchTextAtPath(ref.cell, textPath, token);
              patch(setCellNode(node, tableParams as never, r, c, nextCell));
            }
            await bustTranslationCache(tid, locale);
          }
        }
      } finally {
        setBusy(false);
        setDrafts((d) => {
          const n = { ...d };
          delete n[dkey];
          return n;
        });
      }
    },
    [drafts, grid, tid, locale, layoutName, node, tableParams, patch],
  );

  // 선택 셀의 편집 대상 텍스트 노드(직접 text 또는 의미 텍스트 자손) — `I18nTextField`(ko/en/ja
  // 펼침) 의 value 원천. 순수 구조 셀(텍스트 자손 없음)은 null → 다국어 섹션 미노출.
  const selCellText = useMemo<{ value: string; editable: boolean } | null>(() => {
    if (!selectedRef) return null;
    const { node: textNode, path } = cellTextTarget(selectedRef.cell);
    if (path === null) return null;
    const text = typeof textNode?.text === 'string' ? textNode.text : '';
    // 바인딩식(`{{...}}`)은 편집 비대상 — I18nTextField 가 자체적으로 읽기전용 배지를 띄우므로
    // value 는 그대로 넘기되 editable 판정만 전달(섹션 노출 여부는 텍스트 노드 존재로 결정).
    return { value: text, editable: true };
  }, [selectedRef]);

  // 선택 셀 텍스트 토큰 기록 — `I18nTextField` 가 평문 첫 입력 시 커스텀 키를 생성하면 그
  // `$t:custom.*` 토큰을 선택 셀의 텍스트 노드(직접 text 또는 의미 텍스트 자손)에 기록한다.
  // 기존 키 값 갱신·ko/en/ja 일괄 편집은 위젯이 useCustomTranslation SSoT 안에서 처리하므로
  // 노드 text(키 토큰)는 불변 → 동일 토큰 onChange 도 patch 결과가 동일하다(idempotent).
  // grid 인라인 input(commitCellText)·캔버스 인플레이스와 동일한 키 모델을 공유한다(SSoT).
  const commitSelCellTextToken = useCallback(
    (token: string | undefined): void => {
      if (typeof token !== 'string' || token.length === 0 || !sel) return;
      const ref = cellRefAt(grid, sel.r, sel.c);
      if (!ref) return;
      const path = findTextNodePath(ref.cell);
      if (path === null) return;
      if (path.length === 0) {
        patch(setCellText(node, tableParams as never, sel.r, sel.c, token));
      } else {
        const nextCell = patchTextAtPath(ref.cell, path, token);
        patch(setCellNode(node, tableParams as never, sel.r, sel.c, nextCell));
      }
    },
    [sel, grid, node, tableParams, patch],
  );

  const toggleSelect = useCallback((r: number, c: number, shift: boolean) => {
    if (shift) {
      setSelEnd({ r, c });
    } else {
      setSel({ r, c });
      setSelEnd(null);
    }
  }, []);

  const colIdx = useMemo(() => Array.from({ length: grid.colCount }, (_, i) => i), [grid.colCount]);
  const canMerge = !!(sel && selEnd && (sel.r !== selEnd.r || sel.c !== selEnd.c));
  const canUnmerge = !!(selectedRef && (selectedRef.colSpan > 1 || selectedRef.rowSpan > 1));
  const selClassName =
    selectedRef && typeof selectedRef.cell.props?.className === 'string'
      ? (selectedRef.cell.props.className as string)
      : '';

  return (
    <div className="g7le-node-editor g7le-node-editor--table" data-testid="g7le-table-editor" style={wrap}>
      <div style={sectionTitle}>{t('layout_editor.table_editor.title')}</div>

      {/* 상단 열 거터(추가/삭제/이동) + 표 grid */}
      <div style={{ overflowX: 'auto' }}>
        <table data-testid="g7le-table-editor-grid" style={tableStyle}>
          <thead>
            <tr>
              <th style={cornerCell} />
              {colIdx.map((c) => {
                const left = colMove(c, -1);
                const right = colMove(c, 1);
                const colActive = activeColGut === c;
                return (
                    <th
                      key={c}
                      style={colGutter}
                      data-testid={`g7le-table-colgutter-${c}`}
                      onMouseEnter={() => setHoverCol(c)}
                      onMouseLeave={() => setHoverCol((v) => (v === c ? null : v))}
                    >
                      <div style={{ ...gutterBtns, visibility: colActive ? 'visible' : 'hidden' }}>
                        <button
                          type="button"
                          title={
                            left.ok
                              ? t('layout_editor.table_editor.move_col_left')
                              : moveReasonLabel(left.reason) ||
                                t('layout_editor.table_editor.move_col_left')
                          }
                          disabled={!left.ok || busy}
                          onClick={() => onMoveCol(c, -1)}
                          data-testid={`g7le-table-col-left-${c}`}
                          style={left.ok ? miniBtn : miniBtnBlocked}
                        >
                          ←
                        </button>
                        <button
                          type="button"
                          title={t('layout_editor.table_editor.add_col')}
                          disabled={busy}
                          onClick={() => onAddCol(c)}
                          data-testid={`g7le-table-col-add-${c}`}
                          style={miniBtn}
                        >
                          +
                        </button>
                        <button
                          type="button"
                          title={t('layout_editor.table_editor.remove_col')}
                          disabled={grid.colCount <= 1 || busy}
                          onClick={() => onRemoveCol(c)}
                          data-testid={`g7le-table-col-remove-${c}`}
                          style={miniBtnDanger}
                        >
                          ✕
                        </button>
                        <button
                          type="button"
                          title={
                            right.ok
                              ? t('layout_editor.table_editor.move_col_right')
                              : moveReasonLabel(right.reason) ||
                                t('layout_editor.table_editor.move_col_right')
                          }
                          disabled={!right.ok || busy}
                          onClick={() => onMoveCol(c, 1)}
                          data-testid={`g7le-table-col-right-${c}`}
                          style={right.ok ? miniBtn : miniBtnBlocked}
                        >
                          →
                        </button>
                      </div>
                    </th>
                );
              })}
            </tr>
          </thead>
          <tbody>
            {grid.rows.map((row, ri) => {
              const no = row.cells[0]?.gridRow ?? ri;
              const rowActive = activeRowGut === no;
              return (
                <tr
                  key={ri}
                  data-testid={`g7le-table-row-${no}`}
                  onMouseEnter={() => setHoverRow(no)}
                  onMouseLeave={() => setHoverRow((v) => (v === no ? null : v))}
                >
                  {/* 좌측 행 거터 */}
                  <td style={rowGutter} data-testid={`g7le-table-rowgutter-${no}`}>
                    {(() => {
                      const up = rowMove(no, -1);
                      const down = rowMove(no, 1);
                      return (
                        <div style={{ ...gutterBtnsCol, visibility: rowActive ? 'visible' : 'hidden' }}>
                          <button
                            type="button"
                            title={
                              up.ok
                                ? t('layout_editor.table_editor.move_row_up')
                                : moveReasonLabel(up.reason) ||
                                  t('layout_editor.table_editor.move_row_up')
                            }
                            disabled={!up.ok || busy}
                            onClick={() => onMoveRow(no, -1)}
                            data-testid={`g7le-table-row-up-${no}`}
                            style={up.ok ? miniBtn : miniBtnBlocked}
                          >
                            ↑
                          </button>
                          <button
                            type="button"
                            title={t('layout_editor.table_editor.add_row')}
                            disabled={busy}
                            onClick={() => onAddRow(no)}
                            data-testid={`g7le-table-row-add-${no}`}
                            style={miniBtn}
                          >
                            +
                          </button>
                          <button
                            type="button"
                            title={t('layout_editor.table_editor.remove_row')}
                            disabled={grid.rows.length <= 1 || busy}
                            onClick={() => onRemoveRow(no)}
                            data-testid={`g7le-table-row-remove-${no}`}
                            style={miniBtnDanger}
                          >
                            ✕
                          </button>
                          <button
                            type="button"
                            title={
                              down.ok
                                ? t('layout_editor.table_editor.move_row_down')
                                : moveReasonLabel(down.reason) ||
                                  t('layout_editor.table_editor.move_row_down')
                            }
                            disabled={!down.ok || busy}
                            onClick={() => onMoveRow(no, 1)}
                            data-testid={`g7le-table-row-down-${no}`}
                            style={down.ok ? miniBtn : miniBtnBlocked}
                          >
                            ↓
                          </button>
                        </div>
                      );
                    })()}
                  </td>
                  {/* origin 셀들 */}
                  {row.cells.map((ref) => {
                    const dkey = `${ref.gridRow}:${ref.gridCol}`;
                    // 복합 셀: 텍스트 자손까지 탐색. 텍스트 노드 없으면 순수 구조 셀.
                    const { node: textNode, path: textPath } = cellTextTarget(ref.cell);
                    const info = resolveTextNode(textNode, tid, locale);
                    // 텍스트 편집 가능 = 텍스트 노드 존재 + 평문/키(바인딩 아님).
                    const textEditable = textPath !== null && info.editable;
                    // 순수 구조 셀(아이콘/이미지만, 텍스트 자손 없음) — 구조 라벨 표시.
                    const structural = textPath === null;
                    const draft = drafts[dkey];
                    const value = draft !== undefined ? draft : info.display;
                    const isSel =
                      sel && sel.r === ref.gridRow && sel.c === ref.gridCol;
                    const isInArea =
                      sel &&
                      selEnd &&
                      ref.gridRow >= Math.min(sel.r, selEnd.r) &&
                      ref.gridRow <= Math.max(sel.r, selEnd.r) &&
                      ref.gridCol >= Math.min(sel.c, selEnd.c) &&
                      ref.gridCol <= Math.max(sel.c, selEnd.c);
                    return (
                      <td
                        key={dkey}
                        colSpan={ref.colSpan}
                        rowSpan={ref.rowSpan}
                        data-testid={`g7le-table-cell-${ref.gridRow}-${ref.gridCol}`}
                        onClick={(e) => toggleSelect(ref.gridRow, ref.gridCol, e.shiftKey)}
                        style={{
                          ...cellStyle,
                          // 셀의 실제 테두리(두께/변/색)를 미리보기에 반영(고정 1px 회색 위에 덮어씀).
                          // 컨트롤로 두께/색을 바꾸면 이 미리보기도 즉시 갱신. 활성 스킴(라이트/다크)
                          // 색 토큰을 반영(다크 탭이면 dark: 토큰 swatch).
                          ...cellBorderPreviewStyle(ref.cell, cellBorderCatalog, colorScheme),
                          // 셀 배경색/여백 도 미리보기에 반영 — 배경 토큰(스킴별)→swatch + 인라인.
                          ...cellFillPaddingPreviewStyle(ref.cell, cellFillCatalog, colorScheme),
                          ...(ref.isHeader ? headerCellStyle : {}),
                          ...(isSel ? selectedCellStyle : {}),
                          ...(isInArea && !isSel ? areaCellStyle : {}),
                        }}
                      >
                        {textEditable ? (
                          <input
                            type="text"
                            data-testid={`g7le-table-cell-input-${ref.gridRow}-${ref.gridCol}`}
                            value={value}
                            placeholder={t('layout_editor.table_editor.cell_text')}
                            disabled={busy}
                            // 단일 클릭 = 셀 선택 + 인라인 편집(input 포커스) 동시.
                            // Shift+클릭 = 영역 선택만(편집 포커스 막음).
                            onMouseDown={(e) => {
                              if (e.shiftKey) e.preventDefault(); // 포커스 진입(편집) 차단
                              toggleSelect(ref.gridRow, ref.gridCol, e.shiftKey);
                            }}
                            onClick={(e) => e.stopPropagation()}
                            onChange={(e) =>
                              setDrafts((d) => ({ ...d, [dkey]: e.target.value }))
                            }
                            onBlur={() => void commitCellText(ref.gridRow, ref.gridCol)}
                            style={cellInput}
                          />
                        ) : structural ? (
                          // 순수 구조 셀(아이콘/이미지/컴포넌트만) — 구조는 캔버스/children
                          // 에디터에서 편집. 여기선 선택/병합만(구조 라벨 표시).
                          <span
                            data-testid={`g7le-table-cell-struct-${ref.gridRow}-${ref.gridCol}`}
                            style={boundLabel}
                          >
                            {t('layout_editor.table_editor.structural_cell')}
                          </span>
                        ) : (
                          // 바인딩식 등 비편집 텍스트 — 미리보기.
                          <span style={boundLabel}>{info.display}</span>
                        )}
                      </td>
                    );
                  })}
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {/* 하단 행 추가 */}
      <button
        type="button"
        data-testid="g7le-table-add-row-bottom"
        onClick={() => onAddRow(grid.rows.length - 1)}
        disabled={busy}
        style={addBtn}
      >
        + {t('layout_editor.table_editor.add_row')}
      </button>

      {/* 선택 셀 도구: 병합/해제 + 테두리 */}
      <div style={toolbar} data-testid="g7le-table-cell-tools">
        <button
          type="button"
          data-testid="g7le-table-merge"
          onClick={onMerge}
          disabled={!canMerge || busy}
          style={toolBtn}
        >
          {t('layout_editor.table_editor.merge_cells')}
        </button>
        <button
          type="button"
          data-testid="g7le-table-unmerge"
          onClick={onUnmerge}
          disabled={!canUnmerge || busy}
          style={toolBtn}
        >
          {t('layout_editor.table_editor.unmerge_cells')}
        </button>
      </div>
      {/* 선택 셀 다국어 — grid 인라인 input 은 빠른 평문 편집용이고, 여기서 ko/en/ja 일괄
          편집(🌐 펼침)으로 진입한다(캔버스 인플레이스의 "🌐 모든 언어 편집"
          진입과 패리티). children 항목 텍스트와 동일 공통 위젯 `I18nTextField` + 공용
          nodeTextPath 토큰 기록(SSoT). 순수 구조 셀(텍스트 자손 없음)은 미노출. */}
      {selectedRef && selCellText && (
        <div style={i18nSection} data-testid="g7le-table-cell-i18n-section">
          <div style={i18nLabel}>{t('layout_editor.table_editor.cell_i18n_label')}</div>
          <I18nTextField
            value={selCellText.value}
            onChange={commitSelCellTextToken}
            t={t}
            placeholder={t('layout_editor.table_editor.cell_text')}
            testidPrefix="g7le-table-cell-i18n"
            // 표 셀 텍스트도 `+데이터` 칩 삽입(키화)에 후보 풀이 닿도록 전달.
            candidates={candidates}
            // 표 셀 텍스트도 표현식 분해 트리(접힌
            // 미리보기 + [수정]) + 데이터 칩. 평문/단일키/칩은 종전 경로(opt-in 게이트 회귀 0).
            enableExpressionTree
            expressionTreeCollapsible
          />
        </div>
      )}
      {/* 셀 색상(테두리 + 배경) — 라이트/다크 단일 공용 탭이 색상 전체에 적용. */}
      {selectedRef && (
        <div style={colorSection} data-testid="g7le-table-cell-color-section">
          <ColorSchemeTabs colorScheme={colorScheme} onChange={setColorScheme} t={t} disabled={busy} />
          {cellBorderCatalog && (
            <div style={borderRow} data-testid="g7le-table-cell-border-row">
              <CellBorderControl
                className={selClassName}
                catalog={cellBorderCatalog}
                t={t}
                onChange={onCellClassName}
                colorScheme={colorScheme}
                onPresetToken={onCellBorderPresetToken}
                colorStyle={selColorStyle}
                onColorStyle={onCellColorStyle}
                disabled={busy}
              />
            </div>
          )}
          {/* 셀 배경색 — 프리셋=토큰(스킴별)/자유 HEX=인라인(라이트). 영역 선택 시 영역 전 셀. */}
          <div style={borderRow} data-testid="g7le-table-cell-fill-row">
            <CellFillControl
              colorStyle={selFillStyle}
              className={selFillClassName}
              catalog={cellFillCatalog}
              t={t}
              colorScheme={colorScheme}
              onPresetToken={onFillPresetToken}
              onCustomColor={onCellFill}
              onClear={onFillClear}
              disabled={busy}
            />
          </div>
        </div>
      )}
      {/* 셀 내부 여백 — 인라인 style.padding(프리셋 단계 + 자유 px). 영역 선택 시 영역 전 셀. */}
      {selectedRef && (
        <div style={borderRow} data-testid="g7le-table-cell-padding-row">
          <CellPaddingControl
            paddingStyle={selPaddingStyle}
            catalog={cellPaddingCatalog}
            t={t}
            onChange={onCellPadding}
            disabled={busy}
          />
        </div>
      )}
      {/* 카탈로그 미공급 템플릿(중립 폴백) — raw className 입력으로 디그레이드 */}
      {selectedRef && !cellBorderCatalog && (
        <label style={borderRowInline} data-testid="g7le-table-cell-border-row">
          <span style={borderLabel}>{t('layout_editor.table_editor.cell_border')}</span>
          <input
            type="text"
            data-testid="g7le-table-cell-border-input"
            value={selClassName}
            placeholder={t('layout_editor.table_editor.cell_class_placeholder')}
            onChange={(e) => onCellClassName(e.target.value)}
            disabled={busy}
            style={borderInput}
          />
        </label>
      )}
      {!grid.valid && (
        <div data-testid="g7le-table-grid-warning" style={warn}>
          {t('layout_editor.table_editor.grid_invalid')}
        </div>
      )}
      <div style={hint}>{t('layout_editor.table_editor.select_hint')}</div>
    </div>
  );
}

const wrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 8, width: '100%', marginBottom: 12 };
const sectionTitle: React.CSSProperties = { fontSize: 12, fontWeight: 700, color: '#0f172a' };
const tableStyle: React.CSSProperties = { borderCollapse: 'collapse', width: 'auto' };
const cornerCell: React.CSSProperties = { width: 28, background: 'transparent', border: 'none' };
const colGutter: React.CSSProperties = { padding: 2, border: 'none', background: 'transparent' };
const rowGutter: React.CSSProperties = { padding: 2, border: 'none', background: 'transparent', verticalAlign: 'middle' };
const gutterBtns: React.CSSProperties = { display: 'flex', gap: 2, justifyContent: 'center' };
// 행 거터는 2×2 그리드(4버튼)로 묶어 세로 높이가 셀 행 높이를 키우지 않게 한다
// (세로 1열 스택이면 ~84px 가 행 높이를 강제 → 셀이 과도하게 커짐).
const gutterBtnsCol: React.CSSProperties = {
  display: 'grid',
  gridTemplateColumns: 'repeat(2, 16px)',
  gap: 2,
  justifyContent: 'center',
};
const miniBtn: React.CSSProperties = { width: 16, height: 16, lineHeight: '14px', padding: 0, fontSize: 10, border: '1px solid #cbd5e1', borderRadius: 4, background: '#fff', color: '#475569', cursor: 'pointer' };
const miniBtnDanger: React.CSSProperties = { ...miniBtn, border: '1px solid #fecaca', color: '#dc2626' };
// 이동 불가(병합/섹션 경계) — 비활성 + 금지 커서. disabled 속성과 함께 시각 피드백.
const miniBtnBlocked: React.CSSProperties = { ...miniBtn, cursor: 'not-allowed', opacity: 0.4, color: '#94a3b8', borderColor: '#e2e8f0' };
const cellStyle: React.CSSProperties = { border: '1px solid #cbd5e1', padding: 0, width: 64, minWidth: 64, maxWidth: 120, height: 24, background: '#fff', cursor: 'pointer' };
const headerCellStyle: React.CSSProperties = { background: '#f1f5f9', fontWeight: 600 };
const selectedCellStyle: React.CSSProperties = { outline: '2px solid #2563eb', outlineOffset: -2 };
const areaCellStyle: React.CSSProperties = { background: '#dbeafe' };
const cellInput: React.CSSProperties = { width: '100%', boxSizing: 'border-box', padding: '2px 5px', fontSize: 11, height: 22, lineHeight: '18px', border: 'none', background: 'transparent', outline: 'none' };
const boundLabel: React.CSSProperties = { display: 'block', padding: '2px 5px', fontSize: 10, color: '#64748b', fontStyle: 'italic', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', maxWidth: 110 };
const addBtn: React.CSSProperties = { padding: '4px 8px', fontSize: 12, border: '1px dashed #94a3b8', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer', alignSelf: 'flex-start' };
const toolbar: React.CSSProperties = { display: 'flex', gap: 6 };
const toolBtn: React.CSSProperties = { padding: '4px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer' };
const colorSection: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 8, borderTop: '1px solid #f1f5f9', paddingTop: 8 };
const i18nSection: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6, borderTop: '1px solid #f1f5f9', paddingTop: 8 };
const i18nLabel: React.CSSProperties = { fontSize: 12, fontWeight: 700, color: '#0f172a' };
const borderRow: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6 };
const borderRowInline: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 6 };
const borderLabel: React.CSSProperties = { fontSize: 12, color: '#475569', minWidth: 60 };
const borderInput: React.CSSProperties = { flex: 1, minWidth: 0, padding: '4px 6px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6 };
const warn: React.CSSProperties = { fontSize: 11, color: '#b45309', background: '#fffbeb', border: '1px solid #fde68a', borderRadius: 6, padding: '4px 6px' };
const hint: React.CSSProperties = { fontSize: 11, color: '#94a3b8' };
