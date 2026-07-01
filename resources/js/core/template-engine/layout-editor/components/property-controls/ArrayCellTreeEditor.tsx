// e2e:allow 레이아웃 편집기 속성패널 중첩 셀-트리 컬럼 에디터 UI — Chrome MCP 매트릭스(T1~T7) 실측 + 단위/레이아웃 렌더링 테스트로 검증
/**
 * ArrayCellTreeEditor.tsx — `array-cell-tree` 노드 에디터
 *
 * CardGrid 의 `cardColumns` 처럼 **prop 안에 중첩 노드트리(`cellChildren`)를 품은
 * 배열**을 편집한다. 다른 데이터 prop(평탄 배열)은 ArrayItemsEditor 로 충분하지만,
 * cardColumns 는 각 컬럼이 `{ id, cellChildren:[노드트리] }` 구조라 두 층을 함께
 * 다뤄야 한다(부록8 (E) — 4-b 이월 명시분, "셀 트리 전체" 결정 2026-06-08):
 *  1. 컬럼 층 — 추가/삭제/정렬 + `id`(식별자) 텍스트 편집.
 *  2. 셀 트리 층 — 각 컬럼의 `cellChildren` 노드트리를 **ChildrenListControl 재사용**.
 *     컬럼을 펼치면 그 cellChildren 을 `node.children` 으로 갖는 합성 노드를
 *     ChildrenListControl 에 넘기고, 그 onPatchNode 를 컬럼 배열 패치로 되돌린다 —
 *     자식 노드 추가/삭제/정렬/항목 텍스트 다국어가 전부 그 SSoT 한 벌로 동작한다.
 *
 * params 스키마(이 kind 소유의 불투명 객체):
 *  - `arrayProp`: 컬럼 배열 prop 키(예 "cardColumns").
 *  - `idField`: 컬럼 식별자 필드 키(예 "id"). 컬럼 행 라벨/편집 입력.
 *  - `cellChildrenProp`: 컬럼 안 노드트리 prop 키(예 "cellChildren").
 *  - `cellChildComponent`: 셀 트리에 추가할 자식 컴포넌트(예 "Div") — ChildrenListControl
 *    의 `childComponent` 로 전달.
 *  - `itemLabel`(선택): "컬럼 추가" 버튼 친화 명사.
 *  - `newItem`(선택): 새 컬럼 골격(미지정 시 `{ [idField]: "", [cellChildrenProp]: [] }`).
 *
 * 정적-바인딩 가드: `node.props[arrayProp]` 가 `{{...}}` 바인딩이거나 비-배열이면
 * 편집 비대상 디그레이드(ArrayItemsEditor 와 동일 정책 — 덮어쓰기 위험 차단).
 *
 * 편집기 코어 컴포넌트 — `g7le-*` BEM + 인라인 스타일만, CSS 라이브러리 토큰 비종속.
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useState } from 'react';
import type { NodeEditorProps } from '../../spec/nodeEditorRegistry';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import { ChildrenListControl } from './ChildrenListControl';

/** 바인딩식 토큰. */
const BINDING_RE = /\{\{.*?\}\}/;

/** 컬럼 항목 — `{ [idField]: string, [cellChildrenProp]: node[] }`. */
type ColumnItem = Record<string, unknown>;

/** 값이 `{{...}}` 바인딩 표현식 문자열인지. */
function isBindingExpression(value: unknown): boolean {
  return typeof value === 'string' && BINDING_RE.test(value);
}

/** 친화 라벨 해석 — `$t:` 키면 t(), 아니면 평문. */
function resolveLabel(
  label: string | undefined,
  t: (k: string, p?: Record<string, string | number>) => string,
  fallback: string,
): string {
  if (!label) return fallback;
  return label.startsWith('$t:') ? t(label.slice(3)) : label;
}

export function ArrayCellTreeEditor(props: NodeEditorProps): React.ReactElement {
  const { node, params, t, onPatchNode } = props;

  const arrayProp = typeof params?.arrayProp === 'string' ? (params.arrayProp as string) : null;
  const idField = typeof params?.idField === 'string' ? (params.idField as string) : 'id';
  const cellChildrenProp =
    typeof params?.cellChildrenProp === 'string' ? (params.cellChildrenProp as string) : 'cellChildren';
  const cellChildComponent =
    typeof params?.cellChildComponent === 'string' ? (params.cellChildComponent as string) : 'Div';
  const itemLabel = resolveLabel(
    typeof params?.itemLabel === 'string' ? (params.itemLabel as string) : undefined,
    t,
    t('layout_editor.array_editor.item'),
  );

  const [expanded, setExpanded] = useState<number | null>(null);

  const rawValue = arrayProp ? (node.props ?? ({} as Record<string, unknown>))[arrayProp] : undefined;
  const columns: ColumnItem[] = Array.isArray(rawValue) ? (rawValue as ColumnItem[]) : [];

  /** 컬럼 배열을 통째 교체해 노드 패치(캔버스 PATCH_LAYOUT 반영 + history). */
  const commit = useCallback(
    (next: ColumnItem[]): void => {
      if (!arrayProp) return;
      const propsNext = { ...(node.props ?? {}) } as Record<string, unknown>;
      propsNext[arrayProp] = next;
      onPatchNode({ ...node, props: propsNext });
    },
    [arrayProp, node, onPatchNode],
  );

  /** 새 컬럼 골격 — params.newItem 우선, 미지정 시 { [idField]:"", [cellChildrenProp]:[] }. */
  const buildNewColumn = useCallback((): ColumnItem => {
    const skel = params?.newItem;
    if (skel && typeof skel === 'object') return JSON.parse(JSON.stringify(skel)) as ColumnItem;
    return { [idField]: '', [cellChildrenProp]: [] };
  }, [params, idField, cellChildrenProp]);

  const addColumn = useCallback((): void => {
    commit([...columns, buildNewColumn()]);
  }, [commit, columns, buildNewColumn]);

  const removeAt = useCallback(
    (idx: number): void => {
      commit(columns.filter((_, i) => i !== idx));
      setExpanded(null);
    },
    [commit, columns],
  );

  const move = useCallback(
    (idx: number, dir: -1 | 1): void => {
      const target = idx + dir;
      if (target < 0 || target >= columns.length) return;
      const next = [...columns];
      [next[idx], next[target]] = [next[target]!, next[idx]!];
      commit(next);
    },
    [commit, columns],
  );

  const updateId = useCallback(
    (idx: number, value: string): void => {
      const next = columns.map((c, i) => (i === idx ? { ...c, [idField]: value } : c));
      commit(next);
    },
    [columns, commit, idField],
  );

  /**
   * 한 컬럼의 cellChildren 트리 편집을 ChildrenListControl 로 위임하기 위한 어댑터.
   * ChildrenListControl 은 `node.children` 을 다루므로, 컬럼의 cellChildren 을
   * children 으로 갖는 합성 노드를 만들고, 그 onPatchNode 가 흘린 children 을 다시
   * 컬럼의 cellChildrenProp 으로 되돌려 commit 한다(SSoT — 컬럼 배열 단일 패치 경로).
   */
  const cellTreeNode = useCallback(
    (col: ColumnItem): EditorNode => {
      const cell = Array.isArray(col[cellChildrenProp]) ? (col[cellChildrenProp] as EditorNode[]) : [];
      return { type: 'composite', name: 'CardGridCell', children: cell } as EditorNode;
    },
    [cellChildrenProp],
  );

  const patchCellTree = useCallback(
    (idx: number) =>
      (patched: EditorNode): void => {
        const nextChildren = Array.isArray(patched.children) ? patched.children : [];
        const next = columns.map((c, i) => (i === idx ? { ...c, [cellChildrenProp]: nextChildren } : c));
        commit(next);
      },
    [columns, commit, cellChildrenProp],
  );

  // arrayProp 미선언(잘못된 capability) — 안전 안내.
  if (!arrayProp) {
    return (
      <div
        className="g7le-node-editor g7le-node-editor--cell-tree"
        data-testid="g7le-cell-tree-misconfigured"
        style={emptyHint}
      >
        {t('layout_editor.array_editor.no_array_prop')}
      </div>
    );
  }

  // 정적-바인딩 가드 — 바인딩식이거나 비-배열이면 디그레이드.
  if (isBindingExpression(rawValue) || (rawValue !== undefined && !Array.isArray(rawValue))) {
    return (
      <div
        className="g7le-node-editor g7le-node-editor--cell-tree"
        data-testid="g7le-cell-tree-bound"
        style={boundHint}
      >
        {t('layout_editor.array_editor.bound_degraded')}
      </div>
    );
  }

  return (
    <div
      className="g7le-node-editor g7le-node-editor--cell-tree"
      data-testid="g7le-cell-tree-editor"
      style={wrap}
    >
      <div style={sectionTitle}>{t('layout_editor.cell_tree_editor.columns_title')}</div>

      {columns.length === 0 && (
        <div data-testid="g7le-cell-tree-empty" style={emptyHint}>
          {t('layout_editor.array_editor.empty')}
        </div>
      )}

      {columns.map((col, idx) => {
        const idValue = typeof col[idField] === 'string' ? (col[idField] as string) : '';
        const isOpen = expanded === idx;
        return (
          <div key={idx} data-testid={`g7le-cell-tree-row-${idx}`} style={colBox}>
            <div style={colHeader}>
              <span style={colIndex}>#{idx + 1}</span>
              <input
                type="text"
                data-testid={`g7le-cell-tree-id-${idx}`}
                value={idValue}
                placeholder={t('layout_editor.cell_tree_editor.id_placeholder')}
                onChange={(e) => updateId(idx, e.target.value)}
                style={cellInput}
              />
              <button
                type="button"
                data-testid={`g7le-cell-tree-up-${idx}`}
                title={t('layout_editor.array_editor.move_up')}
                disabled={idx === 0}
                onClick={() => move(idx, -1)}
                style={iconBtn}
              >
                ↑
              </button>
              <button
                type="button"
                data-testid={`g7le-cell-tree-down-${idx}`}
                title={t('layout_editor.array_editor.move_down')}
                disabled={idx === columns.length - 1}
                onClick={() => move(idx, 1)}
                style={iconBtn}
              >
                ↓
              </button>
              <button
                type="button"
                data-testid={`g7le-cell-tree-remove-${idx}`}
                title={t('layout_editor.array_editor.remove')}
                onClick={() => removeAt(idx)}
                style={removeBtn}
              >
                ✕
              </button>
            </div>
            <button
              type="button"
              data-testid={`g7le-cell-tree-toggle-${idx}`}
              onClick={() => setExpanded(isOpen ? null : idx)}
              style={toggleBtn}
            >
              {isOpen
                ? t('layout_editor.cell_tree_editor.collapse_cells')
                : t('layout_editor.cell_tree_editor.expand_cells')}
            </button>
            {isOpen && (
              <div data-testid={`g7le-cell-tree-cells-${idx}`} style={cellTreeBox}>
                <ChildrenListControl
                  {...props}
                  node={cellTreeNode(col)}
                  params={{ childComponent: cellChildComponent }}
                  onPatchNode={patchCellTree(idx)}
                />
              </div>
            )}
          </div>
        );
      })}

      <button type="button" data-testid="g7le-cell-tree-add" onClick={addColumn} style={addBtn}>
        {t('layout_editor.cell_tree_editor.add_column', { item: itemLabel })}
      </button>
    </div>
  );
}

const wrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 8, width: '100%', marginBottom: 12 };
const sectionTitle: React.CSSProperties = { fontSize: 12, fontWeight: 700, color: '#0f172a', marginBottom: 2 };
const colBox: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6, padding: 8, border: '1px solid #e2e8f0', borderRadius: 8, background: '#f8fafc' };
const colHeader: React.CSSProperties = { display: 'flex', gap: 4, alignItems: 'center' };
const colIndex: React.CSSProperties = { fontSize: 11, fontWeight: 700, color: '#64748b', flexShrink: 0 };
const cellInput: React.CSSProperties = { flex: 1, minWidth: 0, padding: '4px 6px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6 };
const iconBtn: React.CSSProperties = { padding: '2px 6px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer' };
const removeBtn: React.CSSProperties = { padding: '2px 6px', fontSize: 12, border: '1px solid #fecaca', borderRadius: 6, background: '#fff', color: '#dc2626', cursor: 'pointer' };
const toggleBtn: React.CSSProperties = { padding: '3px 8px', fontSize: 11, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#2563eb', cursor: 'pointer', alignSelf: 'flex-start' };
const cellTreeBox: React.CSSProperties = { paddingLeft: 8, borderLeft: '2px solid #e2e8f0' };
const addBtn: React.CSSProperties = { padding: '4px 8px', fontSize: 12, border: '1px dashed #94a3b8', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer', alignSelf: 'flex-start' };
const emptyHint: React.CSSProperties = { fontSize: 11, color: '#94a3b8', fontStyle: 'italic' };
const boundHint: React.CSSProperties = { fontSize: 11, color: '#b45309', fontStyle: 'italic', padding: '4px 2px' };
