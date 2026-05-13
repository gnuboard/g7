/**
 * FileUploader 타입 정의
 *
 * @module composite/FileUploader/types
 */

// ========== Types ==========

export interface Attachment {
  id: number;
  hash: string;
  original_filename: string;
  mime_type: string;
  size: number;
  size_formatted: string;
  download_url: string;
  order: number;
  is_image: boolean;
  meta?: Record<string, unknown>;
}

export interface PendingFile {
  id: string;
  file: File;
  compressedBlob?: Blob;
  original_filename: string;
  mime_type: string;
  size: number;
  size_formatted: string;
  preview?: string;
  status: 'pending' | 'compressing' | 'uploading' | 'done' | 'error';
  progress: number;
  error?: string;
}

export interface FileUploaderProps {
  /**
   * 업로드 트리거 이벤트명
   *
   * 지정 시 해당 이벤트를 구독하여 업로드를 트리거합니다.
   * emitEvent 액션 핸들러와 함께 사용합니다.
   *
   * @example
   * // 레이아웃 JSON에서 FileUploader 설정
   * {
   *   "type": "composite",
   *   "name": "FileUploader",
   *   "props": {
   *     "uploadTriggerEvent": "upload:site_logo",
   *     "autoUpload": false
   *   }
   * }
   *
   * // 저장 버튼에서 업로드 트리거
   * {
   *   "handler": "sequence",
   *   "actions": [
   *     { "handler": "emitEvent", "params": { "event": "upload:site_logo" } },
   *     { "handler": "apiCall", "target": "/api/settings", ... }
   *   ]
   * }
   */
  uploadTriggerEvent?: string;
  /** 첨부 대상 모델 타입 */
  attachmentableType?: string;
  /** 첨부 대상 ID */
  attachmentableId?: number;
  /** 컬렉션명 */
  collection?: string;
  /** 최대 첨부 개수 */
  maxFiles?: number;
  /** 파일당 최대 크기 (MB) */
  maxSize?: number;
  /** 동시 업로드 수 */
  maxConcurrentUploads?: number;
  /** 허용 확장자 (예: ".jpg,.png,.pdf") */
  accept?: string;
  /** 이미지 압축 옵션 */
  imageCompression?: {
    maxSizeMB?: number;
    maxWidthOrHeight?: number;
  };
  /** 접근 가능 역할 ID 배열 */
  roleIds?: number[];
  /** 자동 업로드 여부 (false인 경우 uploadTriggerEvent로 업로드 트리거) */
  autoUpload?: boolean;
  /** 파일 변경 콜백 */
  onFilesChange?: (files: PendingFile[]) => void;
  /** 업로드 완료 콜백 */
  onUploadComplete?: (attachments: Attachment[]) => void;
  /** 업로드 에러 콜백 */
  onUploadError?: (error: string, file: File) => void;
  /** 삭제 콜백 */
  onRemove?: (id: number | string) => void;
  /** 순서 변경 콜백 (reorder 완료 후 정렬된 기존 파일 목록 전달) */
  onReorder?: (files: Attachment[]) => void;
  /** 초기 첨부파일 목록 */
  initialFiles?: Attachment[];
  /** 비활성화 여부 (읽기 전용 모드) */
  disabled?: boolean;
  /** 추가 CSS 클래스 */
  className?: string;
  /**
   * 삭제 전 확인 모달 표시 여부 (기존 첨부파일만 적용)
   * true: 삭제 확인 모달 표시 후 삭제
   * false: 즉시 삭제 (기본값)
   */
  confirmBeforeRemove?: boolean;
  /** 삭제 확인 모달 제목 (다국어 키 또는 문자열) */
  confirmRemoveTitle?: string;
  /** 삭제 확인 모달 메시지 (다국어 키 또는 문자열) */
  confirmRemoveMessage?: string;
  /**
   * 업로드 시 추가로 전송할 파라미터
   *
   * FormData에 추가 필드를 포함하여 업로드합니다.
   *
   * @example
   * {
   *   "uploadParams": {
   *     "temp_key": "{{_local.ui.imageTempKey}}"
   *   }
   * }
   */
  uploadParams?: Record<string, string>;
  /**
   * 대표 파일(이미지) 선택 기능 활성화
   *
   * true일 경우 파일 목록에 대표 선택 버튼이 표시됩니다.
   */
  enablePrimarySelection?: boolean;
  /**
   * 현재 대표 파일 식별자 (ID 또는 hash)
   *
   * enablePrimarySelection이 true일 때 사용됩니다.
   */
  primaryFileId?: number | string | null;
  /**
   * 대표 파일 변경 콜백
   */
  onPrimaryChange?: (id: number | string | null) => void;
  /**
   * API 엔드포인트 설정 (모듈용 커스터마이징)
   *
   * @example
   * // 모듈에서 커스텀 API 사용
   * {
   *   "type": "composite",
   *   "name": "FileUploader",
   *   "props": {
   *     "apiEndpoints": {
   *       "upload": "/api/admin/sirsoft-ecommerce/products/attachments",
   *       "delete": "/api/admin/sirsoft-ecommerce/products/attachments/:id",
   *       "reorder": "/api/admin/sirsoft-ecommerce/products/attachments/reorder"
   *     }
   *   }
   * }
   */
  apiEndpoints?: {
    /** 파일 업로드 API (기본값: /api/admin/attachments) */
    upload?: string;
    /** 파일 삭제 API - :id를 실제 ID로 치환 (기본값: /api/admin/attachments/:id) */
    delete?: string;
    /** 순서 변경 API (기본값: /api/admin/attachments/reorder) */
    reorder?: string;
  };
}

export interface FileUploaderRef {
  /** 모든 대기 파일 업로드 */
  uploadAll: () => Promise<Attachment[]>;
  /** 대기 파일 초기화 */
  clear: () => void;
  /** 대기 파일 목록 조회 */
  getPendingFiles: () => PendingFile[];
}

export interface ApiEndpoints {
  upload: string;
  delete: string;
  reorder: string;
}
