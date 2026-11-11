/** 코어가 내주는 본문 서식 지연 장치 */
export interface InlineStyleApi {
    /** 지연된 서식이 실리는 속성 이름 */
    ATTR?: string;
    /** 정화하면서 style 속성을 지연 속성으로 옮긴 HTML 을 돌려준다 */
    sanitize: (purifyInstance: any, html: string, config?: Record<string, any>) => string;
    /** root 아래 지연 속성을 `el.style.cssText` 로 입히고 속성을 뗀다 */
    apply: (root: Element | null) => number;
}
/**
 * 코어 장치를 찾는다.
 *
 * @returns 장치. 옛 코어(장치 없음)면 null
 */
export declare function getInlineStyleApi(): InlineStyleApi | null;
/**
 * 지연 정화 전용 DOMPurify 인스턴스를 돌려준다(처음 부를 때 한 번 만든다).
 *
 * @returns 전용 인스턴스
 */
export declare function getDedicatedPurify(): any;
/**
 * HTML 을 정화하면서 style 속성을 지연 속성으로 옮긴다.
 *
 * @param html 원본 HTML
 * @param config DOMPurify 설정(style 을 금지한 설정이면 서식은 버려진다)
 * @returns 정화한 HTML. 장치가 없으면 null(호출자가 종전 경로)
 */
export declare function sanitizeDeferred(html: string, config?: Record<string, any>): string | null;
/**
 * 화면에 붙인 요소 아래의 지연 서식을 입힌다.
 *
 * @param root 지연 정화한 HTML 을 꽂은 요소
 * @returns 입힌 요소 수. 장치가 없거나 root 가 없으면 0
 */
export declare function applyDeferredStyles(root: Element | null): number;
