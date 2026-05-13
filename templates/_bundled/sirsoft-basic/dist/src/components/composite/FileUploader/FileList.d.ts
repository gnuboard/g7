import { default as React } from 'react';
import { DragEndEvent } from '@dnd-kit/core';
import { Attachment, PendingFile } from './types';
export interface FileListProps {
    /** 모든 파일 목록 (기존 + 대기) */
    allItems: (Attachment | PendingFile)[];
    /** 더 추가 가능 여부 */
    canAddMore: boolean;
    /** 총 파일 수 */
    totalCount: number;
    /** 최대 파일 수 */
    maxFiles: number;
    /** 최대 파일 크기 (MB) */
    maxSize: number;
    /** 허용 확장자 */
    accept?: string;
    /** 파일 삭제 핸들러 */
    onRemove: (item: PendingFile | Attachment) => void;
    /** 재시도 핸들러 */
    onRetry: (pendingFile: PendingFile) => void;
    /** 이미지 클릭 핸들러 */
    onImageClick: (item: PendingFile | Attachment) => void;
    /** 다운로드 핸들러 */
    onDownload: (item: Attachment) => void;
    /** 드래그 종료 핸들러 */
    onDragEnd: (event: DragEndEvent) => void;
    /** 추가 버튼 클릭 핸들러 */
    onAddClick: () => void;
    /** 대표 이미지 선택 기능 활성화 */
    enablePrimarySelection?: boolean;
    /** 현재 대표 이미지 식별자 (ID 또는 hash) */
    primaryFileId?: number | string | null;
    /** 대표 이미지 변경 핸들러 */
    onPrimaryChange?: (id: number | string | null) => void;
}
export declare const FileList: React.FC<FileListProps>;
export default FileList;
