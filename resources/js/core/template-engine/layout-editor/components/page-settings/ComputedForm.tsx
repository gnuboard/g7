/**
 * ComputedForm.tsx — [자동 계산] 탭 본체
 *
 * 레이아웃 최상위 `computed`(키→식)를 친화 편집한다. 각 키는 (1) computedRecipes 프리셋 역해석
 * (matchComputed) → 1급 보기 카드, (2) 3단계 틀 역해석(matchCustomComputed) → 직접만들기 카드,
 * (3) 둘 다 실패 → [고급] 보존(키명 + 샘플 평가값, 무손실). `resolveComputedCard` SSoT.
 *
 * 추가 = 9종 프리셋(자주 쓰는/그 밖에 그룹, `__source` 제공자 배지) + "직접 만들기"(코어 제공,
 * computedRecipes 무관 항상 노출). 미리보기(ComputedPreview)는 샘플 컨텍스트로 평가(결과값+
 * 타입/에러 전환). 부모/자식 상속(`__computedSource`): 부모 키 〔공통〕배지 + 편집 가능(덮어쓰기
 * 안내) + 되돌리기(init_actions 와 반대, shallow merge 라 부모 키도 편집).
 *
 * 본 폼은 prop 주도 — 셸이 computed 값·recipes·샘플컨텍스트·후보·source 맵을 주입한다.
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만.
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useMemo, useState } from 'react';
import {
  normalizeComputedRecipes,
  resolveComputedCard,
  buildComputedExpr,
  type NormalizedComputedRecipe,
} from '../../spec/computedRecipeEngine';
import type { ComputedRecipeSpec, ConditionParamSpec } from '../../spec/specTypes';
import type { RecipeSource } from '../../spec/editorSpecLoader';
import type { BindingContext } from '../../DataBindingEngine';
import { getWidget } from '../../spec/widgetRegistry';
import { ComputedPreview } from './ComputedPreview';
import { CustomComputedBuilder, modelToExpr } from './CustomComputedBuilder';
import type { CustomComputedModel } from '../../spec/computedRecipeEngine';
import { DataChipValueInput } from './DataChipValueInput';
import type { BindingCandidate } from '../../spec/bindingCandidates';

export interface ComputedFormProps {
  /** 편집 중 computed 객체(병합본 — 상속 시 부모+자식) */
  computed: Record<string, string>;
  /** 변경 콜백 — 호스트가 patchDocumentRaw('computed', …) */
  onChange: (next: Record<string, string>) => void;
  /** 친화 보기 스펙(getComputedRecipes) */
  recipes?: Record<string, ComputedRecipeSpec>;
  /** 다국어 해석 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 미리보기 샘플 평가 컨텍스트 */
  sampleContext: BindingContext;
  /** ① 데이터/param 후보 (datasource-picker select 용 `{value,label}[]`) */
  dataSourceCandidates?: Array<{ value: string; label: string }>;
  /**
   * 데이터 칩 후보 풀. preset 파라미터·
   * 직접만들기(literal/firstOf/조건 등) 값 칸의 DataChipValueInput 검색 피커가 쓴다. 미전달 시
   * 데이터 검색 칩만 숨고 평문/표현식 입력은 동작(디그레이드).
   */
  bindingCandidates?: BindingCandidate[];
  /** 출처 맵(`__computedSource`: key→'base'|'route'|'route-override') — 상속 표기 */
  computedSource?: Record<string, string>;
  /** UI 효과형 판정 키 집합(화면효과 토글 — boolean 계산값) */
  effectKeys?: string[];
  /**
   * 덮은 키(`'route-override'`)의 공통값 되돌리기 — 자식 정의만 제거(부모 값 재노출).
   * 셸이 `__editor.original.computed` 에서 해당 키만 제거하고 병합본에서 부모 식으로
   * 복원하는 책임을 진다(병합본 전체 patch 인 onChange 와 분리). 미전달 시 되돌리기
   * 버튼 미노출(독립 RTL 디그레이드).
   */
  onRevert?: (key: string) => void;
}

/** `$t:` 라벨 해석 */
function label(raw: string | undefined, t: ComputedFormProps['t'], fallback: string): string {
  if (typeof raw !== 'string') return fallback;
  return raw.startsWith('$t:') ? t(raw.slice(3)) : raw;
}

/** 레시피 출처 추출 */
function recipeSource(raw: ComputedRecipeSpec | undefined): RecipeSource | null {
  if (raw && typeof raw === 'object') {
    const s = (raw as Record<string, unknown>).__source;
    if (s && typeof s === 'object') return s as RecipeSource;
  }
  return null;
}

/**
 * [자동 계산] 탭 폼.
 *
 * @param props ComputedFormProps
 * @return 자동 계산 폼 엘리먼트
 */
export function ComputedForm({
  computed,
  onChange,
  recipes,
  t,
  sampleContext,
  dataSourceCandidates,
  bindingCandidates,
  computedSource = {},
  effectKeys = [],
  onRevert,
}: ComputedFormProps): React.ReactElement {
  const normalizedRecipes = useMemo(() => normalizeComputedRecipes(recipes), [recipes]);
  const [adding, setAdding] = useState(false);
  const [customModel, setCustomModel] = useState<CustomComputedModel | null>(null);

  const keys = Object.keys(computed);

  const patchKey = useCallback(
    (key: string, expr: string): void => {
      onChange({ ...computed, [key]: expr });
    },
    [computed, onChange],
  );

  const removeKey = useCallback(
    (key: string): void => {
      const next = { ...computed };
      delete next[key];
      onChange(next);
    },
    [computed, onChange],
  );

  const renameAndSet = useCallback(
    (oldKey: string | null, newKey: string, expr: string): void => {
      const next = { ...computed };
      if (oldKey && oldKey !== newKey) delete next[oldKey];
      next[newKey] = expr;
      onChange(next);
    },
    [computed, onChange],
  );

  const addPreset = useCallback(
    (recipe: NormalizedComputedRecipe): void => {
      // 빈 입력으로 식 생성 + 임시 키(보기 id 기반) — 편집에서 채움.
      const expr = buildComputedExpr(recipe, {});
      let key = recipe.id;
      let n = 1;
      while (key in computed) key = `${recipe.id}${++n}`;
      onChange({ ...computed, [key]: expr });
      setAdding(false);
    },
    [computed, onChange],
  );

  const commitCustom = useCallback(() => {
    if (!customModel || !customModel.key) return;
    onChange({ ...computed, [customModel.key]: modelToExpr(customModel) });
    setCustomModel(null);
    setAdding(false);
  }, [customModel, computed, onChange]);

  return (
    <div className="g7le-computed-form" data-testid="g7le-computed-form" style={form}>
      <p style={heading}>{t('layout_editor.page_settings.computed.heading')}</p>

      {keys.length > 0 ? (
        keys.map((key) => (
          <ComputedCard
            key={key}
            computedKey={key}
            expr={computed[key]}
            recipes={normalizedRecipes}
            rawRecipes={recipes}
            t={t}
            sampleContext={sampleContext}
            dataSourceCandidates={dataSourceCandidates}
            bindingCandidates={bindingCandidates}
            source={computedSource[key]}
            isEffect={effectKeys.includes(key)}
            existingKeys={keys}
            onPatchExpr={(expr) => patchKey(key, expr)}
            onRename={(newKey, expr) => renameAndSet(key, newKey, expr)}
            onRemove={() => removeKey(key)}
            onRevert={onRevert ? () => onRevert(key) : undefined}
          />
        ))
      ) : (
        <p data-testid="g7le-computed-empty" style={emptyHint}>{t('layout_editor.computed.empty')}</p>
      )}

      {/* 추가 */}
      <div style={{ minWidth: 0 }}>
        <button type="button" data-testid="g7le-computed-add" onClick={() => setAdding((v) => !v)} style={addBtn}>
          + {t('layout_editor.computed.add')} ▾
        </button>
        {adding ? (
          <div data-testid="g7le-computed-add-list" style={addBox}>
            {/* 9종 프리셋 — common/more 그룹 */}
            {(['common', 'more'] as const).map((group) => {
              const groupRecipes = normalizedRecipes.filter((r) => (r.group ?? 'more') === group);
              if (groupRecipes.length === 0) return null;
              return (
                <div key={group}>
                  <div style={groupTitle}>━━ {t(`layout_editor.computed.group_${group}`)} ━━</div>
                  {groupRecipes.map((r) => {
                    const src = recipeSource(recipes?.[r.id]);
                    return (
                      <button key={r.id} type="button" data-testid={`g7le-computed-preset-${r.id}`} onClick={() => addPreset(r)} style={presetItem}>
                        <span style={presetLabel}>{label(r.label, t, r.id)}</span>
                        {src && src.kind !== 'core' ? (
                          <span data-testid={`g7le-computed-preset-source-${r.id}`} style={badge}>〔{src.id ?? src.kind}〕</span>
                        ) : null}
                      </button>
                    );
                  })}
                </div>
              );
            })}
            {/* 직접 만들기 — 코어 제공 항상 노출 */}
            <div style={{ borderTop: '1px solid #e2e8f0', marginTop: 6, paddingTop: 6 }}>
              <button
                type="button"
                data-testid="g7le-computed-custom-open"
                onClick={() => setCustomModel(customModel ? null : { key: '', op: 'count', source: '', conditions: [] })}
                style={customOpenBtn}
              >
                ⚙ {t('layout_editor.computed.custom_open')} ▾
              </button>
              {customModel ? (
                <div style={{ marginTop: 6 }}>
                  <CustomComputedBuilder
                    model={customModel}
                    onChange={setCustomModel}
                    t={t}
                    dataSourceCandidates={dataSourceCandidates}
                    bindingCandidates={bindingCandidates}
                    existingKeys={keys}
                  />
                  <div data-testid="g7le-computed-custom-preview" style={{ marginTop: 6 }}>
                    <ComputedPreview expr={modelToExpr(customModel)} sampleContext={sampleContext} t={t} testIdPrefix="g7le-computed-custom-preview-eval" />
                  </div>
                  <button type="button" data-testid="g7le-computed-custom-commit" disabled={!customModel.key || keys.includes(customModel.key)} onClick={commitCustom} style={commitBtn}>
                    {t('layout_editor.computed.custom_add')}
                  </button>
                </div>
              ) : null}
            </div>
          </div>
        ) : null}
      </div>
    </div>
  );
}

/** 한 computed 키 카드 */
function ComputedCard({
  computedKey,
  expr,
  recipes,
  rawRecipes,
  t,
  sampleContext,
  dataSourceCandidates,
  bindingCandidates,
  source,
  isEffect,
  existingKeys,
  onPatchExpr,
  onRename,
  onRemove,
  onRevert,
}: {
  computedKey: string;
  expr: string;
  recipes: NormalizedComputedRecipe[];
  rawRecipes?: Record<string, ComputedRecipeSpec>;
  t: ComputedFormProps['t'];
  sampleContext: BindingContext;
  dataSourceCandidates?: Array<{ value: string; label: string }>;
  bindingCandidates?: BindingCandidate[];
  source?: string;
  isEffect: boolean;
  existingKeys: string[];
  onPatchExpr: (expr: string) => void;
  onRename: (newKey: string, expr: string) => void;
  onRemove: () => void;
  onRevert?: () => void;
}): React.ReactElement {
  const [editing, setEditing] = useState(false);
  const [showCode, setShowCode] = useState(false);
  const card = useMemo(() => resolveComputedCard(expr, recipes), [expr, recipes]);
  const isBase = source === 'base';
  // 'route-override' = 부모+자식 동시 선언(자식이 덮음). 순수 자식('route')과 구분 —
  // 〔이 페이지에서 덮음〕 승격 배지 + 공통값 되돌리기(자식 정의만 제거 → 부모 재노출).
  const isOverride = source === 'route-override';
  const advanced = card.kind === 'advanced';

  const recipe = card.kind === 'preset' ? recipes.find((r) => r.id === card.recipeId) ?? null : null;
  const title = recipe ? label(recipe.label, t, computedKey) : computedKey;

  // preset params 편집 → buildComputedExpr 재생성.
  const patchParam = (paramKey: string, value: string): void => {
    if (card.kind !== 'preset' || !recipe) return;
    const nextParams = { ...card.params, [paramKey]: value };
    onPatchExpr(buildComputedExpr(recipe, nextParams));
  };

  return (
    <div data-testid={`g7le-computed-item-${computedKey}`} style={cardStyle}>
      <div style={cardHead}>
        <span style={cardTitle}>{title}</span>
        <code style={keyCode}>{computedKey}</code>
        {isBase ? <span data-testid={`g7le-computed-source-${computedKey}`} style={baseBadge}>〔{t('layout_editor.computed.source_base')}〕</span> : null}
        {isOverride ? <span data-testid={`g7le-computed-overridden-${computedKey}`} style={overriddenBadge}>〔{t('layout_editor.computed.source_overridden')}〕</span> : null}
        {advanced ? <span data-testid="g7le-computed-advanced" style={advBadge}>[{t('layout_editor.action.advanced')}]</span> : null}
        <span style={{ flex: 1 }} />
        {/* 코드(원본 식) 보기 — 모든 카드(친화·고급) 공통. 고급 카드는 친화 환원이 안 돼
            결과값만 보이므로 원본 식 확인 경로가 이것뿐이다. */}
        <button
          type="button"
          data-testid={`g7le-computed-code-${computedKey}`}
          onClick={() => setShowCode((v) => !v)}
          style={iconBtn}
          aria-label={t('layout_editor.value_tree.show_source')}
          title={t('layout_editor.value_tree.show_source')}
        >
          {'</>'}
        </button>
        {!advanced ? (
          <button type="button" data-testid={`g7le-computed-edit-${computedKey}`} onClick={() => setEditing((v) => !v)} style={iconBtn}>
            {t('layout_editor.init_actions.edit')}
          </button>
        ) : null}
        <button type="button" data-testid={`g7le-computed-remove-${computedKey}`} onClick={onRemove} style={iconBtn} aria-label={t('layout_editor.init_actions.remove')}>✕</button>
      </div>

      {showCode ? (
        <pre data-testid={`g7le-computed-code-block-${computedKey}`} style={codeBlock}>{expr}</pre>
      ) : null}

      <div data-testid={`g7le-computed-preview-${computedKey}`}>
        <ComputedPreview expr={expr} computedKey={computedKey} sampleContext={sampleContext} t={t} isEffect={isEffect} testIdPrefix={`g7le-computed-preview-${computedKey}-eval`} />
      </div>

      {isOverride && onRevert ? (
        <div style={revertRow}>
          <span style={revertHint}>ⓘ {t('layout_editor.computed.overridden_hint')}</span>
          <button type="button" data-testid={`g7le-computed-revert-${computedKey}`} onClick={onRevert} style={revertBtn}>
            {t('layout_editor.computed.revert')}
          </button>
        </div>
      ) : null}

      {editing && !advanced ? (
        <div style={editBody}>
          {isBase ? (
            <p data-testid={`g7le-computed-override-notice-${computedKey}`} style={overrideNotice}>
              ⚠ {t('layout_editor.computed.override_notice')}
            </p>
          ) : null}
          {card.kind === 'preset' && recipe ? (
            <>
              {recipe.params.map((param: ConditionParamSpec) => (
                <ParamField
                  key={param.key}
                  param={param}
                  value={card.params[param.key] ?? ''}
                  t={t}
                  dataSourceCandidates={dataSourceCandidates}
                  bindingCandidates={bindingCandidates}
                  onChange={(v) => patchParam(param.key, v)}
                />
              ))}
            </>
          ) : card.kind === 'custom' ? (
            <CustomComputedBuilder
              model={{ ...card.model, key: computedKey }}
              onChange={(m) => onRename(m.key, modelToExpr(m))}
              t={t}
              dataSourceCandidates={dataSourceCandidates}
              bindingCandidates={bindingCandidates}
              existingKeys={existingKeys.filter((k) => k !== computedKey)}
            />
          ) : null}
        </div>
      ) : null}
    </div>
  );
}

/**
 * 고정 후보/선택 위젯 — 등록 위젯 레지스트리로 디스패치(데이터칩 승격 제외 대상). 그 외 값/경로
 * 입력(text·미지정)은 DataChipValueInput 으로 보내 단순 데이터 연동(`{{데이터.경로}}`)을 닿게 한다
 * ActionParamFields 와 동일 정책.
 */
const COMPUTED_REGISTERED_PASSTHROUGH = new Set([
  'datasource-picker',
  'binding-picker',
  'select',
  'toggle',
  'state-key-picker',
  'page-picker',
]);

/** preset param 입력 1건 */
function ParamField({
  param,
  value,
  t,
  dataSourceCandidates,
  bindingCandidates,
  onChange,
}: {
  param: ConditionParamSpec;
  value: string;
  t: ComputedFormProps['t'];
  dataSourceCandidates?: Array<{ value: string; label: string }>;
  bindingCandidates?: BindingCandidate[];
  onChange: (v: string) => void;
}): React.ReactElement {
  const paramLabel = label(param.label, t, param.key);
  const widget = param.widget ?? '';
  // 고정 후보/선택 위젯은 등록 레지스트리로 디스패치. 그 외(값/경로 — text·미지정)는 데이터칩.
  const isPassthrough = COMPUTED_REGISTERED_PASSTHROUGH.has(widget);
  const Widget = isPassthrough ? getWidget(param.widget) : null;
  const candidates = widget === 'datasource-picker' || widget === 'binding-picker' ? dataSourceCandidates : undefined;
  return (
    <div data-testid={`g7le-computed-param-${param.key}`} style={paramRow}>
      <span style={paramLabelStyle}>{paramLabel}</span>
      <div style={{ flex: 1, minWidth: 0 }}>
        {Widget ? (
          <Widget control={{ ...param }} value={value} onChange={(v) => onChange(typeof v === 'string' ? v : '')} t={t} candidates={candidates} />
        ) : (
          // 값/경로 칸 — DataChipValueInput(데이터 검색 칩 + 표현식 분해 트리 + 평문). 평문/숫자는
          // 종전처럼 그대로 입력(키화 0), `{{...}}` 데이터 연동·표현식은 검색 피커/분해 트리로.
          <DataChipValueInput
            value={value}
            onChange={(v) => onChange(v)}
            t={t}
            candidates={bindingCandidates}
            testidPrefix={`g7le-computed-param-${param.key}-chip`}
          />
        )}
      </div>
    </div>
  );
}

const form: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 12, minWidth: 0 };
const heading: React.CSSProperties = { margin: 0, fontSize: 13, fontWeight: 700, color: '#0f172a' };
const cardStyle: React.CSSProperties = { border: '1px solid #e2e8f0', borderRadius: 8, padding: '8px 10px', display: 'flex', flexDirection: 'column', gap: 6, background: '#f8fafc' };
const cardHead: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 6 };
const cardTitle: React.CSSProperties = { fontSize: 13, fontWeight: 600, color: '#0f172a' };
const keyCode: React.CSSProperties = { fontSize: 10, color: '#64748b', fontFamily: 'monospace' };
const baseBadge: React.CSSProperties = { fontSize: 10, color: '#0891b2', whiteSpace: 'nowrap' };
const overriddenBadge: React.CSSProperties = { fontSize: 10, color: '#7c3aed', whiteSpace: 'nowrap' };
const advBadge: React.CSSProperties = { fontSize: 10, color: '#92400e', whiteSpace: 'nowrap' };
const revertRow: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 8, marginTop: 4, minWidth: 0 };
const revertHint: React.CSSProperties = { fontSize: 11, color: '#7c3aed', flex: 1, minWidth: 0 };
const revertBtn: React.CSSProperties = { border: '1px solid #c4b5fd', borderRadius: 6, background: '#f5f3ff', color: '#6d28d9', cursor: 'pointer', fontSize: 11, padding: '2px 8px', whiteSpace: 'nowrap' };
const iconBtn: React.CSSProperties = { border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer', fontSize: 11, padding: '2px 6px' };
const editBody: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 6, paddingTop: 6, borderTop: '1px dashed #e2e8f0' };
const overrideNotice: React.CSSProperties = { margin: 0, fontSize: 11, color: '#b45309', background: '#fef3c7', padding: '6px 8px', borderRadius: 6 };
const paramRow: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 8, minWidth: 0 };
const paramLabelStyle: React.CSSProperties = { fontSize: 11, color: '#64748b', minWidth: 90 };
const paramInput: React.CSSProperties = { width: '100%', padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, boxSizing: 'border-box' };
const addBtn: React.CSSProperties = { padding: '6px 12px', fontSize: 12, border: '1px dashed #94a3b8', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer' };
const addBox: React.CSSProperties = { marginTop: 6, border: '1px solid #cbd5e1', borderRadius: 8, background: '#fff', padding: 8, maxHeight: 380, overflowY: 'auto' };
const groupTitle: React.CSSProperties = { fontSize: 11, fontWeight: 700, color: '#94a3b8', padding: '6px 4px 2px' };
const presetItem: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 6, width: '100%', textAlign: 'left', padding: '6px 8px', fontSize: 12, border: 'none', background: 'transparent', cursor: 'pointer', borderRadius: 6 };
const presetLabel: React.CSSProperties = { flex: 1, minWidth: 0, color: '#0f172a' };
const badge: React.CSSProperties = { fontSize: 10, color: '#64748b', whiteSpace: 'nowrap' };
const customOpenBtn: React.CSSProperties = { padding: '6px 10px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#f8fafc', color: '#475569', cursor: 'pointer' };
const commitBtn: React.CSSProperties = { marginTop: 6, padding: '6px 12px', fontSize: 12, border: '1px solid #3b82f6', borderRadius: 6, background: '#3b82f6', color: '#fff', cursor: 'pointer' };
const emptyHint: React.CSSProperties = { margin: 0, fontSize: 12, color: '#94a3b8' };
const codeBlock: React.CSSProperties = { margin: '2px 0 0', padding: '6px 8px', fontSize: 11, fontFamily: 'monospace', color: '#0f172a', background: '#f1f5f9', border: '1px solid #e2e8f0', borderRadius: 6, whiteSpace: 'pre-wrap', wordBreak: 'break-all', overflowX: 'auto' };
