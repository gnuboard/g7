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
 * 템플릿 자산 **디렉토리** URL 을 생성합니다.
 *
 * AMD 로더처럼 "디렉토리 접두에 `/파일명` 을 이어 붙이는" 소비자를 위한 것이다
 * (예: Monaco 의 `paths.vs`). 그런 소비자에게는 `templateAsset()` 의 인코딩된 쿼리 형태
 * (`?file=vendor%2F…`)를 줄 수 없다 — 뒤에 `/editor/editor.main.js` 를 이어 붙이면 인코딩된
 * 값 뒤에 날것의 경로가 붙은 엉뚱한 file 값이 된다.
 *
 * 2번(`?file=` 디렉토리)은 이어 붙이기만 성립한다. 상대 경로 해석(CSS 의 `url(../x)`, 워커 안
 * `importScripts('./x')`, `new URL(rel, dir + '/')`)은 쿼리 기준으로 풀려 깨지고, 이어 붙인 뒤에
 * `?v=1` 같은 쿼리를 덧붙여도 깨진다. 그런 소비자는 정적 게시본(1번)이 있을 때만 쓴다.
 *
 * 그래서 우선순위가 다르다:
 *  1. 정적 게시본이 있으면 그 경로 — 웹서버가 직접 서빙하는 **실제 디렉토리**라
 *     자산 URL 모드와 무관하게 하위 파일이 그대로 해석된다.
 *  2. 확장자 없는 모드면 `?file=` 디렉토리 — 경로를 인코딩하지 않아 뒤에 이어 붙인
 *     `/loader.js` 가 그대로 한 파일의 file 값이 되고, 경로부에는 확장자가 남지 않는다.
 *     AMD 로더는 쿼리가 있는 URL 에 `.js` 를 붙이지 않으므로(`…/editor/editor.main`)
 *     서버가 같은 이름의 `.js` 를 찾아 준다.
 *  3. 그 밖에는 확장자 형태의 API 경로.
 *
 * @param identifier 템플릿 식별자
 * @param path `dist/` 이하 디렉토리 경로 (예: `vendor/monaco-editor/0.54.0/vs`)
 * @returns 디렉토리 URL (뒤에 `/` 는 붙이지 않는다)
 * @since engine-v1.62.0
 */
export function templateAssetDir(identifier: string, path: string): string {
    const normalizedPath = path.replace(/^\/+/, '').replace(/\/+$/, '');
    const id = encodeURIComponent(identifier);

    const staticUrl = extStaticUrl(`templates/${identifier}/assets/${normalizedPath}`);

    if (staticUrl !== null) {
        return staticUrl;
    }

    // @since engine-v1.75.1 확장자 없는 모드의 file 쿼리 디렉토리
    // 형제 생성기(`extensionAsset()`)와 같이 마운트 접두사를 붙인다 — 하위 폴더 배치에서
    // 이 주소만 사이트 밖(`/api/…`)을 가리켜 편집기 로더가 404 를 받았다.
    if (isExtensionless()) {
        return withBasePrefix(`/api/templates/assets/${id}?${FILE_QUERY_PARAM}=${normalizedPath}`);
    }

    return withBasePrefix(`/api/templates/assets/${id}/${normalizedPath}`);
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
    // 식별자도 서버(`AssetUrl::asset()` 의 `rawurlencode`)와 같은 인코더를 지난다.
    // 종전에는 `encodeURIComponent` 였다 — 경로(`?file=`)는 아래에서 `rawEncode` 로
    // 맞춰 두고 식별자만 옛 인코더가 남아, 한 함수 안에서 두 규칙이 공존했다. 식별자는
    // 슬러그라 발현은 없지만 사본이 갈리면 「어느 쪽이 맞는가」가 다음 회차로 되돌아온다.
    const id = rawEncode(identifier);

    if (!isExtensionless()) {
        return withBasePrefix(`/api/${type}/assets/${id}/${normalizedPath}${versionQuery(version)}`);
    }

    // 서버(`AssetUrl::asset()`)는 `rawurlencode()` 로 값을 만든다. `encodeURIComponent` 는
    // `! ' ( ) *` 를 인코딩하지 않아, 같은 파일의 URL 문자열이 서버와 클라이언트에서 갈렸다 —
    // 디코딩하면 같은 경로라 404 도 오류도 없고, HTTP 캐시 키가 둘로 나뉘어 같은 자산을 두 번
    // 받는 것이 유일한 증상이다. 형제 경로(`convertToCurrentMode`)는 이미 이 교정을 갖고 있어
    // 한 파일 안에서 두 규칙이 공존했다.
    let query = `${FILE_QUERY_PARAM}=${rawEncode(normalizedPath)}`;

    if (version !== undefined && version !== null && version !== '') {
        query += `&v=${version}`;
    }

    return withBasePrefix(`/api/${type}/assets/${id}?${query}`);
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

    return withBasePrefix(`${base}${versionQuery(version)}`);
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

    return withBasePrefix(parts.length > 0 ? `${url}?${parts.join('&')}` : url);
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
 * 정적 게시(bake) 베이스 경로를 반환합니다 (#122).
 *
 * blade 가 게이트(프로덕션 + kill-switch + 게시 완료) 통과 시에만
 * `window.G7Config.staticBase` (`/build/ext/{v}`) 를 주입한다. 부재 시 null —
 * 소비자는 종전 API URL 로 직행한다.
 *
 * @returns 정적 베이스 경로 또는 null
 * @since engine-v1.61.0
 */
export function extStaticBase(): string | null {
    const base = (globalThis as any)?.G7Config?.staticBase;

    return typeof base === 'string' && base !== '' ? base.replace(/\/+$/, '') : null;
}

/**
 * 정적 게시 베이스에 담긴 캐시 버전을 반환합니다.
 *
 * @returns 버전 숫자 또는 null (베이스 부재/형식 불일치)
 * @since engine-v1.61.0
 */
export function extStaticVersion(): number | null {
    const base = extStaticBase();
    if (!base) return null;

    const match = base.match(/\/(\d+)$/);

    return match ? Number(match[1]) : null;
}

/**
 * 정적 게시본 내 파일의 URL 을 생성합니다 (#122).
 *
 * `forVersion` 을 주면 그 버전 디렉토리를 조합한다 — 핸드셰이크 재로드처럼
 * 페이지 렌더 시점과 다른 버전을 요구하는 경우다. 그 버전이 아직 미게시면
 * 404 가 나고, 호출부의 `fetchStaticFirst` 가 legacy API 로 폴백한다.
 *
 * 서버측 `App\Support\AssetUrl`(정적 게시 트리 규약)과 경로 규칙 1:1 —
 * 실파일 확장자를 그대로 쓰므로 dualSuffix 접미사 규칙은 적용하지 않는다.
 *
 * 결과에는 사이트 마운트 접두사를 붙인다. `staticBase` 는 서버 게이트 값(`/build/ext/{v}`)을
 * 그대로 실은 것이라 접두사가 없고, 게시 트리는 사이트의 `public/` 아래에 있다 — 붙이지
 * 않으면 하위 폴더 배치에서 정적 조회가 전부 문서 루트(사이트 밖)로 나간다. 이미 접두사를
 * 가진 값에는 다시 붙이지 않는다(`withBasePrefix()`).
 *
 * @param path 게시 트리 상대 경로 (예: `templates/{id}/routes.json`)
 * @param forVersion 명시 버전 (생략 시 페이지 렌더 버전)
 * @returns 정적 URL 또는 null (staticBase 미주입)
 * @since engine-v1.61.0
 */
export function extStaticUrl(path: string, forVersion?: number): string | null {
    const base = extStaticBase();
    if (!base) return null;

    const normalizedPath = path.replace(/^\/+/, '');

    if (forVersion !== undefined && forVersion > 0 && forVersion !== extStaticVersion()) {
        return withBasePrefix(`${base.replace(/\/\d+$/, '')}/${forVersion}/${normalizedPath}`);
    }

    return withBasePrefix(`${base}/${normalizedPath}`);
}

/**
 * 실패한 정적 게시 URL 을 종전 API URL 로 역변환합니다 (#122 F15).
 *
 * 셸 head 복구기(`public/assets/core/js/g7-shell-head.js` 의 `staticToLegacy`)와
 * **동일 규칙**이어야 한다 — 드리프트는 `assetUrlRecovery.test.ts` 의 대조 케이스가 잡는다.
 * 변환 대상이 아니면(정적 게시 경로가 아니면) null. `/build/core/**` 등 다른 정적
 * 파일은 대상이 아니다.
 *
 * 하위 폴더 배치에서 서버는 `/g7/build/ext/{v}/…` 를 렌더한다. 접두사를 벗겨 판정하고
 * 결과에는 다시 붙인다(`convertToCurrentMode()` 와 같은 규칙). 벗기지 않으면 그 실패를
 * 몰라보고, 붙이지 않으면 복구 주소가 사이트 밖을 가리켜 또 404 가 된다.
 *
 * @param url 실패한 URL
 * @returns 종전 API URL 또는 null
 * @since engine-v1.61.0
 */
export function staticToLegacy(url: string): string | null {
    if (!url) return null;

    const origin = (globalThis as any)?.location?.origin;
    let relative = origin && url.startsWith(origin) ? url.slice(origin.length) : url;

    const basePath = basePrefix();

    if (basePath && (relative === basePath || relative.startsWith(basePath + '/'))) {
        relative = relative.slice(basePath.length);
    }

    const match = relative.match(/^\/build\/ext\/(\d+)\/(.+)$/);
    if (!match) return null;

    const version = match[1];
    const rest = match[2].split('?')[0];

    // 세 확장 타입이 같은 형태로 게시된다 — 템플릿은 `dist/**`, 모듈·플러그인은
    // 운영자의 `custom/**` 이 각각 `{type}/{id}/assets/**` 로 실린다. 한 타입만 역변환하면
    // 나머지 타입의 게시본이 GC 로 사라졌을 때 복구 경로가 없어, 화면은 정상인데 그 확장의
    // 자산만 조용히 빠진다.
    const extensionAssetMatch = rest.match(/^(templates|modules|plugins)\/([^/]+)\/assets\/(.+)$/);
    if (extensionAssetMatch) {
        const [, type, identifier, assetPath] = extensionAssetMatch;

        return `${basePath}/api/${type}/assets/${identifier}/${assetPath}?v=${version}`;
    }

    const bundleMatch = rest.match(/^bundles\/(modules|plugins)\.(js|css)$/);
    if (bundleMatch) {
        return `${basePath}/api/${bundleMatch[1]}/bundle.${bundleMatch[2]}?v=${version}`;
    }

    const templateFetchMatch = rest.match(/^templates\/([^/]+)\/(routes\.json|components\.json|lang\/([A-Za-z0-9-]+)\.json)$/);
    if (templateFetchMatch) {
        const [, identifier, kind] = templateFetchMatch;
        const suffixless = kind.replace(/\.json$/, '');

        return `${basePath}/api/templates/${identifier}/${suffixless}.json?v=${version}`;
    }

    return null;
}

/**
 * 경로 조각을 `?file=` 값으로 다시 인코딩합니다.
 *
 * 서버의 확장자 모드는 경로를 **인코딩하지 않으므로**(형제 `AssetUrl::asset()` 과 동형이고
 * 정적 `/storage/…` 도 그렇다), 여기 오는 값은 인코딩된 것일 수도 원문일 수도 있다.
 * `decodeURIComponent` 를 무조건 부르면 리터럴 `%` 가 든 파일명(`50%off.png`)에서
 * `URIError` 를 던지고, 그 지점은 모듈 자산 로딩 전체와 CSS 자가 복구 핸들러다 —
 * 그 페이지의 확장 자산이 통째로 멈추거나 스타일이 조용히 사라진다.
 *
 * 그래서 디코딩을 **시도**하고, 실패하면 원문 그대로 인코딩한다. 정상 입력의 결과는
 * 종전과 같다(이중 인코딩 방지 성질 유지).
 *
 * `resources/views/partials/asset-url-recovery.blade.php` 의 인라인 판본과 같은 규칙이어야
 * 한다 — 드리프트는 `assetUrlRecovery.test.ts` 가 잡는다.
 *
 * @param filePath 경로 조각
 * @returns `?file=` 에 실을 인코딩된 값
 */
function encodeFileQueryValue(filePath: string): string {
    let decoded = filePath;

    try {
        decoded = decodeURIComponent(filePath);
    } catch {
        decoded = filePath;
    }

    return rawEncode(decoded);
}

/**
 * 서버의 `rawurlencode()` 와 **같은 결과**를 내는 인코더 (RFC 3986).
 *
 * `encodeURIComponent` 는 `! * ' ( )` 를 인코딩하지 않는다. 서버는 인코딩하므로, 같은 파일에
 * 대해 서버가 만든 URL 과 클라이언트가 만든 URL 의 **문자열이 갈린다** — 디코딩하면 같은
 * 파일이라 404 가 되지는 않지만 HTTP 캐시 키가 둘로 나뉘어 같은 자산을 두 번 받는다.
 *
 * @param value 인코딩할 값
 * @returns RFC 3986 기준 인코딩 결과
 */
function rawEncode(value: string): string {
    return encodeURIComponent(value).replace(
        /[!'()*]/g,
        (c) => '%' + c.charCodeAt(0).toString(16).toUpperCase(),
    );
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

/**
 * `G7Config.basePath` 를 비교 가능한 형태로 다듬는다.
 *
 * 루트 설치는 빈 문자열이고, 하위 폴더는 `/` 로 시작하고 `/` 로 끝나지 않는다.
 */
export function basePrefix(): string {
    return normalizeBasePath((globalThis as any)?.G7Config?.basePath);
}

/**
 * 루트 상대 경로에 사이트 마운트 접두사를 붙인다.
 *
 * 이 파일의 생성기들은 `/api/…` 로 시작하는 **루트 상대** 경로를 만든다. 사이트가
 * 문서 루트 하위 폴더(`https://host/g7/`)에 설치된 배치에서 그 주소는 사이트 밖을
 * 가리키고, 문서 루트에 다른 사이트가 있으면 그쪽 응답을 받는다. 서버가 렌더한
 * 자산 URL 은 `AssetUrl::basePrefix()` 로 이미 접두사를 갖고 있어, 접두사를 붙이지
 * 않으면 **같은 화면 안에서 서버가 만든 주소와 브라우저가 만든 주소가 서로 다른
 * 규칙**을 따르게 된다.
 *
 * 이미 접두사를 갖고 있으면 다시 붙이지 않는다 — 호출부가 서버에서 받은 경로를
 * 그대로 넘기는 경우가 있고, 이중 접두(`/g7/g7/api/…`)는 조용한 404 다.
 *
 * @param path `/` 로 시작하는 경로
 * @returns 접두사가 붙은 경로
 */
export function withBasePrefix(path: string): string {
    const prefix = basePrefix();

    // 앞뒤 공백은 판정 전에 걷어낸다 (서버 `AssetUrl::sitePath()` 와 동형) —
    // `' /images/a.png'` 는 `/` 로 시작하지 않아 사이트 경로가 아닌 값으로 읽히고,
    // 접두사 없이 그대로 나갔다.
    path = typeof path === 'string' ? path.trim() : path;

    // `//host/…` 는 프로토콜 상대 **절대** URL 이다 — `/` 로 시작하지만 사이트 경로가
    // 아니므로 접두사를 붙이면 외부 주소가 `/g7//host/…` 라는 깨진 경로가 된다.
    if (!prefix || !path.startsWith('/') || path.startsWith('//')) {
        return path;
    }

    if (hasBasePrefix(path, prefix)) {
        return path;
    }

    return prefix + path;
}

/**
 * 루트 상대 경로가 마운트 접두사를 **이미** 갖고 있는지 판정한다.
 *
 * 접두사 뒤가 끝·`/`·`?`·`#` 이면 접두사 보유다. 종전 판정은 끝과 `/` 두 형태만
 * 알아서, 하위 폴더 홈에 쿼리가 붙은 복귀 주소(`/g7?ref=x`)가 로그인 직후
 * `/g7/g7?ref=x` 로 이중 접두가 됐다 — 사이트 밖으로 나가는 조용한 404 다.
 * 반대로 `/g7x/foo` 는 접두사가 경계 없이 이어진 **다른 경로**라 보유가 아니다.
 * 붙이는 쪽(`withBasePrefix`)과 벗기는 쪽(`stripBasePrefix`)이 이 한 판정을 공유한다 —
 * 서버 `AssetUrl::hasBasePrefix()` 와 같은 규칙이다.
 *
 * @param path `/` 로 시작하는 경로
 * @param prefix `/` 로 시작하고 `/` 로 끝나지 않는 접두사
 * @returns 접두사를 이미 갖고 있으면 true
 */
function hasBasePrefix(path: string, prefix: string): boolean {
    if (!path.startsWith(prefix)) {
        return false;
    }

    const next = path.charAt(prefix.length);

    return next === '' || next === '/' || next === '?' || next === '#';
}

/**
 * origin 판정 전에 주소를 브라우저 URL 파서와 동일하게 정규화한다.
 *
 * 문자열 접두 검사만으로는 authority 우회를 막지 못한다. 브라우저(WHATWG URL)는
 * 파싱 전에 ASCII tab·개행을 제거하고, special scheme(http/https)에서 백슬래시를
 * 슬래시와 등가로 처리하기 때문이다. 그래서 `/\evil.com/x` · `/{tab}/evil.com/x` 는
 * `//` 로 시작하지 않고 scheme 도 없는데 실제로는 `https://evil.com/x` 로 해석된다.
 *
 * 정규화 후 판정하면 경로 **중간**의 백슬래시·연속 슬래시(`/js/a\b.js` · `/f/a//b.png`)는
 * authority 를 만들지 않으므로 그대로 same-origin 으로 통과한다(과차단 없음).
 *
 * 저장측 `SafeLayoutExpressions::normalizeForOriginCheck` · 정적 검사
 * `layout-scripts-src-same-origin` 과 **3층 동형**이어야 한다. 런타임 소비처가
 * 둘(스크립트 src 판정 · 브라우저 이동 판정)이므로 이 파일이 그 SSoT 다 — 사본을
 * 만들면 한쪽만 강화됐을 때 다른 쪽이 조용한 우회로가 된다.
 *
 * @since engine-v1.93.0
 * @param value 원본 주소 문자열
 * @returns 정규화된 주소
 */
export function normalizeForOriginCheck(value: string): string {
    // ASCII tab / LF / CR 제거 (브라우저 파서가 파싱 전에 제거하는 문자)
    // → 백슬래시를 슬래시로 (special scheme 에서 등가)
    const slashed = value.replace(/[\t\n\r]/g, '').replace(/\\/g, '/');

    // 선행 슬래시가 3개 이상이어도 브라우저는 authority 시작으로 접는다
    // (`///host/x` ≡ `//host/x`, `https:///host/x` ≡ `https://host/x`).
    // 경로 중간의 연속 슬래시(`/js//a.js`)는 브라우저도 경로로 두므로 건드리지 않는다.
    return slashed.replace(/^([a-z][a-z0-9+.\-]*:)?\/{2,}/i, '$1//');
}

/**
 * 사이트 마운트 접두사를 벗겨 **라우트 기준 경로**로 되돌린다.
 *
 * `withBasePrefix()` 의 역함수다. 브라우저가 알려 주는 주소(`location.pathname`)는
 * 하위 폴더 배치에서 `/g7/board/free` 인데, 라우트 패턴은 언제나 접두사가 없는
 * `/board/:slug` 로 등록된다. 벗기지 않고 그대로 대조하면 **어떤 패턴에도 맞지 않아**
 * 모든 화면이 「없는 페이지」가 된다 — 오류도 콘솔 메시지도 없이 라우팅만 통째로
 * 죽는 형태라, 데이터 축(`withBasePrefix`)만 갖춘 상태에서는 드러나지 않았다.
 *
 * 접두사와 무관한 경로(이미 벗겨졌거나 다른 사이트를 가리키는 값)는 그대로 돌려준다.
 *
 * @param path `/` 로 시작하는 경로
 * @returns 접두사를 벗긴 경로 (접두사만 남으면 `/`)
 */
export function stripBasePrefix(path: string): string {
    const prefix = basePrefix();

    // 앞뒤 공백은 판정 전에 걷어낸다 — 붙이는 쪽(`withBasePrefix`)과 확장 사본
    // (`routePath`)이 같은 전처리를 갖지 않으면 같은 입력이 한쪽에서만 벗겨진다.
    path = typeof path === 'string' ? path.trim() : path;

    // `/g7abc` 처럼 접두사가 **경계 없이** 이어지는 값은 다른 경로다. 벗기면
    // `abc` 라는 루트 상대가 아닌 문자열이 되어 이후 판정이 전부 어긋난다.
    if (!prefix || typeof path !== 'string' || !hasBasePrefix(path, prefix)) {
        return path;
    }

    const rest = path.slice(prefix.length);

    // 접두사만 남거나 바로 뒤가 `?`·`#` 이면 루트 경로다 — `/g7?tab=1` 은 `/?tab=1`.
    // `/` 로 이어지는 값은 그대로 벗긴다.
    return rest.startsWith('/') ? rest : '/' + rest;
}

function normalizeBasePath(value: unknown): string {
    if (typeof value !== 'string' || value === '' || value === '/') {
        return '';
    }

    const withLeading = value.startsWith('/') ? value : '/' + value;

    return withLeading.replace(/\/+$/, '');
}

export function convertToCurrentMode(url: string): string {
    if (!url || !isExtensionless()) {
        return url;
    }

    // DOM 프로퍼티(`script.src` / `link.href`)는 절대 URL 을 돌려주므로
    // same-origin 접두사를 먼저 벗긴다. 외부 origin 은 대상이 아니다.
    const origin = (globalThis as any)?.location?.origin;
    let relative = origin && url.startsWith(origin) ? url.slice(origin.length) : url;

    // 사이트가 하위 폴더에 마운트된 배치에서는 서버가 만든 URL 이 `/g7/api/…` 이다.
    // 접두사를 벗기지 않고 `/api/` 로 시작하는지만 보면 그 배치에서는 어느 규칙도
    // 매치되지 않아 자가 복구가 통째로 무력해진다. 값은 서버가 준다(SSoT 동일).
    const basePath = normalizeBasePath((globalThis as any)?.G7Config?.basePath);

    if (basePath && (relative === basePath || relative.startsWith(basePath + '/'))) {
        relative = relative.slice(basePath.length);
    }

    if (!relative.startsWith('/api/')) {
        return url;
    }

    const [rawPath, query] = splitQuery(relative);
    const path = rawPath;

    // 변환 결과에는 벗겨 둔 접두사를 다시 붙인다.
    const withBase = (converted: string): string => (basePath ? basePath + converted : converted);

    // 1) 병합 번들: /api/{type}/bundle.{js|css} → /api/{type}/bundle/{js|css}
    const bundleMatch = path.match(/^\/api\/(modules|plugins)\/bundle\.(js|css)$/);
    if (bundleMatch) {
        return withBase(joinQuery(`/api/${bundleMatch[1]}/bundle/${bundleMatch[2]}`, query));
    }

    // 2) 자산: /api/{type}/assets/{id}/{path} → /api/{type}/assets/{id}?file={path}
    const assetMatch = path.match(/^\/api\/(templates|modules|plugins)\/assets\/([^/]+)\/(.+)$/);
    if (assetMatch) {
        const [, type, identifier, filePath] = assetMatch;
        const fileQuery = `${FILE_QUERY_PARAM}=${encodeFileQueryValue(filePath)}`;

        return withBase(`/api/${type}/assets/${identifier}?${fileQuery}${query ? `&${query}` : ''}`);
    }

    // 3) 공개 스토리지: /api/system/public-storage/{path} → /api/system/public-storage?file={path}
    //
    // 규칙 4(접미사 제거)보다 **먼저** 와야 한다. 이 라우트의 무확장자 형태는 접미사를 떼는
    // 형태가 아니라 `?file=` 이므로, 순서가 뒤바뀌면 `/api/system/public-storage/theme.css` 가
    // `/api/system/public-storage/theme` 으로 변환되어 그 파일을 찾다 404 가 된다.
    const publicStorageMatch = path.match(/^\/api\/system\/public-storage\/(.+)$/);
    if (publicStorageMatch) {
        const fileQuery = `${FILE_QUERY_PARAM}=${encodeFileQueryValue(publicStorageMatch[1])}`;

        return withBase(`/api/system/public-storage?${fileQuery}${query ? `&${query}` : ''}`);
    }

    // 4) 고정 접미사: /api/.../x.json → /api/.../x
    const suffixMatch = path.match(/^(\/api\/.+)\.(json|js|css)$/);
    if (suffixMatch) {
        return withBase(joinQuery(suffixMatch[1], query));
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
