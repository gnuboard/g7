// e2e:allow 레이아웃 편집기 반복(iteration) 데이터 연결 속성패널 UI — dnd-kit/합성 이벤트 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스 + 단위/레이아웃 렌더링 테스트로 검증 (DataBindingSection.tsx L1 과 동일 정책)
/**
 * IterationBindingSection.tsx — [속성] 탭 "반복 데이터 연결" 전용 영역
 *
 * `node.iteration.source` 는 **노드 최상위 구조 키**(props 아님 —
 * `feedback_node_structural_keys_if_actions_are_top_level_not_props`)다. 반복 렌더링은
 * Div/Span/Button/Li 등 거의 모든 컴포넌트에 붙을 수 있어, 컴포넌트별 `dataProps`
 * 선언이 아니라 **iteration 을 가진 모든 노드에 공용으로** 노출한다(
 * "노드 구조 기반 공용 처리"). 6-b 의 dataProps 가 iteration 축을 통째로 누락했던 결함
 * 을 본 섹션이 1급으로 메운다.
 *
 * 반복 소스는 항상 **배열(array)** 바인딩이다 — `bindingCandidates` 의 array shape 후보를
 * 그대로 검색·선택한다(DataBindingSection 의 array 행과 동일 UX, 신규 인프라 0). 선택 시
 * `{{<source>.<path>}}` 를 `node.iteration.source` 에 기입(런타임 `DynamicRenderer` 가
 * 동일하게 해소해 데이터 수만큼 반복). 기존 바인딩은 `parseBindingExpression` 으로 소스/
 * 경로를 인지해 표시하고, 단일 경로가 아닌 복합식(`?? []` 폴백 포함)은 "복합 바인딩(코드
 * 편집)" 디그레이드로 읽기전용 표시(부록6 가드).
 *
 * `item_var`/`index_var`(반복 변수명)은 구조 메타라 읽기전용 힌트로만 표시한다 — 그 값은
 * 안쪽 자식의 `{{<item_var>.*}}` 바인딩과 연동되므로 편집기에서 임의 변경 시 자식 표현식이
 * 깨진다(변경은 코드 편집 영역).
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만(라이브러리 중립).
 *
 * @since engine-v1.50.0
 */

import React, { useMemo, useState } from 'react';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import {
  type BindingCandidate,
  buildBindingExpression,
  filterCandidatesByShape,
  parseBindingExpression,
  searchCandidates,
} from '../../spec/bindingCandidates';

export interface IterationBindingSectionProps {
  /** 편집 대상 노드 */
  node: EditorNode;
  /** 연결 가능 데이터 후보 풀(평탄) — EditorCanvasOverlay 가 빌드해 주입 */
  candidates: BindingCandidate[];
  /** 다국어 해석(편집 대상 템플릿 사전) */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 노드 패치 */
  onPatchNode: (patched: EditorNode) => void;
}

/** `$t:` 키 또는 평문 라벨을 해석. 미해석 시 fallback. (DataBindingSection 과 동형) */
function resolveLabel(
  t: IterationBindingSectionProps['t'],
  key: string | undefined,
  fallback: string,
): string {
  if (!key) return fallback;
  const resolved = t(key);
  if (!resolved || resolved === key || resolved.startsWith('$t:')) return fallback;
  return resolved;
}

function candidateDisplayLabel(c: BindingCandidate, t: IterationBindingSectionProps['t']): string {
  return resolveLabel(t, c.labelKey, c.path || c.sourceId);
}

/** 노드의 iteration 구조(있으면) — `{ source?, item_var?, index_var? }` */
function readIteration(
  node: EditorNode,
): { source?: unknown; item_var?: unknown; index_var?: unknown } | null {
  const it = node.iteration;
  if (it && typeof it === 'object') return it as { source?: unknown; item_var?: unknown; index_var?: unknown };
  return null;
}

export function IterationBindingSection({
  node,
  candidates,
  t,
  onPatchNode,
}: IterationBindingSectionProps): React.ReactElement | null {
  const iteration = readIteration(node);
  const source = iteration?.source;
  const sourceStr = typeof source === 'string' ? source : undefined;
  const parsed = useMemo(() => parseBindingExpression(source), [source]);
  // 값이 있으나 단일 경로 바인딩이 아니면 복합 바인딩(코드 편집) 디그레이드.
  const isComplexBinding = sourceStr !== undefined && sourceStr !== '' && parsed === null;
  const [open, setOpen] = useState(false);
  const [keyword, setKeyword] = useState('');

  // 반복 소스는 항상 배열 — array shape 후보만.
  const shaped = useMemo(() => {
    const byShape = filterCandidatesByShape(candidates, 'array');
    return byShape.map((c) => ({ ...c, resolvedLabel: candidateDisplayLabel(c, t) }));
  }, [candidates, t]);
  const results = useMemo(() => searchCandidates(shaped, keyword), [shaped, keyword]);

  // iteration 노드가 아니면 미노출(구조 키 부재 — 공용 처리 게이트).
  if (!iteration) return null;

  const itemVar = typeof iteration.item_var === 'string' ? iteration.item_var : undefined;
  const indexVar = typeof iteration.index_var === 'string' ? iteration.index_var : undefined;

  /** iteration.source 를 패치(다른 iteration 키 보존). undefined 면 source 키만 제거. */
  const patchSource = (value: unknown): void => {
    const nextIteration = { ...(iteration as Record<string, unknown>) };
    if (value === undefined) delete nextIteration.source;
    else nextIteration.source = value;
    onPatchNode({ ...node, iteration: nextIteration as EditorNode['iteration'] });
  };

  const select = (c: BindingCandidate): void => {
    // 반복 소스는 항상 배열 — G7 표준 안전 형태(`?.` 체이닝 + `?? []`)로 기입한다
    // (데이터 미도착 시 `undefined.map` 방지).
    patchSource(buildBindingExpression(c.sourceId, c.path, 'array'));
    setOpen(false);
    setKeyword('');
  };

  return (
    <div data-testid="g7le-iteration-binding-section" style={sectionStyle}>
      <div style={sectionHead}>🔁 {t('layout_editor.binding.iteration_title')}</div>
      <div style={hintRow}>{t('layout_editor.binding.iteration_hint')}</div>

      <div style={rowStyle}>
        <div style={rowHead}>
          <span style={{ fontWeight: 600, fontSize: 12 }}>
            {t('layout_editor.binding.iteration_label')}
          </span>
          <span style={shapeBadge}>{t('layout_editor.binding.shape_array')}</span>
        </div>

        {/* 연결 상태 표시 */}
        {isComplexBinding ? (
          <div data-testid="g7le-iteration-binding-complex" style={complexBadge}>
            {t('layout_editor.binding.complex')}
            <code style={exprCode}>{sourceStr}</code>
          </div>
        ) : parsed ? (
          <div style={connectedRow}>
            <code data-testid="g7le-iteration-binding-expr" style={exprCode}>
              {sourceStr}
            </code>
            <button
              type="button"
              data-testid="g7le-iteration-binding-clear"
              onClick={() => patchSource(undefined)}
              style={clearBtn}
            >
              {t('layout_editor.binding.clear')}
            </button>
          </div>
        ) : (
          <div data-testid="g7le-iteration-binding-empty" style={emptyRow}>
            {t('layout_editor.binding.not_connected')}
          </div>
        )}

        {/* 반복 변수명(읽기전용 힌트) — 안쪽 자식 바인딩과 연동되어 코드 편집 영역 */}
        {(itemVar || indexVar) && (
          <div data-testid="g7le-iteration-vars" style={varsHint}>
            {itemVar && (
              <span>
                {t('layout_editor.binding.iteration_item_var')}: <code style={varCode}>{itemVar}</code>
              </span>
            )}
            {indexVar && (
              <span>
                {t('layout_editor.binding.iteration_index_var')}: <code style={varCode}>{indexVar}</code>
              </span>
            )}
          </div>
        )}

        {/* 검색형 피커 — 복합 바인딩(코드 편집)이 아닐 때만 노출 */}
        {!isComplexBinding && (
          <div style={{ marginTop: 4 }}>
            <button
              type="button"
              data-testid="g7le-iteration-binding-search-toggle"
              onClick={() => setOpen((v) => !v)}
              style={searchToggle}
            >
              🔍 {t('layout_editor.binding.search')}
            </button>
            {open && (
              <div data-testid="g7le-iteration-binding-picker" style={pickerBox}>
                <input
                  type="text"
                  autoFocus
                  data-testid="g7le-iteration-binding-search-input"
                  value={keyword}
                  placeholder={t('layout_editor.binding.search_placeholder')}
                  onChange={(e) => setKeyword(e.target.value)}
                  style={searchInput}
                />
                <div style={resultList}>
                  {results.length === 0 ? (
                    <div style={noResult}>{t('layout_editor.binding.no_results')}</div>
                  ) : (
                    results.map((c) => (
                      <button
                        key={c.expression}
                        type="button"
                        data-testid={`g7le-iteration-binding-candidate-${c.expression}`}
                        onClick={() => select(c)}
                        style={candidateBtn}
                      >
                        <span style={{ display: 'flex', flexDirection: 'column', alignItems: 'flex-start' }}>
                          <span style={{ fontSize: 12 }}>{c.resolvedLabel}</span>
                          <span style={candidateMeta}>
                            {resolveLabel(t, c.groupLabelKey, c.sourceId)} · {c.path || c.sourceId}
                          </span>
                        </span>
                        <span style={candidatePreview}>{c.preview}</span>
                      </button>
                    ))
                  )}
                </div>
              </div>
            )}
          </div>
        )}
      </div>
      <div style={divider} />
    </div>
  );
}

const sectionStyle: React.CSSProperties = { display: 'flex', flexDirection: 'column', marginBottom: 8 };
const sectionHead: React.CSSProperties = { fontSize: 12, fontWeight: 700, color: '#0f172a', marginBottom: 4 };
const hintRow: React.CSSProperties = { fontSize: 10, color: '#94a3b8', marginBottom: 6, lineHeight: 1.4 };
const rowStyle: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 4, padding: '8px 0' };
const rowHead: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 6 };
const shapeBadge: React.CSSProperties = { fontSize: 10, padding: '1px 6px', borderRadius: 4, background: '#e0e7ff', color: '#3730a3' };
const connectedRow: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 6 };
const emptyRow: React.CSSProperties = { fontSize: 11, color: '#94a3b8' };
const exprCode: React.CSSProperties = { fontSize: 11, fontFamily: 'monospace', background: '#f1f5f9', padding: '2px 6px', borderRadius: 4, color: '#0f172a', wordBreak: 'break-all' };
const clearBtn: React.CSSProperties = { fontSize: 11, border: '1px solid #e2e8f0', borderRadius: 4, background: '#fff', color: '#dc2626', padding: '2px 8px', cursor: 'pointer' };
const complexBadge: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 2, fontSize: 11, color: '#64748b' };
const varsHint: React.CSSProperties = { display: 'flex', gap: 12, fontSize: 10, color: '#94a3b8', marginTop: 2 };
const varCode: React.CSSProperties = { fontSize: 10, fontFamily: 'monospace', background: '#f1f5f9', padding: '1px 4px', borderRadius: 3, color: '#475569' };
const searchToggle: React.CSSProperties = { fontSize: 11, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#334155', padding: '4px 10px', cursor: 'pointer', textAlign: 'left', width: '100%' };
const pickerBox: React.CSSProperties = { marginTop: 4, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', padding: 6 };
const searchInput: React.CSSProperties = { width: '100%', padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, boxSizing: 'border-box' };
const resultList: React.CSSProperties = { marginTop: 4, maxHeight: 200, overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: 2 };
const noResult: React.CSSProperties = { fontSize: 11, color: '#94a3b8', padding: '8px 0', textAlign: 'center' };
const candidateBtn: React.CSSProperties = { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8, border: 'none', background: 'transparent', cursor: 'pointer', padding: '6px 8px', borderRadius: 4, textAlign: 'left' };
const candidateMeta: React.CSSProperties = { fontSize: 10, color: '#94a3b8' };
const candidatePreview: React.CSSProperties = { fontSize: 10, color: '#64748b', fontFamily: 'monospace', whiteSpace: 'nowrap' };
const divider: React.CSSProperties = { borderTop: '1px solid #e2e8f0', marginTop: 4 };
