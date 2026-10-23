/**
 * 화면 이동 링크 도우미 (sirsoft-admin_basic 공용, #757 링크 단계)
 *
 * 메뉴·목록 행·탭·페이지 번호처럼 화면을 옮기는 자리를 `<a href>` 로 그려, 가운데 클릭·Ctrl+클릭·우클릭
 * 「새 탭에서 열기」가 되게 한다. 보통 클릭(왼쪽 단추·수정키 없음)만 지금처럼 SPA 로 옮기고, 나머지는 브라우저에 맡긴다.
 *
 * 「보통 클릭인가」·「현재 주소의 쿼리를 href 에 합치기」 규칙은 엔진 한 곳에 있다(`G7Core.navigation.isPlainLeftClick`·
 * `mergeQueryIntoHref`, 작성 결정 W9). 이 파일은 그 규칙을 다시 만들지 않고 부르기만 한다. 그 API 를 내지 않는 옛 엔진에서는
 * 종전처럼 늘 SPA 로 옮기고(쿼리 합치기 없음) — 새 탭은 열리지 않지만 화면 이동은 그대로다.
 */
/** 클릭 판정에 넘기는 이벤트 모양 — `currentTarget` 이 링크 요소여야 엔진이 그 `target`·`download` 를 본다 */
export interface LinkClickLike {
    button?: number;
    ctrlKey?: boolean;
    metaKey?: boolean;
    shiftKey?: boolean;
    altKey?: boolean;
    currentTarget?: EventTarget | null;
}
/**
 * 링크 클릭이 SPA 로 옮길 「보통 클릭」인지 엔진에 묻습니다.
 *
 * 이벤트를 그대로 넘긴다 — 엔진은 `currentTarget`(처리기가 붙은 링크 요소)의 `target`·`download` 까지 본다.
 * React 의 `nativeEvent` 를 넘기면 그 `currentTarget` 은 React 루트 컨테이너라 링크 판정이 빠진다.
 * 문서에 붙은 처리기처럼 `currentTarget` 이 링크가 아니면 `currentTarget` 을 링크 요소로 바꾼 사본을 넘긴다.
 *
 * @param event 클릭 이벤트(React 합성 이벤트·DOM 이벤트·사본, 처리기 안에서 동기로 부른다)
 * @return 보통 클릭이면 true (판정 API 가 없는 엔진이면 true — 종전 동작)
 */
export declare function isPlainLeftClick(event: React.MouseEvent | MouseEvent | LinkClickLike): boolean;
/**
 * 현재 주소의 쿼리를 href 에 합칩니다(목록 문맥 유지, 작성 결정 W8).
 *
 * @param href 링크 주소
 * @return 합친 주소 (API 가 없는 엔진이면 그대로)
 */
export declare function mergeQueryIntoHref(href: string): string;
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
export declare function useLocationSearch(enabled: boolean): string;
/**
 * 링크로 그려도 되는 주소인지 판정해 href 값을 돌려줍니다.
 *
 * - 빈 값·`#`·실행 스킴(`javascript:` 등)은 링크가 아니다 → null
 * - 내부 링크 판정은 코어 한 곳({@link isInternalLink} → `G7Core.navigation.resolveInternalHref`) — `/` 경로와 같은 출처
 *   주소가 내부 링크이고, 같은 출처라도 경로가 `//` 로 시작하면(`https://이 사이트//다른호스트`) 내부 링크가 아니다
 *   (종전 이 사본은 그 주소를 내부로 봐 href·새 탭은 바깥으로 가는데 보통 클릭만 엔진에서 거부됐다, 7.1.3)
 * - `http(s)://` 바깥 주소는 바깥 링크(새 탭으로 연다)
 *
 * @param url 메뉴·항목 데이터의 주소
 * @return href 와 바깥 여부, 링크로 그릴 수 없으면 null
 */
export declare function resolveLinkHref(url: unknown): {
    href: string;
    external: boolean;
} | null;
/**
 * 내부 주소로 SPA 이동합니다(엔진 navigate 핸들러). 엔진이 없으면 같은 출처 경로만 전체 다시 부르기.
 *
 * @param path 이동할 내부 주소
 */
export declare function navigateTo(path: string): void;
/**
 * 링크 클릭 공통 처리 — 보통 클릭이면 기본 이동을 막고 `onPlain` 을 부른다. 그 밖(수정키·가운데 단추·`target`·`download`)은
 * 아무것도 하지 않아 브라우저가 새 탭·새 창·내려받기를 처리한다. 판정은 엔진 한 곳(`isPlainLeftClick`)이 한다.
 *
 * @param event 클릭 이벤트
 * @param onPlain 보통 클릭일 때 할 일(기존 콜백·SPA 이동)
 * @return 보통 클릭으로 처리했으면 true
 */
export declare function handleLinkClick(event: React.MouseEvent, onPlain: () => void): boolean;
/**
 * 새 창 링크의 rel — `target="_blank"` 면 `noopener`·`noreferrer` 중 빠진 것만 덧붙이고 넘겨받은 토큰은 남긴다. 새 창이
 * `window.opener` 로 원래 탭을 바꾸거나 주소를 넘겨받지 못하게 하는 보호다(#757). 새 창이 아니면 받은 값을 그대로 돌려준다.
 *
 * 규칙은 코어 한 곳(`G7Core.navigation.mergeRelForTarget`, engine-v1.83.0)에 있다 — 종전 이 템플릿은 `noopener` 가 있으면
 * `noreferrer` 를 붙이지 않아 템플릿마다 결과가 달랐다(7.1.3 통일). 그 API 가 없는 옛 코어에서만 같은 규칙으로 동작한다.
 *
 * @param target 링크의 target
 * @param rel 넘겨받은 rel
 * @return 붙일 rel(없으면 undefined)
 */
export declare function mergeRelForTarget(target: unknown, rel: unknown): string | undefined;
/**
 * 행 표현식(예: `/admin/menus?id={{row.id}}&mode=edit`)을 그 행 기준으로 풉니다.
 *
 * 목록 컴포넌트가 항목마다 주소를 만들 때 쓴다 — 레이아웃은 원문을 넘기고(components.json skipBindingKeys) 엔진의 바인딩
 * 해석기(`G7Core.getDataBindingEngine`)로 `row`·`item`·`$item` 문맥에서 푼다. 식이 없는 고정 문자열은 그대로 쓴다.
 *
 * @param expr 문자열 또는 행 표현식
 * @param row 현재 행 데이터
 * @return 풀린 문자열 (풀 수 없으면 null)
 */
export declare function resolveRowExpression(expr: unknown, row: unknown): string | null;
