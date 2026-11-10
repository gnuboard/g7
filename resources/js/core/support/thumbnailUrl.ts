/**
 * 이미지 썸네일 주소 유틸 (프론트측 SSoT).
 *
 * 서버측 `App\Thumbnails\ThumbnailUrl` 과 **같은 판정**을 구현한다. 한쪽만 바뀌면 봇 화면(SEO 렌더)과
 * 사람 화면이 서로 다른 주소를 내고, 같은 이미지의 파생본이 두 벌로 갈린다.
 *
 * ## 대상 주소
 *
 * `size` 를 붙여도 되는 주소는 「같은 출처 + 등록된 서빙 경로」뿐이다. 정적 `/storage/...`·외부·CDN 직접 URL·
 * `data:`·`blob:` 는 그대로 둔다 — 그 주소들은 서버가 썸네일을 만드는 지점을 지나지 않으므로 `size` 를 붙여도
 * 원본이 오고, 캐시 키만 하나 늘어난다. 서빙 경로 목록은 서버가 `upload.thumbnail_url_patterns`(고정 접두 +
 * `*` 한 조각 와일드카드)로 내려 준다. 기능이 꺼져 있으면 서버가 빈 목록을 주므로 어떤 주소도 바뀌지 않는다.
 *
 * ## 쿼리는 문자열 덧붙임만
 *
 * `URLSearchParams` 로 다시 쓰면 인코딩·순서가 바뀌어 서명 URL(비밀글 첨부 등)의 서명이 깨진다. 서버 서명
 * 검증은 `size`·`w` 만 빼고 원문 쿼리로 하므로, 그 둘만 걷어내고 나머지 쌍은 원문 그대로 둔다. 걷어낼 키 판정은
 * 서버 `ThumbnailQuery::ignoreClosure()` 와 같다 — `+` 를 공백으로 읽고 퍼센트 복호한 키가 `size`·`w` 이거나
 * `size[`·`w[` 로 시작하면(`size[]`·`size%5B%5D`) 썸네일 키다. 배열 키를 남기면 서버가 size 를 두 번 센 것으로 보고
 * 원본을 낸다.
 *
 * ## 설정 출처
 *
 * 현재 렌더 환경의 전역 상태(`window.__templateApp.getGlobalState().settings.upload`)를 먼저 보고, 그 키가 없으면
 * `window.G7Config.settings.upload`(선례 `assetUrl.ts::getAssetUrlMode`)를 본다. 사용자 화면에서는 TemplateApp 이
 * 부팅 때 `G7Config.settings` 를 그대로 전역 상태에 싣기 때문에 둘이 같은 값이고, 부팅 전에는 G7Config 만 있다.
 * 레이아웃 편집 캔버스는 `window.__templateApp` 을 격리 façade 로 바꿔 `sampleGlobal` 시드를 전역 상태로 주므로
 * 캔버스 미리보기가 시드를 따른다 — 편집기 「썸네일 규격」 위젯(`ThumbnailPresetControl`)과 같은 순서다.
 *
 * @since engine-v1.89.0
 */

import { normalizeForOriginCheck, stripBasePrefix } from './assetUrl';
import { isSameOriginRequestUrl } from './navPolicy';

/** 서버가 내려 주는 규격 한 줄 (`ThumbnailPresetRegistry::forFrontend()`) */
export interface ThumbnailPresetInfo {
    /** 규격 이름 (`xs`·`sm`·…·운영자 규격·확장 규격) */
    name: string;
    /** 가로(px). 맞춤 `width` 는 가로만 있다 */
    width: number | null;
    /** 세로(px) */
    height: number | null;
    /** 맞춤 방식 */
    fit: 'cover' | 'contain' | 'width' | string;
    /** 숫자 요청(`?w=`)·본문 srcset 대상 */
    numeric: boolean;
    /** 출력 형식 */
    format?: string;
}

/** 본문 이미지 규격 기본값 — 서버 설정 `upload.thumbnail_content_preset` 기본값과 같다 */
const DEFAULT_CONTENT_PRESET = 'lg';

/** 2x 후보로 인정하는 최소 배율 — 1.5배보다 작으면 고해상도 화면에서 체감 차이가 없다 */
const RETINA_MIN_RATIO = 1.5;

/** 썸네일 쿼리 키 — 서버 `ThumbnailQuery::SIGNATURE_IGNORED` 와 같다 */
const THUMBNAIL_QUERY_KEYS = ['size', 'w'];

/** 패턴 목록 → 정규식 캐시 (서버가 내려 준 배열이 바뀌지 않는 한 한 번만 만든다) */
let compiledFor: unknown = null;
let compiledPatterns: RegExp[] = [];

/**
 * 업로드 설정 한 키를 읽습니다 — 렌더 환경의 TemplateApp 전역 상태(편집 캔버스 포함) 우선, 없으면 G7Config.
 *
 * @param key `upload.*` 하위 키
 * @returns 값 (없으면 undefined)
 */
function readUploadSetting(key: string): unknown {
    try {
        const state = (globalThis as any)?.__templateApp?.getGlobalState?.();
        const fromState = state?.settings?.upload?.[key];
        if (fromState !== undefined && fromState !== null) {
            return fromState;
        }
    } catch {
        // 전역 상태를 읽을 수 없는 시점(부팅 전)은 G7Config 로 넘어간다
    }

    const fromConfig = (globalThis as any)?.G7Config?.settings?.upload?.[key];
    if (fromConfig !== undefined && fromConfig !== null) {
        return fromConfig;
    }

    return undefined;
}

/**
 * 서버가 내려 준 공개 규격 목록 (사용 중인 규격만).
 *
 * @returns 규격 목록 (설정이 없으면 빈 배열)
 */
export function getThumbnailPresets(): ThumbnailPresetInfo[] {
    const raw = readUploadSetting('thumbnail_presets_public');
    if (!Array.isArray(raw)) {
        return [];
    }

    return raw.filter(
        (row): row is ThumbnailPresetInfo => !!row && typeof row === 'object' && typeof (row as any).name === 'string' && (row as any).name !== '',
    );
}

/**
 * 본문 이미지 규격 이름 (`upload.thumbnail_content_preset`, 기본 `lg`).
 *
 * @returns 규격 이름
 */
export function getContentPreset(): string {
    const raw = readUploadSetting('thumbnail_content_preset');

    return typeof raw === 'string' && raw.trim() !== '' ? raw.trim() : DEFAULT_CONTENT_PRESET;
}

/**
 * 서버가 내려 준 서빙 경로 패턴을 정규식으로 바꿉니다 (`*` = `/` 없는 한 조각, 끝까지 정확히 일치).
 *
 * @returns 정규식 목록
 */
function getCompiledPatterns(): RegExp[] {
    const raw = readUploadSetting('thumbnail_url_patterns');

    if (raw === compiledFor) {
        return compiledPatterns;
    }

    compiledFor = raw;
    compiledPatterns = Array.isArray(raw)
        ? raw
              // 서버 `ThumbnailUrl::patterns()` 와 같은 거름 — `/api/` 로 시작하는 문자열만
              .filter((pattern): pattern is string => typeof pattern === 'string' && pattern.startsWith('/api/'))
              .map((pattern) => new RegExp('^' + pattern.split('*').map(escapeRegExp).join('[^/]+') + '$'))
        : [];

    return compiledPatterns;
}

/**
 * 정규식 특수문자를 이스케이프합니다.
 *
 * @param value 원문
 * @returns 이스케이프된 문자열
 */
function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * 같은 출처 주소의 사이트 루트 기준 경로 (하위 폴더 접두는 벗긴다). 대상이 아니면 null.
 *
 * 서버 `ThumbnailUrl::sameOriginPath()` 의 짝 — 루트 상대(`/…`)와 같은 출처 `http(s)://` 전체 주소만 받는다.
 * 상대 경로(`a.png`)는 문서 위치에 따라 뜻이 바뀌므로 서버처럼 대상에서 뺀다.
 *
 * @param url 주소
 * @returns 경로 또는 null
 */
function sameOriginPath(url: string): string | null {
    const trimmed = url.trim();

    if (trimmed === '' || /^(data|blob):/i.test(trimmed)) {
        return null;
    }

    // 브라우저 URL 파서와 같은 정규화 뒤 판정 — `/\host`·`/{tab}/host` 처럼 외부로 읽히는 변형을 거른다
    const normalized = normalizeForOriginCheck(trimmed);

    if (normalized.startsWith('//')) {
        return null;
    }

    const absolute = /^https?:/i.test(normalized);

    if (!absolute && !normalized.startsWith('/')) {
        return null;
    }

    if (!isSameOriginRequestUrl(trimmed)) {
        return null;
    }

    let pathname: string;
    try {
        pathname = new URL(trimmed, 'https://g7-thumbnail-probe.invalid/').pathname;
    } catch {
        return null;
    }

    return stripBasePrefix(pathname);
}

/**
 * 썸네일을 붙여도 되는 주소인지 판정합니다.
 *
 * @param url 주소 (루트 상대·같은 출처 전체 주소)
 * @returns 같은 출처 + 등록된 서빙 경로면 true
 */
export function isThumbnailableUrl(url: unknown): boolean {
    if (typeof url !== 'string') {
        return false;
    }

    const patterns = getCompiledPatterns();
    if (patterns.length === 0) {
        return false;
    }

    const path = sameOriginPath(url);
    if (path === null) {
        return false;
    }

    return patterns.some((pattern) => pattern.test(path));
}

/**
 * 주소를 「본문·쿼리 쌍·조각」으로 나눕니다. 쌍은 원문 그대로이고 빈 쌍은 버린다(서버와 같다).
 *
 * @param url 주소
 * @returns 나눈 결과
 */
function splitUrl(url: string): { base: string; pairs: string[]; fragment: string } {
    let rest = url;
    let fragment = '';
    const hashAt = rest.indexOf('#');

    if (hashAt !== -1) {
        fragment = rest.slice(hashAt);
        rest = rest.slice(0, hashAt);
    }

    const questionAt = rest.indexOf('?');
    if (questionAt === -1) {
        return { base: rest, pairs: [], fragment };
    }

    const pairs = rest
        .slice(questionAt + 1)
        .split('&')
        .filter((pair) => pair !== '' && !isThumbnailQueryKey(pair.split('=', 1)[0]));

    return { base: rest.slice(0, questionAt), pairs, fragment };
}

/**
 * 쿼리 키 원문이 썸네일 키인지 — 서버 `ThumbnailQuery::ignoreClosure()` 와 같은 판정.
 *
 * @param rawKey 쿼리 키 원문 (인코딩 그대로)
 * @returns `size`·`w` 이거나 `size[`·`w[` 로 시작하면 true
 */
function isThumbnailQueryKey(rawKey: string): boolean {
    const key = safeDecode(rawKey.replace(/\+/g, ' '));

    return THUMBNAIL_QUERY_KEYS.some((name) => key === name || key.startsWith(name + '['));
}

/**
 * 쿼리 키를 복호합니다. 잘못된 퍼센트 인코딩이 섞이면 올바른 ASCII `%XX` 만 푼다 — 서버 `rawurldecode` 가
 * 깨진 조각만 남기고 나머지를 푸는 것과 같은 결과를 내기 위해서다(`size%5B%ZZ` → `size[%ZZ`).
 *
 * @param value 키 원문
 * @returns 복호 결과
 */
function safeDecode(value: string): string {
    try {
        return decodeURIComponent(value);
    } catch {
        return value.replace(/%([0-7][0-9A-Fa-f])/g, (_match, hex: string) => String.fromCharCode(parseInt(hex, 16)));
    }
}

/**
 * 대상 판정 없이 `size` 를 덧붙입니다 — 기존 썸네일 키(`size`·`w`·`size[]` 등)는 걷어내고 끝에 붙이며 `#` 앞에 넣는다.
 *
 * @param url 주소
 * @param preset 규격 이름
 * @returns 주소
 */
function appendSize(url: string, preset: string): string {
    const { base, pairs, fragment } = splitUrl(url);
    pairs.push('size=' + encodeURIComponent(preset));

    return base + '?' + pairs.join('&') + fragment;
}

/**
 * 주소에 규격을 붙입니다 (대상이 아니거나 규격이 비면 그대로).
 *
 * @param url 원 주소
 * @param preset 규격 이름
 * @returns 규격이 붙은 주소
 */
export function withThumbnailSize<T>(url: T, preset: unknown): T | string {
    if (typeof url !== 'string' || typeof preset !== 'string' || preset.trim() === '') {
        return url;
    }

    if (!isThumbnailableUrl(url)) {
        return url;
    }

    return appendSize(url, preset.trim());
}

/**
 * 주소에서 썸네일 쿼리(`size`·`w`·`size[]` 등)를 걷어냅니다 — 확대 보기처럼 원본이 필요한 자리용.
 *
 * @param url 주소
 * @returns 원본 주소 (쿼리 쌍이 남지 않으면 `?` 도 없앤다)
 */
export function stripThumbnailSize<T>(url: T): T | string {
    if (typeof url !== 'string' || url.indexOf('?') === -1) {
        return url;
    }

    const { base, pairs, fragment } = splitUrl(url);

    return base + (pairs.length > 0 ? '?' + pairs.join('&') : '') + fragment;
}

/**
 * srcset 후보 주소 — 공백은 srcset 구문에서 후보 구분자라 인코딩한다.
 *
 * @param url 주소
 * @returns srcset 에 넣을 수 있는 주소
 */
function srcsetUrl(url: string): string {
    return url.replace(/ /g, '%20');
}

/**
 * 고정 규격의 2배 밀도 srcset 을 만듭니다.
 *
 * 2x 후보 = 같은 맞춤의 규격 중 가로가 1.5배 이상인 가장 작은 것. 자르기·맞추기는 가로세로 비가 같아야
 * 한다(비가 다른 규격을 2x 로 주면 고해상도 화면에서만 다른 모양으로 잘린다 — 예: `sm` 정사각 → `og` 1200×630).
 * 후보가 없으면 1x 만 낸다.
 *
 * @param url 원 주소
 * @param preset 1x 규격 이름
 * @returns srcset 문자열 (대상이 아니면 빈 문자열)
 */
export function thumbnailSrcset(url: unknown, preset: unknown): string {
    if (typeof url !== 'string' || typeof preset !== 'string' || preset.trim() === '' || !isThumbnailableUrl(url)) {
        return '';
    }

    const name = preset.trim();
    const presets = getThumbnailPresets();
    const base = presets.find((row) => row.name === name);
    const oneX = `${srcsetUrl(appendSize(url, name))} 1x`;

    if (!base || !isPositive(base.width)) {
        return oneX;
    }

    const baseRatio = aspectRatio(base);
    const candidate = presets
        .filter((row) => {
            if (row.name === name || row.fit !== base.fit || !isPositive(row.width)) {
                return false;
            }
            if ((row.width as number) < (base.width as number) * RETINA_MIN_RATIO) {
                return false;
            }
            if (base.fit === 'width') {
                return true;
            }
            const ratio = aspectRatio(row);

            return baseRatio !== null && ratio !== null && Math.abs(ratio - baseRatio) < 0.01;
        })
        .sort((a, b) => (a.width as number) - (b.width as number))[0];

    return candidate ? `${oneX}, ${srcsetUrl(appendSize(url, candidate.name))} 2x` : oneX;
}

/** 숫자 요청 대상 규격 한 줄의 srcset 후보 */
interface ContentSrcsetEntry {
    /** 가로 기술자 값(px) */
    width: number;
    /** `주소 Nw` */
    candidate: string;
}

/**
 * 본문 이미지 srcset 후보 — 숫자 요청 대상 규격을 가로 오름차순으로, 같은 가로는 앞의 것 하나만.
 *
 * @param url 원 주소
 * @returns 후보 목록 (대상이 아니거나 규격이 없으면 빈 배열)
 */
function contentSrcsetEntries(url: unknown): ContentSrcsetEntry[] {
    if (typeof url !== 'string' || !isThumbnailableUrl(url)) {
        return [];
    }

    const seen = new Set<number>();

    return getThumbnailPresets()
        .filter((row) => row.numeric === true && isPositive(row.width))
        .sort((a, b) => (a.width as number) - (b.width as number))
        .filter((row) => {
            if (seen.has(row.width as number)) {
                return false;
            }
            seen.add(row.width as number);

            return true;
        })
        .map((row) => ({ width: row.width as number, candidate: `${srcsetUrl(appendSize(url, row.name))} ${row.width}w` }));
}

/**
 * 본문 이미지 srcset — 숫자 요청 대상 규격 전부를 가로(`w`) 기술자로.
 *
 * @param url 원 주소
 * @returns srcset 문자열 (대상이 아니거나 규격이 없으면 빈 문자열)
 */
export function contentSrcset(url: unknown): string {
    return contentSrcsetEntries(url)
        .map((entry) => entry.candidate)
        .join(', ');
}

/** `contentSrcsetWithin` 옵션 */
export interface ContentSrcsetWithinOptions {
    /**
     * 원본 주소를 원본 가로 기술자(`{주소} {maxWidth}w`)로 맨 끝에 덧붙인다(기본 false).
     *
     * 켜면 원본보다 **작은** 후보만 남긴다 — 원본과 같은 가로의 후보는 원본과 기술자가 겹쳐 뺀다. 남는 후보가 없으면
     * 원본 하나뿐이라 빈 문자열이다(`src` 로 충분하다). 끄면 원본 가로 **이하** 후보만 남긴다.
     */
    includeOriginal?: boolean;
}

/**
 * 원본 가로를 넘지 않는 본문 srcset — `contentSrcset` 후보에서 원본보다 큰 것을 뺀다.
 *
 * 서버는 원본보다 큰 규격을 키우지 않고 원본을 보내는데, 후보가 `1920w` 라고 적혀 있으면 브라우저는 그 폭으로 알고
 * 골라 그만큼 작게 그린다. 그래서 원본 가로를 아는 자리는 이 함수로 후보를 원본 가로 안으로 줄인다.
 *
 *  - 본문 HTML(`includeOriginal` 끔): 원본 가로 **이하** 후보만. 봇 화면 `HtmlSanitizer` 의 본문 이미지와 같은 규칙이다.
 *  - 원본을 함께 싣는 자리(`includeOriginal` 켬 — 배너·팝업): 원본보다 **작은** 후보 + 원본. 넓은 고해상도 화면은
 *    지금처럼 원본을 받는다.
 *
 * @param url 원 주소
 * 가로는 내림한 정수로 본다. srcset 의 `w` 기술자는 정수이고, 비교와 원본 기술자가 같은 값을 써야 원본 기술자와
 * 후보 기술자가 겹치지 않으며 원본보다 큰 후보가 들어오지 않는다(1 보다 작으면 가로를 모르는 것과 같다).
 *
 * @param url 원 주소
 * @param maxWidth 원본 가로(px, 양의 유한수)
 * @param options 옵션
 * @returns srcset 문자열 (대상이 아니거나, 가로를 모르거나, 남는 후보가 없으면 빈 문자열)
 * @since engine-v1.89.0
 */
export function contentSrcsetWithin(url: unknown, maxWidth: unknown, options?: ContentSrcsetWithinOptions | null): string {
    if (typeof url !== 'string' || !isPositive(maxWidth)) {
        return '';
    }

    const limit = Math.floor(maxWidth as number);
    if (limit < 1) {
        return '';
    }

    const includeOriginal = options?.includeOriginal === true;
    const kept = contentSrcsetEntries(url)
        .filter((entry) => (includeOriginal ? entry.width < limit : entry.width <= limit))
        .map((entry) => entry.candidate);

    if (kept.length === 0) {
        return '';
    }

    if (includeOriginal) {
        kept.push(`${srcsetUrl(url)} ${limit}w`);
    }

    return kept.join(', ');
}

/**
 * 양수인지.
 *
 * @param value 값
 * @returns 양의 유한수면 true
 */
function isPositive(value: unknown): boolean {
    return typeof value === 'number' && Number.isFinite(value) && value > 0;
}

/**
 * 가로세로 비 (세로가 없으면 null).
 *
 * @param row 규격
 * @returns 세로/가로
 */
function aspectRatio(row: ThumbnailPresetInfo): number | null {
    return isPositive(row.width) && isPositive(row.height) ? (row.height as number) / (row.width as number) : null;
}
