import { default as React } from 'react';
export interface ChangelogEntry {
    version: string;
    date: string;
    /** 변경 사항 글머리표 배열 */
    changes: string[];
}
export interface ChangelogTimelineProps {
    title?: string;
    entries?: ChangelogEntry[];
}
/**
 * 테마 상세 페이지의 업데이트 히스토리 타임라인.
 * 버전별로 changes 배열을 글머리표로 렌더.
 */
declare const ChangelogTimeline: React.FC<ChangelogTimelineProps>;
export default ChangelogTimeline;
