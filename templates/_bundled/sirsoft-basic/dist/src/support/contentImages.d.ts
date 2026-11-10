/**
 * 본문 HTML 의 이미지 표시 처리 (HtmlContent 전용, 템플릿 공용).
 *
 * 저장된 본문은 바꾸지 않는다. DOMPurify 가 돌려준 DOM 조각(`RETURN_DOM_FRAGMENT`)을 표시 직전에 그 자리에서 고치고,
 * 호출자가 한 번만 직렬화한다. 조각은 DOMPurify 가 만든 비활성 문서(DOMParser — 브라우징 맥락 없음)에 속해 있어
 * 여기서 `src` 를 바꾸는 동안 이미지 요청이 나가지 않는다. 실제 요청은 직렬화한 문자열이 화면 문서에 들어갈 때
 * 고친 주소로 한 번 나간다.
 *
 * 1. **썸네일** — 서빙 경로 이미지(같은 출처 + 등록 패턴)만 `src` 를 본문 이미지 규격(`upload.thumbnail_content_preset`,
 *    기본 lg)으로 바꾼다. `srcset`(가로 기술자)은 img 의 `width` 속성(정수)을 알 때만, 그 가로 이하의 숫자 규격 후보
 *    (코어 `G7Core.thumbnail.contentSrcsetWithin`)로 덮어쓰고 그 srcset 의 `sizes` 를 붙인다. 원본보다 큰 후보(예: 원본
 *    1000px 에 1920w)를 두면 서버는 키우지 않고 원본을 보내는데 브라우저는 1920px 로 알고 그만큼 작게 그린다. width 를 모르면 srcset 을 넣지 않는다(작성자 srcset 은
 *    그대로) — src 의 본문 규격은 원본이 작으면 원본이 와도 밀도 기술자가 없어 제 크기로 그려진다.
 *    외부·정적·`data:` 이미지는 건드리지 않는다. 봇 화면 짝은 `App\Seo\HtmlSanitizer::sanitizeContent` 이고 규칙이 같다:
 *    srcset 은 덮어쓰고, sizes·loading·decoding 은 작성자가 적지 않았을 때만 넣는다.
 * 2. **확대 보기 표식** — 작성자가 본문에 적은 표식 속성은 먼저 지우고(링크 안 이미지·편집 캔버스가 확대 대상이 되지
 *    않게), 링크(`<a>`) 밖 이미지에 `role="button"`·`tabindex="0"`·`aria-label` 과 표식 속성을 붙인다.
 *    클릭·키보드 처리는 컨테이너 한 곳의 위임이 맡는다. 링크 안 이미지는 링크 이동이 우선이라 표식을 붙이지 않는다.
 *
 * 전역 `DOMPurify.addHook` 은 쓰지 않는다 — 같은 화면의 다른 정화 호출(댓글·미리보기)까지 바뀐다.
 */
/** 본문 이미지 sizes 기본값 — 봇 화면 `HtmlSanitizer::DEFAULT_IMAGE_SIZES` 와 같다 */
export declare const DEFAULT_CONTENT_IMAGE_SIZES = "(min-width: 1024px) 768px, 100vw";
/** 확대 보기 대상 표식 속성 */
export declare const ZOOM_ATTR = "data-g7-zoom";
export interface ContentImageOptions {
    /** srcset 의 sizes 값 (비면 기본값) */
    imageSizes?: string;
    /** 확대 보기 표식을 붙일지 (편집 캔버스는 끈다 — 클릭이 선택 동작이다) */
    zoomable: boolean;
    /** 이미지 대체 텍스트로 확대 보기 버튼 이름을 만든다 */
    zoomLabel: (alt: string) => string;
}
/**
 * img 의 `width` 속성을 정수로 읽습니다 (앞뒤 공백 허용, `50%`·`800px`·소수·0 은 모름).
 * 봇 화면 `HtmlSanitizer::intrinsicWidth()` 와 같은 판정이다.
 *
 * @param img 이미지 요소
 * @returns 가로(px) 또는 null
 */
export declare function intrinsicWidth(img: Element): number | null;
/**
 * 정화된 본문 DOM 의 이미지를 표시용으로 그 자리에서 고칩니다.
 *
 * @param root 정화 결과 (DOMPurify `RETURN_DOM_FRAGMENT` 조각 또는 그것을 담은 요소)
 * @param options 처리 옵션
 */
export declare function decorateContentImages(root: DocumentFragment | Element, options: ContentImageOptions): void;
