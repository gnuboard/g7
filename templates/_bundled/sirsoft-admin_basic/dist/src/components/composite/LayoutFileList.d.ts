import { default as React } from 'react';
export interface LayoutFileItem {
    /**
     * 파일 ID
     */
    id: string | number;
    /**
     * 파일명
     */
    name: string;
    /**
     * 최종 수정 시간
     */
    updated_at: string;
}
export interface LayoutFileListProps {
    /**
     * 파일 목록
     */
    files: LayoutFileItem[];
    /**
     * 선택된 파일 ID
     */
    selectedId?: string | number;
    /**
     * 파일 선택 시 콜백
     */
    onSelect: (id: string | number) => void;
    /**
     * 사용자 정의 클래스
     */
    className?: string;
}
/**
 * LayoutFileList 레이아웃 파일 목록 컴포넌트
 *
 * 레이아웃 파일 목록을 표시하고 선택 기능을 제공하는 composite 컴포넌트입니다.
 * 선택된 파일은 하이라이트되며, 접근성을 위해 ARIA 속성을 지원합니다.
 *
 * @example
 * // 기본 사용
 * <LayoutFileList
 *   files={[
 *     { id: 1, name: 'layout1.json', updated_at: '2024-01-01T12:00:00Z' },
 *     { id: 2, name: 'layout2.json', updated_at: '2024-01-02T15:30:00Z' }
 *   ]}
 *   selectedId={1}
 *   onSelect={(id) => console.log('Selected:', id)}
 * />
 *
 * // 빈 목록
 * <LayoutFileList files={[]} onSelect={(id) => {}} />
 */
export declare const LayoutFileList: React.FC<LayoutFileListProps>;
