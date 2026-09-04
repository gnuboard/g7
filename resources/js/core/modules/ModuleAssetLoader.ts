/**
 * ModuleAssetLoader
 *
 * 모듈/플러그인의 에셋(JS, CSS)을 동적으로 로드하는 클래스입니다.
 * TemplateApp 초기화 시 window.G7Config.moduleAssets를 기반으로
 * 활성화된 모듈의 에셋을 로드합니다.
 */

import { createLogger } from '../utils/Logger';
import { loadScriptWithRetry, loadStylesheetWithRetry } from '../template-engine/networkResilience';
import { convertToCurrentMode, staticToLegacy } from '../support/assetUrl';
import { notifyAssetFailure } from '../assets/AssetFailureNotice';

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
     * 로드에 최종 실패한 CSS 번들/확장 식별자 집합
     *
     * CSS 실패는 로드 흐름을 멈추지 않지만(스타일이 없어도 화면은 동작한다) 사실은
     * 남긴다 — 아이콘만 있는 버튼이 다수인 화면에서는 스타일 소실이 곧 조작 불능이라,
     * 원인을 특정할 근거가 필요하다.
     *
     * @since engine-v1.62.0
     */
    private failedCssAssets: Set<string> = new Set();

    /**
     * CSS 에셋 로드에 최종 실패한 식별자 목록을 반환합니다.
     *
     * @return string[] 실패한 번들 키/확장 식별자 목록
     * @since engine-v1.62.0
     */
    getFailedCssAssets(): string[] {
        return [...this.failedCssAssets];
    }

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
     * 운영자가 덧붙인 사용자 추가 에셋(`custom/`)을 로드합니다.
     *
     * 목록은 서버가 `window.G7Config.customAssets` 로 내려준다 — 활성 확장의 `custom/`
     * 디렉토리를 서버가 해석한 결과이며, 출처(파일 선언·규약 스캔·향후 설정 입력)에
     * 관계없이 같은 모양의 서술자다.
     *
     * 확장 번들이 모두 끝난 뒤 호출해야 한다. CSS 는 나중에 온 규칙이 이기므로,
     * 운영자 스타일이 확장 스타일보다 뒤에 붙어야 재정의가 성립한다.
     *
     * 실패해도 throw 하지 않는다 — 운영자 추가 자산 하나가 실패했다고 앱 부팅을
     * 중단시킬 이유가 없다. 대신 안내 배너로 표면화한다.
     *
     * @return Promise<void>
     * @since engine-v1.62.0
     */
    async loadCustomAssets(): Promise<void> {
        const assets = parseCustomAssetsFromConfig();

        if (assets.length === 0) {
            return;
        }

        for (const asset of assets) {
            const elementId = `g7-custom-${asset.type}-${cssEscapeId(asset.id)}`;

            if (document.getElementById(elementId)) {
                continue;
            }

            const label = `${asset.type === 'style' ? 'custom CSS' : 'custom JS'}: ${asset.id}`;
            const load = (url: string, retries?: number) =>
                asset.type === 'style'
                    ? loadStylesheetWithRetry(convertToCurrentMode(url), { id: elementId }, { label, retries })
                    : loadScriptWithRetry(convertToCurrentMode(url), { id: elementId }, { label, retries });

            // 정적 게시(bake) URL 이면 1회만 시도하고, 미스 시 종전 API URL 로 전환한다 —
            // 형제 경로(`loadBundleCss`)가 이미 갖춘 계층이다. 게시 디렉토리는 GC(현재+직전
            // 1개 보존) 대상이라 캐시된 HTML 의 구버전 정적 URL 이 404 가 될 수 있고, 같은
            // 정적 URL 재시도는 그 상태를 복구하지 못한다. 이 계층이 없으면 운영자 자산만
            // 조용히 빠진 화면이 남는다.
            const legacyUrl = staticToLegacy(asset.url);

            try {
                if (legacyUrl !== null) {
                    try {
                        await load(asset.url, 0);
                    } catch {
                        logger.warn(`Custom asset static miss, falling back to API: ${asset.id} (${asset.url} -> ${legacyUrl})`);
                        await load(legacyUrl);
                    }
                } else {
                    await load(asset.url);
                }

                logger.log(`Custom asset loaded: ${asset.id}`);
            } catch (error) {
                logger.warn(`Failed to load custom asset: ${asset.id} (${asset.url})`, error);
                notifyAssetFailure({
                    id: `custom-asset:${asset.id}`,
                    label: asset.id.split(':').pop() ?? asset.id,
                    retry: async () => {
                        document.getElementById(elementId)?.remove();
                        // 죽은 정적 URL 을 다시 부르지 않는다 — 복구 가능한 쪽으로 재시도한다
                        await load(legacyUrl ?? asset.url);
                    },
                });
            }
        }
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

        // 정적 게시(bake) URL 이면 1회만 시도하고, 미스 시 종전 API URL 로 전환한다 (#122).
        // 게시 디렉토리는 GC(현재+직전 1개 보존) 대상이라 캐시된 HTML 의 구버전 정적 URL 이
        // 404 가 될 수 있고, 같은 정적 URL 재시도는 그 상태를 복구하지 못한다.
        const legacyUrl = staticToLegacy(url);

        try {
            if (legacyUrl !== null) {
                try {
                    await loadStylesheetWithRetry(
                        url,
                        { id: elementId },
                        { label: `bundle CSS: ${key}`, retries: 0 }
                    );
                } catch {
                    logger.warn(`Bundle CSS static miss, falling back to API: ${key} (${url} -> ${legacyUrl})`);
                    await loadStylesheetWithRetry(
                        convertToCurrentMode(legacyUrl),
                        { id: elementId },
                        { label: `bundle CSS: ${key}` }
                    );
                }
            } else {
                await loadStylesheetWithRetry(url, { id: elementId }, { label: `bundle CSS: ${key}` });
            }

            logger.log(`Bundle CSS loaded: ${key}`);

            const link = document.getElementById(elementId);
            if (link) {
                this.registerLoadedAsset(`bundle-${key}`, { type: 'css', element: link });
            }
        } catch (error) {
            // 배너의 [다시 시도] 에는 **복구 가능한 URL** 을 넘긴다. 정적 미스로 여기 왔다면
            // 원본 정적 URL 은 이미 404 가 확정된 주소라, 그대로 넘기면 재시도가 구조적으로
            // 항상 실패한다 — 버튼은 있는데 아무것도 고치지 못하는 상태가 된다.
            this.surfaceCssFailure(
                `bundle-${key}`,
                key,
                legacyUrl !== null ? convertToCurrentMode(legacyUrl) : url,
                error
            );
        }
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

        // 정적 게시(bake) URL 이면 1회만 시도하고, 미스 시 종전 API URL 로 전환해 기존
        // 재시도 예산을 이어간다 (#122 — fetchStaticFirst 와 동형: 정적 1 + 레거시 재시도).
        // 게시 디렉토리는 GC(현재+직전 1개 보존) 대상이라 캐시된 HTML 의 구버전 정적
        // URL 이 404 가 될 수 있고, 같은 정적 URL 재시도는 그 상태를 복구하지 못한다.
        const legacyUrl = staticToLegacy(url);
        const attempt = legacyUrl !== null
            ? loadScriptWithRetry(url, { id: elementId }, { label: `bundle JS: ${key}`, retries: 0 })
                .catch(() => {
                    logger.warn(`Bundle JS static miss, falling back to API: ${key} (${url} -> ${legacyUrl})`);

                    return loadScriptWithRetry(
                        convertToCurrentMode(legacyUrl),
                        { id: elementId },
                        { label: `bundle JS: ${key}` }
                    );
                })
            : loadScriptWithRetry(url, { id: elementId }, { label: `bundle JS: ${key}` });

        const loadPromise = attempt
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
     * `loadJS`·`loadBundleJs` 와 같이 in-flight Promise 를 공유한다 — CSS 경로만 이
     * 계층이 없어서, 같은 확장의 CSS 를 동시에 요청하면 `<link>` 가 중복 생성되고
     * 재시도 로더가 기존 element 를 제거하면서 서로의 시도를 지웠다.
     *
     * 키는 `module-css-{id}` 로 둔다 — `loadJS` 가 raw identifier 를 키로 쓰므로,
     * 같은 키공간을 쓰면 JS 로드가 CSS 로드로 오인되어 조용히 건너뛰어진다.
     *
     * 실패해도 throw 하지 않는 기존 계약을 유지한다(`surfaceCssFailure`).
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

        // 이미 로딩 중인 경우 대기 (JS 키공간과 겹치지 않는 elementId 를 키로 쓴다)
        const existingPromise = this.loadingPromises.get(elementId);
        if (existingPromise) {
            logger.log(`CSS already loading: ${identifier}`);
            return existingPromise;
        }

        const loadPromise = loadStylesheetWithRetry(url, { id: elementId }, { label: `CSS: ${identifier}` })
            .then(() => {
                logger.log(`CSS loaded: ${identifier}`);

                const link = document.getElementById(elementId);
                if (link) {
                    this.registerLoadedAsset(identifier, { type: 'css', element: link });
                }
                this.loadingPromises.delete(elementId);
            })
            .catch((error) => {
                this.loadingPromises.delete(elementId);
                this.surfaceCssFailure(identifier, identifier, url, error);
            });

        this.loadingPromises.set(elementId, loadPromise);

        return loadPromise;
    }

    /**
     * CSS 로드 최종 실패를 표면화합니다.
     *
     * JS 와 달리 **throw 하지 않는다** — 스타일이 없어도 화면은 동작하므로, 한 확장의
     * CSS 실패로 나머지 확장 로드를 중단시키지 않는다는 기존 계약을 유지한다. 대신
     * 실패 사실을 `failedCssAssets` 와 안내 배너에 남긴다. 종전에는 `console.warn`
     * 한 줄이 전부여서, 아이콘만 있는 버튼이 조작 불능이 되어도 원인을 알 수 없었다.
     *
     * @param assetKey 실패 목록에 기록할 키
     * @param label 사용자에게 보일 항목명
     * @param url 실패한 URL
     * @param error 마지막 시도의 에러
     * @since engine-v1.62.0
     */
    private surfaceCssFailure(assetKey: string, label: string, url: string, error: unknown): void {
        logger.warn(`Failed to load CSS after retries: ${assetKey} (${url})`, error);
        this.failedCssAssets.add(assetKey);

        notifyAssetFailure({
            id: `ext-css:${assetKey}`,
            label,
            retry: async () => {
                document.getElementById(`module-css-${assetKey}`)?.remove();
                document.getElementById(`ext-bundle-css-${assetKey.replace(/^bundle-/, '')}`)?.remove();
                await loadStylesheetWithRetry(url, {}, { label: `CSS retry: ${assetKey}` });
                this.failedCssAssets.delete(assetKey);
            },
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
/**
 * 사용자 추가 에셋 서술자
 *
 * 서버(`App\Support\CustomAssets`)가 만든 것과 같은 모양이다. 소비자는 `source` 를
 * 보지 않는다 — 파일에서 왔든, 선언에서 왔든, 나중에 설정 화면에서 왔든 같은 규칙으로
 * 로드된다.
 *
 * @since engine-v1.62.0
 */
export interface CustomAsset {
    /** 중복 로드 방지 식별자 */
    id: string;
    /** 자산 종류 */
    type: 'style' | 'script';
    /** 로드할 URL */
    url: string;
    /** 캐시 무효화 버전 (파일 수정 시각 등) */
    version?: number | null;
    /** 출처 (진단용 — 로드 규칙에는 영향을 주지 않는다) */
    source?: string;
}

/**
 * `window.G7Config.customAssets` 를 파싱합니다.
 *
 * @return CustomAsset[] 서술자 목록 (없으면 빈 배열)
 * @since engine-v1.62.0
 */
export function parseCustomAssetsFromConfig(): CustomAsset[] {
    const raw = (window as any)?.G7Config?.customAssets;

    if (!Array.isArray(raw)) {
        return [];
    }

    return raw.filter(
        (item: any): item is CustomAsset =>
            item
            && typeof item.id === 'string'
            && typeof item.url === 'string'
            && (item.type === 'style' || item.type === 'script')
    );
}

/**
 * 서술자 id 를 element id 에 쓸 수 있는 형태로 바꿉니다.
 *
 * 서술자 id 는 `custom:templates:sirsoft-basic:custom.css` 형태라 콜론·점을 포함한다.
 * 그대로 두면 `document.getElementById` 는 되지만 CSS 선택자로 못 쓴다.
 *
 * @param id 서술자 id
 * @return string 안전한 id 조각
 * @since engine-v1.62.0
 */
function cssEscapeId(id: string): string {
    return id.replace(/[^A-Za-z0-9_-]/g, '-');
}

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
            js: typedAsset.js ? convertToCurrentMode(typedAsset.js) : typedAsset.js,
            css: typedAsset.css ? convertToCurrentMode(typedAsset.css) : typedAsset.css,
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

    // 서버가 확장자 형태로 굳혀 내려준 URL 을 현재 모드로 변환한다.
    // 부트스트랩 자가 복구가 런타임에 모드를 뒤집은 경우 원본은 이미 옛 형태다.
    const urls = g7Config.bundleUrls as ExtensionBundleUrls;

    return {
        moduleJs: urls.moduleJs ? convertToCurrentMode(urls.moduleJs) : urls.moduleJs,
        moduleCss: urls.moduleCss ? convertToCurrentMode(urls.moduleCss) : urls.moduleCss,
        pluginJs: urls.pluginJs ? convertToCurrentMode(urls.pluginJs) : urls.pluginJs,
        pluginCss: urls.pluginCss ? convertToCurrentMode(urls.pluginCss) : urls.pluginCss,
    } as ExtensionBundleUrls;
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
            js: typedAsset.js ? convertToCurrentMode(typedAsset.js) : typedAsset.js,
            css: typedAsset.css ? convertToCurrentMode(typedAsset.css) : typedAsset.css,
            priority: typedAsset.priority,
            external: typedAsset.external,
        });
    }

    return pluginAssets;
}
