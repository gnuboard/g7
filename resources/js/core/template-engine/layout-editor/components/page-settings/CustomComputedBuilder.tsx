/**
 * CustomComputedBuilder.tsx — "직접 만들기" 3단계 고정 틀 빌더
 *
 * `① 어떤 데이터 → ② 무엇을(7동사 + 조건) → ③ 결과 이름` 조립식 빌더. computedRecipes 프리셋
 * 과 무관하게 항상 동작하는 코어 제공 빌더(-34). `computedRecipeEngine.buildCustomComputedExpr`
 * 가 동사별 expr 을 생성하고 `matchCustomComputed` 가 역해석한다(7동사 SSoT = 표).
 *
 * ② 조건은 `필드 비교 값` 한 줄, `[+ 조건]` 으로 AND 다중(틀 내 한정 — 중첩 OR/삼항/cascade
 * 는 틀 밖 [고급]). 미리보기(ComputedPreview)는 호스트가 렌더 — 본 빌더는 모델/식만 만든다.
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만.
 *
 * @since engine-v1.50.0
 */

import React, { useCallback } from 'react';
import {
  buildCustomComputedExpr,
  type CustomComputedModel,
  type CustomOp,
  type CustomCmp,
  type CustomCond,
} from '../../spec/computedRecipeEngine';
import { getWidget } from '../../spec/widgetRegistry';
import { DataChipValueInput } from './DataChipValueInput';
import type { BindingCandidate } from '../../spec/bindingCandidates';

export interface CustomComputedBuilderProps {
  /** 현재 모델 */
  model: CustomComputedModel;
  /** 모델 변경 — 호스트가 buildCustomComputedExpr 로 식 생성 + computed[key] 패치 */
  onChange: (model: CustomComputedModel) => void;
  /** 다국어 해석 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** ① 데이터 후보(binding-picker/datasource-picker select 용 `{value,label}[]`) */
  dataSourceCandidates?: Array<{ value: string; label: string }>;
  /**
   * 데이터 칩 후보 풀. 합산/옵션/조건/
   * literal/firstOf 폴백 등 값·경로 입력의 DataChipValueInput 검색 피커가 쓴다. 미전달 시 데이터
   * 검색 칩만 숨고 평문/표현식 입력은 동작(디그레이드).
   */
  bindingCandidates?: BindingCandidate[];
  /** 결과 이름 중복 검사(이미 존재하는 키) */
  existingKeys?: string[];
}

/** 7동사 + 라벨 키 */
const OPS: Array<{ op: CustomOp; labelKey: string }> = [
  { op: 'count', labelKey: 'layout_editor.computed.op_count' },
  { op: 'sum', labelKey: 'layout_editor.computed.op_sum' },
  { op: 'filter', labelKey: 'layout_editor.computed.op_filter' },
  { op: 'toOptions', labelKey: 'layout_editor.computed.op_to_options' },
  { op: 'nth', labelKey: 'layout_editor.computed.op_nth' },
  { op: 'firstOf', labelKey: 'layout_editor.computed.op_first_of' },
  { op: 'literal', labelKey: 'layout_editor.computed.op_literal' },
];

/** 비교 연산자 목록 */
const CMPS: CustomCmp[] = ['=', '!=', '>', '<', '>=', '<=', 'includes'];

/** 조건을 쓰는 동사 */
const USES_CONDITIONS = new Set<CustomOp>(['count', 'sum', 'filter']);

/**
 * "직접 만들기" 3단계 빌더.
 *
 * @param props CustomComputedBuilderProps
 * @return 빌더 엘리먼트
 */
export function CustomComputedBuilder({
  model,
  onChange,
  t,
  dataSourceCandidates,
  bindingCandidates,
  existingKeys = [],
}: CustomComputedBuilderProps): React.ReactElement {
  const patch = useCallback(
    (mut: (m: CustomComputedModel) => void): void => {
      const next: CustomComputedModel = { ...model };
      mut(next);
      onChange(next);
    },
    [model, onChange],
  );

  const conditions = model.conditions ?? [];
  const SourceWidget = getWidget('datasource-picker');
  const keyDuplicate = model.key !== '' && existingKeys.includes(model.key);

  const addCondition = (): void =>
    patch((m) => { m.conditions = [...(m.conditions ?? []), { field: '', cmp: '=', value: '' }]; });
  const patchCondition = (i: number, mut: (c: CustomCond) => void): void =>
    patch((m) => {
      const next = (m.conditions ?? []).slice();
      const c = { ...next[i] };
      mut(c);
      next[i] = c;
      m.conditions = next;
    });
  const removeCondition = (i: number): void =>
    patch((m) => { m.conditions = (m.conditions ?? []).filter((_, idx) => idx !== i); });

  return (
    <div className="g7le-computed-custom" data-testid="g7le-computed-custom" style={box}>
      {/* ① 어떤 데이터 */}
      <Step label={`① ${t('layout_editor.computed.custom_step1')}`}>
        <div data-testid="g7le-computed-custom-source">
          {SourceWidget ? (
            <SourceWidget
              control={{}}
              value={model.source}
              onChange={(v) => patch((m) => { m.source = typeof v === 'string' ? v : undefined; })}
              t={t}
              candidates={dataSourceCandidates}
            />
          ) : (
            <input
              type="text"
              value={model.source ?? ''}
              onChange={(e) => patch((m) => { m.source = e.target.value; })}
              style={input}
            />
          )}
        </div>
      </Step>

      {/* ② 무엇을 */}
      <Step label={`② ${t('layout_editor.computed.custom_step2')}`}>
        <select
          data-testid="g7le-computed-custom-op"
          value={model.op}
          onChange={(e) => patch((m) => { m.op = e.target.value as CustomOp; })}
          style={input}
        >
          {OPS.map(({ op, labelKey }) => (
            <option key={op} value={op}>
              {t(labelKey)}
            </option>
          ))}
        </select>

        {/* sum 의 합산 필드 — 값/경로 칸이라 데이터 칩(검색 + 표현식 + 평문). . */}
        {model.op === 'sum' ? (
          <div data-testid="g7le-computed-custom-sumfield" style={{ minWidth: 0 }}>
            <DataChipValueInput
              value={model.sumField ?? ''}
              onChange={(v) => patch((m) => { m.sumField = v; })}
              t={t}
              candidates={bindingCandidates}
              placeholder={t('layout_editor.computed.custom_sum_field')}
              testidPrefix="g7le-computed-custom-sumfield-chip"
            />
          </div>
        ) : null}

        {/* toOptions 의 value/label 필드 — 값/경로 칩 */}
        {model.op === 'toOptions' ? (
          <div style={{ display: 'flex', gap: 6 }}>
            <div data-testid="g7le-computed-custom-valuefield" style={{ flex: 1, minWidth: 0 }}>
              <DataChipValueInput value={model.valueField ?? ''} onChange={(v) => patch((m) => { m.valueField = v; })} t={t} candidates={bindingCandidates} placeholder={t('layout_editor.computed.custom_value_field')} testidPrefix="g7le-computed-custom-valuefield-chip" />
            </div>
            <div data-testid="g7le-computed-custom-labelfield" style={{ flex: 1, minWidth: 0 }}>
              <DataChipValueInput value={model.labelField ?? ''} onChange={(v) => patch((m) => { m.labelField = v; })} t={t} candidates={bindingCandidates} placeholder={t('layout_editor.computed.custom_label_field')} testidPrefix="g7le-computed-custom-labelfield-chip" />
            </div>
          </div>
        ) : null}

        {/* nth 의 인덱스(숫자 — 평문 유지) + 속성(값/경로 칩) */}
        {model.op === 'nth' ? (
          <div style={{ display: 'flex', gap: 6 }}>
            <input type="text" data-testid="g7le-computed-custom-index" value={model.index ?? ''} placeholder="0" onChange={(e) => patch((m) => { m.index = e.target.value; })} style={input} />
            <div data-testid="g7le-computed-custom-prop" style={{ flex: 1, minWidth: 0 }}>
              <DataChipValueInput value={model.prop ?? ''} onChange={(v) => patch((m) => { m.prop = v; })} t={t} candidates={bindingCandidates} placeholder={t('layout_editor.computed.custom_prop')} testidPrefix="g7le-computed-custom-prop-chip" />
            </div>
          </div>
        ) : null}

        {/* literal 의 값(값 칩) + 종류 */}
        {model.op === 'literal' ? (
          <div style={{ display: 'flex', gap: 6 }}>
            <select data-testid="g7le-computed-custom-literalkind" value={model.literalKind ?? 'string'} onChange={(e) => patch((m) => { m.literalKind = e.target.value as 'string' | 'number' | 'boolean'; })} style={input}>
              <option value="string">{t('layout_editor.computed.kind_string')}</option>
              <option value="number">{t('layout_editor.computed.kind_number')}</option>
              <option value="boolean">{t('layout_editor.computed.kind_boolean')}</option>
            </select>
            <div data-testid="g7le-computed-custom-literalvalue" style={{ flex: 1, minWidth: 0 }}>
              <DataChipValueInput value={model.literalValue ?? ''} onChange={(v) => patch((m) => { m.literalValue = v; })} t={t} candidates={bindingCandidates} testidPrefix="g7le-computed-custom-literalvalue-chip" />
            </div>
          </div>
        ) : null}

        {/* firstOf 의 후보(쉼표 목록 — 다중 경로, 평문 유지) + 기본값(값 칩) */}
        {model.op === 'firstOf' ? (
          <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
            <input type="text" data-testid="g7le-computed-custom-candidates" value={(model.candidates ?? []).join(', ')} placeholder={t('layout_editor.computed.custom_candidates')} onChange={(e) => patch((m) => { m.candidates = e.target.value.split(',').map((s) => s.trim()).filter(Boolean); })} style={input} />
            <div data-testid="g7le-computed-custom-fallback" style={{ minWidth: 0 }}>
              <DataChipValueInput value={model.fallback ?? ''} onChange={(v) => patch((m) => { m.fallback = v; })} t={t} candidates={bindingCandidates} placeholder={t('layout_editor.computed.custom_fallback')} testidPrefix="g7le-computed-custom-fallback-chip" />
            </div>
          </div>
        ) : null}

        {/* 조건(count/sum/filter) — 필드/값 모두 값·경로 칩 */}
        {USES_CONDITIONS.has(model.op) ? (
          <div style={condBox}>
            {conditions.map((c, i) => (
              <div key={i} data-testid={`g7le-computed-custom-cond-${i}`} style={condRow}>
                <div data-testid={`g7le-computed-custom-cond-field-${i}`} style={{ flex: 1, minWidth: 0 }}>
                  <DataChipValueInput value={c.field} onChange={(v) => patchCondition(i, (cc) => { cc.field = v; })} t={t} candidates={bindingCandidates} placeholder={t('layout_editor.computed.cond_field')} testidPrefix={`g7le-computed-custom-cond-field-${i}-chip`} />
                </div>
                <select value={c.cmp} onChange={(e) => patchCondition(i, (cc) => { cc.cmp = e.target.value as CustomCmp; })} style={condCmp}>
                  {CMPS.map((cmp) => <option key={cmp} value={cmp}>{cmp}</option>)}
                </select>
                <div data-testid={`g7le-computed-custom-cond-value-${i}`} style={{ flex: 1, minWidth: 0 }}>
                  <DataChipValueInput value={c.value} onChange={(v) => patchCondition(i, (cc) => { cc.value = v; })} t={t} candidates={bindingCandidates} placeholder={t('layout_editor.computed.cond_value')} testidPrefix={`g7le-computed-custom-cond-value-${i}-chip`} />
                </div>
                <button type="button" data-testid={`g7le-computed-custom-cond-remove-${i}`} onClick={() => removeCondition(i)} style={condRemove}>✕</button>
              </div>
            ))}
            <button type="button" data-testid="g7le-computed-custom-cond-add" onClick={addCondition} style={addCondBtn}>
              + {t('layout_editor.computed.cond_add')}
            </button>
          </div>
        ) : null}
      </Step>

      {/* ③ 결과 이름 */}
      <Step label={`③ ${t('layout_editor.computed.custom_step3')}`}>
        <input
          type="text"
          data-testid="g7le-computed-custom-key"
          value={model.key}
          onChange={(e) => patch((m) => { m.key = e.target.value; })}
          style={{ ...input, ...(keyDuplicate ? inputError : {}) }}
        />
        {keyDuplicate ? (
          <p data-testid="g7le-computed-custom-key-dup" style={dupWarn}>
            ⚠ {t('layout_editor.computed.key_duplicate')}
          </p>
        ) : null}
      </Step>
    </div>
  );
}

/** 단계 라벨 + 본체 */
function Step({ label, children }: { label: string; children: React.ReactNode }): React.ReactElement {
  return (
    <div style={stepWrap}>
      <div style={stepLabel}>{label}</div>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 6, minWidth: 0 }}>{children}</div>
    </div>
  );
}

/** 모델 → computed 식(`{{ }}` 한 쌍) 헬퍼 — 호스트가 사용 */
export function modelToExpr(model: CustomComputedModel): string {
  return buildCustomComputedExpr(model);
}

const box: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 12, padding: 10, border: '1px solid #cbd5e1', borderRadius: 8, background: '#fff' };
const stepWrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 4, minWidth: 0 };
const stepLabel: React.CSSProperties = { fontSize: 12, fontWeight: 600, color: '#475569' };
const input: React.CSSProperties = { padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, minWidth: 0, width: '100%', boxSizing: 'border-box' };
const inputError: React.CSSProperties = { border: '1px solid #ef4444' };
const condBox: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6, marginTop: 4 };
const condRow: React.CSSProperties = { display: 'flex', gap: 4, alignItems: 'center', minWidth: 0 };
const condInput: React.CSSProperties = { flex: 1, minWidth: 0, padding: '4px 6px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6 };
const condCmp: React.CSSProperties = { padding: '4px 6px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6 };
const condRemove: React.CSSProperties = { border: 'none', background: 'transparent', color: '#94a3b8', cursor: 'pointer', fontSize: 12 };
const addCondBtn: React.CSSProperties = { alignSelf: 'flex-start', padding: '2px 8px', fontSize: 11, border: '1px dashed #94a3b8', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer' };
const dupWarn: React.CSSProperties = { margin: 0, fontSize: 11, color: '#b45309' };
