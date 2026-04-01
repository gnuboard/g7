import { AuthManager } from '../auth/AuthManager';
import { createLogger } from '../utils/Logger';

const logger = createLogger('Router');

/**
 * 라우트 메타 정보
 */
export interface RouteMeta {
  title?: string;
  [key: string]: unknown;
}

/**
 * 인증 타입
 */
export type AuthType = 'admin' | 'user';

/**
 * 라우트 인터페이스
 */
export interface Route {
  path: string;
  layout?: string;
  redirect?: string;
  endpoint?: string;
  params?: Record<string, string>;
  /** 쿼리 파라미터 (배열 쿼리 파라미터 지원: key[]=[...]) */
  query?: Record<string, string | string[]>;
  auth_required?: boolean;
  auth_type?: AuthType;
  meta?: RouteMeta;
}

/**
 * 라우트 매칭 결과
 */
export interface RouteMatch {
  route: Route;
  params: Record<string, string>;
}

/**
 * 이벤트 핸들러 타입
 */
type EventHandler = (...args: any[]) => void;

/**
 * Router 클래스
 *
 * URL 패턴 매칭과 동적 파라미터 추출을 담당합니다.
 */
export class Router {
  private routes: Route[] = [];
  private templateIdentifier: string;
  private eventHandlers: Map<string, EventHandler[]> = new Map();
  private pendingNavigation: string | null = null;
  private isNavigating: boolean = false;

  constructor(templateIdentifier: string) {
    this.templateIdentifier = templateIdentifier;
    this.initPopstateListener();
  }

  /**
   * 브라우저 뒤로가기/앞으로가기 이벤트 리스너 초기화
   */
  private initPopstateListener(): void {
    window.addEventListener('popstate', this.handlePopState);
  }

  /**
   * popstate 이벤트 핸들러 (화살표 함수로 this 바인딩 유지)
   */
  private handlePopState = (): void => {
    this.navigateToCurrentPath();
  };

  /**
   * 이벤트 핸들러 등록
   */
  on(event: string, handler: EventHandler): void {
    if (!this.eventHandlers.has(event)) {
      this.eventHandlers.set(event, []);
    }
    this.eventHandlers.get(event)!.push(handler);
  }

  /**
   * 이벤트 발생 (async 핸들러 지원)
   */
  private async emit(event: string, ...args: any[]): Promise<void> {
    const handlers = this.eventHandlers.get(event);
    if (handlers) {
      // 모든 핸들러가 완료될 때까지 대기 (async 핸들러 지원)
      await Promise.all(handlers.map(handler => handler(...args)));
    }
  }

  /**
   * API에서 라우트 목록을 로드합니다.
   */
  async loadRoutes(): Promise<void> {
    try {
      const response = await fetch(`/api/templates/${this.templateIdentifier}/routes.json`);

      if (!response.ok) {
        throw new Error(`Failed to load routes: ${response.statusText}`);
      }

      const result = await response.json();

      if (!result.success) {
        throw new Error('Failed to load routes from API');
      }

      // API 응답 형태: { success: true, data: { version: "1.0.0", routes: [...] } }
      if (result.data && Array.isArray(result.data.routes)) {
        this.routes = result.data.routes;
        logger.log(`Loaded ${this.routes.length} routes`);
      } else {
        throw new Error('Invalid routes data format');
      }
    } catch (error) {
      logger.error('Error loading routes:', error);
      throw error;
    }
  }

  /**
   * 라우트 목록을 직접 설정합니다 (병렬 로딩용).
   *
   * @param routes - 설정할 라우트 배열
   */
  setRoutes(routes: Route[]): void {
    this.routes = routes;
    logger.log(`Set ${this.routes.length} routes`);
  }

  /**
   * 현재 경로와 매칭되는 라우트를 찾습니다.
   *
   * @param pathname - 매칭할 URL 경로
   * @returns 매칭된 라우트와 파라미터, 없으면 null
   */
  match(pathname: string): RouteMatch | null {
    for (const route of this.routes) {
      const params = this.matchPattern(route.path, pathname);

      if (params !== null) {
        return {
          route,
          params,
        };
      }
    }

    return null;
  }

  /**
   * 패턴과 경로를 매칭하고 파라미터를 추출합니다.
   *
   * 지원되는 패턴:
   * - /admin - 정확한 매칭
   * - /admin/users/:id - 동적 파라미터 (:id)
   * - * /admin - 언어 prefix 지원 (/admin, /ko/admin, /en/admin)
   *
   * @param pattern - 라우트 패턴
   * @param pathname - 실제 URL 경로
   * @returns 추출된 파라미터 객체, 매칭 실패 시 null
   */
  private matchPattern(pattern: string, pathname: string): Record<string, string> | null {
    // 파라미터 이름 추출
    const paramNames: string[] = [];

    // 패턴을 정규식으로 변환
    let regexPattern = pattern
      // :id 형태의 동적 파라미터를 캡처 그룹으로 변환
      .replace(/:([^/]+)/g, (_, paramName) => {
        paramNames.push(paramName);
        return '([^/]+)';
      });

    // 패턴 시작의 */를 optional 언어 prefix로 변환 (예: /ko, /en)
    // */admin → /admin 또는 /ko/admin 또는 /en/admin
    if (regexPattern.startsWith('*/')) {
      regexPattern = '(?:/[^/]+)?' + regexPattern.slice(1);
    }

    // 특수 문자 이스케이프
    regexPattern = regexPattern.replace(/\//g, '\\/');

    // 정규식 생성 (전체 문자열 매칭)
    const regex = new RegExp(`^${regexPattern}$`);
    const match = pathname.match(regex);

    if (!match) {
      return null;
    }

    // 파라미터 추출
    const params: Record<string, string> = {};
    paramNames.forEach((name, index) => {
      params[name] = match[index + 1];
    });

    return params;
  }

  /**
   * 현재 로드된 라우트 목록을 반환합니다.
   */
  getRoutes(): Route[] {
    return [...this.routes];
  }

  /**
   * 경로 기반 인증 타입 판단
   *
   * @param route - 라우트 정보
   * @param pathname - URL 경로
   * @returns 인증 타입
   */
  private getAuthType(route: Route, pathname: string): AuthType {
    // 1. 라우트에 명시된 경우 사용
    if (route.auth_type) {
      return route.auth_type;
    }

    // 2. 경로 기반 자동 판단
    if (pathname.startsWith('/admin')) {
      return 'admin';
    }

    return 'user';
  }

  /**
   * 현재 URL 경로를 처리하여 매칭되는 라우트를 찾고 이벤트를 발생시킵니다.
   */
  async navigateToCurrentPath(): Promise<void> {
    const pathname = window.location.pathname;
    const search = window.location.search;
    const matchResult = this.match(pathname);

    if (!matchResult) {
      logger.warn(`No route matched for path: ${pathname}`);
      this.emit('routeNotFound', pathname);
      return;
    }

    // 리다이렉트 라우트인 경우 처리
    if (matchResult.route.redirect) {
      logger.log(`Redirecting from ${pathname} to ${matchResult.route.redirect}`);
      this.navigate(matchResult.route.redirect);
      return;
    }

    // 인증 검증
    if (matchResult.route.auth_required) {
      const authType = this.getAuthType(matchResult.route, pathname);
      const authManager = AuthManager.getInstance();

      // 이미 인증 상태가 확인된 경우 API 호출 스킵
      let isAuthenticated = authManager.isAuthenticated() && authManager.getAuthType() === authType;

      // 인증 상태가 없거나 타입이 다른 경우에만 API 호출
      if (!isAuthenticated) {
        isAuthenticated = await authManager.checkAuth(authType);
      }

      if (!isAuthenticated) {
        const loginUrl = authManager.getLoginRedirectUrl(authType, pathname + search);
        logger.log(`Not authenticated, redirecting to: ${loginUrl}`);
        window.location.href = loginUrl;
        return;
      }
    }

    // query 파라미터 파싱 (배열 쿼리 파라미터 지원)
    const queryParams = new URLSearchParams(search);
    const query: Record<string, string | string[]> = {};

    // 모든 키를 순회하며 배열 값 처리
    for (const key of queryParams.keys()) {
      // 이미 처리된 키는 스킵
      if (key in query) continue;

      const values = queryParams.getAll(key);
      if (values.length > 1) {
        // 여러 값이 있으면 배열로 저장
        query[key] = values;
      } else if (values.length === 1) {
        // key[]로 끝나면 단일 값도 배열로 저장 (일관성 유지)
        if (key.endsWith('[]')) {
          query[key] = values;
        } else {
          query[key] = values[0];
        }
      }
    }

    // 라우트 변경 이벤트 발생 (핸들러가 완료될 때까지 대기)
    await this.emit('routeChange', {
      path: pathname,
      layout: matchResult.route.layout,
      endpoint: matchResult.route.endpoint,
      params: { ...(matchResult.route.params || {}), ...matchResult.params },
      query,
      auth_required: matchResult.route.auth_required,
      auth_type: matchResult.route.auth_type,
      meta: matchResult.route.meta,
    });
  }

  /**
   * 지정된 경로로 이동합니다.
   *
   * 동시에 여러 navigate 호출이 발생할 경우, 마지막 요청만 처리합니다.
   * 이전 navigate가 진행 중이면 대기했다가 최신 경로로 이동합니다.
   *
   * @param path - 이동할 경로 (예: '/admin/dashboard')
   */
  navigate(path: string): void {
    // 이미 navigate가 진행 중이면 대기열에 추가
    if (this.isNavigating) {
      this.pendingNavigation = path;
      return;
    }

    this.executeNavigation(path);
  }

  /**
   * 실제 navigate 실행
   */
  private async executeNavigation(path: string): Promise<void> {
    this.isNavigating = true;
    this.pendingNavigation = null;

    try {
      // URL 변경 (브라우저 히스토리에 추가)
      window.history.pushState({}, '', path);

      // 라우트 매칭 및 렌더링 (완료까지 대기)
      await this.navigateToCurrentPath();
    } finally {
      this.isNavigating = false;

      // 대기 중인 navigate가 있으면 실행
      if (this.pendingNavigation) {
        const nextPath = this.pendingNavigation;
        this.executeNavigation(nextPath);
      }
    }
  }
}
