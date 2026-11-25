/**
 * 인라인 SVG 화이트리스트 sanitize 설정 (결제 브랜드 마크·관리자 메뉴 아이콘 공용).
 *
 * 레이아웃 JSON 저장 검증은 SVG 문자열 내부의 위험 요소(onload/script/foreignObject 등)를
 * 검출하지 않으므로, XSS 방어는 렌더 시점 sanitize 가 담당한다. 메뉴 아이콘 SVG 는 서버가
 * 저장 전에 한 번 더 검사하지만(MenuIconSvgValidator) 화면도 같은 설정으로 다시 거른다.
 * 프리미티브 태그/속성만 허용하고, 스크립트·이벤트 핸들러·외부 참조(foreignObject/image/use/href)·
 * style 은 배제한다.
 */
export declare const SVG_SANITIZE_CONFIG: any;
/**
 * SVG 마크업을 화이트리스트로 정제합니다.
 *
 * @param svg SVG 마크업 문자열
 * @returns 정제된 마크업 (비었거나 남는 요소가 없으면 빈 문자열)
 */
export declare function sanitizeSvgMarkup(svg: string | null | undefined): string;
