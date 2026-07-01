/**
 * ConditionBuilder.tsx — 표시조건 편집 탭 본체
 *
 * 12.4.3 의 친화 조건(A~H)을 택1 + 파라미터 위젯으로 조립한다. 여러 조건을
 * "그리고"(&&)/"또는"(||)로 결합하고, conditionRecipeEngine 이 최종 결과 전체를
 * **단일 `{{ }}` 한 쌍**으로 합성해 노드 **최상위 `node.if`** 로 출력한다(중첩 보간 금지).
 * `if` 는 DynamicRenderer 가 `effectiveComponentDef.if` 로 조건부 렌더링을 평가하는
 * 위치이며, 번들 레이아웃 규약도 최상위 `if` 다(`props.if` 는 평가되지 않음).
 *
 * 노드의 현재 `if`(최상위, props.if 역호환 폴백)를 parseConditionExpr 로 역해석해 절을
 * 재구성한다. 역해석 불가한 손작성 식은 "직접 작성된 조건"(고급)으로 식 원문을 표시하고
 * 무손실 보존한다(원칙 4.4).
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만.
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import type { EditorSpec, ConditionParamSpec } from '../../spec/specTypes';
import {
  normalizeConditionRecipes,
  combineConditions,
  parseConditionExpr,
  type ConditionClause,
  type ConditionCombinator,
} from '../../spec/conditionRecipeEngine';
import { getWidget } from '../../spec/widgetRegistry';
import { BASE_SCOPE, getEffectiveScopedIf, setScopedIf, type StyleScope } from '../../spec/styleScope';

export interface ConditionBuilderProps {
  node: EditorNode;
  spec: EditorSpec | null;
  t: (key: string, params?: Record<string, string | number>) => string;
  onPatchNode: (patched: EditorNode) => void;
  dataSourceCandidates?: Array<{ value: string; label: string }>;
  stateKeyCandidates?: Array<{ value: string; label: string }>;
  /**
   * 활성 StyleScope. 기본 BASE_SCOPE. 디바이스별 표시조건(D9) — scope≠base 면 표시조건을
   * `responsive[bp].if` 에 읽기/쓰기한다(색 모드는 if 와 무관).
   */
  scope?: StyleScope;
}

function label(raw: string | undefined, t: ConditionBuilderProps['t'], fallback = ''): string {
  if (typeof raw !== 'string') return fallback;
  return raw.startsWith('$t:') ? t(raw.slice(3)) : raw;
}

/**
 * 노드 if 쓰기 — 빈 식이면 제거.
 *
 * `if` 는 노드 **최상위** 속성이다(`node.if`) — DynamicRenderer 가 `effectiveComponentDef.if`
 * 로 조건부 렌더링을 평가하는 위치이며, 번들 레이아웃 2918곳 전부 최상위 `if` 를 쓴다.
 * `props.if` 에 두면 공개 렌더러가 평가하지 않아 조건이 무시된다(비로그인에서
 * `is_admin` 조건 요소가 그대로 노출).
 */
function writeIf(node: EditorNode, ifExpr: string, scope: StyleScope): EditorNode {
  // scope≠base — 디바이스별 표시조건은 responsive[bp].if 로(D9). 레거시 props.if 정리는
  // base 에만 적용(responsive 브랜치엔 레거시 버그 없음).
  if (scope.breakpoint !== 'base') {
    return setScopedIf(node, scope, ifExpr.trim());
  }
  const next = { ...node };
  // 과거 잘못 저장된 props.if 가 있으면 함께 정리(최상위로 이관·제거).
  if (next.props && typeof next.props === 'object' && 'if' in next.props) {
    const props = { ...(next.props as Record<string, unknown>) };
    delete props.if;
    next.props = props;
  }
  if (ifExpr.trim() === '') delete next.if;
  else next.if = ifExpr;
  return next;
}

export function ConditionBuilder({
  node,
  spec,
  t,
  onPatchNode,
  dataSourceCandidates,
  stateKeyCandidates,
  scope = BASE_SCOPE,
}: ConditionBuilderProps): React.ReactElement {
  const operators = React.useMemo(
    () => normalizeConditionRecipes(spec?.conditionRecipes),
    [spec],
  );

  // 최상위 `node.if` 가 정본. 과거 잘못 저장된 `props.if` 는 역호환 폴백으로 읽어
  // 빌더에서 재구성·정정 가능하게 한다(writeIf 가 최상위로 이관·정리).
  //
  // scope≠base 면 디바이스 유효값을 읽는다 — `responsive[bp].if` override 가 있으면
  // 그 값, 없으면 base `node.if` 로 상속 폴백(getEffectiveScopedIf). base 에 정의된
  // 표시조건이 디바이스 세부탭(PC/태블릿/모바일)에서 빈 빌더로 가려지던 결함 수정
  // 디바이스 탭에서 편집하면 writeIf 가 그 디바이스 override 로 기록한다.
  const currentIf =
    scope.breakpoint !== 'base'
      ? getEffectiveScopedIf(node, scope)
      : ((node as Record<string, unknown>).if ??
        (node.props as Record<string, unknown> | undefined)?.if);
  // 빌더는 **무상태** — 절/결합자를 매 렌더 노드의 `if` 에서 재구성한다(parseConditionExpr).
  // 속성 모달이 패치마다 content 를 재마운트하므로 useState 는 유실된다(ditorModalContext
  // idempotent re-open). 노드를 단일 진실로 두어 remount 후에도 편집 상태가 보존된다.
  const parsed = React.useMemo(() => parseConditionExpr(currentIf, operators), [currentIf, operators]);
  const clauses: ConditionClause[] = parsed?.clauses ?? [];
  const combinator: ConditionCombinator = parsed?.combinator ?? 'and';
  // 고급(직접 작성) — if 가 있는데 빌더 형식으로 역해석 안 됨
  const isAdvanced = typeof currentIf === 'string' && currentIf.trim() !== '' && parsed === null;

  if (operators.length === 0) {
    return (
      <div data-testid="g7le-condition-no-recipes" style={emptyNotice}>
        {t('layout_editor.visibility.no_recipes')}
      </div>
    );
  }

  // 모든 변경은 노드의 `if` 로 직접 커밋(무상태) — 다음 렌더에서 parseConditionExpr 가
  // 노드에서 절을 재구성한다. local state 미사용으로 modal remount 후에도 보존.
  const commit = (nextClauses: ConditionClause[], nextCombinator: ConditionCombinator): void => {
    onPatchNode(writeIf(node, combineConditions(nextClauses, nextCombinator, operators), scope));
  };

  const updateClause = (idx: number, next: ConditionClause): void => {
    commit(clauses.map((c, i) => (i === idx ? next : c)), combinator);
  };

  const removeClause = (idx: number): void => {
    commit(clauses.filter((_, i) => i !== idx), combinator);
  };

  const addClause = (combine: ConditionCombinator): void => {
    const first = operators[0];
    commit([...clauses, { operator: first.value, params: {} }], combine);
  };

  const candidatesFor = (widget: string | undefined): Array<{ value: string; label: string }> | undefined => {
    if (widget === 'datasource-picker') return dataSourceCandidates;
    if (widget === 'state-key-picker') return stateKeyCandidates;
    return undefined;
  };

  if (isAdvanced) {
    return (
      <div className="g7le-condition-advanced" data-testid="g7le-condition-advanced" style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
        <div style={advancedNotice}>{t('layout_editor.visibility.advanced_preserved')}</div>
        <code data-testid="g7le-condition-advanced-expr" style={exprCode}>{String(currentIf)}</code>
        <button
          type="button"
          data-testid="g7le-condition-clear-advanced"
          onClick={() => onPatchNode(writeIf(node, '', scope))}
          style={clearBtn}
        >
          {t('layout_editor.visibility.clear')}
        </button>
      </div>
    );
  }

  const previewExpr = combineConditions(clauses, combinator, operators);

  return (
    <div className="g7le-condition-builder" data-testid="g7le-condition-builder" style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
      <div style={builderHint}>{t('layout_editor.visibility.show_when')}</div>

      {clauses.map((clause, idx) => {
        const op = operators.find((o) => o.value === clause.operator) ?? operators[0];
        return (
          <div key={idx} className="g7le-condition-clause" data-testid={`g7le-condition-clause-${idx}`} style={clauseBox}>
            <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
              {idx > 0 && (
                <span data-testid={`g7le-condition-combinator-${idx}`} style={combinatorChip}>
                  {combinator === 'or' ? t('layout_editor.visibility.or') : t('layout_editor.visibility.and')}
                </span>
              )}
              <select
                data-testid={`g7le-condition-select-${idx}`}
                value={clause.operator}
                onChange={(e) => updateClause(idx, { operator: e.target.value, params: {} })}
                style={conditionSelect}
              >
                {operators.map((o) => (
                  <option key={o.value} value={o.value}>
                    {label(o.label, t, o.value)}
                  </option>
                ))}
              </select>
              <button
                type="button"
                data-testid={`g7le-condition-remove-${idx}`}
                aria-label={t('layout_editor.visibility.remove')}
                onClick={() => removeClause(idx)}
                style={removeBtn}
              >
                ✕
              </button>
            </div>
            {op.params.map((param: ConditionParamSpec) => {
              const Widget = getWidget(param.widget);
              return (
                <div key={param.key} className="g7le-condition-param" data-testid={`g7le-condition-param-${idx}-${param.key}`} style={paramRow}>
                  <span style={paramLabelStyle}>{label(param.label, t, param.key)}</span>
                  <div style={{ flex: 1 }}>
                    {Widget ? (
                      <Widget
                        control={{ ...param }}
                        value={clause.params[param.key]}
                        onChange={(v) =>
                          updateClause(idx, {
                            operator: clause.operator,
                            params: { ...clause.params, [param.key]: v === undefined ? '' : String(v) },
                          })
                        }
                        t={t}
                        candidates={candidatesFor(param.widget)}
                      />
                    ) : (
                      <span style={{ fontSize: 11, color: '#94a3b8' }}>{t('layout_editor.property_modal.unsupported_widget')}</span>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        );
      })}

      <div style={{ display: 'flex', gap: 6 }}>
        <button type="button" data-testid="g7le-condition-add-and" onClick={() => addClause('and')} style={addBtn}>
          + {t('layout_editor.visibility.and')}
        </button>
        <button type="button" data-testid="g7le-condition-add-or" onClick={() => addClause('or')} style={addBtn}>
          + {t('layout_editor.visibility.or')}
        </button>
      </div>

      {previewExpr !== '' && (
        <div data-testid="g7le-condition-preview" style={previewBox}>
          <span style={{ fontSize: 11, color: '#64748b' }}>{t('layout_editor.visibility.preview')}</span>
          <code style={exprCode}>{previewExpr}</code>
        </div>
      )}
    </div>
  );
}

const emptyNotice: React.CSSProperties = { fontSize: 12, color: '#94a3b8', padding: '16px 0', textAlign: 'center' };
const builderHint: React.CSSProperties = { fontSize: 12, fontWeight: 600, color: '#0f172a' };
const clauseBox: React.CSSProperties = { border: '1px solid #e2e8f0', borderRadius: 8, padding: 10, display: 'flex', flexDirection: 'column', gap: 8, background: '#f8fafc' };
const conditionSelect: React.CSSProperties = { flex: 1, padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff' };
const combinatorChip: React.CSSProperties = { fontSize: 11, fontWeight: 700, color: '#2563eb', background: '#dbeafe', padding: '2px 8px', borderRadius: 999 };
const removeBtn: React.CSSProperties = { border: 'none', background: 'transparent', color: '#94a3b8', cursor: 'pointer', fontSize: 12 };
const addBtn: React.CSSProperties = { padding: '5px 12px', fontSize: 12, border: '1px dashed #94a3b8', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer' };
const paramRow: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 8 };
const paramLabelStyle: React.CSSProperties = { fontSize: 11, color: '#64748b', minWidth: 64 };
const previewBox: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 4, padding: 10, background: '#f1f5f9', borderRadius: 6 };
const exprCode: React.CSSProperties = { fontSize: 11, color: '#0f172a', fontFamily: 'monospace', wordBreak: 'break-all' };
const advancedNotice: React.CSSProperties = { fontSize: 11, color: '#92400e', background: '#fef3c7', padding: '6px 8px', borderRadius: 6 };
const clearBtn: React.CSSProperties = { padding: '5px 12px', fontSize: 12, border: '1px solid #fecaca', borderRadius: 6, background: '#fff', color: '#dc2626', cursor: 'pointer', alignSelf: 'flex-start' };
