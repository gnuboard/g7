import { default as React } from 'react';
export interface FileDropZoneProps {
    /** 드래그 오버 상태 */
    isDragOver: boolean;
    /** 추가 가능 여부 */
    canAddMore: boolean;
    /** 파일 처리 핸들러 */
    onFiles: (files: FileList) => void;
    /** 드래그 오버 상태 설정 */
    setIsDragOver: (value: boolean) => void;
    /** Input 요소 ref */
    inputRef: React.RefObject<HTMLInputElement | null>;
    /** 허용 확장자 */
    accept?: string;
    /** 최대 파일 수 */
    maxFiles: number;
    /** 최대 파일 크기 (MB) */
    maxSize: number;
    /** 비활성화 여부 */
    disabled?: boolean;
    /** 자식 요소 */
    children?: React.ReactNode;
}
export declare const FileDropZone: React.FC<FileDropZoneProps>;
export default FileDropZone;
