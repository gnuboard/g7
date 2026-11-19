import { Attachment, PendingFile, FileUploaderProps, ApiEndpoints } from './types';
/**
 * 썸네일로 그리는 기존 첨부를 확대 보기에서 blob 없이 열 수 있는 원본 주소.
 *
 * 미리보기 주소(`preview_url`)는 서버가 열람 규칙(비밀글 서명·공개 여부)을 담아 낸 주소라 그대로 연다. 다운로드
 * 주소만 있는 첨부는 권한이 걸려 있을 수 있어(인증 헤더가 있어야 열린다) 주소로 열지 않는다 — 업로더가 인증 요청으로
 * 받아 둔 blob 을 쓴다. 종전에는 이 경우에도 인증 없는 원본 주소를 열어 권한 첨부의 확대 보기가 깨졌다.
 *
 * @param file 기존 첨부
 * @returns 바로 열 주소 (없으면 undefined)
 */
export declare function directZoomSource(file: Attachment): string | undefined;
export interface UseFileUploaderOptions {
    attachmentableType?: string;
    attachmentableId?: number;
    collection: string;
    maxFiles: number;
    maxSize?: number;
    maxConcurrentUploads: number;
    accept?: string;
    imageCompression?: FileUploaderProps['imageCompression'];
    roleIds: number[];
    autoUpload: boolean;
    initialFiles: Attachment[];
    endpoints: ApiEndpoints;
    onFilesChange?: (files: PendingFile[]) => void;
    onUploadComplete?: (attachments: Attachment[]) => void;
    onUploadError?: (error: string, file: File) => void;
    onRemove?: (id: number | string) => void;
    onReorder?: (files: Attachment[]) => void;
    uploadTriggerEvent?: string;
    confirmBeforeRemove: boolean;
    uploadParams?: Record<string, string>;
}
export interface UseFileUploaderReturn {
    existingFiles: Attachment[];
    pendingFiles: PendingFile[];
    isDragOver: boolean;
    setIsDragOver: (value: boolean) => void;
    isDeleting: boolean;
    totalCount: number;
    hasFiles: boolean;
    canAddMore: boolean;
    allItems: (Attachment | PendingFile)[];
    imageFiles: (Attachment | PendingFile)[];
    handleFiles: (files: FileList) => Promise<void>;
    handleRemove: (item: PendingFile | Attachment) => Promise<void>;
    handleRetry: (pendingFile: PendingFile) => void;
    handleUploadAll: () => Promise<Attachment[]>;
    handleOpenGallery: (item: PendingFile | Attachment) => void;
    handleDownload: (item: Attachment) => Promise<void>;
    handleDragEnd: (event: import('@dnd-kit/core').DragEndEvent) => Promise<void>;
    inputRef: React.RefObject<HTMLInputElement | null>;
    galleryOpen: boolean;
    setGalleryOpen: (value: boolean) => void;
    galleryStartIndex: number;
    galleryKeyRef: React.MutableRefObject<number>;
    confirmDialogOpen: boolean;
    setConfirmDialogOpen: (value: boolean) => void;
    itemToDelete: Attachment | null;
    executeRemoveAttachment: (item: Attachment) => Promise<void>;
    authenticatedImageUrls: Map<number, string>;
    /** 썸네일로 그리는 첨부도 원본 blob 을 받아 두게 한다 (썸네일 실패·확대 보기) */
    requestAuthenticatedImage: (ids: number[]) => void;
    clear: () => void;
    getPendingFiles: () => PendingFile[];
}
export declare function useFileUploader(options: UseFileUploaderOptions): UseFileUploaderReturn;
export default useFileUploader;
