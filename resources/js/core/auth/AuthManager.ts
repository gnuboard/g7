import { getApiClient } from '../api/ApiClient';
import { createLogger } from '../utils/Logger';
import { sanitizeNavPath } from '../support/navPolicy';

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
  /** 로그인 비밀번호 변경 단계 완료 엔드포인트 */
  passwordChangeEndpoint: string;
  /** 로그인 비밀번호 변경 단계 연기 엔드포인트 */
  passwordChangePostponeEndpoint: string;
  /**
   * 로그인 전용 화면 경로(로그인·가입·비밀번호 찾기·재설정 등) — 로그인 이동 시 복귀 경로로 싣지 않는다.
   * `loginPath` 는 이 목록과 무관하게 항상 로그인 전용 화면으로 취급한다.
   *
   * @since engine-v1.78.1
   */
  guestOnlyPaths?: string[];
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
 * 로그인 비밀번호 변경 단계 challenge 정보
 *
 * 비밀번호(와 켜져 있으면 2단계 인증)는 통과했지만 비밀번호가 만료됐거나 관리자가 정해 준
 * 비밀번호라 새 비밀번호를 정해야 로그인이 끝나는 상태다 — 토큰은 아직 발급되지 않았다.
 *
 * @since engine-v1.77.0
 */
export interface PasswordChangeChallenge {
  challengeId: string;
  /** expired: 사용 기간 만료 / admin_assigned: 관리자가 정해 준 비밀번호 */
  reason: 'expired' | 'admin_assigned';
  /** '다음에 변경' 으로 미룰 수 있는지 */
  canPostpone: boolean;
  /** 미루면 다시 안내할 때까지의 일수 (미룰 수 없으면 null) */
  postponeDays: number | null;
  /** 비밀번호가 만료된 시각 (관리자 지정이면 null) */
  expiredAt: string | null;
  /** 이 challenge 가 무효가 되는 시각 */
  expiresAt: string | null;
}

/**
 * 로그인 결과
 *
 * 서버는 보안 환경설정과 회원 상태에 따라 **세 가지 형태의 200** 을 돌려준다. 한 형태만
 * 가정하면 다른 형태에서 토큰·사용자 필드가 없어 화면이 알 수 없는 오류로 멈춘다.
 *
 * @since engine-v1.65.0
 * @since engine-v1.76.0 `extras` 추가
 * @since engine-v1.77.0 `password_change_required` 형태 추가
 */
export type LoginResult =
  | { status: 'authenticated'; user: AuthUser; extras?: LoginExtras }
  | { status: 'two_factor_required'; challenge: TwoFactorChallenge }
  | { status: 'password_change_required'; challenge: PasswordChangeChallenge };

/**
 * 세션을 연 로그인 응답에 서버가 덧붙인 안내 필드 (토큰·사용자 제외)
 *
 * 예: 휴면이 풀린 로그인에만 실리는 `dormancy_restored`, 비밀번호 변경을 미룬 로그인의
 * `postponed_until`. 서버가 실었을 때만 키가 있다 — 레이아웃이 그 키의 존재를 조건으로
 * 안내를 띄우기 때문이다.
 *
 * @since engine-v1.76.0
 */
export type LoginExtras = Record<string, unknown>;

/**
 * 2단계 인증을 마친 결과
 *
 * 인증번호가 맞으면 세션이 열리거나(`authenticated`), 비밀번호가 만료됐거나 관리자가 정해 준
 * 비밀번호면 토큰 없이 비밀번호 변경 단계(`password_change_required`)가 온다.
 *
 * @since engine-v1.76.0
 * @since engine-v1.77.0 `status` 로 가르는 두 형태 — 비밀번호 변경 단계 추가
 */
export type TwoFactorSession = Exclude<LoginResult, { status: 'two_factor_required' }>;

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
 * 로그인·2단계 인증·비밀번호 변경 단계 응답의 data 페이로드
 *
 * 세 형태(토큰 발급 / 2단계 인증 challenge / 비밀번호 변경 challenge)가 같은 자리에
 * 오므로 전부 선택 필드다.
 */
interface LoginResponseData {
  token?: string;
  token_type?: string;
  user?: AuthUser;
  two_factor_required?: boolean;
  password_change_required?: boolean;
  challenge_id?: string;
  provider_id?: string;
  expires_at?: string | null;
  reason?: string;
  can_postpone?: boolean;
  postpone_days?: number | null;
  expired_at?: string | null;
  [key: string]: unknown;
}

/**
 * 세션을 연 응답에서 토큰·사용자·단계 표지를 뺀 나머지 필드를 고릅니다.
 *
 * 토큰은 ApiClient 가 보관한다 — 레이아웃 상태(`response`)로 흘려보내지 않는다.
 *
 * @param data 로그인·2단계 인증·비밀번호 변경 단계 응답의 data
 * @returns 남은 필드 (없으면 undefined — 결과 모양을 종전과 같게 둔다)
 */
function pickLoginExtras(data: LoginResponseData): LoginExtras | undefined {
  const {
    token: _token,
    token_type: _tokenType,
    user: _user,
    two_factor_required: _twoFactor,
    password_change_required: _passwordChange,
    ...rest
  } = data;

  return Object.keys(rest).length > 0 ? rest : undefined;
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
    passwordChangeEndpoint: '/auth/admin/login/password-change',
    passwordChangePostponeEndpoint: '/auth/admin/login/password-change/postpone',
    guestOnlyPaths: ['/admin/login', '/admin/forgot-password', '/admin/reset-password'],
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
    passwordChangeEndpoint: '/auth/login/password-change',
    passwordChangePostponeEndpoint: '/auth/login/password-change/postpone',
    guestOnlyPaths: ['/login', '/register', '/forgot-password', '/reset-password'],
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
   *
   * 해제 함수를 돌려줍니다. 확장·템플릿은 엔진보다 늦게 실리고 화면을 오가며 구독을 걸었다 풀므로,
   * 해제 수단이 없으면 화면을 떠나도 리스너가 남습니다(#639).
   *
   * 최초 인증 확인(`preloadAuth`)은 확장 번들이 실리기 전에 끝나 그 이벤트를 놓칠 수 있습니다 —
   * 현재 상태는 {@link getState} 로 읽습니다.
   *
   * @param event 이벤트 이름 (`authStateChange`·`login`·`logout`·`tokenRefreshed`)
   * @param handler 핸들러
   * @returns 해제 함수
   */
  on(event: string, handler: EventHandler): () => void {
    if (!this.eventHandlers.has(event)) {
      this.eventHandlers.set(event, []);
    }
    this.eventHandlers.get(event)!.push(handler);

    return () => this.off(event, handler);
  }

  /**
   * 이벤트 핸들러 해제
   *
   * @param event 이벤트 이름
   * @param handler 등록했던 핸들러 (같은 참조)
   * @since engine-v1.78.0
   */
  off(event: string, handler: EventHandler): void {
    const handlers = this.eventHandlers.get(event);
    if (!handlers) return;

    const index = handlers.indexOf(handler);
    if (index >= 0) {
      handlers.splice(index, 1);
    }
  }

  /**
   * 이벤트 발생
   *
   * 핸들러 목록의 사본을 돈다 — 핸들러 안에서 자기 자신을 해제해도 다음 핸들러를 건너뛰지 않는다.
   */
  private emit(event: string, ...args: any[]): void {
    const handlers = this.eventHandlers.get(event);
    if (handlers) {
      [...handlers].forEach(handler => handler(...args));
    }
  }

  /**
   * 현재 인증 상태의 사본을 반환합니다.
   *
   * 늦게 실린 구독자가 최초 상태를 이벤트 없이 알 수 있게 한다. `user` 도 얕은 사본이다(바꿔도 내부 상태는 그대로).
   *
   * @returns 인증 상태 사본
   * @since engine-v1.78.0
   */
  getState(): AuthState {
    return { ...this.state, user: this.state.user ? { ...this.state.user } : null };
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

      const result = response.success && response.data
        ? this.resolveLoginPayload(type, response.data, true)
        : null;

      if (result) {
        return result;
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
   * 서버가 덧붙인 안내 필드나 비밀번호 변경 단계가 필요하면 {@link completeTwoFactorSession} 을 쓴다.
   * 인증번호가 맞아도 비밀번호가 만료됐거나 관리자가 정해 준 비밀번호면 서버는 토큰 대신
   * 비밀번호 변경 단계를 돌려주는데, 이때는 세션이 열리지 않으므로 이 메서드는 오류를 던진다.
   *
   * @param type - 인증 타입
   * @param payload - challenge 식별자와 사용자가 받은 인증번호
   * @param options - 추가 옵션 (headers 등)
   * @returns 인증된 사용자
   * @throws Error 비밀번호 변경 단계가 온 경우(세션 미개설)
   * @since engine-v1.65.0
   */
  async completeTwoFactor(
    type: AuthType,
    payload: { challengeId: string; code: string },
    options?: { headers?: Record<string, string> }
  ): Promise<AuthUser> {
    const session = await this.completeTwoFactorSession(type, payload, options);

    if (session.status === 'authenticated') {
      return session.user;
    }

    // 공개 계약(사용자 반환)을 지키느라 변경 단계를 담을 자리가 없다 — 세션 없이 사용자를 돌려주지 않는다.
    throw new Error('Password change required');
  }

  /**
   * 2단계 인증 코드를 확인하고 로그인을 완료한 결과를 돌려줍니다.
   *
   * {@link completeTwoFactor} 의 반환(사용자만)은 공개 계약이라 그대로 두고, 서버가 덧붙인 안내
   * 필드(휴면 해제 등)나 비밀번호 변경 단계를 함께 받아야 하는 호출자 — `loginTwoFactor` 핸들러 — 가
   * 이 메서드를 쓴다.
   *
   * @param type - 인증 타입
   * @param payload - challenge 식별자와 사용자가 받은 인증번호
   * @param options - 추가 옵션 (headers 등)
   * @returns 인증 완료(사용자·서버 안내 필드) 또는 비밀번호 변경 단계 요구
   * @since engine-v1.76.0
   * @since engine-v1.77.0 비밀번호 변경 단계 형태를 돌려줄 수 있습니다.
   */
  async completeTwoFactorSession(
    type: AuthType,
    payload: { challengeId: string; code: string },
    options?: { headers?: Record<string, string> }
  ): Promise<TwoFactorSession> {
    return this.postLoginStep(
      type,
      (config) => config.twoFactorEndpoint,
      { challenge_id: payload.challengeId, code: payload.code },
      options
    );
  }

  /**
   * 로그인 비밀번호 변경 단계에서 새 비밀번호를 정하고 로그인을 완료합니다.
   *
   * 서버는 변경에 성공하면 모든 기기를 로그아웃시키고 새 토큰을 하나 발급한다.
   * 규칙 위반·현재 비밀번호와 같은 값·재사용은 422(`errors.password`), challenge
   * 만료·무효는 422(`errors.challenge_id`) — 후자는 처음부터 다시 로그인해야 한다.
   *
   * @param type - 인증 타입
   * @param payload - challenge 식별자와 새 비밀번호(확인 포함)
   * @param options - 추가 옵션 (headers 등)
   * @returns 인증 완료 결과
   * @since engine-v1.77.0
   */
  async completePasswordChange(
    type: AuthType,
    payload: { challengeId: string; password: string; passwordConfirmation: string },
    options?: { headers?: Record<string, string> }
  ): Promise<LoginResult> {
    return this.postLoginStep(
      type,
      (config) => config.passwordChangeEndpoint,
      {
        challenge_id: payload.challengeId,
        password: payload.password,
        password_confirmation: payload.passwordConfirmation,
      },
      options
    );
  }

  /**
   * 로그인 비밀번호 변경 단계를 미루고('다음에 변경') 로그인을 완료합니다.
   *
   * 만료 시 동작이 '다음에 변경 허용' 일 때만 서버가 받아 준다(`can_postpone`).
   *
   * @param type - 인증 타입
   * @param payload - challenge 식별자
   * @param options - 추가 옵션 (headers 등)
   * @returns 인증 완료 결과 (`extras.postponed_until` 포함)
   * @since engine-v1.77.0
   */
  async postponePasswordChange(
    type: AuthType,
    payload: { challengeId: string },
    options?: { headers?: Record<string, string> }
  ): Promise<LoginResult> {
    return this.postLoginStep(
      type,
      (config) => config.passwordChangePostponeEndpoint,
      { challenge_id: payload.challengeId },
      options
    );
  }

  /**
   * 로그인 다음 단계(2단계 인증 확인·비밀번호 변경·연기) 요청을 보내고 결과를 해석합니다.
   *
   * 세 단계가 같은 응답 해석·실패 처리를 공유하도록 단일 지점에 둔다.
   *
   * @param type - 인증 타입
   * @param endpointOf - 설정에서 엔드포인트를 고르는 함수
   * @param body - 요청 본문
   * @param options - 추가 옵션 (headers 등)
   * @returns 로그인 결과 (2단계 인증 형태 제외)
   */
  private async postLoginStep(
    type: AuthType,
    endpointOf: (config: AuthConfig) => string,
    body: Record<string, unknown>,
    options?: { headers?: Record<string, string> }
  ): Promise<TwoFactorSession> {
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
      }>(endpointOf(config), body, requestConfig);

      // 다음 단계 응답에서 2단계 인증 challenge 가 다시 오는 경로는 없다 — 그 형태는 받지 않는다.
      const result = response.success && response.data
        ? this.resolveLoginPayload(type, response.data, false)
        : null;

      if (result && result.status !== 'two_factor_required') {
        return result;
      }

      throw new Error('Login failed');
    } catch (error: any) {
      this.clearState();

      throw this.enhanceAuthError(error, type, 'Login failed');
    }
  }

  /**
   * 로그인 계열 응답의 data 를 `LoginResult` 로 해석합니다.
   *
   * challenge 형태에는 토큰이 없으므로 상태를 건드리지 않는다. 토큰과 사용자가 **모두**
   * 있을 때만 세션을 연다 — 한쪽만 보고 진행하면 undefined 토큰이 저장되어 이후 모든
   * 요청이 401 이 된다.
   *
   * @param type - 인증 타입
   * @param data - 응답 data
   * @param allowTwoFactor - 2단계 인증 challenge 형태를 받을지 여부
   * @returns 해석된 결과 (알 수 없는 형태면 null)
   */
  private resolveLoginPayload(
    type: AuthType,
    data: LoginResponseData,
    allowTwoFactor: boolean
  ): LoginResult | null {
    if (allowTwoFactor && data.two_factor_required === true) {
      trackAuthEvent('login', true, undefined, { type, two_factor: true });

      return {
        status: 'two_factor_required',
        challenge: {
          challengeId: String(data.challenge_id ?? ''),
          providerId: String(data.provider_id ?? ''),
          expiresAt: data.expires_at ?? null,
        },
      };
    }

    if (data.password_change_required === true) {
      trackAuthEvent('login', true, undefined, { type, password_change: true, reason: data.reason });

      return {
        status: 'password_change_required',
        challenge: {
          challengeId: String(data.challenge_id ?? ''),
          reason: data.reason === 'admin_assigned' ? 'admin_assigned' : 'expired',
          canPostpone: data.can_postpone === true,
          postponeDays: typeof data.postpone_days === 'number' ? data.postpone_days : null,
          expiredAt: data.expired_at ?? null,
          expiresAt: data.expires_at ?? null,
        },
      };
    }

    if (data.token && data.user) {
      const user = this.establishSession(type, data.token, data.user);
      // 서버가 덧붙인 안내(휴면 해제·변경 연기 기한 등)를 버리면 화면이 그 사실을 알 길이 없다 (engine-v1.76.0).
      const extras = pickLoginExtras(data);

      return extras ? { status: 'authenticated', user, extras } : { status: 'authenticated', user };
    }

    return null;
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

      // 상태 초기화 — 경계 발행은 아래 명시 발행이 맡는다(이중 발행 방지)
      const previousState = { ...this.state };
      this.clearState(false);

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

    // 로그인 전용 화면(로그인·가입·비밀번호 찾기·재설정)은 복귀 경로로 싣지 않는다 — 세션이 끊긴 채
    // 로그인 화면을 열면 데이터소스 401 이 로그인 이동을 일으키는데, 그 화면 자신을 실으면 로그인을
    // 마친 뒤 다시 로그인 폼에 떨어진다. 로그인 화면이 이미 복귀 경로를 갖고 있으면 그것을 이어 싣는다.
    const effectiveReturnUrl = this.resolveReturnUrl(config, returnUrl);

    const params: string[] = [];
    if (effectiveReturnUrl) {
      params.push(`redirect=${encodeURIComponent(effectiveReturnUrl)}`);
    }
    if (reason) {
      params.push(`reason=${encodeURIComponent(reason)}`);
    }
    return params.length > 0 ? `${config.loginPath}?${params.join('&')}` : config.loginPath;
  }

  /**
   * 로그인 이동에 실을 복귀 경로를 정합니다.
   *
   * @param config - 인증 설정
   * @param returnUrl - 현재 경로(pathname + search)
   * @returns 실을 복귀 경로, 싣지 않으면 null
   */
  private resolveReturnUrl(config: AuthConfig, returnUrl: string): string | null {
    if (!returnUrl) {
      return null;
    }

    const queryIndex = returnUrl.search(/[?#]/);
    const path = queryIndex === -1 ? returnUrl : returnUrl.slice(0, queryIndex);
    if (!this.isGuestOnlyPath(config, path)) {
      return returnUrl;
    }

    // 로그인 화면이 이미 복귀 경로를 갖고 있으면(`/login?redirect=/mypage`) 그 경로를 이어 싣는다.
    if (this.normalizePath(path) === this.normalizePath(config.loginPath) && queryIndex !== -1) {
      const search = returnUrl.slice(queryIndex).split('#')[0];
      const inner = new URLSearchParams(search.startsWith('?') ? search.slice(1) : '').get('redirect');
      if (inner) {
        const innerPath = inner.split(/[?#]/)[0];
        if (!this.isGuestOnlyPath(config, innerPath)) {
          return inner;
        }
      }
    }

    return null;
  }

  /**
   * 로그인 전용 화면 경로인지 판정합니다(로그인 경로 + 설정의 guestOnlyPaths, 끝 슬래시 무시).
   *
   * @param config - 인증 설정
   * @param path - 판정할 경로(쿼리 제외)
   * @returns 로그인 전용 화면이면 true
   */
  private isGuestOnlyPath(config: AuthConfig, path: string): boolean {
    const target = this.normalizePath(path);
    const candidates = [config.loginPath, ...(config.guestOnlyPaths ?? [])];
    return candidates.some((candidate) => this.normalizePath(candidate) === target);
  }

  /**
   * 경로 비교용 정규화 — 끝 슬래시를 떼고 소문자로 맞춘다(루트 `/` 는 유지).
   *
   * @param path - 경로
   * @returns 정규화한 경로
   */
  private normalizePath(path: string): string {
    const trimmed = path.length > 1 ? path.replace(/\/+$/, '') : path;
    return (trimmed || '/').toLowerCase();
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
      // 보안: 같은 도메인 경로만 허용. `startsWith('/')` 만으로는 `//evil.com`·`/\evil.com` 이
      // 통과해 오프사이트로 나가므로 sink 와 같은 판정(navPolicy)을 쓴다.
      try {
        const decoded = decodeURIComponent(redirectUrl);
        const safe = sanitizeNavPath(decoded);
        if (safe !== null) {
          return safe;
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
   *
   * 인증 상태에서 비인증으로 바뀔 때는 `authStateChange` 를 발행한다 — 토큰이 사라지거나 인증 확인이
   * 실패해 비인증이 된 사실을 알리지 않으면, 인증 상태에 묶인 캐시(동의 스냅샷 등)를 비울 기회가 없다(#639).
   * 비인증 → 비인증은 발행하지 않는다.
   *
   * @param emit 경계 발행 여부 (logout 처럼 호출자가 직접 발행하면 false)
   */
  private clearState(emit: boolean = true): void {
    const wasAuthenticated = this.state.isAuthenticated;

    this.state = {
      isAuthenticated: false,
      user: null,
      type: null,
    };

    if (emit && wasAuthenticated) {
      // 이 경로는 인증 확인·로그인 실패 처리 안에서 돈다 — 구독자 예외가 원래 결과(false·인증 오류)를 덮지 않게 격리한다.
      try {
        this.emit('authStateChange', this.state);
      } catch (error) {
        logger.error('authStateChange 구독자 오류 (비인증 전환)', error);
      }
    }
  }

  /**
   * 테스트용 인스턴스 초기화
   */
  static resetInstance(): void {
    AuthManager.instance = null as any;
  }
}

export default AuthManager;
