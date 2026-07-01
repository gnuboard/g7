/**
 * ActionParamFields.tsx — 동작(액션) 인스턴스 params 편집 공용 부품
 *
 * 한 액션 레시피의 `params` 입력 위젯 묶음 + 실행조건(if) 접이식 입력을 렌더한다. 종전 세
 * 동작 빌더([화면 동작] InitActionsForm · 컴포넌트 [동작] ActionRecipeEditor · 다중 액션
 * ActionListBuilder)가 거의 동일한 ParamField/if 토글을 각자 복제했는데(② "공통화 안
 * 됨"), 공용 카드(ActionListBuilder)의 기본 편집 폼으로 일원화한다.
 *
 *  - `ParamFieldList`: recipe.params 순회 → 위젯 디스패치(advanced 면 [고급] 잠금).
 *  - `ActionIfToggle`: 모든 핸들러 공통 실행조건(if) 접이식 input.
 *
 * 위젯/후보 해석은 InitActionsForm 의 종전 ParamField 와 동일 정책(widgetRegistry +
 * page/datasource/state-key 후보 디스패치). 편집기 코어 — `g7le-*` + 인라인 스타일만.
 *
 * @since engine-v1.50.0 · 자유값 칸 데이터칩·표현식 친화 engine-v1.50.0
 */

import React, { useState } from 'react';
import type { ActionRecipeParamSpec } from '../../spec/specTypes';
import type { NormalizedActionRecipe } from '../../spec/actionRecipeEngine';
import { buildAction, withActionIf, extractActionIf } from '../../spec/actionRecipeEngine';
import { getWidget } from '../../spec/widgetRegistry';
import type { BindingCandidate } from '../../spec/bindingCandidates';
import { DataChipValueInput } from './DataChipValueInput';
import { KeyValueChipEditor, type KeyValueExtraItem } from './KeyValueChipEditor';
import { StateKeyPickerControl } from '../property-controls/RecipePickerControls';
import { InitialStateValueEditor } from './InitialStateValueEditor';

type T = (key: string, params?: Record<string, string | number>) => string;

/**
 * 자유 값(데이터칩·표현식 친화)을 받는 위젯 종류 — `text`/`data-chip`/`number` 및 미등록/미지정
 * 위젯. 이들은 등록 위젯 디스패치 대신 `DataChipValueInput`(데이터 검색 칩 + 표현식 분해 트리 +
 * 평문)으로 라우팅한다. SEO 탭과 동일 친화 입력기 — 모든 동작 입력칸에 데이터칩/표현식을 닿게
 * 한다. select/toggle/picker(고정 후보)는
 * 제외(값이 아닌 고정 선택 — 별도 분기).
 */
const FREE_VALUE_WIDGETS = new Set(['text', 'data-chip', 'number']);

/** 고정 후보/선택 위젯 — 등록 위젯 레지스트리로 디스패치(칩화 제외 대상, "값 받는 칸만 칩"). */
const REGISTERED_PASSTHROUGH = new Set([
  'select',
  'toggle',
  'page-picker',
  'datasource-picker',
  'state-key-picker',
  'modal-picker',
  'component-target-picker',
  'tag-input',
  'locale-picker',
  'i18n-text',
  'segmented',
  'slider',
  'color',
  'image',
]);

/** 후보 풀 묶음 — 위젯별 디스패치 입력. */
export interface ActionParamCandidatePools {
  pageCandidates?: Array<{ value: string; label: string }>;
  dataSourceCandidates?: Array<{ value: string; label: string }>;
  stateKeyCandidates?: Array<{ value: string; label: string }>;
  /** 레이아웃 modals 후보(openModal 대상 선택, 에러 처리 탭 등). */
  modalCandidates?: Array<{ value: string; label: string }>;
  bindingCandidates?: BindingCandidate[];
}

/**
 * action-list 위젯(중첩 onSuccess/onError 등) 렌더러 — 공용 ActionListBuilder 재귀 주입용.
 * 순환 의존(ActionParamFields → ActionListBuilder → ActionParamFields)을 끊기 위해 호출자가
 * 주입한다(ActionListBuilder.defaultEditor 가 자기 자신을 주입).
 *
 * `paramKey` 는 이 중첩 목록이 어느 param 인지(onSuccess/onError/actions 등) — 렌더러가
 * 데이터칩 컨텍스트(response/error/그대로)를 결정하는 데 쓴다. onSuccess→응답 칩, onError→오류
 * 칩, 그 외(sequence/parallel 의 actions 등)→부모 컨텍스트 유지.
 */
export type ActionListRenderer = (
  actions: Record<string, unknown>[],
  onChange: (next: Record<string, unknown>[]) => void,
  testIdPrefix: string,
  paramKey?: string,
) => React.ReactNode;

/** `$t:` 라벨 해석 */
function label(raw: string | undefined, t: T, fallback: string): string {
  if (typeof raw !== 'string') return fallback;
  return raw.startsWith('$t:') ? t(raw.slice(3)) : raw;
}

/** 위젯별 후보 선택 */
function candidatesFor(
  widget: string | undefined,
  c: ActionParamCandidatePools,
): Array<{ value: string; label: string }> | undefined {
  if (widget === 'page-picker') return c.pageCandidates;
  if (widget === 'datasource-picker') return c.dataSourceCandidates;
  if (widget === 'state-key-picker') return c.stateKeyCandidates;
  if (widget === 'modal-picker') return c.modalCandidates;
  return undefined;
}


/** 한 param 입력 — advanced 면 [고급] 잠금, action-list 면 중첩 빌더, 그 외는 위젯 디스패치. */
function ParamField({
  param,
  value,
  t,
  pools,
  onChange,
  testIdPrefix,
  renderActionList,
  siblingValues,
}: {
  param: ActionRecipeParamSpec;
  value: unknown;
  t: T;
  pools: ActionParamCandidatePools;
  onChange: (v: unknown) => void;
  testIdPrefix: string;
  renderActionList?: ActionListRenderer;
  /** 같은 액션의 다른 param 입력값(형제) — state-key-value 가 sibling target 으로 path-style 판정 */
  siblingValues?: Record<string, unknown>;
}): React.ReactElement {
  const paramLabel = label(param.label, t, param.key);

  if (param.advanced) {
    return (
      <div data-testid={`${testIdPrefix}-locked-${param.key}`} style={lockedParam}>
        <span style={lockedParamLabel}>{paramLabel}</span>
        <span style={lockedParamBadge}>[{t('layout_editor.action.advanced')}] {t('layout_editor.init_actions.advanced_param_hint')}</span>
      </div>
    );
  }

  // onSuccess/onError 등 중첩 액션 목록(action-list 위젯) — 공용 빌더로 재귀(순환 의존 회피
  // 위해 렌더프롭 주입). 미주입 시 위젯 레지스트리 폴백.
  if (param.widget === 'action-list' && renderActionList) {
    const nested = Array.isArray(value)
      ? (value as unknown[]).filter((a): a is Record<string, unknown> => !!a && typeof a === 'object')
      : [];
    return (
      <div data-testid={`${testIdPrefix}-param-${param.key}`} style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
        <span style={paramLabelStyle}>{paramLabel}</span>
        {renderActionList(nested, (next) => onChange(next.length === 0 ? undefined : next), `${testIdPrefix}-${param.key}`, param.key)}
      </div>
    );
  }

  // conditions 의 분기 목록(branch-list 위젯) — 각 분기는 `{ if?, then }`. action-list 와 달리
  // 항목이 액션이 아니라 조건+동작 묶음이라 전용 편집기로 분기별 if(조건식 데이터칩) + then(중첩
  // 액션 빌더, 단일/배열 both)을 친화 편집한다. 마지막 if 없는 분기 = "그 외(else)".
  if (param.widget === 'branch-list' && renderActionList) {
    const branches = Array.isArray(value)
      ? (value as unknown[]).filter((b): b is Record<string, unknown> => !!b && typeof b === 'object')
      : [];
    return (
      <div data-testid={`${testIdPrefix}-param-${param.key}`} style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
        <span style={paramLabelStyle}>{paramLabel}</span>
        <BranchListField
          branches={branches}
          onChange={(next) => onChange(next.length === 0 ? undefined : next)}
          t={t}
          pools={pools}
          renderActionList={renderActionList}
          testIdPrefix={`${testIdPrefix}-${param.key}`}
        />
      </div>
    );
  }

  // key-value 맵(navigate query / login body / replaceUrl query) — 키 평문 + 값 데이터칩.
  // 값은 객체 맵(`{k:v}`)이라 행 배열 ↔ 맵 어댑터로 KeyValueChipEditor(공용 부품) 재사용.
  if (param.widget === 'key-value') {
    return (
      <div data-testid={`${testIdPrefix}-param-${param.key}`} style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
        <span style={paramLabelStyle}>{paramLabel}</span>
        <KeyValueMapField value={value} onChange={onChange} t={t} candidates={pools.bindingCandidates} testIdPrefix={`${testIdPrefix}-kv-${param.key}`} />
      </div>
    );
  }

  // state-key-value 맵(setState 상태 payload) — 키 = 상태 키 검색(state-key-picker), 값 = 재귀
  // 블럭 편집기(InitialStateValueEditor). 값이 깊은 중첩 객체(`_local: {filter:{...}}`)여도 묶음/
  // 목록/문자 블럭으로 펼쳐 편집한다. 종전 평면
  // KeyValueMapField 는 값을 문자열로만 다뤄 객체 값이 `[object Object]` 로 깨졌다.
  if (param.widget === 'state-key-value') {
    return (
      <div data-testid={`${testIdPrefix}-param-${param.key}`} style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
        <span style={paramLabelStyle}>{paramLabel}</span>
        <StateKeyValueMapField
          value={value}
          onChange={onChange}
          t={t}
          candidates={pools.bindingCandidates}
          stateKeyCandidates={pools.stateKeyCandidates}
          param={param}
          siblingTarget={siblingValues?.target}
          testIdPrefix={`${testIdPrefix}-skv-${param.key}`}
        />
      </div>
    );
  }

  // 자유 값 위젯(text/data-chip/number) 또는 미등록/미지정 위젯 — DataChipValueInput(데이터칩 +
  // 표현식 분해 트리 + 평문). 모든 동작 입력칸에 데이터 연결·표현식을 닿게 한다.
  // number 도 동적 값(`{{...}}`) 허용 — 평문 숫자/표현식 모두 1급(별도 number-only 강제 없음).
  const isFree =
    FREE_VALUE_WIDGETS.has(param.widget ?? '') || !REGISTERED_PASSTHROUGH.has(param.widget ?? '');
  if (isFree) {
    return (
      <div data-testid={`${testIdPrefix}-param-${param.key}`} style={paramRow}>
        <span style={paramLabelStyle}>{paramLabel}</span>
        <div style={{ flex: 1, minWidth: 0 }}>
          <DataChipValueInput
            value={typeof value === 'string' ? value : value == null ? '' : String(value)}
            onChange={(v) => onChange(v === '' ? undefined : v)}
            t={t}
            candidates={pools.bindingCandidates}
            placeholder={param.widget === 'number' ? t('layout_editor.action_param.number_placeholder') : undefined}
            testidPrefix={`${testIdPrefix}-chip-${param.key}`}
          />
        </div>
      </div>
    );
  }

  // 고정 후보/선택 위젯(select/toggle/picker 등) — 등록 위젯 레지스트리 디스패치(칩화 제외).
  const Widget = getWidget(param.widget);
  return (
    <div data-testid={`${testIdPrefix}-param-${param.key}`} style={paramRow}>
      <span style={paramLabelStyle}>{paramLabel}</span>
      <div style={{ flex: 1, minWidth: 0 }}>
        {Widget ? (
          <Widget
            control={{ ...param }}
            value={value}
            onChange={onChange}
            t={t}
            candidates={candidatesFor(param.widget, pools)}
            bindingCandidates={pools.bindingCandidates}
          />
        ) : (
          // 안전 디그레이드 — 등록 누락 위젯도 평문 대신 데이터칩 입력(자유값 보장).
          <DataChipValueInput
            value={typeof value === 'string' ? value : value == null ? '' : String(value)}
            onChange={(v) => onChange(v === '' ? undefined : v)}
            t={t}
            candidates={pools.bindingCandidates}
            testidPrefix={`${testIdPrefix}-chip-${param.key}`}
          />
        )}
      </div>
    </div>
  );
}

/**
 * conditions 분기 목록 편집기 — `[{ if?, then }]` 배열.
 *
 * 각 분기는 실행조건(`if`, 조건식 데이터칩)과 동작(`then`, 중첩 액션 빌더)으로 구성된다.
 * `then` 은 단일 액션(객체) 또는 액션 배열 둘 다 허용(handleConditions 가 양형 지원) — 편집은
 * 항상 액션 리스트로 하되, 1건이면 단일 객체로, 다건이면 배열로 저장해 원 shape 를 보존한다.
 * `if` 없는 분기 = "그 외(else)". 분기 추가/삭제/순서이동을 제공한다(첫 매칭 우선이라 순서 중요).
 *
 * 코어 편집기 — 특정 핸들러/템플릿 비의존. conditions 핸들러를 쓰는 모든 액션에 동일 적용.
 */
function BranchListField({
  branches,
  onChange,
  t,
  pools,
  renderActionList,
  testIdPrefix,
}: {
  branches: Record<string, unknown>[];
  onChange: (next: Record<string, unknown>[]) => void;
  t: T;
  pools: ActionParamCandidatePools;
  renderActionList: ActionListRenderer;
  testIdPrefix: string;
}): React.ReactElement {
  /** then(단일 액션 | 배열 | undefined) → 편집용 액션 배열 */
  const thenToActions = (then: unknown): Record<string, unknown>[] => {
    if (Array.isArray(then)) {
      return then.filter((a): a is Record<string, unknown> => !!a && typeof a === 'object');
    }
    if (then && typeof then === 'object') return [then as Record<string, unknown>];
    return [];
  };
  /** 편집 액션 배열 → then 저장형(1건=단일 객체, 다건=배열, 0건=undefined) */
  const actionsToThen = (actions: Record<string, unknown>[]): unknown => {
    if (actions.length === 0) return undefined;
    if (actions.length === 1) return actions[0];
    return actions;
  };

  const patchBranch = (index: number, next: Record<string, unknown>): void => {
    const copy = branches.slice();
    copy[index] = next;
    onChange(copy);
  };
  const removeBranch = (index: number): void => {
    onChange(branches.filter((_, i) => i !== index));
  };
  const moveBranch = (from: number, to: number): void => {
    if (to < 0 || to >= branches.length) return;
    const copy = branches.slice();
    const [moved] = copy.splice(from, 1);
    copy.splice(to, 0, moved);
    onChange(copy);
  };
  const addBranch = (): void => {
    onChange([...branches, { if: '', then: undefined }]);
  };

  return (
    <div data-testid={`${testIdPrefix}-branches`} style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
      {branches.map((branch, index) => {
        const ifVal = typeof branch.if === 'string' ? branch.if : '';
        const thenActions = thenToActions(branch.then);
        return (
          <div key={index} data-testid={`${testIdPrefix}-branch-${index}`} style={branchBox}>
            <div style={branchHeader}>
              <span style={branchTitle}>
                {ifVal
                  ? t('layout_editor.action_recipe.conditions.branch_when')
                  : t('layout_editor.action_recipe.conditions.branch_else')}
                {' '}
                {index + 1}
              </span>
              <span style={{ display: 'flex', gap: 4 }}>
                <button
                  type="button"
                  data-testid={`${testIdPrefix}-branch-${index}-up`}
                  onClick={() => moveBranch(index, index - 1)}
                  disabled={index === 0}
                  style={branchBtn}
                  title={t('layout_editor.action_recipe.conditions.move_up')}
                >↑</button>
                <button
                  type="button"
                  data-testid={`${testIdPrefix}-branch-${index}-down`}
                  onClick={() => moveBranch(index, index + 1)}
                  disabled={index === branches.length - 1}
                  style={branchBtn}
                  title={t('layout_editor.action_recipe.conditions.move_down')}
                >↓</button>
                <button
                  type="button"
                  data-testid={`${testIdPrefix}-branch-${index}-remove`}
                  onClick={() => removeBranch(index)}
                  style={branchBtn}
                  title={t('layout_editor.action_recipe.conditions.remove_branch')}
                >✕</button>
              </span>
            </div>
            {/* 분기 실행조건(if) — 조건식 데이터칩(표현식 분해). 비우면 "그 외(else)" 분기. */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: 3 }}>
              <span style={branchFieldLabel}>{t('layout_editor.action_recipe.conditions.param_if')}</span>
              <DataChipValueInput
                value={ifVal}
                onChange={(v) => patchBranch(index, { ...branch, if: v })}
                t={t}
                candidates={pools.bindingCandidates}
                testidPrefix={`${testIdPrefix}-branch-${index}-if`}
              />
            </div>
            {/* 분기 동작(then) — 중첩 액션 빌더(재귀). 단일/배열 both 보존. */}
            <div style={{ display: 'flex', flexDirection: 'column', gap: 3 }}>
              <span style={branchFieldLabel}>{t('layout_editor.action_recipe.conditions.param_then')}</span>
              {renderActionList(
                thenActions,
                (nextActions) => patchBranch(index, { ...branch, then: actionsToThen(nextActions) }),
                `${testIdPrefix}-branch-${index}-then`,
              )}
            </div>
          </div>
        );
      })}
      <button type="button" data-testid={`${testIdPrefix}-add-branch`} onClick={addBranch} style={addBranchBtn}>
        + {t('layout_editor.action_recipe.conditions.add_branch')}
      </button>
    </div>
  );
}

const branchBox: React.CSSProperties = {
  border: '1px solid #e2e8f0',
  borderRadius: 6,
  padding: 8,
  display: 'flex',
  flexDirection: 'column',
  gap: 6,
  background: '#f8fafc',
};
const branchHeader: React.CSSProperties = { display: 'flex', justifyContent: 'space-between', alignItems: 'center' };
const branchTitle: React.CSSProperties = { fontSize: 12, fontWeight: 700, color: '#475569' };
const branchFieldLabel: React.CSSProperties = { fontSize: 11, color: '#64748b' };
const branchBtn: React.CSSProperties = {
  border: '1px solid #cbd5e1',
  background: '#fff',
  borderRadius: 4,
  width: 22,
  height: 22,
  cursor: 'pointer',
  fontSize: 12,
  lineHeight: '1',
  color: '#475569',
};
const addBranchBtn: React.CSSProperties = {
  border: '1px dashed #94a3b8',
  background: '#fff',
  borderRadius: 6,
  padding: '6px 10px',
  cursor: 'pointer',
  fontSize: 12,
  color: '#475569',
  alignSelf: 'flex-start',
};

/**
 * 키–값 맵(`{k:v}`) ↔ KeyValueChipEditor(행 배열) 어댑터. navigate query · login body ·
 * setState 상태 payload 등 동적 키 맵 param 을 키 입력 + 값 데이터칩 행으로 편집한다. 값측은
 * 항상 DataChipValueInput(KeyValueChipEditor 내부). 키 입력칸은 renderKeyInput 으로 교체 가능
 * (setState=상태키 검색). 빈 맵은 undefined 로 방출(미입력 키 떨굼 — buildAction 정합).
 */
function KeyValueMapField({
  value,
  onChange,
  t,
  candidates,
  testIdPrefix,
  renderKeyInput,
}: {
  value: unknown;
  onChange: (v: unknown) => void;
  t: T;
  candidates?: BindingCandidate[];
  testIdPrefix: string;
  renderKeyInput?: (key: { value: string; onChange: (v: string) => void; testid: string; placeholder: string }) => React.ReactNode;
}): React.ReactElement {
  // 맵 → 행 배열(`[{key, value}]`). 값은 문자열로 정규화(데이터칩 입력 = 문자열).
  const rows: KeyValueExtraItem[] =
    value && typeof value === 'object' && !Array.isArray(value)
      ? Object.entries(value as Record<string, unknown>).map(([k, v]) => ({
          key: k,
          value: typeof v === 'string' ? v : v == null ? '' : String(v),
        }))
      : [];
  return (
    <KeyValueChipEditor
      value={rows}
      onChange={(next) => {
        const map: Record<string, string> = {};
        for (const item of next) {
          if (item.key.trim() === '') continue;
          map[item.key] = item.value ?? '';
        }
        onChange(Object.keys(map).length === 0 ? undefined : map);
      }}
      keyField="key"
      valueField="value"
      t={t}
      candidates={candidates}
      testidPrefix={testIdPrefix}
      keyPlaceholder={t('layout_editor.action_param.key_placeholder')}
      valuePlaceholder={t('layout_editor.action_param.value_placeholder')}
      addLabel={t('layout_editor.action_param.add_pair')}
      renderKeyInput={renderKeyInput}
    />
  );
}


/**
 * setState 상태 payload 전용 키–값 맵 편집기 — 키 = 상태 키 검색(state-key-picker), 값 = 재귀
 * 블럭 편집기(InitialStateValueEditor). navigate query 등 평면 키–값(KeyValueMapField)과 달리,
 * setState 값은 깊은 중첩 객체(`_local: {filter:{...}}`)일 수 있어 문자열 정규화 시 `[object
 * Object]` 로 깨진다. 값을 묶음/목록/문자 블럭으로 펼쳐 편집하되, 문자 리프는
 * DataChipValueInput 주입으로 데이터칩·표현식·`??` 폴백을 모두 제공한다(데이터소스 [기본값] 편집과
 * 동일 UX — "중첩 블럭 + 표현식 동일 기능"). 빈 맵은 undefined 로 방출(buildAction 정합).
 *
 * @param props value(상태 맵) / onChange / t / candidates(데이터칩) / stateKeyCandidates(키 검색) / param / testIdPrefix
 * @return setState 상태 키–값 행 목록 엘리먼트
 */
function StateKeyValueMapField({
  value,
  onChange,
  t,
  candidates,
  stateKeyCandidates,
  param,
  siblingTarget,
  testIdPrefix,
}: {
  value: unknown;
  onChange: (v: unknown) => void;
  t: T;
  candidates?: BindingCandidate[];
  stateKeyCandidates?: Array<{ value: string; label: string }>;
  param: ActionRecipeParamSpec;
  /** 같은 setState 액션의 target 값 — path-style 판정용 */
  siblingTarget?: unknown;
  testIdPrefix: string;
}): React.ReactElement {
  // 상태 맵(`{키:값}`) → 행 배열. 값은 임의 구조(스칼라/객체/배열) 그대로 보존(문자열 강제 정규화
  // 금지 — 중첩 객체 보존이 본 수정의 핵심).
  const entries: Array<[string, unknown]> =
    value && typeof value === 'object' && !Array.isArray(value)
      ? Object.entries(value as Record<string, unknown>)
      : [];

  // path-style setState 판정 — target 이 경로(`_local.X`/`global.X`)이고 상태맵이 `{value: V}` 단일이면,
  // `value` 는 "그 경로에 넣을 값"(상태 키 이름 아님). 단일 "값" 입력칸으로 표시한다.
  // (런타임 handleSetState: target 이 `_local.X` 면 payload.value 를 그 경로에 설정.)
  const targetStr = typeof siblingTarget === 'string' ? siblingTarget : '';
  const isPathStyleTarget = targetStr.startsWith('_local.') || targetStr.startsWith('global.') || targetStr.startsWith('$parent._local.') || targetStr.startsWith('$root._local.');
  const isPathStyleValue =
    isPathStyleTarget && entries.length === 1 && entries[0][0] === 'value';

  if (isPathStyleValue) {
    const v = entries[0][1];
    // 값 종류(불리언/숫자/문자/객체)를 보존하는 재귀 블럭 편집기 — map-style 값측과 동일 부품 재사용.
    // 불리언 true 가 문자열 "true" 로 깨지지 않게 InitialStateValueEditor(타입 인지)를 쓴다.
    return (
      <div data-testid={`${testIdPrefix}-path-value`} style={{ display: 'flex', flexDirection: 'column', gap: 4 }}>
        <InitialStateValueEditor
          value={v}
          onChange={(nv) => onChange(nv === undefined ? undefined : { value: nv })}
          t={t}
          path="value"
          scope={`${testIdPrefix}-path-value-val`}
          renderStringLeaf={(leaf) => (
            <DataChipValueInput
              value={leaf.value}
              onChange={leaf.onChange}
              t={t}
              candidates={candidates}
              testidPrefix={leaf.testidPrefix}
            />
          )}
        />
      </div>
    );
  }

  // 한 키의 값 변경 → 맵 재구성(불변). 빈 맵이면 undefined 방출.
  const patchEntry = (key: string, v: unknown): void => {
    const next: Record<string, unknown> = {};
    for (const [k, ov] of entries) next[k] = k === key ? v : ov;
    onChange(Object.keys(next).length === 0 ? undefined : next);
  };
  const removeEntry = (key: string): void => {
    const next: Record<string, unknown> = {};
    for (const [k, ov] of entries) if (k !== key) next[k] = ov;
    onChange(Object.keys(next).length === 0 ? undefined : next);
  };

  // 새 상태 키 추가 — 키(state-key-picker) + 종류(InitialStateValueEditor 가 추가 후 전환 제공).
  // 추가 시 빈 문자열로 시작(가장 흔한 경우), 이후 값 블럭에서 묶음/목록으로 전환·중첩 가능.
  const [newKey, setNewKey] = useState('');

  const addEntry = (): void => {
    const k = newKey.trim();
    if (k === '') return;
    if (entries.some(([ek]) => ek === k)) return; // 중복 키 무시.
    const next: Record<string, unknown> = {};
    for (const [ek, ov] of entries) next[ek] = ov;
    next[k] = '';
    onChange(next);
    setNewKey('');
  };

  return (
    <div data-testid={testIdPrefix} style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      {entries.map(([k, v]) => (
        <div
          key={k}
          data-testid={`${testIdPrefix}-row-${k}`}
          style={{ display: 'flex', gap: 6, alignItems: 'flex-start', border: '1px solid #e2e8f0', borderRadius: 8, padding: 8 }}
        >
          <div style={{ flex: 1, minWidth: 0, display: 'flex', flexDirection: 'column', gap: 6 }}>
            {/* 키 — 상태 키 검색(읽기 전용 표시 + 변경 가능). */}
            <div style={{ minWidth: 0 }}>
              <StateKeyPickerControl
                control={{ ...param } as never}
                value={k}
                onChange={(nv) => {
                  // 키 변경 = 키 rename(값 보존). 빈/중복은 무시.
                  const renamed = nv == null ? '' : String(nv);
                  if (renamed === '' || renamed === k) return;
                  if (entries.some(([ek]) => ek === renamed)) return;
                  const next: Record<string, unknown> = {};
                  for (const [ek, ov] of entries) next[ek === k ? renamed : ek] = ov;
                  onChange(next);
                }}
                t={t}
                candidates={stateKeyCandidates}
              />
            </div>
            {/* 값 — 재귀 블럭 편집기(중첩 객체/배열/스칼라). 문자 리프 = 데이터칩·표현식. */}
            <div style={{ minWidth: 0 }}>
              <InitialStateValueEditor
                value={v}
                onChange={(nv) => patchEntry(k, nv)}
                t={t}
                path={k}
                scope={`${testIdPrefix}-val`}
                renderStringLeaf={(leaf) => (
                  <DataChipValueInput
                    value={leaf.value}
                    onChange={leaf.onChange}
                    t={t}
                    candidates={candidates}
                    testidPrefix={leaf.testidPrefix}
                  />
                )}
              />
            </div>
          </div>
          <button
            type="button"
            data-testid={`${testIdPrefix}-remove-${k}`}
            onClick={() => removeEntry(k)}
            title={t('layout_editor.page_settings.seo.remove')}
            aria-label={t('layout_editor.page_settings.seo.remove')}
            style={{ flex: '0 0 auto', padding: '4px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#64748b', cursor: 'pointer' }}
          >
            ✕
          </button>
        </div>
      ))}
      {/* 새 상태 키 추가 — 키 검색 + 추가. 값 종류는 추가 후 값 블럭에서 전환. */}
      <div style={{ display: 'flex', gap: 6, alignItems: 'center' }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <StateKeyPickerControl
            control={{ ...param } as never}
            value={newKey}
            onChange={(v) => setNewKey(v == null ? '' : String(v))}
            t={t}
            candidates={stateKeyCandidates}
          />
        </div>
        <button
          type="button"
          data-testid={`${testIdPrefix}-add`}
          onClick={addEntry}
          style={{ flex: '0 0 auto', padding: '5px 10px', fontSize: 12, border: '1px dashed #cbd5e1', borderRadius: 6, background: '#f8fafc', color: '#475569', cursor: 'pointer' }}
        >
          + {t('layout_editor.action_param.add_pair')}
        </button>
      </div>
    </div>
  );
}

/**
 * recipe 의 params 전체 입력 목록 + buildAction 재생성(if 보존).
 *
 * 액션(raw) 1건 + 그 recipe + 현재 values 를 받아 각 param 위젯을 렌더하고, 변경 시 buildAction
 * 으로 새 액션을 만들어(if 보존) onChange 한다.
 */
export function ParamFieldList({
  raw,
  recipe,
  values,
  t,
  pools,
  onChange,
  testIdPrefix,
  renderActionList,
}: {
  raw: Record<string, unknown>;
  recipe: NormalizedActionRecipe;
  values: Record<string, unknown>;
  t: T;
  pools: ActionParamCandidatePools;
  onChange: (next: Record<string, unknown>) => void;
  testIdPrefix: string;
  renderActionList?: ActionListRenderer;
}): React.ReactElement {
  const patchValue = (key: string, v: unknown): void => {
    const nextValues = { ...values, [key]: v };
    const built = buildAction(recipe, nextValues);
    const ifExpr = extractActionIf(raw);
    onChange(ifExpr ? withActionIf(built, ifExpr) : built);
  };
  return (
    <>
      {recipe.params.filter((param) => isParamVisible(param, values)).map((param) => (
        <ParamField
          key={param.key}
          param={param}
          value={values[param.key]}
          t={t}
          pools={pools}
          onChange={(v) => patchValue(param.key, v)}
          testIdPrefix={testIdPrefix}
          renderActionList={renderActionList}
          siblingValues={values}
        />
      ))}
    </>
  );
}

/**
 * param 의 `dependsOn` 게이팅 — `{ param, equals }` 선언 시 다른 param 값이 `equals` 와 일치할
 * 때만 노출(navigate `transition_overlay_target` = replace=true 시만). 미선언이면 항상 노출.
 *
 * @param param 파라미터 스펙
 * @param values 현재 입력값 맵
 * @return 노출 여부
 */
function isParamVisible(param: ActionRecipeParamSpec, values: Record<string, unknown>): boolean {
  const dep = (param as { dependsOn?: { param?: unknown; equals?: unknown } }).dependsOn;
  if (!dep || typeof dep !== 'object' || typeof dep.param !== 'string') return true;
  return values[dep.param] === dep.equals;
}

/**
 * 모든 핸들러 공통 실행조건(if) 접이식 입력.
 *
 * 액션(raw)의 현재 if 식을 표시/편집한다. 변경 시 withActionIf 로 raw 에 if 를 얹어 onChange.
 */
export function ActionIfToggle({
  raw,
  t,
  onChange,
  testIdPrefix,
  candidates,
}: {
  raw: Record<string, unknown>;
  t: T;
  onChange: (next: Record<string, unknown>) => void;
  testIdPrefix: string;
  /** 조건식 데이터 칩 후보(실행조건은 표현식 — 칩+표현식 입력기). 미전달 시 평문 입력만. */
  candidates?: BindingCandidate[];
}): React.ReactElement {
  const [open, setOpen] = useState(false);
  return (
    <div data-testid={`${testIdPrefix}-if`} style={ifWrap}>
      <button type="button" onClick={() => setOpen((v) => !v)} style={ifToggle}>
        ▸ {t('layout_editor.init_actions.if_label')}
      </button>
      {open ? (
        // 실행 조건은 식(`{{user?.uuid}}`)이므로 bare input 이 아니라 데이터칩+표현식 입력기로
        // 표현식 분해 트리 허용(조건=식).
        <div data-testid={`${testIdPrefix}-if-input`} style={{ minWidth: 0 }}>
          <DataChipValueInput
            value={extractActionIf(raw) ?? ''}
            onChange={(v) => onChange(withActionIf(raw, v))}
            t={t}
            candidates={candidates}
            placeholder={t('layout_editor.init_actions.if_placeholder')}
            testidPrefix={`${testIdPrefix}-if-chip`}
          />
        </div>
      ) : null}
    </div>
  );
}

const paramRow: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 8, minWidth: 0 };
const paramLabelStyle: React.CSSProperties = { fontSize: 11, color: '#64748b', minWidth: 80 };
const lockedParam: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 8, padding: '4px 0' };
const lockedParamLabel: React.CSSProperties = { fontSize: 11, color: '#64748b', minWidth: 80 };
const lockedParamBadge: React.CSSProperties = { fontSize: 11, color: '#92400e' };
const ifWrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 4 };
const ifToggle: React.CSSProperties = { textAlign: 'left', border: 'none', background: 'transparent', color: '#475569', cursor: 'pointer', fontSize: 11, padding: 0 };
const ifInput: React.CSSProperties = { width: '100%', padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, boxSizing: 'border-box' };
