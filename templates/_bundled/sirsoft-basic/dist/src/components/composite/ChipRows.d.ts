import { default as React } from 'react';
import { EditorAttrs } from '../../types';
export interface ChipRowsProps {
    /** 칩 줄의 이름 (스크린리더가 읽는다) */
    ariaLabel?: string;
    /** 추가 CSS 클래스 */
    className?: string;
    /** DOM id 속성 (레이아웃 편집기 코어 일괄 ID) */
    id?: string;
    /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
    editorAttrs?: EditorAttrs;
    /** 칩 (레이아웃이 넘기는 자식) */
    children?: React.ReactNode;
}
/**
 * 분류 칩 줄 컨테이너
 *
 * @example
 * ```html
 * <chip-rows data-type="composite" ariaLabel="$t:shop.category.chips_label">
 *   <a href="…">전체</a>
 *   <div data-each="cat in {{categories.data}}"><a href="…">{{cat.name_localized}}</a></div>
 * </chip-rows>
 * ```
 */
export declare const ChipRows: React.FC<ChipRowsProps>;
export default ChipRows;
