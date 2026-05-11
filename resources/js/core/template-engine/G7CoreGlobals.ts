/**
 * NOTE: @since versions (engine-v1.x.x) refer to internal template engine
 * development iterations, not G7 platform release versions.
 */
/**
 * G7Core 전역 객체 초기화 모듈
 *
 * 템플릿 컴포넌트에서 사용할 수 있는 G7Core 전역 API를 노출합니다.
 * template-engine.ts에서 분리되어 단일 책임 원칙을 따릅니다.
 *
 * @packageDocumentation
 */

import React from 'react';
import ReactDOM from 'react-dom/client';
import { createPortal } from 'react-dom';
import * as ReactJSXRuntime from 'react/jsx-runtime';
import { ComponentRegistry } from './ComponentRegistry';
import { TranslationEngine, TranslationContext } from './TranslationEngine';
import { ActionDispatcher } from './ActionDispatcher';
import { DataBindingEngine } from './DataBindingEngine';
import { useTransitionState } from './TransitionContext';
import { useTranslation } from './TranslationContext';
import { useResponsive } from './ResponsiveContext';
import { AuthManager } from '../auth/AuthManager';
import { getApiClient } from '../api/ApiClient';
import { createLogger, flushEarlyLogs } from '../utils/Logger';
import { WebSocketManager } from '../websocket/WebSocketManager';
import { G7DevToolsCore } from '../devtools/G7DevToolsCore';
import { DiagnosticEngine } from '../devtools/DiagnosticEngine';
import { getServerConnector } from '../devtools/ServerConnector';
import { DevToolsPanel } from '../devtools/ui/DevToolsPanel';
import { getStyleTracker } from '../devtools/StyleTracker';
import type { DiagnosticCategory } from '../devtools/types';
import {
  renderItemChildren,
  createChangeEvent,
  createClickEvent,
  createSubmitEvent,
  createKeyboardEvent,
  mergeClasses,
  conditionalClass,
  joinClasses,
} from './helpers';
import type { SlotContextValue } from './SlotContext';
import {
  useControllableState,
  shallowArrayEqual,
  shallowObjectEqual,
} from '../hooks/useControllableState';
import { triggerModalParentUpdate } from './ParentContextProvider';
import { IdentityGuardInterceptor, IDENTITY_REDIRECT_STASH_KEY } from '../identity/IdentityGuardInterceptor';

const logger = createLogger('G7CoreGlobals');

/**
 * G7Core.devTools 인터페이스
 *
 * 템플릿 컴포넌트와 헬퍼 함수에서 DevTools 추적 기능에 접근하기 위한 통합 인터페이스입니다.
 * G7DevToolsCore.getInstance() 직접 호출 대신 이 인터페이스를 사용합니다.
 *
 * @example
 * ```ts
 * // renderItemChildren 등에서 사용
 * if (G7Core.devTools?.isEnabled()) {
 *   G7Core.devTools.trackIteration(id, source, itemVar, indexVar, itemCount);
 * }
 * ```
 */
export interface G7DevToolsInterface {
  /** DevTools 활성화 상태 확인 */
  isEnabled(): boolean;

  /** 렌더링 추적 */
  trackRender(componentName: string): void;

  /** iteration 추적 */
  trackIteration(
    id: string,
    source: string,
    itemVar: string,
    indexVar: string | undefined,
    itemCount: number
  ): void;

  /** 조건부 렌더링 추적 */
  trackIfCondition(
    id: string,
    condition: string,
    result: boolean,
    componentName?: string
  ): void;

  /** 컴포넌트 마운트 추적 */
  trackMount(componentId: string, info: { name: string; type: string; props?: Record<string, any>; parentId?: string }): void;

  /** 컴포넌트 언마운트 추적 */
  trackUnmount(componentId: string): void;

  /** 표현식 평가 추적 */
  trackExpressionEval(info: {
    expression: string;
    result: any;
    resultType: string;
    duration?: number;
    method: 'resolve' | 'resolveBindings' | 'evaluateExpression';
    componentId?: string;
    componentName?: string;
    propName?: string;
    fromCache: boolean;
    skipCache?: boolean;
    steps?: Array<{
      step: 'parse' | 'resolve' | 'evaluate' | 'cache';
      input?: string;
      output?: any;
      path?: string;
      value?: any;
      fromCache?: boolean;
      error?: string;
    }>;
  }): void;

  /** 바인딩 평가 추적 */
  trackBindingEval(expression?: string): void;

  /** 캐시 히트 추적 */
  recordCacheHit(): void;

  /** 캐시 미스 추적 */
  recordCacheMiss(): void;

  /** 핸들러 등록 추적 */
  trackHandlerRegistration(
    name: string,
    category: 'built-in' | 'custom' | 'module' | 'plugin',
    description?: string,
    source?: string
  ): void;

  /** 핸들러 해제 추적 */
  trackHandlerUnregistration(name: string): void;

  /** 액션 로그 기록 */
  logAction(action: {
    id: string;
    type: string;
    params?: Record<string, any>;
    resolvedParams?: Record<string, any>;
    context?: Record<string, any>;
    startTime: number;
    endTime?: number;
    duration?: number;
    status: 'started' | 'success' | 'error';
    result?: any;
    error?: { name: string; message: string; stack?: string };
    children?: Array<any>;
  }): void;

  /** 네트워크 요청 시작 추적 */
  trackRequest(url: string, method: string): string;

  /** 네트워크 요청 완료 추적 */
  completeRequest(requestId: string, statusCode: number, response?: any): void;

  /** 네트워크 요청 실패 추적 */
  failRequest(requestId: string, error: string): void;

  /** 데이터소스 정의 추적 */
  trackDataSourceDefinition(info: {
    id: string;
    type: 'api' | 'static' | 'route_params' | 'query_params' | 'websocket';
    endpoint?: string;
  }): void;

  /** 데이터소스 로딩 시작 추적 */
  trackDataSourceLoading(id: string): void;

  /** 데이터소스 로드 완료 추적 */
  trackDataSourceLoaded(id: string, data: any, dataPath?: string): void;

  /** 데이터소스 로드 실패 추적 */
  trackDataSourceError(id: string, error: string): void;

  /** Form 추적 */
  trackForm(id: string, dataKey: string, inputs: Array<{ name: string; type: string; binding?: string }>): void;

  /** Form 제거 추적 */
  untrackForm(id: string): void;

  /** setState 렌더링 상관관계 추적 - 시작 */
  startStateChange(
    statePath: string,
    oldValue: any,
    newValue: any,
    trigger?: { actionId?: string; handlerType?: string; source?: string }
  ): string;

  /** setState 렌더링 상관관계 추적 - 완료 */
  completeStateChange(setStateId: string): void;

  /** 컴포넌트 렌더링 추적 (상태 변경 컨텍스트 내) */
  trackComponentRender(
    componentId: string,
    componentName: string,
    renderDuration: number,
    accessedStatePaths?: string[],
    evaluatedBindings?: string[],
    parentId?: string
  ): void;

  /** _local 상태 업데이트 */
  updateLocalState(localState: Record<string, any>): void;

  /** _computed 상태 업데이트 */
  updateComputedState(computedState: Record<string, any>): void;

  /** $parent 컨텍스트 업데이트 (모달/자식 레이아웃에서 부모 상태 접근용) */
  updateParentContext(parentContext: Record<string, any> | undefined): void;

  /** 컴포넌트 상태 소스 추적 (g7-state-hierarchy) */
  trackComponentStateSource?(
    componentId: string,
    componentName: string,
    stateSource: {
      global: string[];
      local: string[];
      context: string[];
    },
    stateProvider: {
      type: 'globalState' | 'dynamicState' | 'componentContext';
      hasComponentContext: boolean;
      parentId?: string;
    }
  ): void;

  /** dynamicState 추적 (g7-state-hierarchy) */
  trackDynamicState?(componentId: string, dynamicState: Record<string, any>): void;

  /** 컨텍스트 플로우 추적 (g7-context-flow) */
  trackContextFlow?(
    componentId: string,
    componentName: string,
    contextReceived: boolean,
    passedToChildren: boolean,
    usedInRender: boolean,
    parentId?: string
  ): void;

  /** 컴포넌트 스타일 추적 (g7-styles) */
  trackComponentStyle?(
    componentId: string,
    componentName: string,
    classes: string[],
    computedStyles: {
      display: string;
      visibility: string;
      opacity: string;
      position: string;
      zIndex: string;
      overflow: string;
      width: string;
      height: string;
      backgroundColor: string;
      color: string;
    }
  ): void;

  /** 인증 이벤트 추적 (g7-auth) */
  trackAuthEvent?(
    type: 'login' | 'logout' | 'token-refresh' | 'token-expired' | 'session-restored' | 'permission-denied' | 'api-unauthorized',
    success: boolean,
    error?: string,
    details?: Record<string, any>
  ): void;

  /** 인증 헤더 추적 (g7-auth) */
  trackAuthHeader?(
    url: string,
    hasAuthHeader: boolean,
    headerType?: string,
    tokenValid?: boolean,
    responseStatus?: number
  ): void;

  /** 로그 추적 (g7-logs) */
  trackLog?(
    level: 'log' | 'warn' | 'error' | 'debug' | 'info',
    prefix: string,
    args: unknown[]
  ): void;

  /**
   * 액션 실행 추적 (debounce 지원)
   *
   * debounce 옵션이 있는 액션의 실행 상태를 추적합니다.
   */
  trackAction?(info: {
    handler: string;
    type: string;
    status: 'pending' | 'success' | 'error' | 'cancelled';
    params?: Record<string, any>;
    debounce?: {
      delay: number;
      status: 'pending' | 'executed' | 'cancelled';
      scheduledAt?: number;
      executedAt?: number;
      mode?: 'leading' | 'trailing';
    };
  }): void;

  /**
   * 데이터소스 업데이트 추적 (부분 업데이트 지원)
   *
   * G7Core.dataSource.updateItem() 호출을 추적합니다.
   */
  trackDataSourceUpdate?(info: {
    dataSourceId: string;
    updateType: 'full' | 'partial';
    itemPath?: string;
    itemId?: string | number;
    updates?: Record<string, any>;
    timestamp: number;
  }): void;

  // ============================================
  // Sequence 실행 추적 메서드 (g7-sequence)
  // ============================================

  /**
   * Sequence 실행 시작
   *
   * @param trigger 트리거 정보
   * @returns 생성된 sequenceId
   */
  startSequenceExecution?(trigger?: {
    eventType?: string;
    source?: string;
    callbackType?: 'onSuccess' | 'onError';
  }): string;

  /**
   * Sequence 내 액션 실행 전 상태 캡처
   *
   * @param sequenceId Sequence ID
   * @param actionIndex 액션 인덱스
   * @param handler 핸들러 이름
   * @param params 액션 파라미터
   * @param currentState 현재 상태
   */
  captureSequenceActionBefore?(
    sequenceId: string,
    actionIndex: number,
    handler: string,
    params: Record<string, any>,
    currentState: {
      _global: Record<string, any>;
      _local: Record<string, any>;
      _isolated?: Record<string, any>;
    }
  ): void;

  /**
   * Sequence 내 액션 실행 후 상태 캡처
   *
   * @param sequenceId Sequence ID
   * @param actionIndex 액션 인덱스
   * @param currentState 현재 상태
   * @param duration 소요 시간
   * @param result 액션 결과
   * @param error 에러 (있는 경우)
   */
  captureSequenceActionAfter?(
    sequenceId: string,
    actionIndex: number,
    currentState: {
      _global: Record<string, any>;
      _local: Record<string, any>;
      _isolated?: Record<string, any>;
    },
    duration: number,
    result?: any,
    error?: Error
  ): void;

  /**
   * Sequence 실행 완료
   *
   * @param sequenceId Sequence ID
   * @param error 전체 에러 (있는 경우)
   */
  endSequenceExecution?(sequenceId: string, error?: Error): void;

  // ============================================
  // Stale Closure 감지 메서드 (g7-stale-closure)
  // ============================================

  /**
   * 상태 캡처 등록 (비동기 핸들러 시작 시)
   *
   * @param handlerId 핸들러 ID
   * @param statePaths 캡처할 상태 경로들
   * @param stateValues 현재 상태 값들
   */
  registerStateCaptureForHandler?(
    handlerId: string,
    statePaths: string[],
    stateValues: Record<string, any>
  ): void;

  /**
   * Stale Closure 감지 (비동기 작업 완료 후)
   *
   * @param handlerId 핸들러 ID
   * @param location 발생 위치
   * @param currentState 현재 상태
   * @param warningType 경고 유형
   * @param actionId 관련 액션 ID
   */
  detectStaleClosure?(
    handlerId: string,
    location: string,
    currentState: Record<string, any>,
    warningType: 'async-state-capture' | 'callback-state-capture' | 'timeout-state-capture' | 'sequence-state-mismatch' | 'event-handler-stale',
    actionId?: string
  ): any[];

  /**
   * Stale Closure 경고 직접 추가
   *
   * @param info 경고 정보
   */
  trackStaleClosureWarning?(info: {
    type: 'async-state-capture' | 'callback-state-capture' | 'timeout-state-capture' | 'sequence-state-mismatch' | 'event-handler-stale';
    location: string;
    capturedPath: string;
    capturedValue: any;
    capturedAt: number;
    currentValue: any;
    actionId?: string;
    stackTrace?: string;
  }): void;

  // ============================================
  // Modal State Scope 추적 메서드
  // ============================================

  /**
   * 모달 열림 추적
   *
   * @param options 모달 정보
   */
  trackModalOpen?(options: {
    modalId: string;
    modalName: string;
    scopeType?: 'isolated' | 'shared' | 'inherited';
    parentModalId?: string;
    componentId?: string;
    initialState?: Record<string, any>;
    isolatedStateKeys?: string[];
    sharedStateKeys?: string[];
  }): void;

  /**
   * 모달 닫힘 추적
   *
   * @param modalId 모달 ID
   * @param finalState 최종 상태 (선택)
   */
  trackModalClose?(modalId: string, finalState?: Record<string, any>): void;

  /**
   * 모달 내 상태 변경 추적
   *
   * @param options 상태 변경 정보
   */
  trackModalStateChange?(options: {
    modalId: string;
    stateKey: string;
    previousValue: any;
    newValue: any;
    changeSource?: 'user-action' | 'api-response' | 'parent-sync' | 'init' | 'cleanup';
  }): void;

  // ============================================
  // Nested Context 추적 메서드
  // ============================================

  /**
   * Nested Context 추적 (expandChildren, cellChildren, iteration, modal, slot)
   *
   * @param info 컨텍스트 정보
   * @returns 생성된 컨텍스트 ID
   */
  trackNestedContext?(info: {
    componentId: string;
    componentType: 'expandChildren' | 'cellChildren' | 'iteration' | 'modal' | 'slot';
    parentContext: {
      available: string[];
      values: Record<string, any>;
    };
    ownContext: {
      added: string[];
      values: Record<string, any>;
    };
    depth: number;
    parentId?: string;
  }): string;

  /**
   * Nested Context 접근 시도 기록
   *
   * @param contextId 컨텍스트 ID
   * @param access 접근 정보
   */
  trackNestedContextAccess?(contextId: string, access: {
    path: string;
    found: boolean;
    value?: any;
    error?: string;
  }): void;

  // ============================================
  // Computed 추적 메서드
  // ============================================

  /**
   * Computed 속성 등록
   *
   * @param name 속성명
   * @param expression 표현식
   * @param dependencies 의존성 배열
   * @param value 현재 값
   * @param computationTime 계산 시간
   * @param componentId 컴포넌트 ID (선택)
   * @param error 에러 메시지 (선택)
   */
  trackComputedProperty?(
    name: string,
    expression: string,
    dependencies: Array<{ type: string; path: string; value?: any }>,
    value: any,
    computationTime: number,
    componentId?: string,
    error?: string
  ): void;

  /**
   * Computed 재계산 기록
   *
   * @param computedName 속성명
   * @param trigger 트리거 유형
   * @param previousValue 이전 값
   * @param newValue 새 값
   * @param computationTime 계산 시간
   * @param triggeredBy 트리거 정보 (선택)
   * @param componentId 컴포넌트 ID (선택)
   */
  trackComputedRecalc?(
    computedName: string,
    trigger: 'dependency-change' | 'manual' | 'init',
    previousValue: any,
    newValue: any,
    computationTime: number,
    triggeredBy?: {
      type: string;
      path: string;
      oldValue?: any;
      newValue?: any;
    },
    componentId?: string
  ): void;

  // ============================================
  // Named Actions 추적 메서드 (g7-named-actions)
  // ============================================

  /** named_actions 정의 등록 */
  setNamedActionDefinitions?(definitions: Record<string, any>): void;

  /** actionRef 해석 이력 기록 */
  trackNamedActionRef?(log: {
    actionRefName: string;
    resolvedHandler: string;
    timestamp: number;
  }): void;

}

/**
 * G7Core 전역 객체 초기화에 필요한 의존성 인터페이스
 */
export interface G7CoreDependencies {
  /** 템플릿 엔진 상태 getter */
  getState: () => {
    translationEngine: TranslationEngine | null;
    translationContext: TranslationContext;
    bindingEngine: DataBindingEngine | null;
    actionDispatcher: ActionDispatcher | null;
    templateMetadata: { locales: string[] } | null;
  };
  /** TransitionManager 인스턴스 */
  transitionManager: {
    getIsPending: () => boolean;
    subscribe: (callback: (isPending: boolean) => void) => () => void;
  };
  /** ResponsiveManager 인스턴스 */
  responsiveManager: object;
  /** WebSocketManager 인스턴스 */
  webSocketManager: WebSocketManager;
  /** SlotContext getter (SlotProvider 내부에서 설정) */
  getSlotContext?: () => SlotContextValue | null;
  /** DynamicRenderer 컴포넌트 getter */
  getDynamicRenderer?: () => React.FC<any> | null;
  /** ComponentRegistry getter */
  getComponentRegistry?: () => any;
  /** DataBindingEngine getter */
  getDataBindingEngine?: () => DataBindingEngine | null;
  /** TranslationEngine getter */
  getTranslationEngine?: () => TranslationEngine | null;
  /** ActionDispatcher getter */
  getActionDispatcher?: () => ActionDispatcher | null;
}

/**
 * React 라이브러리를 전역으로 노출
 *
 * 템플릿 번들에서 React를 사용할 수 있도록 합니다.
 */
function initReactGlobals(): void {
  // React/ReactDOM/ReactJSXRuntime을 전역으로 노출 (템플릿 번들에서 사용)
  (window as any).React = React;
  // React 19에서 unstable_batchedUpdates가 제거되어 @dnd-kit 등에서 에러 발생
  // no-op 폴리필을 제공하여 호환성 유지 (React 19에서는 자동 배칭이 기본값)
  (window as any).ReactDOM = {
    ...ReactDOM,
    createPortal,
    unstable_batchedUpdates: (callback: () => void) => callback(),
  };
  (window as any).ReactJSXRuntime = ReactJSXRuntime;

  logger.log('전역 객체 window.React, window.ReactDOM, window.ReactJSXRuntime에 노출됨');
}

/**
 * 컴포넌트 이벤트 시스템 초기화
 *
 * 컴포넌트 간 통신을 위한 이벤트 시스템을 제공합니다.
 */
function initComponentEventSystem(G7Core: any): void {
  const componentEventListeners = new Map<string, Set<(data?: any) => void | Promise<any>>>();
  const devTools = G7DevToolsCore.getInstance();

  G7Core.componentEvent = {
    /**
     * 컴포넌트 이벤트를 구독합니다.
     * @param eventName 이벤트명 (예: 'triggerUpload:site_logo_uploader')
     * @param callback 이벤트 발생 시 호출될 콜백
     * @returns 구독 해제 함수
     */
    on: (eventName: string, callback: (data?: any) => void | Promise<any>) => {
      if (!componentEventListeners.has(eventName)) {
        componentEventListeners.set(eventName, new Set());
      }
      componentEventListeners.get(eventName)!.add(callback);

      // DevTools 추적
      devTools.trackEventSubscribe(eventName);

      return () => {
        componentEventListeners.get(eventName)?.delete(callback);
        // DevTools 추적
        devTools.trackEventUnsubscribe(eventName);
      };
    },
    /**
     * 컴포넌트 이벤트를 발생시킵니다.
     * @param eventName 이벤트명
     * @param data 전달할 데이터
     * @returns 모든 리스너의 실행 결과 Promise 배열
     */
    emit: async (eventName: string, data?: any): Promise<any[]> => {
      const listeners = componentEventListeners.get(eventName);
      const listenerCount = listeners?.size ?? 0;

      if (!listeners || listeners.size === 0) {
        // DevTools 추적 (리스너 없음)
        devTools.trackEventEmit(eventName, data, 0);
        return [];
      }

      let emitError: Error | undefined;
      let results: any[] = [];

      try {
        results = await Promise.all(
          Array.from(listeners).map(async (callback) => {
            try {
              return await callback(data);
            } catch (error) {
              logger.error(`componentEvent: Error in listener for "${eventName}":`, error);
              throw error;
            }
          })
        );
      } catch (error) {
        emitError = error instanceof Error ? error : new Error(String(error));
      }

      // DevTools 추적
      devTools.trackEventEmit(eventName, data, listenerCount, results, emitError);

      if (emitError) {
        throw emitError;
      }

      return results;
    },
    /**
     * 특정 이벤트의 모든 리스너를 제거합니다.
     * @param eventName 이벤트명
     */
    off: (eventName: string) => {
      componentEventListeners.delete(eventName);
      // DevTools 추적
      devTools.trackEventOff(eventName);
    },
    /**
     * 모든 이벤트 리스너를 제거합니다.
     */
    clear: () => {
      componentEventListeners.clear();
      // DevTools 추적
      devTools.trackEventClear();
    },
  };

  logger.log('전역 객체 window.G7Core.componentEvent에 노출됨');
}

/**
 * 번역 관련 API 초기화
 */
function initTranslationAPI(G7Core: any, deps: G7CoreDependencies): void {
  // useTranslation 훅 노출 (템플릿 컴포넌트에서 다국어 번역 함수 접근용)
  G7Core.useTranslation = useTranslation;

  /**
   * G7Core.t() - 다국어 번역 함수 직접 접근
   *
   * 컴포넌트에서 훅 없이 간편하게 번역 함수를 사용할 수 있습니다.
   *
   * @example
   * ```tsx
   * // 간단한 번역
   * const text = G7Core.t('common.confirm');
   *
   * // 파라미터를 포함한 번역
   * const message = G7Core.t('admin.users.pagination_info', { from: 1, to: 10, total: 100 });
   * ```
   *
   * @param key 번역 키 (예: 'common.confirm')
   * @param params 번역 파라미터 (선택, 예: { count: 5 })
   * @returns 번역된 문자열, 번역이 없으면 키 자체 반환
   */
  G7Core.t = (key: string, params?: Record<string, string | number>): string => {
    const state = deps.getState();
    // 템플릿 엔진이 초기화되지 않은 경우 키 자체 반환
    if (!state.translationEngine || !state.translationContext) {
      return key;
    }

    if (params) {
      // 파라미터를 TranslationEngine 형식으로 변환 (|key=value|key2=value2)
      const paramsStr = '|' + Object.entries(params).map(([k, v]) => `${k}=${v}`).join('|');
      return state.translationEngine.translate(key, state.translationContext, paramsStr);
    }
    return state.translationEngine.translate(key, state.translationContext);
  };

  logger.log('전역 객체 window.G7Core.useTranslation에 노출됨');
}

/**
 * 이벤트 헬퍼 및 렌더링 헬퍼 초기화
 */
function initHelperAPIs(G7Core: any, deps: G7CoreDependencies): void {
  // 이벤트 헬퍼 노출 (템플릿 컴포넌트에서 ActionDispatcher 호환 이벤트 생성용)
  G7Core.createChangeEvent = createChangeEvent;
  G7Core.createClickEvent = createClickEvent;
  G7Core.createSubmitEvent = createSubmitEvent;
  G7Core.createKeyboardEvent = createKeyboardEvent;

  // UUID 생성 함수 노출 (임시 키 등 고유 식별자 생성용)
  // 사용법: G7Core.uuid() → "550e8400-e29b-41d4-a716-446655440000"
  // 레이아웃 표현식에서: {{$uuid()}}
  G7Core.uuid = (): string => {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
      return crypto.randomUUID();
    }
    // fallback: crypto.randomUUID 미지원 환경
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
      const r = (Math.random() * 16) | 0;
      const v = c === 'x' ? r : (r & 0x3) | 0x8;
      return v.toString(16);
    });
  };

  // Logger 팩토리 노출 (모듈/템플릿에서 일관된 로깅을 위해)
  G7Core.createLogger = createLogger;
  logger.log('전역 객체 window.G7Core.createLogger에 노출됨');

  // renderItemChildren 노출 (반복 아이템의 자식 컴포넌트 렌더링용)
  // CardGrid 등에서 호출 시 translationContext, translationEngine, bindingEngine, actionDispatcher를 자동 주입
  // 전역 상태(_global, _local, _computed)도 컨텍스트에 자동 병합하여 cellChildren에서 접근 가능
  G7Core.renderItemChildren = (
    children: any[],
    itemContext: Record<string, any>,
    componentMap: Record<string, any>,
    keyPrefix?: string,
    options?: any
  ) => {
    const state = deps.getState();

    // 전역 상태 가져오기
    // TemplateApp.getGlobalState()는 globalState를 직접 반환 (예: { _remountKeys, sidebarOpen, ... })
    // 이 값이 dataContext._global의 내용과 동일함
    const templateApp = (window as any).__templateApp;
    const globalStateContent = templateApp?.getGlobalState?.() || {};

    // 전역 상태를 컨텍스트에 자동 병합 (cellChildren에서 _global 접근 가능)
    // itemContext에 이미 해당 키가 있으면 itemContext 값 우선 (명시적 전달 존중)
    // _local, _computed는 itemContext에서 전달받거나 비어있음
    const mergedContext = {
      _global: globalStateContent,  // globalState 내용이 곧 _global
      _local: itemContext._local || {},
      _computed: itemContext._computed || {},
      ...itemContext,
    };

    // remount 지원: _global._remountKeys를 확인하여 컴포넌트 리마운트를 지원하는 키 생성 함수
    // 적용된 remountId를 추적하여 중복 적용 방지
    const appliedRemountIds = new Set<string>();
    const getRemountKey = (componentId: string | undefined, fallbackKey: string): string => {
      const remountKeys = mergedContext._global?._remountKeys;
      if (componentId && remountKeys?.[componentId] && !appliedRemountIds.has(componentId)) {
        appliedRemountIds.add(componentId);
        return `${componentId}-remount-${remountKeys[componentId]}`;
      }
      return componentId || fallbackKey;
    };

    // options가 없거나 필요한 엔진이 없으면 현재 state에서 자동 주입
    const mergedOptions = {
      translationContext: state.translationContext,
      translationEngine: state.translationEngine,
      bindingEngine: state.bindingEngine,
      actionDispatcher: state.actionDispatcher,
      getRemountKey, // remount 지원 추가
      ...options,
    };
    return renderItemChildren(children, mergedContext, componentMap, keyPrefix, mergedOptions);
  };

  // getComponentMap 노출 (CardGrid 등에서 전체 컴포넌트 맵 접근용)
  G7Core.getComponentMap = () => {
    return ComponentRegistry.getInstance().getComponentMap();
  };

  /**
   * component_layout을 렌더링하는 헬퍼 함수
   *
   * 컴포넌트에서 component_layout 정의를 받아서 React 요소로 렌더링합니다.
   * 각 항목의 컨텍스트(item, index 등)를 주입하여 데이터 바인딩을 처리합니다.
   *
   * @param layoutDefs component_layout 정의 배열
   * @param itemContext 항목별 컨텍스트 (예: { item: option, index: 0, isSelected: true })
   * @param keyPrefix React key prefix
   * @returns React 요소 배열
   *
   * @example
   * ```tsx
   * // RichSelect 컴포넌트에서 사용
   * const itemElements = G7Core.renderComponentLayout(
   *   __componentLayoutDefs?.item,
   *   { item: option, index, isSelected },
   *   `item-${index}`
   * );
   * ```
   */
  G7Core.renderComponentLayout = (
    layoutDefs: any[] | undefined,
    itemContext: Record<string, any>,
    keyPrefix?: string
  ): React.ReactNode => {
    if (!layoutDefs || !Array.isArray(layoutDefs) || layoutDefs.length === 0) {
      return null;
    }

    const componentMap = ComponentRegistry.getInstance().getComponentMap();
    const state = deps.getState();

    // 전역 상태 가져오기 (remount 지원을 위해)
    const templateApp = (window as any).__templateApp;
    const globalState = templateApp?.getGlobalState?.() || {};

    // 전역 상태를 컨텍스트에 병합 (remount 키 접근을 위해)
    const mergedContext = {
      _global: globalState._global || {},
      _local: globalState._local || {},
      _computed: globalState._computed || {},
      ...itemContext,
    };

    // remount 지원
    const appliedRemountIds = new Set<string>();
    const getRemountKey = (componentId: string | undefined, fallbackKey: string): string => {
      const remountKeys = mergedContext._global?._remountKeys;
      if (componentId && remountKeys?.[componentId] && !appliedRemountIds.has(componentId)) {
        appliedRemountIds.add(componentId);
        return `${componentId}-remount-${remountKeys[componentId]}`;
      }
      return componentId || fallbackKey;
    };

    return renderItemChildren(
      layoutDefs,
      mergedContext,
      componentMap,
      keyPrefix,
      {
        translationContext: state.translationContext,
        translationEngine: state.translationEngine,
        bindingEngine: state.bindingEngine,
        actionDispatcher: state.actionDispatcher,
        getRemountKey,
      }
    );
  };

  /**
   * 확장 행(expandChildren) 컨텐츠를 렌더링하는 헬퍼 함수 (Phase 1-2)
   *
   * DataGrid, CardGrid 등에서 확장 행 렌더링 시 필요한 복잡한 로직을
   * 캡슐화하여 60줄+ 코드를 10줄 이하로 줄입니다.
   *
   * @param config.children 렌더링할 컴포넌트 정의 배열 (expandChildren)
   * @param config.row 현재 행 데이터
   * @param config.expandContext 확장 컨텍스트 (바인딩 표현식 자동 평가)
   * @param config.componentContext 부모 컴포넌트 컨텍스트 (state, setState)
   * @param config.componentMap 컴포넌트 맵 (선택, 없으면 자동 조회)
   * @param config.keyPrefix React key prefix
   * @returns React 요소 배열
   *
   * @example
   * ```tsx
   * // DataGrid에서 확장 행 렌더링 (Before: 60줄+ → After: 10줄)
   * const renderExpandedContent = useCallback((row: any) => {
   *   if (expandedRowRender) return expandedRowRender(row);
   *   if (expandChildren && expandChildren.length > 0) {
   *     return G7Core.renderExpandContent({
   *       children: expandChildren,
   *       row,
   *       expandContext,
   *       componentContext: __componentContext,
   *       keyPrefix: `expand-${row[idField]}`,
   *     });
   *   }
   *   return null;
   * }, [expandChildren, expandContext, __componentContext, expandedRowRender, idField]);
   * ```
   */
  G7Core.renderExpandContent = (config: {
    children: any[];
    row: any;
    expandContext?: Record<string, any>;
    componentContext?: {
      state?: Record<string, any>;
      setState?: (updates: Record<string, any>) => void;
      stateRef?: { current: Record<string, any> };
      computedRef?: { current: Record<string, any> };
    };
    componentMap?: Record<string, React.ComponentType<any>>;
    keyPrefix: string;
  }): React.ReactNode => {
    const { children, row, expandContext, componentContext, keyPrefix } = config;

    if (!children || !Array.isArray(children) || children.length === 0) {
      return null;
    }

    // 컴포넌트 맵 조회 (없으면 자동)
    const componentMap = config.componentMap || ComponentRegistry.getInstance().getComponentMap();
    const state = deps.getState();

    // 1. 전역 상태 가져오기
    const globalState = G7Core.state?.get?.() || {};

    // 2. componentContext에서 최신 _local 상태 가져오기
    // stateRef.current를 우선 사용하여 캐싱된 componentContext에서도 최신 상태 접근
    // stateRef가 없으면 기존 state 사용 (하위 호환성)
    const latestLocalState = componentContext?.stateRef?.current ?? componentContext?.state ?? {};

    const mergedLocal = {
      ...(globalState._local || {}),
      ...latestLocalState,
    };

    // 3. componentContext에서 최신 _computed 상태 가져오기
    // computedRef.current를 우선 사용하여 캐싱된 componentContext에서도 최신 _computed 접근
    // _computed는 _local 기반으로 계산되므로 _local 변경 시 함께 업데이트됨
    const latestComputed = componentContext?.computedRef?.current ?? globalState._computed ?? {};

    // 4. expandContext 바인딩 표현식 자동 평가
    const bindingEngine = state.bindingEngine ?? G7Core.getDataBindingEngine?.();
    const evalContext = {
      ...globalState,
      _local: mergedLocal,
      _computed: latestComputed,
      row,
      item: row,
      $item: row,
    };

    let resolvedExpandContext: Record<string, any> = {};
    if (expandContext && typeof expandContext === 'object' && bindingEngine) {
      try {
        // expandContext의 각 값을 수동으로 바인딩 평가
        // resolveObject는 DEFAULT_SKIP_BINDING_KEYS 때문에 expandContext 내부 키를 스킵할 수 있음
        for (const [key, value] of Object.entries(expandContext)) {
          if (typeof value === 'string') {
            // 단일 Mustache 표현식인지 확인 ({{expr}} 형태)
            const singleBindingMatch = value.match(/^\{\{([^}]+)\}\}$/);
            if (singleBindingMatch) {
              // 단일 바인딩 표현식: evaluateExpression 사용하여 원본 타입 유지
              // resolveBindings는 문자열 보간용이라 배열/객체를 JSON 문자열로 변환함
              const expr = singleBindingMatch[1].trim();
              try {
                resolvedExpandContext[key] = bindingEngine.evaluateExpression(expr, evalContext, { skipCache: true });
              } catch (e) {
                // 평가 실패 시 undefined (폴백 처리는 표현식 자체에서 || 로 처리)
                resolvedExpandContext[key] = undefined;
              }
            } else {
              // 혼합 표현식 (예: "Hello {{name}}"): resolveBindings 사용
              resolvedExpandContext[key] = bindingEngine.resolveBindings(value, evalContext, { skipCache: true });
            }
          } else {
            resolvedExpandContext[key] = value;
          }
        }
      } catch (error) {
        logger.warn('renderExpandContent: expandContext 평가 오류:', error);
      }
    }

    // 4. 최종 컨텍스트 구성
    const context = {
      row,
      item: row,
      $item: row,
      _local: mergedLocal,
      _global: globalState._global || {},
      _computed: globalState._computed || {},
      ...resolvedExpandContext,
    };

    // 5. remount 지원
    const appliedRemountIds = new Set<string>();
    const getRemountKey = (componentId: string | undefined, fallbackKey: string): string => {
      const remountKeys = context._global?._remountKeys;
      if (componentId && remountKeys?.[componentId] && !appliedRemountIds.has(componentId)) {
        appliedRemountIds.add(componentId);
        return `${componentId}-remount-${remountKeys[componentId]}`;
      }
      return componentId || fallbackKey;
    };

    // 6. 렌더링
    return renderItemChildren(children, context, componentMap, keyPrefix, {
      translationContext: state.translationContext,
      translationEngine: state.translationEngine ?? undefined,
      bindingEngine: state.bindingEngine ?? undefined,
      actionDispatcher: state.actionDispatcher,
      componentContext,
      getRemountKey,
    });
  };

  /**
   * 깊은 객체 경로 접근 헬퍼 함수
   *
   * 동적 키를 포함한 깊은 객체 경로 접근과 폴백 값을 지원합니다.
   * 다중 통화, 다국어, 중첩 설정 등 다양한 상황에서 활용할 수 있습니다.
   *
   * @param obj 접근할 객체
   * @param path 경로 (문자열 또는 문자열 배열)
   * @param fallback 경로가 존재하지 않을 때 반환할 기본값
   * @returns 경로에 해당하는 값 또는 폴백 값
   *
   * @example
   * ```ts
   * // 다중 통화 가격 접근
   * $get(product.prices, [currency, 'formatted'], product.price_formatted)
   *
   * // 다국어 객체 접근
   * $get(product.name, locale, product.name.ko)
   *
   * // 중첩 설정 접근
   * $get(settings, ['drivers', 'mail', 'host'], 'localhost')
   *
   * // 동적 키 접근
   * $get(prices, [selectedPlan, 'monthly'], 0)
   * ```
   */
  G7Core.$get = function $get(obj: any, path: string | string[], fallback: any = undefined): any {
    // null/undefined 객체는 즉시 폴백 반환
    if (obj === null || obj === undefined) {
      return fallback;
    }

    // 경로를 배열로 정규화
    const keys = Array.isArray(path) ? path : [path];

    // 빈 경로는 객체 자체 반환
    if (keys.length === 0) {
      return obj;
    }

    let current = obj;

    for (const key of keys) {
      // 현재 위치가 null/undefined이면 폴백 반환
      if (current === null || current === undefined) {
        return fallback;
      }

      // 키가 null/undefined이면 폴백 반환
      if (key === null || key === undefined) {
        return fallback;
      }

      // 다음 레벨로 이동
      current = current[key];
    }

    // 최종 값이 null/undefined이면 폴백 반환
    return current ?? fallback;
  };

  logger.log('전역 객체 window.G7Core.renderItemChildren에 노출됨');
  logger.log('전역 객체 window.G7Core.renderComponentLayout에 노출됨');
  logger.log('전역 객체 window.G7Core.renderExpandContent에 노출됨');
  logger.log('전역 객체 window.G7Core.$get에 노출됨');
}

/**
 * 액션 디스패치 API 초기화
 */
function initDispatchAPI(G7Core: any): void {
  /**
   * 액션을 디스패치합니다.
   *
   * @param action 실행할 액션 정의
   * @param options 디스패치 옵션
   * @param options.componentContext 컴포넌트 컨텍스트 (openModal 등에서 부모 컨텍스트 지정용)
   *   - state: 컴포넌트의 _local 상태
   *   - setState: 컴포넌트의 상태 업데이트 함수
   *   - 지정 시 __g7ActionContext보다 우선 사용됨
   *
   * @example
   * // 기본 사용 (레이아웃 액션에서 자동으로 컨텍스트 설정됨)
   * G7Core.dispatch({ handler: 'openModal', target: 'myModal' });
   *
   * // 컴포넌트에서 직접 호출 시 컨텍스트 명시적 전달
   * G7Core.dispatch(
   *   { handler: 'openModal', target: 'myModal' },
   *   { componentContext: props.__componentContext }
   * );
   */
  G7Core.dispatch = async (action: any, options?: { componentContext?: any }) => {
    const templateApp = (window as any).__templateApp;
    if (!templateApp) {
      logger.warn('G7Core.dispatch: TemplateApp이 초기화되지 않았습니다.');
      return { success: false, error: new Error('TemplateApp이 초기화되지 않았습니다.') };
    }

    const actionDispatcher = templateApp.getActionDispatcher?.();
    if (!actionDispatcher) {
      logger.warn('G7Core.dispatch: ActionDispatcher를 찾을 수 없습니다.');
      return { success: false, error: new Error('ActionDispatcher를 찾을 수 없습니다.') };
    }

    const router = templateApp.getRouter?.();
    const globalState = templateApp.getGlobalState?.();
    const setGlobalState = templateApp.setGlobalState?.bind(templateApp);

    // engine-v1.17.3: 컴포넌트에서 직접 dispatch 호출 시 componentContext 전달 지원
    // openModal 등에서 부모 컨텍스트를 정확히 지정하기 위해 사용
    // 우선순위: options.componentContext > __g7ActionContext > global fallback
    const passedComponentContext = options?.componentContext;

    // 현재 실행 중인 액션의 컨텍스트가 있으면 이를 우선 사용
    // 핸들러 내부에서 G7Core.modal.open() 등을 호출할 때 컴포넌트 컨텍스트 유지
    // engine-v1.16.0: $parent 바인딩 컨텍스트 지원을 위해 필수
    const actionContext = passedComponentContext || (window as any).__g7ActionContext;

    // engine-v1.17.0: setLocal 후 즉시 dispatch 호출 시 최신 로컬 상태 참조 지원
    // React setState는 비동기이므로 actionContext.state에 반영되기 전에 dispatch가 호출될 수 있음
    // __g7PendingLocalState가 있으면 이를 context.state로 사용하여 최신 상태 보장
    const pendingLocalState = (window as any).__g7PendingLocalState;
    let contextState = actionContext?.state ?? globalState;

    // pending 상태가 있으면 context.state에 병합
    // 커스텀 핸들러에서 setLocal 후 dispatch 호출 시 {{_local.xxx}} 표현식에서 최신 값 참조
    if (pendingLocalState && actionContext) {
      contextState = pendingLocalState;
      logger.log('[dispatch] Using __g7PendingLocalState for context.state:', pendingLocalState);
    }

    // v1.19.1: pendingLocalState가 있으면 context.data._local도 갱신
    // refetchDataSource 핸들러에서 context.data._local을 contextLocalState로 우선 참조하므로 (v1.19.0)
    // 커스텀 핸들러에서 setLocal → dispatch 호출 시 context.data._local이 렌더 시점의 stale 상태이면
    // merge 순서에 의해 pendingLocalState(NEW)를 contextLocalState(OLD)가 덮어쓰는 문제 발생
    // sequence 핸들러는 G7Core.dispatch를 경유하지 않으므로 이 코드 경로에 영향 없음
    let contextData = actionContext?.data ?? globalState;
    if (pendingLocalState && actionContext?.data?._local) {
      contextData = {
        ...actionContext.data,
        _local: pendingLocalState,
      };
      logger.log('[dispatch] Updated context.data._local with pendingLocalState');
    }

    const context = {
      navigate: router
        ? (path: string, options?: { replace?: boolean; state?: any }) =>
            router.navigate(path, options)
        : undefined,
      // 액션 컨텍스트가 있으면 컴포넌트의 setState/state 사용, 없으면 전역 사용
      setState: actionContext?.setState ?? setGlobalState,
      state: contextState,
      data: contextData,
      // engine-v1.17.2: 비동기 콜백에서 dispatch 호출 시 target=local setState 처리 지원
      // 컴포넌트 컨텍스트 없이 dispatch 호출된 경우 마커 설정
      // handleSetState에서 이 마커를 확인하여 globalStateUpdater({ _local: ... }) 경로 사용
      _isDispatchFallbackContext: !actionContext?.setState,
    };

    // engine-v1.41.0: debounce 옵션 처리
    // ActionDispatcher의 debouncedCall을 사용하여 기존 타이머 인프라 활용
    if (action.debounce) {
      const debounceKey = action.debounceKey || `dispatch-${action.handler}`;
      const delay = typeof action.debounce === 'number' ? action.debounce : action.debounce.delay;
      actionDispatcher.debouncedCall(debounceKey, delay, () => {
        // 디바운스 타이머 fire 시 최신 컨텍스트로 액션 실행
        const { debounce: _d, debounceKey: _k, ...actionWithoutDebounce } = action;
        actionDispatcher.dispatchAction(actionWithoutDebounce, context);
      });
      return { success: true, debounced: true };
    }

    try {
      return await actionDispatcher.dispatchAction(action, context);
    } catch (error) {
      logger.error('G7Core.dispatch: 액션 실행 오류:', error);
      return {
        success: false,
        error: error instanceof Error ? error : new Error(String(error)),
      };
    }
  };

  logger.log('전역 객체 window.G7Core.dispatch에 노출됨');
}

/**
 * dot notation 경로를 중첩 객체로 변환합니다.
 *
 * @example
 * convertDotNotationToObject({ "filter.orderStatus": ["paid"] })
 * // Returns: { filter: { orderStatus: ["paid"] } }
 *
 * @param updates 변환할 객체
 * @returns 중첩 객체로 변환된 결과
 */
function convertDotNotationToObject(updates: Record<string, any>): Record<string, any> {
  const result: Record<string, any> = {};

  for (const [key, value] of Object.entries(updates)) {
    if (key.includes('.')) {
      // dot notation 경로를 배열로 분리
      const keys = key.split('.');
      let current = result;

      // 중첩 객체 생성
      for (let i = 0; i < keys.length - 1; i++) {
        const k = keys[i];
        if (!(k in current)) {
          current[k] = {};
        } else if (typeof current[k] !== 'object' || current[k] === null) {
          // 기존 값이 객체가 아니면 객체로 교체
          current[k] = {};
        }
        current = current[k];
      }

      // 마지막 키에 값 할당
      current[keys[keys.length - 1]] = value;
    } else {
      // dot notation이 아니면 그대로 복사
      result[key] = value;
    }
  }

  return result;
}

/**
 * 중첩 객체에서 리프(leaf) 경로 전부를 dot notation 문자열 배열로 추출합니다.
 *
 * engine-v1.43.0+ 자동바인딩 경로 자동 승격 감지에 사용.
 * `__g7AutoBindingPaths` 레지스트리에 fullPath 형식(`form.title`)으로 키가 등록되므로,
 * setLocal의 `converted` 중첩 객체에서도 동일한 형식의 리프 경로를 추출해 교집합을 검사한다.
 *
 * 배열은 리프로 취급 (자동바인딩은 배열 자체 값에 매핑되므로).
 *
 * @example
 * flattenLeafPaths({ form: { title: "X", content: "Y" } })
 * // Returns: ["form.title", "form.content"]
 *
 * flattenLeafPaths({ hasChanges: true, form: { tags: ["a", "b"] } })
 * // Returns: ["hasChanges", "form.tags"]
 *
 * @param obj 중첩 객체
 * @param prefix 재귀용 prefix (내부)
 * @returns 리프 경로 문자열 배열
 */
function flattenLeafPaths(obj: Record<string, any>, prefix = ''): string[] {
  const paths: string[] = [];
  if (!obj || typeof obj !== 'object') return paths;
  for (const key of Object.keys(obj)) {
    const fullKey = prefix ? `${prefix}.${key}` : key;
    const value = obj[key];
    if (value && typeof value === 'object' && !Array.isArray(value)) {
      paths.push(...flattenLeafPaths(value, fullKey));
    } else {
      paths.push(fullKey);
    }
  }
  return paths;
}

/**
 * 객체의 모든 키가 숫자(배열 인덱스)인지 확인합니다.
 *
 * @param obj 확인할 객체
 * @returns 모든 키가 숫자이면 true
 */
function hasOnlyNumericKeys(obj: Record<string, any>): boolean {
  const keys = Object.keys(obj);
  return keys.length > 0 && keys.every((k) => /^\d+$/.test(k));
}

/**
 * 두 객체를 깊은 병합(deep merge)합니다.
 *
 * 특수 케이스: target이 배열이고 source가 숫자 키만 가진 객체인 경우,
 * source의 각 키를 배열 인덱스로 해석하여 해당 위치의 요소를 병합합니다.
 * 예: target = [{a:1}, {b:2}], source = {"0": {c:3}} → [{a:1, c:3}, {b:2}]
 *
 * @param target 병합 대상 객체
 * @param source 병합할 소스 객체
 * @returns 병합된 결과 객체
 */
/**
 * base 객체에 없는 leaf 키만 extra에서 추가합니다.
 *
 * deepMerge와 달리 base에 이미 존재하는 값(배열 포함)은 절대 덮어쓰지 않습니다.
 * extra에만 존재하는 키는 재귀적으로 추가됩니다.
 *
 * 용도: setLocal에서 dynamicLocal(actionContext.state)의 setState 전용 키를 globalLocal에
 * 안전하게 추가할 때 사용. dynamicLocal의 stale 배열(init_actions 기본값)이 globalLocal의
 * 정상 API 데이터를 덮어쓰는 것을 방지합니다.
 *
 * @since engine-v1.41.0
 *
 * @example
 * ```ts
 * const base = { form: { category_ids: [381, 384], name: 'A' } };
 * const extra = { form: { category_ids: [], options: [] }, selectedProducts: [1] };
 * addMissingLeafKeys(base, extra);
 * // → { form: { category_ids: [381, 384], name: 'A', options: [] }, selectedProducts: [1] }
 * // base의 category_ids는 보존, extra의 selectedProducts와 options는 추가
 * ```
 */
function addMissingLeafKeys(base: Record<string, any>, extra: Record<string, any>): Record<string, any> {
  const result = { ...base };
  for (const key of Object.keys(extra)) {
    if (!(key in result)) {
      // base에 없는 키: extra 값 그대로 추가
      result[key] = extra[key];
    } else if (
      result[key] !== null &&
      typeof result[key] === 'object' &&
      !Array.isArray(result[key]) &&
      extra[key] !== null &&
      typeof extra[key] === 'object' &&
      !Array.isArray(extra[key])
    ) {
      // 양쪽 모두 plain object: 재귀적으로 처리
      result[key] = addMissingLeafKeys(result[key], extra[key]);
    }
    // base에 이미 존재하는 leaf 값(배열, 문자열, 숫자 등): 건너뜀 (base 값 보존)
  }
  return result;
}

function deepMerge(target: Record<string, any>, source: Record<string, any>): Record<string, any> {
  // 특수 케이스: target이 배열이고 source가 숫자 키만 가진 객체인 경우
  // → 배열 요소를 인덱스별로 병합
  if (Array.isArray(target) && !Array.isArray(source) && hasOnlyNumericKeys(source)) {
    const numericKeys = Object.keys(source).map((k) => parseInt(k, 10));
    const maxKey = numericKeys.length > 0 ? Math.max(...numericKeys) : 0;

    // Sparse array 방지: source의 최대 키가 target 배열 범위를 크게 초과하면
    // 배열 인덱스가 아닌 ID/키 매핑(예: product_option_id → coupon)으로 간주
    // → 배열 병합 대신 일반 객체 병합으로 처리 (sparse array 생성 방지)
    if (maxKey >= target.length + numericKeys.length + 10) {
      // Fall through to normal object merge below
    } else {
      const result = [...target];
      for (const [key, value] of Object.entries(source)) {
        const index = parseInt(key, 10);
        if (index >= 0 && index < result.length) {
          // 인덱스가 범위 내에 있으면 해당 요소와 병합
          if (
            value !== null &&
            typeof value === 'object' &&
            !Array.isArray(value) &&
            result[index] !== null &&
            typeof result[index] === 'object'
          ) {
            result[index] = deepMerge(result[index], value);
          } else {
            result[index] = value;
          }
        } else if (index >= result.length) {
          // 인덱스가 범위를 초과하면 배열 확장
          result[index] = value;
        }
      }
      return result as any;
    }
  }

  const result = { ...target };

  for (const [key, value] of Object.entries(source)) {
    if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
      // 중첩 객체인 경우 재귀적으로 병합
      if (result[key] !== null && typeof result[key] === 'object') {
        // target[key]가 배열이든 객체든 deepMerge가 처리
        result[key] = deepMerge(result[key], value);
      } else {
        result[key] = { ...value };
      }
    } else {
      // 원시 값 또는 배열인 경우 그대로 할당
      result[key] = value;
    }
  }

  return result;
}

/**
 * 전역 상태 관리 API 초기화
 */
function initStateAPI(G7Core: any): void {
  G7Core.state = {
    /**
     * 현재 전역 상태를 반환합니다.
     */
    get: () => {
      const templateApp = (window as any).__templateApp;
      return templateApp?.getGlobalState?.() || {};
    },
    /**
     * 전역 상태를 업데이트합니다.
     *
     * dot notation을 지원합니다.
     * 예: `{ "filter.orderStatus": ["paid"] }` → `{ filter: { orderStatus: ["paid"] } }`
     *
     * @param updates 업데이트할 상태 객체
     * @param options 병합 옵션 { merge?: 'replace' | 'shallow' | 'deep' }
     */
    set: (updates: Record<string, any>, options?: { merge?: 'replace' | 'shallow' | 'deep'; render?: boolean }) => {
      const templateApp = (window as any).__templateApp;
      if (templateApp?.setGlobalState && templateApp?.getGlobalState) {
        const mergeMode = options?.merge || 'deep';
        // dot notation을 중첩 객체로 변환
        const converted = convertDotNotationToObject(updates);

        let finalState: Record<string, any>;
        if (mergeMode === 'replace') {
          finalState = converted;
        } else if (mergeMode === 'shallow') {
          const currentState = templateApp.getGlobalState();
          finalState = { ...currentState, ...converted };
        } else {
          // deep (기본값)
          const currentState = templateApp.getGlobalState();
          finalState = deepMerge(currentState, converted);
        }

        // engine-v1.42.0: render 옵션 전파
        templateApp.setGlobalState(finalState, { render: options?.render });
      } else {
        logger.warn('G7Core.state.set: TemplateApp이 초기화되지 않았습니다.');
      }
    },
    /**
     * 컴포넌트 로컬 상태(_local)를 업데이트합니다.
     *
     * 커스텀 핸들러에서 컴포넌트의 로컬 상태를 직접 업데이트할 때 사용합니다.
     * 이 메서드는 ActionDispatcher의 액션 실행 중에만 유효합니다.
     *
     * - 액션 컨텍스트가 있는 경우: 컴포넌트의 dynamicState를 직접 업데이트하여 즉시 UI에 반영
     * - 액션 컨텍스트가 없는 경우: 전역 _local을 업데이트 (fallback)
     *
     * dot notation을 지원합니다.
     * 예: `{ "filter.orderStatus": ["paid"] }` → `{ filter: { orderStatus: ["paid"] } }`
     *
     * @param updates 업데이트할 로컬 상태 객체
     *
     * @example
     * ```ts
     * // 커스텀 핸들러에서 사용
     * export function myHandler(action: any, context: any): void {
     *   const G7Core = (window as any).G7Core;
     *
     *   // 현재 로컬 상태 가져오기
     *   const currentLocal = G7Core.state.getLocal();
     *
     *   // 로컬 상태 업데이트 (현재 컴포넌트의 상태)
     *   G7Core.state.setLocal({
     *     selectedItems: [...currentLocal.selectedItems, newItem],
     *     isLoading: false,
     *   });
     *
     *   // dot notation 지원
     *   G7Core.state.setLocal({
     *     "filter.orderStatus": ["paid", "shipped"],
     *   });
     *
     *   // scope 옵션으로 부모/루트 레이아웃에 상태 저장 (모달 등에서 유용)
     *   G7Core.state.setLocal({
     *     "form.fields": updatedFields,
     *   }, { scope: 'parent' });
     * }
     * ```
     */
    setLocal: (updates: Record<string, any>, options?: { scope?: 'current' | 'parent' | 'root'; merge?: 'replace' | 'shallow' | 'deep'; debounce?: number; debounceKey?: string; render?: boolean; selfManaged?: boolean }) => {
      const templateApp = (window as any).__templateApp;

      // [engine-v1.43.0+] 이중 저장소 동기화 보호 — 자동바인딩 경로 자동 승격
      //
      // 배경: 엔진은 폼 데이터를 React localDynamicState(저장소 A)와 globalState._local(저장소 B)에
      // 이중 저장한다. render:false 호출이 저장소 A에 자동바인딩된 경로를 건드리면 A가 갱신되지 않아
      // Input이 stale 값을 표시한다. DynamicRenderer의 propsWithAutoBinding 주변 useEffect가 활성
      // 자동바인딩 경로를 __g7AutoBindingPaths: Map<string, number>에 ref count로 등록한다.
      //
      // 규칙: setLocal({render:false})가 레지스트리에 등록된 경로를 업데이트하면 render:true로
      // 강제 승격하여 A↔B 정합성 보장. 예외는 selfManaged:true를 명시한 호출 — CKEditor5 등
      // 자체 DOM 관리 플러그인이 의도적으로 render:false를 유지하려 할 때 사용. 누락 시 엔진이
      // 자동 승격하여 안전 확보 (safe-by-default).
      //
      // 자세한 설계 배경: DynamicRenderer.tsx의 performStateUpdate 상단 주석 참조.
      if (options?.render === false && !options?.selfManaged) {
        const registry = (window as any).__g7AutoBindingPaths as Map<string, number> | undefined;
        if (registry && registry.size > 0) {
          const leafPaths = flattenLeafPaths(convertDotNotationToObject(updates));
          if (leafPaths.some((path) => registry.has(path))) {
            logger.log(
              '[setLocal] render:false + 자동바인딩 경로 겹침 감지 → render:true 자동 승격 (engine-v1.43.0)'
            );
            options = { ...options, render: true };
          }
        }
      }

      // engine-v1.41.0: debounce 옵션 처리
      // ActionDispatcher의 debouncedCall을 사용하여 기존 타이머 인프라 활용
      // 컴포넌트 언마운트 시 자동 정리 + flushPendingDebounceTimers 연동
      if (options?.debounce) {
        const actionDispatcher = templateApp?.getActionDispatcher?.();
        if (actionDispatcher) {
          const key = options.debounceKey || `setLocal-${Object.keys(updates).join(',')}`;
          // 디바운스 타이머 fire 시 원래 setLocal 로직을 debounce 없이 재호출
          const { debounce: _d, debounceKey: _k, ...restOptions } = options;
          actionDispatcher.debouncedCall(key, options.debounce, () => {
            G7Core.state.setLocal(updates, restOptions);
          });
          return;
        }
      }

      const scope = options?.scope ?? 'current';
      const mergeMode = options?.merge || 'deep';

      // ActionDispatcher에서 설정한 현재 컴포넌트 컨텍스트 확인
      const actionContext = (window as any).__g7ActionContext;

      // dot notation을 중첩 객체로 변환
      const converted = convertDotNotationToObject(updates);

      // scope가 'parent' 또는 'root'인 경우 레이아웃 컨텍스트 스택에서 타겟 컨텍스트 사용
      if (scope === 'parent' || scope === 'root') {
        const contextStack: Array<{ state: Record<string, any>; setState: (updates: any) => void }> =
          (window as any).__g7LayoutContextStack || [];

        if (contextStack.length > 0) {
          // parent: 스택의 마지막 (바로 이전 컨텍스트)
          // root: 스택의 첫 번째 (최상위 컨텍스트)
          const targetContext = scope === 'parent'
            ? contextStack[contextStack.length - 1]
            : contextStack[0];

          if (targetContext?.setState) {
            // 함수형 업데이트를 사용하여 항상 최신 상태와 병합
            // React setState는 비동기이므로 스냅샷 대신 함수형 업데이트 필수
            targetContext.setState((currentLocal: Record<string, any>) => {
              if (mergeMode === 'replace') return converted;
              if (mergeMode === 'shallow') return { ...(currentLocal || {}), ...converted };
              return deepMerge(currentLocal || {}, converted);
            });
            logger.log(`[setLocal] scope=${scope}: 타겟 컨텍스트에 상태 업데이트 (mergeMode=${mergeMode})`, converted);
            return;
          }
        }

        // 스택에 컨텍스트가 없으면 경고 후 current로 폴백
        logger.warn(`[setLocal] scope=${scope}: 레이아웃 컨텍스트 스택이 비어있습니다. current로 폴백합니다.`);
      }

      // scope: 'current' (기본값)
      // engine-v1.17.7: actionContext 유무와 관계없이 항상 globalLocal을 업데이트
      // - actionContext.setState()는 클릭된 컴포넌트(ChipCheckbox)의 상태만 업데이트
      // - ChipCheckbox의 상태에는 form.label_assignments가 없으므로 잘못된 대상
      // - templateApp.setGlobalState({ _local: ... })가 실제 _local 상태를 관리
      // - _localInit에서도 동일한 메커니즘 사용 (setGlobalState({ _local: ... }))
      const pendingState = (window as any).__g7PendingLocalState;
      const globalLocal = templateApp?.getGlobalState?.()?._local || {};
      // engine-v1.22.1: actionContext.state(=dynamicState)도 병합하여 전체 _local 반영
      // ActionDispatcher setState(target: "_local")로 설정된 값은 localDynamicState에만 존재하고
      // globalLocal(=templateApp.getGlobalState()._local)에는 없음.
      // setLocal 후 openModal 시 __g7PendingLocalState가 $parent._local 스냅샷으로 사용되므로,
      // dynamicState의 값(예: selectedProducts)이 누락되는 문제 발생.
      //
      // engine-v1.24.7: 모달 내부에서 호출 시 actionContext.state 병합 제외
      //
      // engine-v1.41.0: deepMerge(globalLocal, dynamicLocal)은 stale dynamicLocal의 빈 배열이
      // globalLocal의 정상 배열을 교체하는 오염 경로를 생성함 (CKEditor + SPA 네비게이션 시 재현).
      // dynamicLocal은 actionContext.state(=컴포넌트 렌더 시점 스냅샷)이므로
      // init_actions 기본값(category_ids:[], options:[])을 포함할 수 있음.
      // globalLocal은 최신 committed 상태이므로 dynamicLocal과 충돌 시 globalLocal이 정확함.
      // → globalLocal을 기반으로 dynamicLocal에서 globalLocal에 없는 키만 추가하는
      //   addMissingLeafKeys 전략으로 변경. v1.22.1 목적(setState 전용 키 보존)은 유지하면서
      //   stale 배열 덮어쓰기 방지.
      const layoutContextStack = (window as any).__g7LayoutContextStack || [];
      const isInsideModal = layoutContextStack.length > 0;
      const dynamicLocal = (!isInsideModal && actionContext?.state) ? actionContext.state : undefined;
      const baseLocal = dynamicLocal
        ? addMissingLeafKeys(globalLocal, dynamicLocal)
        : globalLocal;
      const currentSnapshot = pendingState || baseLocal;
      let mergedPending: Record<string, any>;
      if (mergeMode === 'replace') {
        mergedPending = converted;
      } else if (mergeMode === 'shallow') {
        mergedPending = { ...currentSnapshot, ...converted };
      } else {
        mergedPending = deepMerge(currentSnapshot, converted);
      }

      // 1. 전역 _local 상태 업데이트 (핵심!)
      // engine-v1.42.0: render 옵션 전파 — render: false이면 값만 저장, React 렌더링 건너뜀
      if (templateApp?.setGlobalState) {
        templateApp.setGlobalState({ _local: mergedPending }, { render: options?.render });
        logger.log(`[setLocal] globalLocal updated via setGlobalState (mergeMode=${mergeMode}, render=${options?.render ?? true}):`, converted);
      }

      // 2. pendingState도 업데이트 (같은 이벤트 루프 내 후속 getLocal() 호출용)
      (window as any).__g7PendingLocalState = mergedPending;
      logger.log('[setLocal] __g7PendingLocalState updated:', mergedPending);

      // 2-1. sequence 내 커스텀 핸들러 동기화용 별도 변수 설정
      // __g7PendingLocalState는 setGlobalState → updateTemplateData → root.render() →
      // useLayoutEffect에서 null로 클리어될 수 있음 (await 해제 시 마이크로태스크 플러시)
      // __g7SequenceLocalSync는 handleSequence에서만 읽고 초기화하므로 안전
      (window as any).__g7SequenceLocalSync = mergedPending;

      // 2-2. engine-v1.21.2: getLocal() await fallback용 스냅샷
      // __g7PendingLocalState가 useLayoutEffect에서 클리어된 후에도 getLocal()이
      // 최신 setLocal 값을 반환할 수 있도록 별도 스냅샷 유지.
      // handleLocalSetState에서는 참조하지 않으므로 stale 오염 없음.
      // dataContext._local이 갱신되면 클리어됨 (DynamicRenderer useLayoutEffect).
      (window as any).__g7LastSetLocalSnapshot = mergedPending;

      // 3. forcedLocalFields 업데이트 (dataKey 자동 바인딩 컴포넌트 충돌 방지)
      if (mergeMode === 'replace') {
        // replace 모드: 기존 forcedLocalFields 초기화 후 새 값으로 교체
        (window as any).__g7ForcedLocalFields = converted;
      } else {
        const existingForced = (window as any).__g7ForcedLocalFields || {};
        (window as any).__g7ForcedLocalFields = deepMerge(existingForced, converted);
      }
      logger.log('[setLocal] __g7ForcedLocalFields updated:', converted);

      // engine-v1.17.8: setLocal이 업데이트한 키를 기록하여 ROOT의 localDynamicState에서 제거
      // 폼 자동 바인딩이 localDynamicState에 기록한 stale 배열이 dataContext._local을 덮어쓰는 문제 방지
      // setState(target: "local")가 아닌 setLocal만 기록 (setState는 localDynamicState를 직접 업데이트)
      if (mergeMode === 'replace') {
        (window as any).__g7SetLocalOverrideKeys = converted;
      } else {
        const existingKeys = (window as any).__g7SetLocalOverrideKeys || {};
        (window as any).__g7SetLocalOverrideKeys = deepMerge(existingKeys, converted);
      }
      logger.log('[setLocal] __g7SetLocalOverrideKeys updated:', converted);

      // 4. actionContext가 있으면 컴포넌트 로컬 상태도 업데이트 (리액티비티용)
      if (actionContext?.setState) {
        actionContext.setState((currentLocal: Record<string, any>) => {
          if (mergeMode === 'replace') return converted;
          if (mergeMode === 'shallow') return { ...(currentLocal || {}), ...converted };
          return deepMerge(currentLocal || {}, converted);
        });
      }

      // templateApp이 없는 경우 경고
      if (!templateApp?.setGlobalState) {
        logger.warn('G7Core.state.setLocal: TemplateApp이 없습니다.');
      }
    },
    /**
     * 현재 컴포넌트 로컬 상태를 반환합니다.
     *
     * 커스텀 핸들러에서 현재 컴포넌트의 로컬 상태를 조회할 때 사용합니다.
     *
     * @returns 현재 로컬 상태 객체
     *
     * @example
     * ```ts
     * // 현재 컴포넌트 로컬 상태
     * const currentLocal = G7Core.state.getLocal();
     * console.log(currentLocal.selectedItems);
     * ```
     */
    getLocal: (): Record<string, any> => {
      const templateApp = (window as any).__templateApp;

      // engine-v1.17.6: globalLocal + pendingState 2단계 병합
      //
      // 주의: componentState(actionContext.state)를 사용하면 안 됨!
      // - actionContext는 클릭된 컴포넌트(예: ChipCheckbox)의 컨텍스트
      // - ChipCheckbox의 state에는 form.label_assignments가 없거나 빈 배열
      // - 이것이 globalLocal의 정확한 API 데이터를 덮어씀
      //
      // globalLocal은 _localInit에서 API 데이터가 적용된 후 동기화됨
      // (DynamicRenderer의 _localInit effect가 setGlobalState({ _local: ... }) 호출)
      //
      // 참고: __g7CommittedLocalState(React 커밋 후 extendedDataContext._local)를
      // 폴백으로 사용하면 다중 root-level DynamicRenderer(메인 + 모달) 환경에서
      // 마지막으로 렌더된 모달의 _local이 메인 페이지 상태를 덮어쓰는 문제 발생.
      // 비동기 콜백에서 최신 _local이 필요한 경우, 호출자가 직접
      // handlerContext.state(버튼 클릭 시점의 componentContext.state)를 사용해야 함.
      //
      // ──────────────────────────────────────────────────────────────────
      // [모달 scope 제한사항] (Issue #29 분석, 2026-03-25)
      //
      // 현재 getLocal()은 항상 페이지의 globalLocal을 반환한다.
      // modals 섹션의 isolated scope 모달 내부 커스텀 핸들러에서 호출해도
      // 모달의 _local이 아닌 페이지의 _local을 읽는다.
      //
      // 반면 layout JSON의 `setState target: "local"`은 ActionDispatcher가
      // context.setState를 사용하여 모달 DynamicRenderer의 state를 직접 업데이트한다.
      //
      // 이 비대칭으로 인해:
      // - 모달 onMount에서 setState로 모달 scope에 데이터를 복사하면,
      //   이후 핸들러의 getLocal()은 페이지 scope를 읽어 scope 단절 발생
      // - setLocal()도 globalLocal에 쓰므로 모달 UI에 반영되지 않음
      //   (단, actionContext.setState 호출은 있어 컴포넌트 레벨 업데이트는 가능)
      //
      // 현재 우회책: 모달에서 onMount setState를 사용하지 않고,
      // 모달 열기 전 핸들러에서 setLocal()로 페이지 _local에 초기화 후 modal.open().
      // 이렇게 하면 모달이 페이지 _local을 그대로 상속하여 getLocal/setLocal과 일관.
      // (사용자 취소 모달 _modal_cancel.json, 관리자 취소 모달 _modal_cancel_order.json)
      //
      // [향후 리팩토링 방향]
      // getLocal()/setLocal()을 모달-인식(modal-aware)으로 개선:
      // 1. __g7ActionContext에 모달 루트 DynamicRenderer의 state/setState 추가
      //    (현재는 클릭된 리프 컴포넌트의 state만 있어 부분적임)
      // 2. getLocal()에서 __g7ActionContext의 모달 루트 state를 우선 반환
      // 3. setLocal()에서 모달 루트 setState를 우선 호출
      //
      // 주의 사항 (회귀 위험):
      // - __g7PendingLocalState가 scope 무인식 전역 싱글톤이라
      //   setLocal→getLocal→dispatch 체인에서 scope mismatch 발생 가능
      // - troubleshooting 사례 5,7(pendingState), 사례 3(SPA navigate),
      //   global 사례 4(sequence→refetch) 등 기존 수정과 충돌 가능
      // - actionContext.state가 리프 컴포넌트의 부분 상태인 문제 해결 필요
      //   (모달 루트 DynamicRenderer의 전체 _local state 접근 경로 확보)
      // ──────────────────────────────────────────────────────────────────

      const globalState = templateApp?.getGlobalState?.() || {};
      const globalLocal = globalState._local || {};

      // pendingState: setLocal() 호출 후 아직 React에 커밋되지 않은 업데이트
      const pendingState = (window as any).__g7PendingLocalState;
      if (pendingState) {
        return deepMerge(globalLocal, pendingState);
      }

      // engine-v1.21.2: __g7LastSetLocalSnapshot fallback
      // __g7PendingLocalState는 useLayoutEffect에서 매 렌더마다 null로 클리어됨.
      // 이는 handleLocalSetState에서의 stale pendingState 오염을 방지하기 위해 필요하지만,
      // await 경계에서 React 18 마이크로태스크 배칭이 렌더를 플러시하면
      // pendingState가 사라져 getLocal()이 stale한 globalLocal만 반환하는 문제 발생.
      //
      // __g7LastSetLocalSnapshot은 setLocal()에서 함께 설정되지만 useLayoutEffect에서
      // 클리어되지 않으므로, await 이후에도 최신 setLocal 값을 유지함.
      // handleLocalSetState에서는 참조하지 않으므로 stale 오염 없음.
      // globalLocal과 동일 참조인 경우 이미 커밋된 것이므로 스킵.
      const lastSnapshot = (window as any).__g7LastSetLocalSnapshot;
      if (lastSnapshot && lastSnapshot !== globalLocal) {
        return deepMerge(globalLocal, lastSnapshot);
      }

      return globalLocal;
    },
    /**
     * 현재 상태를 기반으로 전역 상태를 업데이트합니다.
     * React의 setState 함수형 업데이트와 유사한 패턴입니다.
     * @param updater 현재 상태를 받아 새 상태를 반환하는 함수
     */
    update: (updater: (prevState: Record<string, any>) => Record<string, any>) => {
      const templateApp = (window as any).__templateApp;
      if (templateApp?.getGlobalState && templateApp?.setGlobalState) {
        const currentState = templateApp.getGlobalState();
        const newUpdates = updater(currentState);
        templateApp.setGlobalState(newUpdates);
      } else {
        logger.warn('G7Core.state.update: TemplateApp이 초기화되지 않았습니다.');
      }
    },
    /**
     * 전역 상태 변경을 구독합니다.
     * @param listener 상태 변경 시 호출될 콜백 함수
     * @returns 구독 해제 함수
     */
    subscribe: (listener: (state: Record<string, any>) => void) => {
      const templateApp = (window as any).__templateApp;
      if (templateApp?.onGlobalStateChange) {
        return templateApp.onGlobalStateChange(listener);
      }
      logger.warn('G7Core.state.subscribe: TemplateApp이 초기화되지 않았습니다.');
      return () => {}; // no-op unsubscribe
    },
    /**
     * 데이터 소스 값을 가져옵니다.
     * @param dataSourceId 데이터 소스 ID (예: 'menus', 'users')
     * @returns 데이터 소스 값 또는 undefined
     */
    getDataSource: (dataSourceId: string) => {
      const templateApp = (window as any).__templateApp;
      return templateApp?.getDataSource?.(dataSourceId);
    },
    /**
     * 격리된 상태(isolated state)를 scopeId로 조회합니다.
     *
     * 커스텀 핸들러에서 특정 격리 스코프의 상태를 조회할 때 사용합니다.
     * 액션 컨텍스트가 있는 경우 해당 컨텍스트의 isolatedContext를 우선 사용합니다.
     *
     * @param scopeId 격리 스코프 ID (선택, 미지정 시 현재 액션 컨텍스트의 격리 상태 반환)
     * @returns 격리된 상태 객체 또는 null
     *
     * @example
     * ```ts
     * // 현재 액션 컨텍스트의 격리 상태 조회
     * const isolated = G7Core.state.getIsolated();
     * console.log(isolated?.step);
     *
     * // 특정 scopeId로 조회 (레지스트리에서)
     * const categoryState = G7Core.state.getIsolated('category-selector');
     * console.log(categoryState?.selectedItems);
     * ```
     */
    getIsolated: (scopeId?: string): Record<string, any> | null => {
      // 1. scopeId가 지정된 경우 레지스트리에서 조회
      if (scopeId) {
        const registry = (window as any).__g7IsolatedStates;
        const scope = registry?.[scopeId];
        return scope?.state ?? null;
      }

      // 2. scopeId가 없으면 현재 액션 컨텍스트의 isolatedContext 사용
      const actionContext = (window as any).__g7ActionContext;
      return actionContext?.isolatedContext?.state ?? null;
    },
    /**
     * 격리된 상태(isolated state)를 업데이트합니다.
     *
     * 커스텀 핸들러에서 격리된 상태를 직접 업데이트할 때 사용합니다.
     * scopeId가 지정된 경우 해당 스코프를, 미지정 시 현재 액션 컨텍스트의 격리 상태를 업데이트합니다.
     *
     * dot notation을 지원합니다.
     * 예: `{ "form.email": "test@example.com" }` → `{ form: { email: "test@example.com" } }`
     *
     * @param scopeIdOrUpdates scopeId (문자열) 또는 업데이트 객체
     * @param maybeUpdates scopeId가 지정된 경우 업데이트 객체
     *
     * @example
     * ```ts
     * // 현재 액션 컨텍스트의 격리 상태 업데이트
     * G7Core.state.setIsolated({ step: 2, selectedItem: item });
     *
     * // 특정 scopeId로 업데이트
     * G7Core.state.setIsolated('category-selector', { step: 2 });
     *
     * // dot notation 지원
     * G7Core.state.setIsolated({ "form.email": "test@example.com" });
     * ```
     */
    setIsolated: (
      scopeIdOrUpdates: string | Record<string, any>,
      maybeUpdates?: Record<string, any>,
      options?: { merge?: 'replace' | 'shallow' | 'deep' }
    ): void => {
      let scopeId: string | undefined;
      let updates: Record<string, any>;
      let mergeOpts: { merge?: 'replace' | 'shallow' | 'deep' } | undefined;

      // 오버로드 처리: (updates) 또는 (scopeId, updates) 또는 (updates, options)
      if (typeof scopeIdOrUpdates === 'string') {
        scopeId = scopeIdOrUpdates;
        updates = maybeUpdates || {};
        mergeOpts = options;
      } else {
        updates = scopeIdOrUpdates;
        // 두 번째 인자가 options인지 확인
        mergeOpts = maybeUpdates && typeof maybeUpdates === 'object' && ('merge' in maybeUpdates) ? maybeUpdates as any : options;
      }
      const mergeMode: 'replace' | 'shallow' | 'deep' = mergeOpts?.merge || 'deep';

      // dot notation을 중첩 객체로 변환
      const converted = convertDotNotationToObject(updates);

      // 1. scopeId가 지정된 경우 레지스트리에서 조회하여 업데이트
      if (scopeId) {
        const registry = (window as any).__g7IsolatedStates;
        const scope = registry?.[scopeId];
        if (scope?.mergeState) {
          scope.mergeState(converted, mergeMode);
        } else {
          logger.warn(`G7Core.state.setIsolated: scopeId '${scopeId}'를 찾을 수 없습니다.`);
        }
        return;
      }

      // 2. scopeId가 없으면 현재 액션 컨텍스트의 isolatedContext 사용
      const actionContext = (window as any).__g7ActionContext;
      if (actionContext?.isolatedContext?.mergeState) {
        actionContext.isolatedContext.mergeState(converted, mergeMode);
      } else {
        logger.warn('G7Core.state.setIsolated: 액션 컨텍스트에 isolatedContext가 없습니다.');
      }
    },
    /**
     * 부모 레이아웃의 컨텍스트를 반환합니다.
     *
     * 모달 등 자식 레이아웃에서 부모 레이아웃의 상태에 접근할 때 사용합니다.
     * `__g7LayoutContextStack`에서 부모 컨텍스트를 가져옵니다.
     *
     * @returns 부모 컨텍스트 객체 또는 null
     *
     * @example
     * ```ts
     * // 커스텀 핸들러에서 부모 상태 조회
     * const parentContext = G7Core.state.getParent();
     * if (parentContext) {
     *   const parentLocal = parentContext._local;
     *   const parentGlobal = parentContext._global;
     *   console.log('부모 로컬 상태:', parentLocal);
     * }
     * ```
     *
     * @since engine-v1.16.0
     */
    getParent: (): { _local: Record<string, any>; _global: Record<string, any>; setState: (updates: any) => void } | null => {
      const contextStack: Array<{
        state: Record<string, any>;
        setState: (updates: any) => void;
        dataContext?: Record<string, any>;
      }> = (window as any).__g7LayoutContextStack || [];

      if (contextStack.length === 0) {
        logger.log('[getParent] 레이아웃 컨텍스트 스택이 비어있습니다.');
        return null;
      }

      // 스택의 마지막 항목이 부모 컨텍스트
      const parentEntry = contextStack[contextStack.length - 1];
      if (!parentEntry) {
        return null;
      }

      // dataContext가 있으면 $parent 바인딩에서 저장한 확장 컨텍스트 사용
      const parentData = parentEntry.dataContext || {};

      return {
        _local: parentEntry.state || parentData._local || {},
        _global: parentData._global || G7Core.state.get() || {},
        setState: parentEntry.setState,
      };
    },
    /**
     * 부모 레이아웃의 로컬 상태(_local)를 업데이트합니다.
     *
     * 모달 등 자식 레이아웃에서 부모 레이아웃의 로컬 상태를 직접 수정할 때 사용합니다.
     *
     * dot notation을 지원합니다.
     * 예: `{ "form.name": "value" }` → `{ form: { name: "value" } }`
     *
     * @param pathOrUpdates 경로 문자열 또는 업데이트 객체
     * @param maybeValue 경로가 지정된 경우 설정할 값
     *
     * @example
     * ```ts
     * // 객체로 업데이트
     * G7Core.state.setParentLocal({
     *   'form.label_assignments': updatedAssignments,
     *   hasChanges: true,
     * });
     *
     * // 경로와 값으로 업데이트
     * G7Core.state.setParentLocal('form.name', 'newValue');
     * ```
     *
     * @since engine-v1.16.0
     */
    setParentLocal: (pathOrUpdates: string | Record<string, any>, maybeValue?: any, options?: { merge?: 'replace' | 'shallow' | 'deep' }): void => {
      const contextStack: Array<{
        state: Record<string, any>;
        setState: (updates: any) => void;
        dataContext?: Record<string, any>;
      }> = (window as any).__g7LayoutContextStack || [];

      if (contextStack.length === 0) {
        logger.warn('[setParentLocal] 레이아웃 컨텍스트 스택이 비어있습니다.');
        return;
      }

      const parentEntry = contextStack[contextStack.length - 1];
      if (!parentEntry?.setState) {
        logger.warn('[setParentLocal] 부모 컨텍스트에 setState가 없습니다.');
        return;
      }

      let updates: Record<string, any>;
      let mergeOpts: { merge?: 'replace' | 'shallow' | 'deep' } | undefined;
      if (typeof pathOrUpdates === 'string') {
        // 경로와 값으로 호출된 경우
        updates = { [pathOrUpdates]: maybeValue };
        mergeOpts = options;
      } else {
        updates = pathOrUpdates;
        // 객체로 호출 시 두 번째 인자가 options일 수 있음
        mergeOpts = maybeValue && typeof maybeValue === 'object' && ('merge' in maybeValue) ? maybeValue : options;
      }
      const mergeMode = mergeOpts?.merge || 'deep';

      // dot notation을 중첩 객체로 변환
      const converted = convertDotNotationToObject(updates);

      // parentEntry.state가 전체 데이터 컨텍스트(_local 포함)인지 확인
      // _local이 있으면 그 안의 상태를 업데이트, 없으면 state 자체가 로컬 상태
      const hasNestedLocal = parentEntry.state?._local !== undefined;
      const currentLocal = hasNestedLocal ? parentEntry.state._local : (parentEntry.state || {});
      let merged: Record<string, any>;
      if (mergeMode === 'replace') {
        merged = converted;
      } else if (mergeMode === 'shallow') {
        merged = { ...currentLocal, ...converted };
      } else {
        merged = deepMerge(currentLocal, converted);
      }

      // CRITICAL: __g7PendingLocalState를 setState 호출 전에 설정해야 함!
      // React가 setState 배치를 처리할 때 handleLocalSetState 콜백이 실행되는데,
      // 이 콜백에서 __g7PendingLocalState를 읽어 최신 상태를 effectivePrev로 사용함.
      // 따라서 setState 호출 전에 설정해야 콜백이 최신 값을 읽을 수 있음.
      (window as any).__g7PendingLocalState = merged;

      // engine-v1.17.12: 전역 _local 상태도 업데이트 (setLocal과 동일)
      // setParentLocal은 parentEntry.setState()로 React 상태를 업데이트하지만,
      // getLocal()은 templateApp.getGlobalState()._local에서 읽음.
      // __g7PendingLocalState가 렌더 후 클리어되면 getLocal()이 stale 데이터를 반환하므로,
      // globalState._local도 함께 업데이트해야 후속 getLocal() 호출이 최신 값을 반환함.
      const templateApp = (window as any).__templateApp;
      if (templateApp?.setGlobalState) {
        templateApp.setGlobalState({ _local: merged });
      }

      parentEntry.setState(merged);

      // 스택의 state와 dataContext._local 모두 업데이트하여
      // 다음 호출 시 currentLocal이 최신 값을 읽고,
      // 모달에서 $parent._local 바인딩이 최신 값을 읽도록 함
      if (hasNestedLocal) {
        parentEntry.state._local = merged;
      } else {
        parentEntry.state = merged;
      }
      if (parentEntry.dataContext) {
        parentEntry.dataContext._local = merged;
      }

      // __g7ForcedLocalFields 업데이트 (dataKey 자동 바인딩 컴포넌트 충돌 방지)
      // setParentLocal로 업데이트된 필드가 Form 자동 바인딩에 의해 덮어쓰이지 않도록 함
      if (mergeMode === 'replace') {
        (window as any).__g7ForcedLocalFields = converted;
      } else {
        const existingForced = (window as any).__g7ForcedLocalFields || {};
        (window as any).__g7ForcedLocalFields = deepMerge(existingForced, converted);
      }

      // ParentContextProvider를 통해 모달만 선택적으로 리렌더링
      // 전체 앱 리렌더링 없이 모달만 업데이트됨
      triggerModalParentUpdate();
    },
    /**
     * 부모 레이아웃의 전역 상태(_global)를 업데이트합니다.
     *
     * 모달 등 자식 레이아웃에서 전역 상태를 직접 수정할 때 사용합니다.
     * 참고: 전역 상태는 앱 전체에서 공유되므로, 부모/자식 구분 없이
     * G7Core.state.set()과 동일하게 동작합니다. 이 메서드는 의미적 명확성을 위해 제공됩니다.
     *
     * dot notation을 지원합니다.
     *
     * @param pathOrUpdates 경로 문자열 또는 업데이트 객체
     * @param maybeValue 경로가 지정된 경우 설정할 값
     *
     * @example
     * ```ts
     * // 객체로 업데이트
     * G7Core.state.setParentGlobal({
     *   modalResult: { confirmed: true, data: selectedItems },
     * });
     *
     * // 경로와 값으로 업데이트
     * G7Core.state.setParentGlobal('modalResult.confirmed', true);
     * ```
     *
     * @since engine-v1.16.0
     */
    setParentGlobal: (pathOrUpdates: string | Record<string, any>, maybeValue?: any): void => {
      let updates: Record<string, any>;
      if (typeof pathOrUpdates === 'string') {
        updates = { [pathOrUpdates]: maybeValue };
      } else {
        updates = pathOrUpdates;
      }

      // 전역 상태는 앱 전체에서 공유되므로 G7Core.state.set() 사용
      G7Core.state.set(updates);
      logger.log('[setParentGlobal] 전역 상태 업데이트:', updates);
    },
  };

  // 격리 상태 레지스트리 초기화
  if (!(window as any).__g7IsolatedStates) {
    (window as any).__g7IsolatedStates = {};
  }

  // 별칭 메서드 추가 (하위 호환성)
  G7Core.state.getGlobal = G7Core.state.get;
  G7Core.state.setGlobal = G7Core.state.set;

  logger.log('전역 객체 window.G7Core.state에 노출됨');
}

/**
 * 데이터 소스 관리 API 초기화
 *
 * 데이터 소스의 조회, 설정, refetch 등을 관리합니다.
 */
function initDataSourceAPI(G7Core: any): void {
  G7Core.dataSource = {
    /**
     * 데이터 소스 값을 가져옵니다.
     * @param dataSourceId 데이터 소스 ID
     * @returns 데이터 소스 값 또는 undefined
     */
    get: (dataSourceId: string) => {
      const templateApp = (window as any).__templateApp;
      return templateApp?.getDataSource?.(dataSourceId);
    },
    /**
     * 데이터 소스 값을 설정하고 UI를 리렌더링합니다.
     *
     * 서버 refetch 없이 클라이언트 측에서 데이터 소스를 직접 업데이트할 때 사용합니다.
     *
     * @param dataSourceId 데이터 소스 ID
     * @param data 설정할 데이터
     * @param options 옵션
     * @param options.merge true면 기존 데이터와 병합 (기본값: false)
     * @param options.sync true면 동기 업데이트 (기본값: false)
     *
     * @example
     * // 데이터 전체 교체
     * G7Core.dataSource.set('products', { data: updatedProducts, meta: {...} });
     *
     * // 기존 데이터와 병합
     * G7Core.dataSource.set('products', { data: updatedProducts }, { merge: true });
     */
    set: (
      dataSourceId: string,
      data: any,
      options?: { merge?: boolean; sync?: boolean }
    ) => {
      const templateApp = (window as any).__templateApp;
      if (templateApp?.setDataSource) {
        templateApp.setDataSource(dataSourceId, data, options);
      } else {
        logger.warn('G7Core.dataSource.set: TemplateApp이 초기화되지 않았습니다.');
      }
    },
    /**
     * 데이터 소스를 서버에서 다시 가져옵니다.
     * @param dataSourceId 데이터 소스 ID
     * @param options 옵션
     * @returns refetch 결과 데이터
     */
    refetch: async (dataSourceId: string, options?: { skipCache?: boolean; sync?: boolean }) => {
      const templateApp = (window as any).__templateApp;
      if (templateApp?.refetchDataSource) {
        return templateApp.refetchDataSource(dataSourceId, options);
      }
      logger.warn('G7Core.dataSource.refetch: TemplateApp이 초기화되지 않았습니다.');
      return undefined;
    },

    /**
     * 데이터 소스 내 특정 배열 아이템만 업데이트합니다.
     *
     * 전체 데이터소스를 교체하지 않고 특정 아이템만 수정하여
     * 불필요한 리렌더링을 방지합니다.
     *
     * @param dataSourceId 데이터 소스 ID
     * @param itemPath 배열 경로 (예: "data.data", "data.data[0].options")
     * @param itemId 업데이트할 아이템의 ID
     * @param updates 업데이트할 필드들
     * @param options 옵션
     * @returns 성공 여부
     *
     * @example
     * ```typescript
     * // 상품 옵션 업데이트
     * G7Core.dataSource.updateItem(
     *   'products',
     *   'data.data[0].options',
     *   123,  // optionId
     *   { selling_price: 15000, _modified: true }
     * );
     *
     * // 사용자 정보 업데이트 (커스텀 ID 필드 사용)
     * G7Core.dataSource.updateItem(
     *   'users',
     *   'data',
     *   'user-456',
     *   { name: '홍길동' },
     *   { idField: 'uuid' }
     * );
     * ```
     */
    updateItem: (
      dataSourceId: string,
      itemPath: string,
      itemId: string | number,
      updates: Record<string, any>,
      options?: {
        /** ID 필드명 (기본: "id") */
        idField?: string;
        /** 깊은 병합 여부 (기본: true) */
        merge?: boolean;
        /** 렌더링 스킵 여부 (기본: false) */
        skipRender?: boolean;
      }
    ): boolean => {
      const templateApp = (window as any).__templateApp;
      if (templateApp?.updateDataSourceItem) {
        return templateApp.updateDataSourceItem(dataSourceId, itemPath, itemId, updates, options);
      }
      logger.warn('G7Core.dataSource.updateItem: TemplateApp이 초기화되지 않았습니다.');
      return false;
    },

    /**
     * 데이터 소스 내 특정 경로의 배열에 새 데이터를 병합합니다.
     *
     * 무한 스크롤 등에서 기존 데이터에 새 데이터를 추가할 때 사용합니다.
     *
     * @param dataSourceId 데이터 소스 ID
     * @param dataPath 배열 경로 (예: "data", "data.items"). null이면 루트 배열
     * @param newData 추가할 새 데이터 (배열)
     * @param mode 병합 모드 - 'append': 끝에 추가, 'prepend': 앞에 추가 (기본: 'append')
     * @returns 성공 여부
     *
     * @example
     * ```typescript
     * // 무한 스크롤: 기존 목록 뒤에 새 데이터 추가
     * G7Core.dataSource.updateData('templates', 'data', newTemplates, 'append');
     *
     * // 새로고침: 새 데이터를 앞에 추가
     * G7Core.dataSource.updateData('notifications', 'data', newNotifications, 'prepend');
     * ```
     */
    updateData: (
      dataSourceId: string,
      dataPath: string | null,
      newData: any[],
      mode: 'append' | 'prepend' = 'append'
    ): boolean => {
      const templateApp = (window as any).__templateApp;
      if (!templateApp?.getDataSource || !templateApp?.setDataSource) {
        logger.warn('G7Core.dataSource.updateData: TemplateApp이 초기화되지 않았습니다.');
        return false;
      }

      // 현재 데이터 소스 가져오기
      const currentDataSource = templateApp.getDataSource(dataSourceId);
      if (!currentDataSource) {
        logger.warn(`G7Core.dataSource.updateData: 데이터 소스 '${dataSourceId}'를 찾을 수 없습니다.`);
        return false;
      }

      // 경로에서 대상 배열 가져오기
      let targetArray: any[];
      let parentObj: any = currentDataSource;
      let lastKey: string | null = null;

      if (dataPath) {
        const pathParts = dataPath.split('.');
        lastKey = pathParts.pop()!;

        for (const part of pathParts) {
          if (parentObj && typeof parentObj === 'object' && part in parentObj) {
            parentObj = parentObj[part];
          } else {
            logger.warn(`G7Core.dataSource.updateData: 경로 '${dataPath}'를 찾을 수 없습니다.`);
            return false;
          }
        }

        targetArray = parentObj[lastKey];
      } else {
        targetArray = currentDataSource;
      }

      if (!Array.isArray(targetArray)) {
        logger.warn(`G7Core.dataSource.updateData: 대상 경로의 데이터가 배열이 아닙니다.`);
        return false;
      }

      if (!Array.isArray(newData)) {
        logger.warn(`G7Core.dataSource.updateData: newData가 배열이 아닙니다.`);
        return false;
      }

      // 배열 병합
      const mergedArray = mode === 'prepend'
        ? [...newData, ...targetArray]
        : [...targetArray, ...newData];

      // 업데이트된 데이터 설정
      if (dataPath && lastKey) {
        parentObj[lastKey] = mergedArray;
        templateApp.setDataSource(dataSourceId, currentDataSource, { merge: false });
      } else {
        templateApp.setDataSource(dataSourceId, mergedArray, { merge: false });
      }

      logger.log(`G7Core.dataSource.updateData: '${dataSourceId}'에 ${newData.length}개 항목 ${mode === 'prepend' ? '앞에' : '뒤에'} 추가됨`);
      return true;
    },
  };

  logger.log('전역 객체 window.G7Core.dataSource에 노출됨');
}

/**
 * 로케일 관리 API 초기화
 */
function initLocaleAPI(G7Core: any, deps: G7CoreDependencies): void {
  G7Core.locale = {
    /**
     * 현재 로케일을 반환합니다.
     */
    current: () => {
      const templateApp = (window as any).__templateApp;
      return templateApp?.getLocale?.() || 'ko';
    },
    /**
     * 지원하는 로케일 목록을 반환합니다.
     */
    supported: () => {
      const templateApp = (window as any).__templateApp;
      const systemLocales = templateApp?.globalState?.appConfig?.supportedLocales;
      if (Array.isArray(systemLocales) && systemLocales.length > 0) {
        return systemLocales;
      }
      const state = deps.getState();
      return state.templateMetadata?.locales || ['ko', 'en'];
    },
    /**
     * 로케일을 변경합니다.
     * @param locale 변경할 로케일 (예: 'ko', 'en')
     */
    change: async (locale: string) => {
      const templateApp = (window as any).__templateApp;
      if (templateApp?.changeLocale) {
        await templateApp.changeLocale(locale);
      } else {
        logger.warn('G7Core.locale.change: TemplateApp이 초기화되지 않았습니다.');
      }
    },
  };

  logger.log('전역 객체 window.G7Core.locale에 노출됨');
}

/**
 * 토스트 알림 API 초기화
 */
function initToastAPI(G7Core: any): void {
  G7Core.toast = {
    /**
     * 토스트 알림을 표시합니다.
     * @param message 표시할 메시지
     * @param options type: 'success'|'error'|'warning'|'info', duration: ms
     */
    show: (message: string, options?: { type?: 'success' | 'error' | 'warning' | 'info'; duration?: number }) => {
      G7Core.dispatch({
        handler: 'toast',
        params: {
          message,
          type: options?.type || 'info',
          ...(options?.duration && { duration: options.duration }),
        },
      });
    },
    success: (message: string, duration?: number) => {
      G7Core.toast.show(message, { type: 'success', duration });
    },
    error: (message: string, duration?: number) => {
      G7Core.toast.show(message, { type: 'error', duration });
    },
    warning: (message: string, duration?: number) => {
      G7Core.toast.show(message, { type: 'warning', duration });
    },
    info: (message: string, duration?: number) => {
      G7Core.toast.show(message, { type: 'info', duration });
    },
  };

  logger.log('전역 객체 window.G7Core.toast에 노출됨');
}

/**
 * 모달 관리 API 초기화
 */
function initModalAPI(G7Core: any): void {
  G7Core.modal = {
    /**
     * 모달을 엽니다.
     * @param modalId 열 모달의 ID
     */
    open: (modalId: string) => {
      G7Core.dispatch({
        handler: 'openModal',
        target: modalId,
      });
    },
    /**
     * 모달을 닫습니다.
     * @param modalId 닫을 모달의 ID (생략 시 최상위 모달 닫기)
     */
    close: (modalId?: string) => {
      G7Core.dispatch({
        handler: 'closeModal',
        ...(modalId && { target: modalId }),
      });
    },
    /**
     * 모든 모달을 닫습니다.
     */
    closeAll: () => {
      G7Core.dispatch({
        handler: 'closeAllModals',
      });
    },
    /**
     * 특정 모달이 열려있는지 확인합니다.
     * @param modalId 확인할 모달의 ID
     */
    isOpen: (modalId: string) => {
      const state = G7Core.state.get();
      return state._global?.activeModal === modalId ||
             state._global?.modalStack?.includes(modalId) ||
             false;
    },
    /**
     * 현재 열린 모달 스택을 반환합니다.
     */
    getStack: () => {
      const state = G7Core.state.get();
      return state._global?.modalStack || [];
    },
  };

  logger.log('전역 객체 window.G7Core.modal에 노출됨');
}

/**
 * 스타일 헬퍼 API 초기화
 */
function initStyleAPI(G7Core: any): void {
  G7Core.style = {
    /**
     * Tailwind CSS 클래스를 런타임에서 병합합니다.
     * 같은 CSS 속성을 제어하는 클래스가 충돌하면 override 클래스를 우선 적용합니다.
     *
     * @example
     * ```typescript
     * G7Core.style.mergeClasses('justify-center items-center', 'justify-between')
     * // 결과: 'items-center justify-between'
     * ```
     */
    mergeClasses,
    /**
     * 조건에 따라 클래스를 적용합니다.
     *
     * @example
     * ```typescript
     * G7Core.style.conditionalClass({
     *   'bg-blue-500': isPrimary,
     *   'opacity-50': isDisabled,
     * })
     * ```
     */
    conditionalClass,
    /**
     * 여러 클래스 문자열을 하나로 합칩니다.
     * falsy 값은 무시됩니다.
     *
     * @example
     * ```typescript
     * G7Core.style.joinClasses('flex', isActive && 'bg-blue-500', 'p-4')
     * ```
     */
    joinClasses,
  };

  logger.log('전역 객체 window.G7Core.style에 노출됨');
}

/**
 * WebSocket 관리 API 초기화
 */
function initWebSocketAPI(G7Core: any, deps: G7CoreDependencies): void {
  const { webSocketManager } = deps;

  G7Core.websocket = {
    /**
     * WebSocketManager 인스턴스를 반환합니다.
     */
    manager: webSocketManager,
    /**
     * WebSocket 채널을 구독합니다.
     * @param channel 채널명 (예: 'admin.dashboard')
     * @param event 이벤트명 (예: 'dashboard.stats.updated')
     * @param callback 이벤트 수신 시 호출될 콜백
     * @param options 구독 옵션 { channelType: 'public' | 'private' | 'presence' }
     * @returns 구독 키 (구독 해제 시 사용)
     */
    subscribe: (
      channel: string,
      event: string,
      callback: (data: unknown) => void,
      options?: { channelType?: 'public' | 'private' | 'presence' }
    ) => webSocketManager.subscribe(channel, event, callback, options),
    /**
     * WebSocket 구독을 해제합니다.
     * @param subscriptionKey 구독 키
     */
    unsubscribe: (subscriptionKey: string) => webSocketManager.unsubscribe(subscriptionKey),
    /**
     * 특정 채널의 모든 구독을 해제합니다.
     * @param channel 채널명
     */
    leaveChannel: (channel: string) => webSocketManager.leaveChannel(channel),
    /**
     * 모든 WebSocket 연결을 종료합니다.
     */
    disconnect: () => webSocketManager.disconnect(),
    /**
     * WebSocket 초기화 여부를 반환합니다.
     */
    isInitialized: () => webSocketManager.isInitialized(),
    /**
     * 현재 활성 구독 수를 반환합니다.
     */
    getSubscriptionCount: () => webSocketManager.getSubscriptionCount(),
  };

  logger.log('전역 객체 window.G7Core.websocket에 노출됨');
}

/**
 * 네비게이션 API 초기화
 */
function initNavigationAPI(G7Core: any, deps: G7CoreDependencies): void {
  const { transitionManager } = deps;

  G7Core.navigation = {
    /**
     * 현재 페이지 전환이 진행 중인지 확인합니다.
     * @returns 전환 진행 중이면 true
     */
    isPending: () => {
      return transitionManager?.getIsPending?.() || false;
    },
    /**
     * 다음 페이지 전환이 완료되면 콜백을 실행합니다.
     * 전환이 시작(isPending=true)된 후 완료(isPending=false)될 때 콜백을 실행합니다.
     * @param callback 전환 완료 시 실행할 콜백
     * @returns 구독 해제 함수
     */
    onComplete: (callback: () => void) => {
      if (!transitionManager) {
        // TransitionManager가 없으면 즉시 실행
        callback();
        return () => {};
      }

      // 전환 시작 여부 추적
      let transitionStarted = transitionManager.getIsPending();

      // 전환 상태 변화 감지 (true → false 순서로 감지되면 콜백 실행)
      const unsubscribe = transitionManager.subscribe((isPending: boolean) => {
        if (isPending) {
          // 전환 시작됨
          transitionStarted = true;
        } else if (transitionStarted) {
          // 전환이 시작된 후 완료됨
          callback();
          unsubscribe();
        }
      });

      return unsubscribe;
    },
  };

  logger.log('전역 객체 window.G7Core.navigation에 노출됨');
}

/**
 * 플러그인 설정 접근 API 초기화
 *
 * 활성화된 플러그인의 환경설정 값에 접근합니다.
 * admin.blade.php에서 G7Config.plugins로 주입된 값을 사용합니다.
 *
 * @example
 * ```ts
 * // 플러그인 전체 설정 조회
 * const daumSettings = G7Core.plugin.getSettings('sirsoft-daum_postcode');
 * // { display_mode: 'layer', popup_width: 900, ... }
 *
 * // 특정 설정 값 조회
 * const displayMode = G7Core.plugin.get('sirsoft-daum_postcode', 'display_mode');
 * // 'layer'
 *
 * // 기본값과 함께 조회
 * const width = G7Core.plugin.get('sirsoft-daum_postcode', 'popup_width', 500);
 * ```
 */
function initPluginAPI(G7Core: any): void {
  G7Core.plugin = {
    /**
     * 플러그인의 전체 설정을 조회합니다.
     * @param identifier 플러그인 식별자
     * @returns 플러그인 설정 객체 또는 undefined
     */
    getSettings: (identifier: string): Record<string, any> | undefined => {
      return (window as any).G7Config?.plugins?.[identifier];
    },
    /**
     * 플러그인의 특정 설정 값을 조회합니다.
     * @param identifier 플러그인 식별자
     * @param key 설정 키
     * @param defaultValue 기본값
     * @returns 설정 값 또는 기본값
     */
    get: (identifier: string, key: string, defaultValue?: any): any => {
      const settings = (window as any).G7Config?.plugins?.[identifier];
      return settings?.[key] ?? defaultValue;
    },
    /**
     * 모든 활성화된 플러그인 설정을 조회합니다.
     * @returns 플러그인 식별자를 키로 하는 설정 객체
     */
    getAll: (): Record<string, Record<string, any>> => {
      return (window as any).G7Config?.plugins ?? {};
    },
  };

  logger.log('전역 객체 window.G7Core.plugin에 노출됨');
}

/**
 * 모듈 설정 접근 API 초기화
 *
 * 활성화된 모듈의 환경설정 값에 접근합니다.
 * admin.blade.php에서 G7Config.modules로 주입된 값을 사용합니다.
 *
 * @example
 * ```ts
 * // 모듈 전체 설정 조회
 * const ecommerceSettings = G7Core.module.getSettings('sirsoft-ecommerce');
 *
 * // 특정 설정 값 조회
 * const currency = G7Core.module.get('sirsoft-ecommerce', 'default_currency', 'KRW');
 * ```
 */
function initModuleAPI(G7Core: any): void {
  G7Core.module = {
    /**
     * 모듈의 전체 설정을 조회합니다.
     * @param identifier 모듈 식별자
     * @returns 모듈 설정 객체 또는 undefined
     */
    getSettings: (identifier: string): Record<string, any> | undefined => {
      return (window as any).G7Config?.modules?.[identifier];
    },
    /**
     * 모듈의 특정 설정 값을 조회합니다.
     * @param identifier 모듈 식별자
     * @param key 설정 키
     * @param defaultValue 기본값
     * @returns 설정 값 또는 기본값
     */
    get: (identifier: string, key: string, defaultValue?: any): any => {
      const settings = (window as any).G7Config?.modules?.[identifier];
      return settings?.[key] ?? defaultValue;
    },
    /**
     * 모든 활성화된 모듈 설정을 조회합니다.
     * @returns 모듈 식별자를 키로 하는 설정 객체
     */
    getAll: (): Record<string, Record<string, any>> => {
      return (window as any).G7Config?.modules ?? {};
    },
  };

  logger.log('전역 객체 window.G7Core.module에 노출됨');
}

/**
 * 코어 API 초기화 (AuthManager, ApiClient, 훅 등)
 */
function initCoreAPIs(G7Core: any, deps: G7CoreDependencies): void {
  const { transitionManager, responsiveManager } = deps;

  // AuthManager 노출 (템플릿 컴포넌트에서 사용자 정보 접근용)
  G7Core.AuthManager = AuthManager;

  // ApiClient 노출 (템플릿 컴포넌트에서 API 호출용)
  G7Core.api = getApiClient();

  // TransitionManager 노출 (템플릿 컴포넌트에서 페이지 전환 상태 접근용)
  // 동기적으로 노출하여 컴포넌트 마운트 시점에 바로 사용 가능하도록 함
  G7Core.TransitionManager = transitionManager;

  // useTransitionState 훅 노출 (템플릿 컴포넌트에서 전환 상태 구독용)
  G7Core.useTransitionState = useTransitionState;

  // ResponsiveManager 노출 (템플릿 컴포넌트에서 반응형 상태 접근용)
  G7Core.ResponsiveManager = responsiveManager;

  // useResponsive 훅 노출 (템플릿 컴포넌트에서 반응형 상태 구독용)
  G7Core.useResponsive = useResponsive;

  // useControllableState 훅 노출 (Phase 1-4)
  // Controlled/Uncontrolled 상태 패턴을 단순화하는 훅
  G7Core.useControllableState = useControllableState;
  G7Core.shallowArrayEqual = shallowArrayEqual;
  G7Core.shallowObjectEqual = shallowObjectEqual;

  // updateQueryParams 노출 (같은 페이지 내 쿼리 변경 시 컴포넌트 리마운트 없이 데이터 갱신)
  // navigate 핸들러에서 replace: true 사용 시 자동 호출됨
  // options.transitionOverlayTarget: 호출별 transition_overlay.target 동적 override (@since engine-v1.36.0)
  G7Core.updateQueryParams = async (
    newPath: string,
    options?: { transitionOverlayTarget?: string }
  ) => {
    const templateApp = (window as any).__templateApp;
    if (templateApp?.updateQueryParams) {
      return templateApp.updateQueryParams(newPath, options);
    }
    logger.warn('G7Core.updateQueryParams: TemplateApp이 초기화되지 않았습니다.');
  };

  logger.log('전역 객체 window.G7Core.AuthManager에 노출됨');
  logger.log('전역 객체 window.G7Core.api에 노출됨');
  logger.log('전역 객체 window.G7Core.ResponsiveManager에 노출됨');
  logger.log('전역 객체 window.G7Core.useResponsive에 노출됨');
  logger.log('전역 객체 window.G7Core.useControllableState에 노출됨');
  logger.log('전역 객체 window.G7Core.updateQueryParams에 노출됨');
}

/**
 * 슬롯 시스템 API 초기화
 *
 * SlotContext와 DynamicRenderer를 외부(템플릿 컴포넌트)에서 접근할 수 있도록 합니다.
 * SlotContainer 컴포넌트에서 이 API를 사용하여 슬롯에 등록된 컴포넌트를 렌더링합니다.
 *
 * @example
 * ```ts
 * // SlotContainer.tsx에서 사용
 * const slotContext = G7Core.getSlotContext();
 * const DynamicRenderer = G7Core.getDynamicRenderer();
 * const registry = G7Core.getComponentRegistry();
 * ```
 */
function initSlotAPI(G7Core: any, deps: G7CoreDependencies): void {
  /**
   * SlotContext 값을 가져옵니다.
   * SlotProvider 내부에서만 유효한 값을 반환합니다.
   *
   * SlotProvider가 마운트되면 window.__slotContextValue에 값이 설정됩니다.
   */
  G7Core.getSlotContext = () => {
    // SlotProvider에서 설정한 전역 값 참조
    return (window as any).__slotContextValue ?? null;
  };

  /**
   * DynamicRenderer 컴포넌트를 가져옵니다.
   * SlotContainer에서 슬롯에 등록된 컴포넌트를 렌더링할 때 사용합니다.
   *
   * DynamicRenderer가 로드되면 window.__DynamicRenderer에 값이 설정됩니다.
   */
  G7Core.getDynamicRenderer = () => {
    // DynamicRenderer 모듈에서 설정한 전역 값 참조
    return (window as any).__DynamicRenderer ?? null;
  };

  /**
   * ComponentRegistry를 가져옵니다.
   */
  G7Core.getComponentRegistry = () => {
    return deps.getComponentRegistry?.() ?? ComponentRegistry.getInstance();
  };

  /**
   * DataBindingEngine을 가져옵니다.
   */
  G7Core.getDataBindingEngine = () => {
    return deps.getDataBindingEngine?.() ?? deps.getState().bindingEngine;
  };

  /**
   * TranslationEngine을 가져옵니다.
   */
  G7Core.getTranslationEngine = () => {
    return deps.getTranslationEngine?.() ?? deps.getState().translationEngine;
  };

  /**
   * ActionDispatcher를 가져옵니다.
   */
  G7Core.getActionDispatcher = () => {
    return deps.getActionDispatcher?.() ?? deps.getState().actionDispatcher;
  };

  logger.log('전역 객체 window.G7Core 슬롯 API에 노출됨 (getSlotContext, getDynamicRenderer 등)');
}

/**
 * 위지윅 편집기 API 초기화
 *
 * 위지윅 레이아웃 편집기 관련 전역 API를 window.G7Core에 노출합니다.
 *
 * @since engine-v1.11.0
 *
 * @example
 * ```ts
 * // 편집 모드 확인
 * if (G7Core.wysiwyg.isEditMode()) {
 *   // 편집 모드 전용 로직
 * }
 *
 * // 편집 모드로 진입
 * G7Core.wysiwyg.enterEditMode('home', 'sirsoft-basic');
 *
 * // 편집 모드 종료
 * G7Core.wysiwyg.exitEditMode();
 * ```
 */
function initWysiwygEditorAPI(G7Core: any): void {
  // 편집 모드 상태 (전역)
  let editModeEnabled = false;
  let currentLayoutName: string | null = null;
  let currentTemplateId: string | null = null;

  G7Core.wysiwyg = {
    /**
     * 현재 편집 모드 여부를 반환합니다.
     *
     * @returns boolean 편집 모드 여부
     */
    isEditMode: (): boolean => {
      return editModeEnabled;
    },

    /**
     * 편집 모드를 활성화합니다.
     *
     * @param layoutName 편집할 레이아웃명
     * @param templateId 템플릿 ID
     */
    setEditMode: (layoutName: string, templateId: string): void => {
      editModeEnabled = true;
      currentLayoutName = layoutName;
      currentTemplateId = templateId;
      logger.log(`위지윅 편집 모드 활성화: ${layoutName} (${templateId})`);
    },

    /**
     * 편집 모드를 비활성화합니다.
     */
    clearEditMode: (): void => {
      editModeEnabled = false;
      currentLayoutName = null;
      currentTemplateId = null;
      logger.log('위지윅 편집 모드 비활성화');
    },

    /**
     * 현재 편집 중인 레이아웃명을 반환합니다.
     *
     * @returns string | null 레이아웃명 또는 null
     */
    getCurrentLayoutName: (): string | null => {
      return currentLayoutName;
    },

    /**
     * 현재 편집 중인 템플릿 ID를 반환합니다.
     *
     * @returns string | null 템플릿 ID 또는 null
     */
    getCurrentTemplateId: (): string | null => {
      return currentTemplateId;
    },

    /**
     * URL 쿼리 파라미터에서 편집 모드 여부를 확인합니다.
     *
     * @returns boolean 편집 모드 여부
     */
    isEditModeFromUrl: (): boolean => {
      if (typeof window === 'undefined') {
        return false;
      }
      const params = new URLSearchParams(window.location.search);
      return params.get('mode') === 'edit';
    },

    /**
     * 편집 모드 URL을 생성합니다.
     * 라우트 기반 URL 형식: /{route}?mode=edit&template={templateId}
     *
     * @param route 라우트 경로 (예: '/', '/shop', '/board/posts')
     * @param templateId 템플릿 ID (예: 'sirsoft-basic')
     * @returns string 편집 모드 URL
     */
    getEditModeUrl: (route: string, templateId: string): string => {
      const baseUrl = window.location.origin;
      // 라우트가 '/'로 시작하지 않으면 추가
      const normalizedRoute = route.startsWith('/') ? route : `/${route}`;
      return `${baseUrl}${normalizedRoute}?mode=edit&template=${encodeURIComponent(templateId)}`;
    },

    /**
     * 편집 모드로 진입합니다. (페이지 이동)
     * 라우트 기반으로 편집 모드에 진입합니다.
     *
     * @param route 라우트 경로 (예: '/', '/shop', '/board/posts')
     * @param templateId 템플릿 ID (예: 'sirsoft-basic')
     */
    enterEditMode: (route: string, templateId: string): void => {
      if (typeof window === 'undefined') {
        return;
      }
      const url = G7Core.wysiwyg.getEditModeUrl(route, templateId);
      window.location.href = url;
    },

    /**
     * 편집 모드를 종료하고 일반 페이지로 이동합니다.
     * mode, template 파라미터를 제거하고 현재 라우트에 머무릅니다.
     */
    exitEditMode: (): void => {
      if (typeof window === 'undefined') {
        return;
      }
      const params = new URLSearchParams(window.location.search);
      params.delete('mode');
      params.delete('template');
      const newUrl = `${window.location.pathname}${params.toString() ? '?' + params.toString() : ''}`;
      window.location.href = newUrl;
    },

    /**
     * 위지윅 편집기 모듈 버전을 반환합니다.
     *
     * @returns string 버전 문자열
     */
    getVersion: (): string => {
      return '1.0.0';
    },

    /**
     * 현재 구현된 Phase를 반환합니다.
     *
     * @returns number Phase 번호
     */
    getPhase: (): number => {
      return 1;
    },
  };

  logger.log('전역 객체 window.G7Core 위지윅 편집기 API에 노출됨 (wysiwyg)');
}

/**
 * G7Core.identity 인터페이스 초기화 (engine-v1.46.0+)
 *
 * 본인인증(IDV) 인터셉터를 템플릿/플러그인이 동일 인스턴스로 공유하기 위한 글로벌 진입점입니다.
 * 템플릿 번들이 IIFE 로 빌드되면서 코어 모듈을 외부화하지 않으므로, 직접 import 대신
 * 이 namespace 를 통해 코어의 단일 IdentityGuardInterceptor 정적 클래스에 접근해야 합니다.
 *
 * 노출되는 진입점:
 * - `setLauncher(launcher)` — 템플릿 부트스트랩에서 모달 launcher 등록
 * - `redirectExternally(payload)` — external_redirect 흐름 helper
 * - `createDeferred()` / `resolveDeferred(result)` — launcher 가 모달 결과를 await 하기 위한 deferred
 * - `hasLauncher()` / `reset()` — 진단/테스트용
 * - `redirectStashKey` — sessionStorage stash 키 상수
 *
 * @param G7Core - window.G7Core 네임스페이스
 */
function initIdentityAPI(G7Core: any): void {
  G7Core.identity = {
    /** 모달 launcher 등록 (템플릿 부트스트랩에서 호출) */
    setLauncher: IdentityGuardInterceptor.setLauncher.bind(IdentityGuardInterceptor),

    /** 등록된 launcher 가 있는지 여부 */
    hasLauncher: IdentityGuardInterceptor.hasLauncher.bind(IdentityGuardInterceptor),

    /** external_redirect 흐름 — sessionStorage stash + window.location 이동 */
    redirectExternally: IdentityGuardInterceptor.redirectExternally.bind(IdentityGuardInterceptor),

    /** launcher 가 모달 결과를 await 하기 위한 deferred Promise 생성 */
    createDeferred: IdentityGuardInterceptor.createDeferred.bind(IdentityGuardInterceptor),

    /** 모달/풀페이지가 verify 결과를 deferred resolver 에 통보 (resolveIdentityChallenge 핸들러 내부에서 사용) */
    resolveDeferred: IdentityGuardInterceptor.resolveDeferred.bind(IdentityGuardInterceptor),

    /** 테스트/진단용 — launcher 와 deferred 슬롯 초기화 */
    reset: IdentityGuardInterceptor.reset.bind(IdentityGuardInterceptor),

    /** sessionStorage stash 키 상수 */
    redirectStashKey: IDENTITY_REDIRECT_STASH_KEY,
  };

  logger.log('전역 객체 window.G7Core 본인인증(IDV) API에 노출됨 (identity)');
}

/**
 * G7Core 전역 객체를 초기화합니다.
 *
 * 템플릿 컴포넌트에서 사용할 수 있는 모든 전역 API를 window.G7Core에 노출합니다.
 *
 * @param deps - 초기화에 필요한 의존성 객체
 */
export function initializeG7CoreGlobals(deps: G7CoreDependencies): void {
  if (typeof window === 'undefined') {
    return;
  }

  // React 전역 노출
  initReactGlobals();

  // G7Core 네임스페이스가 없으면 생성 (TemplateApp.ts에서 이미 생성했을 수 있음)
  if (!(window as any).G7Core) {
    (window as any).G7Core = {};
  }

  const G7Core = (window as any).G7Core;

  // 각 API 그룹 초기화
  initComponentEventSystem(G7Core);
  initCoreAPIs(G7Core, deps);
  initTranslationAPI(G7Core, deps);
  initHelperAPIs(G7Core, deps);
  initDispatchAPI(G7Core);
  initStateAPI(G7Core);
  initDataSourceAPI(G7Core);
  initLocaleAPI(G7Core, deps);
  initToastAPI(G7Core);
  initModalAPI(G7Core);
  initStyleAPI(G7Core);
  initWebSocketAPI(G7Core, deps);
  initNavigationAPI(G7Core, deps);
  initPluginAPI(G7Core);
  initModuleAPI(G7Core);
  initSlotAPI(G7Core, deps);
  initWysiwygEditorAPI(G7Core);
  initIdentityAPI(G7Core);

  logger.log('G7Core 전역 객체 초기화 완료');

  // DevTools API는 initTemplateApp 이후에 초기화됨 (debug 옵션 확인 필요)
  // initDevToolsAPI는 reinitializeDevTools()를 통해 호출됨
}

/**
 * G7Core.devTools 인터페이스 초기화
 *
 * G7DevToolsCore.getInstance() 직접 호출 대신 통합 인터페이스를 통해 추적 기능에 접근합니다.
 * 템플릿 컴포넌트, 헬퍼 함수, 모듈에서 일관된 방식으로 DevTools에 접근할 수 있습니다.
 *
 * @example
 * ```ts
 * // renderItemChildren 등에서 사용
 * const G7Core = (window as any).G7Core;
 * if (G7Core?.devTools?.isEnabled()) {
 *   G7Core.devTools.trackIteration(id, source, itemVar, indexVar, itemCount);
 * }
 * ```
 */
export function initDevToolsInterface(): void {
  const G7Core = (window as any).G7Core;
  if (!G7Core) {
    logger.warn('G7Core가 초기화되지 않았습니다.');
    return;
  }

  const getDevTools = () => G7DevToolsCore.getInstance();

  const devToolsInterface: G7DevToolsInterface = {
    isEnabled: () => {
      try {
        return getDevTools().isEnabled();
      } catch {
        return false;
      }
    },

    trackRender: (componentName: string) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackRender(componentName);
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    trackIteration: (id, source, itemVar, indexVar, itemCount) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackIteration(id, {
            source,
            itemVar,
            indexVar,
            sourceLength: itemCount,
          });
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    trackIfCondition: (id, condition, result, componentName) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackIfCondition(id, condition, result);
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    trackMount: (componentId, info) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackMount(componentId, { ...info, id: componentId });
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    trackUnmount: (componentId) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackUnmount(componentId);
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    trackExpressionEval: (info) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackExpressionEval(info);
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    trackBindingEval: (expression?: string) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackBindingEval(expression);
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    recordCacheHit: () => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().recordCacheHit();
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    recordCacheMiss: () => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().recordCacheMiss();
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    trackHandlerRegistration: (name, category, description, source) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackHandlerRegistration(name, category, description, source);
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    trackHandlerUnregistration: (name) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackHandlerUnregistration(name);
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    logAction: (action) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().logAction(action);
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    trackRequest: (url, method) => {
      try {
        if (getDevTools().isEnabled()) {
          return getDevTools().trackRequest(url, method);
        }
        return '';
      } catch (e) {
        return '';
      }
    },

    completeRequest: (requestId, statusCode, response) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().completeRequest(requestId, statusCode, response);
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    failRequest: (requestId, error) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().failRequest(requestId, error);
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    trackDataSourceDefinition: (info) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackDataSourceDefinition(info);
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    trackDataSourceLoading: (id) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackDataSourceLoading(id);
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    trackDataSourceLoaded: (id, data, dataPath) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackDataSourceLoaded(id, data, dataPath);
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    trackDataSourceError: (id, error) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackDataSourceError(id, error);
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    trackForm: (id, dataKey, inputs) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackForm(id, dataKey, inputs);
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    untrackForm: (id) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().untrackForm(id);
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    startStateChange: (statePath, oldValue, newValue, trigger) => {
      try {
        if (getDevTools().isEnabled()) {
          return getDevTools().startStateChange(statePath, oldValue, newValue, trigger);
        }
        return '';
      } catch (e) {
        return '';
      }
    },

    completeStateChange: (setStateId) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().completeStateChange(setStateId);
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    trackComponentRender: (componentId, componentName, renderDuration, accessedStatePaths, evaluatedBindings, parentId) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackComponentRender(
            componentId,
            componentName,
            renderDuration,
            accessedStatePaths,
            evaluatedBindings,
            parentId
          );
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    updateLocalState: (localState) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().updateLocalState(localState);
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    updateComputedState: (computedState) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().updateComputedState(computedState);
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    updateParentContext: (parentContext) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().updateParentContext(parentContext);
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    trackComponentStateSource: (componentId, componentName, stateSource, stateProvider) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackComponentStateSource(componentId, componentName, stateSource, stateProvider);
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    trackDynamicState: (componentId, dynamicState) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackDynamicState(componentId, dynamicState);
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    trackContextFlow: (componentId, componentName, contextReceived, passedToChildren, usedInRender, parentId) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackContextFlow(componentId, componentName, contextReceived, passedToChildren, usedInRender, parentId);
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    trackComponentStyle: (componentId, componentName, classes, computedStyles) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackComponentStyle(componentId, componentName, classes, computedStyles);
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    trackAuthEvent: (type, success, error, details) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackAuthEvent(type, success, error, details);
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    trackAuthHeader: (url, hasAuthHeader, headerType, tokenValid, responseStatus) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackAuthHeader(url, hasAuthHeader, headerType, tokenValid, responseStatus);
        }
      } catch (e) {
        // DevTools 추적 실패해도 렌더링은 계속
      }
    },

    trackLog: (level, prefix, args) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackLog(level, prefix, args);
        }
      } catch {
        // DevTools 추적 실패해도 로깅은 계속
      }
    },

    trackAction: (info) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackAction?.(info);
        }
      } catch (e) {
        // DevTools 추적 실패해도 액션 실행은 계속
      }
    },

    trackDataSourceUpdate: (info) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackDataSourceUpdate?.(info);
        }
      } catch (e) {
        // DevTools 추적 실패해도 업데이트는 계속
      }
    },

    // ============================================
    // Sequence 실행 추적 메서드
    // ============================================

    startSequenceExecution: (trigger) => {
      try {
        if (getDevTools().isEnabled()) {
          return getDevTools().startSequenceExecution(trigger);
        }
        return '';
      } catch (e) {
        return '';
      }
    },

    captureSequenceActionBefore: (sequenceId, actionIndex, handler, params, currentState) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().captureSequenceActionBefore(sequenceId, actionIndex, handler, params, currentState);
        }
      } catch (e) {
        // DevTools 추적 실패해도 실행은 계속
      }
    },

    captureSequenceActionAfter: (sequenceId, actionIndex, currentState, duration, result, error) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().captureSequenceActionAfter(sequenceId, actionIndex, currentState, duration, result, error);
        }
      } catch (e) {
        // DevTools 추적 실패해도 실행은 계속
      }
    },

    endSequenceExecution: (sequenceId, error) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().endSequenceExecution(sequenceId, error);
        }
      } catch (e) {
        // DevTools 추적 실패해도 실행은 계속
      }
    },

    // ============================================
    // Stale Closure 감지 메서드
    // ============================================

    registerStateCaptureForHandler: (handlerId, statePaths, stateValues) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().registerStateCaptureForHandler(handlerId, statePaths, stateValues);
        }
      } catch (e) {
        // DevTools 추적 실패해도 실행은 계속
      }
    },

    detectStaleClosure: (handlerId, location, currentState, warningType, actionId) => {
      try {
        if (getDevTools().isEnabled()) {
          return getDevTools().detectStaleClosure(handlerId, location, currentState, warningType, actionId);
        }
        return [];
      } catch (e) {
        return [];
      }
    },

    trackStaleClosureWarning: (info) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackStaleClosureWarning(info);
        }
      } catch (e) {
        // DevTools 추적 실패해도 실행은 계속
      }
    },

    // ============================================
    // Modal State Scope 추적 메서드
    // ============================================

    trackModalOpen: (options) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackModalOpen(options);
        }
      } catch (e) {
        // DevTools 추적 실패해도 실행은 계속
      }
    },

    trackModalClose: (modalId, finalState) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackModalClose(modalId, finalState);
        }
      } catch (e) {
        // DevTools 추적 실패해도 실행은 계속
      }
    },

    trackModalStateChange: (options) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackModalStateChange(options);
        }
      } catch (e) {
        // DevTools 추적 실패해도 실행은 계속
      }
    },

    // ============================================
    // Nested Context 추적 메서드
    // ============================================

    trackNestedContext: (info) => {
      try {
        if (getDevTools().isEnabled()) {
          return getDevTools().trackNestedContext(info);
        }
        return '';
      } catch (e) {
        return '';
      }
    },

    trackNestedContextAccess: (contextId, access) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackNestedContextAccess(contextId, access);
        }
      } catch (e) {
        // DevTools 추적 실패해도 실행은 계속
      }
    },

    // ============================================
    // Computed 추적 메서드
    // ============================================

    trackComputedProperty: (name, expression, dependencies, value, computationTime, componentId, error) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackComputedProperty(name, expression, dependencies as any, value, computationTime, componentId, error);
        }
      } catch (e) {
        // DevTools 추적 실패해도 실행은 계속
      }
    },

    trackComputedRecalc: (computedName, trigger, previousValue, newValue, computationTime, triggeredBy, componentId) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackComputedRecalc(computedName, trigger as any, previousValue, newValue, computationTime, triggeredBy as any, componentId);
        }
      } catch (e) {
        // DevTools 추적 실패해도 실행은 계속
      }
    },

    setNamedActionDefinitions: (definitions) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().setNamedActionDefinitions(definitions);
        }
      } catch (e) {
        // DevTools 추적 실패해도 실행은 계속
      }
    },

    trackNamedActionRef: (log) => {
      try {
        if (getDevTools().isEnabled()) {
          getDevTools().trackNamedActionRef(log);
        }
      } catch (e) {
        // DevTools 추적 실패해도 실행은 계속
      }
    },
  };

  // G7Core.devTools에 인터페이스 할당
  G7Core.devTools = devToolsInterface;

  logger.log('G7Core.devTools 인터페이스 초기화 완료');
}

/**
 * G7 DevTools API 초기화
 *
 * 디버깅 및 개발 도구 API를 window.G7DevTools에 노출합니다.
 * 환경설정의 debug_mode가 활성화된 경우에만 전체 기능이 활성화됩니다.
 *
 * initTemplateApp에서 debug 옵션 설정 후 호출해야 합니다.
 *
 * @example
 * ```ts
 * // 상태 조회
 * G7DevTools.state.get()
 *
 * // 액션 이력 조회
 * G7DevTools.actions.getHistory()
 *
 * // 자동 진단
 * G7DevTools.diagnose.analyze(['이전 값이 전송됨'])
 *
 * // 서버로 상태 덤프
 * G7DevTools.server.dumpState()
 * ```
 */
export function initDevToolsAPI(): void {
  const G7Core = (window as any).G7Core;
  if (!G7Core) {
    logger.warn('G7Core가 초기화되지 않았습니다.');
    return;
  }
  const devToolsCore = G7DevToolsCore.getInstance();
  devToolsCore.initialize();

  // DevTools가 비활성화된 경우 최소 API만 노출
  if (!devToolsCore.isEnabled()) {
    (window as any).G7DevTools = {
      isEnabled: () => false,
      enable: () => {
        logger.log('환경설정 > 고급 설정 > 디버그 모드를 켜세요');
      },
    };
    // 비활성화 상태에서도 버퍼 정리 (보안)
    flushEarlyLogs();
    logger.log('G7DevTools 비활성화됨 (디버그 모드 꺼짐)');
    return;
  }

  // G7Core.devTools 인터페이스 초기화 (renderItemChildren 등에서 사용)
  initDevToolsInterface();

  // 초기화 전에 버퍼링된 에러/경고 로그 flush
  flushEarlyLogs();

  // StyleTracker 활성화 (DOM 스타일 이상 감지)
  try {
    const styleTracker = getStyleTracker();
    styleTracker.enable();
    logger.log('StyleTracker 활성화됨');
  } catch (error) {
    logger.warn('StyleTracker 활성화 실패:', error);
  }

  const diagnosticEngine = new DiagnosticEngine();
  const serverConnector = getServerConnector();

  const G7DevTools = {
    // === 활성화 상태 ===
    isEnabled: () => devToolsCore.isEnabled(),

    // === 상태 관리 ===
    state: {
      /**
       * 현재 상태를 조회합니다.
       * @returns { _global, _local, _computed }
       */
      get: () => devToolsCore.getState(),
      /**
       * 상태 변경 이력을 조회합니다.
       * @returns StateSnapshot[]
       */
      getHistory: () => devToolsCore.getStateHistory(),
      /**
       * 상태 변경을 구독합니다.
       * @param path 감시할 경로 ('*'는 모든 변경)
       * @param callback 변경 시 호출될 콜백
       * @returns 구독 해제 함수
       */
      watch: (path: string, callback: (newVal: any, oldVal: any) => void) =>
        devToolsCore.watchState(path, callback),
    },

    // === 액션 추적 ===
    actions: {
      /**
       * 액션 실행 이력을 조회합니다.
       * @returns ActionLog[]
       */
      getHistory: () => devToolsCore.getActionHistory(),
      /**
       * 액션 실행을 구독합니다.
       * @param callback 액션 실행 시 호출될 콜백
       * @returns 구독 해제 함수
       */
      watch: (callback: (action: any) => void) => devToolsCore.watchActions(callback),
      /**
       * 액션 실행 메트릭을 조회합니다.
       * @returns ActionMetrics
       */
      getMetrics: () => devToolsCore.getActionMetrics(),
    },

    // === 바인딩 & 캐시 ===
    binding: {
      /**
       * 표현식을 평가합니다.
       * @param expr 평가할 표현식
       * @returns 평가 결과
       */
      evaluate: (expr: string) => {
        const engine = G7Core.getDataBindingEngine?.();
        if (!engine) {
          logger.warn('DataBindingEngine이 없습니다.');
          return undefined;
        }
        return engine.evaluateExpression(expr, devToolsCore.getState());
      },
      /**
       * 캐시 통계를 조회합니다.
       * @returns CacheStats
       */
      getCacheStats: () => devToolsCore.getCacheStats(),
      /**
       * 캐시 통계를 초기화합니다.
       */
      clearStats: () => {
        devToolsCore.resetCacheStats();
        logger.log('캐시 통계 초기화됨');
      },
    },

    // === 자동 진단 ===
    diagnose: {
      /**
       * 증상을 기반으로 문제를 진단합니다.
       * @param symptoms 증상 배열
       * @returns DiagnosisResult[]
       */
      analyze: (symptoms: string[]) => diagnosticEngine.analyze(symptoms),
      /**
       * 진단 결과에 대한 수정 제안을 반환합니다.
       * @param diagnosis 진단 결과
       * @returns FixSuggestion
       */
      suggestFix: (diagnosis: any) => diagnosticEngine.suggestFix(diagnosis),
      /**
       * 자주 발생하는 문제 목록을 반환합니다.
       * @returns CommonIssue[]
       */
      getCommonIssues: () => diagnosticEngine.getCommonIssues(),
      /**
       * 카테고리별 진단 규칙을 조회합니다.
       * @param category 카테고리
       * @returns DiagnosticRule[]
       */
      getRulesByCategory: (category: DiagnosticCategory) => diagnosticEngine.getRulesByCategory(category),
    },

    // === 서버 통합 ===
    server: {
      /**
       * 현재 상태를 서버로 덤프합니다.
       * @param saveHistory 이력 파일도 저장할지 여부
       * @returns 저장된 파일 경로
       */
      dumpState: (saveHistory?: boolean) => serverConnector.dumpState(saveHistory),
      /**
       * 디버그 로그를 서버로 전송합니다.
       * @param data 로그 데이터
       */
      sendLog: (data: any) => serverConnector.sendLog(data),
      /**
       * 에러 정보를 서버로 전송합니다.
       * @param error 에러 객체
       * @param context 추가 컨텍스트
       */
      sendError: (error: Error, context?: Record<string, any>) =>
        serverConnector.sendError(error, context),
      /**
       * 서버 연결 상태를 확인합니다.
       * @returns 연결 상태
       */
      isConnected: () => serverConnector.isConnected(),
      /**
       * 서버 연결을 테스트합니다.
       * @returns 연결 성공 여부
       */
      testConnection: () => serverConnector.testConnection(),
    },

    // === 설정 ===
    config: {
      /**
       * 디버그 모드 상태를 확인합니다.
       * @returns 디버그 모드 활성화 여부
       */
      isDebugMode: () => devToolsCore.isEnabled(),
      /**
       * 로그 레벨을 설정합니다.
       * @param level 로그 레벨
       */
      setLogLevel: (level: 'debug' | 'info' | 'warn' | 'error') =>
        devToolsCore.setLogLevel(level),
      /**
       * 최대 이력 개수를 설정합니다.
       * @param count 최대 이력 개수
       */
      setMaxHistory: (count: number) => devToolsCore.setMaxHistory(count),
    },

    // === 라이프사이클 ===
    lifecycle: {
      /**
       * 마운트된 컴포넌트 목록을 조회합니다.
       * @returns ComponentInfo[]
       */
      getMountedComponents: () => devToolsCore.getLifecycleInfo().mountedComponents,
      /**
       * 정리되지 않은 이벤트 리스너를 조회합니다.
       * @returns ListenerInfo[]
       */
      getOrphanedListeners: () => devToolsCore.getLifecycleInfo().orphanedListeners,
    },

    // === 성능 ===
    performance: {
      /**
       * 렌더링 횟수를 조회합니다.
       * @returns Map<string, number>
       */
      getRenderCount: () => devToolsCore.getPerformanceInfo().renderCounts,
      /**
       * 바인딩 평가 횟수를 조회합니다.
       * @returns number
       */
      getBindingEvalCount: () => devToolsCore.getPerformanceInfo().bindingEvalCount,
      /**
       * 메모리 경고를 조회합니다.
       * @returns MemoryWarning[]
       */
      getMemoryWarnings: () => devToolsCore.getPerformanceInfo().memoryWarnings,
      /**
       * 프로파일링을 시작합니다.
       */
      startProfiling: () => devToolsCore.startProfiling(),
      /**
       * 프로파일링을 중지하고 결과를 반환합니다.
       * @returns ProfileReport
       */
      stopProfiling: () => devToolsCore.stopProfiling(),
    },

    // === 네트워크 ===
    network: {
      /**
       * 진행 중인 요청을 조회합니다.
       * @returns RequestInfo[]
       */
      getActiveRequests: () => devToolsCore.getNetworkInfo().activeRequests,
      /**
       * 요청 이력을 조회합니다.
       * @returns RequestLog[]
       */
      getRequestHistory: () => devToolsCore.getNetworkInfo().requestHistory,
      /**
       * 대기 중인 데이터소스를 조회합니다.
       * @returns string[]
       */
      getPendingDataSources: () => devToolsCore.getNetworkInfo().pendingDataSources,
    },

    // === 조건부 렌더링 ===
    conditional: {
      /**
       * if 조건 목록을 조회합니다.
       * @returns ConditionInfo[]
       */
      getIfConditions: () => devToolsCore.getConditionalInfo().ifConditions,
      /**
       * iteration 목록을 조회합니다.
       * @returns IterationInfo[]
       */
      getIterations: () => devToolsCore.getConditionalInfo().iterations,
    },

    // === Form ===
    form: {
      /**
       * 추적된 Form 목록을 조회합니다.
       * @returns FormInfo[]
       */
      getForms: () => devToolsCore.getFormInfo(),
    },

    // === WebSocket ===
    websocket: {
      /**
       * WebSocket 정보를 조회합니다.
       * @returns WebSocketInfo
       */
      getInfo: () => devToolsCore.getWebSocketInfo(),
    },
  };

  // 전역 노출
  (window as any).G7DevTools = G7DevTools;

  // G7Core에도 DevTools 참조 추가
  G7Core.devtools = G7DevTools;

  logger.log('G7DevTools 전역 객체 초기화 완료 (window.G7DevTools)');

  // DevToolsPanel UI 렌더링
  renderDevToolsPanel();
}

/**
 * DevToolsPanel UI를 DOM에 렌더링합니다.
 *
 * 별도의 DOM 컨테이너를 생성하여 메인 앱과 독립적으로 렌더링합니다.
 * 디버그 모드가 활성화된 경우에만 렌더링됩니다.
 */
function renderDevToolsPanel(): void {
  // 이미 렌더링되어 있으면 스킵
  if (document.getElementById('g7-devtools-root')) {
    logger.log('DevToolsPanel 이미 렌더링됨');
    return;
  }

  // DOM 컨테이너 생성
  const container = document.createElement('div');
  container.id = 'g7-devtools-root';
  document.body.appendChild(container);

  // React root 생성 및 DevToolsPanel 렌더링
  try {
    const root = ReactDOM.createRoot(container);
    root.render(React.createElement(DevToolsPanel));
    logger.log('DevToolsPanel UI 렌더링 완료');
  } catch (error) {
    logger.error('DevToolsPanel UI 렌더링 실패:', error);
  }
}
