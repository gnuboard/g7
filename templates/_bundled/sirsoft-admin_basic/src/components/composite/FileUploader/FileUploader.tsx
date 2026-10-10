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

import { forwardRef, useImperativeHandle, useMemo, useRef } from 'react';

import { Div } from '../../basic/Div';
import { ConfirmDialog } from '../ConfirmDialog';
import { ImageGallery, GalleryImage } from '../ImageGallery';

import { FileUploaderProvider } from './FileUploaderContext';
import { useFileUploader } from './useFileUploader';
import { FileDropZone } from './FileDropZone';
import { FileList } from './FileList';
import { useFocusRecovery } from './useFocusRecovery';
import type { FileUploaderProps, FileUploaderRef, Attachment, PendingFile } from './types';
import { t } from './utils';

// 생략된 배열 속성의 기본값 — 렌더마다 새 배열을 만들면 그 값을 의존성으로 쓰는 동기화 effect·콜백이
// 렌더마다 다시 돌아 상태 갱신이 끝없이 이어진다(7.1.0 O-PD-2). 얼려 두어 공유 상수가 바뀌지 않게 한다.
const EMPTY_FILES = Object.freeze([]) as unknown as Attachment[];
const EMPTY_ROLE_IDS = Object.freeze([]) as unknown as number[];

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
      // 기본은 고른 순서대로 하나씩 — 서버가 도착 순서로 첨부 번호를 매기면 동시 업로드는 먼저 끝난 파일이 앞에 선다
      maxConcurrentUploads = 1,
      accept,
      imageCompression: compressionOptions,
      roleIds = EMPTY_ROLE_IDS,
      autoUpload = true,
      onFilesChange,
      onUploadComplete,
      onUploadError,
      onRemove,
      onReorder,
      initialFiles = EMPTY_FILES,
      disabled = false,
      size = 'md',
      className = '',
      confirmBeforeRemove = false,
      confirmRemoveTitle,
      confirmRemoveMessage,
      apiEndpoints,
      uploadParams,
      enablePrimarySelection = false,
      primaryFileId,
      onPrimaryChange,
      id,
      editorAttrs,
    },
    ref
  ) => {
    // API 엔드포인트 기본값 설정
    const endpoints = {
      upload: apiEndpoints?.upload ?? '/api/admin/attachments',
      delete: apiEndpoints?.delete ?? '/api/admin/attachments/:id',
      reorder: apiEndpoints?.reorder ?? '/api/admin/attachments/reorder',
    };

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
      onFilesChange,
      onUploadComplete,
      onUploadError,
      onRemove,
      onReorder,
      uploadTriggerEvent,
      confirmBeforeRemove,
      uploadParams,
    });

    // 항목을 지우거나 파일을 고른 뒤 초점을 쥔 요소가 사라지면 가까운 자리로 초점을 돌린다(7.1.0 P-6)
    const rootRef = useRef<HTMLDivElement>(null);
    useFocusRecovery(rootRef);

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
        <Div ref={rootRef} id={id} className={className} {...editorAttrs}>
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
            disabled={disabled}
            size={size}
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
                disabled={disabled}
                size={size}
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
