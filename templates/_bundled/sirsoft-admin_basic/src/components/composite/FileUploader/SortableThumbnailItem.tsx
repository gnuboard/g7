/**
 * SortableThumbnailItem 컴포넌트
 *
 * 드래그 앤 드롭이 가능한 파일 썸네일 아이템입니다.
 *
 * @module composite/FileUploader/SortableThumbnailItem
 */

import React, { useState, useEffect } from 'react';
import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';

import { Div } from '../../basic/Div';
import { Button } from '../../basic/Button';
import { Span } from '../../basic/Span';
import { P } from '../../basic/P';
import { I } from '../../basic/I';
import { Img } from '../../basic/Img';

import type { Attachment, PendingFile } from './types';
import { getFileIcon, t } from './utils';

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const G7Core = (window as any).G7Core;

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Comp:SortableThumbnail')) ?? {
    log: (...args: unknown[]) => console.log('[Comp:SortableThumbnail]', ...args),
    warn: (...args: unknown[]) => console.warn('[Comp:SortableThumbnail]', ...args),
    error: (...args: unknown[]) => console.error('[Comp:SortableThumbnail]', ...args),
};

export interface SortableThumbnailItemProps {
  file: PendingFile | (Attachment & { status?: string });
  onRemove: () => void;
  onRetry?: () => void;
  /** 이미지 클릭 시 갤러리 열기 */
  onImageClick?: () => void;
  /** 비이미지 파일 클릭/다운로드 시 호출 */
  onDownload?: () => void;
  /** 대표 이미지 여부 */
  isPrimary?: boolean;
  /** 대표 이미지 설정 클릭 핸들러 */
  onPrimaryClick?: () => void;
  /** 비활성화 여부 */
  disabled?: boolean;
}

export const SortableThumbnailItem: React.FC<SortableThumbnailItemProps> = ({
  file,
  onRemove,
  onRetry,
  onImageClick,
  onDownload,
  isPrimary,
  onPrimaryClick,
  disabled,
}) => {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: 'hash' in file ? file.hash : file.id,
  });

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.5 : 1,
  };

  const isPending = 'status' in file && file.status !== undefined;
  const status = isPending ? (file as PendingFile).status : 'done';
  const isUploading = status === 'uploading';
  const isCompressing = status === 'compressing';
  const hasError = status === 'error';
  const progress = isPending ? (file as PendingFile).progress : 100;
  const preview = isPending ? (file as PendingFile).preview : undefined;
  // 기존 첨부파일: is_image 필드 우선 사용, 대기 파일: mime_type 확인
  const isImage = !isPending
    ? (file as Attachment).is_image
    : (file.mime_type?.startsWith('image/') ?? false);

  // 인증된 이미지 로딩을 위한 상태
  const [authenticatedImageUrl, setAuthenticatedImageUrl] = useState<string | undefined>(undefined);
  const downloadUrl = !isPending && isImage ? (file as Attachment).download_url : undefined;

  // 기존 첨부파일의 경우 인증된 요청으로 이미지 로드
  useEffect(() => {
    if (!downloadUrl) {
      setAuthenticatedImageUrl(undefined);
      return;
    }

    let isMounted = true;
    let objectUrl: string | undefined;

    const loadAuthenticatedImage = async () => {
      try {
        const blob = await G7Core.api.get(downloadUrl, {
          responseType: 'blob',
        });
        if (isMounted && blob) {
          objectUrl = URL.createObjectURL(blob);
          setAuthenticatedImageUrl(objectUrl);
        }
      } catch (error) {
        logger.error('Failed to load authenticated image:', error);
      }
    };

    loadAuthenticatedImage();

    return () => {
      isMounted = false;
      if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
      }
    };
  }, [downloadUrl]);

  // 기존 첨부파일의 경우 인증된 URL 사용
  const existingImageUrl = authenticatedImageUrl;

  return (
    <Div
      ref={setNodeRef}
      style={style}
      className="relative group bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden"
    >
      {/* 썸네일 영역 */}
      <Div
        className={`aspect-square w-full flex items-center justify-center bg-gray-50 dark:bg-gray-900 relative ${
          (isImage || !isPending) ? 'cursor-pointer' : ''
        }`}
        onClick={() => {
          // 업로드 중/압축 중이면 클릭 무시
          if (isUploading || isCompressing) return;

          if (isImage && onImageClick) {
            // 이미지는 갤러리 열기
            onImageClick();
          } else if (!isPending && onDownload) {
            // 비이미지 기존 파일은 다운로드
            onDownload();
          }
        }}
      >
        {/* 이미지 미리보기 */}
        {(preview || existingImageUrl) ? (
          <Img
            src={preview || existingImageUrl}
            alt={file.original_filename}
            className="w-full h-full object-cover"
          />
        ) : (
          /* 파일 아이콘 */
          <I className={`fa-solid ${getFileIcon(file.mime_type)} text-4xl text-gray-400 dark:text-gray-500`} />
        )}

        {/* 업로드/압축 진행률 오버레이 */}
        {(isUploading || isCompressing) && (
          <Div className="absolute inset-0 bg-black/50 flex flex-col items-center justify-center">
            <Div className="w-3/4 h-2 bg-gray-700 rounded-full overflow-hidden">
              <Div
                className="h-full bg-blue-500 transition-all"
                style={{ width: `${progress}%` }}
              />
            </Div>
            <Span className="text-white text-xs mt-1">{progress}%</Span>
          </Div>
        )}

        {/* 에러 오버레이 */}
        {hasError && (
          <Div className="absolute inset-0 bg-red-500/50 flex items-center justify-center">
            <I className="fa-solid fa-exclamation-triangle text-2xl text-white" />
          </Div>
        )}

        {/* 대표 이미지 뱃지 */}
        {onPrimaryClick && (
          <Button
            type="button"
            onClick={(e) => {
              e.stopPropagation();
              onPrimaryClick();
            }}
            className={`absolute top-1.5 left-1.5 z-10 p-1 rounded-full transition-colors ${
              isPrimary
                ? 'bg-yellow-500 text-white'
                : 'bg-gray-800/50 text-gray-300 hover:bg-yellow-500 hover:text-white opacity-0 group-hover:opacity-100'
            }`}
            title={isPrimary ? t('attachment.primary_image') : t('attachment.set_as_primary')}
          >
            <I className="fa-solid fa-star text-xs" />
          </Button>
        )}

        {/* 호버 시 액션 버튼들 (disabled 시 숨김) */}
        <Div className={`absolute inset-0 bg-black/0 group-hover:bg-black/40 transition-colors flex items-start justify-end p-1 gap-1 opacity-0 group-hover:opacity-100 ${disabled ? 'hidden' : ''}`}>
          {/* 드래그 핸들 */}
          <Button
            type="button"
            {...attributes}
            {...listeners}
            className="p-1.5 bg-white/90 dark:bg-gray-800/90 rounded text-gray-600 dark:text-gray-300 hover:bg-white cursor-grab active:cursor-grabbing"
            title={t('attachment.drag_to_reorder')}
            onClick={(e) => e.stopPropagation()}
          >
            <I className="fa-solid fa-grip-vertical text-sm" />
          </Button>

          {/* 다운로드 버튼 (기존 첨부파일만) */}
          {!isPending && onDownload && (
            <Button
              type="button"
              onClick={(e) => {
                e.stopPropagation();
                onDownload();
              }}
              className="p-1.5 bg-white/90 dark:bg-gray-800/90 rounded text-blue-500 hover:bg-white"
              title={t('common.download')}
            >
              <I className="fa-solid fa-download text-sm" />
            </Button>
          )}

          {/* 재시도 버튼 */}
          {hasError && onRetry && (
            <Button
              type="button"
              onClick={(e) => {
                e.stopPropagation();
                onRetry();
              }}
              className="p-1.5 bg-white/90 dark:bg-gray-800/90 rounded text-orange-500 hover:bg-white"
              title={t('common.retry')}
            >
              <I className="fa-solid fa-arrow-rotate-right text-sm" />
            </Button>
          )}

          {/* 삭제 버튼 */}
          <Button
            type="button"
            onClick={(e) => {
              e.stopPropagation();
              onRemove();
            }}
            disabled={isUploading || isCompressing}
            className="p-1.5 bg-white/90 dark:bg-gray-800/90 rounded text-red-500 hover:bg-white disabled:opacity-50"
            title={t('common.delete')}
          >
            <I className="fa-solid fa-times text-sm" />
          </Button>
        </Div>
      </Div>

      {/* 파일 정보 */}
      <Div
        className={`p-2 ${!isPending && onDownload ? 'cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700' : ''}`}
        onClick={() => {
          // 기존 첨부파일만 클릭 시 다운로드
          if (!isPending && onDownload) {
            onDownload();
          }
        }}
      >
        <P className="text-xs font-medium text-gray-900 dark:text-white truncate" title={file.original_filename}>
          {file.original_filename}
        </P>
        <P className="text-xs text-gray-500 dark:text-gray-400">
          {isPending ? (file as PendingFile).size_formatted : (file as Attachment).size_formatted}
        </P>
      </Div>
    </Div>
  );
};

export default SortableThumbnailItem;
