import { default as React } from 'react';
import { VersionItem } from './VersionList';
export interface LayoutHistoryPanelProps {
    /** 레이아웃 ID */
    layoutId: string | number;
    /** 버전 목록 */
    versions: VersionItem[];
    /** 버전 복원 콜백 */
    onRestore: (versionId: string | number) => void;
}
/**
 * 레이아웃 히스토리 패널 컴포넌트
 * 버전 목록을 표시하고 선택된 버전을 복원하는 기능을 제공합니다.
 */
export declare const LayoutHistoryPanel: React.FC<LayoutHistoryPanelProps>;
