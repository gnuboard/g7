/**
 * NOTE: @since versions (engine-v1.x.x) refer to internal template engine
 * development iterations, not G7 platform release versions.
 */
/**
 * ErrorHandling.ts
 *
 * 에러 핸들링 시스템의 타입 정의
 *
 * 계층적 에러 핸들링 설정 구조:
 * - 액션 개별 → 레이아웃 전역 → 템플릿 전역 → 시스템 기본값
 *
 * @module ErrorHandling
 */

import type { ActionDefinition } from '../template-engine/ActionDispatcher';

/**
 * HTTP 에러 코드 타입
 *
 * 문자열 또는 숫자로 에러 코드를 지정할 수 있습니다.
 * - 400: Bad Request
 * - 401: Unauthorized
 * - 403: Forbidden
 * - 404: Not Found
 * - 422: Unprocessable Entity
 * - 429: Too Many Requests
 * - 500: Internal Server Error
 * - 503: Service Unavailable
 * - 'default': 매칭되는 코드가 없을 때 사용
 */
export type ErrorCode = number | string | 'default';

/**
 * 에러 핸들러 설정
 *
 * 단일 액션 정의 또는 복합 액션(sequence, parallel)을 사용할 수 있습니다.
 *
 * @example 단일 핸들러
 * ```json
 * {
 *   "handler": "toast",
 *   "params": { "type": "error", "message": "{{error.message}}" }
 * }
 * ```
 *
 * @example sequence 핸들러
 * ```json
 * {
 *   "handler": "sequence",
 *   "actions": [
 *     { "handler": "setState", "params": { "target": "global", "errorMessage": "{{error.message}}" } },
 *     { "handler": "openModal", "target": "error_modal" }
 *   ]
 * }
 * ```
 */
export type ErrorHandlerConfig = Omit<ActionDefinition, 'type'>;

/**
 * 에러 핸들링 맵
 *
 * HTTP 에러 코드를 키로, 핸들러 설정을 값으로 가지는 객체입니다.
 * 'default' 키는 명시적으로 정의되지 않은 에러 코드에 대한 폴백으로 사용됩니다.
 *
 * @example
 * ```json
 * {
 *   "401": { "handler": "navigate", "params": { "path": "/admin/login" } },
 *   "403": { "handler": "showErrorPage", "params": { "target": "content" } },
 *   "404": { "handler": "toast", "params": { "type": "warning", "message": "$t:errors.not_found" } },
 *   "default": { "handler": "toast", "params": { "type": "error", "message": "{{error.message}}" } }
 * }
 * ```
 */
export type ErrorHandlingMap = {
  [key in ErrorCode]?: ErrorHandlerConfig;
};

/**
 * onError 핸들러 타입
 *
 * errorHandling에 매칭되는 코드가 없을 때 폴백으로 실행됩니다.
 * 단일 액션 객체 또는 배열(순차 실행)을 지원합니다.
 */
export type OnErrorHandler = ErrorHandlerConfig | ErrorHandlerConfig[];

/**
 * onSuccess 핸들러 설정 타입
 *
 * 단일 액션 정의 또는 복합 액션(sequence, parallel)을 사용할 수 있습니다.
 * params에서 {{response.xxx}} 형태로 API 응답에 접근할 수 있습니다.
 */
export type SuccessHandlerConfig = Omit<ActionDefinition, 'type'>;

/**
 * onSuccess 핸들러 타입
 *
 * 데이터 소스 fetch 성공 시 실행됩니다.
 * 단일 액션 객체 또는 배열(순차 실행)을 지원합니다.
 *
 * @example 단일 핸들러
 * ```json
 * {
 *   "onSuccess": {
 *     "handler": "openModal",
 *     "target": "result_modal"
 *   }
 * }
 * ```
 *
 * @example 조건부 핸들러
 * ```json
 * {
 *   "onSuccess": {
 *     "handler": "conditions",
 *     "conditions": [
 *       {
 *         "if": "{{response.data.has_error}}",
 *         "handler": "openModal",
 *         "target": "error_modal"
 *       }
 *     ]
 *   }
 * }
 * ```
 *
 * @example sequence 핸들러
 * ```json
 * {
 *   "onSuccess": {
 *     "handler": "sequence",
 *     "actions": [
 *       { "handler": "setState", "params": { "target": "local", "resultData": "{{response.data}}" } },
 *       { "handler": "toast", "params": { "type": "success", "message": "Data loaded" } }
 *     ]
 *   }
 * }
 * ```
 */
export type OnSuccessHandler = SuccessHandlerConfig | SuccessHandlerConfig[];

/**
 * onSuccess 컨텍스트
 *
 * onSuccess 핸들러 params에서 {{response.xxx}} 형태로 접근할 수 있는 컨텍스트입니다.
 */
export interface SuccessContext {
  /** API 응답 데이터 (전체) */
  data: any;
  /** 데이터 소스 ID */
  sourceId: string;
}

/**
 * 에러 컨텍스트
 *
 * 에러 핸들러 params에서 {{error.xxx}} 형태로 접근할 수 있는 컨텍스트입니다.
 */
export interface ErrorContext {
  /** HTTP 상태 코드 */
  status: number;
  /** 에러 메시지 */
  message: string;
  /** 필드별 에러 (422 Validation Error) */
  errors?: Record<string, string[]>;
  /** API 응답 전체 데이터 */
  data?: any;
  /** HTTP 상태 텍스트 */
  statusText?: string;
}

/**
 * showErrorPage 핸들러 params
 *
 * 기존 ErrorPageHandler를 액션 시스템에서 호출하기 위한 파라미터입니다.
 */
export interface ShowErrorPageParams {
  /**
   * 에러 코드 (404, 403, 500 등)
   *
   * 생략 시 errorHandling 키값이 자동으로 사용됩니다.
   */
  errorCode?: number;

  /**
   * 렌더링 대상
   *
   * - 'full': 전체 화면 교체 (사이드바 포함), containerId는 'app'
   * - 'content': 컨텐츠 영역만 교체 (사이드바 유지), containerId는 'main-content'
   *
   * @default 'content'
   */
  target?: 'full' | 'content';

  /**
   * 렌더링할 컨테이너 ID
   *
   * 직접 지정 시 target 옵션은 무시됩니다.
   */
  containerId?: string;

  /**
   * 사용할 레이아웃 경로
   *
   * 지정하지 않으면 template.json의 error_config.layouts[errorCode] 사용
   */
  layout?: string;
}

/**
 * 데이터소스 응답 조건부 에러 처리
 *
 * API가 200으로 응답해도 조건이 truthy면 에러로 처리합니다.
 * 표현식에서 `response`로 API 응답 데이터에 접근합니다.
 *
 * @since engine-v1.18.0
 *
 * @example
 * ```json
 * {
 *   "errorCondition": {
 *     "if": "{{response?.data?.abilities?.can_update !== true}}",
 *     "errorCode": 403
 *   }
 * }
 * ```
 */
export interface ErrorCondition {
  /** 조건 표현식 - truthy일 때 에러로 처리 */
  if: string;
  /** 트리거할 에러 코드 (예: 403, 404) */
  errorCode: number;
}

/**
 * 에러 핸들링 설정 레벨
 *
 * 우선순위:
 * 1. action (액션 개별)
 * 2. dataSource (데이터 소스 개별)
 * 3. layout (레이아웃 전역)
 * 4. template (템플릿 전역)
 * 5. system (시스템 기본값)
 */
export type ErrorHandlingLevel =
  | 'action'
  | 'dataSource'
  | 'layout'
  | 'template'
  | 'system';

/**
 * 에러 핸들링 설정 소스
 *
 * ErrorHandlingResolver에서 설정을 병합할 때 사용합니다.
 */
export interface ErrorHandlingSource {
  /** 설정 레벨 */
  level: ErrorHandlingLevel;
  /** 에러 핸들링 맵 */
  errorHandling?: ErrorHandlingMap;
  /** onError 폴백 */
  onError?: OnErrorHandler;
}

/**
 * 에러 핸들링 결과
 *
 * ErrorHandlingResolver.resolve()의 반환 타입입니다.
 */
export interface ErrorHandlingResult {
  /** 실행할 핸들러 설정 */
  handler: ErrorHandlerConfig | null;
  /** 핸들러가 결정된 레벨 */
  level: ErrorHandlingLevel | null;
  /** 매칭된 에러 코드 또는 'default' 또는 'onError' */
  matchedKey: ErrorCode | 'onError' | null;
}

/**
 * 시스템 기본 에러 핸들링 설정
 *
 * 모든 레벨에서 설정이 없을 때 사용되는 기본값입니다.
 */
export const DEFAULT_ERROR_HANDLING: ErrorHandlingMap = {
  401: {
    handler: 'navigate',
    params: { path: '{{auth.loginPath}}' },
  },
  403: {
    handler: 'toast',
    params: { type: 'error', message: '{{error.message}}' },
  },
  404: {
handler: 'toast',
    params: { type: 'error', message: '{{error.message}}' },
  },
  422: {
    handler: 'toast',
    params: { type: 'error', message: '{{error.message}}' },
  },
  default: {
    handler: 'toast',
    params: { type: 'error', message: '{{error.message}}' },
  },
};

/**
 * 에러 핸들링 유틸리티 함수
 */
export const ErrorHandlingUtils = {
  /**
   * 에러 코드에 해당하는 핸들러를 찾습니다.
   *
   * @param errorHandling 에러 핸들링 맵
   * @param errorCode 에러 코드
   * @returns 핸들러 설정 또는 undefined
   */
  findHandler(
    errorHandling: ErrorHandlingMap | undefined,
    errorCode: number
  ): ErrorHandlerConfig | undefined {
    if (!errorHandling) return undefined;

    // 1. 정확한 코드 매칭 (숫자)
    if (errorHandling[errorCode]) {
      return errorHandling[errorCode];
    }

    // 2. 문자열 코드 매칭
    if (errorHandling[String(errorCode)]) {
      return errorHandling[String(errorCode)];
    }

    // 3. default 폴백
    if (errorHandling.default) {
      return errorHandling.default;
    }

    return undefined;
  },

  /**
   * onError 핸들러를 배열로 정규화합니다.
   *
   * @param onError onError 핸들러 (단일 또는 배열)
   * @returns 핸들러 배열
   */
  normalizeOnError(onError: OnErrorHandler | undefined): ErrorHandlerConfig[] {
    if (!onError) return [];
    return Array.isArray(onError) ? onError : [onError];
  },

  /**
   * showErrorPage params의 errorCode를 자동 주입합니다.
   *
   * errorHandling 키값을 errorCode로 사용하되, "default" 등 비숫자 키인 경우
   * 실제 HTTP 에러 코드(actualErrorCode)를 fallback으로 사용합니다.
   *
   * @param handler 핸들러 설정
   * @param errorCodeKey errorHandling 맵의 키값
   * @param actualErrorCode 실제 HTTP 에러 코드 (default 키 매칭 시 fallback)
   * @returns errorCode가 주입된 핸들러 설정
   */
  injectErrorCode(
    handler: ErrorHandlerConfig,
    errorCodeKey: ErrorCode,
    actualErrorCode?: number
  ): ErrorHandlerConfig {
    if (handler.handler !== 'showErrorPage') {
      return handler;
    }

    // params.errorCode가 이미 있으면 그대로 사용
    if (handler.params?.errorCode !== undefined) {
      return handler;
    }

    // errorHandling 키값을 errorCode로 주입
    const errorCode = typeof errorCodeKey === 'string'
      ? parseInt(errorCodeKey, 10)
      : errorCodeKey;

    // 유효한 숫자이면 키값 사용, 아니면 실제 에러 코드를 fallback으로 사용
    const resolvedErrorCode = isNaN(errorCode as number) ? actualErrorCode : errorCode;

    if (resolvedErrorCode === undefined) {
      return handler;
    }

    return {
      ...handler,
      params: {
        ...handler.params,
        errorCode: resolvedErrorCode,
      },
    };
  },
};
