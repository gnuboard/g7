/**
 * FileUploader 모듈
 *
 * 파일 업로드 기능을 제공하는 컴포넌트 모듈입니다.
 *
 * @module composite/FileUploader
 */

// 메인 컴포넌트
export { FileUploader } from './FileUploader';
export { default } from './FileUploader';

// 하위 컴포넌트
export { FileDropZone } from './FileDropZone';
export { FileList } from './FileList';
export { SortableThumbnailItem } from './SortableThumbnailItem';

// Context
export { FileUploaderProvider, useFileUploaderContext } from './FileUploaderContext';
export type { FileUploaderContextValue } from './FileUploaderContext';

// 훅
export { useFileUploader } from './useFileUploader';
export type { UseFileUploaderOptions, UseFileUploaderReturn } from './useFileUploader';

// 타입
export type {
  Attachment,
  PendingFile,
  FileUploaderProps,
  FileUploaderRef,
  ApiEndpoints,
} from './types';

// 유틸리티
export { formatFileSize, extractErrorMessage, getFileIcon, t } from './utils';
