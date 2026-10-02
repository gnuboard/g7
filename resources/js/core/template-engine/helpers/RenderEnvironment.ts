/**
 * RenderEnvironment.ts
 *
 * 렌더 환경 — 한 서브트리를 그릴 때 따라야 하는 폭·언어·엔진·액션 처리기·컴포넌트 목록·편집 모드.
 *
 * DynamicRenderer 는 이 값들을 prop 과 React Context 로 받는다. 그런데 컴포넌트가 자식을 직접
 * 그리는 셀 렌더 함수(`renderItemChildren`)와 `G7Core.renderItemChildren` 계열 래퍼, 슬롯을 다시
 * 그리는 SlotContainer 는 React Context 를 읽지 못해 **호스트(관리자 화면) 전역값**을 썼다.
 * 레이아웃 편집기 미리보기는 iframe 이 아니라 같은 문서의 div 라서 호스트 값(창 폭·호스트 언어·
 * 호스트 dispatcher·호스트 템플릿 컴포넌트)이 미리보기 값과 다르고, 그래서 같은 화면 안에서 렌더
 * 경로마다 다른 모양이 됐다(공개 이슈 #141).
 *
 * DynamicRenderer 가 렌더 환경을 안정 ref 에 기록해 `__componentContext.renderEnvRef` 로 노출하고,
 * 셀 렌더 함수·래퍼·SlotContainer 는 「명시 옵션 > 렌더 환경 > 호스트 전역값」 순서로 값을 고른다.
 * 편집기 밖에서는 렌더 환경이 곧 호스트 값이라 동작이 바뀌지 않는다.
 *
 * `./helpers` 배럴에서 export 하지 않는다 — 배럴을 고정 목록으로 모킹하는 테스트가 있어 새 export 가
 * 그 테스트들에서 undefined 가 된다. 쓰는 곳은 이 파일을 직접 import 한다.
 *
 * @since engine-v1.75.2
 */

import type { TranslationContext, TranslationEngine } from '../TranslationEngine';
import type { DataBindingEngine } from '../DataBindingEngine';
import type { ComponentRegistry } from '../ComponentRegistry';

/**
 * 렌더 환경
 *
 * 모든 필드는 선택이다. 비어 있는 필드는 다음 순위의 값으로 채운다.
 *
 * @since engine-v1.75.2
 */
export interface RenderEnvironment {
  /** responsive 분기를 고를 폭(미리보기에서는 기기 폭, 일반 화면에서는 창 폭) */
  responsiveWidth?: number;
  /** 번역 컨텍스트(미리보기에서는 콘텐츠 언어) */
  translationContext?: TranslationContext;
  /** 번역 엔진 */
  translationEngine?: TranslationEngine;
  /** 바인딩 엔진 */
  bindingEngine?: DataBindingEngine;
  /** 액션 처리기(미리보기에서는 격리 dispatcher) */
  actionDispatcher?: any;
  /** 컴포넌트 레지스트리(미리보기에서는 편집 대상 템플릿의 격리 레지스트리) */
  componentRegistry?: ComponentRegistry;
  /** 편집 모드 — true 면 액션을 바인딩하지 않는다(편집 모드의 클릭은 요소 선택만 의미) */
  isEditMode?: boolean;
}

/** 렌더 환경 필드 목록 — 우선순위 해석 순회용 */
const RENDER_ENVIRONMENT_KEYS: (keyof RenderEnvironment)[] = [
  'responsiveWidth',
  'translationContext',
  'translationEngine',
  'bindingEngine',
  'actionDispatcher',
  'componentRegistry',
  'isEditMode',
];

/**
 * 필드마다 「명시 값 > 렌더 환경 > 기본값」 순서로 고른다.
 *
 * `??` 의미를 따른다 — `undefined`·`null` 만 건너뛰고 `false`·`0` 은 값으로 존중한다.
 *
 * @param explicit 호출자가 명시한 값(옵션 객체를 그대로 넘겨도 된다 — 렌더 환경 필드만 읽는다)
 * @param env 렌더 환경(`renderEnv` 또는 `componentContext.renderEnvRef.current`), 없으면 null
 * @param fallback 호스트 기본값
 * @returns 필드별로 확정한 렌더 환경
 *
 * @since engine-v1.75.2
 */
export function resolveRenderEnvironment(
  explicit: Partial<RenderEnvironment> | null | undefined,
  env: RenderEnvironment | null | undefined,
  fallback: RenderEnvironment,
): RenderEnvironment {
  const resolved: RenderEnvironment = {};

  for (const key of RENDER_ENVIRONMENT_KEYS) {
    (resolved as Record<string, unknown>)[key] = explicit?.[key] ?? env?.[key] ?? fallback[key];
  }

  return resolved;
}
