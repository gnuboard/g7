// audit:allow seo-renderer-parity-sync 이번 변경은 노드 키·컨텍스트 처리 추가가 아니라
// 리터럴 판정을 BindingShape 로 위임한 것이다. 봇(SEO) 측 ExpressionEvaluator 는
// 이미 'true'/'false'/'null' 을 리터럴로 해석하고 있었으므로(app/Seo/ExpressionEvaluator.php),
// 이 수정은 React 경로를 봇 경로에 맞춘 것이며 패리티를 좁힌다. 봇 측 대응 변경 없음.
/**
 * 렌더링 관련 헬퍼 함수 모듈
 *
 * DataGrid의 cellChildren, ul의 li 등 반복 컨텍스트에서
 * 자식 컴포넌트를 렌더링할 때 사용하는 헬퍼 함수들을 제공합니다.
 *
 * @packageDocumentation
 */

import React from 'react';
import { DataBindingEngine, BindingContext } from '../DataBindingEngine';
import { TranslationEngine, TranslationContext } from '../TranslationEngine';
import { ComponentDefinition } from '../DynamicRenderer';
import { responsiveManager } from '../ResponsiveManager';
import { getActionDispatcher } from '../ActionDispatcher';
import { createLogger } from '../../utils/Logger';
import { RAW_PREFIX, RAW_MARKER_START, RAW_MARKER_END, RAW_PLACEHOLDER_MARKER, isRawWrapped, unwrapRaw, containsRawMarker, wrapRawDeep, stripRawDeep, stripRawPrefix } from '../rawMarkers';
import { hasPipes } from '../PipeRegistry';
import {
  extractSingleBinding as canonicalExtractSingleBinding,
  isComplexExpression as canonicalIsComplexExpression,
  resolveSingleBindingValue,
} from '../BindingShape';
import type { G7DevToolsInterface } from '../G7CoreGlobals';
import {
  evaluateConditionExpression,
  evaluateConditionBranches,
  isConditionExpression,
  isConditionBranches,
  type ConditionsProperty,
} from './ConditionEvaluator';

const logger = createLogger('RenderHelpers');

/**
 * G7Core.devTools 인터페이스 가져오기
 *
 * G7DevToolsCore.getInstance() 직접 호출 대신 G7Core.devTools를 사용합니다.
 * DevTools가 비활성화되거나 초기화되지 않은 경우 안전하게 undefined 반환
 */
function getDevTools(): G7DevToolsInterface | undefined {
  try {
    const G7Core = (window as any).G7Core;
    return G7Core?.devTools;
  } catch {
    return undefined;
  }
}

/**
 * 공유 DataBindingEngine 인스턴스
 *
 * 표현식 평가에서 재사용하여 성능 최적화
 */
const sharedBindingEngine = new DataBindingEngine();

/**
 * renderItemChildren 함수 옵션
 */
export interface RenderItemChildrenOptions {
  /**
   * TranslationContext - 다국어 번역에 필요
   */
  translationContext?: TranslationContext;

  /**
   * DataBindingEngine 인스턴스 - 미제공 시 새로 생성
   */
  bindingEngine?: DataBindingEngine;

  /**
   * TranslationEngine 인스턴스 - 미제공 시 싱글톤 사용
   */
  translationEngine?: TranslationEngine;

  /**
   * ActionDispatcher 인스턴스 - 미제공 시 싱글톤 사용
   * CardGrid, DataGrid 등 composite 컴포넌트에서 actions 바인딩에 필요
   */
  actionDispatcher?: any;

  /**
   * 부모 컴포넌트 컨텍스트 (state, setState)
   *
   * expandChildren 등에서 부모 컴포넌트의 로컬 상태를 업데이트할 때 사용합니다.
   * 이 컨텍스트가 전달되면 G7Core.state.setLocal()이 부모의 dynamicState를 업데이트합니다.
   */
  componentContext?: { state?: any; setState?: (updates: any) => void };

  /**
   * remount 키 생성 함수
   *
   * DynamicRenderer의 getRemountKey 함수를 전달받아 cellChildren에서도
   * remount 핸들러가 작동하도록 지원합니다.
   *
   * @param componentId - 컴포넌트 ID
   * @param fallbackKey - ID가 없을 때 사용할 기본 키
   * @returns React key로 사용할 문자열
   */
  getRemountKey?: (componentId: string | undefined, fallbackKey: string) => string;
}

/**
 * 복잡한 표현식 여부 감지
 *
 * 판정은 BindingShape 정본에 위임한다 — 이 모듈의 방언이 종전 6종 중 최대집합이었고,
 * 그것이 정본이 되었다. 다른 렌더 경로들이 좁은 집합을 쓰던 것이 비대칭의 원인이었다.
 *
 * @since engine-v1.55.0
 */
const isComplexExpression = canonicalIsComplexExpression;

/**
 * 단일 바인딩 표현식 추출
 *
 * `{{expression}}` 형태에서 expression 부분만 추출한다.
 * 판정은 BindingShape 정본에 위임한다 (따옴표·이스케이프·중괄호 균형 추적).
 *
 * @since engine-v1.55.0
 */
const extractSingleBinding = canonicalExtractSingleBinding;

/**
 * iteration source 표현식을 평가하여 배열을 반환합니다.
 *
 * 단순 경로(`users.data`)와 복잡한 표현식(`roles?.data ?? []`) 모두 지원합니다.
 * 표현식 여부에 따라 적절한 평가 방식을 선택하여 원본 타입(배열)을 반환합니다.
 *
 * @param source - iteration source 문자열 (예: "roles?.data ?? []", "users.data")
 * @param context - 데이터 컨텍스트
 * @param bindingEngine - DataBindingEngine 인스턴스
 * @returns 평가된 배열 또는 원본 값
 *
 * @example
 * ```typescript
 * // 복잡한 표현식
 * const data = resolveIterationSource("roles?.data ?? []", context, bindingEngine);
 * // 결과: [{id: 1, name: "admin"}, ...]
 *
 * // 단순 경로
 * const data = resolveIterationSource("users.data", context, bindingEngine);
 * // 결과: [{id: 1, name: "홍길동"}, ...]
 * ```
 */
export function resolveIterationSource(
  source: string,
  context: BindingContext,
  bindingEngine: DataBindingEngine
): any {
  // {{...}} 형식의 표현식에서 중괄호 제거
  let normalizedSource = source;
  if (source.startsWith('{{') && source.endsWith('}}')) {
    normalizedSource = source.slice(2, -2).trim();
  }

  // 파이프 표현식은 evaluatePipeExpression 으로 평가한다 (원본 타입 = 배열 유지).
  // isComplexExpression 은 `|` 를 연산자로 보므로 그대로 두면 evaluateExpression 이
  // JS 비트 OR 로 평가해 배열이 아닌 값이 나오고, 그러면 반복 대상이 없어져
  // 목록 전체가 렌더되지 않는다 (에러 없이 빈 화면). @since engine-v1.54.10
  if (hasPipes(normalizedSource)) {
    try {
      return bindingEngine.evaluatePipeExpression(normalizedSource, context, { skipCache: true });
    } catch (error) {
      logger.warn(`resolveIterationSource: 파이프 표현식 평가 실패: ${source}`, error);
      return undefined;
    }
  }

  // 표현식 여부 확인 (?, :, ||, &&, !, 산술/비교 연산자 등)
  if (isComplexExpression(normalizedSource)) {
    // 표현식인 경우 evaluateExpression 사용 (원본 타입 유지)
    try {
      return bindingEngine.evaluateExpression(normalizedSource, context);
    } catch (error) {
      logger.warn(`resolveIterationSource: 표현식 평가 실패: ${source}`, error);
      return undefined;
    }
  }

  // 단순 경로인 경우 resolve 사용
  return bindingEngine.resolve(normalizedSource, context, { skipCache: true });
}

/**
 * 격리된 상태 컨텍스트 인터페이스
 */
export interface IsolatedContextValue {
  state: Record<string, any>;
  setState: (path: string, value: any) => void;
  getState: (path: string) => any;
  mergeState: (updates: Record<string, any>) => void;
}

/**
 * 컴포넌트 컨텍스트 인터페이스
 */
export interface ComponentContext {
  state?: Record<string, any>;
  setState?: (updates: any) => void;
  isolatedContext?: IsolatedContextValue | null;
  /** 부모 DynamicRenderer의 전체 데이터 컨텍스트 (데이터소스 포함) @since engine-v1.19.1 */
  parentDataContext?: Record<string, any>;
}

/**
 * 효과적인 데이터 컨텍스트를 생성합니다.
 *
 * baseContext에 componentContext의 상태를 병합하여 바인딩에 사용할 수 있는
 * 완전한 컨텍스트를 생성합니다. _local, _global, _isolated 상태를 모두 포함합니다.
 *
 * @param baseContext - 기본 데이터 컨텍스트 (_local, _global 등 포함)
 * @param componentContext - 컴포넌트별 로컬 상태 및 격리 상태 컨텍스트
 * @returns 병합된 효과적인 컨텍스트
 *
 * @example
 * ```typescript
 * const effectiveContext = getEffectiveContext(
 *   { _local: { user: {...} }, _global: { settings: {...} } },
 *   { state: { filter: 'active' }, isolatedContext: { state: { step: 1 } } }
 * );
 * // 결과: {
 * //   _local: { user: {...}, filter: 'active' },
 * //   _global: { settings: {...} },
 * //   _isolated: { step: 1 }
 * // }
 * ```
 */
export function getEffectiveContext(
  baseContext: Record<string, any>,
  componentContext?: ComponentContext
): Record<string, any> {
  const result = { ...baseContext };

  // 부모 데이터 컨텍스트 병합 (engine-v1.19.1)
  // DataGrid 등 복합 컴포넌트의 cellChildren/footerCells/footerCardChildren에서
  // 데이터소스(order, active_carriers 등)에 접근할 수 있도록 최상위 컨텍스트에 병합.
  // 기존 baseContext 키(row, value, $value 등)를 덮어쓰지 않음.
  if (componentContext?.parentDataContext) {
    for (const [key, value] of Object.entries(componentContext.parentDataContext)) {
      if (!(key in result)) {
        result[key] = value;
      }
    }
  }

  // componentContext.state를 _local에 병합
  if (componentContext?.state) {
    const existingLocal = result._local || {};
    result._local = {
      ...existingLocal,
      ...componentContext.state,
    };
  }

  // isolatedContext.state를 _isolated에 포함
  if (componentContext?.isolatedContext?.state) {
    result._isolated = componentContext.isolatedContext.state;
  }

  return result;
}

/**
 * bindComponentActions 옵션
 */
export interface BindComponentActionsOptions {
  /**
   * 컴포넌트 컨텍스트 (state, setState, isolatedContext)
   * DynamicRenderer에서 컴포넌트 로컬 상태 관리에 사용
   */
  componentContext?: {
    state?: any;
    setState?: (updates: any) => void;
    /** 격리된 상태 컨텍스트 (isolatedState 속성이 있는 컴포넌트에서 제공) */
    isolatedContext?: IsolatedContextValue | null;
  };

  /**
   * ActionDispatcher 인스턴스
   * 미제공 시 getActionDispatcher() 싱글톤 사용
   * DynamicRenderer에서는 props로 전달받은 인스턴스 사용 권장
   */
  actionDispatcher?: any;
}

/**
 * 컴포넌트의 actions 배열을 props에 바인딩합니다.
 *
 * DynamicRenderer의 액션 바인딩 로직과 동일한 패턴을 사용합니다.
 * actions 배열을 이벤트 핸들러(onClick, onChange 등)로 변환합니다.
 *
 * 코드 중복을 최소화하기 위해 공유 유틸리티로 추출되었습니다.
 * DynamicRenderer와 renderItemChildren에서 동일하게 사용됩니다.
 *
 * @param props - 기존 props 객체
 * @param actions - 액션 정의 배열
 * @param context - 데이터 컨텍스트
 * @param options - 추가 옵션 (componentContext 등)
 * @returns 이벤트 핸들러가 바인딩된 props 객체 (actions prop 제거됨)
 *
 * @example
 * ```typescript
 * const actions = [{ type: 'click', handler: 'openModal', target: 'myModal' }];
 * const boundProps = bindComponentActions({ className: 'btn' }, actions, { row: {...} });
 * // 결과: { className: 'btn', onClick: [Function] }
 * ```
 */
export function bindComponentActions(
  props: Record<string, any>,
  actions: any[] | undefined,
  context: Record<string, any>,
  options?: BindComponentActionsOptions
): Record<string, any> {
  if (!actions || !Array.isArray(actions) || actions.length === 0) {
    return props;
  }

  // options에서 전달된 actionDispatcher를 우선 사용, 없으면 싱글톤 사용
  const actionDispatcher = options?.actionDispatcher ?? getActionDispatcher();
  const propsWithActions = { ...props, actions };
  const boundProps = actionDispatcher.bindActionsToProps(
    propsWithActions,
    context,
    options?.componentContext
  );
  // actions prop 제거 (HTML 속성으로 렌더링되지 않도록)
  delete boundProps.actions;

  return boundProps;
}

/**
 * 조건부 렌더링 평가 함수
 *
 * DynamicRenderer의 shouldRender 로직과 동일한 패턴을 사용합니다.
 * `if` 속성을 평가하여 컴포넌트를 렌더링할지 결정합니다.
 *
 * 코드 중복을 최소화하기 위해 공유 유틸리티로 추출되었습니다.
 * DynamicRenderer와 renderItemChildren에서 동일하게 사용됩니다.
 *
 * @param ifCondition - if 속성 값 (예: "{{row.status == 'active'}}")
 * @param context - 데이터 컨텍스트
 * @param bindingEngine - DataBindingEngine 인스턴스
 * @param componentId - 컴포넌트 ID (디버깅용, 선택)
 * @returns 렌더링 여부 (true: 렌더링, false: 숨김)
 *
 * @example
 * ```typescript
 * // 단순 조건
 * evaluateIfCondition("{{row.active}}", { row: { active: true } }, bindingEngine)
 * // 결과: true
 *
 * // 비교 조건
 * evaluateIfCondition("{{row.status == 'active'}}", { row: { status: 'active' } }, bindingEngine)
 * // 결과: true
 *
 * // falsy 값
 * evaluateIfCondition("{{row.count}}", { row: { count: 0 } }, bindingEngine)
 * // 결과: false
 * ```
 */
export function evaluateIfCondition(
  ifCondition: string | boolean | undefined,
  context: BindingContext,
  bindingEngine: DataBindingEngine,
  componentId?: string
): boolean {
  // boolean 값이 이미 해석된 경우 (엔진 prop 사전 해석으로 condition이 boolean이 될 수 있음)
  if (typeof ifCondition === 'boolean') {
    return ifCondition;
  }

  // if 조건이 없으면 항상 렌더링
  if (!ifCondition) {
    return true;
  }

  try {
    // {{variable}} 형태의 단일 바인딩인지 체크
    const singleBindingPath = extractSingleBinding(ifCondition);

    let resolved: any;
    if (singleBindingPath !== null) {
      // 리터럴 → 파이프 → 표현식 → 경로 라우팅 (판정은 BindingShape 정본).
      // 조건은 원본 타입으로 판정한다 — ConditionEvaluator 와 동일 규칙.
      // @since engine-v1.54.9 / v1.55.0
      resolved = resolveSingleBindingValue(
        stripRawPrefix(singleBindingPath),
        context,
        bindingEngine,
        {
          skipCache: true,
          onEmpty: () => {
            logger.warn(
              `evaluateIfCondition: 빈 바인딩 \`{{}}\` (컴포넌트: ${componentId || 'unknown'}) — undefined 로 해석합니다.`,
            );
          },
        },
      );
    } else {
      // 문자열 보간인 경우 resolveBindings 사용
      resolved = bindingEngine.resolveBindings(ifCondition, context, { skipCache: true });
    }

    // 문자열 "false", "0", "", "null", "undefined"를 false로 처리
    if (typeof resolved === 'string') {
      const lowerResolved = resolved.toLowerCase().trim();
      if (lowerResolved === 'false' || lowerResolved === '0' || lowerResolved === '' ||
          lowerResolved === 'null' || lowerResolved === 'undefined') {
        return false;
      }
    }

    // 결과를 boolean으로 변환
    return Boolean(resolved);
  } catch (error) {
    logger.warn(
      `evaluateIfCondition: 조건 평가 실패 (컴포넌트: ${componentId || 'unknown'}):`,
      error
    );
    return false;
  }
}

/**
 * 컴포넌트 렌더링 조건 옵션
 */
export interface EvaluateRenderConditionOptions {
  /**
   * if 속성 (기존 단일 조건, 하위 호환)
   */
  if?: string;

  /**
   * condition 속성 (if의 별칭 — 컴포넌트 내부 children에서 관례적으로 사용)
   * @since engine-v1.17.1
   */
  condition?: string | boolean;

  /**
   * conditions 속성 (AND/OR 그룹 또는 if/else 체인)
   */
  conditions?: ConditionsProperty;
}

/**
 * 컴포넌트의 렌더링 조건을 평가합니다 (if 또는 conditions)
 *
 * `if` 속성과 `conditions` 속성을 모두 지원합니다.
 * - `if`: 기존 단일 조건 (하위 호환)
 * - `conditions`: AND/OR 그룹 또는 if/else 체인
 *
 * 우선순위: `if` 속성이 있으면 먼저 평가, 없으면 `conditions` 평가
 *
 * @param componentDef - 컴포넌트 정의 또는 조건 옵션 ({ if?, conditions? })
 * @param context - 데이터 컨텍스트
 * @param bindingEngine - DataBindingEngine 인스턴스
 * @param componentId - 컴포넌트 ID (디버깅용, 선택)
 * @returns 렌더링 여부 (true: 렌더링, false: 숨김)
 *
 * @example
 * ```typescript
 * // 기존 if 조건 (하위 호환)
 * evaluateRenderCondition(
 *   { if: "{{user.isAdmin}}" },
 *   context,
 *   bindingEngine
 * ); // → true/false
 *
 * // AND 그룹 조건
 * evaluateRenderCondition(
 *   { conditions: { and: ["{{route.id}}", "{{form_data?.data?.author}}"] } },
 *   context,
 *   bindingEngine
 * ); // → 모든 조건이 true일 때만 true
 *
 * // OR 그룹 조건
 * evaluateRenderCondition(
 *   { conditions: { or: ["{{user.isAdmin}}", "{{user.isManager}}"] } },
 *   context,
 *   bindingEngine
 * ); // → 하나라도 true면 true
 *
 * // if/else 체인 (컴포넌트에서 하나라도 true면 렌더링)
 * evaluateRenderCondition(
 *   {
 *     conditions: [
 *       { if: "{{user.role === 'admin'}}" },
 *       { if: "{{user.role === 'manager'}}" }
 *     ]
 *   },
 *   context,
 *   bindingEngine
 * ); // → 어느 조건이라도 true면 렌더링
 * ```
 */
export function evaluateRenderCondition(
  componentDef: EvaluateRenderConditionOptions,
  context: BindingContext,
  bindingEngine: DataBindingEngine,
  componentId?: string
): boolean {
  // 1. 기존 if 속성 우선 (하위 호환)
  if (componentDef.if !== undefined) {
    return evaluateIfCondition(componentDef.if, context, bindingEngine, componentId);
  }

  // 1-1. condition 속성 (if의 별칭 — 컴포넌트 내부 children에서 관례적 사용)
  if (componentDef.condition !== undefined) {
    return evaluateIfCondition(componentDef.condition, context, bindingEngine, componentId);
  }

  // 2. conditions 속성이 없으면 항상 렌더링
  if (componentDef.conditions === undefined) {
    return true;
  }

  const conditions = componentDef.conditions;

  try {
    // 3. 단일 ConditionExpression (문자열, AND, OR)
    if (isConditionExpression(conditions)) {
      return evaluateConditionExpression(conditions, context, bindingEngine);
    }

    // 4. ConditionBranch[] (if/else 체인)
    if (isConditionBranches(conditions)) {
      const result = evaluateConditionBranches(conditions, context, bindingEngine);
      // 컴포넌트에서는 하나라도 매칭되면 렌더링
      return result.matched;
    }

    // 알 수 없는 형식
    logger.warn(
      `evaluateRenderCondition: 알 수 없는 conditions 형식 (컴포넌트: ${componentId || 'unknown'})`
    );
    return true;
  } catch (error) {
    logger.warn(
      `evaluateRenderCondition: conditions 평가 실패 (컴포넌트: ${componentId || 'unknown'}):`,
      error
    );
    return false;
  }
}

/**
 * 컴포넌트 정의에 반응형 오버라이드를 적용합니다.
 *
 * responsive 속성이 있으면 현재 화면 너비에 맞는 오버라이드를 적용합니다.
 * DynamicRenderer의 effectiveComponentDef 로직과 동일한 패턴을 사용합니다.
 *
 * @param componentDef - 원본 컴포넌트 정의
 * @returns 반응형 오버라이드가 적용된 컴포넌트 정의
 */
function applyResponsiveOverrides(componentDef: ComponentDefinition): ComponentDefinition {
  if (!componentDef.responsive) {
    return componentDef;
  }

  const currentWidth = responsiveManager.getWidth();
  const matchedKey = responsiveManager.getMatchingKey(componentDef.responsive, currentWidth);

  if (!matchedKey) {
    return componentDef;
  }

  const override = componentDef.responsive[matchedKey];
  return {
    ...componentDef,
    // props는 얕은 머지 (지정한 속성만 대체, 미지정 속성 유지)
    props: { ...componentDef.props, ...override.props },
    // children, text, if, iteration은 완전 교체
    children: override.children ?? componentDef.children,
    text: override.text ?? componentDef.text,
    if: override.if ?? componentDef.if,
    iteration: override.iteration ?? componentDef.iteration,
  };
}

/**
 * 반복 아이템의 자식 컴포넌트를 렌더링합니다.
 *
 * DataGrid의 cellChildren, ul의 li 등 반복 컨텍스트에서
 * 자식 컴포넌트를 렌더링할 때 사용합니다.
 *
 * 템플릿 엔진의 바인딩, 표현식 평가, 다국어 번역 등
 * 모든 기능을 지원합니다.
 *
 * @param children - 렌더링할 컴포넌트 정의 배열
 * @param itemContext - 반복 아이템 컨텍스트 (row, item, value, index 등)
 * @param componentMap - 컴포넌트 맵 (ComponentRegistry에서 가져온 컴포넌트들)
 * @param keyPrefix - React key 접두사 (선택)
 * @param options - 추가 옵션 (translationContext, bindingEngine 등)
 * @returns React 노드 배열
 *
 * @example
 * ```tsx
 * // DataGrid에서 cellChildren 렌더링
 * const renderedChildren = renderItemChildren(
 *   cellChildren,
 *   { row, value, index },
 *   componentMap,
 *   `cell-${index}`
 * );
 *
 * // ul에서 li 아이템 렌더링
 * const listItems = renderItemChildren(
 *   itemChildren,
 *   { item, index },
 *   componentMap,
 *   `list-item-${index}`
 * );
 * ```
 */
export function renderItemChildren(
  children: ComponentDefinition[],
  itemContext: Record<string, any>,
  componentMap: Record<string, React.ComponentType<any>>,
  keyPrefix?: string,
  options?: RenderItemChildrenOptions
): React.ReactNode[] {
  if (!children || children.length === 0) {
    return [];
  }

  // 엔진 인스턴스 확인 - 없으면 새로 생성
  const bindingEngine = options?.bindingEngine ?? new DataBindingEngine();
  const translationEngine = options?.translationEngine ?? TranslationEngine.getInstance();
  const translationContext = options?.translationContext ?? {
    templateId: '',
    locale: 'ko',
  };

  // Phase 1-1: componentContext가 있으면 자동으로 state를 _local에 병합
  // Phase 2: isolatedContext.state를 _isolated에 포함
  // 이전에는 DataGrid 등에서 수동으로 수행하던 로직
  const effectiveContext = getEffectiveContext(itemContext, options?.componentContext);

  /**
   * 값의 리프 문자열에 남은 `$t:` 토큰을 번역
   *
   * 표현식 평가 결과가 배열/객체인 경우(예: `{{row.badges}}` → `['$t:a', '$t:b']`)에도
   * 번역이 닿도록 재귀 처리한다. raw 마커가 붙은 문자열은 번역 면제이므로 건드리지 않는다.
   *
   * @param value 평가 결과
   * @param context 항목 컨텍스트
   * @returns 번역이 적용된 값
   *
   * @since engine-v1.56.0
   */
  const translateLeafTokens = (value: any, context: Record<string, any>): any => {
    if (typeof value === 'string') {
      if (isRawWrapped(value) || containsRawMarker(value)) return value;
      if (!/\$t:[a-zA-Z0-9._-]+/.test(value)) return value;
      return translationEngine.resolveTranslations(value, translationContext, context);
    }
    if (Array.isArray(value)) {
      return value.map((item) => translateLeafTokens(item, context));
    }
    if (value && typeof value === 'object') {
      const out: Record<string, any> = {};
      for (const [k, v] of Object.entries(value)) out[k] = translateLeafTokens(v, context);
      return out;
    }
    return value;
  };

  /**
   * props 값을 바인딩/표현식 평가하여 해석
   * 반복 렌더링이므로 캐시를 사용하지 않음 (같은 경로가 다른 row에서 다른 값을 가져야 함)
   */
  const resolveValue = (value: any, context: Record<string, any>): any => {
    if (typeof value === 'string') {
      // raw 마커: 번역 면제 — 마커 제거 후 원본 반환
      // @since engine-v1.27.0
      if (isRawWrapped(value)) {
        return unwrapRaw(value);
      }

      // 혼합 보간 시 raw 마커 영역 보호
      // @since engine-v1.27.0
      if (containsRawMarker(value)) {
        const rawSegments: string[] = [];
        const protectedValue = value.replace(
          new RegExp(`${RAW_MARKER_START}([^${RAW_MARKER_END}]*)${RAW_MARKER_END}`, 'g'),
          (_m: string, content: string) => {
            rawSegments.push(content);
            return `${RAW_PLACEHOLDER_MARKER}${rawSegments.length - 1}${RAW_PLACEHOLDER_MARKER}`;
          }
        );
        let translated = protectedValue;
        if (/\$t:[a-zA-Z0-9._-]+/.test(protectedValue)) {
          translated = translationEngine.resolveTranslations(
            protectedValue,
            translationContext,
            context
          );
        }
        return translated.replace(
          new RegExp(`${RAW_PLACEHOLDER_MARKER}(\\d+)${RAW_PLACEHOLDER_MARKER}`, 'g'),
          (_m: string, idx: string) => rawSegments[parseInt(idx)]
        );
      }

      // 다국어 번역
      // $t:defer: prefix는 DynamicRenderer에서 건너뛰고 여기서 처리됨
      // defer: 부분을 제거하고 일반 $t: 번역으로 처리
      if (value.startsWith('$t:defer:')) {
        const normalizedValue = '$t:' + value.slice(9); // '$t:defer:'.length === 9
        return translationEngine.resolveTranslations(
          normalizedValue,
          translationContext,
          context
        );
      }
      if (value.startsWith('$t:')) {
        return translationEngine.resolveTranslations(
          value,
          translationContext,
          context
        );
      }

      // 문자열 **중간**의 `$t:` 토큰 (예: "27$t:admin.roles.form.suffix")
      //
      // 종전에는 `startsWith('$t:')` 만 봤기 때문에 반복 렌더 경로에서는 중간 `$t:` 가
      // 번역되지 않고 원본 키가 그대로 화면에 노출됐다. 일반 컴포넌트 경로
      // (DynamicRenderer.resolveTranslationsDeep)는 이미 정규식으로 중간 토큰을 처리한다.
      //
      // 이 분기는 위의 `$t:defer:` 처리보다 **뒤에** 둔다. 다만 실사용이 실제로 의존하는
      // 것은 분기 순서가 아니라 **항목 컨텍스트(`context`) 전달**이다 — 반복 서브트리 안의
      // `$t:defer:` 32건(고유 24종)은 전부 `|param={{row.x}}` 형태이고, `row` 는
      // DynamicRenderer 시점에 존재하지 않아 이 경로가 항목 컨텍스트와 함께 해소해야 한다.
      // (순서만 바꾸는 변경은 아래 `{{` 가드 때문에 결과가 같다. 컨텍스트를 떨구면 파라미터
      //  값만 조용히 사라진다 — `RenderHelpers.repeatParity.test.tsx` 가 그쪽을 잠근다.)
      //
      // JSON 구조 문자열(`{...}` / `[...]`) 안의 `$t:` 는 데이터의 일부이므로 번역하지 않는다
      // (DynamicRenderer 의 동일 가드 이식). @since engine-v1.56.0
      if (/\$t:[a-zA-Z0-9._-]+/.test(value)) {
        const trimmed = value.trim();
        const looksLikeJson =
          (trimmed.startsWith('{') && trimmed.endsWith('}')) ||
          (trimmed.startsWith('[') && trimmed.endsWith(']'));
        if (!looksLikeJson && !value.includes('{{')) {
          return translationEngine.resolveTranslations(
            value,
            translationContext,
            context
          );
        }
      }

      // 단일 바인딩 표현식
      const singleBindingPath = extractSingleBinding(value);
      if (singleBindingPath !== null) {
        // raw: 접두사 감지 @since engine-v1.27.0
        let isRawBinding = false;
        let effectivePath = singleBindingPath;
        if (singleBindingPath.startsWith(RAW_PREFIX)) {
          isRawBinding = true;
          effectivePath = singleBindingPath.slice(RAW_PREFIX.length);
        }

        // 형태 판정은 `BindingShape` 단일 출처에 맡긴다. 종전에는 이 지점만
        // `hasPipes → isComplexExpression → 경로 탐색` 3분기를 직접 갈라, 판정 통일
        // (engine-v1.55.0)이 모아 둔 **리터럴**(`true`/`false`/`null`/`undefined`)을
        // 이 경로만 몰랐다. 그래서 `{{true}}` 가 조건 자리에서는 `true`, 반복 렌더 prop
        // 자리에서는 경로 탐색으로 새어 `undefined` 가 됐다 — 통일이 없애려던 바로 그
        // 비대칭이 여기에만 남아 있었다.
        //
        // 실행 정책은 종전과 같다: 파이프는 원본 타입 보존(engine-v1.54.9), 평가 실패는
        // 경고 후 `undefined`(값 소실이 화면 전체를 깨뜨리지 않도록).
        // raw: 접두사는 아래 공통 분기(wrapRawDeep)가 담당하므로 제거된 effectivePath 만
        // 넘긴다 — 원본 value 를 넘기면 raw 마커가 이중으로 감싸진다. @since engine-v1.54.3
        // @since engine-v1.56.4
        let evaluationFailed = false;
        let result = resolveSingleBindingValue(effectivePath, context, bindingEngine, {
          skipCache: true,
          onError: (error) => {
            logger.warn('renderItemChildren: 표현식 평가 실패:', error);
            evaluationFailed = true;

            return undefined;
          },
          onEmpty: () => {
            logger.warn('renderItemChildren: 빈 바인딩 `{{}}` 은 해석하지 않습니다.');
          },
        });

        if (evaluationFailed) {
          return undefined;
        }

        // 표현식 평가 결과가 $t: 접두사 문자열이면 번역 처리
        // 예: {{row.published ? '$t:module.key.published' : '$t:module.key.unpublished'}}
        // → 평가 결과 "$t:module.key.published"를 번역하여 "발행" 반환
        // @since engine-v1.28.1
        // 결과가 배열/객체면 리프 문자열까지 재귀로 처리한다 — 종전에는 문자열만 보고
        // 배열/객체 안의 `$t:` 키가 번역되지 않은 채 화면에 노출됐다. @since engine-v1.56.0
        if (!isRawBinding) {
          result = translateLeafTokens(result, context);
        }

        return isRawBinding && result != null ? wrapRawDeep(result) : result;
      }

      // 문자열 보간
      if (value.includes('{{')) {
        // skipCache: true - 반복 렌더링에서 같은 경로가 다른 값을 가져야 함
        const interpolated = bindingEngine.resolveBindings(value, context, { skipCache: true });
        // 보간 결과에 $t: 접두사가 포함되면 번역 처리 @since engine-v1.28.1
        if (typeof interpolated === 'string' && /\$t:[a-zA-Z0-9._-]+/.test(interpolated)) {
          return translationEngine.resolveTranslations(
            interpolated,
            translationContext,
            context
          );
        }
        return interpolated;
      }

      return value;
    }

    if (Array.isArray(value)) {
      return value.map((item) => resolveValue(item, context));
    }

    if (value && typeof value === 'object') {
      // $switch 특수 객체 — 일반 컴포넌트 경로(resolveObject)는 지원하는데 반복 경로에만
      // 없어서, 같은 작성이 여기서는 `{ $switch: "{{...}}", $cases: {...} }` 객체 그대로
      // 컴포넌트에 전달됐다(조용한 미동작). 평가는 엔진에 위임한다.
      // @since engine-v1.56.0
      if (bindingEngine.isSwitchExpression(value)) {
        return bindingEngine.resolveSwitch(value as any, context, { skipCache: true });
      }

      // 액션 정의 객체는 선평가하지 않는다 — 콜백 prop(onAddressSelect 등)에 담긴
      // 액션 정의를 렌더 시점에 해소하면 내부 `{{_local.xxx}}` 가 그 시점 값으로 고정되고,
      // 실행 시점의 최신 상태를 보지 못한다. ActionDispatcher 가 실행 시점에 해소한다.
      // resolveObject 의 동일 규칙을 반복 경로에도 맞춘다. @since engine-v1.56.0
      if (bindingEngine.isActionDefinition(value)) {
        return { ...value };
      }

      const resolved: Record<string, any> = {};
      for (const [k, v] of Object.entries(value)) {
        resolved[k] = resolveValue(v, context);
      }
      return resolved;
    }

    return value;
  };

  /**
   * 컴포넌트 정의의 props를 해석
   */
  const resolveProps = (
    props: Record<string, any> | undefined,
    context: Record<string, any>
  ): Record<string, any> => {
    if (!props) return {};

    const resolved: Record<string, any> = {};
    for (const [key, value] of Object.entries(props)) {
      resolved[key] = resolveValue(value, context);
    }
    return resolved;
  };

  /**
   * iteration이 있는 컴포넌트 렌더링
   * source 배열의 각 아이템에 대해 컴포넌트를 반복 렌더링
   */
  const renderWithIteration = (
    originalChildDef: ComponentDefinition,
    baseKey: string,
    baseContext: Record<string, any>
  ): React.ReactNode[] => {
    // 반응형 오버라이드 적용
    const childDef = applyResponsiveOverrides(originalChildDef);

    const iteration = childDef.iteration as { source: string; item_var: string; index_var?: string } | undefined;
    if (!iteration) return [];

    // source 경로에서 배열 가져오기 (표현식 지원)
    const sourceArray = resolveIterationSource(iteration.source, baseContext, bindingEngine);

    if (!Array.isArray(sourceArray)) {
      logger.warn(`renderItemChildren: iteration.source가 배열이 아닙니다: ${iteration.source}`);
      return [];
    }

    // DevTools: iteration 추적
    const devTools = getDevTools();
    if (devTools?.isEnabled()) {
      const iterationId = `${baseKey}-iteration`;
      devTools.trackIteration(
        iterationId,
        iteration.source,
        iteration.item_var,
        iteration.index_var,
        sourceArray.length
      );
    }

    // 컴포넌트 조회
    const Component = componentMap[childDef.name];
    if (!Component) {
      logger.warn(`renderItemChildren: 컴포넌트를 찾을 수 없습니다: ${childDef.name}`);
      return [];
    }

    // 각 아이템에 대해 컴포넌트 렌더링 (if 조건 실패 시 필터링)
    return sourceArray.flatMap((item: any, itemIndex: number) => {
      // 컨텍스트에 iteration 변수 추가
      // index_var가 지정되어 있으면 해당 이름 사용, 아니면 기본 패턴(item_var + _index) 사용
      const iterationContext = {
        ...baseContext,
        [iteration.item_var]: item,
        [`${iteration.item_var}_index`]: itemIndex,
        // index_var가 명시적으로 지정된 경우 해당 이름으로도 인덱스 추가
        ...(iteration.index_var ? { [iteration.index_var]: itemIndex } : {}),
      };

      // if/condition/conditions 조건 평가 - 조건이 false이면 렌더링하지 않음
      const shouldRender = evaluateRenderCondition(
        { if: childDef.if, condition: childDef.condition, conditions: childDef.conditions },
        iterationContext,
        bindingEngine,
        childDef.id
      );

      // DevTools: if 조건 추적 (iteration 내부)
      // TODO: conditions 속성은 DevTools Phase 5에서 별도 추적 구현 예정
      if (childDef.if && devTools?.isEnabled()) {
        const conditionId = `${baseKey}-iter-${itemIndex}-if`;
        devTools.trackIfCondition(conditionId, childDef.if, shouldRender, childDef.name);
      }

      if (!shouldRender) {
        return [];
      }

      // remount 지원: getRemountKey가 제공되면 사용, 아니면 기본 키 생성
      // childDef.id의 표현식을 해석 (예: "toggle_{{row.id}}" → "toggle_3")
      const resolvedIterationId = childDef.id
        ? String(stripRawDeep(resolveValue(childDef.id, iterationContext)))
        : undefined;
      const iterationFallbackKey = `${baseKey}-iter-${itemIndex}`;
      const iterationKey = options?.getRemountKey
        ? options.getRemountKey(resolvedIterationId, iterationFallbackKey)
        : iterationFallbackKey;

      // props 해석
      const resolvedProps = resolveProps(childDef.props, iterationContext);

      // actions 바인딩 (DynamicRenderer와 동일한 패턴)
      const finalProps = bindComponentActions(resolvedProps, childDef.actions, iterationContext, {
        actionDispatcher: options?.actionDispatcher,
        componentContext: options?.componentContext,
      });

      // text 처리
      let childrenNodes: React.ReactNode = null;
      if (childDef.text !== undefined) {
        childrenNodes = resolveValue(childDef.text, iterationContext);
      } else if (childDef.children && childDef.children.length > 0) {
        childrenNodes = renderItemChildren(
          childDef.children as ComponentDefinition[],
          iterationContext,
          componentMap,
          iterationKey,
          options
        );
      }

      // DevTools: 컴포넌트 렌더링 추적 (iteration 내부)
      if (devTools?.isEnabled()) {
        devTools.trackRender(childDef.name);
      }

      // raw 마커 최종 제거 — 마커는 번역 패스를 건너뛰게 하려는 내부 표식이므로
      // 화면으로 나가기 전에 벗긴다. 단발 렌더 경로는 resolveTranslationsDeep 안에서
      // 벗기지만 이 경로에는 그 패스가 없어 Unicode Noncharacter 두 글자가 그대로
      // DOM 에 실렸다. @since engine-v1.56.3
      return [
        React.createElement(
          Component,
          { key: iterationKey, ...stripRawDeep(finalProps) },
          stripRawDeep(childrenNodes)
        ),
      ];
    });
  };

  /**
   * 자식 컴포넌트 렌더링
   * Phase 1-1: effectiveContext 사용 (componentContext.state가 _local에 자동 병합됨)
   */
  return children.flatMap((originalChildDef, index) => {
    // 반응형 오버라이드 적용
    const childDef = applyResponsiveOverrides(originalChildDef);

    // remount 지원: getRemountKey가 제공되면 사용, 아니면 기본 키 생성
    // childDef.id의 표현식을 해석 (예: "toggle_{{row.id}}" → "toggle_3")
    const resolvedId = childDef.id
      ? String(stripRawDeep(resolveValue(childDef.id, effectiveContext)))
      : undefined;
    const fallbackKey = keyPrefix ? `${keyPrefix}-${resolvedId || index}` : (resolvedId || `child-${index}`);
    const key = options?.getRemountKey
      ? options.getRemountKey(resolvedId, fallbackKey)
      : fallbackKey;

    // iteration이 있는 경우 반복 렌더링
    // if 조건은 renderWithIteration 내부에서 각 아이템 컨텍스트로 평가됨
    if (childDef.iteration) {
      return renderWithIteration(childDef, key, effectiveContext);
    }

    // if/condition/conditions 조건 평가 - 조건이 false이면 렌더링하지 않음
    // iteration이 없는 경우에만 여기서 평가
    const shouldRender = evaluateRenderCondition(
      { if: childDef.if, condition: childDef.condition, conditions: childDef.conditions },
      effectiveContext,
      bindingEngine,
      childDef.id
    );

    // DevTools: if 조건 추적 (일반 컴포넌트)
    // TODO: conditions 속성은 DevTools Phase 5에서 별도 추적 구현 예정
    const devTools = getDevTools();
    if (childDef.if && devTools?.isEnabled()) {
      const conditionId = `${key}-if`;
      devTools.trackIfCondition(conditionId, childDef.if, shouldRender, childDef.name);
    }

    if (!shouldRender) {
      return [];
    }

    // 컴포넌트 조회
    const Component = componentMap[childDef.name];
    if (!Component) {
      logger.warn(`renderItemChildren: 컴포넌트를 찾을 수 없습니다: ${childDef.name}`);
      return [];
    }

    // props 해석
    const resolvedProps = resolveProps(childDef.props, effectiveContext);

    // actions 바인딩 (DynamicRenderer와 동일한 패턴)
    const finalProps = bindComponentActions(resolvedProps, childDef.actions, effectiveContext, {
      actionDispatcher: options?.actionDispatcher,
      componentContext: options?.componentContext,
    });

    // text 처리
    let childrenNodes: React.ReactNode = null;
    if (childDef.text !== undefined) {
      childrenNodes = resolveValue(childDef.text, effectiveContext);
    } else if (childDef.children && childDef.children.length > 0) {
      // 재귀적으로 자식 렌더링
      childrenNodes = renderItemChildren(
        childDef.children as ComponentDefinition[],
        effectiveContext,
        componentMap,
        key,
        options
      );
    }

    // DevTools: 컴포넌트 렌더링 추적 (일반 컴포넌트)
    if (devTools?.isEnabled()) {
      devTools.trackRender(childDef.name);
    }

    // raw 마커 최종 제거 (iteration 경로와 동일) @since engine-v1.56.3
    return [
      React.createElement(Component, { key, ...stripRawDeep(finalProps) }, stripRawDeep(childrenNodes)),
    ];
  });
}

/**
 * 문자열 내의 모든 바인딩 표현식을 컨텍스트 데이터로 치환
 *
 * 단순 변수({{route.id}})뿐 아니라 복잡한 표현식({{route?.id}}, {{a ? b : c}})도 지원합니다.
 * 코드 중복을 피하기 위해 DataBindingEngine의 기능을 재사용합니다.
 *
 * @param template 바인딩 표현식이 포함된 문자열 (예: "/api/users/{{route?.id}}")
 * @param context 데이터 컨텍스트 (예: { route: { id: "123" } })
 * @param bindingEngine DataBindingEngine 인스턴스 (선택, 미제공 시 공유 인스턴스 사용)
 * @returns 치환된 문자열 (예: "/api/users/123")
 *
 * @example
 * ```typescript
 * // 단순 변수 치환
 * resolveExpressionString("/api/users/{{route.id}}", { route: { id: "123" } });
 * // 결과: "/api/users/123"
 *
 * // Optional chaining 지원
 * resolveExpressionString("/api/users/{{route?.id}}", { route: { id: "456" } });
 * // 결과: "/api/users/456"
 *
 * // 복잡한 표현식 지원
 * resolveExpressionString("{{isEdit ? 'update' : 'create'}}", { isEdit: true });
 * // 결과: "update"
 * ```
 */
/**
 * 표현식 해석 옵션
 */
export interface ResolveExpressionOptions {
  /**
   * 캐시 사용 안 함 (기본값: false)
   * true로 설정하면 매 호출마다 새로운 컨텍스트로 평가
   */
  skipCache?: boolean;
}

export function resolveExpressionString(
  template: string,
  context: BindingContext,
  bindingEngineOrOptions?: DataBindingEngine | ResolveExpressionOptions,
  options?: ResolveExpressionOptions
): string {
  // 파라미터 오버로드 처리: (template, context, options) 또는 (template, context, bindingEngine, options)
  let engine: DataBindingEngine;
  let opts: ResolveExpressionOptions | undefined;

  if (bindingEngineOrOptions instanceof DataBindingEngine) {
    engine = bindingEngineOrOptions;
    opts = options;
  } else {
    engine = sharedBindingEngine;
    opts = bindingEngineOrOptions;
  }

  return engine.resolveBindings(template, context, opts);
}

/**
 * 객체 내의 모든 문자열 값에서 바인딩 표현식을 치환
 *
 * 중첩된 객체와 배열도 재귀적으로 처리합니다.
 *
 * @param obj 바인딩 표현식이 포함된 객체
 * @param context 데이터 컨텍스트
 * @param bindingEngine DataBindingEngine 인스턴스 (선택)
 * @returns 치환된 객체
 *
 * @example
 * ```typescript
 * resolveExpressionObject(
 *   { endpoint: "/api/users/{{route?.id}}", params: { status: "{{filter}}" } },
 *   { route: { id: "123" }, filter: "active" }
 * );
 * // 결과: { endpoint: "/api/users/123", params: { status: "active" } }
 * ```
 */
export function resolveExpressionObject<T extends Record<string, any>>(
  obj: T,
  context: BindingContext,
  bindingEngine?: DataBindingEngine
): T {
  const engine = bindingEngine ?? sharedBindingEngine;
  return engine.resolveObject(obj, context) as T;
}

/**
 * classMap 정의 인터페이스
 */
export interface ClassMapDefinition {
  /** 항상 적용되는 기본 클래스 */
  base?: string;
  /** 키 값에 따른 클래스 변형 */
  variants: Record<string, string>;
  /** 변형을 결정하는 키 표현식 (예: "{{row.status}}") */
  key: string;
  /** 매칭되는 변형이 없을 때 사용할 기본 클래스 */
  default?: string;
}

/**
 * resolveClassMap 옵션
 */
export interface ResolveClassMapOptions {
  /**
   * 캐시 사용 안 함 (기본값: true)
   *
   * iteration/cellChildren 컨텍스트에서 사용될 수 있으므로 기본적으로 캐시 비활성화
   */
  skipCache?: boolean;
}

/**
 * classMap 정의를 해석하여 CSS 클래스 문자열로 변환
 *
 * 동적 값에 따라 다른 CSS 클래스를 적용합니다.
 * 중첩 삼항 연산자를 사용하는 복잡한 className 표현식을 대체합니다.
 *
 * 트러블슈팅 주의사항:
 * - iteration 컨텍스트에서 key 평가 시 skipCache: true 적용 (각 row별 다른 값)
 * - variants 객체 자체는 정적이므로 캐싱 가능
 *
 * @param classMap classMap 정의 객체
 * @param context 데이터 컨텍스트
 * @param bindingEngine DataBindingEngine 인스턴스 (선택, 미제공 시 공유 인스턴스 사용)
 * @param options 해석 옵션
 * @returns 최종 CSS 클래스 문자열
 *
 * @example
 * ```typescript
 * const classMap = {
 *   base: "px-2 py-1 rounded-full text-xs font-medium",
 *   variants: {
 *     success: "bg-green-100 text-green-800",
 *     danger: "bg-red-100 text-red-800",
 *     warning: "bg-yellow-100 text-yellow-800"
 *   },
 *   key: "{{row.status}}",
 *   default: "bg-gray-100 text-gray-600"
 * };
 *
 * const context = { row: { status: "success" } };
 * resolveClassMap(classMap, context);
 * // 결과: "px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800"
 * ```
 */
export function resolveClassMap(
  classMap: ClassMapDefinition,
  context: BindingContext,
  bindingEngine?: DataBindingEngine,
  options?: ResolveClassMapOptions
): string {
  const engine = bindingEngine ?? sharedBindingEngine;
  const opts = { skipCache: true, ...options }; // 기본적으로 캐시 비활성화

  // 1. key 표현식 평가 (항상 skipCache)
  let keyValue: string;
  try {
    const resolved = engine.resolveBindings(classMap.key, context, { skipCache: opts.skipCache });
    // 빈 문자열이거나 undefined/null인 경우 처리
    keyValue = resolved?.toString()?.trim() ?? '';
  } catch (error) {
    logger.warn('resolveClassMap: key 평가 실패:', classMap.key, error);
    keyValue = '';
  }

  // 2. variants에서 매칭되는 클래스 찾기
  let variantClass = '';
  if (keyValue && classMap.variants && keyValue in classMap.variants) {
    variantClass = classMap.variants[keyValue];
  } else if (classMap.default) {
    // 매칭되는 variant가 없으면 default 사용
    variantClass = classMap.default;
  }

  // 3. base와 variant 클래스 결합
  const classes: string[] = [];

  if (classMap.base?.trim()) {
    classes.push(classMap.base.trim());
  }

  if (variantClass?.trim()) {
    classes.push(variantClass.trim());
  }

  return classes.join(' ');
}
