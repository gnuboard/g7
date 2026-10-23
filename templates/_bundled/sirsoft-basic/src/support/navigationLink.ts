// e2e:allow 이번 변경은 코어 링크 API 가 없는 옛 코어(7.1.1·7.1.2) 전용 대체 판정 — 7.1.3 코어 실브라우저에서는 도달하지 않아 Vitest(NavigationLinks.coreLinkApi)로 잠근다. 7.1.3 코어 경로는 Playwright core-link-api spec 이 잰다
/**
 * 화면 이동 링크 도우미 (sirsoft-basic 공용, #757)
 *
 * 머리글 메뉴·상품 카드·목록 행·탭·페이지 번호처럼 화면을 옮기는 자리를 `<a href>` 로 그려, 가운데 클릭·Ctrl+클릭·
 * 우클릭 「새 탭에서 열기」가 되게 한다. 보통 클릭(왼쪽 단추·수정키 없음)만 지금처럼 SPA 로 옮기고, 나머지는 브라우저에 맡긴다.
 *
 * 「보통 클릭인가」·「현재 주소의 쿼리를 href 에 합치기」 규칙은 엔진 한 곳에 있다(`G7Core.navigation.isPlainLeftClick`·
 * `mergeQueryIntoHref`). 이 파일은 그 규칙을 다시 만들지 않고 부르기만 한다. 그 API 를 내지 않는 옛 엔진에서는
 * 종전처럼 늘 SPA 로 옮기고(쿼리 합치기 없음) — 새 탭은 열리지 않지만 화면 이동은 그대로다.
 */

import { useSyncExternalStore } from 'react';
import { isSameOriginRequest } from './sameOriginRequest';

const core = (): any => (window as any).G7Core;

/**
 * 링크 클릭이 SPA 로 옮길 「보통 클릭」인지 엔진에 묻습니다.
 *
 * 이벤트를 그대로 넘긴다 — 엔진은 `currentTarget`(처리기가 붙은 링크 요소)의 `target`·`download` 까지 본다.
 * React 의 `nativeEvent` 를 넘기면 그 `currentTarget` 은 React 루트 컨테이너라 링크 판정이 빠진다.
 *
 * @param event 클릭 이벤트(React 합성 이벤트 또는 DOM 이벤트, 처리기 안에서 동기로 부른다)
 * @return 보통 클릭이면 true (판정 API 가 없는 엔진이면 true — 종전 동작)
 */
export function isPlainLeftClick(event: React.MouseEvent | MouseEvent): boolean {
  const fn = core()?.navigation?.isPlainLeftClick;
  if (typeof fn !== 'function') return true;

  return fn(event) === true;
}

/**
 * 현재 주소의 쿼리를 href 에 합칩니다(목록 문맥 유지).
 *
 * @param href 링크 주소
 * @return 합친 주소 (API 가 없는 엔진이면 그대로)
 */
export function mergeQueryIntoHref(href: string): string {
  const fn = core()?.navigation?.mergeQueryIntoHref;
  if (typeof fn !== 'function') return href;
  const merged = fn(href);

  return typeof merged === 'string' && merged !== '' ? merged : href;
}

/** 구독하지 않는 링크의 subscribe·snapshot — 같은 함수여야 React 가 다시 구독하지 않는다 */
const noSubscribe = (): (() => void) => () => {};
const noSnapshot = (): string => '';
const readLocationSearch = (): string => (typeof window === 'undefined' ? '' : window.location.search);

/**
 * 옛 코어(engine-v1.83.0 전)용 짧은 대체 구독 — 엔진 주소 교체 알림 API 가 있으면 그것을, 없으면 뒤로/앞으로(`popstate`)만 본다.
 */
const subscribeFallback = (callback: () => void): (() => void) => {
  const fn = core()?.navigation?.subscribeLocationSearch;
  if (typeof fn === 'function') {
    const off = fn(callback);

    return typeof off === 'function' ? off : () => {};
  }
  window.addEventListener('popstate', callback);

  return () => window.removeEventListener('popstate', callback);
};

/** 옛 코어용 대체 훅 — 코어 `useLocationSearch` 와 같은 모양 */
const fallbackLocationSearch = (enabled: boolean): string =>
  useSyncExternalStore(
    enabled ? subscribeFallback : noSubscribe,
    enabled ? readLocationSearch : noSnapshot,
    noSnapshot,
  );

/**
 * 렌더할 때 현재 쿼리를 href 에 합치는 컴포넌트가 주소 검색부를 구독합니다(`mergeQuery`·`pageHrefMergeQuery` 등).
 *
 * 구독 규칙은 코어 한 곳(`G7Core.navigation.useLocationSearch`, engine-v1.83.0)에 있다 — 템플릿은 코어와 같은 React 를
 * 쓰므로 그 훅을 그대로 부른다. 그 훅이 없는 옛 코어에서만 짧은 대체로 동작한다. 코어 유무는 렌더 사이에 바뀌지 않으므로
 * 어느 쪽이든 렌더마다 훅 하나(`useSyncExternalStore`)를 같은 순서로 부른다.
 *
 * @param enabled 현재 쿼리를 href 에 합치면 true
 * @return 현재 주소 검색부(`?a=1`, 구독하지 않으면 '')
 */
export function useLocationSearch(enabled: boolean): string {
  const coreHook = core()?.navigation?.useLocationSearch;
  const impl: (enabled: boolean) => string = typeof coreHook === 'function' ? coreHook : fallbackLocationSearch;

  return impl(enabled);
}

/**
 * 이 사이트 안 주소(SPA 로 옮길 내부 링크)인지 판정합니다 — 판정은 코어 한 곳(`G7Core.navigation.resolveInternalHref`,
 * 엔진 navigate 와 같은 규칙: `//host`·`/\host`·경로가 `//` 로 시작하는 같은 출처 주소·실행 스킴은 내부가 아니다).
 * 그 API 가 없는 옛 코어(7.1.1·7.1.2)에서는 짧은 대체로 판정한다 — `/` 로 시작하고 `//`·`/\` 가 아닌 경로, 그리고 같은 출처
 * `http(s)://` 주소 중 경로가 `//`·`/\` 로 시작하지 않는 것(알림·메뉴의 전체 주소 — 7.1.2 템플릿과 같은 결과, 새 탭으로 열리지 않게).
 *
 * @param url 링크 주소(앞뒤 공백 제거됨)
 * @return 내부 링크면 true
 */
function isInternalLink(url: string): boolean {
  const fn = core()?.navigation?.resolveInternalHref;
  if (typeof fn === 'function') {
    // 경로나 http(s) 주소만 링크 후보 — 상대 경로·스킴 없는 값은 종전처럼 링크로 그리지 않는다
    if (!url.startsWith('/') && !/^https?:\/\//i.test(url)) return false;

    return fn(url) !== null;
  }

  if (/^\/(?![/\\])/.test(url)) return true;

  return /^https?:\/\//i.test(url) && isSameOriginRequest(url) && !hasDoubleSlashPath(url);
}

/**
 * 주소의 경로가 `//`(또는 `/\`, 주소 해석 때 `//` 로 바뀜)로 시작하는지 판정합니다 — 엔진 navigate 가 거부하는 같은 출처 주소.
 *
 * @param url 같은 출처 `http(s)://` 주소
 * @return 경로가 `//` 로 시작하거나 주소를 해석할 수 없으면 true
 */
function hasDoubleSlashPath(url: string): boolean {
  try {
    return new URL(url, window.location.href).pathname.startsWith('//');
  } catch {
    return true;
  }
}

/**
 * 링크로 그려도 되는 주소인지 판정해 href 값을 돌려줍니다.
 *
 * - 빈 값·`#`·실행 스킴(`javascript:` 등)은 링크가 아니다 → null
 * - 내부 링크 판정은 코어 한 곳({@link isInternalLink} → `G7Core.navigation.resolveInternalHref`) — `/` 경로와 같은 출처
 *   주소가 내부 링크이고, 같은 출처라도 경로가 `//` 로 시작하면(엔진 navigate 가 거부하는 주소) 내부 링크가 아니다
 * - `http(s)://` 바깥 주소는 바깥 링크(새 탭으로 연다)
 *
 * @param url 메뉴·항목 데이터의 주소
 * @return href 와 바깥 여부, 링크로 그릴 수 없으면 null
 */
export function resolveLinkHref(url: unknown): { href: string; external: boolean } | null {
  if (typeof url !== 'string') return null;
  const trimmed = url.trim();
  if (trimmed === '' || trimmed === '#') return null;
  if (isInternalLink(trimmed)) return { href: trimmed, external: false };
  if (/^https?:\/\//i.test(trimmed)) return { href: trimmed, external: true };

  return null;
}

/**
 * 내부 주소로 SPA 이동합니다(엔진 navigate 핸들러). 엔진이 없으면 같은 출처 경로만 전체 다시 부르기.
 *
 * @param path 이동할 내부 주소
 */
export function navigateTo(path: string): void {
  const g = core();
  if (typeof g?.dispatch === 'function') {
    g.dispatch({ handler: 'navigate', params: { path } });

    return;
  }
  if (resolveLinkHref(path)?.external === false) {
    window.location.href = path;
  }
}

/**
 * 링크 클릭 공통 처리 — 보통 클릭이면 기본 이동을 막고 `onPlain` 을 부른다. 그 밖(수정키·가운데 단추·`target`·`download`)은
 * 아무것도 하지 않아 브라우저가 새 탭·새 창·내려받기를 처리한다. 판정은 엔진 한 곳(`isPlainLeftClick`)이 한다.
 *
 * @param event 클릭 이벤트
 * @param onPlain 보통 클릭일 때 할 일(기존 콜백·SPA 이동)
 * @return 보통 클릭으로 처리했으면 true
 */
export function handleLinkClick(event: React.MouseEvent, onPlain: () => void): boolean {
  if (event.defaultPrevented) return false;
  if (!isPlainLeftClick(event)) return false;
  event.preventDefault();
  onPlain();

  return true;
}

/**
 * 새 창 링크의 rel — `target="_blank"` 면 `noopener noreferrer` 를 합친다(이미 있는 값은 남긴다). 새 창 쪽이 `window.opener`
 * 로 원래 탭을 바꾸지 못하게 하는 보호다(#757). 새 창이 아니면 받은 값을 그대로 돌려준다.
 *
 * @param target 링크의 target
 * @param rel 넘겨받은 rel
 * @return 붙일 rel(없으면 undefined)
 */
export function mergeRelForTarget(target: string | undefined, rel: string | undefined): string | undefined {
  // 규칙은 코어 한 곳(`G7Core.navigation.mergeRelForTarget`, engine-v1.83.0 — 이 템플릿의 규칙이 정본으로 이관됨).
  // 그 API 가 없는 옛 코어에서만 아래 같은 규칙으로 동작한다.
  const fn = core()?.navigation?.mergeRelForTarget;
  if (typeof fn === 'function') return fn(rel, target);
  if (target !== '_blank') return rel;
  const tokens = (rel ?? '').split(/\s+/).filter(Boolean);
  for (const required of ['noopener', 'noreferrer']) {
    if (!tokens.some((token) => token.toLowerCase() === required)) tokens.push(required);
  }

  return tokens.join(' ');
}
