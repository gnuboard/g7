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
  /** 최대 파일 크기 (MB) — 호출부가 선언하지 않으면 용량 문구를 쓰지 않는다(SA-D5) */
  maxSize?: number;
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
  children,
}) => {
  return (
    <Div
      className={`
        relative border-2 border-dashed rounded-lg transition-colors
        ${
          isDragOver
            ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20'
            : 'border-gray-300 dark:border-gray-600'
        }
      `}
      onDragOver={(e) => {
        e.preventDefault();
        if (canAddMore) setIsDragOver(true);
      }}
      onDragLeave={() => setIsDragOver(false)}
      onDrop={(e) => {
        e.preventDefault();
        setIsDragOver(false);
        if (canAddMore) {
          onFiles(e.dataTransfer.files);
        }
      }}
    >
      <input
        ref={inputRef}
        type="file"
        multiple
        accept={accept}
        onChange={(e) => e.target.files && onFiles(e.target.files)}
        className="hidden"
      />

      {/* 파일이 없을 때: 빈 드롭존 */}
      {!children && (
        // 단추 구실 — 안에 문단이 있어 <button> 대신 role 로 두고, Tab 으로 닿아 Enter·Space 로 파일 선택 창을 연다
        <Div
          role="button"
          data-uploader-action="pick"
          tabIndex={0}
          className="p-8 text-center cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500"
          onClick={() => inputRef.current?.click()}
          onKeyDown={(e: React.KeyboardEvent<HTMLDivElement>) => {
            if (e.key !== 'Enter' && e.key !== ' ') return;
            e.preventDefault();
            inputRef.current?.click();
          }}
        >
          <I className="fa-solid fa-cloud-arrow-up text-4xl text-gray-400 dark:text-gray-500 mb-4" aria-hidden="true" />
          <P className="text-sm text-gray-600 dark:text-gray-400">
            {t('attachment.drop_or_click')}
          </P>
          {/* 허용 형식 목록은 쉼표로만 이어져 끊을 자리가 없다 — 좁은 화면에서 넘치지 않게 어디서든 끊는다 */}
          <P className="text-xs text-gray-500 dark:text-gray-500 mt-2 wrap-anywhere">
            {maxSize != null
              ? t('attachment.upload_limit', { maxFiles, maxSize, count: maxFiles })
              : t('attachment.upload_limit_count', { maxFiles, count: maxFiles })}
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