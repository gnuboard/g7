import { Router, RouteMatch } from './Router';
import { createLogger } from '../utils/Logger';

const logger = createLogger('RouteResolver');

/**
 * 라우트 변경 콜백 함수 타입
 */
export type RouteChangeCallback = (match: RouteMatch | null) => void;

/**
 * RouteResolver 클래스
 *
 * 브라우저 History API와 통합되어 라우트 변경을 감지하고 처리합니다.
 * popstate 이벤트를 감지하여 브라우저 뒤로가기/앞으로가기를 처리합니다.
 */
export class RouteResolver {
  private router: Router;
  private onRouteChange: RouteChangeCallback | null = null;
  private isInitialized = false;

  constructor(router: Router) {
    this.router = router;
  }

  /**
   * RouteResolver를 초기화합니다.
   *
   * - router.loadRoutes()를 호출하여 라우트 목록을 로드합니다.
   * - popstate 이벤트 리스너를 등록합니다.
   * - 초기 라우트를 해석합니다.
   *
   * @param callback - 라우트 변경 시 호출될 콜백 함수
   */
  async init(callback: RouteChangeCallback): Promise<void> {
    if (this.isInitialized) {
      logger.warn('RouteResolver is already initialized');
      return;
    }

    this.onRouteChange = callback;

    // 라우트 목록 로드
    await this.router.loadRoutes();

    // popstate 이벤트 리스너 등록 (뒤로가기/앞으로가기)
    window.addEventListener('popstate', this.handlePopState);

    this.isInitialized = true;

    // 초기 라우트 해석
    this.resolve();
  }

  /**
   * popstate 이벤트 핸들러
   *
   * 브라우저 뒤로가기/앞으로가기 시 호출됩니다.
   */
  private handlePopState = (): void => {
    this.resolve();
  };

  /**
   * 현재 URL을 router.match()로 매칭하고 onRouteChange 콜백을 실행합니다.
   * 리다이렉트 라우트인 경우 자동으로 리다이렉트합니다.
   */
  resolve(): void {
    const pathname = window.location.pathname;
    const match = this.router.match(pathname);

    // 리다이렉트 라우트인 경우 처리
    if (match && match.route.redirect) {
      logger.log(`Redirecting from ${pathname} to ${match.route.redirect}`);
      this.navigate(match.route.redirect);
      return;
    }

    if (this.onRouteChange) {
      this.onRouteChange(match);
    }
  }

  /**
   * window.history.pushState()를 사용하여 프로그래매틱 네비게이션을 수행합니다.
   *
   * @param path - 이동할 경로
   * @param state - history state 객체 (선택사항)
   */
  navigate(path: string, state?: unknown): void {
    window.history.pushState(state ?? null, '', path);
    this.resolve();
  }

  /**
   * RouteResolver를 정리합니다.
   *
   * - popstate 이벤트 리스너를 제거합니다.
   */
  destroy(): void {
    window.removeEventListener('popstate', this.handlePopState);
    this.onRouteChange = null;
    this.isInitialized = false;
  }
}
