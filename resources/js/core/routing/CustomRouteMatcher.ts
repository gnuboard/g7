/**
 * 개발자 화면(custom 예약 자리) 주소 판정 (#792 W6)
 *
 * 서버의 `routes/custom.php` 로 등록된 GET 주소는 G7 엔진이 그리지 않고 서버가 그린다. 엔진 화면 안에서 그 주소로
 * 이동하면 엔진이 `pushState` 로 직접 그려 버려 개발자 화면이 보이지 않으므로, 이동 직전에 이 판정으로 걸러
 * 전체 페이지 이동(`location.assign`)·뒤로가기 재적재(`location.reload`)로 넘긴다.
 *
 * 패턴은 서버가 셸에 싣는 `window.G7Config.customRoutes = { user: [...], admin: [...] }` — 라라벨 라우트 URI 의
 * `{param}` 구간을 `*`(제약 없음) 또는 `{정규식}`(라우트 where 제약)으로 바꾼 것이다. `*` 는 한 구간(슬래시 없는
 * 1글자 이상)과 맞고, `{정규식}` 은 그 구간 전체가 정규식과 맞아야 한다(engine-v1.81.0 실측 보강 — 제약을 잃으면
 * `/board/{slug}/{id}`(숫자)가 `/board/free/write` 이동까지 가로챘다). `/admin` 으로 시작하는 주소는
 * 관리자 목록, 나머지는 사용자 목록과 비교한다. 패턴이 없으면 언제나 false — 종전 동작과 완전히 같다.
 *
 * @since engine-v1.81.0
 */

/** 범위별 custom 주소 패턴 */
export interface CustomRoutePatterns {
  user?: string[];
  admin?: string[];
}

/** 컴파일한 정규식 메모 (패턴 문자열 → 정규식) */
const compiled = new Map<string, RegExp[]>();

/**
 * 셸이 내려 준 패턴을 읽습니다.
 *
 * @returns 패턴 (없으면 null)
 */
function readPatterns(): CustomRoutePatterns | null {
  if (typeof window === 'undefined') {
    return null;
  }
  const patterns = (window as any).G7Config?.customRoutes;

  return patterns && typeof patterns === 'object' ? (patterns as CustomRoutePatterns) : null;
}

/**
 * 퍼센트 인코딩을 풉니다 (깨진 인코딩이면 원문).
 *
 * @param value 원문
 * @returns 디코드 결과
 */
function safeDecode(value: string): string {
  try {
    return decodeURIComponent(value);
  } catch {
    return value;
  }
}

/**
 * 비교용 경로 — 같은 출처 절대 URL 은 경로만, 쿼리·해시 제거, 디코드, 끝 슬래시 제거.
 *
 * @param path 이동 대상 (경로 또는 절대 URL)
 * @returns 정규화 경로, 다른 출처면 null
 */
export function normalizeCustomRoutePath(path: string): string | null {
  if (typeof path !== 'string' || path === '') {
    return null;
  }

  let pathname = path;
  if (/^[a-z][a-z0-9+.-]*:\/\//i.test(path) || path.startsWith('//')) {
    try {
      const url = new URL(path, window.location.origin);
      if (url.origin !== window.location.origin) {
        return null;
      }
      pathname = url.pathname;
    } catch {
      return null;
    }
  }

  pathname = pathname.split('#')[0].split('?')[0];
  if (!pathname.startsWith('/')) {
    return null;
  }
  pathname = safeDecode(pathname);
  if (pathname.length > 1) {
    pathname = pathname.replace(/\/+$/, '') || '/';
  }

  return pathname;
}

/** 정규식 특수문자를 문자 그대로로 */
function escapeLiteral(text: string): string {
  return text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/**
 * 패턴 구간 하나를 정규식 조각으로 만듭니다.
 *
 * `*` 는 슬래시 없는 1글자 이상, `{정규식}` 은 서버 라우트의 경로 제약(where)이다(중괄호 짝을 세어 안쪽 `{4}` 같은
 * 수량자도 그대로 둔다). 그 밖은 문자 그대로다.
 *
 * @param segment 패턴 구간
 * @returns 정규식 조각
 */
function segmentSource(segment: string): string {
  let out = '';
  let i = 0;
  while (i < segment.length) {
    const ch = segment[i];
    if (ch === '*') {
      out += '[^/]+';
      i++;
      continue;
    }
    if (ch === '{') {
      let depth = 0;
      let j = i;
      for (; j < segment.length; j++) {
        if (segment[j] === '{') depth++;
        else if (segment[j] === '}') {
          depth--;
          if (depth === 0) break;
        }
      }
      if (j < segment.length) {
        out += `(?:${segment.slice(i + 1, j)})`;
        i = j + 1;
        continue;
      }
    }
    out += escapeLiteral(ch);
    i++;
  }

  return out;
}

/**
 * 패턴 하나를 구간별 정규식 목록으로 만듭니다.
 *
 * 구간마다 따로 비교하므로 제약 정규식이 슬래시를 넘어 다음 구간까지 맞지 못한다.
 *
 * @param pattern `/board/*`, `/board/*\/{[0-9]+}` 형태
 * @returns 구간별 전체 일치 정규식 (만들 수 없는 제약이면 그 구간은 `*` 로 본다)
 */
function compileSegments(pattern: string): RegExp[] {
  const cached = compiled.get(pattern);
  if (cached) {
    return cached;
  }
  const segments = pattern.split('/').map((segment) => {
    try {
      return new RegExp(`^${segmentSource(segment)}$`);
    } catch {
      return /^[^/]+$/;
    }
  });
  compiled.set(pattern, segments);

  return segments;
}

/**
 * 정규화한 경로가 패턴과 맞는지 봅니다.
 *
 * @param pathname 정규화 경로
 * @param pattern 패턴
 * @returns 맞으면 true
 */
function matchesPattern(pathname: string, pattern: string): boolean {
  const segments = compileSegments(pattern);
  const parts = pathname.split('/');

  return parts.length === segments.length && segments.every((regex, index) => regex.test(parts[index]));
}

/**
 * 주소가 서버의 개발자 화면(custom 라우트)인지 판정합니다.
 *
 * @param path 이동 대상 (경로 또는 같은 출처 절대 URL)
 * @param patterns 패턴 (생략 시 `window.G7Config.customRoutes`)
 * @returns 개발자 화면 주소면 true
 */
export function isCustomRoutePath(path: string, patterns: CustomRoutePatterns | null = readPatterns()): boolean {
  if (!patterns) {
    return false;
  }
  const pathname = normalizeCustomRoutePath(path);
  if (pathname === null) {
    return false;
  }

  const isAdmin = pathname === '/admin' || pathname.startsWith('/admin/');
  const list = (isAdmin ? patterns.admin : patterns.user) ?? [];
  if (!Array.isArray(list) || list.length === 0) {
    return false;
  }

  return list.some((pattern) => typeof pattern === 'string' && matchesPattern(pathname, pattern));
}
