/**
 * NOTE: @since versions (engine-v1.x.x) refer to internal template engine
 * development iterations, not G7 platform release versions.
 */
/**
 * 그누보드7 템플릿 엔진 - 데이터 소스 매니저
 *
 * data_sources를 처리하고 API 요청을 관리하는 매니저
 *
 * @package G7
 * @subpackage Template Engine
 * @since 1.0.0
 */

import { getApiClient } from '../api/ApiClient';
import { AuthManager } from '../auth/AuthManager';
import { resolveExpressionString, evaluateRenderCondition } from './helpers/RenderHelpers';
import { DataBindingEngine } from './DataBindingEngine';
import { getErrorHandlingResolver } from '../error';
import { getActionDispatcher } from './ActionDispatcher';
import type { ErrorHandlingMap, OnErrorHandler, OnSuccessHandler, ErrorContext, SuccessContext, ErrorCondition } from '../types/ErrorHandling';
import { createLogger } from '../utils/Logger';
import { webSocketManager } from '../websocket/WebSocketManager';
import type { G7DevToolsInterface } from './G7CoreGlobals';
import type { GlobalHeaderRule } from './LayoutLoader';

const logger = createLogger('DataSourceManager');

// DataBindingEngine 인스턴스 (표현식 평가용)
const bindingEngine = new DataBindingEngine();

/**
 * 파라미터 값을 해석합니다. 배열 등 원본 타입을 유지합니다.
 *
 * resolveExpressionString은 항상 문자열을 반환하므로,
 * 전체가 {{...}} 표현식인 경우 evaluateExpression을 사용하여
 * 배열, 객체 등 원본 타입을 유지합니다.
 *
 * @param value 파라미터 값 (문자열)
 * @param context 바인딩 컨텍스트
 * @returns 해석된 값 (원본 타입 유지)
 */
function resolveParamValue(value: string, context: Record<string, any>): any {
  // 전체가 하나의 {{...}} 표현식인지 확인
  // 예: "{{query['sales_status[]']}}" → true
  // 예: "page={{query.page}}" → false
  const singleExpressionMatch = value.match(/^\{\{(.+)\}\}$/);

  if (singleExpressionMatch) {
    // 단일 표현식인 경우 evaluateExpression으로 원본 타입 유지
    const expression = singleExpressionMatch[1].trim();
    try {
      return bindingEngine.evaluateExpression(expression, context);
    } catch (error) {
      logger.error('Failed to evaluate param expression:', expression, error);
      return value; // 실패 시 원본 반환
    }
  }

  // 복합 문자열인 경우 resolveExpressionString 사용 (문자열 보간)
  return resolveExpressionString(value, context, { skipCache: true });
}

/**
 * URLSearchParams를 배열 파라미터를 지원하는 객체로 변환
 *
 * URLSearchParams.entries()와 Object.fromEntries()를 사용하면
 * 중복 키(배열 파라미터)의 마지막 값만 남게 됩니다.
 * 이 함수는 getAll()을 사용하여 모든 값을 배열로 수집합니다.
 *
 * @param params URLSearchParams 인스턴스
 * @returns 배열 파라미터를 지원하는 객체
 *
 * @example
 * // sales_status[]=a&sales_status[]=b → { 'sales_status[]': ['a', 'b'] }
 * // page=1&sort=name → { page: '1', sort: 'name' }
 */
function parseQueryParams(params: URLSearchParams): Record<string, string | string[]> {
  const result: Record<string, string | string[]> = {};

  for (const key of params.keys()) {
    // 이미 처리한 키는 건너뛰기
    if (key in result) continue;

    const values = params.getAll(key);
    if (values.length > 1) {
      // 여러 값이 있으면 배열로 저장
      result[key] = values;
    } else if (values.length === 1) {
      // 단일 값이지만 key[]  형태면 배열로 저장 (일관성)
      if (key.endsWith('[]')) {
        result[key] = values;
      } else {
        result[key] = values[0];
      }
    }
  }

  return result;
}

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
 * 데이터 소스 타입
 */
export type DataSourceType = 'api' | 'static' | 'route_params' | 'query_params' | 'websocket';

/**
 * WebSocket 채널 타입
 */
export type WebSocketChannelType = 'public' | 'private' | 'presence';

/**
 * HTTP 메서드 타입
 */
export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

/**
 * 로딩 전략 타입
 *
 * - blocking: API 응답 완료 전까지 렌더링하지 않음
 * - progressive: 스켈레톤 UI 렌더링 후 데이터 로드되면 업데이트 (기본값)
 * - background: 일단 렌더링하고 백그라운드에서 fetch
 */
export type LoadingStrategy = 'blocking' | 'progressive' | 'background';

/**
 * 데이터 소스 로딩 상태
 */
export type DataSourceLoadingState = 'idle' | 'loading' | 'success' | 'error';

/**
 * 데이터 소스 인터페이스
 */
export interface DataSource {
  /**
   * 데이터 소스 고유 ID
   */
  id: string;

  /**
   * 데이터 소스 타입
   */
  type: DataSourceType;

  /**
   * API 엔드포인트 (type이 'api'인 경우)
   */
  endpoint?: string;

  /**
   * HTTP 메서드 (type이 'api'인 경우)
   */
  method?: HttpMethod;

  /**
   * 자동 fetch 여부
   */
  auto_fetch?: boolean;

  /**
   * 인증 필요 여부 (deprecated: auth_mode 사용 권장)
   *
   * @deprecated auth_mode를 사용하세요. auth_required: true는 auth_mode: 'required'와 동일합니다.
   */
  auth_required?: boolean;

  /**
   * 인증 모드
   *
   * - 'required': 토큰 필수 (ApiClient 사용, 토큰 없으면 요청 실패)
   * - 'optional': 토큰 있으면 전송, 없으면 일반 요청 (비회원/회원 공용 API)
   * - 'none': 토큰 안 보냄 (공개 API, 기본값)
   *
   * auth_required: true는 auth_mode: 'required'와 동일하게 동작합니다.
   */
  auth_mode?: 'required' | 'optional' | 'none';

  /**
   * 로딩 전략 (기본값: progressive)
   */
  loading_strategy?: LoadingStrategy;

  /**
   * 요청 Content-Type (기본값: 'application/json')
   *
   * 'multipart/form-data'로 설정하면 파일 업로드가 가능합니다.
   * File/Blob 객체를 params에 포함하여 FormData로 전송합니다.
   *
   * @example
   * { "id": "upload", "endpoint": "/api/upload", "method": "POST",
   *   "contentType": "multipart/form-data", "params": { "file": "{{_global.uploadFile}}" } }
   */
  contentType?: string;

  /**
   * 요청 파라미터
   */
  params?: Record<string, any>;

  /**
   * 정적 데이터 (type이 'static'인 경우)
   */
  data?: any;

  /**
   * 로컬 상태 초기화 설정
   *
   * API 응답 데이터를 _local[key]에 자동 복사합니다.
   * 폼 편집 시 초기 데이터를 로컬 상태로 관리할 때 사용합니다.
   *
   * 문자열 형태: 전체 응답 데이터를 저장
   * 객체 형태: path를 지정하여 응답의 특정 필드만 저장
   *
   * @example
   * // 문자열 형태 - 전체 응답 저장
   * { "id": "user", "endpoint": "/api/users/1", "initLocal": "form" }
   * // → response.data가 _local.form에 복사됨
   *
   * @example
   * // 객체 형태 - 특정 필드만 저장
   * { "id": "user", "endpoint": "/api/users/1", "initLocal": { "key": "form", "path": "data" } }
   * // → response.data.data가 _local.form에 복사됨
   *
   * @example
   * // 맵 형태 (engine-v1.7.0+) - 여러 필드를 각각 저장 (표현식 지원)
   * { "id": "cart", "endpoint": "/api/cart", "initLocal": { "selectedItems": "data.item_ids" } }
   * // → response.data.data.item_ids가 _local.selectedItems에 복사됨
   *
   * @example
   * // 맵 형태 + 표현식 (engine-v1.7.0+)
   * { "id": "cart", "initLocal": { "selectedItems": "{{data.items.map(i => i.id)}}" } }
   * // → response.data.data.items를 map하여 _local.selectedItems에 저장
   *
   * @example
   * // dot notation 타겟 경로 (engine-v1.8.0+)
   * { "id": "checkout", "initLocal": { "checkout.item_coupons": "data.promotions.item_coupons" } }
   * // → response.data.data.promotions.item_coupons가 _local.checkout.item_coupons에 복사됨
   *
   * @example
   * // 중첩 객체 표기법 (engine-v1.8.0+)
   * { "id": "checkout", "initLocal": {
   *     "checkout": {
   *       "item_coupons": "data.promotions.item_coupons",
   *       "use_points": "data.use_points"
   *     }
   *   }
   * }
   *
   * @example
   * // 병합 전략 옵션 (engine-v1.8.0+) - _merge: "deep" | "shallow" | "replace"
   * { "id": "checkout", "initLocal": {
   *     "_merge": "replace",
   *     "checkout.item_coupons": "data.promotions.item_coupons"
   *   }
   * }
   */
  initLocal?: string | { key: string; path: string } | Record<string, string | Record<string, string>>;

  /**
   * 전역 상태 초기화 설정
   *
   * API 응답 데이터를 _global[key]에 자동 복사합니다.
   * 여러 페이지에서 공유하는 데이터(현재 사용자, 앱 설정 등)에 사용합니다.
   *
   * 문자열 형태: 전체 응답 데이터를 저장
   * 객체 형태: path를 지정하여 응답의 특정 필드만 저장
   *
   * @example
   * // 문자열 형태 - 전체 응답 저장
   * { "id": "currentUser", "endpoint": "/api/me", "initGlobal": "currentUser" }
   * // → response.data가 _global.currentUser에 복사됨
   *
   * @example
   * // 객체 형태 - 특정 필드만 저장
   * { "id": "modules", "endpoint": "/api/modules", "initGlobal": { "key": "installedModules", "path": "data" } }
   * // → response.data.data가 _global.installedModules에 복사됨
   *
   * @example
   * // 배열 형태 - 여러 전역 상태 동시 초기화
   * { "id": "layout_files", "endpoint": "/api/layouts", "initGlobal": [
   *   "layoutFilesList",
   *   { "key": "selectedLayoutName", "path": "[0].name" }
   * ] }
   * // → response.data가 _global.layoutFilesList에 복사됨
   * // → response.data[0].name이 _global.selectedLayoutName에 복사됨
   *
   * @example
   * // 맵 형태 (engine-v1.7.0+) - 여러 필드를 각각 저장 (표현식 지원)
   * { "id": "config", "endpoint": "/api/config", "initGlobal": { "theme": "data.theme", "locale": "data.locale" } }
   * // → response.data.data.theme이 _global.theme에, response.data.data.locale이 _global.locale에 복사됨
   */
  initGlobal?: string | { key: string; path: string } | Array<string | { key: string; path: string }> | Record<string, string>;

  /**
   * 격리된 상태 초기화 설정
   *
   * API 응답 데이터를 _isolated[key]에 자동 복사합니다.
   * isolatedState 속성이 정의된 컴포넌트 내에서만 사용됩니다.
   * 해당 영역 내에서만 유효한 독립적인 상태가 필요할 때 사용합니다.
   *
   * 문자열 형태: 전체 응답 데이터를 저장
   * 객체 형태: { key: 저장할 키, path: 응답에서 추출할 경로 }
   *
   * @example
   * // 문자열 형태 - 전체 응답 저장
   * { "id": "categories", "endpoint": "/api/categories", "initIsolated": "categoryList" }
   * // → response.data가 _isolated.categoryList에 복사됨
   *
   * @example
   * // 객체 형태 - 특정 필드만 저장
   * { "id": "options", "endpoint": "/api/options", "initIsolated": { "key": "items", "path": "data.list" } }
   * // → response.data.data.list가 _isolated.items에 복사됨
   *
   * @example
   * // 맵 형태 (engine-v1.7.0+) - 여러 필드를 각각 저장 (표현식 지원)
   * { "id": "widget", "endpoint": "/api/widget", "initIsolated": { "items": "data.list", "total": "data.count" } }
   * // → response.data.data.list가 _isolated.items에, data.count가 _isolated.total에 복사됨
   */
  initIsolated?: string | { key: string; path: string } | Record<string, string>;

  /**
   * 마운트 시 다시 fetch 여부
   *
   * true로 설정하면 페이지에 진입할 때마다 데이터를 다시 fetch하고
   * initLocal/initGlobal 옵션이 있는 경우 로컬/전역 상태를 강제로 재초기화합니다.
   *
   * SPA에서 같은 페이지로 다시 이동할 때 (예: 목록 → 편집 → 목록 → 편집)
   * 이전에 수정한 폼 데이터가 남아있는 문제를 해결합니다.
   *
   * @example
   * // data_sources에서 사용
   * { "id": "board", "endpoint": "/api/boards/{{route.id}}", "initLocal": "formData", "refetchOnMount": true }
   * // → 페이지 진입 시 항상 board 데이터를 다시 fetch하고 _local.formData를 초기화
   *
   * @default false
   */
  refetchOnMount?: boolean;

  /**
   * 커스텀 HTTP 헤더
   *
   * API 요청 시 추가할 커스텀 헤더를 정의합니다.
   * 값에 표현식({{...}})을 사용하여 동적으로 헤더 값을 설정할 수 있습니다.
   *
   * 지원되는 컨텍스트:
   * - route: 라우트 파라미터 (예: {{route.id}})
   * - query: 쿼리 파라미터 (예: {{query.mode}})
   * - _global: 전역 상태 (예: {{_global.cartKey}})
   *
   * 주의:
   * - _local은 렌더링 전에 data_source가 처리되므로 사용할 수 없습니다.
   * - 값이 null, undefined, 빈 문자열인 헤더는 전송되지 않습니다.
   *
   * @example
   * // 장바구니 키 헤더 전송
   * {
   *   "id": "cart",
   *   "endpoint": "/api/cart",
   *   "headers": {
   *     "X-Cart-Key": "{{_global.cartKey}}"
   *   }
   * }
   *
   * @example
   * // 여러 커스텀 헤더
   * {
   *   "id": "data",
   *   "endpoint": "/api/data",
   *   "headers": {
   *     "X-Custom-Header": "custom-value",
   *     "X-User-Id": "{{_global.currentUser?.uuid}}"
   *   }
   * }
   */
  headers?: Record<string, string>;

  /**
   * 조건부 로딩 표현식
   *
   * 표현식이 truthy로 평가될 때만 이 데이터 소스를 fetch합니다.
   * 같은 id를 가진 여러 데이터 소스를 정의하고, 조건에 따라 하나만 실행되도록 할 수 있습니다.
   *
   * 지원되는 컨텍스트:
   * - route: 라우트 파라미터 (예: {{route.id}})
   * - query: 쿼리 파라미터 (예: {{query.mode}})
   * - _global: 전역 상태 (예: {{_global.isAdmin}})
   *
   * 주의: _local은 렌더링 전에 data_source가 처리되므로 사용할 수 없습니다.
   *
   * @example
   * // 수정 모드: route.id가 있을 때만 기존 데이터 로드
   * { "id": "user", "endpoint": "/api/users/{{route.id}}", "if": "{{route.id}}" }
   *
   * // 생성 모드: route.id가 없을 때만 템플릿 로드
   * { "id": "user", "endpoint": "/api/users/template", "if": "{{!route.id}}" }
   */
  if?: string;

  /**
   * 조건부 데이터 소스 로드 조건 (복합 조건)
   *
   * if 속성의 상위 호환으로 AND/OR 그룹 및 if-else 체인을 지원합니다.
   *
   * 주의:
   * - if와 conditions가 둘 다 있으면 if가 우선 평가됩니다
   * - _local은 렌더링 전에 data_source가 처리되므로 사용할 수 없습니다
   *
   * @example
   * // AND 그룹: route.id가 있고 권한이 있을 때만 로드
   * {
   *   "id": "product",
   *   "endpoint": "/api/products/{{route.id}}",
   *   "conditions": {
   *     "and": ["{{!!route.id}}", "{{_global.hasPermission('view_product')}}"]
   *   }
   * }
   *
   * @example
   * // OR 그룹: admin이거나 manager일 때 로드
   * {
   *   "id": "dashboard",
   *   "endpoint": "/api/admin/dashboard",
   *   "conditions": {
   *     "or": ["{{_global.user?.role === 'admin'}}", "{{_global.user?.role === 'manager'}}"]
   *   }
   * }
   *
   * @since engine-v1.10.0
   */
  conditions?: import('./helpers/ConditionEvaluator').ConditionsProperty;

  /**
   * WebSocket 채널명 (type이 'websocket'인 경우)
   *
   * @example "admin.dashboard"
   */
  channel?: string;

  /**
   * WebSocket 이벤트명 (type이 'websocket'인 경우)
   *
   * @example "dashboard.stats.updated"
   */
  event?: string;

  /**
   * WebSocket 채널 타입 (기본값: 'private')
   */
  channel_type?: WebSocketChannelType;

  /**
   * WebSocket 수신 시 업데이트할 대상 데이터 소스 ID
   *
   * 이 필드가 설정되면 WebSocket 이벤트 수신 시
   * 해당 ID의 데이터 소스 캐시를 업데이트합니다.
   *
   * @example "dashboard_stats"
   */
  target_source?: string;

  /**
   * API 호출 실패 시 사용할 기본 데이터
   *
   * API 요청이 실패하거나 endpoint가 존재하지 않을 때
   * 이 값이 데이터로 사용됩니다.
   * errorHandling이 함께 정의된 경우 에러 핸들러가 먼저 실행된 후
   * fallback 데이터가 렌더링에 사용됩니다.
   *
   * @example
   * ```json
   * // 객체 fallback
   * { "fallback": { "users": 0, "posts": 0 } }
   *
   * // 배열 fallback
   * { "fallback": [] }
   *
   * // 단순 값 fallback
   * { "fallback": 0 }
   * ```
   */
  fallback?: any;

  /**
   * HTTP 에러 코드별 핸들러 매핑
   *
   * 이 데이터 소스의 API 호출 실패 시 에러 코드에 따라 다른 핸들러를 실행합니다.
   * onError보다 우선순위가 높습니다.
   *
   * @example
   * ```json
   * {
   *   "errorHandling": {
   *     "403": { "handler": "showErrorPage", "params": { "target": "content" } },
   *     "404": { "handler": "toast", "params": { "type": "warning", "message": "$t:errors.not_found" } },
   *     "default": { "handler": "toast", "params": { "type": "error", "message": "{{error.message}}" } }
   *   }
   * }
   * ```
   */
  errorHandling?: ErrorHandlingMap;

  /**
   * 응답 조건부 에러 처리
   *
   * API가 200으로 응답하더라도 응답 데이터 기반으로 에러를 트리거합니다.
   * 조건이 truthy로 평가되면 errorHandling에 정의된 핸들러가 실행됩니다.
   *
   * 표현식에서 `response` 객체로 API 응답 데이터에 접근합니다.
   *
   * @since engine-v1.18.0
   *
   * @example
   * ```json
   * {
   *   "errorCondition": {
   *     "if": "{{response?.data?.abilities?.can_update !== true}}",
   *     "errorCode": 403
   *   },
   *   "errorHandling": {
   *     "403": { "handler": "showErrorPage", "params": { "target": "content" } }
   *   }
   * }
   * ```
   */
  errorCondition?: ErrorCondition;

  /**
   * 범용 에러 핸들러 (폴백)
   *
   * errorHandling에 해당 에러 코드가 정의되지 않은 경우 실행됩니다.
   * 단일 핸들러 또는 핸들러 배열을 지정할 수 있습니다.
   *
   * @example
   * ```json
   * // 단일 핸들러
   * { "onError": { "handler": "toast", "params": { "type": "error", "message": "{{error.message}}" } } }
   *
   * // 핸들러 배열 (순차 실행)
   * { "onError": [
   *   { "handler": "setState", "params": { "target": "global", "errorMessage": "{{error.message}}" } },
   *   { "handler": "openModal", "target": "error_modal" }
   * ] }
   * ```
   */
  onError?: OnErrorHandler;

  /**
   * 데이터 로드 성공 시 실행할 핸들러
   *
   * API 요청이 성공하고 데이터가 캐시에 저장된 후 실행됩니다.
   * params에서 {{response.xxx}} 형태로 API 응답에 접근할 수 있습니다.
   *
   * 주요 사용 사례:
   * - 데이터 로드 후 조건부 모달 표시
   * - 데이터 기반 추가 상태 설정
   * - 로드 완료 후 UI 업데이트
   *
   * @since engine-v1.17.0
   *
   * @example
   * ```json
   * // 단일 핸들러 - 조건부 모달 표시
   * {
   *   "onSuccess": {
   *     "handler": "conditions",
   *     "conditions": [
   *       {
   *         "if": "{{response.data.unavailable_items?.length > 0}}",
   *         "handler": "openModal",
   *         "target": "unavailable_modal"
   *       }
   *     ]
   *   }
   * }
   *
   * // 핸들러 배열 (순차 실행)
   * { "onSuccess": [
   *   { "handler": "setState", "params": { "target": "local", "loaded": true } },
   *   { "handler": "toast", "params": { "type": "success", "message": "Data loaded" } }
   * ] }
   *
   * // sequence로 여러 작업 수행
   * {
   *   "onSuccess": {
   *     "handler": "sequence",
   *     "actions": [
   *       { "handler": "setState", "params": { "target": "local", "resultData": "{{response.data}}" } },
   *       { "handler": "apiCall", "params": { "endpoint": "/api/log", "method": "POST" } }
   *     ]
   *   }
   * }
   * ```
   */
  onSuccess?: OnSuccessHandler;
}

/**
 * 데이터 소스 결과 인터페이스
 */
export interface DataSourceResult {
  /**
   * 데이터 소스 ID
   */
  id: string;

  /**
   * 로딩 상태
   */
  state: DataSourceLoadingState;

  /**
   * 데이터 (성공 시)
   */
  data?: any;

  /**
   * 에러 (실패 시)
   */
  error?: Error;

  /**
   * HTTP 에러 코드 (실패 시)
   */
  errorCode?: number;

  /**
   * 에러가 핸들러에 의해 처리되었는지 여부
   */
  errorHandled?: boolean;
}

/**
 * 데이터 소스 매니저 옵션
 */
export interface DataSourceManagerOptions {
  /**
   * 401 응답 시 호출될 콜백
   */
  onUnauthorized?: () => void;

  /**
   * 에러 발생 시 호출될 콜백
   */
  onError?: (error: Error, source: DataSource) => void;
}

/**
 * 조건 평가용 컨텍스트 인터페이스
 */
export interface ConditionContext {
  /**
   * 라우트 파라미터
   */
  route?: Record<string, string>;

  /**
   * 쿼리 파라미터
   */
  query?: Record<string, string>;

  /**
   * 전역 상태
   */
  _global?: Record<string, any>;
}

/**
 * 데이터 캐시 엔트리 인터페이스
 */
interface DataCacheEntry {
  /**
   * 캐시된 데이터
   */
  data: any;

  /**
   * 캐시 생성 시간
   */
  timestamp: number;
}

/**
 * 데이터 소스 매니저 클래스
 *
 * data_sources를 처리하고 API 요청을 관리
 */
export class DataSourceManager {
  /**
   * 데이터 캐시 만료 시간 (밀리초)
   *
   * 5분으로 설정하여 메모리 누수를 방지합니다.
   */
  private static readonly DATA_CACHE_TTL = 300000; // 5분

  private options: DataSourceManagerOptions;
  private dataCache: Map<string, DataCacheEntry> = new Map();
  private bindingEngine: DataBindingEngine;

  /**
   * 전역 헤더 규칙 (레이아웃에서 설정)
   *
   * API 호출 시 패턴에 매칭되는 엔드포인트에 자동으로 헤더를 추가합니다.
   *
   * @since engine-v1.16.0
   */
  private globalHeaders: GlobalHeaderRule[] = [];

  constructor(options: DataSourceManagerOptions = {}) {
    this.options = options;
    this.bindingEngine = new DataBindingEngine();
  }

  /**
   * 전역 헤더 규칙을 설정합니다.
   *
   * 레이아웃 로드 시 호출되어 globalHeaders를 설정합니다.
   * 이후 모든 API 호출에서 패턴 매칭을 통해 자동으로 헤더가 적용됩니다.
   *
   * @param headers 전역 헤더 규칙 배열
   * @since engine-v1.16.0
   */
  public setGlobalHeaders(headers: GlobalHeaderRule[]): void {
    this.globalHeaders = headers || [];
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
  /**
   * params 객체를 FormData로 변환합니다.
   * File/Blob은 그대로 append, 기타 값은 문자열 변환하여 append합니다.
   */
  private toFormData(params: Record<string, any>): FormData {
    const formData = new FormData();
    for (const [key, value] of Object.entries(params)) {
      if (value instanceof File || value instanceof Blob) {
        formData.append(key, value);
      } else if (value !== null && value !== undefined) {
        formData.append(
          key,
          typeof value === 'object' ? JSON.stringify(value) : String(value)
        );
      }
    }
    return formData;
  }

  private getMatchingGlobalHeaders(
    endpoint: string,
    context: Record<string, any>,
  ): Record<string, string> {
    const result: Record<string, string> = {};

    for (const rule of this.globalHeaders) {
      if (this.matchesPattern(endpoint, rule.pattern)) {
        Object.entries(rule.headers).forEach(([key, value]) => {
          // 표현식 평가 ({{_global.xxx}} 등)
          const resolved = resolveParamValue(value, context);
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
   * 조건부 데이터 소스 필터링
   *
   * 같은 id를 가진 데이터 소스 중 조건을 만족하는 첫 번째 것만 선택합니다.
   * - if 속성이 없는 데이터 소스는 항상 선택됩니다.
   * - if 속성이 있는 데이터 소스는 조건이 truthy일 때만 선택됩니다.
   * - 같은 id가 여러 개인 경우, 조건을 만족하는 첫 번째 것만 선택됩니다.
   *
   * @param sources 데이터 소스 배열
   * @param conditionContext 조건 평가용 컨텍스트 (route, query, _global)
   * @returns 필터링된 데이터 소스 배열
   */
  filterByCondition(
    sources: DataSource[],
    conditionContext: ConditionContext = {}
  ): DataSource[] {
    // 이미 선택된 id를 추적
    const selectedIds = new Set<string>();
    const filteredSources: DataSource[] = [];

    for (const source of sources) {
      // 이미 같은 id가 선택되었으면 스킵
      if (selectedIds.has(source.id)) {
        continue;
      }

      // if와 conditions가 모두 없으면 항상 선택
      if (!source.if && !source.conditions) {
        selectedIds.add(source.id);
        filteredSources.push(source);
        continue;
      }

      // 조건 평가 (evaluateRenderCondition은 if와 conditions 모두 지원)
      const shouldInclude = evaluateRenderCondition(
        { if: source.if, conditions: source.conditions },
        conditionContext,
        this.bindingEngine,
        `data_source:${source.id}`
      );

      if (shouldInclude) {
        selectedIds.add(source.id);
        filteredSources.push(source);
      }
    }

    return filteredSources;
  }

  /**
   * errorCondition을 평가하여 응답 데이터 기반 에러 조건을 체크합니다.
   *
   * @param source 데이터 소스
   * @param responseData API 응답 데이터
   * @returns errorCondition이 truthy면 해당 errorCode, 아니면 null
   */
  private checkErrorCondition(source: DataSource, responseData: any): number | null {
    if (!source.errorCondition?.if || source.errorCondition?.errorCode === undefined) {
      return null;
    }

    try {
      const context = { response: responseData };
      const result = evaluateRenderCondition(
        { if: source.errorCondition.if },
        context,
        this.bindingEngine,
        `errorCondition:${source.id}`
      );

      if (result) {
        logger.log(`errorCondition matched for ${source.id}, triggering error ${source.errorCondition.errorCode}`);
        return source.errorCondition.errorCode;
      }
    } catch (error) {
      logger.error(`Failed to evaluate errorCondition for ${source.id}:`, error);
    }

    return null;
  }

  /**
   * 데이터 소스 fetch (blocking + progressive 전용)
   *
   * @param sources 데이터 소스 배열
   * @param routeParams 라우트 파라미터
   * @param queryParams 쿼리 파라미터
   * @param globalState _global 상태 (globalHeaders 표현식 평가용)
   * @param localState _local 상태 (globalHeaders 표현식 평가용)
   * @returns 데이터 맵 (key: 데이터 소스 ID, value: 데이터)
   */
  async fetchDataSources(
    sources: DataSource[],
    routeParams: Record<string, string> = {},
    queryParams: URLSearchParams = new URLSearchParams(),
    globalState?: Record<string, any>,
    localState?: Record<string, any>,
  ): Promise<Record<string, any>> {
    const results: Record<string, any> = {};

    // auto_fetch가 true인 데이터 소스만 처리 (websocket 타입 제외)
    const autoFetchSources = sources.filter(
      (source) => source.auto_fetch !== false && source.type !== 'websocket'
    );

    // 병렬 처리
    await Promise.all(
      autoFetchSources.map(async (source) => {
        try {
          const data = await this.fetchDataSource(source, routeParams, queryParams, globalState, localState);
          results[source.id] = data;
          this.dataCache.set(source.id, { data, timestamp: Date.now() });

          // errorCondition 체크: 200 응답이어도 조건 만족 시 에러로 처리
          const errorConditionCode = this.checkErrorCondition(source, data);
          if (errorConditionCode !== null) {
            const errorContext: ErrorContext = {
              status: errorConditionCode,
              message: `Error condition matched for ${source.id}`,
              data: data,
            };

            const resolver = getErrorHandlingResolver();
            const resolveResult = resolver.resolve(errorConditionCode, {
              errorHandling: source.errorHandling,
              onError: source.onError,
            });

            if (resolveResult.handler) {
              try {
                await resolver.execute(resolveResult.handler, errorContext);
              } catch (handlerError) {
                logger.error(`Error executing errorCondition handler for ${source.id}:`, handlerError);
              }
            }

            // fallback이 정의된 경우 렌더링용 데이터로 사용
            if (source.fallback !== undefined) {
              results[source.id] = source.fallback;
              this.dataCache.set(source.id, { data: source.fallback, timestamp: Date.now() });
              logger.log(`Using fallback data for ${source.id} (errorCondition)`);
            }

            return; // onSuccess 스킵
          }

          // onSuccess 핸들러 실행
          if (source.onSuccess) {
            try {
              const successContext: SuccessContext = {
                data,
                sourceId: source.id,
              };
              await this.executeOnSuccessHandler(source.onSuccess, successContext);
            } catch (handlerError) {
              logger.error(`Error executing onSuccess handler for ${source.id}:`, handlerError);
            }
          }
        } catch (error) {
          logger.error(`Failed to fetch data source: ${source.id}`, error);

          // HTTP 에러 코드 추출
          const errorCode = (error as any)?.response?.status ||
                           (error as any)?.status ||
                           500;

          // 에러 컨텍스트 생성
          // API 응답의 message를 우선 사용하고, 없으면 Axios 에러 메시지 사용
          const responseData = (error as any)?.response?.data;
          const errorMessage = responseData?.message || (error as Error).message || 'Unknown error';
          const errorContext: ErrorContext = {
            status: errorCode,
            message: errorMessage,
            errors: responseData?.errors,
            data: responseData,
            statusText: (error as any)?.response?.statusText,
          };

          // ErrorHandlingResolver를 통해 핸들러 결정 및 실행
          const resolver = getErrorHandlingResolver();
          const resolveResult = resolver.resolve(errorCode, {
            errorHandling: source.errorHandling,
            onError: source.onError,
          });

          if (resolveResult.handler) {
            try {
              await resolver.execute(resolveResult.handler, errorContext);
            } catch (handlerError) {
              logger.error(`Error executing error handler for ${source.id}:`, handlerError);
            }
          }

          // fallback이 정의된 경우 렌더링용 데이터로 사용
          if (source.fallback !== undefined) {
            results[source.id] = source.fallback;
            this.dataCache.set(source.id, { data: source.fallback, timestamp: Date.now() });
            logger.log(`Using fallback data for ${source.id}`);
          }

          // 기존 onError 콜백도 호출 (호환성 유지)
          if (this.options.onError) {
            this.options.onError(error as Error, source);
          }
        }
      }),
    );

    return results;
  }

  /**
   * 데이터 소스 fetch (with result tracking)
   *
   * @param sources 데이터 소스 배열
   * @param routeParams 라우트 파라미터
   * @param queryParams 쿼리 파라미터
   * @param globalState _global 상태 (선택적, refetchDataSource 시 endpoint 표현식에서 _global 접근 지원)
   * @param localState _local 상태 (선택적, refetchDataSource 시 params 표현식에서 _local 접근 지원)
   * @returns 데이터 소스 결과 배열
   * @param options fetch 옵션
   *   - ignoreAutoFetch: true면 auto_fetch: false인 소스도 강제 fetch (refetchDataSource 용)
   */
  async fetchDataSourcesWithResults(
    sources: DataSource[],
    routeParams: Record<string, string> = {},
    queryParams: URLSearchParams = new URLSearchParams(),
    globalState?: Record<string, any>,
    localState?: Record<string, any>,
    options?: { ignoreAutoFetch?: boolean },
  ): Promise<DataSourceResult[]> {
    // ignoreAutoFetch 옵션이 true면 auto_fetch 필터 무시 (refetchDataSource에서 사용)
    // 그렇지 않으면 auto_fetch가 true인 데이터 소스만 처리 (websocket 타입 제외)
    const targetSources = options?.ignoreAutoFetch
      ? sources.filter((source) => source.type !== 'websocket')
      : sources.filter(
          (source) => source.auto_fetch !== false && source.type !== 'websocket'
        );

    // 병렬 처리 (각 결과를 추적)
    const results = await Promise.all(
      targetSources.map(async (source): Promise<DataSourceResult> => {
        try {
          const data = await this.fetchDataSource(source, routeParams, queryParams, globalState, localState);
          this.dataCache.set(source.id, { data, timestamp: Date.now() });

          // errorCondition 체크: 200 응답이어도 조건 만족 시 에러로 처리
          const errorConditionCode = this.checkErrorCondition(source, data);
          if (errorConditionCode !== null) {
            const errorContext: ErrorContext = {
              status: errorConditionCode,
              message: `Error condition matched for ${source.id}`,
              data: data,
            };

            const resolver = getErrorHandlingResolver();
            const resolveResult = resolver.resolve(errorConditionCode, {
              errorHandling: source.errorHandling,
              onError: source.onError,
            });

            let errorHandled = false;
            if (resolveResult.handler) {
              // blocking 데이터소스의 경우:
              // 핸들러를 await하면 데드락 발생 (showErrorPage가 #main_content를 대기하지만,
              // blocking이 끝나야 #main_content가 마운트됨)
              // → 핸들러를 비동기로 실행하고 즉시 blocking 해제
              if (source.loading_strategy === 'blocking') {
                resolver.execute(resolveResult.handler, errorContext).catch((handlerError) => {
                  logger.error(`Error executing errorCondition handler for ${source.id}:`, handlerError);
                });
                errorHandled = true;
              } else {
                try {
                  await resolver.execute(resolveResult.handler, errorContext);
                  errorHandled = true;
                } catch (handlerError) {
                  logger.error(`Error executing errorCondition handler for ${source.id}:`, handlerError);
                }
              }
            }

            // fallback이 정의된 경우 렌더링용 데이터로 사용
            if (source.fallback !== undefined) {
              this.dataCache.set(source.id, { data: source.fallback, timestamp: Date.now() });
              logger.log(`Using fallback data for ${source.id} (errorCondition)`);
              return {
                id: source.id,
                state: 'success',
                data: source.fallback,
              };
            }

            return {
              id: source.id,
              state: 'error',
              error: new Error(`Error condition matched: ${errorConditionCode}`),
              errorCode: errorConditionCode,
              errorHandled,
            };
          }

          // onSuccess 핸들러 실행
          if (source.onSuccess) {
            try {
              const successContext: SuccessContext = {
                data,
                sourceId: source.id,
              };
              await this.executeOnSuccessHandler(source.onSuccess, successContext);
            } catch (handlerError) {
              logger.error(`Error executing onSuccess handler for ${source.id}:`, handlerError);
            }
          }

          return {
            id: source.id,
            state: 'success',
            data,
          };
        } catch (error) {
          logger.error(`Failed to fetch data source: ${source.id}`, error);

          // HTTP 에러 코드 추출
          const errorCode = (error as any)?.response?.status ||
                           (error as any)?.status ||
                           500;

          // 에러 컨텍스트 생성
          // API 응답의 message를 우선 사용하고, 없으면 Axios 에러 메시지 사용
          const responseData = (error as any)?.response?.data;
          const errorMessage = responseData?.message || (error as Error).message || 'Unknown error';
          const errorContext: ErrorContext = {
            status: errorCode,
            message: errorMessage,
            errors: responseData?.errors,
            data: responseData,
            statusText: (error as any)?.response?.statusText,
          };

          // ErrorHandlingResolver를 통해 핸들러 결정
          const resolver = getErrorHandlingResolver();
          const resolveResult = resolver.resolve(errorCode, {
            errorHandling: source.errorHandling,
            onError: source.onError,
          });

          let errorHandled = false;

          if (resolveResult.handler) {
            // blocking 데이터소스의 경우:
            // 핸들러를 await하면 데드락 발생 (showErrorPage가 #main_content를 대기하지만,
            // blocking이 끝나야 #main_content가 마운트됨)
            // → 핸들러를 비동기로 실행하고 즉시 blocking 해제
            if (source.loading_strategy === 'blocking') {
              resolver.execute(resolveResult.handler, errorContext).catch((handlerError) => {
                logger.error(`Error executing error handler for ${source.id}:`, handlerError);
              });
              errorHandled = true;
            } else {
              try {
                // 핸들러 실행
                await resolver.execute(resolveResult.handler, errorContext);
                errorHandled = true;
              } catch (handlerError) {
                logger.error(`Error executing error handler for ${source.id}:`, handlerError);
              }
            }
          }

          // fallback이 정의된 경우 렌더링용 데이터로 사용
          if (source.fallback !== undefined) {
            this.dataCache.set(source.id, { data: source.fallback, timestamp: Date.now() });
            logger.log(`Using fallback data for ${source.id}`);
            return {
              id: source.id,
              state: 'success',
              data: source.fallback,
            };
          }

          // 기존 onError 콜백도 호출 (호환성 유지)
          if (this.options.onError) {
            this.options.onError(error as Error, source);
          }

          return {
            id: source.id,
            state: 'error',
            error: error as Error,
            errorCode,
            errorHandled,
          };
        }
      }),
    );

    return results;
  }

  /**
   * 백그라운드에서 데이터 소스 fetch (비동기, 결과 반환 안 함)
   *
   * @param sources 데이터 소스 배열
   * @param routeParams 라우트 파라미터
   * @param queryParams 쿼리 파라미터
   * @param onUpdate 데이터 업데이트 콜백
   */
  fetchDataSourcesInBackground(
    sources: DataSource[],
    routeParams: Record<string, string> = {},
    queryParams: URLSearchParams = new URLSearchParams(),
    onUpdate?: (sourceId: string, data: any) => void,
  ): void {
    // auto_fetch가 true이고 loading_strategy가 background인 소스만 처리 (websocket 타입 제외)
    const backgroundSources = sources.filter(
      (source) => source.auto_fetch !== false && source.loading_strategy === 'background' && source.type !== 'websocket'
    );

    // 각 소스를 백그라운드에서 fetch (await 없이)
    backgroundSources.forEach((source) => {
      this.fetchDataSource(source, routeParams, queryParams)
        .then(async (data) => {
          this.dataCache.set(source.id, { data, timestamp: Date.now() });

          // errorCondition 체크: 200 응답이어도 조건 만족 시 에러로 처리
          const errorConditionCode = this.checkErrorCondition(source, data);
          if (errorConditionCode !== null) {
            const errorContext: ErrorContext = {
              status: errorConditionCode,
              message: `Error condition matched for ${source.id}`,
              data: data,
            };

            const resolver = getErrorHandlingResolver();
            const resolveResult = resolver.resolve(errorConditionCode, {
              errorHandling: source.errorHandling,
              onError: source.onError,
            });

            if (resolveResult.handler) {
              try {
                await resolver.execute(resolveResult.handler, errorContext);
              } catch (handlerError) {
                logger.error(`Error executing errorCondition handler for ${source.id}:`, handlerError);
              }
            }

            // fallback이 정의된 경우 렌더링용 데이터로 사용
            if (source.fallback !== undefined) {
              this.dataCache.set(source.id, { data: source.fallback, timestamp: Date.now() });
              logger.log(`Using fallback data for ${source.id} (errorCondition)`);
              if (onUpdate) {
                onUpdate(source.id, source.fallback);
              }
            }

            return; // onSuccess 스킵
          }

          // onSuccess 핸들러 실행
          if (source.onSuccess) {
            try {
              const successContext: SuccessContext = {
                data,
                sourceId: source.id,
              };
              await this.executeOnSuccessHandler(source.onSuccess, successContext);
            } catch (handlerError) {
              logger.error(`Error executing onSuccess handler for ${source.id}:`, handlerError);
            }
          }

          if (onUpdate) {
            onUpdate(source.id, data);
          }
        })
        .catch(async (error) => {
          logger.error(`Background fetch failed: ${source.id}`, error);

          // HTTP 에러 코드 추출
          const errorCode = (error as any)?.response?.status ||
                           (error as any)?.status ||
                           500;

          // 에러 컨텍스트 생성
          // API 응답의 message를 우선 사용하고, 없으면 Axios 에러 메시지 사용
          const responseData = (error as any)?.response?.data;
          const errorMessage = responseData?.message || (error as Error).message || 'Unknown error';
          const errorContext: ErrorContext = {
            status: errorCode,
            message: errorMessage,
            errors: responseData?.errors,
            data: responseData,
            statusText: (error as any)?.response?.statusText,
          };

          // ErrorHandlingResolver를 통해 핸들러 결정 및 실행
          const resolver = getErrorHandlingResolver();
          const resolveResult = resolver.resolve(errorCode, {
            errorHandling: source.errorHandling,
            onError: source.onError,
          });

          if (resolveResult.handler) {
            try {
              await resolver.execute(resolveResult.handler, errorContext);
            } catch (handlerError) {
              logger.error(`Error executing error handler for ${source.id}:`, handlerError);
            }
          }

          // fallback이 정의된 경우 렌더링용 데이터로 사용
          if (source.fallback !== undefined) {
            this.dataCache.set(source.id, { data: source.fallback, timestamp: Date.now() });
            logger.log(`Using fallback data for ${source.id}`);
            if (onUpdate) {
              onUpdate(source.id, source.fallback);
            }
            return;
          }

          // 기존 onError 콜백도 호출 (호환성 유지)
          if (this.options.onError) {
            this.options.onError(error as Error, source);
          }
        });
    });
  }

  /**
   * 단일 데이터 소스 fetch
   *
   * @param source 데이터 소스
   * @param routeParams 라우트 파라미터
   * @param queryParams 쿼리 파라미터
   * @param globalState _global 상태 (선택적)
   * @returns 데이터
   */
  private async fetchDataSource(
    source: DataSource,
    routeParams: Record<string, string>,
    queryParams: URLSearchParams,
    globalState?: Record<string, any>,
    localState?: Record<string, any>,
  ): Promise<any> {
    switch (source.type) {
      case 'api':
        return this.fetchApiDataSource(source, routeParams, queryParams, globalState, localState);
      case 'static':
        return source.data;
      case 'route_params':
        return routeParams;
      case 'query_params':
        return parseQueryParams(queryParams);
      default:
        throw new Error(`Unknown data source type: ${source.type}`);
    }
  }

  /**
   * API 데이터 소스 fetch
   *
   * @param source 데이터 소스
   * @param routeParams 라우트 파라미터
   * @param queryParams 쿼리 파라미터
   * @param globalState _global 상태 (선택적)
   * @param localState _local 상태 (선택적, params 표현식에서 _local 접근 지원)
   * @returns 데이터
   */
  private async fetchApiDataSource(
    source: DataSource,
    routeParams: Record<string, string>,
    queryParams: URLSearchParams,
    globalState?: Record<string, any>,
    localState?: Record<string, any>,
  ): Promise<any> {
    if (!source.endpoint) {
      throw new Error(`API data source ${source.id} has no endpoint`);
    }

    // 표현식 평가용 컨텍스트 구성
    // _global이 전달된 경우 endpoint 표현식에서 _global.xxx 접근 가능
    // _local이 전달된 경우 params 표현식에서 _local.xxx 접근 가능
    // parseQueryParams를 사용하여 배열 쿼리 파라미터 지원 (예: sales_status[]=a&sales_status[]=b)
    const expressionContext: Record<string, any> = {
      route: routeParams,
      query: parseQueryParams(queryParams),
    };

    // globalState가 전달된 경우 _global로 추가
    if (globalState) {
      expressionContext._global = globalState;
    }

    // localState가 전달된 경우 _local로 추가
    // sequence 내 setState local 후 refetchDataSource에서 {{_local.xxx}} 접근 지원
    if (localState) {
      expressionContext._local = localState;
    }

    // endpoint의 {{...}} 표현식 치환 (RenderHelpers의 범용 함수 사용)
    // 복잡한 표현식({{route?.id}}, 삼항 연산자 등)도 지원
    // IMPORTANT: skipCache: true - 매 요청마다 새로운 컨텍스트로 평가해야 함 (페이지네이션 등)
    let endpoint = resolveExpressionString(source.endpoint, expressionContext, { skipCache: true });

    const method = source.method || 'GET';

    // contentType 해석 (multipart/form-data 지원)
    const resolvedContentType = source.contentType
      ? (typeof source.contentType === 'string' && source.contentType.includes('{{'))
        ? resolveExpressionString(source.contentType, expressionContext, { skipCache: true })
        : source.contentType
      : 'application/json';
    const isMultipart = resolvedContentType === 'multipart/form-data';

    // IMPORTANT: source.params를 deep clone하여 원본 보존 (레이아웃 캐시 변형 방지)
    // multipart/form-data인 경우 File/Blob 객체가 JSON 직렬화로 소실되므로 shallow clone 사용
    let params = isMultipart
      ? { ...(source.params || {}) }
      : JSON.parse(JSON.stringify(source.params || {}));

    // 파라미터 치환 (resolveParamValue 사용)
    // {{route.id}}, {{route?.id}}, {{query.page}}, 복잡한 표현식 모두 지원
    // IMPORTANT: 배열 등 원본 타입 유지를 위해 resolveParamValue 사용
    // (resolveExpressionString은 항상 문자열 반환하여 배열이 JSON 문자열로 변환됨)
    Object.entries(params).forEach(([key, value]) => {
      if (typeof value === 'string') {
        params[key] = resolveParamValue(value, expressionContext);
      }
    });

    // 빈 문자열인 파라미터 제거 (API로 전송하지 않음)
    Object.keys(params).forEach((key) => {
      if (params[key] === '') {
        delete params[key];
      }
    });

    // DevTools 요청 추적
    const devTools = getDevTools();
    let requestId: string | null = null;
    if (devTools?.isEnabled()) {
      // 데이터소스 정의 정보 등록
      devTools.trackDataSourceDefinition({
        id: source.id,
        type: source.type,
        endpoint: endpoint,
        method: method,
        autoFetch: source.auto_fetch,
        initLocal: source.initLocal,
        initGlobal: source.initGlobal,
      });

      // DevTools용 전체 URL 생성 (쿼리 파라미터 포함)
      let trackingUrl = endpoint;
      if (method === 'GET' && Object.keys(params).length > 0) {
        const searchParams = new URLSearchParams();
        Object.entries(params).forEach(([key, value]) => {
          if (Array.isArray(value)) {
            value.forEach(v => searchParams.append(key, String(v)));
          } else if (value != null) {
            searchParams.append(key, String(value));
          }
        });
        const queryString = searchParams.toString();
        trackingUrl = queryString ? `${endpoint}?${queryString}` : endpoint;
      }

      requestId = devTools.trackRequest(trackingUrl, method, {
        requestBody: method !== 'GET' ? params : undefined,
        dataSourceId: source.id,
      });
      devTools.trackDataSourceLoading(source.id);
    }

    try {
      let result: any;

      // auth_mode 결정 (auth_required: true는 'required'로 처리하여 하위 호환성 유지)
      const authMode = source.auth_mode ?? (source.auth_required === true ? 'required' : 'none');

      // 토큰 유무 확인 (optional 모드에서 사용)
      const hasToken = AuthManager.getInstance().isAuthenticated();

      // ApiClient 사용 여부 결정
      // - required: 항상 ApiClient 사용
      // - optional: 토큰이 있을 때만 ApiClient 사용
      // - none: 일반 fetch 사용
      const useApiClient = authMode === 'required' || (authMode === 'optional' && hasToken);

      if (useApiClient) {
        const apiClient = getApiClient();

        // ApiClient는 baseURL로 '/api'를 사용하므로, endpoint에서 '/api' 제거
        const normalizedEndpoint = endpoint.startsWith('/api/')
          ? endpoint.substring(4) // '/api/' 제거 -> '/'로 시작
          : endpoint.startsWith('/api')
          ? endpoint.substring(4) || '/' // '/api' 제거 -> '/' 또는 원래 경로
          : endpoint;

        if (this.options.onError) {
          logger.log(`[DataSourceManager] Normalized endpoint: ${endpoint} -> ${normalizedEndpoint}`);
        }

        // 전역 헤더 + 커스텀 헤더 병합 (source.headers가 globalHeaders보다 우선)
        // 1. globalHeaders에서 패턴 매칭된 헤더 추출
        const globalHeadersResolved = this.getMatchingGlobalHeaders(endpoint, expressionContext);

        // 2. source.headers 처리 (개별 데이터소스 헤더)
        const sourceHeaders: Record<string, string> = {};
        if (source.headers) {
          Object.entries(source.headers).forEach(([headerName, headerValue]) => {
            // 표현식 평가 ({{...}} 치환)
            const resolvedValue = resolveParamValue(headerValue, expressionContext);
            // null, undefined, 빈 문자열이 아닌 경우에만 헤더 추가
            if (resolvedValue != null && resolvedValue !== '') {
              sourceHeaders[headerName] = String(resolvedValue);
            }
          });
        }

        // 3. 병합 (source.headers가 globalHeaders를 덮어씀)
        const customHeaders: Record<string, string> = {
          ...globalHeadersResolved,
          ...sourceHeaders,
        };

        // 커스텀 헤더가 있는 경우 config에 포함
        const hasCustomHeaders = Object.keys(customHeaders).length > 0;

        // multipart/form-data인 경우 params를 FormData로 변환
        // Axios는 FormData를 받으면 Content-Type을 자동으로 multipart/form-data(+boundary)로 설정
        const requestData = isMultipart ? this.toFormData(params) : params;

        // HTTP 메서드에 따라 요청
        switch (method) {
          case 'GET':
            result = await apiClient.get(normalizedEndpoint, {
              params,
              ...(hasCustomHeaders && { headers: customHeaders }),
            });
            break;
          case 'POST':
            if (hasCustomHeaders) {
              result = await apiClient.post(normalizedEndpoint, requestData, { headers: customHeaders });
            } else {
              result = await apiClient.post(normalizedEndpoint, requestData);
            }
            break;
          case 'PUT':
            if (hasCustomHeaders) {
              result = await apiClient.put(normalizedEndpoint, requestData, { headers: customHeaders });
            } else {
              result = await apiClient.put(normalizedEndpoint, requestData);
            }
            break;
          case 'PATCH':
            if (hasCustomHeaders) {
              result = await apiClient.patch(normalizedEndpoint, requestData, { headers: customHeaders });
            } else {
              result = await apiClient.patch(normalizedEndpoint, requestData);
            }
            break;
          case 'DELETE':
            result = await apiClient.delete(normalizedEndpoint, {
              params,
              ...(hasCustomHeaders && { headers: customHeaders }),
            });
            break;
          default:
            throw new Error(`Unsupported HTTP method: ${method}`);
        }
      } else {
        // auth_mode가 'none'이거나 'optional'인데 토큰이 없는 경우 일반 fetch 사용
        let url = endpoint;

        // GET 요청인 경우 쿼리 스트링 생성
        if (method === 'GET') {
          const queryString = new URLSearchParams(params).toString();
          url = queryString ? `${endpoint}?${queryString}` : endpoint;
        }

        // 기본 헤더 (multipart/form-data는 Content-Type 생략 - 브라우저 자동 설정)
        const requestHeaders: Record<string, string> = {
          ...(isMultipart ? {} : { 'Content-Type': 'application/json' }),
          'Accept': 'application/json',
        };

        // g7_locale이 설정되어 있으면 Accept-Language 헤더로 전송
        if (typeof window !== 'undefined') {
          const locale = localStorage.getItem('g7_locale');
          if (locale) {
            requestHeaders['Accept-Language'] = locale;
          }
        }

        // 전역 헤더 적용 (globalHeaders)
        const globalHeadersResolved = this.getMatchingGlobalHeaders(endpoint, expressionContext);
        Object.assign(requestHeaders, globalHeadersResolved);

        // 커스텀 헤더 처리 (source.headers가 globalHeaders보다 우선)
        if (source.headers) {
          Object.entries(source.headers).forEach(([headerName, headerValue]) => {
            // 표현식 평가 ({{...}} 치환)
            const resolvedValue = resolveParamValue(headerValue, expressionContext);
            // null, undefined, 빈 문자열이 아닌 경우에만 헤더 추가
            if (resolvedValue != null && resolvedValue !== '') {
              requestHeaders[headerName] = String(resolvedValue);
            }
          });
        }

        // multipart/form-data인 경우 FormData로 변환
        let fetchBody: BodyInit | undefined;
        if (method !== 'GET') {
          if (isMultipart) {
            fetchBody = this.toFormData(params);
            // multipart 시 Content-Type 헤더 제거 (FormData 사용 시 브라우저가 boundary 포함하여 설정)
            delete requestHeaders['Content-Type'];
          } else {
            fetchBody = JSON.stringify(params);
          }
        }

        const response = await fetch(url, {
          method,
          headers: requestHeaders,
          body: fetchBody,
        });

        // 응답 본문 파싱 (성공/실패 모두)
        let responseBody: any;
        try {
          responseBody = await response.json();
        } catch {
          responseBody = null;
        }

        // DevTools 요청 완료 추적 (성공/실패 모두 상태 코드와 응답 기록)
        if (requestId && devTools?.isEnabled()) {
          devTools.completeRequest(requestId, response.status, responseBody);
          // 이미 기록했으므로 catch에서 중복 기록 방지
          requestId = null;
        }

        if (!response.ok) {
          // 에러 응답도 DevTools에 기록 후 throw
          if (devTools?.isEnabled()) {
            devTools.trackDataSourceError(source.id, `HTTP ${response.status}: ${response.statusText}`);
          }
          // response 정보를 Error에 포함하여 상위 catch에서 정확한 상태 코드 추출 가능
          const httpError = new Error(`HTTP error! status: ${response.status}`) as any;
          httpError.response = {
            status: response.status,
            statusText: response.statusText,
            data: responseBody,
          };
          httpError.status = response.status;
          throw httpError;
        }

        result = responseBody;

        // DevTools 데이터소스 로드 완료 추적
        if (devTools?.isEnabled()) {
          devTools.trackDataSourceLoaded(source.id, result);
        }
      }

      // DevTools 요청 완료 추적 (ApiClient 사용 시)
      if (useApiClient && requestId && devTools?.isEnabled()) {
        // ApiClient는 성공 응답만 반환하므로 200으로 기록
        // (에러 시 catch 블록으로 이동)
        devTools.completeRequest(requestId, 200, result);
        devTools.trackDataSourceLoaded(source.id, result);
      }

      return result;
    } catch (error) {
      // DevTools 요청 실패 추적 (ApiClient 에러 또는 네트워크 에러)
      if (requestId && devTools?.isEnabled()) {
        // axios 에러인 경우 상태 코드와 응답 데이터 추출
        const axiosError = error as any;
        if (axiosError?.response) {
          // HTTP 에러 응답이 있는 경우 상태 코드와 응답 본문 기록
          devTools.completeRequest(
            requestId,
            axiosError.response.status,
            axiosError.response.data
          );
        } else {
          // 네트워크 에러 등 응답이 없는 경우
          devTools.failRequest(requestId, error instanceof Error ? error.message : String(error));
        }
        devTools.trackDataSourceError(source.id, error instanceof Error ? error.message : String(error));
      }
      throw error;
    }
  }

  /**
   * 캐시된 데이터 조회
   *
   * TTL이 만료된 캐시는 자동으로 삭제됩니다.
   *
   * @param sourceId 데이터 소스 ID
   * @returns 캐시된 데이터 (없거나 만료되면 undefined)
   */
  getCachedData(sourceId: string): any | undefined {
    const entry = this.dataCache.get(sourceId);

    if (!entry) {
      return undefined;
    }

    // TTL 체크
    const now = Date.now();
    if (now - entry.timestamp > DataSourceManager.DATA_CACHE_TTL) {
      this.dataCache.delete(sourceId);
      return undefined;
    }

    return entry.data;
  }

  /**
   * 캐시 초기화
   */
  clearCache(): void {
    this.dataCache.clear();
  }

  /**
   * WebSocket 데이터 소스를 구독합니다.
   *
   * type이 'websocket'인 데이터 소스를 필터링하여
   * WebSocketManager를 통해 채널을 구독하고 이벤트를 리스닝합니다.
   *
   * @param sources 데이터 소스 배열
   * @param onUpdate 데이터 업데이트 시 호출될 콜백 (sourceId, data)
   * @returns 구독 키 배열 (구독 해제 시 사용)
   */
  subscribeWebSockets(
    sources: DataSource[],
    onUpdate: (sourceId: string, data: unknown) => void
  ): string[] {
    const subscriptionKeys: string[] = [];
    const webSocketSources = sources.filter((s) => s.type === 'websocket');

    if (webSocketSources.length === 0) {
      return subscriptionKeys;
    }

    webSocketSources.forEach((source) => {
      if (!source.channel || !source.event) {
        logger.warn(`WebSocket source ${source.id} missing channel or event`);
        return;
      }

      const key = webSocketManager.subscribe(
        source.channel,
        source.event,
        (data: unknown) => {
          // target_source가 지정된 경우 해당 소스의 캐시 업데이트
          const targetId = source.target_source || source.id;
          this.dataCache.set(targetId, { data, timestamp: Date.now() });
          onUpdate(targetId, data);
        },
        { channelType: source.channel_type || 'private' }
      );

      if (key) {
        subscriptionKeys.push(key);
      }
    });

    return subscriptionKeys;
  }

  /**
   * WebSocket 구독을 해제합니다.
   *
   * @param subscriptionKeys 구독 키 배열
   */
  unsubscribeWebSockets(subscriptionKeys: string[]): void {
    if (subscriptionKeys.length === 0) {
      return;
    }

    subscriptionKeys.forEach((key) => {
      webSocketManager.unsubscribe(key);
    });
  }

  /**
   * onSuccess 핸들러를 실행합니다.
   *
   * 데이터 소스 fetch 성공 후 정의된 onSuccess 핸들러를 실행합니다.
   * params에서 {{response.xxx}} 형태로 응답 데이터에 접근할 수 있습니다.
   *
   * @param onSuccess onSuccess 핸들러 설정
   * @param successContext 성공 컨텍스트 (data, sourceId)
   */
  private async executeOnSuccessHandler(
    onSuccess: OnSuccessHandler,
    successContext: SuccessContext
  ): Promise<void> {
    const actionDispatcher = getActionDispatcher();

    if (!actionDispatcher) {
      logger.warn('ActionDispatcher not available, skipping onSuccess handler');
      return;
    }

    // 핸들러 배열로 정규화
    const handlers = Array.isArray(onSuccess) ? onSuccess : [onSuccess];

    // 순차 실행
    for (const handler of handlers) {
      try {
        // response 컨텍스트로 핸들러 실행
        // {{response.data}}, {{response.sourceId}} 등으로 접근 가능
        await actionDispatcher.dispatchAction(
          {
            type: 'click', // 이벤트 타입은 의미 없음 (dispatchAction에서 사용하지 않음)
            ...handler,
          },
          {
            data: {
              response: successContext,
            },
          }
        );
      } catch (error) {
        logger.error(`Failed to execute onSuccess handler:`, error);
        throw error;
      }
    }
  }
}

/**
 * 전역 데이터 소스 매니저 인스턴스
 */
export const dataSourceManager = new DataSourceManager();
