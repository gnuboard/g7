import type React from 'react';

/**
 * 레이아웃 편집기 주입 속성 (editor attributes)
 *
 * 편집 모드에서 코어 `DynamicRenderer` 가 각 nesting 컴포넌트에 단일 prop 으로
 * 주입하는 DOM 표식/이벤트 핸들러 묶음입니다. 컴포넌트는 이 객체를 받아
 * **시각적 루트 요소**에 그대로 spread(`{...editorAttrs}`) 해야 합니다.
 *
 * - 사용자 페이지(비편집)에서는 주입되지 않으므로 `editorAttrs === undefined`,
 *   `{...undefined}` 는 no-op → DOM 구조/속성 불변 (사용자 페이지 ↔ 프리뷰 패리티 유지).
 * - 포함 내용: `data-editor-*` 표식(드롭 슬롯/드래그 핸들 DOM 쿼리용) + 선택/hover 핸들러.
 * - 도메인 prop 은 컴포넌트가 명시 구조분해하므로 이 객체로 누출되지 않습니다.
 *
 */
export interface EditorAttrs {
  'data-editor-id'?: string;
  'data-editor-name'?: string;
  'data-editor-type'?: string;
  'data-editor-path'?: string;
  onClick?: (event: React.MouseEvent) => void;
  onMouseMove?: (event: React.MouseEvent) => void;
  onMouseLeave?: (event: React.MouseEvent) => void;
  /** 미래 확장 여지 (현재 주입 키 외 임의 data-/aria- 속성 허용) */
  [key: string]: unknown;
}
