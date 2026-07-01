import { default as React } from 'react';
import { EditorAttrs } from '../../types';
export interface PaginationProps {
    currentPage: number;
    totalPages: number;
    onPageChange: (page: number) => void;
    maxVisiblePages?: number;
    showFirstLast?: boolean;
    className?: string;
    style?: React.CSSProperties;
    prevText?: string;
    nextText?: string;
    /**
   * DOM id 속성 (레이아웃 편집기 코어 일괄 ID)
   */
    id?: string;
    /**
       * 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread)
       */
    editorAttrs?: EditorAttrs;
}
/**
 * Pagination 집합 컴포넌트
 *
 * Button + Span 기본 컴포넌트를 조합하여 페이지네이션 UI를 구성합니다.
 * 페이지 번호 생성 알고리즘을 포함하며, 많은 페이지 수에 대해 효율적으로 렌더링합니다.
 *
 * @example
 * // 레이아웃 JSON 사용 예시
 * {
 *   "name": "Pagination",
 *   "props": {
 *     "currentPage": "{{pagination.current}}",
 *     "totalPages": "{{pagination.total}}",
 *     "maxVisiblePages": 5,
 *     "showFirstLast": true
 *   }
 * }
 */
export declare const Pagination: React.FC<PaginationProps>;
