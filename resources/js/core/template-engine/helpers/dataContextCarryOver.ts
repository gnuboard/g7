/**
 * 화면 전환 때 앞 화면의 데이터소스 값을 새 화면 첫 렌더에 이어 쓸지 가리는 판정
 *
 * 새 화면의 progressive·background 데이터소스는 응답 전까지 `undefined`(받기 전)로 그린다.
 * 다만 받는 동안 깜빡임을 막으려고 앞 화면 값을 이어 쓰는 두 경우만 남긴다.
 *
 * 1. **같은 화면** — 앞 화면과 경로(pathname)·레이아웃 이름이 모두 같다(쿼리만 바뀐 검색·쪽 넘김·
 *    정렬·탭, 언어 전환 재진입). 종전처럼 모든 id 를 이어 쓰고 흐림 표시로 다시 받는다.
 * 2. **같은 요청** — 다른 화면이어도 앞 화면이 같은 id 를 같은 정의로 선언했고, 정의가 읽는
 *    경로 파라미터(`route`)·쿼리(`query`)가 같으며 `_local` 을 읽지 않는다. 베이스 레이아웃이
 *    모든 화면에 주는 메뉴·현재 사용자·알림 수 같은 소스가 여기에 든다.
 *
 * 그 밖(이름만 같은 다른 목록, 같은 레이아웃의 다른 대상 — 상품 1 → 2·플러그인 A → B 설정)은
 * 이어 쓰지 않는다. 종전에는 id 만 같으면 이어 써서 새 화면이 받기 전부터 앞 화면 값을 「받음」으로
 * 보고 흐림·빈 문구 조건이 풀렸다(모양이 다르면 0행·「없음」 문구·계산값 오류).
 *
 * 엔진 모듈을 import 하지 않는 순수 함수라 `TemplateApp` 과 시험이 그대로 쓴다.
 *
 * @since engine-v1.81.2
 * @packageDocumentation
 */

/**
 * 데이터 컨텍스트를 마지막으로 그린 화면 — 다음 화면 전환의 비교 기준.
 *
 * @since engine-v1.81.2
 */
export interface RenderedScreen {
  /** 그린 경로(pathname, 쿼리 제외) */
  path: string;
  /** 레이아웃 이름(`layout_name`, 없으면 라우트의 layout) */
  layoutName: string;
  /** 라우트의 레이아웃 키(확장 접두 포함 — 플러그인 설정은 이름이 같아도 이 키가 다르다) */
  routeLayout?: string;
  /** 경로 파라미터 */
  routeParams: Record<string, unknown>;
  /** 쿼리 파라미터 */
  query: Record<string, unknown>;
  /** `if` 평가를 마친 데이터소스 정의 */
  sources: ReadonlyArray<Record<string, any>>;
}

/**
 * 키 순서와 무관하게 같은 값이면 같은 글자가 되는 비교 키를 만듭니다.
 *
 * @param value 비교할 값
 * @return 비교 키 문자열
 */
function stableKey(value: unknown): string {
  if (Array.isArray(value)) {
    return `[${value.map(stableKey).join(',')}]`;
  }

  if (value && typeof value === 'object') {
    const entries = Object.keys(value as Record<string, unknown>)
      .sort()
      .map((key) => `${JSON.stringify(key)}:${stableKey((value as Record<string, unknown>)[key])}`);

    return `{${entries.join(',')}}`;
  }

  return JSON.stringify(value) ?? 'undefined';
}

/**
 * 앞 화면과 새 화면이 같은 화면(쿼리만 다름)인지 판정합니다.
 *
 * @param previous 앞 화면(없으면 첫 진입)
 * @param path 새 경로(pathname)
 * @param layoutName 새 레이아웃 이름
 * @return 경로와 레이아웃 이름이 모두 같으면 true
 */
export function isSameScreen(previous: RenderedScreen | null, path: string, layoutName: string): boolean {
  return previous !== null && previous.path === path && previous.layoutName === layoutName;
}

/**
 * 다른 화면의 같은 id 소스가 같은 요청인지 판정합니다.
 *
 * 정의 글자에 `route`·`query` 가 보이면 그 값까지 같아야 하고, `_local`(화면마다 다른 상태)이 보이면
 * 이어 쓰지 않는다. 글자 판정이라 넓게 걸리는 쪽(설명문 속 단어 등)은 이어 쓰지 않는 쪽 — 받기 전
 * 표시 — 으로만 기운다.
 *
 * @param previous 앞 화면
 * @param source 새 화면의 데이터소스 정의
 * @param routeParams 새 경로 파라미터
 * @param query 새 쿼리 파라미터
 * @return 같은 요청이면 true
 */
export function isSameDataRequest(
  previous: RenderedScreen | null,
  source: Record<string, any>,
  routeParams: Record<string, unknown>,
  query: Record<string, unknown>,
): boolean {
  if (!previous || !source || typeof source.id !== 'string') {
    return false;
  }

  const before = previous.sources.find((candidate) => candidate && candidate.id === source.id);
  if (!before) {
    return false;
  }

  const definition = stableKey(source);
  if (stableKey(before) !== definition) {
    return false;
  }

  if (/\b_local\b/.test(definition)) {
    return false;
  }

  if (/\broute\b/.test(definition) && stableKey(previous.routeParams) !== stableKey(routeParams)) {
    return false;
  }

  if (/\bquery\b/.test(definition) && stableKey(previous.query) !== stableKey(query)) {
    return false;
  }

  return true;
}

/**
 * 새 화면 첫 렌더에 넣을 progressive·background 데이터소스 초기값을 만듭니다.
 *
 * 모든 id 의 키를 만든다 — 값이 없으면 `undefined` 로 두어야 `blur_until_loaded` 가 그 id 를 본다.
 *
 * @param options.previous 앞 화면(없으면 첫 진입)
 * @param options.previousContext 앞 화면 데이터 컨텍스트
 * @param options.sources 새 화면의 progressive·background 데이터소스 정의
 * @param options.path 새 경로(pathname)
 * @param options.layoutName 새 레이아웃 이름
 * @param options.routeParams 새 경로 파라미터
 * @param options.query 새 쿼리 파라미터
 * @return `init` — id 별 초기값, `sameScreen` — 같은 화면 여부
 */
export function buildProgressiveDataInit(options: {
  previous: RenderedScreen | null;
  previousContext: Record<string, any>;
  sources: ReadonlyArray<Record<string, any>>;
  path: string;
  layoutName: string;
  routeParams: Record<string, unknown>;
  query: Record<string, unknown>;
}): { init: Record<string, any>; sameScreen: boolean } {
  const sameScreen = isSameScreen(options.previous, options.path, options.layoutName);
  const init: Record<string, any> = {};

  for (const source of options.sources) {
    const id = source?.id;
    if (typeof id !== 'string') {
      continue;
    }

    const previousValue = options.previousContext[id];
    const carry = previousValue !== undefined
      && (sameScreen || isSameDataRequest(options.previous, source, options.routeParams, options.query));

    init[id] = carry ? previousValue : undefined;
  }

  return { init, sameScreen };
}
