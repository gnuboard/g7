// e2e:allow 레이아웃 편집기 데이터 연결 속성패널 UI — dnd-kit/합성 이벤트 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스(6-b 캔버스 라이브 §공통 검증) + 단위/레이아웃 렌더링 테스트로 검증 (specTypes.ts L1 과 동일 정책)
/**
 * DataBindingSection.tsx — [속성] 탭 "데이터 연결" 전용 영역
 *
 * capability.dataProps 의 각 prop 을 행으로 렌더한다. 각 행은 **항상 바인딩 모드**
 * (정적↔바인딩 토글 없음 — 1차 결함 근절). 연결됨이면 표현식 + [해제], 미연결이면
 * `🔍 데이터 검색` 검색형 피커로 연결 가능 데이터를 키워드로 찾아 선택한다.
 *
 * shape 분기:
 *  - scalar: 스칼라 leaf 후보만 검색·선택.
 *  - array: 배열 leaf 후보만 + itemFields 미리보기.
 *
 * 선택 시 `{{<source>.<path>}}` 를 `props[propKey]` 에 기입(런타임 동일). 기존 바인딩은
 * `parseBindingExpression` 으로 소스/경로를 인지해 표시하고, 단일 경로 바인딩이 아닌
 * 복합식은 "복합 바인딩(코드 편집)" 디그레이드로 읽기전용 표시(부록6 가드).
 *
 * 친화 명칭: 후보의 `labelKey`/`groupLabelKey`(`$t:` 키)를 **편집 대상 템플릿
 * 사전**(`t`)으로 해석한다. 미해석 시 raw 키/경로 폴백(키 노출 회피).
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만(라이브러리 중립).
 *
 * @since engine-v1.50.0
 */

import React, { useMemo, useState } from 'react';
import type { DataPropSpec } from '../../spec/specTypes';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import {
  type BindingCandidate,
  buildBindingExpression,
  filterCandidatesByShape,
  parseBindingExpression,
  searchCandidates,
} from '../../spec/bindingCandidates';

export interface DataBindingSectionProps {
  /** 편집 대상 노드 */
  node: EditorNode;
  /** capability.dataProps 선언 */
  dataProps: DataPropSpec[];
  /** 연결 가능 데이터 후보 풀(평탄) — EditorCanvasOverlay 가 빌드해 주입 */
  candidates: BindingCandidate[];
  /** 다국어 해석(편집 대상 템플릿 사전) — 친화 명칭/라벨 `$t:` 키 해석 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 노드 패치 */
  onPatchNode: (patched: EditorNode) => void;
}

/** `$t:` 키 또는 평문 라벨을 해석. 미해석(키 원문 반환) 시 fallback 으로. */
function resolveLabel(
  t: DataBindingSectionProps['t'],
  key: string | undefined,
  fallback: string,
): string {
  if (!key) return fallback;
  const resolved = t(key);
  // 해석 실패(키 원문 그대로 반환) 또는 `$t:`/`editor.`/`custom.` 원문이면 폴백.
  if (!resolved || resolved === key || resolved.startsWith('$t:')) return fallback;
  return resolved;
}

/** 후보의 표시 라벨 — 친화 명칭(있으면) 그 외 경로. 그룹 라벨은 별도. */
function candidateDisplayLabel(c: BindingCandidate, t: DataBindingSectionProps['t']): string {
  return resolveLabel(t, c.labelKey, c.path || c.sourceId);
}

/** 한 데이터 prop 행 */
function DataPropRow({
  node,
  spec,
  candidates,
  t,
  onPatchNode,
}: {
  node: EditorNode;
  spec: DataPropSpec;
  candidates: BindingCandidate[];
  t: DataBindingSectionProps['t'];
  onPatchNode: (patched: EditorNode) => void;
}): React.ReactElement {
  const props = (node.props ?? {}) as Record<string, unknown>;
  const current = props[spec.propKey];
  const currentStr = typeof current === 'string' ? current : undefined;
  const parsed = useMemo(() => parseBindingExpression(current), [current]);
  // 값이 있으나 단일 경로 바인딩이 아니면 복합 바인딩(코드 편집) 디그레이드.
  const isComplexBinding = currentStr !== undefined && currentStr !== '' && parsed === null;
  const [open, setOpen] = useState(false);
  const [keyword, setKeyword] = useState('');

  const label = resolveLabel(t, spec.label, spec.propKey);
  const shapeLabel = t(
    spec.shape === 'array'
      ? 'layout_editor.binding.shape_array'
      : spec.shape === 'object'
        ? 'layout_editor.binding.shape_object'
        : 'layout_editor.binding.shape_scalar',
  );

  // shape 일치 후보 + 키워드 검색. 친화 명칭을 미리 해석해 검색 대상에 포함.
  const shaped = useMemo(() => {
    const byShape = filterCandidatesByShape(candidates, spec.shape);
    return byShape.map((c) => ({ ...c, resolvedLabel: candidateDisplayLabel(c, t) }));
  }, [candidates, spec.shape, t]);
  const results = useMemo(() => searchCandidates(shaped, keyword), [shaped, keyword]);

  const patch = (value: unknown): void => {
    const nextProps = { ...(node.props ?? {}) } as Record<string, unknown>;
    if (value === undefined) delete nextProps[spec.propKey];
    else nextProps[spec.propKey] = value;
    onPatchNode({ ...node, props: nextProps });
  };

  const select = (c: BindingCandidate): void => {
    // 후보의 단순 expression 이 아니라, shape 에 맞는 G7 표준 안전 형태로 기입한다
    // (`?.` 체이닝 + 폴백 — 데이터 미도착 시 런타임 에러 방지).
    patch(buildBindingExpression(c.sourceId, c.path, spec.shape));
    setOpen(false);
    setKeyword('');
  };

  return (
    <div data-testid={`g7le-binding-row-${spec.propKey}`} style={rowStyle}>
      <div style={rowHead}>
        <span style={{ fontWeight: 600, fontSize: 12 }}>{label}</span>
        <span style={shapeBadge}>{shapeLabel}</span>
        {spec.required && (
          <span data-testid={`g7le-binding-required-${spec.propKey}`} style={requiredBadge}>
            {t('layout_editor.binding.required')}
          </span>
        )}
      </div>

      {/* 연결 상태 표시 */}
      {isComplexBinding ? (
        <div data-testid={`g7le-binding-complex-${spec.propKey}`} style={complexBadge}>
          {t('layout_editor.binding.complex')}
          <code style={exprCode}>{currentStr}</code>
        </div>
      ) : parsed ? (
        <div style={connectedRow}>
          <code data-testid={`g7le-binding-expr-${spec.propKey}`} style={exprCode}>
            {currentStr}
          </code>
          <button
            type="button"
            data-testid={`g7le-binding-clear-${spec.propKey}`}
            onClick={() => patch(undefined)}
            style={clearBtn}
          >
            {t('layout_editor.binding.clear')}
          </button>
        </div>
      ) : (
        <div data-testid={`g7le-binding-empty-${spec.propKey}`} style={emptyRow}>
          {t('layout_editor.binding.not_connected')}
        </div>
      )}

      {/* array/object 행 필드 미리보기(연결됨일 때) */}
      {parsed && (spec.shape === 'array' || spec.shape === 'object') && spec.itemFields && spec.itemFields.length > 0 && (
        <div style={itemFieldsHint}>
          {t('layout_editor.binding.item_fields')}: {spec.itemFields.join(', ')}
        </div>
      )}

      {/* 검색형 피커 — 복합 바인딩(코드 편집)이 아닐 때만 노출 */}
      {!isComplexBinding && (
        <div style={{ marginTop: 4 }}>
          <button
            type="button"
            data-testid={`g7le-binding-search-toggle-${spec.propKey}`}
            onClick={() => setOpen((v) => !v)}
            style={searchToggle}
          >
            🔍 {t('layout_editor.binding.search')}
          </button>
          {open && (
            <div data-testid={`g7le-binding-picker-${spec.propKey}`} style={pickerBox}>
              <input
                type="text"
                autoFocus
                data-testid={`g7le-binding-search-input-${spec.propKey}`}
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
                      data-testid={`g7le-binding-candidate-${c.expression}`}
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
  );
}

export function DataBindingSection({
  node,
  dataProps,
  candidates,
  t,
  onPatchNode,
}: DataBindingSectionProps): React.ReactElement | null {
  if (!Array.isArray(dataProps) || dataProps.length === 0) return null;
  return (
    <div data-testid="g7le-data-binding-section" style={sectionStyle}>
      <div style={sectionHead}>🔗 {t('layout_editor.binding.section_title')}</div>
      {dataProps.map((dp) => (
        <DataPropRow
          key={dp.propKey}
          node={node}
          spec={dp}
          candidates={candidates}
          t={t}
          onPatchNode={onPatchNode}
        />
      ))}
      <div style={divider} />
    </div>
  );
}

const sectionStyle: React.CSSProperties = { display: 'flex', flexDirection: 'column', marginBottom: 8 };
const sectionHead: React.CSSProperties = { fontSize: 12, fontWeight: 700, color: '#0f172a', marginBottom: 6 };
const rowStyle: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 4, padding: '8px 0' };
const rowHead: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 6 };
const shapeBadge: React.CSSProperties = { fontSize: 10, padding: '1px 6px', borderRadius: 4, background: '#e0e7ff', color: '#3730a3' };
const requiredBadge: React.CSSProperties = { fontSize: 10, padding: '1px 6px', borderRadius: 4, background: '#fef3c7', color: '#92400e' };
const connectedRow: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 6 };
const emptyRow: React.CSSProperties = { fontSize: 11, color: '#94a3b8' };
const exprCode: React.CSSProperties = { fontSize: 11, fontFamily: 'monospace', background: '#f1f5f9', padding: '2px 6px', borderRadius: 4, color: '#0f172a', wordBreak: 'break-all' };
const clearBtn: React.CSSProperties = { fontSize: 11, border: '1px solid #e2e8f0', borderRadius: 4, background: '#fff', color: '#dc2626', padding: '2px 8px', cursor: 'pointer' };
const complexBadge: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 2, fontSize: 11, color: '#64748b' };
const itemFieldsHint: React.CSSProperties = { fontSize: 10, color: '#94a3b8' };
const searchToggle: React.CSSProperties = { fontSize: 11, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#334155', padding: '4px 10px', cursor: 'pointer', textAlign: 'left', width: '100%' };
const pickerBox: React.CSSProperties = { marginTop: 4, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', padding: 6 };
const searchInput: React.CSSProperties = { width: '100%', padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, boxSizing: 'border-box' };
const resultList: React.CSSProperties = { marginTop: 4, maxHeight: 200, overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: 2 };
const noResult: React.CSSProperties = { fontSize: 11, color: '#94a3b8', padding: '8px 0', textAlign: 'center' };
const candidateBtn: React.CSSProperties = { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 8, border: 'none', background: 'transparent', cursor: 'pointer', padding: '6px 8px', borderRadius: 4, textAlign: 'left' };
const candidateMeta: React.CSSProperties = { fontSize: 10, color: '#94a3b8' };
const candidatePreview: React.CSSProperties = { fontSize: 10, color: '#64748b', fontFamily: 'monospace', whiteSpace: 'nowrap' };
const divider: React.CSSProperties = { borderTop: '1px solid #e2e8f0', marginTop: 4 };
