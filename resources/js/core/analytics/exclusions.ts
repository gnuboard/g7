/**
 * 브라우저 쪽 선제 제외
 *
 * 서버도 같은 판정을 다시 하지만, 보낼 필요가 없는 요청을 먼저 걸러 냅니다.
 * 추적을 거부한 방문자의 요청이 네트워크 탭에 남지 않는 것 자체가 의미가 있고,
 * 미리 그리기(prerender)처럼 사람이 보지도 않은 화면으로 서버를 두드릴 이유도
 * 없습니다.
 *
 * 여기서 걸러도 서버가 다시 판정합니다 — 브라우저 판정만 믿으면 확장 프로그램이나
 * 조작된 클라이언트가 그 판정을 건너뛸 수 있기 때문입니다.
 */

/** 트래커가 읽는 수집 설정 (노출된 키만) */
export interface AnalyticsClientSettings {
  /** 수집 기능 사용 여부 */
  enabled?: boolean;
  /** 개인정보 수집 모드 */
  privacy_mode?: 'full' | 'consent' | 'anonymous';
  /** 추적 거부 신호 존중 여부 */
  respect_dnt?: boolean;
  /** 제외 경로 글로브 목록 */
  excluded_url_patterns?: string[];
  /** 미리 가져오기 요청 제외 여부 */
  exclude_prefetch?: boolean;
  /** 파일 내려받기 수집 여부 */
  track_downloads?: boolean;
  /** 외부 링크 수집 여부 */
  track_outlinks?: boolean;
  /** 내려받기로 볼 확장자 목록 */
  download_extensions?: string[];
}

/**
 * 경로가 제외 글로브 목록에 걸리는지 판정합니다.
 *
 * 서버의 판정식(`VisitExclusionPipeline::pathMatchesAny`)과 같은 규칙입니다 — `*` 로 끝나면
 * 끝의 `*` 를 **모두** 떼고 앞부분 일치, 아니면 정확히 같아야 합니다. 그래서 `/admin/**` 는
 * `/admin/*` 와 같고 `**` 는 `*` 와 같습니다. 가운데의 `*` 는 글자 그대로이며, 끝 슬래시는
 * 정규화하지 않습니다(`/admin/*` 는 `/admin` 에 걸리지 않습니다). 두 판정이 어긋나면
 * 브라우저는 보냈는데 서버가 버리거나 그 반대가 됩니다.
 *
 * @param path 검사할 경로
 * @param patterns 글로브 목록
 * @returns 제외 대상이면 true
 */
export function pathMatchesAny(path: string, patterns: string[] | undefined): boolean {
  if (!patterns || patterns.length === 0) return false;

  return patterns.some((pattern) => {
    if (!pattern) return false;
    // 서버는 `rtrim($pattern, '*')` 로 끝의 `*` 를 전부 뗀다. 하나만 떼면 `/admin/**` 가
    // 글자 그대로의 `/admin/*` 로 남아 브라우저는 보내고 서버는 버린다.
    if (pattern.endsWith('*')) return path.startsWith(pattern.replace(/\*+$/, ''));

    // 정확 일치는 끝 슬래시를 따지지 않는다 — 서버와 같은 규칙(서버 폴백은 요청 주소의 끝 슬래시를
    // 떼고 비콘은 브라우저 주소 그대로라, 따지면 같은 화면이 한 경로에서만 제외된다).
    return withoutTrailingSlash(path) === withoutTrailingSlash(pattern);
  });
}

/**
 * 경로 끝의 슬래시를 뗍니다 (루트는 그대로).
 *
 * @param path 경로
 * @returns 끝 슬래시를 뗀 경로
 */
function withoutTrailingSlash(path: string): string {
  const trimmed = path.replace(/\/+$/, '');

  return trimmed === '' ? '/' : trimmed;
}

/**
 * 방문자가 추적 거부를 켰는지 확인합니다.
 *
 * @returns 추적 거부 여부
 */
export function hasDoNotTrack(): boolean {
  const nav = navigator as Navigator & { doNotTrack?: string; globalPrivacyControl?: boolean };

  return nav.doNotTrack === '1' || nav.globalPrivacyControl === true;
}

/**
 * 방문자가 「내 방문은 세지 않기」를 선택했는지 확인합니다.
 *
 * 이 쿠키는 트래커가 읽어야 하므로 HttpOnly 가 아닙니다.
 *
 * @param cookieName 제외 쿠키 이름
 * @returns 제외 선택 여부
 */
export function hasOptOutCookie(cookieName = 'g7_analytics_optout'): boolean {
  if (typeof document === 'undefined') return false;

  return document.cookie.split(';').some((entry) => entry.trim() === `${cookieName}=1`);
}

/**
 * 브라우저가 미리 그리는 중인지 확인합니다.
 *
 * 사람이 아직 보지 않은 화면이므로 방문으로 세지 않습니다.
 *
 * @returns 미리 그리는 중이면 true
 */
export function isPrerendering(): boolean {
  if (typeof document === 'undefined') return false;

  // `prerender` 는 표준 타입에 없는 구형 표기라 문자열로 비교한다.
  const doc = document as Document & { prerendering?: boolean };

  return doc.prerendering === true || String(document.visibilityState) === 'prerender';
}

/**
 * 자동화 브라우저 표식을 읽습니다.
 *
 * 값을 판정에 쓰지 않고 그대로 서버에 보냅니다 — 차단 여부는 운영자 설정이 정합니다.
 *
 * @returns navigator.webdriver 값
 */
export function isWebDriver(): boolean {
  return typeof navigator !== 'undefined' && navigator.webdriver === true;
}

/**
 * 이 요청을 보내지 않아도 되는지 판정합니다.
 *
 * @param url 방문 URL
 * @param settings 수집 설정
 * @returns 제외 사유 (보내도 되면 null)
 */
export function shouldSkip(url: string, settings: AnalyticsClientSettings): string | null {
  if (settings.enabled === false) return 'disabled';
  if (settings.exclude_prefetch !== false && isPrerendering()) return 'prefetch';
  if (settings.respect_dnt !== false && hasDoNotTrack()) return 'dnt';
  if (hasOptOutCookie()) return 'opt_out';

  let path = url;
  try {
    path = new URL(url, window.location.origin).pathname;
  } catch {
    // URL 로 못 읽으면 받은 문자열을 경로로 본다 — 판정을 건너뛰는 것보다 낫다.
  }

  if (pathMatchesAny(path, settings.excluded_url_patterns)) return 'url_pattern';

  return null;
}
