// @ts-ignore - DOMPurify 타입 정의 없음
import DOMPurify from 'dompurify';

/**
 * 인라인 SVG 화이트리스트 sanitize 설정 (결제 브랜드 마크·관리자 메뉴 아이콘 공용).
 *
 * 레이아웃 JSON 저장 검증은 SVG 문자열 내부의 위험 요소(onload/script/foreignObject 등)를
 * 검출하지 않으므로, XSS 방어는 렌더 시점 sanitize 가 담당한다. 메뉴 아이콘 SVG 는 서버가
 * 저장 전에 한 번 더 검사하지만(MenuIconSvgValidator) 화면도 같은 설정으로 다시 거른다.
 * 프리미티브 태그/속성만 허용하고, 스크립트·이벤트 핸들러·외부 참조(foreignObject/image/use/href)·
 * style 은 배제한다.
 */
export const SVG_SANITIZE_CONFIG: any = {
  ALLOWED_TAGS: [
    'svg', 'g', 'defs', 'title', 'desc',
    'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'path',
    'text', 'tspan',
    'linearGradient', 'radialGradient', 'stop',
    'clipPath',
  ],
  ALLOWED_ATTR: [
    'xmlns', 'width', 'height', 'viewBox', 'role', 'aria-label', 'aria-hidden',
    'fill', 'fill-opacity', 'fill-rule', 'stroke', 'stroke-width', 'stroke-linecap',
    'stroke-linejoin', 'opacity', 'transform',
    'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry',
    'd', 'points', 'width', 'height',
    'text-anchor', 'dominant-baseline', 'font-family', 'font-size', 'font-weight',
    'offset', 'stop-color', 'stop-opacity', 'gradientUnits', 'id', 'clip-path',
  ],
  // xlink:href / href / use / image / foreignObject 등 외부·스크립트 벡터 강제 차단.
  FORBID_TAGS: ['script', 'foreignObject', 'image', 'use', 'a', 'style'],
  FORBID_ATTR: [
    'onload', 'onerror', 'onclick', 'onmouseover', 'onfocus', 'onblur',
    'href', 'xlink:href',
  ],
};

/**
 * SVG 마크업을 화이트리스트로 정제합니다.
 *
 * @param svg SVG 마크업 문자열
 * @returns 정제된 마크업 (비었거나 남는 요소가 없으면 빈 문자열)
 */
export function sanitizeSvgMarkup(svg: string | null | undefined): string {
  if (typeof svg !== 'string' || svg.trim() === '') {
    return '';
  }

  return String(DOMPurify.sanitize(svg, SVG_SANITIZE_CONFIG) ?? '').trim();
}
