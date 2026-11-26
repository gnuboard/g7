/**
 * ErrorPageHandler
 *
 * 에러 페이지를 처리하는 핸들러 클래스
 * template.json의 error_config를 읽어 에러 코드에 맞는 레이아웃을 로드하고 렌더링합니다.
 */

import type { LayoutLoader } from './LayoutLoader';
import type { DataSourceManager, DataSource } from './DataSourceManager';
import type { GlobalHeaderRule } from './LayoutLoader';
import { createLogger } from '../utils/Logger';
import { suffixed } from '../support/assetUrl';
import { TEMPLATE_ERROR_CONFIG_KEY, hasInlineTemplateConfigKeys, readInlineTemplateConfig } from './inlineTemplateConfig';

const logger = createLogger('ErrorPageHandler');

/**
 * 렌더링 옵션 인터페이스
 */
export interface RenderOptions {
    containerId: string;
    layoutJson: unknown;
    dataContext?: Record<string, unknown>;
    translationContext?: {
        templateId: string;
        locale: string;
    };
}

/**
 * 렌더링 함수 타입 (의존성 주입용)
 */
export type RenderFunction = (options: RenderOptions) => Promise<void>;

/**
 * 에러 설정 인터페이스
 */
export interface ErrorConfig {
    layouts: Record<number | string, string>; // { 404: "404", 403: "403", 500: "500" }
}

/**
 * ErrorPageHandler 생성자 옵션
 */
export interface ErrorPageHandlerOptions {
    templateId: string;
    layoutLoader: LayoutLoader;
    locale: string;
    debug: boolean;
    renderFunction: RenderFunction;
    dataSourceManager: DataSourceManager;
    /** 생성 시점 전역 상태 — `getGlobalState` 가 없을 때만 쓴다(하위 호환) */
    globalState?: Record<string, unknown>;
    /**
     * 그릴 때마다 지금의 전역 상태를 돌려준다 (@since engine-v1.83.0).
     * 앱은 상태를 바꿀 때 전역 상태 객체를 새로 만들므로, 생성 때 받은 참조는 곧 옛 값이 된다.
     */
    getGlobalState?: () => Record<string, unknown>;
    /** 그릴 때마다 지금 화면의 route·query 를 돌려준다 (@since engine-v1.83.0) */
    getRouteContext?: () => { route: Record<string, unknown>; query: Record<string, unknown> };
    /** 그릴 때마다 지금 레이아웃의 전역 헤더 규칙을 돌려준다 — 오류 화면 데이터 소스 요청에 싣는다 (@since engine-v1.83.0) */
    getGlobalHeaders?: () => GlobalHeaderRule[];
    /**
     * 오류 화면을 그리기 직전에 바인딩 값 캐시를 비운다 (@since engine-v1.83.0).
     * 같은 화면에서 403 → 500 을 연속으로 그리면 `{{errorCode}}` 가 경로 글자 키 캐시의 앞 값으로 그려졌다.
     */
    clearBindingCache?: () => void;
    /**
     * 템플릿에 그 코드의 오류 레이아웃도 `default` 도 없을 때(또는 error_config 가 없을 때) 엔진 내장 오류 화면을 그린다
     * (engine-v1.95.0). 종전에는 아무것도 그리지 않고 끝나 화면이 오류 전 모습(빈 장바구니 등)으로 남았다.
     * 생략하면 종전처럼 false 로 끝난다(단위 시험·편집기 미리보기).
     */
    renderBuiltinFallback?: (errorCode: number) => void;
}

/**
 * 에러 페이지 핸들러 클래스
 */
export class ErrorPageHandler {
    private templateId: string;
    private layoutLoader: LayoutLoader;
    private locale: string;
    private debug: boolean;
    private renderFunction: RenderFunction;
    private dataSourceManager: DataSourceManager;
    private globalState: Record<string, unknown>;
    private getGlobalStateFn?: () => Record<string, unknown>;
    private getRouteContextFn?: () => { route: Record<string, unknown>; query: Record<string, unknown> };
    private getGlobalHeadersFn?: () => GlobalHeaderRule[];
    private clearBindingCacheFn?: () => void;
    private renderBuiltinFallbackFn?: (errorCode: number) => void;
    private errorConfig: ErrorConfig | null = null;
    private configLoaded: boolean = false;

    constructor(options: ErrorPageHandlerOptions) {
        this.templateId = options.templateId;
        this.layoutLoader = options.layoutLoader;
        this.locale = options.locale;
        this.debug = options.debug;
        this.renderFunction = options.renderFunction;
        this.dataSourceManager = options.dataSourceManager;
        this.globalState = options.globalState || {};
        this.getGlobalStateFn = options.getGlobalState;
        this.getRouteContextFn = options.getRouteContext;
        this.getGlobalHeadersFn = options.getGlobalHeaders;
        this.clearBindingCacheFn = options.clearBindingCache;
        this.renderBuiltinFallbackFn = options.renderBuiltinFallback;
    }

    /**
     * 템플릿 오류 레이아웃이 없을 때 엔진 내장 오류 화면으로 그린다 (engine-v1.95.0).
     *
     * @param errorCode 오류 코드
     * @param reason 경고 로그에 남길 사유
     * @param shouldRender 그리기 직전 판정
     * @returns 그렸으면 true
     */
    private renderBuiltin(errorCode: number, reason: string, shouldRender?: () => boolean): boolean {
        if (!this.renderBuiltinFallbackFn) {
            return false;
        }
        if (shouldRender && !shouldRender()) {
            logger.log(`Builtin error display ${errorCode} skipped — route changed while loading`);
            return false;
        }
        logger.warn(`${reason} — rendering the engine builtin error display for code ${errorCode}`);
        this.renderBuiltinFallbackFn(errorCode);

        return true;
    }

    /**
     * template.json에서 error_config를 로드합니다.
     */
    async loadConfig(): Promise<ErrorConfig | null> {
        if (this.configLoaded) {
            return this.errorConfig;
        }

        // 셸이 `error_config` 를 실었으면(template.json `expose` 선언, null 이면 「선언했고 값 없음」) API 를 부르지 않는다
        // (@since engine-v1.96.0). 실리지 않았으면 종전처럼 config API
        const inline = readInlineTemplateConfig();
        if (hasInlineTemplateConfigKeys(inline, [TEMPLATE_ERROR_CONFIG_KEY])) {
            const value = inline![TEMPLATE_ERROR_CONFIG_KEY] as ErrorConfig | null;
            this.errorConfig = value && typeof value === 'object' && (value as ErrorConfig).layouts ? value : null;
            this.configLoaded = true;
            if (this.debug) {
                logger.log('Error config from shell:', this.errorConfig);
            }

            return this.errorConfig;
        }

        try {
            // template.json fetch
            const response = await fetch(suffixed(`/api/templates/${this.templateId}/config`, 'json'));

            if (!response.ok) {
                if (this.debug) {
                    logger.warn('Failed to load template config:', response.statusText);
                }
                this.configLoaded = true;
                return null;
            }

            const result = await response.json();

            if (!result.success || !result.data) {
                if (this.debug) {
                    logger.warn('Invalid template config response');
                }
                this.configLoaded = true;
                return null;
            }

            const templateData = result.data;

            // error_config 섹션 확인
            if (!templateData.error_config || !templateData.error_config.layouts) {
                if (this.debug) {
                    logger.warn('No error_config found in template.json');
                }
                this.configLoaded = true;
                return null;
            }

            this.errorConfig = templateData.error_config;
            this.configLoaded = true;

            if (this.debug) {
                logger.log('Error config loaded:', this.errorConfig);
            }

            return this.errorConfig;
        } catch (error) {
            logger.error('Failed to load error config:', error);
            this.configLoaded = true;
            return null;
        }
    }

    /**
     * 에러 페이지를 렌더링합니다.
     *
     * @param errorCode 에러 코드 (404, 403, 500 등)
     * @param containerId 렌더링할 컨테이너 ID (기본값: 'app')
     * @param shouldRender 그리기 직전 판정 — 거짓이면 그리지 않고 false (오류 레이아웃 적재 중 경로가 바뀐 경우, engine-v1.80.1)
     * @returns 렌더링 성공 여부
     */
    async renderError(errorCode: number, containerId: string = 'app', shouldRender?: () => boolean): Promise<boolean> {
        try {
            // 1. error_config 로드
            const config = await this.loadConfig();

            if (!config) {
                // error_config 가 없는 템플릿 — 엔진 내장 오류 화면으로 그린다 (engine-v1.95.0)
                return this.renderBuiltin(errorCode, 'No error_config in template.json', shouldRender);
            }

            // 2. 에러 코드에 맞는 레이아웃 이름 찾기 — 없으면 템플릿의 공통 오류 레이아웃(`layouts.default`), 그것도 없으면 엔진 내장
            //    오류 화면 (engine-v1.95.0). 종전에는 아무것도 그리지 않고 끝나(예: 400) 화면이 오류 전 모습으로 남았다.
            //    다른 코드의 레이아웃(500 등)으로 대신하지 않는다 — 그 레이아웃은 코드와 문구를 박아 두어(「500 서버 오류」) 다른 코드를 잘못 알린다
            const codeLayout = config.layouts[errorCode] || config.layouts[String(errorCode)];
            const layoutName = codeLayout || config.layouts.default;

            if (!layoutName) {
                return this.renderBuiltin(errorCode, `No error layout for code ${errorCode} and no default in error_config`, shouldRender);
            }
            if (!codeLayout) {
                logger.warn(`No error layout for code ${errorCode} — rendering error_config default layout: ${layoutName}`);
            }

            if (this.debug) {
                logger.log(`Loading error layout: ${layoutName} for code: ${errorCode}`);
            }

            // 3. 레이아웃 로드 (기존 LayoutLoader 활용 - extends 병합 자동 처리)
            const layoutData = await this.layoutLoader.loadLayout(this.templateId, layoutName);

            if (!layoutData) {
                if (this.debug) {
                    logger.error(`Failed to load error layout: ${layoutName}`);
                }
                return false;
            }

            if (this.debug) {
                logger.log('Error layout loaded:', layoutData);
            }

            // 지금의 전역 상태 사본 — initGlobal 은 이 사본에 쓴다(앱의 전역 상태 원본을 바꾸지 않는다, @since engine-v1.83.0)
            const globalState: Record<string, unknown> = { ...(this.getGlobalStateFn ? this.getGlobalStateFn() : this.globalState) };
            const routeContext = this.getRouteContextFn?.() ?? { route: {}, query: {} };

            // 4. data_sources fetch (레이아웃에 정의된 API 데이터 로드)
            let fetchedData: Record<string, unknown> = {};
            // 일반 화면과 같이 if 조건으로 거른다 — 꺼진 모듈의 공통 데이터 소스를 오류 화면에서 부르지 않게 (@since engine-v1.84.0)
            const dataSources: DataSource[] = this.dataSourceManager.filterByCondition(
                layoutData.data_sources || [],
                { route: routeContext.route as Record<string, string>, query: routeContext.query as Record<string, string>, _global: globalState }
            );

            if (dataSources.length > 0) {
                if (this.debug) {
                    logger.log('Fetching data sources:', dataSources.map((s: DataSource) => s.id));
                }

                // blocking 데이터 소스 먼저 fetch
                const blockingSources = dataSources.filter(
                    (s: DataSource) => s.loading_strategy === 'blocking'
                );
                const progressiveSources = dataSources.filter(
                    (s: DataSource) => !s.loading_strategy || s.loading_strategy === 'progressive'
                );

                // blocking + progressive 모두 fetch (에러 페이지에서는 모든 데이터 로드 후 렌더링)
                const allSourcesToFetch = [...blockingSources, ...progressiveSources];

                if (allSourcesToFetch.length > 0) {
                    // 레이아웃 전역 헤더를 오류 화면 요청에도 싣는다 (@since engine-v1.83.0)
                    const globalHeaders = this.getGlobalHeadersFn?.() ?? [];
                    if (globalHeaders.length > 0) {
                        this.dataSourceManager.setGlobalHeaders(globalHeaders);
                    }
                    try {
                        fetchedData = await this.dataSourceManager.fetchDataSources(
                            allSourcesToFetch,
                            {}, // 에러 페이지는 라우트 파라미터 없음
                            new URLSearchParams() // 쿼리 파라미터도 없음
                        );

                        if (this.debug) {
                            logger.log('Data sources fetched:', Object.keys(fetchedData));
                        }
                    } catch (fetchError) {
                        logger.error('Failed to fetch data sources:', fetchError);
                        // 데이터 fetch 실패해도 에러 페이지는 렌더링 진행
                    }
                }
            }

            // 5. initGlobal/initLocal 처리 (fetch된 데이터를 _global/_local에 매핑)
            const localInit: Record<string, unknown> = {};
            if (dataSources.length > 0) {
                this.processInitOptions(dataSources, fetchedData, localInit, globalState);

                if (this.debug) {
                    logger.log('initOptions processed:', {
                        globalKeys: Object.keys(globalState),
                        localKeys: Object.keys(localInit),
                    });
                }
            }

            // 6. 렌더링 (에러 코드 + fetch된 데이터 + 전역 상태 + 로컬 상태)
            const dataContext: Record<string, unknown> = {
                ...fetchedData,
                errorCode,
                route: routeContext.route,
                query: routeContext.query,
                _global: globalState,
                _local: { ...localInit },
            };

            // 적재·데이터 fetch 동안 다른 화면으로 옮겼으면 그 화면을 덮지 않는다
            if (shouldRender && !shouldRender()) {
                logger.log(`Error page ${errorCode} skipped — route changed while loading`);
                return false;
            }

            // 바인딩 값 캐시는 경로 글자가 키라 앞 오류 화면(`{{errorCode}}`)·앞 화면 값이 남는다 — 그리기 직전에 비운다 (@since engine-v1.83.0)
            this.clearBindingCacheFn?.();

            await this.renderFunction({
                containerId,
                layoutJson: layoutData,
                dataContext,
                translationContext: {
                    templateId: this.templateId,
                    locale: this.locale,
                },
            });

            if (this.debug) {
                logger.log(`Error page ${errorCode} rendered successfully`);
            }

            return true;
        } catch (error) {
            logger.error(`Failed to render error page ${errorCode}:`, error);
            return false;
        }
    }

    /**
     * data_sources의 initGlobal/initLocal 옵션을 처리하여
     * globalState와 localInit에 fetch된 데이터를 매핑합니다.
     *
     * TemplateApp.processInitOptions()의 간소화 버전으로,
     * 에러 페이지에서 필요한 형태만 지원합니다:
     * - 문자열 형태: initGlobal: "currentUser"
     * - 배열 형태: initGlobal: ["currentUser", "settings"]
     * - 객체 형태: initGlobal: { key: "cartCount", path: "count" }
     *
     * @since engine-v1.28.1
     * @param dataSources 데이터 소스 정의 배열
     * @param fetchedData fetch된 데이터 (키: 데이터소스 ID)
     * @param localInit _local 상태에 매핑할 데이터 (출력용)
     * @param globalState initGlobal 을 쓸 전역 상태 사본 (출력용, @since engine-v1.83.0 — 종전에는 앱 전역 상태 원본에 썼다)
     */
    private processInitOptions(
        dataSources: DataSource[],
        fetchedData: Record<string, unknown>,
        localInit: Record<string, unknown>,
        globalState: Record<string, unknown>
    ): void {
        for (const source of dataSources) {
            const rawData = fetchedData[source.id] as any;
            if (!rawData) continue;

            // API 응답의 data 필드 추출 (success/data 래핑 해제)
            const actualData = rawData?.data ?? rawData;

            // initLocal 처리
            if (source.initLocal) {
                if (typeof source.initLocal === 'string') {
                    localInit[source.initLocal] = actualData;
                    logger.log(`initLocal: ${source.id}.data -> _local.${source.initLocal}`);
                } else if (typeof source.initLocal === 'object' && 'key' in (source.initLocal as any)) {
                    const { key, path } = source.initLocal as { key: string; path?: string };
                    localInit[key] = path ? this.getValueByPath(actualData, path) : actualData;
                    logger.log(`initLocal: ${source.id}.data${path ? '.' + path : ''} -> _local.${key}`);
                }
            }

            // initGlobal 처리
            if (source.initGlobal) {
                const items = Array.isArray(source.initGlobal)
                    ? source.initGlobal
                    : [source.initGlobal];

                for (const item of items) {
                    if (typeof item === 'string') {
                        globalState[item] = actualData;
                        logger.log(`initGlobal: ${source.id}.data -> _global.${item}`);
                    } else if (typeof item === 'object' && item !== null && 'key' in item) {
                        const { key, path } = item as { key: string; path?: string };
                        globalState[key] = path ? this.getValueByPath(actualData, path) : actualData;
                        logger.log(`initGlobal: ${source.id}.data${path ? '.' + path : ''} -> _global.${key}`);
                    }
                }
            }
        }
    }

    /**
     * 점 표기법 경로로 중첩 객체의 값을 가져옵니다.
     *
     * @param obj 대상 객체
     * @param path 점 표기법 경로 (예: "data.count")
     * @returns 경로에 해당하는 값
     */
    private getValueByPath(obj: any, path: string): any {
        return path.split('.').reduce((acc, part) => acc?.[part], obj);
    }

    /**
     * 전역 상태를 바꿉니다 (`getGlobalState` 를 주지 않은 생성에서 쓰는 값).
     *
     * 넘긴 상태로 교체한다 (@since engine-v1.83.0) — 종전에는 병합해, 앱에서 지운 키가 오류 화면에 남았다.
     *
     * @param state 새로운 전역 상태
     */
    updateGlobalState(state: Record<string, unknown>): void {
        this.globalState = { ...state };
    }

    /**
     * 로케일을 업데이트합니다.
     *
     * 언어 변경 시 번역 컨텍스트에 최신 로케일이 반영되도록 합니다.
     *
     * @param locale 새로운 로케일 (예: 'ko', 'en')
     */
    updateLocale(locale: string): void {
        this.locale = locale;

        if (this.debug) {
            logger.log('Locale updated:', locale);
        }
    }

    /**
     * 에러 설정이 로드되었는지 확인합니다.
     */
    isConfigLoaded(): boolean {
        return this.configLoaded;
    }

    /**
     * 특정 에러 코드에 대한 레이아웃이 정의되어 있는지 확인합니다.
     *
     * @param errorCode 확인할 에러 코드
     * @returns 레이아웃 정의 여부
     */
    async hasErrorLayout(errorCode: number): Promise<boolean> {
        const config = await this.loadConfig();
        if (!config) {
            return false;
        }
        return !!(config.layouts[errorCode] || config.layouts[String(errorCode)]);
    }

    /**
     * 캐시된 설정을 초기화합니다.
     */
    clearConfigCache(): void {
        this.errorConfig = null;
        this.configLoaded = false;

        if (this.debug) {
            logger.log('Config cache cleared');
        }
    }
}
