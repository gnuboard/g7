import React, { useEffect, useMemo, useState } from 'react';
import { I } from '../basic/I';
import { Icon } from '../basic/Icon';
import { Img } from '../basic/Img';
import { Span } from '../basic/Span';
import { STYLE_TOKENS } from '../basic/iconNameParser';
import { sanitizeSvgMarkup } from '../../support/svgSanitize';
import type { EditorAttrs } from '../../types';

/**
 * 메뉴 아이콘 구조형 값 (`icon_spec`, 서버 MenuIconPresenter 응답).
 *
 * - class: `{ type: 'class', value: 'fas fa-users' }`
 * - image: `{ type: 'image', source: 'site'|'package'|'attachment', path?, attachment_id?, hash?, url }`
 * - svg:   `{ type: 'svg', value: '<svg …>' }`
 */
export interface MenuIconSpecData {
  type: 'class' | 'image' | 'svg';
  value?: string | null;
  source?: 'site' | 'package' | 'attachment' | null;
  path?: string | null;
  attachment_id?: number | null;
  hash?: string | null;
  url?: string | null;
}

/**
 * FA 클래스 패턴 — 서버 `MenuIconSpec::CLASS_PATTERN` 과 같다.
 */
export const MENU_ICON_CLASS_PATTERN = /^[a-z0-9]+(?:-[a-z0-9]+)*(?:\s+[a-z0-9]+(?:-[a-z0-9]+)*)*$/i;

export interface MenuIconProps {
  /** 구조형 아이콘 (있으면 icon 보다 우선) */
  spec?: MenuIconSpecData | null;
  /** 호환 문자열 아이콘 (FA 클래스 또는 맨 이름 — spec 이 없을 때 class 로 해석) */
  icon?: string | null;
  /** 그릴 수 없을 때(깨진 이미지·형식 오류·빈 SVG) 대신 그릴 FA 클래스/이름. 없으면 아무것도 그리지 않음 */
  fallbackIcon?: string;
  /** 아이콘에 붙일 클래스 (크기·색 — 예: `w-4 h-4 text-gray-600`) */
  className?: string;
  /** DOM id 속성 */
  id?: string;
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용) */
  editorAttrs?: EditorAttrs;
}

/**
 * spec 과 호환 문자열 icon 에서 그릴 아이콘을 정합니다 (spec 우선, 없으면 icon 을 class 로).
 *
 * @param spec 구조형 아이콘
 * @param icon 호환 문자열 아이콘
 * @returns 그릴 구조형 아이콘, 없으면 null
 */
export function resolveMenuIconSpec(
  spec?: MenuIconSpecData | null,
  icon?: string | null,
): MenuIconSpecData | null {
  if (spec && typeof spec === 'object' && typeof spec.type === 'string') {
    return spec;
  }

  if (typeof icon === 'string' && icon.trim() !== '') {
    return { type: 'class', value: icon.trim() };
  }

  return null;
}

/**
 * 그릴 아이콘이 있는지 판정합니다.
 *
 * @param spec 구조형 아이콘
 * @param icon 호환 문자열 아이콘
 * @returns 있으면 true
 */
export function hasMenuIcon(spec?: MenuIconSpecData | null, icon?: string | null): boolean {
  return resolveMenuIconSpec(spec, icon) !== null;
}

/**
 * FA 클래스(또는 맨 이름)를 그립니다.
 *
 * 스타일 토큰(fas·far·fab·fa-solid…)이 있는 완전한 클래스는 `I` + 원문 클래스로 그린다 — 사이드바 계약
 * (메뉴 API 가 내려준 FA 클래스 문자열을 그대로 쓴다, AGENTS.md)이고 종전 사이드바 출력과 바이트가 같다.
 * 맨 이름(`home`·`tag`·`folder` 등 — 이커머스 카테고리·브랜드 목록)은 `Icon` 이 스타일을 붙인다.
 *
 * @param value 클래스 문자열
 * @param className 덧붙일 클래스
 * @param id DOM id
 * @param editorAttrs 편집기 주입 속성
 * @returns 아이콘 요소
 */
function renderClassIcon(value: string, className: string, id?: string, editorAttrs?: EditorAttrs): React.ReactElement {
  const hasStyleToken = value.split(/\s+/).some((token) => STYLE_TOKENS[token] !== undefined);

  if (hasStyleToken) {
    return <I id={id} className={`${value} ${className}`.trim()} aria-hidden="true" {...editorAttrs} />;
  }

  return <Icon id={id} name={value} className={className} {...editorAttrs} />;
}

/**
 * 관리자 메뉴 아이콘 렌더러 — FA 클래스 · 이미지 · 인라인 SVG (#381).
 *
 * 사이드바·메뉴 관리 목록·상세·편집 미리보기가 같은 규칙으로 그린다. 이미지는 `<img>`(깨지면 fallback),
 * SVG 는 화이트리스트 정제 후 인라인으로 그려 글자색(currentColor)을 따른다.
 *
 * @example
 * <MenuIcon spec={item.icon_spec} icon={item.icon} fallbackIcon="fas fa-circle" className="w-5 h-5" />
 */
export const MenuIcon: React.FC<MenuIconProps> = ({
  spec,
  icon,
  fallbackIcon,
  className = '',
  id,
  editorAttrs,
}) => {
  const resolved = resolveMenuIconSpec(spec, icon);
  const [broken, setBroken] = useState(false);
  const imageUrl = resolved?.type === 'image' ? resolved.url ?? '' : '';

  // 이미지 주소가 바뀌면 깨짐 상태를 다시 본다
  useEffect(() => {
    setBroken(false);
  }, [imageUrl]);

  const sanitizedSvg = useMemo(
    () => (resolved?.type === 'svg' ? sanitizeSvgMarkup(resolved.value ?? '') : ''),
    [resolved?.type, resolved?.value],
  );

  const fallback = fallbackIcon ? renderClassIcon(fallbackIcon, className, id, editorAttrs) : null;

  if (!resolved) {
    return fallback;
  }

  if (resolved.type === 'class') {
    const value = (resolved.value ?? '').trim();

    if (value === '' || !MENU_ICON_CLASS_PATTERN.test(value)) {
      return fallback;
    }

    return renderClassIcon(value, className, id, editorAttrs);
  }

  if (resolved.type === 'image') {
    if (imageUrl === '' || broken) {
      return fallback;
    }

    return (
      <Img
        id={id}
        src={imageUrl}
        alt=""
        aria-hidden="true"
        loading="lazy"
        decoding="async"
        onError={() => setBroken(true)}
        className={`menu-icon-media ${className}`.trim()}
        {...editorAttrs}
      />
    );
  }

  if (resolved.type === 'svg') {
    if (sanitizedSvg === '' || !/^<svg[\s>]/i.test(sanitizedSvg)) {
      return fallback;
    }

    return (
      <Span
        id={id}
        aria-hidden="true"
        className={`menu-icon-media ${className}`.trim()}
        dangerouslySetInnerHTML={{ __html: sanitizedSvg }}
        {...editorAttrs}
      />
    );
  }

  return fallback;
};

export default MenuIcon;
