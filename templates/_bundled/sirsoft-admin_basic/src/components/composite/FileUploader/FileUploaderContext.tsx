/**
 * FileUploader Context
 *
 * 파일 업로더의 상태와 액션을 공유하는 Context입니다.
 *
 * @module composite/FileUploader/FileUploaderContext
 */

import React, { createContext, useContext } from 'react';
import type { Attachment, PendingFile, ApiEndpoints } from './types';

// ========== Context Types ==========

export interface FileUploaderContextValue {
  // 상태
  existingFiles: Attachment[];
  pendingFiles: PendingFile[];
  isDragOver: boolean;
  isDeleting: boolean;

  // 설정
  maxFiles: number;
  maxSize: number;
  accept?: string;
  endpoints: ApiEndpoints;
  confirmBeforeRemove: boolean;

  // 계산된 값
  totalCount: number;
  hasFiles: boolean;
  canAddMore: boolean;
  allItems: (Attachment | PendingFile)[];
  imageFiles: (Attachment | PendingFile)[];

  // 액션
  handleFiles: (files: FileList) => Promise<void>;
  handleRemove: (item: PendingFile | Attachment) => Promise<void>;
  handleRetry: (pendingFile: PendingFile) => void;
  handleUploadAll: () => Promise<Attachment[]>;
  handleOpenGallery: (item: PendingFile | Attachment) => void;
  handleDownload: (item: Attachment) => Promise<void>;
  handleDragEnd: (event: import('@dnd-kit/core').DragEndEvent) => Promise<void>;

  // 드래그 상태 설정
  setIsDragOver: (value: boolean) => void;

  // Input ref
  inputRef: React.RefObject<HTMLInputElement | null>;

  // 갤러리 상태
  galleryOpen: boolean;
  setGalleryOpen: (value: boolean) => void;
  galleryStartIndex: number;
  galleryKeyRef: React.MutableRefObject<number>;

  // 삭제 확인 모달 상태
  confirmDialogOpen: boolean;
  setConfirmDialogOpen: (value: boolean) => void;
  itemToDelete: Attachment | null;
  executeRemoveAttachment: (item: Attachment) => Promise<void>;

  // 인증된 이미지 URL 캐시
  authenticatedImageUrls: Map<number, string>;
}

// ========== Context ==========

const FileUploaderContext = createContext<FileUploaderContextValue | null>(null);

/**
 * FileUploader Context Provider
 */
export const FileUploaderProvider: React.FC<{
  value: FileUploaderContextValue;
  children: React.ReactNode;
}> = ({ value, children }) => {
  return (
    <FileUploaderContext.Provider value={value}>
      {children}
    </FileUploaderContext.Provider>
  );
};

/**
 * FileUploader Context 사용 훅
 */
export const useFileUploaderContext = (): FileUploaderContextValue => {
  const context = useContext(FileUploaderContext);
  if (!context) {
    throw new Error('useFileUploaderContext must be used within FileUploaderProvider');
  }
  return context;
};

export default FileUploaderContext;
