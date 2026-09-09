import { getApiClient } from '../api/ApiClient';
import { createLogger } from '../utils/Logger';

const logger = createLogger('AuthManager');

/**
 * DevTools 추적 헬퍼
 */
function trackAuthEvent(
  type: 'login' | 'logout' | 'token-refresh' | 'token-expired' | 'session-restored' | 'permission-denied' | 'api-unauthorized',
  success: boolean,
  error?: string,
  details?: Record<string, any>
): void {
  try {
    const G7Core = (window as any).G7Core;
    G7Core?.devTools?.trackAuthEvent?.(type, success, error, details);
  } catch {
    // DevTools 추적 실패 무시
  }
}

/**
 * 인증 타입
 */
export type AuthType = 'admin' | 'user';

/**
 * 인증 설정 인터페이스
 */
export interface AuthConfig {
  type: AuthType;
  loginPath: string;
  defaultPath: string;
  userEndpoint: string;
  loginEndpoint: string;
  logoutEndpoint: string;
  refreshEndpoint: string;
  /** 2단계 인증 코드 확인 엔드포인트 */
  twoFactorEndpoint: string;
  /** 2단계 인증 코드 재발송 엔드포인트 */
  twoFactorResendEndpoint: string;
}

/**
 * 2단계 인증 challenge 정보
 *
 * 비밀번호 확인만 통과한 상태다 — 토큰은 아직 발급되지 않았고, 코드 확인에 성공해야
 * 세션이 열린다.
 *
 * @since engine-v1.65.0
 */
export interface TwoFactorChallenge {
  challengeId: string;
  providerId: string;
  expiresAt: string | null;
}

/**
 * 로그인 결과
 *
 * 서버는 보안 환경설정에 따라 **두 가지 형태의 200** 을 돌려준다. 한 형태만 가정하면
 * 다른 형태에서 토큰·사용자 필드가 없어 화면이 알 수 없는 오류로 멈춘다.
 *
 * @since engine-v1.65.0
 */
export type LoginResult =
  | { status: 'authenticated'; user: AuthUser }
  | { status: 'two_factor_required'; challenge: TwoFactorChallenge };

/**
 * 인증된 사용자 정보
 */
export interface AuthUser {
  uuid: string;
  name: string;
  email: string;
  [key: string]: any;
}

/**
 * 인증 상태
 */
export interface AuthState {
  isAuthenticated: boolean;
  user: AuthUser | null;
  type: AuthType | null;
}

/**
 * 이벤트 핸들러 타입
 */
type EventHandler = (...args: any[]) => void;

/**
 * 로그인·2단계 인증 응답의 data 페이로드
 *
 * 두 형태(토큰 발급 / challenge 발급)가 같은 자리에 오므로 전부 선택 필드다.
 */
interface LoginResponseData {
  token?: string;
  user?: AuthUser;
  two_factor_required?: boolean;
  challenge_id?: string;
  provider_id?: string;
  expires_at?: string | null;
}

/**
 * 기본 인증 설정
 */
const defaultConfigs: Record<AuthType, AuthConfig> = {
  admin: {
    type: 'admin',
    loginPath: '/admin/login',
    defaultPath: '/admin',
    userEndpoint: '/admin/auth/user',
    loginEndpoint: '/auth/admin/login',
    logoutEndpoint: '/admin/auth/logout',
    refreshEndpoint: '/admin/auth/refresh',
    twoFactorEndpoint: '/auth/admin/login/two-factor',
    twoFactorResendEndpoint: '/auth/admin/login/two-factor/resend',
  },
  user: {
    type: 'user',
    loginPath: '/login',
    defaultPath: '/',
    userEndpoint: '/auth/user',
    loginEndpoint: '/auth/login',
    logoutEndpoint: '/auth/logout',
    refreshEndpoint: '/auth/refresh',
    twoFactorEndpoint: '/auth/login/two-factor',
    twoFactorResendEndpoint: '/auth/login/two-factor/resend',
  },
};

/**
 * AuthManager 클래스
 *
 * 인증 상태 관리 및 토큰 갱신을 담당하는 싱글톤 클래스입니다.
 */
export class AuthManager {
  private static instance: AuthManager;
  private state: AuthState;
  private config: Map<AuthType, AuthConfig>;
  private isRefreshing: boolean = false;
  private refreshPromise: Promise<boolean> | null = null;
  private eventHandlers: Map<string, EventHandler[]> = new Map();

  /** 로케일 스토리지 키 (TemplateApp과 동일) */
  private static readonly LOCALE_STORAGE_KEY = 'g7_locale';

  private constructor() {
    this.state = {
      isAuthenticated: false,
      user: null,
      type: null,
    };

    this.config = new Map();
    this.config.set('admin', defaultConfigs.admin);
    this.config.set('user', defaultConfigs.user);
  }

  /**
   * 싱글톤 인스턴스 반환
   */
  static getInstance(): AuthManager {
    if (!AuthManager.instance) {
      AuthManager.instance = new AuthManager();
    }
    return AuthManager.instance;
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
   * 이벤트 발생
   */
  private emit(event: string, ...args: any[]): void {
    const handlers = this.eventHandlers.get(event);
    if (handlers) {
      handlers.forEach(handler => handler(...args));
    }
  }

  /**
   * 인증 상태 확인
   */
  isAuthenticated(): boolean {
    return this.state.isAuthenticated;
  }

  /**
   * 현재 사용자 정보 반환
   */
  getUser(): AuthUser | null {
    return this.state.user;
  }

  /**
   * 현재 인증 타입 반환
   */
  getAuthType(): AuthType | null {
    return this.state.type;
  }

  /**
   * 백엔드 API로 인증 상태 확인
   *
   * @param type - 인증 타입 (admin 또는 user)
   * @returns 인증 여부
   */
  async checkAuth(type: AuthType): Promise<boolean> {
    const apiClient = getApiClient();
    const token = apiClient.getToken();

    // 토큰이 없으면 미인증
    if (!token) {
      this.clearState();
      return false;
    }

    const config = this.config.get(type);
    if (!config) {
      logger.error(`Unknown auth type: ${type}`);
      return false;
    }

    try {
      // 사용자 정보 조회 API 호출
      const response = await apiClient.get<{ success: boolean; data: AuthUser }>(config.userEndpoint);

      if (response.success && response.data) {
        this.state = {
          isAuthenticated: true,
          user: response.data,
          type: type,
        };
        this.emit('authStateChange', this.state);
        return true;
      }

      this.clearState();
      return false;
    } catch (error: any) {
      // 401 에러인 경우 토큰 갱신 시도
      if (error.response?.status === 401) {
        const refreshed = await this.refreshToken();
        if (refreshed) {
          // 갱신 성공 후 다시 인증 확인
          return this.checkAuth(type);
        }
      }

      this.clearState();
      return false;
    }
  }

  /**
   * 사용자 정보 프리로드 (병렬 로딩용)
   *
   * checkAuth와 동일하지만 에러를 throw하지 않고 조용히 실패합니다.
   *
   * @param type - 인증 타입 (admin 또는 user)
   * @returns 인증 여부
   */
  async preloadAuth(type: AuthType): Promise<boolean> {
    try {
      return await this.checkAuth(type);
    } catch (error) {
      logger.warn(`Preload auth failed for type ${type}:`, error);
      return false;
    }
  }

  /**
   * 로그인 처리
   *
   * 서버는 두 가지 형태의 200 을 돌려준다 — 토큰과 사용자가 실린 정상 응답, 그리고
   * 2단계 인증이 켜져 있을 때의 challenge 응답이다. 후자에는 토큰도 사용자도 없으므로
   * 세션을 열지 않고 challenge 만 돌려준다.
   *
   * @param type - 인증 타입
   * @param credentials - 로그인 자격 증명
   * @param options - 추가 옵션 (headers 등)
   * @returns 인증 완료 또는 2단계 인증 요구
   * @since engine-v1.65.0 반환 타입이 `AuthUser` 에서 `LoginResult` 로 바뀌었습니다.
   */
  async login(
    type: AuthType,
    credentials: { email: string; password: string },
    options?: { headers?: Record<string, string> }
  ): Promise<LoginResult> {
    const apiClient = getApiClient();
    const config = this.config.get(type);

    if (!config) {
      throw new Error(`Unknown auth type: ${type}`);
    }

    try {
      // 추가 헤더 설정 (globalHeaders 지원)
      const requestConfig = options?.headers ? { headers: options.headers } : undefined;

      const response = await apiClient.post<{
        success: boolean;
        data: LoginResponseData;
      }>(config.loginEndpoint, credentials, requestConfig);

      if (response.success && response.data) {
        // 2단계 인증 요구 — 토큰이 없으므로 상태를 건드리지 않는다.
        if (response.data.two_factor_required === true) {
          trackAuthEvent('login', true, undefined, { type, two_factor: true });

          return {
            status: 'two_factor_required',
            challenge: {
              challengeId: String(response.data.challenge_id ?? ''),
              providerId: String(response.data.provider_id ?? ''),
              expiresAt: response.data.expires_at ?? null,
            },
          };
        }

        // 두 필드가 모두 있을 때만 세션을 연다 — 한쪽만 보고 진행하면 undefined 토큰이
        // 저장되어 이후 모든 요청이 401 이 된다.
        if (response.data.token && response.data.user) {
          return {
            status: 'authenticated',
            user: this.establishSession(type, response.data.token, response.data.user),
          };
        }
      }

      throw new Error('Login failed');
    } catch (error: any) {
      this.clearState();

      throw this.enhanceAuthError(error, type, 'Login failed');
    }
  }

  /**
   * 2단계 인증 코드를 확인하고 로그인을 완료합니다.
   *
   * @param type - 인증 타입
   * @param payload - challenge 식별자와 사용자가 받은 인증번호
   * @param options - 추가 옵션 (headers 등)
   * @returns 인증된 사용자 정보
   * @since engine-v1.65.0
   */
  async completeTwoFactor(
    type: AuthType,
    payload: { challengeId: string; code: string },
    options?: { headers?: Record<string, string> }
  ): Promise<AuthUser> {
    const apiClient = getApiClient();
    const config = this.config.get(type);

    if (!config) {
      throw new Error(`Unknown auth type: ${type}`);
    }

    try {
      const requestConfig = options?.headers ? { headers: options.headers } : undefined;

      const response = await apiClient.post<{
        success: boolean;
        data: LoginResponseData;
      }>(
        config.twoFactorEndpoint,
        { challenge_id: payload.challengeId, code: payload.code },
        requestConfig
      );

      if (response.success && response.data?.token && response.data?.user) {
        return this.establishSession(type, response.data.token, response.data.user);
      }

      throw new Error('Login failed');
    } catch (error: any) {
      this.clearState();

      throw this.enhanceAuthError(error, type, 'Login failed');
    }
  }

  /**
   * 2단계 인증 코드를 재발송합니다.
   *
   * 서버가 기존 challenge 를 취소하고 새로 발행하므로, 앞서 받은 인증번호는 더 이상
   * 통하지 않습니다 — 호출자는 반드시 새 challenge 로 교체해야 합니다.
   *
   * @param type - 인증 타입
   * @param payload - 재발송할 challenge 식별자
   * @param options - 추가 옵션 (headers 등)
   * @returns 새 challenge 정보
   * @since engine-v1.65.0
   */
  async resendTwoFactor(
    type: AuthType,
    payload: { challengeId: string },
    options?: { headers?: Record<string, string> }
  ): Promise<TwoFactorChallenge> {
    const apiClient = getApiClient();
    const config = this.config.get(type);

    if (!config) {
      throw new Error(`Unknown auth type: ${type}`);
    }

    try {
      const requestConfig = options?.headers ? { headers: options.headers } : undefined;

      const response = await apiClient.post<{
        success: boolean;
        data: LoginResponseData;
      }>(
        config.twoFactorResendEndpoint,
        { challenge_id: payload.challengeId },
        requestConfig
      );

      if (response.success && response.data?.challenge_id) {
        return {
          challengeId: String(response.data.challenge_id),
          providerId: String(response.data.provider_id ?? ''),
          expiresAt: response.data.expires_at ?? null,
        };
      }

      throw new Error('Resend failed');
    } catch (error: any) {
      // 재발송 실패는 세션 상태와 무관하다 — 이미 열린 세션이 없으므로 상태를 지우지 않는다.
      throw this.enhanceAuthError(error, type, 'Resend failed');
    }
  }

  /**
   * 토큰을 저장하고 인증 상태·로케일을 확정합니다.
   *
   * 정상 로그인과 2단계 인증 완료가 같은 후처리를 공유하도록 단일 지점에 둔다 —
   * 갈라지면 한쪽 경로에서만 로케일 전환이나 이벤트 발행이 빠진다.
   *
   * @param type - 인증 타입
   * @param token - 발급된 토큰
   * @param user - 인증된 사용자
   * @returns 인증된 사용자 정보
   */
  private establishSession(type: AuthType, token: string, user: AuthUser): AuthUser {
    const apiClient = getApiClient();

    // 토큰 저장
    apiClient.setToken(token);

    // 사용자의 language 설정 확인 및 로케일 변경 처리
    const userLanguage = user.language;
    const currentLocale = localStorage.getItem(AuthManager.LOCALE_STORAGE_KEY);
    const localeChanged = userLanguage && userLanguage !== currentLocale;

    if (userLanguage) {
      try {
        localStorage.setItem(AuthManager.LOCALE_STORAGE_KEY, userLanguage);
      } catch (error) {
        logger.warn('Failed to save user language to localStorage:', error);
      }
    }

    // 상태 업데이트
    this.state = {
      isAuthenticated: true,
      user,
      type,
    };

    this.emit('login', this.state);
    this.emit('authStateChange', this.state);

    // DevTools 추적
    trackAuthEvent('login', true, undefined, {
      userId: user.uuid,
      email: user.email,
      type,
    });

    // 로케일이 변경된 경우 TemplateApp 재초기화
    if (localeChanged && (window as any).__templateApp) {
      // changeLocale은 비동기이므로 await 하지 않고 실행
      // navigate가 먼저 실행되고, changeLocale이 완료되면 UI가 업데이트됨
      (window as any).__templateApp.changeLocale(userLanguage);
    }

    return user;
  }

  /**
   * 인증 실패 오류에 서버 응답 정보를 실어 다시 던질 오류를 만듭니다.
   *
   * `code` 를 보존해야 호출자가 네트워크 실패(`ERR_NETWORK` 등)와 HTTP 오류를 구분해
   * 다국어 문구로 안내할 수 있다 — axios 오류는 `TypeError` 가 아니다.
   *
   * @param error - 원본 오류
   * @param type - 인증 타입
   * @param fallbackMessage - 서버 메시지가 없을 때 쓸 기본 문구
   * @returns 보강된 오류
   */
  private enhanceAuthError(error: any, type: AuthType, fallbackMessage: string): any {
    // Axios 에러에서 API 응답 메시지 추출
    // error.response.data.message가 실제 서버 응답 메시지
    const apiMessage = error?.response?.data?.message;
    const enhancedError: any = new Error(apiMessage || error?.message || fallbackMessage);
    enhancedError.response = error?.response;
    enhancedError.status = error?.response?.status;
    enhancedError.code = error?.code;

    // DevTools 추적
    trackAuthEvent('login', false, enhancedError.message, {
      type,
      status: error?.response?.status,
    });

    return enhancedError;
  }

  /**
   * 로그아웃 처리
   */
  async logout(): Promise<void> {
    const apiClient = getApiClient();
    const config = this.state.type ? this.config.get(this.state.type) : null;

    try {
      // 백엔드 로그아웃 API 호출
      if (config) {
        await apiClient.post(config.logoutEndpoint);
      }
    } catch (error) {
      logger.warn('Logout API call failed:', error);
    } finally {
      // 토큰 삭제
      apiClient.removeToken();

      // 상태 초기화
      const previousState = { ...this.state };
      this.clearState();

      this.emit('logout', previousState);
      this.emit('authStateChange', this.state);

      // DevTools 추적
      trackAuthEvent('logout', true, undefined, {
        previousType: previousState.type,
      });

      // 로그인 페이지로 리다이렉트 (queryString 포함)
      if (config && previousState.type) {
        const returnUrl = window.location.pathname + window.location.search;
        window.location.href = this.getLoginRedirectUrl(previousState.type, returnUrl);
      }
    }
  }

  /**
   * 토큰 갱신
   *
   * @returns 갱신 성공 여부
   */
  async refreshToken(): Promise<boolean> {
    // 이미 갱신 중이면 기존 Promise 반환
    if (this.isRefreshing && this.refreshPromise) {
      return this.refreshPromise;
    }

    this.isRefreshing = true;
    this.refreshPromise = this.doRefreshToken();

    try {
      return await this.refreshPromise;
    } finally {
      this.isRefreshing = false;
      this.refreshPromise = null;
    }
  }

  /**
   * 실제 토큰 갱신 수행
   */
  private async doRefreshToken(): Promise<boolean> {
    const authType = this.state.type;
    if (!authType) return false;

    const config = this.config.get(authType);
    if (!config) return false;

    const apiClient = getApiClient();

    try {
      const response = await apiClient.post<{
        success: boolean;
        data: {
          token: string;
        };
      }>(config.refreshEndpoint);

      if (response.success && response.data?.token) {
        apiClient.setToken(response.data.token);
        this.emit('tokenRefreshed');

        // DevTools 추적
        trackAuthEvent('token-refresh', true, undefined, { type: authType });

        return true;
      }

      // DevTools 추적 (실패 - 응답은 있지만 토큰 없음)
      trackAuthEvent('token-refresh', false, 'No token in response', { type: authType });

      return false;
    } catch (error) {
      logger.error('Token refresh failed:', error);

      // DevTools 추적 (실패 - 예외)
      trackAuthEvent('token-refresh', false, error instanceof Error ? error.message : 'Unknown error', {
        type: authType,
      });

      return false;
    }
  }

  /**
   * 보호된 라우트 접근 시 로그인 리다이렉트 URL 생성
   *
   * @param type - 인증 타입
   * @param returnUrl - 로그인 후 돌아갈 URL
   * @param reason - 리다이렉트 사유 (예: 'session_expired') — 로그인 페이지 안내 분기용
   * @returns 로그인 페이지 URL (redirect 파라미터 포함)
   */
  getLoginRedirectUrl(type: AuthType, returnUrl: string, reason?: string): string {
    const config = this.config.get(type);
    if (!config) {
      return '/login';
    }

    const encodedReturnUrl = encodeURIComponent(returnUrl);
    let url = `${config.loginPath}?redirect=${encodedReturnUrl}`;
    if (reason) {
      url += `&reason=${encodeURIComponent(reason)}`;
    }
    return url;
  }

  /**
   * 인증 설정 부분 갱신 (템플릿 부트스트랩에서 호출)
   *
   * 템플릿이 자체 로그인 경로를 사용하는 경우 `initTemplate` 단계에서 호출하여
   * 코어 디폴트(`/login`, `/admin/login`)를 오버라이드한다. 모듈/플러그인에서
   * 호출하면 다른 템플릿에 침범하므로 금지 — 가이드 문서 참조.
   *
   * 보안: `loginPath` 는 동일 origin path-only(`/`로 시작, `//` 금지)만 허용한다.
   * 외부 origin URL 또는 protocol-relative URL 을 허용하면 open redirect 취약점이 된다.
   *
   * @param type - 인증 타입
   * @param partial - 갱신할 설정 (loginPath, defaultPath 등)
   * @throws Error loginPath 가 path-only 형식이 아닐 때
   */
  updateConfig(type: AuthType, partial: Partial<AuthConfig>): void {
    if (partial.loginPath !== undefined) {
      const path = partial.loginPath;
      if (!path.startsWith('/') || path.startsWith('//')) {
        throw new Error(
          `AuthManager.updateConfig: loginPath must be a same-origin path starting with '/' (got: ${path})`
        );
      }
    }
    const current = this.config.get(type);
    if (current) {
      this.config.set(type, { ...current, ...partial });
    }
  }

  /**
   * 로그인 후 리다이렉트 URL 가져오기
   *
   * URL 파라미터에서 redirect 값을 읽어 반환합니다.
   *
   * @param type - 인증 타입
   * @returns 리다이렉트할 URL
   */
  getRedirectUrl(type: AuthType): string {
    const config = this.config.get(type);
    const defaultPath = config?.defaultPath || '/';

    // URL 파라미터에서 redirect 값 읽기
    const urlParams = new URLSearchParams(window.location.search);
    const redirectUrl = urlParams.get('redirect');

    if (redirectUrl) {
      // 보안: 같은 도메인 경로만 허용
      try {
        const decoded = decodeURIComponent(redirectUrl);
        if (decoded.startsWith('/')) {
          return decoded;
        }
      } catch {
        // 디코딩 실패 시 기본 경로 반환
      }
    }

    return defaultPath;
  }

  /**
   * 인증 설정 조회
   */
  getConfig(type: AuthType): AuthConfig | undefined {
    return this.config.get(type);
  }

  /**
   * 상태 초기화
   */
  private clearState(): void {
    this.state = {
      isAuthenticated: false,
      user: null,
      type: null,
    };
  }

  /**
   * 테스트용 인스턴스 초기화
   */
  static resetInstance(): void {
    AuthManager.instance = null as any;
  }
}

export default AuthManager;
