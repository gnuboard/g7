/**
 * NOTE: @since versions (engine-v1.x.x) refer to internal template engine
 * development iterations, not G7 platform release versions.
 */
/**
 * DynamicRenderer.tsx
 *
 * 그누보드7 템플릿 엔진의 동적 렌더링 컴포넌트
 *
 * 주요 기능:
 * - 재귀적 컴포넌트 렌더링 (children 지원)
 * - 반복 렌더링 (iteration 속성)
 * - 조건부 렌더링 (if 속성)
 * - ComponentRegistry 통합
 * - DataBindingEngine 통합
 * - TranslationEngine 통합
 * - ActionDispatcher 통합
 * - React.memo를 통한 성능 최적화
 * - ErrorBoundary를 통한 컴포넌트 레벨 에러 처리
 */

import React, { memo, useMemo, useState, useCallback, useEffect, useLayoutEffect, useRef, Component, ErrorInfo } from 'react';
import { ComponentRegistry } from './ComponentRegistry';
import { DataBindingEngine } from './DataBindingEngine';
import { TranslationEngine, TranslationContext } from './TranslationEngine';
import { ActionDispatcher, ActionDefinition } from './ActionDispatcher';
import { resolveIterationSource, evaluateIfCondition, evaluateRenderCondition, bindComponentActions, resolveClassMap } from './helpers/RenderHelpers';
import { hasPipes } from './PipeRegistry';
import { RAW_PREFIX, RAW_MARKER_START, RAW_MARKER_END, RAW_PLACEHOLDER_MARKER, isRawWrapped, unwrapRaw, containsRawMarker, wrapRawDeep } from './rawMarkers';
import type { ConditionsProperty } from './helpers/ConditionEvaluator';
import { useTransitionState } from './TransitionContext';
import { useResponsive } from './ResponsiveContext';
import { createLogger } from '../utils/Logger';
import { shallowObjectEqual } from '../hooks/useControllableState';
import type { G7DevToolsInterface } from './G7CoreGlobals';

const logger = createLogger('DynamicRenderer');

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
import { responsiveManager } from './ResponsiveManager';
import { FormProvider, FormContextValue, useFormContext, getNestedValue, setNestedValue } from './FormContext';
import { SlotProvider } from './SlotContext';
import { IsolatedStateProvider, useIsolatedState } from './IsolatedStateContext';
import { SortableContainer, SortableItemWrapper, useSortableContext, type SortableStrategy } from './sortable';
import { useParentContext } from './ParentContextProvider';

/**
 * 중첩 객체에 대해 깊은 병합을 수행하는 헬퍼 함수
 *
 * replaceOnlyKeys에 포함된 키(예: 'errors')는 깊은 병합 대신 완전히 교체됩니다.
 * 이를 통해 validation 에러가 누적되지 않고 최신 에러로 갱신됩니다.
 *
 * @param target 기존 상태 객체
 * @param source 업데이트할 객체
 * @returns 깊은 병합된 새 객체
 *
 * @example 일반 객체는 깊은 병합
 * // target: { filter: { searchField: 'all', salesStatus: ['on_sale'] } }
 * // source: { filter: { dateQuick: 'today' } }
 * // result: { filter: { searchField: 'all', salesStatus: ['on_sale'], dateQuick: 'today' } }
 *
 * @example errors는 완전 교체
 * // target: { errors: { name: ["필수"], email: ["필수"] } }
 * // source: { errors: { email: ["필수"] } }
 * // result: { errors: { email: ["필수"] } }  -- name 에러 제거됨
 */
/**
 * 객체의 모든 키가 숫자인지 확인합니다.
 * convertDotNotationToObject가 배열 인덱스를 객체 키로 변환한 경우를 감지합니다.
 */
function hasOnlyNumericKeys(obj: Record<string, any>): boolean {
  const keys = Object.keys(obj);
  return keys.length > 0 && keys.every((k) => /^\d+$/.test(k));
}

export const deepMergeState = (
  target: Record<string, any>,
  source: Record<string, any>
): Record<string, any> => {
  // 깊은 병합에서 제외할 키 목록 (완전 교체되어야 하는 필드들)
  // validation errors 등은 병합하면 이전 오류가 남아있게 됨
  const replaceOnlyKeys = ['errors'];

  const result: Record<string, any> = { ...target };

  for (const key of Object.keys(source)) {
    const sourceValue = source[key];
    const targetValue = target[key];

    // source 값이 null이면 그대로 설정
    if (sourceValue === null) {
      result[key] = null;
      continue;
    }

    // errors 등 특정 키는 항상 완전히 교체 (validation 에러 등은 병합하면 안됨)
    if (replaceOnlyKeys.includes(key)) {
      result[key] = sourceValue;
      continue;
    }

    // source 값이 배열이면 배열로 교체 (배열은 병합하지 않음)
    if (Array.isArray(sourceValue)) {
      result[key] = sourceValue;
      continue;
    }

    // 특수 케이스: target이 배열이고 source가 숫자 키만 가진 객체인 경우
    // convertDotNotationToObject가 "optionInputs.0.values" 같은 경로를
    // { optionInputs: { "0": { values: ... } } } 로 변환하므로,
    // 이를 배열 인덱스로 해석하여 해당 위치의 요소를 병합
    if (
      Array.isArray(targetValue) &&
      sourceValue !== null &&
      typeof sourceValue === 'object' &&
      !Array.isArray(sourceValue) &&
      hasOnlyNumericKeys(sourceValue)
    ) {
      const numericKeys = Object.keys(sourceValue).map((k) => parseInt(k, 10));
      const maxKey = numericKeys.length > 0 ? Math.max(...numericKeys) : 0;

      // Sparse array 방지: source 키가 배열 범위를 크게 초과하면
      // 배열 인덱스가 아닌 ID/키 매핑으로 간주하여 객체로 교체
      if (maxKey >= targetValue.length + numericKeys.length + 10) {
        result[key] = { ...sourceValue };
      } else {
        const arrResult = [...targetValue];
        for (const [srcKey, srcVal] of Object.entries(sourceValue)) {
          const index = parseInt(srcKey, 10);
          if (index >= 0 && index < arrResult.length) {
            if (
              srcVal !== null &&
              typeof srcVal === 'object' &&
              !Array.isArray(srcVal) &&
              arrResult[index] !== null &&
              typeof arrResult[index] === 'object'
            ) {
              arrResult[index] = deepMergeState(arrResult[index], srcVal);
            } else {
              arrResult[index] = srcVal;
            }
          } else if (index >= arrResult.length) {
            arrResult[index] = srcVal;
          }
        }
        result[key] = arrResult;
      }
    } else if (
      // 둘 다 일반 객체인 경우 재귀적으로 깊은 병합
      sourceValue !== null &&
      typeof sourceValue === 'object' &&
      targetValue !== null &&
      typeof targetValue === 'object' &&
      !Array.isArray(targetValue)
    ) {
      result[key] = deepMergeState(targetValue, sourceValue);
    } else {
      // 그 외의 경우 덮어쓰기
      result[key] = sourceValue;
    }
  }

  return result;
};

/**
 * setLocal()이 업데이트한 키를 localDynamicState에서 재귀적으로 제거
 *
 * setLocal()은 dataContext._local을 직접 업데이트하므로,
 * localDynamicState에 남은 stale 값이 deepMergeState에서 우선 적용되는 것을 방지합니다.
 * 객체 키는 재귀적으로 처리하고, 배열/원시값은 해당 키를 삭제합니다.
 *
 * @param target localDynamicState (제거 대상)
 * @param keysToRemove setLocal이 업데이트한 키 구조
 * @returns 해당 키가 제거된 새 객체
 */
export const removeMatchingLeafKeys = (
  target: Record<string, any>,
  keysToRemove: Record<string, any>
): Record<string, any> => {
  const result: Record<string, any> = { ...target };

  for (const key of Object.keys(keysToRemove)) {
    if (!(key in result)) continue;

    const removeValue = keysToRemove[key];
    const targetValue = result[key];

    if (
      removeValue !== null &&
      typeof removeValue === 'object' &&
      !Array.isArray(removeValue) &&
      targetValue !== null &&
      typeof targetValue === 'object' &&
      !Array.isArray(targetValue)
    ) {
      // 양쪽 모두 객체: 재귀적으로 처리
      const cleaned = removeMatchingLeafKeys(targetValue, removeValue);
      if (Object.keys(cleaned).length === 0) {
        delete result[key];
      } else {
        result[key] = cleaned;
      }
    } else {
      // 리프 값 또는 배열: 해당 키 삭제
      delete result[key];
    }
  }

  return result;
};

/**
 * CSS 문자열을 React style 객체로 변환
 *
 * React에서 style prop은 객체여야 하므로, 레이아웃에서 문자열로 정의된
 * CSS 스타일을 자동으로 객체로 변환합니다.
 *
 * @param cssString CSS 문자열 (예: "background-color: #84CC16; color: red")
 * @returns React.CSSProperties 객체
 *
 * @example
 * parseCSSStringToStyleObject("background-color: #84CC16; color: red")
 * // { backgroundColor: '#84CC16', color: 'red' }
 */
const parseCSSStringToStyleObject = (cssString: string): React.CSSProperties => {
  const style: Record<string, string> = {};

  // 세미콜론으로 분리하여 각 선언 처리
  const declarations = cssString.split(';').filter(d => d.trim());

  for (const declaration of declarations) {
    const colonIndex = declaration.indexOf(':');
    if (colonIndex === -1) continue;

    const property = declaration.substring(0, colonIndex).trim();
    const value = declaration.substring(colonIndex + 1).trim();

    if (property && value) {
      // CSS 속성명을 camelCase로 변환 (background-color -> backgroundColor)
      const camelCaseProperty = property.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
      style[camelCaseProperty] = value;
    }
  }

  return style as React.CSSProperties;
};

/**
 * ErrorBoundary Props
 */
interface ErrorBoundaryProps {
  children: React.ReactNode;
  componentId?: string;
  componentName?: string;
  fallback?: React.ReactNode;
}

/**
 * ErrorBoundary State
 */
interface ErrorBoundaryState {
  hasError: boolean;
  error: Error | null;
}

/**
 * 컴포넌트 레벨 에러를 처리하는 ErrorBoundary
 *
 * 렌더링 중 발생하는 에러를 캐치하여 에러 UI를 표시합니다.
 * 전체 페이지가 깨지는 것을 방지하고, 해당 컴포넌트만 에러 상태로 표시합니다.
 */
class ComponentErrorBoundary extends Component<ErrorBoundaryProps, ErrorBoundaryState> {
  constructor(props: ErrorBoundaryProps) {
    super(props);
    this.state = { hasError: false, error: null };
  }

  static getDerivedStateFromError(error: Error): ErrorBoundaryState {
    return { hasError: true, error };
  }

  componentDidCatch(error: Error, errorInfo: ErrorInfo): void {
    logger.error(
      `컴포넌트 렌더링 에러 (ID: ${this.props.componentId}, Name: ${this.props.componentName}):`,
      error,
      errorInfo
    );
  }

  render(): React.ReactNode {
    if (this.state.hasError) {
      // 커스텀 fallback이 있으면 사용
      if (this.props.fallback) {
        return this.props.fallback;
      }

      // 기본 에러 UI - 컴포넌트 로드 실패 메시지
      return (
        <div className="p-4 my-2 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg text-red-800 dark:text-red-400">
          <div className="font-semibold mb-1">
            컴포넌트 로드 실패
          </div>
          <div className="text-xs opacity-80">
            {this.props.componentName && `[${this.props.componentName}] `}
            데이터를 표시할 수 없습니다.
          </div>
          {this.state.error && (
            <details className="mt-2">
              <summary className="cursor-pointer text-xs opacity-70 hover:opacity-100">
                상세 정보
              </summary>
              <div className="mt-1 p-2 bg-red-100 dark:bg-red-900/30 rounded text-xs font-mono whitespace-pre-wrap break-all">
                {this.state.error.message}
              </div>
            </details>
          )}
        </div>
      );
    }

    return this.props.children;
  }
}

/**
 * 컴포넌트 정의 인터페이스
 */
export interface ComponentDefinition {
  /** 컴포넌트 고유 ID */
  id: string;

  /** 컴포넌트 타입 (basic, composite, layout, extension_point) */
  type: 'basic' | 'composite' | 'layout' | 'extension_point';

  /** 컴포넌트 이름 (ComponentRegistry에 등록된 이름 또는 extension_point 이름) */
  name: string;

  /** 컴포넌트에 전달할 props */
  props?: Record<string, any>;

  /** 데이터 바인딩 정의 */
  data_binding?: Record<string, string>;

  /** 인라인 스타일 */
  style?: React.CSSProperties;

  /** 이벤트 액션 정의 */
  actions?: any[];

  /** 자식 컴포넌트 (컴포넌트 정의 또는 텍스트 노드) */
  children?: (ComponentDefinition | string)[];

  /** 간편 텍스트 속성 (children 대신 사용 가능) */
  text?: string;

  /** 반복 렌더링 설정 */
  iteration?: {
    /** 데이터 소스 경로 (예: "products.data") */
    source: string;
    /** 반복 아이템 변수명 (예: "product") */
    item_var: string;
    /** 인덱스 변수명 (선택, 예: "index") */
    index_var?: string;
  };

  /**
   * base 컴포넌트 마킹 (extends 기반 레이아웃에서 LayoutService가 자동 설정)
   *
   * true이면 SPA 네비게이션 시 stable key → 컴포넌트 보존(update)
   * 슬롯 children(페이지 고유 컴포넌트)에는 미설정 → layout_name key → remount
   *
   * @since engine-v1.24.8
   */
  _fromBase?: boolean;

  /** 조건부 렌더링 표현식 (예: "{{user.isAdmin}}") */
  if?: string;

  /**
   * 조건부 렌더링 (if의 별칭 — 컴포넌트 내부 children에서 관례적 사용)
   * 엔진 prop 사전 해석으로 boolean이 될 수 있음
   * @since engine-v1.21.1
   */
  condition?: string | boolean;

  /**
   * 복합 조건부 렌더링 (if의 상위 호환)
   *
   * 지원 형식:
   * - 문자열: 기존 if와 동일 (예: "{{user.isAdmin}}")
   * - AND 그룹: { and: ["{{cond1}}", "{{cond2}}"] } - 모든 조건 true일 때 렌더링
   * - OR 그룹: { or: ["{{cond1}}", "{{cond2}}"] } - 하나라도 true면 렌더링
   * - if/else 체인: [{ if: "{{cond1}}" }, { if: "{{cond2}}" }] - 하나라도 true면 렌더링
   *
   * @since engine-v1.10.0
   */
  conditions?: ConditionsProperty;

  /**
   * 데이터 로딩 중 blur 효과 적용 설정
   *
   * 지원 형식:
   * - boolean: true면 모든 데이터 소스 로딩 완료까지 blur
   * - string: 표현식 (예: "{{_global.isSaving}}")이 truthy일 때 blur
   * - object: 세부 설정
   *   - enabled: blur 활성화 여부
   *   - data_sources: 특정 데이터 소스만 체크 (문자열 또는 배열)
   */
  blur_until_loaded?: boolean | string | {
    enabled: boolean;
    data_sources?: string | string[];
  };

  /**
   * 폼 자동 바인딩을 위한 데이터 키
   *
   * 이 속성이 설정되면 자식 Input/Select/Textarea 컴포넌트에서
   * name prop을 기반으로 자동 value/onChange 바인딩이 활성화됩니다.
   *
   * 예: dataKey="form" + name="email" → _local.form.email에 바인딩
   */
  dataKey?: string;

  /**
   * 변경 사항 추적 여부 (dataKey와 함께 사용)
   *
   * true이면 입력 시 _local.hasChanges를 자동으로 true로 설정
   */
  trackChanges?: boolean;

  /**
   * 자동 바인딩 debounce 설정 (dataKey와 함께 사용)
   *
   * 밀리초 단위로 설정하면 onChange 핸들러가 debounce됩니다.
   * 빈번한 입력 시 성능 최적화에 사용됩니다.
   *
   * @example
   * { "dataKey": "form", "debounce": 300 }
   */
  debounce?: number;

  /**
   * 컴포넌트 생명주기 핸들러
   *
   * 이벤트 기반 actions와 달리, 컴포넌트의 마운트/언마운트 시점에 실행됩니다.
   * form, _local 등 컴포넌트 상태에 접근할 수 있습니다.
   */
  lifecycle?: {
    /** 컴포넌트 마운트 시 실행할 액션 목록 */
    onMount?: ActionDefinition[];
    /** 컴포넌트 언마운트 시 실행할 액션 목록 */
    onUnmount?: ActionDefinition[];
  };

  /**
   * 컴포넌트 이벤트 구독 설정
   *
   * 다른 컴포넌트에서 emitEvent로 발생한 이벤트를 구독합니다.
   * 컴포넌트 마운트 시 자동 구독, 언마운트 시 자동 해제됩니다.
   *
   * 이벤트 데이터는 핸들러에서 {{_eventData}}로 접근할 수 있습니다.
   *
   * @since engine-v1.11.0
   *
   * @example
   * ```json
   * {
   *   "onComponentEvent": [
   *     {
   *       "event": "upload:site_logo",
   *       "handler": "refetchDataSource",
   *       "params": { "id": "site_settings" }
   *     },
   *     {
   *       "event": "form:submitted",
   *       "handler": "setState",
   *       "params": {
   *         "target": "_local",
   *         "value": { "showSuccess": true }
   *       }
   *     }
   *   ]
   * }
   * ```
   */
  onComponentEvent?: Array<{
    /** 구독할 이벤트 이름 (예: "upload:site_logo", "form:submitted") */
    event: string;
    /** 이벤트 발생 시 실행할 핸들러 */
    handler: string;
    /** 핸들러에 전달할 파라미터 */
    params?: Record<string, any>;
    /** 이벤트 핸들러 성공 시 실행할 액션 (선택적) */
    onSuccess?: ActionDefinition | ActionDefinition[];
    /** 이벤트 핸들러 실패 시 실행할 액션 (선택적) */
    onError?: ActionDefinition | ActionDefinition[];
  }>;

  /**
   * 반응형 오버라이드 설정
   *
   * Breakpoint별로 props, children, text, if, iteration을 오버라이드할 수 있습니다.
   *
   * 프리셋:
   * - mobile: 0-767px
   * - tablet: 768-1023px
   * - desktop: 1024px+
   *
   * 커스텀 범위:
   * - "0-599": 0px ~ 599px
   * - "600-899": 600px ~ 899px
   * - "1200-": 1200px 이상
   * - "-599": 599px 이하
   */
  responsive?: {
    [breakpoint: string]: {
      /** 오버라이드할 props (얕은 머지: 지정한 속성만 대체) */
      props?: Record<string, any>;
      /** 완전 교체할 children */
      children?: (ComponentDefinition | string)[];
      /** 완전 교체할 text */
      text?: string;
      /** 완전 교체할 조건부 렌더링 표현식 */
      if?: string;
      /** 완전 교체할 반복 렌더링 설정 */
      iteration?: {
        source: string;
        item_var: string;
        index_var?: string;
      };
    };
  };

  /**
   * 동적 슬롯 ID
   *
   * 컴포넌트를 원래 위치 대신 지정된 SlotContainer로 렌더링합니다.
   * 표현식을 지원하여 동적으로 슬롯을 결정할 수 있습니다.
   *
   * @since engine-v1.10.0
   *
   * @example
   * ```json
   * {
   *   "slot": "{{(_local.visibleFilters || []).includes('category') ? 'basic_filters' : 'detail_filters'}}"
   * }
   * ```
   */
  slot?: string;

  /**
   * 슬롯 내 정렬 순서
   *
   * 같은 슬롯에 여러 컴포넌트가 등록된 경우 정렬 순서를 결정합니다.
   * 낮은 값이 먼저 렌더링됩니다. (기본값: 0)
   *
   * @since engine-v1.10.0
   *
   * @example
   * ```json
   * {
   *   "slot": "basic_filters",
   *   "slotOrder": 1
   * }
   * ```
   */
  slotOrder?: number;

  /**
   * 컴포넌트 전용 커스텀 레이아웃 정의
   *
   * 컴포넌트 내부에서 커스텀 렌더링이 필요한 영역을 정의합니다.
   * 부모-자식 페이지 레이아웃을 위한 slots와는 별개의 용도입니다.
   *
   * 각 키는 컴포넌트에서 정의한 렌더링 영역 이름이며,
   * 값은 해당 영역에 렌더링할 컴포넌트 정의 배열입니다.
   *
   * DynamicRenderer에서 이 속성을 처리하여 컴포넌트 props로 변환합니다.
   * 예: component_layout.item → children prop (item 컨텍스트와 함께 렌더링)
   *
   * @since engine-v1.11.0
   *
   * @example
   * ```json
   * {
   *   "type": "composite",
   *   "name": "RichSelect",
   *   "props": { "options": "{{files}}" },
   *   "component_layout": {
   *     "item": [
   *       { "type": "basic", "name": "Div", "children": [...] }
   *     ],
   *     "selected": [
   *       { "type": "basic", "name": "Span", "text": "{{item.name}}" }
   *     ]
   *   }
   * }
   * ```
   */
  component_layout?: Record<string, (ComponentDefinition | string)[]>;

  /**
   * 확장 자식 컴포넌트 정의 (DataGrid 등에서 사용)
   *
   * DataGrid의 행 확장 영역에서 렌더링할 컴포넌트 정의 배열입니다.
   * 이 속성이 있는 컴포넌트에는 __componentContext prop이 자동 전달되어
   * 확장 영역 내 액션에서 부모 컴포넌트의 상태를 업데이트할 수 있습니다.
   *
   * @since engine-v1.12.0
   */
  expandChildren?: (ComponentDefinition | string)[];

  /**
   * 조건부 스타일 매핑 (classMap)
   *
   * 동적 값에 따라 다른 CSS 클래스를 적용합니다.
   * 중첩 삼항 연산자를 사용하는 복잡한 className 표현식을 대체합니다.
   *
   * @since engine-v1.13.0
   *
   * @example
   * ```json
   * {
   *   "classMap": {
   *     "base": "px-2 py-1 rounded-full text-xs font-medium",
   *     "variants": {
   *       "success": "bg-green-100 text-green-800 dark:bg-green-900/20 dark:text-green-400",
   *       "danger": "bg-red-100 text-red-800 dark:bg-red-900/20 dark:text-red-400",
   *       "warning": "bg-yellow-100 text-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-400"
   *     },
   *     "key": "{{row.status}}",
   *     "default": "bg-gray-100 text-gray-600"
   *   }
   * }
   * ```
   */
  classMap?: {
    /** 항상 적용되는 기본 클래스 */
    base?: string;
    /** 키 값에 따른 클래스 변형 */
    variants: Record<string, string>;
    /** 변형을 결정하는 키 표현식 (예: "{{row.status}}") */
    key: string;
    /** 매칭되는 변형이 없을 때 사용할 기본 클래스 */
    default?: string;
  };

  /**
   * 격리된 상태 스코프 정의
   *
   * 이 속성이 있으면 컴포넌트가 IsolatedStateProvider로 래핑되어
   * 독립적인 상태 스코프를 가집니다. 상태 변경 시 해당 스코프 내의
   * 컴포넌트만 리렌더링됩니다.
   *
   * {{_isolated.xxx}} 바인딩으로 격리된 상태에 접근할 수 있습니다.
   *
   * @since engine-v1.12.0
   *
   * @example
   * ```json
   * {
   *   "type": "basic",
   *   "name": "Div",
   *   "isolatedState": {
   *     "selectedCategories": [null, null, null, null],
   *     "categoryStep": 1
   *   },
   *   "children": [...]
   * }
   * ```
   */
  isolatedState?: Record<string, any>;

  /**
   * 격리된 상태 스코프 ID (DevTools 식별용)
   *
   * 지정하지 않으면 자동 생성됩니다.
   * DevTools에서 격리된 상태를 식별하는 데 사용됩니다.
   *
   * @since engine-v1.12.0
   *
   * @example
   * ```json
   * {
   *   "isolatedState": { "selected": null },
   *   "isolatedScopeId": "category-selector"
   * }
   * ```
   */
  isolatedScopeId?: string;

  /**
   * 정렬 가능한 리스트 렌더링 설정
   *
   * @dnd-kit 기반의 드래그앤드롭 정렬 기능을 제공합니다.
   * iteration과 유사하게 배열 데이터를 렌더링하되, 드래그로 순서 변경이 가능합니다.
   *
   * @since engine-v1.14.0
   *
   * @example
   * ```json
   * {
   *   "sortable": {
   *     "source": "{{_local.form.additional_options}}",
   *     "itemKey": "id",
   *     "strategy": "verticalList",
   *     "handle": "[data-drag-handle]"
   *   },
   *   "itemTemplate": {
   *     "type": "basic",
   *     "name": "Div",
   *     "children": [...]
   *   },
   *   "actions": [{
   *     "event": "onSortEnd",
   *     "handler": "setState",
   *     "params": {
   *       "target": "local",
   *       "form.additional_options": "{{$sortedItems}}"
   *     }
   *   }]
   * }
   * ```
   */
  sortable?: {
    /** 데이터 소스 (배열 바인딩 표현식, 예: "{{_local.form.items}}") */
    source: string;
    /** 아이템 고유 키 필드명 (기본값: 'id') */
    itemKey?: string;
    /** 정렬 전략: verticalList | horizontalList | rectSorting (기본값: 'verticalList') */
    strategy?: 'verticalList' | 'horizontalList' | 'rectSorting';
    /** 드래그 핸들 CSS 선택자 (없으면 전체 아이템이 드래그 가능) */
    handle?: string;
    /** 아이템 변수명 (기본값: '$item') */
    itemVar?: string;
    /** 인덱스 변수명 (기본값: '$index') */
    indexVar?: string;
  };

  /**
   * sortable 사용 시 각 아이템의 템플릿
   *
   * sortable과 함께 사용됩니다.
   * 아이템 컨텍스트 변수 ($item, $index)를 사용하여 각 아이템을 렌더링합니다.
   *
   * @since engine-v1.14.0
   */
  itemTemplate?: ComponentDefinition;

  /**
   * Extension Point에서 전달받은 props
   *
   * 백엔드 LayoutExtensionService에서 extension_point 컴포넌트의 props를
   * 주입된 컴포넌트에 전달할 때 사용됩니다.
   * 데이터 바인딩에서 {{extensionPointProps.xxx}}로 접근할 수 있습니다.
   *
   * @since engine-v1.15.0
   *
   * @example
   * ```json
   * // extension_point 정의 (_tab_basic_info.json)
   * {
   *   "type": "extension_point",
   *   "name": "address_search_slot",
   *   "props": {
   *     "onAddressSelect": {
   *       "handler": "setState",
   *       "params": { "form.zipcode": "{{$event.zonecode}}" }
   *     }
   *   }
   * }
   *
   * // 플러그인 extension JSON에서 사용
   * {
   *   "callbackAction": "{{extensionPointProps.onAddressSelect}}"
   * }
   * ```
   */
  extensionPointProps?: Record<string, any>;

  /**
   * Extension Point에서 전달받은 콜백 액션 객체
   *
   * 백엔드 LayoutExtensionService에서 extension_point 컴포넌트의 callbacks를
   * 주입된 컴포넌트에 전달할 때 사용됩니다.
   * extensionPointProps와 달리 표현식 평가 없이 그대로 전달됩니다.
   * (ActionDispatcher가 실행 시점에 평가)
   *
   * 데이터 바인딩에서 {{extensionPointCallbacks.xxx}}로 접근할 수 있습니다.
   *
   * @since engine-v1.28.0
   */
  extensionPointCallbacks?: Record<string, any>;
}

/**
 * DynamicRenderer Props
 */
export interface DynamicRendererProps {
  /** 렌더링할 컴포넌트 정의 */
  componentDef: ComponentDefinition;

  /** 데이터 컨텍스트 (바인딩에 사용) */
  dataContext: Record<string, any>;

  /** 번역 컨텍스트 */
  translationContext: TranslationContext;

  /** ComponentRegistry 인스턴스 */
  registry: ComponentRegistry;

  /** DataBindingEngine 인스턴스 */
  bindingEngine: DataBindingEngine;

  /** TranslationEngine 인스턴스 */
  translationEngine: TranslationEngine;

  /** ActionDispatcher 인스턴스 */
  actionDispatcher: ActionDispatcher;

  /**
   * 부모 컴포넌트 컨텍스트 (state, setState)
   *
   * 레이아웃 수준에서 상태를 공유하기 위해 사용됩니다.
   * 전달되면 자체 상태 대신 부모의 상태를 사용합니다.
   */
  parentComponentContext?: {
    state: Record<string, any>;
    setState: (updates: Record<string, any>) => void;
  };

  /**
   * 부모 폼 컨텍스트 (prop으로 전달)
   *
   * React Context 대신 prop으로 전달하여 useMemo 타이밍 문제를 해결합니다.
   * FormProvider로 감싸기 전에 자식이 렌더링되는 문제를 우회합니다.
   */
  parentFormContextProp?: FormContextValue;

  /**
   * 부모 데이터 컨텍스트 ($parent 바인딩용)
   *
   * 모달 등 자식 레이아웃에서 부모의 상태(_local, _global, _computed)에
   * 접근할 수 있도록 부모의 extendedDataContext를 전달합니다.
   *
   * 레이아웃 JSON에서 {{$parent._local.xxx}} 형태로 사용합니다.
   *
   * @since engine-v1.16.0
   */
  parentDataContext?: Record<string, any>;

  /**
   * 루트 렌더러 여부
   *
   * true이면 SlotProvider로 래핑합니다. (레이아웃 최상위에서만 true)
   * 자식 DynamicRenderer에서는 false로 전달됩니다.
   */
  isRootRenderer?: boolean;

  /**
   * iteration 컨텍스트 내부 여부
   *
   * true이면 props 바인딩 해석 시 캐시를 사용하지 않습니다.
   * iteration 내에서 같은 표현식이 다른 컨텍스트로 평가되어야 하므로
   * 캐시를 사용하면 첫 번째 아이템의 값이 재사용되는 버그가 발생합니다.
   *
   * @since engine-v1.15.0
   */
  isInsideIteration?: boolean;

  /**
   * 위지윅 편집 모드 여부
   *
   * true이면 컴포넌트에 data-editor-id 속성을 추가하고,
   * 편집기 오버레이가 컴포넌트를 식별할 수 있게 합니다.
   *
   * @since engine-v1.11.0
   */
  isEditMode?: boolean;

  /**
   * 편집 모드에서 컴포넌트 선택 핸들러
   *
   * 컴포넌트가 클릭되면 호출됩니다.
   * 편집기에서 해당 컴포넌트를 선택 상태로 표시합니다.
   *
   * @since engine-v1.11.0
   */
  onComponentSelect?: (componentId: string, event: React.MouseEvent) => void;

  /**
   * 편집 모드에서 컴포넌트 호버 핸들러
   *
   * 마우스가 컴포넌트 위로 이동하면 호출됩니다.
   * 편집기에서 해당 컴포넌트를 하이라이트합니다.
   *
   * @since engine-v1.11.0
   */
  onComponentHover?: (componentId: string | null, event: React.MouseEvent) => void;

  /**
   * 편집 모드에서 드래그 시작 핸들러
   *
   * 컴포넌트가 드래그되기 시작할 때 호출됩니다.
   * 프리뷰 캔버스에서 컴포넌트를 드래그하여 이동할 수 있게 합니다.
   *
   * @since engine-v1.12.0
   */
  onDragStart?: (componentId: string, event: React.DragEvent) => void;

  /**
   * 편집 모드에서 드래그 종료 핸들러
   *
   * 드래그가 종료되면 호출됩니다.
   *
   * @since engine-v1.12.0
   */
  onDragEnd?: (event: React.DragEvent) => void;

  /**
   * 컴포넌트 인덱스 경로 (WYSIWYG 편집기용)
   *
   * 레이아웃 트리에서 컴포넌트의 위치를 나타내는 경로입니다.
   * 예: "0", "0.children.2", "0.children.2.children.0"
   *
   * ID가 없는 컴포넌트를 편집할 때 경로 기반으로 식별합니다.
   *
   * @since engine-v1.13.0
   */
  componentPath?: string;

  /**
   * 레이아웃 key (SPA 네비게이션 시 children remount용)
   *
   * extends 기반 레이아웃에서 _fromBase가 아닌 children의 key에
   * suffix로 포함되어 페이지 전환 시 remount를 보장합니다.
   *
   * @since engine-v1.24.8
   */
  layoutKey?: string;
}

/**
 * DynamicRenderer 에러 클래스
 */
export class DynamicRendererError extends Error {
  constructor(
    message: string,
    public componentId?: string,
    public componentName?: string
  ) {
    super(message);
    this.name = 'DynamicRendererError';
  }
}

/**
 * DynamicRenderer 컴포넌트
 *
 * JSON 정의를 기반으로 React 컴포넌트를 동적으로 렌더링합니다.
 * 재귀적 구조, 반복, 조건부 렌더링을 지원합니다.
 */
const DynamicRenderer: React.FC<DynamicRendererProps> = memo(
  ({
    componentDef,
    dataContext,
    translationContext,
    registry,
    bindingEngine,
    translationEngine,
    actionDispatcher,
    parentComponentContext,
    parentFormContextProp,
    parentDataContext,
    isRootRenderer = false,
    isInsideIteration = false,
    isEditMode = false,
    onComponentSelect,
    onComponentHover,
    onDragStart,
    onDragEnd,
    componentPath,
    layoutKey,
  }) => {
    // 슬롯 컨텍스트는 window.__slotContextValue를 직접 사용
    // React Context 타이밍 이슈로 useSlotContext() 대신 전역 변수 사용

    // 컴포넌트 동적 상태 관리 (loadingActions, apiError, 기타 동적 속성)
    const [localDynamicState, setLocalDynamicState] = useState<Record<string, any>>({
      loadingActions: {},
    });

    /**
     * 렌더 사이클 캐시 초기화 (루트 컴포넌트에서만)
     *
     * 렌더링 시작 시 DataBindingEngine의 렌더 사이클 캐시를 클리어합니다.
     * 이를 통해:
     * - 같은 렌더 사이클 내에서 동일 표현식 재평가 방지 (성능 최적화)
     * - 새 렌더 시 최신 상태값으로 평가 보장
     *
     * engine-v1.17.6: __g7ForcedLocalFields도 렌더 사이클 시작 시 클리어
     * - __g7ForcedLocalFields는 setState/setLocal 호출 시 설정됨
     * - 같은 렌더 사이클 내에서는 누적되어 dynamicState보다 우선 적용
     * - 새 렌더 사이클 시작 시 클리어하여 이전 렌더의 stale 값 제거
     * - 예: validation 실패 후 필드 수정 시 이전 값이 우선 적용되는 문제 방지
     *
     * 루트 컴포넌트 판별: parentComponentContext가 없으면 루트급 (root + modals)
     * engine-v1.17.8: __g7ForcedLocalFields 클리어 + __g7SetLocalOverrideKeys 처리 모두 isRootRenderer 조건 안에서만
     * - 모달도 parentComponentContext 없이 렌더되므로, 모달이 ROOT보다 먼저 렌더링 시
     *   forcedLocalFields를 클리어하면 ROOT가 비동기 setLocal 데이터를 받을 수 없는 문제 방지
     */
    useLayoutEffect(() => {
      if (!parentComponentContext) {
        bindingEngine.startRenderCycle();

        // engine-v1.17.11: 모든 루트급 컴포넌트에서 __g7ForcedLocalFields, __g7PendingLocalState 클리어
        //
        // engine-v1.17.8에서 isRootRenderer(index===0) 조건으로 제한했으나, 이로 인해:
        // - global_toast(index=0)만 클리어, user_layout_root(index=2)는 클리어 안 함
        // - global_toast는 user_layout_root 상태 변경 시 리렌더 안 되므로 클리어 기회 없음
        // - __g7ForcedLocalFields에 stale 값이 영구 잔존 → 커스텀 핸들러의 setState가 무시됨
        //
        // useLayoutEffect는 같은 React commit 내에서 모든 render phase 완료 후 실행되므로,
        // 모달이 먼저 클리어해도 ROOT는 이미 render phase에서 forcedLocalFields를 읽은 상태.
        // React 18+는 모든 상태 업데이트를 자동 배치하므로 동시 리렌더 보장됨.
        //
        // engine-v1.18.2: __g7ForcedLocalFields 조건부 클리어
        // - __g7SetLocalOverrideKeys 존재 = 최근 setLocal() 호출 → forcedFields 유지
        //   (dataContext._local 갱신까지 setLocal 값을 forcedFields로 보호하여 stale 중간 렌더 방지)
        // - __g7SetLocalOverrideKeys 없음 = handleSetState 등의 forcedFields → 즉시 클리어
        if (!(window as any).__g7SetLocalOverrideKeys) {
          (window as any).__g7ForcedLocalFields = undefined;
        }

        // engine-v1.17.10: __g7PendingLocalState 클리어
        // - handleSetState에서 설정한 예상 상태가 렌더 후에도 남아있으면
        //   다음 handleLocalSetState 호출 시 effectivePrev = deepMergeState(prev, pendingState)에서
        //   stale pendingState의 값이 현재 상태를 오염시킴
        // - 렌더 완료 후에는 localDynamicState가 최신 상태이므로 pendingState 불필요
        (window as any).__g7PendingLocalState = null;

        // engine-v1.18.1: __g7SetLocalOverrideKeys 처리는 별도 useLayoutEffect([dataContext._local])로 이동
        // (아래 참조) — dynamicState 클리어를 dataContext._local 갱신 시점까지 지연
      }
    });

    /**
     * engine-v1.27.0: _localInit 데이터로 __g7PendingLocalState/__g7SetLocalOverrideKeys 사전 설정
     *
     * useLayoutEffect는 모든 useEffect보다 먼저 실행됨 (React 보장).
     * _localInit(useEffect)이 localDynamicState를 갱신하기 전에 자식 useEffect가 먼저 실행되면
     * ActionDispatcher가 stale context.state 기반으로 상태 스냅샷을 생성하는 문제 방지.
     * ref 해시 추적으로 동일 데이터 재설정 방지 (사용자 입력 덮어쓰기 방지).
     */
    const localInitPendingSyncRef = useRef<string | null>(null);
    useLayoutEffect(() => {
      if (!parentComponentContext && dataContext._localInit && typeof dataContext._localInit === 'object') {
        const { _forceLocalInit, _merge: initMergeMode, ...initData } = dataContext._localInit as Record<string, any>;
        const syncKey = `${JSON.stringify(initData)}:${_forceLocalInit || 'no-force'}`;

        if (localInitPendingSyncRef.current !== syncKey) {
          localInitPendingSyncRef.current = syncKey;
          const mergeStrategy = initMergeMode || 'shallow';
          // engine-v1.49.2: pending baseline 으로 fresh globalState._local + 기존 pending 사용.
          // useLayoutEffect 클리어 직후 발화 시 pending 이 null 이면 빈 baseline 이 되어
          // globalState 의 직전 setState 결과가 흡수되지 않음. 이로 인해 후속 setLocal 의
          // mergedPending 계산에서 stale pending 우선 사용으로 globalState._local 통째 교체 시
          // 다른 키 손실. fresh globalState 위에 기존 pending 머지로 baseline 정합성 보장.
          const G7Core = (window as any).G7Core;
          const freshGlobalLocal = G7Core?.state?.get?.()?._local || {};
          const pendingState = (window as any).__g7PendingLocalState || {};
          const currentPending = { ...freshGlobalLocal, ...pendingState };

          if (mergeStrategy === 'replace') {
            (window as any).__g7PendingLocalState = {
              loadingActions: currentPending.loadingActions || {},
              ...initData,
              hasChanges: false,
            };
          } else if (mergeStrategy === 'deep') {
            (window as any).__g7PendingLocalState = deepMergeState(currentPending, {
              ...initData,
              hasChanges: false,
            });
          } else {
            (window as any).__g7PendingLocalState = {
              ...currentPending,
              ...initData,
              hasChanges: false,
            };
          }

          // handleLocalSetState에서 pendingToMerge = setLocalOverrides || pendingState 선택 시
          // stale overrides가 pendingState(API 데이터)를 가로채지 않도록 갱신
          const existingOverrides = (window as any).__g7SetLocalOverrideKeys;
          if (existingOverrides && typeof existingOverrides === 'object') {
            if (mergeStrategy === 'replace') {
              (window as any).__g7SetLocalOverrideKeys = { ...initData, hasChanges: false };
            } else {
              (window as any).__g7SetLocalOverrideKeys = deepMergeState(existingOverrides, {
                ...initData,
                hasChanges: false,
              });
            }
          }
        }
      }
    });

    /**
     * setLocal() 키 정리: dataContext._local 변경 시점으로 지연 (engine-v1.18.1)
     *
     * 근본 원인:
     * - setLocal()은 dataContext._local(root.render, startTransition LOW priority)과
     *   dynamicState(actionContext.setState, NORMAL priority)를 모두 업데이트
     * - React가 priority 차이로 별도 배치 처리 시:
     *   1. setState 처리 → dynamicState 갱신 + __g7ForcedLocalFields → 정확한 렌더
     *   2. useLayoutEffect: forcedFields 클리어 + (기존) removeMatchingLeafKeys → dynamicState도 클리어
     *   3. 중간 렌더: forcedFields 없음, dynamicState 클리어, dataContext._local 미갱신 → stale 값 노출 (플리커!)
     *   4. root.render 처리 → dataContext._local 갱신 → 정확한 렌더
     *
     * 수정:
     * - removeMatchingLeafKeys를 dataContext._local 변경 감지 시점으로 지연
     * - dynamicState가 dataContext._local 갱신까지 정확한 값을 유지 → 중간 stale 렌더 방지
     * - 기존 동작 보존: dataContext._local 갱신 후에는 dynamicState에서 해당 키 제거 (중복 방지)
     *
     * engine-v1.18.3: 전역 플래그 클리어를 queueMicrotask로 지연
     * - 복수의 root DynamicRenderer가 존재하는 경우 (global_toast, admin_layout_root 등)
     *   모든 root의 useLayoutEffect가 동일 commit 내에서 순차 실행됨
     * - 이전: 첫 번째 root가 __g7SetLocalOverrideKeys를 즉시 클리어 → 나머지 root는 처리 불가
     *   → _localInit에서 설정된 stale dynamicState가 정리되지 않아 모달 열기 시 이전 값 노출
     * - 수정: queueMicrotask로 클리어를 지연하여 모든 root가 독립적으로 removeMatchingLeafKeys 실행
     *   → 모든 root의 stale dynamicState가 올바르게 정리됨
     */
    useLayoutEffect(() => {
      if (!parentComponentContext) {
        const setLocalKeys = (window as any).__g7SetLocalOverrideKeys;
        if (setLocalKeys) {
          setLocalDynamicState(prev => removeMatchingLeafKeys(prev, setLocalKeys));
          // engine-v1.18.3: 전역 플래그 클리어를 queueMicrotask로 지연
          // 모든 root DynamicRenderer의 useLayoutEffect는 동일 commit phase 내에서
          // 순차적·동기적으로 실행됨. queueMicrotask는 이 동기 블록 이후에 실행되므로
          // 모든 root가 setLocalKeys를 읽고 removeMatchingLeafKeys를 완료한 뒤 클리어됨.
          // 참조 비교로 새 setLocal 호출에 의한 덮어쓰기를 보호함.
          const capturedKeys = setLocalKeys;
          const capturedForced = (window as any).__g7ForcedLocalFields;
          // engine-v1.21.2: getLocal() fallback 스냅샷도 함께 클리어
          const capturedSnapshot = (window as any).__g7LastSetLocalSnapshot;
          queueMicrotask(() => {
            if ((window as any).__g7SetLocalOverrideKeys === capturedKeys) {
              (window as any).__g7SetLocalOverrideKeys = undefined;
            }
            if ((window as any).__g7ForcedLocalFields === capturedForced) {
              (window as any).__g7ForcedLocalFields = undefined;
            }
            // dataContext._local이 갱신되었으므로 setLocal 스냅샷 불필요
            // 참조 비교로 새 setLocal 호출에 의한 덮어쓰기를 보호
            if ((window as any).__g7LastSetLocalSnapshot === capturedSnapshot) {
              (window as any).__g7LastSetLocalSnapshot = undefined;
            }
          });
        }
      }
    }, [dataContext._local]);

    /**
     * @since engine-v1.24.8 _fromBase 컴포넌트의 localDynamicState 초기화
     *
     * _fromBase: true인 컴포넌트는 stable key로 SPA 네비게이션 시에도 보존(unmount/remount 없음).
     * 하지만 이전 페이지에서 설정된 localDynamicState(visibleFilters, hasChanges 등)가
     * 잔존하여 componentContext.state를 통해 모든 하위 컴포넌트에 전파되는 문제 발생.
     *
     * layoutKey(= layout_name)가 변경되면 보존된 컴포넌트의 localDynamicState를 초기화하여
     * 이전 페이지 상태가 새 페이지에 오염되는 것을 방지.
     *
     * - sidebar 메뉴 open/close: React 컴포넌트 내부 useState → localDynamicState와 무관 → 보존됨
     * - Form 자동 바인딩 값: localDynamicState에 저장 → 초기화됨 (새 페이지에서 재설정)
     */
    const prevLayoutKeyRef = useRef(layoutKey);
    useEffect(() => {
      if (componentDef._fromBase && layoutKey && prevLayoutKeyRef.current !== layoutKey) {
        prevLayoutKeyRef.current = layoutKey;
        setLocalDynamicState({ loadingActions: {} });
      }
    }, [layoutKey, componentDef._fromBase]);

    // _localInit 데이터의 해시값 추적 (데이터 변경 감지용)
    const localInitHashRef = useRef<string>('');

    // _forceLocalInit 강제 초기화 적용 여부 추적 (중복 실행 방지용)
    const forceInitAppliedRef = useRef<boolean>(false);

    // @since engine-v1.41.0: _localInit useEffect가 처리 완료한 dataContext._localInit 참조를 추적
    // useMemo(렌더 단계)에서 참조 비교(O(1))로 _localInit 미적용 상태를 감지하여
    // stale dynamicState(init_actions 기본값의 빈 배열 등)가 dataContext._local의
    // API 데이터를 덮어쓰는 것을 방지 (플러그인 onMount setLocal 경합 해소)
    const lastProcessedInitRef = useRef<any>(null);

    // 최신 _local 상태를 참조하는 ref (expandChildren 상태 동기화용)
    // useCallback으로 캐싱된 componentContext에서도 최신 상태에 접근 가능하도록 함
    const latestLocalStateRef = useRef<Record<string, any>>({});

    // 최신 _computed 상태를 참조하는 ref (expandChildren 상태 동기화용)
    // _computed는 _local 기반으로 계산되므로 _local 변경 시 함께 업데이트
    const latestComputedRef = useRef<Record<string, any>>({});

    // 최신 부모 데이터 컨텍스트를 참조하는 ref ($parent 바인딩 stale closure 방지)
    // 모달 등에서 부모 상태 접근 시 항상 최신 값을 반환하도록 함
    const parentDataContextRef = useRef<Record<string, any> | undefined>(parentDataContext);

    // 격리된 상태 컨텍스트 (IsolatedStateProvider 내부에서만 유효)
    const isolatedContext = useIsolatedState();

    // 부모 컨텍스트 훅 (ParentContextProvider 내부에서만 유효)
    // 모달에서 $parent._local 변경 시 선택적 리렌더링을 위해 사용
    const parentContextHook = useParentContext();
    // 훅에서 최신 부모 컨텍스트를 가져오거나, 없으면 prop 사용
    const effectiveParentDataContext = parentContextHook?.getParentDataContext() ?? parentDataContext;

    /**
     * _localInit 처리
     *
     * TemplateApp에서 data_sources의 initLocal 옵션으로 전달된 초기 데이터를
     * 로컬 상태에 병합합니다. 폼 편집 시 API로 받아온 데이터를 _local에 자동 복사합니다.
     *
     * 동작:
     * - dataContext._localInit가 있으면 localDynamicState에 병합
     * - 데이터 내용이 변경되면 (다른 리소스로 이동 시) 새 데이터로 덮어씀
     * - 동일한 데이터는 중복 적용하지 않음 (사용자 수정 데이터 보존)
     *
     * 페이지 전환 시 데이터 갱신:
     * - 211 유저 편집 -> 212 유저 편집 시 _localInit 데이터 해시가 변경됨
     * - 해시가 변경되면 새 데이터로 로컬 상태 초기화
     *
     * refetchOnMount 지원:
     * - _forceLocalInit 타임스탬프가 있으면 해시가 항상 달라짐
     * - 같은 리소스로 재진입해도 강제로 로컬 상태 초기화
     * - _forceLocalInit 플래그는 로컬 상태에 저장하지 않음
     */
    useEffect(() => {
      // _localInit 처리: 데이터가 있으면 컴포넌트 계층 구조와 무관하게 처리
      // 중복 방지는 전역 추적 메커니즘 (__g7LocalInitTracking)으로 처리
      //
      // 배경:
      // - extends 기반 레이아웃에서는 베이스 레이아웃의 루트와 자식 레이아웃이 분리됨
      // - 데이터소스는 자식 레이아웃에 정의되므로 _localInit이 자식 컴포넌트에 전달됨
      // - 이전에는 parentDataContext나 컴포넌트 ID로 판별했으나, extends 레이아웃에서 실패
      //
      // 해결:
      // - _localInit이 있으면 무조건 처리 시도
      // - 전역 해시 추적으로 동일 데이터 중복 적용 방지
      // - componentId 기반 키로 컴포넌트별 독립 추적

      if (dataContext._localInit && typeof dataContext._localInit === 'object') {
        // _forceLocalInit 플래그를 제외한 데이터만 로컬 상태에 병합
        // eslint-disable-next-line @typescript-eslint/no-unused-vars
        const { _forceLocalInit, ...localInitData } = dataContext._localInit;

        // 해시 계산 시 _forceLocalInit 제외 (데이터 내용만 비교)
        // _forceLocalInit 타임스탬프는 refetchOnMount 시 강제 초기화를 위해 사용되지만,
        // 해시 비교에 포함하면 매 렌더링마다 다른 해시가 생성되어 무한 루프 발생
        const currentHash = JSON.stringify(localInitData);
        const hasForceFlag = _forceLocalInit !== undefined;

        // 전역 추적: 컴포넌트와 무관하게 데이터 해시로만 추적 (같은 데이터는 한 번만 처리)
        // - extends 레이아웃에서 여러 컴포넌트가 동일한 _localInit을 받을 수 있음
        // - 컴포넌트 ID 기반 추적은 각 컴포넌트가 독립적으로 처리하여 중복 적용됨
        // - 데이터 해시만으로 추적하여, 같은 데이터는 어떤 컴포넌트에서든 한 번만 처리
        const globalTrackingKey = '__g7LocalInitTracking';
        if (!(window as any)[globalTrackingKey]) {
          (window as any)[globalTrackingKey] = { hash: '', timestamp: undefined };
        }
        const tracking = (window as any)[globalTrackingKey] as { hash: string; timestamp?: number };

        // 추적 키는 데이터 해시 + 타임스탬프 (컴포넌트 ID 제외)
        // refetchOnMount 시 _forceLocalInit 타임스탬프가 변경되면 재적용됨
        const trackingKey = `${currentHash}:${_forceLocalInit || 'no-force'}`;
        const isNewData = tracking.hash !== trackingKey;
        const shouldApply = isNewData;

        if (shouldApply) {
          // 전역 추적 업데이트
          tracking.hash = trackingKey;
          tracking.timestamp = _forceLocalInit;

          // 로컬 ref도 업데이트 (같은 인스턴스 내 중복 방지)
          localInitHashRef.current = currentHash;
          if (hasForceFlag) {
            forceInitAppliedRef.current = true;
          }

          // _merge 메타데이터 추출 (병합 전략: "replace" | "shallow" | "deep", 기본값: "shallow")
          const { _merge: initMergeMode, ...initDataWithoutMeta } = localInitData as Record<string, any>;
          const mergeStrategy = initMergeMode || 'shallow';

          // 새 데이터로 로컬 상태 병합 (기존 loadingActions 등 시스템 상태는 유지)
          // hasChanges를 false로 초기화 (폼 dirty 추적 리셋)
          setLocalDynamicState(prev => {
            if (mergeStrategy === 'replace') {
              // replace: 기존 상태 완전 교체 (loadingActions만 보존)
              return {
                loadingActions: prev.loadingActions || {},
                ...initDataWithoutMeta,
                hasChanges: false,
              };
            } else if (mergeStrategy === 'deep') {
              // deep: 중첩 객체 보존하면서 병합
              return deepMergeState(prev, {
                ...initDataWithoutMeta,
                hasChanges: false,
              });
            } else {
              // shallow (기본값): 최상위 키만 덮어쓰기 (기존 키 보존)
              return {
                ...prev,
                ...initDataWithoutMeta,
                hasChanges: false,
              };
            }
          });

          // 전역 상태도 함께 업데이트 (컴포넌트 로컬 상태와 전역 상태 동기화)
          // G7Core.state.get()._local에서도 데이터를 조회할 수 있도록 함
          if (dataContext._globalSetState) {
            const currentGlobalLocal = (dataContext._global as any)?._local || {};
            let globalLocalUpdate: Record<string, any>;
            if (mergeStrategy === 'replace') {
              globalLocalUpdate = { ...initDataWithoutMeta, hasChanges: false };
            } else if (mergeStrategy === 'deep') {
              globalLocalUpdate = deepMergeState(currentGlobalLocal, { ...initDataWithoutMeta, hasChanges: false });
            } else {
              globalLocalUpdate = { ...currentGlobalLocal, ...initDataWithoutMeta, hasChanges: false };
            }
            dataContext._globalSetState({ _local: globalLocalUpdate });
          }

          // _localInit 데이터가 변경되면 DataBindingEngine 캐시 무효화
          // 이전 페이지의 _local 값이 캐시에 남아있으면 새 데이터가 아닌 캐시된 값을 반환하는 버그 방지
          // 예: Guest 역할 편집 → Admin 역할 편집 시 permission_ids가 캐시된 빈 배열로 반환되는 문제
          bindingEngine.invalidateCacheByKeys(['_local']);

          logger.log('_localInit applied (data changed):', Object.keys(localInitData));
        }

        // engine-v1.41.0: _localInit 처리 완료 후 ref 갱신
        // hash tracking에 의해 shouldApply가 false(skip)된 경우에도 갱신하여
        // extendedDataContext useMemo가 정상 병합(deepMergeState)을 재개하도록 함
        lastProcessedInitRef.current = dataContext._localInit;
      }
    }, [dataContext._localInit, parentDataContext, bindingEngine]);

    // 페이지 전환 상태 구독 (blur_until_loaded 지원)
    const { isTransitioning } = useTransitionState();

    // 부모의 Form 컨텍스트 (자동 바인딩용)
    // prop으로 전달된 것이 있으면 우선 사용 (useMemo 타이밍 문제 해결)
    const contextFormContext = useFormContext();
    const parentFormContext = parentFormContextProp ?? contextFormContext;

    // 반응형 상태 구독
    const { width: responsiveWidth } = useResponsive();

    // Sortable 컨텍스트 (drag handle 자동 지원용)
    const sortableContext = useSortableContext();

    /**
     * 반응형 오버라이드가 적용된 컴포넌트 정의
     *
     * responsive 속성이 있으면 현재 화면 너비에 맞는 오버라이드를 적용합니다.
     * 처리 순서: iterator 변환 → responsive 오버라이드 → 데이터 바인딩 → 다국어 → 액션 바인딩
     */
    const effectiveComponentDef = useMemo(() => {
      // type: "iterator"를 iteration 속성으로 변환
      // JSON에서 { type: "iterator", data: "...", itemName: "..." } 형태로 정의된 경우 처리
      let baseDef = componentDef;
      if ((componentDef as any).type === 'iterator' && (componentDef as any).data) {
        const { data, itemName, indexName, ...rest } = componentDef as any;
        baseDef = {
          ...rest,
          iteration: {
            source: data,
            item_var: itemName || 'item',
            index_var: indexName,
          },
        };
      }

      if (!baseDef.responsive) {
        return baseDef;
      }

      const matchedKey = responsiveManager.getMatchingKey(baseDef.responsive, responsiveWidth);
      if (!matchedKey) {
        return baseDef;
      }

      const override = baseDef.responsive[matchedKey];
      return {
        ...baseDef,
        // props는 얕은 머지 (지정한 속성만 대체, 미지정 속성 유지)
        props: { ...baseDef.props, ...override.props },
        // children, text, if, iteration은 완전 교체
        children: override.children ?? baseDef.children,
        text: override.text ?? baseDef.text,
        if: override.if ?? baseDef.if,
        iteration: override.iteration ?? baseDef.iteration,
      };
    }, [componentDef, responsiveWidth]);

    /**
     * 로컬 상태 업데이트 핸들러
     *
     * setState 호출 시 전달된 모든 속성을 동적으로 병합합니다.
     * 객체 또는 함수형 업데이트(prev => newState)를 지원합니다.
     *
     * 병합 방식:
     * - 기본값: 깊은 병합 (deepMergeState 사용)
     * - __mergeMode: 'shallow'가 포함된 경우: 얕은 병합 (프리셋 적용, 필터 초기화 등)
     *
     * ActionDispatcher의 setState에서 merge: "shallow" 옵션을 사용하면
     * __mergeMode: 'shallow'가 payload에 포함됩니다.
     *
     * 예: setState({ apiError: '...' }), setState(prev => ({ ...prev, count: prev.count + 1 }))
     */
    const handleLocalSetState = useCallback((updates: any) => {
      // __setStateId 추출 (DevTools 렌더링 추적용)
      const setStateId = updates?.__setStateId;

      setLocalDynamicState((prev) => {
        // __g7PendingLocalState가 있으면 prev와 병합하여 최신 상태 반영
        // setParentLocal 호출 직후 함수형 업데이트가 호출되면 prev에는 아직 반영 안됨
        //
        // CRITICAL: setLocal()이 호출된 경우 __g7SetLocalOverrideKeys가 존재함.
        // 이 경우 __g7PendingLocalState는 globalState._local 기반의 전체 스냅샷이므로
        // prev에 있는 사용자 설정값(expandedRows 등 배열)을 초기값으로 덮어쓰는 문제 발생.
        // → setLocal 변경분(__g7SetLocalOverrideKeys)만 병합하여 다른 키 보존.
        // setParentLocal()은 __g7SetLocalOverrideKeys를 설정하지 않으므로 기존 동작 유지.
        const pendingState = (window as any).__g7PendingLocalState;
        const setLocalOverrides = (window as any).__g7SetLocalOverrideKeys;
        const pendingToMerge = setLocalOverrides || pendingState;
        const effectivePrev = pendingToMerge ? deepMergeState(prev, pendingToMerge) : prev;

        // 함수형 업데이트 지원: updates가 함수이면 effectivePrev를 전달하여 호출
        // 함수 결과도 깊은 병합하여 기존 상태 보존 (Form 자동 바인딩 시 다른 필드 유지)
        if (typeof updates === 'function') {
          const funcResult = updates(effectivePrev);
          const merged = deepMergeState(effectivePrev, funcResult);
          return merged;
        }

        // __mergeMode, __setStateId 확인: 'replace' | 'shallow' | 'deep' (기본값)
        const { __mergeMode, __setStateId: _, ...payload } = updates;

        let result;
        if (__mergeMode === 'replace') {
          // 완전 교체: 기존 상태 무시, payload로 완전 교체
          // 폼 초기화, 서버 데이터로 전체 교체 시 사용
          result = payload;
        } else if (__mergeMode === 'shallow') {
          // 얕은 병합: 프리셋 적용, 필터 초기화 등에서 사용
          // 기존 상태의 최상위 키만 덮어씀
          result = { ...effectivePrev, ...payload };
        } else {
          // 깊은 병합 (기본값): 중첩 객체의 기존 필드를 유지하면서 업데이트
          result = deepMergeState(effectivePrev, payload);
        }

        // engine-v1.17.9: __mergeMode가 명시된 호출(커스텀 핸들러의 명시적 setState)일 때
        // __g7ForcedLocalFields도 payload로 업데이트하여 최신 값이 우선되도록 함
        // - sequence 내 setState가 설정한 forcedLocalFields를 커스텀 핸들러가 덮어쓸 수 있게 함
        // - __mergeMode 없는 호출(deep 기본값 = handleSetState 내부 또는 Form 자동 바인딩)은 건너뜀
        //   (handleSetState는 이미 자체적으로 forcedLocalFields를 설정함)
        if (__mergeMode !== undefined) {
          const existingForced = (window as any).__g7ForcedLocalFields;
          if (existingForced) {
            (window as any).__g7ForcedLocalFields = { ...existingForced, ...payload };
          }
        }

        return result;
      });

      // DevTools: 상태 변경 완료 알림 (비동기로 렌더링 후 호출)
      if (setStateId) {
        const devTools = getDevTools();
        if (devTools?.isEnabled()) {
          // 컴포넌트 렌더링 추적
          const componentId = effectiveComponentDef.id || 'unknown';
          const componentName = effectiveComponentDef.name || 'Unknown';

          // 다음 틱에서 completeStateChange 호출 (렌더링 완료 후)
          setTimeout(() => {
            devTools.trackComponentRender?.(
              componentId,
              componentName,
              0, // 렌더링 시간은 측정하기 어려움
              ['_local'], // 접근한 상태 경로
              [] // 바인딩 표현식
            );
            devTools.completeStateChange?.(setStateId);
          }, 0);
        }
      }
    }, [effectiveComponentDef.id, effectiveComponentDef.name]);

    /**
     * 실제 사용할 상태와 setState
     *
     * parentComponentContext가 전달되면 부모의 상태를 사용하고,
     * 그렇지 않으면 자체 로컬 상태를 사용합니다.
     */
    const dynamicState = parentComponentContext?.state ?? localDynamicState;
    const handleSetState = parentComponentContext?.setState ?? handleLocalSetState;

    /**
     * _local을 포함한 확장된 데이터 컨텍스트
     *
     * 레이아웃 JSON에서 {{_local.xxx}} 바인딩을 사용할 수 있도록 합니다.
     * dataContext._local이 있으면 (init_actions에서 설정된 경우) 병합하여 사용합니다.
     *
     * _computedDefinitions가 있으면 최신 _local을 기반으로 _computed를 재계산합니다.
     * 이를 통해 _local 상태 변경 시 _computed 값도 자동으로 갱신됩니다.
     */
    const extendedDataContext = useMemo(() => {
      // dataContext._local이 있으면 dynamicState와 깊은 병합 (init_actions 초기값 + 컴포넌트 상태)
      // 얕은 병합({ ...a, ...b })은 form 같은 중첩 객체를 완전히 대체하여 필드 손실 발생
      // 깊은 병합(deepMergeState)은 중첩 객체의 기존 필드를 유지하면서 업데이트
      //
      // 병합 순서: dataContext._local → dynamicState → __g7ForcedLocalFields
      // 1. dataContext._local: 전역 상태에서 전달된 _local (init_actions, 일반 setLocal)
      // 2. dynamicState: 컴포넌트 로컬 상태 (Form 자동 바인딩 - 사용자 입력 우선)
      // 3. __g7ForcedLocalFields: 비동기 콜백에서 setLocal fallback으로 설정된 특정 필드만 (최우선)
      //
      // engine-v1.17.4: 비동기 콜백에서 setLocal 호출 시 dynamicState가 stale 값을 가질 수 있음
      // __g7ForcedLocalFields는 업데이트된 필드만 저장하여, 해당 필드만 우선 적용
      // 다른 필드의 사용자 입력은 dynamicState에서 보존됨
      // engine-v1.41.0: _localInit이 존재하지만 아직 localDynamicState에 반영되지 않은 경우
      // stale dynamicState(init_actions 기본값의 빈 배열 등)가 dataContext._local의
      // API 데이터를 덮어쓰는 것을 방지 (CKEditor 등 플러그인 onMount setLocal 경합 해소)
      //
      // 첫 렌더 시 dynamicState는:
      // - 신규 컴포넌트: { loadingActions: {} } (useState 초기값) → 건너뛰어도 결과 동일
      // - _fromBase 컴포넌트: 이전 페이지 stale 데이터 → 건너뛰는 것이 정확
      // dataContext._local은 handleRouteChange에서 init_actions + API 데이터가
      // 완전히 머지된 상태이므로 단독 사용 시 불완전하지 않음
      const hasUnappliedInit = dataContext._localInit
        && dataContext._localInit !== lastProcessedInitRef.current;
      let localState: Record<string, any>;
      if (hasUnappliedInit && dataContext._local) {
        localState = dataContext._local;
      } else {
        localState = dataContext._local
          ? deepMergeState(dataContext._local, dynamicState)
          : dynamicState;
      }

      // __g7ForcedLocalFields가 있으면 해당 필드만 최우선으로 병합 (비동기 setLocal fallback 지원)
      const forcedLocalFields = (window as any).__g7ForcedLocalFields;
      if (forcedLocalFields) {
        localState = deepMergeState(localState, forcedLocalFields);
      }

      const result: Record<string, any> = {
        ...dataContext,
        _local: localState,
      };

      // _computedDefinitions가 있으면 최신 _local로 _computed 재계산
      const computedDefinitions = (dataContext as any)._computedDefinitions;
      if (computedDefinitions && Object.keys(computedDefinitions).length > 0) {
        const bindingEngine = new DataBindingEngine();
        // 재계산용 컨텍스트 (최신 _local, _isolated 포함)
        const computedContext = {
          ...result,
          ...(isolatedContext ? { _isolated: isolatedContext.state } : {}),
        };
        const newComputed: Record<string, any> = {};

        // DevTools: 성능 최적화 - 활성화 여부를 루프 밖에서 한 번만 확인
        const devToolsForComputed = getDevTools();
        const isDevToolsEnabled = devToolsForComputed?.isEnabled() ?? false;

        for (const [key, expression] of Object.entries(computedDefinitions)) {
          // DevTools: 성능 최적화 - 활성화된 경우에만 시간 측정
          const computeStartTime = isDevToolsEnabled ? Date.now() : 0;
          const previousValue = isDevToolsEnabled ? (dataContext as any)._computed?.[key] : undefined;
          let computedError: string | undefined;

          try {
            if (typeof expression === 'string') {
              if (expression.startsWith('{{') && expression.endsWith('}}')) {
                // {{...}} 형태의 표현식 평가
                const innerExpression = (expression as string).slice(2, -2).trim();
                const result = bindingEngine.evaluateExpression(innerExpression, computedContext, { skipCache: true });
                newComputed[key] = result;
              } else {
                // 순수 문자열은 그대로 사용
                newComputed[key] = expression;
              }
            } else if (expression && typeof expression === 'object' && '$switch' in expression) {
              // $switch 형태 처리
              const result = bindingEngine.resolveSwitch(expression as any, computedContext, { skipCache: true });
              newComputed[key] = result;
            }
          } catch (error) {
            logger.warn(`Failed to recalculate computed value: ${key}`, error);
            // 에러 시 기존 값 유지
            newComputed[key] = (dataContext as any)._computed?.[key];
            computedError = error instanceof Error ? error.message : String(error);
          }

          // DevTools: Computed 추적 - 값이 변경되었거나 에러가 발생한 경우에만 추적
          if (isDevToolsEnabled && (computedError || previousValue !== newComputed[key])) {
            const computeEndTime = Date.now();
            const duration = computeEndTime - computeStartTime;

            devToolsForComputed.trackComputedProperty?.(
              key,
              typeof expression === 'string' ? expression : JSON.stringify(expression),
              [{ type: '_local', path: '_local', value: result._local }],
              newComputed[key],
              duration,
              effectiveComponentDef.id,
              computedError
            );

            // 값이 변경되었으면 재계산 추적
            if (previousValue !== newComputed[key]) {
              devToolsForComputed.trackComputedRecalc?.(
                key,
                'dependency-change',
                previousValue,
                newComputed[key],
                duration,
                { type: '_local', path: '_local' },
                effectiveComponentDef.id
              );
            }
          }
        }

        result._computed = newComputed;
      }

      // isolatedContext가 있으면 _isolated 네임스페이스 추가
      if (isolatedContext) {
        result._isolated = isolatedContext.state;
      }

      // effectiveParentDataContext가 있으면 $parent 네임스페이스 추가
      // 모달 등에서 {{$parent._local.xxx}} 형태로 부모 상태에 접근 가능
      // ParentContextProvider 내부에서는 훅에서 최신 값을 가져옴
      if (effectiveParentDataContext) {
        result.$parent = effectiveParentDataContext;
      }

      // extensionPointProps가 있으면 데이터 컨텍스트에 추가 (표현식 평가)
      // extension_point 컴포넌트의 props가 주입된 컴포넌트에서 {{extensionPointProps.xxx}}로 접근 가능
      // LayoutExtensionService에서 extension_point의 props를 injectedComponent.extensionPointProps에 추가
      // @since engine-v1.28.0: resolveObject()로 표현식 재귀 평가 (일반 props와 동일 수준)
      if (componentDef.extensionPointProps) {
        result.extensionPointProps = bindingEngine.resolveObject(
          componentDef.extensionPointProps,
          result,
          isInsideIteration ? { skipCache: true } : undefined
        );
      }

      // extensionPointCallbacks가 있으면 데이터 컨텍스트에 추가 (평가 없이 그대로 전달)
      // 액션 객체는 ActionDispatcher가 실행 시점에 표현식을 평가하므로 사전 평가하면 안 됨
      // @since engine-v1.28.0
      if (componentDef.extensionPointCallbacks) {
        result.extensionPointCallbacks = componentDef.extensionPointCallbacks;
      }

      return result;
      // parentContextHook?.version: $parent._local 변경 시 useMemo 재계산 트리거
      // effectiveParentDataContext는 동일한 객체 참조를 반환할 수 있으므로
      // version 변경으로 강제 재계산
      // componentDef.extensionPointProps: extension_point props 변경 시 재계산
      // componentDef.extensionPointCallbacks: extension_point callbacks 변경 시 재계산
    }, [dataContext, dynamicState, isolatedContext?.state, effectiveParentDataContext, parentContextHook?.version, componentDef.extensionPointProps, componentDef.extensionPointCallbacks]);

    // 매 렌더링마다 최신 _local 상태를 ref에 업데이트
    // useCallback으로 캐싱된 componentContext에서도 stateRef.current로 최신 상태 접근 가능
    latestLocalStateRef.current = extendedDataContext._local;

    // 매 렌더링마다 최신 _computed 상태를 ref에 업데이트
    // expandChildren에서 _computed 기반 표현식 평가 시 최신 값 접근 가능
    latestComputedRef.current = (extendedDataContext as any)._computed || {};

    // 매 렌더링마다 최신 부모 데이터 컨텍스트를 ref에 업데이트
    // 모달 내 useCallback 등에서 stale closure 없이 최신 부모 상태 접근 가능
    parentDataContextRef.current = effectiveParentDataContext;

    /**
     * DevTools에 _local/_computed 상태 업데이트
     *
     * 모든 DynamicRenderer에서 DevTools에 상태를 등록합니다.
     * 마지막으로 업데이트된 상태가 DevTools에 표시됩니다.
     * 이를 통해 DevTools 패널에서 _local과 _computed 상태를 실시간으로 확인할 수 있습니다.
     */
    useEffect(() => {
      const devTools = getDevTools();
      if (!devTools?.isEnabled()) return;

      // _local 상태 업데이트 (extendedDataContext._local에 모든 로컬 상태가 병합되어 있음)
      const localState = extendedDataContext._local;
      if (localState) {
        devTools.updateLocalState(localState);
      }

      // _computed 상태 업데이트 (extendedDataContext에서 재계산된 값 사용)
      const computedState = (extendedDataContext as any)._computed;
      if (computedState) {
        devTools.updateComputedState(computedState);
      }

      // $parent 컨텍스트 업데이트 (모달/자식 레이아웃에서 부모 상태 접근용)
      // DevTools에서 {{$parent._local.xxx}} 형태의 바인딩 디버깅 가능
      // effectiveParentDataContext가 있는 경우에만 업데이트 (최상위 레이아웃이 덮어쓰지 않도록)
      if (effectiveParentDataContext) {
        devTools.updateParentContext?.(effectiveParentDataContext);
      }

      // 상태 계층 추적 (g7-state-hierarchy용)
      const componentId = effectiveComponentDef.id || `comp-${Date.now()}`;
      const componentName = effectiveComponentDef.name || 'Unknown';

      // 컴포넌트가 사용하는 상태 소스 추적
      const stateSource = {
        global: Object.keys(dataContext._global || {}),
        local: Object.keys(localState || {}),
        context: parentComponentContext ? Object.keys(parentComponentContext.state || {}) : [],
      };

      const stateProvider = {
        type: parentComponentContext ? 'componentContext' as const : 'dynamicState' as const,
        hasComponentContext: !!parentComponentContext,
        parentId: parentComponentContext ? 'parent' : undefined,
      };

      devTools.trackComponentStateSource?.(componentId, componentName, stateSource, stateProvider);

      // dynamicState 추적
      if (dynamicState && Object.keys(dynamicState).length > 0) {
        devTools.trackDynamicState?.(componentId, dynamicState);
      }

      // 컨텍스트 플로우 추적 (g7-context-flow용)
      const hasExpandChildren = !!(effectiveComponentDef.expandChildren || effectiveComponentDef.props?.expandChildren);
      devTools.trackContextFlow?.(
        componentId,
        componentName,
        !!parentComponentContext,  // contextReceived
        hasExpandChildren,          // passedToChildren
        true                        // usedInRender (렌더링되었으므로 true)
      );
    }, [extendedDataContext._local, dataContext, effectiveComponentDef.id, effectiveComponentDef.name, parentComponentContext, dynamicState, effectiveComponentDef.expandChildren, effectiveComponentDef.props?.expandChildren, effectiveParentDataContext]);

    /**
     * 컴포넌트 컨텍스트 (state, setState, stateRef)
     *
     * ActionDispatcher에서 deepMergeWithState 수행 시 사용되는 상태입니다.
     * extendedDataContext._local을 사용하여 init_actions에서 설정한 값과
     * 컴포넌트 로컬 상태가 모두 포함되도록 합니다.
     *
     * 이전 문제: dynamicState만 사용 → 첫 클릭 시 init_actions 값 손실
     * 해결: extendedDataContext._local 사용 → init_actions 값 + 컴포넌트 상태 병합
     *
     * localDynamicState 의존성 추가: handleLocalSetState가 호출되면 useMemo가 재실행되어
     * 새 componentContext 객체가 생성됨. 이를 통해 자식 컴포넌트(DataGrid 등)의
     * useCallback 의존성(__componentContext?.state)이 변경을 감지할 수 있음.
     *
     * 스프레드 연산자로 state를 새 객체로 생성하여 참조 변경 보장
     *
     * stateRef 추가: DataGrid의 useCallback이 componentContext를 캐싱해도
     * stateRef.current를 통해 항상 최신 _local 상태에 접근 가능.
     * renderExpandContent에서 expandChildren 렌더링 시 최신 상태 반영.
     *
     * computedRef 추가: _computed 값도 _local 기반으로 계산되므로
     * expandChildren에서 _computed 표현식 평가 시 최신 값 접근 가능.
     */
    const componentContext = useMemo(() => ({
      state: { ...extendedDataContext._local },
      setState: handleSetState,
      stateRef: latestLocalStateRef,
      computedRef: latestComputedRef,
      // 격리된 상태 컨텍스트 (target: "isolated" setState 지원)
      isolatedContext,
      // 부모 데이터 컨텍스트 전달 (engine-v1.19.1)
      // DataGrid 등 복합 컴포넌트의 cellChildren/footerCells/footerCardChildren에서
      // iteration 패턴 사용 시 데이터소스(order, active_carriers 등)에 접근 필요.
      // getEffectiveContext()에서 데이터소스 키를 최상위 컨텍스트에 병합함.
      parentDataContext: extendedDataContext,
    }), [extendedDataContext._local, handleSetState, localDynamicState, isolatedContext, extendedDataContext]);

    /**
     * 조건부 렌더링 평가
     *
     * effectiveComponentDef.if 또는 conditions 속성을 평가하여 렌더링 여부 결정
     * evaluateRenderCondition 함수를 사용하여 if와 conditions 모두 지원
     *
     * @since engine-v1.10.0 - conditions 속성 지원 추가
     */
    const shouldRender = useMemo(() => {
      const result = evaluateRenderCondition(
        {
          if: effectiveComponentDef.if,
          condition: effectiveComponentDef.condition,
          conditions: effectiveComponentDef.conditions,
        },
        extendedDataContext,
        bindingEngine,
        effectiveComponentDef.id
      );

      // DevTools에 조건 추적 (if 또는 condition 또는 conditions 속성이 있는 경우)
      const hasCondition = effectiveComponentDef.if || effectiveComponentDef.condition !== undefined || effectiveComponentDef.conditions;
      if (hasCondition) {
        const devTools = getDevTools();
        if (devTools?.isEnabled()) {
          // if 속성이 있으면 기존 방식으로 추적
          if (effectiveComponentDef.if) {
            devTools.trackIfCondition(
              effectiveComponentDef.id || `if-${Date.now()}`,
              effectiveComponentDef.if,
              result
            );
          }
          // TODO: conditions 속성은 DevTools Phase 5에서 별도 추적 구현 예정
        }
      }

      return result;
    }, [effectiveComponentDef.if, effectiveComponentDef.conditions, extendedDataContext, bindingEngine, effectiveComponentDef.id]);

    /**
     * 슬롯 ID 평가
     *
     * slot 속성이 있으면 표현식을 평가하여 실제 슬롯 ID를 결정합니다.
     * skipCache: true 옵션으로 캐시를 무시하여 항상 최신 값을 사용합니다.
     *
     * @since engine-v1.10.0
     */
    const resolvedSlotId = useMemo(() => {
      if (!effectiveComponentDef.slot) return null;

      const slotExpr = effectiveComponentDef.slot;

      // {{...}} 표현식인 경우 평가
      if (slotExpr.startsWith('{{') && slotExpr.endsWith('}}')) {
        const expr = slotExpr.slice(2, -2).trim();
        try {
          // DevTools 추적을 위한 컴포넌트 컨텍스트 정보
          const slotTrackingInfo = {
            componentId: effectiveComponentDef.id,
            componentName: effectiveComponentDef.name,
            propName: 'slot',
          };
          const result = bindingEngine.evaluateExpression(expr, extendedDataContext, slotTrackingInfo);
          logger.log(`[Slot] 표현식 평가: "${expr}" => "${result}" (컴포넌트: ${effectiveComponentDef.id})`);
          return typeof result === 'string' ? result : null;
        } catch (error) {
          logger.warn(
            `slot 표현식 평가 실패 (컴포넌트: ${effectiveComponentDef.id}):`,
            error
          );
          return null;
        }
      }

      // 정적 슬롯 ID
      logger.log(`[Slot] 정적 슬롯 ID: "${slotExpr}" (컴포넌트: ${effectiveComponentDef.id})`);
      return slotExpr;
    }, [effectiveComponentDef.slot, effectiveComponentDef.id, extendedDataContext, bindingEngine]);

    /**
     * 슬롯 등록 키 (중복 등록 방지용)
     *
     * 동일한 컴포넌트가 동일한 슬롯에 중복 등록되는 것을 방지합니다.
     */
    const slotRegistrationKey = useMemo(() => {
      if (!resolvedSlotId || !effectiveComponentDef.id) return '';
      return `${effectiveComponentDef.id}-${resolvedSlotId}-${JSON.stringify(effectiveComponentDef.slotOrder ?? 0)}`;
    }, [resolvedSlotId, effectiveComponentDef.id, effectiveComponentDef.slotOrder]);

    /**
     * 이전 슬롯 ID 추적 (슬롯 변경 시 이전 슬롯에서 제거하기 위함)
     */
    const prevSlotIdRef = useRef<string | null>(null);

    /**
     * 슬롯 등록/해제 처리
     *
     * - slot 속성이 있는 컴포넌트는 원래 위치에서 렌더링되지 않음
     * - 대신 SlotContext에 등록되어 SlotContainer에서 렌더링됨
     * - 슬롯 ID가 변경되면 이전 슬롯에서 제거 후 새 슬롯에 등록
     *
     * 중요: React Context 대신 window.__slotContextValue를 직접 사용
     * - React의 리렌더링 시 Context 값이 defaultValue로 잠시 변경되는 타이밍 이슈 방지
     * - SlotProvider가 항상 전역 변수에 최신 값을 유지
     */
    useEffect(() => {
      // 전역 SlotContext 값 직접 참조 (타이밍 이슈 방지)
      const globalSlotContext = (window as any).__slotContextValue;
      const isSlotEnabled = globalSlotContext?.isEnabled ?? false;

      // 디버그: 슬롯 등록 조건 확인
      if (effectiveComponentDef.slot) {
        logger.log(`[Slot Registration] 컴포넌트: ${effectiveComponentDef.id}, isSlotEnabled: ${isSlotEnabled}, shouldRender: ${shouldRender}, resolvedSlotId: ${resolvedSlotId}`);
      }

      // 슬롯 컨텍스트가 비활성화되었거나 렌더링 조건이 false면 등록 안함
      if (!isSlotEnabled || !shouldRender) {
        if (effectiveComponentDef.slot) {
          logger.log(`[Slot Registration] 등록 스킵 - isSlotEnabled: ${isSlotEnabled}, shouldRender: ${shouldRender}`);
        }
        // 이전에 등록된 경우 해제
        if (prevSlotIdRef.current && effectiveComponentDef.id && globalSlotContext) {
          globalSlotContext.unregisterFromSlot(prevSlotIdRef.current, effectiveComponentDef.id);
          prevSlotIdRef.current = null;
        }
        return;
      }

      // 슬롯 ID가 없으면 등록 안함
      if (!resolvedSlotId || !effectiveComponentDef.id) {
        if (effectiveComponentDef.slot) {
          logger.log(`[Slot Registration] 등록 스킵 - resolvedSlotId: ${resolvedSlotId}, id: ${effectiveComponentDef.id}`);
        }
        // 이전에 등록된 경우 해제
        if (prevSlotIdRef.current && effectiveComponentDef.id && globalSlotContext) {
          globalSlotContext.unregisterFromSlot(prevSlotIdRef.current, effectiveComponentDef.id);
          prevSlotIdRef.current = null;
        }
        return;
      }

      // 슬롯 ID가 변경된 경우 이전 슬롯에서 제거
      if (prevSlotIdRef.current && prevSlotIdRef.current !== resolvedSlotId) {
        globalSlotContext.unregisterFromSlot(prevSlotIdRef.current, effectiveComponentDef.id);
      }

      // 슬롯에 등록 (slot 속성을 제거한 컴포넌트 정의)
      const componentDefWithoutSlot = {
        ...effectiveComponentDef,
        slot: undefined,
        slotOrder: undefined,
      };

      globalSlotContext.registerToSlot(resolvedSlotId, effectiveComponentDef.id, {
        componentDef: componentDefWithoutSlot,
        dataContext: dataContext,
        order: effectiveComponentDef.slotOrder ?? 0,
        parentFormContext: parentFormContextProp ?? null,
        getParentComponentContext: () => componentContext,
        translationContext: translationContext,
        registrationKey: slotRegistrationKey,
      });

      prevSlotIdRef.current = resolvedSlotId;

      // 클린업: 언마운트 시 슬롯에서 제거
      return () => {
        // 클린업 시에도 전역 변수에서 직접 가져옴
        const cleanupSlotContext = (window as any).__slotContextValue;
        if (resolvedSlotId && effectiveComponentDef.id && cleanupSlotContext) {
          cleanupSlotContext.unregisterFromSlot(resolvedSlotId, effectiveComponentDef.id);
        }
      };
    }, [
      // slotContext 의존성 제거 - 전역 변수 사용으로 불필요
      shouldRender,
      resolvedSlotId,
      effectiveComponentDef,
      dataContext,
      parentFormContextProp,
      componentContext,
      translationContext,
      slotRegistrationKey,
    ]);

    /**
     * 값에 대해 재귀적으로 다국어 번역을 처리합니다.
     *
     * $t:defer: prefix가 있는 번역 문자열은 건너뛰고 원본을 유지합니다.
     * 이 문자열은 renderItemChildren에서 올바른 iteration 컨텍스트와 함께 처리됩니다.
     *
     * @example
     * - "$t:common.hello" → 즉시 번역
     * - "$t:defer:admin.modules.vendor|vendor={{row.vendor}}" → 번역 건너뜀 (renderItemChildren에서 처리)
     */
    const resolveTranslationsDeep = useCallback((value: any): any => {
      if (typeof value === 'string') {
        // raw 마커: 번역 면제 — 마커 제거 후 원본 반환
        // @since engine-v1.27.0
        if (isRawWrapped(value)) {
          return unwrapRaw(value);
        }

        // 혼합 보간 시 raw 마커 영역 보호
        // 예: "제목: \uFDD0$t:admin.key\uFDD1 - $t:common.by"
        // 마커 내부의 $t:는 번역에서 제외, 외부의 $t:만 번역
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
          // 나머지 부분만 번역
          let translated = protectedValue;
          if (/\$t:[a-zA-Z0-9._-]+/.test(protectedValue)) {
            translated = translationEngine.resolveTranslations(
              protectedValue,
              translationContext,
              extendedDataContext
            );
          }
          // 플레이스홀더를 원본으로 복원
          return translated.replace(
            new RegExp(`${RAW_PLACEHOLDER_MARKER}(\\d+)${RAW_PLACEHOLDER_MARKER}`, 'g'),
            (_m: string, idx: string) => rawSegments[parseInt(idx)]
          );
        }

        // $t:defer: prefix가 있으면 번역하지 않고 원본 반환
        // renderItemChildren에서 올바른 컨텍스트와 함께 처리됨
        if (value.startsWith('$t:defer:')) {
          return value;
        }
        // 문자열 내에 $t: 번역 패턴이 있는지 확인
        // $t: 뒤에 번역 키(알파벳, 숫자, 점, 대시, 언더스코어)가 오는 패턴만 매칭
        // 예: "$t:common.save", "27$t:admin.roles.form.suffix"
        if (/\$t:[a-zA-Z0-9._-]+/.test(value)) {
          // JSON 구조 문자열 내부의 $t: 토큰은 데이터 자체의 일부이므로 번역하지 않음
          // 예: CodeEditor value prop의 레이아웃 JSON 문자열 {"text":"$t:error.401.title"}
          // @since engine-v1.26.1
          const trimmed = value.trim();
          if (
            (trimmed.startsWith('{') && trimmed.endsWith('}')) ||
            (trimmed.startsWith('[') && trimmed.endsWith(']'))
          ) {
            return value;
          }
          return translationEngine.resolveTranslations(
            value,
            translationContext,
            extendedDataContext
          );
        }
        return value;
      }

      if (Array.isArray(value)) {
        return value.map((item) => resolveTranslationsDeep(item));
      }

      if (value && typeof value === 'object') {
        const result: Record<string, any> = {};
        for (const [k, v] of Object.entries(value)) {
          result[k] = resolveTranslationsDeep(v);
        }
        return result;
      }

      return value;
    }, [translationEngine, translationContext, extendedDataContext]);

    /**
     * 복잡한 표현식 여부 감지
     *
     * 삼항 연산자, 논리 연산자 등이 포함된 경우 true 반환
     */
    const isComplexExpression = useCallback((expr: string): boolean => {
      // 삼항 연산자나 복잡한 표현식 감지 (?, :, ||, &&, !, 산술 연산자, 함수 호출 등)
      // () 추가: $localized(menu.name) 같은 함수 호출 표현식도 복잡한 표현식으로 처리
      return /[?:|&!+\-*/<>=()]/.test(expr);
    }, []);

    /**
     * 단일 바인딩 표현식 매칭 정규식
     *
     * 전체가 {{...}}로 감싸진 표현식을 매칭합니다.
     * 내부에 문자열 리터럴('...' 또는 "...")이 포함된 복잡한 표현식도 지원합니다.
     *
     * 예시:
     * - {{user.name}} -> 매칭
     * - {{row.status === 'active' ? 'yes' : 'no'}} -> 매칭
     * - Hello {{name}} -> 매칭 안됨 (문자열 보간)
     */
    const extractSingleBinding = useCallback((value: string): string | null => {
      // 시작이 {{이고 끝이 }}인지 확인
      if (!value.startsWith('{{') || !value.endsWith('}}')) {
        return null;
      }

      // 중간 내용 추출
      const inner = value.slice(2, -2);

      // 중간에 }}가 있으면 단일 바인딩이 아님 (예: {{a}} {{b}})
      // 단, 문자열 리터럴 안의 }는 제외해야 함
      let inString: string | null = null;
      let braceCount = 0;

      for (let i = 0; i < inner.length; i++) {
        const char = inner[i];
        const prevChar = i > 0 ? inner[i - 1] : '';

        // 이스케이프된 문자는 무시
        if (prevChar === '\\') continue;

        // 문자열 시작/종료 감지
        if ((char === '"' || char === "'") && !inString) {
          inString = char;
        } else if (char === inString) {
          inString = null;
        }

        // 문자열 밖에서 중괄호 카운트
        if (!inString) {
          if (char === '{') braceCount++;
          if (char === '}') braceCount--;

          // 닫는 중괄호가 더 많으면 단일 바인딩이 아님
          if (braceCount < 0) return null;
        }
      }

      // 중괄호가 균형이 맞으면 단일 바인딩
      return braceCount === 0 ? inner.trim() : null;
    }, []);

    /**
     * Props 처리
     *
     * 1. 데이터 바인딩 ({{variable}})
     * 2. 다국어 번역 ($t:key) - 재귀적 처리
     * 3. 액션 바인딩 (onClick 등)
     */
    const resolvedProps = useMemo(() => {
      let props = { ...(effectiveComponentDef.props || {}) };

      // 기본 skipBindingKeys (하위 호환성)
      // 컴포넌트 내부에서 자체적으로 바인딩 처리하는 키들
      const DEFAULT_SKIP_BINDING_KEYS = ['cellChildren', 'expandChildren', 'expandContext', 'render'];

      // 컴포넌트 메타데이터에서 skipBindingKeys 조회
      const componentMeta = registry.getMetadata(effectiveComponentDef.name);
      const componentSkipKeys = componentMeta?.skipBindingKeys || [];

      // 기본값과 컴포넌트별 설정 병합 (중복 제거)
      const skipBindingKeys = [...new Set([...DEFAULT_SKIP_BINDING_KEYS, ...componentSkipKeys])];

      // iteration 컨텍스트에서는 캐시 사용 안 함
      // 같은 표현식이 다른 아이템 컨텍스트로 평가되어야 하므로 캐시 사용 시 잘못된 값 반환
      const bindingOptions = isInsideIteration ? { skipCache: true } : undefined;

      // _computed 참조 표현식의 캐시 스킵 여부를 판별하는 헬퍼
      // _computed는 컴포넌트마다 _local 기반으로 재계산되므로 컴포넌트 간 공유 캐시 사용 시
      // 먼저 렌더링된 컴포넌트의 stale 값이 이후 컴포넌트에 전파되는 버그 발생
      const getComputedAwareOptions = (expr: string) => {
        if (bindingOptions) return bindingOptions; // iteration이면 이미 skipCache
        if (expr.startsWith('_computed') || expr.startsWith('$computed')) {
          return { skipCache: true };
        }
        return undefined;
      };

      // 1. 데이터 바인딩
      for (const [key, value] of Object.entries(props)) {
        // 특정 키는 바인딩 처리 건너뛰기
        if (skipBindingKeys.includes(key)) {
          continue;
        }
        if (typeof value === 'string') {
          // 전체가 {{expression}} 패턴인 경우 (복잡한 표현식 포함)
          const singleBindingPath = extractSingleBinding(value);
          if (singleBindingPath !== null) {
            // raw: 접두사 감지 @since engine-v1.27.0
            let isRawBinding = false;
            let effectivePath = singleBindingPath;
            if (singleBindingPath.startsWith(RAW_PREFIX)) {
              isRawBinding = true;
              effectivePath = singleBindingPath.slice(RAW_PREFIX.length);
            }

            // 복잡한 표현식(삼항 연산자, 논리 연산자 등)인 경우 evaluateExpression 사용
            // DevTools 추적을 위한 컴포넌트 컨텍스트 정보
            const trackingInfo = {
              componentId: effectiveComponentDef.id,
              componentName: effectiveComponentDef.name,
              propName: key,
            };

            let resolvedValue;
            if (isComplexExpression(effectivePath)) {
              try {
                resolvedValue = bindingEngine.evaluateExpression(effectivePath, extendedDataContext, trackingInfo);
              } catch (error) {
                logger.warn(
                  `표현식 평가 실패 (컴포넌트: ${effectiveComponentDef.id}, prop: ${key}):`,
                  error
                );
                resolvedValue = undefined;
              }
            } else {
              resolvedValue = bindingEngine.resolve(effectivePath, extendedDataContext, getComputedAwareOptions(effectivePath), trackingInfo);
            }
            props[key] = isRawBinding && resolvedValue != null ? wrapRawDeep(resolvedValue) : resolvedValue;
          } else {
            // 문자열 보간 (예: "Hello {{name}}")
            const trackingInfo = {
              componentId: effectiveComponentDef.id,
              componentName: effectiveComponentDef.name,
              propName: key,
            };
            props[key] = bindingEngine.resolveBindings(value, extendedDataContext, bindingOptions, trackingInfo);
          }
        } else if (Array.isArray(value)) {
          props[key] = value.map((item) =>
            typeof item === 'string'
              ? bindingEngine.resolveBindings(item, extendedDataContext, bindingOptions)
              : typeof item === 'object' && item !== null
                ? bindingEngine.resolveObject(item, extendedDataContext, bindingOptions)
                : item
          );
        } else if (value && typeof value === 'object') {
          props[key] = bindingEngine.resolveObject(value, extendedDataContext, bindingOptions);
        }
      }


      // 2. 다국어 번역 (재귀적 처리)
      for (const [key, value] of Object.entries(props)) {
        props[key] = resolveTranslationsDeep(value);
      }

      // 2.5. style prop이 문자열인 경우 React style 객체로 변환
      // React에서 style prop은 객체여야 하므로, CSS 문자열을 자동 변환
      if (typeof props.style === 'string') {
        props.style = parseCSSStringToStyleObject(props.style);
      }

      // 3. 액션 바인딩 (공유 유틸리티 함수 사용)
      props = bindComponentActions(
        props,
        effectiveComponentDef.actions,
        extendedDataContext,
        { componentContext, actionDispatcher }
      );

      // 4. classMap 처리 (조건부 스타일 매핑)
      // classMap이 있으면 해석하여 기존 className과 병합
      if (effectiveComponentDef.classMap) {
        const classMapResult = resolveClassMap(
          effectiveComponentDef.classMap,
          extendedDataContext,
          bindingEngine,
          { skipCache: true } // iteration 컨텍스트에서 각 row별 다른 값을 가져야 함
        );

        if (classMapResult) {
          // 기존 className과 병합
          const existingClassName = props.className || '';
          props.className = existingClassName
            ? `${existingClassName} ${classMapResult}`
            : classMapResult;
        }
      }

      // 5. 인라인 스타일 적용
      if (effectiveComponentDef.style) {
        props.style = effectiveComponentDef.style;
      }

      // 6. 동적 상태를 props로 전달
      // Basic 컴포넌트는 HTML 요소를 렌더링하므로 객체 props가 [object Object]로 변환됨
      // Composite 컴포넌트(React 컴포넌트)만 dynamicState를 props로 받을 수 있음
      if (effectiveComponentDef.actions && effectiveComponentDef.actions.length > 0) {
        const isCompositeComponent = effectiveComponentDef.type === 'composite';

        if (isCompositeComponent) {
          // Composite 컴포넌트: dynamicState 전체 전달 (loadingActions, apiError 등)
          Object.assign(props, dynamicState);
        }
        // Basic/Layout 컴포넌트: dynamicState 전달 안 함
        // (바인딩 표현식으로 필요한 값에 접근 가능: {{_local.xxx}})
      }

      // 7. component_layout 처리 (컴포넌트 전용 커스텀 레이아웃)
      // component_layout이 있으면 __componentLayoutDefs prop으로 전달
      // 컴포넌트에서 이를 사용하여 커스텀 렌더링 수행
      if (effectiveComponentDef.component_layout) {
        props.__componentLayoutDefs = effectiveComponentDef.component_layout;
      }

      // 8. componentContext 자동 전달 (Phase 1-3: 확대 적용)
      // 부모 상태(dynamicState)를 자식 컴포넌트가 접근/수정할 수 있도록
      // 다음 조건 중 하나라도 해당하면 __componentContext를 자동 전달:
      // - expandChildren: DataGrid 확장 행 (ComponentDefinition 또는 props)
      // - cellChildren: DataGrid/CardGrid 셀 렌더링 (props 전달)
      // - children: 자식 컴포넌트 렌더링
      // - component_layout: 커스텀 레이아웃 정의
      // - slot: 슬롯 지정 컴포넌트
      // - modalId: 외부 모달을 여는 컴포넌트 (engine-v1.17.3)
      // - composite 타입: 복합 컴포넌트는 내부적으로 dispatch 호출 가능성 있음 (engine-v1.17.3)
      const hasExpandChildren = effectiveComponentDef.expandChildren || effectiveComponentDef.props?.expandChildren;
      const hasCellChildren = effectiveComponentDef.props?.cellChildren;
      const hasChildren = effectiveComponentDef.children && effectiveComponentDef.children.length > 0;
      const hasComponentLayout = effectiveComponentDef.component_layout;
      const hasSlot = effectiveComponentDef.slot;
      const hasModalId = effectiveComponentDef.props?.modalId;
      const isCompositeComponent = effectiveComponentDef.type === 'composite';

      if (hasExpandChildren || hasCellChildren || hasChildren || hasComponentLayout || hasSlot || hasModalId || isCompositeComponent) {
        props.__componentContext = componentContext;
      }

      return props;
    }, [
      effectiveComponentDef.props,
      effectiveComponentDef.actions,
      effectiveComponentDef.style,
      effectiveComponentDef.classMap,
      effectiveComponentDef.id,
      extendedDataContext,
      translationContext,
      bindingEngine,
      translationEngine,
      componentContext,
      dynamicState,
      resolveTranslationsDeep,
      isComplexExpression,
      extractSingleBinding,
      isInsideIteration,
    ]);

    /**
     * resolvedProps 참조 안정화 (engine-v1.25.0)
     *
     * 상태 변경 시 resolvedProps가 재계산되지만, 해석된 값이 이전과 동일하면
     * 이전 참조를 반환하여 하위 컴포넌트의 React.memo가 실제로 작동하게 합니다.
     * shallowObjectEqual은 각 prop 값을 ===로 비교합니다.
     *
     * @since engine-v1.25.0
     */
    const previousResolvedPropsRef = useRef<Record<string, any> | null>(null);
    const stableResolvedProps = useMemo(() => {
      if (
        previousResolvedPropsRef.current &&
        shallowObjectEqual(previousResolvedPropsRef.current, resolvedProps)
      ) {
        return previousResolvedPropsRef.current;
      }
      previousResolvedPropsRef.current = resolvedProps;
      return resolvedProps;
    }, [resolvedProps]);

    /**
     * 자식에게 전달할 FormContext
     *
     * 현재 컴포넌트에 dataKey가 있으면 새 FormContext를 생성하여 자식에게 전달합니다.
     * 없으면 부모로부터 받은 FormContext를 그대로 전달합니다.
     * 이렇게 prop으로 전달해야 useMemo 타이밍 문제를 피할 수 있습니다.
     *
     * dataKey 형식:
     * - "formData" → _local.formData 사용
     * - "_global.formData" → _global.formData 사용
     */
    const formContextForChildren = useMemo<FormContextValue | undefined>(() => {
      // dataKey는 componentDef 레벨에 정의됨 (props가 아님)
      const dataKey = effectiveComponentDef.dataKey;
      const trackChanges = effectiveComponentDef.trackChanges;
      const debounce = effectiveComponentDef.debounce;

      // dataKey가 있으면 새 FormContext 생성
      if (dataKey) {
        // _global. 접두사 처리
        const isGlobal = dataKey.startsWith('_global.');
        const actualDataKey = isGlobal ? dataKey.slice(8) : dataKey; // "_global." 제거

        // _global 또는 _local 상태 선택
        const targetState = isGlobal
          ? (dataContext._global as Record<string, any> | undefined)
          : (extendedDataContext._local as Record<string, any> | undefined);

        // _global인 경우 globalSetState 사용, 아니면 localSetState 사용
        const targetSetState = isGlobal
          ? (updates: Record<string, any>) => {
              // _global 상태 업데이트는 actionDispatcher를 통해 수행
              // globalSetState 핸들러가 필요하므로 componentContext.setState와 다름
              // 여기서는 _global 객체를 직접 업데이트할 수 없으므로
              // dataContext를 통해 전달된 _global setState를 사용해야 함
              if (dataContext._globalSetState) {
                (dataContext._globalSetState as (updates: Record<string, any>) => void)(updates);
              } else {
                logger.warn('_global.formData 사용 시 _globalSetState가 필요합니다.');
              }
            }
          : handleSetState;

        return {
          dataKey: actualDataKey, // "_global." 접두사 제거된 실제 키
          trackChanges: trackChanges ?? false,
          debounce: debounce,
          setState: targetSetState,
          state: targetState ?? {},
          // _global 여부를 추가로 전달 (propsWithAutoBinding에서 사용)
          _isGlobal: isGlobal,
        } as FormContextValue & { _isGlobal?: boolean };
      }

      // dataKey가 없으면 부모의 FormContext를 그대로 전달 (trackChanges 포함)
      // parentFormContext가 유효하면 (dataKey가 있으면) 그대로 자식에게 전달
      if (parentFormContext.dataKey) {
        return parentFormContext;
      }

      return undefined;
    }, [effectiveComponentDef.dataKey, effectiveComponentDef.trackChanges, effectiveComponentDef.debounce, effectiveComponentDef.id, handleSetState, extendedDataContext._local, dataContext._global, dataContext._globalSetState, parentFormContext]);

    /**
     * 이미 remount 키가 적용된 컴포넌트 ID를 추적합니다.
     * 중복 ID가 있는 경우 첫 번째만 remount 처리하기 위함입니다.
     */
    const appliedRemountIds = useRef<Set<string>>(new Set());

    // _remountKeys가 변경될 때마다 추적 Set 초기화
    useEffect(() => {
      appliedRemountIds.current.clear();
    }, [dataContext._global?._remountKeys]);

    /**
     * 컴포넌트 ID 기반으로 React key를 생성합니다.
     *
     * _remountKeys에 해당 컴포넌트 ID의 값이 있으면 key에 포함시켜
     * remount 핸들러 호출 시 컴포넌트가 리마운트되도록 합니다.
     *
     * 중복 ID 처리:
     * - ID가 있고 처음 등장하는 경우: remount 키 적용
     * - ID가 없거나 중복되는 경우: 기존 fallback 키 사용
     *
     * @param componentId 컴포넌트 ID
     * @param fallbackKey ID가 없을 때 사용할 폴백 키
     * @returns React key 문자열
     */
    const getRemountKey = useCallback((componentId: string | undefined, fallbackKey: string): string => {
      const remountKeys = dataContext._global?._remountKeys;

      // ID가 있고, remount 키가 있고, 아직 적용되지 않은 경우에만 remount 처리
      if (componentId && remountKeys?.[componentId] && !appliedRemountIds.current.has(componentId)) {
        appliedRemountIds.current.add(componentId);
        return `${componentId}-remount-${remountKeys[componentId]}`;
      }

      // ID가 없거나 중복되는 경우 기존 방식
      return componentId || fallbackKey;
    }, [dataContext._global?._remountKeys]);

    /**
     * 자식 컴포넌트 렌더링
     */
    const renderChildren = useMemo(() => {
      // 1. text 속성이 있으면 최우선으로 사용
      if (effectiveComponentDef.text !== undefined) {
        if (typeof effectiveComponentDef.text === 'string') {
          const text = effectiveComponentDef.text;
          let resolvedText: any = text;

          // 1단계: 데이터 바인딩 처리 (props 처리와 동일한 로직)
          // skipCache: true - 반복 렌더링에서 같은 경로가 다른 값을 가져야 함
          // DevTools 추적을 위한 컴포넌트 컨텍스트 정보
          const textTrackingInfo = {
            componentId: effectiveComponentDef.id,
            componentName: effectiveComponentDef.name,
            propName: 'text',
            skipCache: true,
          };
          const singleBindingPath = extractSingleBinding(text);

          if (singleBindingPath !== null) {
            // 파이프가 있는 경우 resolveBindings 사용 (파이프 처리 포함)
            if (hasPipes(singleBindingPath)) {
              resolvedText = bindingEngine.resolveBindings(text, extendedDataContext, { skipCache: true }, textTrackingInfo);
            } else if (isComplexExpression(singleBindingPath)) {
              // 복잡한 표현식인 경우 evaluateExpression 사용
              try {
                resolvedText = bindingEngine.evaluateExpression(singleBindingPath, extendedDataContext, textTrackingInfo);
              } catch (error) {
                logger.warn(
                  `text 표현식 평가 실패 (컴포넌트: ${effectiveComponentDef.id}):`,
                  error
                );
                resolvedText = '';
              }
            } else {
              resolvedText = bindingEngine.resolve(singleBindingPath, extendedDataContext, { skipCache: true }, textTrackingInfo);
            }
          } else if (text.includes('{{')) {
            // 문자열 보간
            resolvedText = bindingEngine.resolveBindings(text, extendedDataContext, { skipCache: true }, textTrackingInfo);
          }

          // 2단계: 다국어 번역 처리 (기존 헬퍼 재사용)
          resolvedText = resolveTranslationsDeep(resolvedText);

          return resolvedText ?? '';
        }
        return effectiveComponentDef.text;
      }

      // 2. children이 없으면 null
      if (!effectiveComponentDef.children || effectiveComponentDef.children.length === 0) {
        return null;
      }

      // 3. children 배열 렌더링
      return effectiveComponentDef.children.map((childDef, index) => {
        // 문자열인 경우 그대로 반환 (텍스트 노드)
        if (typeof childDef === 'string') {
          return childDef;
        }

        // 컴포넌트 정의인 경우 DynamicRenderer로 렌더링
        // 자식 컴포넌트에도 componentContext와 formContext를 전달하여 상태 공유
        // extendedDataContext를 전달하여 _local 상태를 자식에게도 공유
        // componentPath를 누적하여 자식에게 전달 (WYSIWYG 편집기용)
        const childPath = componentPath ? `${componentPath}.children.${index}` : `${index}`;

        // @since engine-v1.24.8 _fromBase children은 stable key → 보존(update)
        // non-_fromBase children은 layoutKey suffix로 페이지 전환 시 remount 보장
        const baseChildKey = childDef._fromBase
          ? (childDef.id || `child-${index}`)
          : getRemountKey(childDef.id, `child-${index}`);
        const childKey = (!childDef._fromBase && layoutKey)
          ? `${baseChildKey}_${layoutKey}`
          : baseChildKey;

        return (
          <DynamicRenderer
            key={childKey}
            componentDef={childDef}
            dataContext={extendedDataContext}
            translationContext={translationContext}
            registry={registry}
            bindingEngine={bindingEngine}
            translationEngine={translationEngine}
            actionDispatcher={actionDispatcher}
            parentComponentContext={componentContext}
            parentFormContextProp={formContextForChildren}
            isInsideIteration={isInsideIteration}
            isEditMode={isEditMode}
            onComponentSelect={onComponentSelect}
            onComponentHover={onComponentHover}
            componentPath={childPath}
            onDragStart={onDragStart}
            onDragEnd={onDragEnd}
            layoutKey={layoutKey}
          />
        );
      });
    }, [
      effectiveComponentDef.text,
      effectiveComponentDef.children,
      effectiveComponentDef.id,
      dataContext,
      extendedDataContext,
      translationContext,
      registry,
      bindingEngine,
      translationEngine,
      actionDispatcher,
      componentContext,
      formContextForChildren,
      extractSingleBinding,
      isComplexExpression,
      resolveTranslationsDeep,
      getRemountKey,
      isInsideIteration,
      isEditMode,
      onComponentSelect,
      onComponentHover,
      componentPath,
      onDragStart,
      onDragEnd,
      layoutKey,
    ]);

    /**
     * 반복 렌더링 처리
     */
    const renderIteration = useMemo(() => {
      if (!effectiveComponentDef.iteration) {
        return null;
      }

      try {
        // 데이터 소스 가져오기 (표현식 지원)
        const dataArray = resolveIterationSource(
          effectiveComponentDef.iteration.source,
          extendedDataContext,
          bindingEngine
        );

        if (!Array.isArray(dataArray)) {
          logger.warn(
            `iteration source가 배열이 아닙니다 (컴포넌트: ${effectiveComponentDef.id}):`,
            dataArray
          );
          return null;
        }

        // DevTools에 iteration 추적
        const devTools = getDevTools();
        if (devTools?.isEnabled()) {
          devTools.trackIteration(
            effectiveComponentDef.id || `iteration-${Date.now()}`,
            effectiveComponentDef.iteration!.source,
            effectiveComponentDef.iteration!.item_var,
            effectiveComponentDef.iteration!.index_var,
            dataArray.length
          );
        }

        // 각 아이템에 대해 자식 컴포넌트 렌더링
        return dataArray.map((item, index) => {
          // 반복 컨텍스트 생성 (extendedDataContext 기반, _local 포함)
          const iterationContext: Record<string, any> = {
            ...extendedDataContext,
            [effectiveComponentDef.iteration!.item_var]: item,
          };

          if (effectiveComponentDef.iteration!.index_var) {
            iterationContext[effectiveComponentDef.iteration!.index_var] = index;
          }

          // DevTools: Nested Context 추적 (iteration) - 성능 최적화: 샘플링
          // 전체 컨텍스트 복사로 인한 성능 저하 방지를 위해 첫 번째/마지막 아이템만 추적
          const isFirstOrLast = index === 0 || index === dataArray.length - 1;
          if (devTools?.isEnabled() && isFirstOrLast) {
            devTools.trackNestedContext?.({
              componentId: `${effectiveComponentDef.id || 'unknown'}-iter-${index}`,
              componentType: 'iteration',
              parentContext: {
                available: Object.keys(extendedDataContext),
                // 성능 최적화: 전체 값 대신 키만 전달 (필요시 탭에서 조회)
                values: {},
              },
              ownContext: {
                added: [
                  effectiveComponentDef.iteration!.item_var,
                  ...(effectiveComponentDef.iteration!.index_var ? [effectiveComponentDef.iteration!.index_var] : []),
                ],
                values: {
                  [effectiveComponentDef.iteration!.item_var]: item,
                  ...(effectiveComponentDef.iteration!.index_var ? { [effectiveComponentDef.iteration!.index_var]: index } : {}),
                },
              },
              depth: 1,
            });
          }

          // iteration 없이 현재 컴포넌트를 렌더링하기 위해 iteration을 제거한 복사본 생성
          // children이 있든 없든 현재 컴포넌트(wrapper)로 감싸서 렌더링
          //
          // responsive 도 함께 제거: 부모에서 이미 해당 breakpoint 매칭으로 effectiveComponentDef
          // 가 머지된 상태이므로, 자식에서 다시 responsive 머지가 일어나면 iteration 이 부활하여
          // 무한 재귀가 발생함 (responsive.{breakpoint}.iteration 오버라이드 케이스).
          const componentDefWithoutIteration = {
            ...effectiveComponentDef,
            iteration: undefined,
            responsive: undefined,
          };

          // iteration 아이템의 경로: 부모경로.iteration.인덱스
          const iterationPath = componentPath
            ? `${componentPath}.iteration.${index}`
            : `iteration.${index}`;

          const iterKey = `${getRemountKey(effectiveComponentDef.id, 'item')}-${index}`;

          return (
            <DynamicRenderer
              key={iterKey}
              componentDef={componentDefWithoutIteration}
              dataContext={iterationContext}
              translationContext={translationContext}
              registry={registry}
              bindingEngine={bindingEngine}
              translationEngine={translationEngine}
              actionDispatcher={actionDispatcher}
              parentComponentContext={componentContext}
              parentFormContextProp={formContextForChildren}
              isInsideIteration={true}
              isEditMode={isEditMode}
              onComponentSelect={onComponentSelect}
              onComponentHover={onComponentHover}
              componentPath={iterationPath}
              onDragStart={onDragStart}
              onDragEnd={onDragEnd}
              layoutKey={layoutKey}
            />
          );
        });
      } catch (error) {
        logger.error(
          `iteration 렌더링 실패 (컴포넌트: ${effectiveComponentDef.id}):`,
          error
        );
        return null;
      }
    }, [
      effectiveComponentDef.iteration,
      effectiveComponentDef.children,
      effectiveComponentDef.id,
      dataContext,
      extendedDataContext,
      translationContext,
      registry,
      bindingEngine,
      translationEngine,
      actionDispatcher,
      componentContext,
      formContextForChildren,
      getRemountKey,
      isEditMode,
      onComponentSelect,
      onComponentHover,
      componentPath,
      onDragStart,
      onDragEnd,
      layoutKey,
    ]);

    /**
     * 정렬 가능한 리스트 렌더링 처리 (@dnd-kit 기반)
     *
     * sortable 속성과 itemTemplate을 사용하여 드래그앤드롭으로
     * 순서를 변경할 수 있는 리스트를 렌더링합니다.
     *
     * @since engine-v1.14.0
     */
    // 정렬 버전 카운터 (useState): 정렬 완료 시 증가하여 컴포넌트 리렌더 + DndContext 리마운트 유도
    // useRef → useState 변경: ref 변경은 리렌더를 트리거하지 않아 깊은 memo 체인에서 renderSortable이 재계산되지 않는 문제 해결
    const [sortVersion, setSortVersion] = useState(0);

    const renderSortable = useMemo(() => {
      // sortable과 itemTemplate이 모두 있어야 처리
      if (!effectiveComponentDef.sortable || !effectiveComponentDef.itemTemplate) {
        return null;
      }

      const { sortable, itemTemplate } = effectiveComponentDef;

      try {
        // 데이터 소스 가져오기 (표현식 지원)
        const dataArray = resolveIterationSource(
          sortable.source,
          extendedDataContext,
          bindingEngine
        );

        if (!Array.isArray(dataArray)) {
          logger.warn(
            `sortable source가 배열이 아닙니다 (컴포넌트: ${effectiveComponentDef.id}):`,
            dataArray
          );
          return null;
        }

        // 빈 배열 처리
        if (dataArray.length === 0) {
          return null;
        }


        // 설정값 추출 (기본값 적용)
        const itemKey = sortable.itemKey || 'id';
        const strategy = (sortable.strategy || 'verticalList') as SortableStrategy;
        const itemVar = sortable.itemVar || '$item';
        const indexVar = sortable.indexVar || '$index';
        const handle = sortable.handle;
        const wrapperElement = sortable.wrapperElement;

        /**
         * 정렬 완료 핸들러
         *
         * actions에서 onSortEnd 이벤트를 찾아 실행합니다.
         * $sortedItems, $sortEvent 컨텍스트 변수를 주입합니다.
         */
        const handleSortEnd = (
          sortedItems: any[],
          event: { oldIndex: number; newIndex: number }
        ) => {

          // actions에서 onSortEnd 이벤트 찾기
          const sortEndActions = effectiveComponentDef.actions?.filter(
            (action: any) => action.event === 'onSortEnd' || action.type === 'onSortEnd'
          );

          // DndContext 리마운트를 위해 sortVersion 증가 (useState → 리렌더 트리거)
          setSortVersion(prev => prev + 1);

          if (sortEndActions && sortEndActions.length > 0 && actionDispatcher) {
            // $sortedItems, $sortEvent 컨텍스트 변수 주입
            const sortContext = {
              ...extendedDataContext,
              $sortedItems: sortedItems,
              $sortEvent: event,
            };

            // 모든 onSortEnd 액션 실행
            sortEndActions.forEach((action: any) => {
              try {
                const handler = actionDispatcher.createHandler(
                  action,
                  sortContext,
                  componentContext
                );
                // 더미 이벤트로 실행
                const sortEndEvent = new Event('sortend');
                handler(sortEndEvent);
              } catch (error) {
                logger.error(`onSortEnd action failed: ${action.handler}`, error);
              }
            });
          }
        };

        /**
         * 정렬 시작 핸들러
         */
        const handleSortStart = (event: { activeId: string | number }) => {
          const sortStartActions = effectiveComponentDef.actions?.filter(
            (action: any) => action.event === 'onSortStart' || action.type === 'onSortStart'
          );

          if (sortStartActions && sortStartActions.length > 0 && actionDispatcher) {
            const sortContext = {
              ...extendedDataContext,
              $activeId: event.activeId,
            };

            sortStartActions.forEach((action: any) => {
              try {
                const handler = actionDispatcher.createHandler(
                  action,
                  sortContext,
                  componentContext
                );
                // 더미 이벤트로 실행
                const sortStartEvent = new Event('sortstart');
                handler(sortStartEvent);
              } catch (error) {
                logger.error(`onSortStart action failed: ${action.handler}`, error);
              }
            });
          }
        };

        // 각 아이템 렌더링
        const renderItems = dataArray.map((item, index) => {
          const itemId = String(item[itemKey]);


          // 아이템 컨텍스트 생성
          const itemContext = {
            ...extendedDataContext,
            [itemVar]: item,
            [indexVar]: index,
          };

          // sortable 아이템 경로
          const sortablePath = componentPath
            ? `${componentPath}.sortable.${index}`
            : `sortable.${index}`;

          // sortVersion 포함 키: 정렬 시 sortVersionRef 변경으로 모든 아이템 강제 리마운트
          // 이를 통해 MultilingualInput 등 내부 상태를 가진 컴포넌트도 정렬된 값으로 초기화됩니다.
          const itemTemplateKey = `${getRemountKey(effectiveComponentDef.id, 'sortable-item')}-v${sortVersion}-${itemId}`;

          return (
            <SortableItemWrapper
              key={itemTemplateKey}
              id={itemId}
              handle={handle}
              {...(wrapperElement ? { as: wrapperElement } : {})}
            >
              {/* wrapperElement 지정 시 itemTemplate의 children을 직접 렌더링 (루트 요소 중복 방지) */}
              {(wrapperElement && itemTemplate.children ? itemTemplate.children : [itemTemplate]).map(
                (childDef: any, childIdx: number) => (
                  <DynamicRenderer
                    key={`${itemTemplateKey}-child-${childIdx}`}
                    componentDef={childDef}
                    dataContext={itemContext}
                    translationContext={translationContext}
                    registry={registry}
                    bindingEngine={bindingEngine}
                    translationEngine={translationEngine}
                    actionDispatcher={actionDispatcher}
                    parentComponentContext={componentContext}
                    parentFormContextProp={undefined}
                    isInsideIteration={true}
                    isEditMode={isEditMode}
                    onComponentSelect={onComponentSelect}
                    onComponentHover={onComponentHover}
                    componentPath={sortablePath}
                    onDragStart={onDragStart}
                    onDragEnd={onDragEnd}
                    layoutKey={layoutKey}
                  />
                )
              )}
            </SortableItemWrapper>
          );
        });

        // SortableContainer로 감싸서 반환
        return (
          <SortableContainer
            items={dataArray}
            itemKey={itemKey}
            strategy={strategy}
            onSortEnd={handleSortEnd}
            onSortStart={handleSortStart}
            sortVersion={sortVersion}
          >
            {renderItems}
          </SortableContainer>
        );
      } catch (error) {
        logger.error(
          `sortable 렌더링 실패 (컴포넌트: ${effectiveComponentDef.id}):`,
          error
        );
        return null;
      }
    }, [
      effectiveComponentDef.sortable,
      effectiveComponentDef.itemTemplate,
      effectiveComponentDef.actions,
      effectiveComponentDef.id,
      dataContext,
      extendedDataContext,
      translationContext,
      registry,
      bindingEngine,
      translationEngine,
      actionDispatcher,
      componentContext,
      getRemountKey,
      isEditMode,
      onComponentSelect,
      onComponentHover,
      componentPath,
      onDragStart,
      onDragEnd,
      sortVersion,
      layoutKey,
    ]);

    /**
     * Lifecycle 액션 실행을 위한 ref (중복 실행 방지)
     */
    const mountedRef = useRef(false);

    /**
     * Lifecycle onMount/onUnmount 및 onComponentEvent 처리
     *
     * 컴포넌트 마운트 시:
     * - lifecycle.onMount 액션들을 실행
     * - onComponentEvent 이벤트 구독 설정
     *
     * 컴포넌트 언마운트 시:
     * - onComponentEvent 이벤트 구독 해제
     * - lifecycle.onUnmount 액션들을 실행
     */
    useEffect(() => {
      // 이미 마운트된 경우 중복 실행 방지 (React Strict Mode 대응)
      if (mountedRef.current) {
        return;
      }
      mountedRef.current = true;

      // DevTools 마운트 추적
      const devTools = getDevTools();
      if (devTools?.isEnabled()) {
        devTools.trackMount(componentDef.id, {
          name: componentDef.name,
          type: componentDef.type || 'component',
          props: stableResolvedProps,
        });
      }

      // onMount 액션 실행
      if (componentDef.lifecycle?.onMount && componentDef.lifecycle.onMount.length > 0) {
        for (const action of componentDef.lifecycle.onMount) {
          try {
            // 핸들러 생성 및 실행
            const handler = actionDispatcher.createHandler(
              action,
              extendedDataContext,
              componentContext
            );
            // 더미 이벤트로 실행
            const mountEvent = new Event('mount');
            handler(mountEvent);
            logger.log(`Lifecycle onMount executed: ${action.handler} (Component: ${componentDef.id})`);
          } catch (error) {
            logger.error(
              `Lifecycle onMount failed: ${action.handler} (Component: ${componentDef.id})`,
              error
            );
          }
        }
      }

      // onComponentEvent 구독 설정
      const eventUnsubscribers: Array<() => void> = [];
      if (componentDef.onComponentEvent && componentDef.onComponentEvent.length > 0) {
        const G7Core = (window as any).G7Core;
        if (G7Core?.componentEvent?.on) {
          for (const eventDef of componentDef.onComponentEvent) {
            if (!eventDef.event) {
              logger.warn(`onComponentEvent: event name is required (Component: ${componentDef.id})`);
              continue;
            }

            // 이벤트 콜백 함수 생성
            const eventCallback = async (eventData?: any) => {
              try {
                // 이벤트 데이터를 포함한 확장 컨텍스트 생성
                const eventContext = {
                  ...extendedDataContext,
                  _eventData: eventData,
                };

                // 핸들러 생성 및 실행
                const handler = actionDispatcher.createHandler(
                  {
                    handler: eventDef.handler,
                    params: eventDef.params,
                    onSuccess: eventDef.onSuccess,
                    onError: eventDef.onError,
                  },
                  eventContext,
                  componentContext
                );

                const result = await handler(new CustomEvent(eventDef.event, { detail: eventData }));
                logger.log(
                  `onComponentEvent "${eventDef.event}" handled: ${eventDef.handler} (Component: ${componentDef.id})`,
                  { eventData, result }
                );
                return result;
              } catch (error) {
                logger.error(
                  `onComponentEvent "${eventDef.event}" failed: ${eventDef.handler} (Component: ${componentDef.id})`,
                  error
                );
                throw error;
              }
            };

            // 이벤트 구독
            const unsubscribe = G7Core.componentEvent.on(eventDef.event, eventCallback);
            eventUnsubscribers.push(unsubscribe);
            logger.log(`onComponentEvent: Subscribed to "${eventDef.event}" (Component: ${componentDef.id})`);
          }
        } else {
          logger.warn(`onComponentEvent: G7Core.componentEvent is not available (Component: ${componentDef.id})`);
        }
      }

      // cleanup 함수 (이벤트 구독 해제 + onUnmount 실행)
      return () => {
        // DevTools 언마운트 추적
        const devToolsCleanup = getDevTools();
        if (devToolsCleanup?.isEnabled()) {
          devToolsCleanup.trackUnmount(componentDef.id);
        }

        // onComponentEvent 구독 해제 (onUnmount보다 먼저 실행)
        for (const unsubscribe of eventUnsubscribers) {
          try {
            unsubscribe();
          } catch (error) {
            logger.error(`onComponentEvent: Failed to unsubscribe (Component: ${componentDef.id})`, error);
          }
        }
        if (eventUnsubscribers.length > 0) {
          logger.log(`onComponentEvent: Unsubscribed ${eventUnsubscribers.length} event(s) (Component: ${componentDef.id})`);
        }

        if (componentDef.lifecycle?.onUnmount && componentDef.lifecycle.onUnmount.length > 0) {
          for (const action of componentDef.lifecycle.onUnmount) {
            try {
              const handler = actionDispatcher.createHandler(
                action,
                extendedDataContext,
                componentContext
              );
              const unmountEvent = new Event('unmount');
              handler(unmountEvent);
              logger.log(`Lifecycle onUnmount executed: ${action.handler} (Component: ${componentDef.id})`);
            } catch (error) {
              logger.error(
                `Lifecycle onUnmount failed: ${action.handler} (Component: ${componentDef.id})`,
                error
              );
            }
          }
        }
      };
    // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []); // 마운트 시 한 번만 실행

    /**
     * 현재 컴포넌트의 Form 컨텍스트 값
     *
     * dataKey prop이 있으면 새로운 FormContext를 생성하고,
     * 없으면 부모의 FormContext를 그대로 사용합니다.
     */
    const currentFormContext = useMemo<FormContextValue | null>(() => {
      const dataKey = stableResolvedProps.dataKey;
      const trackChanges = stableResolvedProps.trackChanges;
      const debounce = stableResolvedProps.debounce;

      // dataKey가 없으면 null 반환 (FormProvider를 사용하지 않음)
      if (!dataKey) {
        return null;
      }

      return {
        dataKey,
        trackChanges: trackChanges ?? false,
        debounce: debounce,
        setState: handleSetState,
        state: dynamicState,
      };
    }, [stableResolvedProps.dataKey, stableResolvedProps.trackChanges, stableResolvedProps.debounce, handleSetState, dynamicState]);

    /**
     * DevTools Form 추적
     *
     * dataKey가 있는 컴포넌트를 Form으로 추적합니다.
     * 마운트 시 등록하고 언마운트 시 해제합니다.
     *
     * 참고: dataKey는 레이아웃 JSON에서 컴포넌트 최상위 속성으로 정의됨
     * (props 내부가 아님)
     */
    useEffect(() => {
      // dataKey는 effectiveComponentDef 또는 stableResolvedProps에서 가져옴
      const dataKey = (effectiveComponentDef as any).dataKey || stableResolvedProps.dataKey;
      if (!dataKey) return;

      const devToolsForm = getDevTools();
      if (!devToolsForm?.isEnabled()) return;

      // Form 내부 Input들의 이름을 수집하기 위해 children을 순회
      const inputNames: { name: string; type: string }[] = [];
      const collectInputs = (children: any[]) => {
        if (!children) return;
        for (const child of children) {
          // JSON 레이아웃 구조: child.props?.name 또는 child.name (컴포넌트 타입)
          // props 안에 name이 있거나, 직접 name 속성이 있는 경우
          const inputName = child?.props?.name;
          if (inputName) {
            inputNames.push({
              name: inputName,
              type: child.name || child.type || 'unknown',
            });
          }
          // 중첩된 children도 재귀적으로 탐색
          if (child?.children) {
            collectInputs(child.children);
          }
          // slots 내부의 컴포넌트도 탐색
          if (child?.slots) {
            for (const slotChildren of Object.values(child.slots)) {
              if (Array.isArray(slotChildren)) {
                collectInputs(slotChildren);
              }
            }
          }
        }
      };
      if (componentDef.children) {
        collectInputs(componentDef.children);
      }
      // slots 내부도 탐색
      if ((componentDef as any).slots) {
        for (const slotChildren of Object.values((componentDef as any).slots)) {
          if (Array.isArray(slotChildren)) {
            collectInputs(slotChildren);
          }
        }
      }

      const formId = componentDef.id || `form-${dataKey}`;
      devToolsForm.trackForm(formId, dataKey, inputNames);

      // 클린업: 언마운트 시 Form 추적 해제
      return () => {
        devToolsForm.untrackForm(formId);
      };
    }, [effectiveComponentDef, stableResolvedProps.dataKey, componentDef.id, componentDef.children]);

    /**
     * 자동 바인딩 debounce 타이머 관리
     *
     * 컴포넌트별로 debounce 타이머를 관리하여 빈번한 상태 업데이트를 최적화합니다.
     */
    const autoBindingDebounceTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
    // debounce 대기 중 사용자 입력값 추적 (플리커 방지)
    // debounce 중 부모 리렌더링 시 stale state value 대신 pending value를 사용
    const autoBindingPendingValueRef = useRef<any>(undefined);

    /**
     * 자동바인딩 경로 레지스트리 등록/해제 (engine-v1.43.0+)
     *
     * 이 Input/Textarea 등이 마운트되는 동안 fullPath(=`${dataKey}.${name}`)를
     * `window.__g7AutoBindingPaths` Map에 reference count로 기록한다.
     *
     * 용도: G7CoreGlobals.setLocal이 `render:false` 호출 시 업데이트 경로가
     * 레지스트리와 겹치면 render:true로 자동 승격하여 저장소 A(localDynamicState)
     * 동기화를 구조적으로 보장한다 (`options.selfManaged === true`만 예외).
     *
     * Reference count가 필요한 이유:
     * - iteration 내 동일 fullPath 중복 마운트 (여러 Input이 같은 name)
     * - React Strict Mode의 이중 마운트 (mount→cleanup→mount)
     * Set만 쓰면 첫 cleanup이 경로 제거 → 두 번째 마운트 후 stale 위험.
     *
     * 조건: 실제 자동바인딩이 활성화된 경로만 등록 (propsWithAutoBinding의 조건과 동일).
     * - name prop 존재
     * - 부모 FormContext의 dataKey/setState 존재
     * - props.autoBinding !== false
     */
    useEffect(() => {
      const name = stableResolvedProps?.name;
      if (!name || !parentFormContext.dataKey || !parentFormContext.setState) return;
      if (stableResolvedProps?.autoBinding === false) return;

      const fullPath = `${parentFormContext.dataKey}.${name}`;
      const registry: Map<string, number> =
        ((window as any).__g7AutoBindingPaths as Map<string, number> | undefined)
        ?? new Map<string, number>();
      (window as any).__g7AutoBindingPaths = registry;
      registry.set(fullPath, (registry.get(fullPath) ?? 0) + 1);

      return () => {
        const count = registry.get(fullPath) ?? 0;
        if (count <= 1) registry.delete(fullPath);
        else registry.set(fullPath, count - 1);
      };
    }, [stableResolvedProps?.name, stableResolvedProps?.autoBinding, parentFormContext.dataKey, parentFormContext.setState]);

    /**
     * 폼 자동 바인딩이 적용된 props
     *
     * 부모 FormContext가 있고 name prop이 있으면:
     * 1. value/checked: dataKey 경로에서 해당 필드 값 자동 바인딩
     *    - 값이 boolean이면 checked로 바인딩 (Toggle, Checkbox 등)
     *    - 그 외는 value로 바인딩 (Input, Textarea, Select 등)
     * 2. onChange: 값 변경 시 자동으로 상태 업데이트
     * 3. trackChanges가 true이면 hasChanges도 자동 설정
     * 4. debounce가 설정되면 상태 업데이트를 지연
     *
     * props.value는 기본값으로 사용되며, 바인딩된 값이 있으면 바인딩 값이 우선합니다.
     */
    const propsWithAutoBinding = useMemo(() => {
      // dataKey, trackChanges, debounce는 컴포넌트에 전달하지 않음 (내부 처리용)
      // eslint-disable-next-line @typescript-eslint/no-unused-vars
      const { dataKey, trackChanges, debounce, ...propsWithoutFormConfig } = stableResolvedProps;

      // 자동 바인딩 조건 체크
      const name = propsWithoutFormConfig.name;
      if (!name || !parentFormContext.dataKey || !parentFormContext.setState) {
        return propsWithoutFormConfig;
      }

      // --- 자동 바인딩 스킵 조건 (engine-v1.17.6) ---
      // autoBinding: false 명시 시 자동 바인딩 비활성화
      // (라디오 등 auto-binding의 value 오버라이드가 유해한 컴포넌트에서 사용)
      if (propsWithoutFormConfig.autoBinding === false) {
        // eslint-disable-next-line @typescript-eslint/no-unused-vars
        const { autoBinding: _ab, ...cleanProps } = propsWithoutFormConfig;
        return cleanProps;
      }

      // 자동 바인딩 적용
      const fullPath = `${parentFormContext.dataKey}.${name}`;
      const boundValue = getNestedValue(parentFormContext.state, fullPath);

      // 바인딩된 값이 있으면 사용, 없으면 props.value를 기본값으로 사용
      const currentValue = boundValue !== undefined ? boundValue : propsWithoutFormConfig.value;

      // 컴포넌트 메타데이터 기반 바인딩 타입 결정
      // - 'checked': 항상 checked 바인딩 (Toggle, Checkbox, ChipCheckbox)
      // - 'checkable': type이 checkbox/radio일 때만 checked (Input)
      // - 미지정: 항상 value 바인딩 (RadioGroup, Select 등)
      const componentMeta = registry.getMetadata(componentDef.name);
      const metadataBindingType = componentMeta?.bindingType;
      const isCheckedBinding = typeof currentValue === 'boolean' && (
        metadataBindingType === 'checked'
        || (metadataBindingType === 'checkable'
          && ['checkbox', 'radio'].includes(propsWithoutFormConfig?.type))
      );

      // debounce 설정 가져오기
      const debounceMs = parentFormContext.debounce;

      /**
       * Form 자동바인딩 상태 업데이트 — "이중 저장소 동기화" 구조 (engine-v1.43.0+)
       *
       * ⚠️ 엔진 유지보수자 필독: 아래 구조는 단순 구현이 아니라 "단일화 시도 실패" 이력의 결과다.
       *
       * [배경]
       * 엔진은 폼 데이터를 두 저장소에 나눠 관리한다:
       *   - 저장소 A: React localDynamicState (useState)
       *              — Form 자동바인딩 쓰기, Input 컴포넌트 부분 리렌더로 빠른 반영
       *   - 저장소 B: globalState._local (TemplateApp)
       *              — G7Core.state.setLocal/getLocal, apiCall body 바인딩, 플러그인 동기화
       *
       * engine-v1.43.0 이전에는 자동바인딩이 A에만 썼다. CKEditor5 같은 플러그인이
       * setLocal({render:false})로 B에 쓸 때 mergedPending = deepMerge(globalLocal, ...)의
       * globalLocal base에 자동바인딩 값이 없어, 자동바인딩 값이 빈 초기값으로 덮이는
       * 구조적 결함이 있었다.
       *
       * [왜 저장소 A를 없애고 B로 단일화하지 않는가]
       * 1) B 단일화 = 매 키입력마다 TemplateApp.setGlobalState → root.render() 전체 리렌더.
       *    대형 폼(필드 수십 개 + 바인딩 수천 개)에서 타이핑 지연 발생 위험.
       * 2) "필요한 부분만 리렌더" 최적화 경로(경로 기반 구독 시스템)는 과거에 도입 후
       *    필터 체크박스 클릭 수백 ms 지연 이슈로 전체 롤백된 실패 경로.
       * 3) 즉 "구조 단일화 + 부분 리렌더"는 복구 경로 없는 설계. 이중 저장소를 전제로 운영한다.
       *
       * [동기화 규칙]
       * - 자동바인딩 쓰기는 A + B 양쪽에 동시에 쓴다. B 쓰기는 render:false로 리렌더 억제.
       * - A의 React useState가 해당 Input 자식 트리 리렌더를 담당 (성능 보존).
       * - B는 플러그인/핸들러가 읽는 정본(source of truth). A는 B의 캐시가 아니라 "쓰는 시점에 강제로 일치시키는" 미러.
       * - G7Core.state.setLocal({...}, {render:false}) 호출로 __g7PendingLocalState,
       *   __g7ForcedLocalFields, __g7SetLocalOverrideKeys, __g7LastSetLocalSnapshot,
       *   __g7SequenceLocalSync 보조 캐시도 모두 B와 함께 자동 갱신된다.
       *
       * [엔진 수정 시 주의]
       * - 이 동기화 규칙을 지키지 않는 새 쓰기 경로를 추가하면 자동바인딩 정합성이 깨진다.
       * - 엔진 레벨 재발 방지 장치 (같은 engine-v1.43.0 도입):
       *   · __g7AutoBindingPaths 레지스트리: 자동바인딩 마운트 중인 fullPath 추적 (아래 useEffect)
       *   · G7CoreGlobals.setLocal: render:false 호출이 레지스트리와 겹치면 render:true 자동 승격
       *   · 예외: options.selfManaged === true 인 경우 승격 제외. CKEditor5 등 자체 DOM 관리
       *     플러그인이 의도적으로 render:false를 유지하려 할 때 명시. 누락 시 엔진이 자동 승격하여
       *     정합성 보장 (safe-by-default).
       *   · 즉 플러그인이 render:false setLocal을 쓰든 자동바인딩과 충돌 시 엔진이 구조적으로 차단,
       *     selfManaged 명시한 플러그인만 예외적으로 render:false 유지.
       * - 구독 기반 선택적 리렌더는 과거에 도입 후 롤백된 경로이므로 재시도 전에
       *   반드시 관련 설계 검토 후 논의할 것.
       */
      const performStateUpdate = (newValue: any) => {
        // 중요: prev (localDynamicState) 대신 parentFormContext.state (extendedDataContext._local)를 기반으로 사용
        // 그래야 init_actions로 설정된 API 데이터가 손실되지 않음
        const baseState = parentFormContext.state || {};
        const update = setNestedValue(baseState, fullPath, newValue);

        // trackChanges가 true이면 hasChanges도 설정
        if (parentFormContext.trackChanges) {
          update.hasChanges = true;
        }

        // 저장소 A: React setState가 비동기이므로, 액션 핸들러에서 즉시 최신 값을 읽을 수 있도록
        // 전역 캐시(__g7PendingLocalState)에 업데이트된 상태를 저장. G7Core.state.getLocal()이 이 캐시를 우선 사용.
        (window as any).__g7PendingLocalState = update;

        // 저장소 A: React localDynamicState — 해당 Input 트리 리렌더 (기존 경로, 성능 보존)
        parentFormContext.setState!(update);

        // _global.xxx dataKey는 _globalSetState가 이미 globalState에 직접 쓰므로 B 동기화 불필요
        if ((parentFormContext as any)._isGlobal) return;

        // 저장소 B: globalState._local — 플러그인/apiCall이 읽는 정본. render:false로 리렌더 억제
        // 이중 저장소를 늘 일치시키는 핵심 1줄. 이 경로가 빠지면 자동바인딩 정합성이 깨진다.
        const G7Core = (window as any).G7Core;
        const patch: Record<string, any> = { [fullPath]: newValue };
        if (parentFormContext.trackChanges) patch.hasChanges = true;
        G7Core?.state?.setLocal?.(patch, { render: false });
      };

      // debounce가 설정되면 debounced 업데이트, 아니면 즉시 업데이트
      // onComplete 콜백: 상태 업데이트 완료 후 실행 (액션 핸들러 등)
      const debouncedStateUpdate = (newValue: any, onComplete?: () => void) => {
        if (debounceMs && debounceMs > 0) {
          // 기존 타이머 정리
          if (autoBindingDebounceTimerRef.current) {
            clearTimeout(autoBindingDebounceTimerRef.current);
          }
          // 새 타이머 설정
          autoBindingDebounceTimerRef.current = setTimeout(() => {
            performStateUpdate(newValue);
            autoBindingDebounceTimerRef.current = null;
            // pending ref는 여기서 클리어하지 않음!
            // React setState는 비동기 배치되므로, 이 시점에서 클리어하면
            // refs 클리어 후 ~ state 반영 전 사이 렌더에서 stale 값이 표시됨 (플리커)
            // → useMemo에서 state가 pending 값과 일치하는 것을 확인한 후 클리어
            // 상태 업데이트 후 콜백 실행
            // __g7PendingLocalState 캐시를 사용하여 액션 핸들러에서 즉시 최신 값을 읽을 수 있음
            if (onComplete) {
              onComplete();
              // 콜백 실행 후 캐시 정리
              (window as any).__g7PendingLocalState = null;
            }
          }, debounceMs);
        } else {
          performStateUpdate(newValue);
          // debounce가 없어도 동일하게 처리
          if (onComplete) {
            onComplete();
            // 콜백 실행 후 캐시 정리
            (window as any).__g7PendingLocalState = null;
          }
        }
      };

      if (isCheckedBinding) {
        // checked 바인딩 (Toggle, Checkbox 등) - debounce 미적용 (토글은 즉시 반영)
        const autoOnChange = (e: React.ChangeEvent<HTMLInputElement> | { target: { checked: boolean } }) => {
          const newValue = e.target.checked;
          performStateUpdate(newValue);

          // 기존 onChange가 있으면 호출
          if (propsWithoutFormConfig.onChange) {
            propsWithoutFormConfig.onChange(e as any);
          }
        };

        return {
          ...propsWithoutFormConfig,
          checked: currentValue,  // 체크박스는 checked prop 사용
          onChange: autoOnChange,
        };
      } else {
        // value 바인딩 (Input, Textarea, Select, IconSelect, MultilingualInput 등)
        // 이벤트 객체 형태 { target: { value } } 또는 직접 값 전달 둘 다 지원
        const autoOnChange = (eventOrValue: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement> | { target: { value: any } } | any) => {
          // 이벤트 객체인지 직접 값인지 판단
          const newValue = eventOrValue?.target?.value !== undefined
            ? eventOrValue.target.value
            : eventOrValue;

          // debounce 활성 시 pending value 즉시 저장 (플리커 방지)
          // useMemo 재계산 시 stale state value 대신 이 값을 사용
          if (debounceMs && debounceMs > 0) {
            autoBindingPendingValueRef.current = newValue;
          }

          // debounce 콜백에서 사용할 이벤트 복사본 생성
          // React SyntheticEvent는 비동기적으로 접근하면 속성이 null이 되므로
          // 필요한 값을 미리 복사해둠 (300ms debounce 후에도 값에 접근 가능)
          const eventCopy = eventOrValue?.target
            ? {
                target: {
                  value: newValue,
                  name: eventOrValue.target.name,
                  type: eventOrValue.target.type,
                  checked: eventOrValue.target.checked,
                },
                currentTarget: eventOrValue.currentTarget ? {
                  value: newValue,
                  name: eventOrValue.currentTarget.name,
                } : undefined,
              }
            : eventOrValue;

          // debounce가 설정되면 debounced 업데이트
          // 상태 업데이트 완료 후 기존 onChange(액션 핸들러) 호출
          debouncedStateUpdate(newValue, () => {
            if (propsWithoutFormConfig.onChange) {
              propsWithoutFormConfig.onChange(eventCopy);
            }
          });
        };

        // debounce 대기 중 또는 debounce 완료 직후 pending value 사용 (플리커 방지)
        // 부모 리렌더링 시 currentValue는 stale state에서 읽힌 값일 수 있음
        // pending ref는 debounce 콜백에서 클리어하지 않고, state가 확정된 후 여기서 클리어
        let effectiveValue: any;
        if (autoBindingPendingValueRef.current !== undefined) {
          if (autoBindingDebounceTimerRef.current !== null) {
            // 타이머 실행 중 → 사용자 입력 중, pending value 사용
            effectiveValue = autoBindingPendingValueRef.current;
          } else {
            // 타이머 완료 (null) → state 반영 대기 중
            // state가 pending 값과 일치하면 확정 → pending 클리어
            if (String(currentValue ?? '') === String(autoBindingPendingValueRef.current)) {
              autoBindingPendingValueRef.current = undefined;
              effectiveValue = currentValue ?? '';
            } else {
              // state가 아직 반영되지 않음 → pending value 유지 (플리커 방지)
              effectiveValue = autoBindingPendingValueRef.current;
            }
          }
        } else {
          effectiveValue = currentValue ?? '';
        }

        return {
          ...propsWithoutFormConfig,
          value: effectiveValue,
          onChange: autoOnChange,
        };
      }
    }, [stableResolvedProps, parentFormContext, parentFormContext.state, parentFormContext.debounce]);

    /**
     * 메인 렌더링 로직
     */
    const renderComponent = useMemo(() => {
      // DevTools 렌더 추적
      const devToolsRender = getDevTools();
      if (devToolsRender?.isEnabled()) {
        devToolsRender.trackRender(componentDef.name);
      }

      // 조건부 렌더링 체크
      if (!shouldRender) {
        return null;
      }

      // 정렬 가능 리스트 렌더링 체크 (sortable은 iteration보다 우선)
      if (effectiveComponentDef.sortable && effectiveComponentDef.itemTemplate) {
        return renderSortable;
      }

      // 반복 렌더링 체크
      if (effectiveComponentDef.iteration) {
        return renderIteration;
      }

      // Extension Point 타입 처리
      // 백엔드에서 이미 children에 컴포넌트를 주입한 상태로 전달됩니다.
      // 프론트엔드에서는 단순히 컨테이너로 렌더링하고 children을 출력합니다.
      if (effectiveComponentDef.type === 'extension_point') {
        const { layout, columns, gap, className, ...restProps } = effectiveComponentDef.props || {};

        // 레이아웃 설정에 따른 컨테이너 스타일 결정
        let containerClass = className || '';
        if (layout === 'grid' && columns) {
          containerClass = `${containerClass} grid grid-cols-${columns} gap-${gap || 4}`.trim();
        } else if (layout === 'flex') {
          containerClass = `${containerClass} flex flex-col gap-${gap || 4}`.trim();
        }

        return (
          <div
            id={effectiveComponentDef.id}
            className={containerClass || undefined}
            {...restProps}
          >
            {renderChildren}
          </div>
        );
      }

      // ComponentRegistry에서 컴포넌트 조회
      const Component = registry.getComponent(effectiveComponentDef.name);

      if (!Component) {
        logger.error(
          `컴포넌트를 찾을 수 없습니다: ${effectiveComponentDef.name} (ID: ${effectiveComponentDef.id})`,
          'componentDef:', JSON.stringify(effectiveComponentDef, null, 2)
        );
        return null;
      }

      // 컴포넌트에 전달할 최종 props 구성
      // - 컴포넌트 정의의 id를 DOM id로 전달 (showErrorPage의 containerId로 사용 가능)
      // - props에 이미 id가 명시적으로 지정되어 있으면 그것을 우선 사용
      // - cellChildren이 있는 컴포넌트는 _remountKeys 변경 시 리렌더링되어야 함
      const remountKeys = dataContext._global?._remountKeys;
      const finalProps: Record<string, any> = {
        ...propsWithAutoBinding,
        id: propsWithAutoBinding.id ?? effectiveComponentDef.id,
        // _remountKeys가 변경되면 React가 props 변경을 감지하여 컴포넌트 리렌더링
        // cellChildren 내부의 컴포넌트가 새 key를 받을 수 있도록 함
        ...(remountKeys && Object.keys(remountKeys).length > 0 && {
          __remountTrigger: JSON.stringify(remountKeys),
        }),
      };

      // Sortable drag handle 자동 적용
      // data-drag-handle 속성이 있고 sortableContext가 있으면 listeners 적용
      if (sortableContext?.listeners && sortableContext.handle) {
        const hasDragHandle = propsWithAutoBinding['data-drag-handle'] !== undefined;
        if (hasDragHandle) {
          // listeners를 finalProps에 병합
          Object.assign(finalProps, sortableContext.listeners);
          // 드래그 커서 스타일 추가
          finalProps.style = {
            ...finalProps.style,
            cursor: sortableContext.isDragging ? 'grabbing' : 'grab',
          };
        }
      }

      // 편집 모드일 때 data-editor-id 속성 추가
      if (isEditMode) {
        // id가 없는 컴포넌트에 자동 ID 생성 (컴포넌트 타입과 랜덤 문자열 기반)
        const editorId =
          effectiveComponentDef.id ||
          `auto_${effectiveComponentDef.name || effectiveComponentDef.type || 'unknown'}_${Math.random().toString(36).substring(2, 8)}`;

        finalProps['data-editor-id'] = editorId;
        finalProps['data-editor-name'] = effectiveComponentDef.name;
        finalProps['data-editor-type'] = effectiveComponentDef.type;
        // 인덱스 경로 추가 (ID 없는 컴포넌트 편집용)
        if (componentPath) {
          finalProps['data-editor-path'] = componentPath;
        }

        // 편집 모드 클릭 핸들러 (기존 onClick과 병합)
        const originalOnClick = finalProps.onClick;
        finalProps.onClick = (e: React.MouseEvent) => {
          // 편집기 선택 핸들러 호출
          if (onComponentSelect) {
            e.stopPropagation();
            onComponentSelect(editorId, e);
          }
          // 기존 onClick도 실행
          if (typeof originalOnClick === 'function') {
            originalOnClick(e);
          }
        };

        // 편집 모드 호버 핸들러
        // onMouseMove + stopPropagation을 사용하여 가장 안쪽(자식) 컴포넌트가 우선 하이라이트되도록 함
        if (onComponentHover) {
          finalProps.onMouseMove = (e: React.MouseEvent) => {
            e.stopPropagation(); // 부모 컴포넌트로 이벤트 전파 방지
            onComponentHover(editorId, e);
          };
          finalProps.onMouseLeave = (e: React.MouseEvent) => {
            onComponentHover(null, e);
          };
        }

        // 편집 모드 드래그 핸들러 (프리뷰 캔버스에서 드래그 앤 드롭)
        finalProps.draggable = true;

        if (onDragStart) {
          finalProps.onDragStart = (e: React.DragEvent) => {
            e.stopPropagation(); // 부모 컴포넌트로 이벤트 전파 방지
            onDragStart(editorId, e);
          };
        }

        if (onDragEnd) {
          finalProps.onDragEnd = (e: React.DragEvent) => {
            onDragEnd(e);
          };
        }
      }

      // 컴포넌트 렌더링
      // Basic/Layout 컴포넌트는 HTML 요소를 직접 렌더링하므로
      // 내부 props(__componentContext 등)를 DOM에 전달하면 [object Object]로 노출됨
      // Composite 컴포넌트(React 컴포넌트)는 내부 props를 직접 처리하므로 그대로 전달
      const isBasicOrLayoutComponent = effectiveComponentDef.type === 'basic' || effectiveComponentDef.type === 'layout';
      const propsForRendering = isBasicOrLayoutComponent
        ? (() => {
            // eslint-disable-next-line @typescript-eslint/no-unused-vars
            const { __componentContext, __componentLayoutDefs, ...domSafeProps } = finalProps;
            return domSafeProps;
          })()
        : finalProps;

      let componentElement: React.ReactNode = <Component {...propsForRendering}>{renderChildren}</Component>;

      // dataKey가 있으면 FormProvider로 감싸기
      // formContextForChildren은 effectiveComponentDef.dataKey를 사용 (componentDef 레벨)
      // currentFormContext는 stableResolvedProps.dataKey를 사용하므로 맞지 않음 (props 내부에 dataKey가 없음)
      if (formContextForChildren) {
        componentElement = (
          <FormProvider value={formContextForChildren}>
            {componentElement}
          </FormProvider>
        );
      }

      // isolatedState가 있으면 IsolatedStateProvider로 감싸기
      // IsolatedStateProvider는 가장 바깥에 위치하여 독립된 상태 스코프 생성
      if (effectiveComponentDef.isolatedState) {
        const baseIsolatedState = typeof effectiveComponentDef.isolatedState === 'object'
          ? effectiveComponentDef.isolatedState
          : {};

        // _isolatedInit가 있으면 초기 상태와 병합 (data_sources의 initIsolated 옵션 지원)
        const isolatedInitData = (dataContext as any)?._isolatedInit;
        const initialIsolatedState = isolatedInitData
          ? { ...baseIsolatedState, ...isolatedInitData }
          : baseIsolatedState;

        componentElement = (
          <IsolatedStateProvider
            initialState={initialIsolatedState}
            scopeId={effectiveComponentDef.isolatedScopeId}
          >
            {componentElement}
          </IsolatedStateProvider>
        );
      }

      return componentElement;
    }, [
      shouldRender,
      effectiveComponentDef.iteration,
      effectiveComponentDef.sortable,
      effectiveComponentDef.itemTemplate,
      effectiveComponentDef.type,
      effectiveComponentDef.name,
      effectiveComponentDef.id,
      effectiveComponentDef.props,
      effectiveComponentDef.isolatedState,
      effectiveComponentDef.isolatedScopeId,
      registry,
      propsWithAutoBinding,
      renderChildren,
      renderIteration,
      renderSortable,
      sortableContext,
      currentFormContext,
      isEditMode,
      onComponentSelect,
      onComponentHover,
      onDragStart,
      onDragEnd,
      componentPath,
      // cellChildren이 있는 컴포넌트가 _remountKeys 변경 시 리렌더링되도록 의존성 추가
      dataContext._global?._remountKeys,
    ]);

    /**
     * blur_until_loaded 적용 여부 결정
     *
     * blur_until_loaded는 다음 형식을 지원합니다:
     * - boolean true: 모든 데이터 소스 로딩 완료까지 blur
     * - string: 표현식 (예: "{{_global.isSaving}}")이 truthy일 때 blur
     * - object: { enabled: boolean, data_sources?: string | string[] }
     *   - enabled: blur 활성화 여부
     *   - data_sources: 특정 데이터 소스만 체크 (지정하지 않으면 모든 데이터 소스 체크)
     *
     * blur 적용 조건:
     * 1. 페이지 전환 중 (isTransitioning === true)
     * 2. 지정된 데이터 소스가 아직 로드되지 않음 (undefined)
     */
    const shouldApplyBlur = useMemo(() => {
      const blurConfig = effectiveComponentDef.blur_until_loaded;

      if (!blurConfig) {
        return false;
      }

      // 프리뷰 모드에서는 blur 비활성화 — 시각적 확인이 목적이므로
      // @since engine-v1.26.1
      if (dataContext?._global?.__isPreview) {
        return false;
      }

      // 표현식 문자열인 경우 (예: "{{_global.isSaving}}")
      if (typeof blurConfig === 'string') {
        const singleBindingPath = extractSingleBinding(blurConfig);
        if (singleBindingPath !== null) {
          try {
            // DevTools 추적을 위한 컴포넌트 컨텍스트 정보
            const blurTrackingInfo = {
              componentId: effectiveComponentDef.id,
              componentName: effectiveComponentDef.name,
              propName: 'blur_until_loaded',
            };
            const result = bindingEngine.evaluateExpression(singleBindingPath, extendedDataContext, blurTrackingInfo);
            return Boolean(result);
          } catch (error) {
            logger.warn(
              `blur_until_loaded 표현식 평가 실패 (컴포넌트: ${effectiveComponentDef.id}):`,
              error
            );
            return false;
          }
        }
        return false;
      }

      // 객체 형태인 경우: { enabled: boolean, data_sources?: string | string[] }
      if (typeof blurConfig === 'object' && blurConfig !== null) {
        if (!blurConfig.enabled) {
          return false;
        }

        // data_sources가 지정된 경우 해당 데이터 소스만 체크 (isTransitioning 무시)
        if (blurConfig.data_sources) {
          const targetSources = Array.isArray(blurConfig.data_sources)
            ? blurConfig.data_sources
            : [blurConfig.data_sources];
          return targetSources.some((key: string) => dataContext[key] === undefined);
        }

        // data_sources가 없으면 페이지 전환 상태 또는 모든 데이터 소스 체크
        if (isTransitioning) {
          return true;
        }

        const systemKeys = ['route', 'query', '_global', '_local', '_dataSourceErrors'];
        const dataSourceKeys = Object.keys(dataContext).filter(
          key => !systemKeys.includes(key) && !key.startsWith('_')
        );
        return dataSourceKeys.some(key => dataContext[key] === undefined);
      }

      // boolean true인 경우

      // 1. 페이지 전환 중이면 blur 적용
      if (isTransitioning) {
        return true;
      }

      // 2. 모든 데이터 소스 상태 체크
      const systemKeys = ['route', 'query', '_global', '_local', '_dataSourceErrors'];
      const dataSourceKeys = Object.keys(dataContext).filter(
        key => !systemKeys.includes(key) && !key.startsWith('_')
      );
      return dataSourceKeys.some(key => dataContext[key] === undefined);
    }, [effectiveComponentDef.blur_until_loaded, effectiveComponentDef.id, isTransitioning, dataContext, extendedDataContext, bindingEngine, extractSingleBinding]);

    // DEBUG: blur 적용 시 로깅
    if (shouldApplyBlur) {
      const blurConfig = effectiveComponentDef.blur_until_loaded;
      if (typeof blurConfig === 'object' && blurConfig?.data_sources) {
        const targetSources = Array.isArray(blurConfig.data_sources)
          ? blurConfig.data_sources
          : [blurConfig.data_sources];
        const dataSourceStatus = targetSources.reduce((acc, key) => {
          acc[key] = dataContext[key] === undefined ? 'UNDEFINED' : 'LOADED';
          return acc;
        }, {} as Record<string, string>);
        // dataContext에 있는 모든 키도 출력
        const allDataContextKeys = Object.keys(dataContext).filter(k => !k.startsWith('_'));
        logger.log(`[BLUR APPLIED] Component: ${effectiveComponentDef.id}, data_sources status:`, dataSourceStatus, 'Available keys:', allDataContextKeys);
      } else {
        logger.log(`[BLUR APPLIED] Component: ${effectiveComponentDef.id}, blur_until_loaded:`, blurConfig);
      }
    }

    /**
     * slot 속성이 있는 컴포넌트는 원래 위치에서 렌더링하지 않음
     *
     * SlotContext에 등록되어 SlotContainer에서 렌더링됩니다.
     * useEffect에서 이미 등록 처리가 완료되었으므로 여기서는 null 반환만 합니다.
     *
     * 중요: React Context 대신 window.__slotContextValue를 직접 확인
     * - 타이밍 이슈로 slotContext.isEnabled가 false일 수 있음
     */
    const globalSlotContextForRender = (window as any).__slotContextValue;
    if (resolvedSlotId && globalSlotContextForRender?.isEnabled) {
      return null;
    }

    // 기본 렌더링 결과
    const renderedContent = (
      <ComponentErrorBoundary
        componentId={componentDef.id}
        componentName={componentDef.name}
      >
        {effectiveComponentDef.blur_until_loaded ? (
          <div className={shouldApplyBlur ? "opacity-50 blur-sm transition-all duration-300 pointer-events-none" : undefined}>
            {renderComponent}
          </div>
        ) : (
          renderComponent
        )}
      </ComponentErrorBoundary>
    );

    // 외부(renderTemplate/updateTemplateData)에서 SlotProvider를 제공하므로
    // isRootRenderer에서의 추가 SlotProvider 래핑 불필요 (이중 래핑 방지)
    return renderedContent;
  }
);

DynamicRenderer.displayName = 'DynamicRenderer';

/**
 * DynamicRenderer를 전역에 노출
 *
 * SlotContainer 등 외부 컴포넌트에서 G7Core.getDynamicRenderer()로 접근할 수 있도록 합니다.
 */
if (typeof window !== 'undefined') {
  (window as any).__DynamicRenderer = DynamicRenderer;
}

export default DynamicRenderer;
