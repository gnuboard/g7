/**
 * 에러 핸들링 모듈
 *
 * 계층적 에러 핸들링 시스템을 제공합니다.
 *
 * @module error
 */

export { ErrorHandlingResolver, getErrorHandlingResolver } from './ErrorHandlingResolver';
export type {
  ErrorCode,
  ErrorContext,
  ErrorHandlerConfig,
  ErrorHandlingLevel,
  ErrorHandlingMap,
  ErrorHandlingResult,
  ErrorHandlingSource,
  OnErrorHandler,
  ShowErrorPageParams,
} from '../types/ErrorHandling';
export { DEFAULT_ERROR_HANDLING, ErrorHandlingUtils } from '../types/ErrorHandling';
