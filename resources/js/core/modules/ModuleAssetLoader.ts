/**
 * ModuleAssetLoader
 *
 * 모듈/플러그인의 에셋(JS, CSS)을 동적으로 로드하는 클래스입니다.
 * TemplateApp 초기화 시 window.G7Config.moduleAssets를 기반으로
 * 활성화된 모듈의 에셋을 로드합니다.
 */

import { createLogger } from '../utils/Logger';
import { loadScriptWithRetry } from '../template-engine/networkResilience';

const logger = createLogger('ModuleAssetLoader');

/**
 * 모듈 에셋 정보 인터페이스
 */
export interface ModuleAsset {
    /** 모듈 식별자 (vendor-module 형식) */
    identifier: string;
    /** JS 번들 URL */
    js?: string;
    /** CSS 번들 URL */
    css?: string;
    /** 로드 우선순위 (낮을수록 먼저) */
    priority: number;
    /** 외부 스크립트 정의 (조건부 로드용) */
    external?: ExternalScript[];
}

/**
 * 외부 스크립트 정의 인터페이스
 */
export interface ExternalScript {
    /** 스크립트 URL */
    src: string;
    /** 스크립트 ID (중복 로드 방지) */
    id: string;
    /** 조건부 로드 표현식 (예: "{{_global.settings.useLib}}") */
    if?: string;
}

/**
 * 로드된 에셋 정보
 */
interface LoadedAsset {
    /** 에셋 타입 (js, css) */
    type: 'js' | 'css';
    /** DOM 요소 */
    element: HTMLElement;
}

/**
 * ModuleAssetLoader 클래스
 *
 * 모듈 에셋의 동적 로드/언로드를 관리합니다.
 */
export class ModuleAssetLoader {
    /** 로드된 에셋 맵 (identifier -> LoadedAsset[]) */
    private loadedAssets: Map<string, LoadedAsset[]> = new Map();

    /** 로드 중인 프로미스 맵 (중복 로드 방지) */
    private loadingPromises: Map<string, Promise<void>> = new Map();

    /**
     * 로드에 최종 실패한 JS 번들/확장 식별자 집합
     *
     * 실패가 확정된 확장의 핸들러는 영원히 등록되지 않는다. `waitForHandlers` 가
     * 이 집합을 보고 오지 않을 핸들러를 기다리지 않도록 하기 위한 사실 기록이다.
     *
     * @since engine-v1.53.0
     */
    private failedJsAssets: Set<string> = new Set();

    /**
     * JS 에셋 로드에 최종 실패한 확장이 하나라도 있는지 반환합니다.
     *
     * @return bool 실패한 JS 번들/확장이 있으면 true
     * @since engine-v1.53.0
     */
    hasFailedJsAssets(): boolean {
        return this.failedJsAssets.size > 0;
    }

    /**
     * JS 에셋 로드에 최종 실패한 식별자 목록을 반환합니다.
     *
     * @return string[] 실패한 번들 키/확장 식별자 목록
     * @since engine-v1.53.0
     */
    getFailedJsAssets(): string[] {
        return [...this.failedJsAssets];
    }

    /**
     * 활성화된 모듈들의 에셋을 로드합니다.
     *
     * CSS/JS 모두 병렬 fetch로 로드합니다. JS는 `script.async = false` +
     * 정렬된 DOM append 순서로 **실행 순서는 priority 정렬대로** 보장됩니다.
     * (HTML 사양: async=false 스크립트는 삽입 순서대로 실행)
     *
     * 확장 하나의 로드 실패가 나머지 확장을 함께 죽이지 않도록 `allSettled` 로 모은다.
     * 실패한 확장은 `failedJsAssets` 에 기록되어 `waitForHandlers` 가 오지 않을
     * 핸들러를 기다리지 않게 한다. (개별 로딩은 확장별로 독립이므로 부분 열화가 옳다.
     * 반면 병합 번들은 확장 전체가 한 파일이라 실패 시 reject 로 표면화한다.)
     *
     * @param extensions 모듈 에셋 배열
     * @return Promise<void>
     */
    async loadActiveExtensionAssets(extensions: ModuleAsset[]): Promise<void> {
        if (!extensions || extensions.length === 0) {
            logger.log('No module assets to load');
            return;
        }

        // 우선순위 순으로 정렬 (낮을수록 먼저)
        const sortedExtensions = [...extensions].sort((a, b) => a.priority - b.priority);

        logger.log('Loading module assets:', sortedExtensions.map(e => e.identifier));

        // CSS 병렬 로드 (렌더링 블로킹 방지)
        const cssPromises = sortedExtensions
            .filter(ext => ext.css)
            .map(ext => this.loadCSS(ext.identifier, ext.css!));

        // JS 병렬 fetch (script.async=false로 실행 순서는 append 순서대로 유지)
        // 정렬된 순서로 순차 append하여 우선순위 기반 실행 순서를 보장
        const jsPromises = sortedExtensions
            .filter(ext => ext.js)
            .map(ext => this.loadJS(ext.identifier, ext.js!));

        const results = await Promise.allSettled([...cssPromises, ...jsPromises]);

        const failures = results.filter((r): r is PromiseRejectedResult => r.status === 'rejected');
        if (failures.length > 0) {
            logger.warn(
                `Some module assets failed to load (${failures.length}/${results.length}); continuing with the rest`,
                this.getFailedJsAssets()
            );
            return;
        }

        logger.log('All module assets loaded successfully');
    }

    /**
     * 서버측에서 병합된 확장 번들(JS/CSS)을 로드합니다.
     *
     * 활성 모듈/플러그인 IIFE 를 priority 순으로 이어붙인 단일 파일을 하나의
     * `<script async=false>` 로, CSS 는 하나의 `<link>` 로 append 한다. 개별
     * 로딩과 달리 확장 수와 무관하게 요청 1(+1)건으로 끝난다. `<script async=false>`
     * 는 번들 내부 물리 순서로 실행되므로 병합 시 priority 정렬이 곧 실행 순서다.
     *
     * 중복 append 가드 — 같은 key(module/plugin) 는 최초 1회만 로드한다.
     *
     * @param key 번들 구분 키 (예: 'module' | 'plugin')
     * @param jsUrl 병합 JS URL (없으면 스킵)
     * @param cssUrl 병합 CSS URL (없으면 스킵)
     * @since engine-v1.52.0
     */
    async loadBundle(key: string, jsUrl?: string | null, cssUrl?: string | null): Promise<void> {
        const promises: Promise<void>[] = [];

        if (cssUrl) {
            promises.push(this.loadBundleCss(key, cssUrl));
        }

        if (jsUrl) {
            promises.push(this.loadBundleJs(key, jsUrl));
        }

        if (promises.length === 0) {
            logger.log(`No bundle assets to load for: ${key}`);
            return;
        }

        await Promise.all(promises);
    }

    /**
     * 병합 CSS 번들을 단일 `<link>` 로 로드합니다(중복 가드).
     *
     * @param key 번들 구분 키
     * @param url 병합 CSS URL
     */
    private async loadBundleCss(key: string, url: string): Promise<void> {
        const elementId = `ext-bundle-css-${key}`;

        if (document.getElementById(elementId)) {
            logger.log(`Bundle CSS already loaded: ${key}`);
            return;
        }

        return new Promise<void>((resolve) => {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = url;
            link.id = elementId;

            link.onload = () => {
                logger.log(`Bundle CSS loaded: ${key}`);
                this.registerLoadedAsset(`bundle-${key}`, { type: 'css', element: link });
                resolve();
            };

            link.onerror = () => {
                logger.warn(`Failed to load bundle CSS: ${key} (${url})`);
                resolve();
            };

            document.head.appendChild(link);
        });
    }

    /**
     * 병합 JS 번들을 단일 `<script async=false>` 로 로드합니다(중복 가드).
     *
     * 네트워크 일시 실패에 재시도하고, 3회 시도 후에도 실패하면 **reject** 한다.
     * 종전에는 `onerror` 가 `resolve()` 하여 실패가 성공으로 위장됐고, 상위
     * `loadExtensionAssets` 의 try/catch 가 무력화되어 앱은 한참 뒤 미등록 핸들러
     * 지점에서 죽었다. 실패는 발생 지점에서 표면화해야 한다.
     *
     * @param key 번들 구분 키
     * @param url 병합 JS URL
     * @return Promise<void> 로드 성공 시 resolve
     * @throws Error 재시도 소진 후에도 로드에 실패한 경우
     * @since engine-v1.53.0 (실패 계약 변경: resolve → reject)
     */
    private async loadBundleJs(key: string, url: string): Promise<void> {
        const elementId = `ext-bundle-js-${key}`;

        if (document.getElementById(elementId)) {
            logger.log(`Bundle JS already loaded: ${key}`);
            return;
        }

        const existingPromise = this.loadingPromises.get(elementId);
        if (existingPromise) {
            logger.log(`Bundle JS already loading: ${key}`);
            return existingPromise;
        }

        const loadPromise = loadScriptWithRetry(url, { id: elementId }, { label: `bundle JS: ${key}` })
            .then(() => {
                logger.log(`Bundle JS loaded: ${key}`);
                const script = document.getElementById(elementId);
                if (script) {
                    this.registerLoadedAsset(`bundle-${key}`, { type: 'js', element: script });
                }
                this.loadingPromises.delete(elementId);
            })
            .catch((error) => {
                logger.warn(`Failed to load bundle JS: ${key} (${url})`, error);
                this.failedJsAssets.add(key);
                this.loadingPromises.delete(elementId);
                throw error;
            });

        this.loadingPromises.set(elementId, loadPromise);
        return loadPromise;
    }

    /**
     * CSS 파일을 동적으로 로드합니다.
     *
     * @param identifier 모듈 식별자
     * @param url CSS 파일 URL
     */
    private async loadCSS(identifier: string, url: string): Promise<void> {
        const elementId = `module-css-${identifier}`;

        // 이미 로드된 경우 스킵
        if (document.getElementById(elementId)) {
            logger.log(`CSS already loaded: ${identifier}`);
            return;
        }

        return new Promise<void>((resolve, reject) => {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = url;
            link.id = elementId;

            link.onload = () => {
                logger.log(`CSS loaded: ${identifier}`);
                this.registerLoadedAsset(identifier, { type: 'css', element: link });
                resolve();
            };

            link.onerror = () => {
                logger.warn(`Failed to load CSS: ${identifier} (${url})`);
                // CSS 로드 실패는 경고만 출력하고 계속 진행
                resolve();
            };

            document.head.appendChild(link);
        });
    }

    /**
     * JS 파일을 동적으로 로드합니다.
     *
     * 로드 완료 후 모듈의 initModule() 함수가 자동으로 실행됩니다.
     * (IIFE 번들이 로드되면서 즉시 실행)
     *
     * @param identifier 모듈 식별자
     * @param url JS 파일 URL
     */
    private async loadJS(identifier: string, url: string): Promise<void> {
        const elementId = `module-js-${identifier}`;

        // 이미 로드된 경우 스킵
        if (document.getElementById(elementId)) {
            logger.log(`JS already loaded: ${identifier}`);
            return;
        }

        // 이미 로딩 중인 경우 대기
        const existingPromise = this.loadingPromises.get(identifier);
        if (existingPromise) {
            logger.log(`JS already loading: ${identifier}`);
            return existingPromise;
        }

        const loadPromise = loadScriptWithRetry(url, { id: elementId }, { label: `JS: ${identifier}` })
            .then(() => {
                logger.log(`JS loaded: ${identifier}`);
                const script = document.getElementById(elementId);
                if (script) {
                    this.registerLoadedAsset(identifier, { type: 'js', element: script });
                }
                this.loadingPromises.delete(identifier);
            })
            .catch((error) => {
                logger.warn(`Failed to load JS: ${identifier} (${url})`, error);
                this.failedJsAssets.add(identifier);
                this.loadingPromises.delete(identifier);
                throw error;
            });

        this.loadingPromises.set(identifier, loadPromise);
        return loadPromise;
    }

    /**
     * 로드된 에셋을 맵에 등록합니다.
     *
     * @param identifier 모듈 식별자
     * @param asset 로드된 에셋 정보
     */
    private registerLoadedAsset(identifier: string, asset: LoadedAsset): void {
        const assets = this.loadedAssets.get(identifier) || [];
        assets.push(asset);
        this.loadedAssets.set(identifier, assets);
    }

    /**
     * 특정 모듈의 에셋을 언로드합니다.
     *
     * 모듈 비활성화 시 호출하여 DOM에서 에셋을 제거합니다.
     *
     * @param identifier 모듈 식별자
     */
    unloadExtensionAsset(identifier: string): void {
        const assets = this.loadedAssets.get(identifier);

        if (!assets || assets.length === 0) {
            logger.log(`No assets to unload for: ${identifier}`);
            return;
        }

        assets.forEach(asset => {
            if (asset.element.parentNode) {
                asset.element.parentNode.removeChild(asset.element);
                logger.log(`${asset.type.toUpperCase()} unloaded: ${identifier}`);
            }
        });

        this.loadedAssets.delete(identifier);
        logger.log(`All assets unloaded for: ${identifier}`);
    }

    /**
     * 모든 모듈 에셋을 언로드합니다.
     */
    unloadAllAssets(): void {
        const identifiers = Array.from(this.loadedAssets.keys());

        identifiers.forEach(identifier => {
            this.unloadExtensionAsset(identifier);
        });

        logger.log('All module assets unloaded');
    }

    /**
     * 특정 모듈의 에셋이 로드되었는지 확인합니다.
     *
     * @param identifier 모듈 식별자
     * @returns 로드 여부
     */
    isLoaded(identifier: string): boolean {
        return this.loadedAssets.has(identifier);
    }

    /**
     * 로드된 모듈 목록을 반환합니다.
     *
     * @returns 로드된 모듈 식별자 배열
     */
    getLoadedModules(): string[] {
        return Array.from(this.loadedAssets.keys());
    }
}

// 싱글톤 인스턴스
let moduleAssetLoaderInstance: ModuleAssetLoader | null = null;

/**
 * ModuleAssetLoader 싱글톤 인스턴스를 반환합니다.
 */
export function getModuleAssetLoader(): ModuleAssetLoader {
    if (!moduleAssetLoaderInstance) {
        moduleAssetLoaderInstance = new ModuleAssetLoader();
    }
    return moduleAssetLoaderInstance;
}

/**
 * window.G7Config.moduleAssets에서 ModuleAsset 배열을 생성합니다.
 *
 * @returns ModuleAsset 배열
 */
export function parseModuleAssetsFromConfig(): ModuleAsset[] {
    if (typeof window === 'undefined') {
        return [];
    }

    const g7Config = (window as any).G7Config;
    if (!g7Config?.moduleAssets) {
        return [];
    }

    const moduleAssets: ModuleAsset[] = [];

    for (const [identifier, asset] of Object.entries(g7Config.moduleAssets)) {
        const typedAsset = asset as {
            js?: string;
            css?: string;
            priority: number;
            external?: ExternalScript[];
        };

        moduleAssets.push({
            identifier,
            js: typedAsset.js,
            css: typedAsset.css,
            priority: typedAsset.priority,
            external: typedAsset.external,
        });
    }

    return moduleAssets;
}

/**
 * 확장 병합 번들 URL 정보 인터페이스
 */
export interface ExtensionBundleUrls {
    /** 모듈 병합 JS 번들 URL */
    moduleJs?: string | null;
    /** 모듈 병합 CSS 번들 URL */
    moduleCss?: string | null;
    /** 플러그인 병합 JS 번들 URL */
    pluginJs?: string | null;
    /** 플러그인 병합 CSS 번들 URL */
    pluginCss?: string | null;
}

/**
 * window.G7Config.bundleUrls 에서 병합 번들 URL 을 파싱합니다.
 *
 * 활성 global 에셋이 없는 타입은 서버가 null 을 내려주므로, 프론트는 해당
 * 번들 로드를 스킵한다. bundleUrls 자체가 없으면(구버전 blade 등) null 반환.
 *
 * @returns 번들 URL 객체 또는 null
 * @since engine-v1.52.0
 */
export function parseBundleUrlsFromConfig(): ExtensionBundleUrls | null {
    if (typeof window === 'undefined') {
        return null;
    }

    const g7Config = (window as any).G7Config;
    if (!g7Config?.bundleUrls) {
        return null;
    }

    return g7Config.bundleUrls as ExtensionBundleUrls;
}

/**
 * 플러그인 에셋을 파싱합니다.
 *
 * @returns ModuleAsset 배열 (플러그인용)
 */
export function parsePluginAssetsFromConfig(): ModuleAsset[] {
    if (typeof window === 'undefined') {
        return [];
    }

    const g7Config = (window as any).G7Config;
    if (!g7Config?.pluginAssets) {
        return [];
    }

    const pluginAssets: ModuleAsset[] = [];

    for (const [identifier, asset] of Object.entries(g7Config.pluginAssets)) {
        const typedAsset = asset as {
            js?: string;
            css?: string;
            priority: number;
            external?: ExternalScript[];
        };

        pluginAssets.push({
            identifier,
            js: typedAsset.js,
            css: typedAsset.css,
            priority: typedAsset.priority,
            external: typedAsset.external,
        });
    }

    return pluginAssets;
}
