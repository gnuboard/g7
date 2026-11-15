import { default as React } from 'react';
import { EditorAttrs } from '../../types';
export interface BottomSheetProps {
    /** 열림 여부 */
    open?: boolean;
    /** 시트 제목 */
    title?: string;
    /** 닫기 단추 이름 (기본: 공통 「닫기」 문구) */
    closeLabel?: string;
    /** 닫으려 할 때 (배경 누름·Esc·닫기 단추) */
    onClose?: () => void;
    /** 추가 CSS 클래스 (시트 본체) */
    className?: string;
    /** DOM id 속성 (레이아웃 편집기 코어 일괄 ID) */
    id?: string;
    /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
    editorAttrs?: EditorAttrs;
    /** 시트 내용 */
    children?: React.ReactNode;
}
/**
 * 좁은 화면 바텀시트
 *
 * @example
 * ```html
 * <bottom-sheet data-type="composite" open="{{!!_local.buySheetOpen}}" title="$t:shop.product.select_options">
 *   <on event="onClose"><set-state target="local" :buySheetOpen="false"/></on>
 *   …
 * </bottom-sheet>
 * ```
 */
export declare const BottomSheet: React.FC<BottomSheetProps>;
export default BottomSheet;
