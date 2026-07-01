/**
 * useBindingCandidates.ts — 데이터 칩 후보 풀 빌드
 *
 * EditorCanvasOverlay 내부 `bindingCandidates` useMemo 를 공용 훅으로 추출한다. 캔버스
 * 오버레이와 페이지 설정 모달(여러 폼)이 같은 후보 풀을 공유하기 위함이다. 추출은
 * **동작 무변경**(같은 입력 → 같은 BindingCandidate[]) — 캔버스 회귀 0 이 1순위 제약
 * ([[feedback_engine_regression_code_first_not_tests]]).
 *
 * data_sources 각 소스의 편집기 샘플 응답 shape + 상태 트리(_global/_local/route/query/
 * _computed)를 평탄화한다(런타임 fetch 금지 — 샘플 SSoT 부록6). 친화 명칭은 data_source
 * `label_key` + spec.stateLabels 카탈로그(빌더가 결선).
 *
 *  보완: `_computed` 후보에 evaluateComputedPreview 평가값·타입을 부착할 수 있다
 * (`evaluateComputed` 옵션). 평가 실패 시 기존 scalar 폴백 유지(무손실).
 *
 * @since engine-v1.50.0
 */

import { useMemo } from 'react';
import {
  buildBindingCandidates,
  type BindingCandidate,
  type BindingDataSourceInput,
  type BindingStateInput,
} from '../spec/bindingCandidates';
import { resolveSampleData } from '../sample-data/sampleDataProvider';
import type { DataSource } from '../../DataSourceManager';
import type { EditorSpec } from '../spec/specTypes';
import { evaluateComputedPreview } from '../spec/computedRecipeEngine';

/** useBindingCandidates 입력 */
export interface UseBindingCandidatesInput {
  /** 편집 중 레이아웃 raw (data_sources/computed 등 보유) */
  raw: Record<string, unknown> | undefined | null;
  /** 병합 editor-spec (sampleData/sampleGlobal/states/stateLabels) */
  spec: EditorSpec | null | undefined;
  /**
   * `_computed` 후보에 미리보기 평가값을 부착할지. 기본 false — 캔버스 오버레이는
   * 기존 동작(scalar 폴백) 유지. 페이지 설정 [자동 계산] 탭이 true 로 값 미리보기 보강.
   */
  evaluateComputed?: boolean;
}

/** raw 의 data_sources 를 BindingDataSourceInput[] 로 변환 (샘플 응답 shape 해소) */
function buildDataSourceInputs(
  raw: Record<string, unknown> | undefined | null,
  spec: EditorSpec | null | undefined,
): BindingDataSourceInput[] {
  const dsList = Array.isArray(raw?.data_sources)
    ? (raw!.data_sources as Array<Record<string, unknown>>)
    : [];
  const out: BindingDataSourceInput[] = [];
  for (const ds of dsList) {
    const id = ds?.id;
    if (typeof id !== 'string' || id.length === 0) continue;
    // 편집기 샘플 데이터로 응답 shape 해소(런타임 fetch 금지 — SSoT 부록6).
    const { value } = resolveSampleData(ds as unknown as DataSource, spec?.sampleData);
    const labelKey = typeof ds.label_key === 'string' ? ds.label_key : undefined;
    out.push({ id, sample: value, labelKey });
  }
  return out;
}

/** spec.states 의 전 페이지 initialState 패치 + raw.computed 를 scope 별 상태 트리로 집계 */
function buildStateInputs(
  raw: Record<string, unknown> | undefined | null,
  spec: EditorSpec | null | undefined,
): BindingStateInput[] {
  const states: BindingStateInput[] = [];
  // _global baseline — 병합 spec.sampleGlobal 선언(후보 shape 도출 SSoT).
  if (spec?.sampleGlobal && typeof spec.sampleGlobal === 'object') {
    states.push({ scope: '_global', tree: spec.sampleGlobal });
  }
  // _local/query/route — spec.states 의 전 페이지 상태 initialState 패치를 scope 별 합집합.
  const localTree: Record<string, unknown> = {};
  const queryTree: Record<string, unknown> = {};
  const routeTree: Record<string, unknown> = {};
  const deepAssign = (dst: Record<string, unknown>, src: unknown): void => {
    if (!src || typeof src !== 'object' || Array.isArray(src)) return;
    for (const [k, v] of Object.entries(src as Record<string, unknown>)) {
      if (v && typeof v === 'object' && !Array.isArray(v)) {
        const child =
          dst[k] && typeof dst[k] === 'object' && !Array.isArray(dst[k])
            ? (dst[k] as Record<string, unknown>)
            : (dst[k] = {});
        deepAssign(child, v);
      } else if (!(k in dst)) {
        dst[k] = v;
      }
    }
  };
  for (const g of spec?.states?.groups ?? []) {
    for (const item of g.items ?? []) {
      const init = item.initialState as
        | { local?: unknown; query?: unknown; route?: unknown }
        | undefined;
      if (init?.local) deepAssign(localTree, init.local);
      if (init?.query) deepAssign(queryTree, init.query);
      if (init?.route) deepAssign(routeTree, init.route);
    }
  }
  if (Object.keys(localTree).length) states.push({ scope: '_local', tree: localTree });
  if (Object.keys(queryTree).length) states.push({ scope: 'query', tree: queryTree });
  if (Object.keys(routeTree).length) states.push({ scope: 'route', tree: routeTree });
  // _computed — 레이아웃 raw.computed 키(값은 표현식 문자열이라 shape 는 scalar 폴백).
  if (raw?.computed && typeof raw.computed === 'object' && !Array.isArray(raw.computed)) {
    states.push({ scope: '_computed', tree: raw.computed as Record<string, unknown> });
  }
  return states;
}

/**
 * `_computed` 후보의 표현식을 샘플 컨텍스트로 평가해 값/타입을 부착한 컨텍스트를 만든다.
 *
 * computed 의 raw 값(표현식 문자열) 대신 평가값을 트리에 넣어, 후보 미리보기가 scalar
 * 폴백이 아닌 실제 평가값/타입을 보이게 한다. 평가 실패 키는 원래 값(표현식) 유지.
 *
 * @param  computedTree  raw.computed (key → 표현식 문자열)
 * @param  states  나머지 scope 상태 트리(평가 컨텍스트 구성용)
 * @return 평가된 _computed 트리(key → 평가값)
 */
function evaluateComputedTree(
  computedTree: Record<string, unknown>,
  states: BindingStateInput[],
): Record<string, unknown> {
  // 평가 컨텍스트 — 다른 scope 트리를 루트 변수로 노출(_global/_local/route/query/data_source).
  const sampleContext: Record<string, unknown> = {};
  for (const s of states) {
    if (s.scope === '_computed') continue;
    sampleContext[s.scope] = s.tree;
  }
  const out: Record<string, unknown> = {};
  for (const [key, expr] of Object.entries(computedTree)) {
    if (typeof expr !== 'string') {
      out[key] = expr;
      continue;
    }
    const result = evaluateComputedPreview(expr, sampleContext);
    out[key] = result.ok ? result.value : expr; // 실패 시 표현식 폴백(무손실).
  }
  return out;
}

/**
 * 페이지 설정 [자동 계산] 미리보기용 샘플 평가 컨텍스트를 빌드한다.
 *
 * ComputedForm/ComputedPreview 의 `sampleContext`(BindingContext) 입력 — `evaluateComputedPreview`
 * 가 식을 평가할 루트 변수를 노출한다. 후보 풀과 **같은 입력**(buildDataSourceInputs/
 * buildStateInputs)에서 도출해 미리보기 평가와 후보 칩이 일관된 샘플을 쓰게 한다(불일치 0).
 *
 * 노출 루트: data_source 각 id(샘플 응답 value) + `_global`/`_local`/`query`/`route`(spec.states)
 * + `_computed`(평가값). useBindingCandidates 의 내부 컨텍스트 구성과 동치.
 *
 * @param  raw   편집 중 레이아웃 raw
 * @param  spec  병합 editor-spec
 * @return key → 샘플 트리 (식 평가 루트 변수)
 */
export function buildPageSampleContext(
  raw: Record<string, unknown> | undefined | null,
  spec: EditorSpec | null | undefined,
): Record<string, unknown> {
  const dataSources = buildDataSourceInputs(raw, spec);
  const states = buildStateInputs(raw, spec);
  const ctx: Record<string, unknown> = {};
  // data_source 샘플은 id 를 루트로 노출(`{{ products.data.data }}` 식 평가).
  for (const ds of dataSources) {
    if (ds.sample !== undefined) ctx[ds.id] = ds.sample;
  }
  // 상태 트리는 scope 를 루트로 노출. _computed 는 평가값으로 치환(미리보기 일관).
  for (const s of states) {
    if (s.scope === '_computed') {
      ctx[s.scope] = evaluateComputedTree(s.tree as Record<string, unknown>, states);
    } else {
      ctx[s.scope] = s.tree;
    }
  }
  return ctx;
}

/**
 * 데이터 칩 후보 풀을 빌드한다 (캔버스 오버레이·페이지 설정 모달 공용).
 *
 * @param  input  raw·spec·evaluateComputed 옵션
 * @return BindingCandidate[] (평탄 후보 목록)
 */
export function useBindingCandidates(input: UseBindingCandidatesInput): BindingCandidate[] {
  const { raw, spec, evaluateComputed } = input;
  return useMemo<BindingCandidate[]>(() => {
    const dataSources = buildDataSourceInputs(raw, spec);
    const states = buildStateInputs(raw, spec);

    // _computed 후보에 평가값 부착(옵션). 캔버스 오버레이는 미사용(기존 동작 유지).
    if (evaluateComputed) {
      const computedIdx = states.findIndex((s) => s.scope === '_computed');
      if (computedIdx >= 0) {
        const evaluated = evaluateComputedTree(
          states[computedIdx].tree as Record<string, unknown>,
          states,
        );
        states[computedIdx] = { scope: '_computed', tree: evaluated };
      }
    }

    return buildBindingCandidates({ dataSources, states, spec: spec ?? undefined });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [raw, spec, evaluateComputed]);
}
