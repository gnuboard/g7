/**
 * FileList 컴포넌트
 *
 * 파일 목록을 정렬 가능한 그리드 형태로 표시합니다.
 *
 * @module composite/FileUploader/FileList
 */

import React from 'react';
import {
  DndContext,
  closestCenter,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  DragEndEvent,
} from '@dnd-kit/core';
import {
  SortableContext,
  sortableKeyboardCoordinates,
  rectSortingStrategy,
} from '@dnd-kit/sortable';

import { Div } from '../../basic/Div';
import { Button } from '../../basic/Button';
import { Span } from '../../basic/Span';
import { I } from '../../basic/I';

import { SortableThumbnailItem } from './SortableThumbnailItem';
import type { Attachment, PendingFile } from './types';
import { t } from './utils';

export interface FileListProps {
  /** 모든 파일 목록 (기존 + 대기) */
  allItems: (Attachment | PendingFile)[];
  /** 더 추가 가능 여부 */
  canAddMore: boolean;
  /** 총 파일 수 */
  totalCount: number;
  /** 최대 파일 수 */
  maxFiles: number;
  /** 최대 파일 크기 (MB) */
  maxSize: number;
  /** 허용 확장자 */
  accept?: string;
  /** 파일 삭제 핸들러 */
  onRemove: (item: PendingFile | Attachment) => void;
  /** 재시도 핸들러 */
  onRetry: (pendingFile: PendingFile) => void;
  /** 이미지 클릭 핸들러 */
  onImageClick: (item: PendingFile | Attachment) => void;
  /** 다운로드 핸들러 */
  onDownload: (item: Attachment) => void;
  /** 드래그 종료 핸들러 */
  onDragEnd: (event: DragEndEvent) => void;
  /** 추가 버튼 클릭 핸들러 */
  onAddClick: () => void;
  /** 대표 이미지 선택 기능 활성화 */
  enablePrimarySelection?: boolean;
  /** 현재 대표 이미지 식별자 (ID 또는 hash) */
  primaryFileId?: number | string | null;
  /** 대표 이미지 변경 핸들러 */
  onPrimaryChange?: (id: number | string | null) => void;
  /** 비활성화 여부 */
  disabled?: boolean;
  /**
   * 업로더 크기(드롭존과 같은 값). 표 한 칸용 `xs` 는 칸 폭이 좁아 화면 폭 기준 격자(최대 5열)를 쓰면
   * 썸네일이 짜부라지므로 두 칸 격자를 쓰고, 첨부 개수와 한도 안내를 줄을 나눠 세운다.
   */
  size?: 'md' | 'sm' | 'xs';
}

export const FileList: React.FC<FileListProps> = ({
  allItems,
  canAddMore,
  totalCount,
  maxFiles,
  maxSize,
  accept,
  onRemove,
  onRetry,
  onImageClick,
  onDownload,
  onDragEnd,
  onAddClick,
  enablePrimarySelection,
  primaryFileId,
  onPrimaryChange,
  disabled,
  size = 'md',
}) => {
  const isCompact = size === 'xs';

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 8 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates })
  );

  return (
    <Div className={isCompact ? 'p-2' : 'p-3'}>
      <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
        <SortableContext
          items={allItems.map((item) => ('hash' in item ? item.hash : item.id))}
          strategy={rectSortingStrategy}
        >
          <Div className={isCompact ? 'grid grid-cols-2 gap-2' : 'grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3'}>
            {/* 파일 썸네일들 */}
            {allItems.map((item) => {
              const isExisting = 'hash' in item;
              // 기존 첨부파일: is_image 필드 우선 사용, 대기 파일: mime_type 확인
              const isImage = isExisting
                ? (item as Attachment).is_image
                : (item.mime_type?.startsWith('image/') ?? false);

              return (
                <SortableThumbnailItem
                  key={isExisting ? item.hash : item.id}
                  file={item}
                  onRemove={() => onRemove(item)}
                  onRetry={
                    'status' in item && item.status === 'error'
                      ? () => onRetry(item as PendingFile)
                      : undefined
                  }
                  onImageClick={
                    isImage ? () => onImageClick(item) : undefined
                  }
                  onDownload={
                    isExisting ? () => onDownload(item as Attachment) : undefined
                  }
                  isPrimary={enablePrimarySelection && isExisting && ((item as Attachment).hash || (item as Attachment).id) === primaryFileId}
                  onPrimaryClick={
                    enablePrimarySelection && isExisting
                      ? () => onPrimaryChange?.((item as Attachment).hash || (item as Attachment).id)
                      : undefined
                  }
                  disabled={disabled}
                />
              );
            })}

            {/* 추가 버튼 (더 추가할 수 있고, 비활성화 아닐 때만) — 단추로 그려 Tab 으로 닿고 Enter·Space 로 파일 선택을 연다 */}
            {canAddMore && !disabled && (
              <Button
                type="button"
                data-uploader-action="add"
                className="w-full aspect-square flex flex-col items-center justify-center border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-lg cursor-pointer hover:border-gray-400 dark:hover:border-gray-500 hover:bg-gray-50 dark:hover:bg-gray-800/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 transition-colors"
                onClick={onAddClick}
              >
                <I className="fa-solid fa-plus text-2xl text-gray-400 dark:text-gray-500 mb-1" aria-hidden="true" />
                <Span className="text-xs text-gray-500 dark:text-gray-400">{t('common.add')}</Span>
              </Button>
            )}
          </Div>
        </SortableContext>
      </DndContext>

      {/* 하단 정보 */}
      <Div
        className={
          isCompact
            ? 'mt-2 pt-2 border-t border-gray-200 dark:border-gray-700 flex flex-col items-start gap-0.5 text-xs text-gray-500 dark:text-gray-400'
            : 'mt-3 pt-3 border-t border-gray-200 dark:border-gray-700 flex flex-wrap justify-between items-center gap-x-3 gap-y-1 text-xs text-gray-500 dark:text-gray-400'
        }
      >
        <Span>{t('attachment.attached_count', { count: totalCount, max: maxFiles })}</Span>
        {/* 허용 형식 목록은 쉼표로만 이어져 끊을 자리가 없다 — 좁은 칸에서 넘치지 않게 어디서든 끊는다 */}
        <Span className="min-w-0 wrap-anywhere">{t('attachment.max_size', { size: maxSize })}{accept && ` (${accept})`}</Span>
      </Div>
    </Div>
  );
};

export default FileList;
