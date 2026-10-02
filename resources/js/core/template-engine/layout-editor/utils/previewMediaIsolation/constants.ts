/**
 * 미리보기 매체 격리 상수 (이슈 #141 D7)
 *
 * 레이아웃 편집기 미리보기는 iframe 이 아니라 관리자 문서 안의 div 라서, CSS 의 폭 `@media` 와
 * `vw` 는 언제나 브라우저 창 폭으로 판정된다. 편집기가 떠 있는 동안 문서의 스타일시트를 훑어
 * 폭 조건을 미리보기 프레임 컨테이너 기준으로 나눠 둔다.
 *
 * @since engine-v1.75.2
 */

/** 미리보기 프레임 클래스 — PreviewCanvas 의 기기 폭 div */
export const PREVIEW_FRAME_CLASS = 'g7le-preview-frame';

/** 미리보기 프레임의 컨테이너 이름 — 이름이 붙은 컨테이너 쿼리는 그 컨테이너의 자손만 판정한다 */
export const PREVIEW_CONTAINER_NAME = 'g7le-preview';

/** 미리보기 프레임 폭/100 을 담는 CSS 변수 — `vw` 치환에 쓴다 */
export const PREVIEW_VW_VAR = '--g7le-vw';

/** 시트 소유 노드(`<style>`·`<link>`)에 이 속성이 있으면 격리 대상에서 뺀다 */
export const MEDIA_ISOLATION_OPT_OUT_ATTR = 'data-g7le-no-media-isolation';

/** 컨테이너 지정 `<style>` 표식 속성 */
export const MEDIA_ISOLATION_STYLE_ATTR = 'data-g7le-media-isolation';

/**
 * 원본 폭 `@media` 규칙 가드 — 미리보기 프레임 밖 요소에서만 참.
 * `:where()` 라 우선순위가 0 이다(기존 캐스케이드 불변).
 */
export const OUTSIDE_GUARD = `:where(:not(.${PREVIEW_FRAME_CLASS} *))`;

/** 프레임 안 전용 복제본 가드(`vw` 규칙처럼 컨테이너 쿼리가 아닌 복제본에 쓴다) */
export const INSIDE_GUARD = `:where(.${PREVIEW_FRAME_CLASS} *)`;
