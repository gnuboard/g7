import { default as React } from 'react';
import { EditorAttrs } from '../../types';
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
export declare const MENU_ICON_CLASS_PATTERN: RegExp;
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
export declare function resolveMenuIconSpec(spec?: MenuIconSpecData | null, icon?: string | null): MenuIconSpecData | null;
/**
 * 그릴 아이콘이 있는지 판정합니다.
 *
 * @param spec 구조형 아이콘
 * @param icon 호환 문자열 아이콘
 * @returns 있으면 true
 */
export declare function hasMenuIcon(spec?: MenuIconSpecData | null, icon?: string | null): boolean;
/**
 * 관리자 메뉴 아이콘 렌더러 — FA 클래스 · 이미지 · 인라인 SVG.
 *
 * 사이드바·메뉴 관리 목록·상세·편집 미리보기가 같은 규칙으로 그린다. 이미지는 `<img>`(깨지면 fallback),
 * SVG 는 화이트리스트 정제 후 인라인으로 그려 글자색(currentColor)을 따른다.
 *
 * @example
 * <MenuIcon spec={item.icon_spec} icon={item.icon} fallbackIcon="fas fa-circle" className="w-5 h-5" />
 */
export declare const MenuIcon: React.FC<MenuIconProps>;
export default MenuIcon;
