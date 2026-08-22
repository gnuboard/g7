import { default as React } from 'react';
import { Attachment, PendingFile, ApiEndpoints } from './types';
export interface FileUploaderContextValue {
    existingFiles: Attachment[];
    pendingFiles: PendingFile[];
    isDragOver: boolean;
    isDeleting: boolean;
    maxFiles: number;
    maxSize: number;
    accept?: string;
    endpoints: ApiEndpoints;
    confirmBeforeRemove: boolean;
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
    setIsDragOver: (value: boolean) => void;
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
}
declare const FileUploaderContext: React.Context<FileUploaderContextValue | null>;
/**
 * FileUploader Context Provider
 */
export declare const FileUploaderProvider: React.FC<{
    value: FileUploaderContextValue;
    children: React.ReactNode;
}>;
/**
 * FileUploader Context 사용 훅
 */
export declare const useFileUploaderContext: () => FileUploaderContextValue;
export default FileUploaderContext;
