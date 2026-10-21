/**
 * 운영자 데이터 색(상품 라벨 색 등)에서 대비를 만족하는 화면 색을 파생하는 도우미 (sirsoft-basic 내부 공용 — 관리자 템플릿의 같은 이름 도우미와 같은 규칙)
 *
 * 운영자가 고른 색을 그대로 글자에 쓰면 밝은 색(초록·노랑·주황 500 등)은 흰·연한 바탕에서 2 안팎으로 읽히지 않는다.
 * 화면은 데이터 색을 「정체성」으로만 쓰고, 글자색은 바탕과의 WCAG 대비를 계산해 정한다.
 *
 * - 연한 칩(soft): 바탕은 데이터 색을 페이지 바탕 위에 옅게 깐 것, 글자는 같은 색을 검정(라이트)·흰색(다크) 쪽으로
 *   섞어 가며 바탕(마우스 올림 바탕 포함)과 4.5 이상이 되는 첫 단계.
 * - 꽉 찬 칩(solid): 바탕은 데이터 색, 글자는 검정·흰색 중 바탕·마우스 올림 바탕(10% 짙힘) 모두와 4.5 이상인 쪽.
 *   중간 밝기 색(보라·남색 500 등)처럼 둘 다 못 미치면 바탕을 같은 색으로 조금 짙혀 흰 글자를 쓴다.
 */
export interface Rgb {
    r: number;
    g: number;
    b: number;
}
/** WCAG 본문 글자 기준 대비 */
export declare const TEXT_CONTRAST = 4.5;
/**
 * `#rgb`·`#rrggbb` 색 코드를 RGB 로 바꿉니다.
 *
 * @param hex 색 코드
 * @returns RGB, 읽을 수 없으면 null
 */
export declare function parseHexColor(hex: string | null | undefined): Rgb | null;
/** 상대 휘도(WCAG 2.x) */
export declare function relativeLuminance({ r, g, b }: Rgb): number;
/** 두 색의 대비 */
export declare function contrastRatio(a: Rgb, b: Rgb): number;
/** top 을 alpha 만큼 under 위에 깐 결과 색 */
export declare function blend(top: Rgb, alpha: number, under: Rgb): Rgb;
/** RGB → `#rrggbb` */
export declare function toHex({ r, g, b }: Rgb): string;
/**
 * base 를 toward(검정·흰색) 쪽으로 조금씩 섞어 모든 바탕과 min 이상 대비가 나는 첫 색을 고릅니다.
 *
 * @param base 시작 색(데이터 색)
 * @param backgrounds 판정할 바탕들
 * @param toward 섞어 갈 끝 색
 * @param min 최소 대비
 * @returns 조건을 만족하는 색(끝까지 못 찾으면 끝 색)
 */
export declare function ensureContrast(base: Rgb, backgrounds: Rgb[], toward: Rgb, min?: number): Rgb;
/**
 * 연한 칩 색 — 데이터 색을 옅게 깐 바탕과 대비를 만족하는 같은 계열 글자색.
 *
 * @param hex 데이터 색
 * @param dark 다크 모드 여부
 * @returns 바탕·마우스 올림 바탕·테두리·글자색(CSS 값), 색을 읽을 수 없으면 null
 */
export declare function softChipColors(hex: string | null | undefined, dark: boolean): {
    background: string;
    hoverBackground: string;
    border: string;
    color: string;
} | null;
/**
 * 꽉 찬 칩 색 — 데이터 색 바탕(과 마우스 올림 때 조금 짙힌 바탕) 위에서 4.5 이상인 검정·흰색 글자(필요하면 바탕을 조금 짙힘).
 *
 * @param hex 데이터 색
 * @returns 바탕·마우스 올림 바탕·글자색(CSS 값), 색을 읽을 수 없으면 null
 */
export declare function solidChipColors(hex: string | null | undefined): {
    background: string;
    hoverBackground: string;
    color: string;
} | null;
