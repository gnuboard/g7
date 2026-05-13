/**
 * FileUploader 컴포넌트
 *
 * 파일 업로드를 위한 통합 컴포넌트입니다.
 * - 드래그 앤 드롭 지원
 * - 이미지 압축
 * - 병렬 업로드
 * - 순서 변경
 * - 이미지 갤러리
 *
 * @module composite/FileUploader
 */

import { forwardRef, useImperativeHandle, useMemo, useRef, useCallback } from 'react';

import { Div } from '../../basic/Div';
import { ConfirmDialog } from '../ConfirmDialog';
import { ImageGallery, GalleryImage } from '../ImageGallery';

import { FileUploaderProvider } from './FileUploaderContext';
import { useFileUploader } from './useFileUploader';
import { FileDropZone } from './FileDropZone';
import { FileList } from './FileList';
import type { FileUploaderProps, FileUploaderRef, Attachment, PendingFile } from './types';
import { t } from './utils';

/** 안정적인 빈 배열 참조 — 매 렌더마다 새 [] 생성 방지 (useEffect 무한 루프 방지) */
const EMPTY_FILES: Attachment[] = [];
const EMPTY_ROLE_IDS: number[] = [];

// ========== Main Component ==========

export const FileUploader = forwardRef<FileUploaderRef, FileUploaderProps>(
  (
    {
      uploadTriggerEvent,
      attachmentableType,
      attachmentableId,
      collection = 'default',
      maxFiles = 10,
      maxSize = 10,
      maxConcurrentUploads = 3,
      accept,
      imageCompression: compressionOptions,
      roleIds = EMPTY_ROLE_IDS,
      autoUpload = true,
      onFilesChange,
      onChange,
      onUploadComplete,
      onUploadError,
      onRemove,
      onReorder,
      initialFiles = EMPTY_FILES,
      className = '',
      confirmBeforeRemove = false,
      confirmRemoveTitle,
      confirmRemoveMessage,
      apiEndpoints,
      uploadParams,
      enablePrimarySelection = false,
      primaryFileId,
      onPrimaryChange,
    },
    ref
  ) => {
    // API 엔드포인트 기본값 설정 (User 템플릿용 기본 경로)
    const endpoints = {
      upload: apiEndpoints?.upload ?? '/api/attachments',
      delete: apiEndpoints?.delete ?? '/api/attachments/:id',
      reorder: apiEndpoints?.reorder ?? '/api/attachments/reorder',
    };

    // onFilesChange 래퍼: ActionDispatcher 호환 형태로 변환
    // ActionDispatcher는 type: "change" → onChange prop으로 매핑하므로,
    // onChange가 설정될 수 있음. onFilesChange는 직접 props 전달 시 사용.
    // ref로 안정화: ActionDispatcher가 매 렌더마다 새 함수를 바인딩하므로
    // 직접 의존하면 useMemo/useEffect 무한 루프 발생. ref로 최신 참조만 유지.
    const callbackRef = useRef(onChange ?? onFilesChange);
    callbackRef.current = onChange ?? onFilesChange;

    const wrappedOnFilesChange = useCallback(
      (files: import('./types').PendingFile[]) => {
        const cb = callbackRef.current;
        if (!cb) return;
        // ActionDispatcher 호환: { target: { value: files } } 형태로 전달
        const syntheticEvent = {
          target: { value: files, files },
          preventDefault: () => {},
          stopPropagation: () => {},
        };
        cb(syntheticEvent as any);
      },
      [] // ref 기반이므로 의존성 불필요 — 안정적인 단일 참조
    );

    // 핵심 로직 훅 사용
    const uploader = useFileUploader({
      attachmentableType,
      attachmentableId,
      collection,
      maxFiles,
      maxSize,
      maxConcurrentUploads,
      accept,
      imageCompression: compressionOptions,
      roleIds,
      autoUpload,
      initialFiles,
      endpoints,
      onFilesChange: wrappedOnFilesChange,
      onUploadComplete,
      onUploadError,
      onRemove,
      onReorder,
      uploadTriggerEvent,
      confirmBeforeRemove,
      uploadParams,
    });

    // 컴포넌트 외부에서 호출 가능한 메서드 노출
    useImperativeHandle(ref, () => ({
      uploadAll: uploader.handleUploadAll,
      clear: uploader.clear,
      getPendingFiles: uploader.getPendingFiles,
    }));

    // 갤러리용 이미지 목록 생성
    const galleryImages: GalleryImage[] = useMemo(() => {
      return uploader.imageFiles.map((item) => {
        const isPending = !('hash' in item);
        const pendingItem = item as PendingFile;
        const attachmentItem = item as Attachment;

        if (isPending) {
          // 대기 중인 파일은 로컬 미리보기 사용
          return {
            src: pendingItem.preview || '',
            filename: pendingItem.original_filename,
            title: pendingItem.original_filename,
            downloadRequiresAuth: false,
          };
        } else {
          // 기존 첨부파일은 인증된 URL 또는 다운로드 URL 사용
          const cachedUrl = uploader.authenticatedImageUrls.get(attachmentItem.id);
          return {
            src: cachedUrl || attachmentItem.download_url,
            downloadUrl: attachmentItem.download_url,
            filename: attachmentItem.original_filename,
            title: attachmentItem.original_filename,
            downloadRequiresAuth: true,
          };
        }
      });
    }, [uploader.imageFiles, uploader.authenticatedImageUrls]);

    // Context 값 생성
    const contextValue = useMemo(() => ({
      // 상태
      existingFiles: uploader.existingFiles,
      pendingFiles: uploader.pendingFiles,
      isDragOver: uploader.isDragOver,
      isDeleting: uploader.isDeleting,

      // 설정
      maxFiles,
      maxSize,
      accept,
      endpoints,
      confirmBeforeRemove,

      // 계산된 값
      totalCount: uploader.totalCount,
      hasFiles: uploader.hasFiles,
      canAddMore: uploader.canAddMore,
      allItems: uploader.allItems,
      imageFiles: uploader.imageFiles,

      // 액션
      handleFiles: uploader.handleFiles,
      handleRemove: uploader.handleRemove,
      handleRetry: uploader.handleRetry,
      handleUploadAll: uploader.handleUploadAll,
      handleOpenGallery: uploader.handleOpenGallery,
      handleDownload: uploader.handleDownload,
      handleDragEnd: uploader.handleDragEnd,

      // 드래그 상태 설정
      setIsDragOver: uploader.setIsDragOver,

      // Input ref
      inputRef: uploader.inputRef,

      // 갤러리 상태
      galleryOpen: uploader.galleryOpen,
      setGalleryOpen: uploader.setGalleryOpen,
      galleryStartIndex: uploader.galleryStartIndex,
      galleryKeyRef: uploader.galleryKeyRef,

      // 삭제 확인 모달 상태
      confirmDialogOpen: uploader.confirmDialogOpen,
      setConfirmDialogOpen: uploader.setConfirmDialogOpen,
      itemToDelete: uploader.itemToDelete,
      executeRemoveAttachment: uploader.executeRemoveAttachment,

      // 인증된 이미지 URL 캐시
      authenticatedImageUrls: uploader.authenticatedImageUrls,
    }), [uploader, maxFiles, maxSize, accept, endpoints, confirmBeforeRemove]);

    return (
      <FileUploaderProvider value={contextValue}>
        <Div className={className}>
          {/* 드롭존 + 파일 목록 통합 영역 */}
          <FileDropZone
            isDragOver={uploader.isDragOver}
            canAddMore={uploader.canAddMore}
            onFiles={uploader.handleFiles}
            setIsDragOver={uploader.setIsDragOver}
            inputRef={uploader.inputRef}
            accept={accept}
            maxFiles={maxFiles}
            maxSize={maxSize}
          >
            {/* 파일이 있을 때만 FileList 렌더링 */}
            {uploader.hasFiles && (
              <FileList
                allItems={uploader.allItems}
                canAddMore={uploader.canAddMore}
                totalCount={uploader.totalCount}
                maxFiles={maxFiles}
                maxSize={maxSize}
                accept={accept}
                onRemove={uploader.handleRemove}
                onRetry={uploader.handleRetry}
                onImageClick={uploader.handleOpenGallery}
                onDownload={uploader.handleDownload}
                onDragEnd={uploader.handleDragEnd}
                onAddClick={() => uploader.inputRef.current?.click()}
                enablePrimarySelection={enablePrimarySelection}
                primaryFileId={primaryFileId}
                onPrimaryChange={onPrimaryChange}
              />
            )}
          </FileDropZone>

          {/* 삭제 확인 모달 */}
          {confirmBeforeRemove && (
            <ConfirmDialog
              isOpen={uploader.confirmDialogOpen}
              onClose={() => {
                if (!uploader.isDeleting) {
                  uploader.setConfirmDialogOpen(false);
                }
              }}
              title={confirmRemoveTitle ?? t('attachment.delete_confirm_title')}
              message={confirmRemoveMessage ?? t('attachment.delete_confirm_message')}
              confirmText={t('common.delete')}
              confirmButtonVariant="danger"
              onConfirm={() => {
                if (uploader.itemToDelete) {
                  uploader.executeRemoveAttachment(uploader.itemToDelete);
                }
              }}
              isLoading={uploader.isDeleting}
              loadingText={t('attachment.deleting')}
            />
          )}

          {/* 이미지 갤러리 라이트박스 */}
          {galleryImages.length > 0 && uploader.galleryOpen && (
            <ImageGallery
              key={`gallery-${uploader.galleryKeyRef.current}`}
              images={galleryImages}
              isOpen={true}
              onClose={() => uploader.setGalleryOpen(false)}
              startIndex={uploader.galleryStartIndex}
              showDownload={true}
            />
          )}
        </Div>
      </FileUploaderProvider>
    );
  }
);

FileUploader.displayName = 'FileUploader';

export default FileUploader;