/**
 * ModuleAssetLoader
 *
 * 모듈/플러그인의 에셋(JS, CSS)을 동적으로 로드하는 클래스입니다.
 * TemplateApp 초기화 시 window.G7Config.moduleAssets를 기반으로
 * 활성화된 모듈의 에셋을 로드합니다.
 */

import { createLogger } from '../utils/Logger';

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
     * 활성화된 모듈들의 에셋을 로드합니다.
     *
     * CSS/JS 모두 병렬 fetch로 로드합니다. JS는 `script.async = false` +
     * 정렬된 DOM append 순서로 **실행 순서는 priority 정렬대로** 보장됩니다.
     * (HTML 사양: async=false 스크립트는 삽입 순서대로 실행)
     *
     * @param extensions 모듈 에셋 배열
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

        await Promise.all([...cssPromises, ...jsPromises]);

        logger.log('All module assets loaded successfully');
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

        const loadPromise = new Promise<void>((resolve, reject) => {
            const script = document.createElement('script');
            script.src = url;
            script.id = elementId;
            script.async = false; // 순차적 로드를 위해 async 비활성화

            script.onload = () => {
                logger.log(`JS loaded: ${identifier}`);
                this.registerLoadedAsset(identifier, { type: 'js', element: script });
                this.loadingPromises.delete(identifier);
                resolve();
            };

            script.onerror = () => {
                logger.warn(`Failed to load JS: ${identifier} (${url})`);
                this.loadingPromises.delete(identifier);
                // JS 로드 실패는 경고만 출력하고 계속 진행
                resolve();
            };

            document.head.appendChild(script);
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
