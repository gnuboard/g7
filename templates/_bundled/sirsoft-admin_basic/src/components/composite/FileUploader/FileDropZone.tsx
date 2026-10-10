/**
 * FileDropZone 컴포넌트
 *
 * 파일을 드래그 앤 드롭하거나 클릭하여 선택할 수 있는 영역입니다.
 *
 * @module composite/FileUploader/FileDropZone
 */

import React from 'react';

import { Div } from '../../basic/Div';
import { P } from '../../basic/P';
import { I } from '../../basic/I';

import { t } from './utils';

export interface FileDropZoneProps {
  /** 드래그 오버 상태 */
  isDragOver: boolean;
  /** 추가 가능 여부 */
  canAddMore: boolean;
  /** 파일 처리 핸들러 */
  onFiles: (files: FileList) => void;
  /** 드래그 오버 상태 설정 */
  setIsDragOver: (value: boolean) => void;
  /** Input 요소 ref */
  inputRef: React.RefObject<HTMLInputElement | null>;
  /** 허용 확장자 */
  accept?: string;
  /** 최대 파일 수 */
  maxFiles: number;
  /** 최대 파일 크기 (MB) */
  maxSize: number;
  /** 비활성화 여부 */
  disabled?: boolean;
  /**
   * 드롭존 크기.
   *
   * `md`(기본)는 전체 폭 영역용 원래 크기다. `sm`·`xs` 는 표 한 칸처럼
   * 좁은 자리에 넣을 때 쓴다 — 여백·아이콘·글자가 함께 줄고, `xs` 는
   * 안내 문구를 접어 한 줄로 만든다.
   */
  size?: 'md' | 'sm' | 'xs';
  /** 자식 요소 */
  children?: React.ReactNode;
}

/** 크기별 드롭존 치수. 호출처가 클래스를 손으로 덮어쓰지 않도록 여기 한곳에 모은다. */
const DROPZONE_SIZES = {
  md: { pad: 'p-8', icon: 'text-4xl mb-4', label: 'text-sm', hint: 'text-xs mt-2' },
  sm: { pad: 'p-4', icon: 'text-2xl mb-2', label: 'text-xs', hint: 'text-[11px] mt-1' },
  xs: { pad: 'p-2', icon: 'text-lg mb-1', label: 'text-[11px]', hint: 'hidden' },
} as const;

export const FileDropZone: React.FC<FileDropZoneProps> = ({
  isDragOver,
  canAddMore,
  onFiles,
  setIsDragOver,
  inputRef,
  accept,
  maxFiles,
  maxSize,
  disabled,
  size = 'md',
  children,
}) => {
  const isInteractive = canAddMore && !disabled;
  const dims = DROPZONE_SIZES[size] ?? DROPZONE_SIZES.md;

  return (
    <Div
      className={`
        relative border-2 border-dashed rounded-lg transition-colors
        ${disabled ? 'opacity-60 cursor-not-allowed' : ''}
        ${
          isDragOver && !disabled
            ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20'
            : 'border-gray-300 dark:border-gray-600'
        }
      `}
      onDragOver={(e) => {
        e.preventDefault();
        if (isInteractive) setIsDragOver(true);
      }}
      onDragLeave={() => setIsDragOver(false)}
      onDrop={(e) => {
        e.preventDefault();
        setIsDragOver(false);
        if (isInteractive) {
          onFiles(e.dataTransfer.files);
        }
      }}
    >
      <input
        ref={inputRef}
        type="file"
        multiple
        accept={accept}
        onChange={(e) => e.target.files && !disabled && onFiles(e.target.files)}
        className="hidden"
        disabled={disabled}
      />

      {/* 파일이 없을 때: 빈 드롭존 */}
      {!children && (
        // 단추 구실 — 안에 문단이 있어 <button> 대신 role 로 두고, Tab 으로 닿아 Enter·Space 로 파일 선택 창을 연다
        <Div
          role="button"
          data-uploader-action="pick"
          tabIndex={disabled ? -1 : 0}
          aria-disabled={disabled || undefined}
          className={`${dims.pad} text-center transition-colors rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500 ${disabled ? 'cursor-not-allowed' : 'cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/50'}`}
          onClick={() => !disabled && inputRef.current?.click()}
          onKeyDown={(e: React.KeyboardEvent<HTMLDivElement>) => {
            if (disabled || (e.key !== 'Enter' && e.key !== ' ')) return;
            e.preventDefault();
            inputRef.current?.click();
          }}
        >
          <I className={`fa-solid fa-cloud-arrow-up ${dims.icon} text-gray-400 dark:text-gray-500`} aria-hidden="true" />
          <P className={`${dims.label} text-gray-600 dark:text-gray-400`}>
            {t('attachment.drop_or_click')}
          </P>
          {/* 허용 형식 목록은 쉼표로만 이어져 끊을 자리가 없다 — 좁은 칸에서 넘치지 않게 어디서든 끊는다 */}
          <P className={`${dims.hint} text-gray-500 dark:text-gray-500 wrap-anywhere`}>
            {t('attachment.upload_limit', { maxFiles, maxSize, count: maxFiles })}
            {accept && ` (${accept})`}
          </P>
        </Div>
      )}

      {/* 파일이 있을 때: 자식 요소 렌더링 */}
      {children}
    </Div>
  );
};

export default FileDropZone;
