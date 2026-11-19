import { EmotionCache } from '@emotion/cache';
/** 태그 입력(react-select) emotion 캐시 키 — 클래스 이름 앞글자(`tag-css-…`)가 된다. emotion 키는 소문자와 `-` 만 받는다. */
export declare const TAG_INPUT_EMOTION_KEY = "tag-css";
/**
 * emotion `StyleSheet` 과 같은 모양으로, 규칙을 생성형 스타일시트에 넣는 시트.
 *
 * emotion 이 읽는 필드(`key`·`nonce`·`container`·`isSpeedy`·`ctr`·`tags`·`before`·`prepend`·`insertionPoint`)와 메서드
 * (`insert`·`flush`·`hydrate`)를 갖춘다. `tags` 는 늘 빈 배열이다 — 요소를 만들지 않는다.
 */
export declare class AdoptedEmotionSheet {
    key: string;
    nonce: string | undefined;
    container: Node;
    isSpeedy: boolean;
    ctr: number;
    tags: HTMLStyleElement[];
    before: Element | null;
    prepend: boolean | undefined;
    insertionPoint: HTMLElement | undefined;
    private sheet;
    /**
     * @param options emotion `StyleSheet` 생성자와 같은 옵션(쓰는 것은 key·nonce·container) — emotion `Global` 이
     *   `new cache.sheet.constructor(...)` 로 시트를 하나 더 만들 때도 같은 경로를 탄다
     */
    constructor(options: {
        key: string;
        nonce?: string;
        container?: Node;
    });
    /**
     * 생성형 시트를 돌려줍니다. 없으면 만들고, 문서에서 빠져 있으면(다른 코드가 배열을 통째로 덮어씀) 다시 붙입니다.
     * 배열을 덮어쓰지 않고 자기 시트만 더해 남이 넣은 시트를 보존합니다.
     *
     * @returns 생성형 시트
     */
    private ensure;
    /**
     * 규칙 하나를 시트 끝에 넣습니다. 브라우저가 모르는 접두 선택자(`::-moz-…` 등)는 emotion speedy 모드처럼 건너뜁니다.
     *
     * @param rule CSS 규칙 한 개
     * @returns 반환값 없음
     */
    insert(rule: string): void;
    /**
     * 넣은 규칙을 모두 걷고 시트를 문서에서 뗍니다. 남이 넣은 시트는 그대로 둡니다.
     *
     * @returns 반환값 없음
     */
    flush(): void;
    /**
     * 서버가 그린 `<style>` 을 넘겨받는 자리 — 관리자 화면은 클라이언트에서만 그려 넘겨받을 것이 없습니다.
     *
     * @returns 반환값 없음
     */
    hydrate(): void;
}
/**
 * 규칙을 생성형 스타일시트에 싣는 emotion 캐시를 만듭니다.
 *
 * @param key emotion 캐시 키(소문자와 `-`) — 클래스 이름 앞글자가 된다
 * @returns 캐시. 생성형 시트를 지원하지 않는 환경이면 null(호출하는 쪽은 emotion 기본 캐시를 쓴다)
 */
export declare function createAdoptedEmotionCache(key: string): EmotionCache | null;
/**
 * 태그 입력이 함께 쓰는 emotion 캐시(한 생성형 시트) — 처음 부를 때 만들고 이후 같은 것을 돌려줍니다.
 *
 * @returns 캐시. 생성형 시트를 지원하지 않는 환경이면 null
 */
export declare function getTagInputEmotionCache(): EmotionCache | null;
