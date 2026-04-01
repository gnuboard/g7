/**
 * FileUploader 컴포넌트 (Re-export)
 *
 * 파일 업로드를 위한 통합 컴포넌트입니다.
 * 분리된 모듈에서 내보내기합니다.
 *
 * @module composite/FileUploader
 */

// 메인 컴포넌트
export { FileUploader, default } from './FileUploader/index';

// 하위 컴포넌트
export { FileDropZone } from './FileUploader/FileDropZone';
export { FileList } from './FileUploader/FileList';
export { SortableThumbnailItem } from './FileUploader/SortableThumbnailItem';

// Context
export { FileUploaderProvider, useFileUploaderContext } from './FileUploader/FileUploaderContext';
export type { FileUploaderContextValue } from './FileUploader/FileUploaderContext';

// 훅
export { useFileUploader } from './FileUploader/useFileUploader';
export type { UseFileUploaderOptions, UseFileUploaderReturn } from './FileUploader/useFileUploader';

// 타입
export type {
  Attachment,
  PendingFile,
  FileUploaderProps,
  FileUploaderRef,
  ApiEndpoints,
} from './FileUploader/types';

// 유틸리티
export { formatFileSize, extractErrorMessage, getFileIcon, t as fileUploaderT } from './FileUploader/utils';
