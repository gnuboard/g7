import { default as React } from 'react';
export interface VersionItem {
    id: string | number;
    version: string;
    created_at: string;
    changes_summary: {
        added: number;
        removed: number;
    };
}
export interface VersionListProps {
    versions: VersionItem[];
    selectedId?: string | number;
    onSelect?: (id: string | number) => void;
}
/**
 * 레이아웃 버전 목록을 표시하는 컴포넌트
 * 각 버전의 변경 요약을 표시하고 선택 기능을 제공합니다.
 */
export declare const VersionList: React.FC<VersionListProps>;
