/**
 * 파일 고르기 — 설정 화면 묶음의 기본 부품
 *
 * 이 템플릿 기본 부품(A·Button·Div·H1~H3·Img·Span)에는 입력칸이 없다. 설정 화면이 파일 하나를 고르는 데만 쓰는
 * 기본 부품을 설정 묶음 안에 둔다 — 단추 모양 라벨 안에 숨긴 파일 입력칸이다(라벨을 누르면 파일 고르기 창이 열린다).
 */

import React from 'react';

export interface FilePickerProps {
  /** 단추 글자 */
  label: string;
  /** 접근 이름 (칸 라벨) */
  ariaLabel: string;
  /** 허용 형식 (파일 입력칸 accept) */
  accept?: string;
  /** 단추 클래스 */
  className?: string;
  /** 입력칸 클래스 (숨김) */
  inputClassName?: string;
  /** 파일을 고르면 첫 파일 (취소하면 null) */
  onPick: (file: File | null) => void;
}

/**
 * 파일 고르기
 */
export const FilePicker: React.FC<FilePickerProps> = ({ label, ariaLabel, accept, className, inputClassName, onPick }) => (
  <label className={className}>
    <input type="file" className={inputClassName} accept={accept} aria-label={ariaLabel} onChange={(event) => onPick(event.target.files?.[0] ?? null)} />
    {label}
  </label>
);

export default FilePicker;
