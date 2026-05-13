/**
 * ErrorHandlingResolver.ts
 *
 * 계층적 에러 핸들링 설정을 해석하고 실행하는 클래스
 *
 * 우선순위:
 * 1. 액션/데이터소스 errorHandling[코드]
 * 2. 액션/데이터소스 errorHandling[default]
 * 3. 액션/데이터소스 onError
 * 4. 레이아웃 errorHandling[코드]
 * 5. 레이아웃 errorHandling[default]
 * 6. 템플릿 errorHandling[코드]
 * 7. 템플릿 errorHandling[default]
 * 8. 시스템 기본값
 *
 * @module ErrorHandlingResolver
 */

import type {
  ErrorCode,
  ErrorContext,
  ErrorHandlerConfig,
  ErrorHandlingLevel,
  ErrorHandlingMap,
  ErrorHandlingResult,
  ErrorHandlingSource,
  OnErrorHandler,
} from '../types/ErrorHandling';
import {
  DEFAULT_ERROR_HANDLING,
  ErrorHandlingUtils,
} from '../types/ErrorHandling';
import { createLogger } from '../utils/Logger';

const logger = createLogger('ErrorHandlingResolver');

/**
 * 에러 핸들링 설정 해석기
 *
 * 계층별 에러 핸들링 설정을 관리하고, 에러 코드에 맞는 핸들러를 결정합니다.
 *
 * @example
 * ```ts
 * const resolver = ErrorHandlingResolver.getInstance();
 *
 * // 템플릿/레이아웃 설정 등록
 * resolver.setTemplateConfig(templateErrorHandling);
 * resolver.setLayoutConfig(layoutErrorHandling);
 *
 * // 에러 발생 시 핸들러 찾기
 * const result = resolver.resolve(403, {
 *   errorHandling: actionErrorHandling,
 *   onError: actionOnError,
 * });
 *
 * if (result.handler) {
 *   await resolver.executeHandler(result.handler, errorContext);
 * }
 * ```
 */
export class ErrorHandlingResolver {
  /** 싱글톤 인스턴스 */
  private static instance: ErrorHandlingResolver | null = null;

  /** 템플릿 레벨 에러 핸들링 설정 */
  private templateErrorHandling: ErrorHandlingMap | null = null;

  /** 레이아웃 레벨 에러 핸들링 설정 */
  private layoutErrorHandling: ErrorHandlingMap | null = null;

  /** 액션 실행 함수 (ActionDispatcher.dispatchAction) */
  private executeAction: ((handler: ErrorHandlerConfig, errorContext: any) => Promise<any>) | null = null;

  private constructor() {}

  /**
   * 싱글톤 인스턴스를 반환합니다.
   */
  static getInstance(): ErrorHandlingResolver {
    if (!ErrorHandlingResolver.instance) {
      ErrorHandlingResolver.instance = new ErrorHandlingResolver();
    }
    return ErrorHandlingResolver.instance;
  }

  /**
   * 싱글톤 인스턴스를 초기화합니다. (테스트용)
   */
  static resetInstance(): void {
    ErrorHandlingResolver.instance = null;
  }

  /**
   * 템플릿 레벨 에러 핸들링 설정을 등록합니다.
   *
   * @param config 템플릿의 errorHandling 설정
   */
  setTemplateConfig(config: ErrorHandlingMap | null): void {
    this.templateErrorHandling = config;
    logger.log('Template config set:', config);
  }

  /**
   * 레이아웃 레벨 에러 핸들링 설정을 등록합니다.
   *
   * @param config 레이아웃의 errorHandling 설정
   */
  setLayoutConfig(config: ErrorHandlingMap | null): void {
    this.layoutErrorHandling = config;
    logger.log('Layout config set:', config);
  }

  /**
   * 액션 실행 함수를 설정합니다.
   *
   * ActionDispatcher.dispatchAction을 주입받아 핸들러를 실행합니다.
   *
   * @param executor 액션 실행 함수
   */
  setActionExecutor(
    executor: (handler: ErrorHandlerConfig, errorContext: any) => Promise<any>
  ): void {
    this.executeAction = executor;
  }

  /**
   * 에러 코드에 맞는 핸들러를 결정합니다.
   *
   * @param errorCode HTTP 에러 코드
   * @param options 액션/데이터소스 레벨 설정
   * @returns 핸들러 결정 결과
   */
  resolve(
    errorCode: number,
    options: {
      errorHandling?: ErrorHandlingMap;
      onError?: OnErrorHandler;
    } = {}
  ): ErrorHandlingResult {
    const { errorHandling, onError } = options;

    logger.log('Resolving error:', errorCode, {
      hasActionErrorHandling: !!errorHandling,
      hasActionOnError: !!onError,
      hasLayoutConfig: !!this.layoutErrorHandling,
      hasTemplateConfig: !!this.templateErrorHandling,
    });

    // 1. 액션/데이터소스 errorHandling[코드] 확인
    let handler = ErrorHandlingUtils.findHandler(errorHandling, errorCode);
    if (handler) {
      const matchedKey = this.getMatchedKey(errorHandling!, errorCode);
      return {
        handler: ErrorHandlingUtils.injectErrorCode(handler, matchedKey, errorCode),
        level: 'action',
        matchedKey,
      };
    }

    // 2. 액션/데이터소스 onError 확인
    if (onError) {
      const normalizedOnError = ErrorHandlingUtils.normalizeOnError(onError);
      if (normalizedOnError.length > 0) {
        // onError가 배열인 경우 sequence로 래핑
        const sequenceHandler: ErrorHandlerConfig =
          normalizedOnError.length === 1
            ? normalizedOnError[0]
            : {
                handler: 'sequence',
                actions: normalizedOnError as any,
              };
        return {
          handler: sequenceHandler,
          level: 'action',
          matchedKey: 'onError',
        };
      }
    }

    // 3. 레이아웃 errorHandling 확인
    handler = ErrorHandlingUtils.findHandler(this.layoutErrorHandling || undefined, errorCode);
    if (handler) {
      const matchedKey = this.getMatchedKey(this.layoutErrorHandling!, errorCode);
      return {
        handler: ErrorHandlingUtils.injectErrorCode(handler, matchedKey, errorCode),
        level: 'layout',
        matchedKey,
      };
    }

    // 4. 템플릿 errorHandling 확인
    handler = ErrorHandlingUtils.findHandler(this.templateErrorHandling || undefined, errorCode);
    if (handler) {
      const matchedKey = this.getMatchedKey(this.templateErrorHandling!, errorCode);
      return {
        handler: ErrorHandlingUtils.injectErrorCode(handler, matchedKey, errorCode),
        level: 'template',
        matchedKey,
      };
    }

    // 5. 시스템 기본값
    handler = ErrorHandlingUtils.findHandler(DEFAULT_ERROR_HANDLING, errorCode);
    if (handler) {
      const matchedKey = this.getMatchedKey(DEFAULT_ERROR_HANDLING, errorCode);
      return {
        handler: ErrorHandlingUtils.injectErrorCode(handler, matchedKey, errorCode),
        level: 'system',
        matchedKey,
      };
    }

    // 핸들러를 찾지 못함
    return {
      handler: null,
      level: null,
      matchedKey: null,
    };
  }

  /**
   * 에러 핸들러를 실행합니다.
   *
   * @param handler 실행할 핸들러 설정
   * @param errorContext 에러 컨텍스트
   * @returns 실행 결과
   */
  async execute(
    handler: ErrorHandlerConfig,
    errorContext: ErrorContext
  ): Promise<any> {
    if (!this.executeAction) {
      logger.error('Action executor is not set');
      return;
    }

    logger.log('Executing handler:', handler.handler, {
      errorContext,
    });

    try {
      return await this.executeAction(handler, { error: errorContext });
    } catch (error) {
      logger.error('Failed to execute handler:', error);
      throw error;
    }
  }

  /**
   * 에러 코드에 맞는 핸들러를 찾아 실행합니다.
   *
   * resolve()와 execute()를 한 번에 수행합니다.
   *
   * @param errorCode HTTP 에러 코드
   * @param errorContext 에러 컨텍스트
   * @param options 액션/데이터소스 레벨 설정
   * @returns 실행 결과
   */
  async resolveAndExecute(
    errorCode: number,
    errorContext: ErrorContext,
    options: {
      errorHandling?: ErrorHandlingMap;
      onError?: OnErrorHandler;
    } = {}
  ): Promise<{ handled: boolean; result?: any }> {
    const result = this.resolve(errorCode, options);

    logger.log('Resolve result:', {
      errorCode,
      handler: result.handler?.handler,
      level: result.level,
      matchedKey: result.matchedKey,
    });

    if (!result.handler) {
      logger.warn('No handler found for error:', errorCode);
      return { handled: false };
    }

    try {
      const execResult = await this.execute(result.handler, errorContext);
      return { handled: true, result: execResult };
    } catch (error) {
      logger.error('Handler execution failed:', error);
      return { handled: false };
    }
  }

  /**
   * 에러 핸들링 맵에서 매칭된 키를 반환합니다.
   */
  private getMatchedKey(
    errorHandling: ErrorHandlingMap,
    errorCode: number
  ): ErrorCode {
    if (errorHandling[errorCode]) {
      return errorCode;
    }
    if (errorHandling[String(errorCode)]) {
      return String(errorCode);
    }
    return 'default';
  }

  /**
   * 현재 설정을 초기화합니다.
   *
   * 레이아웃 변경 시 호출하여 이전 설정을 제거합니다.
   */
  clearLayoutConfig(): void {
    this.layoutErrorHandling = null;
    logger.log('Layout config cleared');
  }

  /**
   * 모든 설정을 초기화합니다.
   *
   * 템플릿 변경 시 호출하여 모든 설정을 제거합니다.
   */
  clearAllConfig(): void {
    this.templateErrorHandling = null;
    this.layoutErrorHandling = null;
    logger.log('All config cleared');
  }

  /**
   * 현재 설정 상태를 반환합니다. (디버그용)
   */
  getConfigStatus(): {
    hasTemplate: boolean;
    hasLayout: boolean;
    hasExecutor: boolean;
  } {
    return {
      hasTemplate: this.templateErrorHandling !== null,
      hasLayout: this.layoutErrorHandling !== null,
      hasExecutor: this.executeAction !== null,
    };
  }
}

/**
 * ErrorHandlingResolver 싱글톤 인스턴스를 반환합니다.
 */
export function getErrorHandlingResolver(): ErrorHandlingResolver {
  return ErrorHandlingResolver.getInstance();
}
