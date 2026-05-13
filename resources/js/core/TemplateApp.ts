/**
 * TemplateApp 클래스
 * 템플릿 엔진의 모든 모듈을 통합하고 초기화하는 메인 애플리케이션 클래스
 */

import React from 'react';
import { flushSync } from 'react-dom';
import { createRoot as createReactRoot } from 'react-dom/client';
import { Router } from './routing/Router';
import type { Route } from './routing/Router';
import { LayoutLoader, LayoutLoaderError } from './template-engine/LayoutLoader';
import type { InitActionDefinition, LayoutScript, ComputedSwitchDefinition } from './template-engine/LayoutLoader';
import { DataBindingEngine } from './template-engine/DataBindingEngine';
import { evaluateRenderCondition } from './template-engine/helpers/RenderHelpers';
import { ComponentRegistry } from './template-engine/ComponentRegistry';
import { DataSourceManager } from './template-engine/DataSourceManager';
import { initTemplateEngine, renderTemplate, destroyTemplate, getState, updateTemplateData } from './template-engine';
import { ErrorDisplay } from './template-engine/ErrorDisplay';
import { toTemplateEngineError } from './template-engine/TemplateEngineError';
import { ErrorPageHandler } from './template-engine/ErrorPageHandler';
import { AuthManager, type AuthType } from './auth/AuthManager';
import { getApiClient } from './api/ApiClient';
import { transitionManager } from './template-engine/TransitionManager';
import { getErrorHandlingResolver } from './error';
import type { ErrorHandlingMap } from './types/ErrorHandling';
import { createLogger, Logger } from './utils/Logger';
import { webSocketManager } from './websocket/WebSocketManager';
import { getModuleAssetLoader, parseModuleAssetsFromConfig, parsePluginAssetsFromConfig } from './modules';
import { SystemBannerManager } from './template-engine/SystemBannerManager';
/**
 * DevTools 추적 - G7DevToolsCore.getInstance() 직접 호출 대신 G7Core.devTools를 사용합니다.
 */

const logger = createLogger('TemplateApp');

/**
 * URLSearchParams를 배열 쿼리 파라미터를 지원하는 객체로 변환합니다.
 *
 * 일반 Object.fromEntries(params.entries())는 같은 키의 여러 값 중 마지막 값만 반환합니다.
 * 이 함수는 `key[]` 형태의 키를 배열로 올바르게 파싱합니다.
 *
 * @param params URLSearchParams 객체
 * @returns 파싱된 쿼리 객체 (배열 키는 실제 배열로 변환)
 *
 * @example
 * // URL: ?sales_status[]=on_sale&sales_status[]=sold_out&page=1
 * parseQueryParams(params)
 * // → { 'sales_status[]': ['on_sale', 'sold_out'], page: '1' }
 */
function parseQueryParams(params: URLSearchParams): Record<string, string | string[]> {
    const result: Record<string, string | string[]> = {};

    // 모든 키를 순회
    for (const key of params.keys()) {
        // 이미 처리된 키는 스킵
        if (key in result) continue;

        // 해당 키의 모든 값을 가져옴
        const values = params.getAll(key);

        if (values.length > 1) {
            // 여러 값이 있으면 배열로 저장
            result[key] = values;
        } else if (values.length === 1) {
            // 단일 값
            // key[]로 끝나면 배열로 저장 (URL에서 단일 배열 요소 전송 시)
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
 * WebSocket(Reverb) 설정 인터페이스
 */
export interface WebSocketConfig {
    /** Reverb 앱 키 */
    appKey: string;
    /** WebSocket 호스트 (기본값: localhost) */
    host?: string;
    /** WebSocket 포트 (기본값: 80) */
    port?: number;
    /** 스키마 (http 또는 https, 기본값: https) */
    scheme?: 'http' | 'https';
}

export interface TemplateAppConfig {
    templateId: string;
    templateType: 'admin' | 'user';
    locale: string;
    debug: boolean;
    /** WebSocket(Reverb) 설정 (선택적) */
    websocket?: WebSocketConfig;
    /**
     * 언어 변경 API 엔드포인트 (선택적)
     *
     * 설정하지 않으면 템플릿 타입에 따라 기본값 사용:
     * - admin: { endpoint: '/api/admin/users/me/language', method: 'PATCH' }
     * - user: { endpoint: '/api/user/profile/update-language', method: 'POST' }
     */
    localeApi?: {
        endpoint: string;
        method: 'POST' | 'PATCH' | 'PUT';
    };
}

/**
 * 업로드 설정 인터페이스
 */
export interface UploadSettings {
    /** 최대 파일 크기 (MB) */
    max_file_size: number;
    /** 허용 확장자 (예: 'jpg,jpeg,png') */
    allowed_extensions: string;
    /** 허용 확장자 포맷 (예: '.jpg,.jpeg,.png') */
    allowed_extensions_formatted: string;
    /** 이미지 최대 너비 */
    image_max_width: number;
    /** 이미지 최대 높이 */
    image_max_height: number;
    /** 이미지 품질 (1-100) */
    image_quality: number;
}

/**
 * 일반 설정 인터페이스
 */
export interface GeneralSettings {
    site_name: string;
    site_url: string;
    site_description: string;
    admin_email: string;
    language: string;
    timezone: string;
}

/**
 * SEO 설정 인터페이스
 */
export interface SeoSettings {
    [key: string]: any;
}

/**
 * 보안 설정 인터페이스
 */
export interface SecuritySettings {
    force_https: boolean;
    login_attempt_enabled: boolean;
    auth_token_lifetime: number;
    max_login_attempts: number;
    login_lockout_time: number;
}

/**
 * 고급 설정 인터페이스
 */
export interface AdvancedSettings {
    cache_enabled: boolean;
    layout_cache_enabled: boolean;
    layout_cache_ttl: number;
    stats_cache_enabled: boolean;
    stats_cache_ttl: number;
    seo_cache_enabled: boolean;
    seo_cache_ttl: number;
    debug_mode: boolean;
}

/**
 * 프론트엔드 전역 설정 인터페이스
 *
 * admin.blade.php에서 window.G7Config.settings로 주입되어
 * TemplateApp에서 _global.settings로 로드됩니다.
 *
 * config/settings/defaults.json의 frontend_schema에 정의된 카테고리가
 * 동적으로 포함됩니다. 아래는 기본 카테고리들이며,
 * 새로운 카테고리가 추가되어도 인덱스 시그니처로 수용 가능합니다.
 */
export interface FrontendSettings {
    general?: GeneralSettings;
    upload?: UploadSettings;
    seo?: SeoSettings;
    security?: SecuritySettings;
    advanced?: AdvancedSettings;
    /** 동적 카테고리 지원을 위한 인덱스 시그니처 */
    [key: string]: Record<string, any> | undefined;
}

/**
 * 전역 상태 인터페이스
 */
export interface GlobalState {
    sidebarOpen: boolean;
    /** 전체 환경설정 (환경설정에서 관리) */
    settings?: FrontendSettings;
    /** @deprecated uploadSettings 대신 settings.upload 사용을 권장합니다 */
    uploadSettings?: UploadSettings;
    [key: string]: any;
}

/**
 * 서버에서 주입된 에러 정보 인터페이스
 *
 * 미들웨어에서 의존성 미충족 등으로 에러 상태가 감지되면
 * window.G7Error에 에러 정보를 주입합니다.
 */
export interface G7ErrorInfo {
    /** HTTP 에러 코드 (예: 503) */
    code: number;
    /** 렌더링할 에러 레이아웃 경로 (예: 'errors/503') */
    layout: string;
    /** 에러 관련 추가 데이터 (예: 미충족 의존성 목록) */
    data?: any;
}

declare global {
    interface Window {
        __templateApp?: TemplateApp;
        /** 서버에서 주입된 에러 정보 (미들웨어에서 설정) */
        G7Error?: G7ErrorInfo;
    }
}

export class TemplateApp {
    private router: Router | null = null;
    private layoutLoader: LayoutLoader | null = null;
    private errorPageHandler: ErrorPageHandler | null = null;
    private config: TemplateAppConfig;
    private static readonly LOCALE_STORAGE_KEY = 'g7_locale';
    private static readonly CACHE_VERSION_STORAGE_KEY = 'g7_cache_version';
    private globalState: GlobalState;
    private globalStateListeners: Set<(state: GlobalState) => void> = new Set();
    /** 현재 진행 중인 라우트 변경 요청 ID (새 요청 시 이전 요청 무시용) */
    private currentRouteChangeId: number = 0;
    /** 현재 레이아웃의 데이터 소스 정의 (refetch용) */
    private currentDataSources: any[] = [];
    /** 현재 라우트 파라미터 (refetch용) */
    private currentRouteParams: Record<string, string> = {};
    /** 현재 쿼리 파라미터 (refetch용) */
    private currentQueryParams: URLSearchParams = new URLSearchParams();
    /** 현재 fetch된 데이터 (refetch 및 get용) */
    private currentFetchedData: Record<string, any> = {};
    /** 현재 레이아웃 이름 (레이아웃 전환 감지용) */
    private currentLayoutName: string = '';
    /** 템플릿 레벨 에러 핸들링 설정 */
    private templateErrorHandling: ErrorHandlingMap | null = null;
    /** 현재 WebSocket 구독 키 (라우트 변경 시 해제용) */
    private currentWebSocketSubscriptions: string[] = [];
    /** 확장 기능 캐시 버전 (모듈/플러그인 활성화 시 갱신됨) */
    private extensionCacheVersion: number = 0;
    /** 현재 레이아웃의 전역 헤더 규칙 (API 호출 시 자동 적용) */
    private currentGlobalHeaders: any[] = [];
    /** 전환 오버레이 DOM 요소 @since engine-v1.23.0 */
    private transitionOverlayEl: HTMLDivElement | null = null;
    /** 스켈레톤 오버레이 React 루트 @since engine-v1.24.0 */
    private skeletonOverlayRoot: any = null;
    /** 스켈레톤 오버레이 컨테이너 DOM 요소 @since engine-v1.24.0 */
    private skeletonOverlayContainer: HTMLDivElement | null = null;
    /** spinner 재생성용 상태 (renderTemplate 후 새 DOM에 재마운트) @since engine-v1.29.0 */
    private _spinnerState: {
        target: string;
        fallbackTarget?: string;
        spinnerConfig?: { component?: string; text?: string };
        resolvedText: string;
    } | null = null;

    /**
     * 모달 데이터 소스 레지스트리
     *
     * 모달이 열릴 때 ModalDataSourceWrapper가 등록하고,
     * 닫힐 때 해제합니다. refetchDataSource에서 currentDataSources에
     * 없는 데이터 소스를 여기서 검색합니다.
     *
     * @since 1.18.0
     */
    private modalDataSources: Map<string, any[]> = new Map();

    constructor(config: TemplateAppConfig) {
        // 전역 상태 초기화
        this.globalState = {
            sidebarOpen: false,
        };

        // window.G7Config에서 초기 설정값 로드
        this.loadG7Config();

        // 레거시 로케일 키 마이그레이션
        this.migrateLocaleStorage();

        // 로케일 우선순위:
        // 1. localStorage g7_locale 값 (사용자가 명시적으로 선택한 언어)
        // 2. 서버에서 전달한 로케일 (로그인 사용자의 users.language 값)
        // 3. 기본값 ('ko')
        //
        // SetLocale 미들웨어 우선순위:
        // - 로그인 사용자: users.language (최우선)
        // - 비로그인 사용자: Accept-Language 헤더
        // - 기본값: config('app.locale')
        const savedLocale = this.loadLocaleFromStorage();
        const finalLocale = savedLocale || config.locale || 'ko';

        this.config = {
            ...config,
            locale: finalLocale,
        };

        // localStorage 값이 없을 때만 서버 설정을 저장
        // (사용자가 선택한 언어를 보존)
        if (!savedLocale && config.locale) {
            this.saveLocaleToStorage(config.locale);
        }
    }

    /**
     * window.G7Config에서 초기 설정값을 전역 상태에 로드합니다.
     *
     * admin.blade.php에서 주입된 G7Config.settings, plugins, modules를
     * _global로 로드하여 프론트엔드 전역에서 사용할 수 있게 합니다.
     *
     * 하위 호환성을 위해 _global.uploadSettings도 함께 설정합니다.
     */
    private loadG7Config(): void {
        if (typeof window !== 'undefined' && (window as any).G7Config) {
            const g7Config = (window as any).G7Config;

            // 전체 settings 로드
            if (g7Config.settings) {
                this.globalState.settings = g7Config.settings;
                logger.log('Loaded settings from G7Config:', Object.keys(g7Config.settings));

                // 하위 호환성: uploadSettings도 설정 (deprecated)
                if (g7Config.settings.upload) {
                    this.globalState.uploadSettings = g7Config.settings.upload;
                }
            }

            // 플러그인 설정 로드
            if (g7Config.plugins) {
                this.globalState.plugins = g7Config.plugins;
                logger.log('Loaded plugin settings from G7Config:', Object.keys(g7Config.plugins));
            }

            // 모듈 설정 로드
            if (g7Config.modules) {
                this.globalState.modules = g7Config.modules;
                logger.log('Loaded module settings from G7Config:', Object.keys(g7Config.modules));
            }

            // 앱 config 로드 (config() 기반 설정값)
            if (g7Config.appConfig) {
                this.globalState.appConfig = g7Config.appConfig;
                logger.log('Loaded appConfig from G7Config:', Object.keys(g7Config.appConfig));
            }
        }
    }

    /**
     * routes.json의 path/redirect 문자열 내 {{expression}}을 평가합니다.
     * 레이아웃 JSON과 동일한 표현식 문법을 지원합니다.
     *
     * 컨텍스트: { _global: this.globalState } (loadG7Config()으로 채워진 상태)
     */
    private resolveRouteExpressions(routes: Route[]): Route[] {
        const bindingEngine = new DataBindingEngine();
        const context = { _global: this.globalState };

        return routes.map(route => {
            const updated = { ...route };

            if (updated.path && updated.path.includes('{{')) {
                const originalPath = updated.path;
                updated.path = bindingEngine.resolveBindings(updated.path, context);
                // 이중 슬래시 정리 (빈 값 치환 시 //cart → /cart)
                updated.path = updated.path.replace(/\/\/+/g, '/') || '/';

                // 안전장치: 치환 실패로 {{...}} 잔존 시 경고 + 표현식 제거 fallback
                if (updated.path.includes('{{')) {
                    logger.warn('Route expression resolution failed, using fallback:', originalPath);
                    updated.path = originalPath.replace(/\{\{[^}]+\}\}/g, '').replace(/\/\/+/g, '/') || '/';
                }
            }
            if (updated.redirect && updated.redirect.includes('{{')) {
                const originalRedirect = updated.redirect;
                updated.redirect = bindingEngine.resolveBindings(updated.redirect, context);
                updated.redirect = updated.redirect.replace(/\/\/+/g, '/') || '/';

                if (updated.redirect.includes('{{')) {
                    logger.warn('Route redirect expression resolution failed:', originalRedirect);
                    updated.redirect = originalRedirect.replace(/\{\{[^}]+\}\}/g, '').replace(/\/\/+/g, '/') || '/';
                }
            }

            return updated;
        });
    }

    /**
     * 레거시 로케일 키를 새 키로 마이그레이션
     *
     * 기존 'locale', 'g7_template_locale' 키를 'g7_locale'로 통일합니다.
     */
    private migrateLocaleStorage(): void {
        const legacyKeys = ['locale', 'g7_template_locale'];

        for (const legacyKey of legacyKeys) {
            try {
                const value = localStorage.getItem(legacyKey);
                if (value && value !== localStorage.getItem(TemplateApp.LOCALE_STORAGE_KEY)) {
                    // 새 키로 복사
                    localStorage.setItem(TemplateApp.LOCALE_STORAGE_KEY, value);
                    logger.log(`Migrated locale from '${legacyKey}' to '${TemplateApp.LOCALE_STORAGE_KEY}'`);
                }
                // 레거시 키 제거
                localStorage.removeItem(legacyKey);
            } catch (error) {
                logger.warn(`Failed to migrate locale key '${legacyKey}':`, error);
            }
        }
    }

    /**
     * 애플리케이션 초기화
     */
    async init(): Promise<void> {
        try {
            // 디버그 모드 설정
            Logger.getInstance().setDebug(this.config.debug);
            logger.log('Initializing with config:', this.config);

            // 1. 템플릿 엔진 초기화, ComponentRegistry, routes.json, 사용자 정보를 병렬 로딩
            const componentRegistry = ComponentRegistry.getInstance();
            const authManager = AuthManager.getInstance();

            // 저장된 캐시 버전 로드 (초기 API 호출에 사용)
            const storedCacheVersion = this.loadCacheVersionFromStorage() || 0;

            const [_, __, routesData, ___, templateConfig] = await Promise.all([
                // 템플릿 엔진 초기화 (다국어 파일 병렬 로드)
                initTemplateEngine({
                    templateId: this.config.templateId,
                    templateType: this.config.templateType,
                    locale: this.config.locale,
                    debug: this.config.debug,
                    cacheVersion: storedCacheVersion,
                }),
                // ComponentRegistry 로딩 (components.json)
                componentRegistry.loadComponents(
                    this.config.templateId,
                    this.config.templateType
                ),
                // routes.json 로딩 (저장된 캐시 버전 사용)
                fetch(`/api/templates/${this.config.templateId}/routes.json${storedCacheVersion > 0 ? `?v=${storedCacheVersion}` : ''}`)
                    .then(response => {
                        if (!response.ok) {
                            throw new Error(`Failed to load routes: ${response.statusText}`);
                        }
                        return response.json();
                    })
                    .then(result => {
                        if (!result.success) {
                            throw new Error('Failed to load routes from API');
                        }
                        return result.data;
                    })
                    .catch(error => {
                        logger.error('Error loading routes:', error);
                        throw error;
                    }),
                // 사용자 정보 프리로드 (에러 발생 시 무시)
                authManager.preloadAuth(this.config.templateType === 'admin' ? 'admin' : 'user'),
                // 템플릿 config.json 로딩 (errorHandling 파싱)
                fetch(`/api/templates/${this.config.templateId}/config.json`)
                    .then(response => {
                        if (!response.ok) {
                            // config.json 로드 실패는 무시 (선택적)
                            return null;
                        }
                        return response.json();
                    })
                    .then(result => {
                        if (!result?.success || !result?.data) {
                            return null;
                        }
                        return result.data;
                    })
                    .catch(error => {
                        logger.warn('Error loading template config:', error);
                        return null;
                    }),
            ]);

            logger.log('Template Engine initialized');
            logger.log('ComponentRegistry loaded');
            logger.log('Routes data loaded');
            logger.log('User info preloaded');
            logger.log('Template config loaded:', templateConfig);

            // 확장 기능 캐시 버전 저장 (모듈/플러그인 활성화 시 갱신됨)
            if (templateConfig?.cache_version !== undefined) {
                const previousVersion = this.loadCacheVersionFromStorage();
                this.extensionCacheVersion = templateConfig.cache_version;
                this.saveCacheVersionToStorage(this.extensionCacheVersion);
                logger.log('Extension cache version:', this.extensionCacheVersion);

                // 캐시 버전이 변경된 경우 routes.json 재로드 필요
                if (previousVersion !== null && previousVersion !== this.extensionCacheVersion) {
                    logger.log('Cache version changed, reloading routes...');
                    // routes.json을 새 캐시 버전으로 다시 로드
                    const newRoutesData = await fetch(`/api/templates/${this.config.templateId}/routes.json?v=${this.extensionCacheVersion}`)
                        .then(response => {
                            if (!response.ok) {
                                throw new Error(`Failed to reload routes: ${response.statusText}`);
                            }
                            return response.json();
                        })
                        .then(result => {
                            if (!result.success) {
                                throw new Error('Failed to reload routes from API');
                            }
                            return result.data;
                        });

                    // Router에 새 routes 설정 (아래에서 Router 초기화 후 적용됨)
                    if (Array.isArray(newRoutesData.routes)) {
                        newRoutesData.routes = this.resolveRouteExpressions(newRoutesData.routes);
                        // routesData를 갱신 (const 변수이므로 객체 속성만 변경)
                        Object.assign(routesData, newRoutesData);
                        logger.log('Routes reloaded with new cache version');
                    }

                    // 다국어 데이터도 새 캐시 버전으로 재로드
                    try {
                        const { TranslationEngine } = await import('./template-engine/TranslationEngine');
                        const translationEngine = TranslationEngine.getInstance();
                        translationEngine.setCacheVersion(this.extensionCacheVersion);
                        const locale = this.config.locale || 'ko';
                        const fallbackLocale = 'en';
                        await translationEngine.loadTranslations(this.config.templateId, locale, '/api', true);
                        if (locale !== fallbackLocale) {
                            await translationEngine.loadTranslations(this.config.templateId, fallbackLocale, '/api', true);
                        }
                        logger.log('Translations reloaded with new cache version');
                    } catch (translationError) {
                        logger.error('Failed to reload translations:', translationError);
                    }
                }
            }

            // 템플릿 레벨 에러 핸들링 설정 저장 및 ErrorHandlingResolver에 등록
            if (templateConfig?.errorHandling) {
                this.templateErrorHandling = templateConfig.errorHandling;
                const errorHandlingResolver = getErrorHandlingResolver();
                errorHandlingResolver.setTemplateConfig(this.templateErrorHandling);

                logger.log('Template errorHandling registered:', this.templateErrorHandling);
            }

            // 2. AuthManager 이벤트 핸들러 등록
            authManager.on('logout', () => {
                logger.log('User logged out');
            });

            authManager.on('authStateChange', (state) => {
                logger.log('Auth state changed:', state);
            });

            logger.log('AuthManager event handlers registered');

            // 2.5 ApiClient에 onUnauthorized 콜백 설정
            // 토큰 갱신 실패 시 로그인 페이지로 리다이렉트
            const apiClient = getApiClient();
            const authType = this.config.templateType === 'admin' ? 'admin' : 'user';
            const authConfig = authManager.getConfig(authType);

            apiClient.setOnUnauthorized(() => {
                logger.log('Unauthorized - redirecting to login page');

                // 토큰 삭제
                apiClient.removeToken();

                // onUnauthorized 콜백이 발동했다는 것은 토큰이 서버에서 거부되었다는 의미
                // (apiClient 는 토큰 미보유 시 요청을 skip 하므로 콜백도 발동 안 함).
                // 즉 사용자는 로그인된 상태였다가 세션이 만료된 케이스이므로
                // reason='session_expired' 를 부여해 로그인 페이지에서 안내 토스트가 노출되도록 한다.
                const returnUrl = window.location.pathname + window.location.search;
                const loginUrl = authManager.getLoginRedirectUrl(
                    authType as AuthType,
                    returnUrl,
                    'session_expired'
                );
                window.location.href = loginUrl;
            });

            logger.log('ApiClient onUnauthorized callback registered');

            // 3. LayoutLoader 초기화 (캐시 버전 설정)
            this.layoutLoader = new LayoutLoader(componentRegistry);
            if (this.extensionCacheVersion > 0) {
                this.layoutLoader.setCacheVersion(this.extensionCacheVersion);
            }

            logger.log('LayoutLoader initialized:', this.layoutLoader);

            // 3.5 ErrorPageHandler 초기화 (DataSourceManager 의존성 주입)
            const errorDataSourceManager = new DataSourceManager({
                onUnauthorized: () => {
                    logger.warn('Unauthorized request in error page');
                },
            });

            this.errorPageHandler = new ErrorPageHandler({
                templateId: this.config.templateId,
                layoutLoader: this.layoutLoader,
                locale: this.config.locale,
                debug: this.config.debug,
                renderFunction: renderTemplate,
                dataSourceManager: errorDataSourceManager,
                globalState: this.globalState,
            });

            logger.log('ErrorPageHandler initialized with DataSourceManager');

            // 3.6 서버에서 주입된 에러 상태 확인 (503 의존성 미충족 등)
            // window.G7Error가 있으면 에러 페이지만 렌더링하고 초기화 종료
            if (await this.handleServerError()) {
                logger.log('Server error handled, skipping normal initialization');
                return;
            }

            // 4. Router 초기화 및 라우트 설정
            this.router = new Router(this.config.templateId);

            if (Array.isArray(routesData.routes)) {
                const resolvedRoutes = this.resolveRouteExpressions(routesData.routes);
                this.router.setRoutes(resolvedRoutes);
            } else {
                throw new Error('Invalid routes data format');
            }

            logger.log('Router initialized:', this.router);
            logger.log('Routes set:', this.router.getRoutes());

            // 6. ActionDispatcher에 navigate 함수 및 setGlobalState 주입
            const { getActionDispatcher } = await import('./template-engine');
            const actionDispatcher = getActionDispatcher();

            if (actionDispatcher) {
                actionDispatcher.setDefaultContext({
                    navigate: (path: string) => this.router?.navigate(path),
                });

                // setGlobalState 함수 주입
                actionDispatcher.setGlobalStateUpdater((updates: any, opts?: { render?: boolean }) => this.setGlobalState(updates, opts));

                logger.log('Navigate function and setGlobalState injected to ActionDispatcher');
            }

            // 7. 모듈/플러그인 에셋 로드 (핸들러 등록 위해 초기 라우트 처리 전에 실행)
            await this.loadExtensionAssets();

            // 7.5 템플릿 핸들러 등록 (window.G7TemplateHandlers에서)
            // components.iife.js의 initTemplate()은 window.load 이벤트에서 핸들러를 등록하지만,
            // app.init()은 DOMContentLoaded에서 시작되므로 init_actions가 window.load 전에 실행될 수 있음.
            // 이를 방지하기 위해 초기화 시점에 직접 등록합니다.
            this.reinitializeTemplateHandlers();

            // 8. 라우트 변경 이벤트 핸들러 등록
            this.router.on('routeChange', (route: Route) => this.handleRouteChange(route));

            // 8.5 routeNotFound 이벤트 핸들러 등록 (404 에러 페이지 처리)
            this.router.on('routeNotFound', (path: string) => this.handleRouteNotFound(path));

            // 9. 초기 라우트 처리
            this.router.navigateToCurrentPath();

            logger.log('Template App initialized successfully');
        } catch (error) {
            logger.error('Initialization failed:', error);
            this.showInitError(error as Error);
        }
    }

    /**
     * 모듈/플러그인 에셋 로드
     *
     * window.G7Config.moduleAssets 및 pluginAssets에서 에셋 정보를 읽어
     * 동적으로 JS/CSS를 로드합니다.
     *
     * 모듈 JS는 IIFE 형태로 빌드되어 로드 즉시 initModule()이 실행되고,
     * ActionDispatcher에 핸들러가 등록됩니다.
     */
    private async loadExtensionAssets(): Promise<void> {
        try {
            const moduleAssetLoader = getModuleAssetLoader();

            // 모듈 에셋 로드
            const moduleAssets = parseModuleAssetsFromConfig();
            if (moduleAssets.length > 0) {
                logger.log('Loading module assets:', moduleAssets.map(m => m.identifier));
                await moduleAssetLoader.loadActiveExtensionAssets(moduleAssets);
            }

            // 플러그인 에셋 로드
            const pluginAssets = parsePluginAssetsFromConfig();
            if (pluginAssets.length > 0) {
                logger.log('Loading plugin assets:', pluginAssets.map(p => p.identifier));
                await moduleAssetLoader.loadActiveExtensionAssets(pluginAssets);
            }

            if (moduleAssets.length > 0 || pluginAssets.length > 0) {
                logger.log('Extension assets loaded successfully');
            }
        } catch (error) {
            // 에셋 로드 실패는 경고만 출력하고 앱 계속 진행
            logger.warn('Failed to load extension assets:', error);
        }
    }

    /**
     * 라우트 변경 핸들러
     */
    private async handleRouteChange(route: Route): Promise<void> {
        // 새 요청 ID 생성 (이전 요청 무시용)
        const routeChangeId = ++this.currentRouteChangeId;

        // engine-v1.17.4: 페이지 전환 시 전역 상태 컨텍스트 초기화
        // - __g7ForcedLocalFields: 비동기 setLocal fallback에서 설정된 강제 필드
        // - __g7ActionContext: 이전 페이지의 액션 컨텍스트 (stale setState 방지)
        // - __g7PendingLocalState: 이전 페이지의 pending 상태
        (window as any).__g7ForcedLocalFields = undefined;
        (window as any).__g7ActionContext = undefined;
        (window as any).__g7PendingLocalState = undefined;
        // engine-v1.24.6: 추가 전역 변수 초기화 (이전 페이지 잔존 방지)
        // - __g7LastSetLocalSnapshot: getLocal() fallback — 이전 페이지 setLocal 스냅샷 잔존
        // - __g7SetLocalOverrideKeys: setLocal override 키 — useLayoutEffect 미처리 시 잔존
        // - __g7SequenceLocalSync: sequence 핸들러 동기화 — 이전 페이지 sequence 상태 잔존
        (window as any).__g7LastSetLocalSnapshot = undefined;
        (window as any).__g7SetLocalOverrideKeys = undefined;
        (window as any).__g7SequenceLocalSync = undefined;
        // [engine-v1.43.0+] 자동바인딩 경로 레지스트리 — 이전 페이지 컴포넌트의 언마운트가 라우트 전환과
        // 경쟁할 수 있으므로 강제 재초기화. undefined 대신 빈 Map을 써서 경쟁 상태의 이전 페이지 cleanup이
        // 이후에 registry.delete() 시도할 때 참조 오류 방지.
        (window as any).__g7AutoBindingPaths = new Map<string, number>();

        try {
            logger.log('Route changed:', route, 'requestId:', routeChangeId);

            if (!this.layoutLoader) {
                throw new Error('LayoutLoader is not initialized');
            }

            // 1. 레이아웃 JSON 로드
            if (!route.layout) {
                throw new Error('Route layout is not defined');
            }

            // 시스템 레이아웃: __preview__ 는 프리뷰 API 엔드포인트로 매핑
            let layoutPath = route.layout;
            const isPreviewMode = layoutPath === '__preview__';
            if (isPreviewMode && route.params?.token) {
                layoutPath = `__preview__/${route.params.token}`;
            }

            const layoutData = await this.layoutLoader.loadLayout(
                this.config.templateId,
                layoutPath
            );

            // 새 요청이 들어왔으면 현재 요청 무시
            if (routeChangeId !== this.currentRouteChangeId) {
                logger.log('Route change cancelled (newer request exists):', routeChangeId);
                return;
            }

            logger.log('Layout loaded:', layoutData);

            // 1.5 레이아웃 레벨 에러 핸들링 설정을 ErrorHandlingResolver에 등록
            const errorHandlingResolver = getErrorHandlingResolver();
            if (layoutData.errorHandling) {
                errorHandlingResolver.setLayoutConfig(layoutData.errorHandling);

                logger.log('Layout errorHandling registered:', layoutData.errorHandling);
            } else {
                // 이전 레이아웃의 설정 초기화
                errorHandlingResolver.clearLayoutConfig();
            }

            // 2. data_sources 조건부 필터링 및 분류
            const rawDataSources = layoutData.data_sources || [];

            // Router에서 전달받은 query 정보 사용 (window.location.search 대신)
            // 배열 쿼리 파라미터 지원 (key[]=[...] 형태)
            const queryObject: Record<string, string | string[]> = route.query || {};

            // queryObject를 URLSearchParams로 변환 (배열 값 지원)
            const queryParams = new URLSearchParams();
            for (const [key, value] of Object.entries(queryObject)) {
                if (Array.isArray(value)) {
                    for (const item of value) {
                        queryParams.append(key, item);
                    }
                } else {
                    queryParams.set(key, value);
                }
            }

            // 조건 평가용 컨텍스트 구성 (route, query, _global)
            const conditionContext = {
                route: route.params || {},
                query: queryObject,
                _global: this.globalState,
            };

            // 2.5 외부 스크립트 로드 (scripts 속성 처리)
            if (layoutData.scripts && Array.isArray(layoutData.scripts)) {
                await this.loadLayoutScripts(layoutData.scripts, conditionContext);
            }

            // DataSourceManager를 사용하여 조건부 필터링
            const { DataSourceManager, getActionDispatcher } = await import('./template-engine');
            const dataSourceManager = new DataSourceManager();

            // 프리뷰 모드 설정: ActionDispatcher에 억제 모드 적용 + 전역 상태 플래그 + 배너 표시
            // @since engine-v1.26.1
            if (isPreviewMode) {
                const actionDispatcher = getActionDispatcher();
                if (actionDispatcher) {
                    actionDispatcher.setPreviewMode(true);
                }

                // _global.__isPreview 플래그 주입 (레이아웃 JSON에서 조건부 렌더링에 사용 가능)
                this.setGlobalState({ __isPreview: true });

                // 프리뷰 안내 배너 표시
                SystemBannerManager.show({
                    id: 'preview-mode',
                    message: {
                        ko: '\u26a0 미리보기 모드 — 페이지 이동이 비활성화됩니다',
                        en: '\u26a0 Preview Mode — Navigation is disabled',
                    },
                    background: 'linear-gradient(90deg, #f59e0b, #d97706)',
                    color: 'white',
                });

                logger.log('Preview mode activated');
            } else {
                // 프리뷰 모드 해제 (이전 프리뷰 상태가 남아있을 수 있으므로)
                const actionDispatcher = getActionDispatcher();
                if (actionDispatcher?.isPreviewMode()) {
                    actionDispatcher.setPreviewMode(false);
                    SystemBannerManager.hide('preview-mode');
                }
            }

            // globalHeaders 설정 (레이아웃에서 정의한 전역 헤더)
            // 클래스 속성에 저장하여 refetchDataSource에서도 사용
            this.currentGlobalHeaders = layoutData.globalHeaders || [];
            if (this.currentGlobalHeaders.length > 0) {
                dataSourceManager.setGlobalHeaders(this.currentGlobalHeaders);
                // ActionDispatcher에도 globalHeaders 설정
                const actionDispatcher = getActionDispatcher();
                if (actionDispatcher) {
                    actionDispatcher.setGlobalHeaders(this.currentGlobalHeaders);
                }
                logger.log('globalHeaders set:', this.currentGlobalHeaders.map((h: any) => h.pattern));
            }

            // named_actions 설정 (레이아웃에서 정의한 재사용 가능 액션)
            if (layoutData.named_actions && Object.keys(layoutData.named_actions).length > 0) {
                const actionDispatcher = getActionDispatcher();
                if (actionDispatcher) {
                    actionDispatcher.setNamedActions(layoutData.named_actions);
                }
            }

            // if 조건에 따라 데이터 소스 필터링 (같은 id 중 조건 만족하는 첫 번째만 선택)
            const dataSources = dataSourceManager.filterByCondition(rawDataSources, conditionContext);

            if (rawDataSources.length !== dataSources.length) {
                logger.log('Data sources filtered by condition:', {
                    before: rawDataSources.map((s: any) => s.id),
                    after: dataSources.map((s: any) => s.id),
                });
            }

            const blockingSources = dataSources.filter(
                (source: any) => source.loading_strategy === 'blocking'
            );
            // WebSocket 소스는 이벤트 리스너(실시간 알림)이지 데이터 제공자가 아님
            // Step 6에서 별도로 구독 처리되므로 progressive 목록에서 제외
            // 포함 시 progressiveDataInit에서 undefined로 초기화되어 blur_until_loaded가 영구 블러됨
            const progressiveAndBackgroundSources = dataSources.filter(
                (source: any) => (source.loading_strategy || 'progressive') !== 'blocking' && source.type !== 'websocket'
            );

            // 2.5 transition 오버레이: blocking 데이터 fetch 전 또는 wait_for 명시 시 표시 (@since engine-v1.24.0, wait_for engine-v1.30.0)
            // blocking 데이터 로딩 대기 시간 또는 progressive 데이터 fetch 완료까지 skeleton/spinner UI 표시
            // 3단계 타겟팅: target → fallback_target → fullpage (@since engine-v1.24.2)
            //
            // wait_for: progressive 데이터소스를 명시적으로 spinner 가드 대상으로 등록
            // - background/websocket 데이터소스는 의도상 사용자 차단 불가 → 자동 무시
            // - 존재하지 않는 ID 도 자동 무시 (검증은 백엔드 UpdateLayoutContentRequest 에서 수행)
            const waitForIds: string[] = Array.isArray((layoutData.transition_overlay as any)?.wait_for)
                ? ((layoutData.transition_overlay as any).wait_for as string[])
                : [];
            const waitForActive = waitForIds.length > 0 && dataSources.some((source: any) =>
                waitForIds.includes(source.id)
                && source.type !== 'websocket'
                && (source.loading_strategy || 'progressive') !== 'background'
            );
            if ((blockingSources.length > 0 || waitForActive) && layoutData.transition_overlay) {
                const overlayConfig: any = typeof layoutData.transition_overlay === 'boolean'
                    ? { enabled: layoutData.transition_overlay, style: 'opaque' }
                    : layoutData.transition_overlay;
                if (overlayConfig.enabled && overlayConfig.style === 'skeleton' && overlayConfig.skeleton?.component && overlayConfig.target) {
                    this.renderSkeletonOverlay(overlayConfig.target, overlayConfig.skeleton, layoutData, overlayConfig.fallback_target);
                } else if (overlayConfig.enabled && overlayConfig.style === 'spinner' && overlayConfig.target) {
                    this.renderSpinnerOverlay(overlayConfig.target, overlayConfig.spinner, overlayConfig.fallback_target);
                }
            }

            // 3. blocking 데이터 소스 먼저 fetch (렌더링 전에 완료 필요)
            let blockingData: Record<string, any> = {};
            let dataSourceErrors: Record<string, { message: string; status?: number }> = {};
            // initLocal 옵션으로 초기화할 로컬 상태
            let localInit: Record<string, any> = {};
            // initIsolated 옵션으로 초기화할 격리된 상태
            let isolatedInit: Record<string, any> = {};

            // 이전 WebSocket 구독 해제
            if (this.currentWebSocketSubscriptions.length > 0) {
                logger.log('Unsubscribing previous WebSocket subscriptions:', this.currentWebSocketSubscriptions);
                dataSourceManager.unsubscribeWebSockets(this.currentWebSocketSubscriptions);
                this.currentWebSocketSubscriptions = [];
            }

            // 현재 데이터 소스 정보 저장 (refetch용)
            this.currentDataSources = dataSources;
            this.currentRouteParams = route.params || {};
            this.currentQueryParams = queryParams;

            // DEBUG: queryParams 로깅
            logger.log(`handleRouteChange #${routeChangeId} - queryObject:`, queryObject);
            logger.log(`handleRouteChange #${routeChangeId} - queryParams:`, queryParams.toString());

            if (blockingSources.length > 0) {
                logger.log('Fetching blocking data sources:', blockingSources.map((s: any) => s.id));

                // fetchDataSourcesWithResults를 사용하여 에러 상태도 추적
                const results = await dataSourceManager.fetchDataSourcesWithResults(
                    blockingSources,
                    route.params || {},
                    queryParams
                );

                // 결과를 데이터와 에러로 분리
                results.forEach((result) => {
                    if (result.state === 'success' && result.data !== undefined) {
                        blockingData[result.id] = result.data;
                    } else if (result.state === 'error' && result.error) {
                        // Axios 에러인 경우 response.data.message에서 실제 API 응답 메시지 추출
                        const axiosError = result.error as any;
                        const apiMessage = axiosError.response?.data?.message;
                        dataSourceErrors[result.id] = {
                            message: apiMessage || result.error.message,
                            status: axiosError.response?.status || (axiosError as any).status,
                        };
                    }
                });

                // initLocal/initGlobal/initIsolated 처리 (blocking 데이터 소스)
                this.processInitOptions(blockingSources, blockingData, localInit, isolatedInit);

                // fetch된 데이터 저장 (refetch 및 get용)
                this.currentFetchedData = { ...blockingData };

                logger.log('Blocking data loaded:', Object.keys(blockingData));
                if (Object.keys(dataSourceErrors).length > 0) {
                    logger.log('Data source errors:', dataSourceErrors);
                }
                if (Object.keys(localInit).length > 0) {
                    logger.log('Local state init (blocking):', Object.keys(localInit));
                }
                if (Object.keys(isolatedInit).length > 0) {
                    logger.log('Isolated state init (blocking):', Object.keys(isolatedInit));
                }
            }

            // 4. progressive + background 데이터가 있으면 Transition 시작 (렌더링 전)
            // blur_until_loaded 컴포넌트가 blur 효과를 적용할 수 있도록 renderTemplate 전에 설정
            const hasProgressiveData = progressiveAndBackgroundSources.length > 0;
            if (hasProgressiveData) {
                transitionManager.setPending(true);
                logger.log('Transition started before rendering');
            }

            // 새 요청이 들어왔으면 현재 요청 무시 (blocking 데이터 로드 후)
            if (routeChangeId !== this.currentRouteChangeId) {
                logger.log('Route change cancelled after blocking data (newer request exists):', routeChangeId);
                return;
            }

            // 5. 초기 렌더링 (blocking 데이터 + 라우트 파라미터 + 쿼리 파라미터 + 전역 상태)
            // progressive 데이터 소스가 있을 때 이전 데이터 컨텍스트 유지
            // blur_until_loaded 컴포넌트가 이전 데이터를 보여주면서 blur 효과 적용
            const { getState } = await import('./template-engine');
            const previousDataContext = getState().currentDataContext || {};

            // progressive 데이터 소스 ID 목록 추출
            const progressiveDataSourceIds = progressiveAndBackgroundSources.map((s: any) => s.id);

            // progressive 데이터 소스 초기화 (blur_until_loaded가 개별 위젯에서 작동하도록)
            // 각 데이터 소스 키를 undefined로 초기화하여 dataContext에 존재하게 함
            // 이렇게 해야 blur_until_loaded가 자신의 data_sources만 체크할 수 있음
            const progressiveDataInit: Record<string, any> = {};
            if (hasProgressiveData) {
                progressiveDataSourceIds.forEach((id: string) => {
                    // 이전 데이터 컨텍스트에 값이 있으면 유지, 없으면 undefined로 초기화
                    if (previousDataContext[id] !== undefined) {
                        progressiveDataInit[id] = previousDataContext[id];
                    } else {
                        // undefined로 명시적 초기화 (키가 존재해야 blur_until_loaded가 체크 가능)
                        progressiveDataInit[id] = undefined;
                    }
                });

                const preservedKeys = Object.keys(progressiveDataInit).filter(
                    id => progressiveDataInit[id] !== undefined
                );
                if (preservedKeys.length > 0) {
                    logger.log('Preserving previous progressive data:', preservedKeys);
                }
                logger.log('Progressive data sources initialized:', progressiveDataSourceIds);
            }

            // 주의: initialDataContext 생성 시점에 this.globalState를 참조
            // navigate와 toast가 동시에 실행되는 경우, toast가 setGlobalState를 호출하여
            // this.globalState.toasts가 설정될 수 있음. 이를 반영하기 위해
            // renderTemplate 직전에 _global을 다시 갱신함

            // defines 처리: 레이아웃에 정의된 정적 상수를 _defines에 주입
            const definesData = layoutData.defines || {};

            const initialDataContext = {
                ...progressiveDataInit,  // progressive 데이터 소스 초기화 (blur_until_loaded 지원)
                ...route.params,
                ...blockingData,
                route: { ...(route.params || {}), path: route.path },
                query: queryObject,
                _global: { ...this.globalState },  // 나중에 갱신됨
                _globalSetState: (updates: Partial<GlobalState>) => this.setGlobalState(updates),  // Form dataKey="_global.xxx" 지원
                _dataSourceErrors: Object.keys(dataSourceErrors).length > 0 ? dataSourceErrors : undefined,
                // initLocal 옵션으로 초기화할 로컬 상태 (DynamicRenderer에서 처리)
                _localInit: Object.keys(localInit).length > 0 ? localInit : undefined,
                // initIsolated 옵션으로 초기화할 격리된 상태 (IsolatedStateProvider에서 처리)
                _isolatedInit: Object.keys(isolatedInit).length > 0 ? isolatedInit : undefined,
                // defines: 레이아웃에 정의된 정적 상수 (변경 불가)
                _defines: Object.keys(definesData).length > 0 ? definesData : undefined,
            };

            // computed 처리: 레이아웃에 정의된 파생 상태 계산
            if (layoutData.computed && Object.keys(layoutData.computed).length > 0) {
                const computedData = this.calculateComputed(layoutData.computed, initialDataContext);
                if (Object.keys(computedData).length > 0) {
                    (initialDataContext as any)._computed = computedData;
                    // globalState에도 _computed 저장 (DataGrid expandChildren 등에서 접근 가능하도록)
                    this.globalState._computed = computedData;
                    logger.log('Computed values calculated:', Object.keys(computedData));
                }
                // computed 정의를 dataContext에 저장 (DynamicRenderer에서 _local 변경 시 재계산용)
                (initialDataContext as any)._computedDefinitions = layoutData.computed;
            }

            // 레이아웃 전환 감지: 다른 레이아웃이면 _local 완전 초기화
            // (initLocal 유무와 무관하게 항상 실행되어야 함)
            const newLayoutName = layoutData.layout_name || route.layout;
            const isLayoutChanged = this.currentLayoutName !== '' && this.currentLayoutName !== newLayoutName;

            if (isLayoutChanged) {
                // 다른 레이아웃으로 전환 → _local 완전 초기화 (이전 레이아웃 잔존값 제거)
                logger.log('_local reset due to layout change:', { from: this.currentLayoutName, to: newLayoutName });
                this.globalState._local = {};
            }

            this.currentLayoutName = newLayoutName;

            // 레이아웃 레벨 상태 초기화 (정적 초기값 설정)
            // 실행 순서: initLocal/initGlobal/initIsolated → 데이터소스 initLocal/initGlobal/initIsolated → initActions
            // initLocal 또는 state (하위 호환) 처리
            const layoutInitLocal = layoutData.initLocal || layoutData.state;
            if (layoutInitLocal && Object.keys(layoutInitLocal).length > 0) {
                // _local 상태가 없으면 초기화
                if (!this.globalState._local) {
                    this.globalState._local = {};
                }

                // initLocal 블록의 값을 _local에 병합
                // 기존 _local 값이 있으면 유지하고, 없는 키만 initLocal에서 가져옴
                for (const [key, value] of Object.entries(layoutInitLocal)) {
                    if (this.globalState._local[key] === undefined) {
                        this.globalState._local[key] = JSON.parse(JSON.stringify(value));
                    }
                }

                // dataContext에도 반영
                (initialDataContext as any)._local = { ...this.globalState._local };
                logger.log('initLocal applied to _local:', Object.keys(layoutInitLocal));
            }

            // initGlobal 처리 (레이아웃 레벨 _global 초기값)
            if (layoutData.initGlobal && Object.keys(layoutData.initGlobal).length > 0) {
                // initGlobal 블록의 값을 _global에 깊은 병합
                // 기존 _global 값이 있으면 유지하고, 없는 키만 initGlobal에서 가져옴
                for (const [key, value] of Object.entries(layoutData.initGlobal)) {
                    if (this.globalState[key] === undefined) {
                        this.globalState[key] = JSON.parse(JSON.stringify(value));
                    }
                }

                logger.log('initGlobal applied to _global:', Object.keys(layoutData.initGlobal));
            }

            // initIsolated 처리 (레이아웃 레벨 _isolated 초기값)
            // isolatedInit에 저장하여 DynamicRenderer에서 IsolatedStateProvider에 전달
            if (layoutData.initIsolated && Object.keys(layoutData.initIsolated).length > 0) {
                // 기존 isolatedInit과 병합
                for (const [key, value] of Object.entries(layoutData.initIsolated)) {
                    if (isolatedInit[key] === undefined) {
                        isolatedInit[key] = JSON.parse(JSON.stringify(value));
                    }
                }

                logger.log('initIsolated applied:', Object.keys(layoutData.initIsolated));
            }

            // _global을 최신 상태로 갱신 (initLocal/initGlobal/initIsolated 처리 이후)
            // _local 리셋 및 layoutInitLocal 적용이 완료된 상태에서 스냅샷해야
            // SPA 네비게이션 시 이전 페이지의 stale _local 키가 _global._local에 남지 않음
            initialDataContext._global = {
                ...this.globalState,
                // 레이아웃 경고를 _global에 주입 (베이스 레이아웃에서 LayoutWarnings 컴포넌트가 사용)
                layoutWarnings: layoutData.warnings || [],
            };

            // initActions 또는 init_actions 실행 (렌더링 전에 실행하여 _local/_global 초기값 설정)
            // initActions가 우선, init_actions는 하위 호환을 위해 지원
            const initActionsToExecute = layoutData.initActions || layoutData.init_actions;
            if (initActionsToExecute && initActionsToExecute.length > 0) {
                await this.executeInitActions(initActionsToExecute, initialDataContext);
                logger.log('initActions executed before render');

                // initActions에서 설정한 상태를 dataContext에 반영
                const globalState = this.globalState;

                // _local 상태 반영
                if (globalState._local) {
                    // initActions에서 설정한 _local과 processInitOptions에서 설정한 localInit 병합
                    // localInit가 우선 (API 데이터가 init_actions 기본값을 덮어씀)
                    // 이 병합이 없으면 첫 번째 렌더링에서 init_actions의 빈 배열이 사용됨
                    if (Object.keys(localInit).length > 0) {
                        for (const [key, value] of Object.entries(localInit)) {
                            if (globalState._local[key] !== undefined) {
                                globalState._local[key] = this.deepMerge(globalState._local[key], value);
                            } else {
                                globalState._local[key] = value;
                            }
                        }
                        logger.log('localInit merged into _local after initActions:', Object.keys(localInit));
                    }
                    (initialDataContext as any)._local = globalState._local;
                    logger.log('_local merged into dataContext:', globalState._local);
                }

                // _global 상태 반영 (dataKey="_global.xxx" 지원)
                // initActions에서 setState target: "global"로 설정한 값들을 반영
                initialDataContext._global = {
                    ...this.globalState,
                    layoutWarnings: layoutData.warnings || [],
                };
                logger.log('_global merged into dataContext after initActions');

                // initActions 실행 후 computed 재계산 (initActions에서 설정한 _local/_global 값 반영)
                if (layoutData.computed && Object.keys(layoutData.computed).length > 0) {
                    const computedData = this.calculateComputed(layoutData.computed, initialDataContext);
                    if (Object.keys(computedData).length > 0) {
                        (initialDataContext as any)._computed = computedData;
                        this.globalState._computed = computedData;
                        logger.log('Computed values recalculated after init_actions:', Object.keys(computedData));
                    }
                }

                // initActions에서 refetchDataSource로 로드된 데이터소스 데이터를 dataContext에 반영
                // 이 처리가 없으면 refetchDataSource가 updateTemplateData를 호출해도
                // renderTemplate에서 dataContext가 새로 설정되면서 데이터 손실 발생
                // (troubleshooting-state-global.md 사례 3 참조)
                if (Object.keys(this.currentFetchedData).length > 0) {
                    for (const [dsId, dsData] of Object.entries(this.currentFetchedData)) {
                        (initialDataContext as any)[dsId] = dsData;
                    }
                    logger.log('Fetched data sources merged into dataContext after initActions:', Object.keys(this.currentFetchedData));
                }
            }

            // transition_overlay: 레이아웃 전환 시 stale DOM 방지 오버레이 (@since engine-v1.23.0)
            // skeleton 스타일은 blocking fetch 전에 이미 표시됨 (step 2.5)
            // 여기서는 non-skeleton 스타일(opaque/blur/fade)만 처리
            if (layoutData.transition_overlay && !this.skeletonOverlayContainer) {
                this.showTransitionOverlay(layoutData.transition_overlay, layoutData);
            }

            await renderTemplate({
                containerId: 'app',
                layoutJson: layoutData,
                dataContext: initialDataContext,
                translationContext: {
                    templateId: this.config.templateId,
                    locale: this.config.locale,
                },
            });

            logger.log('Initial render complete with blocking data');

            // renderTemplate이 #app DOM을 교체하면 타겟 내부의 spinner도 사라짐
            // 새 DOM의 타겟에 spinner를 재생성 (@since engine-v1.29.0)
            this.reattachSpinnerOverlay();

            // 6. progressive + background 데이터 소스 fetch (렌더링 후)
            if (progressiveAndBackgroundSources.length > 0) {
                // 새 요청이 들어왔으면 현재 요청 무시 (progressive fetch 전)
                if (routeChangeId !== this.currentRouteChangeId) {
                    logger.log('Route change cancelled before progressive fetch (newer request exists):', routeChangeId);
                    // transition 상태도 정리
                    transitionManager.setPending(false);
                    this.hideTransitionOverlay();
                    return;
                }

                logger.log('Fetching progressive/background data sources:',
                    progressiveAndBackgroundSources.map((s: any) => s.id));

                const { updateTemplateData, getState } = await import('./template-engine');

                try {
                    // progressive/background 데이터를 개별적으로 fetch하고 완료될 때마다 업데이트
                    // 각 데이터 소스가 완료되면 해당 위젯의 blur만 해제됨
                    const fetchPromises = progressiveAndBackgroundSources.map(async (source: any) => {
                        try {
                            const results = await dataSourceManager.fetchDataSourcesWithResults(
                                [source],
                                route.params || {},
                                queryParams,
                                this.globalState  // blocking 데이터 소스의 initGlobal로 설정된 값 접근 지원
                            );

                            const result = results[0];

                            // 결과가 없으면 무시 (websocket 타입 등 fetch 대상이 아닌 경우)
                            if (!result) {
                                logger.log(`Data source ${source.id} skipped (no fetch result)`);
                                return;
                            }

                            // 새 요청이 들어왔으면 현재 요청 무시
                            if (routeChangeId !== this.currentRouteChangeId) {
                                logger.log(`Data source ${source.id} fetch cancelled (newer request exists)`);
                                return;
                            }

                            if (result.state === 'success' && result.data !== undefined) {
                                // 개별 데이터 소스 로드 완료 - 즉시 업데이트하여 해당 위젯 blur 해제
                                logger.log(`Progressive data source loaded: ${source.id}`);

                                // fetch된 데이터 업데이트
                                this.currentFetchedData[source.id] = result.data;

                                // initLocal/initGlobal 처리
                                const singleLocalInit: Record<string, any> = {};
                                this.processInitOptions([source], { [source.id]: result.data }, singleLocalInit);

                                // 캐시 무효화
                                const state = getState();
                                if (state.bindingEngine) {
                                    const keysToInvalidate = [source.id];
                                    // initGlobal이 있으면 _global 키도 무효화
                                    if (source.initGlobal) {
                                        keysToInvalidate.push('_global');
                                    }
                                    // initLocal이 있으면 _local 키도 무효화
                                    if (source.initLocal) {
                                        keysToInvalidate.push('_local');
                                    }
                                    state.bindingEngine.invalidateCacheByKeys(keysToInvalidate);
                                }

                                // 개별 데이터 소스 업데이트 (해당 위젯 blur 해제)
                                const singleUpdateData: Record<string, any> = {
                                    [source.id]: result.data,
                                };

                                // initLocal이 있으면 추가
                                if (Object.keys(singleLocalInit).length > 0) {
                                    singleUpdateData._localInit = singleLocalInit;
                                    Object.assign(localInit, singleLocalInit);
                                }

                                // initGlobal이 있으면 _global 상태도 업데이트
                                if (source.initGlobal) {
                                    singleUpdateData._global = { ...this.globalState };
                                }

                                updateTemplateData(singleUpdateData);

                            } else if (result.state === 'error' && result.error) {
                                // 에러 처리
                                const axiosError = result.error as any;
                                const apiMessage = axiosError.response?.data?.message;
                                dataSourceErrors[source.id] = {
                                    message: apiMessage || result.error.message,
                                    status: axiosError.response?.status || (axiosError as any).status,
                                };
                                logger.log(`Progressive data source error: ${source.id}`, dataSourceErrors[source.id]);

                                // 에러가 발생해도 undefined가 아닌 에러 상태로 표시하여 blur 해제
                                // _dataSourceErrors를 업데이트하여 컴포넌트에서 에러 상태 확인 가능
                                updateTemplateData({
                                    [source.id]: null, // null로 설정하여 로딩 완료 표시 (에러 상태)
                                    _dataSourceErrors: { ...dataSourceErrors },
                                });
                            }
                        } catch (error) {
                            logger.error(`Failed to fetch data source: ${source.id}`, error);
                        }
                    });

                    // 모든 fetch 완료 대기
                    await Promise.all(fetchPromises);

                    // 새 요청이 들어왔으면 현재 요청 무시 (progressive fetch 후)
                    if (routeChangeId !== this.currentRouteChangeId) {
                        logger.log('Route change cancelled after progressive fetch (newer request exists):', routeChangeId);
                        return;
                    }

                    logger.log('All progressive data sources loaded');
                } finally {
                    // Transition 종료 (isPending = false)
                    transitionManager.setPending(false);
                    this.hideTransitionOverlay();
                }
            } else {
                // progressive 데이터가 없으면 즉시 오버레이 제거
                this.hideTransitionOverlay();
            }

            // 7. WebSocket 데이터 소스 구독 (실시간 업데이트용)
            // progressive fetch 완료 후 구독: channel/event 표현식이 fetched 데이터(current_user 등) 참조 가능
            const webSocketSources = dataSources.filter((s: any) => s.type === 'websocket');
            if (webSocketSources.length > 0) {
                logger.log('Subscribing WebSocket data sources:', webSocketSources.map((s: any) => s.id));

                const { updateTemplateData: wsUpdateData, getState: wsGetState } = await import('./template-engine');

                // 채널/이벤트 표현식 평가 컨텍스트
                // blocking + progressive 데이터 모두 포함 (표현식이 fetched 데이터 참조 가능)
                const wsBindingContext = {
                    ...this.currentFetchedData,
                    ...route.params,
                    route: { ...(route.params || {}), path: route.path },
                    query: queryObject,
                    _global: { ...this.globalState },
                };

                // 디버깅: WebSocket 구독 직전 컨텍스트 키 로깅
                // 표현식 평가 실패 시 어떤 키가 누락되었는지 확인용
                logger.log('WebSocket binding context keys:', Object.keys(wsBindingContext));

                this.currentWebSocketSubscriptions = dataSourceManager.subscribeWebSockets(
                    dataSources,
                    (sourceId: string, data: unknown) => {
                        // WebSocket에서 데이터 수신 시 템플릿 업데이트
                        logger.log(`WebSocket data received for: ${sourceId}`, data);

                        // fetch된 데이터 업데이트
                        this.currentFetchedData[sourceId] = data;

                        // 타겟 데이터 소스 정의 찾기 (initGlobal/initLocal 캐시 무효화용)
                        const targetSource = dataSources.find((s: any) => s.id === sourceId);

                        // 캐시 무효화
                        const state = wsGetState();
                        if (state.bindingEngine) {
                            const keysToInvalidate = [sourceId];
                            // 타겟 데이터 소스에 initGlobal이 있으면 _global 키도 무효화
                            if (targetSource?.initGlobal) {
                                keysToInvalidate.push('_global');
                            }
                            // 타겟 데이터 소스에 initLocal이 있으면 _local 키도 무효화
                            if (targetSource?.initLocal) {
                                keysToInvalidate.push('_local');
                            }
                            state.bindingEngine.invalidateCacheByKeys(keysToInvalidate);
                        }

                        // 템플릿 업데이트
                        wsUpdateData({ [sourceId]: data });

                        // engine-v1.33.0: WebSocket 데이터 소스의 onReceive 액션 실행
                        // websocket 소스 정의(원본)에서 onReceive 배열을 찾음
                        // sourceId는 target_source가 적용된 ID이므로 원본 websocket 소스를 별도로 찾음
                        const websocketSource = dataSources.find(
                            (s: any) => s.type === 'websocket' && (s.target_source || s.id) === sourceId
                        );
                        const onReceiveActions = websocketSource?.onReceive;
                        if (Array.isArray(onReceiveActions) && onReceiveActions.length > 0) {
                            const G7Core = (window as any).G7Core;
                            if (G7Core?.dispatch) {
                                // 각 액션을 순차 실행, $args[0]로 페이로드 접근 가능
                                (async () => {
                                    for (const action of onReceiveActions) {
                                        try {
                                            // dispatchAction에 직접 호출하여 $args 컨텍스트 주입
                                            const actionDispatcher = this.getActionDispatcher?.();
                                            if (actionDispatcher) {
                                                await actionDispatcher.dispatchAction(action, {
                                                    navigate: this.getRouter?.() ? (path: string, opts?: any) =>
                                                        this.getRouter().navigate(path, opts) : undefined,
                                                    setState: (updates: any) => this.setGlobalState(updates),
                                                    state: this.globalState,
                                                    data: { ...this.globalState, $args: [data], $event: data },
                                                    _isDispatchFallbackContext: true,
                                                });
                                            }
                                        } catch (err) {
                                            logger.error(
                                                `WebSocket onReceive action failed for ${sourceId}:`,
                                                err
                                            );
                                        }
                                    }
                                })();
                            }
                        }
                    },
                    wsBindingContext
                );

                logger.log('WebSocket subscriptions established:', this.currentWebSocketSubscriptions);
            }

            logger.log('Layout rendered successfully');
        } catch (error) {
            logger.error('Route change handling failed:', error);
            this.hideTransitionOverlay();
            this.showRouteError(error as Error);
        }
    }

    /**
     * initLocal/initGlobal/initIsolated 옵션 처리
     *
     * 데이터 소스의 initLocal/initGlobal/initIsolated 옵션에 따라
     * 응답 데이터를 로컬/전역/격리된 상태에 복사합니다.
     *
     * initLocalDefaults 지원:
     * - initLocalDefaults 객체의 각 키-값을 기본값으로 사용
     * - API 응답 데이터와 병합하여 API 값이 없으면 기본값 사용
     * - 값이 {{...}} 표현식이면 fetchedData를 컨텍스트로 평가
     *
     * refetchOnMount: true인 데이터 소스가 있으면 _forceLocalInit 플래그를 추가하여
     * DynamicRenderer에서 해시 비교 없이 강제로 로컬 상태를 초기화하도록 합니다.
     *
     * @param dataSources 데이터 소스 배열
     * @param fetchedData fetch된 데이터 (sourceId -> data)
     * @param localInit 로컬 상태 초기화 객체 (mutate됨)
     * @param isolatedInit 격리된 상태 초기화 객체 (선택적, mutate됨)
     */
    private processInitOptions(
        dataSources: any[],
        fetchedData: Record<string, any>,
        localInit: Record<string, any>,
        isolatedInit?: Record<string, any>
    ): void {
        // refetchOnMount: true인 데이터 소스가 있는지 확인
        const hasRefetchOnMount = dataSources.some(
            (source: any) => source.refetchOnMount === true && source.initLocal
        );

        // refetchOnMount가 있으면 강제 초기화 플래그 추가
        if (hasRefetchOnMount) {
            localInit._forceLocalInit = Date.now();
            logger.log('refetchOnMount detected, forcing local state init');
        }

        dataSources.forEach((source: any) => {
            const data = fetchedData[source.id];
            if (!data) {
                return;
            }

            // API 응답에서 실제 데이터 추출 (data.data 또는 data 자체)
            const actualData = data.data ?? data;

            // initLocal 처리: _local[key]에 데이터 복사 (깊은 병합)
            // 레이아웃 레벨 initLocal 기본값이 있으면 유지하고, 데이터소스 값과 병합
            // 문자열 형태: 전체 데이터 저장
            // 객체 형태 { key, path }: 특정 필드만 저장
            // 맵 형태 { targetKey: pathOrExpression }: 여러 필드를 각각 저장 (engine-v1.7.0+)
            if (source.initLocal) {
                if (typeof source.initLocal === 'string') {
                    let mergedData = actualData;

                    // initLocalDefaults가 있으면 기본값과 병합
                    if (source.initLocalDefaults && typeof source.initLocalDefaults === 'object') {
                        const defaults = this.evaluateDefaults(source.initLocalDefaults, fetchedData);
                        // 기본값을 먼저 적용하고, API 데이터로 덮어쓰기 (API 값 우선)
                        mergedData = { ...defaults, ...actualData };

                        logger.log(`initLocalDefaults applied for ${source.initLocal}:`, Object.keys(defaults));
                    }

                    // 레이아웃 레벨 initLocal 기본값과 깊은 병합
                    // 기존 _local[key] 값이 있으면 기본값으로 유지, API 응답 값과 병합
                    const existingValue = this.globalState._local?.[source.initLocal];
                    if (existingValue !== undefined && typeof existingValue === 'object' && typeof mergedData === 'object') {
                        localInit[source.initLocal] = this.deepMerge(existingValue, mergedData);
                        logger.log(`initLocal (merged): ${source.id}.data -> _local.${source.initLocal}`);
                    } else {
                        localInit[source.initLocal] = mergedData;
                        logger.log(`initLocal: ${source.id}.data -> _local.${source.initLocal}`);
                    }
                } else if (typeof source.initLocal === 'object' && source.initLocal.key) {
                    // 레거시 형식: { key: "targetKey", path: "data.path" }
                    const { key, path } = source.initLocal;
                    // path가 지정되면 해당 경로의 데이터만 추출 (표현식 지원)
                    let targetData = path ? this.extractValueByPathOrExpression(actualData, path, source.id) : actualData;

                    // initLocalDefaults가 있으면 기본값과 병합
                    if (source.initLocalDefaults && typeof source.initLocalDefaults === 'object') {
                        const defaults = this.evaluateDefaults(source.initLocalDefaults, fetchedData);
                        targetData = { ...defaults, ...targetData };

                        logger.log(`initLocalDefaults applied for ${key}:`, Object.keys(defaults));
                    }

                    // 레이아웃 레벨 initLocal 기본값과 깊은 병합
                    const existingValue = this.globalState._local?.[key];
                    if (existingValue !== undefined && typeof existingValue === 'object' && typeof targetData === 'object') {
                        localInit[key] = this.deepMerge(existingValue, targetData);
                        logger.log(`initLocal (merged): ${source.id}.data${path ? '.' + path : ''} -> _local.${key}`);
                    } else {
                        localInit[key] = targetData;
                        logger.log(`initLocal: ${source.id}.data${path ? '.' + path : ''} -> _local.${key}`);
                    }
                } else if (typeof source.initLocal === 'object') {
                    // 맵 형식 (engine-v1.7.0+): 다양한 표기법 지원
                    // 1. 평탄 맵: { "selectedItems": "data.item_ids" }
                    // 2. dot notation 타겟: { "checkout.item_coupons": "data.promotions.item_coupons" }
                    // 3. 중첩 객체: { "checkout": { "item_coupons": "data.promotions.item_coupons" } }
                    // 4. 병합 전략: { "_merge": "deep" | "shallow" | "replace", "key": "path" }

                    // 병합 전략 추출 (기본값: deep)
                    const mergeStrategy = (source.initLocal._merge as 'deep' | 'shallow' | 'replace') || 'deep';

                    // 중첩 객체 표기법을 dot notation으로 평탄화
                    const mappings = this.flattenNestedObjectToMappings(source.initLocal);

                    for (const { targetPath, sourcePath } of mappings) {
                        // 경로 또는 표현식에서 값 추출
                        const targetData = this.extractValueByPathOrExpression(actualData, sourcePath, source.id);

                        // dot notation 경로 처리
                        if (targetPath.includes('.')) {
                            // 중첩 경로: 기존 _local 값을 먼저 복사 후 setValueAtPath로 병합
                            const rootKey = targetPath.split('.')[0];

                            // localInit에 rootKey가 없으면 기존 _local 값 복사
                            if (localInit[rootKey] === undefined && this.globalState._local?.[rootKey] !== undefined) {
                                // 기존 _local 값을 깊은 복사하여 localInit에 설정
                                localInit[rootKey] = JSON.parse(JSON.stringify(this.globalState._local[rootKey]));
                            }

                            // 이제 setValueAtPath로 중첩 경로에 값 설정
                            this.setValueAtPath(localInit, targetPath, targetData, mergeStrategy);
                            logger.log(`initLocal map (${mergeStrategy}): ${source.id} ${sourcePath} -> _local.${targetPath}`);
                        } else {
                            // 단일 키: 기존 로직 (레이아웃 레벨 기본값과 병합)
                            const existingValue = this.globalState._local?.[targetPath];
                            if (mergeStrategy === 'replace') {
                                localInit[targetPath] = targetData;
                                logger.log(`initLocal map (replace): ${source.id} ${sourcePath} -> _local.${targetPath}`);
                            } else if (mergeStrategy === 'shallow') {
                                if (existingValue !== undefined && typeof existingValue === 'object' && typeof targetData === 'object') {
                                    localInit[targetPath] = { ...existingValue, ...targetData };
                                    logger.log(`initLocal map (shallow): ${source.id} ${sourcePath} -> _local.${targetPath}`);
                                } else {
                                    localInit[targetPath] = targetData;
                                    logger.log(`initLocal map: ${source.id} ${sourcePath} -> _local.${targetPath}`);
                                }
                            } else {
                                // deep merge (기본값)
                                if (existingValue !== undefined && typeof existingValue === 'object' && typeof targetData === 'object') {
                                    localInit[targetPath] = this.deepMerge(existingValue, targetData);
                                    logger.log(`initLocal map (deep): ${source.id} ${sourcePath} -> _local.${targetPath}`);
                                } else {
                                    localInit[targetPath] = targetData;
                                    logger.log(`initLocal map: ${source.id} ${sourcePath} -> _local.${targetPath}`);
                                }
                            }
                        }
                    }
                }
            }

            // initGlobal 처리: _global[key]에 데이터 복사 (깊은 병합)
            // 레이아웃 레벨 initGlobal 기본값이 있으면 유지하고, 데이터소스 값과 병합
            // 배열 형태: 여러 전역 상태 동시 초기화
            // 문자열 형태: 전체 데이터 저장
            // 객체 형태 { key, path }: 특정 필드만 저장
            // 맵 형태 { targetKey: pathOrExpression }: 여러 필드를 각각 저장 (engine-v1.7.0+)
            if (source.initGlobal) {
                // 맵 형식인지 확인: 배열이 아니고, 객체이고, 'key' 프로퍼티가 없음
                const isMapFormat = typeof source.initGlobal === 'object' &&
                    !Array.isArray(source.initGlobal) &&
                    !('key' in source.initGlobal);

                if (isMapFormat) {
                    // 맵 형식 (engine-v1.7.0+): { targetKey1: pathOrExpression1, targetKey2: pathOrExpression2 }
                    for (const [targetKey, pathOrExpression] of Object.entries(source.initGlobal as Record<string, string>)) {
                        if (typeof pathOrExpression !== 'string') {
                            logger.warn(`initGlobal map value must be string, got ${typeof pathOrExpression} for key ${targetKey}`);
                            continue;
                        }

                        // 경로 또는 표현식에서 값 추출
                        const targetData = this.extractValueByPathOrExpression(actualData, pathOrExpression, source.id);

                        // 레이아웃 레벨 initGlobal 기본값과 깊은 병합
                        const existingValue = this.globalState[targetKey];
                        if (existingValue !== undefined && typeof existingValue === 'object' && typeof targetData === 'object') {
                            this.globalState[targetKey] = this.deepMerge(existingValue, targetData);
                            logger.log(`initGlobal map (merged): ${source.id} ${pathOrExpression} -> _global.${targetKey}`);
                        } else {
                            this.globalState[targetKey] = targetData;
                            logger.log(`initGlobal map: ${source.id} ${pathOrExpression} -> _global.${targetKey}`);
                        }
                    }
                } else {
                    // 기존 형식: 배열 또는 단일 값
                    const initGlobalItems = Array.isArray(source.initGlobal)
                        ? source.initGlobal
                        : [source.initGlobal];

                    for (const item of initGlobalItems) {
                        if (typeof item === 'string') {
                            // 레이아웃 레벨 initGlobal 기본값과 깊은 병합
                            const existingValue = this.globalState[item];
                            if (existingValue !== undefined && typeof existingValue === 'object' && typeof actualData === 'object') {
                                this.globalState[item] = this.deepMerge(existingValue, actualData);
                                logger.log(`initGlobal (merged): ${source.id}.data -> _global.${item}`);
                            } else {
                                this.globalState[item] = actualData;
                                logger.log(`initGlobal: ${source.id}.data -> _global.${item}`);
                            }
                        } else if (typeof item === 'object' && item.key) {
                            const { key, path } = item;
                            // path가 지정되면 해당 경로의 데이터만 추출 (표현식 지원)
                            const targetData = path ? this.extractValueByPathOrExpression(actualData, path, source.id) : actualData;

                            // 레이아웃 레벨 initGlobal 기본값과 깊은 병합
                            const existingValue = this.globalState[key];
                            if (existingValue !== undefined && typeof existingValue === 'object' && typeof targetData === 'object') {
                                this.globalState[key] = this.deepMerge(existingValue, targetData);
                                logger.log(`initGlobal (merged): ${source.id}.data${path ? '.' + path : ''} -> _global.${key}`);
                            } else {
                                this.globalState[key] = targetData;
                                logger.log(`initGlobal: ${source.id}.data${path ? '.' + path : ''} -> _global.${key}`);
                            }
                        }
                    }
                }
            }

            // initIsolated 처리: _isolated[key]에 데이터 복사 (깊은 병합)
            // 레이아웃 레벨 initIsolated 기본값이 있으면 유지하고, 데이터소스 값과 병합
            // isolatedState 속성이 정의된 컴포넌트에서만 유효
            // 맵 형태 { targetKey: pathOrExpression }: 여러 필드를 각각 저장 (engine-v1.7.0+)
            if (isolatedInit && source.initIsolated) {
                if (typeof source.initIsolated === 'string') {
                    // 레이아웃 레벨 initIsolated 기본값과 깊은 병합
                    const existingValue = isolatedInit[source.initIsolated];
                    if (existingValue !== undefined && typeof existingValue === 'object' && typeof actualData === 'object') {
                        isolatedInit[source.initIsolated] = this.deepMerge(existingValue, actualData);
                        logger.log(`initIsolated (merged): ${source.id}.data -> _isolated.${source.initIsolated}`);
                    } else {
                        isolatedInit[source.initIsolated] = actualData;
                        logger.log(`initIsolated: ${source.id}.data -> _isolated.${source.initIsolated}`);
                    }
                } else if (typeof source.initIsolated === 'object' && source.initIsolated.key) {
                    // 레거시 형식: { key: "targetKey", path: "data.path" }
                    const { key, path } = source.initIsolated;
                    // path가 지정되면 해당 경로의 데이터만 추출 (표현식 지원)
                    const targetData = path ? this.extractValueByPathOrExpression(actualData, path, source.id) : actualData;

                    // 레이아웃 레벨 initIsolated 기본값과 깊은 병합
                    const existingValue = isolatedInit[key];
                    if (existingValue !== undefined && typeof existingValue === 'object' && typeof targetData === 'object') {
                        isolatedInit[key] = this.deepMerge(existingValue, targetData);
                        logger.log(`initIsolated (merged): ${source.id}.data${path ? '.' + path : ''} -> _isolated.${key}`);
                    } else {
                        isolatedInit[key] = targetData;
                        logger.log(`initIsolated: ${source.id}.data${path ? '.' + path : ''} -> _isolated.${key}`);
                    }
                } else if (typeof source.initIsolated === 'object') {
                    // 맵 형식 (engine-v1.7.0+): { targetKey1: pathOrExpression1, targetKey2: pathOrExpression2 }
                    for (const [targetKey, pathOrExpression] of Object.entries(source.initIsolated)) {
                        if (typeof pathOrExpression !== 'string') {
                            logger.warn(`initIsolated map value must be string, got ${typeof pathOrExpression} for key ${targetKey}`);
                            continue;
                        }

                        // 경로 또는 표현식에서 값 추출
                        const targetData = this.extractValueByPathOrExpression(actualData, pathOrExpression, source.id);

                        // 레이아웃 레벨 initIsolated 기본값과 깊은 병합
                        const existingValue = isolatedInit[targetKey];
                        if (existingValue !== undefined && typeof existingValue === 'object' && typeof targetData === 'object') {
                            isolatedInit[targetKey] = this.deepMerge(existingValue, targetData);
                            logger.log(`initIsolated map (merged): ${source.id} ${pathOrExpression} -> _isolated.${targetKey}`);
                        } else {
                            isolatedInit[targetKey] = targetData;
                            logger.log(`initIsolated map: ${source.id} ${pathOrExpression} -> _isolated.${targetKey}`);
                        }
                    }
                }
            }
        });
    }

    /**
     * initLocalDefaults의 각 값을 평가
     *
     * {{...}} 표현식이면 fetchedData를 컨텍스트로 평가하고,
     * 그렇지 않으면 원본 값을 그대로 사용합니다.
     *
     * @param defaults 기본값 객체
     * @param fetchedData fetch된 데이터 (표현식 평가 컨텍스트)
     * @returns 평가된 기본값 객체
     */
    private evaluateDefaults(
        defaults: Record<string, any>,
        fetchedData: Record<string, any>
    ): Record<string, any> {
        const result: Record<string, any> = {};

        for (const [key, value] of Object.entries(defaults)) {
            if (typeof value === 'string' && value.startsWith('{{') && value.endsWith('}}')) {
                // {{...}} 표현식 평가
                const expression = value.slice(2, -2).trim();
                result[key] = this.evaluateExpression(expression, fetchedData);
            } else {
                // 그대로 사용
                result[key] = value;
            }
        }

        return result;
    }

    /**
     * 간단한 표현식 평가
     *
     * 점(.) 표기법과 옵셔널 체이닝(?.)을 지원하며,
     * nullish 병합 연산자(??)도 지원합니다.
     *
     * @param expression 평가할 표현식 (예: "board_config?.data?.defaults?.type ?? 'basic'")
     * @param context 평가 컨텍스트
     * @returns 평가 결과
     */
    private evaluateExpression(expression: string, context: Record<string, any>): any {
        try {
            // nullish 병합 연산자 처리
            const nullishParts = expression.split('??').map(p => p.trim());

            for (const part of nullishParts) {
                // 리터럴 값 체크 (따옴표로 감싸진 문자열, 숫자, boolean)
                if (/^['"].*['"]$/.test(part)) {
                    return part.slice(1, -1); // 따옴표 제거
                }
                if (part === 'true') return true;
                if (part === 'false') return false;
                if (/^-?\d+(\.\d+)?$/.test(part)) return Number(part);

                // 경로 평가
                const value = this.getNestedValue(context, part);
                if (value !== undefined && value !== null) {
                    return value;
                }
            }

            return undefined;
        } catch (error) {
            logger.warn(`Expression evaluation failed: ${expression}`, error);
            return undefined;
        }
    }

    /**
     * 중첩 객체에서 값 가져오기
     *
     * 점(.) 표기법과 옵셔널 체이닝(?.)을 지원합니다.
     *
     * @param obj 대상 객체
     * @param path 경로 (예: "board_config?.data?.defaults?.type")
     * @returns 찾은 값 또는 undefined
     */
    private getNestedValue(obj: any, path: string): any {
        // ?. 를 . 로 정규화
        const normalizedPath = path.replace(/\?\./g, '.');

        let current = obj;

        // 경로를 파싱하여 각 부분 추출 (배열 인덱스 포함)
        // "[0].name" → ["[0]", "name"]
        // "data[0].name" → ["data", "[0]", "name"]
        // "items[0][1].value" → ["items", "[0]", "[1]", "value"]
        const parts = normalizedPath.split(/\.(?![^\[]*\])/).flatMap(part => {
            // 배열 인덱스 분리: "data[0]" → ["data", "[0]"]
            const matches = part.match(/^([^\[]*)((?:\[\d+\])*)$/);
            if (matches) {
                const [, base, indices] = matches;
                const result: string[] = [];
                if (base) result.push(base);
                // "[0][1]" → ["[0]", "[1]"]
                const indexMatches = indices.match(/\[\d+\]/g);
                if (indexMatches) result.push(...indexMatches);
                return result;
            }
            return [part];
        }).filter(p => p !== '');

        for (const part of parts) {
            if (current === undefined || current === null) {
                return undefined;
            }

            // 배열 인덱스 처리: "[0]" → 0
            if (part.startsWith('[') && part.endsWith(']')) {
                const index = parseInt(part.slice(1, -1), 10);
                current = current[index];
            } else {
                current = current[part];
            }
        }

        return current;
    }

    /**
     * 경로 또는 표현식에서 값 추출
     *
     * 경로가 {{...}} 형식이면 표현식으로 평가하고,
     * 그렇지 않으면 일반 경로로 처리합니다.
     *
     * @param obj 대상 객체 (API 응답 데이터)
     * @param pathOrExpression 경로 또는 표현식
     * @param sourceId 데이터 소스 ID (로깅용)
     * @returns 추출된 값
     *
     * @example
     * // 일반 경로
     * extractValueByPathOrExpression(data, "data.items", "cart")
     *
     * @example
     * // 표현식
     * extractValueByPathOrExpression(data, "{{data.items.map(i => i.id)}}", "cart")
     */
    private extractValueByPathOrExpression(obj: any, pathOrExpression: string, sourceId: string): any {
        // 표현식 패턴 확인: {{...}}
        const expressionMatch = pathOrExpression.match(/^\{\{(.+)\}\}$/);

        if (expressionMatch) {
            // 표현식으로 평가
            const expression = expressionMatch[1].trim();
            const bindingEngine = new DataBindingEngine();

            // 컨텍스트 구성: data 변수로 API 응답 접근 가능
            const context: Record<string, any> = {
                data: obj,
                // _global, _local도 컨텍스트에 추가
                _global: this.globalState,
                _local: this.globalState._local || {},
            };

            try {
                const result = bindingEngine.evaluateExpression(expression, context);
                logger.log(`initLocal/initGlobal expression evaluated: ${pathOrExpression} -> `, result);
                return result;
            } catch (error) {
                logger.warn(`initLocal/initGlobal expression evaluation failed for ${sourceId}:`, pathOrExpression, error);
                return undefined;
            }
        }

        // 일반 경로로 처리
        return this.getNestedValue(obj, pathOrExpression);
    }

    /**
     * 레이아웃의 외부 스크립트 로드
     *
     * scripts 속성에 정의된 외부 스크립트를 동적으로 로드합니다.
     * if 조건이 있는 경우 조건을 평가하여 조건을 만족할 때만 로드합니다.
     * 이미 로드된 스크립트(같은 id)는 중복 로드하지 않습니다.
     *
     * @param scripts 스크립트 정의 배열
     * @param conditionContext 조건 평가를 위한 컨텍스트 (route, query, _global)
     */
    private async loadLayoutScripts(
        scripts: LayoutScript[],
        conditionContext: Record<string, any>
    ): Promise<void> {
        const loadPromises: Promise<void>[] = [];

        for (const script of scripts) {
            // 조건 체크 (if 또는 conditions)
            // evaluateRenderCondition은 if가 우선, 둘 다 없으면 true 반환
            if (script.if !== undefined || script.conditions !== undefined) {
                const shouldLoad = evaluateRenderCondition(
                    { if: script.if, conditions: script.conditions },
                    conditionContext,
                    this.bindingEngine,
                    `script:${script.id}`
                );
                if (!shouldLoad) {
                    logger.log(`Script skipped (condition not met): ${script.id}`);
                    continue;
                }
            }

            // 이미 로드된 스크립트는 건너뛰기
            const existingScript = document.getElementById(script.id);
            if (existingScript) {
                logger.log(`Script already loaded: ${script.id}`);
                continue;
            }

            // 스크립트 동적 로드 (Promise로 래핑)
            const loadPromise = new Promise<void>((resolve, reject) => {
                const scriptEl = document.createElement('script');
                scriptEl.src = script.src;
                scriptEl.id = script.id;
                scriptEl.async = script.async ?? true;

                scriptEl.onload = () => {
                    logger.log(`Script loaded successfully: ${script.id}`);
                    resolve();
                };

                scriptEl.onerror = () => {
                    logger.warn(`Failed to load script: ${script.id} (${script.src})`);
                    // 스크립트 로드 실패는 경고만 출력하고 계속 진행
                    resolve();
                };

                document.head.appendChild(scriptEl);
            });

            loadPromises.push(loadPromise);
        }

        // 모든 스크립트 로드 완료 대기
        if (loadPromises.length > 0) {
            await Promise.all(loadPromises);
            logger.log(`All scripts loaded: ${scripts.filter(s => !document.getElementById(s.id) || loadPromises.length > 0).map(s => s.id).join(', ')}`);
        }
    }

    /**
     * 스크립트 조건 평가
     *
     * {{...}} 형태의 표현식을 평가합니다.
     *
     * @param condition 조건 표현식 (예: "{{_global.pluginActive}}")
     * @param context 평가 컨텍스트
     * @returns 조건 평가 결과 (truthy/falsy)
     */
    private evaluateScriptCondition(condition: string, context: Record<string, any>): boolean {
        try {
            // {{...}} 형태에서 표현식 추출
            if (condition.startsWith('{{') && condition.endsWith('}}')) {
                const expression = condition.slice(2, -2).trim();

                // 간단한 표현식 평가 (점 표기법, 옵셔널 체이닝, 메서드 호출)
                // Function 생성자를 사용하여 안전하게 평가
                // eslint-disable-next-line @typescript-eslint/no-implied-eval
                const fn = new Function('ctx', `
                    with(ctx) {
                        try {
                            return Boolean(${expression});
                        } catch (e) {
                            return false;
                        }
                    }
                `);

                return fn(context);
            }

            // {{}} 형태가 아니면 truthy 체크
            return Boolean(condition);
        } catch (error) {
            logger.warn(`Failed to evaluate script condition: ${condition}`, error);
            return false;
        }
    }

    /**
     * 초기화 에러 화면 표시
     */
    private showInitError(error: Error): void {
        // Error를 TemplateEngineError로 변환
        const templateError = toTemplateEngineError(error);

        // ErrorDisplay를 사용하여 렌더링
        // 원본 에러도 전달하여 상세 메시지 추출 가능하도록 함
        ErrorDisplay.renderFromError(
            'app',
            templateError,
            '초기화 실패',
            this.config.debug,
            {
                templateId: this.config.templateId,
                locale: this.config.locale,
            },
            undefined, // translationEngine
            error // originalError
        );
    }

    /**
     * 레이아웃 전환 오버레이 표시 (순수 DOM 조작)
     *
     * React 렌더 사이클과 무관하게 즉시 적용됩니다.
     *
     * @since engine-v1.23.0
     */
    private showTransitionOverlay(config: boolean | { enabled: boolean; style?: string; target?: string; skeleton?: { component: string; animation?: string; iteration_count?: number }; spinner?: { component?: string; text?: string } }, layoutData?: any): void {
        // config 정규화
        const normalized = typeof config === 'boolean'
            ? { enabled: config, style: 'opaque' as const, target: undefined as string | undefined, skeleton: undefined as any, spinner: undefined as any }
            : { enabled: config.enabled, style: config.style || 'opaque', target: config.target, skeleton: (config as any).skeleton, spinner: (config as any).spinner, fallback_target: (config as any).fallback_target as string | undefined };

        if (!normalized.enabled) return;

        // 기존 오버레이가 있으면 제거
        this.hideTransitionOverlay();

        // skeleton 스타일: React 기반 스켈레톤 UI 렌더링 (@since engine-v1.24.0)
        // 3단계 타겟팅: target → fallback_target → fullpage (@since engine-v1.24.2)
        if (normalized.style === 'skeleton' && normalized.skeleton?.component && normalized.target && layoutData) {
            this.renderSkeletonOverlay(normalized.target, normalized.skeleton, layoutData, normalized.fallback_target);
            return;
        }

        // spinner 스타일: 커스텀 로딩 컴포넌트 또는 기본 스피너 렌더링 (@since engine-v1.29.0)
        // skeleton과 동일한 3단계 타겟팅 지원
        if (normalized.style === 'spinner' && normalized.target) {
            this.renderSpinnerOverlay(normalized.target, normalized.spinner, normalized.fallback_target);
            return;
        }

        // 다크 모드 감지
        const isDark = document.documentElement.classList.contains('dark');

        // 스타일별 CSS 생성
        let bgCss: string;
        let extraCss = '';
        switch (normalized.style) {
            case 'blur':
                bgCss = isDark ? 'rgba(17,24,39,0.3)' : 'rgba(255,255,255,0.3)';
                extraCss = 'backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);';
                break;
            case 'fade':
                bgCss = isDark ? 'rgba(17,24,39,0.8)' : 'rgba(255,255,255,0.8)';
                break;
            case 'skeleton':
                // skeleton 스타일이지만 필수 조건 미충족 시 opaque 폴백
                bgCss = isDark ? 'rgb(17,24,39)' : 'rgb(249,250,251)';
                break;
            case 'opaque':
            default:
                bgCss = isDark ? 'rgb(17,24,39)' : 'rgb(249,250,251)';
                break;
        }

        if (normalized.target) {
            // CSS <style> 주입 + ::after 의사 요소 방식
            // - <head>에 삽입 → React 렌더 트리 외부 (재렌더 시 소멸하지 않음)
            // - ::after는 부모의 스태킹 컨텍스트 내부 → 형제 요소(header 등) 가림 불가
            // - React가 DOM 교체해도 같은 ID에 CSS 규칙 재매칭
            const selector = `#${CSS.escape(normalized.target)}`;
            const style = document.createElement('style');
            style.id = 'g7-transition-overlay';
            style.textContent = `${selector}{position:relative;z-index:0;}${selector}::after{content:'';position:absolute;inset:0;background:${bgCss};${extraCss}z-index:2147483647;pointer-events:none;}`;
            document.head.appendChild(style);
            this.transitionOverlayEl = style as unknown as HTMLDivElement;
        } else {
            // 폴백: target 미지정 시 전체 화면 DOM 오버레이
            const overlay = document.createElement('div');
            overlay.id = 'g7-transition-overlay';
            overlay.setAttribute('aria-hidden', 'true');
            overlay.style.cssText = `position:fixed;inset:0;z-index:9999;pointer-events:none;background:${bgCss};${extraCss}`;
            document.body.appendChild(overlay);
            this.transitionOverlayEl = overlay;
        }
    }

    /**
     * 레이아웃 전환 오버레이 제거
     *
     * @since engine-v1.23.0
     */
    private hideTransitionOverlay(): void {
        if (this.transitionOverlayEl) {
            this.transitionOverlayEl.remove();
            this.transitionOverlayEl = null;
        }
        this.hideSkeletonOverlay();
    }

    /**
     * 스켈레톤 오버레이 React 렌더링
     *
     * React 트리 외부(#app의 형제)에 absolute positioned 컨테이너를 생성하고,
     * target 요소의 위치/크기를 기반으로 정확히 오버레이합니다.
     * 컴포넌트 레지스트리에서 스켈레톤 컴포넌트를 조회하여
     * 레이아웃의 components 트리를 props로 전달합니다.
     *
     * 설계 원칙:
     * - React 트리 외부 배치: renderTemplate()이 #app을 재렌더해도 스켈레톤 유지
     * - position: absolute (not fixed): 스크롤 상태에서도 header 가림 없음
     * - 동기 렌더: blocking data fetch 대기 시간에 즉시 표시
     *
     * @param target - 오버레이를 삽입할 컨테이너 요소 ID
     * @param skeletonConfig - 스켈레톤 설정 (component, animation, iteration_count)
     * @param layoutData - 현재 레이아웃 데이터 (components 트리 포함)
     * @since engine-v1.24.0
     */
    /**
     * 3단계 스켈레톤 오버레이 렌더링 (@since engine-v1.24.2)
     *
     * 네비게이션 컨텍스트에 따라 스켈레톤 범위가 달라집니다:
     * 1. target DOM 존재     → 해당 영역만 (페이지 내부 전환, 예: 마이페이지 탭)
     * 2. fallback_target 존재 → 해당 영역만 (페이지 전환, 예: 헤더에서 네비게이트)
     * 3. 둘 다 미존재 (초기 로드) → 전체 페이지 스켈레톤
     */
    private renderSkeletonOverlay(
        target: string,
        skeletonConfig: { component: string; animation?: string; iteration_count?: number },
        layoutData: any,
        fallbackTarget?: string
    ): void {
        const registry = ComponentRegistry.getInstance();
        const SkeletonComponent = registry.getComponent(skeletonConfig.component);

        if (!SkeletonComponent) {
            logger.log(`Skeleton component "${skeletonConfig.component}" not found in registry, falling back to opaque overlay`);
            this.showTransitionOverlay({ enabled: true, style: 'opaque', target });
            return;
        }

        // 3단계 fallback chain: target → fallback_target → #app (fullpage)
        let targetEl = document.getElementById(target);
        let skeletonScope: 'target' | 'fallback' | 'fullpage' = 'target';

        if (!targetEl && fallbackTarget) {
            targetEl = document.getElementById(fallbackTarget);
            skeletonScope = 'fallback';
        }
        if (!targetEl) {
            targetEl = document.getElementById('app');
            skeletonScope = 'fullpage';
        }
        if (!targetEl) {
            logger.log(`Skeleton overlay: no target found (target="#${target}", fallback="${fallbackTarget || 'none'}"), falling back to opaque overlay`);
            this.showTransitionOverlay({ enabled: true, style: 'opaque', target });
            return;
        }

        // 기존 스켈레톤 오버레이 제거
        this.hideSkeletonOverlay();

        const isDark = document.documentElement.classList.contains('dark');
        const bgColor = isDark ? 'rgb(17,24,39)' : 'rgb(249,250,251)';

        // Step 1: CSS <style> 주입으로 즉시 이전 컨텐츠 가림 (동기, DOM 즉시 반영)
        // ::after 의사 요소는 React 렌더와 무관하게 즉시 적용됨
        // renderTemplate()이 #app DOM을 교체해도 새 #target에 ::after가 재매칭됨
        const cssTargetId = skeletonScope === 'fullpage' ? 'app'
            : skeletonScope === 'fallback' ? fallbackTarget!
            : target;
        const selector = `#${CSS.escape(cssTargetId)}`;
        const style = document.createElement('style');
        style.id = 'g7-skeleton-overlay-style';
        style.textContent = `${selector}{position:relative;z-index:0;}${selector}::after{content:'';position:absolute;inset:0;background:${bgColor};z-index:2147483646;pointer-events:none;}`;
        document.head.appendChild(style);
        this.transitionOverlayEl = style as unknown as HTMLDivElement;

        // Step 2: 스켈레톤 React 컨테이너를 document.body에 생성 (React 관리 #app 외부)
        // renderTemplate()이 #app 내부 DOM을 교체해도 body 직속 컨테이너는 파괴되지 않음
        // z-index: 20 — 헤더(z-30~50)보다 낮아 헤더를 가리지 않고, 컨텐츠(z-auto)보다 높아 커버
        const container = document.createElement('div');
        container.id = 'g7-skeleton-overlay';
        container.setAttribute('role', 'status');
        container.setAttribute('aria-busy', 'true');
        container.setAttribute('aria-label', 'Loading...');

        if (skeletonScope === 'fullpage') {
            // 초기 로드: #app이 비어있어 getBoundingClientRect() height=0
            // → fixed 포지션으로 전체 뷰포트 커버
            container.style.cssText = [
                'position:fixed',
                'inset:0',
                'z-index:20',
                'overflow:hidden',
                'pointer-events:none',
                `background:${bgColor}`,
            ].join(';') + ';';
        } else {
            // target/fallback: DOM이 존재 → 해당 영역 크기/위치에 맞춤
            const rect = targetEl.getBoundingClientRect();
            const scrollX = window.scrollX || document.documentElement.scrollLeft;
            const scrollY = window.scrollY || document.documentElement.scrollTop;
            container.style.cssText = [
                'position:absolute',
                `top:${rect.top + scrollY}px`,
                `left:${rect.left + scrollX}px`,
                `width:${rect.width}px`,
                `height:${rect.height}px`,
                'z-index:20',
                'overflow:hidden',
                'pointer-events:none',
                `background:${bgColor}`,
            ].join(';') + ';';
        }

        document.body.appendChild(container);
        this.skeletonOverlayContainer = container;

        // 스켈레톤 컴포넌트 트리 결정 (scope에 따라 다름)
        // fullpage: 전체 컴포넌트 트리 → 헤더+컨텐츠+푸터 모두 스켈레톤
        // fallback: fallback_target의 자식만 → 컨텐츠 영역만 스켈레톤
        // target: target의 자식만 → 탭 컨텐츠 등 좁은 영역만 스켈레톤
        const components = layoutData.components || [];
        let targetComponents: any[];
        if (skeletonScope === 'fullpage') {
            targetComponents = components;
        } else {
            const searchId = skeletonScope === 'fallback' ? fallbackTarget! : target;
            targetComponents = this.findComponentChildrenById(components, searchId);
        }

        // React root 생성 및 스켈레톤 동기 렌더
        // flushSync: React 18+의 비동기 스케줄링을 강제 동기화
        // blocking fetch 대기 전에 DOM에 즉시 반영되어야 함
        const root = createReactRoot(container);
        this.skeletonOverlayRoot = root;

        flushSync(() => {
            root.render(
                React.createElement(SkeletonComponent, {
                    components: targetComponents,
                    options: {
                        animation: skeletonConfig.animation || 'pulse',
                        iteration_count: skeletonConfig.iteration_count || 5,
                    },
                })
            );
        });

        const scopeLabel = skeletonScope === 'fullpage' ? 'fullpage (#app)'
            : skeletonScope === 'fallback' ? `fallback (#${fallbackTarget})`
            : `target (#${target})`;
        logger.log(`Skeleton overlay rendered [${scopeLabel}] with "${skeletonConfig.component}" (${targetComponents.length} components)`);
    }

    /**
     * 컴포넌트 트리에서 특정 ID를 가진 컴포넌트의 children을 찾습니다.
     *
     * @param components - 검색할 컴포넌트 배열
     * @param targetId - 찾을 컴포넌트 ID
     * @returns 해당 ID 컴포넌트의 children 배열 (미발견 시 전체 트리 반환)
     * @since engine-v1.24.0
     */
    private findComponentChildrenById(components: any[], targetId: string): any[] {
        const search = (nodes: any[]): any[] | null => {
            for (const comp of nodes) {
                if (comp.id === targetId) {
                    return comp.children || [];
                }
                if (comp.children && Array.isArray(comp.children)) {
                    const found = search(comp.children);
                    if (found !== null) return found;
                }
            }
            return null;
        };
        // 미발견 시 전체 트리 반환 (폴백)
        return search(components) || components;
    }

    /**
     * 스피너 오버레이 렌더링 (@since engine-v1.29.0)
     *
     * 2단계 패턴:
     * Step 1: CSS ::after 주입으로 이전 콘텐츠 즉시 가림
     * Step 2: 타겟 요소 내부에 spinner 컨테이너 삽입 (position:absolute; inset:0)
     *
     * 컨테이너는 타겟 요소 내부에 삽입되어 문서 흐름에 따라 자연스럽게 스크롤됩니다.
     * renderTemplate이 DOM을 교체하면 컨테이너도 함께 사라지므로,
     * reattachSpinnerOverlay()로 새 DOM의 타겟에 재생성합니다.
     *
     * 3단계 fallback chain: target → fallback_target → #app (fullpage)
     *
     * @param target - 오버레이를 삽입할 컨테이너 요소 ID
     * @param spinnerConfig - 스피너 설정 (component, text)
     * @param fallbackTarget - target 미발견 시 폴백 타겟 ID
     */
    private renderSpinnerOverlay(
        target: string,
        spinnerConfig?: { component?: string; text?: string },
        fallbackTarget?: string
    ): void {
        // 3단계 fallback chain: target → fallback_target → #app (fullpage)
        let targetEl = document.getElementById(target);
        let spinnerScope: 'target' | 'fallback' | 'fullpage' = 'target';

        if (!targetEl && fallbackTarget) {
            targetEl = document.getElementById(fallbackTarget);
            spinnerScope = 'fallback';
        }
        if (!targetEl) {
            targetEl = document.getElementById('app');
            spinnerScope = 'fullpage';
        }
        if (!targetEl) {
            logger.log(`Spinner overlay: no target found (target="#${target}", fallback="${fallbackTarget || 'none'}"), falling back to opaque overlay`);
            this.showTransitionOverlay({ enabled: true, style: 'opaque', target });
            return;
        }

        // 기존 오버레이 제거
        this.hideSkeletonOverlay();

        // CSS 주입 — 타겟에 position:relative만 설정 + keyframes 정의
        // 비주얼 스타일(배경색, z-index 등)은 컴포넌트가 결정
        const cssTargetId = spinnerScope === 'fullpage' ? 'app'
            : spinnerScope === 'fallback' ? fallbackTarget!
            : target;
        const selector = `#${CSS.escape(cssTargetId)}`;
        const style = document.createElement('style');
        style.id = 'g7-skeleton-overlay-style';
        style.textContent = [
            `${selector}{position:relative;}`,
            `@keyframes g7-spin{to{transform:rotate(360deg)}}`,
        ].join('');
        document.head.appendChild(style);
        this.transitionOverlayEl = style as unknown as HTMLDivElement;

        // 번역 텍스트를 미리 해석 (flushSync 내에서 G7Core.t()가 동작하지 않을 수 있음)
        const resolvedText = spinnerConfig?.text
            || (window as any).G7Core?.t?.('nav.loading')
            || '';

        // spinner 상태 저장 (renderTemplate 후 재생성용)
        this._spinnerState = {
            target,
            fallbackTarget,
            spinnerConfig,
            resolvedText,
        };

        // 타겟 요소 내부에 spinner 컨테이너 삽입
        this._mountSpinnerInTarget(targetEl);

        const scopeLabel = spinnerScope === 'fullpage' ? 'fullpage (#app)'
            : spinnerScope === 'fallback' ? `fallback (#${fallbackTarget})`
            : `target (#${target})`;
        const componentName = spinnerConfig?.component || 'default spinner';
        logger.log(`Spinner overlay rendered [${scopeLabel}] with "${componentName}"`);
    }

    /**
     * spinner 컨테이너를 타겟 요소 내부에 마운트
     *
     * 엔진은 빈 컨테이너만 제공합니다.
     * 포지셔닝, 배경, z-index, 다크모드 등 모든 비주얼 스타일은
     * 컴포넌트(또는 기본 폴백)가 결정합니다.
     *
     * @since engine-v1.29.0
     */
    private _mountSpinnerInTarget(
        targetEl: HTMLElement
    ): void {
        if (!this._spinnerState) return;

        const { spinnerConfig, resolvedText } = this._spinnerState;

        // 기존 spinner 정리
        if (this.skeletonOverlayRoot) {
            try { this.skeletonOverlayRoot.unmount(); } catch { /* DOM 이미 제거됨 */ }
            this.skeletonOverlayRoot = null;
        }
        if (this.skeletonOverlayContainer) {
            try { this.skeletonOverlayContainer.remove(); } catch { /* DOM 이미 제거됨 */ }
            this.skeletonOverlayContainer = null;
        }

        // 엔진은 빈 컨테이너만 삽입 — 스타일 없음, 컴포넌트가 모든 비주얼 결정
        const container = document.createElement('div');
        container.id = 'g7-skeleton-overlay';
        container.setAttribute('role', 'status');
        container.setAttribute('aria-busy', 'true');
        targetEl.appendChild(container);
        this.skeletonOverlayContainer = container;

        // 커스텀 로딩 컴포넌트 지정 시: React 렌더링 (컴포넌트가 자체 레이아웃/스타일 결정)
        if (spinnerConfig?.component) {
            const registry = ComponentRegistry.getInstance();
            const LoadingComponent = registry.getComponent(spinnerConfig.component);
            if (LoadingComponent) {
                const root = createReactRoot(container);
                this.skeletonOverlayRoot = root;
                flushSync(() => {
                    root.render(
                        React.createElement(LoadingComponent, {
                            options: { text: resolvedText },
                        })
                    );
                });
                return;
            }
            logger.log(`Spinner component "${spinnerConfig.component}" not found in registry, using default spinner`);
        }

        // 기본 스피너 폴백 (커스텀 컴포넌트 미지정 시)
        // 폴백만 인라인 스타일 사용 — 커스텀 컴포넌트 사용 시 이 코드는 실행되지 않음
        container.innerHTML = `<div style="position:absolute;inset:0;z-index:2147483647;overflow:hidden;background:var(--g7-overlay-bg, rgb(249,250,251));display:flex;align-items:center;justify-content:center;"><div style="width:32px;height:32px;border:3px solid var(--g7-spinner-color, #9ca3af);border-top-color:transparent;border-radius:50%;animation:g7-spin 0.8s linear infinite;"></div></div>`;
    }

    /**
     * renderTemplate 후 새 DOM의 타겟 요소에 spinner 재생성
     *
     * renderTemplate이 #app 내부 DOM을 교체하면 타겟 내부의 spinner도 함께 사라집니다.
     * 이 메서드는 새 DOM에서 타겟을 찾아 spinner를 재생성합니다.
     *
     * @since engine-v1.29.0
     */
    private reattachSpinnerOverlay(): void {
        if (!this._spinnerState) return;

        const { target, fallbackTarget } = this._spinnerState;

        // 새 DOM에서 타겟 찾기 (동일한 3단계 fallback)
        let newTarget = document.getElementById(target);
        if (!newTarget && fallbackTarget) {
            newTarget = document.getElementById(fallbackTarget);
        }
        if (!newTarget) {
            newTarget = document.getElementById('app');
        }
        if (!newTarget) return;

        this._mountSpinnerInTarget(newTarget);
        logger.log(`Spinner overlay reattached to new DOM target`);
    }

    /**
     * 스켈레톤 오버레이 제거
     *
     * @since engine-v1.24.0
     */
    private hideSkeletonOverlay(): void {
        if (this.skeletonOverlayRoot) {
            try {
                this.skeletonOverlayRoot.unmount();
            } catch {
                // renderTemplate()이 DOM을 교체하면서 이미 제거된 경우 무시
            }
            this.skeletonOverlayRoot = null;
        }
        if (this.skeletonOverlayContainer) {
            try {
                if (this.skeletonOverlayContainer.parentNode) {
                    this.skeletonOverlayContainer.remove();
                }
            } catch {
                // 이미 제거된 경우 무시
            }
            this.skeletonOverlayContainer = null;
        }
        // 스켈레톤 전용 CSS style 태그 제거 (hideTransitionOverlay에서 못 잡은 경우)
        const styleEl = document.getElementById('g7-skeleton-overlay-style');
        if (styleEl) styleEl.remove();
        // spinner 재생성 상태 초기화
        this._spinnerState = null;
    }

    /**
     * 라우트 에러 화면 표시
     */
    private showRouteError(error: Error): void {
        // 레이아웃 fetch 401 가드: 토큰 만료 등으로 권한이 사라진 상태에서
        // 레이아웃을 받지 못하면 코어가 로그인 페이지로 자동 리다이렉트한다.
        // (Issue #301 — 사용자 인식 문제 해결: 시스템 장애 화면 대신 안내 토스트)
        //
        // hadToken 판정 (reason='session_expired' 부여 여부):
        //   - 현재 apiClient 가 토큰을 보유했거나
        //   - LayoutLoader 가 첫 401 시점에 토큰을 보유했었다는 마킹(details.hadToken)이 있으면
        //     세션이 거부된 케이스로 간주 → reason 부여 (토스트 노출)
        //   - 둘 다 false 면 익명 사용자의 인증 필요 페이지 진입 → reason 미부여
        //
        // LayoutLoader 는 401 응답 시 토큰을 자동 제거하고 재시도하므로 가드 진입
        // 시점에 apiClient.getToken() 만 보면 항상 null 이다. 따라서 LayoutLoader 가
        // details.hadToken=true 로 마킹하는 정보를 함께 사용해야 한다.
        if (error instanceof LayoutLoaderError && error.details?.status === 401) {
            const templateId = this.config.templateId || '';
            const pathname = window.location.pathname;
            const authType: AuthType =
                templateId.includes('admin') || pathname.startsWith('/admin') ? 'admin' : 'user';
            const returnUrl = pathname + window.location.search;
            const hadToken =
                !!getApiClient().getToken() || error.details?.hadToken === true;
            const loginUrl = AuthManager.getInstance().getLoginRedirectUrl(
                authType,
                returnUrl,
                hadToken ? 'session_expired' : undefined
            );
            window.location.href = loginUrl;
            return;
        }

        // Error를 TemplateEngineError로 변환
        const templateError = toTemplateEngineError(error);

        // ErrorDisplay를 사용하여 렌더링
        // 원본 에러도 전달하여 상세 메시지 추출 가능하도록 함
        ErrorDisplay.renderFromError(
            'app',
            templateError,
            '페이지 로딩 실패',
            this.config.debug,
            {
                templateId: this.config.templateId,
                locale: this.config.locale,
            },
            undefined, // translationEngine
            error // originalError
        );
    }

    /**
     * 서버에서 주입된 에러 상태 처리 (503 의존성 미충족 등)
     *
     * 미들웨어에서 의존성 미충족 등을 감지하면 window.G7Error에 에러 정보를 주입합니다.
     * 이 메서드는 해당 에러를 감지하고 ErrorPageHandler를 통해 에러 페이지를 렌더링합니다.
     *
     * handleRouteNotFound와 동일한 패턴을 사용하여 일관된 에러 처리를 보장합니다.
     *
     * @returns 에러가 처리되었으면 true, 에러가 없거나 처리 실패 시 false
     */
    private async handleServerError(): Promise<boolean> {
        // window.G7Error 확인
        const errorInfo = window.G7Error;
        if (!errorInfo) {
            return false;
        }

        logger.warn('Server error detected:', errorInfo);

        try {
            // ErrorPageHandler를 통해 에러 페이지 렌더링 시도
            if (this.errorPageHandler) {
                // 에러 데이터를 전역 상태에 주입 (레이아웃에서 접근 가능하도록)
                if (errorInfo.data) {
                    this.globalState.errorData = errorInfo.data;
                }

                // 최신 전역 상태 전달
                this.errorPageHandler.updateGlobalState(this.globalState);

                // 최신 로케일 전달
                this.errorPageHandler.updateLocale(this.config.locale);

                const rendered = await this.errorPageHandler.renderError(errorInfo.code, 'app');

                if (rendered) {
                    logger.log(`${errorInfo.code} error page rendered successfully`);
                    return true;
                }
            }

            // 폴백: 기존 ErrorDisplay 사용
            logger.log(`Falling back to ErrorDisplay for ${errorInfo.code}`);
            this.showInitError(new Error(`Service unavailable (${errorInfo.code})`));
            return true;
        } catch (error) {
            logger.error(`Failed to render ${errorInfo.code} page:`, error);
            // 최종 폴백: 기존 ErrorDisplay 사용
            this.showInitError(new Error(`Service unavailable (${errorInfo.code})`));
            return true;
        }
    }

    /**
     * 라우트를 찾을 수 없을 때 처리 (404 에러 페이지)
     *
     * ErrorPageHandler를 통해 에러 레이아웃을 로드하고 렌더링합니다.
     * 에러 레이아웃이 없으면 기존 ErrorDisplay 폴백을 사용합니다.
     *
     * @param path 찾을 수 없는 경로
     */
    private async handleRouteNotFound(path: string): Promise<void> {
        logger.warn('Route not found:', path);

        try {
            // ErrorPageHandler를 통해 404 에러 페이지 렌더링 시도
            if (this.errorPageHandler) {
                // 최신 전역 상태 전달 (사이드바 상태, 사용자 정보 등)
                this.errorPageHandler.updateGlobalState(this.globalState);

                // 최신 로케일 전달 (언어 변경 반영)
                this.errorPageHandler.updateLocale(this.config.locale);

                const rendered = await this.errorPageHandler.renderError(404, 'app');

                if (rendered) {
                    logger.log('404 error page rendered successfully');
                    return;
                }
            }

            // 폴백: 기존 ErrorDisplay 사용
            logger.log('Falling back to ErrorDisplay for 404');
            this.showRouteError(new Error(`Page not found: ${path}`));
        } catch (error) {
            logger.error('Failed to render 404 page:', error);
            // 최종 폴백: 기존 ErrorDisplay 사용
            this.showRouteError(new Error(`Page not found: ${path}`));
        }
    }

    /**
     * Router 인스턴스 반환
     */
    getRouter(): Router | null {
        return this.router;
    }

    /**
     * 현재 설정 반환
     */
    getConfig(): TemplateAppConfig {
        return this.config;
    }

    /**
     * LayoutLoader 인스턴스 반환
     */
    getLayoutLoader(): LayoutLoader | null {
        return this.layoutLoader;
    }

    /**
     * 확장 상태(routes/translations/layouts/module assets) 원자적 재동기화
     *
     * 모듈/플러그인/템플릿의 install/activate/deactivate/uninstall 직후 호출되어
     * 전체 새로고침 없이 변경된 확장 상태를 즉시 반영합니다.
     *
     * 동작 순서:
     *   1. `/api/templates/{id}/config.json` 에서 최신 cache_version 획득
     *   2. 변경이 있으면 `this.extensionCacheVersion` 및 localStorage 갱신
     *   3. `router.loadRoutes(newVersion)` — 새 버전 쿼리로 routes 재fetch
     *   4. LayoutLoader.setCacheVersion + clear — 다음 레이아웃 로드부터 신 버전 사용
     *   5. TranslationEngine.setCacheVersion + loadTranslations(true) — 다국어 재로드
     *
     * 각 단계는 try/catch 로 격리되어 한 단계의 실패가 다른 단계를 막지 않습니다.
     *
     * @since engine-v1.19.0
     */
    async reloadExtensionState(): Promise<void> {
        logger.log('reloadExtensionState: start');

        // 1. 최신 cache_version 획득 (브라우저 캐시 우회용 `_` 쿼리 포함)
        let newVersion: number | undefined;
        try {
            const configResponse = await fetch(
                `/api/templates/${this.config.templateId}/config.json?_=${Date.now()}`
            );
            if (configResponse.ok) {
                const configResult = await configResponse.json();
                if (configResult?.success && configResult?.data?.cache_version !== undefined) {
                    newVersion = configResult.data.cache_version;
                }
            }
        } catch (error) {
            logger.warn('reloadExtensionState: failed to fetch config.json', error);
        }

        // 2. 버전 상태 갱신
        if (newVersion !== undefined && newVersion !== this.extensionCacheVersion) {
            logger.log(
                `reloadExtensionState: cache version ${this.extensionCacheVersion} -> ${newVersion}`
            );
            this.extensionCacheVersion = newVersion;
            this.saveCacheVersionToStorage(newVersion);
        }

        // 3. Router routes 재로드 (버전 쿼리 부착 필수)
        try {
            if (this.router) {
                await this.router.loadRoutes(this.extensionCacheVersion);
                logger.log('reloadExtensionState: routes reloaded');
            }
        } catch (error) {
            logger.error('reloadExtensionState: routes reload failed', error);
        }

        // 4. LayoutLoader 버전 갱신 + 캐시 클리어
        try {
            if (this.layoutLoader) {
                if (this.extensionCacheVersion > 0) {
                    this.layoutLoader.setCacheVersion(this.extensionCacheVersion);
                }
                this.layoutLoader.clear();
                logger.log('reloadExtensionState: layout cache cleared');
            }
        } catch (error) {
            logger.error('reloadExtensionState: layout cache clear failed', error);
        }

        // 5. 다국어 재로드 — 활성 translations 맵은 loadTranslations 가 원자적으로 교체할 때까지
        //    유지되므로 병렬 `toast($t:...)` 와 경합하지 않음. 명시적 clearCache() 호출 금지
        //    (engine-v1.38.1 참고: setCacheVersion 이 TTL 캐시만 비움).
        try {
            const { TranslationEngine } = await import('./template-engine/TranslationEngine');
            const translationEngine = TranslationEngine.getInstance();
            if (this.extensionCacheVersion > 0) {
                translationEngine.setCacheVersion(this.extensionCacheVersion);
            }
            const locale = this.config.locale || 'ko';
            const fallbackLocale = 'en';
            await translationEngine.loadTranslations(this.config.templateId, locale, '/api', true);
            if (locale !== fallbackLocale) {
                await translationEngine.loadTranslations(this.config.templateId, fallbackLocale, '/api', true);
            }
            logger.log('reloadExtensionState: translations reloaded');
        } catch (error) {
            logger.error('reloadExtensionState: translations reload failed', error);
        }

        // 6. 활성 로케일 목록 갱신 — 언어팩 설치/활성화 직후
        //    `_global.appConfig.supportedLocales` 가 즉시 반영되도록 한다.
        //    이 값은 template-engine.ts:createGlobalVariables() 의 `$locales` 의 SSoT 로,
        //    UserProfile 등 언어 셀렉터 컴포넌트가 새로고침 없이 재렌더링한다.
        try {
            const localesResponse = await fetch(`/api/locales/active?_=${Date.now()}`);
            if (localesResponse.ok) {
                const localesResult = await localesResponse.json();
                const locales = localesResult?.data?.locales;
                if (Array.isArray(locales) && locales.length > 0) {
                    const currentAppConfig = this.globalState.appConfig ?? {};
                    this.setGlobalState({
                        appConfig: { ...currentAppConfig, supportedLocales: locales },
                    });
                    logger.log('reloadExtensionState: supportedLocales refreshed', locales);
                }
            }
        } catch (error) {
            logger.warn('reloadExtensionState: supportedLocales refresh failed', error);
        }

        logger.log('reloadExtensionState: done');
    }

    /**
     * ActionDispatcher 인스턴스 반환
     *
     * 템플릿 컴포넌트에서 액션을 실행할 때 사용합니다.
     */
    getActionDispatcher() {
        // 이미 import된 getState를 사용하여 ActionDispatcher 반환
        const state = getState();
        return state.actionDispatcher;
    }

    /**
     * 로케일 변경
     *
     * @param locale 새로운 로케일 (예: 'ko', 'en')
     */
    async changeLocale(locale: string): Promise<void> {
        try {
            logger.log('Changing locale to:', locale);

            // 로케일이 동일하면 무시
            if (this.config.locale === locale) {
                logger.log('Locale is already', locale);
                return;
            }

            // 로케일 업데이트
            this.config.locale = locale;

            // localStorage에 저장
            this.saveLocaleToStorage(locale);

            // 로그인한 사용자인 경우 DB에 저장 (실패해도 UI는 변경)
            await this.saveLocaleToDatabase(locale);

            // 레이아웃 캐시 클리어 (새 로케일로 레이아웃 재로드 필요)
            if (this.layoutLoader) {
                this.layoutLoader.clear();
                logger.log('Layout cache cleared');
            }

            // ErrorPageHandler의 로케일도 업데이트
            if (this.errorPageHandler) {
                this.errorPageHandler.updateLocale(locale);
                logger.log('ErrorPageHandler locale updated');
            }

            // 기존 템플릿 엔진 정리
            destroyTemplate();

            // 템플릿 엔진 재초기화 (캐시 버전 유지)
            await initTemplateEngine({
                templateId: this.config.templateId,
                templateType: this.config.templateType,
                locale: locale,
                debug: this.config.debug,
                cacheVersion: this.extensionCacheVersion,
            });

            logger.log('Template Engine re-initialized with new locale');

            // ActionDispatcher에 navigate 함수 및 setGlobalState 재주입
            // (initTemplateEngine에서 새로운 ActionDispatcher 인스턴스가 생성되므로 재주입 필요)
            const { getActionDispatcher } = await import('./template-engine');
            const actionDispatcher = getActionDispatcher();

            if (actionDispatcher) {
                actionDispatcher.setDefaultContext({
                    navigate: (path: string) => this.router?.navigate(path),
                });

                actionDispatcher.setGlobalStateUpdater((updates: any, opts?: { render?: boolean }) => this.setGlobalState(updates, opts));

                logger.log('Navigate function and setGlobalState re-injected to ActionDispatcher');
            }

            // 모듈 핸들러 재등록
            // ActionDispatcher가 새로 생성되었으므로 모듈들이 핸들러를 다시 등록해야 함
            this.reinitializeModuleHandlers();
            logger.log('Module handlers re-initialized');

            // 템플릿 핸들러 재등록
            // 모듈 핸들러와 마찬가지로 템플릿 커스텀 핸들러도 재등록해야 함
            this.reinitializeTemplateHandlers();
            logger.log('Template handlers re-initialized');

            // 플러그인 핸들러 재등록
            // 모듈 핸들러와 마찬가지로 플러그인 핸들러도 재등록해야 함
            this.reinitializePluginHandlers();
            logger.log('Plugin handlers re-initialized');

            // 현재 라우트 재렌더링
            if (this.router) {
                this.router.navigateToCurrentPath();
            }

            logger.log('Locale changed successfully to', locale);
        } catch (error) {
            logger.error('Failed to change locale:', error);
            throw error;
        }
    }

    /**
     * localStorage에서 저장된 로케일 로드
     */
    private loadLocaleFromStorage(): string | null {
        try {
            return localStorage.getItem(TemplateApp.LOCALE_STORAGE_KEY);
        } catch (error) {
            logger.warn('Failed to load locale from storage:', error);
            return null;
        }
    }

    /**
     * localStorage에 로케일 저장
     *
     * @param locale 저장할 로케일
     */
    private saveLocaleToStorage(locale: string): void {
        try {
            localStorage.setItem(TemplateApp.LOCALE_STORAGE_KEY, locale);
        } catch (error) {
            logger.warn('Failed to save locale to storage:', error);
        }
    }

    /**
     * 로그인한 사용자의 언어 설정을 DB에 저장합니다.
     *
     * config.localeApi가 설정되어 있으면 해당 설정 사용,
     * 없으면 템플릿 타입에 따라 기본값 사용:
     * - admin: /api/admin/users/me/language (PATCH)
     * - user: /api/user/profile/update-language (POST)
     *
     * @param locale 저장할 로케일
     */
    private async saveLocaleToDatabase(locale: string): Promise<void> {
        // Bearer 토큰이 없으면 비로그인 상태 → DB 저장 스킵
        const bearerToken = localStorage.getItem('auth_token');
        if (!bearerToken) {
            logger.log('No auth token, skipping DB locale save');
            return;
        }

        // API 엔드포인트 결정: config.localeApi 우선, 없으면 템플릿 타입에 따라 기본값
        let endpoint: string;
        let method: string;

        if (this.config.localeApi) {
            endpoint = this.config.localeApi.endpoint;
            method = this.config.localeApi.method;
        } else {
            const isAdmin = this.config.templateType === 'admin';
            endpoint = isAdmin
                ? '/api/admin/users/me/language'
                : '/api/user/profile/update-language';
            method = isAdmin ? 'PATCH' : 'POST';
        }

        try {
            // XSRF 토큰 가져오기
            const xsrfToken = this.getXsrfToken();

            const response = await fetch(endpoint, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...(xsrfToken && { 'X-XSRF-TOKEN': xsrfToken }),
                    Authorization: `Bearer ${bearerToken}`,
                },
                credentials: 'include',
                body: JSON.stringify({ language: locale }),
            });

            if (!response.ok) {
                logger.warn('Failed to save locale to DB (UI will still change):', response.statusText);
            } else {
                logger.log('Locale saved to DB:', locale);
            }
        } catch (error) {
            logger.warn('Failed to call locale API (UI will still change):', error);
        }
    }

    /**
     * 쿠키에서 XSRF 토큰 읽기
     */
    private getXsrfToken(): string | null {
        if (typeof document === 'undefined') {
            return null;
        }
        const value = `; ${document.cookie}`;
        const parts = value.split(`; XSRF-TOKEN=`);
        if (parts.length === 2) {
            return decodeURIComponent(parts.pop()?.split(';').shift() || '');
        }
        return null;
    }

    /**
     * localStorage에서 저장된 캐시 버전 로드
     *
     * @returns 저장된 캐시 버전 또는 null
     */
    private loadCacheVersionFromStorage(): number | null {
        try {
            const value = localStorage.getItem(TemplateApp.CACHE_VERSION_STORAGE_KEY);
            return value ? parseInt(value, 10) : null;
        } catch (error) {
            logger.warn('Failed to load cache version from storage:', error);
            return null;
        }
    }

    /**
     * localStorage에 캐시 버전 저장
     *
     * @param version 저장할 캐시 버전
     */
    private saveCacheVersionToStorage(version: number): void {
        try {
            localStorage.setItem(TemplateApp.CACHE_VERSION_STORAGE_KEY, String(version));
        } catch (error) {
            logger.warn('Failed to save cache version to storage:', error);
        }
    }

    /**
     * 모듈 핸들러 재초기화
     *
     * ActionDispatcher가 새로 생성된 후 모듈들이 핸들러를 다시 등록할 수 있도록
     * window 전역 객체에서 모듈의 initModule 함수를 찾아 호출합니다.
     *
     * 모듈은 window.__[ModuleName] = { initModule: Function } 형태로 노출되어야 합니다.
     */
    private reinitializeModuleHandlers(): void {
        if (typeof window === 'undefined') {
            return;
        }

        // window 객체에서 __로 시작하는 모듈 객체를 찾아 initModule 호출
        const modulePrefix = '__';
        const windowObj = window as any;

        Object.keys(windowObj).forEach((key) => {
            if (key.startsWith(modulePrefix) && typeof windowObj[key]?.initModule === 'function') {
                try {
                    windowObj[key].initModule();
                    logger.log(`Module handler re-initialized: ${key}`);
                } catch (error) {
                    logger.warn(`Failed to re-initialize module handlers for ${key}:`, error);
                }
            }
        });
    }

    /**
     * 템플릿 핸들러를 재등록합니다.
     *
     * 템플릿 JS(components.iife.js)에서 window.G7TemplateHandlers로 핸들러 맵을 노출하고,
     * 이 메서드에서 ActionDispatcher에 재등록합니다.
     *
     * 로케일 변경 시 ActionDispatcher가 새로 생성되므로 템플릿 핸들러도 재등록해야 합니다.
     */
    private reinitializeTemplateHandlers(): void {
        if (typeof window === 'undefined') {
            return;
        }

        const templateHandlers = (window as any).G7TemplateHandlers;
        if (!templateHandlers) {
            logger.warn('Template handlers not found on window.G7TemplateHandlers');
            return;
        }

        const actionDispatcher = this.getActionDispatcher();
        if (!actionDispatcher) {
            logger.warn('ActionDispatcher not available for template handler registration');
            return;
        }

        Object.entries(templateHandlers).forEach(([name, handler]) => {
            actionDispatcher.registerHandler(name, handler as any);
        });

        logger.log(`${Object.keys(templateHandlers).length} template handler(s) re-registered:`, Object.keys(templateHandlers));
    }

    /**
     * 플러그인 핸들러 재초기화
     *
     * ActionDispatcher가 새로 생성된 후 플러그인들이 핸들러를 다시 등록할 수 있도록
     * window 전역 객체에서 플러그인의 initPlugin 함수를 찾아 호출합니다.
     *
     * 플러그인은 window.__[PluginName] = { initPlugin: Function } 형태로 노출되어야 합니다.
     */
    private reinitializePluginHandlers(): void {
        if (typeof window === 'undefined') {
            return;
        }

        // window 객체에서 __로 시작하는 플러그인 객체를 찾아 initPlugin 호출
        const pluginPrefix = '__';
        const windowObj = window as any;

        Object.keys(windowObj).forEach((key) => {
            if (key.startsWith(pluginPrefix) && typeof windowObj[key]?.initPlugin === 'function') {
                try {
                    windowObj[key].initPlugin();
                    logger.log(`Plugin handler re-initialized: ${key}`);
                } catch (error) {
                    logger.warn(`Failed to re-initialize plugin handlers for ${key}:`, error);
                }
            }
        });
    }

    /**
     * 현재 로케일 반환
     */
    getLocale(): string {
        return this.config.locale;
    }

    /**
     * ErrorPageHandler 반환
     *
     * ActionDispatcher에서 showErrorPage 핸들러 실행 시 사용합니다.
     *
     * @returns ErrorPageHandler 인스턴스 또는 null
     */
    getErrorPageHandler(): ErrorPageHandler | null {
        return this.errorPageHandler;
    }

    /**
     * 전역 상태 반환
     */
    getGlobalState(): GlobalState {
        return { ...this.globalState };
    }

    /**
     * 전역 상태 업데이트
     *
     * @param updates 업데이트할 상태 객체 또는 함수형 업데이트 (prev => newState)
     * @param options.render false이면 상태 업데이트만 수행하고 React 렌더링을 건너뜀 (engine-v1.42.0+)
     */
    setGlobalState(updates: Partial<GlobalState> | ((prev: GlobalState) => GlobalState), options?: { render?: boolean }): void {
        // DevTools: 이전 상태 저장
        const prevState = { ...this.globalState };
        // 함수형 업데이트 지원 (Form dataKey="_global.xxx" 자동 바인딩에서 사용)
        if (typeof updates === 'function') {
            this.globalState = updates(this.globalState);
        } else {
            this.globalState = {
                ...this.globalState,
                ...updates,
            };
        }

        logger.log('Global state updated:', this.globalState);

        // DevTools: 상태 스냅샷 캡처 (G7Core.devTools 통합 인터페이스 사용)
        const G7Core = (window as any).G7Core;
        G7Core?.devTools?.captureStateSnapshot?.({
            source: 'setGlobalState',
            prev: prevState,
            next: this.globalState,
        });

        // 리스너들에게 상태 변경 알림
        this.globalStateListeners.forEach(listener => {
            listener(this.globalState);
        });

        // engine-v1.42.0: render: false이면 상태 업데이트만 수행, React 렌더링 건너뛰기
        // 값은 this.globalState에 저장되므로 getLocal()/getState()로 최신 값 접근 가능
        // 플러그인(CKEditor 등)이 자체 DOM을 관리하는 경우, React 리렌더 없이 값만 저장
        if (options?.render === false) return;

        // 전역 상태만 업데이트 (라우트 재로딩 없이)
        // 렌더링 완료 여부 확인 후 updateTemplateData 호출
        // init_actions에서 setGlobalState가 호출될 때는 아직 renderTemplate 전이므로
        // updateTemplateData가 실패할 수 있음 (reactRoot가 없음)
        import('./template-engine').then(({ updateTemplateData, getState }) => {
            const engineState = getState();
            // 렌더링이 완료된 경우에만 updateTemplateData 호출
            if (engineState.reactRoot && engineState.currentLayoutJson) {
                // engine-v1.17.3: _local도 함께 업데이트하여 비동기 콜백에서 setState 호출 시 UI 갱신 보장
                // globalStateUpdater({ _local: ... }) 호출 시 this.globalState._local이 업데이트되지만,
                // DynamicRenderer는 dataContext._local을 직접 접근하므로 _local도 별도로 전달해야 함
                updateTemplateData({
                    _global: { ...this.globalState },
                    _local: this.globalState._local || {},
                });
            }
        });
    }

    /**
     * 전역 상태 변경 리스너 등록
     *
     * @param listener 상태 변경 시 호출될 콜백 함수
     */
    onGlobalStateChange(listener: (state: GlobalState) => void): void {
        this.globalStateListeners.add(listener);
    }

    /**
     * 전역 상태 변경 리스너 제거
     *
     * @param listener 제거할 콜백 함수
     */
    offGlobalStateChange(listener: (state: GlobalState) => void): void {
        this.globalStateListeners.delete(listener);
    }

    /**
     * 특정 데이터 소스를 다시 fetch
     *
     * 모듈 설치/활성화 후 사이드바 메뉴 갱신 등에 사용합니다.
     * blur_until_loaded가 설정된 컴포넌트는 refetch 중 blur 효과가 적용됩니다.
     *
     * @param dataSourceId 데이터 소스 ID (예: 'modules', 'sidebar_menus')
     * @param options refetch 옵션 (sync: true면 startTransition 없이 즉시 렌더링)
     * @returns fetch된 데이터 또는 undefined
     */
    async refetchDataSource(dataSourceId: string, options?: { sync?: boolean; globalStateOverride?: Record<string, any>; localStateOverride?: Record<string, any> }): Promise<any> {
        // 데이터 소스 정의 찾기 (페이지 레벨 → 모달 레벨 순서로 검색)
        let dataSourceDef = this.currentDataSources.find((ds: any) => ds.id === dataSourceId);

        // 페이지 레벨에서 못 찾으면 모달 데이터 소스에서 검색
        if (!dataSourceDef) {
            for (const [, sources] of this.modalDataSources) {
                const found = sources.find((ds: any) => ds.id === dataSourceId);
                if (found) {
                    dataSourceDef = found;
                    break;
                }
            }
        }

        if (!dataSourceDef) {
            logger.warn(`Data source not found: ${dataSourceId}`);
            return undefined;
        }

        logger.log(`Refetching data source: ${dataSourceId}`, options?.sync ? '(sync mode)' : '', options?.globalStateOverride ? '(with global override)' : '', options?.localStateOverride ? '(with local override)' : '');

        // blur_until_loaded 지원: refetch 시작 시 transition 상태를 pending으로 설정
        // DynamicRenderer에서 isTransitioning을 확인하여 blur 효과 적용
        transitionManager.setPending(true);

        try {
            const dataSourceManager = new DataSourceManager();

            // globalHeaders 설정 (레이아웃에서 정의한 전역 헤더)
            if (this.currentGlobalHeaders.length > 0) {
                dataSourceManager.setGlobalHeaders(this.currentGlobalHeaders);
            }

            // _global 상태 가져오기 (endpoint 표현식에서 {{_global.xxx}} 접근 지원)
            // globalStateOverride가 있으면 기존 상태와 병합 (sequence에서 setState 후 업데이트된 값 반영)
            const state = getState();
            const currentGlobalState = state.currentDataContext?._global || {};
            const globalState = options?.globalStateOverride
                ? { ...currentGlobalState, ...options.globalStateOverride }
                : currentGlobalState;

            // _local 상태 가져오기 (params 표현식에서 {{_local.xxx}} 접근 지원)
            // localStateOverride가 있으면 기존 상태와 병합 (sequence에서 setState local 후 업데이트된 값 반영)
            const currentLocalState = state.currentDataContext?._local || {};
            const localState = options?.localStateOverride
                ? { ...currentLocalState, ...options.localStateOverride }
                : currentLocalState;

            // 단일 데이터 소스 fetch
            // ignoreAutoFetch: true - auto_fetch: false인 데이터 소스도 강제 fetch
            // localState: sequence 내 setState local 후 {{_local.xxx}} 표현식 지원
            const results = await dataSourceManager.fetchDataSourcesWithResults(
                [dataSourceDef],
                this.currentRouteParams,
                this.currentQueryParams,
                globalState,
                localState,
                { ignoreAutoFetch: true }
            );

            const result = results[0];

            if (result.state === 'success' && result.data !== undefined) {
                // 캐시 업데이트
                this.currentFetchedData[dataSourceId] = result.data;

                // initGlobal/initLocal 처리
                // processInitOptions에서 this.globalState가 업데이트됨
                const localInit: Record<string, any> = {};
                this.processInitOptions([dataSourceDef], { [dataSourceId]: result.data }, localInit);

                // BindingEngine 캐시 무효화
                const state = getState();
                if (state.bindingEngine) {
                    const keysToInvalidate = [dataSourceId];
                    // initGlobal이 있으면 _global 키도 무효화
                    if (dataSourceDef.initGlobal) {
                        keysToInvalidate.push('_global');
                    }
                    // initLocal이 있으면 _local 키도 무효화
                    if (dataSourceDef.initLocal) {
                        keysToInvalidate.push('_local');
                    }
                    state.bindingEngine.invalidateCacheByKeys(keysToInvalidate);
                }

                // 데이터 컨텍스트 업데이트 (sync 옵션 전달)
                const updateData: Record<string, any> = {
                    [dataSourceId]: result.data,
                };

                // initLocal이 있으면 추가
                if (Object.keys(localInit).length > 0) {
                    updateData._localInit = localInit;
                }

                // initGlobal이 있으면 _global도 업데이트
                // processInitOptions에서 this.globalState가 업데이트되었으므로 React 상태에도 반영
                if (dataSourceDef.initGlobal) {
                    updateData._global = { ...this.globalState };
                }

                updateTemplateData(updateData, options?.sync ? { sync: true } : undefined);

                logger.log(`Data source refetched successfully: ${dataSourceId}`);

                return result.data;
            } else if (result.state === 'error') {
                logger.error(`Failed to refetch data source: ${dataSourceId}`, result.error);
                return undefined;
            }
        } catch (error) {
            logger.error(`Error refetching data source: ${dataSourceId}`, error);
            return undefined;
        } finally {
            // blur_until_loaded 지원: refetch 완료 후 transition 상태 해제
            transitionManager.setPending(false);
        }
    }

    /**
     * 모달 데이터 소스를 레지스트리에 등록
     *
     * ModalDataSourceWrapper가 마운트될 때 호출합니다.
     * 등록된 데이터 소스는 refetchDataSource에서 검색 가능해집니다.
     *
     * @param modalId 모달 ID
     * @param dataSources 해당 모달의 data_sources 배열
     * @since 1.18.0
     */
    registerModalDataSources(modalId: string, dataSources: any[]): void {
        this.modalDataSources.set(modalId, dataSources);
        logger.log(`Modal data sources registered: ${modalId} (${dataSources.length} sources)`);
    }

    /**
     * 모달 데이터 소스를 레지스트리에서 해제
     *
     * ModalDataSourceWrapper가 언마운트되거나 모달이 닫힐 때 호출합니다.
     *
     * @param modalId 모달 ID
     * @since 1.18.0
     */
    unregisterModalDataSources(modalId: string): void {
        this.modalDataSources.delete(modalId);
        logger.log(`Modal data sources unregistered: ${modalId}`);
    }

    /**
     * 현재 fetch된 데이터 소스 데이터 반환
     *
     * @param dataSourceId 데이터 소스 ID
     * @returns 캐시된 데이터 또는 undefined
     */
    getDataSource(dataSourceId: string): any {
        return this.currentFetchedData[dataSourceId];
    }

    /**
     * 데이터 소스 값을 설정하고 UI를 리렌더링합니다.
     *
     * 서버 refetch 없이 클라이언트 측에서 데이터 소스를 직접 업데이트할 때 사용합니다.
     * DataGrid 인라인 편집, 폼 데이터 수정 등에서 활용됩니다.
     *
     * @param dataSourceId 데이터 소스 ID
     * @param data 설정할 데이터 (전체 교체)
     * @param options 옵션
     * @param options.merge true면 기존 데이터와 병합 (기본값: false, 전체 교체)
     * @param options.sync true면 동기 업데이트 (기본값: false)
     *
     * @example
     * // 데이터 전체 교체
     * G7Core.dataSource.set('products', { data: updatedProducts, meta: {...} });
     *
     * // 기존 데이터와 병합
     * G7Core.dataSource.set('products', { data: updatedProducts }, { merge: true });
     */
    setDataSource(
        dataSourceId: string,
        data: any,
        options?: { merge?: boolean; sync?: boolean }
    ): void {
        if (!dataSourceId) {
            logger.warn('setDataSource: dataSourceId is required');
            return;
        }

        const { merge = false, sync = false } = options || {};

        // 데이터 업데이트
        if (merge && this.currentFetchedData[dataSourceId]) {
            // 병합 모드: 기존 데이터와 shallow merge
            this.currentFetchedData[dataSourceId] = {
                ...this.currentFetchedData[dataSourceId],
                ...data,
            };
        } else {
            // 교체 모드: 전체 교체
            this.currentFetchedData[dataSourceId] = data;
        }

        // UI 리렌더링 트리거
        import('./template-engine').then(({ updateTemplateData }) => {
            updateTemplateData(
                { [dataSourceId]: this.currentFetchedData[dataSourceId] },
                sync ? { sync: true } : undefined
            );
        });

        logger.log(`setDataSource: Updated ${dataSourceId}`, merge ? '(merged)' : '(replaced)');
    }

    /**
     * 데이터 소스 내 특정 배열 아이템만 업데이트합니다.
     *
     * 전체 데이터소스를 교체하지 않고 특정 아이템만 수정하여
     * 불필요한 리렌더링을 방지합니다.
     *
     * @param dataSourceId 데이터 소스 ID
     * @param itemPath 배열 경로 (예: "data.data", "data.data[0].options")
     * @param itemId 업데이트할 아이템의 ID
     * @param updates 업데이트할 필드들
     * @param options 옵션
     * @returns 성공 여부
     */
    updateDataSourceItem(
        dataSourceId: string,
        itemPath: string,
        itemId: string | number,
        updates: Record<string, any>,
        options?: {
            idField?: string;
            merge?: boolean;
            skipRender?: boolean;
        }
    ): boolean {
        const { idField = 'id', merge = true, skipRender = false } = options || {};

        // 1. 현재 데이터 소스 가져오기
        const currentData = this.currentFetchedData[dataSourceId];
        if (!currentData) {
            logger.warn(`updateDataSourceItem: DataSource '${dataSourceId}' not found`);
            return false;
        }

        // 2. itemPath로 배열 찾기
        const pathParts = this.parseItemPath(itemPath);
        let target: any = currentData;

        for (const part of pathParts) {
            target = target?.[part];
            if (target === undefined) {
                logger.warn(`updateDataSourceItem: Path '${itemPath}' not found in dataSource`);
                return false;
            }
        }

        // 3. 배열인지 확인
        if (!Array.isArray(target)) {
            logger.warn(`updateDataSourceItem: Target at '${itemPath}' is not an array`);
            return false;
        }

        // 4. 아이템 찾기 및 업데이트
        const itemIndex = target.findIndex((item: any) =>
            String(item[idField]) === String(itemId)
        );

        if (itemIndex === -1) {
            logger.warn(`updateDataSourceItem: Item with ${idField}='${itemId}' not found`);
            return false;
        }

        // 5. 아이템 업데이트 (원본 배열 직접 수정 - 참조 유지)
        if (merge) {
            // 깊은 병합
            target[itemIndex] = this.deepMerge(target[itemIndex], updates);
        } else {
            // 얕은 병합
            target[itemIndex] = { ...target[itemIndex], ...updates };
        }

        // DevTools 추적
        const devTools = (window as any).G7Core?.devTools;
        if (devTools?.isEnabled?.()) {
            devTools.trackDataSourceUpdate?.({
                dataSourceId,
                updateType: 'partial',
                itemPath,
                itemId,
                updates,
                timestamp: Date.now(),
            });
        }

        // 6. 변경 알림 (선택적 렌더링)
        if (!skipRender) {
            import('./template-engine').then(({ updateTemplateData }) => {
                updateTemplateData(
                    { [dataSourceId]: this.currentFetchedData[dataSourceId] },
                    { sync: false }
                );
            });
        }

        logger.log(`updateDataSourceItem: Updated ${dataSourceId}.${itemPath}[${idField}=${itemId}]`);
        return true;
    }

    /**
     * itemPath를 파싱하여 경로 배열로 변환합니다.
     *
     * @example
     * parseItemPath("data.data[0].options") → ["data", "data", 0, "options"]
     */
    private parseItemPath(path: string): (string | number)[] {
        const result: (string | number)[] = [];
        const regex = /([^\.\[\]]+)|\[(\d+)\]/g;
        let match;

        while ((match = regex.exec(path)) !== null) {
            if (match[1] !== undefined) {
                result.push(match[1]);
            } else if (match[2] !== undefined) {
                result.push(parseInt(match[2], 10));
            }
        }

        return result;
    }

    /**
     * 깊은 병합을 수행합니다.
     *
     * 배열은 병합하지 않고 교체합니다.
     */
    private deepMerge(target: any, source: any): any {
        if (source === null || source === undefined) return target;
        if (typeof source !== 'object') return source;
        if (Array.isArray(source)) return source;

        const result = { ...target };
        for (const key of Object.keys(source)) {
            if (typeof source[key] === 'object' && source[key] !== null && !Array.isArray(source[key])) {
                result[key] = this.deepMerge(result[key] || {}, source[key]);
            } else {
                result[key] = source[key];
            }
        }
        return result;
    }

    /**
     * dot notation 경로에 값을 설정합니다.
     *
     * 중첩된 객체 경로를 자동으로 생성하며, 기존 값과 병합합니다.
     *
     * @param obj 대상 객체
     * @param path dot notation 경로 (예: "checkout.item_coupons")
     * @param value 설정할 값
     * @param mergeStrategy 병합 전략 ("deep" | "shallow" | "replace")
     *
     * @example
     * setValueAtPath({}, "checkout.item_coupons", { "1": 100 }, "deep")
     * // → { checkout: { item_coupons: { "1": 100 } } }
     */
    private setValueAtPath(obj: any, path: string, value: any, mergeStrategy: 'deep' | 'shallow' | 'replace' = 'deep'): void {
        const keys = path.split('.');
        let current = obj;

        // 마지막 키 전까지 경로 생성
        for (let i = 0; i < keys.length - 1; i++) {
            const key = keys[i];
            if (current[key] === undefined || current[key] === null || typeof current[key] !== 'object') {
                current[key] = {};
            }
            current = current[key];
        }

        // 마지막 키에 값 설정 (병합 전략 적용)
        const lastKey = keys[keys.length - 1];
        const existingValue = current[lastKey];

        if (mergeStrategy === 'replace' || existingValue === undefined) {
            current[lastKey] = value;
        } else if (mergeStrategy === 'shallow') {
            if (typeof existingValue === 'object' && typeof value === 'object' && !Array.isArray(value)) {
                current[lastKey] = { ...existingValue, ...value };
            } else {
                current[lastKey] = value;
            }
        } else {
            // deep merge (기본값)
            if (typeof existingValue === 'object' && typeof value === 'object' && !Array.isArray(value)) {
                current[lastKey] = this.deepMerge(existingValue, value);
            } else {
                current[lastKey] = value;
            }
        }
    }

    /**
     * 중첩된 객체 표기법을 dot notation 매핑으로 평탄화합니다.
     *
     * @param obj 중첩된 객체
     * @param prefix 현재 경로 접두사
     * @returns dot notation 키-값 쌍 배열
     *
     * @example
     * flattenNestedObjectToMappings({
     *   checkout: {
     *     item_coupons: "data.promotions.item_coupons",
     *     use_points: "data.use_points"
     *   }
     * })
     * // → [
     * //   { targetPath: "checkout.item_coupons", sourcePath: "data.promotions.item_coupons" },
     * //   { targetPath: "checkout.use_points", sourcePath: "data.use_points" }
     * // ]
     */
    private flattenNestedObjectToMappings(
        obj: Record<string, any>,
        prefix: string = ''
    ): Array<{ targetPath: string; sourcePath: string }> {
        const result: Array<{ targetPath: string; sourcePath: string }> = [];

        for (const [key, value] of Object.entries(obj)) {
            // _merge는 예약된 키 → 스킵
            if (key === '_merge') continue;

            const currentPath = prefix ? `${prefix}.${key}` : key;

            if (typeof value === 'string') {
                // 문자열 값 = 소스 경로
                result.push({ targetPath: currentPath, sourcePath: value });
            } else if (typeof value === 'object' && value !== null && !Array.isArray(value)) {
                // 중첩 객체 = 재귀 처리
                result.push(...this.flattenNestedObjectToMappings(value, currentPath));
            }
        }

        return result;
    }

    /**
     * 쿼리 파라미터를 업데이트하고 auto_fetch 데이터 소스를 refetch합니다.
     *
     * 같은 페이지에서 검색/필터 변경 시 컴포넌트 리마운트 없이
     * URL과 데이터만 갱신할 때 사용합니다.
     *
     * navigate 핸들러에서 replace: true 옵션 사용 시 자동 호출됩니다.
     *
     * @param newPath 새 경로 (쿼리스트링 포함)
     * @param options 선택 옵션
     *   - transitionOverlayTarget: 이 호출에 한해 transition_overlay.target 을 동적으로 override
     *     (탭 안의 서브 탭, 목록 페이지네이션 등 부분 영역만 spinner 가 표시되어야 할 때 사용)
     *     @since engine-v1.36.0
     * @returns refetch 완료 후 resolve되는 Promise
     *
     * @example
     * // ActionDispatcher에서 호출
     * G7Core.updateQueryParams('/admin/products?status=active&page=2');
     * G7Core.updateQueryParams('/admin/settings?tab=notification&channel=email', { transitionOverlayTarget: 'notif_channel_content' });
     */
    async updateQueryParams(newPath: string, options?: { transitionOverlayTarget?: string }): Promise<void> {
        logger.log('updateQueryParams:', newPath);

        // URL 파싱
        const url = new URL(newPath, window.location.origin);
        const newQueryParams = new URLSearchParams(url.search);

        // 1. URL 업데이트 (히스토리 교체, 리로드 없음)
        window.history.replaceState(null, '', newPath);
        logger.log('URL updated via replaceState');

        // 2. 내부 쿼리 컨텍스트 업데이트
        this.currentQueryParams = newQueryParams;
        logger.log('currentQueryParams updated:', Object.fromEntries(newQueryParams.entries()));

        // 3. auto_fetch: true인 데이터 소스들 refetch
        // WebSocket 소스는 이벤트 리스너(실시간 알림)이지 fetch 대상이 아님 (engine-v1.32.2 정책)
        // handleRouteChange progressive 경로와 동일하게 호출 전에 필터링하여 계약 일관성 확보
        const autoFetchDataSources = this.currentDataSources.filter(
            (ds: any) => ds.auto_fetch !== false && ds.type !== 'websocket'
        );

        if (autoFetchDataSources.length === 0) {
            logger.log('No auto_fetch data sources to refetch');
            return;
        }

        logger.log(`Refetching ${autoFetchDataSources.length} auto_fetch data sources`);

        // blur_until_loaded 지원: transition 상태를 pending으로 설정
        transitionManager.setPending(true);

        // transition_overlay spinner: wait_for 에 명시된 progressive 데이터소스가 refetch 대상이면 오버레이 표시
        // navigate replace:true(탭 전환 등)로 이 경로에 진입했을 때 handleRouteChange step 2.5 와 동일 동작 (@since engine-v1.35.0)
        // options.transitionOverlayTarget 으로 호출별 target 동적 override 지원 (탭 안의 서브 탭/페이지네이션 등) (@since engine-v1.36.0)
        const layoutJson = getState().currentLayoutJson as any;
        const overlayConfig = layoutJson?.transition_overlay;
        const waitForIds: string[] = Array.isArray(overlayConfig?.wait_for) ? overlayConfig.wait_for : [];
        const blockingRefetch = autoFetchDataSources.some((s: any) => (s.loading_strategy || 'progressive') === 'blocking');
        const waitForActive = waitForIds.length > 0 && autoFetchDataSources.some((s: any) =>
            waitForIds.includes(s.id)
            && s.type !== 'websocket'
            && (s.loading_strategy || 'progressive') !== 'background'
        );
        if ((blockingRefetch || waitForActive) && overlayConfig && typeof overlayConfig === 'object') {
            const effectiveTarget = options?.transitionOverlayTarget || overlayConfig.target;
            if (overlayConfig.enabled && overlayConfig.style === 'skeleton' && overlayConfig.skeleton?.component && effectiveTarget) {
                this.renderSkeletonOverlay(effectiveTarget, overlayConfig.skeleton, layoutJson, overlayConfig.fallback_target);
            } else if (overlayConfig.enabled && overlayConfig.style === 'spinner' && effectiveTarget) {
                this.renderSpinnerOverlay(effectiveTarget, overlayConfig.spinner, overlayConfig.fallback_target);
            }
        }

        try {
            const dataSourceManager = new DataSourceManager();

            // globalHeaders 설정 (레이아웃에서 정의한 전역 헤더)
            if (this.currentGlobalHeaders.length > 0) {
                dataSourceManager.setGlobalHeaders(this.currentGlobalHeaders);
            }

            // _global 상태 가져오기
            const state = getState();
            const globalState = state.currentDataContext?._global || {};

            // 모든 auto_fetch 데이터 소스 fetch
            const results = await dataSourceManager.fetchDataSourcesWithResults(
                autoFetchDataSources,
                this.currentRouteParams,
                this.currentQueryParams,
                globalState,
                undefined,  // localState: 초기 fetch에서는 _local 불필요
                { ignoreAutoFetch: false }
            );

            // 결과 처리 및 UI 업데이트
            // result.id 기반 조회로 매핑 (handleRouteChange blocking 경로와 동일 패턴)
            // 인덱스 기반 매핑은 fetchDataSourcesWithResults가 내부에서 소스를 필터링할 경우
            // autoFetchDataSources와 results의 인덱스가 어긋나 데이터가 잘못된 키에 기록됨
            const updateData: Record<string, any> = {};
            const localInit: Record<string, any> = {};
            const sourceById = new Map(autoFetchDataSources.map((ds: any) => [ds.id, ds]));

            for (const result of results) {
                const dataSourceDef = sourceById.get(result.id);
                if (!dataSourceDef) {
                    logger.warn(`Refetch result id not found in autoFetchDataSources: ${result.id}`);
                    continue;
                }
                const dataSourceId = result.id;

                if (result.state === 'success' && result.data !== undefined) {
                    // 캐시 업데이트
                    this.currentFetchedData[dataSourceId] = result.data;
                    updateData[dataSourceId] = result.data;

                    // initGlobal/initLocal 처리
                    this.processInitOptions([dataSourceDef], { [dataSourceId]: result.data }, localInit);

                    logger.log(`Data source refetched: ${dataSourceId}`);
                } else if (result.state === 'error') {
                    logger.error(`Failed to refetch data source: ${dataSourceId}`, result.error);
                }
            }

            // BindingEngine 캐시 무효화
            if (state.bindingEngine) {
                const keysToInvalidate = Object.keys(updateData);
                // query 관련 키도 무효화
                keysToInvalidate.push('query');
                state.bindingEngine.invalidateCacheByKeys(keysToInvalidate);
            }

            // initLocal이 있으면 추가
            if (Object.keys(localInit).length > 0) {
                updateData._localInit = localInit;
            }

            // UI 업데이트
            if (Object.keys(updateData).length > 0) {
                // query 컨텍스트도 업데이트 (배열 쿼리 파라미터 지원)
                updateData.query = parseQueryParams(this.currentQueryParams);

                // computed 재계산: 현재 레이아웃에 computed 정의가 있으면 재계산
                // (navigate replace: true 시 _local 상태가 변경되면 $computed도 갱신되어야 함)
                if (state.currentLayoutJson?.computed && Object.keys(state.currentLayoutJson.computed).length > 0) {
                    // 재계산에 필요한 컨텍스트 구성
                    const computeContext = {
                        ...state.currentDataContext,
                        ...updateData,
                        query: updateData.query,
                    };
                    const computedData = this.calculateComputed(state.currentLayoutJson.computed, computeContext);
                    if (Object.keys(computedData).length > 0) {
                        updateData._computed = computedData;
                        this.globalState._computed = computedData;
                        logger.log('Computed values recalculated in updateQueryParams:', Object.keys(computedData));
                    }
                }

                updateTemplateData(updateData, { sync: true });
                logger.log('Template data updated with new query params');
            }
        } catch (error) {
            logger.error('Error in updateQueryParams:', error);
        } finally {
            // transition 상태 해제
            transitionManager.setPending(false);
            // transition_overlay spinner 해제 (@since engine-v1.35.0)
            this.hideTransitionOverlay();
        }
    }

    /**
     * computed 값 계산
     *
     * 레이아웃에 정의된 computed 표현식을 평가하여 결과 객체를 반환합니다.
     * 각 computed 값은 dataContext를 기반으로 계산됩니다.
     * 문자열 표현식과 $switch 객체 표현식 모두 지원합니다.
     *
     * @param computed computed 정의 객체 (key: 이름, value: 표현식 문자열 또는 $switch 객체)
     * @param dataContext 표현식 평가에 사용할 데이터 컨텍스트
     * @returns 계산된 값 객체
     *
     * @example
     * // 레이아웃 JSON의 computed 정의
     * {
     *   "computed": {
     *     "fullName": "{{user.firstName}} {{user.lastName}}",
     *     "isAdmin": "{{user.role === 'admin'}}",
     *     "statusClass": {
     *       "$switch": "{{status}}",
     *       "$cases": { "active": "text-green-500", "inactive": "text-gray-500" },
     *       "$default": "text-gray-400"
     *     }
     *   }
     * }
     */
    private calculateComputed(
        computed: Record<string, string | ComputedSwitchDefinition>,
        dataContext: Record<string, any>
    ): Record<string, any> {
        const result: Record<string, any> = {};
        const bindingEngine = new DataBindingEngine();

        for (const [key, expression] of Object.entries(computed)) {
            try {
                let value: any;

                // $switch 형태의 computed 정의 처리
                if (this.isComputedSwitchDefinition(expression)) {
                    value = bindingEngine.resolveSwitch(expression, dataContext, { skipCache: true });
                } else if (typeof expression === 'string') {
                    // 문자열 표현식 처리
                    if (expression.startsWith('{{') && expression.endsWith('}}')) {
                        // {{...}} 형태의 표현식 평가
                        const innerExpression = expression.slice(2, -2).trim();
                        value = this.evaluateComputedExpression(innerExpression, dataContext);
                    } else {
                        // 순수 문자열은 그대로 사용
                        value = expression;
                    }
                }

                result[key] = value;
                logger.log(`Computed ${key}:`, value);
            } catch (error) {
                logger.warn(`Failed to calculate computed value: ${key}`, error);
                result[key] = undefined;
            }
        }

        return result;
    }

    /**
     * $switch 형태의 computed 정의인지 확인
     */
    private isComputedSwitchDefinition(obj: any): obj is ComputedSwitchDefinition {
        return (
            obj !== null &&
            typeof obj === 'object' &&
            !Array.isArray(obj) &&
            '$switch' in obj &&
            '$cases' in obj
        );
    }

    /**
     * computed 표현식 평가
     *
     * JavaScript 표현식을 안전하게 평가합니다.
     * dataContext의 값들을 컨텍스트로 사용합니다.
     *
     * @param expression 평가할 표현식
     * @param context 평가 컨텍스트
     * @returns 평가 결과
     */
    private evaluateComputedExpression(expression: string, context: Record<string, any>): any {
        try {
            // 안전한 표현식 평가를 위해 with 문과 Function 생성자 사용
            // eslint-disable-next-line @typescript-eslint/no-implied-eval
            const fn = new Function('ctx', `
                with(ctx) {
                    try {
                        return ${expression};
                    } catch (e) {
                        return undefined;
                    }
                }
            `);

            return fn(context);
        } catch (error) {
            logger.warn(`Expression evaluation failed: ${expression}`, error);
            return undefined;
        }
    }

    /**
     * 초기화 액션 실행
     *
     * 레이아웃 로드 후 init_actions에 정의된 핸들러를 순차적으로 실행합니다.
     * 정적 바인딩을 지원합니다 (route, query, _global, blocking 데이터 등).
     *
     * 중요: 각 액션 실행 후 데이터 컨텍스트를 갱신하여 이전 핸들러가 설정한
     * 상태(_global, _local)를 다음 핸들러에서 참조할 수 있도록 합니다.
     *
     * @param initActions 초기화 액션 목록
     * @param dataContext 바인딩에 사용할 데이터 컨텍스트
     */
    private async executeInitActions(
        initActions: InitActionDefinition[],
        dataContext: Record<string, any> = {}
    ): Promise<void> {
        const { getActionDispatcher } = await import('./template-engine');
        const actionDispatcher = getActionDispatcher();

        if (!actionDispatcher) {
            logger.warn('ActionDispatcher not available for init_actions');
            return;
        }

        logger.log('Executing init_actions:', initActions.map(a => a.handler));
        logger.log('Init actions dataContext:', dataContext);
        logger.log('Init actions dataContext._global:', dataContext._global);

        // 모듈/플러그인 핸들러(네임스페이스가 있는 핸들러) 목록 추출
        const moduleHandlers = initActions
            .map(a => a.handler)
            .filter(h => h.includes('.'));

        // 모듈 핸들러가 있으면 등록될 때까지 대기
        if (moduleHandlers.length > 0) {
            await this.waitForHandlers(actionDispatcher, moduleHandlers);
        }

        // 현재 데이터 컨텍스트 (각 액션 실행 후 갱신됨)
        let currentDataContext = { ...dataContext };

        for (const initAction of initActions) {
            try {
                // ActionDefinition 형태로 변환하여 핸들러 실행
                // resultTo, onSuccess, onError, auth_mode 등 전체 액션 체인 지원
                const actionDef = {
                    type: 'click' as const, // init_actions는 이벤트 타입이 필요 없지만 형식상 필요
                    handler: initAction.handler,
                    target: initAction.target,
                    params: initAction.params,
                    resultTo: initAction.resultTo,
                    onSuccess: initAction.onSuccess, // onSuccess 콜백 전달
                    onError: initAction.onError,     // onError 콜백 전달
                    if: initAction.if,                 // 조건부 실행 지원
                    conditions: initAction.conditions, // conditions 핸들러용 조건 분기 배열
                    auth_mode: (initAction as any).auth_mode, // 인증 모드 전달
                    auth_required: (initAction as any).auth_required, // 하위 호환
                } as any;

                // 핸들러 직접 호출 (최신 데이터 컨텍스트 전달)
                const handler = actionDispatcher.createHandler(actionDef, currentDataContext);

                // 더미 이벤트 생성하여 핸들러 실행
                const dummyEvent = new Event('init');
                await handler(dummyEvent);

                logger.log(`Init action executed: ${initAction.handler}`);

                // 액션 실행 후 데이터 컨텍스트 갱신 (다음 액션에서 최신 상태 사용)
                // 이전 핸들러가 G7Core.state.set()으로 설정한 _global 값을 다음 핸들러가 참조할 수 있도록 함
                const updatedGlobalState = this.globalState;
                currentDataContext = {
                    ...currentDataContext,
                    _global: { ...updatedGlobalState },
                    _local: updatedGlobalState._local || currentDataContext._local || {},
                };
                logger.log(`Data context refreshed after ${initAction.handler}:`, {
                    cartKey: currentDataContext._global?.cartKey,
                });
            } catch (error) {
                logger.error(`Failed to execute init action: ${initAction.handler}`, error);
            }
        }
    }

    /**
     * 핸들러가 등록될 때까지 대기
     *
     * 모듈/플러그인 핸들러는 에셋 로드 후에 등록되므로, init_actions 실행 전에 대기합니다.
     *
     * @param actionDispatcher ActionDispatcher 인스턴스
     * @param handlerNames 대기할 핸들러 이름 목록
     * @param maxWait 최대 대기 시간 (ms)
     */
    private async waitForHandlers(
        actionDispatcher: any,
        handlerNames: string[],
        maxWait: number = 5000
    ): Promise<void> {
        const startTime = Date.now();
        const checkInterval = 50; // 50ms마다 체크

        const allHandlersRegistered = () => {
            return handlerNames.every(name =>
                actionDispatcher.customHandlers?.has(name)
            );
        };

        // 이미 모두 등록되어 있으면 즉시 반환
        if (allHandlersRegistered()) {
            logger.log('All module handlers already registered');
            return;
        }

        logger.log('Waiting for module handlers:', handlerNames);

        return new Promise((resolve) => {
            const check = () => {
                if (allHandlersRegistered()) {
                    logger.log('All module handlers now registered');
                    resolve();
                    return;
                }

                if (Date.now() - startTime >= maxWait) {
                    const missing = handlerNames.filter(name =>
                        !actionDispatcher.customHandlers?.has(name)
                    );
                    logger.warn('Timeout waiting for handlers:', missing);
                    resolve(); // 타임아웃이어도 계속 진행
                    return;
                }

                setTimeout(check, checkInterval);
            };

            check();
        });
    }
}

/**
 * TemplateApp 인스턴스 생성 및 초기화 헬퍼 함수
 */
export function initTemplateApp(config: TemplateAppConfig): TemplateApp {
    const app = new TemplateApp(config);

    // WebSocket 설정이 전달된 경우 WebSocketManager에 설정
    if (config.websocket) {
        webSocketManager.configure(config.websocket);
        logger.log('WebSocket 설정 완료');
    }

    // DOMContentLoaded 이벤트에서 자동 초기화
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            app.init();
            window.__templateApp = app;
        });
    } else {
        // 이미 DOMContentLoaded가 완료된 경우 즉시 초기화
        app.init();
        window.__templateApp = app;
    }

    return app;
}

/**
 * 전역 객체에 노출 (TemplateApp 모듈 자체에서 처리)
 */
if (typeof window !== 'undefined') {
    (window as any).G7Core = (window as any).G7Core || {};

    // 직접 할당 (getter 방식은 template-engine.ts와 충돌 발생)
    (window as any).G7Core.initTemplateApp = initTemplateApp;

    // G7Core.dataSource API 노출
    // TemplateApp 인스턴스의 refetchDataSource, getDataSource 메서드에 접근
    (window as any).G7Core.dataSource = {
        /**
         * 특정 데이터 소스를 다시 fetch
         *
         * @param dataSourceId 데이터 소스 ID (예: 'modules', 'sidebar_menus')
         * @param options refetch 옵션
         *   - sync: true면 startTransition 없이 즉시 렌더링
         *   - globalStateOverride: endpoint 표현식에서 사용할 _global 상태 오버라이드 (sequence 내 setState 후 업데이트된 값 반영)
         * @returns Promise<any> fetch된 데이터 또는 undefined
         */
        refetch: async (dataSourceId: string, options?: { sync?: boolean; globalStateOverride?: Record<string, any> }): Promise<any> => {
            const app = window.__templateApp;
            if (!app) {
                logger.warn('TemplateApp not initialized (G7Core.dataSource.refetch)');
                return undefined;
            }
            return app.refetchDataSource(dataSourceId, options);
        },

        /**
         * 현재 fetch된 데이터 소스 데이터 반환
         *
         * @param dataSourceId 데이터 소스 ID
         * @returns 캐시된 데이터 또는 undefined
         */
        get: (dataSourceId: string): any => {
            const app = window.__templateApp;
            if (!app) {
                logger.warn('TemplateApp not initialized (G7Core.dataSource.get)');
                return undefined;
            }
            return app.getDataSource(dataSourceId);
        },
    };
}
