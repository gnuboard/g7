import { default as React } from 'react';
import { IconOption } from './IconSelect';
import { MenuIconSpecData } from './MenuIcon';
import { EditorAttrs } from '../../types';
/** 편집 탭 */
export type MenuIconFieldTab = 'pick' | 'input' | 'upload' | 'svg';
export interface MenuIconFieldProps {
    /** 현재 값 — 구조형 아이콘(`icon_spec`) 또는 null */
    value?: MenuIconSpecData | null;
    /** 값 변경 — 엔진 폼 바인딩이 읽는 가짜 이벤트(`{ target: { name, value } }`)로 내보낸다 */
    onChange?: (event: {
        target: {
            name?: string;
            value: MenuIconSpecData | null;
        };
    }) => void;
    /** 폼 필드 이름 (기본 icon_spec) */
    name?: string;
    /** 「선택」 탭 아이콘 목록 (기본 20개) */
    options?: IconOption[];
    /** 「선택」 탭 플레이스홀더 */
    placeholder?: string;
    /** 「선택」 탭 검색 플레이스홀더 */
    searchPlaceholder?: string;
    /** 「선택」 탭 검색 결과 없음 문구 */
    noResultsText?: string;
    /** 업로드 API 주소 */
    uploadEndpoint?: string;
    /** 비활성 */
    disabled?: boolean;
    /** 서버 검증 오류 문구 (필드 아래 표시) */
    error?: string;
    /** 사용자 정의 클래스 */
    className?: string;
    /** DOM id 속성 */
    id?: string;
    /** 레이아웃 편집기 주입 속성 (편집 모드 전용) */
    editorAttrs?: EditorAttrs;
}
/**
 * 값에 맞는 첫 탭을 고릅니다.
 *
 * @param value 현재 값
 * @param options 선택 탭 목록
 * @returns 탭
 */
export declare function initialTab(value: MenuIconSpecData | null | undefined, options?: IconOption[]): MenuIconFieldTab;
/**
 * 직접 입력 문자열을 구조형 값으로 해석합니다.
 *
 * `/` 로 시작하면 사이트 이미지 경로, `http:`·`https:`·`//`·`data:`·`javascript:` 는 거부(오류 키),
 * 그 밖은 FA 클래스.
 *
 * @param raw 입력 문자열
 * @returns 해석 결과 (값 또는 오류 키)
 */
export declare function parseDirectInput(raw: string): {
    spec: MenuIconSpecData | null;
    errorKey: string | null;
};
/**
 * 관리자 메뉴 아이콘 입력 필드.
 *
 * 미리보기 + 유형 라벨 + 탭 4개(선택·직접 입력·업로드·SVG 코드). 확장이 선언한 이미지·SVG 아이콘도
 * 미리보기로 보여 「아이콘 선택」으로 비어 보이지 않는다.
 */
export declare const MenuIconField: React.FC<MenuIconFieldProps>;
export default MenuIconField;
