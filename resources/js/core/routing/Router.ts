import { AuthManager } from '../auth/AuthManager';
import { createLogger } from '../utils/Logger';
import { suffixed } from '../support/assetUrl';
import { notifyLocationReplaced } from '../template-engine/navigationLinks';
import {
  consumeArmedLeaveGuardBypass,
  endLeaveApproval,
  installLeaveGuardUnloadListener,
  requestLeave,
} from './LeaveGuard';

const logger = createLogger('Router');

/**
 * Router.navigate 옵션
 */
export interface RouterNavigateOptions {
  /** 레이아웃 이탈 가드(`meta.leave_guard`) 확인을 이번 이동에 한해 생략 (저장 성공 뒤 이동 등) @since engine-v1.82.0 */
  bypassLeaveGuard?: boolean;
  /** 호출 측 호환용 — Router 는 언제나 pushState 한다(교체는 navigate 액션의 replace 가 맡는다) */
  replace?: boolean;
  [key: string]: unknown;
}

/**
 * 지금 히스토리 항목의 위치 — Navigation API(`navigation.currentEntry.index`)가 있을 때만 안다.
 *
 * @returns 위치, 모르면 null
 */
function currentHistoryEntryIndex(): number | null {
  const index = (window as any).navigation?.currentEntry?.index;

  return typeof index === 'number' && index >= 0 ? index : null;
}

/**
 * 지금 주소(경로+쿼리+해시)
 */
function currentUrl(): string {
  return window.location.pathname + window.location.search + window.location.hash;
}

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
  /** 마지막으로 확정한 화면의 주소 — 이탈 가드가 popstate 를 되돌릴 때 쓴다 @since engine-v1.82.0 */
  private committedUrl: string;
  /** 마지막으로 확정한 히스토리 항목 위치(Navigation API), 모르면 null @since engine-v1.82.0 */
  private committedHistoryIndex: number | null;

  constructor(templateIdentifier: string) {
    this.templateIdentifier = templateIdentifier;
    this.committedUrl = currentUrl();
    this.committedHistoryIndex = currentHistoryEntryIndex();
    this.initPopstateListener();
    installLeaveGuardUnloadListener();
  }

  /**
   * 브라우저 뒤로가기/앞으로가기 이벤트 리스너 초기화
   */
  private initPopstateListener(): void {
    window.addEventListener('popstate', this.handlePopState);
  }

  /**
   * popstate 이벤트 핸들러 (화살표 함수로 this 바인딩 유지)
   *
   * popstate 는 주소가 이미 바뀐 뒤에 온다. 레이아웃 이탈 가드가 참이고 사용자가 머무르기를 고르면
   * 확정 화면의 주소를 같은 자리에서 곧바로 다시 쌓는다(`pushState`). 화면은 다시 그리지 않는다 (engine-v1.82.0)
   */
  private handlePopState = (): void => {
    const targetIndex = currentHistoryEntryIndex();
    const bypass = consumeArmedLeaveGuardBypass();
    if (!requestLeave(currentUrl(), { bypass, fromPath: this.getCommittedUrl() })) {
      this.restoreBlockedPopState(targetIndex);

      return;
    }

    this.committedUrl = currentUrl();
    this.committedHistoryIndex = targetIndex;
    this.navigateToCurrentPath();
  };

  /**
   * 이탈 가드가 막은 popstate 를 되돌립니다 — 확정 주소를 **동기로** 다시 쌓는다.
   *
   * `history.go(차이)` 로 원래 항목에 돌아가는 방식은 쓰지 않는다. 되돌림이 비동기라 그 사이 주소가 목적지에
   * 머물고, 대기 중인 디바운스 입력의 경로 검사(`window.location.pathname`)가 머무른 화면의 입력을 「다른 경로」로
   * 보고 버린다(입력 손실 — 이 가드가 막으려는 바로 그것). 뒤로 가기를 막은 경우 기록은 [.., 목적지, 확정 화면] 이
   * 되어 다시 뒤로 가면 또 묻는다. 앞으로 가기를 막은 경우 그 뒤의 앞으로 기록은 잃는다.
   *
   * @param _targetIndex popstate 가 내려앉은 항목 위치 (진단용)
   */
  private restoreBlockedPopState(_targetIndex: number | null): void {
    window.history.pushState({}, '', this.getCommittedUrl());
    this.committedHistoryIndex = currentHistoryEntryIndex();
  }

  /**
   * 확정 화면의 주소 — Navigation API 가 있으면 그 항목의 지금 주소(그 뒤 replaceState 로 바뀐 쿼리 포함)를 쓴다.
   *
   * @returns 경로+쿼리+해시
   */
  private getCommittedUrl(): string {
    const index = this.committedHistoryIndex;
    if (index !== null) {
      try {
        const entry = (window as any).navigation?.entries?.()?.[index];
        if (typeof entry?.url === 'string') {
          const url = new URL(entry.url);
          if (url.origin === window.location.origin) {
            return url.pathname + url.search + url.hash;
          }
        }
      } catch {
        // 아래 기록값으로
      }
    }

    return this.committedUrl;
  }

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
   *
   * @param cacheVersion - 확장 캐시 버전. 전달 시 `?v=${version}` 쿼리로 부착되어
   *   백엔드 응답 캐시(PublicTemplateController::getRoutes)의 버전 키를 일치시킵니다.
   *   미전달 시 `v=0`으로 해석되어 구 버전과 TTL 공유 — 확장 라이프사이클 직후
   *   재로드 시에는 반드시 최신 cache_version을 전달해야 합니다.
   */
  async loadRoutes(cacheVersion?: number): Promise<void> {
    try {
      const version = cacheVersion !== undefined && cacheVersion > 0 ? cacheVersion : null;
      const response = await fetch(
        suffixed(`/api/templates/${this.templateIdentifier}/routes`, 'json', version),
      );

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
        logger.log(`Loaded ${this.routes.length} routes${cacheVersion ? ` (v=${cacheVersion})` : ''}`);
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
    const normalizedPath = this.normalizePathname(pathname);

    for (const route of this.routes) {
      const params = this.matchPattern(route.path, normalizedPath);

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
   * URL 경로의 끝 슬래시를 정규화합니다.
   *
   * 브라우저가 `/admin/` 처럼 끝 슬래시가 붙은 경로로 진입해도 끝 슬래시 없는
   * 라우트 패턴(언어 prefix 형태 포함)에 매칭되도록, 루트(`/`)를 제외한 나머지
   * 경로의 끝 슬래시를 제거한다. 라우트 패턴은 끝 슬래시 없는 형태로만 정의되므로
   * (routes.json 규약), 미정규화 시 `/admin/` 이 어떤 라우트에도 매칭되지 않아
   * 404 로 떨어지고 — 미인증 진입 시 로그인 리다이렉트 대신 404 가 노출된다.
   *
   * @param pathname 원본 URL 경로
   * @returns 끝 슬래시가 정규화된 경로 (루트는 `/` 유지)
   * @since engine-v1.54.1
   */
  private normalizePathname(pathname: string): string {
    if (pathname.length > 1 && pathname.endsWith('/')) {
      return pathname.replace(/\/+$/, '') || '/';
    }

    return pathname;
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
      // 새 화면(오류 화면)으로 넘어간다 — 이탈 승인은 여기까지 (engine-v1.82.0)
      endLeaveApproval();
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

      // 인증 확인을 기다리는 동안 더 새 이동이 대기열에 들어왔으면 이 경로는 그리지 않는다 (engine-v1.82.0)
      if (this.isNavigating && this.pendingNavigation !== null) {
        logger.log(`Route ${pathname} superseded while checking auth`);
        return;
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
    try {
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
    } finally {
      // 새 화면이 그려졌다 — 다음 이탈부터 그 화면의 가드로 다시 묻는다 (engine-v1.82.0)
      endLeaveApproval();
    }
  }

  /**
   * 지정된 경로로 이동합니다.
   *
   * 동시에 여러 navigate 호출이 발생할 경우, 마지막 요청만 처리합니다.
   * 이전 navigate가 진행 중이면 `navigationSuperseded` 를 알려 그 화면 처리를 멈추게 하고(engine-v1.82.0),
   * 그 처리가 물러나는 대로 최신 경로로 이동합니다.
   *
   * 지금 화면 레이아웃이 `meta.leave_guard` 를 선언했고 그 식이 참이면 주소를 바꾸기 **전에** 확인을 묻고,
   * 머무르기를 고르면 아무것도 하지 않는다(pushState·routeChange 0). 같은 경로(쿼리·해시만 다름)는 묻지 않는다.
   *
   * @param path - 이동할 경로 (예: '/admin/dashboard')
   * @param options - `bypassLeaveGuard: true` 면 이탈 확인 생략 (engine-v1.82.0)
   * @returns 이동을 시작(또는 대기열에 넣음)했으면 true, 이탈 가드에서 머무르기를 골랐으면 false (engine-v1.82.0 — 종전 void)
   */
  navigate(path: string, options?: RouterNavigateOptions): boolean {
    // 이탈 가드 — 대기열에 넣기 전에도 판정한다 (engine-v1.82.0)
    if (!requestLeave(path, { bypass: options?.bypassLeaveGuard === true })) {
      logger.log(`Navigation to ${path} cancelled by leave guard`);
      return false;
    }

    // 이미 navigate가 진행 중이면 대기열에 추가
    if (this.isNavigating) {
      this.pendingNavigation = path;
      // 진행 중인 이동은 이제 낡았다 — 구독자(TemplateApp)가 그 화면 처리를 멈추게 알린다 (engine-v1.82.0).
      // 종전에는 앞 이동을 끝까지(앞 화면 데이터 응답·렌더·progressive 받기까지) 마친 뒤에야 이 이동을 시작해,
      // 사용자가 이미 떠난 화면(글 수정)이 그려지고 늦은 응답이 들어간 뒤 새 화면(새 글)으로 넘어갔다.
      void this.emit('navigationSuperseded', path);
      return true;
    }

    this.executeNavigation(path);

    return true;
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
      // 같은 레이아웃으로 쿼리만 바뀌는 이동에서도 `:mergeQuery` 링크가 새 주소로 다시 그려지게 알린다 (engine-v1.82.1)
      notifyLocationReplaced();
      this.committedUrl = currentUrl();
      this.committedHistoryIndex = currentHistoryEntryIndex();

      // 라우트 매칭 및 렌더링 (완료까지 대기)
      await this.navigateToCurrentPath();
    } catch (error) {
      // 화면을 바꾸지 못했다 — 이탈 승인을 남겨 두면 지금 화면의 가드가 꺼진 채 남는다 (engine-v1.82.0)
      endLeaveApproval();
      throw error;
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
