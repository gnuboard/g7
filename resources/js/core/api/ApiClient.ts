import axios, { AxiosInstance, AxiosRequestConfig, AxiosResponse, InternalAxiosRequestConfig } from 'axios';
import { AuthManager } from '../auth/AuthManager';
import { createLogger } from '../utils/Logger';
import type { G7DevToolsCore } from '../devtools/G7DevToolsCore';

const logger = createLogger('ApiClient');

/**
 * DevTools 인스턴스 가져오기
 */
function getDevTools(): G7DevToolsCore | null {
  if (typeof window !== 'undefined' && (window as any).__G7_DEVTOOLS__) {
    return (window as any).__G7_DEVTOOLS__ as G7DevToolsCore;
  }
  return null;
}

/**
 * Axios 설정에 DevTools 요청 ID를 저장하기 위한 확장 인터페이스
 */
interface AxiosConfigWithDevTools extends InternalAxiosRequestConfig {
  _devToolsRequestId?: string | null;
  _retry?: boolean;
}

/**
 * API 에러 정보
 */
export interface ApiErrorInfo {
  /** HTTP 상태 코드 */
  status: number;
  /** 에러 메시지 */
  message: string;
  /** API 응답 데이터 */
  data?: any;
  /** HTTP 상태 텍스트 */
  statusText?: string;
}

/**
 * API 에러 핸들러 타입
 */
export type ApiErrorHandler = (error: ApiErrorInfo) => void;

/**
 * API 클라이언트 설정 인터페이스
 */
interface ApiClientConfig {
  baseURL?: string;
  timeout?: number;
  /** @deprecated onUnauthorized 대신 onError를 사용하세요 */
  onTokenExpired?: () => void;
  /** 401 토큰 갱신 실패 시 호출 (로그인 페이지 리다이렉트용) */
  onUnauthorized?: () => void;
  /** 글로벌 에러 핸들러 (모든 API 에러에 대해 호출) */
  onError?: ApiErrorHandler;
}

/**
 * API 클라이언트 클래스
 * 인증이 필요한 API 요청에 자동으로 Authorization 헤더를 추가합니다.
 */
class ApiClient {
  private client: AxiosInstance;
  private config: ApiClientConfig;
  private readonly TOKEN_KEY = 'auth_token';

  constructor(config: ApiClientConfig = {}) {
    this.config = {
      baseURL: config.baseURL || '/api',
      timeout: config.timeout || 30000,
      onTokenExpired: config.onTokenExpired, // eslint-disable-line deprecation/deprecation
      onUnauthorized: config.onUnauthorized,
      onError: config.onError,
    };

    this.client = axios.create({
      baseURL: this.config.baseURL,
      timeout: this.config.timeout,
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      // 배열 파라미터를 key[]=a&key[]=b 형식으로 직렬화 (Laravel 호환)
      paramsSerializer: {
        serialize: (params) => {
          const parts: string[] = [];
          for (const [key, value] of Object.entries(params)) {
            if (value === null || value === undefined) continue;

            if (Array.isArray(value)) {
              // 배열: key[]=a&key[]=b 형식
              // 키에 이미 []가 있으면 그대로 사용, 없으면 []를 추가
              const arrayKey = key.endsWith('[]') ? key : `${key}[]`;
              for (const item of value) {
                parts.push(`${encodeURIComponent(arrayKey)}=${encodeURIComponent(String(item))}`);
              }
            } else {
              // 일반 값
              parts.push(`${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`);
            }
          }
          return parts.join('&');
        },
      },
    });

    this.setupInterceptors();
  }

  /**
   * 토큰 저장
   */
  setToken(token: string): void {
    if (typeof window !== 'undefined') {
      localStorage.setItem(this.TOKEN_KEY, token);
    }
  }

  /**
   * 토큰 조회
   */
  getToken(): string | null {
    if (typeof window !== 'undefined') {
      return localStorage.getItem(this.TOKEN_KEY);
    }
    return null;
  }

  /**
   * 토큰 삭제
   */
  removeToken(): void {
    if (typeof window !== 'undefined') {
      localStorage.removeItem(this.TOKEN_KEY);
    }
  }

  /**
   * 인터셉터 설정
   */
  private setupInterceptors(): void {
    // 요청 인터셉터
    this.client.interceptors.request.use(
      (config: InternalAxiosRequestConfig) => {
        // URL이 이미 /api로 시작하면 baseURL 제거 (중복 방지)
        if (config.url?.startsWith('/api') && config.baseURL === '/api') {
          config.baseURL = '';
        }

        const token = this.getToken();
        if (token && config.headers) {
          config.headers.Authorization = `Bearer ${token}`;
        }

        // g7_locale이 설정되어 있으면 Accept-Language 헤더로 전송
        if (typeof window !== 'undefined' && config.headers) {
          const locale = localStorage.getItem('g7_locale');
          if (locale) {
            config.headers['Accept-Language'] = locale;
          }
        }

        // DevTools 요청 추적 시작
        const devTools = getDevTools();
        if (devTools?.isEnabled()) {
          const fullUrl = this.buildFullUrl(config);
          const method = (config.method || 'GET').toUpperCase();
          const requestId = devTools.trackRequest(
            fullUrl,
            method,
            { requestBody: config.data }
          );
          (config as AxiosConfigWithDevTools)._devToolsRequestId = requestId;
        }

        return config;
      },
      (error) => {
        return Promise.reject(error);
      }
    );

    // 응답 인터셉터
    this.client.interceptors.response.use(
      (response: AxiosResponse) => {
        // DevTools 요청 완료 추적 (성공)
        const config = response.config as AxiosConfigWithDevTools;
        const requestId = config._devToolsRequestId;
        if (requestId) {
          const devTools = getDevTools();
          if (devTools?.isEnabled()) {
            devTools.completeRequest(requestId, response.status, response.data);
          }
          config._devToolsRequestId = null;
        }
        return response;
      },
      async (error) => {
        const originalRequest = error.config as AxiosConfigWithDevTools | undefined;
        const requestUrl = originalRequest?.url || '';

        // onUnauthorized 콜백 실행을 건너뛸 엔드포인트 패턴
        // - /auth/: 인증 관련 API (로그인 실패 시 리다이렉트 방지, 토큰 갱신 무한 루프 방지)
        // - /layouts/: 레이아웃 서빙 API (공개 접근 가능, 토큰 만료 시 로그인 페이지 접근 허용)
        const skipUnauthorizedPatterns = [
          '/auth/',
          '/layouts/',
        ];
        const shouldSkipUnauthorized = skipUnauthorizedPatterns.some(pattern => requestUrl.includes(pattern));

        // 401 Unauthorized 처리
        if (error.response?.status === 401 && !originalRequest?._retry && !shouldSkipUnauthorized) {
          originalRequest!._retry = true;

          // AuthManager를 통한 토큰 갱신 시도
          const authManager = AuthManager.getInstance();
          const refreshed = await authManager.refreshToken();

          if (refreshed) {
            // 토큰 갱신 성공, 원래 요청 재시도 (DevTools 추적은 재시도된 요청에서 처리)
            const token = this.getToken();
            if (token) {
              originalRequest!.headers.Authorization = `Bearer ${token}`;
            }
            // 재시도 전 requestId 클리어 (새 요청으로 다시 추적됨)
            originalRequest!._devToolsRequestId = null;
            return this.client(originalRequest!);
          }

          // 갱신 실패 - DevTools에 에러 기록
          this.completeDevToolsRequest(originalRequest, error);
          this.removeToken();

          if (this.config.onUnauthorized) {
            this.config.onUnauthorized();
          }

          return Promise.reject(error);
        }

        // 인증 관련 요청이 401인 경우 (로그인 실패, 토큰 만료/삭제 등)
        // 각 호출 측에서 에러를 직접 처리하도록 함
        if (error.response?.status === 401 && shouldSkipUnauthorized) {
          this.completeDevToolsRequest(originalRequest, error);
          return Promise.reject(error);
        }

        // 403 Forbidden 처리 - onUnauthorized를 호출하지 않고 onError로 처리
        // 403은 "권한 없음"이므로 로그인 페이지로 리다이렉트하면 안됨
        if (error.response?.status === 403) {
          this.completeDevToolsRequest(originalRequest, error);
          this.callOnError(error);
          return Promise.reject(error);
        }

        // 기타 에러 처리 (404, 422, 500 등)
        this.completeDevToolsRequest(originalRequest, error);
        if (error.response) {
          this.callOnError(error);
        }

        return Promise.reject(error);
      }
    );
  }

  /**
   * GET 요청
   */
  async get<T = any>(url: string, config?: AxiosRequestConfig): Promise<T> {
    const response = await this.client.get<T>(url, config);
    return response.data;
  }

  /**
   * POST 요청
   */
  async post<T = any>(url: string, data?: any, config?: AxiosRequestConfig): Promise<T> {
    const response = await this.client.post<T>(url, data, config);
    return response.data;
  }

  /**
   * PUT 요청
   */
  async put<T = any>(url: string, data?: any, config?: AxiosRequestConfig): Promise<T> {
    const response = await this.client.put<T>(url, data, config);
    return response.data;
  }

  /**
   * PATCH 요청
   */
  async patch<T = any>(url: string, data?: any, config?: AxiosRequestConfig): Promise<T> {
    const response = await this.client.patch<T>(url, data, config);
    return response.data;
  }

  /**
   * DELETE 요청
   */
  async delete<T = any>(url: string, config?: AxiosRequestConfig): Promise<T> {
    const response = await this.client.delete<T>(url, config);
    return response.data;
  }

  /**
   * Axios 인스턴스 직접 접근 (고급 사용)
   */
  getInstance(): AxiosInstance {
    return this.client;
  }

  /**
   * onUnauthorized 콜백 설정
   *
   * 토큰 갱신 실패 시 호출될 콜백을 설정합니다.
   * 싱글톤 인스턴스 생성 후에도 콜백을 설정할 수 있습니다.
   *
   * @param callback 401 토큰 갱신 실패 시 호출될 콜백
   */
  setOnUnauthorized(callback: () => void): void {
    this.config.onUnauthorized = callback;
  }

  /**
   * 글로벌 에러 핸들러 설정
   *
   * API 에러 발생 시 호출될 핸들러를 설정합니다.
   * ErrorHandlingResolver와 연동하여 계층적 에러 핸들링을 수행합니다.
   *
   * @param handler 에러 핸들러 함수
   */
  setOnError(handler: ApiErrorHandler): void {
    this.config.onError = handler;
  }

  /**
   * DevTools 요청 완료 추적 (에러 응답용)
   *
   * @param config Axios 요청 설정
   * @param error Axios 에러 객체
   */
  private completeDevToolsRequest(config: AxiosConfigWithDevTools | undefined, error: any): void {
    if (!config?._devToolsRequestId) return;

    const devTools = getDevTools();
    if (!devTools?.isEnabled()) return;

    const requestId = config._devToolsRequestId;
    config._devToolsRequestId = null;

    if (error.response) {
      // HTTP 응답이 있는 경우 (4xx, 5xx 등)
      devTools.completeRequest(requestId, error.response.status, error.response.data);
    } else if (error.request) {
      // 요청은 보냈지만 응답이 없는 경우 (네트워크 오류, 타임아웃 등)
      devTools.failRequest(requestId, error.message || 'Network error');
    } else {
      // 요청 설정 중 오류
      devTools.failRequest(requestId, error.message || 'Request error');
    }
  }

  /**
   * 요청 설정에서 전체 URL을 생성합니다.
   *
   * @param config Axios 요청 설정
   * @returns 전체 URL 문자열
   */
  private buildFullUrl(config: InternalAxiosRequestConfig): string {
    let url = config.url || '';

    // baseURL이 있고, url이 상대 경로인 경우 결합
    if (config.baseURL && !url.startsWith('http')) {
      url = `${config.baseURL}${url.startsWith('/') ? '' : '/'}${url}`;
    }

    // 쿼리 파라미터가 있는 경우 추가
    if (config.params && typeof config.params === 'object') {
      const params = config.params as Record<string, any>;
      const parts: string[] = [];
      for (const [key, value] of Object.entries(params)) {
        if (value === null || value === undefined) continue;
        if (Array.isArray(value)) {
          const arrayKey = key.endsWith('[]') ? key : `${key}[]`;
          for (const item of value) {
            parts.push(`${encodeURIComponent(arrayKey)}=${encodeURIComponent(String(item))}`);
          }
        } else {
          parts.push(`${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`);
        }
      }
      if (parts.length > 0) {
        url += (url.includes('?') ? '&' : '?') + parts.join('&');
      }
    }

    return url;
  }

  /**
   * 글로벌 에러 핸들러를 호출합니다.
   *
   * @param error Axios 에러 객체
   */
  private callOnError(error: any): void {
    if (!this.config.onError) {
      return;
    }

    const errorInfo: ApiErrorInfo = {
      status: error.response?.status || 0,
      message: error.response?.data?.message || error.message || 'Unknown error',
      data: error.response?.data,
      statusText: error.response?.statusText,
    };

    try {
      this.config.onError(errorInfo);
    } catch (handlerError) {
      logger.error('Error in onError handler:', handlerError);
    }
  }
}

// 싱글톤 인스턴스
let apiClientInstance: ApiClient | null = null;

/**
 * API 클라이언트 인스턴스 생성 또는 가져오기
 */
export function createApiClient(config?: ApiClientConfig): ApiClient {
  if (!apiClientInstance) {
    apiClientInstance = new ApiClient(config);
  }
  return apiClientInstance;
}

/**
 * 기본 API 클라이언트 인스턴스 가져오기
 */
export function getApiClient(): ApiClient {
  if (!apiClientInstance) {
    apiClientInstance = new ApiClient();
  }
  return apiClientInstance;
}

export default ApiClient;
