/**
 * 자산·동적 엔드포인트 URL 생성기 (프론트측 SSoT).
 *
 * 서버측 `App\Support\AssetUrl` 와 **동일한 규칙**을 구현한다. 한쪽만 바뀌면
 * 서버가 만든 URL 과 클라이언트가 만든 URL 이 어긋나 그 자산만 404 가 되므로,
 * 규칙을 바꿀 때는 반드시 양쪽을 함께 수정한다.
 *
 * ## 배경
 *
 * nginx/Apache 의 표준적 정적 최적화 블록은 URL 마지막 확장자로 분기한다.
 *
 * ```nginx
 * location ~* \.(js|css|json)$ { expires max; access_log off; }
 * ```
 *
 * nginx 에서 정규식 location 은 프리픽스 location 보다 먼저 매칭되므로
 * `try_files ... /index.php` 폴백이 실행될 기회가 없다. 그런 환경에서는
 * 확장자 붙은 동적 응답이 PHP 에 도달하지 못하고 404 가 된다.
 *
 * ## 모드
 *
 * | 모드 | 의미 |
 * |---|---|
 * | `extension` (기본) | 확장자 유지 — 정상 환경 |
 * | `extensionless` | 확장자 제거 — 정적 블록이 가로채는 환경 |
 *
 * 초기값은 서버가 `window.G7Config.assetUrlMode` 로 내려준다. 부트스트랩
 * 자가 복구가 확장자 형태 실패를 감지하면 런타임에 `extensionless` 로 전환한다
 * (단방향 1회 — 역방향 전환은 만들지 않는다. 무한 왕복 방지).
 *
 * @since engine-v1.54.0
 */

/** 확장자 유지 모드 식별자. */
export const MODE_EXTENSION = 'extension';

/** 확장자 제거 모드 식별자. */
export const MODE_EXTENSIONLESS = 'extensionless';

/** 자산 URL 모드 타입. */
export type AssetUrlMode = typeof MODE_EXTENSION | typeof MODE_EXTENSIONLESS;

/** 확장자 없는 자산 URL 에서 파일 경로를 담는 쿼리 파라미터명. */
export const FILE_QUERY_PARAM = 'file';

/** localStorage 캐시 키 접두사 (cache_version 을 포함해 서버 정상화 후 고착 방지). */
const STORAGE_KEY_PREFIX = 'g7_asset_url_mode';

/** localStorage 캐시 TTL (24시간). 서버가 정상화되면 자동으로 재판정된다. */
const STORAGE_TTL_MS = 24 * 60 * 60 * 1000;

/**
 * 현재 자산 URL 모드를 반환합니다.
 *
 * 우선순위: 런타임 전환값(`G7Config.assetUrlMode`) → 설정값
 * (`G7Config.settings.general.asset_url_mode`) → 기본값 `extension`.
 *
 * @returns 현재 모드
 */
export function getAssetUrlMode(): AssetUrlMode {
    const config = (globalThis as any)?.G7Config;

    // 부트스트랩 자가 복구가 기록하는 독립 전역이 최우선이다. `<head>` 파샬이 이 값을
    // 확정한 뒤 `<body>` 에서 G7Config 객체가 통째로 재대입되는 순서라, G7Config 만
    // 보면 전환 결과를 놓칠 수 있다.
    const recovered = (globalThis as any)?.__g7AssetUrlMode;
    if (recovered === MODE_EXTENSIONLESS) {
        return MODE_EXTENSIONLESS;
    }

    const runtime = config?.assetUrlMode;
    if (runtime === MODE_EXTENSIONLESS || runtime === MODE_EXTENSION) {
        return runtime;
    }

    const configured = config?.settings?.general?.asset_url_mode;
    if (configured === MODE_EXTENSIONLESS) {
        return MODE_EXTENSIONLESS;
    }

    return MODE_EXTENSION;
}

/**
 * 현재 모드가 확장자 없는 모드인지 여부를 반환합니다.
 *
 * @returns 확장자 없는 모드이면 true
 */
export function isExtensionless(): boolean {
    return getAssetUrlMode() === MODE_EXTENSIONLESS;
}

/**
 * 자산 URL 모드를 런타임에 전환합니다 (단방향).
 *
 * `extension → extensionless` 만 허용한다. 역방향을 허용하면 양쪽 형태가 모두
 * 실패하는 상황(PHP 다운·WAF 차단)에서 `ext → extless → ext → …` 무한 왕복이 된다.
 *
 * 서버 설정은 바꾸지 않는다 — 미인증 클라이언트가 전역 설정을 뒤집을 수 있으면 안 된다.
 *
 * @param mode 전환할 모드
 * @returns 실제로 전환되었으면 true
 */
export function setAssetUrlMode(mode: AssetUrlMode): boolean {
    if (mode !== MODE_EXTENSIONLESS) {
        return false;
    }

    if ((globalThis as any)?.__g7AssetUrlMode === MODE_EXTENSIONLESS) {
        return false;
    }

    const config = (globalThis as any)?.G7Config;
    if (config?.assetUrlMode === MODE_EXTENSIONLESS) {
        return false;
    }

    (globalThis as any).__g7AssetUrlMode = MODE_EXTENSIONLESS;
    if (config) {
        config.assetUrlMode = MODE_EXTENSIONLESS;
    }
    persistMode(MODE_EXTENSIONLESS);

    return true;
}

/**
 * 캐시된 모드를 읽어 적용합니다 (같은 브라우저 재방문 시 첫 요청부터 정타).
 *
 * 캐시 키에 `cache_version` 을 포함하고 TTL 을 두어, 서버가 정상화된 뒤에도
 * 클라이언트가 옛 모드에 영구 고착되지 않도록 한다.
 *
 * @returns 캐시가 적용되었으면 true
 */
export function restoreCachedMode(): boolean {
    try {
        const raw = globalThis.localStorage?.getItem(storageKey());
        if (!raw) {
            return false;
        }

        const parsed = JSON.parse(raw) as { mode?: string; at?: number };

        if (parsed?.mode !== MODE_EXTENSIONLESS) {
            return false;
        }

        if (typeof parsed.at !== 'number' || Date.now() - parsed.at > STORAGE_TTL_MS) {
            globalThis.localStorage?.removeItem(storageKey());
            return false;
        }

        (globalThis as any).__g7AssetUrlMode = MODE_EXTENSIONLESS;

        const config = (globalThis as any)?.G7Config;
        if (config) {
            config.assetUrlMode = MODE_EXTENSIONLESS;
        }

        return true;
    } catch {
        // localStorage 접근 불가(사파리 프라이빗 등)는 치명적이지 않다 — 캐시 없이 진행
        return false;
    }
}

/**
 * 템플릿 자산 URL 을 생성합니다.
 *
 * 템플릿은 서버가 `dist/` 를 자동 부가하므로 `path` 에 `dist/` 를 포함하지 않는다.
 *
 * @param identifier 템플릿 식별자
 * @param path `dist/` 이하 파일 경로 (예: `js/components.iife.js`)
 * @param version 캐시 무효화 버전 (없으면 미부착)
 * @returns 생성된 URL
 */
export function templateAsset(identifier: string, path: string, version?: number | string | null): string {
    return extensionAsset('templates', identifier, path, version);
}

/**
 * 모듈 자산 URL 을 생성합니다.
 *
 * 모듈은 모듈 루트 기준이라 `path` 에 `dist/` 를 직접 포함한다 (템플릿과 비대칭).
 *
 * @param identifier 모듈 식별자
 * @param path 모듈 루트 기준 파일 경로
 * @param version 캐시 무효화 버전
 * @returns 생성된 URL
 */
export function moduleAsset(identifier: string, path: string, version?: number | string | null): string {
    return extensionAsset('modules', identifier, path, version);
}

/**
 * 플러그인 자산 URL 을 생성합니다.
 *
 * @param identifier 플러그인 식별자
 * @param path 플러그인 루트 기준 파일 경로
 * @param version 캐시 무효화 버전
 * @returns 생성된 URL
 */
export function pluginAsset(identifier: string, path: string, version?: number | string | null): string {
    return extensionAsset('plugins', identifier, path, version);
}

/**
 * 확장 타입을 인자로 받는 자산 URL 생성기.
 *
 * 확장자 없는 모드에서는 파일 경로를 `?file=` 쿼리로 옮긴다. 경로가 곧 파일명이라
 * 접미사만 떼어낼 수 없기 때문이며, nginx 의 location 정규식이 쿼리스트링을 제외한
 * 경로에만 매칭되므로 이 형태가 안전하다.
 *
 * @param type `templates` | `modules` | `plugins`
 * @param identifier 확장 식별자
 * @param path 파일 경로
 * @param version 캐시 무효화 버전
 * @returns 생성된 URL
 */
export function extensionAsset(
    type: string,
    identifier: string,
    path: string,
    version?: number | string | null,
): string {
    const normalizedPath = path.replace(/^\/+/, '');
    const id = encodeURIComponent(identifier);

    if (!isExtensionless()) {
        return `/api/${type}/assets/${id}/${normalizedPath}${versionQuery(version)}`;
    }

    let query = `${FILE_QUERY_PARAM}=${encodeURIComponent(normalizedPath)}`;

    if (version !== undefined && version !== null && version !== '') {
        query += `&v=${version}`;
    }

    return `/api/${type}/assets/${id}?${query}`;
}

/**
 * 확장 병합 번들 URL 을 생성합니다.
 *
 * 접미사(js/css)가 번들 종류를 구분하므로 제거할 수 없다.
 * 확장자 없는 모드에서는 경로 세그먼트로 내린다 (`bundle.js` → `bundle/js`).
 *
 * @param type `modules` | `plugins`
 * @param kind `js` | `css`
 * @param version 캐시 무효화 버전
 * @returns 생성된 URL
 */
export function extensionBundle(type: string, kind: string, version?: number | string | null): string {
    const base = isExtensionless() ? `/api/${type}/bundle/${kind}` : `/api/${type}/bundle.${kind}`;

    return `${base}${versionQuery(version)}`;
}

/**
 * 고정 접미사를 갖는 동적 엔드포인트 URL 을 생성합니다.
 *
 * 확장자 없는 모드에서는 접미사를 제거한다 (`routes.json` → `routes`).
 * 추가 쿼리는 `extraQuery` 로 전달한다 (이미 `?` 가 붙은 뒤에 이어붙이지 않도록
 * 결합을 이 함수가 책임진다).
 *
 * @param path 접미사를 제외한 경로 (예: `/api/templates/foo/routes`)
 * @param suffix 접미사 (예: `json`)
 * @param version 캐시 무효화 버전
 * @param extraQuery 추가 쿼리스트링 (`a=1&b=2` 형태, `?` 없이)
 * @returns 생성된 URL
 */
export function suffixed(
    path: string,
    suffix: string,
    version?: number | string | null,
    extraQuery?: string,
): string {
    const base = path.replace(/\/+$/, '');
    const normalized = suffix.replace(/^\.+/, '');
    const url = isExtensionless() ? base : `${base}.${normalized}`;

    // 순서 주의: extraQuery 를 v 보다 **앞**에 둔다. 치환 이전 호출부가
    // `?with_source_meta=1&v=...` 순서로 만들고 있었고, 쿼리 순서가 바뀌면
    // URL 문자열이 달라져 HTTP 캐시 키가 갈린다(의미는 같아도 캐시 미스).
    const parts: string[] = [];
    if (extraQuery) {
        parts.push(extraQuery.replace(/^[?&]+/, ''));
    }
    if (version !== undefined && version !== null && version !== '') {
        parts.push(`v=${version}`);
    }

    return parts.length > 0 ? `${url}?${parts.join('&')}` : url;
}

/**
 * 레이아웃 서빙 URL 을 생성합니다.
 *
 * @param templateId 템플릿 식별자
 * @param layoutPath 레이아웃 경로 (예: `home`, `board/list`)
 * @param version 캐시 무효화 버전
 * @param extraQuery 추가 쿼리스트링
 * @returns 생성된 URL
 */
export function layoutUrl(
    templateId: string,
    layoutPath: string,
    version?: number | string | null,
    extraQuery?: string,
): string {
    return suffixed(`/api/layouts/${encodeURIComponent(templateId)}/${layoutPath}`, 'json', version, extraQuery);
}

/**
 * 레이아웃 미리보기 서빙 URL 을 생성합니다.
 *
 * @param token 미리보기 토큰 (UUID)
 * @returns 생성된 URL
 */
export function layoutPreviewUrl(token: string): string {
    return suffixed(`/api/layouts/preview/${encodeURIComponent(token)}`, 'json');
}

/**
 * 서버가 확장자 형태로 만들어 내려준 URL 을 **현재 모드**에 맞게 변환합니다.
 *
 * `G7Config.bundleUrls` / `moduleAssets` / `pluginAssets` 는 서버 렌더 시점에
 * 문자열로 굳어 내려온다. 부트스트랩 자가 복구가 런타임에 모드를 뒤집으면 이 값들이
 * 옛 형태로 남으므로, 소비 지점에서 이 함수를 거쳐야 한다.
 *
 * 확장자 모드이거나 변환 대상이 아닌 URL(외부 origin, `/build/...` 정적 파일 등)은
 * 원본을 그대로 돌려준다 — 코어 엔진 번들은 `public/` 의 실물 파일이라 변환하면 깨진다.
 *
 * @param url 서버가 생성한 URL
 * @returns 현재 모드에 맞는 URL
 */
export function convertToCurrentMode(url: string): string {
    if (!url || !isExtensionless()) {
        return url;
    }

    // DOM 프로퍼티(`script.src` / `link.href`)는 절대 URL 을 돌려주므로
    // same-origin 접두사를 먼저 벗긴다. 외부 origin 은 대상이 아니다.
    const origin = (globalThis as any)?.location?.origin;
    const relative = origin && url.startsWith(origin) ? url.slice(origin.length) : url;

    if (!relative.startsWith('/api/')) {
        return url;
    }

    const [path, query] = splitQuery(relative);

    // 1) 병합 번들: /api/{type}/bundle.{js|css} → /api/{type}/bundle/{js|css}
    const bundleMatch = path.match(/^\/api\/(modules|plugins)\/bundle\.(js|css)$/);
    if (bundleMatch) {
        return joinQuery(`/api/${bundleMatch[1]}/bundle/${bundleMatch[2]}`, query);
    }

    // 2) 자산: /api/{type}/assets/{id}/{path} → /api/{type}/assets/{id}?file={path}
    const assetMatch = path.match(/^\/api\/(templates|modules|plugins)\/assets\/([^/]+)\/(.+)$/);
    if (assetMatch) {
        const [, type, identifier, filePath] = assetMatch;
        const fileQuery = `${FILE_QUERY_PARAM}=${encodeURIComponent(decodeURIComponent(filePath))}`;

        return `/api/${type}/assets/${identifier}?${fileQuery}${query ? `&${query}` : ''}`;
    }

    // 3) 고정 접미사: /api/.../x.json → /api/.../x
    const suffixMatch = path.match(/^(\/api\/.+)\.(json|js|css)$/);
    if (suffixMatch) {
        return joinQuery(suffixMatch[1], query);
    }

    return url;
}

/**
 * URL 을 경로와 쿼리로 분리합니다.
 *
 * @param url 대상 URL
 * @returns [경로, 쿼리(`?` 제외)]
 */
function splitQuery(url: string): [string, string] {
    const idx = url.indexOf('?');

    return idx === -1 ? [url, ''] : [url.slice(0, idx), url.slice(idx + 1)];
}

/**
 * 경로와 쿼리를 결합합니다.
 *
 * @param path 경로
 * @param query 쿼리 (`?` 제외)
 * @returns 결합된 URL
 */
function joinQuery(path: string, query: string): string {
    return query ? `${path}?${query}` : path;
}

/**
 * 캐시 무효화 쿼리스트링을 생성합니다.
 *
 * @param version 버전 값
 * @returns `?v=...` 또는 빈 문자열
 */
function versionQuery(version?: number | string | null): string {
    if (version === undefined || version === null || version === '') {
        return '';
    }

    return `?v=${version}`;
}

/**
 * localStorage 캐시 키를 생성합니다 (cache_version 포함).
 *
 * @returns 캐시 키
 */
function storageKey(): string {
    const cacheVersion = (globalThis as any)?.G7Config?.cache_version ?? 0;

    return `${STORAGE_KEY_PREFIX}:${cacheVersion}`;
}

/**
 * 판정된 모드를 localStorage 에 저장합니다.
 *
 * @param mode 저장할 모드
 */
function persistMode(mode: AssetUrlMode): void {
    try {
        globalThis.localStorage?.setItem(storageKey(), JSON.stringify({ mode, at: Date.now() }));
    } catch {
        // 저장 실패는 치명적이지 않다 — 다음 방문에 다시 판정하면 된다
    }
}
