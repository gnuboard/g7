/**
 * 링크 클릭 판정과 링크 주소 쿼리 병합 — 화면 이동을 `<a href>` 로 그릴 때의 규칙 SSoT.
 *
 * 화면 이동이 링크(`<a href>`)이면 가운데 클릭·Ctrl/Cmd/Shift+클릭·우클릭 「새 탭에서 열기」를 브라우저가
 * 처리한다. 엔진(`ActionDispatcher.createHandler`)과 합성 컴포넌트(사이드바·DataGrid 행 메뉴·페이지 번호 …)는
 * **보통 클릭일 때만** 기본 동작을 막고 SPA 로 옮기며, 그 판정을 이 모듈 하나로 한다
 * (`G7Core.navigation.isPlainLeftClick`). 목록 문맥 유지(`mergeQuery`)도 렌더 때 href 에 미리 합쳐
 * 보통 클릭과 새 탭이 같은 주소를 받게 한다(`G7Core.navigation.mergeQueryIntoHref`).
 *
 * @since engine-v1.80.1
 */

import { useSyncExternalStore } from 'react';
import { isDangerousScheme, toSameOriginNavPath } from '../support/navPolicy';

/** 클릭 판정에 쓰는 이벤트 모양 — DOM MouseEvent·React 합성 이벤트·대체 이벤트 모두 받는다 */
export interface LinkClickLike {
  button?: number;
  ctrlKey?: boolean;
  metaKey?: boolean;
  shiftKey?: boolean;
  altKey?: boolean;
  currentTarget?: EventTarget | null;
}

/**
 * href 가 실제 이동 주소인지 판정합니다.
 *
 * 빈 값·`#`(로 시작하는 문서 안 위치)·위험 스킴(`javascript:`·`vbscript:`·`data:` — 판정은 navPolicy SSoT)은
 * 주소가 아니다.
 *
 * @param href href 속성 값
 * @returns 실제 주소이면 true
 */
export function isRealHref(href: unknown): href is string {
  if (typeof href !== 'string') return false;
  const trimmed = href.trim();
  if (trimmed === '' || trimmed.startsWith('#')) return false;
  return !isDangerousScheme(trimmed);
}

/**
 * 이벤트의 currentTarget 이 `<a href>` 이면 그 요소를 돌려줍니다.
 *
 * currentTarget 은 디스패치 중에만 채워진다 — `await` 뒤에는 null 이므로 동기 구간에서 부른다.
 *
 * @param event 클릭 이벤트
 * @returns 링크 요소, 아니면 null
 */
export function getEventAnchor(event: LinkClickLike | null | undefined): HTMLAnchorElement | null {
  const current = event?.currentTarget as Element | null | undefined;
  if (!current || typeof (current as Element).matches !== 'function') return null;
  return (current as Element).matches('a[href]') ? (current as HTMLAnchorElement) : null;
}

/**
 * 「보통 클릭」인지 판정합니다 — 브라우저가 같은 탭에서 이동할 클릭이라 SPA 가 가로채도 되는 경우.
 *
 * 왼쪽 버튼(0) · 수정키(Ctrl·Cmd·Shift·Alt) 없음 · 링크라면 `target` 이 없거나 `_self` · `download` 없음.
 * 이 밖(새 탭·새 창·내려받기)은 브라우저 기본 동작에 맡긴다. 가운데 버튼은 `click` 이 아니라 `auxclick`
 * 으로 와서 보통 클릭 처리기에 닿지 않지만, 같은 규칙으로 false 다.
 *
 * @param event 클릭 이벤트
 * @returns 보통 클릭이면 true
 */
export function isPlainLeftClick(event: LinkClickLike | null | undefined): boolean {
  if (!event) return true;
  if ((event.button ?? 0) !== 0) return false;
  if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return false;

  const anchor = getEventAnchor(event);
  if (anchor) {
    const target = anchor.getAttribute('target');
    if (target && target.trim() !== '' && target.trim().toLowerCase() !== '_self') return false;
    if (anchor.hasAttribute('download')) return false;
  }

  return true;
}

/**
 * 클릭이 시작된 실제 주소 링크를 브라우저에 맡겨야 하는지 판정합니다(새 탭·새 창·내려받기).
 *
 * 링크는 `event.target` 에서 가장 가까운 `a[href]` 이고, 그 링크가 처리기가 붙은 요소(`currentTarget`)
 * 자신이거나 그 안쪽일 때만 본다. 그래서 링크에 붙은 처리기뿐 아니라 **링크를 감싼 클릭 가능한 부모**
 * (카드 아코디언 등)도 같은 판정을 받아, 버블링된 부모 처리기가 기본 동작을 막아 새 탭을 취소하거나
 * 부모 액션을 실행하지 않는다. 처리기 요소 안쪽에 있는 단추 같은 다른 요소에서 시작된 클릭은 해당 없음.
 *
 * @param event 클릭 이벤트
 * @returns 브라우저에 맡길 링크 클릭이면 true
 */
export function isLinkClickForBrowser(event: (LinkClickLike & { target?: EventTarget | null }) | null | undefined): boolean {
  const current = event?.currentTarget as Element | null | undefined;
  if (!current || typeof (current as Element).contains !== 'function') return false;
  const origin = event?.target as Element | null | undefined;
  const start = origin && typeof (origin as Element).closest === 'function' ? origin : current;
  const link = (start as Element).closest('a[href]') as HTMLAnchorElement | null;
  if (!link || !(link === current || current.contains(link))) return false;
  if (!isRealHref(link.getAttribute('href'))) return false;
  return !isPlainLeftClick({
    button: event?.button,
    ctrlKey: event?.ctrlKey,
    metaKey: event?.metaKey,
    shiftKey: event?.shiftKey,
    altKey: event?.altKey,
    currentTarget: link,
  });
}

/**
 * 같은 출처의 실제 주소 href 를 「경로?쿼리#해시」로 돌려줍니다.
 *
 * 다른 출처(외부 사이트)·실제 주소가 아닌 값은 null — 링크의 href 로 SPA 이동할 수 있는 것은 같은 출처뿐이다
 * (저장 검사는 `<a href>` 에 외부 http(s) 주소를 허용하므로 이동 대상으로 그대로 쓰면 안 된다).
 *
 * @param href href 속성 값
 * @returns 경로 문자열, 같은 출처 주소가 아니면 null
 */
export function toSameOriginPath(href: unknown): string | null {
  if (!isRealHref(href) || typeof window === 'undefined') return null;
  // 같은 출처 판정 SSoT 는 navPolicy 하나 — 링크 href 는 상대 주소도 오므로 현재 주소를 기준으로 넘긴다
  return toSameOriginNavPath(href, window.location.href);
}

/**
 * 링크 href 가 이 사이트 안 주소(SPA 로 옮길 수 있는 내부 링크)인지 판정해 「경로?쿼리#해시」로 돌려줍니다.
 *
 * 링크 컴포넌트(A·페이지 번호·탭·메뉴)의 「보통 클릭을 SPA 로 옮겨도 되는가」 판정 하나다 — 템플릿은 이 함수를 부르고
 * 판정을 다시 만들지 않는다. 종전에는 템플릿마다 사본이 있어 갈라졌다(sirsoft-admin_basic 은 `https://이 사이트//다른호스트` 를
 * 내부 링크로 봐 href·새 탭은 바깥으로 가는데 보통 클릭만 거부됐다). 판정 규칙은 엔진 navigate 와 같다({@link toSameOriginPath}):
 * 빈 값·`#…`·실행 스킴·다른 출처·`//host`·`/\host`·경로가 `//` 로 시작하는 같은 출처 주소는 null.
 *
 * @param href 링크 주소
 * @returns 내부 링크면 경로(+쿼리·해시), 아니면 null
 * @since engine-v1.83.0
 */
export function resolveInternalHref(href: unknown): string | null {
  return toSameOriginPath(href);
}

/**
 * 새 창 링크의 rel 을 정합니다 — `target="_blank"` 면 `noopener`·`noreferrer` 중 빠진 것만 덧붙이고 넘겨받은 토큰은 남깁니다.
 *
 * 새 창이 `window.opener` 로 이 화면을 바꾸거나 주소를 넘겨받지 못하게 하는 보호다(#757). 새 창이 아니면 받은 값을 그대로
 * 돌려준다(없으면 undefined — rel 속성을 달지 않는다). 대소문자는 가리지 않고 판정한다. 템플릿 링크 컴포넌트의 rel 규칙이
 * 세 갈래(빠진 토큰마다 추가·`noopener` 가 있으면 그대로·늘 둘 다 추가)였던 것을 하나로 둔다(sirsoft-basic 규칙이 정본).
 *
 * @param rel 넘겨받은 rel
 * @param target 링크 target
 * @returns 붙일 rel
 * @since engine-v1.83.0
 */
export function mergeRelForTarget(rel: string | undefined | null, target: string | undefined | null): string | undefined {
  const given = typeof rel === 'string' ? rel : undefined;
  if (target !== '_blank') return given;
  const tokens = (given ?? '').split(/\s+/).filter(Boolean);
  for (const required of ['noopener', 'noreferrer']) {
    if (!tokens.some((token) => token.toLowerCase() === required)) tokens.push(required);
  }

  return tokens.join(' ');
}

/**
 * 쿼리 파라미터 묶음에 값 객체를 덮어씁니다 — `navigate` `mergeQuery` 와 같은 규칙.
 *
 * 빈 값(null·undefined·'')은 그 키를 지우고, 배열은 `키[]` 로 펼친다(Laravel 배열 인식).
 *
 * @param params 대상 쿼리(제자리 수정)
 * @param values 덮어쓸 값
 * @returns 같은 params
 */
export function applyQueryValues(params: URLSearchParams, values: Record<string, any>): URLSearchParams {
  for (const [key, value] of Object.entries(values)) {
    if (value === null || value === undefined || value === '') {
      params.delete(key);
    } else if (Array.isArray(value)) {
      const arrayKey = key.endsWith('[]') ? key : `${key}[]`;
      params.delete(key);
      params.delete(arrayKey);
      for (const item of value) {
        if (item !== undefined && item !== null && item !== '') {
          params.append(arrayKey, String(item));
        }
      }
    } else {
      params.set(key, String(value));
    }
  }
  return params;
}

/**
 * 현재 주소의 쿼리를 링크 주소에 합칩니다 — 링크 자신의 쿼리가 이깁니다.
 *
 * `<a href="/admin/users?page=2" :mergeQuery="true">` 를 목록(`?search=kim&page=1`)에서 그리면
 * `/admin/users?search=kim&page=2`. 같은 출처의 실제 주소가 아니면 href 를 그대로 돌려준다.
 * 상대 경로는 경로 형태(`/…`)로 돌려준다.
 *
 * @param href 링크 주소
 * @returns 쿼리를 합친 주소
 */
export function mergeQueryIntoHref(href: string): string {
  const path = toSameOriginPath(href);
  if (path === null) return href;

  const url = new URL(path, window.location.href);
  const linkKeys = new Set(url.searchParams.keys());
  const merged = new URLSearchParams();
  const emitted = new Set<string>();
  // 현재 쿼리의 순서를 지키고, 링크에도 있는 키는 그 자리에 링크 값(여럿이면 전부)으로 바꾼다
  for (const [key, value] of new URLSearchParams(window.location.search)) {
    if (!linkKeys.has(key)) {
      merged.append(key, value);
      continue;
    }
    if (emitted.has(key)) continue;
    emitted.add(key);
    for (const linkValue of url.searchParams.getAll(key)) merged.append(key, linkValue);
  }
  // 링크에만 있는 키는 뒤에
  for (const key of linkKeys) {
    if (emitted.has(key)) continue;
    for (const linkValue of url.searchParams.getAll(key)) merged.append(key, linkValue);
  }
  const qs = merged.toString();
  return `${url.pathname}${qs ? `?${qs}` : ''}${url.hash}`;
}

/**
 * 엔진이 같은 화면에서 주소만 바꿨을 때 창에 쏘는 이벤트 이름.
 *
 * 쿼리만 바꾸는 `navigate replace`(TemplateApp.updateQueryParams)·`replaceUrl` 은 `history.replaceState` 로 주소를 바꾸고
 * 화면을 다시 마운트하지 않는다. 브라우저는 replaceState·pushState 에 이벤트를 쏘지 않으므로 엔진이 대신 알린다.
 *
 * @since engine-v1.82.1
 */
export const LOCATION_REPLACED_EVENT = 'g7:location-replaced';

/**
 * 같은 화면에서 주소(검색부)를 바꿨다고 구독자에게 알립니다 — `history.replaceState`·`pushState` 직후에 부른다.
 *
 * 렌더할 때 현재 쿼리를 href 에 합치는 링크(`mergeQuery`)는 props 가 그대로라 주소만 바뀌면 다시 그려지지 않는다
 * (DynamicRenderer memo·props 참조 안정화). 그 링크가 {@link subscribeLocationSearch} 로 이 알림을 받아 다시 그린다.
 *
 * @since engine-v1.82.1
 */
export function notifyLocationReplaced(): void {
  if (typeof window === 'undefined' || typeof window.dispatchEvent !== 'function') return;
  window.dispatchEvent(new Event(LOCATION_REPLACED_EVENT));
}

/**
 * 현재 주소 검색부(`window.location.search`)가 바뀔 수 있는 순간을 구독합니다 — 뒤로/앞으로(`popstate`)와
 * 엔진의 같은 화면 주소 교체({@link notifyLocationReplaced}).
 *
 * `useSyncExternalStore(subscribeLocationSearch, () => window.location.search)` 의 subscribe 로 쓴다 — 값이 같으면
 * React 가 다시 그리지 않으므로 해시만 바뀐 알림은 비용이 없다. 렌더 때 현재 쿼리를 읽는 링크(`mergeQuery`)만 구독한다.
 *
 * @param callback 바뀔 수 있을 때 부를 함수
 * @returns 구독 해제 함수
 * @since engine-v1.82.1
 */
export function subscribeLocationSearch(callback: () => void): () => void {
  if (typeof window === 'undefined' || typeof callback !== 'function') return () => {};
  const handler = (): void => callback();
  window.addEventListener('popstate', handler);
  window.addEventListener(LOCATION_REPLACED_EVENT, handler);
  return () => {
    window.removeEventListener('popstate', handler);
    window.removeEventListener(LOCATION_REPLACED_EVENT, handler);
  };
}

/** 구독하지 않는 링크의 subscribe·snapshot — 같은 함수여야 React 가 다시 구독하지 않는다 */
const noSubscribe = (): (() => void) => () => {};
const noSnapshot = (): string => '';
const readLocationSearch = (): string => (typeof window === 'undefined' ? '' : window.location.search);

/**
 * 렌더할 때 현재 쿼리를 href 에 합치는 링크(`mergeQuery`·`pageHrefMergeQuery` 등)가 주소 검색부를 구독하는 React 훅.
 *
 * 같은 화면에서 쿼리만 바꾸는 `navigate replace` 는 화면을 다시 마운트하지 않고 링크 props 도 그대로라 엔진이 링크를 다시
 * 그리지 않는다 — 그러면 가운데 클릭·Ctrl+클릭·새 탭·주소 복사가 옛 쿼리로 간다. 검색부가 바뀌면(뒤로/앞으로·
 * {@link notifyLocationReplaced}) 이 훅이 다시 그린다. `enabled` 가 거짓이면 구독하지 않는다 — 같은 함수 참조를 넘겨 React 가
 * 다시 구독하지 않고, 쿼리를 합치지 않는 링크의 렌더 비용은 그대로다. 템플릿은 코어와 같은 React(`window.React`)를 쓰므로
 * 이 훅을 그대로 부른다(종전에는 템플릿마다 사본, 7.1.2 엔진 수정을 템플릿마다 손으로 옮겨야 했다).
 *
 * @param enabled 현재 쿼리를 href 에 합치면 true
 * @returns 현재 주소 검색부(`?a=1`, 구독하지 않으면 '')
 * @since engine-v1.83.0
 */
export function useLocationSearch(enabled: boolean): string {
  return useSyncExternalStore(
    enabled ? subscribeLocationSearch : noSubscribe,
    enabled ? readLocationSearch : noSnapshot,
    noSnapshot,
  );
}
