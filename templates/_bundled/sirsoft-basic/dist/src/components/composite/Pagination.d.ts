import { default as React } from 'react';
import { EditorAttrs } from '../../types';
export interface PaginationProps {
    currentPage: number;
    /**
     * 마지막 페이지 번호
     *
     * 총 건수가 상한을 넘겨 정확히 세지 않은 목록은 마지막 페이지를 계산할 수 없다.
     * 그 경우 서버가 `null` 을 보내며, 페이지 번호 목록과 마지막 페이지 점프가 사라지고
     * 이전/다음 이동만 남는다. 0 이나 1 로 채우면 화면이 "1페이지뿐" 이라고 잘못 말하게 된다.
     */
    totalPages: number | null;
    onPageChange: (page: number) => void;
    maxVisiblePages?: number;
    /**
     * 첫/마지막 페이지 버튼 표시 여부 (두 버튼의 기본값)
     *
     * 마지막 페이지만 따로 감춰야 하는 경우가 있으므로 `showLast` 로 덮어쓸 수 있다.
     */
    showFirstLast?: boolean;
    /**
     * 첫 페이지 버튼 표시 여부 (미지정 시 showFirstLast 를 따른다)
     */
    showFirst?: boolean;
    /**
     * 마지막 페이지 버튼 표시 여부 (미지정 시 showFirstLast 를 따른다)
     *
     * 총 건수가 부정확한 목록에서는 마지막 페이지를 계산할 수 없으므로,
     * `totalPages` 가 null 이면 이 값과 무관하게 감춰진다.
     */
    showLast?: boolean;
    /**
     * 다음 페이지 존재 여부 (총 건수를 모르는 목록에서 사용)
     *
     * 총 건수를 몰라도 다음 페이지 존재 여부는 서버가 정확히 판정한다.
     * `totalPages` 가 null 일 때만 쓰인다 — 아래 `canGoNext` 주석 참조.
     */
    hasMorePages?: boolean;
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
