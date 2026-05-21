import { default as React } from 'react';
export interface NoticeListItem {
    title: string;
    /** ISO/유사 형식 (예: '2026-05-15') */
    date: string;
    href: string;
}
export interface NoticeTabDef {
    key: string;
    label: string;
}
export interface NoticeTabProps {
    title?: string;
    /** 더보기 링크 — undefined면 + 버튼 비표시 */
    moreHref?: string;
    /** 더보기 aria-label */
    moreLabel?: string;
    tabs: NoticeTabDef[];
    /** 각 탭 key별 리스트 데이터 */
    lists: Record<string, NoticeListItem[]>;
    /** 기본 활성 탭 — 미지정 시 첫 번째 탭 */
    defaultTab?: string;
    className?: string;
}
/**
 * S4 알림마당 — 공지/보도/채용 탭 카드.
 * aria-selected 토글, hidden 속성으로 비활성 리스트 처리.
 */
declare const NoticeTab: React.FC<NoticeTabProps>;
export default NoticeTab;
