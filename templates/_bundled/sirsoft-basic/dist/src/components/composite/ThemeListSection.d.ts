import { default as React } from 'react';
export interface ThemeCardItem {
    id: number | string;
    title: string;
    summary?: string;
    thumbnail?: string;
    category?: string;
    price?: number | string;
    href?: string;
}
export interface ThemeCategoryDef {
    key: string;
    label: string;
}
export interface ThemeListSectionProps {
    /** breadcrumb 제목 */
    pageTitle?: string;
    eyebrow?: string;
    lead?: string;
    /** 카테고리 필터 — { key:'all', label:'전체' } 포함 */
    categories?: ThemeCategoryDef[];
    /** 전체 카드 목록 (서버에서 한 번에 받아 클라이언트 필터/정렬/페이징) */
    posts?: ThemeCardItem[];
    /** 한 페이지 카드 수 */
    perPage?: number;
    /** 상세 페이지 base href — `${baseHref}/${id}` 로 카드 링크 구성 */
    baseHref?: string;
}
/**
 * 테마 게시판 리스트 페이지 본체.
 * page-hero(브레드크럼 + 타이틀) + filter chips + search + sort + 카드 그리드 + pager.
 * 데이터는 props.posts(배열) 한 번에 받음 — 클라이언트 사이드 필터/정렬/페이징.
 */
declare const ThemeListSection: React.FC<ThemeListSectionProps>;
export default ThemeListSection;
