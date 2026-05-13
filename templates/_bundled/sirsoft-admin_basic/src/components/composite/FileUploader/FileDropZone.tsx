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
  /** 자식 요소 */
  children?: React.ReactNode;
}

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
  children,
}) => {
  const isInteractive = canAddMore && !disabled;

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
        <Div
          className={`p-8 text-center transition-colors ${disabled ? 'cursor-not-allowed' : 'cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/50'}`}
          onClick={() => !disabled && inputRef.current?.click()}
        >
          <I className="fa-solid fa-cloud-arrow-up text-4xl text-gray-400 dark:text-gray-500 mb-4" />
          <P className="text-sm text-gray-600 dark:text-gray-400">
            {t('attachment.drop_or_click')}
          </P>
          <P className="text-xs text-gray-500 dark:text-gray-500 mt-2">
            {t('attachment.upload_limit', { maxFiles, maxSize })}
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
