/**
 * SortableThumbnailItem 컴포넌트
 *
 * 드래그 앤 드롭이 가능한 파일 썸네일 아이템입니다.
 *
 * @module composite/FileUploader/SortableThumbnailItem
 */

import React, { useState, useEffect, useContext } from 'react';
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
import { isDirectAssetUrl } from '../assetOrigin';
import FileUploaderContext from './FileUploaderContext';
import { attachmentThumbUrl } from '../../../support/thumbnail';
import { keyboardActivationProps, FOCUS_VISIBLE_RING } from '../../../support/keyboardActivation';

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
  const attachment = !isPending && isImage ? (file as Attachment) : undefined;
  const downloadUrl = attachment ? attachment.download_url : undefined;

  // 미리보기 주소(없으면 다운로드 주소)가 서빙 경로면 sm 썸네일을 <img> 로 바로 그린다 — 원본 blob 을 받지 않는다.
  // 썸네일이 실패하면(권한 거부 등) 그 칸만 종전 인증 요청 경로로 돌아간다 — 업로더 안이면 업로더가 받는다.
  const thumbnailSrc = attachment ? attachmentThumbUrl(attachment, 'sm') : undefined;
  const [thumbnailFailed, setThumbnailFailed] = useState(false);
  useEffect(() => {
    setThumbnailFailed(false);
  }, [thumbnailSrc]);
  const showThumbnail = Boolean(thumbnailSrc) && !thumbnailFailed;

  // 업로더가 이미 가진 그림 주소(방금 올린 그림의 로컬 파일·업로더가 받아 둔 blob). 업로더 안에서는 업로더가
  // 기존 첨부를 한 번 받으므로 이 칸이 다시 받지 않는다 — 종전 basic 은 업로더와 칸이 같은 원본을 두 번 받았다.
  // 업로더 밖에서 단독으로 쓰일 때(Context 없음)만 이 칸이 받는다. 업로더 안에서 썸네일이 실패하면 업로더에 그 첨부를
  // 받아 달라고 알린다 — 칸이 받으면 blob 이 칸 안에만 있어 확대 보기가 인증 없는 원본 주소를 열었다.
  const uploaderContext = useContext(FileUploaderContext);
  const cachedImageUrl = attachment ? uploaderContext?.authenticatedImageUrls?.get(attachment.id) : undefined;
  const loadsItself = Boolean(downloadUrl) && !showThumbnail && !cachedImageUrl && !uploaderContext;
  const requestUploaderImage = uploaderContext?.requestAuthenticatedImage;
  const attachmentId = attachment?.id;
  useEffect(() => {
    if (thumbnailFailed && attachmentId !== undefined && requestUploaderImage) {
      requestUploaderImage([attachmentId]);
    }
  }, [thumbnailFailed, attachmentId, requestUploaderImage]);

  // 기존 첨부파일의 경우 인증된 요청으로 이미지 로드
  useEffect(() => {
    if (!downloadUrl || !loadsItself) {
      setAuthenticatedImageUrl(undefined);
      return;
    }

    // 공개 자산 디스크(S3/CDN)가 준 교차 출처 URL 은 인증이 필요 없는 공개 자산이다.
    // XHR 로 가져오면 CORS 미설정 CDN 에서 실패하므로 URL 을 그대로 사용한다.
    if (isDirectAssetUrl(downloadUrl)) {
      setAuthenticatedImageUrl(downloadUrl);
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
  }, [downloadUrl, loadsItself]);

  // 기존 첨부파일 그림 주소 — 썸네일 → 업로더가 가진 주소 → 이 칸이 받은 주소
  const existingImageUrl = showThumbnail ? thumbnailSrc : (cachedImageUrl ?? authenticatedImageUrl);

  return (
    <Div
      ref={setNodeRef}
      style={style}
      data-uploader-item=""
      className="relative group bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden"
    >
      {/* 썸네일 영역 */}
      <Div
        className={`aspect-square w-full flex items-center justify-center bg-gray-50 dark:bg-gray-900 relative ${
          (isImage || !isPending) ? 'cursor-pointer' : ''
        } ${isImage && onImageClick ? FOCUS_VISIBLE_RING : ''
        }`}
        // 이미지 크게 보기는 다른 단추가 없어 이 칸이 키보드 단추다. 다운로드는 아래 다운로드 단추가 맡는다(#869 키보드 접근)
        {...(isImage && onImageClick ? { ...keyboardActivationProps(() => { if (!isUploading && !isCompressing) onImageClick(); }), 'aria-label': t('common.zoom_image_named', { name: file.original_filename }) } : {})}
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
            onError={!preview && showThumbnail ? () => setThumbnailFailed(true) : undefined}
          />
        ) : (
          /* 파일 아이콘 */
          <I className={`fa-solid ${getFileIcon(file.mime_type)} text-4xl text-gray-500 dark:text-gray-500`} />
        )}

        {/* 업로드/압축 진행률 오버레이 */}
        {(isUploading || isCompressing) && (
          <Div className="absolute inset-0 bg-black/60 flex flex-col items-center justify-center">
            <Div className="w-3/4 h-2 bg-gray-700 dark:bg-gray-600 rounded-full overflow-hidden">
              <Div
                className="h-full bg-blue-500 dark:bg-blue-400 transition-all"
                style={{ width: `${progress}%` }}
              />
            </Div>
            <Span className="text-white text-xs mt-1">{progress}%</Span>
          </Div>
        )}

        {/* 에러 오버레이 */}
        {hasError && (
          <Div className="absolute inset-0 bg-red-600/70 dark:bg-red-600/70 flex items-center justify-center">
            <I className="fa-solid fa-exclamation-triangle text-2xl text-white" />
          </Div>
        )}

        {/* 대표 이미지 뱃지 */}
        {onPrimaryClick && (
          <Button
            type="button"
            data-uploader-action="primary"
            // 비활성(읽기 전용)이면 대표 표시는 남기되 바꿀 수 없다 — 대표가 아닌 칸의 ★ 는 감춘다
            disabled={disabled}
            onClick={(e) => {
              e.stopPropagation();
              if (disabled) return;
              onPrimaryClick();
            }}
            className={`absolute top-1.5 left-1.5 z-10 p-1 rounded-full transition-colors disabled:cursor-not-allowed ${
              isPrimary
                ? 'bg-amber-700 text-white'
                : disabled
                  ? 'hidden'
                  : 'bg-gray-800/70 text-gray-300 hover:bg-amber-700 hover:text-white opacity-0 group-hover:opacity-100 group-focus-within:opacity-100'
            }`}
            title={isPrimary ? t('attachment.primary_image') : t('attachment.set_as_primary')}
            aria-label={isPrimary ? t('attachment.primary_image') : t('attachment.set_as_primary')}
          >
            <I className="fa-solid fa-star text-xs" aria-hidden="true" />
          </Button>
        )}

        {/* 호버 시 액션 버튼들 (disabled 시 숨김) — 키보드 초점이 썸네일 안에 있을 때도 보여야 어느 단추에 초점이 있는지 보인다 */}
        <Div className={`absolute inset-0 bg-black/0 group-hover:bg-black/40 group-focus-within:bg-black/40 transition-colors flex items-start justify-end p-1 gap-1 opacity-0 group-hover:opacity-100 group-focus-within:opacity-100 ${disabled ? 'hidden' : ''}`}>
          {/* 드래그 핸들 */}
          <Button
            type="button"
            data-uploader-action="drag"
            {...attributes}
            {...listeners}
            className="p-1.5 bg-white/90 dark:bg-gray-800/90 rounded text-gray-600 dark:text-gray-300 hover:bg-white dark:hover:bg-gray-700 cursor-grab active:cursor-grabbing"
            title={t('attachment.drag_to_reorder')}
            aria-label={t('attachment.drag_to_reorder')}
            onClick={(e) => e.stopPropagation()}
          >
            {/* 아이콘만 있는 단추 — 글꼴 아이콘의 CSS 글리프가 title 보다 먼저 이름으로 잡히므로 이름은 aria-label 로 달고 아이콘은 숨긴다 */}
            <I className="fa-solid fa-grip-vertical text-sm" aria-hidden="true" />
          </Button>

          {/* 다운로드 버튼 (기존 첨부파일만) */}
          {!isPending && onDownload && (
            <Button
              type="button"
              data-uploader-action="download"
              onClick={(e) => {
                e.stopPropagation();
                onDownload();
              }}
              className="p-1.5 bg-white/90 dark:bg-gray-800/90 rounded text-blue-700 dark:text-blue-300 hover:bg-white dark:hover:bg-gray-700"
              title={t('common.download')}
              aria-label={t('common.download')}
            >
              <I className="fa-solid fa-download text-sm" aria-hidden="true" />
            </Button>
          )}

          {/* 재시도 버튼 */}
          {hasError && onRetry && (
            <Button
              type="button"
              data-uploader-action="retry"
              onClick={(e) => {
                e.stopPropagation();
                onRetry();
              }}
              className="p-1.5 bg-white/90 dark:bg-gray-800/90 rounded text-orange-800 dark:text-orange-300 hover:bg-white dark:hover:bg-gray-700"
              title={t('common.retry')}
              aria-label={t('common.retry')}
            >
              <I className="fa-solid fa-arrow-rotate-right text-sm" aria-hidden="true" />
            </Button>
          )}

          {/* 삭제 버튼 */}
          <Button
            type="button"
            data-uploader-action="remove"
            onClick={(e) => {
              e.stopPropagation();
              onRemove();
            }}
            disabled={isUploading || isCompressing}
            className="p-1.5 bg-white/90 dark:bg-gray-800/90 rounded text-red-700 dark:text-red-300 hover:bg-white dark:hover:bg-gray-700 disabled:opacity-50"
            title={t('common.delete')}
            aria-label={t('common.delete')}
          >
            <I className="fa-solid fa-times text-sm" aria-hidden="true" />
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
