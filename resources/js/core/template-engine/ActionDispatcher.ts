/**
 * NOTE: @since versions (engine-v1.x.x) refer to internal template engine
 * development iterations, not G7 platform release versions.
 */
/**
 * ActionDispatcher.ts
 *
 * 그누보드7 템플릿 엔진의 이벤트 핸들러 관리 및 액션 실행 엔진
 *
 * 주요 기능:
 * - 이벤트 핸들러 바인딩 (onClick, onChange 등)
 * - 액션 타입별 실행 (navigate, apiCall, setState 등)
 * - 파라미터 바인딩 및 {{}} 변수 치환
 * - React Router 및 상태 관리 통합
 *
 * @module ActionDispatcher
 */

import { DataBindingEngine } from './DataBindingEngine';
import { resolveExpressionString } from './helpers/RenderHelpers';
import { TranslationEngine, TranslationContext } from './TranslationEngine';
import { AuthManager, AuthType } from '../auth/AuthManager';
import { getApiClient } from '../api/ApiClient';
import { getErrorHandlingResolver } from '../error';
import type { ErrorHandlerConfig, ErrorContext } from '../types/ErrorHandling';
import { createLogger } from '../utils/Logger';
import type { G7DevToolsInterface } from './G7CoreGlobals';
import { evaluateConditionBranches } from './helpers/ConditionEvaluator';
import { triggerModalParentUpdate } from './ParentContextProvider';
import type { GlobalHeaderRule } from './LayoutLoader';

const logger = createLogger('ActionDispatcher');

/**
 * 프리뷰 모드에서 억제되는 핸들러 목록
 *
 * 프리뷰 페이지 이탈 또는 현재 세션 변경을 유발하는 핸들러를 정의합니다.
 * 이 목록에 포함된 핸들러는 프리뷰 모드에서 실행되지 않고 로그만 남깁니다.
 *
 * @since engine-v1.26.1
 */
const PREVIEW_SUPPRESSED_HANDLERS: ReadonlySet<string> = new Set([
    // 직접 네비게이션 — 현재 페이지를 떠남
    'navigate',
    'navigateBack',
    'navigateForward',
    'replaceUrl',

    // 간접 네비게이션 — 페이지 리로드 또는 리다이렉트 유발
    'refresh',          // window.location.reload()
    'logout',           // 로그아웃 후 로그인 페이지로 리다이렉트
]);

/**
 * 프리뷰 모드에서 억제되는 레이아웃 기능 목록
 *
 * 레이아웃 JSON의 최상위 기능 중 프리뷰 모드에서 비활성화할 대상입니다.
 * redirect 속성 등 라우트 레벨에서 페이지 이탈을 유발하는 기능을 정의합니다.
 *
 * @since engine-v1.26.1
 */
const PREVIEW_SUPPRESSED_LAYOUT_FEATURES: ReadonlySet<string> = new Set([
    'redirect',         // 라우트 리다이렉트 속성
]);

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

// ============================================================================
// 타입 정의
// ============================================================================

/**
 * 액션 타입
 */
export type ActionType =
  | 'navigate' // 페이지 이동
  | 'navigateBack' // 브라우저 뒤로가기
  | 'navigateForward' // 브라우저 앞으로가기
  | 'replaceUrl' // URL만 변경 (데이터소스 refetch 없음)
  | 'apiCall' // API 호출
  | 'login' // 로그인 (토큰 저장 포함)
  | 'logout' // 로그아웃
  | 'setState' // 상태 변경
  | 'setError' // 에러 상태 설정
  | 'openModal' // 모달 열기
  | 'closeModal' // 모달 닫기
  | 'showAlert' // 알림 표시
  | 'toast' // 토스트 알림 표시
  | 'switch' // 조건 분기 처리
  | 'conditions' // 조건 분기 처리 (AND/OR 그룹, if-else 체인 지원)
  | 'sequence' // 순차 액션 실행
  | 'parallel' // 병렬 액션 실행
  | 'showErrorPage' // 에러 페이지 표시
  | 'loadScript' // 외부 스크립트 동적 로드
  | 'callExternal' // 외부 스크립트 생성자/메서드 호출
  | 'callExternalEmbed' // 외부 스크립트를 레이어 모드로 임베드
  | 'saveToLocalStorage' // 로컬스토리지에 저장
  | 'loadFromLocalStorage' // 로컬스토리지에서 불러오기
  | 'scrollIntoView' // 특정 요소로 스크롤
  | 'custom'; // 사용자 정의 액션

/**
 * 이벤트 타입
 */
export type EventType =
  | 'click'
  | 'change'
  | 'input'
  | 'submit'
  | 'focus'
  | 'blur'
  | 'keydown'
  | 'keyup'
  | 'keypress'
  | 'mousedown'
  | 'mouseup'
  | 'mouseenter'
  | 'mouseleave'
  | 'scroll'
  // 드래그 앤 드롭 이벤트
  | 'dragstart'
  | 'drag'
  | 'dragend'
  | 'dragenter'
  | 'dragover'
  | 'dragleave'
  | 'drop'
  // sortable 이벤트 (@dnd-kit 기반)
  | 'onSortStart'
  | 'onSortEnd'
  | 'onSortOver';

/**
 * 액션 정의
 */
export interface ActionDefinition {
  /** 이벤트 타입 */
  type: EventType;
  /** 액션 핸들러 이름 */
  handler: ActionType | string;
  /** 커스텀 이벤트 핸들러 이름 (예: "onNavigate") - 지정 시 type 대신 사용됨 */
  event?: string;
  /** 액션 타겟 (URL, API 엔드포인트 등) */
  target?: string;
  /** 액션 파라미터 */
  params?: Record<string, any>;
  /** 액션 성공 시 실행할 후속 액션 (단일 또는 배열) */
  onSuccess?: ActionDefinition | ActionDefinition[];
  /** 액션 실패 시 실행할 후속 액션 (단일 또는 배열) - errorHandling에 매칭되지 않을 때 실행 */
  onError?: ActionDefinition | ActionDefinition[];
  /**
   * HTTP 에러 코드별 핸들러 매핑
   *
   * onError보다 우선순위가 높습니다.
   * - errorHandling[코드] → errorHandling[default] → onError 순으로 확인
   *
   * @example
   * ```json
   * {
   *   "errorHandling": {
   *     "403": { "handler": "showErrorPage", "params": { "target": "content" } },
   *     "422": { "handler": "toast", "params": { "type": "error", "message": "{{error.message}}" } },
   *     "default": { "handler": "toast", "params": { "type": "error", "message": "{{error.message}}" } }
   *   }
   * }
   * ```
   */
  errorHandling?: import('../types/ErrorHandling').ErrorHandlingMap;
  /** 확인 메시지 표시 여부 */
  confirm?: string;
  /** 키보드 이벤트에서 특정 키 필터링 (예: "Enter", "Escape") */
  key?: string;
  /** switch 핸들러용 케이스 정의 - $args[0] 값을 기준으로 분기 */
  cases?: Record<string, ActionDefinition>;
  /** sequence 핸들러용 순차 실행 액션 배열 */
  actions?: ActionDefinition[];
  /** 인증 필요 여부 (apiCall 핸들러에서 Bearer 토큰 포함 여부) - auth_mode 사용 권장 */
  auth_required?: boolean;
  /**
   * 인증 모드 (apiCall 핸들러)
   * - 'none': 토큰 미포함 (기본값)
   * - 'required': 토큰 필수 (없으면 에러)
   * - 'optional': 토큰이 있으면 포함, 없으면 미포함
   */
  auth_mode?: 'none' | 'required' | 'optional';
  /** 조건부 실행 - 표현식이 true일 때만 액션 실행 */
  if?: string;
  /**
   * conditions 핸들러용 조건 브랜치 배열
   *
   * if/else if/else 체인을 정의합니다. 첫 번째 매칭되는 브랜치의 then 액션이 실행됩니다.
   *
   * @example
   * ```json
   * {
   *   "handler": "conditions",
   *   "conditions": [
   *     {
   *       "if": "{{$args[0] === 'edit'}}",
   *       "then": { "handler": "navigate", "params": { "path": "/edit/{{row.id}}" } }
   *     },
   *     {
   *       "if": "{{$args[0] === 'delete'}}",
   *       "then": [
   *         { "handler": "setState", "params": { "target": "_local", "deleteTargetId": "{{row.id}}" } },
   *         { "handler": "openModal", "params": { "id": "delete_confirm_modal" } }
   *       ]
   *     },
   *     {
   *       "then": { "handler": "toast", "params": { "message": "알 수 없는 액션" } }
   *     }
   *   ]
   * }
   * ```
   *
   * @since engine-v1.10.0
   */
  conditions?: import('./helpers/ConditionEvaluator').ConditionBranch[];
  /**
   * loadScript 핸들러 - 스크립트 로드 완료 후 실행할 액션
   *
   * @example
   * ```json
   * {
   *   "handler": "loadScript",
   *   "params": {
   *     "src": "//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js",
   *     "id": "daum_postcode_script"
   *   },
   *   "onLoad": { "handler": "setState", "params": { "daumPostcodeLoaded": true } }
   * }
   * ```
   */
  onLoad?: ActionDefinition;
  /**
   * callExternal 핸들러 - 외부 스크립트 콜백 이벤트명
   *
   * oncomplete 등의 콜백이 호출되면 이 이벤트를 발생시킵니다.
   * G7Core.componentEvent.on()으로 구독할 수 있습니다.
   *
   * @example
   * ```json
   * {
   *   "handler": "callExternal",
   *   "params": {
   *     "constructor": "daum.Postcode",
   *     "args": { "oncomplete": true },
   *     "callbackEvent": "postcode:complete",
   *     "method": "open"
   *   }
   * }
   * ```
   */
  callbackEvent?: string;
  /**
   * 핸들러 실행 결과를 상태에 저장
   *
   * 핸들러가 반환하는 값을 지정된 상태 키에 저장합니다.
   * 동적 컬럼 생성, 계산 결과 저장 등에 활용됩니다.
   *
   * @example
   * ```json
   * {
   *   "handler": "sirsoft-ecommerce.buildProductColumns",
   *   "params": {
   *     "baseColumns": [...],
   *     "currencies": "{{ecommerceSettings?.language_currency?.currencies}}"
   *   },
   *   "resultTo": {
   *     "target": "_local",
   *     "key": "productColumns"
   *   }
   * }
   * ```
   */
  resultTo?: {
    /** 저장 대상: "_local" (로컬 상태), "_global" (전역 상태), "_isolated" (격리된 상태) */
    target: '_local' | '_global' | '_isolated';
    /** 상태 키 (dot notation 지원, 예: "calculatedPrices.{{option.id}}") */
    key: string;
    /** 병합 모드: "replace" | "shallow" | "deep" (기본값: "deep") */
    merge?: 'replace' | 'shallow' | 'deep';
  };
  /**
   * 액션 실행 지연 (debounce)
   *
   * 연속 호출 시 마지막 호출만 실행합니다.
   * 숫자: delay ms (기본 설정 적용)
   * 객체: 상세 설정
   *
   * @example
   * ```json
   * // 간단한 형태 (300ms 지연)
   * { "type": "change", "handler": "updateField", "debounce": 300 }
   *
   * // 상세 설정
   * {
   *   "type": "change",
   *   "handler": "updateField",
   *   "debounce": {
   *     "delay": 500,
   *     "leading": false,
   *     "trailing": true
   *   }
   * }
   * ```
   */
  debounce?:
    | number
    | {
        /** 지연 시간 (ms) */
        delay: number;
        /** 첫 호출 즉시 실행 여부 (기본: false) */
        leading?: boolean;
        /** 마지막 호출 후 실행 여부 (기본: true) */
        trailing?: boolean;
      };
  /**
   * named_actions에 정의된 액션을 참조
   *
   * named_actions의 키를 지정하면 해당 액션 정의(handler, params 등)를 가져옵니다.
   * type, key, event 등 이벤트 바인딩 속성은 개별 지정합니다.
   * actionRef와 인라인 handler/params가 동시에 있으면 인라인이 우선합니다.
   *
   * @example
   * ```json
   * {
   *   "type": "keypress",
   *   "key": "Enter",
   *   "actionRef": "searchProducts"
   * }
   * ```
   *
   * @since engine-v1.19.0
   */
  actionRef?: string;
}

/**
 * Navigate 옵션 (React Router NavigateOptions 호환)
 */
export interface NavigateOptions {
  /** true이면 히스토리를 교체 (뒤로가기 불가) */
  replace?: boolean;
  /** 전달할 상태 데이터 */
  state?: any;
}

/**
 * 격리된 상태 컨텍스트 인터페이스
 *
 * isolatedState 속성이 있는 컴포넌트에서 IsolatedStateProvider를 통해 제공됩니다.
 */
export interface IsolatedContextValue {
  /** 현재 격리된 상태 */
  state: Record<string, any>;
  /** 특정 경로의 상태를 설정 */
  setState: (path: string, value: any) => void;
  /** 특정 경로의 상태를 조회 */
  getState: (path: string) => any;
  /** 상태를 병합 모드에 따라 업데이트 */
  mergeState: (updates: Record<string, any>, mergeMode?: 'replace' | 'shallow' | 'deep') => void;
}

/**
 * 액션 컨텍스트
 */
export interface ActionContext {
  /** 데이터 컨텍스트 */
  data?: any;
  /** 이벤트 객체 */
  event?: Event;
  /** 컴포넌트 props */
  props?: Record<string, any>;
  /** 현재 상태 */
  state?: any;
  /** 상태 업데이트 함수 */
  setState?: (updates: any) => void;
  /** React Router navigate 함수 */
  navigate?: (path: string, options?: NavigateOptions) => void;
  /**
   * 격리된 상태 컨텍스트
   *
   * isolatedState 속성이 있는 컴포넌트에서 자동으로 주입됩니다.
   * target: "isolated"로 setState 핸들러 호출 시 사용됩니다.
   */
  isolatedContext?: IsolatedContextValue | null;
}

/**
 * 로딩 상태 맵 타입
 * 액션 ID를 키로, 로딩 여부를 값으로 가지는 객체
 */
export type LoadingActionsMap = Record<string, boolean>;

/**
 * 액션 핸들러 함수 타입
 */
export type ActionHandler = (
  action: ActionDefinition,
  context: ActionContext
) => void | Promise<void>;

/**
 * 액션 실행 결과
 */
export interface ActionResult {
  success: boolean;
  data?: any;
  error?: Error;
}

/**
 * 액션 에러 클래스
 */
export class ActionError extends Error {
  constructor(
    message: string,
    public action?: ActionDefinition,
    public originalError?: Error
  ) {
    super(message);
    this.name = 'ActionError';
  }
}

// ============================================================================
// ActionDispatcher 클래스
// ============================================================================

/**
 * 이벤트 핸들러 관리 및 액션 실행 엔진
 *
 * @example
 * const dispatcher = new ActionDispatcher({ navigate, setState });
 * const handler = dispatcher.createHandler(actionDef, dataContext);
 * <button onClick={handler}>클릭</button>
 */
export class ActionDispatcher {
  /** 데이터 바인딩 엔진 */
  private bindingEngine: DataBindingEngine;

  /** 번역 엔진 */
  private translationEngine?: TranslationEngine;

  /** 번역 컨텍스트 */
  private translationContext?: TranslationContext;

  /** 커스텀 액션 핸들러 맵 */
  private customHandlers: Map<string, ActionHandler> = new Map();

  /** 기본 컨텍스트 */
  private defaultContext: Partial<ActionContext>;

  /** 전역 상태 업데이트 함수 */
  private globalStateUpdater?: (updates: any) => void;

  /** ErrorHandlingResolver 연동 여부 */
  private errorHandlingSetup: boolean = false;

  /**
   * Debounce 타이머 저장소
   *
   * 컴포넌트별, 액션별로 debounce 타이머를 관리합니다.
   * Key: `${componentId}-${handler}-${eventName}`
   */
  private debounceTimers: Map<string, ReturnType<typeof setTimeout>> = new Map();

  /**
   * 대기 중인 debounce 액션의 즉시 실행 함수 맵
   *
   * 비디바운스 액션 실행 전에 대기 중인 debounce 액션을 즉시 실행(flush)하여
   * state가 최신 상태가 되도록 합니다.
   * Key 형식은 debounceTimers와 동일합니다.
   */
  private pendingDebounceFlushers: Map<string, () => void> = new Map();

  /**
   * 디바운스 대기 중 누적된 객체 값
   *
   * _changedKeys 메타데이터가 포함된 이벤트의 변경분을 누적합니다.
   * 이를 통해 다국어 입력 등 객체 값의 stale closure로 인한 키 유실을 방지합니다.
   * Key: debounceKey
   * @since engine-v1.28.0
   */
  private debounceAccumulatedValues: Map<string, Record<string, any>> = new Map();

  /**
   * 전역 헤더 규칙 (레이아웃에서 설정)
   *
   * apiCall 핸들러 호출 시 패턴에 매칭되는 엔드포인트에 자동으로 헤더를 추가합니다.
   *
   * @since engine-v1.16.0
   */
  private globalHeaders: GlobalHeaderRule[] = [];

  /**
   * 명명된 액션 정의 (레이아웃에서 설정)
   *
   * 레이아웃 JSON의 named_actions 섹션에서 정의된 재사용 가능한 액션입니다.
   * 컴포넌트의 actionRef 속성으로 참조할 수 있습니다.
   *
   * @since engine-v1.19.0
   */
  private namedActions: Record<string, ActionDefinition> = {};

  /**
   * 프리뷰 모드 여부
   *
   * 프리뷰 모드에서는 PREVIEW_SUPPRESSED_HANDLERS에 정의된 핸들러가
   * 실행되지 않고 로그만 남깁니다.
   *
   * @since engine-v1.26.1
   */
  private previewMode: boolean = false;

  /**
   * ActionDispatcher 생성자
   */
  constructor(
    defaultContext: Partial<ActionContext> = {},
    translationEngine?: TranslationEngine,
    translationContext?: TranslationContext
  ) {
    this.bindingEngine = new DataBindingEngine();
    this.translationEngine = translationEngine;
    this.translationContext = translationContext;
    this.defaultContext = defaultContext;

    // 기본 핸들러 등록
    this.registerDefaultHandlers();

    // ErrorHandlingResolver에 액션 실행기 등록
    this.setupErrorHandling();

  }

  /**
   * 전역 헤더 규칙을 설정합니다.
   *
   * 레이아웃 로드 시 호출되어 globalHeaders를 설정합니다.
   * 이후 apiCall 핸들러에서 패턴 매칭을 통해 자동으로 헤더가 적용됩니다.
   *
   * @param headers 전역 헤더 규칙 배열
   * @since engine-v1.16.0
   */
  public setGlobalHeaders(headers: GlobalHeaderRule[]): void {
    this.globalHeaders = headers || [];
  }

  /**
   * 명명된 액션 정의를 설정합니다.
   *
   * 레이아웃 로드 시 호출되어 namedActions를 설정합니다.
   * 이후 컴포넌트의 actionRef 속성으로 참조할 수 있습니다.
   *
   * @param actions 명명된 액션 정의 맵
   * @since engine-v1.19.0
   */
  public setNamedActions(actions: Record<string, ActionDefinition>): void {
    this.namedActions = actions || {};

    // DevTools: named_actions 정의 등록
    const devTools = getDevTools();
    if (devTools?.isEnabled?.()) {
      devTools.setNamedActionDefinitions?.(this.namedActions);
    }

    logger.log('[setNamedActions] registered:', Object.keys(this.namedActions));
  }

  /**
   * 현재 등록된 named_actions 정의를 반환합니다.
   *
   * @returns 명명된 액션 정의 맵
   * @since engine-v1.19.0
   */
  public getNamedActions(): Record<string, ActionDefinition> {
    return this.namedActions;
  }

  /**
   * 프리뷰 모드를 설정합니다.
   *
   * 프리뷰 모드에서는 PREVIEW_SUPPRESSED_HANDLERS에 정의된 핸들러가
   * 실행되지 않고 경고 로그만 남깁니다.
   *
   * @param enabled 프리뷰 모드 활성화 여부
   * @since engine-v1.26.1
   */
  public setPreviewMode(enabled: boolean): void {
    this.previewMode = enabled;
    logger.log('Preview mode:', enabled ? 'enabled' : 'disabled');
  }

  /**
   * 현재 프리뷰 모드 여부를 반환합니다.
   *
   * @returns 프리뷰 모드 활성화 여부
   * @since engine-v1.26.1
   */
  public isPreviewMode(): boolean {
    return this.previewMode;
  }

  /**
   * 프리뷰 모드에서 억제되는 핸들러 목록을 반환합니다.
   *
   * @returns 억제 대상 핸들러 이름의 ReadonlySet
   * @since engine-v1.26.1
   */
  public static getPreviewSuppressedHandlers(): ReadonlySet<string> {
    return PREVIEW_SUPPRESSED_HANDLERS;
  }

  /**
   * 프리뷰 모드에서 억제되는 레이아웃 기능 목록을 반환합니다.
   *
   * @returns 억제 대상 레이아웃 기능의 ReadonlySet
   * @since engine-v1.26.1
   */
  public static getPreviewSuppressedLayoutFeatures(): ReadonlySet<string> {
    return PREVIEW_SUPPRESSED_LAYOUT_FEATURES;
  }

  /**
   * actionRef를 해석하여 완전한 ActionDefinition을 반환합니다.
   *
   * actionRef가 있으면 named_actions에서 해당 정의를 가져와 이벤트 속성과 병합합니다.
   * 인라인 handler/params가 있으면 인라인이 우선합니다 (override).
   *
   * @param action 원본 액션 정의 (actionRef 포함 가능)
   * @returns 해석된 액션 정의
   * @since engine-v1.19.0
   */
  resolveActionRef(action: ActionDefinition): ActionDefinition {
    if (!action.actionRef) {
      return action;
    }

    const namedAction = this.namedActions[action.actionRef];
    if (!namedAction) {
      logger.warn(`[resolveActionRef] named action not found: "${action.actionRef}"`);
      return action;
    }

    // DevTools: actionRef 해석 이력 기록
    const devTools = getDevTools();
    if (devTools?.isEnabled?.()) {
      devTools.trackNamedActionRef?.({
        actionRefName: action.actionRef,
        resolvedHandler: namedAction.handler,
        timestamp: Date.now(),
      });
    }

    // 이벤트 바인딩 속성(type, key, event, if, debounce)은 원본에서 유지
    // handler, params, onSuccess, onError 등은 namedAction에서 가져오되, 인라인이 있으면 override
    const { actionRef, type, key, event, ...inlineOverrides } = action;
    const resolved: ActionDefinition = {
      ...namedAction,
      ...Object.fromEntries(
        Object.entries(inlineOverrides).filter(([, v]) => v !== undefined)
      ),
      type: type ?? namedAction.type,
    };

    // key, event는 원본에서만 가져옴 (namedAction에는 이벤트 바인딩이 없음)
    if (key !== undefined) resolved.key = key;
    if (event !== undefined) resolved.event = event;

    return resolved;
  }

  /**
   * 엔드포인트가 패턴에 매칭되는지 확인합니다.
   *
   * @param endpoint API 엔드포인트 경로
   * @param pattern glob 스타일 패턴 ("*", "/api/shop/*" 등)
   * @returns 매칭 여부
   * @since engine-v1.16.0
   */
  private matchesPattern(endpoint: string, pattern: string): boolean {
    if (pattern === '*') return true;

    // glob 패턴을 정규식으로 변환 (* → .*)
    const regexPattern = pattern
      .replace(/[.+?^${}()|[\]\\]/g, '\\$&')  // 특수문자 이스케이프
      .replace(/\*/g, '.*');                   // * → .*

    return new RegExp(`^${regexPattern}$`).test(endpoint);
  }

  /**
   * 엔드포인트에 매칭되는 전역 헤더를 추출합니다.
   *
   * globalHeaders 배열을 순회하며 패턴이 매칭되는 모든 헤더를 병합합니다.
   * 나중에 정의된 규칙의 헤더가 먼저 정의된 규칙의 헤더를 덮어씁니다.
   *
   * @param endpoint API 엔드포인트 경로
   * @param context 표현식 평가를 위한 컨텍스트
   * @returns 병합된 헤더 객체
   * @since engine-v1.16.0
   */
  private getMatchingGlobalHeaders(
    endpoint: string,
    context: Record<string, any>,
  ): Record<string, string> {
    const result: Record<string, string> = {};

    for (const rule of this.globalHeaders) {
      if (this.matchesPattern(endpoint, rule.pattern)) {
        Object.entries(rule.headers).forEach(([key, value]) => {
          // 표현식 평가 ({{_global.xxx}} 등) - resolveExpressionString으로 문자열 보간 지원
          const resolved = resolveExpressionString(value, context, { skipCache: true });
          // null, undefined, 빈 문자열이 아닌 경우에만 헤더 추가
          if (resolved != null && resolved !== '') {
            result[key] = String(resolved);
          }
        });
      }
    }

    return result;
  }

  /**
   * ErrorHandlingResolver와 연동을 설정합니다.
   *
   * ErrorHandlingResolver가 에러 핸들러를 실행할 때
   * ActionDispatcher.dispatchAction을 사용하도록 설정합니다.
   */
  private setupErrorHandling(): void {
    if (this.errorHandlingSetup) {
      return;
    }

    const resolver = getErrorHandlingResolver();

    // ErrorHandlingResolver에서 핸들러 실행 시 dispatchAction 사용
    resolver.setActionExecutor(async (handler: ErrorHandlerConfig, errorContext: { error: ErrorContext }) => {
      // ErrorHandlerConfig를 ActionDefinition으로 변환
      const action: ActionDefinition = {
        type: 'click', // 이벤트 타입은 의미 없음 (dispatchAction에서 사용하지 않음)
        handler: handler.handler as ActionType,
        target: handler.target,
        params: handler.params,
        actions: handler.actions, // sequence 핸들러용 actions 배열 전달
      };

      // 에러 컨텍스트를 data에 포함
      const context: Partial<ActionContext> = {
        data: {
          ...this.defaultContext.data,
          error: errorContext.error,
        },
      };

      return await this.dispatchAction(action, context);
    });

    this.errorHandlingSetup = true;
  }

  /**
   * 기본 핸들러를 등록합니다.
   *
   * 템플릿 엔진에서 기본적으로 제공하는 핸들러들을 등록합니다.
   */
  private registerDefaultHandlers(): void {
    // refetchDataSource: 데이터 소스 다시 fetch
    // sync 옵션: true면 startTransition 없이 즉시 렌더링 (드래그 앤 드롭 후 순서 변경 등)
    // globalStateOverride: sequence 내에서 setState 후 refetchDataSource 호출 시
    //   React 상태가 비동기로 업데이트되어 getState()가 이전 값을 반환하는 문제 해결
    // localStateOverride: sequence 내에서 setState local 후 refetchDataSource 호출 시
    //   params에서 {{_local.xxx}} 참조 지원
    // isolatedStateOverride: isolated 상태 override (isolatedContext가 있는 경우)
    this.registerHandler('refetchDataSource', async (action: ActionDefinition, context: ActionContext) => {
      const dataSourceId = action.params?.dataSourceId;
      const sync = action.params?.sync;

      if (!dataSourceId) {
        logger.warn('refetchDataSource: dataSourceId is required');
        return;
      }

      // G7Core.dataSource.refetch() 호출 (sync, globalStateOverride, localStateOverride, isolatedStateOverride 전달)
      // G7Core.state.get()으로 최신 전역 상태를 직접 가져옴 (React 상태 비동기 업데이트 문제 해결)
      // context.state._global은 action 실행 시점의 snapshot이라 initCartKey 등에서 설정한 값이 반영 안됨
      if (typeof window !== 'undefined' && (window as any).G7Core?.dataSource?.refetch) {
        // 최신 전역 상태 직접 가져오기 (G7Core.state.set()으로 설정한 값 즉시 반영)
        const currentGlobalState = (window as any).G7Core?.state?.get?.() || {};
        const globalStateOverride = {
          ...context.state?._global,
          ...currentGlobalState,  // 최신 상태로 덮어쓰기
        };
        // engine-v1.17.0: 커스텀 핸들러에서 setLocal 후 dispatch 호출 시 최신 로컬 상태 참조
        // G7Core.state.getLocal()은 __g7PendingLocalState를 우선 확인하여 최신 값 반환
        //
        // engine-v1.19.0: sequence 내 setState 후 refetchDataSource 호출 시
        // React 18의 마이크로태스크 기반 배칭이 await 경계에서 렌더를 플러시할 수 있음
        // 렌더 시 useLayoutEffect가 __g7PendingLocalState를 null로 클리어하므로
        // getLocal()이 stale한 globalLocal(initLocal 시점 값)을 반환하는 문제 발생
        // context.data._local은 sequence 핸들러가 누적한 최신 _local 상태이므로 우선 병합
        //
        // context 구조별 _local 접근:
        // - 컴포넌트 컨텍스트: context.data._local = extendedDataContext._local
        // - sequence 컨텍스트: context.data._local = currentState (누적 상태)
        // - dispatch fallback: context.data._local = globalState._local
        const currentLocalState = (window as any).G7Core?.state?.getLocal?.() || {};
        const contextLocalState = context.data?._local || context.state || {};
        const localStateOverride = { ...currentLocalState, ...contextLocalState };
        const isolatedStateOverride = context.isolatedContext?.state;
        logger.log('[refetchDataSource] localStateOverride:', localStateOverride);
        await (window as any).G7Core.dataSource.refetch(dataSourceId, {
          ...(sync ? { sync: true } : {}),
          ...(globalStateOverride ? { globalStateOverride } : {}),
          ...(localStateOverride ? { localStateOverride } : {}),
          ...(isolatedStateOverride ? { isolatedStateOverride } : {}),
        });
      } else {
        logger.warn('refetchDataSource: G7Core.dataSource.refetch is not available');
      }
    });

    // appendDataSource: 데이터 소스에 새 데이터를 병합 (무한 스크롤용)
    // 참고: executeAction에서 resolveParams가 이미 호출되어 action.params는 해석된 상태로 전달됨
    this.registerHandler('appendDataSource', async (action: ActionDefinition, _context: ActionContext) => {
      const { dataSourceId, dataPath, newData } = action.params || {};

      if (!dataSourceId) {
        logger.warn('appendDataSource: dataSourceId is required');
        return;
      }

      if (newData === undefined) {
        logger.warn('appendDataSource: newData is required');
        return;
      }

      // G7Core.dataSource.updateData() 호출
      if (typeof window !== 'undefined' && (window as any).G7Core?.dataSource?.updateData) {
        // resolveParams에서 이미 표현식이 해석되었으므로 직접 사용
        // dataPath와 newData는 이미 해석된 값임
        if (!Array.isArray(newData)) {
          logger.warn('appendDataSource: newData must be an array, got:', typeof newData, newData);
          return;
        }

        await (window as any).G7Core.dataSource.updateData(dataSourceId, dataPath || null, newData, 'append');
      } else {
        logger.warn('appendDataSource: G7Core.dataSource.updateData is not available');
      }
    });

    // updateDataSource: API 응답 데이터로 데이터 소스 전체 교체 (refetch 없이 직접 업데이트)
    // 장바구니 수량 변경 등 API가 전체 데이터를 반환하는 경우, refetch 대신 사용하여 네트워크 왕복 감소
    // params.dataSourceId: 업데이트할 데이터소스 ID
    // params.data: 새 데이터 (API 응답에서 바인딩 표현식으로 가져옴, 예: {{response.data}})
    // params.merge: true면 기존 데이터와 병합 (기본: false)
    this.registerHandler('updateDataSource', async (action: ActionDefinition, _context: ActionContext) => {
      const { dataSourceId, data, merge = false } = action.params || {};

      if (!dataSourceId) {
        logger.warn('updateDataSource: dataSourceId is required');
        return;
      }

      if (data === undefined) {
        logger.warn('updateDataSource: data is required');
        return;
      }

      // G7Core.dataSource.set() 호출
      if (typeof window !== 'undefined' && (window as any).G7Core?.dataSource?.set) {
        logger.log(`[updateDataSource] Updating dataSource '${dataSourceId}' with:`, data);
        (window as any).G7Core.dataSource.set(dataSourceId, data, { merge });
      } else {
        logger.warn('updateDataSource: G7Core.dataSource.set is not available');
      }
    });

    // scrollIntoView: 특정 요소로 스크롤
    // params.selector: CSS 선택자 (예: "#loading_indicator", "[data-id='item-1']")
    // params.behavior: 'smooth' | 'instant' | 'auto' (기본: 'smooth')
    // params.block: 'start' | 'center' | 'end' | 'nearest' (기본: 'nearest')
    // params.inline: 'start' | 'center' | 'end' | 'nearest' (기본: 'nearest')
    // params.waitForElement: true면 MutationObserver로 요소가 DOM에 추가될 때까지 대기 (기본: false)
    // params.timeout: waitForElement 사용 시 최대 대기 시간(ms) (기본: 2000)
    // params.delay: 스크롤 전 대기 시간(ms) - DOM 렌더링 대기용 (기본: 0, waitForElement=true면 무시)
    // params.retryCount: 요소를 찾지 못했을 때 재시도 횟수 (기본: 0, waitForElement=true면 무시)
    // params.retryInterval: 재시도 간격(ms) (기본: 50, waitForElement=true면 무시)
    // params.scrollContainer: 스크롤할 컨테이너 selector (지정 시 해당 컨테이너만 스크롤, 브라우저 스크롤 영향 없음)
    this.registerHandler('scrollIntoView', async (action: ActionDefinition, _context: ActionContext) => {
      const {
        selector,
        behavior = 'smooth',
        block = 'nearest',
        inline = 'nearest',
        waitForElement = false,
        timeout = 2000,
        delay = 0,
        retryCount = 0,
        retryInterval = 50,
        scrollContainer,
      } = action.params || {};

      if (!selector) {
        logger.warn('scrollIntoView: selector is required');
        return;
      }

      if (typeof window === 'undefined' || typeof document === 'undefined') {
        logger.warn('scrollIntoView: window/document is not available');
        return;
      }

      let element: Element | null = null;

      if (waitForElement) {
        // MutationObserver를 사용하여 요소가 DOM에 추가될 때까지 대기
        element = await new Promise<Element | null>((resolve) => {
          // 이미 요소가 존재하면 바로 반환
          const existingElement = document.querySelector(selector);
          if (existingElement) {
            resolve(existingElement);
            return;
          }

          const timeoutId = setTimeout(() => {
            observer.disconnect();
            resolve(null);
          }, timeout);

          const observer = new MutationObserver(() => {
            const foundElement = document.querySelector(selector);
            if (foundElement) {
              clearTimeout(timeoutId);
              observer.disconnect();
              resolve(foundElement);
            }
          });

          observer.observe(document.body, {
            childList: true,
            subtree: true,
          });
        });

        if (!element) {
          logger.warn(`scrollIntoView: element not found for selector "${selector}" after ${timeout}ms timeout`);
          return;
        }
      } else {
        // 기존 방식: delay + retry
        if (delay > 0) {
          await new Promise((resolve) => setTimeout(resolve, delay));
        }

        element = document.querySelector(selector);
        let attempts = 0;

        while (!element && attempts < retryCount) {
          await new Promise((resolve) => setTimeout(resolve, retryInterval));
          element = document.querySelector(selector);
          attempts++;
        }

        if (!element) {
          logger.warn(`scrollIntoView: element not found for selector "${selector}" after ${attempts + 1} attempts`);
          return;
        }
      }

      // scrollContainer가 지정된 경우 해당 컨테이너 내에서만 스크롤
      if (scrollContainer) {
        const container = document.querySelector(scrollContainer) as HTMLElement | null;
        if (!container) {
          logger.warn(`scrollIntoView: container not found for selector "${scrollContainer}"`);
          return;
        }

        const elementRect = element.getBoundingClientRect();
        const containerRect = container.getBoundingClientRect();

        // 컨테이너 기준 상대 위치 계산
        const relativeTop = elementRect.top - containerRect.top + container.scrollTop;
        const relativeBottom = relativeTop + elementRect.height;

        let targetScrollTop: number;

        switch (block) {
          case 'start':
            targetScrollTop = relativeTop;
            break;
          case 'center':
            targetScrollTop = relativeTop - (container.clientHeight / 2) + (elementRect.height / 2);
            break;
          case 'end':
            targetScrollTop = relativeBottom - container.clientHeight;
            break;
          case 'nearest':
          default:
            // 요소가 이미 보이면 스크롤하지 않음
            const isAbove = elementRect.top < containerRect.top;
            const isBelow = elementRect.bottom > containerRect.bottom;

            if (isAbove) {
              targetScrollTop = relativeTop;
            } else if (isBelow) {
              targetScrollTop = relativeBottom - container.clientHeight;
            } else {
              // 이미 보임 - 스크롤 불필요
              return;
            }
            break;
        }

        container.scrollTo({
          top: Math.max(0, targetScrollTop),
          behavior: behavior as ScrollBehavior,
        });
        return;
      }

      // 기본: 네이티브 scrollIntoView 사용
      element.scrollIntoView({
        behavior: behavior as ScrollBehavior,
        block: block as ScrollLogicalPosition,
        inline: inline as ScrollLogicalPosition,
      });
    });

    // reloadRoutes: 라우트 다시 로드
    this.registerHandler('reloadRoutes', async (_action: ActionDefinition, _context: ActionContext) => {
      if (typeof window === 'undefined') {
        logger.warn('reloadRoutes: window is not available');
        return;
      }

      const templateApp = (window as any).__templateApp;
      if (!templateApp) {
        logger.warn('reloadRoutes: TemplateApp not initialized');
        return;
      }

      const router = templateApp.getRouter?.();
      if (!router) {
        logger.warn('reloadRoutes: Router not available');
        return;
      }

      try {
        await router.loadRoutes();
        logger.log('reloadRoutes: Routes reloaded successfully');
      } catch (error) {
        logger.error('reloadRoutes: Failed to reload routes', error);
        throw error;
      }
    });

    // refresh: 현재 페이지 새로고침
    this.registerHandler('refresh', async (_action: ActionDefinition, _context: ActionContext) => {
      if (typeof window === 'undefined') {
        logger.warn('refresh: window is not available');
        return;
      }

      window.location.reload();
    });

    // remount: 컴포넌트 ID 기반으로 리마운트 트리거
    // _remountKeys 객체에 컴포넌트 ID별 카운터를 저장하여 key prop으로 사용
    this.registerHandler('remount', async (action: ActionDefinition, _context: ActionContext) => {
      const { componentId } = action.params || {};

      if (!componentId) {
        logger.warn('remount: componentId parameter is required');
        return;
      }

      if (!this.globalStateUpdater) {
        logger.warn('remount: globalStateUpdater is not set');
        return;
      }

      // G7Core.state에서 현재 _global._remountKeys 가져오기
      // dataContext._global에 저장되어 있으므로 _global을 통해 접근
      const G7Core = (window as any).G7Core;
      const currentState = G7Core?.state?.get() || {};
      const remountKeys = currentState._global?._remountKeys || {};
      const currentValue = remountKeys[componentId] || 0;

      // 해당 컴포넌트의 리마운트 키 증가
      // globalStateUpdater는 TemplateApp.setGlobalState이며, 최상위 레벨 속성을 기대함
      // setGlobalState 내부에서 updateTemplateData({ _global: { ...this.globalState } })로 감싸므로
      // _global 없이 직접 _remountKeys를 전달해야 함
      this.globalStateUpdater({
        _remountKeys: {
          ...remountKeys,
          [componentId]: currentValue + 1,
        },
      });

      logger.log(`remount: ${componentId} key incremented to ${currentValue + 1}`);
    });

    // reloadTranslations: 다국어 파일 다시 로드
    // 모듈/플러그인 활성화 후 새 캐시 버전으로 번역 데이터를 다시 로드합니다.
    this.registerHandler('reloadTranslations', async (_action: ActionDefinition, _context: ActionContext) => {
      if (typeof window === 'undefined') {
        logger.warn('reloadTranslations: window is not available');
        return;
      }

      // TranslationEngine 싱글톤 인스턴스 가져오기
      const { TranslationEngine } = await import('./TranslationEngine');
      const translationEngine = TranslationEngine.getInstance();

      // 현재 템플릿 ID와 로케일 가져오기
      const templateApp = (window as any).__templateApp;
      if (!templateApp) {
        logger.warn('reloadTranslations: TemplateApp not initialized');
        return;
      }

      const config = templateApp.getConfig?.();
      if (!config?.templateId) {
        logger.warn('reloadTranslations: templateId not available');
        return;
      }

      const locale = config.locale || 'ko';
      const fallbackLocale = 'en';

      try {
        // 새 캐시 버전 가져오기 (모듈/플러그인 활성화 시 버전이 변경됨)
        // config.json을 호출하여 최신 cache_version을 가져옵니다.
        const configResponse = await fetch(`/api/templates/${config.templateId}/config.json?_=${Date.now()}`);
        if (configResponse.ok) {
          const templateConfig = await configResponse.json();
          // API 응답 구조: {success: true, data: {cache_version: ...}}
          const cacheVersion = templateConfig?.data?.cache_version;
          if (cacheVersion !== undefined) {
            const currentVersion = translationEngine.getCacheVersion();
            if (currentVersion !== cacheVersion) {
              logger.log('reloadTranslations: Cache version changed', currentVersion, '->', cacheVersion);
              translationEngine.setCacheVersion(cacheVersion);
            }
          }
        }

        // 캐시 무효화를 위해 clearCache 호출 (있는 경우)
        if (typeof translationEngine.clearCache === 'function') {
          translationEngine.clearCache();
        }

        // 현재 로케일과 폴백 로케일 다시 로드 (브라우저 캐시 무효화)
        await translationEngine.loadTranslations(config.templateId, locale, '/api', true);
        if (locale !== fallbackLocale) {
          await translationEngine.loadTranslations(config.templateId, fallbackLocale, '/api', true);
        }
        logger.log('reloadTranslations: Translations reloaded successfully');
      } catch (error) {
        logger.error('reloadTranslations: Failed to reload translations', error);
        throw error;
      }
    });

    // reloadModuleHandlers: 모듈 핸들러 동적 로드/제거
    // 모듈 활성화/비활성화 시 window.G7Config.moduleAssets 병합/제거 및 JS 로드
    this.registerHandler('reloadModuleHandlers', async (action: ActionDefinition, _context: ActionContext) => {
      if (typeof window === 'undefined') {
        logger.warn('reloadModuleHandlers: window is not available');
        return;
      }

      const { moduleInfo, action: actionType } = action.params || {};

      if (!moduleInfo) {
        logger.warn('reloadModuleHandlers: moduleInfo is required');
        return;
      }

      // API 응답의 data 래퍼 처리 (ResponseHelper 응답 구조 호환)
      // 응답 구조: { success, message, data: { identifier, assets, ... } }
      const moduleData = moduleInfo.data || moduleInfo;

      if (!moduleData.identifier) {
        logger.warn('reloadModuleHandlers: moduleInfo with identifier is required');
        return;
      }

      if (!actionType || (actionType !== 'add' && actionType !== 'remove')) {
        logger.warn('reloadModuleHandlers: action must be "add" or "remove"');
        return;
      }

      const G7Config = (window as any).G7Config;
      if (!G7Config) {
        logger.warn('reloadModuleHandlers: G7Config not available');
        return;
      }

      const identifier = moduleData.identifier;

      try {
        if (actionType === 'add') {
          // 활성화: moduleAssets에 병합
          if (moduleData.assets) {
            G7Config.moduleAssets = G7Config.moduleAssets || {};
            G7Config.moduleAssets[identifier] = moduleData.assets;

            logger.log(`reloadModuleHandlers: Added assets for ${identifier}`);

            // JS 파일이 있으면 동적 로드
            if (moduleData.assets.js) {
              const scriptUrl = moduleData.assets.js;
              const scriptId = `module-${identifier}`;

              // 이미 로드된 스크립트인지 확인
              if (document.getElementById(scriptId)) {
                logger.warn(`reloadModuleHandlers: Script ${scriptId} already loaded`);
                return;
              }

              // <script> 태그 동적 생성
              const script = document.createElement('script');
              script.id = scriptId;
              script.src = scriptUrl;
              script.async = true;

              await new Promise<void>((resolve, reject) => {
                script.onload = () => {
                  logger.log(`reloadModuleHandlers: Script loaded successfully for ${identifier}`);
                  resolve();
                };
                script.onerror = () => {
                  logger.error(`reloadModuleHandlers: Failed to load script for ${identifier}`);
                  reject(new Error(`Failed to load module script: ${scriptUrl}`));
                };
                document.head.appendChild(script);
              });

              // CSS 파일이 있으면 동적 로드
              if (moduleData.assets.css) {
                const cssUrl = moduleData.assets.css;
                const linkId = `module-css-${identifier}`;

                if (!document.getElementById(linkId)) {
                  const link = document.createElement('link');
                  link.id = linkId;
                  link.rel = 'stylesheet';
                  link.href = cssUrl;
                  document.head.appendChild(link);
                  logger.log(`reloadModuleHandlers: CSS loaded for ${identifier}`);
                }
              }
            }
          }
        } else if (actionType === 'remove') {
          // 비활성화: moduleAssets에서 제거
          if (G7Config.moduleAssets && G7Config.moduleAssets[identifier]) {
            delete G7Config.moduleAssets[identifier];
            logger.log(`reloadModuleHandlers: Removed assets for ${identifier}`);
          }

          // 로드된 스크립트 제거
          const scriptId = `module-${identifier}`;
          const script = document.getElementById(scriptId);
          if (script) {
            script.remove();
            logger.log(`reloadModuleHandlers: Removed script for ${identifier}`);
          }

          // CSS 제거
          const linkId = `module-css-${identifier}`;
          const link = document.getElementById(linkId);
          if (link) {
            link.remove();
            logger.log(`reloadModuleHandlers: Removed CSS for ${identifier}`);
          }
        }
      } catch (error) {
        logger.error(`reloadModuleHandlers: Failed to ${actionType} module assets`, error);
        throw error;
      }
    });

    // reloadPluginHandlers: 플러그인 핸들러 동적 로드/제거
    // 플러그인 활성화/비활성화 시 window.G7Config.pluginAssets 병합/제거 및 JS 로드
    this.registerHandler('reloadPluginHandlers', async (action: ActionDefinition, _context: ActionContext) => {
      if (typeof window === 'undefined') {
        logger.warn('reloadPluginHandlers: window is not available');
        return;
      }

      const { pluginInfo, action: actionType } = action.params || {};

      if (!pluginInfo) {
        logger.warn('reloadPluginHandlers: pluginInfo is required');
        return;
      }

      // API 응답의 data 래퍼 처리 (ResponseHelper 응답 구조 호환)
      // 응답 구조: { success, message, data: { identifier, assets, ... } }
      const pluginData = pluginInfo.data || pluginInfo;

      if (!pluginData.identifier) {
        logger.warn('reloadPluginHandlers: pluginInfo with identifier is required');
        return;
      }

      if (!actionType || (actionType !== 'add' && actionType !== 'remove')) {
        logger.warn('reloadPluginHandlers: action must be "add" or "remove"');
        return;
      }

      const G7Config = (window as any).G7Config;
      if (!G7Config) {
        logger.warn('reloadPluginHandlers: G7Config not available');
        return;
      }

      const identifier = pluginData.identifier;

      try {
        if (actionType === 'add') {
          // 활성화: pluginAssets에 병합
          if (pluginData.assets) {
            G7Config.pluginAssets = G7Config.pluginAssets || {};
            G7Config.pluginAssets[identifier] = pluginData.assets;

            logger.log(`reloadPluginHandlers: Added assets for ${identifier}`);

            // JS 파일이 있으면 동적 로드
            if (pluginData.assets.js) {
              const scriptUrl = pluginData.assets.js;
              const scriptId = `plugin-${identifier}`;

              // 이미 로드된 스크립트인지 확인
              if (document.getElementById(scriptId)) {
                logger.warn(`reloadPluginHandlers: Script ${scriptId} already loaded`);
                return;
              }

              // <script> 태그 동적 생성
              const script = document.createElement('script');
              script.id = scriptId;
              script.src = scriptUrl;
              script.async = true;

              await new Promise<void>((resolve, reject) => {
                script.onload = () => {
                  logger.log(`reloadPluginHandlers: Script loaded successfully for ${identifier}`);
                  resolve();
                };
                script.onerror = () => {
                  logger.error(`reloadPluginHandlers: Failed to load script for ${identifier}`);
                  reject(new Error(`Failed to load plugin script: ${scriptUrl}`));
                };
                document.head.appendChild(script);
              });

              // CSS 파일이 있으면 동적 로드
              if (pluginData.assets.css) {
                const cssUrl = pluginData.assets.css;
                const linkId = `plugin-css-${identifier}`;

                if (!document.getElementById(linkId)) {
                  const link = document.createElement('link');
                  link.id = linkId;
                  link.rel = 'stylesheet';
                  link.href = cssUrl;
                  document.head.appendChild(link);
                  logger.log(`reloadPluginHandlers: CSS loaded for ${identifier}`);
                }
              }
            }
          }
        } else if (actionType === 'remove') {
          // 비활성화: pluginAssets에서 제거
          if (G7Config.pluginAssets && G7Config.pluginAssets[identifier]) {
            delete G7Config.pluginAssets[identifier];
            logger.log(`reloadPluginHandlers: Removed assets for ${identifier}`);
          }

          // 로드된 스크립트 제거
          const scriptId = `plugin-${identifier}`;
          const script = document.getElementById(scriptId);
          if (script) {
            script.remove();
            logger.log(`reloadPluginHandlers: Removed script for ${identifier}`);
          }

          // CSS 제거
          const linkId = `plugin-css-${identifier}`;
          const link = document.getElementById(linkId);
          if (link) {
            link.remove();
            logger.log(`reloadPluginHandlers: Removed CSS for ${identifier}`);
          }
        }
      } catch (error) {
        logger.error(`reloadPluginHandlers: Failed to ${actionType} plugin assets`, error);
        throw error;
      }
    });

    // showErrorPage: 에러 페이지 표시
    // ErrorPageHandler를 통해 에러 코드에 맞는 레이아웃을 로드하고 렌더링합니다.
    let _showErrorPageActive = false;
    this.registerHandler('showErrorPage', async (action: ActionDefinition, _context: ActionContext) => {
      // 중복 호출 방지: 여러 데이터소스가 동시에 에러를 반환할 때
      // 각각이 독립적으로 errorHandling을 트리거하여 showErrorPage가 복수 호출됨
      // 첫 번째 호출만 처리하고 나머지는 무시
      if (_showErrorPageActive) {
        logger.log('showErrorPage: Already active, skipping duplicate call');
        return;
      }
      _showErrorPageActive = true;

      if (typeof window === 'undefined') {
        _showErrorPageActive = false;
        logger.warn('showErrorPage: window is not available');
        return;
      }

      const templateApp = (window as any).__templateApp;
      if (!templateApp) {
        _showErrorPageActive = false;
        logger.warn('showErrorPage: TemplateApp not initialized');
        return;
      }

      const errorPageHandler = templateApp.getErrorPageHandler?.();
      if (!errorPageHandler) {
        _showErrorPageActive = false;
        logger.warn('showErrorPage: ErrorPageHandler not available');
        return;
      }

      // params에서 에러 코드 및 옵션 추출
      const errorCode = action.params?.errorCode || 500;
      const target = action.params?.target || 'content';
      let containerId = action.params?.containerId;

      // containerId가 지정되지 않은 경우 target에 따라 결정
      if (!containerId) {
        containerId = target === 'full' ? 'app' : 'main_content';
      }

      // 레이아웃 경로가 지정된 경우 (향후 확장용)
      // const layout = action.params?.layout;

      logger.log('showErrorPage:', {
        errorCode,
        target,
        containerId,
      });

      /**
       * 컨테이너가 DOM에 존재하는지 확인하고, 없으면 MutationObserver로 대기
       * progressive 로딩 시 컨테이너가 아직 렌더링되지 않았을 수 있음
       */
      const waitForContainer = (id: string, timeout: number = 60000): Promise<Element> => {
        return new Promise((resolve, reject) => {
          // 이미 존재하면 즉시 반환
          const existing = document.getElementById(id);
          if (existing) {
            resolve(existing);
            return;
          }

          logger.log(`showErrorPage: Waiting for container #${id}...`);

          // MutationObserver로 DOM 변화 감지
          let timeoutId: ReturnType<typeof setTimeout>;
          const observer = new MutationObserver((_mutations, obs) => {
            const element = document.getElementById(id);
            if (element) {
              logger.log(`showErrorPage: Container #${id} found`);
              obs.disconnect();
              clearTimeout(timeoutId);
              resolve(element);
            }
          });

          observer.observe(document.body, {
            childList: true,
            subtree: true,
          });

          // 타임아웃 설정
          timeoutId = setTimeout(() => {
            observer.disconnect();
            reject(new Error(`Container #${id} not found within ${timeout}ms`));
          }, timeout);
        });
      };

      try {
        // 컨테이너가 준비될 때까지 대기
        await waitForContainer(containerId);

        // 에러 페이지 렌더링 전 레이아웃 errorHandling 임시 해제
        // 에러 페이지가 _user_base를 extends할 경우, 데이터소스 에러(예: user 401)가
        // ErrorHandlingResolver 싱글톤의 레이아웃 errorHandling을 재트리거하여 무한 루프 발생 방지
        const resolver = getErrorHandlingResolver();
        const savedLayoutConfig = (resolver as any).layoutErrorHandling;
        resolver.clearLayoutConfig();

        try {
          const success = await errorPageHandler.renderError(errorCode, containerId);
          if (!success) {
            logger.warn(`showErrorPage: Failed to render error page for code ${errorCode}`);
          }
        } finally {
          // 렌더링 완료 후 레이아웃 errorHandling 복원
          resolver.setLayoutConfig(savedLayoutConfig);
        }
      } catch (error) {
        logger.error('showErrorPage: Error rendering error page:', error);
        throw error;
      } finally {
        _showErrorPageActive = false;
      }
    });

    // emitEvent: 컴포넌트 이벤트 발생
    // G7Core.componentEvent.emit()을 통해 이벤트를 브로드캐스트하고 모든 리스너의 응답을 기다립니다.
    // 파일 업로드, 폼 검증, 컴포넌트 간 통신 등 다양한 용도로 사용할 수 있습니다.
    //
    // @example 레이아웃 JSON에서 사용:
    // {
    //   "handler": "emitEvent",
    //   "params": {
    //     "event": "upload:site_logo",  // 이벤트명 (컴포넌트에서 구독)
    //     "data": { "collection": "site_logo" }  // 전달할 데이터 (선택)
    //   }
    // }
    //
    // @example 컴포넌트에서 구독:
    // useEffect(() => {
    //   const unsubscribe = G7Core.componentEvent.on('upload:site_logo', async (data) => {
    //     const result = await uploadFiles();
    //     return result;  // emitEvent 호출자에게 반환
    //   });
    //   return () => unsubscribe();
    // }, []);
    //
    // 리스너의 결과는 _local._eventResult에 저장되어 후속 액션에서 접근 가능합니다.
    this.registerHandler('emitEvent', async (action: ActionDefinition, context: ActionContext) => {
      const eventName = action.params?.event;
      const eventData = action.params?.data;

      if (!eventName) {
        logger.warn('emitEvent: event parameter is required');
        return;
      }

      if (typeof window === 'undefined') {
        logger.warn('emitEvent: window is not available');
        return;
      }

      const G7Core = (window as any).G7Core;
      if (!G7Core?.componentEvent?.emit) {
        logger.warn('emitEvent: G7Core.componentEvent is not available');
        return;
      }

      try {
        logger.log(`emitEvent: Emitting "${eventName}"`, eventData);

        // 이벤트 데이터에 컨텍스트 정보 병합
        const mergedData = {
          ...eventData,
          _context: {
            data: context.data,
            state: context.state,
          },
        };

        const results = await G7Core.componentEvent.emit(eventName, mergedData);

        // 결과가 없으면 (리스너가 없으면) 경고
        if (!results || results.length === 0) {
          logger.warn(`emitEvent: No listeners found for "${eventName}"`);
        } else {
          logger.log(`emitEvent: Event "${eventName}" completed with ${results.length} listener(s)`, results);
        }

        // 결과를 _local._eventResult에 저장하여 후속 액션에서 접근 가능하게 함
        if (this.globalStateUpdater) {
          const currentState = G7Core?.state?.get() || {};
          this.globalStateUpdater({
            _local: {
              ...currentState._local,
              _eventResult: {
                event: eventName,
                success: true,
                data: results.length === 1 ? results[0] : results,
                listeners: results.length,
              },
            },
          });
        }
      } catch (error) {
        logger.error(`emitEvent: Event "${eventName}" failed`, error);

        // 에러도 _local._eventResult에 저장
        if (this.globalStateUpdater) {
          const currentState = G7Core?.state?.get() || {};
          this.globalStateUpdater({
            _local: {
              ...currentState._local,
              _eventResult: {
                event: eventName,
                success: false,
                error: error instanceof Error ? error.message : String(error),
              },
            },
          });
        }

        throw error;
      }
    });

    // updateProductField: 상품 목록에서 개별 필드 인라인 수정
    // products 데이터 소스의 로컬 데이터를 업데이트하고, 변경된 상품 ID를 _local.modifiedProductIds에 추적합니다.
    // 일괄 변경 버튼 클릭 시 해당 목록의 상품들만 API로 전송합니다.
    //
    // @example 레이아웃 JSON에서 사용:
    // {
    //   "handler": "updateProductField",
    //   "params": {
    //     "productId": "{{row.id}}",
    //     "field": "stock_quantity",
    //     "value": "{{$event.target.value}}"
    //   }
    // }
    this.registerHandler('updateProductField', async (action: ActionDefinition, _context: ActionContext) => {
      const { productId, field, value } = action.params || {};

      if (!productId || !field) {
        logger.warn('updateProductField: productId and field are required');
        return;
      }

      if (typeof window === 'undefined') {
        logger.warn('updateProductField: window is not available');
        return;
      }

      const G7Core = (window as any).G7Core;
      if (!G7Core?.state?.get || !this.globalStateUpdater) {
        logger.warn('updateProductField: G7Core.state or globalStateUpdater is not available');
        return;
      }

      try {
        const currentState = G7Core.state.get() || {};
        const productsData = currentState.products?.data?.data || [];

        // 상품 목록에서 해당 상품 찾아서 필드 업데이트
        const updatedProducts = productsData.map((product: any) => {
          if (product.id === productId) {
            return {
              ...product,
              [field]: value,
              _modified: true, // 수정됨 표시
            };
          }
          return product;
        });

        // 변경된 상품 ID 추적
        const modifiedProductIds = new Set(currentState._local?.modifiedProductIds || []);
        modifiedProductIds.add(productId);

        // 상태 업데이트 (products 데이터 + 수정 추적)
        this.globalStateUpdater({
          products: {
            ...currentState.products,
            data: {
              ...currentState.products?.data,
              data: updatedProducts,
            },
          },
          _local: {
            ...currentState._local,
            modifiedProductIds: Array.from(modifiedProductIds),
          },
        });

        logger.log(`updateProductField: Updated product ${productId}, field: ${field}, value:`, value);
      } catch (error) {
        logger.error('updateProductField: Error updating product field', error);
        throw error;
      }
    });

    // updateOptionField: 상품 옵션 필드 인라인 수정
    // 상품 옵션의 필드를 수정하고 변경 추적합니다.
    this.registerHandler('updateOptionField', async (action: ActionDefinition, _context: ActionContext) => {
      const { productId, optionId, field, value } = action.params || {};

      if (!productId || !optionId || !field) {
        logger.warn('updateOptionField: productId, optionId and field are required');
        return;
      }

      if (typeof window === 'undefined') {
        logger.warn('updateOptionField: window is not available');
        return;
      }

      const G7Core = (window as any).G7Core;
      if (!G7Core?.state?.get || !this.globalStateUpdater) {
        logger.warn('updateOptionField: G7Core.state or globalStateUpdater is not available');
        return;
      }

      try {
        const currentState = G7Core.state.get() || {};
        const productsData = currentState.products?.data?.data || [];

        // 상품 목록에서 해당 상품과 옵션 찾아서 필드 업데이트
        const updatedProducts = productsData.map((product: any) => {
          if (product.id === productId && product.options) {
            const updatedOptions = product.options.map((option: any) => {
              if (option.id === optionId) {
                return {
                  ...option,
                  [field]: value,
                  _modified: true,
                };
              }
              return option;
            });
            return {
              ...product,
              options: updatedOptions,
              _modified: true,
            };
          }
          return product;
        });

        // 변경된 상품 ID 추적
        const modifiedProductIds = new Set(currentState._local?.modifiedProductIds || []);
        modifiedProductIds.add(productId);

        // 상태 업데이트
        this.globalStateUpdater({
          products: {
            ...currentState.products,
            data: {
              ...currentState.products?.data,
              data: updatedProducts,
            },
          },
          _local: {
            ...currentState._local,
            modifiedProductIds: Array.from(modifiedProductIds),
          },
        });

        logger.log(`updateOptionField: Updated product ${productId} option ${optionId}, field: ${field}, value:`, value);
      } catch (error) {
        logger.error('updateOptionField: Error updating option field', error);
        throw error;
      }
    });

    // setLocale: 언어 변경 핸들러
    // TemplateApp.changeLocale()을 호출하여 언어 변경, DB 저장, UI 리렌더링을 수행합니다.
    this.registerHandler('setLocale', async (action: ActionDefinition, _context: ActionContext) => {
      const locale = action.target;

      if (!locale || typeof locale !== 'string') {
        logger.warn('setLocale: Invalid locale:', locale);
        return;
      }

      // TemplateApp 인스턴스를 통해 언어 변경
      const templateApp = (window as any).__templateApp;
      if (templateApp && typeof templateApp.changeLocale === 'function') {
        try {
          await templateApp.changeLocale(locale);
          logger.log('setLocale: Locale changed to', locale);
        } catch (error) {
          logger.error('setLocale: Failed to change locale:', error);
          // 폴백: 페이지 새로고침
          window.location.reload();
        }
      } else {
        // TemplateApp이 없으면 localStorage에 저장 후 새로고침
        logger.warn('setLocale: TemplateApp not found, falling back to page reload');
        try {
          localStorage.setItem('g7_locale', locale);
        } catch {
          // ignore storage errors
        }
        window.location.reload();
      }
    });

    // suppress: 에러 전파를 의도적으로 방지하는 no-op 핸들러
    // 데이터소스/apiCall의 errorHandling에서 특정 에러 코드를 상위 레벨로 전파하지 않을 때 사용
    // 예: 비회원의 /api/auth/user 401은 정상 동작이므로 레이아웃 errorHandling으로 전파 방지
    // @since engine-v1.21.0
    this.registerHandler('suppress', async () => {
      logger.log('suppress: Error intentionally suppressed');
    });

    // DevTools에 빌트인 핸들러 메타데이터 일괄 등록
    this.registerBuiltInHandlerMetadata();
  }

  /**
   * 빌트인 핸들러 메타데이터를 DevTools에 등록합니다.
   */
  private registerBuiltInHandlerMetadata(): void {
    const devTools = getDevTools();
    if (!devTools?.isEnabled()) return;

    const builtInHandlers: Array<{ name: string; description: string }> = [
      { name: 'refetchDataSource', description: '데이터 소스를 다시 fetch합니다' },
      { name: 'appendDataSource', description: '데이터 소스에 새 데이터를 병합합니다 (무한 스크롤용)' },
      { name: 'updateDataSource', description: 'API 응답으로 데이터 소스를 직접 업데이트합니다 (refetch 대체)' },
      { name: 'reloadRoutes', description: '라우트를 다시 로드합니다' },
      { name: 'refresh', description: '현재 페이지를 새로고침합니다' },
      { name: 'remount', description: '컴포넌트를 리마운트합니다' },
      { name: 'reloadTranslations', description: '다국어 파일을 다시 로드합니다' },
      { name: 'showErrorPage', description: '에러 페이지를 표시합니다' },
      { name: 'emitEvent', description: '이벤트를 발생시킵니다' },
      { name: 'updateProductField', description: '상품 필드를 인라인 수정합니다' },
      { name: 'updateOptionField', description: '상품 옵션 필드를 인라인 수정합니다' },
      { name: 'setLocale', description: '언어를 변경합니다 (DB 저장 + UI 리렌더링)' },
    ];

    for (const handler of builtInHandlers) {
      devTools.trackHandlerRegistration(handler.name, 'built-in', handler.description);
    }
  }

  /**
   * 이벤트 핸들러를 생성합니다.
   *
   * @param action 액션 정의
   * @param dataContext 데이터 컨텍스트
   * @param componentContext 컴포넌트 컨텍스트 (state, setState)
   */
  createHandler(
    action: ActionDefinition,
    dataContext?: any,
    componentContext?: { state?: any; setState?: (updates: any) => void; isolatedContext?: IsolatedContextValue | null }
  ): (event: Event) => void {
    logger.log('createHandler called for:', action.handler, action.type);

    // DevTools: 핸들러 생성 시점 상태 캡처 (stale closure 감지용)
    const devTools = (window as any).__g7DevTools;
    const handlerCreatedAt = Date.now();
    const capturedLocalState = componentContext?.state ? { ...componentContext.state } : null;
    const handlerId = `handler_${action.handler}_${handlerCreatedAt}`;

    return async (event: Event) => {
      logger.log('Handler invoked for:', action.handler, 'event:', event.type);

      // DevTools: 핸들러 실행 시점에 stale closure 감지
      if (devTools?.isEnabled?.() && capturedLocalState) {
        const G7Core = (window as any).G7Core;
        const currentLocalState = G7Core?.state?.getLocal?.() ?? componentContext?.state ?? {};
        const timeDiff = Date.now() - handlerCreatedAt;

        // 캡처된 상태와 현재 상태 비교
        for (const key of Object.keys(capturedLocalState)) {
          const capturedValue = capturedLocalState[key];
          const currentValue = currentLocalState[key];

          // 값이 다르고 100ms 이상 경과했으면 stale closure 경고
          if (capturedValue !== currentValue && timeDiff > 100) {
            devTools.trackStaleClosureWarning?.({
              type: 'event-handler-stale',
              location: `createHandler(${action.handler})`,
              capturedPath: `_local.${key}`,
              capturedValue,
              capturedAt: handlerCreatedAt,
              currentValue,
              actionId: handlerId,
              stackTrace: new Error().stack,
            });
            logger.warn(
              `[Stale Closure] _local.${key} changed after handler creation:`,
              `captured="${capturedValue}" → current="${currentValue}" (${timeDiff}ms ago)`
            );
          }
        }
      }

      try {
        // 기본 이벤트 동작 방지 (폼 제출 시 페이지 리로드 방지)
        // 단, change 이벤트에서는 preventDefault()를 호출하면 안됨
        // 체크박스/라디오/input의 change 이벤트에서 preventDefault() 호출 시
        // 네이티브 상태 변경이 취소되어 React 상태와 불일치 발생
        if (event.type !== 'change') {
          event.preventDefault();
        }

        // 폼 데이터 추출 (submit 이벤트인 경우)
        let formData: Record<string, any> = {};
        if (
          event.type === 'submit' &&
          event.target instanceof HTMLFormElement
        ) {
          const form = event.target as HTMLFormElement;
          const formDataObj = new FormData(form);

          // FormData를 객체로 변환
          formDataObj.forEach((value, key) => {
            formData[key] = value;
          });
        }

        // 컨텍스트 병합 (폼 데이터 + 컴포넌트 컨텍스트 포함)
        const context: ActionContext = {
          ...this.defaultContext,
          data: {
            ...dataContext,
            form: formData, // 폼 데이터를 form 객체로 추가
            // _local: 레이아웃 수준의 로컬 상태 (componentContext.state)
            _local: componentContext?.state || {},
            // $event: 이벤트 객체를 data에 추가 ({{$event.target.value}} 바인딩 지원)
            $event: event,
          },
          event,
          // 컴포넌트 컨텍스트 병합
          ...(componentContext && {
            state: componentContext.state,
            setState: componentContext.setState,
          }),
          // 격리된 상태 컨텍스트 (isolatedState 속성이 있는 컴포넌트에서 제공)
          isolatedContext: componentContext?.isolatedContext,
        };

        // 확인 메시지 표시
        if (action.confirm) {
          let message = this.resolveValue(action.confirm, context.data);

          // $t: 다국어 구문 처리
          if (this.translationEngine && this.translationContext && message.startsWith('$t:')) {
            message = this.translationEngine.resolveTranslations(
              message,
              this.translationContext,
              context.data
            );
          }

          if (!confirm(message)) {
            return;
          }
        }

        // G7Core.state.setLocal(), G7Core.modal.open() 등에서 사용할 수 있도록 현재 컨텍스트 저장
        // engine-v1.16.0: componentContext + context.data를 함께 저장하여 $parent 바인딩 지원
        const previousActionContext = (window as any).__g7ActionContext;
        (window as any).__g7ActionContext = {
          ...componentContext,
          // context.data에는 _global, _local, _computed 등 전체 데이터 컨텍스트가 포함됨
          // G7Core.dispatch()에서 openModal 등 호출 시 이 데이터가 필요함
          data: context.data,
        };

        try {
          // 액션 실행
          await this.executeAction(action, context);
        } finally {
          // 액션 완료 후 이전 컨텍스트 복원
          (window as any).__g7ActionContext = previousActionContext;
        }
      } catch (error) {
        logger.error('Action execution failed:', error);

        // 에러 액션 실행 (컴포넌트 컨텍스트 포함)
        if (action.onError) {
          // 에러 객체를 data에 포함시켜 바인딩 가능하게 함
          const errorData = error instanceof ActionError && error.originalError
            ? error.originalError
            : error;

          const errorContext = {
            ...this.defaultContext,
            data: {
              ...dataContext,
              error: errorData, // 에러 객체 추가
              // _local: 레이아웃 수준의 로컬 상태 (componentContext.state)
              _local: componentContext?.state || {},
              // $event: 이벤트 객체를 data에 추가
              $event: event,
            },
            event,
            // 컴포넌트 컨텍스트 병합 (setState를 통해 에러 상태 업데이트 가능)
            ...(componentContext && {
              state: componentContext.state,
              setState: componentContext.setState,
            }),
          };

          // onError가 배열인 경우 순차 실행
          const errorActions = Array.isArray(action.onError) ? action.onError : [action.onError];
          for (const errorAction of errorActions) {
            await this.executeAction(errorAction, errorContext);
          }
        }
      }
    };
  }

  /**
   * 액션 ID를 생성합니다.
   *
   * 핸들러 타입, 타겟, 타임스탬프를 조합하여 고유한 액션 ID를 생성합니다.
   *
   * @param action 액션 정의
   * @param target 해석된 타겟
   */
  private generateActionId(action: ActionDefinition, target?: string): string {
    const handlerPart = action.handler;
    const targetPart = target || 'no-target';
    const timestamp = Date.now();
    const random = Math.random().toString(36).substring(2, 9);

    return `${handlerPart}_${targetPart.replace(/[^a-zA-Z0-9]/g, '_')}_${timestamp}_${random}`;
  }

  /**
   * 액션을 실행합니다.
   *
   * @param action 액션 정의
   * @param context 액션 컨텍스트
   */
  private async executeAction(
    action: ActionDefinition,
    context: ActionContext
  ): Promise<ActionResult> {
    // actionRef 해석 - named_actions 참조를 실제 액션 정의로 변환
    action = this.resolveActionRef(action);

    // DevTools 액션 로깅 시작
    const devTools = getDevTools();
    const devToolsActionId = devTools?.isEnabled() ? `action_${Date.now()}_${Math.random().toString(36).substring(2, 11)}` : undefined;
    const startTime = performance.now();

    // DevTools: 해석된 params를 추적하기 위해 별도 저장
    let devToolsResolvedParams: Record<string, any> | undefined;

    if (devToolsActionId && devTools) {
      devTools.logAction({
        id: devToolsActionId,
        type: action.handler,
        params: this.sanitizeForDevTools(action.params),
        context: this.sanitizeForDevTools({
          hasState: !!context.state,
          hasSetState: !!context.setState,
          dataKeys: context.data ? Object.keys(context.data) : [],
        }),
        startTime,
        status: 'started',
      });
    }

    // if 조건 확인 - 조건이 false이면 액션 건너뜀
    if (action.if !== undefined) {
      try {
        const conditionResult: any = this.resolveValue(action.if, context.data);
        // resolveValue가 문자열 "false"/"true"를 반환할 수 있으므로 명시적 변환
        const isTruthy = conditionResult === true || conditionResult === 'true' ||
                         (conditionResult && conditionResult !== 'false' && conditionResult !== '0' && conditionResult !== false);
        logger.log('[executeAction] if condition result:', action.handler, isTruthy);
        if (!isTruthy) {
          // DevTools: 조건 불충족으로 스킵됨
          if (devToolsActionId && devTools) {
            devTools.logAction({
              id: devToolsActionId,
              type: action.handler,
              startTime,
              endTime: performance.now(),
              duration: performance.now() - startTime,
              status: 'success',
              result: { skipped: true, reason: 'if condition false' },
            });
          }
          return { success: true, data: undefined };
        }
      } catch (error) {
        logger.error('[executeAction] if condition error:', action.handler, error);
        // DevTools: 조건 평가 에러
        if (devToolsActionId && devTools) {
          devTools.logAction({
            id: devToolsActionId,
            type: action.handler,
            startTime,
            endTime: performance.now(),
            duration: performance.now() - startTime,
            status: 'error',
            error: {
              name: 'ConditionEvaluationError',
              message: error instanceof Error ? error.message : String(error),
              stack: error instanceof Error ? error.stack : undefined,
            },
          });
        }
        return { success: true, data: undefined };
      }
    }

    // 액션 ID 생성 (API 호출인 경우에만)
    let actionId: string | undefined;

    try {
      // 파라미터 바인딩
      const resolvedParams = this.resolveParams(action.params, context.data);

      // DevTools: 해석된 params 저장
      if (devToolsActionId) {
        devToolsResolvedParams = this.sanitizeForDevTools(resolvedParams);
      }

      // 타겟 바인딩
      const resolvedTarget = action.target
        ? this.resolveValue(action.target, context.data)
        : undefined;

      // API 호출 시 로딩 상태 시작 (컴포넌트별 개별 관리)
      if (action.handler === 'apiCall' && context.setState) {
        actionId = this.generateActionId(action, resolvedTarget);

        // 기존 loadingActions 상태 유지하면서 새 액션 추가
        const currentLoadingActions = context.state?.loadingActions || {};
        context.setState({
          loadingActions: {
            ...currentLoadingActions,
            [actionId]: true
          }
        });
      }

      // 프리뷰 모드 억제 체크 (switch 진입 전)
      // PREVIEW_SUPPRESSED_HANDLERS에 정의된 핸들러는 프리뷰 모드에서 실행하지 않음
      // @since engine-v1.26.1
      if (this.previewMode && PREVIEW_SUPPRESSED_HANDLERS.has(action.handler)) {
        logger.warn(`Preview mode: "${action.handler}" suppressed`, resolvedTarget || resolvedParams);

        // DevTools: 억제 로그
        if (devToolsActionId && devTools) {
          devTools.logAction({
            id: devToolsActionId,
            type: action.handler,
            startTime,
            endTime: performance.now(),
            duration: performance.now() - startTime,
            status: 'skipped',
            metadata: { reason: 'preview_mode_suppressed' },
          });
        }

        return { success: true, data: undefined };
      }

      // 액션 핸들러 실행
      let result: any;

      switch (action.handler) {
        case 'navigate':
          // navigate 액션의 경우 params.path를 우선적으로 사용 (동적 바인딩 지원)
          const navigatePath = resolvedParams.path || resolvedTarget;
          result = await this.handleNavigate(navigatePath!, resolvedParams, context);
          break;

        case 'navigateBack':
          result = await this.handleNavigateBack();
          break;

        case 'navigateForward':
          result = await this.handleNavigateForward();
          break;

        case 'openWindow': {
          const openWindowPath = resolvedParams.path || resolvedTarget;
          result = await this.handleOpenWindow(openWindowPath!, resolvedParams);
          break;
        }

        case 'replaceUrl': {
          const replaceUrlPath = resolvedParams.path || resolvedTarget || window.location.pathname;
          result = await this.handleReplaceUrl(replaceUrlPath, resolvedParams);
          break;
        }

        case 'apiCall':
          // DevTools: Stale Closure 감지를 위한 상태 캡처 (onSuccess가 있는 경우만)
          if (action.onSuccess && devTools?.isEnabled() && devToolsActionId) {
            const G7Core = (window as any).G7Core;
            const captureState: Record<string, any> = {};
            // _global과 _local 상태 캡처
            if (G7Core?.state?.get) {
              const globalState = G7Core.state.get();
              captureState['_global'] = globalState;
            }
            if (context.state) {
              captureState['_local'] = context.state;
            }
            devTools.registerStateCaptureForHandler?.(devToolsActionId, ['_global', '_local'], captureState);
          }

          // auth_mode 우선, auth_required는 하위 호환
          const authMode = action.auth_mode ?? (action.auth_required ? 'required' : 'none');
          result = await this.handleApiCall(
            resolvedTarget!,
            resolvedParams,
            context,
            authMode
          );
          break;

        case 'login':
          result = await this.handleLogin(
            resolvedTarget!,
            resolvedParams,
            context
          );
          break;

        case 'logout':
          result = await this.handleLogout(resolvedTarget!, context);
          break;

        case 'setState':
          logger.log('[executeAction] setState resolvedParams:', resolvedParams);
          result = await this.handleSetState(resolvedParams, context);
          break;

        case 'setError':
          result = await this.handleSetError(resolvedTarget!, resolvedParams, context);
          break;

        case 'openModal':
          result = await this.handleOpenModal(resolvedTarget!, context);
          break;

        case 'closeModal':
          result = await this.handleCloseModal(context);
          break;

        case 'showAlert':
          result = await this.handleShowAlert(resolvedTarget!, context);
          break;

        case 'toast':
          result = await this.handleToast(resolvedParams, context);
          break;

        case 'switch':
          result = await this.handleSwitch(action, context);
          break;

        case 'conditions':
          result = await this.handleConditions(action, context);
          break;

        case 'sequence':
          result = await this.handleSequence(action, context);
          break;

        case 'parallel':
          result = await this.handleParallel(action, context);
          break;

        case 'loadScript':
          result = await this.handleLoadScript(resolvedParams, action, context);
          break;

        case 'callExternal':
          result = await this.handleCallExternal(resolvedParams, action, context);
          break;

        case 'callExternalEmbed':
          result = await this.handleCallExternalEmbed(resolvedParams, action, context);
          break;

        case 'saveToLocalStorage':
          result = await this.handleSaveToLocalStorage(resolvedParams, context);
          break;

        case 'loadFromLocalStorage':
          result = await this.handleLoadFromLocalStorage(resolvedParams, context);
          break;

        default:
          // 커스텀 핸들러 실행 (바인딩된 target과 params 전달)
          result = await this.handleCustomAction(
            {
              ...action,
              target: resolvedTarget,
              params: resolvedParams,
            },
            context
          );
          break;
      }

      // resultTo 처리: 핸들러 실행 결과를 상태에 저장
      if (action.resultTo && result !== undefined) {
        const { target, key, merge: resultToMerge } = action.resultTo;
        // key에서 {{}} 바인딩 해석
        const resolvedKey = this.resolveValue(key, context.data);
        const resultToMergeMode: 'replace' | 'shallow' | 'deep' = resultToMerge === 'replace' ? 'replace' : resultToMerge === 'shallow' ? 'shallow' : 'deep';

        if (target === '_local' && context.setState) {
          // 로컬 상태에 저장 (dot notation 지원)
          const update = this.buildNestedUpdate(resolvedKey, result);
          // merge 모드에 따라 __mergeMode 메타데이터 추가
          const updateWithMode = resultToMergeMode !== 'deep'
            ? { ...update, __mergeMode: resultToMergeMode }
            : update;
          context.setState(updateWithMode);
          logger.log(`[resultTo] Saved to _local.${resolvedKey} (merge=${resultToMergeMode}):`, result);
        } else if (target === '_local' && this.globalStateUpdater) {
          // init_actions 등에서 componentContext가 없는 경우 globalStateUpdater를 통해 _local 업데이트
          // globalState._local에 저장하여 렌더링 시 DynamicRenderer에서 사용
          const G7Core = (window as any).G7Core;
          const currentState = G7Core?.state?.get() || {};
          const currentLocal = currentState._local || {};
          const newValue = this.buildNestedUpdate(resolvedKey, result);
          let mergedLocal: Record<string, any>;
          if (resultToMergeMode === 'replace') {
            mergedLocal = newValue;
          } else if (resultToMergeMode === 'shallow') {
            mergedLocal = { ...currentLocal, ...newValue };
          } else {
            mergedLocal = this.deepMergeWithState(newValue, currentLocal);
          }
          this.globalStateUpdater({ _local: mergedLocal });

          logger.log(`[resultTo] Saved to _local.${resolvedKey} via globalStateUpdater (merge=${resultToMergeMode}):`, result);
        } else if (target === '_global' && this.globalStateUpdater) {
          // 전역 상태에 저장
          const update = this.buildNestedUpdate(resolvedKey, result);
          this.globalStateUpdater(update);

          logger.log(`[resultTo] Saved to _global.${resolvedKey}:`, result);
        } else if (target === '_isolated' && context.isolatedContext) {
          // 격리된 상태에 저장
          const update = this.buildNestedUpdate(resolvedKey, result);
          context.isolatedContext.mergeState(update, resultToMergeMode);
          logger.log(`[resultTo] Saved to _isolated.${resolvedKey} (merge=${resultToMergeMode}):`, result);
        } else {
          logger.warn(`[resultTo] Cannot save result: target=${target}, setState=${!!context.setState}, globalStateUpdater=${!!this.globalStateUpdater}, isolatedContext=${!!context.isolatedContext}`);
        }
      }

      // 성공 액션 실행 (단일 또는 배열 지원)
      // 여러 액션이 있는 경우 sequence로 처리하여 상태 동기화 보장
      if (action.onSuccess) {
        // DevTools: Stale Closure 감지 (apiCall 완료 후)
        if (devTools?.isEnabled() && devToolsActionId && action.handler === 'apiCall') {
          const G7Core = (window as any).G7Core;
          const currentState: Record<string, any> = {};
          if (G7Core?.state?.get) {
            currentState['_global'] = G7Core.state.get();
          }
          if (context.state) {
            currentState['_local'] = context.state;
          }
          // Stale Closure 감지 - 캡처된 상태와 현재 상태 비교
          devTools.detectStaleClosure?.(
            devToolsActionId,
            `${action.handler} → onSuccess`,
            currentState,
            'callback-state-capture',
            devToolsActionId
          );
        }

        // result와 response 모두 사용 가능하도록 컨텍스트 구성
        // - result: 기존 호환성 유지
        // - response: API 응답임을 명확히 표현 (권장)
        const successContext = {
          ...context,
          data: { ...context.data, result, response: result },
        };
        const successActions = Array.isArray(action.onSuccess) ? action.onSuccess : [action.onSuccess];

        if (successActions.length > 1) {
          // 여러 액션을 sequence로 감싸서 처리 - handleSequence의 상태 동기화 로직 활용
          await this.handleSequence(
            { handler: 'sequence', type: 'click', actions: successActions },
            successContext
          );
        } else if (successActions.length === 1) {
          await this.executeAction(successActions[0], successContext);
        }
      }

      // DevTools: 성공 완료 로깅 (resolvedParams 포함)
      if (devToolsActionId && devTools) {
        devTools.logAction({
          id: devToolsActionId,
          type: action.handler,
          params: this.sanitizeForDevTools(action.params),
          resolvedParams: devToolsResolvedParams,
          startTime,
          endTime: performance.now(),
          duration: performance.now() - startTime,
          status: 'success',
          result: this.sanitizeForDevTools(result),
        });
      }

      return { success: true, data: result };
    } catch (error) {
      const actionError =
        error instanceof ActionError
          ? error
          : new ActionError(
              `Failed to execute action: ${action.handler}`,
              action,
              error instanceof Error ? error : undefined
            );

      // API 응답에서 에러 정보 추출
      const apiResponse = (actionError.originalError as any)?.response || {};
      const responseData = apiResponse.data || {};
      const errorStatus = (actionError.originalError as any)?.status || apiResponse.status || 500;

      // 에러 컨텍스트 생성 (ErrorHandlingResolver와 호환)
      // API 응답의 message를 우선 사용하고, 없으면 ActionError 메시지 사용
      const errorMessage = responseData.message || actionError.message;
      const errorContextData: ErrorContext = {
        status: errorStatus,
        message: errorMessage,
        errors: responseData.errors || apiResponse.errors,
        data: responseData,
        statusText: (actionError.originalError as any)?.statusText,
      };

      // 에러 핸들링 우선순위:
      // 1. action.errorHandling[코드] → action.errorHandling[default]
      // 2. action.onError
      // 3. 레이아웃/템플릿/시스템 기본값 (ErrorHandlingResolver)

      // ErrorHandlingResolver를 통해 핸들러 결정
      const resolver = getErrorHandlingResolver();
      const result = resolver.resolve(errorStatus, {
        errorHandling: action.errorHandling,
        onError: action.onError as any, // ActionDefinition[] → ErrorHandlerConfig[] 변환
      });

      if (result.handler) {
        // DevTools: Stale Closure 감지 (apiCall 에러 발생 후)
        if (devTools?.isEnabled() && devToolsActionId && action.handler === 'apiCall') {
          const G7Core = (window as any).G7Core;
          const currentState: Record<string, any> = {};
          if (G7Core?.state?.get) {
            currentState['_global'] = G7Core.state.get();
          }
          if (context.state) {
            currentState['_local'] = context.state;
          }
          // Stale Closure 감지 - 캡처된 상태와 현재 상태 비교
          devTools.detectStaleClosure?.(
            devToolsActionId,
            `${action.handler} → onError`,
            currentState,
            'callback-state-capture',
            devToolsActionId
          );
        }

        // 핸들러가 결정되면 실행
        const errorContext = {
          ...context,
          data: {
            ...context.data,
            error: errorContextData,
          },
        };

        try {
          // ErrorHandlerConfig를 ActionDefinition으로 변환하여 실행
          const handlerAction: ActionDefinition = {
            type: 'click',
            handler: result.handler.handler as ActionType,
            target: result.handler.target,
            params: result.handler.params,
            actions: result.handler.actions as ActionDefinition[],
          };

          await this.executeAction(handlerAction, errorContext);
          return { success: false, error: actionError };
        } catch (handlerError) {
          logger.error('Error executing error handler:', handlerError);
          return { success: false, error: actionError };
        }
      }

      // DevTools: 에러 로깅 (resolvedParams 포함)
      if (devToolsActionId && devTools) {
        devTools.logAction({
          id: devToolsActionId,
          type: action.handler,
          params: this.sanitizeForDevTools(action.params),
          resolvedParams: devToolsResolvedParams,
          startTime,
          endTime: performance.now(),
          duration: performance.now() - startTime,
          status: 'error',
          error: {
            name: actionError.name,
            message: actionError.message,
            stack: actionError.stack,
          },
        });
      }

      // 핸들러를 찾지 못한 경우 에러를 throw
      throw actionError;
    } finally {
      // API 호출 후 로딩 상태 해제 (컴포넌트별 개별 관리)
      if (action.handler === 'apiCall' && context.setState && actionId) {
        const currentLoadingActions = context.state?.loadingActions || {};
        const { [actionId]: _, ...remainingLoadingActions } = currentLoadingActions;

        context.setState({
          loadingActions: remainingLoadingActions
        });
      }
    }
  }

  /**
   * navigate 액션을 처리합니다.
   *
   * @param target 이동할 경로 (기본 경로)
   * @param params 파라미터 (mergeQuery, query, replace 등)
   * @param context 액션 컨텍스트
   */
  private async handleNavigate(
    target: string,
    params: Record<string, any>,
    context: ActionContext
  ): Promise<void> {
    let finalPath = target;

    // query 파라미터 처리
    if (params.query) {
      if (params.mergeQuery === true) {
        // mergeQuery가 true이면 기존 쿼리스트링과 병합
        finalPath = this.buildMergedQueryPath(target, params.query);
      } else {
        // mergeQuery가 false이거나 없으면 새 쿼리스트링으로 대체
        const queryString = new URLSearchParams();
        for (const [key, value] of Object.entries(params.query)) {
          if (value !== undefined && value !== null && value !== '') {
            // 배열인 경우 각 요소를 개별 파라미터로 추가 (예: sales_status[]=a&sales_status[]=b)
            // 키에 []가 없으면 추가하여 Laravel이 배열로 인식하도록 함
            if (Array.isArray(value)) {
              const arrayKey = key.endsWith('[]') ? key : `${key}[]`;
              for (const item of value) {
                if (item !== undefined && item !== null && item !== '') {
                  queryString.append(arrayKey, String(item));
                }
              }
            } else {
              queryString.set(key, String(value));
            }
          }
        }
        const qs = queryString.toString();
        if (qs) {
          finalPath = `${target}?${qs}`;
        }
      }
    }

    // DEBUG: navigate 호출 전 로깅
    logger.log('handleNavigate:', {
      target,
      params,
      finalPath,
      replace: params.replace,
      windowLocationSearch: window.location.search,
    });

    // replace: true인 경우 - URL만 교체하고 데이터 소스 refetch (컴포넌트 리마운트 없음)
    // 같은 페이지에서 검색/필터 변경 시 사용
    if (params.replace === true) {
      const G7Core = (window as any).G7Core;
      if (G7Core?.updateQueryParams) {
        await G7Core.updateQueryParams(finalPath);
        logger.log('handleNavigate: Used updateQueryParams for replace mode');
        return;
      }
      // G7Core.updateQueryParams가 없으면 fallback으로 React Router 사용
      logger.warn('handleNavigate: G7Core.updateQueryParams not available, falling back to React Router');
    }

    // navigate 함수 확인
    if (!context.navigate) {
      throw new ActionError(
        'Navigate function is not provided in context'
      );
    }

    // 일반 navigate (페이지 전환) - React Router 사용
    context.navigate(finalPath, { replace: params.replace === true });
  }

  /**
   * openWindow 액션을 처리합니다.
   *
   * 새 브라우저 창(탭)으로 지정된 경로를 엽니다.
   *
   * @param target 열 경로
   * @param params 파라미터 (query 등)
   */
  private async handleOpenWindow(
    target: string,
    params: Record<string, any>
  ): Promise<void> {
    let finalPath = target;

    // query 파라미터 처리
    if (params.query) {
      const queryString = new URLSearchParams();
      for (const [key, value] of Object.entries(params.query)) {
        if (value !== null && value !== undefined && value !== '') {
          queryString.set(key, String(value));
        }
      }
      const qs = queryString.toString();
      if (qs) {
        finalPath = `${target}?${qs}`;
      }
    }

    logger.log('handleOpenWindow:', { target, params, finalPath });

    window.open(finalPath, '_blank');
  }

  /**
   * navigateBack 액션을 처리합니다.
   *
   * 브라우저 히스토리에서 뒤로 이동합니다.
   */
  private async handleNavigateBack(): Promise<void> {
    logger.log('handleNavigateBack');
    window.history.back();
  }

  /**
   * navigateForward 액션을 처리합니다.
   *
   * 브라우저 히스토리에서 앞으로 이동합니다.
   */
  private async handleNavigateForward(): Promise<void> {
    logger.log('handleNavigateForward');
    window.history.forward();
  }

  /**
   * replaceUrl 액션을 처리합니다.
   *
   * URL만 변경하고 데이터소스 refetch나 컴포넌트 리마운트를 수행하지 않습니다.
   * 리스트 항목 선택 시 URL에 상태를 반영할 때 사용합니다.
   *
   * @param target 변경할 경로
   * @param params 파라미터 (query, mergeQuery)
   */
  private async handleReplaceUrl(
    target: string,
    params: Record<string, any>
  ): Promise<void> {
    let finalPath = target;

    // query 파라미터 처리 (navigate와 동일 로직)
    if (params.query) {
      if (params.mergeQuery === true) {
        finalPath = this.buildMergedQueryPath(target, params.query);
      } else {
        const queryString = new URLSearchParams();
        for (const [key, value] of Object.entries(params.query)) {
          if (value !== undefined && value !== null && value !== '') {
            if (Array.isArray(value)) {
              const arrayKey = key.endsWith('[]') ? key : `${key}[]`;
              for (const item of value) {
                if (item !== undefined && item !== null && item !== '') {
                  queryString.append(arrayKey, String(item));
                }
              }
            } else {
              queryString.set(key, String(value));
            }
          }
        }
        const qs = queryString.toString();
        if (qs) {
          finalPath = `${target}?${qs}`;
        }
      }
    }

    logger.log('handleReplaceUrl:', { target, params, finalPath });

    // URL만 변경 (데이터소스 refetch 없음, 컴포넌트 리마운트 없음)
    window.history.replaceState(null, '', finalPath);
  }

  /**
   * 기존 URL의 쿼리 파라미터와 새 파라미터를 병합합니다.
   *
   * @param basePath 기본 경로 (쿼리스트링 없이)
   * @param newParams 병합할 새 파라미터
   */
  private buildMergedQueryPath(
    basePath: string,
    newParams: Record<string, any>
  ): string {
    // 현재 URL의 쿼리 파라미터 가져오기
    const currentParams = new URLSearchParams(window.location.search);

    // 새 파라미터 병합 (기존 값 덮어쓰기)
    for (const [key, value] of Object.entries(newParams)) {
      if (value === null || value === undefined || value === '') {
        // 빈 값이면 파라미터 제거
        currentParams.delete(key);
      } else if (Array.isArray(value)) {
        // 배열인 경우 기존 값 제거 후 각 요소를 개별 파라미터로 추가
        // 키에 []가 없으면 추가하여 Laravel이 배열로 인식하도록 함
        const arrayKey = key.endsWith('[]') ? key : `${key}[]`;
        currentParams.delete(key);
        currentParams.delete(arrayKey);
        for (const item of value) {
          if (item !== undefined && item !== null && item !== '') {
            currentParams.append(arrayKey, String(item));
          }
        }
      } else {
        currentParams.set(key, String(value));
      }
    }

    // 쿼리스트링 생성
    const queryString = currentParams.toString();

    // basePath에서 기존 쿼리스트링 제거
    const pathWithoutQuery = basePath.split('?')[0];

    return queryString ? `${pathWithoutQuery}?${queryString}` : pathWithoutQuery;
  }

  /**
   * CSRF 토큰을 가져옵니다 (Laravel Sanctum).
   */
  private async ensureCsrfToken(): Promise<void> {
    try {
      await fetch('/sanctum/csrf-cookie', {
        credentials: 'include',
      });
    } catch (error) {
      throw new ActionError(
        'Failed to fetch CSRF token',
        undefined,
        error instanceof Error ? error : undefined
      );
    }
  }

  /**
   * 쿠키에서 CSRF 토큰을 추출합니다.
   */
  private getCsrfTokenFromCookie(): string | null {
    const name = 'XSRF-TOKEN';
    const value = `; ${document.cookie}`;
    const parts = value.split(`; ${name}=`);
    if (parts.length === 2) {
      const token = parts.pop()?.split(';').shift();
      return token ? decodeURIComponent(token) : null;
    }
    return null;
  }

  /**
   * 객체를 query string으로 변환합니다.
   *
   * 배열은 key[]=value1&key[]=value2 형식으로 직렬화됩니다.
   * null, undefined, 빈 문자열은 제외됩니다.
   *
   * @param obj 변환할 객체
   * @returns query string (? 제외)
   */
  private buildQueryString(obj: Record<string, any>): string {
    const params = new URLSearchParams();

    for (const [key, value] of Object.entries(obj)) {
      if (value === null || value === undefined || value === '') {
        continue;
      }

      if (Array.isArray(value)) {
        // 배열은 key[]=value 형식으로 추가
        for (const item of value) {
          if (item !== null && item !== undefined && item !== '') {
            params.append(`${key}[]`, String(item));
          }
        }
      } else if (typeof value === 'object') {
        // 중첩 객체는 JSON 문자열로 변환
        params.append(key, JSON.stringify(value));
      } else {
        params.append(key, String(value));
      }
    }

    return params.toString();
  }

  /**
   * apiCall 액션을 처리합니다.
   *
   * @param target API 엔드포인트
   * @param params 요청 파라미터
   * @param context 액션 컨텍스트
   * @param authMode 인증 모드 ('none' | 'required' | 'optional')
   */
  private async handleApiCall(
    target: string,
    params: Record<string, any>,
    _context: ActionContext,
    authMode: 'none' | 'required' | 'optional' = 'none'
  ): Promise<any> {
    const { method = 'GET', body, headers, contentType } = params;

    // CSRF token 가져오기 (POST, PUT, DELETE 등)
    if (method !== 'GET' && method !== 'HEAD') {
      await this.ensureCsrfToken();
    }

    // CSRF 토큰을 쿠키에서 추출하여 헤더에 추가
    const csrfToken = this.getCsrfTokenFromCookie();

    // authMode에 따라 Bearer 토큰 추가
    // - 'none': 토큰 미포함
    // - 'required': 토큰 필수 (토큰이 있으면 포함)
    // - 'optional': 토큰이 있으면 포함, 없으면 미포함
    let authHeader: Record<string, string> = {};
    if (authMode === 'required' || authMode === 'optional') {
      const apiClient = getApiClient();
      const token = apiClient.getToken();
      if (token) {
        authHeader = { Authorization: `Bearer ${token}` };
      }
    }

    // GET 요청 시 query 또는 body를 query string으로 변환
    let finalTarget = target;
    const queryData = params.query || (method === 'GET' ? body : null);
    if (queryData && typeof queryData === 'object') {
      const queryString = this.buildQueryString(queryData);
      if (queryString) {
        // target에 이미 query string이 있는 경우 &로 연결, 없으면 ?로 시작
        const separator = target.includes('?') ? '&' : '?';
        finalTarget = `${target}${separator}${queryString}`;
      }
    }

    // 전역 헤더 추출 (패턴 매칭)
    // Stale Closure 방지: G7Core.state.getGlobal/getLocal()로 최신 상태 조회
    // (cartKey 재발급 등 중간에 상태가 변경된 경우에도 최신 값 사용)
    const currentGlobalState = (window as any).G7Core?.state?.getGlobal?.() || _context.state?._global || {};
    const currentLocalState = (window as any).G7Core?.state?.getLocal?.() || _context.state?._local || {};

    const expressionContext: Record<string, any> = {
      _global: currentGlobalState,
      _local: currentLocalState,
    };
    const globalHeadersResolved = this.getMatchingGlobalHeaders(target, expressionContext);

    // g7_locale이 설정되어 있으면 Accept-Language 헤더로 전송
    const localeHeader: Record<string, string> = {};
    if (typeof window !== 'undefined') {
      const locale = localStorage.getItem('g7_locale');
      if (locale) {
        localeHeader['Accept-Language'] = locale;
      }
    }

    // multipart/form-data 여부 판단 (레이아웃 JSON의 contentType 파라미터 기반)
    const isMultipart = contentType === 'multipart/form-data';

    const options: RequestInit = {
      method,
      headers: {
        // multipart/form-data는 Content-Type을 설정하지 않음 (브라우저가 boundary 포함하여 자동 설정)
        ...(isMultipart ? {} : { 'Content-Type': 'application/json' }),
        Accept: 'application/json',
        ...localeHeader, // g7_locale → Accept-Language
        ...(csrfToken && { 'X-XSRF-TOKEN': csrfToken }), // CSRF 토큰을 헤더에 포함
        ...authHeader, // Bearer 토큰 (auth_required가 true인 경우)
        ...globalHeadersResolved, // 전역 헤더 (패턴 매칭)
        ...headers, // 개별 헤더가 globalHeaders를 덮어씀
      },
      credentials: 'include', // 쿠키 전송 활성화
    };

    if (body && method !== 'GET') {
      if (isMultipart) {
        // multipart/form-data: FormData 객체로 변환
        const formData = new FormData();
        for (const [key, value] of Object.entries(body)) {
          if (value instanceof File || value instanceof Blob) {
            formData.append(key, value);
          } else if (value !== null && value !== undefined) {
            formData.append(
              key,
              typeof value === 'object' ? JSON.stringify(value) : String(value)
            );
          }
        }
        options.body = formData;
      } else {
        options.body = JSON.stringify(body);
      }
    }

    // DevTools 요청 추적 시작
    const devTools = getDevTools();
    let requestId: string | null = null;
    if (devTools?.isEnabled()) {
      requestId = devTools.trackRequest(finalTarget, method);
    }

    try {
      const response = await fetch(finalTarget, options);

      // 응답 본문 파싱 (성공/실패 모두)
      let responseData: any;
      try {
        responseData = await response.json();
      } catch {
        responseData = null;
      }

      // DevTools 요청 완료 추적 (성공/에러 모두 상태 코드와 응답 기록)
      if (requestId && devTools?.isEnabled()) {
        devTools.completeRequest(requestId, response.status, responseData);
        requestId = null; // 중복 기록 방지
      }

      if (!response.ok) {
        const errorData = responseData || {};

        // API 응답 데이터를 포함한 에러 객체 생성
        const apiError: any = new Error(
          errorData.message || `API call failed: ${response.statusText}`
        );
        apiError.response = errorData; // API 응답 전체를 포함
        apiError.status = response.status;
        apiError.statusText = response.statusText;

        throw new ActionError(
          errorData.message || `API call failed: ${response.statusText}`,
          undefined,
          apiError
        );
      }

      return responseData;
    } catch (error) {
      // 네트워크 오류 등으로 fetch 자체가 실패한 경우
      if (requestId && devTools?.isEnabled()) {
        devTools.failRequest(requestId, error instanceof Error ? error.message : String(error));
      }
      throw error;
    }
  }

  /**
   * login 액션을 처리합니다.
   *
   * AuthManager를 통해 로그인하고 토큰을 저장합니다.
   *
   * @param target 인증 타입 (admin 또는 user)
   * @param params 요청 파라미터 (body에 email, password 포함)
   * @param context 액션 컨텍스트
   */
  private async handleLogin(
    target: string,
    params: Record<string, any>,
    _context: ActionContext
  ): Promise<any> {
    const { body } = params;

    if (!body || !body.email || !body.password) {
      throw new ActionError(
        'Login requires email and password in body params'
      );
    }

    // target을 인증 타입으로 사용 (admin 또는 user)
    const authType: AuthType = target === 'user' ? 'user' : 'admin';

    // 로그인 엔드포인트 결정 (globalHeaders 패턴 매칭용)
    // ApiClient는 baseURL이 '/api'이므로 실제 요청 경로에 '/api' prefix 추가
    const loginEndpoint = authType === 'admin'
      ? '/api/auth/admin/login'
      : '/api/auth/login';

    // globalHeaders에서 패턴 매칭되는 헤더 추출
    // Stale Closure 방지: G7Core.state.getGlobal/getLocal()로 최신 상태 조회
    // (cartKey 재발급 등 중간에 상태가 변경된 경우에도 최신 값 사용)
    const currentGlobalState = (window as any).G7Core?.state?.getGlobal?.() || _context.state?._global || {};
    const currentLocalState = (window as any).G7Core?.state?.getLocal?.() || _context.state?._local || {};

    const expressionContext: Record<string, any> = {
      _global: currentGlobalState,
      _local: currentLocalState,
    };
    const globalHeadersResolved = this.getMatchingGlobalHeaders(loginEndpoint, expressionContext);

    const authManager = AuthManager.getInstance();

    try {
      // AuthManager.login()을 통해 로그인 및 토큰 저장
      // globalHeaders가 있으면 options.headers로 전달
      const loginOptions = Object.keys(globalHeadersResolved).length > 0
        ? { headers: globalHeadersResolved }
        : undefined;

      const user = await authManager.login(
        authType,
        { email: body.email, password: body.password },
        loginOptions
      );

      return { user };
    } catch (error: any) {
      // AuthManager에서 이미 API 응답 메시지를 추출한 에러를 throw하므로
      // error.message에 실제 서버 응답 메시지가 들어있음
      const errorMessage = error.message || 'Login failed';

      const apiError: any = new Error(errorMessage);
      apiError.response = error.response?.data || {};
      apiError.status = error.status || error.response?.status || 500;

      throw new ActionError(
        errorMessage,
        undefined,
        apiError
      );
    }
  }

  /**
   * logout 액션을 처리합니다.
   *
   * AuthManager를 통해 로그아웃하고 토큰을 삭제합니다.
   *
   * @param target 사용하지 않음
   * @param context 액션 컨텍스트
   */
  private async handleLogout(
    _target: string,
    _context: ActionContext
  ): Promise<void> {
    const authManager = AuthManager.getInstance();
    await authManager.logout();
  }

  /**
   * setState 액션을 처리합니다.
   *
   * target이 'global'이면 전역 상태 업데이트, 그 외에는 컴포넌트 로컬 상태 업데이트
   *
   * 깊은 병합 지원:
   * - { formData: { name: "value" } } → formData.name만 업데이트, 다른 필드 유지
   * - "..." spread 연산자도 여전히 지원 (하위 호환성)
   *
   * Dot notation 경로 지원:
   * - target: "_local.formData.name" → _local.formData.name에 value 설정
   * - 부분 업데이트로 다른 필드는 유지됨
   *
   * @param params 상태 업데이트 파라미터 (target, payload 포함)
   * @param context 액션 컨텍스트
   * @returns 병합된 상태 (sequence 핸들러에서 context.state 동기화에 사용)
   */
  private async handleSetState(
    params: Record<string, any>,
    context: ActionContext
  ): Promise<Record<string, any> | undefined> {
    logger.log('[handleSetState] START, params:', params);
    const { target = 'component', scope, merge, ...payload } = params;

    // DevTools 추적 시작
    const devTools = getDevTools();
    const G7Core = (window as any).G7Core;

    // $parent 또는 $root 타겟 처리 (Phase 4: $parent 바인딩 컨텍스트)
    // 예: "$parent._local", "$parent._global", "$parent._local.form.name", "$root._local"
    if (typeof target === 'string' && (target.startsWith('$parent.') || target.startsWith('$root.'))) {
      return this.handleParentScopeSetState(target, payload, merge, context);
    }
    // sequence 내에서 실행될 때는 context.state를 우선 사용
    // 이를 통해 비동기 React 상태 업데이트를 기다리지 않고도
    // 이전 setState의 결과를 올바르게 참조할 수 있음
    const currentState = (context.state && Object.keys(context.state).length > 0)
      ? context.state
      : (G7Core?.state?.get() || {});

    // 상태 경로 결정
    const statePath = target === 'global' ? '_global' : target.startsWith('_local.') ? target : '_local';

    // 이전 값 캡처
    const oldValue = statePath === '_global'
      ? currentState._global
      : statePath.startsWith('_local.')
        ? this.getNestedProperty(currentState._local || {}, statePath.slice(7))
        : currentState._local;

    // DevTools: 상태 변경 시작
    const setStateId = devTools?.isEnabled() ? devTools.startStateChange(
      statePath,
      oldValue,
      payload,
      {
        actionId: context.actionId,
        handlerType: 'setState',
        source: `target=${target}`,
      }
    ) : undefined;

    // params는 이미 resolveParams에서 처리되었으므로 추가 평가 불필요
    // merge 옵션: "replace" | "shallow" | "deep" (기본값)
    // - replace: 기존 상태 완전 무시, 새 값으로 교체
    // - shallow: 최상위 키만 덮어쓰기 (DynamicRenderer에서 처리)
    // - deep: 재귀적 깊은 병합 (ActionDispatcher에서 수행)
    const mergeMode: 'replace' | 'shallow' | 'deep' = merge === 'replace' ? 'replace' : merge === 'shallow' ? 'shallow' : 'deep';
    const resolvedPayload = mergeMode !== 'deep'
      ? { ...payload, __mergeMode: mergeMode as 'replace' | 'shallow', ...(setStateId ? { __setStateId: setStateId } : {}) }
      : { ...payload, ...(setStateId ? { __setStateId: setStateId } : {}) };

    if (target === 'global') {
      // 전역 상태 업데이트
      if (!this.globalStateUpdater) {
        logger.warn('Global state updater is not set');
        if (setStateId && devTools) devTools.completeStateChange(setStateId);
        return undefined;
      }
      // 전역 상태는 항상 G7Core.state.get()에서 최신 값을 가져옴
      // G7Core.state.get()은 이미 _global의 내용물을 직접 반환함 (templateApp.getGlobalState())
      // globalStateUpdater 호출 후 즉시 G7Core.state.get()에 반영되므로
      // sequence 내에서도 항상 최신 상태를 참조함 (별도 동기화 불필요)
      // 주의: currentState._global 병합 시 sequence 내 다른 핸들러(closeModal 등)가
      //       변경한 값을 stale 값으로 덮어쓰는 버그 발생 (2026-01-30 수정)
      const currentGlobal = G7Core?.state?.get() || {};

      // dot notation 키 처리를 위해 deepMergeWithState 적용
      // __setStateId, __mergeMode는 메타데이터이므로 병합에서 제외 후 다시 추가
      const { __setStateId: _setStateId, __mergeMode: _mergeMode, ...payloadWithoutMeta } = resolvedPayload as any;
      const mergedPayload = mergeMode === 'deep'
        ? this.deepMergeWithState(payloadWithoutMeta, currentGlobal)
        : payloadWithoutMeta;  // replace, shallow: 병합 없이 payload 그대로
      const finalPayload = {
        ...mergedPayload,
        ...(setStateId ? { __setStateId: setStateId } : {}),
        ...(mergeMode !== 'deep' ? { __mergeMode: mergeMode as 'replace' | 'shallow' } : {}),
      };

      logger.log('setState global:', finalPayload);
      this.globalStateUpdater(finalPayload);

      // DevTools: 상태 변경 완료 (global은 즉시 완료)
      // Note: 실제 렌더링은 비동기로 발생하므로 setTimeout으로 완료 처리
      if (setStateId && devTools) setTimeout(() => devTools.completeStateChange(setStateId), 0);
      // 전역 상태 payload를 반환하여 sequence에서 _global 동기화에 사용
      // __mergeMode를 포함하여 sequence에서 올바른 병합 전략을 선택할 수 있도록 함
      return { __target: 'global', ...(mergeMode !== 'deep' ? { __mergeMode: mergeMode } : {}), ...mergedPayload };
    } else if (target === 'isolated') {
      // 격리된 상태 업데이트 (isolatedState 속성으로 생성된 독립 스코프)
      const isolatedContext = context.isolatedContext;

      if (isolatedContext) {
        // IsolatedStateContext.mergeState를 통해 상태 업데이트
        const { __mergeMode, __setStateId, ...cleanPayload } = resolvedPayload as any;
        isolatedContext.mergeState(cleanPayload, mergeMode);
        logger.log('[handleSetState] isolated state updated:', cleanPayload, 'mergeMode:', mergeMode);

        // DevTools: 상태 변경 완료
        if (setStateId && devTools) setTimeout(() => devTools.completeStateChange(setStateId), 0);
        return { __target: 'isolated', ...cleanPayload };
      } else {
        // 폴백: IsolatedStateContext가 없으면 local로 처리
        logger.warn(
          '[handleSetState] isolated target used but no IsolatedStateContext found. ' +
          'Make sure the component has "isolatedState" attribute. Falling back to local state.'
        );

        // local 상태로 폴백
        if (context.setState) {
          // engine-v1.17.10: pendingLocal 우선 사용 (Form 자동 바인딩 경합 방지)
          const pendingLocal = (window as any).__g7PendingLocalState;
          const currentState = pendingLocal || context.state || {};
          const finalPayload = mergeMode === 'deep'
            ? this.deepMergeWithState(resolvedPayload, currentState)
            : resolvedPayload;  // replace, shallow: DynamicRenderer에서 처리
          context.setState(finalPayload);
          if (setStateId && devTools) setTimeout(() => devTools.completeStateChange(setStateId), 0);
          return finalPayload;
        } else {
          if (setStateId && devTools) devTools.completeStateChange(setStateId);
          throw new ActionError(
            'isolated target used but no IsolatedStateContext found and no setState function in context'
          );
        }
      }
    } else if (target === 'local' || target === 'component' || target === '_local') {
      // 컴포넌트 로컬 상태 업데이트
      // merge: "shallow" 옵션이면 얕은 병합 (DynamicRenderer에서 처리)
      // 기본값은 깊은 병합 (여기서 수행)

      // scope 옵션: 'parent' 또는 'root'인 경우 레이아웃 컨텍스트 스택에서 타겟 컨텍스트 사용
      // 모달 등 별도 스코프에서 부모 레이아웃의 상태를 업데이트할 때 유용
      if (scope === 'parent' || scope === 'root') {
        const layoutContextStack: Array<{ state: Record<string, any>; setState: (updates: any) => void }> =
          (window as any).__g7LayoutContextStack || [];

        if (layoutContextStack.length > 0) {
          // parent: 스택의 마지막 (바로 이전 컨텍스트)
          // root: 스택의 첫 번째 (최상위 컨텍스트)
          const targetContext = scope === 'parent'
            ? layoutContextStack[layoutContextStack.length - 1]
            : layoutContextStack[0];

          if (targetContext?.setState) {
            const { __mergeMode, __setStateId, ...cleanPayload } = resolvedPayload as any;
            const finalPayload = mergeMode === 'deep'
              ? this.deepMergeWithState(cleanPayload, targetContext.state || {})
              : cleanPayload;  // replace, shallow: 병합 없이 payload 그대로

            logger.log(`[handleSetState] scope=${scope}: 타겟 컨텍스트에 상태 업데이트`, finalPayload);
            targetContext.setState(finalPayload);

            if (setStateId && devTools) setTimeout(() => devTools.completeStateChange(setStateId), 0);
            return finalPayload;
          }
        }

        // 스택에 컨텍스트가 없으면 경고 후 current로 폴백
        logger.warn(`[handleSetState] scope=${scope}: 레이아웃 컨텍스트 스택이 비어있습니다. current로 폴백합니다.`);
      }

      // scope: 'current' (기본값) 또는 폴백
      // engine-v1.17.2: _isDispatchFallbackContext가 true면 컴포넌트 setState가 아닌 전역 폴백(setGlobalState)임
      // 이 경우 context.setState를 호출하면 _global이 업데이트되므로,
      // globalStateUpdater({ _local: ... }) 경로를 사용해야 _local이 올바르게 업데이트됨
      // (비동기 콜백에서 G7Core.dispatch 호출 시 발생하는 문제 해결)
      const isRealComponentContext = context.setState && !(context as any)._isDispatchFallbackContext;
      logger.log('[handleSetState] isRealComponentContext:', isRealComponentContext,
        'context.setState:', !!context.setState,
        '_isDispatchFallbackContext:', (context as any)._isDispatchFallbackContext);
      if (isRealComponentContext) {
        // 컴포넌트 컨텍스트가 있으면 직접 setState 호출
        logger.log('[handleSetState] Using COMPONENT setState path');
        logger.log('[handleSetState] resolvedPayload:', resolvedPayload);
        logger.log('[handleSetState] context.state:', context.state);
        logger.log('[handleSetState] mergeMode:', mergeMode);
        // engine-v1.17.10: Form 자동 바인딩 후 onComplete에서 호출 시,
        // context.state는 아직 이전 값(React setState 비동기).
        // __g7PendingLocalState에 자동 바인딩이 반영된 최신 상태가 있으므로 우선 사용.
        const pendingLocal = (window as any).__g7PendingLocalState;
        const currentState = pendingLocal || context.state || {};

        // engine-v1.24.6: context.setState에는 변경 필드만 전달 (전체 _local 축적 방지)
        // 이전: deep 모드에서 deepMerge(resolvedPayload, currentState) = 전체 _local 스냅샷을 전달
        //       → localDynamicState에 전체 _local 축적 → SPA 이동 후 stale 필드가 dataContext._local override
        // 수정: resolvedPayload(변경 필드)만 전달, handleLocalSetState가 effectivePrev와 병합 처리
        // 주의: deepMergeWithState({}, payload)로 dot notation 변환 수행 (전체 상태 병합은 하지 않음)
        const convertedPayload = mergeMode === 'deep'
          ? this.deepMergeWithState(resolvedPayload, {})
          : resolvedPayload;
        logger.log('[handleSetState] convertedPayload (변경 필드만, dot notation 변환):', convertedPayload);
        context.setState!(convertedPayload);

        // __g7PendingLocalState에는 전체 병합 결과 저장 (getLocal() 동기화용)
        // context.setState와 분리: setState는 변경분만, pending은 전체 상태
        const fullMergedState = mergeMode === 'deep'
          ? this.deepMergeWithState(resolvedPayload, currentState)
          : mergeMode === 'shallow'
            ? { ...(context.state || {}), ...resolvedPayload }
            : resolvedPayload;  // replace
        const { __mergeMode: _pmm, __setStateId: _pssid, ...pendingExpected } = fullMergedState as any;
        (window as any).__g7PendingLocalState = pendingExpected;
        logger.log('[handleSetState] __g7PendingLocalState updated:', pendingExpected);

        // engine-v1.17.5: dataKey 자동 바인딩이 있는 컴포넌트에서 setState 핸들러 호출 시
        // dynamicState(Form 자동 바인딩)가 stale 값을 가질 수 있음
        // extendedDataContext 병합 순서: dataContext._local → dynamicState → __g7ForcedLocalFields
        // __g7ForcedLocalFields에 업데이트된 필드를 저장하여 최우선으로 적용
        //
        // engine-v1.17.9: resolvedPayload(변경된 필드만) 사용 — finalPayload(전체 상태 스냅샷) 사용 금지
        // deep 모드에서 finalPayload = deepMergeWithState(resolvedPayload, currentState) → 전체 상태 포함
        // 이 전체 스냅샷을 forcedLocalFields에 넣으면, sequence 내 후속 커스텀 핸들러가
        // localDynamicState에 설정한 값을 forcedLocalFields의 stale 값이 덮어쓰는 문제 발생
        // (예: selectedOptionItems: [] 가 [newItem]을 덮어씀)
        const { __mergeMode: _mm, __setStateId: _ssid, ...cleanPayloadForForced } = resolvedPayload as any;
        if (mergeMode === 'replace') {
          // replace 모드: 기존 forcedFields 무시, payload만으로 리셋
          (window as any).__g7ForcedLocalFields = cleanPayloadForForced;
        } else {
          const existingForced = (window as any).__g7ForcedLocalFields || {};
          (window as any).__g7ForcedLocalFields = this.deepMergeWithState(cleanPayloadForForced, existingForced);
        }

        logger.log('[handleSetState] __g7ForcedLocalFields updated:', cleanPayloadForForced);

        // DevTools: 렌더링 완료 후 상태 변경 완료 (DynamicRenderer에서 처리되지만 fallback으로 setTimeout 사용)
        if (setStateId && devTools) setTimeout(() => devTools.completeStateChange(setStateId), 0);
        // sequence에서 _local 동기화에 사용하기 위해 __target 마커 추가
        return { __target: 'local', ...fullMergedState };
      } else if (this.globalStateUpdater) {
        // init_actions 등에서 componentContext가 없는 경우 globalStateUpdater를 통해 _local 업데이트
        logger.log('[handleSetState] Using GLOBAL STATE UPDATER path for _local');

        // engine-v1.17.2: sequence 내 연속 setState 시 이전 setState 결과 참조 지원
        // globalStateUpdater는 비동기이므로 __g7PendingLocalState를 우선 사용
        const pendingLocal = (window as any).__g7PendingLocalState;
        const currentLocal = pendingLocal || currentState._local || {};
        logger.log('[handleSetState] currentLocal (with pending):', currentLocal);

        const finalLocal = mergeMode === 'deep'
          ? this.deepMergeWithState(resolvedPayload, currentLocal)
          : resolvedPayload;  // replace, shallow: DynamicRenderer에서 처리
        logger.log('[handleSetState] finalLocal (merged):', finalLocal);
        this.globalStateUpdater({
          _local: finalLocal,
        });

        // engine-v1.17.2: 다음 setState에서 최신 _local 참조 가능하도록 pending 상태 업데이트
        (window as any).__g7PendingLocalState = finalLocal;
        logger.log('[handleSetState] __g7PendingLocalState updated for globalStateUpdater path');

        // DevTools: 상태 변경 완료
        if (setStateId && devTools) setTimeout(() => devTools.completeStateChange(setStateId), 0);
        // sequence에서 _local 동기화에 사용하기 위해 __target 마커 추가
        return { __target: 'local', ...finalLocal };
      } else {
        if (setStateId && devTools) devTools.completeStateChange(setStateId);
        throw new ActionError(
          'setState function is not provided in context and globalStateUpdater is not set'
        );
      }
    } else if (target.startsWith('_local.')) {
      // Dot notation 경로 지원: _local.formData.name 형식
      // _local. 제거 후 경로 파싱
      const path = target.slice(7); // "_local." 제거
      // __mergeMode, __setStateId를 제외한 payload에서 value 추출
      const { __mergeMode, __setStateId, ...cleanPayload } = resolvedPayload as any;
      const value = cleanPayload.value !== undefined
        ? cleanPayload.value
        : Object.values(cleanPayload)[0];

      if (context.setState) {
        // 컴포넌트 컨텍스트가 있으면 직접 setState 호출
        // engine-v1.17.10: pendingLocal 우선 사용 (Form 자동 바인딩 경합 방지)
        const pendingLocal = (window as any).__g7PendingLocalState;
        const currentState = pendingLocal || context.state || {};
        const update = this.createNestedUpdate(path, value, currentState);
        const mergedState = { ...currentState, ...update };
        context.setState(update);
        if (setStateId && devTools) setTimeout(() => devTools.completeStateChange(setStateId), 0);
        return mergedState;
      } else {
        if (setStateId && devTools) devTools.completeStateChange(setStateId);
        throw new ActionError(
          'setState function is not provided in context'
        );
      }
    } else {
      // 컴포넌트 로컬 상태 업데이트 (기본)
      // merge: "shallow" 옵션이면 얕은 병합, 기본값은 깊은 병합
      if (!context.setState) {
        if (setStateId && devTools) devTools.completeStateChange(setStateId);
        throw new ActionError(
          'setState function is not provided in context'
        );
      }
      // engine-v1.17.10: pendingLocal 우선 사용 (Form 자동 바인딩 경합 방지)
      const pendingLocal = (window as any).__g7PendingLocalState;
      const currentState = pendingLocal || context.state || {};
      const finalPayload = mergeMode === 'deep'
        ? this.deepMergeWithState(resolvedPayload, currentState)
        : resolvedPayload;  // replace, shallow: DynamicRenderer에서 처리
      context.setState(finalPayload);
      // DevTools: 상태 변경 완료
      if (setStateId && devTools) setTimeout(() => devTools.completeStateChange(setStateId), 0);
      return finalPayload;
    }
  }

  /**
   * $parent 또는 $root 스코프의 상태 업데이트를 처리합니다.
   *
   * 모달 등 자식 레이아웃에서 부모 레이아웃의 상태를 직접 수정할 때 사용합니다.
   * `__g7LayoutContextStack`에서 부모/루트 컨텍스트를 가져와 상태를 업데이트합니다.
   *
   * @param target 타겟 문자열 (예: "$parent._local", "$parent._global", "$parent._local.form.name")
   * @param payload 업데이트할 데이터
   * @param merge 병합 모드 ('shallow' | undefined)
   * @param context 현재 액션 컨텍스트
   * @returns 업데이트된 상태 또는 undefined
   *
   * @example
   * // 부모의 _local 전체 업데이트
   * { "handler": "setState", "params": { "target": "$parent._local", "form": { "name": "value" } } }
   *
   * @example
   * // 부모의 _local 내 특정 경로 업데이트
   * { "handler": "setState", "params": { "target": "$parent._local.form.name", "value": "newValue" } }
   *
   * @example
   * // 부모의 _global 업데이트
   * { "handler": "setState", "params": { "target": "$parent._global", "modalResult": { "confirmed": true } } }
   *
   * @since engine-v1.16.0
   */
  private async handleParentScopeSetState(
    target: string,
    payload: Record<string, any>,
    merge: string | undefined,
    context: ActionContext
  ): Promise<Record<string, any> | undefined> {
    const devTools = getDevTools();
    const G7Core = (window as any).G7Core;
    const mergeMode: 'replace' | 'shallow' | 'deep' = merge === 'replace' ? 'replace' : merge === 'shallow' ? 'shallow' : 'deep';

    // 타겟 파싱: "$parent._local" → scope='$parent', stateType='_local', path=undefined
    // "$parent._local.form.name" → scope='$parent', stateType='_local', path='form.name'
    const isParent = target.startsWith('$parent.');
    const scopePrefix = isParent ? '$parent.' : '$root.';
    const afterScope = target.slice(scopePrefix.length); // "_local" 또는 "_local.form.name" 또는 "_global"

    let stateType: '_local' | '_global';
    let nestedPath: string | undefined;

    if (afterScope === '_local' || afterScope === '_global') {
      stateType = afterScope as '_local' | '_global';
      nestedPath = undefined;
    } else if (afterScope.startsWith('_local.')) {
      stateType = '_local';
      nestedPath = afterScope.slice(7); // "_local." 제거
    } else if (afterScope.startsWith('_global.')) {
      stateType = '_global';
      nestedPath = afterScope.slice(8); // "_global." 제거
    } else {
      logger.warn(`[handleParentScopeSetState] 지원하지 않는 타겟 형식: ${target}. _local 또는 _global으로 시작해야 합니다.`);
      return undefined;
    }

    // 레이아웃 컨텍스트 스택에서 부모/루트 컨텍스트 가져오기
    const layoutContextStack: Array<{
      state: Record<string, any>;
      setState: (updates: any) => void;
      dataContext?: Record<string, any>;
    }> = (window as any).__g7LayoutContextStack || [];

    if (layoutContextStack.length === 0) {
      logger.warn(`[handleParentScopeSetState] 레이아웃 컨텍스트 스택이 비어있습니다. target=${target}`);
      return undefined;
    }

    // parent: 스택의 마지막 (바로 이전 컨텍스트)
    // root: 스택의 첫 번째 (최상위 컨텍스트)
    const targetContext = isParent
      ? layoutContextStack[layoutContextStack.length - 1]
      : layoutContextStack[0];

    if (!targetContext) {
      logger.warn(`[handleParentScopeSetState] 타겟 컨텍스트를 찾을 수 없습니다. target=${target}`);
      return undefined;
    }

    // DevTools 추적
    const oldValue = stateType === '_global'
      ? targetContext.state?._global
      : targetContext.state?._local;
    const setStateId = devTools?.isEnabled() ? devTools.startStateChange(
      target,
      oldValue,
      payload,
      {
        actionId: (context as any).actionId,
        handlerType: 'setState',
        source: `target=${target}`,
      }
    ) : undefined;

    // 메타데이터 제거
    const { __mergeMode, __setStateId, ...cleanPayload } = payload as any;

    if (stateType === '_global') {
      // $parent._global 또는 $root._global 업데이트
      // 전역 상태는 globalStateUpdater를 통해 업데이트
      if (!this.globalStateUpdater) {
        logger.warn('[handleParentScopeSetState] globalStateUpdater가 설정되지 않았습니다.');
        if (setStateId && devTools) devTools.completeStateChange(setStateId);
        return undefined;
      }

      const currentGlobal = G7Core?.state?.get() || {};

      if (nestedPath) {
        // $parent._global.path.to.field 형식 - 중첩 경로 업데이트
        const value = cleanPayload.value !== undefined ? cleanPayload.value : Object.values(cleanPayload)[0];
        const nestedUpdate = this.createNestedUpdate(nestedPath, value, currentGlobal);
        const finalPayload = { ...currentGlobal, ...nestedUpdate };
        logger.log(`[handleParentScopeSetState] ${target}: 전역 상태 중첩 경로 업데이트`, finalPayload);
        this.globalStateUpdater(finalPayload);
      } else {
        // $parent._global 형식 - 전체 병합
        const finalPayload = mergeMode === 'deep'
          ? this.deepMergeWithState(cleanPayload, currentGlobal)
          : cleanPayload;  // replace, shallow: 병합 없이 payload 그대로
        logger.log(`[handleParentScopeSetState] ${target}: 전역 상태 업데이트 (mergeMode=${mergeMode})`, finalPayload);
        this.globalStateUpdater(mergeMode !== 'deep' ? { ...finalPayload, __mergeMode: mergeMode } : finalPayload);
      }

      if (setStateId && devTools) setTimeout(() => devTools.completeStateChange(setStateId), 0);
      return { __target: target, ...cleanPayload };
    } else {
      // $parent._local 또는 $root._local 업데이트
      if (!targetContext.setState) {
        logger.warn(`[handleParentScopeSetState] 타겟 컨텍스트에 setState가 없습니다. target=${target}`);
        if (setStateId && devTools) devTools.completeStateChange(setStateId);
        return undefined;
      }

      const currentLocal = targetContext.state || {};

      if (nestedPath) {
        // $parent._local.path.to.field 형식 - 중첩 경로 업데이트
        const value = cleanPayload.value !== undefined ? cleanPayload.value : Object.values(cleanPayload)[0];
        const update = this.createNestedUpdate(nestedPath, value, currentLocal);
        const mergedState = { ...currentLocal, ...update };
        logger.log(`[handleParentScopeSetState] ${target}: 로컬 상태 중첩 경로 업데이트`, update);
        targetContext.setState(update);
        // 스택의 state와 dataContext._local 모두 업데이트하여
        // 다음 setState 호출 시 currentLocal이 최신 값을 읽고,
        // 모달에서 $parent._local 바인딩이 최신 값을 읽도록 함
        targetContext.state = mergedState;
        if (targetContext.dataContext) {
          targetContext.dataContext._local = mergedState;
        }
        // ParentContextProvider를 통해 모달만 선택적으로 리렌더링
        // 전체 앱 리렌더링 없이 모달만 업데이트됨
        triggerModalParentUpdate();
        if (setStateId && devTools) setTimeout(() => devTools.completeStateChange(setStateId), 0);
        return mergedState;
      } else {
        // $parent._local 형식 - 전체 병합
        // DynamicRenderer.handleLocalSetState에서 __mergeMode를 참조하여 병합 처리
        const setStatePayload = mergeMode !== 'deep'
          ? { ...cleanPayload, __mergeMode: mergeMode }
          : cleanPayload;
        // 실제 상태 결과 계산 (스택 동기화용, 메타데이터 없음)
        const expectedState = mergeMode === 'replace'
          ? cleanPayload
          : mergeMode === 'shallow'
            ? { ...currentLocal, ...cleanPayload }
            : this.deepMergeWithState(cleanPayload, currentLocal);
        logger.log(`[handleParentScopeSetState] ${target}: 로컬 상태 업데이트 (mergeMode=${mergeMode})`, expectedState);
        targetContext.setState(setStatePayload);
        // 스택의 state와 dataContext._local 모두 업데이트하여
        // 다음 setState 호출 시 currentLocal이 최신 값을 읽고,
        // 모달에서 $parent._local 바인딩이 최신 값을 읽도록 함
        targetContext.state = expectedState;
        if (targetContext.dataContext) {
          targetContext.dataContext._local = expectedState;
        }
        // ParentContextProvider를 통해 모달만 선택적으로 리렌더링
        // 전체 앱 리렌더링 없이 모달만 업데이트됨
        triggerModalParentUpdate();
        if (setStateId && devTools) setTimeout(() => devTools.completeStateChange(setStateId), 0);
        return expectedState;
      }
    }
  }

  /**
   * payload를 현재 상태와 깊은 병합합니다.
   *
   * 중첩 객체의 경우 기존 상태의 다른 필드를 유지하면서 지정된 필드만 업데이트합니다.
   * 단, 'errors' 키는 항상 완전히 교체됩니다 (validation 에러는 병합하면 안됨).
   *
   * @param payload 업데이트할 데이터
   * @param currentState 현재 상태
   * @returns 병합된 업데이트 객체
   *
   * @example
   * // currentState: { formData: { name: "old", email: "a@b.com" }, hasChanges: false }
   * // payload: { formData: { name: "new" }, hasChanges: true }
   * // result: { formData: { name: "new", email: "a@b.com" }, hasChanges: true }
   *
   * @example errors는 완전 교체
   * // currentState: { errors: { name: ["필수"], email: ["필수"] } }
   * // payload: { errors: { email: ["필수"] } }
   * // result: { errors: { email: ["필수"] } }  -- name 에러 제거됨
   */
  private deepMergeWithState(
    payload: Record<string, any>,
    currentState: Record<string, any>
  ): Record<string, any> {
    // 깊은 병합에서 제외할 키 목록 (완전 교체되어야 하는 필드들)
    const replaceOnlyKeys = ['errors'];

    // '...' 키가 있으면 먼저 spread 처리
    let processedPayload = payload;
    if ('...' in payload) {
      const spreadValue = payload['...'];
      if (spreadValue && typeof spreadValue === 'object' && !Array.isArray(spreadValue)) {
        // spread 값을 먼저 펼치고, 나머지 키를 덮어쓰기
        const { '...': _, ...rest } = payload;
        processedPayload = { ...spreadValue, ...rest };
      } else {
        // spread 값이 유효하지 않으면 '...' 키만 제거
        const { '...': _, ...rest } = payload;
        processedPayload = rest;
      }
    }

    // 기존 상태를 기반으로 시작 (모든 기존 필드 유지)
    const result: Record<string, any> = { ...currentState };

    for (const [key, value] of Object.entries(processedPayload)) {
      // errors 등 특정 키는 항상 완전히 교체 (validation 에러 등은 병합하면 안됨)
      if (replaceOnlyKeys.includes(key)) {
        result[key] = value;
        continue;
      }

      // Dot notation 키 처리: "form.language_currency.currencies" 형태
      // 단, spread 연산자 '...'는 제외
      if (key.includes('.') && key !== '...') {
        // 중요: 여러 dot notation 키가 같은 루트를 공유할 때 (예: form.a, form.b)
        // 이전 키의 변경사항이 유지되도록 result를 기준으로 nestedUpdate 생성
        const nestedUpdate = this.createNestedUpdate(key, value, result);
        // 결과를 깊은 병합
        this.deepMergeInto(result, nestedUpdate, result);
        continue;
      }

      // File, Blob, Date 등 non-plain 객체는 재귀 병합하지 않고 직접 할당
      // (spread 연산자로 복사하면 내부 데이터가 소실됨)
      if (
        value !== null &&
        typeof value === 'object' &&
        !Array.isArray(value) &&
        Object.getPrototypeOf(value) !== Object.prototype &&
        Object.getPrototypeOf(value) !== null
      ) {
        result[key] = value;
        continue;
      }

      // 값이 일반 객체이고, 현재 상태에도 해당 키가 객체로 존재하면 재귀적 깊은 병합
      if (
        value !== null &&
        typeof value === 'object' &&
        !Array.isArray(value) &&
        currentState[key] !== null &&
        typeof currentState[key] === 'object' &&
        !Array.isArray(currentState[key])
      ) {
        // 중첩 객체: 재귀적으로 깊은 병합 수행
        result[key] = this.deepMergeWithState(value, currentState[key]);
      } else {
        // 기본값, 배열, null 등: 그대로 덮어쓰기
        result[key] = value;
      }
    }

    return result;
  }

  /**
   * source 객체를 target 객체에 깊은 병합합니다 (in-place).
   *
   * @param target 병합 대상 객체
   * @param source 병합할 소스 객체
   * @param currentState 현재 상태 (깊은 병합 시 기존 값 참조용)
   */
  private deepMergeInto(
    target: Record<string, any>,
    source: Record<string, any>,
    currentState: Record<string, any>
  ): void {
    for (const [key, value] of Object.entries(source)) {
      // value가 null이면 그대로 덮어쓰기 (null로 초기화하는 경우)
      if (value === null) {
        target[key] = value;
        continue;
      }

      // target[key]가 null이면 source 값으로 교체
      if (target[key] === null) {
        target[key] = value;
        continue;
      }

      if (
        typeof value === 'object' &&
        !Array.isArray(value) &&
        target[key] !== undefined &&
        target[key] !== null &&
        typeof target[key] === 'object' &&
        !Array.isArray(target[key])
      ) {
        // 원본 상태와 같은 참조면 shallow copy 후 병합 (원본 변이 방지)
        if (target[key] === currentState[key]) {
          target[key] = { ...target[key] };
        }
        // 두 값이 모두 객체인 경우 재귀적으로 병합
        this.deepMergeInto(target[key], value, currentState[key] || {});
      } else if (
        typeof value === 'object' &&
        !Array.isArray(value) &&
        target[key] === undefined &&
        currentState[key] !== null &&
        typeof currentState[key] === 'object' &&
        !Array.isArray(currentState[key])
      ) {
        // target에 없지만 currentState에 있으면 currentState와 병합
        target[key] = { ...currentState[key], ...value };
      } else {
        target[key] = value;
      }
    }
  }

  /**
   * dot notation 경로를 기반으로 중첩 업데이트 객체를 생성합니다.
   *
   * 부분 업데이트를 수행하여 다른 필드는 유지합니다.
   *
   * @param path dot notation 경로 (예: "formData.name")
   * @param value 설정할 값
   * @param currentState 현재 상태
   * @returns 업데이트 객체
   *
   * @example
   * createNestedUpdate("formData.name", "John", { formData: { name: "", email: "" } })
   * // 결과: { formData: { name: "John", email: "" } }
   */
  private createNestedUpdate(
    path: string,
    value: any,
    currentState: Record<string, any>
  ): Record<string, any> {
    const keys = path.split('.');

    if (keys.length === 1) {
      // 단일 키인 경우
      return { [keys[0]]: value };
    }

    // 중첩 경로인 경우: 깊은 복사 후 부분 업데이트
    const result: Record<string, any> = {};
    let current = result;
    let stateCursor: Record<string, any> | null | undefined = currentState;

    for (let i = 0; i < keys.length - 1; i++) {
      const key = keys[i];
      // 현재 상태의 해당 키 값을 얕은 복사
      // stateCursor가 null이거나 객체가 아닌 경우 빈 객체로 처리
      const stateValue: unknown = stateCursor?.[key];
      const isPlainObject =
        stateValue !== null &&
        stateValue !== undefined &&
        typeof stateValue === 'object' &&
        !Array.isArray(stateValue);

      if (isPlainObject) {
        current[key] = { ...(stateValue as Record<string, unknown>) };
      } else {
        current[key] = {};
      }
      current = current[key];
      // stateCursor 업데이트: 객체인 경우만 진행, 아니면 빈 객체로
      stateCursor = isPlainObject
        ? (stateValue as Record<string, unknown>)
        : {};
    }

    // 마지막 키에 값 설정
    current[keys[keys.length - 1]] = value;

    return result;
  }

  /**
   * dot notation 경로에서 중첩 객체를 생성합니다.
   *
   * resultTo 패턴에서 핸들러 결과를 상태에 저장할 때 사용합니다.
   * createNestedUpdate와 달리 현재 상태 없이 순수하게 중첩 객체만 생성합니다.
   *
   * @param path dot notation 경로 (예: "productColumns", "calculatedPrices.123")
   * @param value 저장할 값
   * @returns 중첩 객체
   *
   * @example
   * buildNestedUpdate("productColumns", [...])
   * // 결과: { productColumns: [...] }
   *
   * buildNestedUpdate("calculatedPrices.123", { KRW: 1000 })
   * // 결과: { calculatedPrices: { "123": { KRW: 1000 } } }
   */
  private buildNestedUpdate(path: string, value: any): Record<string, any> {
    const keys = path.split('.');

    if (keys.length === 1) {
      return { [keys[0]]: value };
    }

    // 중첩 경로인 경우 객체 생성
    const result: Record<string, any> = {};
    let current = result;

    for (let i = 0; i < keys.length - 1; i++) {
      current[keys[i]] = {};
      current = current[keys[i]];
    }

    current[keys[keys.length - 1]] = value;
    return result;
  }

  /**
   * setError 액션을 처리합니다.
   *
   * 컴포넌트의 에러 상태를 설정합니다.
   * - target이 {{}} 바인딩이면: context.data에서 값 추출 (예: {{error.message}})
   * - target이 $t:로 시작하면: 다국어 키로 번역
   * - 그 외: 문자열 그대로 사용
   *
   * params.stateTarget으로 에러를 저장할 상태 스코프를 지정할 수 있습니다:
   * - 'local' (기본값): _local.apiError에 저장
   * - 'global': _global.apiError에 저장
   * - 'isolated': _isolated.apiError에 저장
   *
   * @param target 에러 메시지, 다국어 키, 또는 바인딩 표현식
   * @param params 추가 파라미터 (stateTarget: 'local' | 'global' | 'isolated')
   * @param context 액션 컨텍스트
   */
  private async handleSetError(
    target: string,
    params: any,
    context: ActionContext
  ): Promise<void> {
    let errorMessage = target;

    // {{}} 바인딩 처리 (예: {{error.message}}, {{response.error}})
    errorMessage = this.resolveValue(errorMessage, context.data);

    // $t: 다국어 구문 처리
    if (this.translationEngine && this.translationContext && errorMessage.startsWith('$t:')) {
      errorMessage = this.translationEngine.resolveTranslations(
        errorMessage,
        this.translationContext,
        context.data
      );
    }

    // stateTarget에 따라 에러 저장 위치 결정
    const stateTarget = params?.stateTarget || 'local';

    if (stateTarget === 'isolated' && context.isolatedContext) {
      // 격리된 상태에 저장
      context.isolatedContext.mergeState({ apiError: errorMessage });
      logger.log('[handleSetError] Error set to isolated state:', errorMessage);
    } else if (stateTarget === 'global' && this.globalStateUpdater) {
      // 전역 상태에 저장
      this.globalStateUpdater({ apiError: errorMessage });
      logger.log('[handleSetError] Error set to global state:', errorMessage);
    } else if (context.setState) {
      // 로컬 상태에 저장 (기본값)
      context.setState({ apiError: errorMessage });
      logger.log('[handleSetError] Error set to local state:', errorMessage);
    } else {
      logger.warn('[handleSetError] Cannot set error: no state updater available');
    }
  }

  /**
   * openModal 액션을 처리합니다.
   *
   * 모달 스택에 모달 ID를 추가하여 모달을 엽니다.
   * 멀티 모달(중첩 모달)을 지원합니다.
   *
   * @param target 모달 ID
   * @param context 액션 컨텍스트
   */
  private async handleOpenModal(
    target: string,
    context: ActionContext
  ): Promise<void> {
    if (!this.globalStateUpdater) {
      logger.warn('Global state updater is not set for openModal');
      return;
    }

    // 현재 모달 스택 가져오기
    const currentStack = context.data?._global?.modalStack || [];

    // 이미 열려있는 모달이면 스택에 추가하지 않음
    if (currentStack.includes(target)) {
      logger.warn(`Modal "${target}" is already open`);
      return;
    }

    // 레이아웃 컨텍스트 스택에 현재 컨텍스트 push (scope: 'parent' 및 $parent 바인딩 지원용)
    // 모달에서 부모 레이아웃의 상태에 접근할 수 있도록 함
    //
    // context.setState/context.state가 없는 경우 (DataSourceManager onSuccess 등):
    // G7Core.state.getLocal()로 현재 상태를 가져오고 globalStateUpdater를 통한 proxy setState 사용
    const G7Core = (window as any).G7Core;
    const hasDirectContext = context.setState && context.state !== undefined;
    const fallbackState = !hasDirectContext ? (G7Core?.state?.getLocal?.() || {}) : undefined;
    const hasFallbackContext = !hasDirectContext && fallbackState !== undefined && this.globalStateUpdater;

    if (hasDirectContext || hasFallbackContext) {
      const layoutContextStack: Array<{
        state: Record<string, any>;
        setState: (updates: any) => void;
        dataContext?: Record<string, any>;  // $parent 바인딩용 전체 데이터 컨텍스트
      }> = (window as any).__g7LayoutContextStack || [];

      // 상태와 setState 결정
      const effectiveState = hasDirectContext ? context.state : fallbackState!;
      const effectiveSetState = hasDirectContext
        ? context.setState!
        : (updates: any) => {
            // DataSourceManager onSuccess 등에서 context.setState가 없는 경우
            // globalStateUpdater를 통해 _local 업데이트
            this.globalStateUpdater!({ _local: updates });
          };

      layoutContextStack.push({
        state: effectiveState,
        setState: effectiveSetState,
        // $parent 바인딩을 위해 필요한 상태만 저장 (API 응답 데이터 제외)
        // context.data에 포함된 product_labels 등 대량 API 데이터는 복사하지 않음
        // 이를 통해 메모리 사용량을 크게 줄임 (120MB → 수 KB)
        // sequence 내에서 setState 후 호출 시 context.state에 최신 _local 상태가 있음
        // context.state를 우선 사용하여 stale closure 문제 방지
        dataContext: {
          _local: effectiveState || context.data?._local,
          // hasDirectContext: 기존 동작 유지 (context.data?._global만 사용)
          // hasFallbackContext: context.data?._global이 없으므로 G7Core.state.get() 사용
          _global: hasDirectContext ? context.data?._global : (context.data?._global || G7Core?.state?.get?.()),
          _computed: context.data?._computed,
        },
      });
      (window as any).__g7LayoutContextStack = layoutContextStack;
      logger.log(`[handleOpenModal] 레이아웃 컨텍스트 스택에 push, 스택 크기: ${layoutContextStack.length}, state:`, effectiveState, hasDirectContext ? '(direct)' : '(fallback via G7Core)');
    } else {
      logger.warn(`[handleOpenModal] 컨텍스트 스택에 push 실패 - setState: ${!!context.setState}, state: ${context.state !== undefined}, G7Core: ${!!G7Core?.state?.getLocal}`);
    }

    // 모달 스택에 새 모달 추가 (스택 방식으로 중첩 지원)
    const newStack = [...currentStack, target];
    this.globalStateUpdater({
      modalStack: newStack,
      // 하위 호환성을 위해 activeModal도 유지 (최상위 모달)
      activeModal: target,
    });

    // DevTools: 모달 열림 추적
    const devTools = getDevTools();
    if (devTools?.isEnabled()) {
      const parentModalId = currentStack.length > 0 ? currentStack[currentStack.length - 1] : undefined;
      devTools.trackModalOpen?.({
        modalId: target,
        modalName: target,
        scopeType: 'isolated',
        parentModalId,
        initialState: context.state ? { ...context.state } : {},
      });
    }
  }

  /**
   * closeModal 액션을 처리합니다.
   *
   * 모달 스택에서 최상위 모달을 제거합니다.
   * 스택이 비어있지 않으면 이전 모달이 다시 표시됩니다.
   *
   * @param context 액션 컨텍스트 (선택적)
   */
  private async handleCloseModal(context?: ActionContext): Promise<void> {
    if (!this.globalStateUpdater) {
      logger.warn('Global state updater is not set for closeModal');
      return;
    }

    // 현재 모달 스택 가져오기
    const currentStack = context?.data?._global?.modalStack || [];

    // 닫히는 모달 ID (스택의 마지막 항목)
    const closingModalId = currentStack.length > 0 ? currentStack[currentStack.length - 1] : null;

    // 스택에서 최상위 모달 제거
    const newStack = currentStack.slice(0, -1);

    // 스택이 비어있으면 activeModal도 null로 설정
    const newActiveModal = newStack.length > 0 ? newStack[newStack.length - 1] : null;

    // 레이아웃 컨텍스트 스택에서 pop (scope: 'parent' 및 $parent 바인딩 지원용)
    const layoutContextStack: Array<{
      state: Record<string, any>;
      setState: (updates: any) => void;
      dataContext?: Record<string, any>;
    }> = (window as any).__g7LayoutContextStack || [];
    if (layoutContextStack.length > 0) {
      // 메모리 정리: pop 전에 dataContext 참조 해제
      const poppedContext = layoutContextStack[layoutContextStack.length - 1];
      if (poppedContext) {
        poppedContext.dataContext = undefined;
      }
      layoutContextStack.pop();
      (window as any).__g7LayoutContextStack = layoutContextStack;
      logger.log(`[handleCloseModal] 레이아웃 컨텍스트 스택에서 pop, 스택 크기: ${layoutContextStack.length}`);
    }

    // DevTools: 모달 닫힘 추적
    if (closingModalId) {
      const devTools = getDevTools();
      if (devTools?.isEnabled()) {
        devTools.trackModalClose?.(closingModalId, context?.state);
      }
    }

    this.globalStateUpdater({
      modalStack: newStack,
      activeModal: newActiveModal,
    });
  }

  /**
   * showAlert 액션을 처리합니다.
   *
   * @param target 알림 메시지
   * @param context 액션 컨텍스트
   */
  private async handleShowAlert(
    target: string,
    context: ActionContext
  ): Promise<void> {
    let message = target;

    // $t: 다국어 구문 처리
    if (this.translationEngine && this.translationContext && target.startsWith('$t:')) {
      message = this.translationEngine.resolveTranslations(
        target,
        this.translationContext,
        context.data
      );
    }

    alert(message);
  }

  /**
   * toast 액션을 처리합니다.
   *
   * 토스트 알림을 표시하며, 여러 토스트가 스택으로 쌓입니다.
   *
   * @param params 토스트 파라미터 (type, message, icon, duration)
   * @param context 액션 컨텍스트
   */
  private async handleToast(
    params: Record<string, any>,
    context: ActionContext
  ): Promise<void> {
    const { type = 'info', message, icon, duration } = params;

    let resolvedMessage = message;

    // $t: 다국어 구문 처리
    if (this.translationEngine && this.translationContext && message?.startsWith('$t:')) {
      resolvedMessage = this.translationEngine.resolveTranslations(
        message,
        this.translationContext,
        context.data
      );
    }

    // 전역 상태에 토스트 메시지 추가 (배열로 스택 관리)
    if (this.globalStateUpdater) {
      const toastId = `toast_${Date.now()}_${Math.random().toString(36).substring(2, 9)}`;
      const newToast = {
        id: toastId,
        type,
        message: resolvedMessage,
        ...(icon && { icon }),
        ...(duration && { duration }),
      };

      // 현재 toasts 배열 가져오기 (G7Core.state.get() 사용)
      const G7Core = (window as any).G7Core;
      const currentState = G7Core?.state?.get();
      const currentToasts = currentState?.toasts || [];

      // 기존 toasts 배열에 새 토스트 추가
      const newToasts = [...currentToasts, newToast];

      this.globalStateUpdater({
        toasts: newToasts,
      });
    } else {
      // globalStateUpdater가 없으면 콘솔에 출력
      logger.log(`[Toast ${type}] ${resolvedMessage}`);
    }
  }

  /**
   * switch 액션을 처리합니다.
   *
   * 두 가지 방식으로 케이스 키를 결정합니다:
   * 1. params.value가 지정된 경우: 해당 값을 케이스 키로 사용 (데이터 바인딩 지원)
   * 2. params.value가 없는 경우: $args[0] 값을 케이스 키로 사용 (기존 방식)
   *
   * 매칭되는 케이스가 없으면 "default" 케이스를 찾아 실행합니다.
   *
   * @example params.value 방식 (플러그인 환경설정 기반 분기)
   * ```json
   * {
   *   "type": "click",
   *   "handler": "switch",
   *   "params": {
   *     "value": "{{_global.plugins['sirsoft-daum_postcode']?.display_mode ?? 'layer'}}"
   *   },
   *   "cases": {
   *     "popup": { "handler": "callExternal", "params": { ... } },
   *     "layer": { "handler": "callExternalEmbed", "params": { ... } },
   *     "default": { "handler": "toast", "params": { "message": "Unknown mode" } }
   *   }
   * }
   * ```
   *
   * @example $args[0] 방식 (DataGrid 행 액션)
   * ```json
   * {
   *   "event": "onRowAction",
   *   "type": "action",
   *   "handler": "switch",
   *   "cases": {
   *     "view": { "handler": "navigate", "params": { "path": "/users/{{$args[1].id}}" } },
   *     "edit": { "handler": "navigate", "params": { "path": "/users/{{$args[1].id}}/edit" } },
   *     "delete": { "handler": "openModal", "target": "delete_modal" }
   *   }
   * }
   * ```
   *
   * @param action 액션 정의 (cases 포함)
   * @param context 액션 컨텍스트
   */
  private async handleSwitch(
    action: ActionDefinition,
    context: ActionContext
  ): Promise<any> {
    const { cases } = action;

    if (!cases) {
      logger.warn('switch handler requires cases property');
      return;
    }

    // 파라미터 바인딩 (params.value에 데이터 바인딩 적용)
    const resolvedParams = this.resolveParams(action.params, context.data);

    // 케이스 키 결정: params.value가 있으면 사용, 없으면 $args[0] 사용
    let caseKey: any;
    if (resolvedParams?.value !== undefined) {
      caseKey = resolvedParams.value;
      logger.log('switch handler: using params.value as case key:', caseKey);
    } else {
      caseKey = context.data?.$args?.[0];
      logger.log('switch handler: using $args[0] as case key:', caseKey);
    }

    if (caseKey === undefined || caseKey === null) {
      // default 케이스 확인
      if (cases['default']) {
        logger.log('switch handler: case key is undefined, using default case');
        return await this.executeAction(cases['default'], context);
      }
      logger.warn('switch handler: case key is not defined and no default case');
      return;
    }

    // 케이스 키를 문자열로 변환
    const caseKeyStr = String(caseKey);

    // 해당 케이스의 액션 찾기
    let caseAction = cases[caseKeyStr];

    // 매칭되는 케이스가 없으면 default 케이스 사용
    if (!caseAction && cases['default']) {
      logger.log(`switch handler: no case found for key "${caseKeyStr}", using default case`);
      caseAction = cases['default'];
    }

    if (!caseAction) {
      logger.warn(`switch handler: no case found for key "${caseKeyStr}" and no default case`);
      return;
    }

    // 케이스 액션 실행
    return await this.executeAction(caseAction, context);
  }

  /**
   * conditions 액션을 처리합니다.
   *
   * if/else if/else 체인을 통해 조건부 액션 실행을 지원합니다.
   * 첫 번째 매칭되는 브랜치의 then 액션이 실행됩니다.
   *
   * AND/OR 그룹 조건과 중첩 조건도 지원합니다.
   *
   * @example
   * ```json
   * {
   *   "type": "click",
   *   "handler": "conditions",
   *   "conditions": [
   *     {
   *       "if": "{{$args[0] === 'edit'}}",
   *       "then": { "handler": "navigate", "params": { "path": "/edit/{{row.id}}" } }
   *     },
   *     {
   *       "if": "{{$args[0] === 'delete'}}",
   *       "then": [
   *         { "handler": "setState", "params": { "target": "_local", "deleteTargetId": "{{row.id}}" } },
   *         { "handler": "openModal", "params": { "id": "delete_confirm_modal" } }
   *       ]
   *     },
   *     {
   *       "then": { "handler": "toast", "params": { "message": "알 수 없는 액션" } }
   *     }
   *   ]
   * }
   * ```
   *
   * @since engine-v1.10.0
   */
  private async handleConditions(
    action: ActionDefinition,
    context: ActionContext
  ): Promise<any> {
    const { conditions } = action;

    if (!conditions || !Array.isArray(conditions)) {
      logger.warn('conditions handler requires conditions array');
      return;
    }

    // 조건 평가를 위한 바인딩 컨텍스트 생성
    const bindingContext = context.data || {};

    // 조건 브랜치 평가
    const result = evaluateConditionBranches(conditions, bindingContext, this.bindingEngine);

    if (!result.matched) {
      logger.log('conditions handler: no matching branch found');
      return undefined;
    }

    const branch = conditions[result.branchIndex];
    logger.log(`conditions handler: matched branch ${result.branchIndex}`);

    if (!branch.then) {
      logger.warn('conditions handler: matched branch has no "then" action');
      return;
    }

    // then이 배열이면 sequence처럼 순차 실행
    if (Array.isArray(branch.then)) {
      return await this.handleSequence(
        { ...action, actions: branch.then as ActionDefinition[] },
        context
      );
    }

    // 단일 액션 실행
    return await this.executeAction(branch.then as ActionDefinition, context);
  }

  /**
   * sequence 액션을 처리합니다.
   *
   * 여러 액션을 순차적으로 실행하며, 이전 액션의 결과를 다음 액션에서 사용할 수 있습니다.
   * - $prev: 직전 액션의 결과
   * - $results: 모든 이전 결과 배열 [result0, result1, ...]
   * - $results[0], $results[1] 등으로 특정 결과 접근
   *
   * @example
   * ```json
   * {
   *   "type": "click",
   *   "handler": "sequence",
   *   "actions": [
   *     { "handler": "setState", "params": { "target": "global", "selectedModule": "{{row}}" } },
   *     { "handler": "openModal", "target": "module_install_modal" }
   *   ]
   * }
   * ```
   *
   * @example 결과 전달 예시
   * ```json
   * {
   *   "handler": "sequence",
   *   "actions": [
   *     { "handler": "apiCall", "target": "/api/users/1" },
   *     { "handler": "setState", "params": { "user": "{{$prev.data}}" } },
   *     { "handler": "toast", "params": { "message": "사용자: {{$prev.name}}" } }
   *   ]
   * }
   * ```
   *
   * @param action 액션 정의 (actions 배열 포함)
   * @param context 액션 컨텍스트
   * @returns 모든 액션 결과 배열
   */
  private async handleSequence(
    action: ActionDefinition,
    context: ActionContext
  ): Promise<any[]> {
    // 레이아웃 JSON 컴파일 시 actions가 params.actions로 배치되는 경우를 처리
    const actions = action.actions || (action.params as any)?.actions;

    if (!actions || !Array.isArray(actions) || actions.length === 0) {
      logger.warn('sequence handler requires non-empty actions array');
      return [];
    }

    // DevTools: Sequence 실행 시작
    const devTools = getDevTools();
    const sequenceId = devTools?.isEnabled()
      ? devTools.startSequenceExecution?.({
          eventType: action.type,
        }) || ''
      : '';

    // sequence 내 커스텀 핸들러 상태 동기화용 변수 초기화
    // setLocal()에서 설정한 __g7SequenceLocalSync를 사용하여 후속 액션에 최신 상태 전달
    (window as any).__g7SequenceLocalSync = undefined;

    const results: any[] = [];
    let prevResult: any = undefined;
    // sequence 내에서 상태 변경을 추적하기 위한 로컬 상태 복사본
    // 이를 통해 setState 후 다음 액션에서 업데이트된 상태를 참조할 수 있음
    let currentState = context.state ? { ...context.state } : {};
    // isolated 상태 추적 (isolatedState 속성이 있는 컴포넌트에서 제공된 경우)
    let isolatedState = context.isolatedContext?.state
      ? { ...context.isolatedContext.state }
      : {};
    // computed 상태 추적: _local 변경 시 _computed 재계산하여 다음 액션에서 최신 값 참조
    let currentComputed = context.data?._computed || {};

    // DevTools: 현재 상태 스냅샷 준비 헬퍼
    const G7Core = (window as any).G7Core;
    const getStateSnapshot = () => ({
      _global: G7Core?.state?.get() || {},
      _local: { ...currentState },
      _isolated: Object.keys(isolatedState).length > 0 ? { ...isolatedState } : undefined,
    });

    for (let i = 0; i < actions.length; i++) {
      const currentAction = actions[i];
      const actionStartTime = Date.now();

      // DevTools: 액션 실행 전 상태 캡처
      if (sequenceId && devTools?.isEnabled()) {
        devTools.captureSequenceActionBefore?.(
          sequenceId,
          i,
          currentAction.handler || 'unknown',
          currentAction.params || {},
          getStateSnapshot()
        );
      }

      // 컨텍스트에 이전 결과 정보와 최신 상태 추가
      const sequenceContext: ActionContext = {
        ...context,
        state: currentState, // 최신 상태 반영
        data: {
          ...context.data,
          _local: currentState,        // sequence 내 setState 후 최신 _local 상태 반영
          _computed: currentComputed,  // sequence 내 setState 후 재계산된 _computed 반영
          $computed: currentComputed,  // _computed alias
          $prev: prevResult,           // 직전 액션 결과
          $results: [...results],      // 모든 이전 결과 배열 (복사본)
          _isolated: isolatedState,    // 최신 isolated 상태 반영
        },
        // 업데이트된 isolated 상태를 가진 컨텍스트 전달
        isolatedContext: context.isolatedContext ? {
          ...context.isolatedContext,
          state: isolatedState,
        } : null,
      };

      try {
        // 액션 실행
        const result = await this.executeAction(currentAction, sequenceContext);

        // 결과 저장
        prevResult = result.data;
        results.push(result.data);

        // setState 액션인 경우 currentState 동기화
        // handleSetState가 반환한 병합된 상태를 사용하여
        // 다음 액션에서 올바른 상태를 참조할 수 있도록 함
        if (currentAction.handler === 'setState' && result.data !== undefined) {
          // handleSetState가 __target: 'global'을 포함한 경우 _global에 병합
          if (result.data.__target === 'global') {
            const { __target, __mergeMode, ...globalPayload } = result.data;
            if (__mergeMode === 'replace') {
              // replace: 기존 _global 완전 교체
              currentState = { ...currentState, _global: globalPayload };
            } else {
              // shallow/deep: 기존 _global에 병합 (deep인 경우 이미 fully merged)
              currentState = {
                ...currentState,
                _global: { ...(currentState._global || {}), ...globalPayload }
              };
            }
            logger.log('[handleSequence] global state synchronized (mergeMode=%s):', __mergeMode || 'deep', currentState._global);
          } else if (result.data.__target === 'isolated') {
            // isolated 상태 동기화
            const { __target, __mergeMode, ...isolatedPayload } = result.data;
            if (__mergeMode === 'replace') {
              isolatedState = isolatedPayload;
            } else {
              isolatedState = { ...isolatedState, ...isolatedPayload };
            }
            logger.log('[handleSequence] isolated state synchronized (mergeMode=%s):', __mergeMode || 'deep', isolatedState);
          } else if (result.data.__target === 'local') {
            // local 상태 동기화 - currentState는 이미 _local의 내용물이므로 직접 병합
            // 주의: _local로 감싸면 중첩 발생 ($parent._local._local 문제)
            const { __target, __mergeMode, __setStateId, ...localPayload } = result.data;
            if (__mergeMode === 'replace') {
              // replace: 기존 local 상태 완전 교체
              currentState = localPayload;
            } else {
              // shallow/deep: 기존 local 상태에 병합 (deep인 경우 이미 fully merged)
              currentState = { ...currentState, ...localPayload };
            }
            logger.log('[handleSequence] local state synchronized (mergeMode=%s):', __mergeMode || 'deep', currentState);
          } else {
            // __target이 없는 경우 (하위 호환성)
            currentState = result.data;
            logger.log('[handleSequence] state synchronized (legacy):', currentState);
          }

          // _computed 재계산: _local 변경 시 _computed도 업데이트해야 다음 액션에서 최신 값 참조 가능
          const computedDefinitions = context.data?._computedDefinitions;
          if (computedDefinitions && Object.keys(computedDefinitions).length > 0) {
            const newComputed: Record<string, any> = {};
            const computedContext = {
              ...context.data,
              _local: currentState,
              _computed: newComputed,
              $computed: newComputed,
              _isolated: isolatedState,
            };
            for (const [key, expression] of Object.entries(computedDefinitions)) {
              if (typeof expression === 'string') {
                const trimmed = expression.trim();
                if (trimmed.startsWith('{{') && trimmed.endsWith('}}')) {
                  try {
                    const innerExpr = trimmed.slice(2, -2).trim();
                    newComputed[key] = this.bindingEngine.evaluateExpression(
                      innerExpr,
                      computedContext,
                      { skipCache: true }
                    );
                  } catch (e) {
                    // 평가 실패 시 기존 값 유지
                    newComputed[key] = currentComputed[key];
                  }
                }
              }
            }
            currentComputed = newComputed;
            logger.log('[handleSequence] _computed recalculated after setState:', currentComputed);
          }
        }

        // 비-setState 핸들러 후 상태 동기화
        // 커스텀 핸들러가 G7Core.state.setLocal()을 호출한 경우,
        // __g7SequenceLocalSync에 최신 상태(deepMerge 완료 스냅샷)가 저장되어 있으므로
        // 이를 currentState에 반영하여 후속 액션이 올바른 상태를 참조하도록 함
        //
        // 주의: __g7PendingLocalState는 사용 불가 — setLocal() 내의 setGlobalState() →
        // import().then() → updateTemplateData() → root.render() → useLayoutEffect에서
        // null로 클리어됨 (await 해제 시 마이크로태스크 플러시로 인해)
        if (currentAction.handler !== 'setState') {
          const syncState = (window as any).__g7SequenceLocalSync;
          if (syncState && syncState !== currentState) {
            currentState = syncState;
            // 사용 후 클리어 (같은 sequence 내 다음 비-setState 핸들러에서 stale 값 방지)
            (window as any).__g7SequenceLocalSync = undefined;
            logger.log('[handleSequence] currentState synchronized from __g7SequenceLocalSync after custom handler:', currentAction.handler);

            // _computed 재계산: 상태가 변경되었으므로 _computed도 업데이트
            const computedDefinitions = context.data?._computedDefinitions;
            if (computedDefinitions && Object.keys(computedDefinitions).length > 0) {
              const newComputed: Record<string, any> = {};
              const computedContext = {
                ...context.data,
                _local: currentState,
                _computed: newComputed,
                $computed: newComputed,
                _isolated: isolatedState,
              };
              for (const [key, expression] of Object.entries(computedDefinitions)) {
                if (typeof expression === 'string') {
                  const trimmed = expression.trim();
                  if (trimmed.startsWith('{{') && trimmed.endsWith('}}')) {
                    try {
                      const innerExpr = trimmed.slice(2, -2).trim();
                      newComputed[key] = this.bindingEngine.evaluateExpression(
                        innerExpr,
                        computedContext,
                        { skipCache: true }
                      );
                    } catch (e) {
                      // 평가 실패 시 기존 값 유지
                      newComputed[key] = currentComputed[key];
                    }
                  }
                }
              }
              currentComputed = newComputed;
              logger.log('[handleSequence] _computed recalculated after custom handler:', currentComputed);
            }
          }
        }

        // DevTools: 액션 실행 후 상태 캡처
        if (sequenceId && devTools?.isEnabled()) {
          devTools.captureSequenceActionAfter?.(
            sequenceId,
            i,
            getStateSnapshot(),
            Date.now() - actionStartTime,
            result.data
          );
        }

      } catch (error) {
        // DevTools: 에러 발생 시 상태 캡처
        if (sequenceId && devTools?.isEnabled()) {
          devTools.captureSequenceActionAfter?.(
            sequenceId,
            i,
            getStateSnapshot(),
            Date.now() - actionStartTime,
            undefined,
            error as Error
          );
          devTools.endSequenceExecution?.(sequenceId, error as Error);
        }
        logger.error(`sequence handler: action[${i}] failed:`, error);
        // 에러 발생 시 중단하고 에러를 상위로 전파
        throw error;
      }
    }

    // sequence 종료 시 동기화 변수 클리어
    (window as any).__g7SequenceLocalSync = undefined;

    // DevTools: Sequence 실행 완료
    if (sequenceId && devTools?.isEnabled()) {
      devTools.endSequenceExecution?.(sequenceId);
    }

    return results;
  }

  /**
   * parallel 액션을 처리합니다.
   *
   * 여러 액션을 병렬로 실행하며, 모든 액션이 완료될 때까지 기다립니다.
   * Promise.all을 사용하여 모든 액션을 동시에 실행합니다.
   *
   * 주의: 병렬 실행이므로 액션 간 실행 순서가 보장되지 않습니다.
   * 순서가 중요한 경우 sequence 핸들러를 사용하세요.
   *
   * @example
   * ```json
   * {
   *   "handler": "parallel",
   *   "actions": [
   *     { "handler": "toast", "params": { "type": "success", "message": "완료!" } },
   *     { "handler": "refetchDataSource", "params": { "dataSourceId": "modules" } },
   *     { "handler": "refetchDataSource", "params": { "dataSourceId": "admin_menu" } }
   *   ]
   * }
   * ```
   *
   * @example onSuccess에서 사용
   * ```json
   * {
   *   "handler": "apiCall",
   *   "target": "/api/admin/modules/activate",
   *   "onSuccess": [
   *     {
   *       "handler": "parallel",
   *       "actions": [
   *         { "handler": "toast", "params": { "type": "success", "message": "활성화 완료" } },
   *         { "handler": "refetchDataSource", "params": { "dataSourceId": "modules" } },
   *         { "handler": "refetchDataSource", "params": { "dataSourceId": "admin_menu" } }
   *       ]
   *     }
   *   ]
   * }
   * ```
   *
   * @param action 액션 정의 (actions 배열 포함)
   * @param context 액션 컨텍스트
   * @returns 모든 액션 결과 배열 (PromiseSettledResult 형식)
   */
  private async handleParallel(
    action: ActionDefinition,
    context: ActionContext
  ): Promise<PromiseSettledResult<ActionResult>[]> {
    // 레이아웃 JSON 컴파일 시 actions가 params.actions로 배치되는 경우를 처리
    const actions = action.actions || (action.params as any)?.actions;

    if (!actions || !Array.isArray(actions) || actions.length === 0) {
      logger.warn('parallel handler requires non-empty actions array');
      return [];
    }

    logger.log(`parallel handler: executing ${actions.length} actions in parallel`);

    // isolated 상태 스냅샷 (병렬 실행 시 모든 액션이 동일한 초기 상태 참조하도록)
    const isolatedContextSnapshot = context.isolatedContext ? {
      ...context.isolatedContext,
      state: { ...context.isolatedContext.state },
    } : null;

    // 모든 액션을 병렬로 실행
    const promises = actions.map((currentAction, index) => {
      const actionContext: ActionContext = {
        ...context,
        isolatedContext: isolatedContextSnapshot,
      };
      return this.executeAction(currentAction, actionContext)
        .catch(error => {
          // 개별 액션 에러를 로깅하지만 다른 액션은 계속 실행
          logger.error(`parallel handler: action[${index}] failed:`, error);
          throw error;
        });
    });

    // Promise.allSettled를 사용하여 모든 액션 완료 대기
    // 일부 액션이 실패해도 다른 액션은 계속 실행됨
    const results = await Promise.allSettled(promises);

    // 실패한 액션이 있는지 확인하고 로깅
    const failedCount = results.filter(r => r.status === 'rejected').length;
    if (failedCount > 0) {
      logger.warn(`parallel handler: ${failedCount}/${actions.length} actions failed`);
    }

    return results;
  }

  /**
   * 로드된 외부 스크립트 ID를 추적하기 위한 Set
   */
  private static loadedScripts: Set<string> = new Set();

  /**
   * 외부 스크립트를 동적으로 로드합니다.
   *
   * 이미 로드된 스크립트는 재로드하지 않고 캐시된 상태를 사용합니다.
   * 스크립트 로드 완료 시 onLoad 액션을 실행합니다.
   *
   * @param params 스크립트 로드 파라미터
   *   - src: 스크립트 URL (필수)
   *   - id: 스크립트 요소 ID (선택, 중복 로드 방지에 사용)
   *   - async: 비동기 로드 여부 (기본값: true)
   *   - defer: 지연 로드 여부 (기본값: false)
   * @param action 액션 정의 (onLoad 액션 포함 가능)
   * @param context 액션 컨텍스트
   *
   * @example
   * ```json
   * {
   *   "handler": "loadScript",
   *   "params": {
   *     "src": "//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js",
   *     "id": "daum_postcode_script"
   *   },
   *   "onLoad": { "handler": "setState", "params": { "daumPostcodeLoaded": true } }
   * }
   * ```
   */
  private async handleLoadScript(
    params: Record<string, any>,
    action: ActionDefinition,
    context: ActionContext
  ): Promise<boolean> {
    const { src, id, async = true, defer = false } = params;

    if (!src) {
      throw new ActionError('loadScript handler requires "src" parameter', action);
    }

    const scriptId = id || `script_${src.replace(/[^a-zA-Z0-9]/g, '_')}`;

    // 이미 로드된 스크립트인지 확인
    if (ActionDispatcher.loadedScripts.has(scriptId)) {
      logger.log(`loadScript: script already loaded, skipping: ${scriptId}`);

      // onLoad 액션이 있으면 즉시 실행
      if (action.onLoad) {
        await this.executeAction(action.onLoad, context);
      }

      return true;
    }

    // DOM에 이미 스크립트가 존재하는지 확인
    const existingScript = document.getElementById(scriptId);
    if (existingScript) {
      logger.log(`loadScript: script element already exists: ${scriptId}`);
      ActionDispatcher.loadedScripts.add(scriptId);

      // onLoad 액션이 있으면 즉시 실행
      if (action.onLoad) {
        await this.executeAction(action.onLoad, context);
      }

      return true;
    }

    logger.log(`loadScript: loading script: ${src}`);

    return new Promise((resolve, reject) => {
      const script = document.createElement('script');
      script.id = scriptId;
      script.src = src;
      script.async = async;
      script.defer = defer;

      script.onload = async () => {
        logger.log(`loadScript: script loaded successfully: ${scriptId}`);
        ActionDispatcher.loadedScripts.add(scriptId);

        // onLoad 액션 실행
        if (action.onLoad) {
          try {
            await this.executeAction(action.onLoad, context);
          } catch (error) {
            logger.error('loadScript: onLoad action failed:', error);
          }
        }

        resolve(true);
      };

      script.onerror = (error) => {
        logger.error(`loadScript: failed to load script: ${src}`, error);
        reject(new ActionError(`Failed to load script: ${src}`, action));
      };

      document.head.appendChild(script);
    });
  }

  /**
   * 외부 스크립트의 생성자나 메서드를 호출합니다.
   *
   * 외부 라이브러리(예: Daum 우편번호 API)의 생성자를 호출하고
   * 콜백 결과를 이벤트로 전달하거나 폼 필드에 직접 매핑합니다.
   *
   * @param params 호출 파라미터
   *   - constructor: 호출할 생성자 경로 (예: "daum.Postcode")
   *   - args: 생성자에 전달할 인자 객체 (콜백 속성에 true 지정 시 콜백 함수로 변환)
   *   - method: 인스턴스 생성 후 호출할 메서드 (예: "open", "embed")
   *   - methodArgs: 메서드에 전달할 인자 배열
   *   - callbackEvent: 콜백 결과를 전달할 이벤트명 (예: "postcode:complete")
   *   - callbackSetState: 콜백 데이터를 폼 필드에 매핑하는 설정 (engine-v1.8.0+)
   *   - embedTarget: embed 메서드 사용 시 대상 요소 선택자
   * @param action 액션 정의
   * @param context 액션 컨텍스트
   *
   * @example callbackSetState를 사용한 폼 필드 매핑
   * ```json
   * {
   *   "handler": "callExternal",
   *   "params": {
   *     "constructor": "daum.Postcode",
   *     "args": { "oncomplete": true },
   *     "callbackSetState": {
   *       "basic_info": {
   *         "zipcode": "zonecode",
   *         "base_address": "roadAddress"
   *       }
   *     },
   *     "method": "open"
   *   }
   * }
   * ```
   *
   * @example callbackEvent를 사용한 이벤트 전달
   * ```json
   * {
   *   "handler": "callExternal",
   *   "params": {
   *     "constructor": "daum.Postcode",
   *     "args": { "oncomplete": true },
   *     "callbackEvent": "postcode:complete",
   *     "method": "open"
   *   }
   * }
   * ```
   */
  private async handleCallExternal(
    params: Record<string, any>,
    action: ActionDefinition,
    context: ActionContext
  ): Promise<any> {
    // 'constructor'는 예약어이므로 직접 접근
    const constructorPath = params['constructor'] as string | undefined;
    const args = (params.args || {}) as Record<string, any>;
    const method = params.method as string | undefined;
    const methodArgs = (params.methodArgs || []) as any[];
    const callbackEvent = params.callbackEvent as string | undefined;
    const embedTarget = params.embedTarget as string | undefined;
    // 콜백 데이터를 폼 필드에 직접 매핑하는 설정 (engine-v1.8.0+)
    const callbackSetState = params.callbackSetState as Record<string, any> | undefined;
    // 콜백 시 실행할 액션 정의 (engine-v1.9.0+) - extension_point의 props에서 전달받은 액션 실행 가능
    const callbackAction = params.callbackAction as ActionDefinition | ActionDefinition[] | undefined;

    if (!constructorPath) {
      throw new ActionError('callExternal handler requires "constructor" parameter', action);
    }

    // 생성자 함수 찾기 (예: "daum.Postcode" → window.daum.Postcode)
    const Constructor = this.getNestedProperty(window as unknown as Record<string, any>, constructorPath);

    if (!Constructor || typeof Constructor !== 'function') {
      throw new ActionError(
        `Constructor not found or not a function: ${constructorPath}. ` +
        'Make sure the script is loaded first using loadScript handler.',
        action
      );
    }

    logger.log(`callExternal: calling constructor ${constructorPath}`);

    // 콜백 함수 생성 (callbackEvent가 지정된 경우)
    const processedArgs = { ...args };

    // args에서 true로 설정된 콜백 속성을 실제 콜백 함수로 변환
    for (const [key, value] of Object.entries(args)) {
      if (value === true) {
        processedArgs[key] = (data: any) => {
          logger.log(`callExternal: callback triggered for ${key}`, data);

          // G7Core.componentEvent로 이벤트 발생 (callbackEvent가 지정된 경우)
          if (callbackEvent && typeof window !== 'undefined' && (window as any).G7Core?.componentEvent) {
            (window as any).G7Core.componentEvent.emit(callbackEvent, data);
          }

          // callbackSetState 처리: 콜백 데이터를 폼 필드에 직접 매핑 (재귀적으로 깊은 중첩 지원 + 깊은 병합)
          // 예: { "form": { "basic_info": { "zipcode": "zonecode" } } }
          // → _local.form.basic_info.zipcode = data.zonecode
          // 기존 상태의 다른 필드는 유지됨 (깊은 병합)
          if (callbackSetState && context.setState) {
            // 재귀적으로 매핑 처리 - 깊은 중첩 구조 지원
            const processMapping = (mapping: Record<string, any>): Record<string, any> => {
              const result: Record<string, any> = {};
              for (const [fieldName, dataPath] of Object.entries(mapping)) {
                if (typeof dataPath === 'string') {
                  // 리프 노드: 실제 데이터 매핑
                  result[fieldName] = this.getNestedProperty(data, dataPath);
                } else if (typeof dataPath === 'object' && dataPath !== null) {
                  // 중첩 객체: 재귀 처리
                  result[fieldName] = processMapping(dataPath);
                }
              }
              return result;
            };

            const mappedValues = processMapping(callbackSetState);
            logger.log(`callExternal: callbackSetState mapping result`, mappedValues);

            // 깊은 병합 수행: 기존 상태의 다른 필드 유지
            const mergedValues = this.deepMergeWithState(mappedValues, context.state || {});
            logger.log(`callExternal: merged with existing state`, mergedValues);
            context.setState(mergedValues);
          } else if (callbackEvent && context.setState) {
            // callbackSetState가 없으면 기존 방식으로 이벤트 결과 저장
            context.setState({ [`${callbackEvent.replace(/:/g, '_')}_result`]: data });
          }

          // callbackAction 처리: 콜백 데이터를 $event로 전달하여 액션 실행 (engine-v1.9.0+)
          // extension_point의 props에서 전달받은 onAddressSelect 등의 액션 실행에 활용
          // $event를 context.data에 추가해야 resolveParams에서 접근 가능
          if (callbackAction) {
            const callbackContext = {
              ...context,
              data: { ...context.data, $event: data },
            };
            const actions = Array.isArray(callbackAction) ? callbackAction : [callbackAction];
            for (const cbAction of actions) {
              try {
                this.executeAction(cbAction, callbackContext);
              } catch (error) {
                logger.error('callExternal: callbackAction failed:', error);
              }
            }
          }
        };
      }
    }

    // 생성자 호출
    const instance = new Constructor(processedArgs);

    // 메서드 호출 (지정된 경우)
    if (method && typeof instance[method] === 'function') {
      logger.log(`callExternal: calling method ${method}`);

      // embed 메서드의 경우 대상 요소 찾기
      if (method === 'embed' && embedTarget) {
        const targetElement = document.querySelector(embedTarget);
        if (targetElement) {
          return instance[method](targetElement, ...methodArgs);
        } else {
          throw new ActionError(`Embed target element not found: ${embedTarget}`, action);
        }
      }

      return instance[method](...methodArgs);
    }

    return instance;
  }

  /**
   * 외부 라이브러리를 레이어(오버레이) 모드로 임베드합니다.
   *
   * 페이지 위에 오버레이 레이어를 생성하고 그 안에 외부 라이브러리 UI를 임베드합니다.
   * 주로 Daum 우편번호 API와 같은 외부 서비스를 팝업 대신 레이어로 표시할 때 사용합니다.
   *
   * @param params 호출 파라미터
   *   - constructor: 호출할 생성자 경로 (예: "daum.Postcode")
   *   - args: 생성자에 전달할 인자 객체 (콜백 속성에 true 지정 시 콜백 함수로 변환)
   *   - callbackSetState: 콜백 데이터를 폼 필드에 매핑하는 설정
   *   - layerClassName: 레이어 컨테이너에 적용할 추가 CSS 클래스
   * @param action 액션 정의
   * @param context 액션 컨텍스트
   *
   * @example
   * ```json
   * {
   *   "handler": "callExternalEmbed",
   *   "params": {
   *     "constructor": "daum.Postcode",
   *     "args": { "oncomplete": true },
   *     "callbackSetState": {
   *       "basic_info": {
   *         "zipcode": "zonecode",
   *         "base_address": "roadAddress"
   *       }
   *     }
   *   }
   * }
   * ```
   */
  private async handleCallExternalEmbed(
    params: Record<string, any>,
    action: ActionDefinition,
    context: ActionContext
  ): Promise<any> {
    const constructorPath = params['constructor'] as string | undefined;
    const args = (params.args || {}) as Record<string, any>;
    const callbackSetState = params.callbackSetState as Record<string, any> | undefined;
    const callbackEvent = params.callbackEvent as string | undefined;
    const layerClassName = params.layerClassName as string | undefined;
    // 콜백 시 실행할 액션 정의 (engine-v1.9.0+) - extension_point의 props에서 전달받은 액션 실행 가능
    const callbackAction = params.callbackAction as ActionDefinition | ActionDefinition[] | undefined;

    if (!constructorPath) {
      throw new ActionError('callExternalEmbed handler requires "constructor" parameter', action);
    }

    // 생성자 함수 찾기
    const Constructor = this.getNestedProperty(window as unknown as Record<string, any>, constructorPath);

    if (!Constructor || typeof Constructor !== 'function') {
      throw new ActionError(
        `Constructor not found or not a function: ${constructorPath}. ` +
        'Make sure the script is loaded first.',
        action
      );
    }

    logger.log(`callExternalEmbed: creating layer for ${constructorPath}`);

    // 레이어 요소들 생성
    const { layer, closeLayer } = this.createEmbedLayer(layerClassName);

    // 콜백 처리
    const processedArgs = { ...args };

    for (const [key, value] of Object.entries(args)) {
      if (value === true) {
        processedArgs[key] = (data: any) => {
          logger.log(`callExternalEmbed: callback triggered for ${key}`, data);

          // 레이어 닫기
          closeLayer();

          // G7Core.componentEvent로 이벤트 발생
          if (callbackEvent && typeof window !== 'undefined' && (window as any).G7Core?.componentEvent) {
            (window as any).G7Core.componentEvent.emit(callbackEvent, data);
          }

          // callbackSetState 처리 (재귀적으로 깊은 중첩 지원 + 깊은 병합)
          if (callbackSetState && context.setState) {
            // 재귀적으로 매핑 처리 - 깊은 중첩 구조 지원
            const processMapping = (mapping: Record<string, any>): Record<string, any> => {
              const result: Record<string, any> = {};
              for (const [fieldName, dataPath] of Object.entries(mapping)) {
                if (typeof dataPath === 'string') {
                  // 리프 노드: 실제 데이터 매핑
                  result[fieldName] = this.getNestedProperty(data, dataPath);
                } else if (typeof dataPath === 'object' && dataPath !== null) {
                  // 중첩 객체: 재귀 처리
                  result[fieldName] = processMapping(dataPath);
                }
              }
              return result;
            };

            const mappedValues = processMapping(callbackSetState);
            logger.log(`callExternalEmbed: callbackSetState mapping result`, mappedValues);

            // 깊은 병합 수행: 기존 상태의 다른 필드 유지
            const mergedValues = this.deepMergeWithState(mappedValues, context.state || {});
            logger.log(`callExternalEmbed: merged with existing state`, mergedValues);
            context.setState(mergedValues);
          }

          // callbackAction 처리: 콜백 데이터를 $event로 전달하여 액션 실행 (engine-v1.9.0+)
          // extension_point의 props에서 전달받은 onAddressSelect 등의 액션 실행에 활용
          // $event를 context.data에 추가해야 resolveParams에서 접근 가능
          if (callbackAction) {
            const callbackContext = {
              ...context,
              data: { ...context.data, $event: data },
            };
            const actions = Array.isArray(callbackAction) ? callbackAction : [callbackAction];
            for (const cbAction of actions) {
              try {
                this.executeAction(cbAction, callbackContext);
              } catch (error) {
                logger.error('callExternalEmbed: callbackAction failed:', error);
              }
            }
          }
        };
      }
    }

    // 생성자 호출 및 레이어에 임베드
    const instance = new Constructor(processedArgs);

    if (typeof instance.embed === 'function') {
      instance.embed(layer);
      logger.log(`callExternalEmbed: embedded in layer`);
    } else {
      closeLayer();
      throw new ActionError(`Constructor ${constructorPath} does not have embed method`, action);
    }

    return instance;
  }

  /**
   * 임베드용 레이어(오버레이) 요소를 생성합니다.
   *
   * @param additionalClassName 추가 CSS 클래스
   * @returns overlay 요소, layer 요소, closeLayer 함수
   */
  private createEmbedLayer(additionalClassName?: string): {
    overlay: HTMLElement;
    layer: HTMLElement;
    closeLayer: () => void;
  } {
    // 오버레이 배경
    const overlay = document.createElement('div');
    overlay.id = 'g7-embed-overlay';
    overlay.style.cssText = `
      position: fixed;
      inset: 0;
      background-color: rgba(0, 0, 0, 0.5);
      z-index: 9999;
      display: flex;
      align-items: center;
      justify-content: center;
    `;

    // 레이어 컨테이너
    const layer = document.createElement('div');
    layer.id = 'g7-embed-layer';
    layer.style.cssText = `
      position: relative;
      background: white;
      border-radius: 8px;
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
      max-width: 90vw;
      max-height: 90vh;
      overflow: hidden;
    `;

    if (additionalClassName) {
      layer.className = additionalClassName;
    }

    // 닫기 버튼
    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.innerHTML = '×';
    closeButton.style.cssText = `
      position: absolute;
      top: 8px;
      right: 8px;
      width: 32px;
      height: 32px;
      border: none;
      background: rgba(0, 0, 0, 0.1);
      border-radius: 50%;
      font-size: 20px;
      cursor: pointer;
      z-index: 10;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #666;
      transition: background-color 0.2s;
    `;
    closeButton.onmouseenter = () => {
      closeButton.style.backgroundColor = 'rgba(0, 0, 0, 0.2)';
    };
    closeButton.onmouseleave = () => {
      closeButton.style.backgroundColor = 'rgba(0, 0, 0, 0.1)';
    };

    // 레이어 닫기 함수
    const closeLayer = () => {
      if (overlay.parentNode) {
        overlay.parentNode.removeChild(overlay);
      }
    };

    closeButton.onclick = closeLayer;

    // ESC 키로 닫기
    const handleKeydown = (e: KeyboardEvent) => {
      if (e.key === 'Escape') {
        closeLayer();
        document.removeEventListener('keydown', handleKeydown);
      }
    };
    document.addEventListener('keydown', handleKeydown);

    // 오버레이 클릭으로 닫기 (레이어 외부 클릭 시)
    overlay.onclick = (e) => {
      if (e.target === overlay) {
        closeLayer();
      }
    };

    // DOM에 추가
    layer.appendChild(closeButton);
    overlay.appendChild(layer);
    document.body.appendChild(overlay);

    return { overlay, layer, closeLayer };
  }

  /**
   * 중첩된 객체 속성을 경로로 접근합니다.
   *
   * @param obj 대상 객체 (예: window)
   * @param path 점으로 구분된 경로 (예: "daum.Postcode")
   * @returns 해당 경로의 값 또는 undefined
   */
  private getNestedProperty(obj: Record<string, any>, path: string): any {
    return path.split('.').reduce((current: any, key: string) => {
      return current && current[key] !== undefined ? current[key] : undefined;
    }, obj);
  }

  /**
   * 로컬스토리지에 데이터를 저장합니다.
   *
   * @param params 파라미터 (key: 저장할 키, value: 저장할 값)
   * @param context 액션 컨텍스트
   *
   * @example
   * ```json
   * {
   *   "handler": "saveToLocalStorage",
   *   "params": {
   *     "key": "product_filter_config",
   *     "value": "{{_local.visibleFilters}}"
   *   }
   * }
   * ```
   */
  private async handleSaveToLocalStorage(
    params: Record<string, any>,
    context: ActionContext
  ): Promise<boolean> {
    const { key, value } = params;

    if (!key) {
      logger.error('saveToLocalStorage: key parameter is required');
      return false;
    }

    try {
      const serializedValue = typeof value === 'string' ? value : JSON.stringify(value);
      localStorage.setItem(key, serializedValue);
      logger.log(`saveToLocalStorage: saved ${key}`, value);
      return true;
    } catch (error) {
      logger.error('saveToLocalStorage: failed to save', { key, error });
      return false;
    }
  }

  /**
   * 로컬스토리지에서 데이터를 불러와 상태에 설정합니다.
   *
   * @param params 파라미터 (key: 불러올 키, target: 상태 타겟, stateKey: 상태 키, defaultValue: 기본값)
   * @param context 액션 컨텍스트
   *
   * @example
   * ```json
   * {
   *   "handler": "loadFromLocalStorage",
   *   "params": {
   *     "key": "product_filter_config",
   *     "target": "_local",
   *     "stateKey": "visibleFilters",
   *     "defaultValue": ["searchField", "searchKeyword", "category", "date", "salesStatus", "displayStatus"]
   *   }
   * }
   * ```
   */
  private async handleLoadFromLocalStorage(
    params: Record<string, any>,
    context: ActionContext
  ): Promise<any> {
    const { key, target, stateKey, defaultValue } = params;

    if (!key) {
      logger.error('loadFromLocalStorage: key parameter is required');
      return defaultValue;
    }

    try {
      const storedValue = localStorage.getItem(key);

      if (storedValue === null) {
        logger.log(`loadFromLocalStorage: no value found for ${key}, using default`);

        // 기본값이 있고 상태에 설정해야 하는 경우
        if (stateKey && context.setState) {
          context.setState({ [stateKey]: defaultValue });
        }

        return defaultValue;
      }

      // JSON 파싱 시도
      let parsedValue: any;
      try {
        parsedValue = JSON.parse(storedValue);
      } catch {
        // JSON이 아니면 문자열 그대로 사용
        parsedValue = storedValue;
      }

      logger.log(`loadFromLocalStorage: loaded ${key}`, parsedValue);

      // 상태에 설정
      if (stateKey && context.setState) {
        context.setState({ [stateKey]: parsedValue });
      }

      return parsedValue;
    } catch (error) {
      logger.error('loadFromLocalStorage: failed to load', { key, error });
      return defaultValue;
    }
  }

  /**
   * 커스텀 액션을 처리합니다.
   *
   * @param action 액션 정의
   * @param context 액션 컨텍스트
   */
  private async handleCustomAction(
    action: ActionDefinition,
    context: ActionContext
  ): Promise<any> {
    const handler = this.customHandlers.get(action.handler);

    if (!handler) {
      throw new ActionError(
        `Unknown action handler: ${action.handler}`,
        action
      );
    }

    return await handler(action, context);
  }

  /**
   * 파라미터를 해석합니다.
   *
   * @param params 원본 파라미터
   * @param dataContext 데이터 컨텍스트
   */
  private resolveParams(
    params: Record<string, any> | undefined,
    dataContext?: any
  ): Record<string, any> {
    if (!params) return {};

    const resolved: Record<string, any> = {};

    for (const [key, value] of Object.entries(params)) {
      if (key.includes('{{')) {
        logger.warn(
          `[resolveParams] setState params의 키에 표현식이 포함되어 있습니다: "${key}". ` +
            `키는 해석되지 않습니다. 배열 항목 수정은 .map()/.filter() 패턴을 사용하세요.`
        );
      }
      if (typeof value === 'string' && value.includes('{{')) {
        // {{}} 표현식인 경우 evaluateExpression 사용 (타입 보존)
        resolved[key] = this.evaluateExpression(value, dataContext);
      } else if (typeof value === 'string') {
        // 일반 문자열은 그대로 반환
        resolved[key] = value;
      } else if (Array.isArray(value)) {
        resolved[key] = value.map((item) =>
          typeof item === 'string' && item.includes('{{')
            ? this.evaluateExpression(item, dataContext)
            : item
        );
      } else if (
        typeof value === 'object' &&
        value !== null &&
        !Array.isArray(value) &&
        Object.getPrototypeOf(value) !== Object.prototype &&
        Object.getPrototypeOf(value) !== null
      ) {
        // File, Blob, Date 등 non-plain 객체는 재귀 해석하지 않고 직접 전달
        resolved[key] = value;
      } else if (typeof value === 'object' && value !== null) {
        resolved[key] = this.resolveParams(value, dataContext);
      } else {
        resolved[key] = value;
      }
    }

    return resolved;
  }

  /**
   * 문자열 값을 해석합니다 ({{}} 바인딩 처리).
   *
   * @param value 원본 값
   * @param dataContext 데이터 컨텍스트
   */
  private resolveValue(value: string, dataContext?: any): string {
    if (!dataContext) return value;

    // 액션 실행 시점에서는 항상 캐시를 사용하지 않음
    // - 이벤트마다 컨텍스트가 다름 (iteration 변수: item, index 등)
    // - $args, $event는 매번 새로운 값
    // - 트러블슈팅 가이드: "액션 실행 시점 (ActionDispatcher) | X 사용 안 함"
    return this.bindingEngine.resolveBindings(value, dataContext, { skipCache: true });
  }

  /**
   * 객체 내의 모든 {{}} 표현식을 평가합니다.
   *
   * "..." 키는 JavaScript spread 연산자처럼 동작합니다.
   * 예: { "...": "{{_local.form}}", "name": "new" } → { ...oldForm, name: "new" }
   *
   * @param obj 평가할 객체
   * @param dataContext 데이터 컨텍스트
   */
  private evaluateExpressions(obj: Record<string, any>, dataContext?: any): Record<string, any> {
    let result: Record<string, any> = {};

    for (const [key, value] of Object.entries(obj)) {
      if (key === '...') {
        // "..." 키는 spread 연산자로 처리
        // 값이 {{}} 표현식이면 평가하고, 객체면 병합
        let spreadValue = value;
        if (typeof value === 'string' && value.includes('{{')) {
          spreadValue = this.evaluateExpression(value, dataContext);
        }
        // spreadValue가 객체인 경우에만 병합
        if (spreadValue && typeof spreadValue === 'object' && !Array.isArray(spreadValue)) {
          result = { ...result, ...spreadValue };
        }
        // spreadValue가 null, undefined, 또는 객체가 아닌 경우 무시
      } else if (typeof value === 'string' && value.includes('{{')) {
        // {{}} 표현식 평가
        result[key] = this.evaluateExpression(value, dataContext);
      } else if (typeof value === 'object' && value !== null && !Array.isArray(value)) {
        // 중첩 객체 재귀 처리
        result[key] = this.evaluateExpressions(value, dataContext);
      } else {
        result[key] = value;
      }
    }

    return result;
  }

  /**
   * 이미 resolve된 payload에서 남아있는 {{}} 표현식만 평가합니다.
   * 배열, 객체 등 이미 평가된 값은 그대로 유지합니다.
   */
  private evaluateExpressionsIfNeeded(obj: Record<string, any>, dataContext?: any): Record<string, any> {
    const result: Record<string, any> = {};

    for (const [key, value] of Object.entries(obj)) {
      if (typeof value === 'string' && value.includes('{{')) {
        // 문자열이고 {{}}를 포함하면 평가
        result[key] = this.evaluateExpression(value, dataContext);
      } else {
        // 그 외는 그대로 유지 (배열, 객체, 기본값 등)
        result[key] = value;
      }
    }

    return result;
  }

  /**
   * {{}} 표현식을 평가합니다.
   *
   * DataBindingEngine.evaluateExpression을 사용하여 $t: 토큰 등을 올바르게 처리합니다.
   * 평가 결과가 $t:로 시작하는 문자열이면 번역을 수행합니다.
   *
   * @param expr 표현식 문자열
   * @param dataContext 데이터 컨텍스트
   */
  private evaluateExpression(expr: string, dataContext?: any): any {
    if (!dataContext) return expr;

    // {{expression}} 패턴 매칭 — 단일 {{...}} 표현식만 매칭
    const match = expr.match(/^\{\{(.+)\}\}$/);
    // 복합 표현식 감지: 캡처 그룹 내에 }} 또는 {{가 포함되면
    // 실제로는 {{A}}/text/{{B}} 형태의 복합 표현식임
    // (greedy .+가 첫 번째 {{부터 마지막 }}까지 모두 캡처하기 때문)
    const isSingleExpression = match && !match[1].includes('}}') && !match[1].includes('{{');

    if (!isSingleExpression) {
      // {{}} 패턴이 아니거나 복합 표현식({{A}}/text/{{B}})인 경우
      // resolveBindings가 각 {{...}} 블록을 개별 처리
      // 복합 표현식에서도 최신 _global/_computed 상태 주입 (Stale Closure 방지)
      let effectiveContext = dataContext;
      if (expr.includes('_global')) {
        const G7Core = (window as any).G7Core;
        const latestGlobal = G7Core?.state?.get()?._global;
        if (latestGlobal) {
          effectiveContext = { ...dataContext, _global: latestGlobal };
        }
      }
      if (expr.includes('_computed') || expr.includes('$computed')) {
        const actionContext = (window as any).__g7ActionContext;
        const latestComputed = actionContext?.computedRef?.current;
        if (latestComputed && Object.keys(latestComputed).length > 0) {
          effectiveContext = {
            ...effectiveContext,
            _computed: latestComputed,
            $computed: latestComputed,
          };
        }
      }
      return this.bindingEngine.resolveBindings(expr, effectiveContext, { skipCache: true });
    }

    let expression = match![1].trim();

    // $args.숫자 형태를 $args[숫자]로 변환 (예: $args.1 → $args[1])
    expression = expression.replace(/\$args\.(\d+)/g, '$args[$1]');

    // _global 참조 시 최신 전역 상태 사용 (Stale Closure 문제 해결)
    // 액션 핸들러가 렌더링 시점의 dataContext를 캡처하고 있어도,
    // _global 값은 항상 최신 상태를 참조하도록 함
    let effectiveDataContext = dataContext;
    if (expression.includes('_global')) {
      const G7Core = (window as any).G7Core;
      const latestGlobal = G7Core?.state?.get()?._global;
      if (latestGlobal) {
        effectiveDataContext = {
          ...dataContext,
          _global: latestGlobal,
        };
      }
    }

    // _computed 참조 시 최신 computed 상태 사용 (Stale Closure 문제 해결)
    // _computed는 _local 기반으로 계산되므로, _local 변경 후 캐싱된 컨텍스트의 _computed가 이전 값을 참조할 수 있음
    // computedRef.current는 매 렌더링마다 DynamicRenderer에서 최신 값으로 업데이트됨
    if (expression.includes('_computed') || expression.includes('$computed')) {
      const actionContext = (window as any).__g7ActionContext;
      const latestComputed = actionContext?.computedRef?.current;
      if (latestComputed && Object.keys(latestComputed).length > 0) {
        effectiveDataContext = {
          ...effectiveDataContext,
          _computed: latestComputed,
          $computed: latestComputed,
        };
      }
    }

    // 디버그 로그 (init_actions 바인딩 문제 진단용)
    if (expression.includes('_global.modules')) {
      logger.log('[evaluateExpression] expression:', expression);
      logger.log('[evaluateExpression] dataContext._global:', effectiveDataContext._global);
      logger.log('[evaluateExpression] dataContext._global?.modules:', effectiveDataContext._global?.modules);
    }

    try {
      // DataBindingEngine.evaluateExpression을 사용하여 $t: 토큰 등을 올바르게 처리
      const result = this.bindingEngine.evaluateExpression(expression, effectiveDataContext);

      // 디버그 로그 (init_actions 바인딩 문제 진단용)
      if (expression.includes('_global.modules')) {
        logger.log('[evaluateExpression] result:', result);
      }

      // 결과가 $t:로 시작하는 문자열이면 번역 수행
      if (typeof result === 'string' && result.startsWith('$t:') && this.translationEngine && this.translationContext) {
        return this.translationEngine.resolveTranslations(
          result,
          this.translationContext,
          dataContext
        );
      }

      return result;
    } catch (error) {
      logger.error('Expression evaluation failed:', expr, error);
      return expr;
    }
  }

  /**
   * 기본 컨텍스트를 설정합니다.
   *
   * 이 메서드는 초기화 이후에 navigate 함수 등을 주입할 때 사용됩니다.
   *
   * @param context 추가할 컨텍스트 (기존 컨텍스트와 병합됨)
   */
  setDefaultContext(context: Partial<ActionContext>): void {
    this.defaultContext = {
      ...this.defaultContext,
      ...context,
    };
  }

  /**
   * 전역 상태 업데이트 함수를 설정합니다.
   *
   * @param updater 전역 상태 업데이트 함수
   */
  setGlobalStateUpdater(updater: (updates: any) => void): void {
    this.globalStateUpdater = updater;
  }

  /**
   * 전역 상태 업데이트 함수를 반환합니다.
   *
   * @returns 전역 상태 업데이트 함수 또는 undefined
   */
  getGlobalStateUpdater(): ((updates: any) => void) | undefined {
    return this.globalStateUpdater;
  }

  /**
   * 액션을 직접 실행합니다. (템플릿 컴포넌트에서 useActions 훅을 통해 사용)
   *
   * 레이아웃 JSON의 액션 정의와 동일한 형태로 액션을 실행할 수 있습니다.
   * onSuccess, onError 체이닝도 지원됩니다.
   *
   * @param action 액션 정의
   * @param context 액션 컨텍스트 (선택적, 기본 컨텍스트와 병합됨)
   * @returns 액션 실행 결과
   *
   * @example
   * ```ts
   * const result = await dispatcher.dispatchAction({
   *   handler: 'navigate',
   *   params: { path: '/admin/users/1/edit' }
   * });
   *
   * const result = await dispatcher.dispatchAction({
   *   handler: 'apiCall',
   *   target: '/api/admin/users/1',
   *   params: { method: 'DELETE' },
   *   onSuccess: [
   *     { handler: 'toast', params: { type: 'success', message: '삭제 완료' } }
   *   ]
   * });
   * ```
   */
  async dispatchAction(
    action: ActionDefinition,
    context?: Partial<ActionContext>
  ): Promise<ActionResult> {
    // 컨텍스트 병합
    const mergedContext: ActionContext = {
      ...this.defaultContext,
      ...context,
      data: {
        ...this.defaultContext.data,
        ...context?.data,
      },
    };

    // 확인 메시지 표시
    if (action.confirm) {
      let message = this.resolveValue(action.confirm, mergedContext.data);

      // $t: 다국어 구문 처리
      if (this.translationEngine && this.translationContext && message.startsWith('$t:')) {
        message = this.translationEngine.resolveTranslations(
          message,
          this.translationContext,
          mergedContext.data
        );
      }

      if (!confirm(message)) {
        return { success: false };
      }
    }

    try {
      const result = await this.executeAction(action, mergedContext);
      return result;
    } catch (error) {
      logger.error('dispatchAction failed:', error);

      // onError 액션 실행
      if (action.onError) {
        const errorData = error instanceof ActionError && error.originalError
          ? { message: error.originalError.message, response: (error.originalError as any).response }
          : { message: error instanceof Error ? error.message : String(error) };

        const errorContext: ActionContext = {
          ...mergedContext,
          data: {
            ...mergedContext.data,
            error: errorData,
          },
        };

        const errorActions = Array.isArray(action.onError) ? action.onError : [action.onError];
        for (const errorAction of errorActions) {
          await this.executeAction(errorAction, errorContext);
        }
      }

      return {
        success: false,
        error: error instanceof Error ? error : new Error(String(error)),
      };
    }
  }

  /**
   * 커스텀 액션 핸들러를 등록합니다.
   *
   * @param name 핸들러 이름
   * @param handler 핸들러 함수
   * @param options 핸들러 옵션 (DevTools용)
   */
  registerHandler(
    name: string,
    handler: ActionHandler,
    options?: {
      category?: 'built-in' | 'custom' | 'module' | 'plugin';
      description?: string;
      source?: string;
    }
  ): void {
    this.customHandlers.set(name, handler);

    // DevTools에 핸들러 등록 추적
    const devTools = getDevTools();
    if (devTools?.isEnabled()) {
      devTools.trackHandlerRegistration(
        name,
        options?.category ?? 'custom',
        options?.description,
        options?.source
      );
    }
  }

  /**
   * 커스텀 액션 핸들러를 제거합니다.
   *
   * @param name 핸들러 이름
   */
  unregisterHandler(name: string): void {
    this.customHandlers.delete(name);

    // DevTools에 핸들러 해제 추적
    const devTools = getDevTools();
    if (devTools?.isEnabled()) {
      devTools.trackHandlerUnregistration(name);
    }
  }

  /**
   * 등록된 모든 핸들러를 조회합니다.
   */
  getRegisteredHandlers(): string[] {
    return Array.from(this.customHandlers.keys());
  }

  // ============================================================================
  // Debounce 유틸리티 메서드
  // ============================================================================

  /**
   * Debounce 설정을 정규화합니다.
   *
   * 숫자만 전달된 경우 기본 설정을 적용합니다.
   *
   * @param config debounce 설정 (숫자 또는 객체)
   * @returns 정규화된 debounce 설정
   */
  private normalizeDebounceConfig(
    config: number | { delay: number; leading?: boolean; trailing?: boolean }
  ): { delay: number; leading: boolean; trailing: boolean } {
    if (typeof config === 'number') {
      return { delay: config, leading: false, trailing: true };
    }
    return {
      delay: config.delay,
      leading: config.leading ?? false,
      trailing: config.trailing ?? true,
    };
  }

  /**
   * 이벤트에서 필요한 데이터를 추출합니다.
   *
   * 이벤트 객체는 비동기 콜백에서 접근할 수 없으므로
   * 필요한 데이터를 미리 추출합니다.
   *
   * @param event DOM 이벤트
   * @returns 추출된 이벤트 데이터
   */
  private extractEventData(event: Event): Record<string, any> {
    const target = event.target as HTMLInputElement | HTMLSelectElement | null;

    // 커스텀 이벤트 감지 (예: MultilingualInput에서 emit하는 { target: { name, value } })
    // 커스텀 이벤트는 tagName이 없고, value가 객체일 수 있음
    const isCustomEvent = target && !('tagName' in target);

    if (isCustomEvent) {
      // 커스텀 이벤트: target 속성을 그대로 복사
      const result: Record<string, any> = {
        type: (event as any).type ?? 'custom',
        target: {
          value: (target as any).value,
          name: (target as any).name ?? '',
        },
      };

      // _changedKeys 메타데이터 보존 (디바운스 병합에 사용)
      if ((event as any)._changedKeys) {
        result._changedKeys = (event as any)._changedKeys;
      }

      return result;
    }

    // DOM 이벤트: 기존 로직
    const targetData: Record<string, any> = target
      ? {
          value: target.value,
          name: target.name ?? '',
          checked: (target as HTMLInputElement).checked ?? false,
          type: target.type ?? '',
          tagName: target.tagName,
        }
      : {};

    // scroll 이벤트인 경우 스크롤 관련 속성 추가
    if (event.type === 'scroll' && target) {
      const element = target as HTMLElement;
      targetData.scrollHeight = element.scrollHeight ?? 0;
      targetData.scrollTop = element.scrollTop ?? 0;
      targetData.clientHeight = element.clientHeight ?? 0;
      targetData.scrollLeft = element.scrollLeft ?? 0;
      targetData.clientWidth = element.clientWidth ?? 0;
      targetData.scrollWidth = element.scrollWidth ?? 0;
    }

    return {
      type: event.type,
      target: target ? targetData : null,
    };
  }

  /**
   * Debounced 액션을 실행합니다.
   *
   * @param action 액션 정의
   * @param extractedEvent 추출된 이벤트 데이터
   * @param dataContext 데이터 컨텍스트
   * @param componentContext 컴포넌트 컨텍스트
   * @param args 원본 이벤트 인자
   */
  private executeDebouncedAction(
    action: ActionDefinition,
    extractedEvent: Record<string, any>,
    dataContext: any,
    componentContext?: { state?: any; setState?: (updates: any) => void; isolatedContext?: IsolatedContextValue | null },
    args?: any[]
  ): void {
    // $event를 추출된 데이터로 대체한 컨텍스트 생성
    const contextWithEvent = {
      ...dataContext,
      $event: extractedEvent,
      $args: args,
    };

    // 가짜 이벤트 객체 생성 (extractedEvent 기반)
    const syntheticEvent = {
      type: extractedEvent.type,
      target: extractedEvent.target,
      preventDefault: () => {},
      stopPropagation: () => {},
    } as Event;

    this.createHandler(action, contextWithEvent, componentContext)(syntheticEvent);
  }

  /**
   * Debounce가 적용된 핸들러를 실행합니다.
   *
   * @param action 액션 정의
   * @param event DOM 이벤트
   * @param dataContext 데이터 컨텍스트
   * @param componentContext 컴포넌트 컨텍스트
   * @param debounceKey debounce 고유 키
   * @param args 원본 이벤트 인자
   */
  private handleDebouncedAction(
    action: ActionDefinition,
    event: Event,
    dataContext: any,
    componentContext?: { state?: any; setState?: (updates: any) => void; isolatedContext?: IsolatedContextValue | null },
    debounceKey?: string,
    args?: any[]
  ): void {
    const config = this.normalizeDebounceConfig(action.debounce!);
    const key = debounceKey || `default-${action.handler}-${Date.now()}`;

    // 이벤트에서 필요한 데이터 즉시 추출 (비동기 접근 불가)
    const extractedEvent = this.extractEventData(event);

    // _changedKeys 프로토콜: 객체 값의 변경 키만 누적하여 stale closure 방지
    // MultilingualInput 등에서 emit한 _changedKeys를 기반으로 이전 디바운스 누적값과 병합
    const changedKeys = extractedEvent._changedKeys;
    if (changedKeys && Array.isArray(changedKeys)
        && extractedEvent?.target?.value
        && typeof extractedEvent.target.value === 'object'
        && !Array.isArray(extractedEvent.target.value)) {
      const accumulated = this.debounceAccumulatedValues.get(key);
      if (accumulated) {
        // 이전 누적값 기반, 현재 변경 키만 덮어쓰기
        const merged = { ...accumulated };
        for (const k of changedKeys) {
          merged[k] = extractedEvent.target.value[k];
        }
        extractedEvent.target.value = merged;
      }
      this.debounceAccumulatedValues.set(key, extractedEvent.target.value);
    }

    // 기존 타이머 취소
    const existingTimer = this.debounceTimers.get(key);
    if (existingTimer) {
      clearTimeout(existingTimer);
      this.debounceTimers.delete(key);
    }

    // DevTools 추적 - pending 상태
    const devTools = getDevTools();
    if (devTools?.isEnabled()) {
      devTools.trackAction({
        handler: typeof action.handler === 'string' ? action.handler : String(action.handler),
        type: action.type,
        status: 'pending',
        params: action.params,
        debounce: {
          delay: config.delay,
          status: 'pending',
          scheduledAt: Date.now(),
        },
      });
    }

    // leading: true이고 첫 호출인 경우 즉시 실행
    const isFirstCall = !this.debounceTimers.has(key + '_leading');
    if (config.leading && isFirstCall) {
      this.debounceTimers.set(key + '_leading', setTimeout(() => {}, 0)); // 마커용
      this.executeDebouncedAction(action, extractedEvent, dataContext, componentContext, args);

      // DevTools 추적 - leading 실행
      if (devTools?.isEnabled()) {
        devTools.trackAction({
          handler: typeof action.handler === 'string' ? action.handler : String(action.handler),
          type: action.type,
          status: 'success',
          params: action.params,
          debounce: {
            delay: config.delay,
            status: 'executed',
            executedAt: Date.now(),
            mode: 'leading',
          },
        });
      }
    }

    // trailing: true이면 마지막 호출 후 delay 후 실행
    if (config.trailing) {
      // 플러시 함수: 비디바운스 액션이 실행되기 전에 즉시 호출될 수 있음
      const executeTrailing = () => {
        this.executeDebouncedAction(action, extractedEvent, dataContext, componentContext, args);

        // DevTools 추적 - trailing 실행
        if (devTools?.isEnabled()) {
          devTools.trackAction({
            handler: typeof action.handler === 'string' ? action.handler : String(action.handler),
            type: action.type,
            status: 'success',
            params: action.params,
            debounce: {
              delay: config.delay,
              status: 'executed',
              executedAt: Date.now(),
              mode: 'trailing',
            },
          });
        }
      };

      const timer = setTimeout(() => {
        this.debounceTimers.delete(key);
        this.debounceTimers.delete(key + '_leading'); // leading 마커 정리
        this.pendingDebounceFlushers.delete(key);
        this.debounceAccumulatedValues.delete(key); // _changedKeys 누적값 정리

        executeTrailing();
      }, config.delay);

      this.debounceTimers.set(key, timer);
      this.pendingDebounceFlushers.set(key, executeTrailing);
    }
  }

  /**
   * 컴포넌트 언마운트 시 debounce 타이머를 정리합니다.
   *
   * @param componentId 컴포넌트 ID
   */
  clearDebounceTimers(componentId?: string): void {
    if (componentId) {
      // 특정 컴포넌트의 타이머만 정리
      for (const [key, timer] of this.debounceTimers) {
        if (key.startsWith(componentId + '-')) {
          clearTimeout(timer);
          this.debounceTimers.delete(key);
          this.pendingDebounceFlushers.delete(key);
          this.debounceAccumulatedValues.delete(key);
        }
      }
    } else {
      // 모든 타이머 정리
      for (const timer of this.debounceTimers.values()) {
        clearTimeout(timer);
      }
      this.debounceTimers.clear();
      this.pendingDebounceFlushers.clear();
      this.debounceAccumulatedValues.clear();
    }
  }

  /**
   * 대기 중인 모든 debounce 액션을 즉시 실행합니다.
   *
   * 비디바운스 액션이 실행되기 전에 호출하여, 디바운스로 인해
   * 아직 state에 반영되지 않은 변경사항을 즉시 적용합니다.
   * 이를 통해 비디바운스 핸들러가 항상 최신 state를 읽을 수 있습니다.
   */
  flushPendingDebounceTimers(): void {
    if (this.pendingDebounceFlushers.size === 0) return;

    // 반복 중 맵 변경 방지를 위해 복사 후 정리
    const flushers = new Map(this.pendingDebounceFlushers);

    for (const [key, flushFn] of flushers) {
      // 대기 중인 타이머 취소
      const timer = this.debounceTimers.get(key);
      if (timer) {
        clearTimeout(timer);
        this.debounceTimers.delete(key);
      }
      this.debounceTimers.delete(key + '_leading');
      this.pendingDebounceFlushers.delete(key);

      // 즉시 실행
      flushFn();
    }
  }

  /**
   * 컴포넌트 props에서 액션 핸들러를 생성합니다.
   *
   * @param props 컴포넌트 props
   * @param dataContext 데이터 컨텍스트
   * @param componentContext 컴포넌트 컨텍스트 (state, setState)
   */
  bindActionsToProps(
    props: Record<string, any>,
    dataContext?: any,
    componentContext?: { state?: any; setState?: (updates: any) => void; isolatedContext?: IsolatedContextValue | null }
  ): Record<string, any> {
    const boundProps: Record<string, any> = { ...props };

    // actions 필드에서 액션 정의 추출
    if (props.actions && Array.isArray(props.actions)) {
      // 동일한 이벤트 타입의 액션들을 그룹화
      const actionsByEvent: Map<string, ActionDefinition[]> = new Map();

      for (const rawAction of props.actions) {
        // actionRef 해석 - named_actions 참조를 실제 액션 정의로 변환
        const action = this.resolveActionRef(rawAction);
        const eventName = action.event || this.getEventHandlerName(action.type);
        if (!actionsByEvent.has(eventName)) {
          actionsByEvent.set(eventName, []);
        }
        actionsByEvent.get(eventName)!.push(action);
      }

      // 컴포넌트 ID 추출 (debounce key 생성용)
      const componentId = props.name || props.id || 'unknown';

      // drop 액션이 있지만 dragover/dragenter 액션이 없으면 자동으로 preventDefault 핸들러 추가
      // HTML5 드래그앤드롭에서 drop을 허용하려면 dragenter + dragover 모두 preventDefault() 호출 필요
      const hasDropAction = actionsByEvent.has('onDrop');
      const hasDragOverAction = actionsByEvent.has('onDragOver');
      const hasDragEnterAction = actionsByEvent.has('onDragEnter');
      if (hasDropAction && !hasDragOverAction) {
        boundProps['onDragOver'] = (e: DragEvent) => {
          e.preventDefault();
        };
      }
      if (hasDropAction && !hasDragEnterAction) {
        boundProps['onDragEnter'] = (e: DragEvent) => {
          e.preventDefault();
        };
      }

      // 각 이벤트 타입별로 핸들러 생성
      for (const [eventName, actions] of actionsByEvent) {
        // 커스텀 이벤트 (onSortChange, onSelectionChange 등)는 가변 인자를 받음
        boundProps[eventName] = (...args: any[]) => {
          for (const action of actions) {
            // 첫 번째 인자가 이벤트 객체인 경우 (표준 DOM 이벤트)
            const firstArg = args[0];
            const isStandardEvent = firstArg && typeof firstArg === 'object' && 'preventDefault' in firstArg;

            // dragover/drop 이벤트에서 자동으로 preventDefault 호출 (드롭 허용)
            if (isStandardEvent && (eventName === 'onDragOver' || eventName === 'onDrop')) {
              firstArg.preventDefault();
            }

            // 키보드 이벤트에서 key 필터링
            if (isStandardEvent && action.key && 'key' in firstArg) {
              if (firstArg.key !== action.key) {
                logger.log('Key filter not matched:', action.key, 'actual:', firstArg.key);
                continue; // 키가 일치하지 않으면 이 액션 건너뛰기
              }
              logger.log('Key filter matched:', action.key);
            }

            // debounce 옵션이 있는 경우 debounce 처리
            if (action.debounce) {
              const debounceKey = `${componentId}-${action.handler}-${eventName}`;

              // 커스텀 컴포넌트 이벤트 객체 감지 (예: MultilingualInput의 { target: { name, value } })
              // React 컴포넌트에서 onChange로 emit하는 plain object
              const isCustomComponentEvent =
                firstArg &&
                typeof firstArg === 'object' &&
                !('preventDefault' in firstArg) &&
                'target' in firstArg &&
                firstArg.target !== null;

              // 이벤트 객체 결정: 표준 DOM 이벤트 > 커스텀 컴포넌트 이벤트 > 빈 이벤트
              let eventForHandler: Event;
              if (isStandardEvent) {
                eventForHandler = firstArg;
              } else if (isCustomComponentEvent) {
                // 커스텀 컴포넌트 이벤트를 synthetic event로 변환
                const syntheticEvent: any = {
                  type: 'custom',
                  target: firstArg.target,
                  preventDefault: () => {},
                  stopPropagation: () => {},
                };
                // _changedKeys 메타데이터 보존 (디바운스 병합에 사용)
                if (firstArg._changedKeys) {
                  syntheticEvent._changedKeys = firstArg._changedKeys;
                }
                eventForHandler = syntheticEvent as unknown as Event;
              } else {
                eventForHandler = new Event('custom');
              }

              // 커스텀 이벤트인 경우 $args 컨텍스트 추가
              const contextForDebounce = action.event
                ? { ...dataContext, $args: args }
                : dataContext;

              this.handleDebouncedAction(
                action,
                eventForHandler,
                contextForDebounce,
                componentContext,
                debounceKey,
                args
              );
            } else {
              // 대기 중인 debounce 액션을 즉시 실행하여 state 최신화
              this.flushPendingDebounceTimers();

              // 기존 로직 (debounce 없음)
              // 커스텀 이벤트 핸들러인 경우 (action.event 사용)
              if (action.event) {
                // 커스텀 콜백의 모든 인자를 $args 배열로 전달
                const contextWithArgs = {
                  ...dataContext,
                  $args: args,
                };

                // 표준 DOM 이벤트가 아닌 경우 빈 이벤트 객체 생성
                const eventForHandler = isStandardEvent ? firstArg : new Event('custom');
                this.createHandler(action, contextWithArgs, componentContext)(eventForHandler);
              } else {
                // 표준 이벤트 핸들러
                if (isStandardEvent) {
                  this.createHandler(action, dataContext, componentContext)(firstArg);
                } else {
                  // 커스텀 컴포넌트 이벤트 감지 (예: MultilingualInput의 { target: { name, value } })
                  // React 컴포넌트에서 onChange로 emit하는 plain object
                  const isCustomComponentEvent =
                    firstArg &&
                    typeof firstArg === 'object' &&
                    !('preventDefault' in firstArg) &&
                    'target' in firstArg &&
                    firstArg.target !== null;

                  if (isCustomComponentEvent) {
                    // 커스텀 컴포넌트 이벤트를 synthetic event로 변환
                    const eventForHandler = {
                      type: 'custom',
                      target: firstArg.target,
                      preventDefault: () => {},
                      stopPropagation: () => {},
                    } as unknown as Event;
                    this.createHandler(action, dataContext, componentContext)(eventForHandler);
                  }
                }
              }
            }
          }
        };
      }
    }

    return boundProps;
  }

  /**
   * 이벤트 타입을 핸들러 prop 이름으로 변환합니다.
   *
   * React는 camelCase 이벤트 이름을 사용합니다 (예: onKeyDown, onMouseEnter)
   *
   * @param eventType 이벤트 타입
   */
  private getEventHandlerName(eventType: EventType): string {
    // React 이벤트 이름 매핑 (camelCase)
    const eventNameMap: Record<string, string> = {
      click: 'onClick',
      change: 'onChange',
      input: 'onInput',
      submit: 'onSubmit',
      focus: 'onFocus',
      blur: 'onBlur',
      keydown: 'onKeyDown',
      keyup: 'onKeyUp',
      keypress: 'onKeyPress',
      mousedown: 'onMouseDown',
      mouseup: 'onMouseUp',
      mouseenter: 'onMouseEnter',
      mouseleave: 'onMouseLeave',
      scroll: 'onScroll',
      // 드래그 앤 드롭 이벤트
      dragstart: 'onDragStart',
      drag: 'onDrag',
      dragend: 'onDragEnd',
      dragenter: 'onDragEnter',
      dragover: 'onDragOver',
      dragleave: 'onDragLeave',
      drop: 'onDrop',
    };

    return eventNameMap[eventType] || `on${eventType.charAt(0).toUpperCase()}${eventType.slice(1)}`;
  }

  /**
   * DevTools 로깅용으로 데이터를 안전하게 직렬화합니다.
   *
   * 순환 참조, 함수, DOM 요소 등을 제거하여 안전하게 로깅할 수 있도록 합니다.
   *
   * @param data 직렬화할 데이터
   * @param maxDepth 최대 깊이 (기본값: 5)
   * @returns 직렬화된 데이터
   */
  private sanitizeForDevTools(data: any, maxDepth: number = 5): any {
    const seen = new WeakSet();

    const sanitize = (value: any, depth: number): any => {
      // 기본 타입은 그대로 반환
      if (value === null || value === undefined) {
        return value;
      }

      if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
        return value;
      }

      // 깊이 제한 초과
      if (depth > maxDepth) {
        return '[Max Depth Exceeded]';
      }

      // 함수는 함수명만 반환
      if (typeof value === 'function') {
        return `[Function: ${value.name || 'anonymous'}]`;
      }

      // DOM 요소는 태그명과 클래스 반환
      if (value instanceof Element) {
        return `[Element: ${value.tagName}${value.className ? '.' + value.className.split(' ').join('.') : ''}]`;
      }

      // Event 객체는 타입만 반환
      if (value instanceof Event) {
        return `[Event: ${value.type}]`;
      }

      // 배열 처리
      if (Array.isArray(value)) {
        if (seen.has(value)) {
          return '[Circular Array]';
        }
        seen.add(value);
        // 큰 배열은 처음 10개만
        const result = value.slice(0, 10).map(item => sanitize(item, depth + 1));
        if (value.length > 10) {
          result.push(`... ${value.length - 10} more items`);
        }
        return result;
      }

      // 객체 처리
      if (typeof value === 'object') {
        if (seen.has(value)) {
          return '[Circular Reference]';
        }
        seen.add(value);

        const result: Record<string, any> = {};
        const keys = Object.keys(value);

        // 큰 객체는 처음 20개 키만
        const keysToProcess = keys.slice(0, 20);
        for (const key of keysToProcess) {
          try {
            result[key] = sanitize(value[key], depth + 1);
          } catch {
            result[key] = '[Error accessing property]';
          }
        }

        if (keys.length > 20) {
          result['...'] = `${keys.length - 20} more properties`;
        }

        return result;
      }

      return String(value);
    };

    return sanitize(data, 0);
  }
}

/**
 * 싱글톤 인스턴스 생성 헬퍼
 */
let instance: ActionDispatcher | null = null;

/**
 * ActionDispatcher 싱글톤 인스턴스를 반환합니다.
 *
 * @param defaultContext 기본 컨텍스트 (인스턴스 생성 시에만 적용)
 * @returns ActionDispatcher 싱글톤 인스턴스
 */
export function getActionDispatcher(
  defaultContext?: Partial<ActionContext>
): ActionDispatcher {
  if (!instance) {
    instance = new ActionDispatcher(defaultContext);
  }
  return instance;
}

/**
 * ActionDispatcher 싱글톤 인스턴스를 설정합니다.
 *
 * template-engine.ts에서 생성한 ActionDispatcher 인스턴스를
 * 싱글톤으로 설정하여 renderItemChildren 등에서 동일한 인스턴스를 사용하도록 합니다.
 *
 * @param dispatcher 설정할 ActionDispatcher 인스턴스
 */
export function setActionDispatcherInstance(dispatcher: ActionDispatcher): void {
  instance = dispatcher;
}
