import React, { useMemo } from 'react';
import { Div } from '../basic/Div';
import { Span } from '../basic/Span';
import type { EditorAttrs } from '../../types';
import { sanitizeSvgMarkup } from '../../support/svgSanitize';

/**
 * 브랜드 마크 데이터
 *
 * 결제수단 카탈로그(`_cached_brand_mark`)가 실어 내리는 브랜드 시각정보.
 * 두 형태 중 하나:
 * - 색 배지: `{ text: 'N', class: 'bg-green-500 text-white' }`
 * - 인라인 SVG 로고: `{ svg: '<svg>...</svg>' }`
 */
export interface BrandMarkData {
  /** 배지 텍스트 (예: 'N', 'NP', 'K') */
  text?: string;
  /** 배지 배경/텍스트 색상 Tailwind 클래스 (예: 'bg-green-500 text-white') */
  class?: string;
  /** 인라인 SVG 로고 마크업 문자열 */
  svg?: string;
}

export interface BrandMarkProps {
  /**
   * 브랜드 마크 데이터 (카탈로그 `_cached_brand_mark`).
   * 값이 없으면 아무것도 렌더하지 않음(레이아웃이 아이콘 폴백을 그림).
   */
  brandMark?: BrandMarkData | null;

  /** 마크 컨테이너 크기(px). 기본 32 (배지·SVG 공통) */
  size?: number;

  /** 사용자 정의 클래스 (컨테이너에 병합) */
  className?: string;

  /** DOM id 속성 (레이아웃 편집기 코어 일괄 ID) */
  id?: string;

  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: EditorAttrs;
}

/**
 * 결제 브랜드 마크 컴포넌트 (관리자)
 *
 * 결제수단 카탈로그가 내려준 브랜드 시각정보를 데이터 기반으로 렌더한다.
 * 과거에는 각 PG 플러그인의 체크아웃 DOM 인젝터가 렌더 후 DOM 을 후처리해 마크를
 * 주입했으나, 시각정보를 카탈로그(`_cached_brand_mark`)로 편입하고 이 컴포넌트가
 * 직접 그리도록 통일했다.
 *
 * - `brandMark.svg` 가 있으면 화이트리스트 sanitize 후 인라인 SVG 로고를 렌더.
 * - `brandMark.text` 가 있으면 색 배지(text + Tailwind 색상 클래스)를 렌더.
 * - 둘 다 없으면 null 을 반환해 레이아웃이 아이콘 폴백을 그리게 한다.
 *
 * @example
 * // 색 배지
 * <BrandMark brandMark={{ text: 'N', class: 'bg-green-500 text-white' }} />
 *
 * // SVG 로고
 * <BrandMark brandMark={{ svg: '<svg>...</svg>' }} />
 */
export const BrandMark: React.FC<BrandMarkProps> = ({
  brandMark,
  size = 32,
  className = '',
  id,
  editorAttrs,
}) => {
  const svg = brandMark?.svg;
  const text = brandMark?.text;

  // SVG 로고를 화이트리스트 sanitize (렌더 시점 XSS 방어선).
  const sanitizedSvg = useMemo(() => {
    // 설정은 메뉴 아이콘(MenuIcon)과 공용 — src/support/svgSanitize.ts
    return sanitizeSvgMarkup(svg);
  }, [svg]);

  const dimensionStyle: React.CSSProperties = {
    width: `${size}px`,
    height: `${size}px`,
    flex: `0 0 ${size}px`,
  };

  if (sanitizedSvg) {
    return (
      <Div
        id={id}
        aria-hidden="true"
        className={`inline-flex items-center justify-center ${className}`.trim()}
        style={dimensionStyle}
        dangerouslySetInnerHTML={{ __html: sanitizedSvg }}
        {...editorAttrs}
      />
    );
  }

  if (text && text.trim() !== '') {
    const badgeColorClass = brandMark?.class ?? 'bg-gray-500 text-white';

    return (
      <Span
        id={id}
        aria-hidden="true"
        className={`inline-flex items-center justify-center rounded-lg text-xs font-bold ${badgeColorClass} ${className}`.trim()}
        style={dimensionStyle}
        {...editorAttrs}
      >
        {text}
      </Span>
    );
  }

  // 브랜드 마크가 없는 결제수단(무통장/카드 등) — 레이아웃이 아이콘 폴백을 그린다.
  return null;
};

export default BrandMark;
