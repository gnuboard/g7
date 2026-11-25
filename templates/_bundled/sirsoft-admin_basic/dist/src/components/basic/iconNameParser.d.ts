import { IconStyle } from './IconTypes';
/**
 * Font Awesome 스타일 토큰 → IconStyle.
 *
 * FA5 단축형(`fas`·`far`·`fal`·`fad`·`fab`)과 FA6 장형(`fa-solid`·`fa-regular`·`fa-light`·`fa-duotone`·`fa-brands`)을
 * 모두 읽는다. 종전 Icon 은 장형만 읽어 `fas fa-users` 를 `fas fa-fas fa-users` 로 조합했다(우연히 보임)·
 * `far`·`fab` 는 스타일을 잃었다(#381).
 *
 * 주의: 이 파일은 sirsoft-admin_basic 과 sirsoft-basic 에 **같은 내용**으로 있다 — 한쪽을 고치면 다른 쪽도 고친다.
 */
export declare const STYLE_TOKENS: Readonly<Record<string, IconStyle>>;
/**
 * 아이콘 이름 해석 결과.
 */
export interface ParsedIconName {
    /** 이름 속 스타일 토큰이 가리키는 스타일 (없으면 null — 호출부가 prop 기본값을 쓴다) */
    style: IconStyle | null;
    /** 글리프 이름 (`fa-` 접두 1회 제거) */
    glyph: string;
    /** 나머지 클래스 (`fa-spin`, 목록 밖 `fa-sharp` 등) — 순서 보존 */
    extraClasses: string[];
}
/**
 * 아이콘 이름 문자열을 스타일·글리프·추가 클래스로 나눕니다.
 *
 * 공백으로 나눈 뒤 첫 스타일 토큰을 style 로(이후 스타일 토큰은 버림), 첫 비스타일 토큰을 glyph 로
 * (앞 `fa-` 한 번 제거), 나머지는 extraClasses 로 둔다.
 *
 * @example
 * parseIconName('fas fa-users')            // { style: 'solid', glyph: 'users', extraClasses: [] }
 * parseIconName('far fa-star')             // { style: 'regular', glyph: 'star', extraClasses: [] }
 * parseIconName('fas fa-spinner fa-spin')  // { style: 'solid', glyph: 'spinner', extraClasses: ['fa-spin'] }
 * parseIconName('users')                   // { style: null, glyph: 'users', extraClasses: [] }
 *
 * @param name 아이콘 이름
 * @returns 해석 결과
 */
export declare function parseIconName(name: string | null | undefined): ParsedIconName;
