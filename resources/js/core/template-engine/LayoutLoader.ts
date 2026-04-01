/**
 * NOTE: @since versions (engine-v1.x.x) refer to internal template engine
 * development iterations, not G7 platform release versions.
 */
/**
 * LayoutLoader.ts
 *
 * 레이아웃 데이터를 비동기로 로드하고 TemplateEngine을 통해 렌더링하는 클래스
 *
 * 역할:
 * - API로부터 레이아웃 JSON 데이터 로드
 * - 로드된 데이터를 DOM에 렌더링
 * - 에러 발생 시 에러 UI 표시
 */

import type { ComponentRegistry } from './ComponentRegistry';
import type { ErrorHandlingMap } from '../types/ErrorHandling';
import { createLogger } from '../utils/Logger';
import { G7DevToolsCore } from '../devtools/G7DevToolsCore';
import { getApiClient } from '../api/ApiClient';

const logger = createLogger('LayoutLoader');

/**
 * 레이아웃 컴포넌트 데이터 인터페이스
 */
export interface LayoutComponent {
  /** 컴포넌트 타입 */
  type: string;

  /** 컴포넌트 props */
  props?: Record<string, any>;

  /** 자식 컴포넌트 목록 */
  children?: LayoutComponent[];

  /** 텍스트 콘텐츠 (children이 없을 경우) */
  text?: string;
}

/**
 * 초기화 액션 정의 (레이아웃 로드 시 실행)
 */
export interface InitActionDefinition {
  /** 액션 핸들러 이름 */
  handler: string;
  /** 액션 타겟 (선택적) */
  target?: string;
  /** 액션 파라미터 (선택적) */
  params?: Record<string, any>;
  /** 조건부 실행 (표현식이 truthy일 때만 실행) */
  if?: string;
  /** 핸들러 결과를 상태에 저장할 위치 (선택적) */
  resultTo?: {
    /** 저장 대상: "_local" (로컬 상태), "_global" (전역 상태), "_isolated" (격리된 상태) */
    target: '_local' | '_global' | '_isolated';
    /** 상태 키 */
    key: string;
    /** 병합 모드: "replace" | "shallow" | "deep" (기본값: "deep") */
    merge?: 'replace' | 'shallow' | 'deep';
  };
  /** 액션 성공 시 실행할 후속 액션 (단일 또는 배열) */
  onSuccess?: InitActionDefinition | InitActionDefinition[];
  /** 액션 실패 시 실행할 후속 액션 (단일 또는 배열) */
  onError?: InitActionDefinition | InitActionDefinition[];
  /** conditions 핸들러용 조건 분기 배열 (선택적) */
  conditions?: Array<{
    if: string;
    then?: any;
    else?: any;
  }>;
}

/**
 * 외부 스크립트 정의 (레이아웃 로드 시 동적 로드)
 *
 * 레이아웃 진입 시 외부 스크립트를 1회만 로드합니다.
 * 이미 로드된 스크립트는 중복 로드하지 않습니다.
 *
 * @since engine-v1.8.0
 *
 * @example
 * ```json
 * {
 *   "scripts": [
 *     {
 *       "src": "//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js",
 *       "id": "daum_postcode_script",
 *       "if": "{{_global.installedPlugins?.find(p => p.identifier === 'sirsoft-daum_postcode' && p.status === 'active')}}"
 *     }
 *   ]
 * }
 * ```
 */
export interface LayoutScript {
  /** 스크립트 URL (필수) */
  src: string;
  /** 스크립트 요소 ID (중복 로드 방지용, 필수) */
  id: string;
  /** 조건부 로드 표현식 (선택적, 예: "{{_global.pluginActive}}") */
  if?: string;
  /**
   * 조건부 로드 조건 (복합 조건)
   *
   * if 속성의 상위 호환으로 AND/OR 그룹을 지원합니다.
   * if와 conditions가 둘 다 있으면 if가 우선 평가됩니다.
   *
   * @example
   * // OR 그룹: 분석 플래그가 활성화되거나 admin일 때 로드
   * {
   *   "src": "//analytics.js",
   *   "id": "analytics_script",
   *   "conditions": {
   *     "or": ["{{_global.featureFlags?.enableAnalytics}}", "{{_global.user?.role === 'admin'}}"]
   *   }
   * }
   *
   * @since engine-v1.10.0
   */
  conditions?: import('./helpers/ConditionEvaluator').ConditionsProperty;
  /** 비동기 로드 여부 (기본: true) */
  async?: boolean;
}

/**
 * 레이아웃 경고 타입
 *
 * 호환성 경고, 시스템 경고 등 다양한 경고 유형을 지원합니다.
 */
export type LayoutWarningType = 'compatibility' | 'deprecation' | 'license' | 'security' | 'system';

/**
 * 레이아웃 경고 레벨
 */
export type LayoutWarningLevel = 'info' | 'warning' | 'error';

/**
 * 레이아웃 경고 인터페이스
 *
 * 백엔드에서 프론트엔드로 전달되는 경고 정보입니다.
 */
export interface LayoutWarning {
  /** 경고 고유 ID (dismiss 처리에 사용) */
  id: string;
  /** 경고 유형 */
  type: LayoutWarningType;
  /** 경고 레벨 */
  level: LayoutWarningLevel;
  /** 사용자에게 표시할 메시지 */
  message: string;
  /** 추가 메타데이터 (경고 유형에 따라 다름) */
  [key: string]: any;
}

/**
 * 전역 헤더 규칙 인터페이스
 *
 * 레이아웃에서 정의한 API 호출에 자동으로 적용되는 HTTP 헤더를 정의합니다.
 * pattern에 매칭되는 엔드포인트에만 헤더가 적용됩니다.
 *
 * @since engine-v1.16.0
 *
 * @example
 * ```json
 * {
 *   "globalHeaders": [
 *     { "pattern": "*", "headers": { "X-Template": "basic" } },
 *     { "pattern": "/api/modules/sirsoft-ecommerce/*", "headers": { "X-Cart-Key": "{{_global.cartKey}}" } }
 *   ]
 * }
 * ```
 */
export interface GlobalHeaderRule {
  /** 엔드포인트 매칭 패턴 ("*", "/api/shop/*" 등) */
  pattern: string;
  /** 적용할 HTTP 헤더 키-값 쌍 (표현식 지원) */
  headers: Record<string, string>;
}

/**
 * 레이아웃 데이터 인터페이스
 */
export interface LayoutData {
  /** 레이아웃 버전 */
  version: string;

  /** 레이아웃 이름 */
  layout_name: string;

  /** 최상위 컴포넌트 목록 */
  components: LayoutComponent[];

  /** 레이아웃 메타데이터 */
  meta?: {
    title?: string;
    description?: string;
    auth_required?: boolean;
    [key: string]: any;
  };

  /** 데이터 소스 */
  data_sources?: any[];

  /**
   * 초기화 액션 목록 (레이아웃 로드 시 실행)
   *
   * @deprecated init_actions 대신 initActions 사용을 권장합니다
   */
  init_actions?: InitActionDefinition[];

  /**
   * 초기화 액션 목록 (레이아웃 로드 시 실행)
   *
   * state 블록 적용 후, 렌더링 전에 실행되어 _local/_global 상태를 설정합니다.
   * 동적 표현식({{...}})을 사용한 초기값 설정이 가능합니다.
   *
   * @since engine-v1.11.0
   */
  initActions?: InitActionDefinition[];

  /**
   * 외부 스크립트 목록 (레이아웃 로드 시 동적 로드)
   *
   * 레이아웃 진입 시 외부 스크립트를 1회만 로드합니다.
   * if 조건이 있으면 조건을 만족할 때만 로드합니다.
   *
   * @since engine-v1.8.0
   *
   * @example
   * ```json
   * {
   *   "scripts": [
   *     {
   *       "src": "//cdn.example.com/library.js",
   *       "id": "my_library",
   *       "if": "{{_global.pluginActive}}"
   *     }
   *   ]
   * }
   * ```
   */
  scripts?: LayoutScript[];

  /**
   * 시스템 경고 목록
   *
   * 백엔드에서 감지된 경고(버전 호환성 등)를 프론트엔드에 전달합니다.
   * 템플릿의 베이스 레이아웃에서 이 필드를 기반으로 Alert 컴포넌트를 렌더링합니다.
   *
   * @example
   * ```json
   * {
   *   "warnings": [
   *     {
   *       "id": "compatibility_123",
   *       "type": "compatibility",
   *       "level": "warning",
   *       "message": "오버라이드가 모듈 버전과 호환되지 않습니다"
   *     }
   *   ]
   * }
   * ```
   */
  warnings?: LayoutWarning[];

  /**
   * 레이아웃 전환 시 오버레이 설정
   *
   * 페이지 전환 시 순수 DOM 조작으로 오버레이를 표시하여
   * React 비동기 렌더링 중 stale DOM flash를 방지합니다.
   *
   * - true: 기본 opaque 스타일 (document.body 전체)
   * - { enabled, style, target }: 상세 설정
   * - false/미설정: 오버레이 없음 (기존 동작)
   *
   * @since engine-v1.23.0
   */
  transition_overlay?: boolean | {
      enabled: boolean;
      style?: 'opaque' | 'blur' | 'fade' | 'skeleton' | 'spinner';
      /** 오버레이를 삽입할 컨테이너 요소 ID (미지정 시 document.body) */
      target?: string;
      /**
       * target DOM 미존재 시 대체 타겟 요소 ID
       *
       * 3단계 fallback chain: target → fallback_target → #app (전체 페이지)
       * 예: 마이페이지 탭 전환 시 target="mypage_tab_content",
       *     페이지 전환 시 fallback_target="main_content_area"
       *
       * @since engine-v1.24.2
       */
      fallback_target?: string;
      /**
       * 스켈레톤 UI 렌더링 설정
       *
       * style이 'skeleton'일 때 사용됩니다.
       * 지정된 컴포넌트에 레이아웃 컴포넌트 트리를 전달하여
       * 동적으로 스켈레톤 UI를 생성합니다.
       *
       * @since engine-v1.24.0
       */
      skeleton?: {
          /** 스켈레톤 렌더러 컴포넌트 이름 (컴포넌트 레지스트리에 등록된 이름) */
          component: string;
          /** 스켈레톤 애니메이션 타입 */
          animation?: 'pulse' | 'wave' | 'none';
          /** iteration 블록의 기본 반복 횟수 */
          iteration_count?: number;
      };
      /**
       * 스피너 로딩 UI 설정
       *
       * style이 'spinner'일 때 사용됩니다.
       * 커스텀 로딩 컴포넌트를 지정하거나, 미지정 시 기본 CSS 스피너로 폴백합니다.
       *
       * @since engine-v1.29.0
       */
      spinner?: {
          /** 로딩 컴포넌트 이름 (컴포넌트 레지스트리에 등록된 이름) */
          component?: string;
          /** 커스텀 로딩 텍스트 (미지정 시 nav.loading 번역 키 사용) */
          text?: string;
      };
  };

  /**
   * 레이아웃 레벨 에러 핸들링 설정
   *
   * 이 레이아웃에서 발생하는 모든 에러에 대한 기본 핸들링을 정의합니다.
   * 액션/데이터소스 레벨 설정보다 우선순위가 낮습니다.
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
   * 레이아웃 접근 권한 목록
   *
   * 이 레이아웃에 접근하기 위해 필요한 권한 목록입니다.
   * - 빈 배열([]): 공개 레이아웃 (인증된 사용자 모두 접근 가능)
   * - 권한 배열: AND 조건으로 모든 권한 필요
   * - undefined: 부모 레이아웃 권한 상속
   *
   * 서버에서 권한 검증 후 401(미인증) 또는 403(권한 없음) 응답을 반환합니다.
   *
   * @since engine-v1.14.0
   *
   * @example
   * ```json
   * {
   *   "permissions": ["core.users.read"]
   * }
   * ```
   */
  permissions?: string[];

  /**
   * 전역 헤더 규칙 (globalHeaders)
   *
   * 레이아웃에서 정의한 모든 API 호출(data_sources, apiCall)에
   * 자동으로 적용되는 HTTP 헤더를 정의합니다.
   * pattern에 매칭되는 엔드포인트에만 헤더가 적용됩니다.
   *
   * 적용 우선순위:
   * 1. globalHeaders (낮음)
   * 2. data_source.headers / apiCall params.headers (높음)
   *
   * 상속 시 병합 규칙:
   * - 부모와 자식의 globalHeaders를 병합
   * - 동일 pattern은 headers 병합 (자식 우선)
   *
   * @since engine-v1.16.0
   *
   * @example
   * ```json
   * {
   *   "globalHeaders": [
   *     { "pattern": "*", "headers": { "X-Template": "basic" } },
   *     { "pattern": "/api/modules/sirsoft-ecommerce/*", "headers": { "X-Cart-Key": "{{_global.cartKey}}" } }
   *   ]
   * }
   * ```
   */
  globalHeaders?: GlobalHeaderRule[];

  /**
   * 명명된 액션 정의 (named_actions)
   *
   * 재사용 가능한 액션을 정의합니다.
   * 컴포넌트의 actionRef 속성으로 참조할 수 있습니다.
   * 부모-자식 레이아웃 간 병합 시 자식이 부모를 오버라이드합니다.
   *
   * @since engine-v1.19.0
   *
   * @example
   * ```json
   * {
   *   "named_actions": {
   *     "searchProducts": {
   *       "handler": "navigate",
   *       "params": {
   *         "path": "/admin/ecommerce/products",
   *         "mergeQuery": true,
   *         "query": { "page": 1, "search_field": "..." }
   *       }
   *     }
   *   }
   * }
   * ```
   */
  named_actions?: Record<string, any>;

  /**
   * 정적 상수 정의 (defines)
   *
   * 렌더링 중 변하지 않는 상수 값을 정의합니다.
   * 매핑 테이블, 설정 값 등 정적 데이터에 적합합니다.
   * 컴포넌트에서 `{{_defines.xxx}}`로 접근합니다.
   *
   * @since engine-v1.9.0
   *
   * @example
   * ```json
   * {
   *   "defines": {
   *     "currencyFlagMap": {
   *       "KRW": "kr",
   *       "USD": "us",
   *       "JPY": "jp",
   *       "EUR": "eu"
   *     },
   *     "MAX_ITEMS": 100,
   *     "STATUS_COLORS": {
   *       "active": "green",
   *       "inactive": "gray"
   *     }
   *   }
   * }
   * ```
   */
  defines?: Record<string, any>;

  /**
   * 로컬 상태 초기값 정의 (state)
   *
   * @deprecated state 대신 initLocal 사용을 권장합니다
   */
  state?: Record<string, any>;

  /**
   * _local 상태 초기값 정의 (initLocal)
   *
   * 레이아웃 로드 시 _local 상태의 초기값을 정의합니다.
   * 정적 값만 지원하며, 동적 표현식({{...}})은 사용할 수 없습니다.
   * 폼의 기본값, 탭 초기 상태 등 정적 초기값 설정에 적합합니다.
   *
   * 적용 순서:
   * 1. 레이아웃 initLocal (정적 기본값)
   * 2. 데이터소스 initLocal (API 응답으로 병합)
   * 3. initActions (동적 초기화)
   *
   * 병합 방식:
   * - 데이터소스 initLocal이 있으면 깊은 병합 (deep merge)
   * - 레이아웃 initLocal의 값은 기본값으로 유지
   * - 데이터소스 응답에서 추가되는 값만 병합
   *
   * @since engine-v1.11.0
   *
   * @example
   * ```json
   * {
   *   "initLocal": {
   *     "activeTab": "basic",
   *     "hasChanges": false,
   *     "formData": {
   *       "name": "",
   *       "type": "basic",
   *       "use_comment": false
   *     }
   *   }
   * }
   * ```
   */
  initLocal?: Record<string, any>;

  /**
   * _global 상태 초기값 정의 (initGlobal)
   *
   * 레이아웃 로드 시 _global 상태의 초기값을 정의합니다.
   * 정적 값만 지원하며, 동적 표현식({{...}})은 사용할 수 없습니다.
   *
   * 적용 순서:
   * 1. 레이아웃 initGlobal (정적 기본값)
   * 2. 데이터소스 initGlobal (API 응답으로 병합)
   * 3. initActions (동적 초기화)
   *
   * 병합 방식:
   * - 데이터소스 initGlobal이 있으면 깊은 병합 (deep merge)
   * - 레이아웃 initGlobal의 값은 기본값으로 유지
   * - 데이터소스 응답에서 추가되는 값만 병합
   *
   * @since engine-v1.11.0
   *
   * @example
   * ```json
   * {
   *   "initGlobal": {
   *     "sidebarOpen": true,
   *     "theme": "light"
   *   }
   * }
   * ```
   */
  initGlobal?: Record<string, any>;

  /**
   * _isolated 상태 초기값 정의 (initIsolated)
   *
   * 레이아웃 로드 시 _isolated 상태의 초기값을 정의합니다.
   * isolatedState 속성이 정의된 컴포넌트 내에서만 사용됩니다.
   * 정적 값만 지원하며, 동적 표현식({{...}})은 사용할 수 없습니다.
   *
   * 적용 순서:
   * 1. 레이아웃 initIsolated (정적 기본값)
   * 2. 데이터소스 initIsolated (API 응답으로 병합)
   *
   * 병합 방식:
   * - 데이터소스 initIsolated가 있으면 깊은 병합 (deep merge)
   * - 레이아웃 initIsolated의 값은 기본값으로 유지
   * - 데이터소스 응답에서 추가되는 값만 병합
   *
   * @since engine-v1.11.0
   *
   * @example
   * ```json
   * {
   *   "initIsolated": {
   *     "selectedItems": [],
   *     "filterOptions": {
   *       "status": "all"
   *     }
   *   }
   * }
   * ```
   */
  initIsolated?: Record<string, any>;

  /**
   * 파생 상태 정의 (computed)
   *
   * 다른 상태나 데이터에서 파생되는 값을 정의합니다.
   * 의존하는 데이터가 변경되면 자동으로 재계산됩니다.
   * 컴포넌트에서 `{{_computed.xxx}}`로 접근합니다.
   *
   * 표현식에서 사용 가능한 컨텍스트:
   * - `_defines`: defines에 정의된 상수
   * - `_global`: 전역 상태
   * - `_local`: 로컬 상태
   * - 데이터 소스 결과
   *
   * @since engine-v1.9.0
   *
   * @example
   * ```json
   * {
   *   "computed": {
   *     "flagCode": "{{_defines.currencyFlagMap[currency.code] ?? 'xx'}}",
   *     "totalPrice": "{{items.reduce((sum, i) => sum + i.price, 0)}}",
   *     "isAdmin": "{{_global.user?.role === 'admin'}}"
   *   }
   * }
   * ```
   *
   * $switch 표현식도 지원합니다:
   *
   * @example
   * ```json
   * {
   *   "computed": {
   *     "statusBadgeClass": {
   *       "$switch": "{{row.status_variant}}",
   *       "$cases": {
   *         "success": "bg-green-100 text-green-800",
   *         "danger": "bg-red-100 text-red-800"
   *       },
   *       "$default": "bg-gray-100 text-gray-600"
   *     }
   *   }
   * }
   * ```
   */
  computed?: Record<string, string | ComputedSwitchDefinition>;
}

/**
 * $switch 형태의 computed 정의
 */
export interface ComputedSwitchDefinition {
  $switch: string;
  $cases: Record<string, any>;
  $default?: any;
}

/**
 * LayoutLoader 에러 클래스
 */
export class LayoutLoaderError extends Error {
  constructor(
    message: string,
    public code: string,
    public details?: any
  ) {
    super(message);
    this.name = 'LayoutLoaderError';
  }
}

/**
 * LayoutLoader 클래스
 *
 * 레이아웃 JSON을 로드하고 DOM에 렌더링
 */
export class LayoutLoader {
  /** ComponentRegistry 인스턴스 */
  private componentRegistry: ComponentRegistry;

  /** 현재 로드된 레이아웃 데이터 */
  private currentLayout: LayoutData | null = null;

  /** 레이아웃 캐시 (templateId:layoutPath를 키로 사용) */
  private layoutCache = new Map<string, Promise<LayoutData>>();

  /** 확장 기능 캐시 버전 (모듈/플러그인 활성화 시 갱신됨) */
  private cacheVersion: number = 0;

  /**
   * 생성자
   *
   * @param componentRegistry ComponentRegistry 인스턴스
   */
  constructor(componentRegistry: ComponentRegistry) {
    this.componentRegistry = componentRegistry;
  }

  /**
   * 캐시 버전 설정
   *
   * 모듈/플러그인 활성화 시 캐시 버전이 변경되어
   * 새로운 레이아웃 데이터를 서버에서 가져오도록 합니다.
   *
   * @param version 캐시 버전 (타임스탬프)
   */
  public setCacheVersion(version: number): void {
    if (this.cacheVersion !== version) {
      logger.log('Cache version updated:', this.cacheVersion, '->', version);
      this.cacheVersion = version;
      // 캐시 버전 변경 시 기존 캐시 클리어 (새 버전으로 로드 필요)
      this.layoutCache.clear();
    }
  }

  /**
   * 현재 캐시 버전 반환
   */
  public getCacheVersion(): number {
    return this.cacheVersion;
  }

  /**
   * 레이아웃 데이터 로드 (캐싱 지원)
   *
   * @param templateId 템플릿 식별자
   * @param layoutPath 레이아웃 경로
   * @returns 레이아웃 데이터
   */
  public async loadLayout(templateId: string, layoutPath: string): Promise<LayoutData> {
    // 캐시 키 생성
    const cacheKey = `${templateId}:${layoutPath}`;

    // 캐시 확인
    if (this.layoutCache.has(cacheKey)) {
      logger.log('Loading layout from cache:', layoutPath);
      const cachedData = await this.layoutCache.get(cacheKey)!;
      // IMPORTANT: deep clone하여 반환 (원본 캐시 데이터 보존)
      // data_sources.params 등이 변형되어도 캐시가 오염되지 않도록 함
      const data = JSON.parse(JSON.stringify(cachedData)) as LayoutData;
      this.currentLayout = data;

      // DevTools에 레이아웃 로드 추적 (캐시 히트)
      G7DevToolsCore.getInstance().trackLayoutLoad(layoutPath, templateId, data, 'cache');

      return data;
    }

    // 캐시 미스 - 새로운 로딩 Promise 생성 및 캐시에 저장
    const loadPromise = this.fetchLayout(templateId, layoutPath);
    this.layoutCache.set(cacheKey, loadPromise);

    return loadPromise;
  }

  /**
   * 레이아웃 데이터 실제 로드 (내부 메서드)
   *
   * @param templateId 템플릿 식별자
   * @param layoutPath 레이아웃 경로
   * @param skipToken 토큰 없이 요청 (401 재시도 시 사용)
   * @returns 레이아웃 데이터
   */
  private async fetchLayout(templateId: string, layoutPath: string, skipToken: boolean = false): Promise<LayoutData> {
    try {
      // API 엔드포인트 구성 (캐시 버전 쿼리 파라미터 추가)
      // 시스템 레이아웃 분기: __preview__ 는 별도 API 엔드포인트 사용
      let baseUrl: string;
      if (layoutPath.startsWith('__preview__/')) {
        const token = layoutPath.replace('__preview__/', '');
        baseUrl = `/api/layouts/preview/${token}.json`;
      } else {
        baseUrl = `/api/layouts/${templateId}/${layoutPath}.json`;
      }
      const apiUrl = this.cacheVersion > 0 ? `${baseUrl}?v=${this.cacheVersion}` : baseUrl;

      logger.log('Fetching layout from API:', apiUrl);

      // 인증 토큰 가져오기 (있으면 포함, 없으면 비회원 요청)
      const apiClient = getApiClient();
      const token = skipToken ? null : apiClient.getToken();
      const headers: Record<string, string> = {
        'Accept': 'application/json',
      };
      if (token) {
        headers['Authorization'] = `Bearer ${token}`;
      }

      // API 호출 (인증 헤더 포함)
      const response = await fetch(apiUrl, { headers });

      // JSON 파싱 (에러 응답도 JSON일 수 있으므로 먼저 파싱)
      let responseData: any;
      try {
        responseData = await response.json();
      } catch {
        responseData = null;
      }

      if (!response.ok) {
        // 401 Unauthorized 처리: 토큰이 무효하면 삭제 후 토큰 없이 재시도
        // 로그인 페이지 등 공개 레이아웃 접근을 보장하기 위함
        if (response.status === 401 && !skipToken && token) {
          logger.log('Token invalid, removing and retrying without token');
          apiClient.removeToken();
          return this.fetchLayout(templateId, layoutPath, true);
        }

        // API 응답에서 에러 메시지 추출
        const apiMessage = responseData?.message || responseData?.error;

        throw new LayoutLoaderError(
          `Failed to fetch layout: ${response.status} ${response.statusText}`,
          'FETCH_FAILED',
          {
            status: response.status,
            statusText: response.statusText,
            url: apiUrl,
            apiMessage,
          }
        );
      }

      // API 응답에서 실제 레이아웃 데이터 추출
      const data = responseData.data || responseData;

      // 레이아웃 데이터 검증
      this.validateLayoutData(data);

      // 현재 레이아웃 저장
      this.currentLayout = data;

      // DevTools에 레이아웃 로드 추적 (API 로드)
      G7DevToolsCore.getInstance().trackLayoutLoad(layoutPath, templateId, data, 'api');

      logger.log('Layout fetched and cached successfully:', data.layout_name);

      return data;
    } catch (error) {
      // 에러 발생 시 캐시에서 제거
      const cacheKey = `${templateId}:${layoutPath}`;
      this.layoutCache.delete(cacheKey);

      if (error instanceof LayoutLoaderError) {
        throw error;
      }

      throw new LayoutLoaderError(
        'Failed to load layout',
        'LOAD_FAILED',
        { originalError: error }
      );
    }
  }

  /**
   * 레이아웃 프리페치 (백그라운드 로딩)
   *
   * @param templateId 템플릿 식별자
   * @param layoutPath 레이아웃 경로
   * @returns 레이아웃 데이터 Promise
   */
  public prefetchLayout(templateId: string, layoutPath: string): Promise<LayoutData> {
    return this.loadLayout(templateId, layoutPath);
  }

  /**
   * 레이아웃 데이터 검증
   *
   * @param data 레이아웃 데이터
   */
  private validateLayoutData(data: any): void {
    // 필수 필드 검증
    if (!data.version) {
      throw new LayoutLoaderError(
        'Layout data missing required field: version',
        'VALIDATION_FAILED',
        { field: 'version' }
      );
    }

    if (!data.layout_name) {
      throw new LayoutLoaderError(
        'Layout data missing required field: layout_name',
        'VALIDATION_FAILED',
        { field: 'layout_name' }
      );
    }

    if (!Array.isArray(data.components)) {
      throw new LayoutLoaderError(
        'Layout data field "components" must be an array',
        'VALIDATION_FAILED',
        { field: 'components', value: data.components }
      );
    }

    logger.log('Layout data validation passed');
  }

  /**
   * 레이아웃 렌더링
   *
   * @param container 렌더링할 컨테이너 요소
   * @param layout 레이아웃 데이터 (생략 시 현재 로드된 레이아웃 사용)
   */
  public renderLayout(container: HTMLElement, layout?: LayoutData): void {
    try {
      const layoutData = layout || this.currentLayout;

      if (!layoutData) {
        throw new LayoutLoaderError(
          'No layout data to render',
          'NO_LAYOUT_DATA'
        );
      }

      logger.log('Rendering layout:', layoutData.layout_name);

      // 컨테이너 초기화
      container.innerHTML = '';

      // 컴포넌트 렌더링
      this.renderComponents(container, layoutData.components);

      logger.log('Layout rendered successfully');
    } catch (error) {
      logger.error('Render error:', error);

      // 에러 UI 표시
      this.renderErrorState(container, error);
    }
  }

  /**
   * 컴포넌트 목록 렌더링
   *
   * @param container 컨테이너 요소
   * @param components 컴포넌트 목록
   */
  private renderComponents(container: HTMLElement, components: LayoutComponent[]): void {
    for (const componentData of components) {
      const element = this.renderComponent(componentData);
      if (element) {
        container.appendChild(element);
      }
    }
  }

  /**
   * 개별 컴포넌트 렌더링
   *
   * @param componentData 컴포넌트 데이터
   * @returns 렌더링된 DOM 요소 또는 null
   */
  private renderComponent(componentData: LayoutComponent): HTMLElement | null {
    try {
      // 컴포넌트 조회
      const Component = this.componentRegistry.getComponent(componentData.type);

      if (!Component) {
        logger.warn(`Component not found: ${componentData.type}`);
        return this.createPlaceholderElement(componentData.type);
      }

      // 컴포넌트 렌더링을 위한 div 생성
      const wrapper = document.createElement('div');
      wrapper.setAttribute('data-component', componentData.type);

      // props 설정
      if (componentData.props) {
        Object.entries(componentData.props).forEach(([key, value]) => {
          wrapper.setAttribute(`data-prop-${key}`, JSON.stringify(value));
        });
      }

      // 자식 요소 렌더링
      if (componentData.children && componentData.children.length > 0) {
        this.renderComponents(wrapper, componentData.children);
      } else if (componentData.text) {
        wrapper.textContent = componentData.text;
      }

      return wrapper;
    } catch (error) {
      logger.error(`Error rendering component ${componentData.type}:`, error);
      return this.createErrorElement(componentData.type, error);
    }
  }

  /**
   * 플레이스홀더 요소 생성
   *
   * @param componentType 컴포넌트 타입
   * @returns 플레이스홀더 DOM 요소
   */
  private createPlaceholderElement(componentType: string): HTMLElement {
    const div = document.createElement('div');
    div.className = 'component-placeholder';
    div.setAttribute('data-component-type', componentType);
    div.innerHTML = `
      <div style="padding: 1rem; border: 2px dashed #ccc; background: #f9f9f9; text-align: center;">
        <p style="margin: 0; color: #666;">Component not found: <strong>${componentType}</strong></p>
      </div>
    `;
    return div;
  }

  /**
   * 컴포넌트 에러 요소 생성
   *
   * @param componentType 컴포넌트 타입
   * @param error 에러 객체
   * @returns 에러 DOM 요소
   */
  private createErrorElement(componentType: string, error: any): HTMLElement {
    const div = document.createElement('div');
    div.className = 'component-error';
    div.setAttribute('data-component-type', componentType);
    div.innerHTML = `
      <div style="padding: 1rem; border: 2px solid #f44336; background: #ffebee; border-radius: 4px;">
        <p style="margin: 0 0 0.5rem 0; color: #c62828; font-weight: bold;">Component Error: ${componentType}</p>
        <p style="margin: 0; color: #666; font-size: 0.875rem;">${error?.message || 'Unknown error'}</p>
      </div>
    `;
    return div;
  }

  /**
   * 에러 상태 렌더링
   *
   * @param container 컨테이너 요소
   * @param error 에러 객체
   */
  private renderErrorState(container: HTMLElement, error: any): void {
    const errorMessage = error instanceof Error ? error.message : String(error);
    const errorCode = error instanceof LayoutLoaderError ? error.code : 'UNKNOWN_ERROR';

    container.innerHTML = `
      <div class="layout-error" data-error-code="${errorCode}" style="
        padding: 2rem;
        text-align: center;
        background: #ffebee;
        border: 2px solid #f44336;
        border-radius: 8px;
        margin: 2rem auto;
        max-width: 600px;
      ">
        <h2 style="
          margin: 0 0 1rem 0;
          color: #c62828;
          font-size: 1.5rem;
        ">레이아웃 로드 실패</h2>

        <p style="
          margin: 0 0 1rem 0;
          color: #666;
          font-size: 1rem;
        ">${errorMessage}</p>

        <p style="
          margin: 0;
          color: #999;
          font-size: 0.875rem;
        ">오류 코드: ${errorCode}</p>

        <button
          onclick="window.location.reload()"
          style="
            margin-top: 1.5rem;
            padding: 0.75rem 1.5rem;
            background: #f44336;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 1rem;
          "
        >페이지 새로고침</button>
      </div>
    `;

    logger.error('Error state rendered:', errorCode, errorMessage);
  }

  /**
   * 현재 레이아웃 데이터 조회
   */
  public getCurrentLayout(): LayoutData | null {
    return this.currentLayout;
  }

  /**
   * 레이아웃 데이터 및 캐시 클리어
   */
  public clear(): void {
    this.currentLayout = null;
    this.layoutCache.clear();
    logger.log('Layout data and cache cleared');
  }
}

export default LayoutLoader;
