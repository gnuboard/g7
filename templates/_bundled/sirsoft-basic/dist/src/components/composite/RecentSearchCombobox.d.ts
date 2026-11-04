import { default as React } from 'react';
import { EditorAttrs } from '../../types';
/** 최근 검색어 한 건 (엔진 계약) */
export interface RecentSearchItem {
    id: number | null;
    keyword: string;
    searchedAt: number;
}
export interface RecentSearchComboboxProps {
    /** 최근 검색어 범위 (코어 통합검색 `core.site`, 확장은 `{확장ID}.{이름}`) — 같은 범위끼리 목록을 공유한다 */
    scope?: string;
    /** 입력칸 name */
    name?: string;
    /** 처음 보일 검색어(예: 주소의 `q`). 바뀌면 입력칸이 따라간다 — 기록하지 않는다 */
    value?: string | null;
    /**
     * 검색 결과 화면 경로(예: `/search`) — 주면 입력칸이 주소를 따라간다: 이 경로에 있으면 주소의 `name` 검색어를,
     * 다른 화면이면 빈칸을 보인다. 화면을 옮겨도 다시 마운트되지 않는 머리글·모바일 서랍 검색창용(검색한 뒤 다른 화면에 검색어가 남지 않게)
     */
    resultPath?: string | null;
    /** 안내 문구 */
    placeholder?: string;
    /** 입력칸 접근성 이름 (없으면 안내 문구) */
    ariaLabel?: string;
    /** 검색 단추를 보이는가 */
    showButton?: boolean;
    /** 검색 단추 글자 (없으면 「검색」) */
    buttonText?: string;
    /** 입력칸 왼쪽 돋보기 아이콘을 보이는가 */
    showIcon?: boolean;
    /** 루트 클래스 */
    className?: string;
    /** 입력칸 클래스 (주면 기본 모양을 대신한다) */
    inputClassName?: string;
    /** 검색 단추 클래스 (주면 기본 모양을 대신한다) */
    buttonClassName?: string;
    /** 검색 — 인자: 다듬은 검색어. 레이아웃은 `<on event="onSearch">` 와 `$args[0]` 으로 받는다 */
    onSearch?: (keyword: string) => void;
    /** 루트 시험 식별자 */
    'data-testid'?: string;
    /** DOM id (레이아웃 편집기 코어 일괄 ID) */
    id?: string;
    /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
    editorAttrs?: EditorAttrs;
}
/**
 * 최근 검색어 펼침 목록을 가진 검색창
 */
export declare const RecentSearchCombobox: React.FC<RecentSearchComboboxProps>;
export default RecentSearchCombobox;
